<?php

declare(strict_types=1);

namespace Netresearch\NrImageOptimize\Tests\Unit;

use Intervention\Image\Interfaces\ImageInterface;
use Netresearch\NrImageOptimize\Processor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

#[CoversClass(Processor::class)]
class ProcessorTest extends TestCase
{
    private function createProcessor(): Processor
    {
        $reflection = new ReflectionClass(Processor::class);

        /** @var Processor $processor */
        $processor = $reflection->newInstanceWithoutConstructor();

        return $processor;
    }

    private function setProperty(object $object, string $property, mixed $value): void
    {
        $reflection = new ReflectionProperty($object, $property);
        $reflection->setAccessible(true);
        $reflection->setValue($object, $value);
    }

    private function getProperty(object $object, string $property): mixed
    {
        $reflection = new ReflectionProperty($object, $property);
        $reflection->setAccessible(true);

        return $reflection->getValue($object);
    }

    private function callMethod(object $object, string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($object, ...$arguments);
    }

    #[Test]
    public function getValueFromModeParsesNumericFragments(): void
    {
        $processor = $this->createProcessor();

        self::assertSame(800, $this->callMethod($processor, 'getValueFromMode', 'w', 'w800h400q80m1'));
        self::assertSame(400, $this->callMethod($processor, 'getValueFromMode', 'h', 'w800h400q80m1'));
        self::assertSame(80, $this->callMethod($processor, 'getValueFromMode', 'q', 'w800h400q80m1'));
        self::assertSame(1, $this->callMethod($processor, 'getValueFromMode', 'm', 'w800h400q80m1'));
    }

    #[Test]
    public function getValueFromModeReturnsNullIfIdentifierMissing(): void
    {
        $processor = $this->createProcessor();

        self::assertNull($this->callMethod($processor, 'getValueFromMode', 'q', 'w200h300'));
        self::assertNull($this->callMethod($processor, 'getValueFromMode', 'w', ''));
    }

    #[Test]
    public function skipWebPCreationReadsQueryParameter(): void
    {
        $processor = $this->createProcessor();

        $uri = $this->createMock(UriInterface::class);
        $uri->method('getQuery')->willReturn('skipWebP=1');

        $request = $this->createMock(RequestInterface::class);
        $request->method('getUri')->willReturn($uri);

        $this->setProperty($processor, 'request', $request);

        self::assertTrue($this->callMethod($processor, 'skipWebPCreation'));
    }

    #[Test]
    public function skipWebPCreationDefaultsToFalseWhenFlagMissing(): void
    {
        $processor = $this->createProcessor();

        $uri = $this->createMock(UriInterface::class);
        $uri->method('getQuery')->willReturn('');

        $request = $this->createMock(RequestInterface::class);
        $request->method('getUri')->willReturn($uri);

        $this->setProperty($processor, 'request', $request);

        self::assertFalse($this->callMethod($processor, 'skipWebPCreation'));
    }

    #[Test]
    public function skipWebPCreationTreatsZeroFlagAsFalse(): void
    {
        $processor = $this->createProcessor();

        $uri = $this->createMock(UriInterface::class);
        $uri->method('getQuery')->willReturn('skipWebP=0');

        $request = $this->createMock(RequestInterface::class);
        $request->method('getUri')->willReturn($uri);

        $this->setProperty($processor, 'request', $request);

        self::assertFalse($this->callMethod($processor, 'skipWebPCreation'));
    }

    #[Test]
    public function skipAvifCreationReadsQueryParameter(): void
    {
        $processor = $this->createProcessor();

        $uri = $this->createMock(UriInterface::class);
        $uri->method('getQuery')->willReturn('skipAvif=1');

        $request = $this->createMock(RequestInterface::class);
        $request->method('getUri')->willReturn($uri);

        $this->setProperty($processor, 'request', $request);

        self::assertTrue($this->callMethod($processor, 'skipAvifCreation'));
    }

    #[Test]
    public function skipAvifCreationDefaultsToFalseWhenFlagMissing(): void
    {
        $processor = $this->createProcessor();

        $uri = $this->createMock(UriInterface::class);
        $uri->method('getQuery')->willReturn('');

        $request = $this->createMock(RequestInterface::class);
        $request->method('getUri')->willReturn($uri);

        $this->setProperty($processor, 'request', $request);

        self::assertFalse($this->callMethod($processor, 'skipAvifCreation'));
    }

    #[Test]
    public function skipAvifCreationTreatsZeroFlagAsFalse(): void
    {
        $processor = $this->createProcessor();

        $uri = $this->createMock(UriInterface::class);
        $uri->method('getQuery')->willReturn('skipAvif=0');

        $request = $this->createMock(RequestInterface::class);
        $request->method('getUri')->willReturn($uri);

        $this->setProperty($processor, 'request', $request);

        self::assertFalse($this->callMethod($processor, 'skipAvifCreation'));
    }

