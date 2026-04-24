<?php

/*
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\NrImageOptimize\Tests\Unit\Event;

use Netresearch\NrImageOptimize\Event\ImageProcessedEvent;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Tests for ImageProcessedEvent.
 *
 * No CoversClass attribute: final readonly classes cannot be instrumented
 * by PCOV on PHP 8.5, causing PHPUnit coverage warnings.
 */
final class ImageProcessedEventTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $event = new ImageProcessedEvent(
            pathOriginal: '/var/www/html/public/fileadmin/image.jpg',
            pathVariant: '/var/www/html/public/processed/fileadmin/image.w800h600q80m0.jpg',
            extension: 'jpg',
            targetWidth: 800,
            targetHeight: 600,
            targetQuality: 80,
            processingMode: 0,
            webpGenerated: true,
            avifGenerated: false,
        );

        self::assertSame('/var/www/html/public/fileadmin/image.jpg', $event->pathOriginal);
        self::assertSame('/var/www/html/public/processed/fileadmin/image.w800h600q80m0.jpg', $event->pathVariant);
        self::assertSame('jpg', $event->extension);
        self::assertSame(800, $event->targetWidth);
        self::assertSame(600, $event->targetHeight);
        self::assertSame(80, $event->targetQuality);
        self::assertSame(0, $event->processingMode);
        self::assertTrue($event->webpGenerated);
        self::assertFalse($event->avifGenerated);
    }

    #[Test]
    public function constructorAcceptsNullDimensions(): void
    {
        $event = new ImageProcessedEvent(
            pathOriginal: '/var/www/html/public/fileadmin/photo.png',
            pathVariant: '/var/www/html/public/processed/fileadmin/photo.q90.png',
            extension: 'png',
            targetWidth: null,
            targetHeight: null,
            targetQuality: 90,
            processingMode: 1,
            webpGenerated: false,
            avifGenerated: false,
        );

        self::assertNull($event->targetWidth);
        self::assertNull($event->targetHeight);
        self::assertSame(1, $event->processingMode);
    }

    #[Test]
    public function eventIsReadonly(): void
    {
        $event = new ImageProcessedEvent(
            pathOriginal: '/original.jpg',
            pathVariant: '/variant.jpg',
            extension: 'jpg',
            targetWidth: 100,
            targetHeight: 100,
            targetQuality: 75,
            processingMode: 0,
            webpGenerated: true,
            avifGenerated: true,
        );

        $reflection = new ReflectionClass($event);
        self::assertTrue($reflection->isReadOnly());
    }
}
