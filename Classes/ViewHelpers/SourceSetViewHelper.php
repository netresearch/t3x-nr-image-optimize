<?php

/*
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\NrImageOptimize\ViewHelpers;

use function array_filter;
use function array_map;
use function array_unique;
use function explode;
use function floor;
use function getimagesize;
use function htmlspecialchars;
use function http_build_query;
use function implode;
use function is_array;
use function round;
use function sort;
use function sprintf;
use function str_contains;
use function strtolower;
use function trigger_error;
use function trim;

use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\PathUtility;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * Fluid ViewHelper that renders a responsive <picture> source set and <img> tag
 * for images processed by the on-the-fly processor. It generates URLs pointing
 * to the "/processed" endpoint, including width/height/mode/quality parameters
 * and optional flags to skip AVIF or WebP creation.
 *
 * Supports both native lazy loading and JS-based lazyload libraries by emitting
 * appropriate attributes (loading, data-src, data-srcset).
 *
 * @author  Axel Seemann <axel.seemann@netresearch.de>
 * @author  Rico Sonntag <rico.sonntag@netresearch.de>
 * @license Netresearch https://www.netresearch.de
 */
final class SourceSetViewHelper extends AbstractViewHelper
{
    /**
     * Default width variants if none are provided or all are invalid.
     *
     * @var list<int>
     */
    private const DEFAULT_WIDTH_VARIANTS = [480, 576, 640, 768, 992, 1200, 1800];

    /**
     * Default quality for generated image variants.
     */
    private const DEFAULT_QUALITY = 100;

    /**
     * Transparent 1x1 GIF used as placeholder for JS lazy-loaded images.
     */
    private const LAZY_LOAD_PLACEHOLDER = 'data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==';

    /**
     * Default sizes attribute for responsive images.
     */
    private const DEFAULT_SIZES = 'auto, (min-width: 992px) 991px, 100vw';

    /**
     * Retina density multiplier for legacy srcset generation.
     */
    private const RETINA_MULTIPLIER = 2;

    /**
     * MIME types for next-gen formats where the `type` attribute on `<source>`
     * provides genuine browser skip-signal value. Universally-supported formats
     * (JPEG, PNG, GIF) are omitted since every browser that supports `<picture>`
     * also supports those formats — adding `type` for them provides no benefit.
     *
     * @var array<string, string>
     */
    private const EXTENSION_MIME_MAP = [
        'webp' => 'image/webp',
        'avif' => 'image/avif',
    ];

    /**
     * Fluid internal: disable automatic output escaping as we output HTML tags.
     */
    protected $escapeOutput = false;

    /**
     * Fluid internal: do not escape child content since we assemble HTML ourselves.
     */
    protected $escapeChildren = false;

    /**
     * Cache for getimagesize() results to avoid repeated disk I/O for the same
     * image file during a single request cycle.
     *
     * @var array<string, array{0: int, 1: int}|false>
     */
    private static array $imageSizeCache = [];

    /**
     * Register and describe supported ViewHelper arguments.
     */
    public function initializeArguments(): void
    {
        parent::initializeArguments();

        $this->registerArgument('path', 'string', 'Public path to the source image (e.g. /fileadmin/foo.jpg).', true);
        $this->registerArgument('set', 'array', 'Responsive set: [maxWidth => [width=>int, height=>int]] to build <source> tags.', false, []);
        $this->registerArgument('width', 'int|float', 'Base width in px for the <img> (0 = auto from file).', false, 0);
        $this->registerArgument('height', 'int|float', 'Base height in px for the <img> (0 = auto, keeps ratio).', false, 0);
        $this->registerArgument('alt', 'string', 'Alternative text (accessibility). HTML-escaped.', false, '');
        $this->registerArgument('class', 'string', 'CSS classes for <img>; include "lazyload" to use JS lazy load.', false, '');
        $this->registerArgument('mode', 'string', "Resize mode: 'cover' (crop/fill) or 'fit' (scale inside).", false, 'cover');
        $this->registerArgument('title', 'string', 'Title attribute for the image. HTML-escaped.', false, '');
        $this->registerArgument('lazyload', 'bool', 'Add loading="lazy" (native lazy loading).', false, false);
        $this->registerArgument('attributes', 'array', 'Extra HTML attributes merged into the rendered tag.', false, []);
        $this->registerArgument('responsiveSrcset', 'bool', 'Enable width-based responsive srcset (default: false for backward compatibility).', false, false);
        $this->registerArgument('widthVariants', 'string|array', 'Width variants for responsive srcset (comma-separated string or array).', false);
        $this->registerArgument('sizes', 'string', 'Sizes attribute for responsive images.', false, self::DEFAULT_SIZES);
        $this->registerArgument('fetchpriority', 'string', "Resource fetch priority for the image: 'high', 'low', or 'auto'.", false, '');
    }

