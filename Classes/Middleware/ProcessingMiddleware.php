<?php

/*
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\NrImageOptimize\Middleware;

use Netresearch\NrImageOptimize\Processor;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function str_starts_with;

/**
 * PSR-15 middleware that intercepts requests to processed image variants and
 * delegates the generation/serving of those images to the Processor.
 *
 * If the request path starts with "/processed/", the middleware triggers on-the-fly
 * image processing and returns the generated response. Otherwise, the request is
 * passed to the next handler in the stack.
 *
 * @author  Axel Seemann <axel.seemann@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 *
 * @see    https://www.netresearch.de
 */
readonly class ProcessingMiddleware implements MiddlewareInterface
{
    /**
     * Constructor.
     *
     * @param Processor $processor Processor responsible for generating/sending processed images
     */
    public function __construct(
        private Processor $processor,
    ) {}

    /**
     * Process an incoming server request.
     *
     * If the URI path begins with "/processed/", the request is handled by the
     * image processor, which generates the requested variant and returns it as a
     * PSR-7 response. Otherwise, the request is delegated to the next handler.
     *
     * @param ServerRequestInterface  $request Incoming request
     * @param RequestHandlerInterface $handler Next handler in the middleware stack
     *
     * @return ResponseInterface The image response or the response from the next handler
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Only intercept requests that target the on-the-fly processed image endpoint
        if (str_starts_with($request->getUri()->getPath(), '/processed/')) {
            return $this->processor->generateAndSend($request);
        }

        return $handler->handle($request);
    }
}
