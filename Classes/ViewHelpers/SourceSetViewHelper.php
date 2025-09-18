<?php

/**
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\NrImageOptimize\ViewHelpers;

use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\PathUtility;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

use function array_filter;
use function array_unique;
use function explode;
use function floor;
use function getimagesize;
use function htmlentities;
use function http_build_query;
use function implode;
use function is_array;
use function round;
use function sort;
use function sprintf;
use function str_contains;
use function trim;

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
 * @link    https://www.netresearch.de
 */
class SourceSetViewHelper extends AbstractViewHelper
{
    /**
     * Default width variants if none are provided or all are invalid.
     *
     * @var array<int>
     */
    private const DEFAULT_WIDTH_VARIANTS = [500, 1000, 1500, 2500];

    /**
     * Fluid internal: disable automatic output escaping as we output HTML tags.
     *
     * @var bool
     */
    protected $escapeOutput = false;

    /**
     * Fluid internal: do not escape child content since we assemble HTML ourselves.
     *
     * @var bool
     */
    protected $escapeChildren = false;

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

        // New arguments for responsive srcset
        $this->registerArgument('responsiveSrcset', 'bool', 'Enable width-based responsive srcset (default: false for backward compatibility).', false, false);
        $this->registerArgument('widthVariants', 'string|array', 'Width variants for responsive srcset (comma-separated string or array).', false, null);
        $this->registerArgument('sizes', 'string', 'Sizes attribute for responsive images.', false, '(max-width: 576px) 100vw, (max-width: 768px) 50vw, 33vw');
        $this->registerArgument('fetchpriority', 'string', "Resource fetch priority for the image: 'high', 'low', or 'auto'.", false, '');
    }

    /**
     * Render the responsive picture sources and the image tag.
     *
     * @return string HTML markup containing <source> elements and the <img>
     */
    public function render(): string
    {
        $this->escapeOutput   = false;
        $this->escapeChildren = false;

        $width  = $this->getArgWidth();
        $height = $this->getArgHeight();

        // Check if responsive srcset is enabled
        if (($this->arguments['responsiveSrcset'] ?? false) === true) {
            return $this->renderResponsiveSrcset($width, $height);
        }

        // Legacy behavior: 2x density variant
        return $this->renderLegacyDensitySrcset($width, $height);
    }

    /**
     * Render the new responsive width-based srcset with sizes attribute.
     */
    private function renderResponsiveSrcset(int $width, int $height): string
    {
        // Get width variants
        $widthVariants = $this->getWidthVariants();

        // Calculate aspect ratio if height is provided
        $aspectRatio = ($width > 0 && $height > 0) ? $height / $width : 0;

        // Generate srcset entries
        $srcsetEntries = [];
        foreach ($widthVariants as $variantWidth) {
            $variantHeight   = $this->calculateVariantHeight($variantWidth, $aspectRatio);
            $url             = $this->getResourcePath($this->getArgPath(), $variantWidth, $variantHeight);
            $srcsetEntries[] = $url . ' ' . $variantWidth . 'w';
        }

        $srcSet = implode(', ', $srcsetEntries);

        // Use the original requested width for the src attribute
        $srcPath = $this->getResourcePath($this->getArgPath(), $width, $height);

        // Resolve sizes with safe default if not provided via setArguments()
        $defaultSizes = '(max-width: 576px) 100vw, (max-width: 768px) 50vw, 33vw';
        $sizesValue   = $this->arguments['sizes'] ?? $defaultSizes;

        $props = [
            'src'           => $this->useJsLazyLoad() ? 'data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==' : $srcPath,
            'srcset'        => $srcSet,
            'sizes'         => $sizesValue,
            'width'         => $width,
            'height'        => $height,
            'alt'           => trim(htmlentities($this->arguments['alt'] ?? '')),
            'title'         => trim(htmlentities($this->arguments['title'] ?? '')),
            'class'         => trim($this->arguments['class'] ?? ''),
            'fetchpriority' => $this->getArgFetchpriority(),
        ];

        if ($this->useJsLazyLoad()) {
            $props['data-src']    = $srcPath;
            $props['data-srcset'] = $srcSet;
        }

        return $this->generateSrcSet()
            . $this->tag(
                'img',
                array_filter(
                    $props,
                    static fn (int|string|null $value): bool => ($value !== null) && ($value !== '')
                )
            );
    }

    /**
     * Render the legacy density-based srcset (2x variant).
     */
    private function renderLegacyDensitySrcset(int $width, int $height): string
    {
        // Provide a higher pixel-density (DPR 2) candidate via srcset "x2"
        $srcSet  = $this->getResourcePath($this->getArgPath(), $width * 2, $height * 2) . ' x2';
        $srcPath = $this->getResourcePath($this->getArgPath(), $width, $height);

        $props = [
            'src'           => $this->useJsLazyLoad() ? 'data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==' : $srcPath,
            'data-src'      => $srcPath,
            'data-srcset'   => $srcSet,
            'srcset'        => $srcSet,
            'width'         => $width,
            'height'        => $height,
            'alt'           => trim(htmlentities($this->arguments['alt'] ?? '')),
            'title'         => trim(htmlentities($this->arguments['title'] ?? '')),
            'class'         => trim($this->arguments['class'] ?? ''),
            'fetchpriority' => $this->getArgFetchpriority(),
        ];

        return $this->generateSrcSet()
            . $this->tag(
                'img',
                array_filter(
                    $props,
                    static fn (int|string|null $value): bool => ($value !== null) && ($value !== '')
                )
            );
    }

    /**
     * Calculate the height for a variant width while maintaining aspect ratio.
     */
    private function calculateVariantHeight(int $variantWidth, float $aspectRatio): int
    {
        return $aspectRatio > 0 ? (int) round($variantWidth * $aspectRatio) : 0;
    }

    /**
     * Get width variants as an array of integers, validated and sorted.
     *
     * @return array<int>
     */
    private function getWidthVariants(): array
    {
        $variants = $this->arguments['widthVariants'] ?? self::DEFAULT_WIDTH_VARIANTS;

        if (is_array($variants)) {
            $widths = array_map('intval', $variants);
        } else {
            $widths = array_map('intval', array_map('trim', explode(',', (string) $variants)));
        }

        // Remove duplicates, invalid widths, and sort
        $widths = $this->validateWidthVariants($widths);
        sort($widths);

        return $widths;
    }

    /**
     * Validate width variants and remove invalid values.
     *
     * @param array<int> $widths
     *
     * @return array<int>
     */
    private function validateWidthVariants(array $widths): array
    {
        // Remove duplicates and invalid widths
        $validWidths = array_unique(array_filter($widths, fn (int $width): bool => $width > 0));

        // Return default widths if no valid widths are provided
        return $validWidths === [] ? self::DEFAULT_WIDTH_VARIANTS : $validWidths;
    }

    /**
     * Determine whether JS-based lazy loading is requested via class name.
     */
    public function useJsLazyLoad(): bool
    {
        return str_contains($this->arguments['class'] ?? '', 'lazyload');
    }

    /**
     * Build a processed image URL for the given path and parameters.
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
        int $quality = 100,
        bool $skipAvif = false,
        bool $skipWebP = false,
    ): string {
        if ($width === 0 && $height === 0) {
            $info = getimagesize(Environment::getPublicPath() . $path);

            if ($info !== false) {
                $width  = $info[0];
                $height = $info[1];
            }
        }

        $args = [
            'w' . $width,
            'h' . $height,
            'm' . $this->getArgMode(),
            'q' . $quality,
        ];

        $pathInfo = PathUtility::pathinfo($path);

        if (isset($pathInfo['extension']) && ($pathInfo['extension'] === 'svg')) {
            return $path;
        }

        $generatorConfig = implode('', $args);

        $url = sprintf(
            '/processed%s/%s.%s.%s',
            $pathInfo['dirname'] ?? '',
            $pathInfo['filename'] ?? '',
            $generatorConfig,
            $pathInfo['extension'] ?? ''
        );

        $queryArgs = [
            'skipWebP' => $skipWebP,
            'skipAvif' => $skipAvif,
        ];

        $queryArgs = array_filter($queryArgs);

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
        $return = '';
        foreach ($this->getArgSet() as $maxWidth => $dimensions) {
            $props          = [];
            $props['media'] = '(max-width: ' . $maxWidth . 'px)';
            $srcSet         = sprintf(
                '%s, %s x2',
                $this->getResourcePath($this->getArgPath(), $dimensions['width'], $dimensions['height'] ?? 0),
                $this->getResourcePath($this->getArgPath(), $dimensions['width'] * 2, ($dimensions['height'] ?? 0) * 2)
            );
            $props['srcset']      = $srcSet;
            $props['data-srcset'] = $srcSet;

            $return .= $this->tag('source', $props);
        }

        return $return;
    }

    /**
     * Render a self-closing HTML tag with given attributes and global attributes/lazyload applied.
     *
     * @param string                               $tag        Tag name (e.g., 'img' or 'source')
     * @param array<string, int|string|float|bool> $properties Attribute map to render into the tag
     *
     * @return string The HTML string ending with a newline
     */
    private function tag(string $tag, array $properties): string
    {
        $tagString = '<' . $tag;
        foreach ($properties as $key => $value) {
            $tagString .= ' ' . $key . '="' . $value . '"';
        }

        foreach ($this->getAttributes() as $key => $value) {
            $tagString .= ' ' . $key . '="' . $value . '"';
        }

        if ($this->useNativeLazyLoad()) {
            $tagString .= ' loading="lazy"';
        }

        $tagString .= ' />';

        return $tagString . PHP_EOL;
    }

    /**
     * Resolve width argument as integer pixels.
     *
     * @return int
     */
    private function getArgWidth(): int
    {
        return (int) floor($this->arguments['width'] ?? 0);
    }

    /**
     * Resolve height argument as integer pixels.
     *
     * @return int
     */
    private function getArgHeight(): int
    {
        return (int) floor($this->arguments['height'] ?? 0);
    }

    /**
     * Get the original image path argument.
     *
     * @return string
     */
    private function getArgPath(): string
    {
        return $this->arguments['path'];
    }

    /**
     * Return the responsive set definition used to build <source> elements.
     *
     * @return array<array-key, array<string, int>> Map of max-width => ['width'=>int, 'height'=>int]
     */
    private function getArgSet(): array
    {
        return $this->arguments['set'] ?? [];
    }

    /**
     * Map the semantic mode argument to a numeric processing mode used by Processor.
     * 0 = cover (default), 1 = fit (scale).
     *
     * @return int
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
     * @return array<string|int|float|bool> Key/value pairs merged into the HTML element
     */
    private function getAttributes(): array
    {
        if (!isset($this->arguments['attributes'])
            || !is_array($this->arguments['attributes'])
        ) {
            return [];
        }

        return $this->arguments['attributes'];
    }

    /**
     * Whether to add the native loading="lazy" attribute to the rendered tag.
     *
     * @return bool
     */
    private function useNativeLazyLoad(): bool
    {
        return (bool) ($this->arguments['lazyload'] ?? false);
    }

    /**
     * Validate and return the fetchpriority attribute value.
     * Allowed values: 'high', 'low', 'auto'. Any other value returns empty string (attribute omitted).
     */
    private function getArgFetchpriority(): string
    {
        $value = trim((string) ($this->arguments['fetchpriority'] ?? ''));
        if ($value === '') {
            return '';
        }

        $value = strtolower($value);

        return match ($value) {
            'high', 'low', 'auto' => $value,
            default => '',
        };
    }
}
