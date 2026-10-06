<?php

/**
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\NrImageOptimize\Tests\Unit\Service;

use Netresearch\NrImageOptimize\Service\VariantUrlSigner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[CoversClass(VariantUrlSigner::class)]
final class VariantUrlSignerTest extends TestCase
{
    private const PATH = '/processed/fileadmin/hero.w1200h800m0q85.jpg';

    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = 'variant-url-signer-test-key';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey']);

        parent::tearDown();
    }

    #[Test]
    public function signatureOfAPathIsAcceptedForThatPath(): void
    {
        $signer = new VariantUrlSigner();

        self::assertTrue($signer->isValid(self::PATH, $signer->sign(self::PATH)));
    }

    #[Test]
    public function signatureIsAnHmacOfThePathWithAPurposeSpecificSecret(): void
    {
        $signer = new VariantUrlSigner();

        $signature = $signer->sign(self::PATH);

        self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $signature);
        self::assertNotSame(GeneralUtility::hmac(self::PATH, 'another-purpose'), $signature);
    }

    #[Test]
    public function signatureOfOnePathIsRefusedForAnotherPath(): void
    {
        $signer = new VariantUrlSigner();

        self::assertFalse($signer->isValid(
            '/processed/fileadmin/hero.w8192h8192m0q100.jpg',
            $signer->sign(self::PATH),
        ));
    }

    #[Test]
    public function emptySignatureIsRefused(): void
    {
        $signer = new VariantUrlSigner();

        self::assertFalse($signer->isValid(self::PATH, ''));
    }

    #[Test]
    public function signatureMadeWithAnotherEncryptionKeyIsRefused(): void
    {
        $signer    = new VariantUrlSigner();
        $signature = $signer->sign(self::PATH);

        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = 'rotated-encryption-key';

        self::assertFalse($signer->isValid(self::PATH, $signature));
    }

    #[Test]
    public function withoutEncryptionKeyNothingIsSignedAndNothingIsAccepted(): void
    {
        $signer = new VariantUrlSigner();

        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = '';

        // What a signature would be if only the purpose-specific secret,
        // which is public in this source file, keyed the HMAC.
        $keyedWithoutEncryptionKey = GeneralUtility::hmac(self::PATH, 'nr_image_optimize:processed-variant-url');

        self::assertSame('', $signer->sign(self::PATH));
        self::assertFalse($signer->isValid(self::PATH, $keyedWithoutEncryptionKey));
    }
}
