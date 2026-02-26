<?php

/**
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

$EM_CONF[$_EXTKEY] = [
    'title'          => 'Netresearch Image Optimize',
    'description'    => 'Advanced image optimization extension for TYPO3. Features include: Lazy image processing (on-demand generation), WebP and AVIF format support with automatic fallback, optimized compression for smaller file sizes, ViewHelper for responsive images with srcset support, middleware for efficient image delivery, and support for Intervention Image library. Perfect for improving Core Web Vitals and page loading performance.',
    'category'       => 'fe',
    'author'         => 'Netresearch DTT GmbH',
    'author_email'   => 'info@netresearch.de',
    'author_company' => 'Netresearch DTT GmbH',
    'state'          => 'stable',
    'version'        => '1.0.2',
    'constraints'    => [
        'depends' => [
            'typo3' => '11.5.0-12.4.99',
            'php'   => '8.1.0-8.4.99',
        ],
        'conflicts' => [],
        'suggests'  => [
            'webp' => 'WebP support for better image compression',
        ],
    ],
    'autoload' => [
        'psr-4' => [
            'Netresearch\\NrImageOptimize\\' => 'Classes/',
        ],
    ],
];
