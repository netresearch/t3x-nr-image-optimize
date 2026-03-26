<?php

/*
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\NrImageOptimize\Event;

/**
 * PSR-14 event dispatched when a cached or freshly-processed image variant is
 * about to be served to the client. Allows integrators to inspect the response-
 * related metadata for purposes such as logging, analytics, or metrics collection.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 */
final readonly class VariantServedEvent
{
    /**
     * @param string $pathVariant        Absolute base path of the variant (the actual served format
     *                                   may be a .webp or .avif sidecar of this path)
     * @param string $extension          Lowercased file extension of the original variant
     * @param int    $responseStatusCode HTTP status code of the response (always 200 in practice)
     * @param bool   $fromCache          Whether the variant was served from an existing cached file
     */
    public function __construct(
        public string $pathVariant,
        public string $extension,
        public int $responseStatusCode,
        public bool $fromCache,
    ) {}
}