    /**
     * Render the responsive picture sources and the image tag.
     *
     * @return string HTML markup containing <source> elements and the <img>
     */
    public function render(): string
    {
        $width  = $this->getArgWidth();
        $height = $this->getArgHeight();

        if (($this->arguments['responsiveSrcset'] ?? false) === true) {
            return $this->renderResponsiveSrcset($width, $height);
        }

        return $this->renderLegacyDensitySrcset($width, $height);
    }

    /**
     * Render the responsive width-based srcset with sizes attribute.
     *
     * @param int $width  Base width in pixels
     * @param int $height Base height in pixels
     *
     * @return string HTML markup
     */
    private function renderResponsiveSrcset(int $width, int $height): string
    {
        $widthVariants = $this->getWidthVariants();
        $path          = $this->getArgPath();
        $jsLazy        = $this->useJsLazyLoad();

        $aspectRatio = ($width > 0 && $height > 0) ? $height / $width : 0.0;

        $srcsetEntries = [];

        foreach ($widthVariants as $variantWidth) {
            $variantHeight   = $this->calculateVariantHeight($variantWidth, $aspectRatio);
            $url             = $this->getResourcePath($path, $variantWidth, $variantHeight);
            $srcsetEntries[] = $url . ' ' . $variantWidth . 'w';
        }

        $srcSet  = implode(', ', $srcsetEntries);
        $srcPath = $this->getResourcePath($path, $width, $height);

        $sizes      = $this->arguments['sizes'] ?? self::DEFAULT_SIZES;
        $sizesValue = is_string($sizes) ? $sizes : self::DEFAULT_SIZES;

        $props          = $this->buildImageAttributes($srcPath, $srcSet, $width, $height, $jsLazy);
        $props['sizes'] = $sizesValue;

        if ($jsLazy) {
            $props['data-src']    = $srcPath;
            $props['data-srcset'] = $srcSet;
        }

        $sources = $this->generateSrcSet();
        $imgTag  = $this->tag('img', $this->filterEmptyAttributes($props));

        return $this->wrapInPicture($sources . $imgTag);
    }

    /**
     * Render the legacy density-based srcset (2x variant).
     *
     * @param int $width  Base width in pixels
     * @param int $height Base height in pixels
     *
     * @return string HTML markup
     */
    private function renderLegacyDensitySrcset(int $width, int $height): string
    {
        $path   = $this->getArgPath();
        $jsLazy = $this->useJsLazyLoad();

        $srcSet  = $this->getResourcePath($path, $width * self::RETINA_MULTIPLIER, $height * self::RETINA_MULTIPLIER) . ' 2x';
        $srcPath = $this->getResourcePath($path, $width, $height);

        $props = $this->buildImageAttributes($srcPath, $srcSet, $width, $height, $jsLazy);

        if ($jsLazy) {
            $props['data-src']    = $srcPath;
            $props['data-srcset'] = $srcSet;
        }

        $sources = $this->generateSrcSet();
        $imgTag  = $this->tag('img', $this->filterEmptyAttributes($props));

        return $this->wrapInPicture($sources . $imgTag);
    }

