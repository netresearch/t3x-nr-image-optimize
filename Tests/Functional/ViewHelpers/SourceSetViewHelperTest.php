<?php

/*
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\NrImageOptimize\Tests\Functional\ViewHelpers;

use Netresearch\NrImageOptimize\ViewHelpers\SourceSetViewHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Fluid\View\StandaloneView;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Functional tests for SourceSetViewHelper rendering with actual Fluid templates.
 */
#[CoversClass(SourceSetViewHelper::class)]
final class SourceSetViewHelperTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'netresearch/nr-image-optimize',
    ];

    protected array $pathsToProvideInTestInstance = [
        'typo3conf/ext/nr_image_optimize/Tests/Functional/Fixtures/test-image.png' => 'fileadmin/test-image.png',
    ];

    #[Test]
    public function viewHelperRendersImgTagWithSrcset(): void
    {
        $view = $this->createView();
        $view->setTemplateSource(
            '{namespace nrio=Netresearch\NrImageOptimize\ViewHelpers}'
            . '<nrio:sourceSet path="/fileadmin/test-image.png" width="100" height="75" alt="Test" />',
        );

        $output = $view->render();

        self::assertStringContainsString('<img', $output);
        self::assertStringContainsString('srcset=', $output);
        self::assertStringContainsString('alt="Test"', $output);
        self::assertStringContainsString('/processed/', $output);
    }

    #[Test]
    public function viewHelperRendersResponsiveSrcsetWithWidthVariants(): void
    {
        $view = $this->createView();
        $view->setTemplateSource(
            '{namespace nrio=Netresearch\NrImageOptimize\ViewHelpers}'
            . '<nrio:sourceSet path="/fileadmin/test-image.png" width="800" height="600"'
            . ' responsiveSrcset="1" widthVariants="320,640,960" alt="Responsive" />',
        );

        $output = $view->render();

        self::assertStringContainsString('<img', $output);
        self::assertStringContainsString('320w', $output);
        self::assertStringContainsString('640w', $output);
        self::assertStringContainsString('960w', $output);
        self::assertStringContainsString('sizes=', $output);
    }

    #[Test]
    public function viewHelperAppliesLazyLoadPlaceholder(): void
    {
        $view = $this->createView();
        $view->setTemplateSource(
            '{namespace nrio=Netresearch\NrImageOptimize\ViewHelpers}'
            . '<nrio:sourceSet path="/fileadmin/test-image.png" width="100" height="75"'
            . ' class="lazyload" alt="" />',
        );

        $output = $view->render();

        self::assertStringContainsString('data:image/gif;base64,', $output);
        self::assertStringContainsString('data-src=', $output);
        self::assertStringContainsString('data-srcset=', $output);
    }

    #[Test]
    public function viewHelperAppliesNativeLazyLoading(): void
    {
        $view = $this->createView();
        $view->setTemplateSource(
            '{namespace nrio=Netresearch\NrImageOptimize\ViewHelpers}'
            . '<nrio:sourceSet path="/fileadmin/test-image.png" width="100" height="75"'
            . ' lazyload="1" alt="" />',
        );

        $output = $view->render();

        self::assertStringContainsString('loading="lazy"', $output);
    }

    #[Test]
    public function viewHelperRendersFitMode(): void
    {
        $view = $this->createView();
        $view->setTemplateSource(
            '{namespace nrio=Netresearch\NrImageOptimize\ViewHelpers}'
            . '<nrio:sourceSet path="/fileadmin/test-image.png" width="100" height="75"'
            . ' mode="fit" alt="" />',
        );

        $output = $view->render();

        self::assertStringContainsString('m1', $output, 'Fit mode should produce mode=1 in URL');
    }

    #[Test]
    public function viewHelperRendersSourceTagsWithSet(): void
    {
        $view = $this->createView();
        $view->setTemplateSource(
            '{namespace nrio=Netresearch\NrImageOptimize\ViewHelpers}'
            . '<nrio:sourceSet path="/fileadmin/test-image.png" width="800" height="600"'
            . ' set="{768: {width: 400, height: 300}, 1200: {width: 600, height: 450}}" alt="" />',
        );

        $output = $view->render();

        self::assertStringContainsString('<source', $output);
        self::assertStringContainsString('(max-width: 768px)', $output);
        self::assertStringContainsString('(max-width: 1200px)', $output);
    }

    #[Test]
    public function viewHelperRendersCustomAttributes(): void
    {
        $view = $this->createView();
        $view->setTemplateSource(
            '{namespace nrio=Netresearch\NrImageOptimize\ViewHelpers}'
            . '<nrio:sourceSet path="/fileadmin/test-image.png" width="100" height="75"'
            . ' attributes="{data-test: \'hello\'}" alt="" />',
        );

        $output = $view->render();

        self::assertStringContainsString('data-test="hello"', $output);
    }

    #[Test]
    public function viewHelperRendersFetchpriority(): void
    {
        $view = $this->createView();
        $view->setTemplateSource(
            '{namespace nrio=Netresearch\NrImageOptimize\ViewHelpers}'
            . '<nrio:sourceSet path="/fileadmin/test-image.png" width="100" height="75"'
            . ' fetchpriority="high" alt="" />',
        );

        $output = $view->render();

        self::assertStringContainsString('fetchpriority="high"', $output);
    }

    #[Test]
    public function viewHelperEscapesHtmlInAltAttribute(): void
    {
        $view = $this->createView();
        $view->setTemplateSource(
            '{namespace nrio=Netresearch\NrImageOptimize\ViewHelpers}'
            . '<nrio:sourceSet path="/fileadmin/test-image.png" width="100" height="75"'
            . ' alt="Test &quot;image&quot;" />',
        );

        $output = $view->render();

        self::assertStringContainsString('alt="', $output);
        self::assertStringNotContainsString('alt="Test "image""', $output);
    }

    #[Test]
    public function viewHelperReturnsSvgPathUnmodified(): void
    {
        $view = $this->createView();
        $view->setTemplateSource(
            '{namespace nrio=Netresearch\NrImageOptimize\ViewHelpers}'
            . '<nrio:sourceSet path="/fileadmin/logo.svg" width="100" height="75" alt="" />',
        );

        $output = $view->render();

        // SVG paths should not be routed through /processed/
        self::assertStringNotContainsString('/processed/', $output);
    }

    private function createView(): StandaloneView
    {
        $view = $this->get(StandaloneView::class);

        return $view;
    }
}
