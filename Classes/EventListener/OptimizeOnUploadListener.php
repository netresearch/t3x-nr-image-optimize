<?php

/*
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\NrImageOptimize\EventListener;

use function in_array;

use Netresearch\NrImageOptimize\Service\ImageOptimizer;

use function strtolower;

use TYPO3\CMS\Core\Resource\Event\AfterFileAddedEvent;
use TYPO3\CMS\Core\Resource\Event\AfterFileReplacedEvent;
use TYPO3\CMS\Core\Resource\FileInterface;

final class OptimizeOnUploadListener
{
    /** @var array<string, true> */
    private array $guard = [];

    public function __construct(private readonly ImageOptimizer $optimizer) {}

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
        $storage = $file->getStorage();
        // FAL identifiers are only unique within a storage, so key the guard
        // on storage UID + identifier to avoid cross-storage collisions.
        $id = $storage->getUid() . ':' . $file->getIdentifier();
        if (isset($this->guard[$id])) {
            return; // Re-entrancy guard (e.g. triggered by replaceFile)
        }

        if (!$storage->isOnline()) {
            return;
        }

        $ext = strtolower($file->getExtension());
        if (!in_array($ext, $this->optimizer->supportedExtensions(), true)) {
            return;
        }

        // Disable permission check in CLI/backend-independent context
        $previousPermissions = $storage->getEvaluatePermissions();
        $storage->setEvaluatePermissions(false);

        $this->guard[$id] = true;
        try {
            $this->optimizer->optimize($file, false, null, false);
        } finally {
            unset($this->guard[$id]);
            $storage->setEvaluatePermissions($previousPermissions);
        }
    }
}
