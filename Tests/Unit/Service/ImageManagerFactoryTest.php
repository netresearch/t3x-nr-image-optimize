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

use Netresearch\NrImageOptimize\Service\ImageManagerAdapter;
use Netresearch\NrImageOptimize\Service\ImageManagerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(ImageManagerFactory::class)]
#[CoversClass(ImageManagerAdapter::class)]
class ImageManagerFactoryTest extends TestCase
{
    private ImageManagerFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = new ImageManagerFactory();
    }

    #[Test]
    public function createReturnsManagerCapableOfReadingImages(): void
    {
        if (!extension_loaded('imagick') && !extension_loaded('gd')) {
            self::markTestSkipped('Neither imagick nor gd extension is available.');
        }

        $imageManager = $this->factory->create();
        $adapter      = new ImageManagerAdapter($imageManager);

        // Create a real PNG via GD to verify the adapter can read images
        $tmpFile = sys_get_temp_dir() . '/nr-pio-factory-test-' . uniqid('', true) . '.png';
        $gd      = imagecreatetruecolor(1, 1);
        self::assertNotFalse($gd);
        imagepng($gd, $tmpFile);
        imagedestroy($gd);

        try {
            $image = $adapter->read($tmpFile);
            self::assertSame(1, $image->width());
            self::assertSame(1, $image->height());
        } finally {
            unlink($tmpFile);
        }
    }

    #[Test]
    public function createThrowsWhenNoDriverAvailable(): void
    {
        if (extension_loaded('imagick') || extension_loaded('gd')) {
            self::markTestSkipped('Cannot test no-driver path when a driver extension is loaded.');
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No supported image driver available');

        $this->factory->create();
    }
}
