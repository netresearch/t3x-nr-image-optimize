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

class SourceSetViewHelper extends AbstractViewHelper
{
    protected $escapeOutput = false;

    protected $escapeChildren = false;

    public function initializeArguments(): void
    {
        parent::initializeArguments();

        $this->registerArgument('path', 'string', 'Path to the original image', true);
        $this->registerArgument('set', 'array', 'Array of image sizes', false, []);
        $this->registerArgument('width', 'int|float', 'Width of the original image', false, 0);
        $this->registerArgument('height', 'int|float', 'Height of the original image', false, 0);
        $this->registerArgument('alt', 'string', 'Alt text for the image', false, '');
        $this->registerArgument('class', 'string', 'Class for the image', false, '');
        $this->registerArgument('mode', 'string', 'Mode for the image', false, 'cover');
        $this->registerArgument('title', 'string', 'Title for the image', false, '');
        $this->registerArgument('lazyload', 'bool', 'Use lazyload', false, false);
        $this->registerArgument('attributes', 'array', 'Additional attributes', false, []);
    }

    public function render(): string
    {
        $this->escapeOutput   = false;
        $this->escapeChildren = false;

        $width  = $this->getArgWidth();
        $height = $this->getArgHeight();

        $srcSet  = $this->getResourcePath($this->getArgPath(), $width * 2, $height * 2) . ' x2';
        $srcPath = $this->getResourcePath($this->getArgPath(), $width, $height);

        $props = [
            'src'         => $this->useJsLazyLoad() ? 'data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==' : $srcPath,
            'data-src'    => $srcPath,
            'data-srcset' => $srcSet,
            'srcset'      => $srcSet,
            'width'       => $width,
            'height'      => $height,
            'alt'         => trim(htmlentities($this->arguments['alt'] ?? '')),
            'title'       => trim(htmlentities($this->arguments['title'] ?? '')),
            'class'       => trim($this->arguments['class'] ?? ''),
        ];

        return $this->generateSrcSet() . $this->tag('img', array_filter($props));
    }

    public function useJsLazyLoad(): bool
    {
        return str_contains($this->arguments['class'] ?? '', 'lazyload');
    }

    public function getResourcePath(string $path, int $width = 0, int $height = 0, int $quality = 100, bool $skipAvif = false, bool $skipWebP = false): string
    {
        if ($width === 0 && $height === 0) {
            $info   = getimagesize(Environment::getPublicPath() . $path);
            $width  = $info[0];
            $height = $info[1];
        }

        $args = [
            'w' . $width,
            'h' . $height,
            'm' . $this->getArgMode(),
            'q' . $quality,
        ];

        $pathInfo = PathUtility::pathinfo($path);

        if ($pathInfo['extension'] === 'svg') {
            return $path;
        }

        $generatorConfig = implode('', $args);

        if ($generatorConfig === '') {
            return $path;
        }

        $url = sprintf(
            '/processed%s/%s.%s.%s',
            $pathInfo['dirname'],
            $pathInfo['filename'],
            $generatorConfig,
            $pathInfo['extension']
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

    public function generateSrcSet(): string
    {
        $return = '';
        foreach ($this->getArgSet() as $maxWidth => $dimensions) {
            $props          = [];
            $props['media'] = '(max-width: ' . $maxWidth . 'px)';
            $srcSet         = sprintf(
                '%s, %s x2',
                $this->getResourcePath($this->getArgPath(), $dimensions['width'], $dimensions['height'] ?? 0),
                $this->getResourcePath($this->getArgPath(), $dimensions['width'] * 2, $dimensions['height'] ?? 0 * 2)
            );
            $props['srcset']      = $srcSet;
            $props['data-srcset'] = $srcSet;

            $return .= $this->tag('source', $props);
        }

        return $return;
    }

    /**
     * @param string        $tag
     * @param array<string> $properties
     *
     * @return string
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

    private function getArgWidth(): int
    {
        return (int) floor($this->arguments['width'] ?? 0);
    }

    private function getArgHeight(): int
    {
        return (int) floor($this->arguments['height'] ?? 0);
    }

    private function getArgPath(): string
    {
        return $this->arguments['path'];
    }

    /**
     * @return array<array<int>>
     */
    private function getArgSet(): array
    {
        return $this->arguments['set'] ?? [];
    }

    private function getArgMode(): int
    {
        return match ($this->arguments['mode']) {
            'fit'   => 1,
            default => 0,
        };
    }

    /**
     * @return array<string|int|float|bool>
     */
    private function getAttributes(): array
    {
        if (empty($this->arguments['attributes'])) {
            return [];
        }

        return $this->arguments['attributes'];
    }

    private function useNativeLazyLoad(): bool
    {
        return (bool) $this->arguments['lazyload'];
    }
}
