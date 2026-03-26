<?php

/*
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\NrImageOptimize\Tests\Unit\Service;

use Netresearch\NrImageOptimize\Service\SystemRequirementsService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;

#[CoversClass(SystemRequirementsService::class)]
class SystemRequirementsServiceTest extends TestCase
{
    private SystemRequirementsService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $tempDir = sys_get_temp_dir() . '/nr-image-optimize-sysreq-test-' . uniqid('', true);
        mkdir($tempDir, 0o777, true);
        mkdir($tempDir . '/public', 0o777, true);
        mkdir($tempDir . '/var', 0o777, true);
        mkdir($tempDir . '/config', 0o777, true);

        Environment::initialize(
            new ApplicationContext('Testing'),
            true,
            true,
            $tempDir,
            $tempDir . '/public',
            $tempDir . '/var',
            $tempDir . '/config',
            $tempDir . '/public/index.php',
            'UNIX',
        );

        $this->service = new SystemRequirementsService();
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
    public function collectReturnsCategoriesWithCorrectStructure(): void
    {
        $result = $this->service->collect();

        foreach ($result as $category) {
            self::assertArrayHasKey('labelKey', $category); // @phpstan-ignore staticMethod.alreadyNarrowedType
            self::assertArrayHasKey('items', $category); // @phpstan-ignore staticMethod.alreadyNarrowedType
            self::assertIsArray($category['items']); // @phpstan-ignore staticMethod.alreadyNarrowedType
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

        // Find the mbstring extension item (required, not optional)
        $mbstringItem = null;
        foreach ($result['items'] as $item) {
            if ($item['labelKey'] === 'sysreq.phpExtension' && in_array('mbstring', $item['labelArguments'], true)) {
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
        self::assertCount(2, $result['items']);

        // Check intervention/image
        $interventionImage = $result['items'][0];
        self::assertSame('intervention/image', $interventionImage['label']);

        // Check intervention/gif
        $interventionGif = $result['items'][1];
        self::assertSame('intervention/gif', $interventionGif['label']);
    }

    #[Test]
    public function checkTypo3ReturnsVersionStatus(): void
    {
        /** @var array<string, mixed> $result */
        $result = $this->callMethod('checkTypo3');

        self::assertSame('sysreq.typo3Requirements', $result['labelKey']);
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

        // 1 exec availability + 4 CLI tools = at least 5 items
        self::assertGreaterThanOrEqual(5, count($result['items']));

        // First item is exec availability
        $execItem = $result['items'][0];
        self::assertSame('sysreq.execAvailability', $execItem['labelKey']);

        // Check that CLI tool labels are present
        $labels = array_column($result['items'], 'label');
        self::assertContains('CLI: magick', $labels);
        self::assertContains('CLI: convert', $labels);
        self::assertContains('CLI: identify', $labels);
        self::assertContains('CLI: gm (GraphicsMagick)', $labels);
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
}
