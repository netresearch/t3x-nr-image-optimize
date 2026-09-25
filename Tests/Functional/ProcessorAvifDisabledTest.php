<?php

/*
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\NrImageOptimize\Tests\Functional;

use Netresearch\NrImageOptimize\Processor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Functional test for generateAvif=0 with the real encoder.
 */
#[CoversClass(Processor::class)]
final class ProcessorAvifDisabledTest extends FunctionalTestCase
{
    use AvifEncoderProbeTrait;

    protected array $testExtensionsToLoad = [
        'netresearch/nr-image-optimize',
    ];

    protected array $pathsToProvideInTestInstance = [
        'typo3conf/ext/nr_image_optimize/Tests/Functional/Fixtures/test-image.png' => 'fileadmin/test-image.png',
    ];

    protected array $configurationToUseInTestInstance = [
        'EXTENSIONS' => [
            'nr_image_optimize' => [
                'generateAvif' => '0',
            ],
        ],
    ];

    #[Test]
    public function disabledAvifIsNotWritten(): void
    {
        // Without a working AVIF encoder the file would be missing anyway, so
        // the assertion below could not tell the switch from the environment.
        $this->skipUnlessAvifEncoderWorks();

        $response = $this->get(Processor::class)->generateAndSend(
            new ServerRequest(new Uri('https://example.com/processed/fileadmin/test-image.w42h31m0q80.png')),
        );

        self::assertSame(200, $response->getStatusCode());

        $variantPath = Environment::getPublicPath() . '/processed/fileadmin/test-image.w42h31m0q80.png';
        self::assertFileExists($variantPath);
        self::assertFileDoesNotExist($variantPath . '.avif', 'AVIF variant must not be written when generateAvif=0');
    }
}
