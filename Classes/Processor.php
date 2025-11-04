<?php

/**
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\NrImageOptimize;

use GuzzleHttp\Psr7\Query;
use Intervention\Image\Drivers\Imagick\Driver;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Locking\Exception\LockCreateException;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\HttpUtility;

use function array_search;
use function dirname;
use function file_exists;
use function file_get_contents;
use function header;
use function is_dir;
use function md5;
use function mkdir;
use function preg_match;
use function preg_match_all;
use function round;
use function sprintf;
use function strtolower;
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
 * @link    https://www.netresearch.de
 */
class Processor
{
    /**
     * The incoming PSR-7 request used to determine variant path and query flags.
     *
     * @var RequestInterface
     */
    private RequestInterface $request;

    /**
     * URL path of the requested processed variant (decoded).
     */
    private string $variantUrl;

    /**
     * Intervention Image manager used to read/encode images.
     *
     * @var ImageManager
     */
    private readonly ImageManager $imageManager;

    /**
     * The currently loaded original image.
     *
     * @var ImageInterface
     */
    private ImageInterface $image;

    /**
     * Target width in pixels (null when not specified).
     */
    private ?int $targetWidth = null;

    /**
     * Target height in pixels (null when not specified).
     */
    private ?int $targetHeight = null;

    /**
     * Output quality (1-100) for encoding.
     */
    private int $targetQuality = 80;

    /**
     * Processing mode: 0 = cover (crop), 1 = fit (scale to contain).
     */
    private int $processingMode;

    /**
     * Absolute filesystem path to the original image file.
     */
    private string $pathOriginal;

    /**
     * Absolute filesystem path (without extension) for the processed variant.
     */
    private string $pathVariant;

    /**
     * Lowercased extension of the requested variant (e.g., jpg, webp, avif).
     */
    private string $extension;

    /**
     * Initialize the image processor.
     *
     * The constructor checks for external optimization tools and creates the
     * Intervention Image manager using the Imagick driver.
     */
    public function __construct()
    {
        $this->imageManager = new ImageManager(
            new Driver()
        );
    }

    /**
     * Set the current PSR-7 request to be used for processing.
     *
     * @param RequestInterface $request Incoming request containing the processed URL and query params
     */
    public function setRequest(RequestInterface $request): void
    {
        $this->request = $request;
    }

