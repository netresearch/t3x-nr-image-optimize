<?php

/**
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\NrImageOptimize\Tests\Functional;

use Imagick;
use Throwable;

use function class_exists;

/**
 * Skip helper for functional tests that depend on a working AVIF encoder.
 *
 * Listing "AVIF" in Imagick::queryFormats() is not enough: some ImageMagick
 * builds list the format but return no data when encoding, which leaves an
 * empty .avif file behind. The probe therefore encodes a tiny image.
 */
trait AvifEncoderProbeTrait
{
    private function skipUnlessAvifEncoderWorks(): void
    {
        if (!class_exists(Imagick::class) || Imagick::queryFormats('AVIF') === []) {
            self::markTestSkipped('ImageMagick without AVIF encoder');
        }

        try {
            $probe = new Imagick();
            $probe->newImage(8, 8, 'white');
            $probe->setImageFormat('AVIF');
            $probe->setCompressionQuality(60);
            $encoded = $probe->getImagesBlob();
        } catch (Throwable) {
            $encoded = '';
        }

        if ($encoded === '') {
            self::markTestSkipped('ImageMagick lists AVIF but its encoder returns no data');
        }
    }
}
