<?php

/*
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\NrImageOptimize\Tests\Unit\ViewHelpers;

use function array_filter;
use function array_map;
use function assert;
use function base64_decode;
use function explode;
use function file_put_contents;
use function floor;
use function is_dir;
use function mkdir;

use Netresearch\NrImageOptimize\ViewHelpers\SourceSetViewHelper;

use function pathinfo;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function preg_match;

use ReflectionMethod;
use ReflectionProperty;

use function rmdir;
use function str_contains;
use function strpos;
use function substr_count;
use function sys_get_temp_dir;

use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;
use TYPO3Fluid\Fluid\Core\ViewHelper\ArgumentDefinition;

use function uniqid;
use function unlink;

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
    #[DataProvider('nextGenMimeTypeProvider')]
    public function generateSrcSetIncludesTypeForNextGenFormats(string $extension, string $expectedType): void
    {
        $this->viewHelper->setArguments([
            'path' => '/images/picture.' . $extension,
            'set'  => [
                480 => ['width' => 200, 'height' => 120],
            ],
        ]);

        $result = $this->viewHelper->generateSrcSet();

        self::assertStringContainsString('type="' . $expectedType . '"', $result);
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function nextGenMimeTypeProvider(): iterable
    {
        yield 'webp' => ['webp', 'image/webp'];
        yield 'avif' => ['avif', 'image/avif'];
    }

    #[Test]
    #[DataProvider('universalFormatProvider')]
    public function generateSrcSetOmitsTypeForUniversalFormats(string $extension): void
    {
        $this->viewHelper->setArguments([
            'path' => '/images/picture.' . $extension,
            'set'  => [
                480 => ['width' => 200, 'height' => 120],
            ],
        ]);

        $result = $this->viewHelper->generateSrcSet();

        self::assertStringNotContainsString('type=', $result);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function universalFormatProvider(): iterable
    {
        yield 'jpg' => ['jpg'];
        yield 'jpeg' => ['jpeg'];
        yield 'png' => ['png'];
        yield 'gif' => ['gif'];
    }

    #[Test]
    public function generateSrcSetOmitsTypeForUnknownExtension(): void
    {
        $this->viewHelper->setArguments([
            'path' => '/images/picture.bmp',
            'set'  => [
                480 => ['width' => 200, 'height' => 120],
            ],
        ]);

        $result = $this->viewHelper->generateSrcSet();

        self::assertStringNotContainsString('type=', $result);
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

        self::assertTrue($this->callMethod('useJsLazyLoad'));
    }

    #[Test]
    public function useJsLazyLoadReturnsFalseWhenClassDoesNotContainLazyload(): void
    {
        $this->viewHelper->setArguments([
            'class' => 'image responsive',
        ]);

        self::assertFalse($this->callMethod('useJsLazyLoad'));
    }

    #[Test]
    public function useJsLazyLoadReturnsFalseWhenClassIsEmpty(): void
    {
        $this->viewHelper->setArguments([
            'class' => '',
        ]);

        self::assertFalse($this->callMethod('useJsLazyLoad'));
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

    // =========================================================================
    // getResourcePath: trigger_error when getimagesize fails (lines 380-383)
    // =========================================================================

    #[Test]
    public function getResourcePathTriggersErrorWhenGetimagesizeFails(): void
    {
        // Use a path that doesn't exist on disk → getimagesize returns false
        // The trigger_error(E_USER_NOTICE) should fire
        set_error_handler(static function (int $errno, string $errstr): bool {
            if ($errno === E_USER_NOTICE && str_contains($errstr, 'getimagesize() failed')) {
                return true; // handled
            }

            return false;
        });

        try {
            $result = $this->callMethod(
                'getResourcePath',
                '/nonexistent/image-that-does-not-exist.jpg',
                0,
                0,
            );

            // Should still return a processed URL (with w0h0) despite the error
            assert(is_string($result));
            self::assertStringContainsString('/processed/', $result);
        } finally {
            restore_error_handler();
        }
    }

    // =========================================================================
    // Mutation-killing tests: initializeArguments registration via reflection
    // =========================================================================

    #[Test]
    public function initializeArgumentsRegistersAllExpectedArguments(): void
    {
        // Verify all arguments are registered (kills MethodCallRemoval on each registerArgument line)
        $viewHelper = new SourceSetViewHelper();
        $viewHelper->initializeArguments();

        $reflection = new ReflectionProperty(AbstractViewHelper::class, 'argumentDefinitions');
        /** @var array<string, ArgumentDefinition> $definitions */
        $definitions = $reflection->getValue($viewHelper);

        $expectedArgs = [
            'path', 'set', 'width', 'height', 'alt', 'class',
            'mode', 'title', 'lazyload', 'attributes', 'responsiveSrcset',
            'widthVariants', 'sizes', 'fetchpriority',
        ];

        foreach ($expectedArgs as $argName) {
            self::assertArrayHasKey($argName, $definitions, sprintf("Argument '%s' must be registered.", $argName));
        }
    }

    #[Test]
    public function initializeArgumentsRegistersPathAsRequired(): void
    {
        // Kills TrueValue mutant on path (true → false)
        $viewHelper = new SourceSetViewHelper();
        $viewHelper->initializeArguments();

        $reflection = new ReflectionProperty(AbstractViewHelper::class, 'argumentDefinitions');
        /** @var array<string, ArgumentDefinition> $definitions */
        $definitions = $reflection->getValue($viewHelper);

        self::assertTrue($definitions['path']->isRequired(), "'path' must be required.");
    }

    #[Test]
    public function initializeArgumentsRegistersCorrectDefaults(): void
    {
        // Kills DecrementInteger/IncrementInteger on width/height defaults (0 → -1/1)
        $viewHelper = new SourceSetViewHelper();
        $viewHelper->initializeArguments();

        $reflection = new ReflectionProperty(AbstractViewHelper::class, 'argumentDefinitions');
        /** @var array<string, ArgumentDefinition> $definitions */
        $definitions = $reflection->getValue($viewHelper);

        self::assertSame(0, $definitions['width']->getDefaultValue());
        self::assertSame(0, $definitions['height']->getDefaultValue());
        self::assertFalse($definitions['lazyload']->getDefaultValue());
        self::assertFalse($definitions['responsiveSrcset']->getDefaultValue());
        self::assertSame('', $definitions['fetchpriority']->getDefaultValue());
        self::assertSame('', $definitions['alt']->getDefaultValue());
        self::assertSame('', $definitions['class']->getDefaultValue());
        self::assertSame('cover', $definitions['mode']->getDefaultValue());
        self::assertSame('', $definitions['title']->getDefaultValue());
        self::assertSame([], $definitions['set']->getDefaultValue());
        self::assertSame([], $definitions['attributes']->getDefaultValue());
    }

    #[Test]
    public function initializeArgumentsRegistersWidthVariantsAsOptional(): void
    {
        // Kills FalseValue mutant on widthVariants (false → true for required)
        $viewHelper = new SourceSetViewHelper();
        $viewHelper->initializeArguments();

        $reflection = new ReflectionProperty(AbstractViewHelper::class, 'argumentDefinitions');
        /** @var array<string, ArgumentDefinition> $definitions */
        $definitions = $reflection->getValue($viewHelper);

        self::assertFalse($definitions['widthVariants']->isRequired(), "'widthVariants' must be optional.");
    }

    // =========================================================================
    // Mutation-killing tests: wrapInPicture exact output format
    // =========================================================================

    #[Test]
    public function wrapInPictureProducesExactFormat(): void
    {
        // Kills all Concat/ConcatOperandRemoval mutants on line 279
        $result = $this->callMethod('wrapInPicture', 'INNER');

        self::assertSame('<picture>' . PHP_EOL . 'INNER</picture>' . PHP_EOL, $result);
    }

    // =========================================================================
    // Mutation-killing tests: responsive srcset entry format
    // =========================================================================

    #[Test]
    public function renderResponsiveSrcsetEntryHasExactFormat(): void
    {
        // Kills Concat/ConcatOperandRemoval on line 170 ($url . ' ' . $variantWidth . 'w')
        $this->viewHelper->setArguments([
            'path'             => '/path/to/image.jpg',
            'width'            => 1000,
            'height'           => 500,
            'responsiveSrcset' => true,
            'widthVariants'    => '320',
        ]);

        $result = $this->viewHelper->render();

        // The srcset entry must be "URL 320w" with exactly one space before the width descriptor
        self::assertStringContainsString('/processed/path/to/image.w320h160m0q100.jpg 320w', $result);
    }

    // =========================================================================
    // Mutation-killing tests: source ordering in wrapInPicture calls
    // =========================================================================

    #[Test]
    public function renderLegacyModeSourcesAppearBeforeImgTag(): void
    {
        // Kills Concat/ConcatOperandRemoval on line 219 ($sources . $imgTag)
        $this->viewHelper->setArguments([
            'path'   => '/images/photo.jpg',
            'width'  => 200,
            'height' => 100,
            'set'    => [
                480 => ['width' => 100, 'height' => 50],
            ],
        ]);

        $result = $this->viewHelper->render();

        $sourcePos = strpos($result, '<source');
        $imgPos    = strpos($result, '<img');
        self::assertNotFalse($sourcePos);
        self::assertNotFalse($imgPos);
        self::assertLessThan($imgPos, $sourcePos, '<source> must appear before <img>');
    }

    #[Test]
    public function renderResponsiveModeSourcesAppearBeforeImgTag(): void
    {
        // Kills Concat/ConcatOperandRemoval on line 190 ($sources . $imgTag)
        $this->viewHelper->setArguments([
            'path'             => '/images/photo.jpg',
            'width'            => 200,
            'height'           => 100,
            'responsiveSrcset' => true,
            'set'              => [
                480 => ['width' => 100, 'height' => 50],
            ],
        ]);

        $result = $this->viewHelper->render();

        $sourcePos = strpos($result, '<source');
        $imgPos    = strpos($result, '<img');
        self::assertNotFalse($sourcePos);
        self::assertNotFalse($imgPos);
        self::assertLessThan($imgPos, $sourcePos, '<source> must appear before <img>');
    }

    // =========================================================================
    // Mutation-killing tests: filterEmptyAttributes boundary values
    // =========================================================================

    #[Test]
    public function filterEmptyAttributesRemovesZeroWidthAndHeight(): void
    {
        // Kills MatchArmRemoval, DecrementInteger, IncrementInteger, Identical on lines 258-260
        $method = new ReflectionMethod(SourceSetViewHelper::class, 'filterEmptyAttributes');

        $input = [
            'alt'    => '',
            'width'  => 0,
            'height' => 0,
            'class'  => 'test',
        ];

        $result = $method->invoke($this->viewHelper, $input);

        // width=0 and height=0 must be filtered out
        self::assertArrayNotHasKey('width', $result);
        self::assertArrayNotHasKey('height', $result);
        // alt='' must be preserved (accessibility)
        self::assertArrayHasKey('alt', $result);
        self::assertSame('', $result['alt']);
        // class must be preserved
        self::assertArrayHasKey('class', $result);
    }

    #[Test]
    public function filterEmptyAttributesKeepsNonZeroWidthAndHeight(): void
    {
        // Kills mutations changing the check value from 0 to 1 or -1
        $method = new ReflectionMethod(SourceSetViewHelper::class, 'filterEmptyAttributes');

        $input = [
            'width'  => 1,
            'height' => 1,
        ];

        $result = $method->invoke($this->viewHelper, $input);

        self::assertArrayHasKey('width', $result);
        self::assertSame(1, $result['width']);
        self::assertArrayHasKey('height', $result);
        self::assertSame(1, $result['height']);
    }

    #[Test]
    public function filterEmptyAttributesHandlesWidthAndHeightIndependently(): void
    {
        // Kills MatchArmRemoval that removes 'width' or 'height' from the match arm
        $method = new ReflectionMethod(SourceSetViewHelper::class, 'filterEmptyAttributes');

        // Test width=0 is filtered but height=100 is kept
        $result1 = $method->invoke($this->viewHelper, ['width' => 0, 'height' => 100]);
        self::assertArrayNotHasKey('width', $result1);
        self::assertArrayHasKey('height', $result1);

        // Test width=100 is kept but height=0 is filtered
        $result2 = $method->invoke($this->viewHelper, ['width' => 100, 'height' => 0]);
        self::assertArrayHasKey('width', $result2);
        self::assertArrayNotHasKey('height', $result2);
    }

    // =========================================================================
    // Mutation-killing tests: calculateVariantHeight rounding
    // =========================================================================

    #[Test]
    public function calculateVariantHeightUsesRoundNotFloorOrCeil(): void
    {
        // 7 * 0.49 = 3.43 → round=3, floor=3, ceil=4 → distinguishes round from ceil
        self::assertSame(3, $this->callMethod('calculateVariantHeight', 7, 0.49));

        // 3 * 0.5 = 1.5 → round=2, floor=1, ceil=2 → distinguishes round from floor
        self::assertSame(2, $this->callMethod('calculateVariantHeight', 3, 0.5));
    }

    #[Test]
    public function calculateVariantHeightReturnsZeroForZeroAspectRatioStrictly(): void
    {
        // Kills GreaterThan → GreaterThanOrEqual mutant on line 292
        self::assertSame(0, $this->callMethod('calculateVariantHeight', 640, 0.0));
        self::assertSame(0, $this->callMethod('calculateVariantHeight', 640, -0.1));
    }

    // =========================================================================
    // Mutation-killing tests: getWidthVariants non-numeric handling
    // =========================================================================

    #[Test]
    public function getWidthVariantsArrayNonNumericValuesMapToZero(): void
    {
        // Kills DecrementInteger (0 → -1) and IncrementInteger (0 → 1) on line 305
        $this->viewHelper->setArguments([
            'path'          => '/path/to/image.jpg',
            'widthVariants' => ['foo', 'bar'],
        ]);

        // All non-numeric → 0 → filtered → falls back to defaults
        self::assertSame([480, 576, 640, 768, 992, 1200, 1800], $this->callMethod('getWidthVariants'));
    }

    #[Test]
    public function getWidthVariantsStringTrimsWhitespace(): void
    {
        // Kills UnwrapArrayMap that removes trim() on line 308
        $this->viewHelper->setArguments([
            'path'          => '/path/to/image.jpg',
            'widthVariants' => ' 320 , 640 ',
        ]);

        self::assertSame([320, 640], $this->callMethod('getWidthVariants'));
    }

    // =========================================================================
    // Mutation-killing tests: getResourcePath defaults and logic
    // =========================================================================

    #[Test]
    public function getResourcePathDefaultWidthAndHeightAreZero(): void
    {
        // Kills IncrementInteger/DecrementInteger on default params (lines 360-361)
        $this->viewHelper->setArguments(['mode' => 'cover']);

        set_error_handler(static fn (): bool => true);

        try {
            $result = $this->viewHelper->getResourcePath('/nonexistent/defaults.jpg');
            self::assertStringContainsString('w0h0', $result);
        } finally {
            restore_error_handler();
        }
    }

    #[Test]
    public function getResourcePathWidthZeroHeightNonZeroDoesNotCallGetimagesize(): void
    {
        // Kills LogicalAnd → LogicalOr mutant on line 371 (&&  → ||)
        $this->viewHelper->setArguments(['mode' => 'cover']);

        $result = $this->viewHelper->getResourcePath('/path/to/image.jpg', 0, 50);

        self::assertStringContainsString('w0h50', $result);
    }

    #[Test]
    public function getResourcePathHeightZeroWidthNonZeroDoesNotCallGetimagesize(): void
    {
        // Also tests the LogicalAnd mutant from the other side
        $this->viewHelper->setArguments(['mode' => 'cover']);

        $result = $this->viewHelper->getResourcePath('/path/to/image.jpg', 50, 0);

        self::assertStringContainsString('w50h0', $result);
    }

    // =========================================================================
    // Mutation-killing tests: generateSrcSet dimension defaults
    // =========================================================================

    #[Test]
    public function generateSrcSetUsesZeroDefaultForMissingDimensions(): void
    {
        // Kills DecrementInteger/IncrementInteger on lines 432-433 (0 → -1/1)
        $this->viewHelper->setArguments([
            'path' => '/images/photo.jpg',
            'set'  => [
                480 => [], // No width or height
            ],
        ]);

        $result = $this->viewHelper->generateSrcSet();

        self::assertStringContainsString('w0h0', $result);
    }

    // =========================================================================
    // Mutation-killing tests: tag BitwiseOr (ENT_QUOTES | ENT_HTML5)
    // =========================================================================

    #[Test]
    public function tagEscapesSingleQuotesInAttributeKeys(): void
    {
        // Kills BitwiseOr mutants on lines 472, 478
        $this->viewHelper->setArguments([
            'attributes' => [
                "data-it's" => 'value',
            ],
        ]);

        $tagMethod = new ReflectionMethod(SourceSetViewHelper::class, 'tag');
        $result    = $tagMethod->invoke(
            $this->viewHelper,
            'img',
            ['src' => '/test.jpg'],
        );
        assert(is_string($result));

        self::assertStringNotContainsString("data-it's", $result);
        self::assertMatchesRegularExpression('/data-it(?:&#039;|&apos;)s/', $result);
    }

    #[Test]
    public function tagEscapesSingleQuotesInPropertyKeys(): void
    {
        // Kills BitwiseOr mutant on line 472 specifically (properties loop)
        $tagMethod = new ReflectionMethod(SourceSetViewHelper::class, 'tag');

        $this->viewHelper->setArguments([
            'attributes' => [],
        ]);

        $result = $tagMethod->invoke(
            $this->viewHelper,
            'source',
            ["it's" => 'val'],
        );
        assert(is_string($result));

        self::assertStringNotContainsString("it's", $result);
    }

    // =========================================================================
    // Mutation-killing tests: getArgWidth / getArgHeight non-numeric
    // =========================================================================

    #[Test]
    public function getArgWidthReturnsZeroForNonNumericInput(): void
    {
        // Kills DecrementInteger/IncrementInteger (0 → -1/1) on line 501
        $this->viewHelper->setArguments([
            'path'  => '/path/to/image.jpg',
            'width' => 'not-a-number',
        ]);

        self::assertSame(0, $this->callMethod('getArgWidth'));
    }

    #[Test]
    public function getArgHeightReturnsZeroForNonNumericInput(): void
    {
        // Kills DecrementInteger/IncrementInteger (0 → -1/1) on line 513
        $this->viewHelper->setArguments([
            'path'   => '/path/to/image.jpg',
            'height' => 'not-a-number',
        ]);

        self::assertSame(0, $this->callMethod('getArgHeight'));
    }

    // =========================================================================
    // Mutation-killing tests: getArgFetchpriority trim and early return
    // =========================================================================

    #[Test]
    public function getArgFetchpriorityTrimsWhitespace(): void
    {
        // Kills UnwrapTrim mutant on line 597
        $this->viewHelper->setArguments([
            'fetchpriority' => '  high  ',
        ]);

        self::assertSame('high', $this->callMethod('getArgFetchpriority'));
    }

    #[Test]
    public function getArgFetchpriorityEmptyAfterTrimReturnsEmpty(): void
    {
        // Kills ReturnRemoval mutant on line 600
        $this->viewHelper->setArguments([
            'fetchpriority' => '   ',
        ]);

        self::assertSame('', $this->callMethod('getArgFetchpriority'));
    }

    // =========================================================================
    // Mutation-killing tests: getStringArgument trim
    // =========================================================================

    #[Test]
    public function getStringArgumentTrimsValue(): void
    {
        // Kills UnwrapTrim mutant on line 622
        $this->viewHelper->setArguments([
            'alt' => '  trimmed  ',
        ]);

        self::assertSame('trimmed', $this->callMethod('getStringArgument', 'alt'));
    }

    // =========================================================================
    // Mutation-killing tests: getMimeTypeForPath strtolower
    // =========================================================================

    #[Test]
    public function getMimeTypeForPathIsCaseInsensitive(): void
    {
        // Kills UnwrapStrToLower mutant on line 635
        self::assertSame('image/webp', $this->callMethod('getMimeTypeForPath', '/images/photo.WEBP'));
        self::assertSame('image/avif', $this->callMethod('getMimeTypeForPath', '/images/photo.AVIF'));
    }

    // =========================================================================
    // Mutation-killing tests: SVG strtolower on line 393
    // =========================================================================

    #[Test]
    public function getResourcePathReturnsSvgPathUnchangedCaseInsensitive(): void
    {
        // Kills UnwrapStrToLower mutant on line 393
        $this->viewHelper->setArguments(['mode' => 'cover']);

        $result = $this->viewHelper->getResourcePath('/icons/logo.SVG', 320, 200, 75);
        self::assertSame('/icons/logo.SVG', $result);
    }

    // =========================================================================
    // Mutation-killing tests: aspectRatio boundaries (line 163)
    // =========================================================================

    #[Test]
    public function renderResponsiveSrcsetAspectRatioZeroWhenWidthZero(): void
    {
        // Kills GreaterThan→GreaterThanOrEqual on $width > 0 (line 163)
        // and LogicalAnd→LogicalOr and OneZeroFloat (0.0 → 1.0)
        $this->viewHelper->setArguments([
            'path'             => '/path/to/image.jpg',
            'width'            => 0,
            'height'           => 500,
            'responsiveSrcset' => true,
            'widthVariants'    => '320',
        ]);

        $result = $this->viewHelper->render();

        // width=0 means aspect ratio = 0.0, so variant heights should be 0
        self::assertStringContainsString('h0', $result);
    }

    #[Test]
    public function renderResponsiveSrcsetAspectRatioZeroWhenHeightZero(): void
    {
        // Kills GreaterThan→GreaterThanOrEqual on $height > 0 (line 163)
        $this->viewHelper->setArguments([
            'path'             => '/path/to/image.jpg',
            'width'            => 500,
            'height'           => 0,
            'responsiveSrcset' => true,
            'widthVariants'    => '320',
        ]);

        $result = $this->viewHelper->render();

        self::assertStringContainsString('w320h0', $result);
    }

    // =========================================================================
    // Mutation-killing tests: getimagesize index mutations (lines 381-382)
    // =========================================================================

    #[Test]
    public function getResourcePathUsesCorrectWidthAndHeightFromGetimagesize(): void
    {
        // Kills IncrementInteger on info[0] (line 381) and DecrementInteger on info[1] (line 382)
        $publicPath   = Environment::getPublicPath();
        $directory    = $publicPath . '/fileadmin/_nr_image_optimize_tests';
        $relativePath = '/fileadmin/_nr_image_optimize_tests/' . uniqid('rect_', true) . '.png';
        $absolutePath = $publicPath . $relativePath;

        if (!is_dir($directory)) {
            mkdir($directory, 0o777, true);
        }

        // Create a 2x1 pixel PNG to distinguish width from height (no GD needed)
        $imageBinary = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAIAAAABCAIAAAB7QOjdAAAAD0lEQVR4nGP4z8DAwPAfAAcAAf9+CLHQAAAAAElFTkSuQmCC',
            true,
        );
        self::assertNotFalse($imageBinary);
        file_put_contents($absolutePath, $imageBinary);

        $this->viewHelper->setArguments(['mode' => 'cover']);

        // Clear cache
        $cacheProperty = new ReflectionProperty(SourceSetViewHelper::class, 'imageSizeCache');
        $cacheProperty->setValue(null, []);

        $result = $this->viewHelper->getResourcePath($relativePath, 0, 0, 100);

        // Must contain w2h1 (width=2, height=1), not w1h1 or w2h2
        self::assertStringContainsString('w2h1', $result);

        unlink($absolutePath);
        @rmdir($directory);
    }

    // =========================================================================
    // Mutation-killing tests: exact picture tag output
    // =========================================================================

    #[Test]
    public function renderLegacyModeExactPictureWrap(): void
    {
        // Kills all wrapInPicture Concat mutants by checking exact start/end
        $this->viewHelper->setArguments([
            'path'   => '/path/to/image.jpg',
            'width'  => 100,
            'height' => 100,
        ]);

        $result = $this->viewHelper->render();

        self::assertStringStartsWith('<picture>' . PHP_EOL, $result);
        self::assertStringEndsWith('</picture>' . PHP_EOL, $result);
        self::assertStringContainsString('<picture>' . PHP_EOL . '<img ', $result);
    }

    // =========================================================================
    // Mutation-killing tests: generateSrcSet exact srcset format
    // =========================================================================

    #[Test]
    public function generateSrcSetExactSrcsetEntryFormat(): void
    {
        $this->viewHelper->setArguments([
            'path' => '/images/pic.jpg',
            'set'  => [
                640 => ['width' => 300, 'height' => 200],
            ],
        ]);

        $result = $this->viewHelper->generateSrcSet();

        $expectedSrcset = '/processed/images/pic.w300h200m0q100.jpg, /processed/images/pic.w600h400m0q100.jpg 2x';
        self::assertStringContainsString('srcset="' . $expectedSrcset . '"', $result);
    }

    // =========================================================================
    // Mutation-killing tests: FunctionCallRemoval trigger_error (line 384)
    // =========================================================================

    #[Test]
    public function getResourcePathTriggersNoticeOnMissingImage(): void
    {
        // Kills FunctionCallRemoval mutant on trigger_error (line 384)
        $noticeFired = false;

        set_error_handler(static function (int $errno, string $errstr) use (&$noticeFired): bool {
            if ($errno === E_USER_NOTICE && str_contains($errstr, 'getimagesize() failed')) {
                $noticeFired = true;

                return true;
            }

            return false;
        });

        // Clear image size cache
        $cacheProperty = new ReflectionProperty(SourceSetViewHelper::class, 'imageSizeCache');
        $cacheProperty->setValue(null, []);

        try {
            $this->viewHelper->setArguments(['mode' => 'cover']);
            $this->callMethod('getResourcePath', '/nonexistent/trigger-test.jpg', 0, 0);
            self::assertTrue($noticeFired, 'E_USER_NOTICE must be triggered for missing image.');
        } finally {
            restore_error_handler();
        }
    }

    // =========================================================================
    // Mutation-killing tests: CastFloat and CastInt mutations
    // =========================================================================

    #[Test]
    public function getArgWidthHandlesStringNumericInput(): void
    {
        // Kills CastFloat mutant on line 501
        $this->viewHelper->setArguments([
            'path'  => '/path/to/image.jpg',
            'width' => '320',
        ]);

        self::assertSame(320, $this->callMethod('getArgWidth'));
    }

    #[Test]
    public function getArgHeightHandlesStringNumericInput(): void
    {
        // Kills CastFloat mutant on line 513
        $this->viewHelper->setArguments([
            'path'   => '/path/to/image.jpg',
            'height' => '200',
        ]);

        self::assertSame(200, $this->callMethod('getArgHeight'));
    }

    // =========================================================================
    // Mutation-killing tests: UnwrapArrayMap / CastInt on line 305
    // =========================================================================

    #[Test]
    public function getWidthVariantsArrayCastsNonIntNumericValues(): void
    {
        // Kills UnwrapArrayMap on line 305 and CastInt mutant
        $this->viewHelper->setArguments([
            'path'          => '/path/to/image.jpg',
            'widthVariants' => [320.9, '640.1', 1024],
        ]);

        $result = $this->callMethod('getWidthVariants');

        // After (int) cast: 320, 640, 1024
        self::assertSame([320, 640, 1024], $result);
    }

    // =========================================================================
    // Mutation-killing tests: responsive mode with both sources and correct content
    // =========================================================================

    #[Test]
    public function renderResponsiveSrcsetIncludesSourcesFromSet(): void
    {
        // Kills ConcatOperandRemoval mutants that remove $sources from wrapInPicture
        $this->viewHelper->setArguments([
            'path'             => '/images/photo.jpg',
            'width'            => 800,
            'height'           => 600,
            'responsiveSrcset' => true,
            'set'              => [
                480 => ['width' => 400, 'height' => 300],
            ],
        ]);

        $result = $this->viewHelper->render();

        self::assertStringContainsString('<source', $result);
        self::assertStringContainsString('<img', $result);
    }

    // =========================================================================
    // Mutation-killing tests: sizes default value (line 128)
    // =========================================================================

    #[Test]
    public function initializeArgumentsRegistersSizesWithCorrectDefault(): void
    {
        $viewHelper = new SourceSetViewHelper();
        $viewHelper->initializeArguments();

        $reflection = new ReflectionProperty(AbstractViewHelper::class, 'argumentDefinitions');
        /** @var array<string, ArgumentDefinition> $definitions */
        $definitions = $reflection->getValue($viewHelper);

        self::assertSame('auto, (min-width: 992px) 991px, 100vw', $definitions['sizes']->getDefaultValue());
    }

    // =========================================================================
    // Mutation-killing tests: BitwiseOr on value escaping (line 473, 479)
    // =========================================================================

    #[Test]
    public function tagEscapesSingleQuotesInPropertyValues(): void
    {
        $tagMethod = new ReflectionMethod(SourceSetViewHelper::class, 'tag');

        $this->viewHelper->setArguments(['attributes' => []]);

        $result = $tagMethod->invoke(
            $this->viewHelper,
            'source',
            ['srcset' => "it's a test"],
        );
        assert(is_string($result));

        self::assertStringNotContainsString("it's", $result);
    }

    #[Test]
    public function tagEscapesSingleQuotesInAdditionalAttributeValues(): void
    {
        // Kills BitwiseOr mutant on line 479 (value escaping in getAttributes loop)
        $this->viewHelper->setArguments([
            'attributes' => [
                'data-label' => "it's special",
            ],
        ]);

        $tagMethod = new ReflectionMethod(SourceSetViewHelper::class, 'tag');
        $result    = $tagMethod->invoke(
            $this->viewHelper,
            'img',
            ['src' => '/test.jpg'],
        );
        assert(is_string($result));

        self::assertStringNotContainsString("it's", $result);
    }

    // =========================================================================
    // Mutation-killing tests: LogicalAnd → LogicalOr on line 371
    // =========================================================================

    #[Test]
    public function getResourcePathWithWidthSetAndHeightZeroPreservesDimensions(): void
    {
        // Kills LogicalAnd → LogicalOr mutant on line 371:
        //   Original: if ($width === 0 && $height === 0)
        //   Mutant:   if ($width === 0 || $height === 0)
        //
        // With the mutant, when width=100, height=0, the || would be true
        // and getimagesize() would overwrite dimensions with the real image size (2x1).
        // With the original, the condition is false so dimensions stay as 100x0.
        $publicPath   = Environment::getPublicPath();
        $directory    = $publicPath . '/fileadmin/_nr_image_optimize_tests';
        $relativePath = '/fileadmin/_nr_image_optimize_tests/' . uniqid('logical_', true) . '.png';
        $absolutePath = $publicPath . $relativePath;

        if (!is_dir($directory)) {
            mkdir($directory, 0o777, true);
        }

        // 2x1 pixel PNG
        $imageBinary = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAIAAAABCAIAAAB7QOjdAAAAD0lEQVR4nGP4z8DAwPAfAAcAAf9+CLHQAAAAAElFTkSuQmCC',
            true,
        );
        self::assertNotFalse($imageBinary);
        file_put_contents($absolutePath, $imageBinary);

        $this->viewHelper->setArguments(['mode' => 'cover']);

        // Clear the static image size cache
        $cacheProperty = new ReflectionProperty(SourceSetViewHelper::class, 'imageSizeCache');
        $cacheProperty->setValue(null, []);

        // width=100, height=0: original code does NOT enter getimagesize branch
        $result = $this->viewHelper->getResourcePath($relativePath, 100, 0, 100);

        // Must contain w100h0 — the original dimensions unchanged.
        // With the LogicalOr mutant, getimagesize would run and overwrite to w2h1.
        self::assertStringContainsString('w100h0', $result);
        self::assertStringNotContainsString('w2h1', $result);

        unlink($absolutePath);
        @rmdir($directory);
    }

    #[Test]
    public function getResourcePathWithHeightSetAndWidthZeroPreservesDimensions(): void
    {
        // Symmetric test for the LogicalAnd → LogicalOr mutant on line 371:
        // width=0, height=100 should NOT trigger getimagesize in the original code
        // but WOULD with the || mutant (because width === 0).
        $publicPath   = Environment::getPublicPath();
        $directory    = $publicPath . '/fileadmin/_nr_image_optimize_tests';
        $relativePath = '/fileadmin/_nr_image_optimize_tests/' . uniqid('logical2_', true) . '.png';
        $absolutePath = $publicPath . $relativePath;

        if (!is_dir($directory)) {
            mkdir($directory, 0o777, true);
        }

        // 2x1 pixel PNG
        $imageBinary = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAIAAAABCAIAAAB7QOjdAAAAD0lEQVR4nGP4z8DAwPAfAAcAAf9+CLHQAAAAAElFTkSuQmCC',
            true,
        );
        self::assertNotFalse($imageBinary);
        file_put_contents($absolutePath, $imageBinary);

        $this->viewHelper->setArguments(['mode' => 'cover']);

        // Clear the static image size cache
        $cacheProperty = new ReflectionProperty(SourceSetViewHelper::class, 'imageSizeCache');
        $cacheProperty->setValue(null, []);

        // width=0, height=100: original code does NOT enter getimagesize branch
        $result = $this->viewHelper->getResourcePath($relativePath, 0, 100, 100);

        // Must contain w0h100 — the original dimensions unchanged.
        // With the LogicalOr mutant, getimagesize would run and overwrite to w2h1.
        self::assertStringContainsString('w0h100', $result);
        self::assertStringNotContainsString('w2h1', $result);

        unlink($absolutePath);
        @rmdir($directory);
    }

    // =========================================================================
    // Mutation-killing tests: DecrementInteger non-numeric → -1 (line 305)
    // =========================================================================

    #[Test]
    public function getWidthVariantsArrayNonNumericDoesNotProduceNegativeWidths(): void
    {
        // Targets DecrementInteger mutant on line 305 (0 → -1 for non-numeric).
        // Both 0 and -1 are filtered by validateWidthVariants (> 0), so the mutant
        // is equivalent. This test documents that non-numeric values never produce
        // negative widths in the intermediate array before validation.
        $this->viewHelper->setArguments([
            'path'          => '/path/to/image.jpg',
            'widthVariants' => [500, 'invalid', 300],
        ]);

        $result = $this->callMethod('getWidthVariants');

        // Valid values are kept; non-numeric produces 0 (filtered), leaving [300, 500]
        self::assertSame([300, 500], $result);

        foreach ($result as $width) {
            self::assertGreaterThan(0, $width, 'All returned widths must be positive');
        }
    }
}
