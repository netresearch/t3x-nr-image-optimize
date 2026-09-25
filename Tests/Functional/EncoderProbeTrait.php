<?php

/*
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\NrImageOptimize\Tests\Functional;

use function class_exists;

use Imagick;
use Throwable;

/**
 * Skip helpers for functional tests that depend on a working sidecar encoder.
 *
 * Listing a format in Imagick::queryFormats() is not enough: some ImageMagick
 * builds list AVIF but return no data when encoding, which leaves an empty
 * .avif file behind. The probe therefore encodes a tiny image.
 */
trait EncoderProbeTrait
{
    private function skipUnlessAvifEncoderWorks(): void
    {
        $this->skipUnlessImagickEncodes('AVIF');
    }

    private function skipUnlessWebpEncoderWorks(): void
    {
        $this->skipUnlessImagickEncodes('WEBP');
    }

    /**
     * @param non-empty-string $format ImageMagick format name, e.g. "AVIF"
     */
    private function skipUnlessImagickEncodes(string $format): void
    {
        if (!class_exists(Imagick::class) || Imagick::queryFormats($format) === []) {
            self::markTestSkipped('ImageMagick without ' . $format . ' encoder');
        }

        try {
            $probe = new Imagick();
            $probe->newImage(8, 8, 'white');
            $probe->setImageFormat($format);
            $probe->setCompressionQuality(60);
            $encoded = $probe->getImagesBlob();
        } catch (Throwable) {
            $encoded = '';
        }

        if ($encoded === '') {
            self::markTestSkipped('ImageMagick lists ' . $format . ' but its encoder returns no data');
        }
    }
}
