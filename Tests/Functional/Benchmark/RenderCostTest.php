<?php

/*
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\NrImageOptimize\Tests\Functional\Benchmark;

use function copy;
use function count;
use function hrtime;
use function is_dir;
use function is_executable;
use function mkdir;

use Netresearch\NrImageOptimize\ViewHelpers\SourceSetViewHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

use function sprintf;

use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Service\ImageService;
use TYPO3\CMS\Fluid\Core\Rendering\RenderingContextFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use TYPO3Fluid\Fluid\View\TemplateView;

/**
 * Guards the architectural claim behind the "Performance model" chapter of
 * the documentation: rendering a page with nrio:sourceSet processes no
 * image, while TYPO3 core's f:image pipeline (ImageService, the class the
 * ViewHelper delegates into) processes every referenced image during render.
 *
 * This is the cheap, always-on half of the benchmark. The full scenario
 * matrix with real browser timings lives in Tests/E2E (`make benchmark`).
 */
#[CoversClass(SourceSetViewHelper::class)]
final class RenderCostTest extends FunctionalTestCase
{
    private const IMAGE_COUNT = 12;

    protected array $testExtensionsToLoad = [
        'netresearch/nr-image-optimize',
    ];

    protected array $pathsToProvideInTestInstance = [
        'typo3conf/ext/nr_image_optimize/Tests/Functional/Fixtures/test-image.png' => 'fileadmin/test-image.png',
    ];

    protected array $configurationToUseInTestInstance = [
        'GFX' => [
            'processor_enabled' => true,
            'processor'         => 'ImageMagick',
            'processor_path'    => '/usr/bin/',
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $directory = Environment::getPublicPath() . '/fileadmin/benchmark';

        if (!is_dir($directory)) {
            mkdir($directory, 0o777, true);
        }

        for ($i = 1; $i <= self::IMAGE_COUNT; ++$i) {
            copy(
                Environment::getPublicPath() . '/fileadmin/test-image.png',
                sprintf('%s/image-%02d.png', $directory, $i),
            );
        }
    }

    #[Test]
    public function renderingWithSourceSetViewHelperProcessesNoImageWhileCoreProcessesAll(): void
    {
        if (!$this->imageMagickAvailable()) {
            self::markTestSkipped('ImageMagick binary not found in /usr/bin; cannot exercise the core pipeline.');
        }

        // Core: what every <f:image> on a page does while the page renders.
        $imageService    = $this->get(ImageService::class);
        $resourceFactory = $this->get(ResourceFactory::class);

        $start = hrtime(true);

        for ($i = 1; $i <= self::IMAGE_COUNT; ++$i) {
            $file = $resourceFactory->retrieveFileOrFolderObject(sprintf('fileadmin/benchmark/image-%02d.png', $i));
            self::assertInstanceOf(File::class, $file);
            $imageService->applyProcessingInstructions($file, ['width' => '50c', 'height' => '38c']);
        }

        $coreNanoseconds = hrtime(true) - $start;
        $coreVariants    = $this->countFiles(Environment::getPublicPath() . '/fileadmin/_processed_');

        // Extension: the same images through nrio:sourceSet on a rendered template.
        $template = '{namespace nrio=Netresearch\NrImageOptimize\ViewHelpers}';

        for ($i = 1; $i <= self::IMAGE_COUNT; ++$i) {
            $template .= sprintf(
                '<nrio:sourceSet path="/fileadmin/benchmark/image-%02d.png" width="50" height="38" alt="" />',
                $i,
            );
        }

        $start = hrtime(true);

        $html = $this->renderTemplate($template);

        $extensionNanoseconds = hrtime(true) - $start;
        $extensionVariants    = $this->countFiles(Environment::getPublicPath() . '/processed');

        self::assertSame(self::IMAGE_COUNT, substr_count($html, '<img'), 'Every image must have been rendered');
        self::assertStringContainsString('/processed/fileadmin/benchmark/image-01.', $html, 'Images must be referenced through /processed/ URLs');
        self::assertSame(0, $extensionVariants, 'Rendering must not write a single variant file');
        self::assertGreaterThanOrEqual(
            self::IMAGE_COUNT,
            $coreVariants,
            'Core must have processed every image during render (otherwise the comparison is void)',
        );
        self::assertLessThan(
            $coreNanoseconds,
            $extensionNanoseconds,
            sprintf(
                'Rendering %d images took %.1f ms with nrio:sourceSet but %.1f ms through the core pipeline',
                self::IMAGE_COUNT,
                $extensionNanoseconds / 1e6,
                $coreNanoseconds / 1e6,
            ),
        );
    }

    private function renderTemplate(string $source): string
    {
        $renderingContext = $this->get(RenderingContextFactory::class)->create();
        $view             = new TemplateView($renderingContext);

        $renderingContext->getTemplatePaths()->setTemplateSource($source);

        $rendered = $view->render();
        self::assertIsString($rendered);

        return $rendered;
    }

    private function countFiles(string $directory): int
    {
        if (!is_dir($directory)) {
            return 0;
        }

        return count(GeneralUtility::getAllFilesAndFoldersInPath([], $directory . '/'));
    }

    private function imageMagickAvailable(): bool
    {
        return is_executable('/usr/bin/magick') || is_executable('/usr/bin/convert');
    }
}
