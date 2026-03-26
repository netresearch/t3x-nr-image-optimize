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
use TYPO3\CMS\Fluid\Core\Rendering\RenderingContextFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use TYPO3Fluid\Fluid\View\TemplateView;

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
        $output = $this->renderTemplate(
            '{namespace nrio=Netresearch\NrImageOptimize\ViewHelpers}'
            . '<nrio:sourceSet path="/fileadmin/test-image.png" width="100" height="75" alt="Test" />',
        );

        self::assertStringContainsString('<img', $output);
        self::assertStringContainsString('srcset=', $output);
        self::assertStringContainsString('alt="Test"', $output);
        self::assertStringContainsString('/processed/', $output);
    }

    #[Test]
    public function viewHelperRendersResponsiveSrcsetWithWidthVariants(): void
    {
        $output = $this->renderTemplate(
            '{namespace nrio=Netresearch\NrImageOptimize\ViewHelpers}'
            . '<nrio:sourceSet path="/fileadmin/test-image.png" width="800" height="600"'
            . ' responsiveSrcset="1" widthVariants="320,640,960" alt="Responsive" />',
        );

        self::assertStringContainsString('<img', $output);
        self::assertStringContainsString('320w', $output);
        self::assertStringContainsString('640w', $output);
        self::assertStringContainsString('960w', $output);
        self::assertStringContainsString('sizes=', $output);
    }

    #[Test]
    public function viewHelperAppliesLazyLoadPlaceholder(): void
    {
        $output = $this->renderTemplate(
            '{namespace nrio=Netresearch\NrImageOptimize\ViewHelpers}'
            . '<nrio:sourceSet path="/fileadmin/test-image.png" width="100" height="75"'
            . ' class="lazyload" alt="" />',
        );

        self::assertStringContainsString('data:image/gif;base64,', $output);
        self::assertStringContainsString('data-src=', $output);
        self::assertStringContainsString('data-srcset=', $output);
    }

    #[Test]
    public function viewHelperAppliesNativeLazyLoading(): void
    {
        $output = $this->renderTemplate(
            '{namespace nrio=Netresearch\NrImageOptimize\ViewHelpers}'
            . '<nrio:sourceSet path="/fileadmin/test-image.png" width="100" height="75"'
            . ' lazyload="1" alt="" />',
        );

        self::assertStringContainsString('loading="lazy"', $output);
    }

    #[Test]
    public function viewHelperRendersFitMode(): void
    {
        $output = $this->renderTemplate(
            '{namespace nrio=Netresearch\NrImageOptimize\ViewHelpers}'
            . '<nrio:sourceSet path="/fileadmin/test-image.png" width="100" height="75"'
            . ' mode="fit" alt="" />',
        );

        self::assertStringContainsString('m1', $output, 'Fit mode should produce mode=1 in URL');
    }

    #[Test]
    public function viewHelperRendersSourceTagsWithSet(): void
    {
        $output = $this->renderTemplate(
            '{namespace nrio=Netresearch\NrImageOptimize\ViewHelpers}'
            . '<nrio:sourceSet path="/fileadmin/test-image.png" width="800" height="600"'
            . ' set="{768: {width: 400, height: 300}, 1200: {width: 600, height: 450}}" alt="" />',
        );

        self::assertStringContainsString('<source', $output);
        self::assertStringContainsString('(max-width: 768px)', $output);
        self::assertStringContainsString('(max-width: 1200px)', $output);
        // type attribute is only emitted for next-gen formats (webp, avif)
        self::assertStringNotContainsString('type=', $output);
    }

    #[Test]
    public function viewHelperRendersCustomAttributes(): void
    {
        $output = $this->renderTemplate(
            '{namespace nrio=Netresearch\NrImageOptimize\ViewHelpers}'
            . '<nrio:sourceSet path="/fileadmin/test-image.png" width="100" height="75"'
            . ' attributes="{data-test: \'hello\'}" alt="" />',
        );

        self::assertStringContainsString('data-test="hello"', $output);
    }

    #[Test]
    public function viewHelperRendersFetchpriority(): void
    {
        $output = $this->renderTemplate(
            '{namespace nrio=Netresearch\NrImageOptimize\ViewHelpers}'
            . '<nrio:sourceSet path="/fileadmin/test-image.png" width="100" height="75"'
            . ' fetchpriority="high" alt="" />',
        );

        self::assertStringContainsString('fetchpriority="high"', $output);
    }

    #[Test]
    public function viewHelperEscapesHtmlInAltAttribute(): void
    {
        $output = $this->renderTemplate(
            '{namespace nrio=Netresearch\NrImageOptimize\ViewHelpers}'
            . '<nrio:sourceSet path="/fileadmin/test-image.png" width="100" height="75"'
            . ' alt="Test &quot;image&quot;" />',
        );

        self::assertStringContainsString('alt="', $output);
        self::assertStringNotContainsString('alt="Test "image""', $output);
    }

    #[Test]
    public function viewHelperReturnsSvgPathUnmodified(): void
    {
        $output = $this->renderTemplate(
            '{namespace nrio=Netresearch\NrImageOptimize\ViewHelpers}'
            . '<nrio:sourceSet path="/fileadmin/logo.svg" width="100" height="75" alt="" />',
        );

        // SVG paths should not be routed through /processed/
        self::assertStringNotContainsString('/processed/', $output);
    }

    /**
     * Render a Fluid template source string through the TYPO3 rendering context.
     */
    private function renderTemplate(string $templateSource): string
    {
        $renderingContextFactory = $this->get(RenderingContextFactory::class);
        $renderingContext        = $renderingContextFactory->create();
        $renderingContext->getTemplatePaths()->setTemplateSource($templateSource);

        $view = new TemplateView($renderingContext);

        return (string) $view->render();
    }
}
