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
        $moduleTemplate->assign('totalSizeBytes', $stats['size']);
        $moduleTemplate->assign('totalSizeHuman', $this->formatBytes($stats['size']));

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

    /**
     * @return array{count: int, size: int}
     */
    private function getDirectoryStats(string $path): array
    {
        $count = 0;
        $size  = 0;

        if (!is_dir($path)) {
            return ['count' => $count, 'size' => $size];
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                ++$count;
                $size += $file->getSize();
            }
        }

        return ['count' => $count, 'size' => $size];
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
