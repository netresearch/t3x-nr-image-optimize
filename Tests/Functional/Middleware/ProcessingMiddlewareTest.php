<?php

/*
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\NrImageOptimize\Tests\Functional\Middleware;

use Netresearch\NrImageOptimize\Event\VariantServedEvent;
use Netresearch\NrImageOptimize\Middleware\ProcessingMiddleware;
use Netresearch\NrImageOptimize\Processor;
use Netresearch\NrImageOptimize\Service\ImageManagerAdapter;
use Netresearch\NrImageOptimize\Service\ImageManagerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Functional tests for ProcessingMiddleware PSR-15 pipeline behavior.
 *
 * Declares UsesClass for everything the middleware transitively exercises
 * when it delegates to Processor (image processing service chain + events).
 * beStrictAboutCoverageMetadata fails the run on anything unlisted here
 * so this list must stay in sync with what the Processor actually pulls
 * in via DI — growing pains of integration tests.
 */
#[CoversClass(ProcessingMiddleware::class)]
#[UsesClass(Processor::class)]
#[UsesClass(ImageManagerAdapter::class)]
#[UsesClass(ImageManagerFactory::class)]
#[UsesClass(VariantServedEvent::class)]
final class ProcessingMiddlewareTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'netresearch/nr-image-optimize',
    ];

    #[Test]
    public function middlewarePassesNonProcessedRequestToNextHandler(): void
    {
        $request = new ServerRequest(new Uri('https://example.com/some-page'));

        $expectedResponse = new Response();
        $expectedResponse = $expectedResponse->withStatus(200);

        $handler = new class ($expectedResponse) implements RequestHandlerInterface {
            public function __construct(
                private readonly ResponseInterface $response,
            ) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };

        $middleware = $this->get(ProcessingMiddleware::class);
        $response   = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($expectedResponse, $response);
    }

    #[Test]
    public function middlewareInterceptsProcessedPathRequests(): void
    {
        $request = new ServerRequest(new Uri('https://example.com/processed/fileadmin/test.w100h75m0q80.png'));

        $handler = new class implements RequestHandlerInterface {
            public bool $called = false;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->called = true;

                return new Response();
            }
        };

        $middleware = $this->get(ProcessingMiddleware::class);
        $response   = $middleware->process($request, $handler);

        self::assertFalse($handler->called, 'Next handler should not be called for /processed/ paths');
        self::assertContains(
            $response->getStatusCode(),
            [200, 400, 404, 500, 503],
            'Processor should return a valid HTTP status for processed paths',
        );
    }

    #[Test]
    public function middlewareReturns400ForMalformedProcessedUrl(): void
    {
        $request = new ServerRequest(new Uri('https://example.com/processed/'));

        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response();
            }
        };

        $middleware = $this->get(ProcessingMiddleware::class);
        $response   = $middleware->process($request, $handler);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function middlewareReturns404ForNonExistentSourceImage(): void
    {
        $request = new ServerRequest(
            new Uri('https://example.com/processed/fileadmin/nonexistent.w100h75m0q80.png'),
        );

        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response();
            }
        };

        $middleware = $this->get(ProcessingMiddleware::class);
        $response   = $middleware->process($request, $handler);

        self::assertContains(
            $response->getStatusCode(),
            [400, 404],
            'Should return 400 or 404 for non-existent source image',
        );
    }
}
