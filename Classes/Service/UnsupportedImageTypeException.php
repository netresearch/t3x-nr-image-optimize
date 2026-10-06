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

namespace Netresearch\NrImageOptimize\Service;

use RuntimeException;

/**
 * Thrown by ImageReaderInterface::read() when a file's content is not an
 * image of a type the extension decodes.
 */
final class UnsupportedImageTypeException extends RuntimeException {}
