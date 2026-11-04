<?php

/**
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\NrImageOptimize\Service;

use TYPO3\CMS\Core\Utility\GeneralUtility;

use function hash_equals;
use function is_array;
use function is_string;

/**
 * Signs and verifies the variant path of a "/processed/" URL.
 *
 * The processor creates a new variant only when its URL carries a valid
 * signature, so the variants that can be created on demand are the ones the
 * installation's own templates rendered (SourceSetViewHelper signs every URL
 * it builds). A variant that already exists on disk is served without a
 * signature.
 *
 * The signature is an HMAC (GeneralUtility::hmac(), SHA-1) over the
 * URL-decoded variant path -- source path, mode string (w/h/q/m) and
 * extension -- keyed with the installation's encryption key plus a secret
 * specific to this purpose. A URL that sets the skipWebP/skipAvif query flags
 * is signed over the path plus those flags, so a flag appended to a signed URL
 * invalidates it: whichever request creates a variant decides which sidecars
 * it gets, so an unsigned flag would let anyone strip a variant's AVIF or WebP
 * sidecar for every later visitor. A URL without flags is signed over the
 * path alone.
 *
 * Changing $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] invalidates
 * every signature; pages have to be re-rendered (cache flush) afterwards.
 */
final class VariantUrlSigner
{
    /**
     * Name of the query parameter that carries the signature.
     */
    public const QUERY_PARAMETER = 'sig';

    /**
     * Purpose-specific secret mixed into the HMAC key so a signature issued
     * here is never valid for another TYPO3 HMAC use and vice versa.
     */
    private const ADDITIONAL_SECRET = 'nr_image_optimize:processed-variant-url';

    /**
     * Return the signature for a URL-decoded variant path.
     *
     * Returns an empty string when the installation has no encryption key:
     * without it the HMAC key would consist of the public additional secret
     * alone, so no signature is issued (and isValid() accepts none).
     *
     * @param string $variantPath URL-decoded path, e.g. "/processed/fileadmin/a.w800h0m0q75.jpg"
     * @param bool   $skipWebP    Whether the URL carries the skipWebP flag
     * @param bool   $skipAvif    Whether the URL carries the skipAvif flag
     *
     * @return string Hex-encoded HMAC, or '' when no encryption key is configured
     */
    public function sign(string $variantPath, bool $skipWebP = false, bool $skipAvif = false): string
    {
        if (!$this->hasEncryptionKey()) {
            return '';
        }

        return GeneralUtility::hmac(
            $this->signedMessage($variantPath, $skipWebP, $skipAvif),
            self::ADDITIONAL_SECRET,
        );
    }

    /**
     * Whether the signature is valid for the URL-decoded variant path.
     *
     * @param string $variantPath URL-decoded path as received by the processor
     * @param string $signature   Value of the QUERY_PARAMETER query parameter
     * @param bool   $skipWebP    Whether the request carries the skipWebP flag
     * @param bool   $skipAvif    Whether the request carries the skipAvif flag
     *
     * @return bool True only for a non-empty signature issued by sign() for the same path and flags with the current encryption key
     */
    public function isValid(
        string $variantPath,
        string $signature,
        bool $skipWebP = false,
        bool $skipAvif = false,
    ): bool {
        if ($signature === '') {
            return false;
        }

        $expected = $this->sign($variantPath, $skipWebP, $skipAvif);

        if ($expected === '') {
            return false;
        }

        return hash_equals($expected, $signature);
    }

    /**
     * The string the HMAC is computed over.
     *
     * Without flags this is the path alone, so URLs rendered before the
     * flags were signed keep their signature. With a flag, the flag state
     * follows a NUL byte; the processor only accepts paths ending in an
     * image extension, so no path can produce the same string.
     */
    private function signedMessage(string $variantPath, bool $skipWebP, bool $skipAvif): string
    {
        if (!$skipWebP && !$skipAvif) {
            return $variantPath;
        }

        return $variantPath . "\0skipWebP=" . (int) $skipWebP . '&skipAvif=' . (int) $skipAvif;
    }

    /**
     * Whether TYPO3 has a non-empty encryption key configured.
     */
    private function hasEncryptionKey(): bool
    {
        $configuration = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        $system        = is_array($configuration) ? ($configuration['SYS'] ?? null) : null;
        $key           = is_array($system) ? ($system['encryptionKey'] ?? null) : null;

        return is_string($key) && $key !== '';
    }
}