    /**
     * Build the common image tag attributes shared between responsive and legacy modes.
     *
     * @param string $srcPath Source URL for the img src attribute
     * @param string $srcSet  Srcset attribute value
     * @param int    $width   Image width in pixels
     * @param int    $height  Image height in pixels
     * @param bool   $jsLazy  Whether JS-based lazy loading is active
     *
     * @return array<string, int|string> Attribute map
     */
    private function buildImageAttributes(string $srcPath, string $srcSet, int $width, int $height, bool $jsLazy): array
    {
        return [
            'src'           => $jsLazy ? self::LAZY_LOAD_PLACEHOLDER : $srcPath,
            'srcset'        => $srcSet,
            'width'         => $width,
            'height'        => $height,
            'alt'           => $this->getStringArgument('alt'),
            'title'         => $this->getStringArgument('title'),
            'class'         => $this->getStringArgument('class'),
            'fetchpriority' => $this->getArgFetchpriority(),
        ];
    }

    /**
     * Filter out empty or null attribute values, preserving empty 'alt' for accessibility.
     *
     * @param array<string, int|string|null> $props Attribute map
     *
     * @return array<string, int|string|null> Filtered attribute map
     */
    private function filterEmptyAttributes(array $props): array
    {
        return array_filter(
            $props,
            static fn (int|string|null $value, string|int $key): bool => match (true) {
                $key === 'alt'                      => $value !== null,
                $key === 'width', $key === 'height' => !in_array($value, [null, '', 0], true),
                default                             => $value !== null && $value !== '',
            },
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * Wrap the given HTML content in a <picture> element.
     *
     * Per HTML spec, <source> elements are only valid inside <picture>, <audio>,
     * or <video>. This ensures valid markup when <source> tags are present.
     *
     * @param string $content Inner HTML (source elements + img tag)
     *
     * @return string HTML wrapped in <picture>...</picture>
     */
    private function wrapInPicture(string $content): string
    {
        return '<picture>' . PHP_EOL . $content . '</picture>' . PHP_EOL;
    }

    /**
     * Calculate the height for a variant width while maintaining aspect ratio.
     *
     * @param int   $variantWidth Target width for the variant
     * @param float $aspectRatio  Height-to-width ratio (0 if not constrained)
     *
     * @return int Calculated height or 0 if no aspect ratio constraint
     */
    private function calculateVariantHeight(int $variantWidth, float $aspectRatio): int
    {
        return $aspectRatio > 0 ? (int) round($variantWidth * $aspectRatio) : 0;
    }

    /**
     * Get width variants as an array of integers, validated and sorted.
     *
     * @return list<int>
     */
    private function getWidthVariants(): array
    {
        $variants = $this->arguments['widthVariants'] ?? self::DEFAULT_WIDTH_VARIANTS;

        if (is_array($variants)) {
            $widths = array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $variants);
        } else {
            $variantString = is_string($variants) ? $variants : '';
            $widths        = array_map(intval(...), array_map(trim(...), explode(',', $variantString)));
        }

        $widths = $this->validateWidthVariants($widths);
        sort($widths);

        return $widths;
    }

    /**
     * Validate width variants and remove invalid values.
     *
     * @param array<int> $widths Raw width values
     *
     * @return array<int> Validated widths or defaults if none are valid
     */
    private function validateWidthVariants(array $widths): array
    {
        $validWidths = array_unique(array_filter($widths, static fn (int $width): bool => $width > 0));

        return $validWidths === [] ? self::DEFAULT_WIDTH_VARIANTS : $validWidths;
    }

    /**
     * Determine whether JS-based lazy loading is requested via class name.
     *
     * @return bool True if 'lazyload' is present in the class attribute
     */
    private function useJsLazyLoad(): bool
    {
        $class = $this->arguments['class'] ?? '';

        return is_string($class) && str_contains($class, 'lazyload');
    }

    /**
     * Build a processed image URL for the given path and parameters.
     *
     * Uses a static cache for getimagesize() results to avoid repeated disk I/O
     * when the same source image is used for multiple variants in a single page render.
     *
     * @param string $path     Public path to the original image (e.g., /fileadmin/..)
     * @param int    $width    Target width (0 keeps original)
     * @param int    $height   Target height (0 keeps original)
     * @param int    $quality  Target quality (1-100)
     * @param bool   $skipAvif Whether to suppress AVIF generation for this URL
     * @param bool   $skipWebP Whether to suppress WebP generation for this URL
     *
     * @return string URL under /processed/... including variant configuration and optional query string
     */
    public function getResourcePath(
        string $path,
        int $width = 0,
        int $height = 0,
        int $quality = self::DEFAULT_QUALITY,
        bool $skipAvif = false,
        bool $skipWebP = false,
    ): string {
        // Reject path traversal attempts
        if (str_contains($path, '..')) {
            return $path;
        }

        if ($width === 0 && $height === 0) {
            // Use static cache to avoid repeated getimagesize() disk I/O
            // for the same source image across multiple variant URLs.
            if (!array_key_exists($path, self::$imageSizeCache)) {
                self::$imageSizeCache[$path] = @getimagesize(Environment::getPublicPath() . $path);
            }

            $info = self::$imageSizeCache[$path];

            if ($info !== false) {
                $width  = $info[0];
                $height = $info[1];
            } else {
                trigger_error(
                    sprintf('getimagesize() failed for "%s"', $path),
                    E_USER_NOTICE,
                );
            }
        }

        $pathInfo = PathUtility::pathinfo($path);

        if (array_key_exists('extension', $pathInfo) && strtolower($pathInfo['extension']) === 'svg') {
            return $path;
        }

        $generatorConfig = 'w' . $width . 'h' . $height . 'm' . $this->getArgMode() . 'q' . $quality;

        $url = sprintf(
            '/processed%s/%s.%s.%s',
            $pathInfo['dirname'] ?? '',
            $pathInfo['filename'] ?? '',
            $generatorConfig,
            $pathInfo['extension'] ?? '',
        );

        $queryArgs = array_filter([
            'skipWebP' => $skipWebP,
            'skipAvif' => $skipAvif,
        ]);

        if ($queryArgs === []) {
            return $url;
        }

        return $url . '?' . http_build_query($queryArgs);
    }

    /**
     * Generate <source> elements for a responsive <picture> based on the provided set argument.
     *
     * @return string HTML markup containing one <source> per breakpoint with normal and x2 candidates
     */
    public function generateSrcSet(): string
    {
        $return   = '';
        $path     = $this->getArgPath();
        $jsLazy   = $this->useJsLazyLoad();
        $mimeType = $this->getMimeTypeForPath($path);

        foreach ($this->getArgSet() as $maxWidth => $dimensions) {
            $dimensionWidth  = $dimensions['width'] ?? 0;
            $dimensionHeight = $dimensions['height'] ?? 0;
            $srcSet          = sprintf(
                '%s, %s 2x',
                $this->getResourcePath($path, $dimensionWidth, $dimensionHeight),
                $this->getResourcePath($path, $dimensionWidth * self::RETINA_MULTIPLIER, $dimensionHeight * self::RETINA_MULTIPLIER),
            );

            $sourceProps = [
                'media'  => '(max-width: ' . $maxWidth . 'px)',
                'srcset' => $srcSet,
            ];

            if ($mimeType !== '') {
                $sourceProps['type'] = $mimeType;
            }

            if ($jsLazy) {
                $sourceProps['data-srcset'] = $srcSet;
            }

            $return .= $this->tag('source', $sourceProps);
        }

        return $return;
    }

    /**
     * Render a self-closing HTML tag with given attributes and global attributes/lazyload applied.
     *
     * @param string                                    $tag        Tag name (e.g., 'img' or 'source')
     * @param array<string, int|string|float|bool|null> $properties Attribute map to render into the tag
     *
     * @return string The HTML string ending with a newline
     */
    private function tag(string $tag, array $properties): string
    {
        $tagString = '<' . $tag;

        foreach ($properties as $key => $value) {
            $tagString .= ' ' . htmlspecialchars($key, ENT_QUOTES | ENT_HTML5)
                . '="' . htmlspecialchars((string) $value, ENT_QUOTES | ENT_HTML5) . '"';
        }

        if ($tag === 'img') {
            foreach ($this->getAttributes() as $key => $value) {
                $tagString .= ' ' . htmlspecialchars($key, ENT_QUOTES | ENT_HTML5)
                    . '="' . htmlspecialchars((string) $value, ENT_QUOTES | ENT_HTML5) . '"';
            }
        }

        if ($tag === 'img' && $this->useNativeLazyLoad()) {
            $tagString .= ' loading="lazy"';
        }

        $tagString .= ' />';

        return $tagString . PHP_EOL;
    }

    /**
     * Resolve width argument as integer pixels.
     *
     * @return int Width in pixels (0 means auto)
     */
    private function getArgWidth(): int
    {
        $width = $this->arguments['width'] ?? 0;

        return (int) floor(is_numeric($width) ? (float) $width : 0);
    }

    /**
     * Resolve height argument as integer pixels.
     *
     * @return int Height in pixels (0 means auto)
     */
    private function getArgHeight(): int
    {
        $height = $this->arguments['height'] ?? 0;

        return (int) floor(is_numeric($height) ? (float) $height : 0);
    }

    /**
     * Get the original image path argument.
     *
     * @return string Public path to the source image
     */
    private function getArgPath(): string
    {
        $path = $this->arguments['path'] ?? '';

        return is_string($path) ? $path : '';
    }

    /**
     * Return the responsive set definition used to build <source> elements.
     *
     * @return array<array-key, array<string, int>> Map of max-width => ['width'=>int, 'height'=>int]
     */
    private function getArgSet(): array
    {
        $set = $this->arguments['set'] ?? [];

        if (!is_array($set)) {
            return [];
        }

        /** @var array<array-key, array<string, int>> $set */
        return $set;
    }

    /**
     * Map the semantic mode argument to a numeric processing mode used by Processor.
     * 0 = cover (default), 1 = fit (scale).
     *
     * @return int Processing mode identifier
     */
    private function getArgMode(): int
    {
        $mode = $this->arguments['mode'] ?? 'cover';

        return match ($mode) {
            'fit'   => 1,
            default => 0,
        };
    }

    /**
     * Additional attributes to append to the rendered tag(s).
     *
     * @return array<string, string|int|float|bool> Key/value pairs merged into the HTML element
     */
    private function getAttributes(): array
    {
        if (!array_key_exists('attributes', $this->arguments)
            || !is_array($this->arguments['attributes'])
        ) {
            return [];
        }

        /** @var array<string, bool|float|int|string> $attributes */
        $attributes = $this->arguments['attributes'];

        return $attributes;
    }

    /**
     * Whether to add the native loading="lazy" attribute to the rendered tag.
     *
     * @return bool True if native lazy loading is enabled
     */
    private function useNativeLazyLoad(): bool
    {
        return (bool) ($this->arguments['lazyload'] ?? false);
    }

    /**
     * Validate and return the fetchpriority attribute value.
     * Allowed values: 'high', 'low', 'auto'. Any other value returns empty string (attribute omitted).
     *
     * @return string Valid fetchpriority value or empty string
     */
    private function getArgFetchpriority(): string
    {
        $raw   = $this->arguments['fetchpriority'] ?? '';
        $value = is_string($raw) ? trim($raw) : '';

        if ($value === '') {
            return '';
        }

        $value = strtolower($value);

        return match ($value) {
            'high', 'low', 'auto' => $value,
            default               => '',
        };
    }

    /**
     * Get a trimmed string value from a ViewHelper argument.
     *
     * @param string $name Argument name
     *
     * @return string Trimmed value or empty string
     */
    private function getStringArgument(string $name): string
    {
        $value = $this->arguments[$name] ?? '';

        return is_string($value) ? trim($value) : '';
    }

    /**
     * Resolve the MIME type for a given image path based on its file extension.
     *
     * @param string $path Public path to the source image
     *
     * @return string MIME type string (e.g. 'image/jpeg') or empty string if unknown
     */
    private function getMimeTypeForPath(string $path): string
    {
        $pathInfo  = PathUtility::pathinfo($path);
        $extension = strtolower($pathInfo['extension'] ?? '');

        return self::EXTENSION_MIME_MAP[$extension] ?? '';
    }
}
