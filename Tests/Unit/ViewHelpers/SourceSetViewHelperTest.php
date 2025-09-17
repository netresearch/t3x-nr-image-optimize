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

        // Test width-based srcset output
        self::assertStringContainsString('500w', $result);
        self::assertStringContainsString('1000w', $result);
        self::assertStringContainsString('1500w', $result);
        self::assertStringContainsString('2500w', $result);

        // Test sizes attribute with default breakpoints
        self::assertStringContainsString('sizes="(max-width: 576px) 100vw, (max-width: 768px) 50vw', $result);

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

        // Also test data-sizes for lazy loading
        self::assertStringContainsString('data-sizes="(max-width: 640px) 100vw, (max-width: 1024px) 75vw, 50vw"', $result);
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
        self::assertStringContainsString('data-sizes=', $result);
        self::assertStringContainsString('class="lazyload"', $result);
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

        // Test if aspect ratio is preserved in variants
        self::assertStringContainsString('w500h250', $result); // 500x250 maintains 2:1
        self::assertStringContainsString('w1000h500', $result); // 1000x500 maintains 2:1
        self::assertStringContainsString('w1500h750', $result); // 1500x750 maintains 2:1
    }
}
