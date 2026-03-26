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
use function max;
use function md5;
use function min;
use function mkdir;
use function preg_match;
use function preg_match_all;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

use function realpath;
use function round;

use RuntimeException;

use function sprintf;
use function str_starts_with;
use function strtolower;

use Throwable;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Locking\Exception\LockCreateException;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

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
     * Maximum number of attempts to acquire a lock before returning a 503 response.
     */
    private const LOCK_MAX_RETRIES = 10;

    /**
     * Microseconds to wait between lock acquisition attempts (100 ms).
     */
    private const LOCK_RETRY_INTERVAL_USEC = 100_000;

    /**
     * Directory permissions used when creating variant output directories.
     */
    private const DIRECTORY_PERMISSIONS = 0o775;

    /**
     * Maximum allowed dimension (width or height) in pixels to prevent
     * denial-of-service through excessive memory allocation.
     */
    private const MAX_DIMENSION = 8192;

    /**
     * Minimum allowed output quality (1-100).
     */
    private const MIN_QUALITY = 1;

    /**
     * Maximum allowed output quality (1-100).
     */
    private const MAX_QUALITY = 100;

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
     * Parses the requested variant from the URI, validates that all derived paths
     * remain within the TYPO3 public root, acquires a processing lock to avoid
     * duplicate work, loads and resizes/crops the original image, writes the
     * processed files (including optional WebP/AVIF variants), and returns a
     * PSR-7 response with the best available representation.
     *
     * Returns 400 if the URL does not match the expected pattern or path validation
     * fails, 404 if the original image is missing, 500 on processing errors, and
     * 503 if a lock cannot be acquired in time.
     *
     * @param RequestInterface $request Incoming request containing the processed URL and query params
     *
     * @return ResponseInterface The image response or an error response
     */
    public function generateAndSend(RequestInterface $request): ResponseInterface
    {
        $variantUrl = urldecode($request->getUri()->getPath());

        $urlInfo = $this->gatherInformationBasedOnUrl($variantUrl);

        // Reject requests that did not match the expected URL pattern
        if ($urlInfo === null) {
            return $this->responseFactory->createResponse(400);
        }

        // Validate that both resolved paths stay within the public root
        if (!$this->isPathWithinPublicRoot($urlInfo['pathOriginal'])
            || !$this->isPathWithinPublicRoot($urlInfo['pathVariant'])
        ) {
            return $this->responseFactory->createResponse(400);
        }

        $locker = $this->getLocker($variantUrl . '-process');

        $lockResponse = $this->acquireLockWithRetry($locker);

        if ($lockResponse instanceof ResponseInterface) {
            return $lockResponse;
        }

        try {
            return $this->processAndRespond($request, $urlInfo);
        } catch (Throwable) {
            return $this->responseFactory->createResponse(500);
        } finally {
            $locker->release();
        }
    }

    /**
     * Perform the actual image processing and build the response.
     *
     * Extracted from generateAndSend to ensure the lock is always released
     * via the try/finally in the caller, even when exceptions occur.
     *
     * @param RequestInterface $request Incoming request
     * @param array{
     *     pathVariant: string,
     *     pathOriginal: string,
     *     extension: string,
     *     targetWidth: int|null,
     *     targetHeight: int|null,
     *     targetQuality: int,
     *     processingMode: int,
     * }                       $urlInfo Parsed URL information
     *
     * @return ResponseInterface The image response or an error response
     */
    private function processAndRespond(
        RequestInterface $request,
        array $urlInfo,
    ): ResponseInterface {
        if (!file_exists($urlInfo['pathOriginal'])) {
            return $this->responseFactory->createResponse(404);
        }

        $image = $this->loadOriginal($urlInfo['pathOriginal']);

        if ($image instanceof ResponseInterface) {
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

        $this->ensureDirectoryExists(dirname($urlInfo['pathVariant']));

        $image->save($urlInfo['pathVariant'], $targetQuality);

        $extension   = $urlInfo['extension'];
        $pathVariant = $urlInfo['pathVariant'];

        if (!$this->isWebpImage($extension) && !$this->skipWebPCreation($request)) {
            $this->generateWebpVariant($image, $targetQuality, $pathVariant);
        }

        if (!$this->isAvifImage($extension) && !$this->skipAvifCreation($request)) {
            $this->generateAvifVariant($image, $targetQuality, $pathVariant);
        }

        return $this->buildOutputResponse($image, $extension, $targetQuality, $pathVariant);
    }

    /**
     * Parse the requested variant URL and derive processing parameters.
     *
     * Returns null if the URL does not match the expected pattern, preventing
     * arbitrary path construction from malformed input.
     *
     * Computes original/variant file paths, normalizes the extension, and
     * extracts width/height/quality/mode values from the encoded mode string.
     * Dimension and quality values are clamped to safe ranges to prevent
     * denial-of-service through excessive resource allocation.
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
     * }|null Parsed parameters or null if the URL does not match
     */
    private function gatherInformationBasedOnUrl(string $variantUrl): ?array
    {
        $information = [];

        $matched = preg_match(
            '/^(\/processed\/)((?:(?!\.\.).)*)\.([0-9whqm]+)\.([a-zA-Z0-9]{1,4})$/',
            $variantUrl,
            $information,
        );

        if ($matched !== 1) {
            return null;
        }

        $basePath = Environment::getPublicPath();

        $extension = strtolower($information[4] ?? '');

        if ($extension === 'jpeg') {
            $extension = 'jpg';
        }

        // Clamp dimensions and quality to safe ranges to prevent DoS
        $targetWidth   = $this->clampDimension($this->getValueFromMode('w', $information[3] ?? ''));
        $targetHeight  = $this->clampDimension($this->getValueFromMode('h', $information[3] ?? ''));
        $targetQuality = $this->clampQuality(
            $this->getValueFromMode('q', $information[3] ?? '') ?? self::MAX_QUALITY,
        );

        return [
            'pathVariant'    => $basePath . $variantUrl,
            'pathOriginal'   => $basePath . '/' . ($information[2] ?? '') . '.' . ($information[4] ?? ''),
            'extension'      => $extension,
            'targetWidth'    => $targetWidth,
            'targetHeight'   => $targetHeight,
            'targetQuality'  => $targetQuality,
            'processingMode' => $this->getValueFromMode('m', $information[3] ?? '') ?? 0,
        ];
    }

    /**
     * Clamp a dimension value to the allowed range.
     *
     * Returns null if input is null (dimension not specified), otherwise
     * restricts to 1..MAX_DIMENSION to prevent zero-pixel images and
     * excessive memory allocation.
     *
     * @param int|null $value Raw dimension from URL
     *
     * @return int|null Clamped dimension or null
     */
    private function clampDimension(?int $value): ?int
    {
        if ($value === null) {
            return null;
        }

        return max(1, min($value, self::MAX_DIMENSION));
    }

    /**
     * Clamp quality to the valid 1-100 range.
     *
     * @param int $value Raw quality from URL
     *
     * @return int Clamped quality value
     */
    private function clampQuality(int $value): int
    {
        return max(self::MIN_QUALITY, min($value, self::MAX_QUALITY));
    }

    /**
     * Validate that a filesystem path resolves to a location within the TYPO3
     * public directory. This prevents path-traversal attacks using encoded or
     * otherwise crafted sequences that escape the web root.
     *
     * @param string $path Absolute filesystem path to validate
     *
     * @return bool True if the path is safely within the public root
     */
    private function isPathWithinPublicRoot(string $path): bool
    {
        $publicPath = realpath(Environment::getPublicPath());

        if ($publicPath === false) {
            return false;
        }

        // For existing paths, use realpath to resolve symlinks
        $resolvedPath = realpath($path);

        if ($resolvedPath !== false) {
            return str_starts_with($resolvedPath, $publicPath);
        }

        // For paths that do not yet exist (variant files), resolve the
        // deepest existing parent directory and validate it
        $parent = $path;

        while (($parent = dirname($parent)) !== $parent) {
            $resolvedParent = realpath($parent);

            if ($resolvedParent !== false) {
                return str_starts_with($resolvedParent, $publicPath);
            }
        }

        return false;
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
     * or the loaded image on success. The read lock is always released, even if
     * the image read throws an exception.
     *
     * @param string $pathOriginal Absolute filesystem path to the original image
     *
     * @return ImageInterface|ResponseInterface The loaded image or a 503 error response
     */
    private function loadOriginal(string $pathOriginal): ImageInterface|ResponseInterface
    {
        $locker = $this->getLocker($pathOriginal . '-read');

        $lockResponse = $this->acquireLockWithRetry($locker);

        if ($lockResponse instanceof ResponseInterface) {
            return $lockResponse;
        }

        try {
            return $this->imageManager->read($pathOriginal);
        } finally {
            $locker->release();
        }
    }

    /**
     * Attempt to acquire a lock with retries, returning a 503 response on failure.
     *
     * @param LockingStrategyInterface $locker The lock to acquire
     *
     * @return ResponseInterface|null Null on success, 503 response if lock could not be acquired
     */
    private function acquireLockWithRetry(LockingStrategyInterface $locker): ?ResponseInterface
    {
        $retryCount = 0;

        while ($locker->acquire() === false) {
            usleep(self::LOCK_RETRY_INTERVAL_USEC);

            ++$retryCount;

            if ($retryCount === self::LOCK_MAX_RETRIES) {
                return $this->responseFactory->createResponse(503)
                    ->withBody($this->streamFactory->createStream(
                        LocalizationUtility::translate('processor.error.imageBeingProcessed', 'NrImageOptimize')
                            ?? 'Image is currently being processed',
                    ));
            }
        }

        return null;
    }

    /**
     * Ensure a directory exists, creating it recursively if needed.
     *
     * @param string $directory Absolute path to the directory
     *
     * @throws RuntimeException If the directory cannot be created
     */
    private function ensureDirectoryExists(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (!mkdir($directory, self::DIRECTORY_PERMISSIONS, true) && !is_dir($directory)) {
            throw new RuntimeException(
                sprintf(
                    'Directory "%s" was not created',
                    $directory,
                ),
            );
        }
    }

    /**
     * Derive the missing target dimension while preserving the original aspect ratio.
     *
     * If only width or height is provided, the other is computed from the image's ratio.
     * Handles zero-dimension images safely to avoid division by zero.
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
        $imageHeight = $image->height();
        $imageWidth  = $image->width();

        // Guard against division by zero for degenerate images
        if ($imageHeight === 0 || $imageWidth === 0) {
            return [$targetWidth, $targetHeight];
        }

        $aspectRatio = $imageWidth / $imageHeight;

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
     *
     * @return bool True if the extension is 'webp'
     */
    private function isWebpImage(string $extension): bool
    {
        return $extension === 'webp';
    }

    /**
     * Whether the given extension is AVIF.
     *
     * @param string $extension Lowercased file extension
     *
     * @return bool True if the extension is 'avif'
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
     *
     * @return bool True if WebP generation should be skipped
     */
    private function skipWebPCreation(RequestInterface $request): bool
    {
        return (bool) $this->getQueryValue($request, 'skipWebP');
    }

    /**
     * Check whether AVIF generation should be skipped for this request.
     *
     * @param RequestInterface $request The incoming request
     *
     * @return bool True if AVIF generation should be skipped
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
