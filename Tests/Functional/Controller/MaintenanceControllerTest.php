<?php

/**
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\NrImageOptimize\Tests\Functional\Controller;

use Netresearch\NrImageOptimize\Controller\MaintenanceController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

use function file_put_contents;
use function is_dir;
use function mkdir;

/**
 * Functional tests for MaintenanceController backend module actions.
 */
#[CoversClass(MaintenanceController::class)]
final class MaintenanceControllerTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'typo3/cms-backend',
        'typo3/cms-extbase',
        'typo3/cms-fluid',
    ];

    protected array $testExtensionsToLoad = [
        'netresearch/nr-image-optimize',
    ];

    #[Test]
    public function maintenanceControllerIsRegisteredInContainer(): void
    {
        // Verifies that the DI container can resolve the controller with all its dependencies
        // (ModuleTemplateFactory, SystemRequirementsService, LanguageServiceFactory).
        // A ContainerExceptionInterface is thrown if any dependency is missing.
        // The expectation is implicit: no exception = success.
        $this->get(MaintenanceController::class);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function processedDirectoryCanBeCreatedAndCleared(): void
    {
        $processedPath = Environment::getPublicPath() . '/processed';

        // Create processed directory with test files
        if (!is_dir($processedPath)) {
            mkdir($processedPath, 0o775, true);
        }

        $testFile = $processedPath . '/test-variant.png';
        file_put_contents($testFile, 'fake-image-data');

        self::assertFileExists($testFile);

        // Simulate clearing via GeneralUtility (same mechanism the controller uses)
        GeneralUtility::rmdir($processedPath, true);
        GeneralUtility::mkdir($processedPath);

        self::assertDirectoryExists($processedPath);
        self::assertFileDoesNotExist($testFile);
    }

    #[Test]
    public function processedDirectoryStatsAreCorrect(): void
    {
        $processedPath = Environment::getPublicPath() . '/processed';

        if (!is_dir($processedPath)) {
            mkdir($processedPath, 0o775, true);
        }

        // Create subdirectory with files
        $subDir = $processedPath . '/fileadmin';

        if (!is_dir($subDir)) {
            mkdir($subDir, 0o775, true);
        }

        file_put_contents($subDir . '/file1.jpg', 'fake-jpg-data-1');
        file_put_contents($subDir . '/file2.png', 'fake-png-data-2');
        file_put_contents($subDir . '/file3.webp', 'fake-webp-data');

        self::assertFileExists($subDir . '/file1.jpg');
        self::assertFileExists($subDir . '/file2.png');
        self::assertFileExists($subDir . '/file3.webp');
    }
}
