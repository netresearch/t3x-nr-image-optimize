<?php

/*
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\NrImageOptimize\Service;

use function extension_loaded;

use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\ImageManager;
use RuntimeException;

/**
 * Factory for creating an Intervention ImageManager with the best available driver.
 *
 * Prefers the Imagick driver when the ext-imagick PHP extension is loaded,
 * falls back to the GD driver when ext-gd is available, and throws a
 * RuntimeException if neither extension is present.
 *
 * @author  Sebastian Mendel <sebastian.mendel@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 */
final class ImageManagerFactory
{
    /**
     * Create an ImageManager instance using the best available image driver.
     *
     * @return ImageManager Configured image manager
     *
     * @throws RuntimeException         If neither imagick nor gd extension is loaded
     * @throws \Intervention\Image\Exceptions\DriverException If the selected driver fails to initialize
     */
    public function create(): ImageManager
    {
        if (extension_loaded('imagick')) {
            return new ImageManager(new ImagickDriver());
        }

        if (extension_loaded('gd')) {
            return new ImageManager(new GdDriver());
        }

        throw new RuntimeException(
            'No supported image driver available. Install the PHP imagick or gd extension.',
        );
    }
}
