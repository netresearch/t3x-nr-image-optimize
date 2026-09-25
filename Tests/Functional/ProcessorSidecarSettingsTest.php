<?php

/*
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\NrImageOptimize\Tests\Functional;

use function filesize;

use Imagick;
use Netresearch\NrImageOptimize\Event\ImageProcessedEvent;
use Netresearch\NrImageOptimize\Event\VariantServedEvent;
use Netresearch\NrImageOptimize\Processor;
use Netresearch\NrImageOptimize\Service\ImageManagerAdapter;
use Netresearch\NrImageOptimize\Service\ImageManagerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Functional tests for the sidecar extension settings with the real encoder:
 * WebP generation switched off, AVIF configured at quality 100, and an AVIF
 * original requested at quality 100.
 */
#[CoversClass(Processor::class)]
#[UsesClass(ImageManagerAdapter::class)]
#[UsesClass(ImageManagerFactory::class)]
#[UsesClass(ImageProcessedEvent::class)]
#[UsesClass(VariantServedEvent::class)]
final class ProcessorSidecarSettingsTest extends FunctionalTestCase
{
    use EncoderProbeTrait;

    protected array $testExtensionsToLoad = [
        'netresearch/nr-image-optimize',
    ];

    protected array $pathsToProvideInTestInstance = [
        'typo3conf/ext/nr_image_optimize/Tests/Functional/Fixtures/test-image.png' => 'fileadmin/test-image.png',
    ];

    protected array $configurationToUseInTestInstance = [
        'EXTENSIONS' => [
            'nr_image_optimize' => [
                'generateWebp' => '0',
                'generateAvif' => '1',
                'qualityAvif'  => '100',
            ],
        ],
    ];

    #[Test]
    public function disabledWebpIsNotWritten(): void
    {
        $response = $this->get(Processor::class)->generateAndSend(
            new ServerRequest(new Uri('https://example.com/processed/fileadmin/test-image.w40h30m0q80.png')),
        );

        self::assertSame(200, $response->getStatusCode());

        $variantPath = Environment::getPublicPath() . '/processed/fileadmin/test-image.w40h30m0q80.png';
        self::assertFileExists($variantPath);
        self::assertFileDoesNotExist($variantPath . '.webp', 'WebP variant must not be written when generateWebp=0');
    }

    #[Test]
    public function avifIsWrittenAlthoughQualityIsConfiguredAt100(): void
    {
        $this->skipUnlessAvifEncoderWorks();

        $response = $this->get(Processor::class)->generateAndSend(
            new ServerRequest(new Uri('https://example.com/processed/fileadmin/test-image.w44h33m0q80.png')),
        );

        self::assertSame(200, $response->getStatusCode());

        $avifPath = Environment::getPublicPath() . '/processed/fileadmin/test-image.w44h33m0q80.png.avif';
        self::assertFileExists($avifPath, 'AVIF variant must be written although qualityAvif=100');
        self::assertGreaterThan(0, filesize($avifPath));
    }

    #[Test]
    public function avifOriginalRequestedAtQuality100IsWritten(): void
    {
        $this->skipUnlessAvifEncoderWorks();

        $original = new Imagick();
        $original->newImage(64, 48, 'red');
        $original->setImageFormat('AVIF');
        $original->setCompressionQuality(60);
        $original->writeImage(Environment::getPublicPath() . '/fileadmin/test-image-avif.avif');

        $response = $this->get(Processor::class)->generateAndSend(
            new ServerRequest(new Uri('https://example.com/processed/fileadmin/test-image-avif.w32h24m0q100.avif')),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/avif', $response->getHeaderLine('Content-Type'));

        $variantPath = Environment::getPublicPath() . '/processed/fileadmin/test-image-avif.w32h24m0q100.avif';
        self::assertFileExists($variantPath, 'AVIF primary variant must be written although q100 was requested');
        self::assertGreaterThan(0, filesize($variantPath));
    }
}
