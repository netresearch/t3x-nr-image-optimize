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

namespace Netresearch\NrImageOptimize\Tests\Acceptance\ViewHelpers;

use DOMDocument;
use DOMElement;
use Netresearch\NrImageOptimize\Service\VariantUrlSigner;
use Netresearch\NrImageOptimize\ViewHelpers\SourceSetViewHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Crypto\HashService;

use function array_unique;
use function explode;
use function implode;
use function parse_str;
use function parse_url;
use function str_starts_with;
use function sys_get_temp_dir;
use function trim;

/**
 * Acceptance tests that verify the rendered HTML output of the SourceSetViewHelper.
 *
 * These tests parse the actual HTML output with DOMDocument to assert correct
 * element structure, attribute presence, and attribute values -- simulating what
 * a browser would receive.
 */
#[CoversClass(SourceSetViewHelper::class)]
#[UsesClass(VariantUrlSigner::class)]
final class SourceSetViewHelperHtmlOutputTest extends TestCase
{
    private SourceSetViewHelper $viewHelper;

    protected function setUp(): void
    {
        parent::setUp();

        $tempDir = sys_get_temp_dir() . '/nr-image-optimize-acceptance';

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

        $this->viewHelper = new SourceSetViewHelper(new VariantUrlSigner(new HashService()));
        $this->viewHelper->initializeArguments();
    }

    /**
     * Parse HTML fragment into a DOMDocument for structured assertions.
     */
    private function parseHtml(string $html): DOMDocument
    {
        $doc = new DOMDocument();
        $doc->loadHTML('<html><body>' . $html . '</body></html>', LIBXML_NOERROR | LIBXML_HTML_NOIMPLIED);

        return $doc;
    }

    /**
     * Get the first element matching a tag name from parsed HTML.
     */
    private function getFirstElement(DOMDocument $doc, string $tagName): ?DOMElement
    {
        $elements = $doc->getElementsByTagName($tagName);

        if ($elements->length === 0) {
            return null;
        }

        $item = $elements->item(0);

        return $item instanceof DOMElement ? $item : null;
    }

    // ──────────────────────────────────────────────────
    // <img> tag attribute tests
    // ──────────────────────────────────────────────────

    #[Test]
    public function imgTagHasCorrectSrcAttribute(): void
    {
        $this->viewHelper->setArguments([
            'path'   => '/fileadmin/hero.jpg',
            'width'  => 800,
            'height' => 600,
            'mode'   => 'cover',
        ]);

        $html = $this->viewHelper->render();
        $doc  = $this->parseHtml($html);
        $img  = $this->getFirstElement($doc, 'img');

        self::assertNotNull($img, 'Expected an <img> tag in the output.');
        self::assertSame(
            '/processed/fileadmin/hero.w800h600m0q75.jpg',
            $img->getAttribute('src'),
        );
    }

    #[Test]
    public function imgTagHasCorrectSrcsetInLegacyMode(): void
    {
        $this->viewHelper->setArguments([
            'path'   => '/fileadmin/hero.jpg',
            'width'  => 400,
            'height' => 300,
            'mode'   => 'cover',
        ]);

        $html = $this->viewHelper->render();
        $doc  = $this->parseHtml($html);
        $img  = $this->getFirstElement($doc, 'img');

        self::assertNotNull($img);

        $srcset = $img->getAttribute('srcset');
        self::assertStringContainsString('/processed/fileadmin/hero.w800h600m0q75.jpg 2x', $srcset);
    }

    #[Test]
    public function imgTagHasCorrectWidthAndHeightAttributes(): void
    {
        $this->viewHelper->setArguments([
            'path'   => '/fileadmin/photo.jpg',
            'width'  => 1024,
            'height' => 768,
        ]);

        $html = $this->viewHelper->render();
        $doc  = $this->parseHtml($html);
        $img  = $this->getFirstElement($doc, 'img');

        self::assertNotNull($img);
        self::assertSame('1024', $img->getAttribute('width'));
        self::assertSame('768', $img->getAttribute('height'));
    }

