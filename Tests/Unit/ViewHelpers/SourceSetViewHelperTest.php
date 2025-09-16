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

    #[Test]
    public function render(): void
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

        self::assertStringContainsString(
            'src="/processed/path/to/image.w100h100m0q100.jpg"',
            $result
        );
        self::assertStringContainsString(
            'data-src="/processed/path/to/image.w100h100m0q100.jpg"',
            $result
        );
        self::assertStringContainsString(
            'data-srcset="/processed/path/to/image.w200h200m0q100.jpg x2"',
            $result
        );
        self::assertStringContainsString(
            'srcset="/processed/path/to/image.w200h200m0q100.jpg x2"',
            $result
        );
        self::assertStringContainsString(
            'alt="Test Image"',
            $result
        );
        self::assertStringContainsString(
            'class="test-class"',
            $result
        );
    }

    #[Test]
    public function shouldUseLazyLoad(): void
    {
        $this->viewHelper->setArguments([
            'class' => 'lazyload',
        ]);
        self::assertTrue($this->viewHelper->useJsLazyLoad());

        $this->viewHelper->setArguments([
            'class' => '',
        ]);
        self::assertFalse($this->viewHelper->useJsLazyLoad());
    }

    #[Test]
    public function getResourcePath(): void
    {
        $path   = '/path/to/image.jpg';
        $width  = 100;
        $height = 100;

        $result = $this->viewHelper->getResourcePath(
            $path,
            $width,
            $height
        );

        self::assertEquals(
            '/processed/path/to/image.w100h100m0q100.jpg',
            $result
        );
    }

    #[Test]
    public function generateSrcSet(): void
    {
        $this->viewHelper->setArguments([
            'path' => '/path/to/image.jpg',
            'set'  => [
                767 => [
                    'width' => 360,
                ],
            ],
        ]);

        $result = $this->viewHelper->generateSrcSet();

        self::assertStringContainsString(
            'media="(max-width: 767px)"',
            $result
        );
        self::assertStringContainsString(
            'srcset="/processed/path/to/image.w360h0m0q100.jpg, /processed/path/to/image.w720h0m0q100.jpg x2"',
            $result
        );
    }
}
