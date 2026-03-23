<?php

/*
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\NrImageOptimize\Tests\Unit;

use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\EncodedImageInterface;
use Intervention\Image\Interfaces\ImageInterface;
use Netresearch\NrImageOptimize\Processor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UriInterface;
use ReflectionMethod;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;

#[CoversClass(Processor::class)]
class ProcessorTest extends TestCase
{
    private LockFactory&MockObject $lockFactory;

    private ResponseFactoryInterface&MockObject $responseFactory;

    private StreamFactoryInterface&MockObject $streamFactory;

    protected function setUp(): void
    {
        parent::setUp();

        Environment::initialize(
            new ApplicationContext('Testing'),
            true,
            true,
            '/var/www/html',
            '/var/www/html/public',
            '/var/www/html/var',
            '/var/www/html/config',
            '/var/www/html/public/index.php',
            'UNIX',
        );

        $this->lockFactory     = $this->createMock(LockFactory::class);
        $this->responseFactory = $this->createMock(ResponseFactoryInterface::class);
        $this->streamFactory   = $this->createMock(StreamFactoryInterface::class);
    }

    /**
     * Create a Processor instance using the GD driver (Imagick may not be
     * available in all environments).
     */
    private function createProcessor(): Processor
    {
        return new Processor(
            new ImageManager(new Driver()),
            $this->lockFactory,
            $this->responseFactory,
            $this->streamFactory,
        );
    }

    private function callMethod(object $object, string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod($object, $method);

        return $reflection->invoke($object, ...$arguments);
    }

    #[Test]
    public function gatherInformationBasedOnUrlParsesVariantConfiguration(): void
    {
        $processor = $this->createProcessor();

        /** @var array<string, mixed> $result */
        $result = $this->callMethod($processor, 'gatherInformationBasedOnUrl', '/processed/path/to/image.w800h400q75m1.webp');

        self::assertSame(
            '/var/www/html/public/processed/path/to/image.w800h400q75m1.webp',
            $result['pathVariant'],
        );
        self::assertSame(
            '/var/www/html/public/path/to/image.webp',
            $result['pathOriginal'],
        );
        self::assertSame(800, $result['targetWidth']);
        self::assertSame(400, $result['targetHeight']);
        self::assertSame(75, $result['targetQuality']);
        self::assertSame(1, $result['processingMode']);
        self::assertSame('webp', $result['extension']);
    }

    #[Test]
    public function gatherInformationBasedOnUrlNormalizesJpegExtension(): void
    {
        $processor = $this->createProcessor();

        /** @var array<string, mixed> $result */
        $result = $this->callMethod($processor, 'gatherInformationBasedOnUrl', '/processed/path/to/image.w200h100m0q60.jpeg');

        self::assertSame('jpg', $result['extension']);
    }

    #[Test]
    public function gatherInformationBasedOnUrlAppliesDefaultsWhenModeDetailsMissing(): void
    {
        $processor = $this->createProcessor();

        /** @var array<string, mixed> $result */
        $result = $this->callMethod($processor, 'gatherInformationBasedOnUrl', '/processed/path/to/image.w800.jpg');

        self::assertSame(800, $result['targetWidth']);
        self::assertNull($result['targetHeight']);
        self::assertSame(100, $result['targetQuality']);
        self::assertSame(0, $result['processingMode']);
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
    #[DataProvider('skipVariantQueryProvider')]
    public function skipVariantCreationEvaluatesQueryFlag(string $method, string $query, bool $expected): void
    {
        $processor = $this->createProcessor();

        $uri = $this->createMock(UriInterface::class);
        $uri->method('getQuery')->willReturn($query);

        $request = $this->createMock(RequestInterface::class);
        $request->method('getUri')->willReturn($uri);

        self::assertSame($expected, $this->callMethod($processor, $method, $request));
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: bool}>
     */
    public static function skipVariantQueryProvider(): iterable
    {
        yield 'webp flag enabled' => ['skipWebPCreation', 'skipWebP=1', true];
        yield 'webp flag disabled via zero' => ['skipWebPCreation', 'skipWebP=0', false];
        yield 'webp flag missing' => ['skipWebPCreation', '', false];
        yield 'avif flag enabled' => ['skipAvifCreation', 'skipAvif=1', true];
        yield 'avif flag disabled via zero' => ['skipAvifCreation', 'skipAvif=0', false];
        yield 'avif flag missing' => ['skipAvifCreation', '', false];
    }

    #[Test]
    public function getQueryValueReturnsRequestedParameter(): void
    {
        $processor = $this->createProcessor();

        $uri = $this->createMock(UriInterface::class);
        $uri->method('getQuery')->willReturn('foo=bar&skipWebP=1');

        $request = $this->createMock(RequestInterface::class);
        $request->method('getUri')->willReturn($uri);

        self::assertSame('bar', $this->callMethod($processor, 'getQueryValue', $request, 'foo'));
        self::assertSame('1', $this->callMethod($processor, 'getQueryValue', $request, 'skipWebP'));
    }

    #[Test]
    public function getQueryValueReturnsNullForMissingParameter(): void
    {
        $processor = $this->createProcessor();

        $uri = $this->createMock(UriInterface::class);
        $uri->method('getQuery')->willReturn('foo=bar');

        $request = $this->createMock(RequestInterface::class);
        $request->method('getUri')->willReturn($uri);

        self::assertNull($this->callMethod($processor, 'getQueryValue', $request, 'baz'));
    }

    #[Test]
    public function calculateTargetDimensionsDerivesMissingHeight(): void
    {
        $processor = $this->createProcessor();

        /** @var ImageInterface&MockObject $image */
        $image = $this->createMock(ImageInterface::class);
        $image->method('width')->willReturn(800);
        $image->method('height')->willReturn(400);

        /** @var array{0: int|null, 1: int|null} $result */
        $result = $this->callMethod($processor, 'calculateTargetDimensions', $image, 400, null);

        self::assertSame(400, $result[0]);
        self::assertSame(200, $result[1]);
    }

    #[Test]
    public function calculateTargetDimensionsDerivesMissingWidth(): void
    {
        $processor = $this->createProcessor();

        /** @var ImageInterface&MockObject $image */
        $image = $this->createMock(ImageInterface::class);
        $image->method('width')->willReturn(800);
        $image->method('height')->willReturn(400);

        /** @var array{0: int|null, 1: int|null} $result */
        $result = $this->callMethod($processor, 'calculateTargetDimensions', $image, null, 200);

        self::assertSame(400, $result[0]);
        self::assertSame(200, $result[1]);
    }

    #[Test]
    public function processImageUsesCoverForDefaultMode(): void
    {
        $processor = $this->createProcessor();

        /** @var ImageInterface&MockObject $image */
        $image = $this->createMock(ImageInterface::class);
        $image->expects(self::once())->method('cover')->with(400, 200);
        $image->expects(self::never())->method('scale');

        $this->callMethod($processor, 'processImage', $image, 400, 200, 0);
    }

    #[Test]
    public function processImageFallsBackToCoverForUnknownMode(): void
    {
        $processor = $this->createProcessor();

        /** @var ImageInterface&MockObject $image */
        $image = $this->createMock(ImageInterface::class);
        $image->expects(self::once())->method('cover')->with(600, 400);
        $image->expects(self::never())->method('scale');

        $this->callMethod($processor, 'processImage', $image, 600, 400, 99);
    }

    #[Test]
    public function processImageUsesScaleForFitMode(): void
    {
        $processor = $this->createProcessor();

        /** @var ImageInterface&MockObject $image */
        $image = $this->createMock(ImageInterface::class);
        $image->expects(self::never())->method('cover');
        $image->expects(self::once())->method('scale')->with(320, 180);

        $this->callMethod($processor, 'processImage', $image, 320, 180, 1);
    }

    #[Test]
    public function processImageSkipsWhenDimensionMissing(): void
    {
        $processor = $this->createProcessor();

        /** @var ImageInterface&MockObject $image */
        $image = $this->createMock(ImageInterface::class);
        $image->expects(self::never())->method('cover');
        $image->expects(self::never())->method('scale');

        $this->callMethod($processor, 'processImage', $image, null, 200, 0);
    }

    #[Test]
    public function hasVariantForChecksFileExistence(): void
    {
        $processor = $this->createProcessor();

        $base = sys_get_temp_dir() . '/nr-image-optimize-' . uniqid('', true);
        $webp = $base . '.webp';
        touch($webp);

        self::assertTrue($this->callMethod($processor, 'hasVariantFor', $base, 'webp'));
        self::assertFalse($this->callMethod($processor, 'hasVariantFor', $base, 'avif'));

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

        $this->callMethod($processor, 'generateWebpVariant', $image, 90, $variantBase);
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

        $this->callMethod($processor, 'generateAvifVariant', $image, 75, $variantBase);
    }

    #[Test]
    public function variantExtensionHelpersDetectRequestedFormat(): void
    {
        $processor = $this->createProcessor();

        self::assertTrue($this->callMethod($processor, 'isWebpImage', 'webp'));
        self::assertFalse($this->callMethod($processor, 'isAvifImage', 'webp'));

        self::assertTrue($this->callMethod($processor, 'isAvifImage', 'avif'));
        self::assertFalse($this->callMethod($processor, 'isWebpImage', 'avif'));

        self::assertFalse($this->callMethod($processor, 'isWebpImage', 'jpg'));
        self::assertFalse($this->callMethod($processor, 'isAvifImage', 'jpg'));
    }

    #[Test]
    public function getLockerCreatesPrefixedLockName(): void
    {
        $locker = $this->createMock(LockingStrategyInterface::class);

        $this->lockFactory->expects(self::once())
            ->method('createLocker')
            ->with('nr_image_optimize-' . md5('test-key'))
            ->willReturn($locker);

        $processor = $this->createProcessor();

        self::assertSame($locker, $processor->getLocker('test-key'));
    }
}
