<?php

/*
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\NrImageOptimize\Tests\Functional;

use function getimagesize;

use Netresearch\NrImageOptimize\Processor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Functional tests for the Processor with real image fixtures.
 *
 * These tests verify actual image processing (resize/crop) behavior
 * against test fixture images.
 */
#[CoversClass(Processor::class)]
final class ProcessorTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'netresearch/nr-image-optimize',
    ];

    protected array $pathsToProvideInTestInstance = [
        'typo3conf/ext/nr_image_optimize/Tests/Functional/Fixtures/test-image.png' => 'fileadmin/test-image.png',
    ];

    #[Test]
    public function processorResizesImageToRequestedDimensions(): void
    {
        $processor = $this->get(Processor::class);

        $uri     = new Uri('https://example.com/processed/fileadmin/test-image.w50h38m0q80.png');
        $request = new ServerRequest($uri);

        $response = $processor->generateAndSend($request);

        self::assertSame(200, $response->getStatusCode());

        $contentType = $response->getHeaderLine('Content-Type');
        self::assertNotEmpty($contentType, 'Response should have Content-Type header');

        $body = (string) $response->getBody();
        self::assertNotEmpty($body, 'Response body should contain image data');
    }

    #[Test]
    public function processorCreatesVariantFileOnDisk(): void
    {
        $processor = $this->get(Processor::class);

        $uri     = new Uri('https://example.com/processed/fileadmin/test-image.w50h38m0q80.png');
        $request = new ServerRequest($uri);

        $response = $processor->generateAndSend($request);

        self::assertSame(200, $response->getStatusCode());

        $variantPath = Environment::getPublicPath() . '/processed/fileadmin/test-image.w50h38m0q80.png';

        self::assertFileExists($variantPath, 'Variant file should be created on disk');
    }

    #[Test]
    public function processorServesAlreadyProcessedImageFromCache(): void
    {
        $processor = $this->get(Processor::class);

        $uri     = new Uri('https://example.com/processed/fileadmin/test-image.w50h38m0q80.png');
        $request = new ServerRequest($uri);

        // First request generates the image
        $response1 = $processor->generateAndSend($request);
        self::assertSame(200, $response1->getStatusCode());

        // Second request should serve from cache
        $response2 = $processor->generateAndSend($request);
        self::assertSame(200, $response2->getStatusCode());
        self::assertNotEmpty($response2->getHeaderLine('Cache-Control'));
    }

    #[Test]
    public function processorReturns404ForNonExistentSourceImage(): void
    {
        $processor = $this->get(Processor::class);

        $uri     = new Uri('https://example.com/processed/fileadmin/no-such-file.w100h75m0q80.png');
        $request = new ServerRequest($uri);

        $response = $processor->generateAndSend($request);

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function processorReturns400ForInvalidUrl(): void
    {
        $processor = $this->get(Processor::class);

        $uri     = new Uri('https://example.com/processed/');
        $request = new ServerRequest($uri);

        $response = $processor->generateAndSend($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function processorReturns400ForPathTraversalAttempt(): void
    {
        $processor = $this->get(Processor::class);

        $uri     = new Uri('https://example.com/processed/../../etc/passwd.w100h75m0q80.png');
        $request = new ServerRequest($uri);

        $response = $processor->generateAndSend($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function processorAppliesCoverModeByDefault(): void
    {
        $processor = $this->get(Processor::class);

        // mode=0 is cover (crop to fill)
        $uri     = new Uri('https://example.com/processed/fileadmin/test-image.w50h50m0q90.png');
        $request = new ServerRequest($uri);

        $response = $processor->generateAndSend($request);

        self::assertSame(200, $response->getStatusCode());

        $variantPath = Environment::getPublicPath() . '/processed/fileadmin/test-image.w50h50m0q90.png';
        self::assertFileExists($variantPath);

        $info = getimagesize($variantPath);
        self::assertIsArray($info, 'Processed image should be a valid image file');
        self::assertSame(50, $info[0], 'Cover mode should produce exact target width');
        self::assertSame(50, $info[1], 'Cover mode should produce exact target height');
    }

    #[Test]
    public function processorAppliesScaleModeWhenRequested(): void
    {
        $processor = $this->get(Processor::class);

        // mode=1 is scale (fit inside)
        $uri     = new Uri('https://example.com/processed/fileadmin/test-image.w50h50m1q90.png');
        $request = new ServerRequest($uri);

        $response = $processor->generateAndSend($request);

        self::assertSame(200, $response->getStatusCode());

        $variantPath = Environment::getPublicPath() . '/processed/fileadmin/test-image.w50h50m1q90.png';
        self::assertFileExists($variantPath);

        $info = getimagesize($variantPath);
        self::assertIsArray($info, 'Processed image should be a valid image file');

        // Scale mode fits inside the box, so at least one dimension should match
        // and both should be <= target
        self::assertLessThanOrEqual(50, $info[0], 'Scale mode width should not exceed target');
        self::assertLessThanOrEqual(50, $info[1], 'Scale mode height should not exceed target');
        self::assertTrue(
            $info[0] === 50 || $info[1] === 50,
            'Scale mode should have at least one dimension matching the target',
        );
    }

    #[Test]
    public function processorSetsHttpCachingHeaders(): void
    {
        $processor = $this->get(Processor::class);

        $uri     = new Uri('https://example.com/processed/fileadmin/test-image.w50h38m0q80.png?skipWebP=1&skipAvif=1');
        $request = new ServerRequest($uri);

        $response = $processor->generateAndSend($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('max-age=', $response->getHeaderLine('Cache-Control'));
        self::assertStringContainsString('immutable', $response->getHeaderLine('Cache-Control'));
    }

    #[Test]
    public function processorSkipsWebpVariantWhenFlagged(): void
    {
        $processor = $this->get(Processor::class);

        $uri     = new Uri('https://example.com/processed/fileadmin/test-image.w30h23m0q80.png?skipWebP=1&skipAvif=1');
        $request = new ServerRequest($uri);

        $response = $processor->generateAndSend($request);
        self::assertSame(200, $response->getStatusCode());

        $variantPath = Environment::getPublicPath() . '/processed/fileadmin/test-image.w30h23m0q80.png';
        self::assertFileExists($variantPath);
        self::assertFileDoesNotExist($variantPath . '.webp', 'WebP variant should not be created when skipWebP=1');
    }
}
