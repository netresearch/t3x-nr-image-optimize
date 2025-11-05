<?php

/**
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\NrImageOptimize\EventListener;

use TYPO3\CMS\Backend\Controller\Event\ModifyTemporaryAssetsEvent;
use TYPO3\CMS\Backend\Module\Maintenance\RemoveTemporaryAssets\TemporaryAsset;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;

use function glob;
use function is_dir;

/**
 * Adds the on-demand processed images of this extension to the "Remove Temporary Assets"
 * maintenance module so editors can flush them individually via the TYPO3 backend.
 */
final class RegisterTemporaryAssetEventListener
{
    private const IDENTIFIER = 'nr-image-optimize-processed-images';

    private const TITLE_IDENTIFIER = 'LLL:EXT:nr_image_optimize/Resources/Private/Language/locallang.xlf:maintenance.removeTemporaryAssets.processedImages.title';

    private const DESCRIPTION_IDENTIFIER = 'LLL:EXT:nr_image_optimize/Resources/Private/Language/locallang.xlf:maintenance.removeTemporaryAssets.processedImages.description';

    private const ICON_IDENTIFIER = 'actions-edit-delete';

    public function __invoke(ModifyTemporaryAssetsEvent $event): void
    {
        $processedDirectory = Environment::getPublicPath() . '/processed';

        $temporaryAsset = TemporaryAsset::create(self::IDENTIFIER)
            ->withTitle(self::TITLE_IDENTIFIER)
            ->withDescription(self::DESCRIPTION_IDENTIFIER)
            ->withIconIdentifier(self::ICON_IDENTIFIER)
            ->withAvailability(fn (): bool => $this->directoryContainsFiles($processedDirectory))
            ->withExecution(function () use ($processedDirectory): void {
                if (!is_dir($processedDirectory)) {
                    return;
                }

                GeneralUtility::rmdir($processedDirectory, true);
            });

        $event->addTemporaryAsset($temporaryAsset);
    }

    private function directoryContainsFiles(string $path): bool
    {
        if (!is_dir($path)) {
            return false;
        }

        $files = glob($path . '/*');

        return $files !== false && $files !== [];
    }
}
