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
use function count;
use function date;
use function floor;
use function is_dir;
use function log;
use function max;
use function min;

use Netresearch\NrImageOptimize\Service\SystemRequirementsService;
use Psr\Http\Message\ResponseInterface;

use function realpath;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

use function round;

use RuntimeException;

use function str_replace;
use function strtolower;

use Throwable;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
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
final class MaintenanceController extends ActionController
{
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
    ) {}

    /**
     * Display the maintenance overview with directory statistics for processed images.
     */
    public function indexAction(): ResponseInterface
    {
        $processedPath = Environment::getPublicPath() . '/processed';
        $stats         = $this->getDirectoryStats($processedPath);

        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->assign('processedPath', $processedPath);
        $moduleTemplate->assign('fileCount', $stats['count']);
        $moduleTemplate->assign('directoryCount', $stats['directories']);
        $moduleTemplate->assign('totalSizeBytes', $stats['size']);
        $moduleTemplate->assign('totalSizeHuman', $this->formatBytes($stats['size']));
        $moduleTemplate->assign('largestFiles', $stats['largestFiles']);
        $moduleTemplate->assign('fileTypes', $stats['fileTypes']);
        $moduleTemplate->assign('oldestFile', $stats['oldestFile']);
        $moduleTemplate->assign('newestFile', $stats['newestFile']);

        return $moduleTemplate->renderResponse('Maintenance/Index');
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
     * Clear all processed image variants and recreate the processed directory.
     *
     * Validates the resolved path matches the expected path to prevent directory
     * traversal attacks before performing deletion.
     */
    public function clearProcessedImagesAction(): ResponseInterface
    {
        $processedPath      = Environment::getPublicPath() . '/processed';
        $resolvedPublicPath = realpath(Environment::getPublicPath());

        if ($resolvedPublicPath === false) {
            throw new RuntimeException('Security check failed: Public path could not be resolved');
        }

        $expectedPath = $resolvedPublicPath . '/processed';

        try {
            if (is_dir($processedPath)) {
                $resolvedPath = realpath($processedPath);

                if ($resolvedPath === false || $resolvedPath !== $expectedPath) {
                    throw new RuntimeException('Security check failed: Path mismatch');
                }

                GeneralUtility::rmdir($processedPath, true);
            }

            GeneralUtility::mkdir($processedPath);

            $this->addFlashMessage(
                $this->getLanguageService()->sL('LLL:EXT:nr_image_optimize/Resources/Private/Language/locallang.xlf:flash.clear.success'),
                '',
                ContextualFeedbackSeverity::OK,
            );
        } catch (Throwable $exception) {
            error_log(sprintf(
                'nr_image_optimize: clearProcessedImages failed: %s in %s:%d',
                $exception->getMessage(),
                $exception->getFile(),
                $exception->getLine(),
            ));

            $this->addFlashMessage(
                $this->getLanguageService()->sL('LLL:EXT:nr_image_optimize/Resources/Private/Language/locallang.xlf:flash.clear.error'),
                '',
                ContextualFeedbackSeverity::ERROR,
            );
        }

        return $this->redirect('index');
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

        $count       = 0;
        $size        = 0;
        $directories = 0;
        $files       = [];
        $fileTypes   = [];
        $oldestFile  = null;
        $newestFile  = null;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

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

            $extension = strtolower((string) $file->getExtension());
            $fileTypes[$extension] ??= ['count' => 0, 'size' => 0];
            ++$fileTypes[$extension]['count'];
            $fileTypes[$extension]['size'] += $fileSize;

            $files[] = [
                'name' => $file->getFilename(),
                'path' => str_replace($path . '/', '', (string) $file->getPathname()),
                'size' => $fileSize,
            ];

            $oldestFile = $this->updateTimestampRecord($oldestFile, $file->getFilename(), $mtime, true);
            $newestFile = $this->updateTimestampRecord($newestFile, $file->getFilename(), $mtime, false);
        }

        $largestFiles = $this->buildLargestFiles($files);

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
     * Sort files by size descending and return the top entries with human-readable sizes.
     *
     * @param list<array{name: string, path: string, size: int}> $files All collected file entries
     *
     * @return list<array{name: string, path: string, size: int, sizeHuman: string}> Top largest files
     */
    private function buildLargestFiles(array $files): array
    {
        usort($files, static fn (array $a, array $b): int => $b['size'] <=> $a['size']);
        $largestFiles = array_slice($files, 0, self::LARGEST_FILES_LIMIT);

        foreach ($largestFiles as &$file) {
            $file['sizeHuman'] = $this->formatBytes($file['size']);
        }

        unset($file);

        return $largestFiles;
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
        return $this->languageServiceFactory->createFromUserPreferences($GLOBALS['BE_USER'] ?? null);
    }
}
