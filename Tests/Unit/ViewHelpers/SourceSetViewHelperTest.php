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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use TYPO3\CMS\Core\Core\ApplicationContext;
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
use function sprintf;
use function substr_count;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

#[CoversClass(SourceSetViewHelper::class)]
class SourceSetViewHelperTest extends TestCase
{
    private SourceSetViewHelper $viewHelper;

    protected function setUp(): void
    {
        parent::setUp();

        $tempDir = sys_get_temp_dir() . '/nr-image-optimize-test';

        Environment::initialize(
            new ApplicationContext('Testing'),
            true,
            true,
            $tempDir,
            $tempDir . '/public',
            $tempDir . '/var',
            $tempDir . '/config',
            $tempDir . '/public/index.php',
            'UNIX',
        );

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
        self::assertStringContainsString('srcset="/processed/path/to/image.w200h200m0q100.jpg 2x"', $result);
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

        $srcMatchResult = preg_match('/src="([^"]+)"/', $result, $srcMatches);
        self::assertSame(1, $srcMatchResult);
        self::assertSame('/processed/path/to/image.w1250h1250m0q100.jpg', $srcMatches[1]);

        $srcsetMatchResult = preg_match('/srcset="([^"]+)"/', $result, $matches);
        self::assertSame(1, $srcsetMatchResult);

        $variants = array_filter(
            array_map(trim(...), explode(',', $matches[1])),
            static fn (string $variant): bool => $variant !== '',
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
    public function getWidthVariantsAcceptsArrayInput(): void
    {
        $this->viewHelper->setArguments([
            'path'          => '/path/to/image.jpg',
            'widthVariants' => [800, 1200, 400],
        ]);

        self::assertSame([400, 800, 1200], $this->callMethod('getWidthVariants'));
    }

    #[Test]
    public function getWidthVariantsRemovesDuplicatesFromArray(): void
    {
        $this->viewHelper->setArguments([
            'path'          => '/path/to/image.jpg',
            'widthVariants' => [640, 640, 320, 320],
        ]);

        self::assertSame([320, 640], $this->callMethod('getWidthVariants'));
    }

    #[Test]
    public function getWidthVariantsUsesDefaultsWhenNotProvided(): void
    {
        $this->viewHelper->setArguments([
            'path' => '/path/to/image.jpg',
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

        $matchResult = preg_match('/srcset="([^"]+)"/', $result, $matches);
        self::assertSame(1, $matchResult);

        $expectedRatio = 0.5;

        $variants = array_filter(
            array_map(trim(...), explode(',', $matches[1])),
            static fn (string $variant): bool => $variant !== '',
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
            mkdir($directory, 0o777, true);
        }

        $imageBinary = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/woAAgMBgBZ20G8AAAAASUVORK5CYII=',
            true,
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
            pathinfo($relativePath, PATHINFO_EXTENSION),
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
    public function getResourcePathOmitsQueryStringWhenNoFlagsSet(): void
    {
        $this->viewHelper->setArguments([
            'mode' => 'cover',
        ]);

        $result = $this->viewHelper->getResourcePath('/path/to/image.jpg', 100, 100, 80, false, false);

        self::assertStringNotContainsString('?', $result);
    }

    #[Test]
    public function getResourcePathIncludesOnlySkipWebPQueryWhenOnlyWebPSkipped(): void
    {
        $this->viewHelper->setArguments([
            'mode' => 'cover',
        ]);

        $result = $this->viewHelper->getResourcePath('/path/to/image.jpg', 100, 100, 80, false, true);

        self::assertStringContainsString('skipWebP=1', $result);
        self::assertStringNotContainsString('skipAvif', $result);
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
            . '/processed/images/picture.w400h240m0q100.jpg 2x" />' . PHP_EOL;

        self::assertSame($expected, $result);
    }

    #[Test]
    public function generateSrcSetReturnsEmptyStringForEmptySet(): void
    {
        $this->viewHelper->setArguments([
            'path' => '/images/picture.jpg',
            'set'  => [],
        ]);

        $result = $this->viewHelper->generateSrcSet();

        self::assertSame('', $result);
    }

    #[Test]
    public function generateSrcSetHandlesMultipleBreakpoints(): void
    {
        $this->viewHelper->setArguments([
            'path' => '/images/picture.jpg',
            'set'  => [
                480 => ['width' => 200, 'height' => 120],
                768 => ['width' => 400, 'height' => 240],
            ],
        ]);

        $result = $this->viewHelper->generateSrcSet();

        self::assertStringContainsString('(max-width: 480px)', $result);
        self::assertStringContainsString('(max-width: 768px)', $result);
        self::assertSame(2, substr_count($result, '<source'));
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
            ],
        );

        self::assertSame(
            '<img src="/processed/example.jpg" alt="Example" data-track="hero" aria-hidden="true" />' . PHP_EOL,
            $result,
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
    public function renderFetchpriorityAutoValue(): void
    {
        $this->viewHelper->setArguments([
            'path'          => '/path/to/image.jpg',
            'width'         => 400,
            'height'        => 300,
            'fetchpriority' => 'auto',
        ]);

        $result = $this->viewHelper->render();
        self::assertStringContainsString('fetchpriority="auto"', $result);
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
    public function getAttributesReturnsEmptyArrayWhenNotSet(): void
    {
        $this->viewHelper->setArguments([]);

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

    #[Test]
    public function getArgSetReturnsEmptyArrayWhenNotProvided(): void
    {
        $this->viewHelper->setArguments([
            'path' => '/images/demo.jpg',
        ]);

        self::assertSame([], $this->callMethod('getArgSet'));
    }

    #[Test]
    public function useJsLazyLoadReturnsTrueWhenClassContainsLazyload(): void
    {
        $this->viewHelper->setArguments([
            'class' => 'image lazyload responsive',
        ]);

        self::assertTrue($this->viewHelper->useJsLazyLoad());
    }

    #[Test]
    public function useJsLazyLoadReturnsFalseWhenClassDoesNotContainLazyload(): void
    {
        $this->viewHelper->setArguments([
            'class' => 'image responsive',
        ]);

        self::assertFalse($this->viewHelper->useJsLazyLoad());
    }

    #[Test]
    public function useJsLazyLoadReturnsFalseWhenClassIsEmpty(): void
    {
        $this->viewHelper->setArguments([
            'class' => '',
        ]);

        self::assertFalse($this->viewHelper->useJsLazyLoad());
    }

    #[Test]
    public function useNativeLazyLoadReturnsTrueWhenEnabled(): void
    {
        $this->viewHelper->setArguments([
            'lazyload' => true,
        ]);

        self::assertTrue($this->callMethod('useNativeLazyLoad'));
    }

    #[Test]
    public function useNativeLazyLoadReturnsFalseWhenDisabled(): void
    {
        $this->viewHelper->setArguments([
            'lazyload' => false,
        ]);

        self::assertFalse($this->callMethod('useNativeLazyLoad'));
    }

    #[Test]
    public function calculateVariantHeightReturnsZeroWhenNoAspectRatio(): void
    {
        self::assertSame(0, $this->callMethod('calculateVariantHeight', 640, 0.0));
    }

    #[Test]
    public function calculateVariantHeightComputesProportionally(): void
    {
        // Aspect ratio of 0.5 means height is half the width
        self::assertSame(320, $this->callMethod('calculateVariantHeight', 640, 0.5));
    }

    #[Test]
    #[DataProvider('fetchpriorityProvider')]
    public function getArgFetchpriorityValidatesValues(string $input, string $expected): void
    {
        $this->viewHelper->setArguments([
            'fetchpriority' => $input,
        ]);

        self::assertSame($expected, $this->callMethod('getArgFetchpriority'));
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function fetchpriorityProvider(): iterable
    {
        yield 'high' => ['high', 'high'];
        yield 'low' => ['low', 'low'];
        yield 'auto' => ['auto', 'auto'];
        yield 'HIGH uppercase' => ['HIGH', 'high'];
        yield 'Low mixed case' => ['Low', 'low'];
        yield 'invalid value' => ['urgent', ''];
        yield 'empty string' => ['', ''];
        yield 'whitespace only' => ['  ', ''];
    }

    #[Test]
    public function renderLegacyModeIncludesDataSrcAndDataSrcsetAttributes(): void
    {
        $this->viewHelper->setArguments([
            'path'   => '/path/to/image.jpg',
            'width'  => 400,
            'height' => 300,
            'class'  => 'lazyload',
        ]);

        $result = $this->viewHelper->render();

        // Legacy mode with JS lazy-load includes data-src and data-srcset
        self::assertStringContainsString('data-src=', $result);
        self::assertStringContainsString('data-srcset=', $result);
    }

    #[Test]
    public function renderLegacyModeWithLazyloadClassUsesPlaceholderSrc(): void
    {
        $this->viewHelper->setArguments([
            'path'   => '/path/to/image.jpg',
            'width'  => 400,
            'height' => 300,
            'class'  => 'lazyload',
        ]);

        $result = $this->viewHelper->render();

        // When lazyload class is present, src should be a transparent placeholder
        self::assertStringContainsString('src="data:image/gif;base64,', $result);
    }

    #[Test]
    public function renderOutputIsSelfClosingTag(): void
    {
        $this->viewHelper->setArguments([
            'path'   => '/path/to/image.jpg',
            'width'  => 400,
            'height' => 300,
        ]);

        $result = $this->viewHelper->render();

        self::assertStringContainsString('/>', $result);
        self::assertStringContainsString('<img ', $result);
    }

    #[Test]
    public function renderEscapesHtmlInAltAndTitle(): void
    {
        $this->viewHelper->setArguments([
            'path'   => '/path/to/image.jpg',
            'width'  => 400,
            'height' => 300,
            'alt'    => 'Image <with> "quotes"',
            'title'  => 'Title &amp; more',
        ]);

        $result = $this->viewHelper->render();

        self::assertStringContainsString('alt="Image &lt;with&gt; &quot;quotes&quot;"', $result);
        self::assertStringContainsString('title="Title &amp;amp; more"', $result);
    }

    #[Test]
    public function renderResponsiveSrcsetWithZeroHeightOmitsHeightCalculation(): void
    {
        $this->viewHelper->setArguments([
            'path'             => '/path/to/image.jpg',
            'width'            => 800,
            'height'           => 0,
            'responsiveSrcset' => true,
        ]);

        $result = $this->viewHelper->render();

        // When height is 0, variant heights should also be 0 (no aspect ratio)
        self::assertStringContainsString('h0', $result);
    }

    #[Test]
    public function validateWidthVariantsReturnsDefaultsForEmptyArray(): void
    {
        /** @var array<int> $result */
        $result = $this->callMethod('validateWidthVariants', []);

        self::assertSame([480, 576, 640, 768, 992, 1200, 1800], $result);
    }

    #[Test]
    public function validateWidthVariantsFiltersInvalidValues(): void
    {
        /** @var array<int> $result */
        $result = $this->callMethod('validateWidthVariants', [0, -1, 100, 200, 0]);

        self::assertContains(100, $result);
        self::assertContains(200, $result);
        self::assertNotContains(0, $result);
        self::assertNotContains(-1, $result);
    }

    // -------------------------------------------------------------------------
    // getResourcePath: path traversal rejection (line 350)
    // -------------------------------------------------------------------------

    #[Test]
    public function getResourcePathRejectsPathTraversal(): void
    {
        $result = $this->callMethod('getResourcePath', '../../etc/passwd', 100, 50);

        // Should return the original path unchanged when it contains '..'
        self::assertSame('../../etc/passwd', $result);
    }

    // -------------------------------------------------------------------------
    // generateSrcSet: data-srcset on source elements when JS lazy (line 421)
    // -------------------------------------------------------------------------

    #[Test]
    public function generateSrcSetIncludesDataSrcsetWhenJsLazyActive(): void
    {
        $this->viewHelper->setArguments([
            'path'  => '/images/picture.jpg',
            'class' => 'lazyload',
            'set'   => [
                480 => [
                    'width'  => 200,
                    'height' => 120,
                ],
            ],
        ]);

        $result = $this->viewHelper->generateSrcSet();

        self::assertStringContainsString('data-srcset=', $result);
        self::assertStringContainsString('srcset=', $result);
    }
}