    #[Test]
    public function imgTagHasCorrectSizesInResponsiveMode(): void
    {
        $this->viewHelper->setArguments([
            'path'             => '/fileadmin/banner.jpg',
            'width'            => 1200,
            'height'           => 400,
            'responsiveSrcset' => true,
            'sizes'            => '(max-width: 768px) 100vw, 50vw',
        ]);

        $html = $this->viewHelper->render();
        $doc  = $this->parseHtml($html);
        $img  = $this->getFirstElement($doc, 'img');

        self::assertNotNull($img);
        self::assertSame('(max-width: 768px) 100vw, 50vw', $img->getAttribute('sizes'));
    }

    #[Test]
    public function responsiveSrcsetContainsWidthDescriptors(): void
    {
        $this->viewHelper->setArguments([
            'path'             => '/fileadmin/banner.jpg',
            'width'            => 1200,
            'height'           => 400,
            'responsiveSrcset' => true,
            'widthVariants'    => '480,768,1200',
        ]);

        $html = $this->viewHelper->render();
        $doc  = $this->parseHtml($html);
        $img  = $this->getFirstElement($doc, 'img');

        self::assertNotNull($img);

        $srcset = $img->getAttribute('srcset');
        self::assertStringContainsString('480w', $srcset);
        self::assertStringContainsString('768w', $srcset);
        self::assertStringContainsString('1200w', $srcset);
    }

    // ──────────────────────────────────────────────────
    // <source> element tests for responsive breakpoints
    // ──────────────────────────────────────────────────

    #[Test]
    public function sourceElementsGeneratedForBreakpoints(): void
    {
        $this->viewHelper->setArguments([
            'path'   => '/fileadmin/product.jpg',
            'width'  => 600,
            'height' => 400,
            'set'    => [
                480 => ['width' => 300, 'height' => 200],
                768 => ['width' => 500, 'height' => 333],
            ],
        ]);

        $html = $this->viewHelper->render();
        $doc  = $this->parseHtml($html);

        $sources = $doc->getElementsByTagName('source');
        self::assertSame(2, $sources->length, 'Expected 2 <source> elements for 2 breakpoints.');
    }

    #[Test]
    public function sourceElementHasCorrectMediaQuery(): void
    {
        $this->viewHelper->setArguments([
            'path'   => '/fileadmin/product.jpg',
            'width'  => 600,
            'height' => 400,
            'set'    => [
                480 => ['width' => 300, 'height' => 200],
            ],
        ]);

        $html = $this->viewHelper->render();
        $doc  = $this->parseHtml($html);

        $source = $this->getFirstElement($doc, 'source');
        self::assertNotNull($source);
        self::assertSame('(max-width: 480px)', $source->getAttribute('media'));
    }

    #[Test]
    public function sourceElementSrcsetContains1xAnd2xCandidates(): void
    {
        $this->viewHelper->setArguments([
            'path'   => '/fileadmin/product.jpg',
            'width'  => 600,
            'height' => 400,
            'set'    => [
                480 => ['width' => 300, 'height' => 200],
            ],
        ]);

        $html = $this->viewHelper->render();
        $doc  = $this->parseHtml($html);

        $source = $this->getFirstElement($doc, 'source');
        self::assertNotNull($source);

        $srcset = $source->getAttribute('srcset');
        // 1x candidate
        self::assertStringContainsString('/processed/fileadmin/product.w300h200m0q75.jpg', $srcset);
        // 2x candidate
        self::assertStringContainsString('/processed/fileadmin/product.w600h400m0q75.jpg 2x', $srcset);
    }

    /**
     * For universally-supported formats (JPEG, PNG, GIF) the view helper
     * deliberately omits the `type` attribute — every browser that supports
     * `<picture>` also supports those formats, so the type hint would only
     * add markup noise without giving the browser any skip-signal.
     *
     * See `SourceSetViewHelper::EXTENSION_MIME_MAP` for the intentional
     * format whitelist (webp, avif only).
     */
    #[Test]
    public function sourceElementHasNoTypeAttributeForJpeg(): void
    {
        $this->viewHelper->setArguments([
            'path'   => '/fileadmin/product.jpg',
            'width'  => 600,
            'height' => 400,
            'set'    => [
                480 => ['width' => 300, 'height' => 200],
            ],
        ]);

        $html = $this->viewHelper->render();
        $doc  = $this->parseHtml($html);

        $source = $this->getFirstElement($doc, 'source');
        self::assertNotNull($source);
        self::assertFalse(
            $source->hasAttribute('type'),
            'source elements for JPEG must not carry a type attribute (universally supported format)',
        );
    }

