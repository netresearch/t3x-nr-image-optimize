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

namespace Netresearch\NrImageOptimize\Tests\Functional;

use Netresearch\NrImageOptimize\Service\VariantUrlSigner;
use TYPO3\CMS\Core\Crypto\HashService;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;

use function is_string;
use function parse_str;
use function urldecode;

/**
 * Builds variant requests that carry the signature SourceSetViewHelper adds
 * to every URL it renders -- over the path and the skip flags in $query --
 * keyed with the test instance's encryption key.
 */
trait SignedVariantRequestTrait
{
    /**
     * @param string $path  Variant path as it appears in the URL, e.g. "/processed/fileadmin/a.w50h38m0q80.png"
     * @param string $query Additional query string without the signature, e.g. "skipWebP=1"
     */
    private function signedVariantRequest(string $path, string $query = ''): ServerRequest
    {
        // The flags are signed the way SourceSetViewHelper signs them and the
        // processor reads them back.
        $flags = [];
        parse_str($query, $flags);

        $signature = (new VariantUrlSigner(new HashService()))->sign(
            urldecode($path),
            is_string($flags['skipWebP'] ?? null) && (bool) $flags['skipWebP'],
            is_string($flags['skipAvif'] ?? null) && (bool) $flags['skipAvif'],
        );

        $query = ($query === '' ? '' : $query . '&')
            . VariantUrlSigner::QUERY_PARAMETER . '=' . $signature;

        return new ServerRequest(new Uri('https://example.com' . $path . '?' . $query));
    }
}
