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
 * image processing and terminates the request after sending the response. Otherwise,
 * the request is passed to the next handler in the stack.
 *
 * @author  Axel Seemann <axel.seemann@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
 */
readonly class ProcessingMiddleware implements MiddlewareInterface
{
    /**
     * Image processor service used to generate and send variants.
     *
     * @var Processor
     */
    private Processor $processor;

    /**
     * Constructor.
     *
     * @param Processor $processor Processor responsible for generating/sending processed images
     */
    public function __construct(Processor $processor)
    {
        $this->processor = $processor;
    }

    /**
     * Process an incoming server request.
     *
     * If the URI path begins with "/processed/", the request is handled by the
     * image processor, which generates the requested variant and streams it to
     * the client. In that case, the script terminates after sending the output.
     * Otherwise, the request is delegated to the next handler.
     *
     * @param ServerRequestInterface  $request Incoming request
     * @param RequestHandlerInterface $handler Next handler in the middleware stack
     *
     * @return ResponseInterface The response from the next handler (if not processed here)
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Only intercept requests that target the on-the-fly processed image endpoint
        if (str_starts_with($request->getUri()->getPath(), '/processed/')) {
            $this->processor->setRequest($request);
            $this->processor->generateAndSend();
            exit;
        }

        return $handler->handle($request);
    }
}
