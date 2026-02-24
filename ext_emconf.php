<?php

/**
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

$EM_CONF['nr_image_optimize'] = [
    'title' => 'Netresearch: Image Optimization',
    'description' => 'On-demand image optimization for TYPO3 with processed delivery, WebP and AVIF variants, and responsive srcset ViewHelpers.',
    'category'       => 'fe',
    'author'         => 'Team der Netresearch DTT GmbH',
    'author_email'   => '',
    'author_company' => 'Netresearch DTT GmbH',
    'state'          => 'stable',
    'version'        => '2.2.1',
    'constraints'    => [
        'depends' => [
            'typo3' => '13.0.0-13.4.99',
        ],
        'conflicts' => [],
        'suggests'  => [],
    ],
];
