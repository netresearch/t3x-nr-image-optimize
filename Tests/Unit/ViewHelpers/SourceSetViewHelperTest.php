<?php

/**
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\NrImageOptimize\Tests\Unit\ViewHelpers;

use Netresearch\NrImageOptimize\ViewHelpers\SourceSetViewHelper;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use TYPO3\CMS\Core\Core\Environment;

use function array_filter;
use function array_map;
use function base64_decode;
use function explode;
use function file_put_contents;
use function floor;
use function is_dir;
use function mkdir;
use function pathinfo;
use function preg_match;
use function rmdir;
use function uniqid;
use function unlink;

#[CoversClass(SourceSetViewHelper::class)]
class SourceSetViewHelperTest extends TestCase
{
    private SourceSetViewHelper $viewHelper;

    #[Before]
    protected function setUp(): void
    {
        $this->viewHelper = new SourceSetViewHelper();
        $this->viewHelper->initializeArguments();
    }

    private function callMethod(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(SourceSetViewHelper::class, $method);

        return $reflection->invoke($this->viewHelper, ...$arguments);
    }

    #[Test]
    public function renderLegacyBehavior(): void
    {
        $this->viewHelper->setArguments([
            'path'   => '/path/to/image.jpg',
            'width'  => 100,
            'height' => 100,
            'alt'    => 'Test Image',
            'class'  => 'test-class',
            'set'    => [
                767 => [
                    'width' => 360,
                ],
            ],
        ]);

        $result = $this->viewHelper->render();

        // Test legacy 2x density output
        self::assertStringContainsString('srcset="/processed/path/to/image.w200h200m0q100.jpg x2"', $result);
        self::assertStringContainsString('width="100"', $result);
        self::assertStringContainsString('height="100"', $result);
        self::assertStringContainsString('alt="Test Image"', $result);
        self::assertStringContainsString('class="test-class"', $result);

        // Ensure no sizes attribute in legacy mode
        self::assertStringNotContainsString('sizes=', $result);
    }

    #[Test]
    public function renderResponsiveSrcset(): void
    {
        $this->viewHelper->setArguments([
            'path'             => '/path/to/image.jpg',
            'width'            => 1250,
            'height'           => 1250,
            'alt'              => 'Test Image',
            'class'            => 'test-class',
            'responsiveSrcset' => true,
        ]);

        $result = $this->viewHelper->render();

        self::assertMatchesRegularExpression('/srcset="[^"]+"/', $result);

        preg_match('/src="([^"]+)"/', $result, $srcMatches);
        self::assertSame('/processed/path/to/image.w1250h1250m0q100.jpg', $srcMatches[1]);

        preg_match('/srcset="([^"]+)"/', $result, $matches);
        self::assertArrayHasKey(1, $matches);

        $variants = array_filter(
            array_map(trim(...), explode(',', $matches[1])),
            static fn (string $variant): bool => $variant !== ''
        );
        self::assertNotEmpty($variants);

        // Test sizes attribute default
        self::assertStringContainsString('sizes="auto, (min-width: 992px) 991px, 100vw"', $result);
    }

    #[Test]
    public function renderAddsEmptyAltAttributeWhenOmitted(): void
    {
        $this->viewHelper->setArguments([
            'path'             => '/path/to/image.jpg',
            'width'            => 800,
            'height'           => 600,
            'responsiveSrcset' => true,
        ]);

        $result = $this->viewHelper->render();

        self::assertStringContainsString('alt=""', $result);
    }

    #[Test]
    public function renderLegacyModeAddsEmptyAltAttributeWhenOmitted(): void
    {
        $this->viewHelper->setArguments([
            'path'   => '/path/to/image.jpg',
            'width'  => 400,
            'height' => 300,
        ]);

        $result = $this->viewHelper->render();

        self::assertStringContainsString('alt=""', $result);
    }

    #[Test]
    public function renderCustomWidthVariants(): void
    {
        $this->viewHelper->setArguments([
            'path'             => '/path/to/image.jpg',
            'width'            => 1250,
            'height'           => 1250,
            'responsiveSrcset' => true,
            'widthVariants'    => '320,640,1024,2048',
        ]);

        $result = $this->viewHelper->render();

        // Test custom width variants
        self::assertStringContainsString('320w', $result);
        self::assertStringContainsString('640w', $result);
        self::assertStringContainsString('1024w', $result);
        self::assertStringContainsString('2048w', $result);

        // Ensure old width variants are not present
        self::assertStringNotContainsString('500w', $result);
        self::assertStringNotContainsString('2500w', $result);
    }

    #[Test]
    public function getWidthVariantsParsesAndSortsValues(): void
    {
        $this->viewHelper->setArguments([
            'path'          => '/path/to/image.jpg',
            'widthVariants' => '640, 320, 640, foo',
        ]);

        self::assertSame([320, 640], $this->callMethod('getWidthVariants'));
    }

    #[Test]
    public function getWidthVariantsFallsBackToDefaultsWhenInvalid(): void
    {
        $this->viewHelper->setArguments([
            'path'          => '/path/to/image.jpg',
            'widthVariants' => '0, -100, foo',
        ]);

        self::assertSame([480, 576, 640, 768, 992, 1200, 1800], $this->callMethod('getWidthVariants'));
    }

    #[Test]
    public function renderCustomSizes(): void
    {
        $this->viewHelper->setArguments([
            'path'             => '/path/to/image.jpg',
            'width'            => 1250,
            'height'           => 1250,
            'responsiveSrcset' => true,
            'sizes'            => '(max-width: 640px) 100vw, (max-width: 1024px) 75vw, 50vw',
        ]);

        $result = $this->viewHelper->render();

        // Test custom sizes attribute
        self::assertStringContainsString('sizes="(max-width: 640px) 100vw, (max-width: 1024px) 75vw, 50vw"', $result);
    }

    #[Test]
    public function renderLazyLoading(): void
    {
        $this->viewHelper->setArguments([
            'path'             => '/path/to/image.jpg',
            'width'            => 1250,
            'height'           => 1250,
            'responsiveSrcset' => true,
            'class'            => 'lazyload',
            'lazyload'         => true,
        ]);

        $result = $this->viewHelper->render();

        // Test native lazy loading
        self::assertStringContainsString('loading="lazy"', $result);

        // Test JS lazy loading attributes
        self::assertStringContainsString('data-src=', $result);
        self::assertStringContainsString('data-srcset=', $result);
        self::assertStringNotContainsString('data-sizes=', $result);
    }

    #[Test]
    public function renderResponsiveSrcsetWithoutLazyloadClassDoesNotEmitDataAttributes(): void
    {
        $this->viewHelper->setArguments([
            'path'             => '/path/to/image.jpg',
            'width'            => 900,
            'height'           => 600,
            'responsiveSrcset' => true,
            'class'            => 'image-fluid',
        ]);

        $result = $this->viewHelper->render();

        self::assertStringNotContainsString('data-src=', $result);
        self::assertStringNotContainsString('data-srcset=', $result);
        self::assertStringNotContainsString('loading="', $result);
    }

    #[Test]
    public function renderResponsiveSrcsetPreservesAspectRatio(): void
    {
        $this->viewHelper->setArguments([
            'path'             => '/path/to/image.jpg',
            'width'            => 1000,
            'height'           => 500, // 2:1 aspect ratio
            'responsiveSrcset' => true,
        ]);

        $result = $this->viewHelper->render();

        preg_match('/srcset="([^"]+)"/', $result, $matches);
        self::assertArrayHasKey(1, $matches);

        $expectedRatio = 0.5;

        $variants = array_filter(
            array_map(trim(...), explode(',', $matches[1])),
            static fn (string $variant): bool => $variant !== ''
        );

        foreach ($variants as $variant) {
            if (preg_match('/\.w(?P<width>\d+)h(?P<height>\d+)m/', $variant, $dimensions) !== 1) {
                self::fail('Failed to extract variant dimensions from srcset entry.');
            }

            $width  = (int) $dimensions['width'];
            $height = (int) $dimensions['height'];

            self::assertSame((int) floor($width * $expectedRatio), $height);
        }
    }

    #[Test]
    public function getResourcePathBuildsProcessedUrl(): void
    {
        $this->viewHelper->setArguments([
            'mode' => 'cover',
        ]);

        $result = $this->viewHelper->getResourcePath('/path/to/image.jpg', 320, 200, 75);

        self::assertSame('/processed/path/to/image.w320h200m0q75.jpg', $result);
    }

    #[Test]
    public function getResourcePathHonorsModeAndQueryFlags(): void
    {
        $this->viewHelper->setArguments([
            'mode' => 'fit',
        ]);

        $result = $this->viewHelper->getResourcePath('/path/to/image.jpg', 640, 480, 85, true, true);

        self::assertSame('/processed/path/to/image.w640h480m1q85.jpg?skipWebP=1&skipAvif=1', $result);
    }

    #[Test]
    public function getResourcePathUsesFileDimensionsWhenWidthAndHeightMissing(): void
    {
        $publicPath   = Environment::getPublicPath();
        $directory    = $publicPath . '/fileadmin/_nr_image_optimize_tests';
        $relativePath = '/fileadmin/_nr_image_optimize_tests/' . uniqid('sample_', true) . '.png';
        $absolutePath = $publicPath . $relativePath;

        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $imageBinary = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/woAAgMBgBZ20G8AAAAASUVORK5CYII=',
            true
        );
        self::assertNotFalse($imageBinary);
        file_put_contents($absolutePath, $imageBinary);

        $this->viewHelper->setArguments([
            'mode' => 'cover',
        ]);

        $result = $this->viewHelper->getResourcePath($relativePath, 0, 0, 92);

        $expected = sprintf(
            '/processed%s/%s.w1h1m0q92.%s',
            pathinfo($relativePath, PATHINFO_DIRNAME),
            pathinfo($relativePath, PATHINFO_FILENAME),
            pathinfo($relativePath, PATHINFO_EXTENSION)
        );

        self::assertSame($expected, $result);

        unlink($absolutePath);
        @rmdir($directory);
    }

    #[Test]
    public function getResourcePathReturnsSvgPathUnchanged(): void
    {
        $this->viewHelper->setArguments([
            'mode' => 'cover',
        ]);

        $result = $this->viewHelper->getResourcePath('/icons/logo.svg', 320, 200, 75);

        self::assertSame('/icons/logo.svg', $result);
    }

    #[Test]
    public function generateSrcSetCreatesSourceElementsForBreakpoints(): void
    {
        $this->viewHelper->setArguments([
            'path' => '/images/picture.jpg',
            'set'  => [
                480 => [
                    'width'  => 200,
                    'height' => 120,
                ],
            ],
        ]);

        $result = $this->viewHelper->generateSrcSet();

        $expected = '<source media="(max-width: 480px)" '
            . 'srcset="/processed/images/picture.w200h120m0q100.jpg, '
            . '/processed/images/picture.w400h240m0q100.jpg x2" '
            . 'data-srcset="/processed/images/picture.w200h120m0q100.jpg, '
            . '/processed/images/picture.w400h240m0q100.jpg x2" />' . PHP_EOL;

        self::assertSame($expected, $result);
    }

    #[Test]
    public function tagMergesAdditionalAttributes(): void
    {
        $this->viewHelper->setArguments([
            'attributes' => [
                'data-track'  => 'hero',
                'aria-hidden' => 'true',
            ],
        ]);

        $tagMethod = new ReflectionMethod(SourceSetViewHelper::class, 'tag');

        $result = $tagMethod->invoke(
            $this->viewHelper,
            'img',
            [
                'src' => '/processed/example.jpg',
                'alt' => 'Example',
            ]
        );

        self::assertSame(
            '<img src="/processed/example.jpg" alt="Example" data-track="hero" aria-hidden="true" />' . PHP_EOL,
            $result
        );
    }

    #[Test]
    public function renderValidatesFetchpriorityArgument(): void
    {
        $this->viewHelper->setArguments([
            'path'          => '/path/to/image.jpg',
            'width'         => 400,
            'height'        => 300,
            'fetchpriority' => 'HIGH',
        ]);

        $resultHigh = $this->viewHelper->render();
        self::assertStringContainsString('fetchpriority="high"', $resultHigh);

        $this->viewHelper->setArguments([
            'path'          => '/path/to/image.jpg',
            'width'         => 400,
            'height'        => 300,
            'fetchpriority' => 'LOW',
        ]);

        $resultLow = $this->viewHelper->render();
        self::assertStringContainsString('fetchpriority="low"', $resultLow);

        $this->viewHelper->setArguments([
            'path'          => '/path/to/image.jpg',
            'width'         => 400,
            'height'        => 300,
            'fetchpriority' => 'invalid',
        ]);

        $resultInvalid = $this->viewHelper->render();
        self::assertStringNotContainsString('fetchpriority="', $resultInvalid);
    }

    #[Test]
    public function getArgModeMapsFitAndDefaultsToCover(): void
    {
        $this->viewHelper->setArguments([
            'mode' => 'fit',
        ]);

        self::assertSame(1, $this->callMethod('getArgMode'));

        $this->viewHelper->setArguments([
            'mode' => 'cover',
        ]);

        self::assertSame(0, $this->callMethod('getArgMode'));

        $this->viewHelper->setArguments([
            'mode' => 'unknown',
        ]);

        self::assertSame(0, $this->callMethod('getArgMode'));
    }

    #[Test]
    public function getAttributesReturnsEmptyArrayForInvalidInput(): void
    {
        $this->viewHelper->setArguments([
            'attributes' => 'not-an-array',
        ]);

        self::assertSame([], $this->callMethod('getAttributes'));
    }

    #[Test]
    public function getArgWidthCastsAndFloorsValues(): void
    {
        $this->viewHelper->setArguments([
            'path'  => '/path/to/image.jpg',
            'width' => 320.8,
        ]);

        self::assertSame(320, $this->callMethod('getArgWidth'));

        $this->viewHelper->setArguments([
            'path' => '/path/to/image.jpg',
        ]);

        self::assertSame(0, $this->callMethod('getArgWidth'));
    }

    #[Test]
    public function getArgHeightCastsAndFloorsValues(): void
    {
        $this->viewHelper->setArguments([
            'path'   => '/path/to/image.jpg',
            'height' => 199.9,
        ]);

        self::assertSame(199, $this->callMethod('getArgHeight'));

        $this->viewHelper->setArguments([
            'path' => '/path/to/image.jpg',
        ]);

        self::assertSame(0, $this->callMethod('getArgHeight'));
    }

    #[Test]
    public function getArgSetReturnsConfiguredBreakpoints(): void
    {
        $set = [
            640 => ['width' => 320, 'height' => 200],
        ];

        $this->viewHelper->setArguments([
            'path' => '/images/demo.jpg',
            'set'  => $set,
        ]);

        self::assertSame($set, $this->callMethod('getArgSet'));
    }
}
