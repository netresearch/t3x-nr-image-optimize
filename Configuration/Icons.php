<?php

/*
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

use TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider;
use TYPO3\CMS\Core\Information\Typo3Version;

/*
 * TYPO3 v14 ships a redesigned backend with light/dark mode: use the flat
 * icon (currentColor + teal accent) that adapts to the active color scheme.
 * v13 uses the colored (teal tile) legacy variant that matches the classic
 * module menu.
 */
$suffix = (new Typo3Version())->getMajorVersion() >= 14 ? '.svg' : '.legacy.svg';

return [
    'module-image-optimize' => [
        'provider' => SvgIconProvider::class,
        'source'   => 'EXT:nr_image_optimize/Resources/Public/Icons/module-image-optimize' . $suffix,
    ],
];
