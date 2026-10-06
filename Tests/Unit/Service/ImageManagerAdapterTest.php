<?php

/*
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
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
use Netresearch\NrImageOptimize\Service\UnsupportedImageTypeException;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
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
    public function readDecodesFilePathContainingNonAsciiCharacters(): void
    {
        $tmpDir = sys_get_temp_dir() . '/nr-pio-adapter-test-' . uniqid('', true) . '/Gründung';
        self::assertTrue(mkdir($tmpDir, 0o777, true));

        $tmpFile = $tmpDir . '/test.png';
        $gd      = imagecreatetruecolor(4, 5);
        self::assertNotFalse($gd);
        imagepng($gd, $tmpFile);
        imagedestroy($gd);

        try {
            $imageManager = new ImageManager(Driver::class);
            $adapter      = new ImageManagerAdapter($imageManager);
            $image        = $adapter->read($tmpFile);

            self::assertSame(4, $image->width());
            self::assertSame(5, $image->height());
        } finally {
            unlink($tmpFile); // nosemgrep: php.lang.security.unlink-use.unlink-use -- test fixture teardown of self-created tmp file
            rmdir($tmpDir);
            rmdir(dirname($tmpDir));
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function nonImageContentProvider(): iterable
    {
        yield 'PostScript named .png' => ['png', "%!PS-Adobe-3.0\n%%BoundingBox: 0 0 10 10\nshowpage\n"];
        yield 'SVG named .jpg' => ['jpg', '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"/>'];
        yield 'text named .gif' => ['gif', 'plain text'];
        yield 'empty file' => ['png', ''];
    }

    #[Test]
    #[DataProvider('nonImageContentProvider')]
    public function readRefusesFileWhoseContentIsNotASupportedImage(string $extension, string $content): void
    {
        $tmpFile = sys_get_temp_dir() . '/nr-pio-adapter-test-' . uniqid('', true) . '.' . $extension;
        file_put_contents($tmpFile, $content);

        try {
            $adapter = new ImageManagerAdapter(new ImageManager(Driver::class));

            $this->expectException(UnsupportedImageTypeException::class);

            $adapter->read($tmpFile);
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