    #[Test]
    public function sourceElementHasNoTypeAttributeForPng(): void
    {
        $this->viewHelper->setArguments([
            'path'   => '/fileadmin/product.png',
            'width'  => 600,
            'height' => 400,
            'set'    => [
                480 => ['width' => 300, 'height' => 200],
            ],
        ]);

        $html = $this->viewHelper->render();
        $doc  = $this->parseHtml($html);

        $source = $this->getFirstElement($doc, 'source');
        self::assertNotNull($source);
        self::assertFalse(
            $source->hasAttribute('type'),
            'source elements for PNG must not carry a type attribute (universally supported format)',
        );
    }

    #[Test]
    public function sourceElementHasNoTypeAttributeForGif(): void
    {
        $this->viewHelper->setArguments([
            'path'   => '/fileadmin/animation.gif',
            'width'  => 600,
            'height' => 400,
            'set'    => [
                480 => ['width' => 300, 'height' => 200],
            ],
        ]);

        $html = $this->viewHelper->render();
        $doc  = $this->parseHtml($html);

        $source = $this->getFirstElement($doc, 'source');
        self::assertNotNull($source);
        self::assertFalse(
            $source->hasAttribute('type'),
            'source elements for GIF must not carry a type attribute (universally supported format)',
        );
    }

    /**
     * For next-gen formats (webp, avif) the type attribute lets the browser
     * skip downloading a `<source>` it can't decode, so the view helper
     * emits it explicitly.
     */
    #[Test]
    public function sourceElementHasCorrectTypeForWebp(): void
    {
        $this->viewHelper->setArguments([
            'path'   => '/fileadmin/photo.webp',
            'width'  => 600,
            'height' => 400,
            'set'    => [
                480 => ['width' => 300, 'height' => 200],
            ],
        ]);

        $html = $this->viewHelper->render();
        $doc  = $this->parseHtml($html);

        $source = $this->getFirstElement($doc, 'source');
        self::assertNotNull($source);
        self::assertSame('image/webp', $source->getAttribute('type'));
    }

    #[Test]
    public function sourceElementHasCorrectTypeForAvif(): void
    {
        $this->viewHelper->setArguments([
            'path'   => '/fileadmin/photo.avif',
            'width'  => 600,
            'height' => 400,
            'set'    => [
                480 => ['width' => 300, 'height' => 200],
            ],
        ]);

        $html = $this->viewHelper->render();
        $doc  = $this->parseHtml($html);

        $source = $this->getFirstElement($doc, 'source');
        self::assertNotNull($source);
        self::assertSame('image/avif', $source->getAttribute('type'));
    }

    // ──────────────────────────────────────────────────
    // Lazy loading attribute tests
    // ──────────────────────────────────────────────────

    #[Test]
    public function nativeLazyLoadAddsLoadingAttribute(): void
    {
        $this->viewHelper->setArguments([
            'path'     => '/fileadmin/hero.jpg',
            'width'    => 800,
            'height'   => 600,
            'lazyload' => true,
        ]);

        $html = $this->viewHelper->render();

        self::assertStringContainsString('loading="lazy"', $html);
    }

    #[Test]
    public function jsLazyLoadSetsPlaceholderSrcAndDataAttributes(): void
    {
        $this->viewHelper->setArguments([
            'path'   => '/fileadmin/hero.jpg',
            'width'  => 800,
            'height' => 600,
            'class'  => 'lazyload',
        ]);

        $html = $this->viewHelper->render();
        $doc  = $this->parseHtml($html);
        $img  = $this->getFirstElement($doc, 'img');

        self::assertNotNull($img);

        // src should be transparent placeholder
        self::assertStringStartsWith('data:image/gif;base64,', $img->getAttribute('src'));

        // data-src should contain the real URL
        self::assertStringContainsString('/processed/fileadmin/hero', $img->getAttribute('data-src'));

        // data-srcset should be present
        self::assertNotEmpty($img->getAttribute('data-srcset'));
    }

    #[Test]
    public function jsLazyLoadOnSourceElementsAddsDataSrcset(): void
    {
        $this->viewHelper->setArguments([
            'path'   => '/fileadmin/hero.jpg',
            'width'  => 800,
            'height' => 600,
            'class'  => 'lazyload',
            'set'    => [
                480 => ['width' => 300, 'height' => 200],
            ],
        ]);

        $html = $this->viewHelper->render();
        $doc  = $this->parseHtml($html);

        $source = $this->getFirstElement($doc, 'source');
        self::assertNotNull($source);
        self::assertNotEmpty($source->getAttribute('data-srcset'));
    }

