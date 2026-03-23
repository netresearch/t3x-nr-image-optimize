<?php

/*
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\NrImageOptimize;

use function array_search;
use function dirname;
use function file_exists;
use function file_get_contents;

use GuzzleHttp\Psr7\Query;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;

use function is_dir;
use function md5;
use function mkdir;
use function preg_match;
use function preg_match_all;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

use function round;

use RuntimeException;

use function sprintf;
use function strtolower;

use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Locking\Exception\LockCreateException;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;

use function urldecode;
use function usleep;

/**
 * Handles on-the-fly image processing for generated variants served from the
 * "/processed" path. Parses the requested variant from the URL, loads the
 * original image, applies resizing/cropping according to the mode, and writes
 * optimized variants (original format, and optionally AVIF/WebP) to disk while
 * streaming the result to the client.
 *
 * Concurrency safety is ensured via TYPO3's locking API to avoid duplicate work
 * when multiple requests for the same image arrive simultaneously.
 *
 * @author  Axel Seemann <axel.seemann@netresearch.de>
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 *
 * @see    https://www.netresearch.de
 */
class Processor
{
    /**
     * Initialize the image processor with all required dependencies.
     *
     * @param ImageManager             $imageManager    Intervention Image manager used to read/encode images
     * @param LockFactory              $lockFactory     TYPO3 lock factory for concurrent processing coordination
     * @param ResponseFactoryInterface $responseFactory PSR-17 response factory
     * @param StreamFactoryInterface   $streamFactory   PSR-17 stream factory
     */
    public function __construct(
        private readonly ImageManager $imageManager,
        private readonly LockFactory $lockFactory,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {}

    /**
     * Entry point invoked by the middleware to handle a processed image request.
     *
     * Parses the requested variant from the URI, acquires a processing lock to
     * avoid duplicate work, loads and resizes/crops the original image, writes
     * the processed files (including optional WebP/AVIF variants), and returns
     * a PSR-7 response with the best available representation.
     *
     * Returns 404 if the original image is missing and 503 if a lock cannot be
     * acquired in time.
     *
     * @param RequestInterface $request Incoming request containing the processed URL and query params
     *
     * @return ResponseInterface The image response or an error response
     */
    public function generateAndSend(RequestInterface $request): ResponseInterface
    {
        $variantUrl = urldecode($request->getUri()->getPath());

        $locker    = $this->getLocker($variantUrl . '-process');
        $lockCount = 0;

        while ($locker->acquire() === false) {
            usleep(100000);

            ++$lockCount;

            if ($lockCount === 10) {
                return $this->responseFactory->createResponse(503)
                    ->withBody($this->streamFactory->createStream('Image is currently being processed'));
            }
        }

        $urlInfo = $this->gatherInformationBasedOnUrl($variantUrl);

        if (!file_exists($urlInfo['pathOriginal'])) {
            $locker->release();

            return $this->responseFactory->createResponse(404);
        }

        $image = $this->loadOriginal($urlInfo['pathOriginal']);

        if ($image instanceof ResponseInterface) {
            $locker->release();

            return $image;
        }

        $targetWidth  = $urlInfo['targetWidth'];
        $targetHeight = $urlInfo['targetHeight'];

        [$targetWidth, $targetHeight] = $this->calculateTargetDimensions(
            $image,
            $targetWidth,
            $targetHeight,
        );

        $targetQuality  = $urlInfo['targetQuality'];
        $processingMode = $urlInfo['processingMode'];

        $image = $this->processImage($image, $targetWidth, $targetHeight, $processingMode);

        $dir = dirname($urlInfo['pathVariant']);

        if (!is_dir($dir)
            && !mkdir($dir, 0o775, true)
            && !is_dir($dir)
        ) {
            throw new RuntimeException(
                sprintf(
                    'Directory "%s" was not created',
                    $dir,
                ),
            );
        }

        $image->save($urlInfo['pathVariant'], $targetQuality);

        $extension   = $urlInfo['extension'];
        $pathVariant = $urlInfo['pathVariant'];

        if ($this->isWebpImage($extension) === false && $this->skipWebPCreation($request) === false) {
            $this->generateWebpVariant($image, $targetQuality, $pathVariant);
        }

        if ($this->isAvifImage($extension) === false && $this->skipAvifCreation($request) === false) {
            $this->generateAvifVariant($image, $targetQuality, $pathVariant);
        }

        $response = $this->buildOutputResponse($image, $extension, $targetQuality, $pathVariant);

        $locker->release();

        return $response;
    }

    /**
     * Parse the requested variant URL and derive processing parameters.
     *
     * Computes original/variant file paths, normalizes the extension, and
     * extracts width/height/quality/mode values from the encoded mode string.
     *
     * @param string $variantUrl The decoded variant URL path
     *
     * @return array{
     *     pathVariant: string,
     *     pathOriginal: string,
     *     extension: string,
     *     targetWidth: int|null,
     *     targetHeight: int|null,
     *     targetQuality: int,
     *     processingMode: int,
     * }
     */
    private function gatherInformationBasedOnUrl(string $variantUrl): array
    {
        $information = [];

        preg_match(
            '/^(\/processed\/)(.*)\.([0-9whqm]+)\.([a-zA-Z0-9]{1,4})$/',
            $variantUrl,
            $information,
        );

        $basePath = Environment::getPublicPath();

        $extension = strtolower($information[4] ?? '');

        if ($extension === 'jpeg') {
            $extension = 'jpg';
        }

        return [
            'pathVariant'    => $basePath . $variantUrl,
            'pathOriginal'   => $basePath . '/' . ($information[2] ?? '') . '.' . ($information[4] ?? ''),
            'extension'      => $extension,
            'targetWidth'    => $this->getValueFromMode('w', $information[3] ?? ''),
            'targetHeight'   => $this->getValueFromMode('h', $information[3] ?? ''),
            'targetQuality'  => $this->getValueFromMode('q', $information[3] ?? '') ?? 100,
            'processingMode' => $this->getValueFromMode('m', $information[3] ?? '') ?? 0,
        ];
    }

    /**
     * Extract a numeric value from the compact mode string (e.g., h400w600q80m1).
     *
     * @param string $what Identifier to look for: 'h', 'w', 'q', or 'm'
     * @param string $mode The encoded mode string
     *
     * @return int|null The integer value if present, otherwise null
     */
    private function getValueFromMode(string $what, string $mode): ?int
    {
        if ($mode === '') {
            return null;
        }

        $modeMatch = [];

        if ((bool) preg_match_all('/([hwqm]{1})(\d+)/', $mode, $modeMatch)) {
            $key = array_search($what, $modeMatch[1], true);

            if ($key === false) {
                return null;
            }

            return (int) $modeMatch[2][$key];
        }

        return null;
    }

    /**
     * Load the original image from the disk under a read lock to avoid conflicts.
     *
     * Returns a 503 response if the lock cannot be acquired after several tries,
     * or the loaded image on success.
     *
     * @param string $pathOriginal Absolute filesystem path to the original image
     *
     * @return ImageInterface|ResponseInterface The loaded image or a 503 error response
     */
    private function loadOriginal(string $pathOriginal): ImageInterface|ResponseInterface
    {
        $locker = $this->getLocker($pathOriginal . '-read');

        $count = 0;

        while ($locker->acquire() === false) {
            usleep(100000);
            ++$count;
            if ($count === 10) {
                return $this->responseFactory->createResponse(503)
                    ->withBody($this->streamFactory->createStream('Image is currently being processed'));
            }
        }

        $image = $this->imageManager->read($pathOriginal);
        $locker->release();

        return $image;
    }

    /**
     * Derive the missing target dimension while preserving the original aspect ratio.
     *
     * If only width or height is provided, the other is computed from the image's ratio.
     *
     * @param ImageInterface $image        The loaded image
     * @param int|null       $targetWidth  Target width (may be null)
     * @param int|null       $targetHeight Target height (may be null)
     *
     * @return array{0: int|null, 1: int|null} The resolved [width, height] pair
     */
    private function calculateTargetDimensions(
        ImageInterface $image,
        ?int $targetWidth,
        ?int $targetHeight,
    ): array {
        $aspectRatio = $image->width() / $image->height();

        if (($targetHeight === null)
            && ($targetWidth !== null)
        ) {
            $targetHeight = (int) round($targetWidth / $aspectRatio);
        }

        if (($targetWidth === null)
            && ($targetHeight !== null)
        ) {
            $targetWidth = (int) round($targetHeight * $aspectRatio);
        }

        return [$targetWidth, $targetHeight];
    }

    /**
     * Whether the given extension is WebP.
     *
     * @param string $extension Lowercased file extension
     */
    private function isWebpImage(string $extension): bool
    {
        return $extension === 'webp';
    }

    /**
     * Whether the given extension is AVIF.
     *
     * @param string $extension Lowercased file extension
     */
    private function isAvifImage(string $extension): bool
    {
        return $extension === 'avif';
    }

    /**
     * Fetch a query parameter value from the incoming request URI.
     *
     * @param RequestInterface $request The incoming request
     * @param string           $key     Parameter name
     *
     * @return mixed The raw query value or null if not present
     */
    private function getQueryValue(RequestInterface $request, string $key): mixed
    {
        $query = Query::parse($request->getUri()->getQuery());

        return $query[$key] ?? null;
    }

    /**
     * Check whether WebP generation should be skipped for this request.
     *
     * @param RequestInterface $request The incoming request
     */
    private function skipWebPCreation(RequestInterface $request): bool
    {
        return (bool) $this->getQueryValue($request, 'skipWebP');
    }

    /**
     * Check whether AVIF generation should be skipped for this request.
     *
     * @param RequestInterface $request The incoming request
     */
    private function skipAvifCreation(RequestInterface $request): bool
    {
        return (bool) $this->getQueryValue($request, 'skipAvif');
    }

    /**
     * Encode and persist the WebP variant of the current image.
     *
     * @param ImageInterface $image         The processed image
     * @param int            $targetQuality Output quality (1-100)
     * @param string         $pathVariant   Absolute path (without extension) for the variant
     */
    private function generateWebpVariant(ImageInterface $image, int $targetQuality, string $pathVariant): void
    {
        $image->toWebp($targetQuality);
        $image->save($pathVariant . '.webp');
    }

    /**
     * Encode and persist the AVIF variant of the current image.
     *
     * @param ImageInterface $image         The processed image
     * @param int            $targetQuality Output quality (1-100)
     * @param string         $pathVariant   Absolute path (without extension) for the variant
     */
    private function generateAvifVariant(ImageInterface $image, int $targetQuality, string $pathVariant): void
    {
        $image->toAvif($targetQuality);
        $image->save($pathVariant . '.avif');
    }

    /**
     * Build the PSR-7 response with the best available image representation.
     *
     * Prefers AVIF, then WebP, falling back to the processed original format.
     *
     * @param ImageInterface $image         The processed image
     * @param string         $extension     Lowercased extension of the variant
     * @param int            $targetQuality Output quality (1-100)
     * @param string         $pathVariant   Absolute path (without extension) for the variant
     *
     * @return ResponseInterface The image response with appropriate Content-Type
     */
    private function buildOutputResponse(
        ImageInterface $image,
        string $extension,
        int $targetQuality,
        string $pathVariant,
    ): ResponseInterface {
        if ($this->hasVariantFor($pathVariant, 'avif')) {
            return $this->responseFactory->createResponse(200)
                ->withHeader('Content-Type', 'image/avif')
                ->withBody($this->streamFactory->createStream(
                    (string) file_get_contents($pathVariant . '.avif'),
                ));
        }

        if ($this->hasVariantFor($pathVariant, 'webp')) {
            return $this->responseFactory->createResponse(200)
                ->withHeader('Content-Type', 'image/webp')
                ->withBody($this->streamFactory->createStream(
                    (string) file_get_contents($pathVariant . '.webp'),
                ));
        }

        return $this->responseFactory->createResponse(200)
            ->withHeader('Content-Type', $image->origin()->mimetype())
            ->withBody($this->streamFactory->createStream(
                $image->encodeByExtension($extension, $targetQuality)->toString(),
            ));
    }

    /**
     * Apply the requested resize/crop operation to the image.
     *
     * Mode 0 = cover (crop to fill), Mode 1 = scale to fit inside.
     * If either dimension is missing, no processing is performed.
     *
     * @param ImageInterface $image          The loaded image
     * @param int|null       $targetWidth    Target width in pixels
     * @param int|null       $targetHeight   Target height in pixels
     * @param int            $processingMode Processing mode (0 = cover, 1 = scale)
     *
     * @return ImageInterface The (possibly resized) image
     */
    private function processImage(
        ImageInterface $image,
        ?int $targetWidth,
        ?int $targetHeight,
        int $processingMode,
    ): ImageInterface {
        if (($targetWidth === null)
            || ($targetHeight === null)
        ) {
            return $image;
        }

        match ($processingMode) {
            1       => $image->scale($targetWidth, $targetHeight),
            default => $image->cover($targetWidth, $targetHeight),
        };

        return $image;
    }

    /**
     * Check whether a processed variant file exists for the given extension.
     *
     * @param string $pathVariant Base path (without extension) of the variant
     * @param string $variant     File extension without dot (e.g., 'webp', 'avif')
     *
     * @return bool True if the variant file exists
     */
    private function hasVariantFor(string $pathVariant, string $variant): bool
    {
        return file_exists($pathVariant . '.' . $variant);
    }

    /**
     * Create a TYPO3 lock for the given key to coordinate concurrent image processing.
     *
     * @param string $key Arbitrary identifier (will be hashed into the final lock name)
     *
     * @return LockingStrategyInterface A lock instance that must be acquired/released by the caller
     *
     * @throws LockCreateException If the lock cannot be created by the configured strategy
     */
    public function getLocker(string $key): LockingStrategyInterface
    {
        return $this->lockFactory
            ->createLocker('nr_image_optimize-' . md5($key));
    }
}
