<?php

/*
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\NrImageOptimize\Tests\Unit;

use PHPUnit\Framework\MockObject\Stub;
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
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use ReflectionClass;
use ReflectionMethod;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;

#[CoversClass(Processor::class)]
class ProcessorTest extends TestCase
{
    private Stub $lockFactory;

    private Stub $responseFactory;

    private Stub $streamFactory;

    private Processor $processor;

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

        $this->lockFactory     = $this->createStub(LockFactory::class);
        $this->responseFactory = $this->createStub(ResponseFactoryInterface::class);
        $this->streamFactory   = $this->createStub(StreamFactoryInterface::class);

        // ImageManager is final and cannot be mocked. Use newInstanceWithoutConstructor
        // since tests only exercise private helper methods via reflection that do not
        // depend on ImageManager.
        $reflection      = new ReflectionClass(Processor::class);
        $this->processor = $reflection->newInstanceWithoutConstructor();

        // Inject the mocked dependencies via reflection for tests that need them
        $this->setProperty($this->processor, 'lockFactory', $this->lockFactory);
        $this->setProperty($this->processor, 'responseFactory', $this->responseFactory);
        $this->setProperty($this->processor, 'streamFactory', $this->streamFactory);
    }

    private function setProperty(object $object, string $property, mixed $value): void
    {
        $reflection = new ReflectionClass($object);
        $prop       = $reflection->getProperty($property);
        $prop->setValue($object, $value);
    }

    /**
     * Create a fresh Processor with specific dependencies injected via reflection.
     *
     * Required because constructor-promoted readonly properties cannot be reassigned,
     * so tests needing specific mock expectations must build a new instance.
     *
     * @param object|null $lockFactory     Lock factory stub/mock
     * @param object|null $responseFactory Response factory stub/mock
     * @param object|null $streamFactory   Stream factory stub/mock
     */
    private function createProcessor(
        ?object $lockFactory = null,
        ?object $responseFactory = null,
        ?object $streamFactory = null,
    ): Processor {
        $reflection = new ReflectionClass(Processor::class);
        $instance   = $reflection->newInstanceWithoutConstructor();

        $this->setProperty($instance, 'lockFactory', $lockFactory ?? $this->createStub(LockFactory::class));
        $this->setProperty($instance, 'responseFactory', $responseFactory ?? $this->createStub(ResponseFactoryInterface::class));
        $this->setProperty($instance, 'streamFactory', $streamFactory ?? $this->createStub(StreamFactoryInterface::class));

        return $instance;
    }

    private function callMethod(object $object, string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod($object, $method);

        return $reflection->invoke($object, ...$arguments);
    }

    #[Test]
    public function gatherInformationBasedOnUrlParsesVariantConfiguration(): void
    {
        /** @var array<string, mixed> $result */
        $result = $this->callMethod($this->processor, 'gatherInformationBasedOnUrl', '/processed/path/to/image.w800h400q75m1.webp');

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
        /** @var array<string, mixed> $result */
        $result = $this->callMethod($this->processor, 'gatherInformationBasedOnUrl', '/processed/path/to/image.w200h100m0q60.jpeg');

        self::assertSame('jpg', $result['extension']);
    }

    #[Test]
    public function gatherInformationBasedOnUrlAppliesDefaultsWhenModeDetailsMissing(): void
    {
        /** @var array<string, mixed> $result */
        $result = $this->callMethod($this->processor, 'gatherInformationBasedOnUrl', '/processed/path/to/image.w800.jpg');

        self::assertSame(800, $result['targetWidth']);
        self::assertNull($result['targetHeight']);
        self::assertSame(100, $result['targetQuality']);
        self::assertSame(0, $result['processingMode']);
    }

    #[Test]
    public function parseAllModeValuesExtractsAllParameters(): void
    {
        /** @var array<string, int> $result */
        $result = $this->callMethod($this->processor, 'parseAllModeValues', 'w800h400q80m1');

        self::assertSame(800, $result['w']);
        self::assertSame(400, $result['h']);
        self::assertSame(80, $result['q']);
        self::assertSame(1, $result['m']);
    }

    #[Test]
    public function parseAllModeValuesReturnsEmptyForEmptyString(): void
    {
        /** @var array<string, int> $result */
        $result = $this->callMethod($this->processor, 'parseAllModeValues', '');

        self::assertSame([], $result);
    }

    #[Test]
    public function parseAllModeValuesFirstOccurrenceWins(): void
    {
        /** @var array<string, int> $result */
        $result = $this->callMethod($this->processor, 'parseAllModeValues', 'w100w200');

        self::assertSame(100, $result['w']);
    }

    #[Test]
    public function parseAllModeValuesHandlesPartialParameters(): void
    {
        /** @var array<string, int> $result */
        $result = $this->callMethod($this->processor, 'parseAllModeValues', 'w200h300');

        self::assertSame(200, $result['w']);
        self::assertSame(300, $result['h']);
        self::assertArrayNotHasKey('q', $result);
        self::assertArrayNotHasKey('m', $result);
    }

    #[Test]
    public function parseAllModeValuesHandlesLargeNumericValues(): void
    {
        /** @var array<string, int> $result */
        $result = $this->callMethod($this->processor, 'parseAllModeValues', 'w99999');

        self::assertSame(99999, $result['w']);
    }

    #[Test]
    public function parseQueryParamsExtractsBothFlags(): void
    {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getQuery')->willReturn('skipWebP=1&skipAvif=1');

        $request = $this->createStub(RequestInterface::class);
        $request->method('getUri')->willReturn($uri);

        /** @var array{skipWebP: bool, skipAvif: bool} $result */
        $result = $this->callMethod($this->processor, 'parseQueryParams', $request);

        self::assertTrue($result['skipWebP']);
        self::assertTrue($result['skipAvif']);
    }

    #[Test]
    public function parseQueryParamsDefaultsToFalse(): void
    {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getQuery')->willReturn('');

        $request = $this->createStub(RequestInterface::class);
        $request->method('getUri')->willReturn($uri);

        /** @var array{skipWebP: bool, skipAvif: bool} $result */
        $result = $this->callMethod($this->processor, 'parseQueryParams', $request);

        self::assertFalse($result['skipWebP']);
        self::assertFalse($result['skipAvif']);
    }

    #[Test]
    public function parseQueryParamsHandlesDisabledFlags(): void
    {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getQuery')->willReturn('skipWebP=0&skipAvif=0');

        $request = $this->createStub(RequestInterface::class);
        $request->method('getUri')->willReturn($uri);

        /** @var array{skipWebP: bool, skipAvif: bool} $result */
        $result = $this->callMethod($this->processor, 'parseQueryParams', $request);

        self::assertFalse($result['skipWebP']);
        self::assertFalse($result['skipAvif']);
    }

    #[Test]
    public function calculateTargetDimensionsDerivesMissingHeight(): void
    {
        $image = $this->createStub(ImageInterface::class);
        $image->method('width')->willReturn(800);
        $image->method('height')->willReturn(400);

        /** @var array{0: int|null, 1: int|null} $result */
        $result = $this->callMethod($this->processor, 'calculateTargetDimensions', $image, 400, null);

        self::assertSame(400, $result[0]);
        self::assertSame(200, $result[1]);
    }

    #[Test]
    public function calculateTargetDimensionsDerivesMissingWidth(): void
    {
        $image = $this->createStub(ImageInterface::class);
        $image->method('width')->willReturn(800);
        $image->method('height')->willReturn(400);

        /** @var array{0: int|null, 1: int|null} $result */
        $result = $this->callMethod($this->processor, 'calculateTargetDimensions', $image, null, 200);

        self::assertSame(400, $result[0]);
        self::assertSame(200, $result[1]);
    }

    #[Test]
    public function calculateTargetDimensionsReturnsBothWhenProvided(): void
    {
        $image = $this->createStub(ImageInterface::class);
        $image->method('width')->willReturn(800);
        $image->method('height')->willReturn(400);

        /** @var array{0: int|null, 1: int|null} $result */
        $result = $this->callMethod($this->processor, 'calculateTargetDimensions', $image, 600, 300);

        self::assertSame(600, $result[0]);
        self::assertSame(300, $result[1]);
    }

    #[Test]
    public function calculateTargetDimensionsReturnsNullsWhenBothMissing(): void
    {
        $image = $this->createStub(ImageInterface::class);
        $image->method('width')->willReturn(800);
        $image->method('height')->willReturn(400);

        /** @var array{0: int|null, 1: int|null} $result */
        $result = $this->callMethod($this->processor, 'calculateTargetDimensions', $image, null, null);

        self::assertNull($result[0]);
        self::assertNull($result[1]);
    }

    #[Test]
    public function processImageUsesCoverForDefaultMode(): void
    {
        $image = $this->createMock(ImageInterface::class);
        $image->expects(self::once())->method('cover')->with(400, 200);
        $image->expects(self::never())->method('scale');

        $this->callMethod($this->processor, 'processImage', $image, 400, 200, 0);
    }

    #[Test]
    public function processImageFallsBackToCoverForUnknownMode(): void
    {
        $image = $this->createMock(ImageInterface::class);
        $image->expects(self::once())->method('cover')->with(600, 400);
        $image->expects(self::never())->method('scale');

        $this->callMethod($this->processor, 'processImage', $image, 600, 400, 99);
    }

    #[Test]
    public function processImageUsesScaleForFitMode(): void
    {
        $image = $this->createMock(ImageInterface::class);
        $image->expects(self::never())->method('cover');
        $image->expects(self::once())->method('scale')->with(320, 180);

        $this->callMethod($this->processor, 'processImage', $image, 320, 180, 1);
    }

    #[Test]
    public function processImageSkipsWhenWidthMissing(): void
    {
        $image = $this->createMock(ImageInterface::class);
        $image->expects(self::never())->method('cover');
        $image->expects(self::never())->method('scale');

        $result = $this->callMethod($this->processor, 'processImage', $image, null, 200, 0);

        self::assertSame($image, $result);
    }

    #[Test]
    public function processImageSkipsWhenHeightMissing(): void
    {
        $image = $this->createMock(ImageInterface::class);
        $image->expects(self::never())->method('cover');
        $image->expects(self::never())->method('scale');

        $result = $this->callMethod($this->processor, 'processImage', $image, 400, null, 0);

        self::assertSame($image, $result);
    }

    #[Test]
    public function processImageSkipsWhenBothDimensionsMissing(): void
    {
        $image = $this->createMock(ImageInterface::class);
        $image->expects(self::never())->method('cover');
        $image->expects(self::never())->method('scale');

        $result = $this->callMethod($this->processor, 'processImage', $image, null, null, 1);

        self::assertSame($image, $result);
    }

    #[Test]
    public function processImageReturnsTheImageInstance(): void
    {
        $image = $this->createStub(ImageInterface::class);
        $image->method('cover')->willReturn($image);

        $result = $this->callMethod($this->processor, 'processImage', $image, 400, 200, 0);

        self::assertSame($image, $result);
    }

    #[Test]
    public function hasVariantForChecksFileExistence(): void
    {
        $base = sys_get_temp_dir() . '/nr-image-optimize-' . uniqid('', true);
        $webp = $base . '.webp';
        touch($webp);

        self::assertTrue($this->callMethod($this->processor, 'hasVariantFor', $base, 'webp'));
        self::assertFalse($this->callMethod($this->processor, 'hasVariantFor', $base, 'avif'));

        unlink($webp);
    }

    #[Test]
    public function generateWebpVariantEncodesAndSavesImage(): void
    {
        $image   = $this->createMock(ImageInterface::class);
        $encoded = $this->createMock(EncodedImageInterface::class);

        $variantBase = sys_get_temp_dir() . '/nr-image-optimize-' . uniqid('variant', true);

        $image->expects(self::once())->method('toWebp')->with(90)->willReturn($encoded);
        $encoded->expects(self::once())->method('save')->with($variantBase . '.webp')->willReturnSelf();

        $this->callMethod($this->processor, 'generateWebpVariant', $image, 90, $variantBase);
    }

    #[Test]
    public function generateAvifVariantEncodesAndSavesImage(): void
    {
        $image   = $this->createMock(ImageInterface::class);
        $encoded = $this->createMock(EncodedImageInterface::class);

        $variantBase = sys_get_temp_dir() . '/nr-image-optimize-' . uniqid('variant', true);

        $image->expects(self::once())->method('toAvif')->with(75)->willReturn($encoded);
        $encoded->expects(self::once())->method('save')->with($variantBase . '.avif')->willReturnSelf();

        $this->callMethod($this->processor, 'generateAvifVariant', $image, 75, $variantBase);
    }

    #[Test]
    public function variantExtensionHelpersDetectRequestedFormat(): void
    {
        self::assertTrue($this->callMethod($this->processor, 'isWebpImage', 'webp'));
        self::assertFalse($this->callMethod($this->processor, 'isAvifImage', 'webp'));

        self::assertTrue($this->callMethod($this->processor, 'isAvifImage', 'avif'));
        self::assertFalse($this->callMethod($this->processor, 'isWebpImage', 'avif'));

        self::assertFalse($this->callMethod($this->processor, 'isWebpImage', 'jpg'));
        self::assertFalse($this->callMethod($this->processor, 'isAvifImage', 'jpg'));
    }

    #[Test]
    #[DataProvider('extensionHelperProvider')]
    public function isWebpAndAvifImageDetectsCorrectExtensions(string $extension, bool $expectedWebp, bool $expectedAvif): void
    {
        self::assertSame($expectedWebp, $this->callMethod($this->processor, 'isWebpImage', $extension));
        self::assertSame($expectedAvif, $this->callMethod($this->processor, 'isAvifImage', $extension));
    }

    /**
     * @return iterable<string, array{0: string, 1: bool, 2: bool}>
     */
    public static function extensionHelperProvider(): iterable
    {
        yield 'webp' => ['webp', true, false];
        yield 'avif' => ['avif', false, true];
        yield 'jpg' => ['jpg', false, false];
        yield 'png' => ['png', false, false];
        yield 'gif' => ['gif', false, false];
        yield 'empty' => ['', false, false];
        yield 'WEBP uppercase' => ['WEBP', false, false];
        yield 'AVIF uppercase' => ['AVIF', false, false];
    }

    #[Test]
    public function getLockerCreatesPrefixedLockName(): void
    {
        $locker      = $this->createStub(LockingStrategyInterface::class);
        $lockFactory = $this->createMock(LockFactory::class);

        $lockFactory->expects(self::once())
            ->method('createLocker')
            ->with('nr_image_optimize-' . md5('test-key'))
            ->willReturn($locker);

        $processor = $this->createProcessor(lockFactory: $lockFactory);

        self::assertSame($locker, $this->callMethod($processor, 'getLocker', 'test-key'));
    }

    #[Test]
    public function buildOutputResponsePrefersAvifWhenAvailable(): void
    {
        $base = sys_get_temp_dir() . '/nr-image-optimize-response-' . uniqid('', true);
        file_put_contents($base . '.avif', 'fake-avif-data');
        file_put_contents($base . '.webp', 'fake-webp-data');

        $response = $this->createStub(ResponseInterface::class);
        $stream   = $this->createStub(StreamInterface::class);

        $this->responseFactory->method('createResponse')->willReturn($response);
        $response->method('withHeader')->willReturn($response);
        $this->streamFactory->method('createStream')->willReturn($stream);
        $response->method('withBody')->willReturn($response);

        $result = $this->callMethod($this->processor, 'buildOutputResponse', 'jpg', $base);

        self::assertSame($response, $result);

        unlink($base . '.avif');
        unlink($base . '.webp');
    }

    #[Test]
    public function buildOutputResponseFallsToWebpWhenNoAvif(): void
    {
        $base = sys_get_temp_dir() . '/nr-image-optimize-response-' . uniqid('', true);
        file_put_contents($base . '.webp', 'fake-webp-data');

        $response = $this->createStub(ResponseInterface::class);
        $stream   = $this->createStub(StreamInterface::class);

        $this->responseFactory->method('createResponse')->willReturn($response);
        $response->method('withHeader')->willReturn($response);
        $this->streamFactory->method('createStream')->willReturn($stream);
        $response->method('withBody')->willReturn($response);

        $result = $this->callMethod($this->processor, 'buildOutputResponse', 'jpg', $base);

        self::assertSame($response, $result);

        unlink($base . '.webp');
    }

    #[Test]
    public function buildOutputResponseFallsToOriginalFormatWhenNoVariants(): void
    {
        $base = sys_get_temp_dir() . '/nr-image-optimize-response-' . uniqid('', true);
        file_put_contents($base, 'original-jpg-data');

        $response = $this->createStub(ResponseInterface::class);
        $stream   = $this->createStub(StreamInterface::class);

        $this->responseFactory->method('createResponse')->willReturn($response);
        $response->method('withHeader')->willReturn($response);
        $this->streamFactory->method('createStream')->willReturn($stream);
        $response->method('withBody')->willReturn($response);

        $result = $this->callMethod($this->processor, 'buildOutputResponse', 'jpg', $base);

        self::assertSame($response, $result);

        unlink($base);
    }

    #[Test]
    public function buildFileResponseReturnsNullForMissingFile(): void
    {
        $result = $this->callMethod(
            $this->processor,
            'buildFileResponse',
            '/nonexistent/path/image.jpg',
            'image/jpeg',
        );

        self::assertNull($result);
    }

    #[Test]
    public function buildFileResponseReturnsResponseForExistingFile(): void
    {
        $base = sys_get_temp_dir() . '/nr-image-optimize-cache-' . uniqid('', true);
        file_put_contents($base, 'test-content');

        $response = $this->createStub(ResponseInterface::class);
        $stream   = $this->createStub(StreamInterface::class);

        $this->responseFactory->method('createResponse')->willReturn($response);
        $response->method('withHeader')->willReturn($response);
        $this->streamFactory->method('createStream')->willReturn($stream);
        $response->method('withBody')->willReturn($response);

        $result = $this->callMethod($this->processor, 'buildFileResponse', $base, 'image/jpeg');

        self::assertNotNull($result);

        unlink($base);
    }

    #[Test]
    public function serveCachedVariantReturnsNullWhenNoFilesExist(): void
    {
        $result = $this->callMethod(
            $this->processor,
            'serveCachedVariant',
            '/nonexistent/path/image.w800h400.jpg',
            'jpg',
        );

        self::assertNull($result);
    }

    #[Test]
    public function serveCachedVariantPrefersAvif(): void
    {
        $base = sys_get_temp_dir() . '/nr-image-optimize-cache-' . uniqid('', true);
        file_put_contents($base . '.avif', 'avif-data');
        file_put_contents($base . '.webp', 'webp-data');
        file_put_contents($base, 'jpg-data');

        $response = $this->createStub(ResponseInterface::class);
        $stream   = $this->createStub(StreamInterface::class);

        $this->responseFactory->method('createResponse')->willReturn($response);
        $response->method('withHeader')->willReturn($response);
        $this->streamFactory->method('createStream')->willReturn($stream);
        $response->method('withBody')->willReturn($response);

        $result = $this->callMethod($this->processor, 'serveCachedVariant', $base, 'jpg');

        self::assertNotNull($result);

        unlink($base . '.avif');
        unlink($base . '.webp');
        unlink($base);
    }

    #[Test]
    public function gatherInformationBasedOnUrlHandlesExtensionCaseInsensitivity(): void
    {
        /** @var array<string, mixed> $result */
        $result = $this->callMethod($this->processor, 'gatherInformationBasedOnUrl', '/processed/image.w100h50.PNG');

        self::assertSame('png', $result['extension']);
    }

    #[Test]
    public function gatherInformationBasedOnUrlHandlesUrlWithoutModeString(): void
    {
        // This URL does not match the regex pattern (no mode digits) so returns null
        $result = $this->callMethod($this->processor, 'gatherInformationBasedOnUrl', '/processed/image.jpg');

        self::assertNull($result);
    }

    #[Test]
    public function gatherInformationBasedOnUrlHandlesNestedDirectories(): void
    {
        /** @var array<string, mixed> $result */
        $result = $this->callMethod(
            $this->processor,
            'gatherInformationBasedOnUrl',
            '/processed/deep/nested/path/image.w400h300q90m0.jpg',
        );

        self::assertSame(400, $result['targetWidth']);
        self::assertSame(300, $result['targetHeight']);
        self::assertSame(90, $result['targetQuality']);
        self::assertSame(0, $result['processingMode']);
        self::assertStringContainsString('deep/nested/path', $result['pathOriginal']);
    }

    #[Test]
    public function gatherInformationBasedOnUrlReturnsNullForNonMatchingUrls(): void
    {
        // Path traversal attempt
        self::assertNull($this->callMethod($this->processor, 'gatherInformationBasedOnUrl', '/processed/../../../etc/passwd.w100.jpg'));
        // Empty string
        self::assertNull($this->callMethod($this->processor, 'gatherInformationBasedOnUrl', ''));
        // Root path
        self::assertNull($this->callMethod($this->processor, 'gatherInformationBasedOnUrl', '/'));
        // No processed prefix
        self::assertNull($this->callMethod($this->processor, 'gatherInformationBasedOnUrl', '/fileadmin/image.w100.jpg'));
    }

    #[Test]
    public function gatherInformationClampsDimensionsToMaximum(): void
    {
        /** @var array<string, mixed> $result */
        $result = $this->callMethod($this->processor, 'gatherInformationBasedOnUrl', '/processed/image.w99999h99999q200m0.jpg');

        // Dimensions should be clamped to MAX_DIMENSION (8192)
        self::assertSame(8192, $result['targetWidth']);
        self::assertSame(8192, $result['targetHeight']);
        // Quality should be clamped to 100
        self::assertSame(100, $result['targetQuality']);
    }

    #[Test]
    public function gatherInformationClampsZeroDimensionToOne(): void
    {
        /** @var array<string, mixed> $result */
        $result = $this->callMethod($this->processor, 'gatherInformationBasedOnUrl', '/processed/image.w0h0q0m0.jpg');

        // Width and height of 0 should be clamped to 1
        self::assertSame(1, $result['targetWidth']);
        self::assertSame(1, $result['targetHeight']);
        // Quality of 0 should be clamped to 1
        self::assertSame(1, $result['targetQuality']);
    }

    #[Test]
    public function clampDimensionReturnsNullForNull(): void
    {
        self::assertNull($this->callMethod($this->processor, 'clampDimension', null));
    }

    #[Test]
    public function clampDimensionClampsToRange(): void
    {
        self::assertSame(1, $this->callMethod($this->processor, 'clampDimension', 0));
        self::assertSame(1, $this->callMethod($this->processor, 'clampDimension', -5));
        self::assertSame(100, $this->callMethod($this->processor, 'clampDimension', 100));
        self::assertSame(8192, $this->callMethod($this->processor, 'clampDimension', 99999));
    }

    #[Test]
    public function clampQualityClampsToRange(): void
    {
        self::assertSame(1, $this->callMethod($this->processor, 'clampQuality', 0));
        self::assertSame(1, $this->callMethod($this->processor, 'clampQuality', -10));
        self::assertSame(50, $this->callMethod($this->processor, 'clampQuality', 50));
        self::assertSame(100, $this->callMethod($this->processor, 'clampQuality', 100));
        self::assertSame(100, $this->callMethod($this->processor, 'clampQuality', 999));
    }

    #[Test]
    public function calculateTargetDimensionsHandlesZeroHeight(): void
    {
        $image = $this->createStub(ImageInterface::class);
        $image->method('width')->willReturn(800);
        $image->method('height')->willReturn(0);

        /** @var array{0: int|null, 1: int|null} $result */
        $result = $this->callMethod($this->processor, 'calculateTargetDimensions', $image, 400, null);

        // Should return original values without calculation to avoid division by zero
        self::assertSame(400, $result[0]);
        self::assertNull($result[1]);
    }

    #[Test]
    public function calculateTargetDimensionsHandlesZeroWidth(): void
    {
        $image = $this->createStub(ImageInterface::class);
        $image->method('width')->willReturn(0);
        $image->method('height')->willReturn(400);

        /** @var array{0: int|null, 1: int|null} $result */
        $result = $this->callMethod($this->processor, 'calculateTargetDimensions', $image, null, 200);

        // Should return original values without calculation to avoid division by zero
        self::assertNull($result[0]);
        self::assertSame(200, $result[1]);
    }

    #[Test]
    public function generateAndSendReturns400ForNonMatchingUrl(): void
    {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/not-processed/image.jpg');

        $request = $this->createStub(RequestInterface::class);
        $request->method('getUri')->willReturn($uri);

        $response = $this->createStub(ResponseInterface::class);
        $this->responseFactory->method('createResponse')->willReturn($response);

        $result = $this->processor->generateAndSend($request);

        self::assertSame($response, $result);
    }
}
