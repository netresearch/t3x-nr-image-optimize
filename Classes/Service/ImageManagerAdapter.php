<?php

/*
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\NrImageOptimize\Service;

use Closure;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;

/**
 * Adapter that bridges Intervention Image v3 and v4 API differences.
 *
 * v3 exposes ImageManager::read() for loading images while v4 replaced
 * it with ImageManager::decode(). This adapter detects the available
 * method at construction time and delegates accordingly, allowing the
 * rest of the codebase to depend on {@see ImageReaderInterface} without
 * any version-conditional logic.
 *
 * @author  Sebastian Mendel <sebastian.mendel@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 */
final readonly class ImageManagerAdapter implements ImageReaderInterface
{
    /**
     * @var Closure(string): ImageInterface
     */
    private Closure $readCallback;

    public function __construct(ImageManager $imageManager)
    {
        $this->readCallback = method_exists($imageManager, 'read')
            ? $imageManager->read(...)
            : $imageManager->decode(...);
    }

    public function read(string $path): ImageInterface
    {
        return ($this->readCallback)($path);
    }
}
