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
 * PSR-14 event dispatched after an image variant has been processed and saved
 * to disk. Allows integrators to hook into the post-processing pipeline, e.g.
 * to apply additional optimizations, collect metrics, or trigger cache warming.
 *
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 */
final readonly class ImageProcessedEvent
{
    /**
     * @param string   $pathOriginal   Absolute path to the original source image
     * @param string   $pathVariant    Absolute path to the processed variant file
     * @param string   $extension      Lowercased file extension of the variant (e.g. 'jpg', 'png', 'webp')
     * @param int|null $targetWidth    Final resolved and clamped width in pixels (null if unspecified in URL)
     * @param int|null $targetHeight   Final resolved and clamped height in pixels (null if unspecified in URL)
     * @param int      $targetQuality  Output quality (1-100)
     * @param int      $processingMode Processing mode (0 = cover, 1 = scale)
     * @param bool     $webpGenerated  Whether a WebP variant was generated alongside
     * @param bool     $avifGenerated  Whether an AVIF variant was generated alongside
     */
    public function __construct(
        public string $pathOriginal,
        public string $pathVariant,
        public string $extension,
        public ?int $targetWidth,
        public ?int $targetHeight,
        public int $targetQuality,
        public int $processingMode,
        public bool $webpGenerated,
        public bool $avifGenerated,
    ) {}
}
