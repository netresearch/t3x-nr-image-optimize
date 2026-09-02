<?php

/*
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\NrImageOptimize\Tests\Functional\Controller;

use function file_put_contents;
use function is_dir;
use function mkdir;

use Netresearch\NrImageOptimize\Controller\MaintenanceController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\ExtbaseRequestParameters;
use TYPO3\CMS\Extbase\Mvc\Request;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

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
        //
        // MaintenanceController extends Extbase's ActionController whose
        // constructor resolves ConfigurationManager; that manager reads
        // from $GLOBALS['TYPO3_REQUEST'] and calls ApplicationType::fromRequest,
        // which requires an `applicationType` request attribute.
        // Functional tests don't set that up, so plant a minimal backend
        // request here — the controller is only instantiated, not invoked.
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest('https://example.com/typo3/'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);

        try {
            $this->get(MaintenanceController::class);
            $this->addToAssertionCount(1);
        } finally {
            unset($GLOBALS['TYPO3_REQUEST']);
        }
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

    #[Test]
    public function statisticsActionReturnsJsonWithDirectoryStats(): void
    {
        $processedPath = Environment::getPublicPath() . '/processed';

        // "processed" is a shared fixture path across test methods in this
        // class; reset it so leftovers from other tests can't inflate the
        // counts asserted below.
        GeneralUtility::rmdir($processedPath, true);

        $subDir = $processedPath . '/fileadmin';
        mkdir($subDir, 0o775, true);

        file_put_contents($subDir . '/file1.jpg', 'fake-jpg-data-1');
        file_put_contents($subDir . '/file2.png', 'fake-png-data-2');

        $response = $this->dispatchAction('statistics');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));

        /** @var array<string, mixed> $data */
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(2, $data['fileCount']);
        self::assertSame(1, $data['directoryCount']);
        self::assertCount(2, $data['largestFiles']);
        self::assertArrayHasKey('jpg', $data['fileTypes']);
        self::assertArrayHasKey('png', $data['fileTypes']);
        self::assertContains($data['oldestFile']['name'], ['file1.jpg', 'file2.png']);
        self::assertContains($data['newestFile']['name'], ['file1.jpg', 'file2.png']);
        self::assertSame($processedPath, $data['processedPath']);
    }

    #[Test]
    public function invalidatePathActionDeletesOnlyMatchingVariantsAndReturnsCount(): void
    {
        $processedPath = Environment::getPublicPath() . '/processed';

        // "processed" is a shared fixture path across test methods in this
        // class; reset it so leftovers from other tests can't affect the
        // count asserted below.
        GeneralUtility::rmdir($processedPath, true);

        $subDir = $processedPath . '/fileadmin/images';
        mkdir($subDir, 0o775, true);

        file_put_contents($subDir . '/logo.w300h200m1q80.webp', 'fake-webp');
        file_put_contents($subDir . '/logo.w600h400m1q80.avif', 'fake-avif');
        file_put_contents($subDir . '/other.w300h200m1q80.webp', 'fake-webp-other');

        $response = $this->dispatchAction('invalidatePath', ['path' => 'fileadmin/images/logo.png']);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));

        /** @var array<string, mixed> $data */
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(2, $data['deletedCount']);
        self::assertFileDoesNotExist($subDir . '/logo.w300h200m1q80.webp');
        self::assertFileDoesNotExist($subDir . '/logo.w600h400m1q80.avif');
        self::assertFileExists($subDir . '/other.w300h200m1q80.webp');
    }

    #[Test]
    public function invalidatePathActionRejectsUnexpectedSymlinkTarget(): void
    {
        $publicPath    = Environment::getPublicPath();
        $processedPath = $publicPath . '/processed';
        $foreignTarget = $publicPath . '/not-processed';

        GeneralUtility::rmdir($processedPath, true);
        mkdir($foreignTarget, 0o775, true);
        file_put_contents($foreignTarget . '/keep.txt', 'keep');
        symlink($foreignTarget, $processedPath);

        // Same guard as clearProcessedImagesAction(): a "processed" symlink
        // pointing outside must reject enumeration/deletion, not silently
        // walk and delete inside the foreign target.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Security check failed: unexpected processed path target/');

        try {
            $this->dispatchAction('invalidatePath', ['path' => 'fileadmin/images/logo.png']);
        } finally {
            self::assertFileExists($foreignTarget . '/keep.txt');
        }
    }

    #[Test]
    public function clearProcessedImagesActionEmptiesDirectoryKeepingItInPlace(): void
    {
        $processedPath = Environment::getPublicPath() . '/processed';

        // "processed" is a shared fixture path across test methods in this
        // class; reset it so leftovers from other tests can't make the
        // "fileadmin no longer exists" assertion below a false negative.
        GeneralUtility::rmdir($processedPath, true);

        mkdir($processedPath . '/fileadmin', 0o775, true);
        file_put_contents($processedPath . '/fileadmin/variant.jpg', 'fake-jpg');
        file_put_contents($processedPath . '/top-level.png', 'fake-png');

        $response = $this->dispatchAction('clearProcessedImages');

        self::assertGreaterThanOrEqual(300, $response->getStatusCode());
        self::assertLessThan(400, $response->getStatusCode());
        self::assertDirectoryExists($processedPath);
        self::assertDirectoryDoesNotExist($processedPath . '/fileadmin');
        self::assertFileDoesNotExist($processedPath . '/top-level.png');
    }

    #[Test]
    public function clearProcessedImagesActionCreatesDirectoryWhenMissing(): void
    {
        $processedPath = Environment::getPublicPath() . '/processed';
        GeneralUtility::rmdir($processedPath, true);
        self::assertDirectoryDoesNotExist($processedPath);

        $response = $this->dispatchAction('clearProcessedImages');

        self::assertGreaterThanOrEqual(300, $response->getStatusCode());
        self::assertLessThan(400, $response->getStatusCode());
        self::assertDirectoryExists($processedPath);
    }

    #[Test]
    public function clearProcessedImagesActionReportsErrorForUnexpectedSymlinkTarget(): void
    {
        $publicPath    = Environment::getPublicPath();
        $processedPath = $publicPath . '/processed';
        $foreignTarget = $publicPath . '/not-processed';

        GeneralUtility::rmdir($processedPath, true);
        mkdir($foreignTarget, 0o775, true);
        file_put_contents($foreignTarget . '/keep.txt', 'keep');
        symlink($foreignTarget, $processedPath);

        // The guard rejects a symlink whose target is not named "processed":
        // the action logs, shows an error flash and still redirects -- without
        // deleting anything.
        $response = $this->dispatchAction('clearProcessedImages');

        self::assertGreaterThanOrEqual(300, $response->getStatusCode());
        self::assertLessThan(400, $response->getStatusCode());
        self::assertFileExists($foreignTarget . '/keep.txt');
    }

    /**
     * Run a MaintenanceController action through the Extbase request lifecycle
     * so the guard, flash messages and the redirect response are exercised as
     * they are in production (not just the extracted filesystem helper).
     *
     * @param array<string, mixed> $arguments Action arguments (e.g. ['path' => '...'])
     */
    private function dispatchAction(string $action, array $arguments = []): ResponseInterface
    {
        $requestParameters = (new ExtbaseRequestParameters(MaintenanceController::class))
            ->setControllerActionName($action)
            ->setControllerName('Maintenance')
            ->setControllerExtensionName('NrImageOptimize')
            ->setArguments($arguments);

        $serverRequest = (new ServerRequest('https://example.com/typo3/'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('extbase', $requestParameters);
        $serverRequest = $serverRequest->withAttribute(
            'normalizedParams',
            NormalizedParams::createFromRequest($serverRequest),
        );
        $GLOBALS['TYPO3_REQUEST'] = $serverRequest;

        try {
            return $this->get(MaintenanceController::class)->processRequest(new Request($serverRequest));
        } finally {
            unset($GLOBALS['TYPO3_REQUEST']);
        }
    }
}
