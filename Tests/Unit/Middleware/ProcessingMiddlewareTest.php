<?php

/*
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\NrImageOptimize\Tests\Unit\Middleware;

use Netresearch\NrImageOptimize\Middleware\ProcessingMiddleware;
use Netresearch\NrImageOptimize\Processor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[CoversClass(ProcessingMiddleware::class)]
class ProcessingMiddlewareTest extends TestCase
{
    #[Test]
    public function processDelegatesToNextHandlerForNonProcessedPaths(): void
    {
        $processor = $this->createMock(Processor::class);
        $processor->expects(self::never())->method('generateAndSend');
        $middleware = new ProcessingMiddleware($processor);

        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/assets/image.jpg');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);

        $response = $this->createStub(ResponseInterface::class);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->with($request)->willReturn($response);

        self::assertSame($response, $middleware->process($request, $handler));
    }

    #[Test]
    public function processTriggersProcessorForProcessedRequests(): void
    {
        $processor = $this->createMock(Processor::class);

        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/processed/path/to/image.jpg');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);

        $response = $this->createStub(ResponseInterface::class);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $processor->expects(self::once())
            ->method('generateAndSend')
            ->with($request)
            ->willReturn($response);

        $middleware = new ProcessingMiddleware($processor);

        self::assertSame($response, $middleware->process($request, $handler));
    }

    #[Test]
    #[DataProvider('nonProcessedPathsProvider')]
    public function processDelegatesToNextHandlerForVariousNonProcessedPaths(string $path): void
    {
        $processor = $this->createMock(Processor::class);
        $processor->expects(self::never())->method('generateAndSend');
        $middleware = new ProcessingMiddleware($processor);

        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn($path);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);

        $response = $this->createStub(ResponseInterface::class);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->with($request)->willReturn($response);

        self::assertSame($response, $middleware->process($request, $handler));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function nonProcessedPathsProvider(): iterable
    {
        yield 'root path' => ['/'];
        yield 'empty path' => [''];
        yield 'assets path' => ['/assets/image.jpg'];
        yield 'fileadmin path' => ['/fileadmin/user_upload/photo.jpg'];
        yield 'processed without slash' => ['/processed'];
        yield 'processed-like prefix' => ['/processedimages/image.jpg'];
        yield 'typo3 backend' => ['/typo3/'];
    }

    #[Test]
    #[DataProvider('processedPathsProvider')]
    public function processTriggersProcessorForVariousProcessedPaths(string $path): void
    {
        $processor = $this->createMock(Processor::class);

        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn($path);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);

        $response = $this->createStub(ResponseInterface::class);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $processor->expects(self::once())
            ->method('generateAndSend')
            ->with($request)
            ->willReturn($response);

        $middleware = new ProcessingMiddleware($processor);

        self::assertSame($response, $middleware->process($request, $handler));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function processedPathsProvider(): iterable
    {
        yield 'simple processed image' => ['/processed/image.w800h400.jpg'];
        yield 'nested processed path' => ['/processed/path/to/deep/image.w100h100.png'];
        yield 'processed with query-like name' => ['/processed/image.w200h100q80m1.webp'];
        yield 'processed root slash' => ['/processed/image.jpg'];
    }
}
