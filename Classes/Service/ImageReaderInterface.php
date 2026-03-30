<?php

/*
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\NrImageOptimize\Service;

use Intervention\Image\Interfaces\ImageInterface;

/**
 * Abstraction over Intervention Image's version-specific image loading API.
 *
 * Intervention Image v3 uses ImageManager::read() while v4 uses
 * ImageManager::decode(). This interface hides that difference so
 * consumers can depend on a single, statically verifiable contract
 * without version-conditional code or PHPStan ignore tags.
 *
 * @author  Sebastian Mendel <sebastian.mendel@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 */
interface ImageReaderInterface
{
    /**
     * Load an image from the given filesystem path.
     *
     * @param string $path Absolute filesystem path to the image file
     *
     * @return ImageInterface The decoded image
     */
    public function read(string $path): ImageInterface;
}
