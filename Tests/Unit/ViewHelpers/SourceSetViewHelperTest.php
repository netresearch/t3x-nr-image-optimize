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
use PHPUnit\Framework\TestCase;

/**
 * @covers \Netresearch\NrImageOptimize\ViewHelpers\SourceSetViewHelper
 */
class SourceSetViewHelperTest extends TestCase
{
    private SourceSetViewHelper $viewHelper;

    protected function setUp(): void
    {
        $this->viewHelper = new SourceSetViewHelper();
        $this->viewHelper->initializeArguments();
    }

    public function testRender(): void
    {
        $this->viewHelper->setArguments([
            'path'   => '/path/to/image.jpg',
            'width'  => 100,
            'height' => 100,
            'alt'    => 'Test Image',
            'class'  => 'test-class',
            'set'    => [767 => ['width' => 360]],
        ]);

        $result = $this->viewHelper->render();

        $this->assertStringContainsString('src="/processed/path/to/image.w100h100m0q100.jpg"', $result);
        $this->assertStringContainsString('data-src="/processed/path/to/image.w100h100m0q100.jpg"', $result);
        $this->assertStringContainsString('data-srcset="/processed/path/to/image.w200h200m0q100.jpg x2"', $result);
        $this->assertStringContainsString('srcset="/processed/path/to/image.w200h200m0q100.jpg x2"', $result);
        $this->assertStringContainsString('alt="Test Image"', $result);
        $this->assertStringContainsString('class="test-class"', $result);
    }

    public function testShouldUseLazyLoad(): void
    {
        $this->viewHelper->setArguments(['class' => 'lazyload']);
        $this->assertTrue($this->viewHelper->shouldUseLazyLoad());

        $this->viewHelper->setArguments(['class' => '']);
        $this->assertFalse($this->viewHelper->shouldUseLazyLoad());
    }

    public function testGetResourcePath(): void
    {
        $path   = '/path/to/image.jpg';
        $width  = 100;
        $height = 100;

        $result = $this->viewHelper->getResourcePath($path, $width, $height);

        $this->assertEquals('/processed/path/to/image.w100h100m0q100.jpg', $result);
    }

    public function testGenerateSrcSet(): void
    {
        $this->viewHelper->setArguments([
            'path' => '/path/to/image.jpg',
            'set'  => [767 => ['width' => 360]],
        ]);

        $result = $this->viewHelper->generateSrcSet();

        $this->assertStringContainsString('media="(max-width: 767px)"', $result);
        $this->assertStringContainsString('srcset="/processed/path/to/image.w360h0m0q100.jpg, /processed/path/to/image.w720h0m0q100.jpg x2"', $result);
    }
}
