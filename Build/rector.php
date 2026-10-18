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

use Rector\Config\RectorConfig;

$configure = require_once __DIR__ . '/../.Build/vendor/netresearch/typo3-ci-workflows/config/rector/rector.php';

return static function (RectorConfig $rectorConfig) use ($configure): void {
    // Shared org base config: paths (with Tests/), code-quality sets, rule
    // skips, the package's ergebnis-free phpstan-rector.neon, and the PHP and
    // TYPO3 level sets for the lowest supported versions (composer.json:
    // php >=8.2, typo3/cms-core ^13.4 || ^14.3).
    $configure($rectorConfig, __DIR__ . '/..', [
        'tests' => true,
        'php'   => '8.2',
        'typo3' => 13,
    ]);

    $rectorConfig->disableParallel();
};
