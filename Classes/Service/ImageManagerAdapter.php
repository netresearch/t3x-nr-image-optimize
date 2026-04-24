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
        $this->readCallback = $this->resolveReadMethod($imageManager);
    }

    /**
     * Detect whether the ImageManager exposes read() (v3) or decode() (v4)
     * and return a closure bound to the correct method.
     *
     * The object parameter type prevents PHPStan from statically narrowing
     * the method_exists() check against a single installed library version.
     *
     * @return Closure(string): ImageInterface
     */
    private function resolveReadMethod(object $manager): Closure
    {
        $method = method_exists($manager, 'read') ? 'read' : 'decode';

        /** @var Closure(string): ImageInterface */
        return $manager->{$method}(...);
    }

    public function read(string $path): ImageInterface
    {
        $result = ($this->readCallback)($path);
        assert($result instanceof ImageInterface);

        return $result;
    }
}
