<?php

/**
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\NrImageOptimize;

use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use RuntimeException;
use Throwable;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Locking\Exception\LockCreateException;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;
use TYPO3\CMS\Core\Resource\StorageRepository;

use function array_keys;
use function count;
use function dirname;
use function error_log;
use function file_exists;
use function filemtime;
use function filesize;
use function gmdate;
use function is_dir;
use function is_link;
use function is_string;
use function max;
use function md5;
use function min;
use function mkdir;
use function parse_str;
use function preg_match;
use function preg_match_all;
use function realpath;
use function round;
use function scandir;
use function sprintf;
use function str_contains;
use function str_starts_with;
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
     * Cache-Control max-age for processed images (1 year in seconds).
     * Processed image URLs contain dimension/quality parameters, making them
     * effectively content-addressed -- the URL changes whenever the variant changes.
     */
    private const CACHE_MAX_AGE = 31_536_000;

    /**
     * Maps file extensions to MIME types for processed image responses.
     *
     * @var array<string, string>
     */
    private const EXTENSION_MIME_MAP = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'bmp'  => 'image/bmp',
        'tiff' => 'image/tiff',
        'tif'  => 'image/tiff',
    ];

    /**
     * Regex pattern for parsing variant URLs.
     */
    private const URL_PATTERN = '/^(\/processed\/)((?:(?!\.\.).)*)\.([0-9whqm]*[whqm][0-9whqm]*)\.([a-zA-Z0-9]{1,4})$/';

    /**
     * Regex pattern for extracting mode values (w, h, q, m) with their numeric values.
     */
    private const MODE_PATTERN = '/([hwqm])(\d+)/';

    /**
     * Cached lists of absolute filesystem roots (realpath-resolved) under
     * which image paths are considered safe, keyed by the TYPO3 public path
     * that was in effect when each list was computed.
     *
     * Each entry contains the public path plus the basePath of every
     * Local-driver FAL storage, each resolved through realpath so that
     * legitimately symlinked storage directories (e.g. fileadmin on an
     * NFS/EFS mount) are recognised as allowed. Any symlink inside a storage
     * that points to a location outside these roots is rejected.
     *
     * Keying by public path auto-invalidates the cache when the public path
     * changes — matters for TYPO3 functional tests (each test instance has
     * its own typo3temp/var/tests/functional-XXXX/ root) and long-running
     * worker setups (FrankenPHP, swoole, RoadRunner) that might reinitialise
     * Environment between handler invocations. In a normal HTTP request the
     * public path is constant, so this degenerates to a single-entry cache.
     *
     * @var array<string, list<string>>
     */
    private static array $resolvedAllowedRootsByPublicPath = [];

    /**
     * Initialize the image processor with all required dependencies.
     *
     * @param ImageManager             $imageManager      Intervention Image manager used to read/encode images
     * @param LockFactory              $lockFactory       TYPO3 lock factory for concurrent processing coordination
     * @param ResponseFactoryInterface $responseFactory   PSR-17 response factory
     * @param StreamFactoryInterface   $streamFactory     PSR-17 stream factory
     * @param StorageRepository        $storageRepository FAL storage repository used to expand the set of
     *                                                    filesystem roots from which images may be served
     *                                                    (supports symlinked storage targets, e.g. NFS/EFS)
     */
    public function __construct(
        private readonly ImageManager $imageManager,
        private readonly LockFactory $lockFactory,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly StorageRepository $storageRepository,
    ) {
    }

    /**
     * Entry point invoked by the middleware to handle a processed image request.
     *
     * First checks whether the requested variant (or a preferred AVIF/WebP version)
     * already exists on disk and serves it directly with full HTTP caching headers,
     * avoiding all image processing overhead.
     *
     * If no cached file exists, parses the requested variant from the URI, validates
     * that all derived paths remain within the TYPO3 public root, acquires a processing
     * lock to avoid duplicate work, loads and resizes/crops the original image, writes
     * the processed files (including optional WebP/AVIF variants), and returns a
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

        // Validate that both resolved paths stay within an allowed root
        if (!$this->isPathWithinAllowedRoots($urlInfo['pathOriginal'])
            || !$this->isPathWithinAllowedRoots($urlInfo['pathVariant'])
        ) {
            return $this->responseFactory->createResponse(400);
        }

        // Short-circuit: serve the already-processed file directly from disk,
        // bypassing lock acquisition, image loading, and all processing.
        $cachedResponse = $this->serveCachedVariant($urlInfo['pathVariant'], $urlInfo['extension']);

        if ($cachedResponse instanceof ResponseInterface) {
            return $cachedResponse;
        }

        try {
            $locker = $this->getLocker($variantUrl . '-process');
        } catch (LockCreateException) {
            return $this->responseFactory->createResponse(503);
        }

        $lockResponse = $this->acquireLockWithRetry($locker);

        if ($lockResponse instanceof ResponseInterface) {
            return $lockResponse;
        }

        try {
            // Re-check after acquiring lock: another process may have completed
            // processing while we waited for the lock.
            $cachedResponse = $this->serveCachedVariant($urlInfo['pathVariant'], $urlInfo['extension']);

            if ($cachedResponse instanceof ResponseInterface) {
                return $cachedResponse;
            }

            return $this->processAndRespond($request, $urlInfo);
        } catch (Throwable $exception) {
            error_log(sprintf(
                'nr_image_optimize: Processing failed for "%s": %s in %s:%d',
                $variantUrl,
                $exception->getMessage(),
                $exception->getFile(),
                $exception->getLine(),
            ));

            return $this->responseFactory->createResponse(500);
        } finally {
            $locker->release();
        }
    }

    /**
     * Attempt to serve an already-processed variant directly from disk.
     *
     * Checks for AVIF and WebP variants first (preferred formats), then falls
     * back to the primary variant file. Returns null if no cached file exists.
     *
     * @param string $pathVariant Absolute path to the primary variant file
     * @param string $extension   Lowercased extension of the original request
     *
     * @return ResponseInterface|null The cached response with HTTP caching headers, or null
     */
    private function serveCachedVariant(string $pathVariant, string $extension): ?ResponseInterface
    {
        // Prefer AVIF, then WebP, then the original format. Each step falls
        // through to the next when buildFileResponse returns null — which it
        // does for missing AND for 0-byte files (see buildFileResponse). That
        // matters when e.g. Imagick silently writes an empty .avif because
        // the encoder isn't installed: we must not short-circuit on the empty
        // file and skip a valid WebP/primary that's also on disk.
        if (!$this->isAvifImage($extension)) {
            $response = $this->buildFileResponse($pathVariant . '.avif', 'image/avif');

            if ($response instanceof ResponseInterface) {
                return $response;
            }
        }

        if (!$this->isWebpImage($extension)) {
            $response = $this->buildFileResponse($pathVariant . '.webp', 'image/webp');

            if ($response instanceof ResponseInterface) {
                return $response;
            }
        }

        $mimeType = self::EXTENSION_MIME_MAP[$extension] ?? 'application/octet-stream';

        return $this->buildFileResponse($pathVariant, $mimeType);
    }

    /**
     * Build a PSR-7 response that streams a file from disk with HTTP caching headers.
     *
     * Sets Cache-Control for long-term immutable caching (processed image URLs are
     * content-addressed), ETag based on file path and modification time, and
     * Last-Modified from the filesystem timestamp.
     *
     * @param string $filePath Absolute path to the file to serve
     * @param string $mimeType MIME type for the Content-Type header
     *
     * @return ResponseInterface|null The response or null if the file cannot be read
     */
    private function buildFileResponse(string $filePath, string $mimeType): ?ResponseInterface
    {
        if (!file_exists($filePath)) {
            return null;
        }

        $fileSize  = filesize($filePath);
        $fileMtime = filemtime($filePath);

        // Treat 0-byte variant files as "not present" so buildOutputResponse's
        // AVIF -> WebP -> primary fallback chain skips over them. Empty
        // variant files occur when a driver silently writes nothing (most
        // often: Imagick builds without AVIF encoder support leave an empty
        // .avif next to a valid PNG). Returning that empty file to the
        // client is strictly worse than falling back to the format that
        // actually has pixels.
        if ($fileSize === false || $fileSize === 0) {
            return null;
        }

        $response = $this->responseFactory->createResponse(200)
            ->withHeader('Content-Type', $mimeType)
            ->withHeader('Cache-Control', 'public, max-age=' . self::CACHE_MAX_AGE . ', immutable')
            ->withHeader('Content-Length', (string) $fileSize)
            ->withBody($this->streamFactory->createStreamFromFile($filePath));

        if ($fileMtime !== false) {
            return $response
                ->withHeader('Last-Modified', gmdate('D, d M Y H:i:s', $fileMtime) . ' GMT')
                ->withHeader('ETag', '"' . md5($filePath . $fileMtime) . '"');
        }

        return $response;
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

        $image = $this->imageManager->read($urlInfo['pathOriginal']);

        $targetWidth  = $urlInfo['targetWidth'];
        $targetHeight = $urlInfo['targetHeight'];

        [$targetWidth, $targetHeight] = $this->calculateTargetDimensions(
            $image,
            $targetWidth,
            $targetHeight,
        );

        // Re-clamp dimensions after aspect-ratio calculation to prevent DoS
        $targetWidth  = $this->clampDimension($targetWidth);
        $targetHeight = $this->clampDimension($targetHeight);

        $targetQuality  = $urlInfo['targetQuality'];
        $processingMode = $urlInfo['processingMode'];

        $image = $this->processImage($image, $targetWidth, $targetHeight, $processingMode);

        $this->ensureDirectoryExists(dirname($urlInfo['pathVariant']));

        $image->save($urlInfo['pathVariant'], $targetQuality);

        $extension   = $urlInfo['extension'];
        $pathVariant = $urlInfo['pathVariant'];

        // Parse query parameters once for both skip checks instead of
        // calling parse_str() separately for each flag.
        $queryParams = $this->parseQueryParams($request);

        if (!$this->isWebpImage($extension) && !$queryParams['skipWebP']) {
            try {
                $this->generateWebpVariant($image, $targetQuality, $pathVariant);
            } catch (Throwable $e) {
                error_log('nr_image_optimize: WebP variant failed: ' . $e->getMessage());
            }
        }

        if (!$this->isAvifImage($extension) && !$queryParams['skipAvif']) {
            try {
                $this->generateAvifVariant($image, $targetQuality, $pathVariant);
            } catch (Throwable $e) {
                error_log('nr_image_optimize: AVIF variant failed: ' . $e->getMessage());
            }
        }

        return $this->buildOutputResponse($extension, $pathVariant);
    }

    /**
     * Parse the requested variant URL and derive processing parameters.
     *
     * Returns null if the URL does not match the expected pattern, preventing
     * arbitrary path construction from malformed input.
     *
     * Computes original/variant file paths, normalizes the extension, and
     * extracts width/height/quality/mode values from the encoded mode string
     * in a single regex pass. Dimension and quality values are clamped to safe
     * ranges to prevent denial-of-service through excessive resource allocation.
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
            self::URL_PATTERN,
            $variantUrl,
            $information,
        );

        if ($matched !== 1) {
            return null;
        }

        $basePath     = Environment::getPublicPath();
        $relativePath = $information[2];
        $modeString   = $information[3];
        $rawExtension = $information[4];

        $extension = strtolower($rawExtension);

        if ($extension === 'jpeg') {
            $extension = 'jpg';
        }

        // Parse the mode string once and extract all values in a single pass
        $modeValues = $this->parseAllModeValues($modeString);

        // Clamp dimensions and quality to safe ranges to prevent DoS
        $targetWidth   = $this->clampDimension($modeValues['w'] ?? null);
        $targetHeight  = $this->clampDimension($modeValues['h'] ?? null);
        $targetQuality = $this->clampQuality($modeValues['q'] ?? self::MAX_QUALITY);

        return [
            'pathVariant'    => $basePath . $variantUrl,
            'pathOriginal'   => $basePath . '/' . $relativePath . '.' . $rawExtension,
            'extension'      => $extension,
            'targetWidth'    => $targetWidth,
            'targetHeight'   => $targetHeight,
            'targetQuality'  => $targetQuality,
            'processingMode' => $modeValues['m'] ?? 0,
        ];
    }

    /**
     * Parse all mode values from the compact mode string in a single regex pass.
     *
     * Instead of calling preg_match_all once per identifier (w, h, q, m), this
     * method extracts all key-value pairs in one pass and returns them as an
     * associative array.
     *
     * @param string $mode The encoded mode string (e.g., "w800h400q80m1")
     *
     * @return array<string, int> Associative array of identifier => value pairs
     */
    private function parseAllModeValues(string $mode): array
    {
        $values = [];

        if ($mode === '') {
            return $values;
        }

        $matches = [];

        if ((bool) preg_match_all(self::MODE_PATTERN, $mode, $matches)) {
            $count = count($matches[1]);

            for ($i = 0; $i < $count; ++$i) {
                // First occurrence wins (matches original behavior of array_search)
                if (!isset($values[$matches[1][$i]])) {
                    $values[$matches[1][$i]] = (int) $matches[2][$i];
                }
            }
        }

        return $values;
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
     * public directory or any configured Local FAL storage base path. This
     * prevents path-traversal attacks using encoded or otherwise crafted
     * sequences that escape the web root, while still allowing legitimate
     * setups where e.g. fileadmin is a symlink to an external mount (NFS/EFS).
     *
     * Symlinks are resolved via realpath() so that an attacker (including a
     * compromised admin who places a symlink inside a storage directory)
     * cannot escape the declared roots by pointing a symlink at an arbitrary
     * location such as /etc.
     *
     * NUL bytes in the input are rejected outright: realpath() treats them as
     * a hard error, but the parent-walk fallback would otherwise trim them off
     * and revalidate a misleading parent prefix.
     *
     * @param string $path Absolute filesystem path to validate
     *
     * @return bool True if the path is safely within an allowed root
     */
    private function isPathWithinAllowedRoots(string $path): bool
    {
        if (str_contains($path, "\0")) {
            return false;
        }

        $allowedRoots = $this->getAllowedRoots();

        if ($allowedRoots === []) {
            return false;
        }

        // For existing paths, use realpath to resolve symlinks
        $resolvedPath = realpath($path);

        if ($resolvedPath !== false) {
            return $this->isWithinAnyRoot($resolvedPath, $allowedRoots);
        }

        // For paths that do not yet exist (variant files), resolve the
        // deepest existing parent directory and validate it
        $parent = $path;

        do {
            $previous = $parent;
            $parent   = dirname($parent);

            $resolvedParent = realpath($parent);

            if ($resolvedParent !== false) {
                return $this->isWithinAnyRoot($resolvedParent, $allowedRoots);
            }
        } while ($parent !== $previous);

        return false;
    }

    /**
     * Check whether an already-resolved (realpath'd) absolute path lies within
     * at least one of the allowed filesystem roots.
     *
     * @param string       $resolvedPath Realpath-resolved absolute path
     * @param list<string> $allowedRoots Realpath-resolved absolute roots
     *
     * @return bool True if $resolvedPath equals any root or is nested inside one
     */
    private function isWithinAnyRoot(string $resolvedPath, array $allowedRoots): bool
    {
        foreach ($allowedRoots as $root) {
            if ($resolvedPath === $root) {
                return true;
            }

            if (str_starts_with($resolvedPath, $root . DIRECTORY_SEPARATOR)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build (and cache statically) the list of realpath-resolved absolute
     * roots under which image paths are considered safe.
     *
     * Always includes the TYPO3 public path. Additionally includes the
     * resolved base path of every Local-driver FAL storage so that storages
     * whose directory is a symlink to an external mount (e.g. fileadmin on
     * AWS EFS or another NFS share) remain servable.
     *
     * Storages are silently skipped when their driver type is not "Local",
     * when basePath is missing or empty, or when the configured directory
     * does not (yet) exist on disk and cannot be realpath'd.
     *
     * Throwables from StorageRepository (e.g. during very early bootstrap
     * when TCA is not yet loaded) are caught so that path validation still
     * works against the public root alone. The catch is intentionally broad
     * so uninitialized-readonly-property Errors raised in tests that build
     * Processor via newInstanceWithoutConstructor() are handled as well.
     *
     * @return list<string> Zero or more realpath-resolved allowed roots
     */
    private function getAllowedRoots(): array
    {
        $publicPathRaw = Environment::getPublicPath();

        if (isset(self::$resolvedAllowedRootsByPublicPath[$publicPathRaw])) {
            return self::$resolvedAllowedRootsByPublicPath[$publicPathRaw];
        }

        $roots      = [];
        $publicPath = realpath($publicPathRaw);

        if ($publicPath !== false) {
            $roots[$publicPath] = true;
        }

        try {
            foreach ($this->storageRepository->findAll() as $storage) {
                if ($storage->getDriverType() !== 'Local') {
                    continue;
                }

                $configuration = $storage->getConfiguration();
                $basePath      = $configuration['basePath'] ?? '';

                if (!is_string($basePath)) {
                    continue;
                }

                if ($basePath === '') {
                    continue;
                }

                $pathType = $configuration['pathType'] ?? 'relative';

                $absolutePath = $pathType === 'absolute'
                    ? $basePath
                    : $publicPathRaw . DIRECTORY_SEPARATOR . $basePath;

                $resolvedBasePath = realpath($absolutePath);

                if ($resolvedBasePath !== false) {
                    $roots[$resolvedBasePath] = true;
                }
            }
        } catch (Throwable $e) {
            // StorageRepository may be unusable (e.g. during very early
            // bootstrap). Fall back to whatever roots we already collected
            // and log at warning level so operators see the degradation
            // instead of silently receiving 400s for every storage-backed
            // variant request.
            error_log(sprintf(
                'nr_image_optimize: path validation limited to public root; StorageRepository unavailable: %s',
                $e->getMessage(),
            ));
        }

        // Also add the realpath-resolved target of every symlinked immediate
        // child of the public path. Deployments frequently symlink
        // public/processed and public/uploads (alongside public/fileadmin)
        // to a shared external mount such as AWS EFS, e.g.:
        //
        //   ln -sf /mnt/efs/cms/fileadmin  /var/www/public/fileadmin
        //   ln -sf /mnt/efs/cms/processed  /var/www/public/processed
        //   ln -sf /mnt/efs/cms/uploads    /var/www/public/uploads
        //
        // The FAL-storage lookup above resolves fileadmin (its basePath is
        // declared in sys_file_storage), but `processed` and `uploads` are
        // not FAL storages, so their symlink targets would otherwise be
        // absent from the allowed-roots set. When a not-yet-existing variant
        // path under /processed/ is validated, isPathWithinAllowedRoots()
        // walks up parents, hits public/processed, and realpath() resolves
        // through the symlink to the external mount -- which would be
        // rejected without this expansion, causing HTTP 400 on every
        // uncached variant request.
        if ($publicPath !== false) {
            $entries = @scandir($publicPathRaw);

            if ($entries !== false) {
                foreach ($entries as $entry) {
                    if ($entry === '.') {
                        continue;
                    }

                    if ($entry === '..') {
                        continue;
                    }

                    $childPath = $publicPathRaw . DIRECTORY_SEPARATOR . $entry;

                    if (!is_link($childPath)) {
                        continue;
                    }

                    $resolvedChild = realpath($childPath);

                    if ($resolvedChild !== false) {
                        $roots[$resolvedChild] = true;
                    }
                }
            }
        }

        $resolved = array_keys($roots);

        self::$resolvedAllowedRootsByPublicPath[$publicPathRaw] = $resolved;

        return $resolved;
    }

    /**
     * Parse query parameters from the request URI once.
     *
     * Avoids redundant parse_str() calls when checking multiple query flags.
     *
     * @param RequestInterface $request The incoming request
     *
     * @return array{skipWebP: bool, skipAvif: bool} Parsed query flags
     */
    private function parseQueryParams(RequestInterface $request): array
    {
        $query = [];
        parse_str($request->getUri()->getQuery(), $query);

        return [
            'skipWebP' => is_string($query['skipWebP'] ?? null) && (bool) $query['skipWebP'],
            'skipAvif' => is_string($query['skipAvif'] ?? null) && (bool) $query['skipAvif'],
        ];
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
        for ($attempt = 0; $attempt < self::LOCK_MAX_RETRIES; ++$attempt) {
            try {
                if ($locker->acquire() !== false) {
                    return null;
                }
            } catch (Throwable) {
                // Lock infrastructure failure — treat as lock-not-acquired
            }

            usleep(self::LOCK_RETRY_INTERVAL_USEC);
        }

        // Hardcoded English string is intentional: this is an HTTP API response in a
        // middleware context where LocalizationUtility is not available. The translation
        // key 'processor.error.imageBeingProcessed' exists in the XLF files but cannot
        // be used here.
        return $this->responseFactory->createResponse(503)
            ->withBody($this->streamFactory->createStream(
                'Image is currently being processed',
            ));
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

        if (!@mkdir($directory, self::DIRECTORY_PERMISSIONS, true) && !is_dir($directory)) {
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

        // Nothing to compute when both dimensions are missing
        if ($targetWidth === null && $targetHeight === null) {
            return [null, null];
        }

        // Guard against division by zero for degenerate images
        if ($imageHeight === 0 || $imageWidth === 0) {
            return [$targetWidth, $targetHeight];
        }

        $aspectRatio = $imageWidth / $imageHeight;

        if ($targetHeight === null) {
            $targetHeight = (int) round($targetWidth / $aspectRatio, 0);
        }

        if ($targetWidth === null) {
            $targetWidth = (int) round($targetHeight * $aspectRatio, 0);
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
     * Encode and persist the WebP variant of the current image.
     *
     * @param ImageInterface $image         The processed image
     * @param int            $targetQuality Output quality (1-100)
     * @param string         $pathVariant   Absolute path of the primary variant file
     */
    private function generateWebpVariant(ImageInterface $image, int $targetQuality, string $pathVariant): void
    {
        $image->toWebp($targetQuality)->save($pathVariant . '.webp');
    }

    /**
     * Encode and persist the AVIF variant of the current image.
     *
     * @param ImageInterface $image         The processed image
     * @param int            $targetQuality Output quality (1-100)
     * @param string         $pathVariant   Absolute path of the primary variant file
     */
    private function generateAvifVariant(ImageInterface $image, int $targetQuality, string $pathVariant): void
    {
        $image->toAvif($targetQuality)->save($pathVariant . '.avif');
    }

    /**
     * Build the PSR-7 response with the best available image representation.
     *
     * Prefers AVIF, then WebP, falling back to the processed original format.
     * All responses include HTTP caching headers (Cache-Control, ETag, Last-Modified)
     * since processed image URLs are content-addressed.
     *
     * @param string $extension   Lowercased extension of the variant
     * @param string $pathVariant Absolute path of the primary variant file
     *
     * @return ResponseInterface The image response with appropriate Content-Type and caching headers
     */
    private function buildOutputResponse(
        string $extension,
        string $pathVariant,
    ): ResponseInterface {
        if ($this->hasVariantFor($pathVariant, 'avif')) {
            $response = $this->buildFileResponse($pathVariant . '.avif', 'image/avif');

            if ($response instanceof ResponseInterface) {
                return $response;
            }
        }

        if ($this->hasVariantFor($pathVariant, 'webp')) {
            $response = $this->buildFileResponse($pathVariant . '.webp', 'image/webp');

            if ($response instanceof ResponseInterface) {
                return $response;
            }
        }

        $mimeType = self::EXTENSION_MIME_MAP[$extension] ?? 'application/octet-stream';
        $response = $this->buildFileResponse($pathVariant, $mimeType);

        if ($response instanceof ResponseInterface) {
            return $response;
        }

        // Last resort: return error if file operations failed
        return $this->responseFactory->createResponse(500);
    }

    /**
     * Apply scaling or cropping to the image based on the processing mode.
     *
     * Mode 1 uses scale (fit within bounds), any other value uses cover (crop to fill).
     *
     * @param ImageInterface $image          The image to process
     * @param int|null       $targetWidth    Target width in pixels
     * @param int|null       $targetHeight   Target height in pixels
     * @param int            $processingMode Processing mode (1 = scale, other = cover)
     *
     * @return ImageInterface The processed image
     */
    private function processImage(
        ImageInterface $image,
        ?int $targetWidth,
        ?int $targetHeight,
        int $processingMode,
    ): ImageInterface {
        if ($targetWidth === null || $targetHeight === null) {
            return $image;
        }

        return match ($processingMode) {
            1       => $image->scale($targetWidth, $targetHeight),
            default => $image->cover($targetWidth, $targetHeight),
        };
    }

    /**
     * Check whether a variant file exists for the given format extension.
     *
     * @param string $pathVariant Base path of the primary variant file
     * @param string $variant     Extension of the variant to check (e.g. 'avif', 'webp')
     *
     * @return bool True if the variant file exists on disk
     */
    private function hasVariantFor(string $pathVariant, string $variant): bool
    {
        return file_exists($pathVariant . '.' . $variant);
    }

    /**
     * @param string $key
     *
     * @return LockingStrategyInterface
     *
     * @throws LockCreateException
     */
    private function getLocker(string $key): LockingStrategyInterface
    {
        return $this->lockFactory->createLocker('nr_image_optimize-' . md5($key));
    }
}