    #[Test]
    public function noLazyLoadAttributesWhenDisabled(): void
    {
        $this->viewHelper->setArguments([
            'path'     => '/fileadmin/hero.jpg',
            'width'    => 800,
            'height'   => 600,
            'class'    => 'hero-image',
            'lazyload' => false,
        ]);

        $html = $this->viewHelper->render();

        self::assertStringNotContainsString('loading="lazy"', $html);
        self::assertStringNotContainsString('data-src=', $html);
        self::assertStringNotContainsString('data-srcset=', $html);
    }

    // ──────────────────────────────────────────────────
    // fetchpriority attribute tests
    // ──────────────────────────────────────────────────

    #[Test]
    #[DataProvider('fetchpriorityRenderProvider')]
    public function fetchpriorityAttributeRenderedCorrectly(string $input, ?string $expected): void
    {
        $this->viewHelper->setArguments([
            'path'          => '/fileadmin/hero.jpg',
            'width'         => 800,
            'height'        => 600,
            'fetchpriority' => $input,
        ]);

        $html = $this->viewHelper->render();

        if ($expected !== null) {
            self::assertStringContainsString('fetchpriority="' . $expected . '"', $html);
        } else {
            self::assertStringNotContainsString('fetchpriority=', $html);
        }
    }

    /**
     * @return iterable<string, array{0: string, 1: string|null}>
     */
    public static function fetchpriorityRenderProvider(): iterable
    {
        yield 'high renders as high' => ['high', 'high'];
        yield 'low renders as low' => ['low', 'low'];
        yield 'auto renders as auto' => ['auto', 'auto'];
        yield 'empty omits attribute' => ['', null];
        yield 'invalid omits attribute' => ['urgent', null];
    }

    // ──────────────────────────────────────────────────
    // SVG passthrough tests
    // ──────────────────────────────────────────────────

    #[Test]
    public function svgPathIsNotProcessed(): void
    {
        $this->viewHelper->setArguments([
            'mode' => 'cover',
        ]);

        $result = $this->viewHelper->getResourcePath('/icons/logo.svg', 320, 200, 75);

        self::assertSame('/icons/logo.svg', $result);
        self::assertStringNotContainsString('/processed/', $result);
    }

    #[Test]
    public function svgWithUppercaseExtensionIsNotProcessed(): void
    {
        $this->viewHelper->setArguments([
            'mode' => 'cover',
        ]);

        $result = $this->viewHelper->getResourcePath('/icons/logo.SVG', 320, 200, 75);

        self::assertSame('/icons/logo.SVG', $result);
        self::assertStringNotContainsString('/processed/', $result);
    }

    // ──────────────────────────────────────────────────
    // HTML escaping tests
    // ──────────────────────────────────────────────────

    #[Test]
    public function altAttributeIsHtmlEscaped(): void
    {
        $this->viewHelper->setArguments([
            'path'   => '/fileadmin/photo.jpg',
            'width'  => 400,
            'height' => 300,
            'alt'    => 'Photo "with" <special> & chars',
        ]);

        $html = $this->viewHelper->render();

        // Verify the raw HTML contains properly escaped values
        self::assertStringContainsString('alt="Photo &quot;with&quot; &lt;special&gt; &amp; chars"', $html);
    }

    #[Test]
    public function titleAttributeIsHtmlEscaped(): void
    {
        $this->viewHelper->setArguments([
            'path'   => '/fileadmin/photo.jpg',
            'width'  => 400,
            'height' => 300,
            'title'  => 'Title "with" <html> & entities',
        ]);

        $html = $this->viewHelper->render();

        self::assertStringContainsString('title="Title &quot;with&quot; &lt;html&gt; &amp; entities"', $html);
    }

    #[Test]
    public function emptyAltAttributeIsPreservedForAccessibility(): void
    {
        $this->viewHelper->setArguments([
            'path'   => '/fileadmin/decorative.jpg',
            'width'  => 200,
            'height' => 200,
            'alt'    => '',
        ]);

        $html = $this->viewHelper->render();

        // Empty alt must still be present for accessibility (decorative images)
        self::assertStringContainsString('alt=""', $html);
    }

