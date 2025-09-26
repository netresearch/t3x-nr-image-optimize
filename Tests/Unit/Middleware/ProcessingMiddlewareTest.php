<?php

/**
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
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

#[CoversClass(ProcessingMiddleware::class)]
class ProcessingMiddlewareTest extends TestCase
{
    #[Test]
    public function processDelegatesToNextHandlerForNonProcessedPaths(): void
    {
        $processor = $this->createMock(Processor::class);
        $processor->expects(self::never())->method('setRequest');
        $processor->expects(self::never())->method('generateAndSend');
        $middleware = new ProcessingMiddleware($processor);

        $uri = $this->createMock(UriInterface::class);
        $uri->method('getPath')->willReturn('/assets/image.jpg');

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);

        $response = $this->createMock(ResponseInterface::class);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->with($request)->willReturn($response);

        self::assertSame($response, $middleware->process($request, $handler));
    }

    #[Test]
    public function processTriggersProcessorForProcessedRequests(): void
    {
        $processor = $this->createMock(Processor::class);

        $uri = $this->createMock(UriInterface::class);
        $uri->method('getPath')->willReturn('/processed/path/to/image.jpg');

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $processor->expects(self::once())->method('setRequest')->with($request);
        $processor->expects(self::once())->method('generateAndSend')->willThrowException(new RuntimeException('stop')); // prevent exit

        $middleware = new ProcessingMiddleware($processor);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('stop');

        $middleware->process($request, $handler);
    }
}
