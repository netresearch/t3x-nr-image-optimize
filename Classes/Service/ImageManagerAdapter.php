<?php

/*
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: Netresearch DTT GmbH
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\NrImageOptimize\Service;

use Closure;

use function getimagesize;
use function in_array;

use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;

use function restore_error_handler;
use function set_error_handler;

use SplFileInfo;

use function sprintf;

/**
 * Adapter that bridges Intervention Image v3 and v4 API differences.
 *
 * v3 exposes ImageManager::read() for loading images while v4 replaced
 * it with ImageManager::decode(). This adapter detects the available
 * method at construction time and delegates accordingly, allowing the
 * rest of the codebase to depend on {@see ImageReaderInterface} without
 * any version-conditional logic.
 *
 * @author  Sebastian Mendel <sebastian.mendel@netresearch.de>
 * @license GPL-3.0-or-later https://www.gnu.org/licenses/gpl-3.0.html
 */
final readonly class ImageManagerAdapter implements ImageReaderInterface
{
    /**
     * Image types (as reported by getimagesize()) handed to the decoder.
     *
     * What Intervention would otherwise decode depends on the driver (GD or
     * Imagick) and, for Imagick, on the host's ImageMagick policy -- which
     * may include formats such as PostScript, PDF, SVG or MVG. The content
     * is checked, not the file name.
     *
     * @var list<int>
     */
    private const SUPPORTED_IMAGE_TYPES = [
        IMAGETYPE_JPEG,
        IMAGETYPE_PNG,
        IMAGETYPE_GIF,
        IMAGETYPE_WEBP,
        IMAGETYPE_AVIF,
        IMAGETYPE_BMP,
        IMAGETYPE_TIFF_II,
        IMAGETYPE_TIFF_MM,
    ];

    /**
     * @var Closure(SplFileInfo): ImageInterface
     */
    private Closure $readCallback;

    public function __construct(ImageManager $imageManager)
    {
        $this->readCallback = $this->resolveReadMethod($imageManager);
    }

    /**
     * Detect whether the ImageManager exposes read() (v3) or decode() (v4)
     * and return a closure bound to the correct method.
     *
     * The object parameter type prevents PHPStan from statically narrowing
     * the method_exists() check against a single installed library version.
     *
     * @return Closure(SplFileInfo): ImageInterface
     */
    private function resolveReadMethod(object $manager): Closure
    {
        $method = method_exists($manager, 'read') ? 'read' : 'decode';

        /** @var Closure(SplFileInfo): ImageInterface */
        return $manager->{$method}(...);
    }

    public function read(string $path): ImageInterface
    {
        $this->assertSupportedImageType($path);

        // Wrapping the path in SplFileInfo forces Intervention's decoder
        // auto-detection (InputHandler::handle()) to match on the input's
        // *type* via SplFileInfoImageDecoder rather than inspecting its
        // *content*. Without this, CanDetectImageSources::couldBeBinaryData()
        // treats any path containing non-ASCII bytes (e.g. an umlaut in a
        // directory name) as binary image data -- BinaryImageDecoder is
        // tried before FilePathImageDecoder in the decoder chain -- and
        // routes the raw path string into Imagick::readImageBlob() /
        // imagecreatefromstring(), which fails to decode and throws
        // ImageDecoderException.
        return ($this->readCallback)(new SplFileInfo($path));
    }

    /**
     * Refuse files whose content is not one of SUPPORTED_IMAGE_TYPES.
     *
     * getimagesize() reads only the header bytes. For a file too short to
     * hold an image header it emits a notice; that case is answered by the
     * false return value, so the notice is not passed on to the error
     * handler (which may log it or turn it into an exception).
     *
     * @throws UnsupportedImageTypeException
     */
    private function assertSupportedImageType(string $path): void
    {
        set_error_handler(static fn (): bool => true);

        try {
            $info = getimagesize($path);
        } finally {
            restore_error_handler();
        }

        if ($info === false || !in_array($info[2], self::SUPPORTED_IMAGE_TYPES, true)) {
            throw new UnsupportedImageTypeException(sprintf('"%s" is not an image of a supported type', $path), 1791331201);
        }
    }
}
