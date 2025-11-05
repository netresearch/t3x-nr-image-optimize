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
        'parent'            => 'system',
        'position'          => ['after' => 'config'],
        'access'            => 'systemMaintainer',
        'workspaces'        => 'live',
        'path'              => '/module/system/nr-image-optimize',
        'labels'            => 'LLL:EXT:nr_image_optimize/Resources/Private/Language/locallang.xlf:module.maintenance',
        'extensionName'     => 'NrImageOptimize',
        'iconIdentifier'    => 'actions-edit-delete',
        'controllerActions' => [
            MaintenanceController::class => [
                'index',
                'clearProcessedImages',
            ],
        ],
    ],
];