    #[Test]
    public function calculateTargetDimensionsDerivesMissingHeight(): void
    {
        $processor = $this->createProcessor();

        /** @var ImageInterface&MockObject $image */
        $image = $this->createMock(ImageInterface::class);
        $image->method('width')->willReturn(800);
        $image->method('height')->willReturn(400);

        $this->setProperty($processor, 'image', $image);
        $this->setProperty($processor, 'targetWidth', 400);
        $this->setProperty($processor, 'targetHeight', null);

        $this->callMethod($processor, 'calculateTargetDimensions');

        self::assertSame(200, $this->getProperty($processor, 'targetHeight'));
    }

    #[Test]
    public function calculateTargetDimensionsDerivesMissingWidth(): void
    {
        $processor = $this->createProcessor();

        /** @var ImageInterface&MockObject $image */
        $image = $this->createMock(ImageInterface::class);
        $image->method('width')->willReturn(800);
        $image->method('height')->willReturn(400);

        $this->setProperty($processor, 'image', $image);
        $this->setProperty($processor, 'targetWidth', null);
        $this->setProperty($processor, 'targetHeight', 200);

        $this->callMethod($processor, 'calculateTargetDimensions');

        self::assertSame(400, $this->getProperty($processor, 'targetWidth'));
    }

    #[Test]
    public function processImageUsesCoverForDefaultMode(): void
    {
        $processor = $this->createProcessor();

        /** @var ImageInterface&MockObject $image */
        $image = $this->createMock(ImageInterface::class);
        $image->expects(self::once())->method('cover')->with(400, 200);
        $image->expects(self::never())->method('scale');

        $this->setProperty($processor, 'image', $image);
        $this->setProperty($processor, 'targetWidth', 400);
        $this->setProperty($processor, 'targetHeight', 200);
        $this->setProperty($processor, 'processingMode', 0);

        $this->callMethod($processor, 'processImage');
    }

    #[Test]
    public function processImageFallsBackToCoverForUnknownMode(): void
    {
        $processor = $this->createProcessor();

        /** @var ImageInterface&MockObject $image */
        $image = $this->createMock(ImageInterface::class);
        $image->expects(self::once())->method('cover')->with(600, 400);
        $image->expects(self::never())->method('scale');

        $this->setProperty($processor, 'image', $image);
        $this->setProperty($processor, 'targetWidth', 600);
        $this->setProperty($processor, 'targetHeight', 400);
        $this->setProperty($processor, 'processingMode', 99);

        $this->callMethod($processor, 'processImage');
    }

    #[Test]
    public function processImageUsesScaleForFitMode(): void
    {
        $processor = $this->createProcessor();

        /** @var ImageInterface&MockObject $image */
        $image = $this->createMock(ImageInterface::class);
        $image->expects(self::never())->method('cover');
        $image->expects(self::once())->method('scale')->with(320, 180);

        $this->setProperty($processor, 'image', $image);
        $this->setProperty($processor, 'targetWidth', 320);
        $this->setProperty($processor, 'targetHeight', 180);
        $this->setProperty($processor, 'processingMode', 1);

        $this->callMethod($processor, 'processImage');
    }

    #[Test]
    public function processImageSkipsWhenDimensionMissing(): void
    {
        $processor = $this->createProcessor();

        /** @var ImageInterface&MockObject $image */
        $image = $this->createMock(ImageInterface::class);
        $image->expects(self::never())->method('cover');
        $image->expects(self::never())->method('scale');

        $this->setProperty($processor, 'image', $image);
        $this->setProperty($processor, 'targetWidth', null);
        $this->setProperty($processor, 'targetHeight', 200);
        $this->setProperty($processor, 'processingMode', 0);

        $this->callMethod($processor, 'processImage');
    }

    #[Test]
    public function hasVariantForChecksFileExistence(): void
    {
        $processor = $this->createProcessor();

        $base = sys_get_temp_dir() . '/nr-image-optimize-' . uniqid('', true);
        $webp = $base . '.webp';
        touch($webp);

        $this->setProperty($processor, 'pathVariant', $base);

        self::assertTrue($this->callMethod($processor, 'hasVariantFor', 'webp'));
        self::assertFalse($this->callMethod($processor, 'hasVariantFor', 'avif'));

        unlink($webp);
    }

    #[Test]
    public function variantExtensionHelpersDetectRequestedFormat(): void
    {
        $processor = $this->createProcessor();

        $this->setProperty($processor, 'extension', 'webp');
        self::assertTrue($this->callMethod($processor, 'isWebpImage'));
        self::assertFalse($this->callMethod($processor, 'isAvifImage'));

        $this->setProperty($processor, 'extension', 'avif');
        self::assertTrue($this->callMethod($processor, 'isAvifImage'));
        self::assertFalse($this->callMethod($processor, 'isWebpImage'));

        $this->setProperty($processor, 'extension', 'jpg');
        self::assertFalse($this->callMethod($processor, 'isWebpImage'));
        self::assertFalse($this->callMethod($processor, 'isAvifImage'));
    }
}