    // ──────────────────────────────────────────────────
    // Complete HTML structure validation
    // ──────────────────────────────────────────────────

    #[Test]
    public function legacyModeOutputIsWellFormedHtml(): void
    {
        $this->viewHelper->setArguments([
            'path'   => '/fileadmin/photo.jpg',
            'width'  => 800,
            'height' => 600,
            'alt'    => 'A photo',
            'class'  => 'img-fluid',
            'set'    => [
                480 => ['width' => 400, 'height' => 300],
            ],
        ]);

        $html = $this->viewHelper->render();
        $doc  = $this->parseHtml($html);

        // Verify structure: source elements come before img
        $sources = $doc->getElementsByTagName('source');
        $imgs    = $doc->getElementsByTagName('img');

        self::assertSame(1, $sources->length, 'Expected 1 <source> element.');
        self::assertSame(1, $imgs->length, 'Expected 1 <img> element.');
    }

    #[Test]
    public function responsiveModeOutputIsWellFormedHtml(): void
    {
        $this->viewHelper->setArguments([
            'path'             => '/fileadmin/banner.jpg',
            'width'            => 1200,
            'height'           => 400,
            'alt'              => 'Banner',
            'responsiveSrcset' => true,
            'widthVariants'    => '480,768,1200',
        ]);

        $html = $this->viewHelper->render();
        $doc  = $this->parseHtml($html);

        $imgs = $doc->getElementsByTagName('img');
        self::assertSame(1, $imgs->length, 'Expected exactly 1 <img> element in responsive mode.');

        $img = $imgs->item(0);
        self::assertInstanceOf(DOMElement::class, $img);

        // All required attributes present
        self::assertTrue($img->hasAttribute('src'), 'img must have src');
        self::assertTrue($img->hasAttribute('srcset'), 'img must have srcset');
        self::assertTrue($img->hasAttribute('sizes'), 'img must have sizes');
        self::assertTrue($img->hasAttribute('width'), 'img must have width');
        self::assertTrue($img->hasAttribute('height'), 'img must have height');
        self::assertTrue($img->hasAttribute('alt'), 'img must have alt');
    }

    #[Test]
    public function customAttributesMergedIntoOutput(): void
    {
        $this->viewHelper->setArguments([
            'path'       => '/fileadmin/photo.jpg',
            'width'      => 400,
            'height'     => 300,
            'attributes' => [
                'data-analytics' => 'hero-image',
                'role'           => 'presentation',
            ],
        ]);

        $html = $this->viewHelper->render();
        $doc  = $this->parseHtml($html);
        $img  = $this->getFirstElement($doc, 'img');

        self::assertNotNull($img);
        self::assertSame('hero-image', $img->getAttribute('data-analytics'));
        self::assertSame('presentation', $img->getAttribute('role'));
    }

    #[Test]
    public function fitModeUsesCorrectModeValueInUrls(): void
    {
        $this->viewHelper->setArguments([
            'path'   => '/fileadmin/photo.jpg',
            'width'  => 400,
            'height' => 300,
            'mode'   => 'fit',
        ]);

        $html = $this->viewHelper->render();

        // fit mode = m1 in URL
        self::assertStringContainsString('m1', $html);
        self::assertStringNotContainsString('m0', $html);
    }

    #[Test]
    public function combinedNativeAndJsLazyLoadAttributes(): void
    {
        $this->viewHelper->setArguments([
            'path'     => '/fileadmin/hero.jpg',
            'width'    => 1200,
            'height'   => 800,
            'class'    => 'lazyload img-fluid',
            'lazyload' => true,
        ]);

        $html = $this->viewHelper->render();

        // Both native and JS lazy loading simultaneously
        self::assertStringContainsString('loading="lazy"', $html);
        self::assertStringContainsString('data-src=', $html);
        self::assertStringContainsString('data-srcset=', $html);
        self::assertStringContainsString('class="lazyload img-fluid"', $html);
    }

    // ──────────────────────────────────────────────────
    // skipWebP / skipAvif arguments
    // ──────────────────────────────────────────────────

