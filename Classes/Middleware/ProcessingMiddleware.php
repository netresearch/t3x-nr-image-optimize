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
 * @author  Axel Seemann <axel.seemann@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 * @link    https://www.netresearch.de
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
            $this->processor->setRequest($request);
            $this->processor->generateAndSend();
            exit;
        }

        return $handler->handle($request);
    }
}
