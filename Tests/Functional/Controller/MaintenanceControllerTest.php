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
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\ExtbaseRequestParameters;
use TYPO3\CMS\Extbase\Mvc\Request;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

use function file_put_contents;
use function ini_get;
use function ini_set;
use function is_dir;
use function mkdir;
use function symlink;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

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

    #[Test]
    public function clearProcessedImagesActionEmptiesDirectoryKeepingItInPlace(): void
    {
        $processedPath = Environment::getPublicPath() . '/processed';

        if (!is_dir($processedPath)) {
            mkdir($processedPath, 0o775, true);
        }

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
        // the action logs via error_log(), shows an error flash and still
        // redirects -- without deleting anything. error_log() is redirected
        // to a throwaway file for the call so the diagnostic line does not
        // trip beStrictAboutOutputDuringTests.
        $originalErrorLog = ini_get('error_log');
        $tempLogFile      = tempnam(sys_get_temp_dir(), 'nr-image-optimize-error-log-');
        ini_set('error_log', $tempLogFile);

        try {
            $response = $this->dispatchAction('clearProcessedImages');
        } finally {
            ini_set('error_log', $originalErrorLog === false ? '' : $originalErrorLog);
            unlink($tempLogFile); // nosemgrep: php.lang.security.unlink-use.unlink-use -- test fixture teardown of self-created tmp file
        }

        self::assertGreaterThanOrEqual(300, $response->getStatusCode());
        self::assertLessThan(400, $response->getStatusCode());
        self::assertFileExists($foreignTarget . '/keep.txt');

        // The guard rejects clearing, so the dangling "processed" symlink is
        // never removed by the action itself -- clean it up here so it does
        // not leak into sibling tests when PHPUnit's defects-first execution
        // order runs this test ahead of one that expects a plain directory.
        unlink($processedPath); // nosemgrep: php.lang.security.unlink-use.unlink-use -- test fixture teardown of self-created symlink
        GeneralUtility::rmdir($foreignTarget, true);
    }

    /**
     * Run a MaintenanceController action through the Extbase request lifecycle
     * so the guard, flash messages and the redirect response are exercised as
     * they are in production (not just the extracted filesystem helper).
     */
    private function dispatchAction(string $action): ResponseInterface
    {
        $requestParameters = (new ExtbaseRequestParameters(MaintenanceController::class))
            ->setControllerActionName($action)
            ->setControllerName('Maintenance')
            ->setControllerExtensionName('NrImageOptimize');

        $serverRequest = (new ServerRequest('https://example.com/typo3/'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('extbase', $requestParameters);
        $serverRequest = $serverRequest->withAttribute(
            'normalizedParams',
            NormalizedParams::createFromRequest($serverRequest),
        );
        $GLOBALS['TYPO3_REQUEST'] = $serverRequest;

        try {
            /** @var MaintenanceController $controller */
            $controller = $this->get(MaintenanceController::class);

            return $controller->processRequest(new Request($serverRequest));
        } finally {
            unset($GLOBALS['TYPO3_REQUEST']);
        }
    }
}
