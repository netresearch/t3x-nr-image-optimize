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

use function base64_decode;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function uniqid;
use function pathinfo;
use function rmdir;
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
        $reflection->setAccessible(true);

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

        // Test width-based srcset output (default variants)
        self::assertStringContainsString('480w', $result);
        self::assertStringContainsString('576w', $result);
        self::assertStringContainsString('640w', $result);
        self::assertStringContainsString('768w', $result);
        self::assertStringContainsString('992w', $result);
        self::assertStringContainsString('1200w', $result);
        self::assertStringContainsString('1800w', $result);
        self::assertStringNotContainsString('500w', $result);

        // Test sizes attribute default
        self::assertStringContainsString('sizes="auto, (min-width: 992px) 991px, 100vw"', $result);

        // Test base attributes
        self::assertStringContainsString('width="1250"', $result);
        self::assertStringContainsString('height="1250"', $result);
        self::assertStringContainsString('alt="Test Image"', $result);
        self::assertStringContainsString('class="test-class"', $result);
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

        // Ensure data-sizes is no longer emitted
        self::assertStringNotContainsString('data-sizes=', $result);
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
        self::assertStringContainsString('class="lazyload"', $result);
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
    }

    #[Test]
    public function aspectRatioIsPreserved(): void
    {
        $this->viewHelper->setArguments([
            'path'             => '/path/to/image.jpg',
            'width'            => 1000,
            'height'           => 500, // 2:1 aspect ratio
            'responsiveSrcset' => true,
        ]);

        $result = $this->viewHelper->render();

        // Test if aspect ratio is preserved in variants (for default widths)
        self::assertStringContainsString('w480h240', $result); // 480x240 maintains 2:1
        self::assertStringContainsString('w992h496', $result); // 992x496 maintains 2:1
        self::assertStringContainsString('w1800h900', $result); // 1800x900 maintains 2:1
    }

    #[Test]
    public function calculateVariantHeightUsesAspectRatio(): void
    {
        self::assertSame(400, $this->callMethod('calculateVariantHeight', 800, 0.5));
        self::assertSame(0, $this->callMethod('calculateVariantHeight', 800, 0.0));
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
        $publicPath = Environment::getPublicPath();
        $directory  = $publicPath . '/fileadmin/_nr_image_optimize_tests';
        $relativePath = '/fileadmin/_nr_image_optimize_tests/' . uniqid('sample_', true) . '.png';
        $absolutePath = $publicPath . $relativePath;

        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $imageBinary = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/woAAgMBgBZ20G8AAAAASUVORK5CYII=');
        file_put_contents($absolutePath, $imageBinary);

        $this->viewHelper->setArguments([
            'mode' => 'cover',
        ]);

        $result = $this->viewHelper->getResourcePath($relativePath, 0, 0, 92);

        $pathInfo = pathinfo($relativePath);
        $expected = sprintf(
            '/processed%s/%s.w1h1m0q92.%s',
            $pathInfo['dirname'],
            $pathInfo['filename'],
            $pathInfo['extension']
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
    public function useJsLazyLoadDetectsLazyloadClass(): void
    {
        $this->viewHelper->setArguments([
            'class' => 'img-responsive lazyload',
        ]);

        self::assertTrue($this->viewHelper->useJsLazyLoad());

        $this->viewHelper->setArguments([
            'class' => 'img-responsive',
        ]);

        self::assertFalse($this->viewHelper->useJsLazyLoad());
    }

    #[Test]
    public function tagMergesAdditionalAttributesAndHonorsNativeLazyLoading(): void
    {
        $this->viewHelper->setArguments([
            'attributes' => [
                'data-track' => 'hero',
                'aria-hidden' => 'true',
            ],
            'lazyload' => true,
        ]);

        $tagMethod = new ReflectionMethod(SourceSetViewHelper::class, 'tag');
        $tagMethod->setAccessible(true);

        $result = $tagMethod->invoke(
            $this->viewHelper,
            'img',
            [
                'src' => '/processed/example.jpg',
                'alt' => 'Example',
            ]
        );

        self::assertSame(
            '<img src="/processed/example.jpg" alt="Example" data-track="hero" aria-hidden="true" loading="lazy" />' . PHP_EOL,
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
            'fetchpriority' => 'invalid',
        ]);

        $resultInvalid = $this->viewHelper->render();
        self::assertStringNotContainsString('fetchpriority="', $resultInvalid);
    }

    #[Test]
    public function tagMergesAdditionalAttributesAndNativeLazyload(): void
    {
        $this->viewHelper->setArguments([
            'attributes' => [
                'data-role' => 'hero-image',
                'aria-hidden' => 'false',
            ],
            'lazyload' => true,
        ]);

        $result = $this->callMethod('tag', 'img', [
            'src' => '/processed/path/image.jpg',
            'alt' => 'Example',
        ]);

        self::assertStringStartsWith('<img ', $result);
        self::assertStringContainsString('src="/processed/path/image.jpg"', $result);
        self::assertStringContainsString('alt="Example"', $result);
        self::assertStringContainsString('data-role="hero-image"', $result);
        self::assertStringContainsString('aria-hidden="false"', $result);
        self::assertStringContainsString('loading="lazy"', $result);
        self::assertStringEndsWith(PHP_EOL, $result);
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
}