    /**
     * Every rendering path that emits /processed/ URLs, with the number of
     * candidate URLs it must produce: legacy density srcset, responsive width
     * variants, <source> sets, and the JS lazy-load data attributes.
     *
     * @return iterable<string, array{array<string, mixed>, int}>
     */
    public static function skipFormatRenderModeProvider(): iterable
    {
        yield 'legacy density srcset' => [
            ['width' => 400, 'height' => 300],
            2,
        ];

        yield 'responsive width variants' => [
            ['width' => 800, 'height' => 600, 'responsiveSrcset' => true, 'widthVariants' => '320,640,960'],
            4,
        ];

        yield 'source sets with legacy img' => [
            [
                // 700x500 keeps the <img> candidates distinct from the
                // <source> 2x candidates, so all six URLs are counted.
                'width'  => 700,
                'height' => 500,
                'set'    => [
                    480 => ['width' => 300, 'height' => 200],
                    768 => ['width' => 500, 'height' => 333],
                ],
            ],
            6,
        ];

        yield 'js lazy load with source sets and width variants' => [
            [
                'width'            => 800,
                'height'           => 600,
                'class'            => 'lazyload',
                'responsiveSrcset' => true,
                'widthVariants'    => '320,640',
                'set'              => [480 => ['width' => 300, 'height' => 200]],
            ],
            5,
        ];
    }

    /**
     * @return iterable<string, array{bool, bool}>
     */
    public static function skipFormatFlagProvider(): iterable
    {
        yield 'skipAvif only' => [false, true];
        yield 'skipWebP only' => [true, false];
        yield 'both' => [true, true];
    }

    /**
     * @return iterable<string, array{array<string, mixed>, int, bool, bool}>
     */
    public static function skipFormatProvider(): iterable
    {
        foreach (self::skipFormatRenderModeProvider() as $modeName => [$arguments, $expectedUrls]) {
            foreach (self::skipFormatFlagProvider() as $flagName => [$skipWebP, $skipAvif]) {
                yield $modeName . ', ' . $flagName => [$arguments, $expectedUrls, $skipWebP, $skipAvif];
            }
        }
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[Test]
    #[DataProvider('skipFormatProvider')]
    public function skipFormatArgumentsAreCarriedByEveryCandidateUrl(
        array $arguments,
        int $expectedUrls,
        bool $skipWebP,
        bool $skipAvif,
    ): void {
        $this->viewHelper->setArguments([
            'path'     => '/fileadmin/hero.jpg',
            'skipWebP' => $skipWebP,
            'skipAvif' => $skipAvif,
        ] + $arguments);

        $urls = $this->collectCandidateUrls($this->viewHelper->render());

        self::assertCount($expectedUrls, array_unique($urls), 'Unexpected number of distinct candidate URLs: ' . implode(' | ', $urls));

        foreach ($urls as $url) {
            $query = $this->parseQuery($url);

            self::assertSame($skipWebP ? '1' : null, $query['skipWebP'] ?? null, 'skipWebP flag on ' . $url);
            self::assertSame($skipAvif ? '1' : null, $query['skipAvif'] ?? null, 'skipAvif flag on ' . $url);
        }
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[Test]
    #[DataProvider('skipFormatRenderModeProvider')]
    public function noSkipFlagIsEmittedByDefault(array $arguments, int $expectedUrls): void
    {
        $this->viewHelper->setArguments(['path' => '/fileadmin/hero.jpg'] + $arguments);

        $urls = $this->collectCandidateUrls($this->viewHelper->render());

        self::assertCount($expectedUrls, array_unique($urls));

        foreach ($urls as $url) {
            $query = $this->parseQuery($url);

            self::assertArrayNotHasKey('skipWebP', $query, $url);
            self::assertArrayNotHasKey('skipAvif', $query, $url);
        }
    }

    /**
     * Collect every /processed/ candidate URL from src, srcset, data-src and
     * data-srcset of all <img> and <source> elements in the rendered markup.
     *
     * @return list<string>
     */
    private function collectCandidateUrls(string $html): array
    {
        $doc  = $this->parseHtml($html);
        $urls = [];

        foreach (['img', 'source'] as $tagName) {
            foreach ($doc->getElementsByTagName($tagName) as $element) {
                foreach (['src', 'srcset', 'data-src', 'data-srcset'] as $attribute) {
                    foreach (explode(',', $element->getAttribute($attribute)) as $candidate) {
                        $url = explode(' ', trim($candidate))[0];

                        if (str_starts_with($url, '/processed/')) {
                            $urls[] = $url;
                        }
                    }
                }
            }
        }

        return $urls;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function parseQuery(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query;
    }
}
