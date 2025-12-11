<?php

/**
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\NrImageOptimize\EventListener;

use Netresearch\NrImageOptimize\Service\ImageOptimizer;
use TYPO3\CMS\Core\Resource\Event\AfterFileAddedEvent;
use TYPO3\CMS\Core\Resource\Event\AfterFileReplacedEvent;
use TYPO3\CMS\Core\Resource\FileInterface;

use function in_array;
use function strtolower;

final class OptimizeOnUploadListener
{
    /** @var array<string, true> */
    private array $guard = [];

    public function __construct(private readonly ImageOptimizer $optimizer)
    {
    }

    public function optimizeAfterAdd(AfterFileAddedEvent $event): void
    {
        $this->handle($event->getFile());
    }

    public function optimizeAfterReplace(AfterFileReplacedEvent $event): void
    {
        $this->handle($event->getFile());
    }

    private function handle(FileInterface $file): void
    {
        $id = $file->getIdentifier();
        if (isset($this->guard[$id])) {
            return; // Re-Entrancy (z. B. durch replaceFile ausgelöst)
        }

        $storage = $file->getStorage();
        if (!$storage->isOnline()) {
            return;
        }

        $ext = strtolower($file->getExtension());
        if (!in_array($ext, $this->optimizer->supportedExtensions(), true)) {
            return;
        }

        // Rechteprüfung im CLI/BE-unabhängigen Kontext deaktivieren
        $storage->setEvaluatePermissions(false);

        $this->guard[$id] = true;
        try {
            $this->optimizer->optimize($file, false, null, false);
        } finally {
            unset($this->guard[$id]);
        }
    }
}
