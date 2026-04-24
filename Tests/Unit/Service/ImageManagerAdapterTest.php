<?php

/*
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\NrImageOptimize\Tests\Unit\Service;

use function class_implements;

use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Netresearch\NrImageOptimize\Service\ImageManagerAdapter;
use Netresearch\NrImageOptimize\Service\ImageReaderInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Tests for ImageManagerAdapter.
 *
 * Uses CoversNothing because readonly classes cannot be instrumented
 * by PCOV for code coverage analysis.
 */
#[CoversNothing]
final class ImageManagerAdapterTest extends TestCase
{
    #[Test]
    public function implementsImageReaderInterface(): void
    {
        // Runtime reflection check — PHPStan narrows `instanceof` on a
        // freshly-constructed typed object, making `assertInstanceOf()`
        // redundant from its perspective. `class_implements()` isn't
        // narrowed, so the assertion survives and a mutation that removes
        // `implements ImageReaderInterface` from the class declaration
        // would be caught.
        $implementedInterfaces = class_implements(ImageManagerAdapter::class);
        self::assertIsArray($implementedInterfaces);
        self::assertContains(ImageReaderInterface::class, $implementedInterfaces);
    }

    #[Test]
    public function readDelegatesToImageManager(): void
    {
        $tmpFile = sys_get_temp_dir() . '/nr-pio-adapter-test-' . uniqid('', true) . '.png';
        $gd      = imagecreatetruecolor(2, 3);
        self::assertNotFalse($gd);
        imagepng($gd, $tmpFile);
        imagedestroy($gd);

        try {
            $imageManager = new ImageManager(Driver::class);
            $adapter      = new ImageManagerAdapter($imageManager);
            $image        = $adapter->read($tmpFile);

            self::assertSame(2, $image->width());
            self::assertSame(3, $image->height());
        } finally {
            unlink($tmpFile); // nosemgrep: php.lang.security.unlink-use.unlink-use -- test fixture teardown of self-created tmp file
        }
    }

    #[Test]
    public function adapterIsReadonly(): void
    {
        $reflection = new ReflectionClass(ImageManagerAdapter::class);
        self::assertTrue($reflection->isReadOnly());
    }

    #[Test]
    public function adapterIsFinal(): void
    {
        $reflection = new ReflectionClass(ImageManagerAdapter::class);
        self::assertTrue($reflection->isFinal());
    }
}
