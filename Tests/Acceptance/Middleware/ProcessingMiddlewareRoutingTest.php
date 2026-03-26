<?php

/*
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\NrImageOptimize\Tests\Acceptance\Middleware;

use function base64_decode;
use function file_put_contents;

use function is_dir;
use function mkdir;

use Netresearch\NrImageOptimize\Middleware\ProcessingMiddleware;
use Netresearch\NrImageOptimize\Processor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

use function rmdir;
use function sys_get_temp_dir;

use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Http\ResponseFactory;
use TYPO3\CMS\Core\Http\StreamFactory;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;

use function unlink;

/**
 * Acceptance tests for the ProcessingMiddleware routing and the Processor
 * HTTP response behavior.
 *
 * These tests verify:
 * - Middleware routing decisions based on URL path
 * - HTTP response status codes (200, 400, 404)
 * - Response headers (Content-Type, Cache-Control, ETag)
 * - Malformed URL rejection
 */
#[CoversClass(ProcessingMiddleware::class)]
#[CoversClass(Processor::class)]
class ProcessingMiddlewareRoutingTest extends TestCase
{
    private string $publicPath;

    private string $tempDir;

    private ResponseFactoryInterface $responseFactory;

    private StreamFactoryInterface $streamFactory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir    = sys_get_temp_dir() . '/nr-image-optimize-acceptance-mw';
        $this->publicPath = $this->tempDir . '/public';

        if (!is_dir($this->publicPath)) {
            mkdir($this->publicPath, 0o777, true);
        }

        Environment::initialize(
            new ApplicationContext('Testing'),
            true,
            true,
            $this->tempDir,
            $this->publicPath,
            $this->tempDir . '/var',
            $this->tempDir . '/config',
            $this->publicPath . '/index.php',
            'UNIX',
        );

