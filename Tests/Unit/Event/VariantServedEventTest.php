<?php

/*
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\NrImageOptimize\Tests\Unit\Event;

use Netresearch\NrImageOptimize\Event\VariantServedEvent;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Tests for VariantServedEvent.
 *
 * No CoversClass attribute: final readonly classes cannot be instrumented
 * by PCOV on PHP 8.5, causing PHPUnit coverage warnings.
 */
final class VariantServedEventTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $event = new VariantServedEvent(
            pathVariant: '/var/www/html/public/processed/image.w800h600q80m0.jpg',
            extension: 'jpg',
            responseStatusCode: 200,
            fromCache: true,
        );

        self::assertSame('/var/www/html/public/processed/image.w800h600q80m0.jpg', $event->pathVariant);
        self::assertSame('jpg', $event->extension);
        self::assertSame(200, $event->responseStatusCode);
        self::assertTrue($event->fromCache);
    }

    #[Test]
    public function constructorHandlesFreshlyProcessedVariant(): void
    {
        $event = new VariantServedEvent(
            pathVariant: '/var/www/html/public/processed/photo.w1024h768q90m1.png',
            extension: 'png',
            responseStatusCode: 200,
            fromCache: false,
        );

        self::assertFalse($event->fromCache);
        self::assertSame(200, $event->responseStatusCode);
    }

    #[Test]
    public function constructorAcceptsErrorStatusCodes(): void
    {
        $event = new VariantServedEvent(
            pathVariant: '/var/www/html/public/processed/missing.w100h100q75m0.jpg',
            extension: 'jpg',
            responseStatusCode: 404,
            fromCache: false,
        );

        self::assertSame(404, $event->responseStatusCode);
    }

    #[Test]
    public function eventIsReadonly(): void
    {
        $event = new VariantServedEvent(
            pathVariant: '/variant.jpg',
            extension: 'jpg',
            responseStatusCode: 200,
            fromCache: true,
        );

        $reflection = new ReflectionClass($event);
        self::assertTrue($reflection->isReadOnly());
    }
}
