<?php

/**
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

use Netresearch\NrImageOptimize\Controller\MaintenanceController;

return [
    'nr_image_optimize' => [
        'parent'            => 'tools',
        'position'          => ['after' => 'toolsmaintenance'],
        'access'            => 'systemMaintainer',
        'workspaces'        => 'live',
        'path'              => '/module/tools/nr-image-optimize',
        'labels'            => 'LLL:EXT:nr_image_optimize/Resources/Private/Language/locallang.xlf:module.maintenance',
        'extensionName'     => 'NrImageOptimize',
        'iconIdentifier'    => 'module-image-optimize',
        'controllerActions' => [
            MaintenanceController::class => [
                'index',
                'clearProcessedImages',
            ],
        ],
    ],
];