    /**
     * Parse the requested variant URL and derive processing parameters.
     *
     * Computes original/variant file paths, normalizes the extension, and
     * extracts width/height/quality/mode values from the encoded mode string.
     */
    private function gatherInformationBasedOnUrl(): void
    {
        $information = [];

        preg_match(
            '/^(\/processed\/)(.*)\.([0-9whqm]+)\.([a-zA-Z0-9]{1,4})$/',
            $this->variantUrl,
            $information
        );

        $basePath = Environment::getPublicPath();

        $this->pathVariant  = $basePath . $this->variantUrl;
        $this->pathOriginal = $basePath . '/' . ($information[2] ?? '') . '.' . ($information[4] ?? '');
        $this->extension    = strtolower($information[4] ?? '');

        if ($this->extension === 'jpeg') {
            $this->extension = 'jpg';
        }

        $this->targetWidth    = $this->getValueFromMode('w', $information[3] ?? '');
        $this->targetHeight   = $this->getValueFromMode('h', $information[3] ?? '');
        $this->targetQuality  = $this->getValueFromMode('q', $information[3] ?? '') ?? 100;
        $this->processingMode = $this->getValueFromMode('m', $information[3] ?? '') ?? 0;
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
     * Sends a 503 response if the lock cannot be acquired after several tries.
     */
    private function loadOriginal(): void
    {
        $locker = $this->getLocker($this->pathOriginal . '-read');

        $count = 0;

        while ($locker->acquire() === false) {
            usleep(100000);
            ++$count;
            if ($count === 10) {
                header(HttpUtility::HTTP_STATUS_503);
                echo 'Image is currently being processed';
                exit;
            }
        }

        $this->image = $this->imageManager->read($this->pathOriginal);
        $locker->release();
    }

    /**
     * Derive the missing target dimension while preserving the original aspect ratio.
     *
     * If only width or height is provided, the other is computed from the image's ratio.
     */
    private function calculateTargetDimensions(): void
    {
        $aspectRatio = $this->image->width() / $this->image->height();

        if (($this->targetHeight === null)
            && ($this->targetWidth !== null)
        ) {
            $this->targetHeight = (int) round($this->targetWidth / $aspectRatio);
        }

        if (($this->targetWidth === null)
            && ($this->targetHeight !== null)
        ) {
            $this->targetWidth = (int) round($this->targetHeight * $aspectRatio);
        }
    }

    /**
     * Whether the requested variant's extension is WebP.
     */
    private function isWebpImage(): bool
    {
        return $this->extension === 'webp';
    }

    /**
     * Whether the requested variant's extension is AVIF.
     */
    private function isAvifImage(): bool
    {
        return $this->extension === 'avif';
    }

    /**
     * Fetch a query parameter value from the incoming request URI.
     *
     * @param string $key Parameter name
     *
     * @return mixed The raw query value or null if not present
     */
    private function getQueryValue(string $key): mixed
    {
        $query = Query::parse($this->request->getUri()->getQuery());

        return $query[$key] ?? null;
    }

    /**
     * Check whether WebP generation should be skipped for this request.
     */
    private function skipWebPCreation(): bool
    {
        return (bool) $this->getQueryValue('skipWebP');
    }

    /**
     * Check whether AVIF generation should be skipped for this request.
     */
    private function skipAvifCreation(): bool
    {
        return (bool) $this->getQueryValue('skipAvif');
    }

    /**
     * Encode and persist the WebP variant of the current image.
     */
    private function generateWebpVariant(): void
    {
        $this->performWithoutMutatingOriginal(function (): void {
            $this->image->toWebp($this->targetQuality);
            $this->image->save($this->pathVariant . '.webp');
        });
    }

    /**
     * Encode and persist the AVIF variant of the current image.
     */
    private function generateAvifVariant(): void
    {
        $this->performWithoutMutatingOriginal(function (): void {
            $this->image->toAvif($this->targetQuality);
            $this->image->save($this->pathVariant . '.avif');
        });
    }

    /**
     * Execute an image operation without keeping the mutated instance afterwards.
     *
     * The Intervention image encoder mutates the underlying image resource when
     * converting to another format (e.g. WebP or AVIF). This breaks alpha
     * transparency for the subsequently streamed response because the main image
     * instance is no longer the original PNG/GIF. By cloning the original state
     * beforehand and restoring it afterwards, we ensure later operations (like
     * streaming the processed PNG) still work with an unmodified image.
     */
    private function performWithoutMutatingOriginal(callable $operation): void
    {
        $original = clone $this->image;

        try {
            $operation();
        } finally {
            $this->image = $original;
        }
    }

    /**
     * Stream the best available image representation to the client.
     *
     * Prefers AVIF, then WebP, falling back to the processed original format.
     * Sets an appropriate Content-Type header and echoes the binary content.
     */
    private function output(): void
    {
        if ($this->hasVariantFor('avif')) {
            header('Content-Type: image/avif');
            echo file_get_contents($this->pathVariant . '.avif');

            return;
        }

        if ($this->hasVariantFor('webp')) {
            header('Content-Type: image/webp');
            echo file_get_contents($this->pathVariant . '.webp');

            return;
        }

        header('Content-Type: ' . $this->image->origin()->mimetype());
        echo $this->image->encodeByExtension($this->extension, $this->targetQuality)->toString();
    }

    /**
     * Entry point invoked by the middleware to handle a processed image request.
     *
     * Parses the requested variant from the URI, acquires a processing lock to
     * avoid duplicate work, loads and resizes/crops the original image, writes
     * the processed files (including optional WebP/AVIF variants), and streams
     * the best available representation back to the client.
     *
     * Sends 404 if the original image is missing and 503 if a lock cannot be
     * acquired in time. This method echoes the response body and sets headers.
     */
    public function generateAndSend(): void
    {
        $this->variantUrl = urldecode($this->request->getUri()->getPath());

        $locker    = $this->getLocker($this->variantUrl . '-process');
        $lockCount = 0;

        while ($locker->acquire() === false) {
            usleep(100000);

            ++$lockCount;

            if ($lockCount === 10) {
                header(HttpUtility::HTTP_STATUS_503);
                echo 'Image is currently being processed';
                exit;
            }
        }

        $this->gatherInformationBasedOnUrl();

        if (!file_exists($this->pathOriginal)) {
            header(HttpUtility::HTTP_STATUS_404);
            exit;
        }

        $this->loadOriginal();
        $this->calculateTargetDimensions();
        $this->processImage();

        $dir = dirname($this->pathVariant);

        if (!is_dir($dir)
            && !mkdir($dir, 0775, true)
            && !is_dir($dir)
        ) {
            throw new RuntimeException(
                sprintf(
                    'Directory "%s" was not created',
                    $dir
                )
            );
        }

        $this->image->save($this->pathVariant, $this->targetQuality);

        if ($this->isWebpImage() === false && $this->skipWebPCreation() === false) {
            $this->generateWebpVariant();
        }

        if ($this->isAvifImage() === false && $this->skipAvifCreation() === false) {
            $this->generateAvifVariant();
        }

        $this->output();

        $locker->release();
    }

    /**
     * Apply the requested resize/crop operation to the image.
     *
     * Mode 0 = cover (crop to fill), Mode 1 = scale to fit inside.
     * If either dimension is missing, no processing is performed.
     */
    private function processImage(): void
    {
        if (($this->targetWidth === null)
            || ($this->targetHeight === null)
        ) {
            return;
        }

        match ($this->processingMode) {
            1       => $this->image->scale($this->targetWidth, $this->targetHeight),
            default => $this->image->cover($this->targetWidth, $this->targetHeight),
        };
    }

    /**
     * Check whether a processed variant file exists for the given extension.
     *
     * @param string $variant File extension without dot (e.g., 'webp', 'avif')
     *
     * @return bool True if the variant file exists
     */
    private function hasVariantFor(string $variant): bool
    {
        return file_exists($this->pathVariant . '.' . $variant);
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
        return GeneralUtility::makeInstance(LockFactory::class)
            ->createLocker('nr_image_optimize-' . md5($key));
    }
}