        $this->responseFactory = new ResponseFactory();
        $this->streamFactory   = new StreamFactory();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);

        parent::tearDown();
    }

    // ──────────────────────────────────────────────────
    // Middleware routing tests
    // ──────────────────────────────────────────────────

    #[Test]
    public function middlewareRoutesProcessedUrlToProcessor(): void
    {
        $processor = $this->createMock(Processor::class);

        $expectedResponse = $this->responseFactory->createResponse(200)
            ->withBody($this->streamFactory->createStream('image-data'));

        $processor
            ->expects(self::once())
            ->method('generateAndSend')
            ->willReturn($expectedResponse);

        $middleware = new ProcessingMiddleware($processor);

        $request = $this->createRequestWithPath('/processed/fileadmin/image.w800h600m0q100.jpg');
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $response = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function middlewareDelegatesNonProcessedUrlToNextHandler(): void
    {
        $processor = $this->createMock(Processor::class);
        $processor->expects(self::never())->method('generateAndSend');

        $middleware = new ProcessingMiddleware($processor);

        $request          = $this->createRequestWithPath('/fileadmin/image.jpg');
        $expectedResponse = $this->responseFactory->createResponse(200);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())
            ->method('handle')
            ->with($request)
            ->willReturn($expectedResponse);

        $response = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function middlewareDoesNotInterceptRootPath(): void
    {
        $processor = $this->createMock(Processor::class);
        $processor->expects(self::never())->method('generateAndSend');

        $middleware = new ProcessingMiddleware($processor);

        $request          = $this->createRequestWithPath('/');
        $expectedResponse = $this->responseFactory->createResponse(200);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())
            ->method('handle')
            ->willReturn($expectedResponse);

        $middleware->process($request, $handler);
    }

    #[Test]
    public function middlewareDoesNotInterceptPartialMatch(): void
    {
        $processor = $this->createMock(Processor::class);
        $processor->expects(self::never())->method('generateAndSend');

        $middleware = new ProcessingMiddleware($processor);

        // Path starts with /processedXYZ but not /processed/
        $request          = $this->createRequestWithPath('/processedXYZ/image.jpg');
        $expectedResponse = $this->responseFactory->createResponse(200);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())
            ->method('handle')
            ->willReturn($expectedResponse);

        $middleware->process($request, $handler);
    }

    // ──────────────────────────────────────────────────
    // Processor HTTP response tests (400 for malformed URLs)
    // ──────────────────────────────────────────────────

    #[Test]
    public function processorReturns400ForMalformedUrl(): void
    {
        $processor = $this->createProcessor();

        $request  = $this->createRequestWithPath('/processed/no-mode-string.jpg');
        $response = $processor->generateAndSend($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function processorReturns400ForUrlWithPathTraversalInMiddle(): void
    {
        $processor = $this->createProcessor();

        $request  = $this->createRequestWithPath('/processed/../../etc/passwd.w100h100m0q80.jpg');
        $response = $processor->generateAndSend($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function processorReturns400ForEmptyProcessedPath(): void
    {
        $processor = $this->createProcessor();

        $request  = $this->createRequestWithPath('/processed/');
        $response = $processor->generateAndSend($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function processorReturns400ForUrlWithoutExtension(): void
    {
        $processor = $this->createProcessor();

        $request  = $this->createRequestWithPath('/processed/fileadmin/image.w100h100m0q80');
        $response = $processor->generateAndSend($request);

        self::assertSame(400, $response->getStatusCode());
    }

    // ──────────────────────────────────────────────────
    // Processor HTTP response tests (404 for missing originals)
    // ──────────────────────────────────────────────────

    #[Test]
    public function processorReturns404ForMissingOriginalFile(): void
    {
        $processor = $this->createProcessor();

        $request  = $this->createRequestWithPath('/processed/fileadmin/nonexistent.w400h300m0q80.jpg');
        $response = $processor->generateAndSend($request);

        // 404 because the original image does not exist
        self::assertSame(404, $response->getStatusCode());
    }

    // ──────────────────────────────────────────────────
    // Processor HTTP response headers for cached variants
    // ──────────────────────────────────────────────────

    #[Test]
    public function processorServesExistingVariantWithCacheHeaders(): void
    {
        // Pre-create the variant file on disk to simulate a cached variant
        $variantDir  = $this->publicPath . '/processed/fileadmin';
        $variantFile = $variantDir . '/cached.w100h100m0q80.jpg';

        if (!is_dir($variantDir)) {
            mkdir($variantDir, 0o777, true);
        }

        // Write a minimal JPEG-like content
        file_put_contents($variantFile, $this->createMinimalJpeg());

        $processor = $this->createProcessor();
        $request   = $this->createRequestWithPath('/processed/fileadmin/cached.w100h100m0q80.jpg');
        $response  = $processor->generateAndSend($request);

        self::assertSame(200, $response->getStatusCode());

        // Content-Type header
        self::assertSame('image/jpeg', $response->getHeaderLine('Content-Type'));

        // Cache-Control header (1 year immutable)
        $cacheControl = $response->getHeaderLine('Cache-Control');
        self::assertStringContainsString('public', $cacheControl);
        self::assertStringContainsString('max-age=31536000', $cacheControl);
        self::assertStringContainsString('immutable', $cacheControl);

        // ETag header present
        self::assertNotEmpty($response->getHeaderLine('ETag'));

        // Last-Modified header present
        self::assertNotEmpty($response->getHeaderLine('Last-Modified'));

        // Content-Length header present
        self::assertNotEmpty($response->getHeaderLine('Content-Length'));

        unlink($variantFile);
    }

    #[Test]
    public function processorPrefersAvifVariantWhenAvailable(): void
    {
        $variantDir  = $this->publicPath . '/processed/fileadmin';
        $variantFile = $variantDir . '/multi.w100h100m0q80.jpg';

        if (!is_dir($variantDir)) {
            mkdir($variantDir, 0o777, true);
        }

        // Create both the base variant and an AVIF variant
        file_put_contents($variantFile, $this->createMinimalJpeg());
        file_put_contents($variantFile . '.avif', 'fake-avif-data');

        $processor = $this->createProcessor();
        $request   = $this->createRequestWithPath('/processed/fileadmin/multi.w100h100m0q80.jpg');
        $response  = $processor->generateAndSend($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/avif', $response->getHeaderLine('Content-Type'));

        unlink($variantFile . '.avif');
        unlink($variantFile);
    }

    #[Test]
    public function processorPrefersWebpVariantWhenNoAvifAvailable(): void
    {
        $variantDir  = $this->publicPath . '/processed/fileadmin';
        $variantFile = $variantDir . '/webptest.w100h100m0q80.jpg';

        if (!is_dir($variantDir)) {
            mkdir($variantDir, 0o777, true);
        }

        // Create both the base variant and a WebP variant (no AVIF)
        file_put_contents($variantFile, $this->createMinimalJpeg());
        file_put_contents($variantFile . '.webp', 'fake-webp-data');

        $processor = $this->createProcessor();
        $request   = $this->createRequestWithPath('/processed/fileadmin/webptest.w100h100m0q80.jpg');
        $response  = $processor->generateAndSend($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/webp', $response->getHeaderLine('Content-Type'));

        unlink($variantFile . '.webp');
        unlink($variantFile);
    }

    // ──────────────────────────────────────────────────
    // Helper methods
    // ──────────────────────────────────────────────────

    private function createRequestWithPath(string $path): ServerRequestInterface&MockObject
    {
        $uri = $this->createMock(UriInterface::class);
        $uri->method('getPath')->willReturn($path);
        $uri->method('getQuery')->willReturn('');

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);

        return $request;
    }

    /**
     * Create a Processor instance using reflection to bypass the final ImageManager.
     *
     * ImageManager is declared final and cannot be mocked. We use
     * newInstanceWithoutConstructor and inject dependencies via reflection,
     * matching the approach in the existing ProcessorTest.
     */
    private function createProcessor(): Processor
    {
        $lockFactory = $this->createMock(LockFactory::class);
        $locker      = $this->createMock(LockingStrategyInterface::class);
        $locker->method('acquire')->willReturn(true);
        $locker->method('release')->willReturn(true);
        $lockFactory->method('createLocker')->willReturn($locker);

        $reflection = new ReflectionClass(Processor::class);
        $instance   = $reflection->newInstanceWithoutConstructor();

        $this->setProperty($instance, 'lockFactory', $lockFactory);
        $this->setProperty($instance, 'responseFactory', $this->responseFactory);
        $this->setProperty($instance, 'streamFactory', $this->streamFactory);

        return $instance;
    }

    private function setProperty(object $object, string $property, mixed $value): void
    {
        $reflection = new ReflectionClass($object);
        $prop       = $reflection->getProperty($property);
        $prop->setValue($object, $value);
    }

    /**
     * Create a minimal valid JPEG byte sequence for testing.
     */
    private function createMinimalJpeg(): string
    {
        // Minimal JPEG: SOI marker + JFIF APP0 + minimal content + EOI marker
        $jpeg = base64_decode(
            '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////'
            . '////////////////////////////////////////////////////////////'
            . '2wBDAf//////////////////////////////////////////////////////'
            . '////////////////////////////////////////////wAARCAABAAEDASIA'
            . 'AhEBAxEB/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAA'
            . 'AAAAAAD/xAAUAQEAAAAAAAAAAAAAAAAAAAAA/8QAFBEBAAAAAAAAAAAAAAAA'
            . 'AAAAAP/aAAwDAQACEQMRAD8AKgA=',
            true,
        );

        return $jpeg !== false ? $jpeg : "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\xFF\xD9";
    }

    /**
     * Recursively remove a directory and its contents.
     */
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getRealPath());
            } else {
                @unlink($item->getRealPath());
            }
        }

        @rmdir($dir);
    }
}
