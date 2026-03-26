<?php

/*
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\NrImageOptimize;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Contract for on-the-fly image processing of generated variants.
 *
 * Implementations receive the incoming request, generate the requested image
 * variant (or serve it from cache), and return a PSR-7 response.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 */
interface ProcessorInterface
{
    /**
     * Handle a processed image request and return the image response.
     *
     * @param ServerRequestInterface $request Incoming request containing the processed URL and query params
     *
     * @return ResponseInterface The image response or an error response
     */
    public function generateAndSend(ServerRequestInterface $request): ResponseInterface;
}
