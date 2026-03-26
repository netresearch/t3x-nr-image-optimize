<?php

/**
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
 */
class ProcessingMiddleware implements MiddlewareInterface
{
    private readonly Processor $processor;

    public function __construct(Processor $processor)
    {
        $this->processor = $processor;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (str_starts_with($request->getUri()->getPath(), '/processed/')) {
            return $this->processor->generateAndSend($request);
        }

        return $handler->handle($request);
    }
}
