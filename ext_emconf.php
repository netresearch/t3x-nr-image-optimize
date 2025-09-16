<?php

/**
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

$EM_CONF['nr_image_optimize'] = [
    'title'          => 'Netresearch Image Optimize',
    'description'    => 'Advanced image optimization extension for TYPO3 13. Features include: Lazy image processing (on-demand generation), WebP and AVIF format support with automatic fallback, optimized compression for smaller file sizes, ViewHelper for responsive images with srcset support, middleware for efficient image delivery, and support for Intervention Image library. Perfect for improving Core Web Vitals and page loading performance.',
    'category'       => 'fe',
    'author'         => 'Netresearch DTT GmbH',
    'author_email'   => 'info@netresearch.de',
    'author_company' => 'Netresearch DTT GmbH',
    'state'          => 'stable',
    'version'        => '2.0.0',
    'constraints'    => [
        'depends' => [
            'typo3' => '13.0.0-13.4.99',
        ],
        'conflicts' => [],
        'suggests'  => [
            'webp' => 'WebP support for better image compression',
        ],
    ],
];
