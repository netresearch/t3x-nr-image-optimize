<?php

/*
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\NrImageOptimize\Controller;

use function array_slice;
use function basename;
use function count;
use function date;

use FilesystemIterator;

use function floor;
use function is_dir;
use function json_encode;
use function log;
use function max;
use function min;

use Netresearch\NrImageOptimize\Service\SystemRequirementsService;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function realpath;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

use function round;

use RuntimeException;
use SplFileInfo;

use function str_replace;
use function strtolower;

use Throwable;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Authentication\AbstractUserAuthentication;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

use function uasort;
use function usort;

/**
 * Backend module controller for clearing processed images and checking system requirements.
 */
final class MaintenanceController extends ActionController implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * Number of largest files to display in the directory stats overview.
     */
    private const LARGEST_FILES_LIMIT = 5;

    /**
     * Date format used for file timestamp display.
     */
    private const DATE_FORMAT = 'Y-m-d H:i:s';

    /**
     * @param ModuleTemplateFactory     $moduleTemplateFactory     Factory for backend module templates
     * @param SystemRequirementsService $systemRequirementsService Service to check system requirements
     * @param LanguageServiceFactory    $languageServiceFactory    Factory for localized language services
     */
    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly SystemRequirementsService $systemRequirementsService,
        private readonly LanguageServiceFactory $languageServiceFactory,
    ) {
        $this->logger = new NullLogger();
    }

    /**
     * Display the maintenance overview. Directory statistics are not computed
     * here -- on large "processed" trees the filesystem walk is too slow to
     * block page rendering -- the view loads them asynchronously via
     * {@see self::statisticsAction()}.
     */
    public function indexAction(): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->assign('processedPath', Environment::getPublicPath() . '/processed');

        return $moduleTemplate->renderResponse('Maintenance/Index');
    }

    /**
     * Compute directory statistics for processed images and return them as JSON.
     *
     * Fetched asynchronously by the maintenance overview so opening the module
     * is never blocked by the filesystem walk over "processed".
     */
    public function statisticsAction(): ResponseInterface
    {
        $processedPath = Environment::getPublicPath() . '/processed';
        $stats         = $this->getDirectoryStats($processedPath);

        return $this->jsonResponse(json_encode([
            'processedPath'  => $processedPath,
            'fileCount'      => $stats['count'],
            'directoryCount' => $stats['directories'],
            'totalSizeBytes' => $stats['size'],
            'totalSizeHuman' => $this->formatBytes($stats['size']),
            'largestFiles'   => $stats['largestFiles'],
            'fileTypes'      => $stats['fileTypes'],
            'oldestFile'     => $stats['oldestFile'],
            'newestFile'     => $stats['newestFile'],
        ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    /**
     * Display system requirements and their current status.
     */
    public function systemRequirementsAction(): ResponseInterface
    {
        $data = $this->systemRequirementsService->collect();

        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->assign('requirements', $data);

        return $moduleTemplate->renderResponse('Maintenance/SystemRequirements');
    }

    /**
     * Clear all processed image variants.
     *
     * Empties the "<public>/processed" directory in place: the directory -- or,
     * on Deployer/CI deployments, the symlink pointing at a shared volume -- is
     * kept and only its contents are removed. The resolved target must be a
     * directory named "processed"; the previous realpath-equality check forced
     * the target inside the web root and thus failed on every symlinked
     * deployment.
     */
    public function clearProcessedImagesAction(): ResponseInterface
    {
        $processedPath = Environment::getPublicPath() . '/processed';

        try {
            if (is_dir($processedPath)) {
                // "processed" is commonly a symlink to a shared volume on
                // Deployer/CI deployments. Resolve the real target and require
                // it to actually be a directory named "processed" before
                // deleting its contents -- a guard against a mis-pointed symlink
                // that, unlike realpath-equality, does not force the target to
                // live inside the web root.
                $resolved = realpath($processedPath);

                if ($resolved === false || basename($resolved) !== 'processed') {
                    throw new RuntimeException('Security check failed: unexpected processed path target');
                }

                $this->emptyDirectory($processedPath);
            } else {
                GeneralUtility::mkdir($processedPath);
            }

            $this->addFlashMessage(
                $this->getLanguageService()->sL('LLL:EXT:nr_image_optimize/Resources/Private/Language/locallang.xlf:flash.clear.success'),
                '',
                ContextualFeedbackSeverity::OK,
            );
        } catch (Throwable $exception) {
            $this->getLogger()->error('clearProcessedImages failed', [
                'exception' => $exception,
            ]);

            $this->addFlashMessage(
                $this->getLanguageService()->sL('LLL:EXT:nr_image_optimize/Resources/Private/Language/locallang.xlf:flash.clear.error'),
                '',
                ContextualFeedbackSeverity::ERROR,
            );
        }

        return $this->redirect('index');
    }

    /**
     * Delete every entry inside a directory while keeping the directory itself
     * (or a symlink pointing at it) in place.
     *
     * Removing and recreating the directory would replace a "processed" symlink
     * with a real directory and detach the shared volume on symlinked
     * deployments, so only the children are removed.
     *
     * @param string $path Absolute path to the directory to empty
     */
    private function emptyDirectory(string $path): void
    {
        $entries = new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS);

        // GeneralUtility::rmdir() is symlink-safe: it recurses into real
        // subdirectories but only unlinks a symlinked child, never follows it.
        /** @var SplFileInfo $entry */
        foreach ($entries as $entry) {
            GeneralUtility::rmdir($entry->getPathname(), true);
        }
    }

    /**
     * Gather filesystem statistics for the given directory.
     *
     * @param string $path Absolute path to the directory to scan
     *
     * @return array{
     *     count: int,
     *     size: int,
     *     directories: int,
     *     largestFiles: list<array{name: string, path: string, size: int, sizeHuman: string}>,
     *     fileTypes: array<string, array{count: int, size: int, sizeHuman: string}>,
     *     oldestFile: array{name: string, mtime: int, date: string}|null,
     *     newestFile: array{name: string, mtime: int, date: string}|null,
     * }
     */
    private function getDirectoryStats(string $path): array
    {
        if (!is_dir($path)) {
            return [
                'count'        => 0,
                'size'         => 0,
                'directories'  => 0,
                'largestFiles' => [],
                'fileTypes'    => [],
                'oldestFile'   => null,
                'newestFile'   => null,
            ];
        }

        $count        = 0;
        $size         = 0;
        $directories  = 0;
        $largestFiles = [];
        $fileTypes    = [];
        $oldestFile   = null;
        $newestFile   = null;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isDir()) {
                ++$directories;

                continue;
            }

            if (!$file->isFile()) {
                continue;
            }

            ++$count;
            $fileSize = $file->getSize();
            $size += $fileSize;
            $mtime = $file->getMTime();

            $extension = strtolower($file->getExtension());
            $fileTypes[$extension] ??= ['count' => 0, 'size' => 0];
            ++$fileTypes[$extension]['count'];
            $fileTypes[$extension]['size'] += $fileSize;

            $largestFiles = $this->updateLargestFiles($largestFiles, [
                'name' => $file->getFilename(),
                'path' => str_replace($path . '/', '', $file->getPathname()),
                'size' => $fileSize,
            ]);

            $oldestFile = $this->updateTimestampRecord($oldestFile, $file->getFilename(), $mtime, true);
            $newestFile = $this->updateTimestampRecord($newestFile, $file->getFilename(), $mtime, false);
        }

        foreach ($largestFiles as &$largestFile) {
            $largestFile['sizeHuman'] = $this->formatBytes($largestFile['size']);
        }

        unset($largestFile);

        uasort($fileTypes, static fn (array $a, array $b): int => $b['size'] <=> $a['size']);

        foreach ($fileTypes as &$typeData) {
            $typeData['sizeHuman'] = $this->formatBytes($typeData['size']);
        }

        unset($typeData);

        return [
            'count'        => $count,
            'size'         => $size,
            'directories'  => $directories,
            'largestFiles' => $largestFiles,
            'fileTypes'    => $fileTypes,
            'oldestFile'   => $oldestFile,
            'newestFile'   => $newestFile,
        ];
    }

    /**
     * Update the oldest or newest file record based on modification time.
     *
     * @param array{name: string, mtime: int, date: string}|null $current  Current record
     * @param string                                             $filename File name
     * @param int                                                $mtime    Modification timestamp
     * @param bool                                               $oldest   True to track oldest, false for newest
     *
     * @return array{name: string, mtime: int, date: string} Updated record
     */
    private function updateTimestampRecord(?array $current, string $filename, int $mtime, bool $oldest): array
    {
        if ($current === null
            || ($oldest && $mtime < $current['mtime'])
            || (!$oldest && $mtime > $current['mtime'])
        ) {
            return [
                'name'  => $filename,
                'mtime' => $mtime,
                'date'  => date(self::DATE_FORMAT, $mtime),
            ];
        }

        return $current;
    }

    /**
     * Insert a candidate file into a size-bounded top-files list, keeping it
     * sorted descending by size and capped at {@see self::LARGEST_FILES_LIMIT}.
     *
     * Avoids materializing every scanned file just to sort them once at the
     * end -- a candidate that cannot possibly make the cut (smaller than the
     * current minimum once the list is full) is rejected without sorting.
     *
     * @param list<array{name: string, path: string, size: int}> $largestFiles Current bounded top-files list
     * @param array{name: string, path: string, size: int}       $candidate    File to consider for inclusion
     *
     * @return list<array{name: string, path: string, size: int}> Updated bounded top-files list
     */
    private function updateLargestFiles(array $largestFiles, array $candidate): array
    {
        if (count($largestFiles) >= self::LARGEST_FILES_LIMIT
            && $candidate['size'] <= $largestFiles[count($largestFiles) - 1]['size']
        ) {
            return $largestFiles;
        }

        $largestFiles[] = $candidate;
        usort($largestFiles, static fn (array $a, array $b): int => $b['size'] <=> $a['size']);

        return array_slice($largestFiles, 0, self::LARGEST_FILES_LIMIT);
    }

    /**
     * Format a byte count into a human-readable string with appropriate unit.
     *
     * @param int $bytes Number of bytes
     *
     * @return string Formatted string (e.g., "1.5 MB")
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow   = $bytes > 0 ? (int) floor(log($bytes, 1024)) : 0;
        $pow   = min($pow, count($units) - 1);

        return round($bytes / (1024 ** $pow), 2) . ' ' . $units[$pow];
    }

    /**
     * Get the language service for backend localization.
     *
     * @return LanguageService The language service configured for the current backend user
     */
    private function getLanguageService(): LanguageService
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;

        return $this->languageServiceFactory->createFromUserPreferences(
            $backendUser instanceof AbstractUserAuthentication ? $backendUser : null,
        );
    }

    /**
     * Return a guaranteed non-null logger.
     *
     * The constructor initializes $this->logger to a NullLogger, and
     * LoggerAwareTrait::setLogger() always sets a real logger -- so the
     * property is effectively never null at runtime.  Because the trait
     * declares the property as nullable, PHPStan cannot infer this;
     * the helper narrows the type for static analysis.
     */
    private function getLogger(): LoggerInterface
    {
        return $this->logger ?? new NullLogger();
    }
}
