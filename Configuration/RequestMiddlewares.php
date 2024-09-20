<?php

/**
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

use Netresearch\NrImageOptimize\Middleware\ProcessingMiddleware;

return [
    'frontend' => [
        'netresearch/nr-image-optimize' => [
            'target' => ProcessingMiddleware::class,
            'before' => [
                'typo3/cms-frontend/site',
            ],
        ],
    ],
];
