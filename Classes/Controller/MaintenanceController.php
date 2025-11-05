<?php

/**
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\NrImageOptimize\Controller;

use Psr\Http\Message\ResponseInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

use function is_dir;
use function realpath;

/**
 * Backend module controller for clearing processed images.
 */
final class MaintenanceController extends ActionController
{
    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
    ) {
    }

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

    public function clearProcessedImagesAction(): ResponseInterface
    {
        $processedPath = Environment::getPublicPath() . '/processed';
        $expectedPath  = realpath(Environment::getPublicPath()) . '/processed';

        try {
            if (is_dir($processedPath)) {
                $resolvedPath = realpath($processedPath);

                if ($resolvedPath !== $expectedPath) {
                    throw new RuntimeException('Security check failed: Path mismatch');
                }

                GeneralUtility::rmdir($processedPath, true);
            }

            GeneralUtility::mkdir($processedPath);

            $this->addFlashMessage(
                $this->getLanguageService()->sL('LLL:EXT:nr_image_optimize/Resources/Private/Language/locallang.xlf:flash.clear.success'),
                '',
                ContextualFeedbackSeverity::OK
            );
        } catch (Throwable $e) {
            $this->addFlashMessage(
                $this->getLanguageService()->sL('LLL:EXT:nr_image_optimize/Resources/Private/Language/locallang.xlf:flash.clear.error') . ': ' . $e->getMessage(),
                '',
                ContextualFeedbackSeverity::ERROR
            );
        }

        return $this->redirect('index');
    }

    private function addNotification(string $message, ContextualFeedbackSeverity $severity): void
    {
        $pageRenderer = GeneralUtility::makeInstance(PageRenderer::class);
        $pageRenderer->loadJavaScriptModule('@typo3/backend/notification.js');
        $pageRenderer->addJsInlineCode(
            'nr-image-optimize-notification',
            sprintf(
                'import Notification from "@typo3/backend/notification.js"; Notification.%s("", "%s", 5);',
                match($severity) {
                    ContextualFeedbackSeverity::NOTICE => 'notice',
                    ContextualFeedbackSeverity::INFO => 'info',
                    ContextualFeedbackSeverity::OK => 'success',
                    ContextualFeedbackSeverity::WARNING => 'warning',
                    ContextualFeedbackSeverity::ERROR => 'error',
                },
                addslashes($message)
            )
        );
    }

    /**
     * @return array{count: int, size: int, directories: int, largestFiles: array, fileTypes: array, oldestFile: array|null, newestFile: array|null}
     */
    private function getDirectoryStats(string $path): array
    {
        $count       = 0;
        $size        = 0;
        $directories = 0;
        $files       = [];
        $fileTypes   = [];
        $oldestFile  = null;
        $newestFile  = null;

        if (!is_dir($path)) {
            return [
                'count'        => $count,
                'size'         => $size,
                'directories'  => $directories,
                'largestFiles' => [],
                'fileTypes'    => [],
                'oldestFile'   => null,
                'newestFile'   => null,
            ];
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                ++$directories;

                continue;
            }

            if ($file->isFile()) {
                ++$count;
                $fileSize = $file->getSize();
                $size += $fileSize;
                $mtime = $file->getMTime();

                // Track file types
                $extension = strtolower((string) $file->getExtension());
                if (!isset($fileTypes[$extension])) {
                    $fileTypes[$extension] = ['count' => 0, 'size' => 0];
                }

                ++$fileTypes[$extension]['count'];
                $fileTypes[$extension]['size'] += $fileSize;

                // Track largest files (top 5)
                $files[] = [
                    'name' => $file->getFilename(),
                    'path' => str_replace($path . '/', '', (string) $file->getPathname()),
                    'size' => $fileSize,
                ];

                // Track oldest and newest
                if ($oldestFile === null || $mtime < $oldestFile['mtime']) {
                    $oldestFile = ['name' => $file->getFilename(), 'mtime' => $mtime, 'date' => date('Y-m-d H:i:s', $mtime)];
                }

                if ($newestFile === null || $mtime > $newestFile['mtime']) {
                    $newestFile = ['name' => $file->getFilename(), 'mtime' => $mtime, 'date' => date('Y-m-d H:i:s', $mtime)];
                }
            }
        }

        // Sort and limit largest files
        usort($files, static fn (array $a, array $b): int => $b['size'] <=> $a['size']);
        $largestFiles = array_slice($files, 0, 5);

        // Format file sizes
        foreach ($largestFiles as &$file) {
            $file['sizeHuman'] = $this->formatBytes($file['size']);
        }

        // Sort file types by size
        uasort($fileTypes, static fn ($a, $b): int => $b['size'] <=> $a['size']);

        // Format file type sizes
        foreach ($fileTypes as &$typeData) {
            $typeData['sizeHuman'] = $this->formatBytes($typeData['size']);
        }

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

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow   = $bytes > 0 ? (int) floor(log($bytes, 1024)) : 0;
        $pow   = min($pow, count($units) - 1);

        return round($bytes / (1024 ** $pow), 2) . ' ' . $units[$pow];
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
