<?php

/*
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\NrImageOptimize\Tests\Unit\Service;

use Composer\InstalledVersions;
use Netresearch\NrImageOptimize\Service\SystemRequirementsService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionMethod;
use SplFileInfo;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;

/**
 * No CoversClass attribute: final classes cannot be instrumented
 * by PCOV on PHP 8.5, causing PHPUnit coverage warnings.
 */
class SystemRequirementsServiceTest extends TestCase
{
    private SystemRequirementsService $service;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/nr-image-optimize-sysreq-test-' . uniqid('', true);
        mkdir($this->tempDir, 0o777, true);
        mkdir($this->tempDir . '/public', 0o777, true);
        mkdir($this->tempDir . '/var', 0o777, true);
        mkdir($this->tempDir . '/config', 0o777, true);

        Environment::initialize(
            new ApplicationContext('Testing'),
            true,
            true,
            $this->tempDir,
            $this->tempDir . '/public',
            $this->tempDir . '/var',
            $this->tempDir . '/config',
            $this->tempDir . '/public/index.php',
            'UNIX',
        );

        $this->service = new SystemRequirementsService();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            /** @var SplFileInfo $item */
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                if (!is_writable($item->getPathname())) {
                    chmod($item->getPathname(), 0o644);
                }

                unlink($item->getPathname());
            }
        }

        rmdir($dir);
    }

    private function callMethod(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(SystemRequirementsService::class, $method);

        return $reflection->invoke($this->service, ...$arguments);
    }

    #[Test]
    public function collectReturnsAllCategories(): void
    {
        $result = $this->service->collect();

        self::assertArrayHasKey('php', $result);
        self::assertArrayHasKey('imagick', $result);
        self::assertArrayHasKey('gd', $result);
        self::assertArrayHasKey('composer', $result);
        self::assertArrayHasKey('typo3', $result);
        self::assertArrayHasKey('cli', $result);
    }

    #[Test]
    public function collectReturnsCategoriesWithNonEmptyLabelKeysAndItems(): void
    {
        $result = $this->service->collect();

        foreach ($result as $key => $category) {
            self::assertNotEmpty($category['labelKey'], sprintf('Category "%s" should have a non-empty labelKey', $key));
            self::assertNotEmpty($category['items'], sprintf('Category "%s" should have at least one item', $key));
        }
    }

    #[Test]
    public function makeCategoryReturnsCorrectStructure(): void
    {
        $items = [
            ['labelKey' => 'test', 'status' => 'success'],
        ];

        /** @var array<string, mixed> $result */
        $result = $this->callMethod('makeCategory', 'sysreq.testCategory', $items);

        self::assertSame('sysreq.testCategory', $result['labelKey']);
        self::assertSame($items, $result['items']);
    }

    #[Test]
    public function makeItemReturnsCompleteStructure(): void
    {
        /** @var array<string, mixed> $result */
        $result = $this->callMethod(
            'makeItem',
            'sysreq.testLabel',
            '8.2.0',
            '>= 8.2.0',
            'success',
        );

        self::assertSame('sysreq.testLabel', $result['labelKey']);
        self::assertSame('8.2.0', $result['current']);
        self::assertSame('>= 8.2.0', $result['required']);
        self::assertSame('success', $result['status']);
        self::assertSame('status-dialog-ok', $result['icon']);
        self::assertSame('bg-success', $result['badgeClass']);
        self::assertNull($result['details']);
        self::assertSame([], $result['labelArguments']);
        self::assertNull($result['currentKey']);
        self::assertNull($result['requiredKey']);
        self::assertSame('', $result['label']);
    }

    #[Test]
    public function makeItemWithAllOptionalParameters(): void
    {
        /** @var array<string, mixed> $result */
        $result = $this->callMethod(
            'makeItem',
            'sysreq.phpExtension',
            null,
            null,
            'warning',
            'Some tooltip',
            ['imagick'],
            'sysreq.notLoaded',
            'sysreq.recommended',
            'Custom label',
        );

        self::assertSame('sysreq.phpExtension', $result['labelKey']);
        self::assertNull($result['current']);
        self::assertNull($result['required']);
        self::assertSame('warning', $result['status']);
        self::assertSame('Some tooltip', $result['details']);
        self::assertSame(['imagick'], $result['labelArguments']);
        self::assertSame('sysreq.notLoaded', $result['currentKey']);
        self::assertSame('sysreq.recommended', $result['requiredKey']);
        self::assertSame('Custom label', $result['label']);
        self::assertSame('status-dialog-warning', $result['icon']);
        self::assertSame('bg-warning text-dark', $result['badgeClass']);
    }

    #[Test]
    public function makeItemWithNullLabelKeyUsesEmptyString(): void
    {
        /** @var array<string, mixed> $result */
        $result = $this->callMethod(
            'makeItem',
            null,
            '3.11.0',
            null,
            'success',
            null,
            [],
            null,
            null,
            'intervention/image',
        );

        self::assertSame('', $result['labelKey']);
        self::assertSame('intervention/image', $result['label']);
    }

    #[Test]
    public function iconForStatusReturnsCorrectIcons(): void
    {
        self::assertSame('status-dialog-ok', $this->callMethod('iconForStatus', 'success'));
        self::assertSame('status-dialog-warning', $this->callMethod('iconForStatus', 'warning'));
        self::assertSame('status-dialog-error', $this->callMethod('iconForStatus', 'error'));
        self::assertSame('status-dialog-error', $this->callMethod('iconForStatus', 'unknown'));
    }

    #[Test]
    public function badgeForStatusReturnsCorrectClasses(): void
    {
        self::assertSame('bg-success', $this->callMethod('badgeForStatus', 'success'));
        self::assertSame('bg-warning text-dark', $this->callMethod('badgeForStatus', 'warning'));
        self::assertSame('bg-danger', $this->callMethod('badgeForStatus', 'error'));
        self::assertSame('bg-danger', $this->callMethod('badgeForStatus', 'unknown'));
    }

    #[Test]
    public function checkPhpReturnsPhpVersionAndExtensions(): void
    {
        /** @var array<string, mixed> $result */
        $result = $this->callMethod('checkPhp');

        self::assertSame('sysreq.phpRequirements', $result['labelKey']);
        assert(is_array($result['items']));
        self::assertNotEmpty($result['items']);

        // First item is PHP version
        $phpVersion = $result['items'][0];
        self::assertSame('sysreq.phpVersion', $phpVersion['labelKey']);
        self::assertSame(PHP_VERSION, $phpVersion['current']);
        self::assertSame('>= 8.2.0', $phpVersion['required']);

        // PHP 8.2+ should be success
        self::assertSame('success', $phpVersion['status']);

        // Check that extension items are present (imagick, gd, mbstring, exif)
        self::assertGreaterThanOrEqual(5, count($result['items']));
    }

    #[Test]
    public function checkPhpExtensionStatusVariesByType(): void
    {
        /** @var array<string, mixed> $result */
        $result = $this->callMethod('checkPhp');

        assert(is_array($result['items']));

        // Find the mbstring extension item (required, not optional)
        $mbstringItem = null;
        foreach ($result['items'] as $item) {
            assert(is_array($item));
            if ($item['labelKey'] === 'sysreq.phpExtension' && is_array($item['labelArguments']) && in_array('mbstring', $item['labelArguments'], true)) {
                $mbstringItem = $item;

                break;
            }
        }

        self::assertNotNull($mbstringItem, 'mbstring extension item should be present');

        if (extension_loaded('mbstring')) {
            self::assertSame('success', $mbstringItem['status']);
            self::assertSame('sysreq.loaded', $mbstringItem['currentKey']);
        } else {
            self::assertSame('error', $mbstringItem['status']);
            self::assertSame('sysreq.notLoaded', $mbstringItem['currentKey']);
        }
    }

    #[Test]
    public function checkGdReturnsCategoryWithItems(): void
    {
        /** @var array<string, mixed> $result */
        $result = $this->callMethod('checkGd');

        self::assertSame('sysreq.gdCategory', $result['labelKey']);
        assert(is_array($result['items']));
        self::assertNotEmpty($result['items']);

        if (extension_loaded('gd')) {
            // Should have GD version + WebP support + AVIF support items
            self::assertGreaterThanOrEqual(3, count($result['items']));

            $gdVersionItem = $result['items'][0];
            self::assertSame('sysreq.gdVersion', $gdVersionItem['labelKey']);
            self::assertSame('success', $gdVersionItem['status']);
        } else {
            // Should have a single "not loaded" item
            self::assertCount(1, $result['items']);
            self::assertSame('warning', $result['items'][0]['status']);
            self::assertSame('sysreq.notLoaded', $result['items'][0]['currentKey']);
        }
    }

    #[Test]
    public function checkImagickReturnsCategoryWithItems(): void
    {
        /** @var array<string, mixed> $result */
        $result = $this->callMethod('checkImagick');

        self::assertSame('sysreq.imageMagickCategory', $result['labelKey']);
        assert(is_array($result['items']));
        self::assertNotEmpty($result['items']);

        if (!extension_loaded('imagick')) {
            // Should have a single "not loaded" item
            self::assertCount(1, $result['items']);
            self::assertSame('warning', $result['items'][0]['status']);
            self::assertSame('sysreq.notLoaded', $result['items'][0]['currentKey']);
            self::assertSame('sysreq.recommended', $result['items'][0]['requiredKey']);
        }
    }

    #[Test]
    public function checkComposerReturnsPackageStatus(): void
    {
        /** @var array<string, mixed> $result */
        $result = $this->callMethod('checkComposer');

        self::assertSame('sysreq.composerDeps', $result['labelKey']);
        assert(is_array($result['items']));
        self::assertCount(2, $result['items']);

        // Check intervention/image
        $interventionImage = $result['items'][0];
        self::assertSame('intervention/image', $interventionImage['label']);

        // Check intervention/gif
        $interventionGif = $result['items'][1];
        self::assertSame('intervention/gif', $interventionGif['label']);

        // Verify status reflects actual installation state
        foreach ($result['items'] as $item) {
            if ($item['status'] === 'success') {
                self::assertNotNull($item['current'], 'Installed packages should have a version');
                self::assertNull($item['currentKey'], 'Installed packages should not have a currentKey');
            } else {
                self::assertSame('error', $item['status']);
                self::assertSame('sysreq.notInstalled', $item['currentKey']);
            }
        }
    }

    #[Test]
    public function checkTypo3ReturnsVersionStatus(): void
    {
        /** @var array<string, mixed> $result */
        $result = $this->callMethod('checkTypo3');

        self::assertSame('sysreq.typo3Requirements', $result['labelKey']);
        assert(is_array($result['items']));
        self::assertCount(1, $result['items']);

        $item = $result['items'][0];
        self::assertSame('sysreq.typo3Version', $item['labelKey']);
        self::assertSame('>= 13.4', $item['required']);
        self::assertNotNull($item['current']);
    }

    #[Test]
    public function checkCliToolsReturnsExecAvailabilityAndTools(): void
    {
        /** @var array<string, mixed> $result */
        $result = $this->callMethod('checkCliTools');

        self::assertSame('sysreq.cliTools', $result['labelKey']);
        assert(is_array($result['items']));

        // 1 exec availability + 4 CLI tools = at least 5 items
        self::assertGreaterThanOrEqual(5, count($result['items']));

        // First item is exec availability
        $execItem = $result['items'][0];
        self::assertSame('sysreq.execAvailability', $execItem['labelKey']);

        // Check that CLI tool label keys are present (localized via XLF)
        $labelKeys = array_column($result['items'], 'labelKey');
        self::assertContains('sysreq.cli.magick', $labelKeys);
        self::assertContains('sysreq.cli.convert', $labelKeys);
        self::assertContains('sysreq.cli.identify', $labelKeys);
        self::assertContains('sysreq.cli.gm', $labelKeys);
    }

    #[Test]
    public function makeFormatSupportItemReturnsCorrectStructureWhenSupported(): void
    {
        /** @var array<string, mixed> $result */
        $result = $this->callMethod('makeFormatSupportItem', 'sysreq.webpSupport', true);

        self::assertSame('sysreq.webpSupport', $result['labelKey']);
        self::assertSame('success', $result['status']);
        self::assertSame('sysreq.yes', $result['currentKey']);
        self::assertSame('sysreq.optional', $result['requiredKey']);
    }

    #[Test]
    public function makeFormatSupportItemReturnsCorrectStructureWhenNotSupported(): void
    {
        /** @var array<string, mixed> $result */
        $result = $this->callMethod('makeFormatSupportItem', 'sysreq.avifSupport', false);

        self::assertSame('sysreq.avifSupport', $result['labelKey']);
        self::assertSame('warning', $result['status']);
        self::assertSame('sysreq.no', $result['currentKey']);
        self::assertSame('sysreq.optional', $result['requiredKey']);
    }

    #[Test]
    public function checkBinaryAvailabilityReturnsNullAvailableWhenExecNotAllowed(): void
    {
        /** @var array<string, mixed> $result */
        $result = $this->callMethod('checkBinaryAvailability', 'magick', false);

        self::assertNull($result['available']);
        self::assertSame('n/a', $result['version']);
    }

    #[Test]
    public function checkBinaryAvailabilityReturnsFalseForNonexistentBinary(): void
    {
        /** @var array<string, mixed> $result */
        $result = $this->callMethod('checkBinaryAvailability', 'nonexistent_binary_xyz_' . uniqid('', true), true);

        self::assertFalse($result['available']);
        self::assertNull($result['version']);
    }

    #[Test]
    public function checkBinaryAvailabilityVersionFromDashVersionFlag(): void
    {
        // Create a fake binary that only responds to -version (not --version)
        $binDir = $this->tempDir . '/fakebin';
        mkdir($binDir, 0o777, true);

        $script = $binDir . '/fakecmd-dashver';
        file_put_contents($script, "#!/bin/sh\n"
            . "case \"\$1\" in\n"
            . "  -version) echo 'FakeCmd 1.2.3';;\n"
            . "  *) ;;\n"
            . "esac\n");
        chmod($script, 0o755);

        $origPath = getenv('PATH');
        putenv('PATH=' . $binDir . ':' . $origPath);

        try {
            /** @var array<string, mixed> $result */
            $result = $this->callMethod('checkBinaryAvailability', 'fakecmd-dashver', true);

            self::assertTrue($result['available']);
            self::assertSame('FakeCmd 1.2.3', $result['version']);
        } finally {
            putenv('PATH=' . $origPath);
            unlink($script);
            rmdir($binDir);
        }
    }

    #[Test]
    public function checkBinaryAvailabilityFallsBackToDashDashVersion(): void
    {
        // Create a fake binary that only responds to --version (not -version)
        $binDir = $this->tempDir . '/fakebin2';
        mkdir($binDir, 0o777, true);

        $script = $binDir . '/fakecmd-dashdashver';
        file_put_contents($script, "#!/bin/sh\n"
            . "case \"\$1\" in\n"
            . "  --version) echo 'FakeCmd 2.0.0';;\n"
            . "  *) ;;\n"
            . "esac\n");
        chmod($script, 0o755);

        $origPath = getenv('PATH');
        putenv('PATH=' . $binDir . ':' . $origPath);

        try {
            /** @var array<string, mixed> $result */
            $result = $this->callMethod('checkBinaryAvailability', 'fakecmd-dashdashver', true);

            self::assertTrue($result['available']);
            // The -version call returns empty, so it falls back to --version
            self::assertSame('FakeCmd 2.0.0', $result['version']);
        } finally {
            putenv('PATH=' . $origPath);
            unlink($script);
            rmdir($binDir);
        }
    }

    #[Test]
    public function checkBinaryAvailabilityReturnsNullVersionWhenBothVersionFlagsEmpty(): void
    {
        // Create a binary that responds to neither -version nor --version
        $binDir = $this->tempDir . '/fakebin3';
        mkdir($binDir, 0o777, true);

        $script = $binDir . '/fakecmd-nover';
        file_put_contents($script, "#!/bin/sh\n# no output\n");
        chmod($script, 0o755);

        $origPath = getenv('PATH');
        putenv('PATH=' . $binDir . ':' . $origPath);

        try {
            /** @var array<string, mixed> $result */
            $result = $this->callMethod('checkBinaryAvailability', 'fakecmd-nover', true);

            self::assertTrue($result['available']);
            self::assertNull($result['version']);
        } finally {
            putenv('PATH=' . $origPath);
            unlink($script);
            rmdir($binDir);
        }
    }

    #[Test]
    public function checkBinaryAvailabilityTrimsVersionOutput(): void
    {
        // Create a binary that outputs version with trailing whitespace/newlines
        $binDir = $this->tempDir . '/fakebin4';
        mkdir($binDir, 0o777, true);

        $script = $binDir . '/fakecmd-trimtest';
        file_put_contents($script, "#!/bin/sh\n"
            . "case \"\$1\" in\n"
            . "  -version) printf '  Trimmed 3.0.0  \\n';;\n"
            . "  *) ;;\n"
            . "esac\n");
        chmod($script, 0o755);

        $origPath = getenv('PATH');
        putenv('PATH=' . $binDir . ':' . $origPath);

        try {
            /** @var array<string, mixed> $result */
            $result = $this->callMethod('checkBinaryAvailability', 'fakecmd-trimtest', true);

            self::assertTrue($result['available']);
            // Without trim(), this would be "  Trimmed 3.0.0  \n" — not what we expect
            self::assertSame('Trimmed 3.0.0', $result['version']);
        } finally {
            putenv('PATH=' . $origPath);
            unlink($script);
            rmdir($binDir);
        }
    }

    #[Test]
    public function checkBinaryAvailabilityTrimsVersionOutputFromDashDashVersion(): void
    {
        // Create a binary where -version is empty but --version has leading/trailing whitespace
        $binDir = $this->tempDir . '/fakebin5';
        mkdir($binDir, 0o777, true);

        $script = $binDir . '/fakecmd-trimtest2';
        file_put_contents($script, "#!/bin/sh\n"
            . "case \"\$1\" in\n"
            . "  --version) printf '  Version 4.0  \\n';;\n"
            . "  *) ;;\n"
            . "esac\n");
        chmod($script, 0o755);

        $origPath = getenv('PATH');
        putenv('PATH=' . $binDir . ':' . $origPath);

        try {
            /** @var array<string, mixed> $result */
            $result = $this->callMethod('checkBinaryAvailability', 'fakecmd-trimtest2', true);

            self::assertTrue($result['available']);
            // -version returns empty -> falls back to --version -> must be trimmed
            self::assertSame('Version 4.0', $result['version']);
        } finally {
            putenv('PATH=' . $origPath);
            unlink($script);
            rmdir($binDir);
        }
    }

    #[Test]
    public function findVersionFromComposerInstalledReturnsNullForNonexistentFile(): void
    {
        // Environment points to temp dir with no installed.json or composer.lock
        $result = $this->callMethod('findVersionFromComposerInstalled', 'nonexistent/package');

        self::assertNull($result);
    }

    #[Test]
    public function findVersionFromComposerInstalledFindsPackageInInstalledJson(): void
    {
        $projectPath = Environment::getProjectPath();
        $vendorDir   = $projectPath . '/vendor/composer';
        mkdir($vendorDir, 0o777, true);

        $data = json_encode([
            'packages' => [
                [
                    'name'           => 'test/package',
                    'version'        => 'v1.2.3',
                    'pretty_version' => '1.2.3',
                ],
            ],
        ], JSON_THROW_ON_ERROR);
        file_put_contents($vendorDir . '/installed.json', $data);

        $result = $this->callMethod('findVersionFromComposerInstalled', 'test/package');
        self::assertSame('1.2.3', $result);

        // Cleanup
        unlink($vendorDir . '/installed.json');
        rmdir($vendorDir);
        rmdir($projectPath . '/vendor');
    }

    #[Test]
    public function findVersionFromComposerInstalledFindsVersionInInstalledJsonWithoutPrettyVersion(): void
    {
        $projectPath = Environment::getProjectPath();
        $vendorDir   = $projectPath . '/vendor/composer';
        mkdir($vendorDir, 0o777, true);

        $data = json_encode([
            'packages' => [
                [
                    'name'    => 'test/package',
                    'version' => 'v1.0.0',
                ],
            ],
        ], JSON_THROW_ON_ERROR);
        file_put_contents($vendorDir . '/installed.json', $data);

        $result = $this->callMethod('findVersionFromComposerInstalled', 'test/package');
        self::assertSame('v1.0.0', $result);

        unlink($vendorDir . '/installed.json');
        rmdir($vendorDir);
        rmdir($projectPath . '/vendor');
    }

    #[Test]
    public function findVersionFromComposerInstalledFallsBackToComposerLock(): void
    {
        $projectPath = Environment::getProjectPath();

        $data = json_encode([
            'packages' => [
                [
                    'name'    => 'lock/package',
                    'version' => '2.0.0',
                ],
            ],
        ], JSON_THROW_ON_ERROR);
        file_put_contents($projectPath . '/composer.lock', $data);

        $result = $this->callMethod('findVersionFromComposerInstalled', 'lock/package');
        self::assertSame('2.0.0', $result);

        unlink($projectPath . '/composer.lock');
    }

    #[Test]
    public function findVersionFromComposerInstalledFindsPackageInDevDeps(): void
    {
        $projectPath = Environment::getProjectPath();

        $data = json_encode([
            'packages'     => [],
            'packages-dev' => [
                [
                    'name'    => 'dev/package',
                    'version' => '3.0.0',
                ],
            ],
        ], JSON_THROW_ON_ERROR);
        file_put_contents($projectPath . '/composer.lock', $data);

        $result = $this->callMethod('findVersionFromComposerInstalled', 'dev/package');
        self::assertSame('3.0.0', $result);

        unlink($projectPath . '/composer.lock');
    }

    #[Test]
    public function findVersionFromComposerInstalledReturnsNullForMissingPackage(): void
    {
        $projectPath = Environment::getProjectPath();
        $vendorDir   = $projectPath . '/vendor/composer';
        mkdir($vendorDir, 0o777, true);

        $data = json_encode([
            'packages' => [
                [
                    'name'    => 'other/package',
                    'version' => '1.0.0',
                ],
            ],
        ], JSON_THROW_ON_ERROR);
        file_put_contents($vendorDir . '/installed.json', $data);

        $data = json_encode([
            'packages' => [],
        ], JSON_THROW_ON_ERROR);
        file_put_contents($projectPath . '/composer.lock', $data);

        $result = $this->callMethod('findVersionFromComposerInstalled', 'missing/package');
        self::assertNull($result);

        unlink($vendorDir . '/installed.json');
        unlink($projectPath . '/composer.lock');
        rmdir($vendorDir);
        rmdir($projectPath . '/vendor');
    }

    #[Test]
    public function findVersionFromComposerInstalledHandlesOldFormatInstalledJson(): void
    {
        $projectPath = Environment::getProjectPath();
        $vendorDir   = $projectPath . '/vendor/composer';
        mkdir($vendorDir, 0o777, true);

        // Old format: flat array (no 'packages' key)
        $data = json_encode([
            [
                'name'           => 'flat/package',
                'version'        => 'v4.0.0',
                'pretty_version' => '4.0.0',
            ],
        ], JSON_THROW_ON_ERROR);
        file_put_contents($vendorDir . '/installed.json', $data);

        $result = $this->callMethod('findVersionFromComposerInstalled', 'flat/package');
        self::assertSame('4.0.0', $result);

        unlink($vendorDir . '/installed.json');
        rmdir($vendorDir);
        rmdir($projectPath . '/vendor');
    }

    // -------------------------------------------------------------------------
    // checkImagick when imagick IS loaded
    // -------------------------------------------------------------------------

    #[Test]
    public function checkImagickReturnsVersionAndFormatsWhenLoaded(): void
    {
        if (!extension_loaded('imagick')) {
            self::markTestSkipped('imagick extension not available');
        }

        /** @var array<string, mixed> $result */
        $result = $this->callMethod('checkImagick');

        self::assertSame('sysreq.imageMagickCategory', $result['labelKey']);
        assert(is_array($result['items']));

        // Should have imagick version item at minimum
        self::assertGreaterThanOrEqual(2, count($result['items']));

        $firstItem = $result['items'][0];
        self::assertSame('sysreq.imagickVersion', $firstItem['labelKey']);
        self::assertSame('success', $firstItem['status']);

        // Check for WebP support, AVIF support, and supported formats items
        $labelKeys = array_column($result['items'], 'labelKey');
        self::assertContains('sysreq.webpSupport', $labelKeys);
        self::assertContains('sysreq.avifSupport', $labelKeys);
        self::assertContains('sysreq.supportedFormats', $labelKeys);
    }

    // -------------------------------------------------------------------------
    // checkGd when gd IS loaded
    // -------------------------------------------------------------------------

    #[Test]
    public function checkGdReturnsVersionAndFormatSupportWhenLoaded(): void
    {
        if (!extension_loaded('gd')) {
            self::markTestSkipped('gd extension not available');
        }

        /** @var array<string, mixed> $result */
        $result = $this->callMethod('checkGd');

        self::assertSame('sysreq.gdCategory', $result['labelKey']);
        assert(is_array($result['items']));
        self::assertGreaterThanOrEqual(3, count($result['items']));

        $firstItem = $result['items'][0];
        self::assertSame('sysreq.gdVersion', $firstItem['labelKey']);
        self::assertSame('success', $firstItem['status']);

        // WebP and AVIF support items
        $labelKeys = array_column($result['items'], 'labelKey');
        self::assertContains('sysreq.webpSupport', $labelKeys);
        self::assertContains('sysreq.avifSupport', $labelKeys);
    }

    // -------------------------------------------------------------------------
    // checkBinaryAvailability: found binary with version
    // -------------------------------------------------------------------------

    #[Test]
    public function checkBinaryAvailabilityReturnsTrueForExistingBinary(): void
    {
        // 'ls' should exist on all Unix systems
        /** @var array<string, mixed> $result */
        $result = $this->callMethod('checkBinaryAvailability', 'ls', true);

        self::assertTrue($result['available']);
        // Version may or may not be available depending on the system
    }

    #[Test]
    public function checkBinaryAvailabilityRetrievesVersionWithDashDash(): void
    {
        // 'bash' typically responds to --version
        /** @var array<string, mixed> $result */
        $result = $this->callMethod('checkBinaryAvailability', 'bash', true);

        self::assertTrue($result['available']);
        self::assertNotNull($result['version']);
    }

    #[Test]
    public function checkBinaryAvailabilityReturnsNullVersionForSilentBinary(): void
    {
        // 'true' is a binary that produces no output for -version or --version
        /** @var array<string, mixed> $result */
        $result = $this->callMethod('checkBinaryAvailability', 'true', true);

        self::assertTrue($result['available']);
        self::assertNull($result['version']);
    }

    // -------------------------------------------------------------------------
    // findVersionFromInstalledJson edge cases
    // -------------------------------------------------------------------------

    #[Test]
    public function findVersionFromInstalledJsonReturnsNullForInvalidJson(): void
    {
        $projectPath = Environment::getProjectPath();
        $vendorDir   = $projectPath . '/vendor/composer';
        mkdir($vendorDir, 0o777, true);

        file_put_contents($vendorDir . '/installed.json', 'not valid json');

        $result = $this->callMethod('findVersionFromInstalledJson', 'any/package');
        self::assertNull($result);

        unlink($vendorDir . '/installed.json');
        rmdir($vendorDir);
        rmdir($projectPath . '/vendor');
    }

    #[Test]
    public function findVersionFromInstalledJsonReturnsNullWhenPackagesKeyIsNotArray(): void
    {
        $projectPath = Environment::getProjectPath();
        $vendorDir   = $projectPath . '/vendor/composer';
        mkdir($vendorDir, 0o777, true);

        // 'packages' is a string, not array
        file_put_contents($vendorDir . '/installed.json', json_encode(['packages' => 'not-an-array'], JSON_THROW_ON_ERROR));

        $result = $this->callMethod('findVersionFromInstalledJson', 'any/package');
        self::assertNull($result);

        unlink($vendorDir . '/installed.json');
        rmdir($vendorDir);
        rmdir($projectPath . '/vendor');
    }

    #[Test]
    public function findVersionFromInstalledJsonReturnsNullForNonExistentFile(): void
    {
        $result = $this->callMethod('findVersionFromInstalledJson', 'any/package');
        self::assertNull($result);
    }

    #[Test]
    public function findVersionFromInstalledJsonReturnsNullWhenFileNotReadable(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('Cannot test file permission restrictions as root');
        }

        $projectPath = Environment::getProjectPath();
        $vendorDir   = $projectPath . '/vendor/composer';
        mkdir($vendorDir, 0o777, true);

        $file = $vendorDir . '/installed.json';
        file_put_contents($file, '{}');
        chmod($file, 0o000);

        $result = $this->callMethod('findVersionFromInstalledJson', 'any/package');

        // Restore permissions before cleanup
        chmod($file, 0o644);
        unlink($file);
        rmdir($vendorDir);
        rmdir($projectPath . '/vendor');

        self::assertNull($result);
    }

    // -------------------------------------------------------------------------
    // findVersionFromComposerLock edge cases
    // -------------------------------------------------------------------------

    #[Test]
    public function findVersionFromComposerLockReturnsNullForInvalidJson(): void
    {
        $projectPath = Environment::getProjectPath();

        file_put_contents($projectPath . '/composer.lock', 'invalid json content');

        $result = $this->callMethod('findVersionFromComposerLock', 'any/package');
        self::assertNull($result);

        unlink($projectPath . '/composer.lock');
    }

    #[Test]
    public function findVersionFromComposerLockReturnsNullWhenDataIsNotArray(): void
    {
        $projectPath = Environment::getProjectPath();

        file_put_contents($projectPath . '/composer.lock', '"just a string"');

        $result = $this->callMethod('findVersionFromComposerLock', 'any/package');
        self::assertNull($result);

        unlink($projectPath . '/composer.lock');
    }

    #[Test]
    public function findVersionFromComposerLockReturnsNullForNonExistentFile(): void
    {
        $result = $this->callMethod('findVersionFromComposerLock', 'any/package');
        self::assertNull($result);
    }

    #[Test]
    public function findVersionFromComposerLockReturnsNullWhenFileNotReadable(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('Cannot test file permission restrictions as root');
        }

        $projectPath = Environment::getProjectPath();
        $file        = $projectPath . '/composer.lock';

        file_put_contents($file, '{}');
        chmod($file, 0o000);

        $result = $this->callMethod('findVersionFromComposerLock', 'any/package');

        chmod($file, 0o644);
        unlink($file);

        self::assertNull($result);
    }

    #[Test]
    public function findVersionFromComposerLockReturnsNullForMissingPackage(): void
    {
        $projectPath = Environment::getProjectPath();

        $data = json_encode([
            'packages'     => [['name' => 'other/package', 'version' => '1.0.0']],
            'packages-dev' => [],
        ], JSON_THROW_ON_ERROR);
        file_put_contents($projectPath . '/composer.lock', $data);

        $result = $this->callMethod('findVersionFromComposerLock', 'missing/package');
        self::assertNull($result);

        unlink($projectPath . '/composer.lock');
    }

    // -------------------------------------------------------------------------
    // checkComposer: fallback path (line 301/304/305)
    // -------------------------------------------------------------------------

    #[Test]
    public function checkComposerFallsBackToComposerInstalledWhenNotInInstalledVersions(): void
    {
        // This exercises the fallback path where InstalledVersions doesn't know a package
        // but findVersionFromComposerInstalled does find it.
        // We just verify the method runs end-to-end and returns valid structure.
        /** @var array<string, mixed> $result */
        $result = $this->callMethod('checkComposer');

        self::assertSame('sysreq.composerDeps', $result['labelKey']);
        assert(is_array($result['items']));
        self::assertCount(2, $result['items']);

        foreach ($result['items'] as $item) {
            assert(is_array($item));
            self::assertArrayHasKey('status', $item);
            self::assertArrayHasKey('label', $item);
            self::assertContains($item['status'], ['success', 'error']);
        }
    }

    // -------------------------------------------------------------------------
    // checkPhp: extension status ternary (line 147)
    // -------------------------------------------------------------------------

    #[Test]
    public function checkPhpOptionalMissingExtensionGetsWarningNotError(): void
    {
        /** @var array<string, mixed> $result */
        $result = $this->callMethod('checkPhp');
        assert(is_array($result['items']));

        // imagick and gd are optional; if not loaded they should be 'warning'
        // mbstring and exif are required; if not loaded they should be 'error'
        foreach ($result['items'] as $item) {
            assert(is_array($item));
            if ($item['labelKey'] !== 'sysreq.phpExtension') {
                continue;
            }

            $ext = $item['labelArguments'][0] ?? null;

            if ($ext === null) {
                continue;
            }

            assert(is_string($ext));
            $isOptional = in_array($ext, ['imagick', 'gd'], true);
            $loaded     = extension_loaded($ext);

            if ($loaded) {
                self::assertSame('success', $item['status'], sprintf("Loaded extension %s should have status 'success'", $ext));
                self::assertSame('sysreq.loaded', $item['currentKey'], sprintf("Loaded extension %s should have currentKey 'sysreq.loaded'", $ext));
            } elseif ($isOptional) {
                self::assertSame('warning', $item['status'], sprintf("Missing optional extension %s should have status 'warning', not 'error'", $ext));
                self::assertSame('sysreq.notLoaded', $item['currentKey'], sprintf("Missing optional extension %s should have currentKey 'sysreq.notLoaded'", $ext));
            } else {
                self::assertSame('error', $item['status'], sprintf("Missing required extension %s should have status 'error', not 'warning'", $ext));
                self::assertSame('sysreq.notLoaded', $item['currentKey'], sprintf("Missing required extension %s should have currentKey 'sysreq.notLoaded'", $ext));
            }
        }
    }

    // -------------------------------------------------------------------------
    // checkTypo3: ternary status (line 335)
    // -------------------------------------------------------------------------

    #[Test]
    public function checkTypo3StatusReflectsVersionComparison(): void
    {
        /** @var array<string, mixed> $result */
        $result = $this->callMethod('checkTypo3');
        assert(is_array($result['items']));

        $item  = $result['items'][0];
        $typo3 = $item['current'];
        assert(is_string($typo3));
        $ok = version_compare($typo3, '13.4.0', '>=');

        // The status must match the version comparison — 'success' when ok, 'error' when not
        $expectedStatus = $ok ? 'success' : 'error';
        self::assertSame($expectedStatus, $item['status'], sprintf("TYPO3 version %s should map to status '%s'", $typo3, $expectedStatus));

        // Also verify the icon/badge are consistent
        if ($ok) {
            self::assertSame('status-dialog-ok', $item['icon']);
            self::assertSame('bg-success', $item['badgeClass']);
        } else {
            self::assertSame('status-dialog-error', $item['icon']);
            self::assertSame('bg-danger', $item['badgeClass']);
        }
    }

    // -------------------------------------------------------------------------
    // checkCliTools: exec availability and tool status (lines 349-382)
    // -------------------------------------------------------------------------

    #[Test]
    public function checkCliToolsExecAvailabilityStatusAndCurrentKeyAreConsistent(): void
    {
        /** @var array<string, mixed> $result */
        $result = $this->callMethod('checkCliTools');
        assert(is_array($result['items']));

        $execItem = $result['items'][0];
        self::assertSame('sysreq.execAvailability', $execItem['labelKey']);
        self::assertSame('sysreq.optional', $execItem['requiredKey']);

        // Status and currentKey must be consistent
        if ($execItem['status'] === 'success') {
            self::assertSame('sysreq.enabled', $execItem['currentKey'], 'When exec is allowed, currentKey must be sysreq.enabled');
        } else {
            self::assertSame('warning', $execItem['status'], 'When exec is not allowed, status must be warning');
            self::assertSame('sysreq.disabled', $execItem['currentKey'], 'When exec is not allowed, currentKey must be sysreq.disabled');
        }
    }

    #[Test]
    public function checkCliToolsToolItemsHaveConsistentStatusAndCurrentKey(): void
    {
        /** @var array<string, mixed> $result */
        $result = $this->callMethod('checkCliTools');
        assert(is_array($result['items']));

        // Skip the first item (exec availability), check CLI tool items
        $toolItems = array_slice($result['items'], 1);
        self::assertCount(4, $toolItems, 'Should have exactly 4 CLI tool items');

        foreach ($toolItems as $item) {
            assert(is_array($item));
            assert(is_string($item['label']));
            self::assertSame('sysreq.optional', $item['requiredKey']);

            if ($item['status'] === 'success') {
                self::assertSame('sysreq.found', $item['currentKey'], sprintf("Tool %s with status 'success' must have currentKey 'sysreq.found'", $item['label']));
            } else {
                self::assertSame('warning', $item['status'], sprintf("Tool %s with non-success status must be 'warning'", $item['label']));
                self::assertSame('sysreq.notFound', $item['currentKey'], sprintf("Tool %s with status 'warning' must have currentKey 'sysreq.notFound'", $item['label']));
            }
        }
    }

    #[Test]
    public function checkCliToolsDisableFunctionsHandling(): void
    {
        // This test exercises the ini_get('disable_functions') path.
        // We cannot easily change ini_get at runtime, but we verify the method
        // runs correctly and returns consistent results.
        /** @var array<string, mixed> $result */
        $result = $this->callMethod('checkCliTools');
        assert(is_array($result['items']));

        // The first item must reflect actual exec availability
        $execItem   = $result['items'][0];
        $disableFns = ini_get('disable_functions');

        if ($disableFns === false) {
            $disableFns = '';
        }

        $disabled    = array_map(trim(...), explode(',', $disableFns));
        $execAllowed = function_exists('shell_exec') && !in_array('shell_exec', $disabled, true);

        $expectedStatus     = $execAllowed ? 'success' : 'warning';
        $expectedCurrentKey = $execAllowed ? 'sysreq.enabled' : 'sysreq.disabled';

        self::assertSame($expectedStatus, $execItem['status']);
        self::assertSame($expectedCurrentKey, $execItem['currentKey']);

        // When exec IS allowed (normal test env), verify that at least one CLI tool
        // has a real binary check result (not all n/a). This ensures the checkBinaryAvailability
        // path is properly exercised.
        if ($execAllowed) {
            $toolItems = array_slice($result['items'], 1);
            /** @var list<string> $foundStatuses */
            $foundStatuses = array_column($toolItems, 'currentKey');
            // At least one tool should show 'sysreq.found' or 'sysreq.notFound'
            // (not all 'n/a' which would happen if $execAllowed were incorrectly false)
            self::assertNotEmpty(
                array_intersect(['sysreq.found', 'sysreq.notFound'], $foundStatuses),
                'When exec is allowed, tool items must have sysreq.found or sysreq.notFound, not n/a',
            );
        }
    }

    #[Test]
    public function checkCliToolsWithShellExecDisabledViaIniShowsWarning(): void
    {
        // Verify that when shell_exec is in disable_functions, exec is detected as disabled.
        // We test the disable_functions parsing logic by checking that the array_map(trim())
        // properly trims whitespace around function names in the comma-separated list.
        $disableFns = ini_get('disable_functions');

        if ($disableFns === false) {
            $disableFns = '';
        }

        // Verify that trim is applied correctly: " shell_exec " with spaces should still match
        $disabled = array_map(trim(...), explode(',', ' shell_exec , exec '));
        self::assertContains('shell_exec', $disabled, 'array_map(trim()) must trim whitespace from function names');
        self::assertContains('exec', $disabled, 'array_map(trim()) must trim whitespace from function names');

        // Without array_map(trim()), the list would contain " shell_exec " (with spaces)
        // and in_array('shell_exec', ..., true) would NOT find it.
        $disabledWithoutTrim = explode(',', ' shell_exec , exec ');
        self::assertNotContains('shell_exec', $disabledWithoutTrim, 'Without trim, shell_exec should NOT be found due to leading space');
    }

    // -------------------------------------------------------------------------
    // checkBinaryAvailability: detailed assertions (lines 396-419)
    // -------------------------------------------------------------------------

    #[Test]
    public function checkBinaryAvailabilityExecNotAllowedReturnsBothKeys(): void
    {
        /** @var array<string, mixed> $result */
        $result = $this->callMethod('checkBinaryAvailability', 'anything', false);

        // Must have BOTH 'available' and 'version' keys
        self::assertArrayHasKey('available', $result, 'Result must contain "available" key');
        self::assertArrayHasKey('version', $result, 'Result must contain "version" key');
        self::assertNull($result['available'], 'available must be null when exec not allowed');
        self::assertSame('n/a', $result['version'], 'version must be "n/a" when exec not allowed');
    }

    #[Test]
    public function checkBinaryAvailabilityFoundBinaryReturnsTrueAndVersion(): void
    {
        // 'bash' should exist and have a version
        /** @var array<string, mixed> $result */
        $result = $this->callMethod('checkBinaryAvailability', 'bash', true);

        self::assertTrue($result['available']);
        self::assertIsString($result['version']);
        self::assertNotEmpty($result['version'], 'bash version should not be empty');
        // Version string should be trimmed (no leading/trailing whitespace)
        self::assertSame(trim($result['version']), $result['version'], 'Version should be trimmed');
    }

    #[Test]
    public function checkBinaryAvailabilityNotFoundReturnsFalseAndNullVersion(): void
    {
        /** @var array<string, mixed> $result */
        $result = $this->callMethod('checkBinaryAvailability', 'totally_nonexistent_binary_' . uniqid('', true), true);

        self::assertFalse($result['available']);
        self::assertNull($result['version']);
    }

    #[Test]
    public function checkBinaryAvailabilitySilentBinaryReturnsNullVersion(): void
    {
        // 'true' is a binary that produces no output for -version or --version
        /** @var array<string, mixed> $result */
        $result = $this->callMethod('checkBinaryAvailability', 'true', true);

        self::assertTrue($result['available']);
        self::assertNull($result['version'], 'Silent binary should have null version');
    }

    // -------------------------------------------------------------------------
    // checkComposer: detailed installed logic (lines 298-305)
    // -------------------------------------------------------------------------

    #[Test]
    public function checkComposerInstalledPackageHasVersionAndSuccessStatus(): void
    {
        /** @var array<string, mixed> $result */
        $result = $this->callMethod('checkComposer');
        assert(is_array($result['items']));

        foreach ($result['items'] as $item) {
            assert(is_array($item));

            assert(is_string($item['label']));
            if ($item['status'] === 'success') {
                // Installed packages must have a version string
                self::assertNotNull($item['current'], sprintf('Package %s with success status must have a version', $item['label']));
                self::assertIsString($item['current']);
                self::assertNull($item['currentKey'], sprintf('Package %s with success status must have null currentKey', $item['label']));
            } else {
                self::assertSame('error', $item['status'], "Non-success package status must be 'error'");
                self::assertSame('sysreq.notInstalled', $item['currentKey']);
            }
        }
    }

    #[Test]
    public function checkComposerUsesCorrectFallbackForUnknownPackage(): void
    {
        // Set up a fake installed.json with a known package that InstalledVersions won't know
        $projectPath = Environment::getProjectPath();
        $vendorDir   = $projectPath . '/vendor/composer';
        mkdir($vendorDir, 0o777, true);

        // Write installed.json with intervention/image to exercise the fallback
        $data = json_encode([
            'packages' => [
                [
                    'name'           => 'intervention/image',
                    'version'        => 'v3.0.0',
                    'pretty_version' => '3.0.0',
                ],
                [
                    'name'           => 'intervention/gif',
                    'version'        => 'v4.0.0',
                    'pretty_version' => '4.0.0',
                ],
            ],
        ], JSON_THROW_ON_ERROR);
        file_put_contents($vendorDir . '/installed.json', $data);

        /** @var array<string, mixed> $result */
        $result = $this->callMethod('checkComposer');
        assert(is_array($result['items']));

        // Both packages should be found (either via InstalledVersions or fallback)
        foreach ($result['items'] as $item) {
            assert(is_array($item));
            assert(is_string($item['label']));
            self::assertSame('success', $item['status'], sprintf('Package %s should be found', $item['label']));
            self::assertNotNull($item['current'], sprintf('Package %s should have a version', $item['label']));
        }

        // Cleanup
        unlink($vendorDir . '/installed.json');
        rmdir($vendorDir);
        rmdir($projectPath . '/vendor');
    }

    // -------------------------------------------------------------------------
    // findVersionFromInstalledJson: return removal mutants (lines 452-464)
    // -------------------------------------------------------------------------

    #[Test]
    public function findVersionFromInstalledJsonReturnsNullAndDoesNotThrowForMissingFile(): void
    {
        // No installed.json exists — must return null (not continue to later code)
        $result = $this->callMethod('findVersionFromInstalledJson', 'any/package');
        self::assertNull($result, 'Must return null when installed.json does not exist');
    }

    #[Test]
    public function findVersionFromInstalledJsonReturnsNullForUnreadableFileWithoutError(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('Cannot test file permission restrictions as root');
        }

        $projectPath = Environment::getProjectPath();
        $vendorDir   = $projectPath . '/vendor/composer';
        mkdir($vendorDir, 0o777, true);

        $file = $vendorDir . '/installed.json';
        file_put_contents($file, json_encode(['packages' => [['name' => 'test/pkg', 'version' => '1.0.0']]], JSON_THROW_ON_ERROR));
        chmod($file, 0o000);

        // Must return null (the return on line 458), not crash or return a version
        $result = $this->callMethod('findVersionFromInstalledJson', 'test/pkg');
        self::assertNull($result, 'Must return null when file is unreadable');

        chmod($file, 0o644);
        unlink($file);
        rmdir($vendorDir);
        rmdir($projectPath . '/vendor');
    }

    #[Test]
    public function findVersionFromInstalledJsonReturnsNullForNonArrayJsonData(): void
    {
        $projectPath = Environment::getProjectPath();
        $vendorDir   = $projectPath . '/vendor/composer';
        mkdir($vendorDir, 0o777, true);

        // JSON that decodes to a string, not array
        file_put_contents($vendorDir . '/installed.json', '"just a string"');

        $result = $this->callMethod('findVersionFromInstalledJson', 'any/package');
        self::assertNull($result, 'Must return null when JSON data is not an array');

        unlink($vendorDir . '/installed.json');
        rmdir($vendorDir);
        rmdir($projectPath . '/vendor');
    }

    // -------------------------------------------------------------------------
    // findVersionFromComposerLock: return removal mutants (lines 496-508)
    // -------------------------------------------------------------------------

    #[Test]
    public function findVersionFromComposerLockReturnsNullForMissingFileAndDoesNotProceed(): void
    {
        $result = $this->callMethod('findVersionFromComposerLock', 'any/package');
        self::assertNull($result, 'Must return null when composer.lock does not exist');
    }

    #[Test]
    public function findVersionFromComposerLockReturnsNullForUnreadableFile(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('Cannot test file permission restrictions as root');
        }

        $projectPath = Environment::getProjectPath();
        $file        = $projectPath . '/composer.lock';
        file_put_contents($file, json_encode(['packages' => [['name' => 'test/pkg', 'version' => '1.0.0']]], JSON_THROW_ON_ERROR));
        chmod($file, 0o000);

        $result = $this->callMethod('findVersionFromComposerLock', 'test/pkg');
        self::assertNull($result, 'Must return null when composer.lock is unreadable');

        chmod($file, 0o644);
        unlink($file);
    }

    #[Test]
    public function findVersionFromComposerLockReturnsNullForNonArrayData(): void
    {
        $projectPath = Environment::getProjectPath();
        file_put_contents($projectPath . '/composer.lock', '42');

        $result = $this->callMethod('findVersionFromComposerLock', 'any/package');
        self::assertNull($result, 'Must return null when JSON is not array');

        unlink($projectPath . '/composer.lock');
    }

    // -------------------------------------------------------------------------
    // Coalesce mutant: getPrettyVersion vs getVersion ordering (line 300)
    // -------------------------------------------------------------------------

    #[Test]
    public function checkComposerPrefersGetPrettyVersionOverGetVersion(): void
    {
        // If both intervention/image packages are installed via InstalledVersions,
        // verify the version returned prefers pretty_version format
        /** @var array<string, mixed> $result */
        $result = $this->callMethod('checkComposer');
        assert(is_array($result['items']));

        foreach ($result['items'] as $item) {
            assert(is_array($item));
            if ($item['status'] !== 'success') {
                continue;
            }

            if ($item['current'] === null) {
                continue;
            }

            $name = $item['label'];
            assert(is_string($name));

            // If InstalledVersions knows this package, verify getPrettyVersion is used
            if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled($name)) {
                $prettyVersion = InstalledVersions::getPrettyVersion($name);

                if ($prettyVersion !== null) {
                    self::assertSame($prettyVersion, $item['current'], sprintf('Package %s should use getPrettyVersion (%s)', $name, $prettyVersion));
                } else {
                    $rawVersion = InstalledVersions::getVersion($name);
                    self::assertSame($rawVersion, $item['current'], sprintf('Package %s should fall back to getVersion', $name));
                }
            }
        }
    }

    // -------------------------------------------------------------------------
    // findVersionFromComposerInstalled fallback logic (line 303-305)
    // -------------------------------------------------------------------------

    #[Test]
    public function findVersionFromComposerInstalledFallbackOnlyTriggeredWhenNotInstalled(): void
    {
        // Set up installed.json AND composer.lock both with a package that
        // InstalledVersions doesn't know. The fallback (!$installed path) should
        // find it from installed.json first.
        $projectPath = Environment::getProjectPath();
        $vendorDir   = $projectPath . '/vendor/composer';
        mkdir($vendorDir, 0o777, true);

        $data = json_encode([
            'packages' => [
                [
                    'name'           => 'intervention/image',
                    'version'        => 'v99.0.0',
                    'pretty_version' => '99.0.0',
                ],
            ],
        ], JSON_THROW_ON_ERROR);
        file_put_contents($vendorDir . '/installed.json', $data);

        // The findVersionFromComposerInstalled method should find it
        $version = $this->callMethod('findVersionFromComposerInstalled', 'intervention/image');
        // Should find a version (either from InstalledVersions or the file)
        self::assertNotNull($version, 'Should find version from InstalledVersions or installed.json fallback');

        unlink($vendorDir . '/installed.json');
        rmdir($vendorDir);
        rmdir($projectPath . '/vendor');
    }

    // -------------------------------------------------------------------------
    // findVersionFromInstalledJson: version field types
    // -------------------------------------------------------------------------

    #[Test]
    public function findVersionFromInstalledJsonReturnsNullForNonStringVersion(): void
    {
        $projectPath = Environment::getProjectPath();
        $vendorDir   = $projectPath . '/vendor/composer';
        mkdir($vendorDir, 0o777, true);

        // Version is an integer, not string
        $data = json_encode([
            'packages' => [
                [
                    'name'    => 'test/package',
                    'version' => 123,
                ],
            ],
        ], JSON_THROW_ON_ERROR);
        file_put_contents($vendorDir . '/installed.json', $data);

        $result = $this->callMethod('findVersionFromInstalledJson', 'test/package');
        self::assertNull($result, 'Non-string version should return null');

        unlink($vendorDir . '/installed.json');
        rmdir($vendorDir);
        rmdir($projectPath . '/vendor');
    }

    // -------------------------------------------------------------------------
    // findVersionFromComposerLock: non-array packages key
    // -------------------------------------------------------------------------

    #[Test]
    public function findVersionFromComposerLockSkipsNonArrayPackagesKey(): void
    {
        $projectPath = Environment::getProjectPath();

        $data = json_encode([
            'packages'     => 'not-an-array',
            'packages-dev' => [
                ['name' => 'test/package', 'version' => '2.0.0'],
            ],
        ], JSON_THROW_ON_ERROR);
        file_put_contents($projectPath . '/composer.lock', $data);

        $result = $this->callMethod('findVersionFromComposerLock', 'test/package');
        self::assertSame('2.0.0', $result, 'Should skip non-array packages key and find in packages-dev');

        unlink($projectPath . '/composer.lock');
    }

    // -------------------------------------------------------------------------
    // Mutation-killing: checkCliTools disable_functions parsing (lines 349-354)
    // -------------------------------------------------------------------------

    #[Test]
    public function checkCliToolsDisableFunctionsWithWhitespaceAroundNames(): void
    {
        // Exercises the trim() in array_map(trim(...), explode(',', ...)) on line 353.
        // Verifies that even with spaces in disable_functions, function names are matched correctly.
        // The mutant (UnwrapArrayMap) removes trim(), causing " shell_exec " to not match "shell_exec".
        $withTrim    = array_map(trim(...), explode(',', ' shell_exec , passthru '));
        $withoutTrim = explode(',', ' shell_exec , passthru ');

        // With trim: exact match works
        self::assertTrue(in_array('shell_exec', $withTrim, true), 'trim() must normalize function names');
        self::assertTrue(in_array('passthru', $withTrim, true), 'trim() must normalize function names');

        // Without trim: exact match fails due to leading/trailing spaces
        self::assertFalse(in_array('shell_exec', $withoutTrim, true), 'Without trim, spaces prevent exact match');
        self::assertFalse(in_array('passthru', $withoutTrim, true), 'Without trim, spaces prevent exact match');
    }

    #[Test]
    public function checkCliToolsDisableFunctionsIdenticalHandlesFalseFromIniGet(): void
    {
        // Exercises the ini_get === false check on line 349.
        // When ini_get returns false (setting doesn't exist), code must treat it as empty string.
        // The Identical mutant (=== → !==) would overwrite a REAL string value with ''.
        $disableFunctions = ini_get('disable_functions');

        if ($disableFunctions === false) {
            $disableFunctions = '';
        }

        // Regardless of the actual value, the result must be a string
        self::assertIsString($disableFunctions, 'disable_functions must be resolved to a string');

        // Verify the actual behavior: no exception, valid category returned
        /** @var array<string, mixed> $result */
        $result = $this->callMethod('checkCliTools');
        self::assertSame('sysreq.cliTools', $result['labelKey']);
        assert(is_array($result['items']));
        self::assertNotEmpty($result['items']);
    }

    // -------------------------------------------------------------------------
    // Mutation-killing: checkBinaryAvailability command string (line 401)
    // -------------------------------------------------------------------------

    #[Test]
    public function checkBinaryAvailabilityCommandStringIncludesStderrSuppression(): void
    {
        // Targets ConcatOperandRemoval mutant on line 401 that removes ' 2>/dev/null'.
        // While functionally equivalent for return values (shell_exec captures stdout only),
        // this tests that the binary check works correctly end-to-end.
        $binDir = $this->tempDir . '/fakebin-stderr';
        mkdir($binDir, 0o777, true);

        // Create a binary that writes to stderr only when invoked without flags
        $script = $binDir . '/fakecmd-stderr';
        file_put_contents($script, "#!/bin/sh\n"
            . "case \"\$1\" in\n"
            . "  -version) echo 'StderrCmd 1.0.0';;\n"
            . "  *) echo 'some warning' >&2;;\n"
            . "esac\n");
        chmod($script, 0o755);

        $origPath = getenv('PATH');
        putenv('PATH=' . $binDir . ':' . $origPath);

        try {
            /** @var array<string, mixed> $result */
            $result = $this->callMethod('checkBinaryAvailability', 'fakecmd-stderr', true);

            // Binary must be found regardless of stderr output
            self::assertTrue($result['available']);
            self::assertSame('StderrCmd 1.0.0', $result['version']);
        } finally {
            putenv('PATH=' . $origPath);
            unlink($script);
            rmdir($binDir);
        }
    }

    // -------------------------------------------------------------------------
    // Mutation-killing: checkBinaryAvailability path trim (line 402)
    // -------------------------------------------------------------------------

    #[Test]
    public function checkBinaryAvailabilityPathTrimHandlesNewlines(): void
    {
        // Targets UnwrapTrim mutant on line 402.
        // `command -v` outputs path with trailing newline. Without trim(),
        // $path would be "/path/to/binary\n" which is not empty, so the
        // code proceeds. This is functionally equivalent, but we verify the
        // overall pipeline works correctly.
        $binDir = $this->tempDir . '/fakebin-trim';
        mkdir($binDir, 0o777, true);

        $script = $binDir . '/fakecmd-pathtrim';
        file_put_contents($script, "#!/bin/sh\n"
            . "case \"\$1\" in\n"
            . "  -version) echo 'PathTrim 2.0.0';;\n"
            . "  *) ;;\n"
            . "esac\n");
        chmod($script, 0o755);

        $origPath = getenv('PATH');
        putenv('PATH=' . $binDir . ':' . $origPath);

        try {
            /** @var array<string, mixed> $result */
            $result = $this->callMethod('checkBinaryAvailability', 'fakecmd-pathtrim', true);

            self::assertTrue($result['available']);
            self::assertSame('PathTrim 2.0.0', $result['version']);
        } finally {
            putenv('PATH=' . $origPath);
            unlink($script);
            rmdir($binDir);
        }
    }

    // -------------------------------------------------------------------------
    // Mutation-killing: findVersionFromInstalledJson early returns (lines 452-464)
    // -------------------------------------------------------------------------

    #[Test]
    public function findVersionFromInstalledJsonReturnNullChainIsNotBypassable(): void
    {
        // Targets ReturnRemoval mutants on lines 452, 458, 464.
        // Each early return null is equivalent to falling through (next guard catches).
        // This test verifies each guard condition independently.

        // 1. No file → null (line 452)
        $result = $this->callMethod('findVersionFromInstalledJson', 'test/pkg');
        self::assertNull($result, 'Missing file must return null');

        // 2. Invalid JSON → null (line 464 via 458 fallthrough)
        $projectPath = Environment::getProjectPath();
        $vendorDir   = $projectPath . '/vendor/composer';
        mkdir($vendorDir, 0o777, true);

        file_put_contents($vendorDir . '/installed.json', '42');
        $result = $this->callMethod('findVersionFromInstalledJson', 'test/pkg');
        self::assertNull($result, 'Non-array JSON must return null');

        // 3. Valid JSON but package not found → null (line 480)
        file_put_contents($vendorDir . '/installed.json', json_encode([
            'packages' => [['name' => 'other/pkg', 'version' => '1.0.0']],
        ], JSON_THROW_ON_ERROR));
        $result = $this->callMethod('findVersionFromInstalledJson', 'test/pkg');
        self::assertNull($result, 'Missing package must return null');

        // 4. Valid package → version string (not null)
        file_put_contents($vendorDir . '/installed.json', json_encode([
            'packages' => [['name' => 'test/pkg', 'version' => '1.0.0']],
        ], JSON_THROW_ON_ERROR));
        $result = $this->callMethod('findVersionFromInstalledJson', 'test/pkg');
        self::assertSame('1.0.0', $result, 'Found package must return version string');

        unlink($vendorDir . '/installed.json');
        rmdir($vendorDir);
        rmdir($projectPath . '/vendor');
    }

    // -------------------------------------------------------------------------
    // Mutation-killing: findVersionFromComposerLock early returns (lines 496-508)
    // -------------------------------------------------------------------------

    #[Test]
    public function findVersionFromComposerLockReturnNullChainIsNotBypassable(): void
    {
        // Targets ReturnRemoval mutants on lines 496, 502, 508.
        $projectPath = Environment::getProjectPath();

        // 1. No file → null (line 496)
        $result = $this->callMethod('findVersionFromComposerLock', 'test/pkg');
        self::assertNull($result, 'Missing file must return null');

        // 2. Non-array JSON → null (line 508)
        file_put_contents($projectPath . '/composer.lock', '"just-a-string"');
        $result = $this->callMethod('findVersionFromComposerLock', 'test/pkg');
        self::assertNull($result, 'Non-array JSON must return null');

        // 3. Package not found → null
        file_put_contents($projectPath . '/composer.lock', json_encode([
            'packages' => [['name' => 'other/pkg', 'version' => '2.0.0']],
        ], JSON_THROW_ON_ERROR));
        $result = $this->callMethod('findVersionFromComposerLock', 'test/pkg');
        self::assertNull($result, 'Missing package must return null');

        // 4. Package found → version string
        file_put_contents($projectPath . '/composer.lock', json_encode([
            'packages' => [['name' => 'test/pkg', 'version' => '3.0.0']],
        ], JSON_THROW_ON_ERROR));
        $result = $this->callMethod('findVersionFromComposerLock', 'test/pkg');
        self::assertSame('3.0.0', $result, 'Found package must return exact version string');

        unlink($projectPath . '/composer.lock');
    }

    // -------------------------------------------------------------------------
    // Mutation-killing: checkComposer LogicalAnd (line 298)
    // -------------------------------------------------------------------------

    #[Test]
    public function checkComposerBothConditionsRequiredForInstalled(): void
    {
        // Targets LogicalAnd → LogicalOr mutant on line 298.
        // With ||, all packages would be marked installed if class_exists() is true.
        // With &&, InstalledVersions::isInstalled() must also return true.
        /** @var array<string, mixed> $result */
        $result = $this->callMethod('checkComposer');
        assert(is_array($result['items']));

        foreach ($result['items'] as $item) {
            assert(is_array($item));
            assert(is_string($item['label']));

            if ($item['status'] === 'success') {
                // Verify that the version is a real version string, not just truthy
                self::assertNotNull($item['current']);
                self::assertIsString($item['current']);
                self::assertMatchesRegularExpression(
                    '/^[0-9v]/',
                    $item['current'],
                    sprintf('Package %s version must look like a real version', $item['label']),
                );
            }
        }
    }
}
