<?php

/**
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

use TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider;

return [
    'module-image-optimize' => [
        'provider' => SvgIconProvider::class,
        'source'   => 'EXT:nr_image_optimize/Resources/Public/Icons/module-image-optimize.svg',
    ],
];
