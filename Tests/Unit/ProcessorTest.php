<?php

/**
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\NrImageOptimize\Tests\Unit;

use Intervention\Image\Interfaces\EncodedImageInterface;
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
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

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
    public function gatherInformationBasedOnUrlParsesVariantConfiguration(): void
    {
        $processor = $this->createProcessor();

        $this->setProperty($processor, 'variantUrl', '/processed/path/to/image.w800h400q75m1.webp');

        $this->callMethod($processor, 'gatherInformationBasedOnUrl');

        $basePath = Environment::getPublicPath();

        self::assertSame(
            $basePath . '/processed/path/to/image.w800h400q75m1.webp',
            $this->getProperty($processor, 'pathVariant')
        );
        self::assertSame(
            $basePath . '/path/to/image.webp',
            $this->getProperty($processor, 'pathOriginal')
        );
        self::assertSame(800, $this->getProperty($processor, 'targetWidth'));
        self::assertSame(400, $this->getProperty($processor, 'targetHeight'));
        self::assertSame(75, $this->getProperty($processor, 'targetQuality'));
        self::assertSame(1, $this->getProperty($processor, 'processingMode'));
        self::assertSame('webp', $this->getProperty($processor, 'extension'));
    }

    #[Test]
    public function gatherInformationBasedOnUrlNormalizesJpegExtension(): void
    {
        $processor = $this->createProcessor();

        $this->setProperty($processor, 'variantUrl', '/processed/path/to/image.w200h100m0q60.jpeg');

        $this->callMethod($processor, 'gatherInformationBasedOnUrl');

        $basePath = Environment::getPublicPath();

        self::assertSame(
            $basePath . '/processed/path/to/image.w200h100m0q60.jpeg',
            $this->getProperty($processor, 'pathVariant')
        );
        self::assertSame(
            $basePath . '/path/to/image.jpeg',
            $this->getProperty($processor, 'pathOriginal')
        );
        self::assertSame('jpg', $this->getProperty($processor, 'extension'));
        self::assertSame(60, $this->getProperty($processor, 'targetQuality'));
        self::assertSame(0, $this->getProperty($processor, 'processingMode'));
    }

    #[Test]
    public function gatherInformationBasedOnUrlAppliesDefaultsWhenModeDetailsMissing(): void
    {
        $processor = $this->createProcessor();

        $this->setProperty($processor, 'variantUrl', '/processed/path/to/image.w800.jpg');

        $this->callMethod($processor, 'gatherInformationBasedOnUrl');

        $basePath = Environment::getPublicPath();

        self::assertSame(
            $basePath . '/processed/path/to/image.w800.jpg',
            $this->getProperty($processor, 'pathVariant')
        );
        self::assertSame(
            $basePath . '/path/to/image.jpg',
            $this->getProperty($processor, 'pathOriginal')
        );
        self::assertSame(800, $this->getProperty($processor, 'targetWidth'));
        self::assertNull($this->getProperty($processor, 'targetHeight'));
        self::assertSame(100, $this->getProperty($processor, 'targetQuality'));
        self::assertSame(0, $this->getProperty($processor, 'processingMode'));
        self::assertSame('jpg', $this->getProperty($processor, 'extension'));
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
    public function generateWebpVariantEncodesAndSavesImage(): void
    {
        $processor = $this->createProcessor();

        /** @var ImageInterface&MockObject $image */
        $image   = $this->createMock(ImageInterface::class);
        $encoded = $this->createMock(EncodedImageInterface::class);

        $variantBase = sys_get_temp_dir() . '/nr-image-optimize-' . uniqid('variant', true);

        $image->expects(self::once())->method('toWebp')->with(90)->willReturn($encoded);
        $image->expects(self::once())->method('save')->with($variantBase . '.webp')->willReturnSelf();

        $this->setProperty($processor, 'image', $image);
        $this->setProperty($processor, 'targetQuality', 90);
        $this->setProperty($processor, 'pathVariant', $variantBase);

        $this->callMethod($processor, 'generateWebpVariant');
    }

    #[Test]
    public function generateAvifVariantEncodesAndSavesImage(): void
    {
        $processor = $this->createProcessor();

        /** @var ImageInterface&MockObject $image */
        $image   = $this->createMock(ImageInterface::class);
        $encoded = $this->createMock(EncodedImageInterface::class);

        $variantBase = sys_get_temp_dir() . '/nr-image-optimize-' . uniqid('variant', true);

        $image->expects(self::once())->method('toAvif')->with(75)->willReturn($encoded);
        $image->expects(self::once())->method('save')->with($variantBase . '.avif')->willReturnSelf();

        $this->setProperty($processor, 'image', $image);
        $this->setProperty($processor, 'targetQuality', 75);
        $this->setProperty($processor, 'pathVariant', $variantBase);

        $this->callMethod($processor, 'generateAvifVariant');
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

    #[Test]
    public function getLockerCreatesPrefixedLockName(): void
    {
        $processor = $this->createProcessor();

        $locker = $this->createMock(LockingStrategyInterface::class);

        $factory = $this->createMock(LockFactory::class);
        $factory->expects(self::once())
            ->method('createLocker')
            ->with('nr_image_optimize-' . md5('test-key'))
            ->willReturn($locker);

        GeneralUtility::setSingletonInstance(LockFactory::class, $factory);

        try {
            self::assertSame($locker, $processor->getLocker('test-key'));
        } finally {
            GeneralUtility::purgeInstances();
        }
    }
}
