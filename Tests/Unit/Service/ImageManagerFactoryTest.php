<?php

/*
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\NrImageOptimize\Tests\Unit\Service;

use function extension_loaded;

use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\ImageManager;
use Netresearch\NrImageOptimize\Service\ImageManagerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

#[CoversClass(ImageManagerFactory::class)]
class ImageManagerFactoryTest extends TestCase
{
    private ImageManagerFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = new ImageManagerFactory();
    }

    #[Test]
    public function createReturnsImageManagerWithAvailableDriver(): void
    {
        if (!extension_loaded('imagick') && !extension_loaded('gd')) {
            self::markTestSkipped('Neither imagick nor gd extension is available.');
        }

        $imageManager = $this->factory->create();

        $reflection = new ReflectionProperty(ImageManager::class, 'driver');
        $driver     = $reflection->getValue($imageManager);

        self::assertTrue(
            $driver instanceof ImagickDriver || $driver instanceof GdDriver,
            'Expected either Imagick or GD driver',
        );
    }

    #[Test]
    public function createPrefersImagickOverGd(): void
    {
        if (!extension_loaded('imagick')) {
            self::markTestSkipped('Imagick extension is not available.');
        }

        $imageManager = $this->factory->create();

        $reflection = new ReflectionProperty(ImageManager::class, 'driver');
        $driver     = $reflection->getValue($imageManager);

        self::assertInstanceOf(ImagickDriver::class, $driver);
    }

    #[Test]
    public function createFallsBackToGdWhenImagickUnavailable(): void
    {
        if (!extension_loaded('gd')) {
            self::markTestSkipped('GD extension is not available.');
        }

        if (extension_loaded('imagick')) {
            self::markTestSkipped(
                'Cannot test GD fallback because imagick extension is loaded.',
            );
        }

        $imageManager = $this->factory->create();

        $reflection = new ReflectionProperty(ImageManager::class, 'driver');
        $driver     = $reflection->getValue($imageManager);

        self::assertInstanceOf(GdDriver::class, $driver);
    }
}
