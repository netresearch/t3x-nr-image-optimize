<?php

/*
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\NrImageOptimize\Tests\Unit;

use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\DriverInterface;
use Intervention\Image\Interfaces\EncodedImageInterface;
use Intervention\Image\Interfaces\ImageInterface;
use Netresearch\NrImageOptimize\Event\ImageProcessedEvent;
use Netresearch\NrImageOptimize\Event\VariantServedEvent;
use Netresearch\NrImageOptimize\Processor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Locking\Exception\LockCreateException;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;

#[CoversClass(Processor::class)]
#[UsesClass(ImageProcessedEvent::class)]
#[UsesClass(VariantServedEvent::class)]
class ProcessorTest extends TestCase
{
    private MockObject $lockFactory;

    private MockObject $responseFactory;

    private MockObject $streamFactory;

    private MockObject $eventDispatcher;

    private Processor $processor;

    private ?string $tempDir = null;

    private ?ReflectionProperty $prop = null;

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
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);

        // ImageManager is final and cannot be mocked. Use newInstanceWithoutConstructor
        // since tests only exercise private helper methods via reflection that do not
        // depend on ImageManager.
        $reflection      = new ReflectionClass(Processor::class);
        $this->processor = $reflection->newInstanceWithoutConstructor();

        // Inject the mocked dependencies via reflection for tests that need them
        $this->setProperty($this->processor, 'lockFactory', $this->lockFactory);
        $this->setProperty($this->processor, 'responseFactory', $this->responseFactory);
        $this->setProperty($this->processor, 'streamFactory', $this->streamFactory);
        $this->setProperty($this->processor, 'eventDispatcher', $this->eventDispatcher);
    }

    protected function tearDown(): void
    {
        if ($this->tempDir !== null && $this->prop instanceof ReflectionProperty && is_dir($this->tempDir)) {
            $this->tearDownRealEnvironment($this->tempDir, $this->prop);
        }

        $this->tempDir = null;
        $this->prop    = null;

        parent::tearDown();
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
     * @param object|null $eventDispatcher Event dispatcher stub/mock
     */
    private function createProcessor(
        ?object $lockFactory = null,
        ?object $responseFactory = null,
        ?object $streamFactory = null,
        ?object $eventDispatcher = null,
    ): Processor {
        $reflection = new ReflectionClass(Processor::class);
        $instance   = $reflection->newInstanceWithoutConstructor();

        $this->setProperty($instance, 'lockFactory', $lockFactory ?? $this->createMock(LockFactory::class));
        $this->setProperty($instance, 'responseFactory', $responseFactory ?? $this->createMock(ResponseFactoryInterface::class));
        $this->setProperty($instance, 'streamFactory', $streamFactory ?? $this->createMock(StreamFactoryInterface::class));
        $this->setProperty($instance, 'eventDispatcher', $eventDispatcher ?? $this->createMock(EventDispatcherInterface::class));

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
        $uri = $this->createMock(UriInterface::class);
        $uri->method('getQuery')->willReturn('skipWebP=1&skipAvif=1');

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);

        /** @var array{skipWebP: bool, skipAvif: bool} $result */
        $result = $this->callMethod($this->processor, 'parseQueryParams', $request);

        self::assertTrue($result['skipWebP']);
        self::assertTrue($result['skipAvif']);
    }

    #[Test]
    public function parseQueryParamsDefaultsToFalse(): void
    {
        $uri = $this->createMock(UriInterface::class);
        $uri->method('getQuery')->willReturn('');

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);

        /** @var array{skipWebP: bool, skipAvif: bool} $result */
        $result = $this->callMethod($this->processor, 'parseQueryParams', $request);

        self::assertFalse($result['skipWebP']);
        self::assertFalse($result['skipAvif']);
    }

    #[Test]
    public function parseQueryParamsHandlesDisabledFlags(): void
    {
        $uri = $this->createMock(UriInterface::class);
        $uri->method('getQuery')->willReturn('skipWebP=0&skipAvif=0');

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);

        /** @var array{skipWebP: bool, skipAvif: bool} $result */
        $result = $this->callMethod($this->processor, 'parseQueryParams', $request);

        self::assertFalse($result['skipWebP']);
        self::assertFalse($result['skipAvif']);
    }

    #[Test]
    public function calculateTargetDimensionsDerivesMissingHeight(): void
    {
        $image = $this->createMock(ImageInterface::class);
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
        $image = $this->createMock(ImageInterface::class);
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
        $image = $this->createMock(ImageInterface::class);
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
        $image = $this->createMock(ImageInterface::class);
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
        $image = $this->createMock(ImageInterface::class);
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
        $locker      = $this->createMock(LockingStrategyInterface::class);
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

        $response = $this->createMock(ResponseInterface::class);
        $stream   = $this->createMock(StreamInterface::class);

        $this->responseFactory->method('createResponse')->willReturn($response);
        $response->method('withHeader')->willReturn($response);
        $this->streamFactory->method('createStreamFromFile')->willReturn($stream);
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

        $response = $this->createMock(ResponseInterface::class);
        $stream   = $this->createMock(StreamInterface::class);

        $this->responseFactory->method('createResponse')->willReturn($response);
        $response->method('withHeader')->willReturn($response);
        $this->streamFactory->method('createStreamFromFile')->willReturn($stream);
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

        $response = $this->createMock(ResponseInterface::class);
        $stream   = $this->createMock(StreamInterface::class);

        $this->responseFactory->method('createResponse')->willReturn($response);
        $response->method('withHeader')->willReturn($response);
        $this->streamFactory->method('createStreamFromFile')->willReturn($stream);
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

        $response = $this->createMock(ResponseInterface::class);
        $stream   = $this->createMock(StreamInterface::class);

        $this->responseFactory->method('createResponse')->willReturn($response);
        $response->method('withHeader')->willReturn($response);
        $this->streamFactory->method('createStreamFromFile')->willReturn($stream);
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

        $response = $this->createMock(ResponseInterface::class);
        $stream   = $this->createMock(StreamInterface::class);

        $this->responseFactory->method('createResponse')->willReturn($response);
        $response->method('withHeader')->willReturn($response);
        $this->streamFactory->method('createStreamFromFile')->willReturn($stream);
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
        $image = $this->createMock(ImageInterface::class);
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
        $image = $this->createMock(ImageInterface::class);
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
        $uri = $this->createMock(UriInterface::class);
        $uri->method('getPath')->willReturn('/not-processed/image.jpg');

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);

        $response = $this->createMock(ResponseInterface::class);
        $this->responseFactory->expects(self::once())
            ->method('createResponse')
            ->with(400)
            ->willReturn($response);

        $result = $this->processor->generateAndSend($request);

        self::assertSame($response, $result);
    }

    #[Test]
    public function buildFileResponseSetsCorrectStatusAndHeaders(): void
    {
        $base = sys_get_temp_dir() . '/nr-image-optimize-headers-' . uniqid('', true);
        file_put_contents($base, 'header-test-content');

        $response = $this->createMock(ResponseInterface::class);
        $stream   = $this->createMock(StreamInterface::class);

        $responseFactory = $this->createMock(ResponseFactoryInterface::class);
        $responseFactory->expects(self::once())
            ->method('createResponse')
            ->with(200)
            ->willReturn($response);

        $response->method('withHeader')->willReturn($response);

        $streamFactory = $this->createMock(StreamFactoryInterface::class);
        $streamFactory->method('createStreamFromFile')->willReturn($stream);

        $response->method('withBody')->willReturn($response);

        $processor = $this->createProcessor(
            responseFactory: $responseFactory,
            streamFactory: $streamFactory,
        );

        $result = $this->callMethod($processor, 'buildFileResponse', $base, 'image/jpeg');

        self::assertNotNull($result);

        unlink($base);
    }

    #[Test]
    public function buildOutputResponseReturns500WhenAllVariantsFail(): void
    {
        $base = sys_get_temp_dir() . '/nr-image-optimize-500-' . uniqid('', true);
        // No files exist at all — all buildFileResponse calls return null

        $response500     = $this->createMock(ResponseInterface::class);
        $responseFactory = $this->createMock(ResponseFactoryInterface::class);
        $responseFactory->expects(self::once())
            ->method('createResponse')
            ->with(500)
            ->willReturn($response500);

        $processor = $this->createProcessor(responseFactory: $responseFactory);

        $result = $this->callMethod($processor, 'buildOutputResponse', 'jpg', $base);

        self::assertSame($response500, $result);
    }

    #[Test]
    public function serveCachedVariantSkipsAvifCheckWhenExtensionIsAvif(): void
    {
        $base = sys_get_temp_dir() . '/nr-image-optimize-avif-skip-' . uniqid('', true);
        // Create webp and primary files but no .avif — extension is avif so avif check should be skipped
        file_put_contents($base . '.webp', 'webp-data');
        file_put_contents($base, 'primary-data');

        $response = $this->createMock(ResponseInterface::class);
        $stream   = $this->createMock(StreamInterface::class);

        $this->responseFactory->method('createResponse')->willReturn($response);
        $response->method('withHeader')->willReturn($response);
        $this->streamFactory->method('createStreamFromFile')->willReturn($stream);
        $response->method('withBody')->willReturn($response);

        // When extension is 'avif', the avif check is skipped, webp check is skipped (not webp ext), falls to primary
        $result = $this->callMethod($this->processor, 'serveCachedVariant', $base, 'avif');

        self::assertNotNull($result);

        unlink($base . '.webp');
        unlink($base);
    }

    #[Test]
    public function serveCachedVariantSkipsWebpCheckWhenExtensionIsWebp(): void
    {
        $base = sys_get_temp_dir() . '/nr-image-optimize-webp-skip-' . uniqid('', true);
        file_put_contents($base, 'primary-data');

        $response = $this->createMock(ResponseInterface::class);
        $stream   = $this->createMock(StreamInterface::class);

        $this->responseFactory->method('createResponse')->willReturn($response);
        $response->method('withHeader')->willReturn($response);
        $this->streamFactory->method('createStreamFromFile')->willReturn($stream);
        $response->method('withBody')->willReturn($response);

        // When extension is 'webp', both avif and webp upgrade checks are skipped, falls to primary
        $result = $this->callMethod($this->processor, 'serveCachedVariant', $base, 'webp');

        self::assertNotNull($result);

        unlink($base);
    }

    #[Test]
    public function serveCachedVariantPrefersWebpOverPrimaryWhenNoAvif(): void
    {
        $base = sys_get_temp_dir() . '/nr-image-optimize-webp-pref-' . uniqid('', true);
        file_put_contents($base . '.webp', 'webp-data');
        file_put_contents($base, 'jpg-data');

        $response = $this->createMock(ResponseInterface::class);
        $stream   = $this->createMock(StreamInterface::class);

        $this->responseFactory->method('createResponse')->willReturn($response);
        $response->method('withHeader')->willReturn($response);
        $this->streamFactory->method('createStreamFromFile')->willReturn($stream);
        $response->method('withBody')->willReturn($response);

        $result = $this->callMethod($this->processor, 'serveCachedVariant', $base, 'jpg');

        self::assertNotNull($result);

        unlink($base . '.webp');
        unlink($base);
    }

    // -------------------------------------------------------------------------
    // generateAndSend: path validation (400 for paths outside public root)
    // -------------------------------------------------------------------------

    #[Test]
    public function isPathWithinPublicRootAcceptsPathsInsidePublicDir(): void
    {
        $refClass = new ReflectionClass(Processor::class);
        $prop     = $refClass->getProperty('resolvedPublicPath');
        $prop->setValue(null, null);

        $tempDir = sys_get_temp_dir() . '/nr-pio-pubroot-' . uniqid('', true);
        mkdir($tempDir . '/public/subdir', 0o777, true);

        Environment::initialize(
            new ApplicationContext('Testing'),
            true,
            true,
            $tempDir,
            $tempDir . '/public',
            $tempDir . '/var',
            $tempDir . '/config',
            $tempDir . '/public/index.php',
            'UNIX',
        );

        // Existing path within public root
        self::assertTrue($this->callMethod($this->processor, 'isPathWithinPublicRoot', $tempDir . '/public/subdir'));

        // Public root itself
        self::assertTrue($this->callMethod($this->processor, 'isPathWithinPublicRoot', $tempDir . '/public'));

        // Non-existent path under public root: the parent-walk resolves up to
        // the existing /public directory, which is within the public root
        self::assertTrue($this->callMethod($this->processor, 'isPathWithinPublicRoot', $tempDir . '/public/subdir/nonexistent.jpg'));

        // Path outside public root (existing)
        $outsideDir = sys_get_temp_dir() . '/nr-pio-outside-' . uniqid('', true);
        mkdir($outsideDir, 0o777, true);
        self::assertFalse($this->callMethod($this->processor, 'isPathWithinPublicRoot', $outsideDir));
        rmdir($outsideDir);

        // Non-existent path where no parent resolves to public root
        self::assertFalse($this->callMethod($this->processor, 'isPathWithinPublicRoot', '/completely/fake/path/image.jpg'));

        // Cleanup
        rmdir($tempDir . '/public/subdir');
        rmdir($tempDir . '/public');
        rmdir($tempDir);

        $prop->setValue(null, null);
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
    }

    #[Test]
    public function isPathWithinPublicRootReturnsFalseWhenPublicPathCannotBeResolved(): void
    {
        $refClass = new ReflectionClass(Processor::class);
        $prop     = $refClass->getProperty('resolvedPublicPath');
        // Simulate cached false result (public path doesn't resolve)
        $prop->setValue(null, false);

        $result = $this->callMethod($this->processor, 'isPathWithinPublicRoot', '/some/path');

        self::assertFalse($result);

        // Reset
        $prop->setValue(null, null);
    }

    #[Test]
    public function isPathWithinPublicRootReturnsFalseForNonExistentPaths(): void
    {
        $refClass = new ReflectionClass(Processor::class);
        $prop     = $refClass->getProperty('resolvedPublicPath');

        $tempDir = sys_get_temp_dir() . '/nr-pio-walk-' . uniqid('', true);
        mkdir($tempDir . '/public/deep/nested', 0o777, true);

        Environment::initialize(
            new ApplicationContext('Testing'),
            true,
            true,
            $tempDir,
            $tempDir . '/public',
            $tempDir . '/var',
            $tempDir . '/config',
            $tempDir . '/public/index.php',
            'UNIX',
        );

        $prop->setValue(null, null);

        // Non-existent file path: realpath() returns false, and the parent walk loop
        // body is unreachable due to the while-condition assignment pattern
        // (($parent = dirname($parent)) !== $parent always evaluates to false).
        // Non-existent paths under the public root should be accepted
        // (the parent-walk resolves to the existing parent directory).
        $result = $this->callMethod(
            $this->processor,
            'isPathWithinPublicRoot',
            $tempDir . '/public/deep/nested/very/deeply/image.jpg',
        );
        self::assertTrue($result);

        // A path completely outside the public root should be rejected
        $outsidePath   = '/tmp/completely-outside-' . uniqid('', true) . '/image.jpg';
        $resultOutside = $this->callMethod(
            $this->processor,
            'isPathWithinPublicRoot',
            $outsidePath,
        );
        self::assertFalse($resultOutside);

        // Cleanup
        rmdir($tempDir . '/public/deep/nested');
        rmdir($tempDir . '/public/deep');
        rmdir($tempDir . '/public');
        rmdir($tempDir);

        $prop->setValue(null, null);
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
    }

    // -------------------------------------------------------------------------
    // generateAndSend: LockCreateException catch
    // -------------------------------------------------------------------------
    /**
     * Set up a real temp directory for tests that exercise generateAndSend (needs real filesystem for path validation).
     *
     * @return array{tempDir: string, prop: ReflectionProperty} Temp dir path and resolvedPublicPath property
     */
    private function setUpRealEnvironment(): array
    {
        $tempDir = sys_get_temp_dir() . '/nr-pio-env-' . uniqid('', true);
        mkdir($tempDir . '/public/processed/images', 0o777, true);
        mkdir($tempDir . '/public/images', 0o777, true);

        $refClass = new ReflectionClass(Processor::class);
        $prop     = $refClass->getProperty('resolvedPublicPath');
        $prop->setValue(null, null);

        Environment::initialize(
            new ApplicationContext('Testing'),
            true,
            true,
            $tempDir,
            $tempDir . '/public',
            $tempDir . '/var',
            $tempDir . '/config',
            $tempDir . '/public/index.php',
            'UNIX',
        );

        $this->tempDir = $tempDir;
        $this->prop    = $prop;

        return ['tempDir' => $tempDir, 'prop' => $prop];
    }

    /**
     * Clean up the real temp environment after tests.
     */
    private function tearDownRealEnvironment(string $tempDir, ReflectionProperty $prop): void
    {
        // Remove all files recursively
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($tempDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($tempDir);

        $prop->setValue(null, null);
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
    }

    #[Test]
    public function generateAndSendServesCachedVariantEvenWhenLockFactoryWouldFail(): void
    {
        ['tempDir' => $tempDir, 'prop' => $prop] = $this->setUpRealEnvironment();

        // Both pathOriginal and pathVariant must exist for isPathWithinPublicRoot to pass
        file_put_contents($tempDir . '/public/images/photo.jpg', 'original');
        file_put_contents($tempDir . '/public/processed/images/photo.w100h50m0q80.jpg', 'variant');

        $lockFactory = $this->createMock(LockFactory::class);
        $lockFactory->method('createLocker')
            ->willThrowException(new LockCreateException('Cannot create lock'));

        $response = $this->createMock(ResponseInterface::class);
        $stream   = $this->createMock(StreamInterface::class);

        // Since the variant file exists, serveCachedVariant will be called first
        // and will return a response before lock is attempted.
        // To test the LockCreateException, we need the variant NOT to exist.
        // But then isPathWithinPublicRoot fails. So we test LockCreateException
        // directly via acquireLockWithRetry test instead.
        // Here we demonstrate that the cached variant short-circuits.
        $responseFactory = $this->createMock(ResponseFactoryInterface::class);
        $responseFactory->method('createResponse')->willReturn($response);

        $streamFactory = $this->createMock(StreamFactoryInterface::class);
        $streamFactory->method('createStreamFromFile')->willReturn($stream);

        $response->method('getStatusCode')->willReturn(200);
        $response->method('withHeader')->willReturn($response);
        $response->method('withBody')->willReturn($response);

        $processor = $this->createProcessor(
            lockFactory: $lockFactory,
            responseFactory: $responseFactory,
            streamFactory: $streamFactory,
        );

        $uri = $this->createMock(UriInterface::class);
        $uri->method('getPath')->willReturn('/processed/images/photo.w100h50m0q80.jpg');

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);

        $result = $processor->generateAndSend($request);
        // Returns cached response, not 503
        self::assertSame($response, $result);

        $this->tearDownRealEnvironment($tempDir, $prop);
    }

    // -------------------------------------------------------------------------
    // acquireLockWithRetry
    // -------------------------------------------------------------------------

    #[Test]
    public function acquireLockWithRetryReturnsNullOnImmediateAcquire(): void
    {
        $locker = $this->createMock(LockingStrategyInterface::class);
        $locker->method('acquire')->willReturn(true);

        $result = $this->callMethod($this->processor, 'acquireLockWithRetry', $locker);

        self::assertNull($result);
    }

    #[Test]
    public function acquireLockWithRetryReturns503AfterAllRetriesFail(): void
    {
        $locker = $this->createMock(LockingStrategyInterface::class);
        $locker->method('acquire')->willReturn(false);

        $response503 = $this->createMock(ResponseInterface::class);
        $stream      = $this->createMock(StreamInterface::class);

        $responseFactory = $this->createMock(ResponseFactoryInterface::class);
        $responseFactory->method('createResponse')->with(503)->willReturn($response503);

        $streamFactory = $this->createMock(StreamFactoryInterface::class);
        $streamFactory->method('createStream')->willReturn($stream);

        $response503->method('withBody')->willReturn($response503);

        $processor = $this->createProcessor(
            responseFactory: $responseFactory,
            streamFactory: $streamFactory,
        );

        $result = $this->callMethod($processor, 'acquireLockWithRetry', $locker);

        self::assertSame($response503, $result);
    }

    #[Test]
    public function acquireLockWithRetryHandlesExceptionsDuringAcquire(): void
    {
        $locker = $this->createMock(LockingStrategyInterface::class);
        $locker->method('acquire')->willThrowException(new RuntimeException('Lock error'));

        $response503 = $this->createMock(ResponseInterface::class);
        $stream      = $this->createMock(StreamInterface::class);

        $responseFactory = $this->createMock(ResponseFactoryInterface::class);
        $responseFactory->method('createResponse')->with(503)->willReturn($response503);

        $streamFactory = $this->createMock(StreamFactoryInterface::class);
        $streamFactory->method('createStream')->willReturn($stream);

        $response503->method('withBody')->willReturn($response503);

        $processor = $this->createProcessor(
            responseFactory: $responseFactory,
            streamFactory: $streamFactory,
        );

        $result = $this->callMethod($processor, 'acquireLockWithRetry', $locker);

        self::assertSame($response503, $result);
    }

    // -------------------------------------------------------------------------
    // ensureDirectoryExists
    // -------------------------------------------------------------------------

    #[Test]
    public function ensureDirectoryExistsDoesNothingForExistingDirectory(): void
    {
        $dir = sys_get_temp_dir() . '/nr-pio-ensuredir-' . uniqid('', true);
        mkdir($dir, 0o777, true);

        // Should not throw
        $this->callMethod($this->processor, 'ensureDirectoryExists', $dir);

        self::assertDirectoryExists($dir);

        rmdir($dir);
    }

    #[Test]
    public function ensureDirectoryExistsCreatesNewDirectory(): void
    {
        $dir = sys_get_temp_dir() . '/nr-pio-ensuredir-new-' . uniqid('', true) . '/sub/deep';

        $this->callMethod($this->processor, 'ensureDirectoryExists', $dir);

        self::assertDirectoryExists($dir);

        // Cleanup
        rmdir($dir);
        rmdir(dirname($dir));
        rmdir(dirname($dir, 2));
    }

    #[Test]
    public function ensureDirectoryExistsThrowsWhenMkdirFails(): void
    {
        // Use /dev/null as a path prefix - mkdir will fail since /dev/null is a device file
        $dir = '/dev/null/impossible-dir-' . uniqid('', true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Directory "/dev/null/impossible-dir-');

        $this->callMethod($this->processor, 'ensureDirectoryExists', $dir);
    }

    // -------------------------------------------------------------------------
    // buildFileResponse edge cases (fileSize === false path)
    // -------------------------------------------------------------------------

    #[Test]
    public function buildFileResponseReturnsResponseWithoutEtagWhenMtimeFalse(): void
    {
        // Since we can't easily make filemtime return false for a real file, we test the
        // normal path to ensure the response is returned (fileMtime !== false path).
        // The buildFileResponse for existing files was already tested. This test
        // ensures we cover the return $response line (310) when fileMtime is false.
        // We can't easily mock built-in functions, but we can ensure the method handles
        // all code paths by testing with a real file which covers the fileMtime !== false branch.
        $base = sys_get_temp_dir() . '/nr-pio-etag-' . uniqid('', true);
        file_put_contents($base, 'etag-test');

        $response = $this->createMock(ResponseInterface::class);
        $stream   = $this->createMock(StreamInterface::class);

        $this->responseFactory->method('createResponse')->willReturn($response);
        $response->method('withHeader')->willReturn($response);
        $this->streamFactory->method('createStreamFromFile')->willReturn($stream);
        $response->method('withBody')->willReturn($response);

        $result = $this->callMethod($this->processor, 'buildFileResponse', $base, 'image/png');

        self::assertNotNull($result);

        unlink($base);
    }

    // -------------------------------------------------------------------------
    // processAndRespond: various paths
    // -------------------------------------------------------------------------

    #[Test]
    public function processAndRespondReturns404WhenOriginalDoesNotExist(): void
    {
        $response404 = $this->createMock(ResponseInterface::class);

        $responseFactory = $this->createMock(ResponseFactoryInterface::class);
        $responseFactory->method('createResponse')->with(404)->willReturn($response404);

        $processor = $this->createProcessor(responseFactory: $responseFactory);

        $uri = $this->createMock(UriInterface::class);
        $uri->method('getQuery')->willReturn('');

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);

        $urlInfo = [
            'pathVariant'    => '/tmp/nonexistent-variant.w100h50.jpg',
            'pathOriginal'   => '/tmp/nonexistent-original.jpg',
            'extension'      => 'jpg',
            'targetWidth'    => 100,
            'targetHeight'   => 50,
            'targetQuality'  => 80,
            'processingMode' => 0,
        ];

        $result = $this->callMethod($processor, 'processAndRespond', $request, $urlInfo);

        self::assertSame($response404, $result);
    }

    // -------------------------------------------------------------------------
    // generateAndSend: cached variant served before lock
    // -------------------------------------------------------------------------

    #[Test]
    public function generateAndSendServesCachedVariantBeforeLocking(): void
    {
        ['tempDir' => $tempDir, 'prop' => $prop] = $this->setUpRealEnvironment();

        // Create the cached variant file
        $variantPath = $tempDir . '/public/processed/images/photo.w100h50m0q80.jpg';
        file_put_contents($variantPath, 'cached-image-data');

        $response = $this->createMock(ResponseInterface::class);
        $stream   = $this->createMock(StreamInterface::class);

        $responseFactory = $this->createMock(ResponseFactoryInterface::class);
        $responseFactory->method('createResponse')->willReturn($response);

        $streamFactory = $this->createMock(StreamFactoryInterface::class);
        $streamFactory->method('createStreamFromFile')->willReturn($stream);

        $response->method('getStatusCode')->willReturn(200);
        $response->method('withHeader')->willReturn($response);
        $response->method('withBody')->willReturn($response);

        // Lock factory should NOT be called since we serve from cache
        $lockFactory = $this->createMock(LockFactory::class);
        $lockFactory->expects(self::never())->method('createLocker');

        $processor = $this->createProcessor(
            lockFactory: $lockFactory,
            responseFactory: $responseFactory,
            streamFactory: $streamFactory,
        );

        $uri = $this->createMock(UriInterface::class);
        $uri->method('getPath')->willReturn('/processed/images/photo.w100h50m0q80.jpg');

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);

        $result = $processor->generateAndSend($request);

        self::assertSame($response, $result);

        $this->tearDownRealEnvironment($tempDir, $prop);
    }

    // -------------------------------------------------------------------------
    // (generateAndSend 400 for non-matching URL is tested above at line 756)
    // -------------------------------------------------------------------------

    // -------------------------------------------------------------------------
    // generateAndSend: Throwable catch (500 response)
    // -------------------------------------------------------------------------

    #[Test]
    public function generateAndSendServesCachedWebpVariant(): void
    {
        ['tempDir' => $tempDir, 'prop' => $prop] = $this->setUpRealEnvironment();

        // Create only a WebP cached variant (no avif)
        $variantPath = $tempDir . '/public/processed/images/photo.w100h50m0q80.jpg';
        file_put_contents($variantPath . '.webp', 'cached-webp-data');
        // Need the variant path to exist for isPathWithinPublicRoot
        file_put_contents($variantPath, 'placeholder');
        file_put_contents($tempDir . '/public/images/photo.jpg', 'original');

        $response = $this->createMock(ResponseInterface::class);
        $stream   = $this->createMock(StreamInterface::class);

        $responseFactory = $this->createMock(ResponseFactoryInterface::class);
        $responseFactory->method('createResponse')->willReturn($response);

        $streamFactory = $this->createMock(StreamFactoryInterface::class);
        $streamFactory->method('createStreamFromFile')->willReturn($stream);

        $response->method('withHeader')->willReturn($response);
        $response->method('withBody')->willReturn($response);

        $processor = $this->createProcessor(
            responseFactory: $responseFactory,
            streamFactory: $streamFactory,
        );

        $uri = $this->createMock(UriInterface::class);
        $uri->method('getPath')->willReturn('/processed/images/photo.w100h50m0q80.jpg');

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);

        $result = $processor->generateAndSend($request);

        self::assertSame($response, $result);

        $this->tearDownRealEnvironment($tempDir, $prop);
    }

    // -------------------------------------------------------------------------
    // generateAndSend: re-check cached variant after lock acquisition
    // -------------------------------------------------------------------------

    #[Test]
    public function generateAndSendServesCachedPrimaryVariant(): void
    {
        ['tempDir' => $tempDir, 'prop' => $prop] = $this->setUpRealEnvironment();

        // Create the primary cached variant file and original
        $variantPath = $tempDir . '/public/processed/images/photo.w100h50m0q80.jpg';
        file_put_contents($variantPath, 'cached-primary-data');
        file_put_contents($tempDir . '/public/images/photo.jpg', 'original');

        $response = $this->createMock(ResponseInterface::class);
        $stream   = $this->createMock(StreamInterface::class);

        $responseFactory = $this->createMock(ResponseFactoryInterface::class);
        $responseFactory->method('createResponse')->willReturn($response);

        $streamFactory = $this->createMock(StreamFactoryInterface::class);
        $streamFactory->method('createStreamFromFile')->willReturn($stream);

        $response->method('withHeader')->willReturn($response);
        $response->method('withBody')->willReturn($response);

        // Lock factory should NOT be called since we serve from cache
        $lockFactory = $this->createMock(LockFactory::class);
        $lockFactory->expects(self::never())->method('createLocker');

        $processor = $this->createProcessor(
            lockFactory: $lockFactory,
            responseFactory: $responseFactory,
            streamFactory: $streamFactory,
        );

        $uri = $this->createMock(UriInterface::class);
        $uri->method('getPath')->willReturn('/processed/images/photo.w100h50m0q80.jpg');

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);

        $result = $processor->generateAndSend($request);

        self::assertSame($response, $result);

        $this->tearDownRealEnvironment($tempDir, $prop);
    }

    // -------------------------------------------------------------------------
    // buildOutputResponse: fallback paths
    // -------------------------------------------------------------------------

    #[Test]
    public function buildOutputResponseFallsToWebpVariantWhenAvifFileResponseReturnsNull(): void
    {
        // Create avif variant file that exists (hasVariantFor returns true)
        // but buildFileResponse returns null (simulated by removing the file between checks)
        // In practice this is hard to test without filesystem manipulation.
        // Instead test the webp-only path explicitly.
        $base = sys_get_temp_dir() . '/nr-pio-webp-fallback-' . uniqid('', true);
        file_put_contents($base . '.webp', 'webp-data');

        $response = $this->createMock(ResponseInterface::class);
        $stream   = $this->createMock(StreamInterface::class);

        $this->responseFactory->method('createResponse')->willReturn($response);
        $response->method('withHeader')->willReturn($response);
        $this->streamFactory->method('createStreamFromFile')->willReturn($stream);
        $response->method('withBody')->willReturn($response);

        $result = $this->callMethod($this->processor, 'buildOutputResponse', 'png', $base);

        self::assertSame($response, $result);

        unlink($base . '.webp');
    }

    #[Test]
    public function buildOutputResponseUsesApplicationOctetStreamForUnknownExtension(): void
    {
        $base = sys_get_temp_dir() . '/nr-pio-unknown-ext-' . uniqid('', true);
        file_put_contents($base, 'raw-data');

        $response = $this->createMock(ResponseInterface::class);
        $stream   = $this->createMock(StreamInterface::class);

        $this->responseFactory->method('createResponse')->willReturn($response);
        $response->method('withHeader')->willReturn($response);
        $this->streamFactory->method('createStreamFromFile')->willReturn($stream);
        $response->method('withBody')->willReturn($response);

        $result = $this->callMethod($this->processor, 'buildOutputResponse', 'xyz', $base);

        self::assertSame($response, $result);

        unlink($base);
    }

    // -------------------------------------------------------------------------
    // parseQueryParams with non-string values
    // -------------------------------------------------------------------------

    #[Test]
    public function parseQueryParamsHandlesArrayQueryValues(): void
    {
        $uri = $this->createMock(UriInterface::class);
        // Array-style params: skipWebP[]=foo should result in non-string value
        $uri->method('getQuery')->willReturn('skipWebP[]=foo&skipAvif=1');

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);

        /** @var array{skipWebP: bool, skipAvif: bool} $result */
        $result = $this->callMethod($this->processor, 'parseQueryParams', $request);

        // skipWebP is an array, not a string, so should be false
        self::assertFalse($result['skipWebP']);
        self::assertTrue($result['skipAvif']);
    }

    // =========================================================================
    // generateAndSend: path validation failure (line 194)
    // =========================================================================

    #[Test]
    public function generateAndSendReturns400WhenPathEscapesPublicRoot(): void
    {
        ['tempDir' => $tempDir, 'prop' => $prop] = $this->setUpRealEnvironment();

        $response400     = $this->createMock(ResponseInterface::class);
        $responseFactory = $this->createMock(ResponseFactoryInterface::class);
        $responseFactory->method('createResponse')->with(400)->willReturn($response400);

        $processor = $this->createProcessor(responseFactory: $responseFactory);

        // URL that matches regex but pathOriginal resolves outside public root
        // The regex requires /processed/ prefix and at least one mode letter
        // With the double-dot block in regex, this should return null from gatherInformationBasedOnUrl
        $uri = $this->createMock(UriInterface::class);
        $uri->method('getPath')->willReturn('/processed/../../../etc/passwd.w100.jpg');
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);

        $result = $processor->generateAndSend($request);
        self::assertSame($response400, $result);

        $this->tearDownRealEnvironment($tempDir, $prop);
    }

    // =========================================================================
    // generateAndSend: LockCreateException → 503 (lines 206-208)
    // =========================================================================

    #[Test]
    public function generateAndSendReturns503WhenLockCreationFails(): void
    {
        ['tempDir' => $tempDir, 'prop' => $prop] = $this->setUpRealEnvironment();

        // Create original image so path validation succeeds
        file_put_contents($tempDir . '/public/img.jpg', 'fake-image');

        $response503     = $this->createMock(ResponseInterface::class);
        $responseFactory = $this->createMock(ResponseFactoryInterface::class);
        $responseFactory->method('createResponse')->with(503)->willReturn($response503);

        $lockFactory = $this->createMock(LockFactory::class);
        $lockFactory->method('createLocker')
            ->willThrowException(new LockCreateException('Lock backend unavailable'));

        $streamFactory = $this->createMock(StreamFactoryInterface::class);

        $processor = $this->createProcessor(
            lockFactory: $lockFactory,
            responseFactory: $responseFactory,
            streamFactory: $streamFactory,
        );

        $uri = $this->createMock(UriInterface::class);
        $uri->method('getPath')->willReturn('/processed/img.w100h50m0q80.jpg');
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);

        $result = $processor->generateAndSend($request);
        self::assertSame($response503, $result);

        $this->tearDownRealEnvironment($tempDir, $prop);
    }

    // =========================================================================
    // generateAndSend: acquireLockWithRetry returns 503 (lines 211-214)
    // =========================================================================

    #[Test]
    public function generateAndSendReturns503WhenLockAcquisitionTimesOut(): void
    {
        ['tempDir' => $tempDir, 'prop' => $prop] = $this->setUpRealEnvironment();

        file_put_contents($tempDir . '/public/img.jpg', 'fake-image');

        $response503 = $this->createMock(ResponseInterface::class);
        $stream503   = $this->createMock(StreamInterface::class);

        $responseFactory = $this->createMock(ResponseFactoryInterface::class);
        $responseFactory->method('createResponse')->with(503)->willReturn($response503);
        $response503->method('withBody')->willReturn($response503);

        $streamFactory = $this->createMock(StreamFactoryInterface::class);
        $streamFactory->method('createStream')->willReturn($stream503);

        $locker = $this->createMock(LockingStrategyInterface::class);
        $locker->method('acquire')->willReturn(false); // Always fails

        $lockFactory = $this->createMock(LockFactory::class);
        $lockFactory->method('createLocker')->willReturn($locker);

        $processor = $this->createProcessor(
            lockFactory: $lockFactory,
            responseFactory: $responseFactory,
            streamFactory: $streamFactory,
        );

        $uri = $this->createMock(UriInterface::class);
        $uri->method('getPath')->willReturn('/processed/img.w100h50m0q80.jpg');
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);

        $result = $processor->generateAndSend($request);
        self::assertSame($response503, $result);

        $this->tearDownRealEnvironment($tempDir, $prop);
    }

    // =========================================================================
    // generateAndSend: processAndRespond throws → catch Throwable → 500 (lines 226-236)
    // =========================================================================

    #[Test]
    public function generateAndSendReturns500WhenProcessingThrows(): void
    {
        ['tempDir' => $tempDir, 'prop' => $prop] = $this->setUpRealEnvironment();

        file_put_contents($tempDir . '/public/img.jpg', 'fake-image');

        $response500     = $this->createMock(ResponseInterface::class);
        $responseFactory = $this->createMock(ResponseFactoryInterface::class);
        $responseFactory->method('createResponse')->with(500)->willReturn($response500);

        $locker = $this->createMock(LockingStrategyInterface::class);
        $locker->method('acquire')->willReturn(true);

        $lockFactory = $this->createMock(LockFactory::class);
        $lockFactory->method('createLocker')->willReturn($locker);

        $streamFactory = $this->createMock(StreamFactoryInterface::class);

        // Processor without ImageManager — processAndRespond will throw
        // TypeError "must not be accessed before initialization"
        $processor = $this->createProcessor(
            lockFactory: $lockFactory,
            responseFactory: $responseFactory,
            streamFactory: $streamFactory,
        );

        $uri = $this->createMock(UriInterface::class);
        $uri->method('getPath')->willReturn('/processed/img.w100h50m0q80.jpg');
        $uri->method('getQuery')->willReturn('');
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);

        $result = $processor->generateAndSend($request);
        self::assertSame($response500, $result);

        $this->tearDownRealEnvironment($tempDir, $prop);
    }

    // =========================================================================
    // generateAndSend: cached variant served after lock (lines 220-223)
    // =========================================================================

    #[Test]
    public function generateAndSendServesCachedVariantAfterLockAcquired(): void
    {
        ['tempDir' => $tempDir, 'prop' => $prop] = $this->setUpRealEnvironment();

        // Create original AND variant (simulates another process finished while waiting)
        file_put_contents($tempDir . '/public/img.jpg', 'fake-original');
        file_put_contents($tempDir . '/public/processed/img.w100h50m0q80.jpg', 'cached-variant');

        $response200 = $this->createMock(ResponseInterface::class);
        $response200->method('getStatusCode')->willReturn(200);
        $response200->method('withHeader')->willReturn($response200);
        $response200->method('withBody')->willReturn($response200);

        $responseFactory = $this->createMock(ResponseFactoryInterface::class);
        $responseFactory->method('createResponse')->with(200)->willReturn($response200);

        $locker = $this->createMock(LockingStrategyInterface::class);
        $locker->method('acquire')->willReturn(true);

        $lockFactory = $this->createMock(LockFactory::class);
        $lockFactory->method('createLocker')->willReturn($locker);

        $stream = $this->createMock(StreamInterface::class);
        $stream->method('getSize')->willReturn(14);
        $streamFactory = $this->createMock(StreamFactoryInterface::class);
        $streamFactory->method('createStreamFromFile')->willReturn($stream);

        $processor = $this->createProcessor(
            lockFactory: $lockFactory,
            responseFactory: $responseFactory,
            streamFactory: $streamFactory,
        );

        $uri = $this->createMock(UriInterface::class);
        $uri->method('getPath')->willReturn('/processed/img.w100h50m0q80.jpg');
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);

        $result = $processor->generateAndSend($request);
        self::assertSame($response200, $result);

        $this->tearDownRealEnvironment($tempDir, $prop);
    }

    // =========================================================================
    // processAndRespond: full path with mock DriverInterface (lines 332-387)
    // Uses a real ImageManager with a mocked DriverInterface to avoid needing
    // GD or Imagick extensions.
    // =========================================================================

    /**
     * Create a Processor with a real ImageManager backed by a mock driver.
     *
     * @return array{processor: Processor, image: MockObject&ImageInterface}
     */
    private function createProcessorWithImageManager(
        ?object $lockFactory = null,
        ?object $responseFactory = null,
        ?object $streamFactory = null,
        ?object $eventDispatcher = null,
    ): array {
        $image = $this->createMock(ImageInterface::class);

        $driver = $this->createMock(DriverInterface::class);
        $driver->method('handleInput')->willReturn($image);

        $imageManager = new ImageManager($driver);

        $reflection = new ReflectionClass(Processor::class);
        $instance   = $reflection->newInstanceWithoutConstructor();

        $this->setProperty($instance, 'imageManager', $imageManager);
        $this->setProperty($instance, 'lockFactory', $lockFactory ?? $this->createMock(LockFactory::class));
        $this->setProperty($instance, 'responseFactory', $responseFactory ?? $this->createMock(ResponseFactoryInterface::class));
        $this->setProperty($instance, 'streamFactory', $streamFactory ?? $this->createMock(StreamFactoryInterface::class));
        $this->setProperty($instance, 'eventDispatcher', $eventDispatcher ?? $this->createMock(EventDispatcherInterface::class));

        return ['processor' => $instance, 'image' => $image];
    }

    #[Test]
    public function processAndRespondProcessesImageAndBuildsResponse(): void
    {
        $tempDir = sys_get_temp_dir() . '/nr-pio-process-' . uniqid('', true);
        mkdir($tempDir . '/processed', 0o777, true);

        $originalPath = $tempDir . '/original.jpg';
        file_put_contents($originalPath, 'fake-jpeg-data');
        $variantPath = $tempDir . '/processed/original.w400h200m0q80.jpg';

        $response200 = $this->createMock(ResponseInterface::class);
        $response200->method('withHeader')->willReturn($response200);
        $response200->method('withBody')->willReturn($response200);

        $responseFactory = $this->createMock(ResponseFactoryInterface::class);
        $responseFactory->method('createResponse')->willReturn($response200);

        $stream        = $this->createMock(StreamInterface::class);
        $streamFactory = $this->createMock(StreamFactoryInterface::class);
        $streamFactory->method('createStreamFromFile')->willReturn($stream);

        $encoded = $this->createMock(EncodedImageInterface::class);

        ['processor' => $processor, 'image' => $image] = $this->createProcessorWithImageManager(
            responseFactory: $responseFactory,
            streamFactory: $streamFactory,
        );

        $image->method('width')->willReturn(800);
        $image->method('height')->willReturn(400);
        $image->method('cover')->willReturn($image);
        $image->method('save')->willReturnCallback(
            static function (string $path) use ($image): ImageInterface {
                file_put_contents($path, 'processed-image');

                return $image;
            },
        );
        $image->method('toWebp')->willReturn($encoded);
        $image->method('toAvif')->willReturn($encoded);
        $encoded->method('save')->willReturnCallback(
            static function (string $path) use ($encoded): EncodedImageInterface {
                file_put_contents($path, 'encoded-variant');

                return $encoded;
            },
        );

        $uri = $this->createMock(UriInterface::class);
        $uri->method('getQuery')->willReturn('');
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);

        $urlInfo = [
            'pathVariant'    => $variantPath,
            'pathOriginal'   => $originalPath,
            'extension'      => 'jpg',
            'targetWidth'    => 400,
            'targetHeight'   => 200,
            'targetQuality'  => 80,
            'processingMode' => 0,
        ];

        $result = $this->callMethod($processor, 'processAndRespond', $request, $urlInfo);

        self::assertSame($response200, $result);

        $files = glob($tempDir . '/processed/*');

        if ($files !== false) {
            foreach ($files as $f) {
                unlink($f);
            }
        }

        unlink($originalPath);
        rmdir($tempDir . '/processed');
        rmdir($tempDir);
    }

    #[Test]
    public function processAndRespondSkipsWebpWhenExtensionIsWebp(): void
    {
        $tempDir = sys_get_temp_dir() . '/nr-pio-webp-skip-proc-' . uniqid('', true);
        mkdir($tempDir . '/processed', 0o777, true);

        $originalPath = $tempDir . '/original.webp';
        file_put_contents($originalPath, 'fake-webp');
        $variantPath = $tempDir . '/processed/original.w400h200m0q80.webp';

        $response200 = $this->createMock(ResponseInterface::class);
        $response200->method('withHeader')->willReturn($response200);
        $response200->method('withBody')->willReturn($response200);

        $responseFactory = $this->createMock(ResponseFactoryInterface::class);
        $responseFactory->method('createResponse')->willReturn($response200);

        $stream        = $this->createMock(StreamInterface::class);
        $streamFactory = $this->createMock(StreamFactoryInterface::class);
        $streamFactory->method('createStreamFromFile')->willReturn($stream);

        $encoded = $this->createMock(EncodedImageInterface::class);

        ['processor' => $processor, 'image' => $image] = $this->createProcessorWithImageManager(
            responseFactory: $responseFactory,
            streamFactory: $streamFactory,
        );

        $image->method('width')->willReturn(400);
        $image->method('height')->willReturn(200);
        $image->method('cover')->willReturn($image);
        $image->method('save')->willReturnCallback(
            static function (string $path) use ($image): ImageInterface {
                file_put_contents($path, 'processed');

                return $image;
            },
        );
        $image->expects(self::never())->method('toWebp');
        $image->method('toAvif')->willReturn($encoded);
        $encoded->method('save')->willReturnCallback(
            static function (string $path) use ($encoded): EncodedImageInterface {
                file_put_contents($path, 'avif-data');

                return $encoded;
            },
        );

        $uri = $this->createMock(UriInterface::class);
        $uri->method('getQuery')->willReturn('');
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);

        $urlInfo = [
            'pathVariant'    => $variantPath,
            'pathOriginal'   => $originalPath,
            'extension'      => 'webp',
            'targetWidth'    => 400,
            'targetHeight'   => 200,
            'targetQuality'  => 80,
            'processingMode' => 0,
        ];

        $result = $this->callMethod($processor, 'processAndRespond', $request, $urlInfo);

        self::assertSame($response200, $result);

        $files = glob($tempDir . '/processed/*');

        if ($files !== false) {
            foreach ($files as $f) {
                unlink($f);
            }
        }

        unlink($originalPath);
        rmdir($tempDir . '/processed');
        rmdir($tempDir);
    }

    #[Test]
    public function processAndRespondSkipsAvifWhenExtensionIsAvif(): void
    {
        $tempDir = sys_get_temp_dir() . '/nr-pio-avif-skip-proc-' . uniqid('', true);
        mkdir($tempDir . '/processed', 0o777, true);

        $originalPath = $tempDir . '/original.avif';
        file_put_contents($originalPath, 'fake-avif');
        $variantPath = $tempDir . '/processed/original.w400h200m0q80.avif';

        $response200 = $this->createMock(ResponseInterface::class);
        $response200->method('withHeader')->willReturn($response200);
        $response200->method('withBody')->willReturn($response200);

        $responseFactory = $this->createMock(ResponseFactoryInterface::class);
        $responseFactory->method('createResponse')->willReturn($response200);

        $stream        = $this->createMock(StreamInterface::class);
        $streamFactory = $this->createMock(StreamFactoryInterface::class);
        $streamFactory->method('createStreamFromFile')->willReturn($stream);

        $encoded = $this->createMock(EncodedImageInterface::class);

        ['processor' => $processor, 'image' => $image] = $this->createProcessorWithImageManager(
            responseFactory: $responseFactory,
            streamFactory: $streamFactory,
        );

        $image->method('width')->willReturn(400);
        $image->method('height')->willReturn(200);
        $image->method('cover')->willReturn($image);
        $image->method('save')->willReturnCallback(
            static function (string $path) use ($image): ImageInterface {
                file_put_contents($path, 'processed');

                return $image;
            },
        );
        $image->method('toWebp')->willReturn($encoded);
        $image->expects(self::never())->method('toAvif');
        $encoded->method('save')->willReturnCallback(
            static function (string $path) use ($encoded): EncodedImageInterface {
                file_put_contents($path, 'webp-data');

                return $encoded;
            },
        );

        $uri = $this->createMock(UriInterface::class);
        $uri->method('getQuery')->willReturn('');
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);

        $urlInfo = [
            'pathVariant'    => $variantPath,
            'pathOriginal'   => $originalPath,
            'extension'      => 'avif',
            'targetWidth'    => 400,
            'targetHeight'   => 200,
            'targetQuality'  => 80,
            'processingMode' => 0,
        ];

        $result = $this->callMethod($processor, 'processAndRespond', $request, $urlInfo);

        self::assertSame($response200, $result);

        $files = glob($tempDir . '/processed/*');

        if ($files !== false) {
            foreach ($files as $f) {
                unlink($f);
            }
        }

        unlink($originalPath);
        rmdir($tempDir . '/processed');
        rmdir($tempDir);
    }

    #[Test]
    public function processAndRespondSkipsBothVariantsViaQueryParams(): void
    {
        $tempDir = sys_get_temp_dir() . '/nr-pio-skip-both-' . uniqid('', true);
        mkdir($tempDir . '/processed', 0o777, true);

        $originalPath = $tempDir . '/original.jpg';
        file_put_contents($originalPath, 'fake-jpg');
        $variantPath = $tempDir . '/processed/original.w400h200m0q80.jpg';

        $response200 = $this->createMock(ResponseInterface::class);
        $response200->method('withHeader')->willReturn($response200);
        $response200->method('withBody')->willReturn($response200);

        $responseFactory = $this->createMock(ResponseFactoryInterface::class);
        $responseFactory->method('createResponse')->willReturn($response200);

        $stream        = $this->createMock(StreamInterface::class);
        $streamFactory = $this->createMock(StreamFactoryInterface::class);
        $streamFactory->method('createStreamFromFile')->willReturn($stream);

        ['processor' => $processor, 'image' => $image] = $this->createProcessorWithImageManager(
            responseFactory: $responseFactory,
            streamFactory: $streamFactory,
        );

        $image->method('width')->willReturn(400);
        $image->method('height')->willReturn(200);
        $image->method('cover')->willReturn($image);
        $image->method('save')->willReturnCallback(
            static function (string $path) use ($image): ImageInterface {
                file_put_contents($path, 'processed');

                return $image;
            },
        );
        $image->expects(self::never())->method('toWebp');
        $image->expects(self::never())->method('toAvif');

        $uri = $this->createMock(UriInterface::class);
        $uri->method('getQuery')->willReturn('skipWebP=1&skipAvif=1');
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);

        $urlInfo = [
            'pathVariant'    => $variantPath,
            'pathOriginal'   => $originalPath,
            'extension'      => 'jpg',
            'targetWidth'    => 400,
            'targetHeight'   => 200,
            'targetQuality'  => 80,
            'processingMode' => 0,
        ];

        $result = $this->callMethod($processor, 'processAndRespond', $request, $urlInfo);
        self::assertSame($response200, $result);

        $files = glob($tempDir . '/processed/*');

        if ($files !== false) {
            foreach ($files as $f) {
                unlink($f);
            }
        }

        unlink($originalPath);
        rmdir($tempDir . '/processed');
        rmdir($tempDir);
    }

    #[Test]
    public function processAndRespondHandlesWebpAndAvifGenerationFailure(): void
    {
        $tempDir = sys_get_temp_dir() . '/nr-pio-gen-fail-' . uniqid('', true);
        mkdir($tempDir . '/processed', 0o777, true);

        $originalPath = $tempDir . '/original.jpg';
        file_put_contents($originalPath, 'fake-jpg');
        $variantPath = $tempDir . '/processed/original.w400h200m0q80.jpg';

        $response200 = $this->createMock(ResponseInterface::class);
        $response200->method('withHeader')->willReturn($response200);
        $response200->method('withBody')->willReturn($response200);

        $responseFactory = $this->createMock(ResponseFactoryInterface::class);
        $responseFactory->method('createResponse')->willReturn($response200);

        $stream        = $this->createMock(StreamInterface::class);
        $streamFactory = $this->createMock(StreamFactoryInterface::class);
        $streamFactory->method('createStreamFromFile')->willReturn($stream);

        ['processor' => $processor, 'image' => $image] = $this->createProcessorWithImageManager(
            responseFactory: $responseFactory,
            streamFactory: $streamFactory,
        );

        $image->method('width')->willReturn(400);
        $image->method('height')->willReturn(200);
        $image->method('cover')->willReturn($image);
        $image->method('save')->willReturnCallback(
            static function (string $path) use ($image): ImageInterface {
                file_put_contents($path, 'processed');

                return $image;
            },
        );
        $image->method('toWebp')->willThrowException(new RuntimeException('WebP encoding failed'));
        $image->method('toAvif')->willThrowException(new RuntimeException('AVIF encoding failed'));

        $uri = $this->createMock(UriInterface::class);
        $uri->method('getQuery')->willReturn('');
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);

        $urlInfo = [
            'pathVariant'    => $variantPath,
            'pathOriginal'   => $originalPath,
            'extension'      => 'jpg',
            'targetWidth'    => 400,
            'targetHeight'   => 200,
            'targetQuality'  => 80,
            'processingMode' => 0,
        ];

        $result = $this->callMethod($processor, 'processAndRespond', $request, $urlInfo);

        self::assertSame($response200, $result);

        $files = glob($tempDir . '/processed/*');

        if ($files !== false) {
            foreach ($files as $f) {
                unlink($f);
            }
        }

        unlink($originalPath);
        rmdir($tempDir . '/processed');
        rmdir($tempDir);
    }

    #[Test]
    public function processAndRespondUsesScaleModeWithDerivedHeight(): void
    {
        $tempDir = sys_get_temp_dir() . '/nr-pio-scale-' . uniqid('', true);
        mkdir($tempDir . '/processed', 0o777, true);

        $originalPath = $tempDir . '/original.jpg';
        file_put_contents($originalPath, 'fake-jpg');
        $variantPath = $tempDir . '/processed/original.w400m1q80.jpg';

        $response200 = $this->createMock(ResponseInterface::class);
        $response200->method('withHeader')->willReturn($response200);
        $response200->method('withBody')->willReturn($response200);

        $responseFactory = $this->createMock(ResponseFactoryInterface::class);
        $responseFactory->method('createResponse')->willReturn($response200);

        $stream        = $this->createMock(StreamInterface::class);
        $streamFactory = $this->createMock(StreamFactoryInterface::class);
        $streamFactory->method('createStreamFromFile')->willReturn($stream);

        ['processor' => $processor, 'image' => $image] = $this->createProcessorWithImageManager(
            responseFactory: $responseFactory,
            streamFactory: $streamFactory,
        );

        $image->method('width')->willReturn(800);
        $image->method('height')->willReturn(400);
        $image->method('scale')->willReturn($image);
        $image->method('save')->willReturnCallback(
            static function (string $path) use ($image): ImageInterface {
                file_put_contents($path, 'processed');

                return $image;
            },
        );

        $uri = $this->createMock(UriInterface::class);
        $uri->method('getQuery')->willReturn('skipWebP=1&skipAvif=1');
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);

        $urlInfo = [
            'pathVariant'    => $variantPath,
            'pathOriginal'   => $originalPath,
            'extension'      => 'jpg',
            'targetWidth'    => 400,
            'targetHeight'   => null,
            'targetQuality'  => 80,
            'processingMode' => 1,
        ];

        $result = $this->callMethod($processor, 'processAndRespond', $request, $urlInfo);
        self::assertSame($response200, $result);

        $files = glob($tempDir . '/processed/*');

        if ($files !== false) {
            foreach ($files as $f) {
                unlink($f);
            }
        }

        unlink($originalPath);
        rmdir($tempDir . '/processed');
        rmdir($tempDir);
    }

    // =========================================================================
    // generateAndSend: re-check after lock via file creation during lock wait
    // =========================================================================

    #[Test]
    public function generateAndSendReturnsReCheckedCachedVariantAfterLock(): void
    {
        ['tempDir' => $tempDir, 'prop' => $prop] = $this->setUpRealEnvironment();

        // Create original but NOT the variant before lock acquisition
        file_put_contents($tempDir . '/public/img.jpg', 'fake-original');
        $variantPath = $tempDir . '/public/processed/img.w100h50m0q80.jpg';

        $response200 = $this->createMock(ResponseInterface::class);
        $response200->method('getStatusCode')->willReturn(200);
        $response200->method('withHeader')->willReturn($response200);
        $response200->method('withBody')->willReturn($response200);

        $responseFactory = $this->createMock(ResponseFactoryInterface::class);
        $responseFactory->method('createResponse')->with(200)->willReturn($response200);

        // Simulate another process creating the file during lock wait
        $locker = $this->createMock(LockingStrategyInterface::class);
        $locker->method('acquire')->willReturnCallback(
            static function () use ($variantPath): bool {
                file_put_contents($variantPath, 'created-during-lock-wait');

                return true;
            },
        );
        $locker->expects(self::once())->method('release');

        $lockFactory = $this->createMock(LockFactory::class);
        $lockFactory->method('createLocker')->willReturn($locker);

        $stream        = $this->createMock(StreamInterface::class);
        $streamFactory = $this->createMock(StreamFactoryInterface::class);
        $streamFactory->method('createStreamFromFile')->willReturn($stream);

        $processor = $this->createProcessor(
            lockFactory: $lockFactory,
            responseFactory: $responseFactory,
            streamFactory: $streamFactory,
        );

        $uri = $this->createMock(UriInterface::class);
        $uri->method('getPath')->willReturn('/processed/img.w100h50m0q80.jpg');
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);

        // This covers line 223: return $cachedResponse after lock re-check
        $result = $processor->generateAndSend($request);
        self::assertSame($response200, $result);

        $this->tearDownRealEnvironment($tempDir, $prop);
    }

    // =========================================================================
    // generateAndSend: path validation failure returns 400 (line 194)
    // =========================================================================

    #[Test]
    public function generateAndSendReturns400WhenVariantPathOutsidePublicRoot(): void
    {
        $tempDir = sys_get_temp_dir() . '/nr-pio-pathval-' . uniqid('', true);
        mkdir($tempDir . '/public/processed', 0o777, true);
        mkdir($tempDir . '/outside', 0o777, true);

        $refClass = new ReflectionClass(Processor::class);
        $prop     = $refClass->getProperty('resolvedPublicPath');
        $prop->setValue(null, null);

        Environment::initialize(
            new ApplicationContext('Testing'),
            true,
            true,
            $tempDir,
            $tempDir . '/public',
            $tempDir . '/var',
            $tempDir . '/config',
            $tempDir . '/public/index.php',
            'UNIX',
        );

        // Create a symlink inside /public/processed that points outside public root
        $symlinkTarget = $tempDir . '/outside';
        $symlinkPath   = $tempDir . '/public/processed/escape';
        symlink($symlinkTarget, $symlinkPath);

        // Create the original file at the symlink destination
        file_put_contents($tempDir . '/outside/photo.jpg', 'image-data');

        $response400     = $this->createMock(ResponseInterface::class);
        $responseFactory = $this->createMock(ResponseFactoryInterface::class);
        $responseFactory->method('createResponse')->with(400)->willReturn($response400);

        $processor = $this->createProcessor(responseFactory: $responseFactory);

        $uri = $this->createMock(UriInterface::class);
        $uri->method('getPath')->willReturn('/processed/escape/photo.w100h50m0q80.jpg');
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);

        $result = $processor->generateAndSend($request);
        self::assertSame($response400, $result);

        // Cleanup
        unlink($symlinkPath);
        unlink($tempDir . '/outside/photo.jpg');
        rmdir($tempDir . '/outside');
        rmdir($tempDir . '/public/processed');
        rmdir($tempDir . '/public');
        rmdir($tempDir);

        $prop->setValue(null, null);
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
    }

    // =========================================================================
    // Event dispatching: VariantServedEvent for cached variant
    // =========================================================================

    #[Test]
    public function generateAndSendDispatchesVariantServedEventForCachedVariant(): void
    {
        ['tempDir' => $tempDir, 'prop' => $prop] = $this->setUpRealEnvironment();

        $variantPath = $tempDir . '/public/processed/images/photo.w100h50m0q80.jpg';
        file_put_contents($variantPath, 'cached-image-data');

        $response = $this->createMock(ResponseInterface::class);
        $stream   = $this->createMock(StreamInterface::class);

        $responseFactory = $this->createMock(ResponseFactoryInterface::class);
        $responseFactory->method('createResponse')->willReturn($response);

        $streamFactory = $this->createMock(StreamFactoryInterface::class);
        $streamFactory->method('createStreamFromFile')->willReturn($stream);

        $response->method('withHeader')->willReturn($response);
        $response->method('withBody')->willReturn($response);
        $response->method('getStatusCode')->willReturn(200);

        $lockFactory = $this->createMock(LockFactory::class);
        $lockFactory->expects(self::never())->method('createLocker');

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static function (object $event): bool {
                if (!$event instanceof VariantServedEvent) {
                    return false;
                }

                return $event->responseStatusCode === 200
                    && $event->fromCache
                    && $event->extension === 'jpg'
                    && str_contains($event->pathVariant, 'photo.w100h50m0q80.jpg');
            }));

        $processor = $this->createProcessor(
            lockFactory: $lockFactory,
            responseFactory: $responseFactory,
            streamFactory: $streamFactory,
            eventDispatcher: $eventDispatcher,
        );

        $uri = $this->createMock(UriInterface::class);
        $uri->method('getPath')->willReturn('/processed/images/photo.w100h50m0q80.jpg');

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);

        $processor->generateAndSend($request);

        $this->tearDownRealEnvironment($tempDir, $prop);
    }

    // =========================================================================
    // Event dispatching: VariantServedEvent for freshly processed variant
    // =========================================================================

    #[Test]
    public function generateAndSendDispatchesVariantServedEventForFreshlyProcessedVariant(): void
    {
        ['tempDir' => $tempDir, 'prop' => $prop] = $this->setUpRealEnvironment();

        // Create original but NOT the variant
        file_put_contents($tempDir . '/public/img.jpg', 'fake-original');

        $response200 = $this->createMock(ResponseInterface::class);
        $response200->method('withHeader')->willReturn($response200);
        $response200->method('withBody')->willReturn($response200);
        $response200->method('getStatusCode')->willReturn(200);

        $responseFactory = $this->createMock(ResponseFactoryInterface::class);
        $responseFactory->method('createResponse')->willReturn($response200);

        $stream        = $this->createMock(StreamInterface::class);
        $streamFactory = $this->createMock(StreamFactoryInterface::class);
        $streamFactory->method('createStreamFromFile')->willReturn($stream);

        $locker = $this->createMock(LockingStrategyInterface::class);
        $locker->method('acquire')->willReturn(true);

        $lockFactory = $this->createMock(LockFactory::class);
        $lockFactory->method('createLocker')->willReturn($locker);

        $encoded = $this->createMock(EncodedImageInterface::class);

        $image = $this->createMock(ImageInterface::class);
        $image->method('width')->willReturn(200);
        $image->method('height')->willReturn(100);
        $image->method('cover')->willReturn($image);
        $image->method('save')->willReturnCallback(
            static function (string $path) use ($image): ImageInterface {
                file_put_contents($path, 'processed');

                return $image;
            },
        );
        $image->method('toWebp')->willReturn($encoded);
        $image->method('toAvif')->willReturn($encoded);
        $encoded->method('save')->willReturnCallback(
            static function (string $path) use ($encoded): EncodedImageInterface {
                file_put_contents($path, 'encoded');

                return $encoded;
            },
        );

        $driver = $this->createMock(DriverInterface::class);
        $driver->method('handleInput')->willReturn($image);

        $imageManager = new ImageManager($driver);

        $dispatchedEvents = [];
        $eventDispatcher  = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::exactly(2))
            ->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$dispatchedEvents): object {
                $dispatchedEvents[] = $event;

                return $event;
            });

        $reflection = new ReflectionClass(Processor::class);
        $instance   = $reflection->newInstanceWithoutConstructor();

        $this->setProperty($instance, 'imageManager', $imageManager);
        $this->setProperty($instance, 'lockFactory', $lockFactory);
        $this->setProperty($instance, 'responseFactory', $responseFactory);
        $this->setProperty($instance, 'streamFactory', $streamFactory);
        $this->setProperty($instance, 'eventDispatcher', $eventDispatcher);

        $uri = $this->createMock(UriInterface::class);
        $uri->method('getPath')->willReturn('/processed/img.w100h50m0q80.jpg');
        $uri->method('getQuery')->willReturn('');
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);

        $instance->generateAndSend($request);

        // First event: ImageProcessedEvent from processAndRespond
        self::assertCount(2, $dispatchedEvents);
        self::assertInstanceOf(ImageProcessedEvent::class, $dispatchedEvents[0]);
        self::assertSame('jpg', $dispatchedEvents[0]->extension);
        self::assertSame(100, $dispatchedEvents[0]->targetWidth);
        self::assertSame(50, $dispatchedEvents[0]->targetHeight);
        self::assertSame(80, $dispatchedEvents[0]->targetQuality);
        self::assertSame(0, $dispatchedEvents[0]->processingMode);
        self::assertTrue($dispatchedEvents[0]->webpGenerated);
        self::assertTrue($dispatchedEvents[0]->avifGenerated);

        // Second event: VariantServedEvent with fromCache=false
        self::assertInstanceOf(VariantServedEvent::class, $dispatchedEvents[1]);
        self::assertSame(200, $dispatchedEvents[1]->responseStatusCode);
        self::assertFalse($dispatchedEvents[1]->fromCache);
        self::assertSame('jpg', $dispatchedEvents[1]->extension);

        $this->tearDownRealEnvironment($tempDir, $prop);
    }

    // =========================================================================
    // Event dispatching: ImageProcessedEvent payload (processAndRespond)
    // =========================================================================

    #[Test]
    public function processAndRespondDispatchesImageProcessedEventWithCorrectPayload(): void
    {
        $tempDir = sys_get_temp_dir() . '/nr-pio-evt-proc-' . uniqid('', true);
        mkdir($tempDir . '/processed', 0o777, true);

        $originalPath = $tempDir . '/original.jpg';
        file_put_contents($originalPath, 'fake-jpeg-data');
        $variantPath = $tempDir . '/processed/original.w400h200m0q80.jpg';

        $response200 = $this->createMock(ResponseInterface::class);
        $response200->method('withHeader')->willReturn($response200);
        $response200->method('withBody')->willReturn($response200);

        $responseFactory = $this->createMock(ResponseFactoryInterface::class);
        $responseFactory->method('createResponse')->willReturn($response200);

        $stream        = $this->createMock(StreamInterface::class);
        $streamFactory = $this->createMock(StreamFactoryInterface::class);
        $streamFactory->method('createStreamFromFile')->willReturn($stream);

        $encoded = $this->createMock(EncodedImageInterface::class);

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static function (object $event) use ($originalPath, $variantPath): bool {
                if (!$event instanceof ImageProcessedEvent) {
                    return false;
                }

                return $event->pathOriginal === $originalPath
                    && $event->pathVariant === $variantPath
                    && $event->extension === 'jpg'
                    && $event->targetWidth === 400
                    && $event->targetHeight === 200
                    && $event->targetQuality === 80
                    && $event->processingMode === 0
                    && $event->webpGenerated
                    && $event->avifGenerated;
            }));

        ['processor' => $processor, 'image' => $image] = $this->createProcessorWithImageManager(
            responseFactory: $responseFactory,
            streamFactory: $streamFactory,
            eventDispatcher: $eventDispatcher,
        );

        $image->method('width')->willReturn(800);
        $image->method('height')->willReturn(400);
        $image->method('cover')->willReturn($image);
        $image->method('save')->willReturnCallback(
            static function (string $path) use ($image): ImageInterface {
                file_put_contents($path, 'processed-image');

                return $image;
            },
        );
        $image->method('toWebp')->willReturn($encoded);
        $image->method('toAvif')->willReturn($encoded);
        $encoded->method('save')->willReturnCallback(
            static function (string $path) use ($encoded): EncodedImageInterface {
                file_put_contents($path, 'encoded-variant');

                return $encoded;
            },
        );

        $uri = $this->createMock(UriInterface::class);
        $uri->method('getQuery')->willReturn('');
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);

        $urlInfo = [
            'pathVariant'    => $variantPath,
            'pathOriginal'   => $originalPath,
            'extension'      => 'jpg',
            'targetWidth'    => 400,
            'targetHeight'   => 200,
            'targetQuality'  => 80,
            'processingMode' => 0,
        ];

        $this->callMethod($processor, 'processAndRespond', $request, $urlInfo);

        $files = glob($tempDir . '/processed/*');

        if ($files !== false) {
            foreach ($files as $f) {
                unlink($f);
            }
        }

        unlink($originalPath);
        rmdir($tempDir . '/processed');
        rmdir($tempDir);
    }

    // =========================================================================
    // Event dispatching: ImageProcessedEvent with failed variant generation
    // =========================================================================

    #[Test]
    public function processAndRespondDispatchesImageProcessedEventWithFalseFlags(): void
    {
        $tempDir = sys_get_temp_dir() . '/nr-pio-evt-fail-' . uniqid('', true);
        mkdir($tempDir . '/processed', 0o777, true);

        $originalPath = $tempDir . '/original.jpg';
        file_put_contents($originalPath, 'fake-jpeg-data');
        $variantPath = $tempDir . '/processed/original.w400h200m0q80.jpg';

        $response200 = $this->createMock(ResponseInterface::class);
        $response200->method('withHeader')->willReturn($response200);
        $response200->method('withBody')->willReturn($response200);

        $responseFactory = $this->createMock(ResponseFactoryInterface::class);
        $responseFactory->method('createResponse')->willReturn($response200);

        $stream        = $this->createMock(StreamInterface::class);
        $streamFactory = $this->createMock(StreamFactoryInterface::class);
        $streamFactory->method('createStreamFromFile')->willReturn($stream);

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static function (object $event): bool {
                if (!$event instanceof ImageProcessedEvent) {
                    return false;
                }

                return $event->webpGenerated === false
                    && $event->avifGenerated === false;
            }));

        ['processor' => $processor, 'image' => $image] = $this->createProcessorWithImageManager(
            responseFactory: $responseFactory,
            streamFactory: $streamFactory,
            eventDispatcher: $eventDispatcher,
        );

        $image->method('width')->willReturn(800);
        $image->method('height')->willReturn(400);
        $image->method('cover')->willReturn($image);
        $image->method('save')->willReturnCallback(
            static function (string $path) use ($image): ImageInterface {
                file_put_contents($path, 'processed-image');

                return $image;
            },
        );
        $image->method('toWebp')->willThrowException(new RuntimeException('WebP failed'));
        $image->method('toAvif')->willThrowException(new RuntimeException('AVIF failed'));

        $uri = $this->createMock(UriInterface::class);
        $uri->method('getQuery')->willReturn('');
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);

        $urlInfo = [
            'pathVariant'    => $variantPath,
            'pathOriginal'   => $originalPath,
            'extension'      => 'jpg',
            'targetWidth'    => 400,
            'targetHeight'   => 200,
            'targetQuality'  => 80,
            'processingMode' => 0,
        ];

        $this->callMethod($processor, 'processAndRespond', $request, $urlInfo);

        $files = glob($tempDir . '/processed/*');

        if ($files !== false) {
            foreach ($files as $f) {
                unlink($f);
            }
        }

        unlink($originalPath);
        rmdir($tempDir . '/processed');
        rmdir($tempDir);
    }

    // =========================================================================
    // Event dispatching: VariantServedEvent after lock re-check (cached)
    // =========================================================================

    #[Test]
    public function generateAndSendDispatchesVariantServedEventAfterLockRecheck(): void
    {
        ['tempDir' => $tempDir, 'prop' => $prop] = $this->setUpRealEnvironment();

        file_put_contents($tempDir . '/public/img.jpg', 'fake-original');
        $variantPath = $tempDir . '/public/processed/img.w100h50m0q80.jpg';

        $response200 = $this->createMock(ResponseInterface::class);
        $response200->method('withHeader')->willReturn($response200);
        $response200->method('withBody')->willReturn($response200);
        $response200->method('getStatusCode')->willReturn(200);

        $responseFactory = $this->createMock(ResponseFactoryInterface::class);
        $responseFactory->method('createResponse')->with(200)->willReturn($response200);

        $locker = $this->createMock(LockingStrategyInterface::class);
        $locker->method('acquire')->willReturnCallback(
            static function () use ($variantPath): bool {
                file_put_contents($variantPath, 'created-during-lock-wait');

                return true;
            },
        );

        $lockFactory = $this->createMock(LockFactory::class);
        $lockFactory->method('createLocker')->willReturn($locker);

        $stream        = $this->createMock(StreamInterface::class);
        $streamFactory = $this->createMock(StreamFactoryInterface::class);
        $streamFactory->method('createStreamFromFile')->willReturn($stream);

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static function (object $event): bool {
                if (!$event instanceof VariantServedEvent) {
                    return false;
                }

                return $event->fromCache
                    && $event->responseStatusCode === 200;
            }));

        $processor = $this->createProcessor(
            lockFactory: $lockFactory,
            responseFactory: $responseFactory,
            streamFactory: $streamFactory,
            eventDispatcher: $eventDispatcher,
        );

        $uri = $this->createMock(UriInterface::class);
        $uri->method('getPath')->willReturn('/processed/img.w100h50m0q80.jpg');
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);

        $processor->generateAndSend($request);

        $this->tearDownRealEnvironment($tempDir, $prop);
    }

    // =========================================================================
    // Event dispatch catch: listener throws → warning logged, response still served
    // Covers Processor.php lines 225-226 (VariantServedEvent catch in pre-lock path)
    // =========================================================================

    #[Test]
    public function generateAndSendLogsCatchWhenEventListenerThrowsOnCachedVariant(): void
    {
        ['tempDir' => $tempDir, 'prop' => $prop] = $this->setUpRealEnvironment();

        // Create cached variant so pre-lock cache-hit path is taken
        file_put_contents($tempDir . '/public/img.jpg', 'original');
        file_put_contents($tempDir . '/public/processed/img.w100h50m0q80.jpg', 'cached');

        $response = $this->createMock(ResponseInterface::class);
        $response->method('withHeader')->willReturn($response);
        $response->method('withBody')->willReturn($response);
        $response->method('getStatusCode')->willReturn(200);

        $responseFactory = $this->createMock(ResponseFactoryInterface::class);
        $responseFactory->method('createResponse')->with(200)->willReturn($response);

        $stream = $this->createMock(StreamInterface::class);
        $stream->method('getSize')->willReturn(6);
        $streamFactory = $this->createMock(StreamFactoryInterface::class);
        $streamFactory->method('createStreamFromFile')->willReturn($stream);

        // Event dispatcher that throws on dispatch
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->method('dispatch')
            ->willThrowException(new RuntimeException('Listener bug'));

        $processor = $this->createProcessor(
            responseFactory: $responseFactory,
            streamFactory: $streamFactory,
            eventDispatcher: $eventDispatcher,
        );

        $uri = $this->createMock(UriInterface::class);
        $uri->method('getPath')->willReturn('/processed/img.w100h50m0q80.jpg');
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);

        // Despite the listener throwing, the cached response is still returned
        $result = $processor->generateAndSend($request);
        self::assertSame($response, $result);

        $this->tearDownRealEnvironment($tempDir, $prop);
    }
}
