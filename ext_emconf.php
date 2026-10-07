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

$EM_CONF['nr_image_optimize'] = [
    'title' => 'Image Optimization',
    'description' => 'On-demand image optimization with processed delivery, WebP and AVIF variants, and responsive srcset ViewHelpers.',
    'category'       => 'fe',
    'author'         => 'Team der Netresearch DTT GmbH',
    'author_email'   => '',
    'author_company' => 'Netresearch DTT GmbH',
    'state'          => 'stable',
    'version'        => '2.6.1',
    'constraints'    => [
        'depends' => [
            'php'   => '8.2.0-8.5.99',
            'typo3' => '13.4.0-14.3.99',
        ],
        'conflicts' => [],
        'suggests'  => [],
    ],
];
