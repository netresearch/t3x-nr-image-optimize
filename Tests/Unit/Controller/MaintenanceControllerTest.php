<?php

/*
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\NrImageOptimize\Tests\Unit\Controller;

use Netresearch\NrImageOptimize\Controller\MaintenanceController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;

/**
 * Tests for MaintenanceController private helper methods.
 *
 * The controller depends on final TYPO3 classes (ModuleTemplateFactory) which cannot
 * be mocked, so we use ReflectionClass::newInstanceWithoutConstructor() to test the
 * private helper methods (getDirectoryStats, formatBytes) in isolation.
 */
#[CoversClass(MaintenanceController::class)]
class MaintenanceControllerTest extends TestCase
{
    private MaintenanceController $controller;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/nr-image-optimize-controller-test-' . uniqid('', true);
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

        $reflection       = new ReflectionClass(MaintenanceController::class);
        $this->controller = $reflection->newInstanceWithoutConstructor();
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
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($dir);
    }

    private function callMethod(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(MaintenanceController::class, $method);

        return $reflection->invoke($this->controller, ...$arguments);
    }

    #[Test]
    public function formatBytesReturnsFormattedValue(): void
    {
        self::assertSame('0 B', $this->callMethod('formatBytes', 0));
        self::assertSame('512 B', $this->callMethod('formatBytes', 512));
        self::assertSame('1 KB', $this->callMethod('formatBytes', 1024));
        self::assertSame('1.5 KB', $this->callMethod('formatBytes', 1536));
        self::assertSame('1 MB', $this->callMethod('formatBytes', 1048576));
        self::assertSame('1 GB', $this->callMethod('formatBytes', 1073741824));
    }

    #[Test]
    public function formatBytesHandlesNegativeValues(): void
    {
        self::assertSame('0 B', $this->callMethod('formatBytes', -100));
    }

    #[Test]
    public function formatBytesHandlesSmallValues(): void
    {
        self::assertSame('1 B', $this->callMethod('formatBytes', 1));
        self::assertSame('999 B', $this->callMethod('formatBytes', 999));
    }

    #[Test]
    public function formatBytesHandlesExactBoundaries(): void
    {
        self::assertSame('1 KB', $this->callMethod('formatBytes', 1024));
        self::assertSame('1 MB', $this->callMethod('formatBytes', 1024 * 1024));
        self::assertSame('1 GB', $this->callMethod('formatBytes', 1024 * 1024 * 1024));
    }

    #[Test]
    public function getDirectoryStatsReturnsEmptyForNonExistentDirectory(): void
    {
        /** @var array<string, mixed> $result */
        $result = $this->callMethod('getDirectoryStats', '/non/existent/path');

        self::assertSame(0, $result['count']);
        self::assertSame(0, $result['size']);
        self::assertSame(0, $result['directories']);
        self::assertSame([], $result['largestFiles']);
        self::assertSame([], $result['fileTypes']);
        self::assertNull($result['oldestFile']);
        self::assertNull($result['newestFile']);
    }

    #[Test]
    public function getDirectoryStatsCountsFilesAndDirectories(): void
    {
        $testDir = $this->tempDir . '/stats-test';
        mkdir($testDir, 0o777, true);
        mkdir($testDir . '/subdir', 0o777, true);

        file_put_contents($testDir . '/file1.jpg', str_repeat('x', 100));
        file_put_contents($testDir . '/file2.png', str_repeat('y', 200));
        file_put_contents($testDir . '/subdir/file3.jpg', str_repeat('z', 300));

        /** @var array<string, mixed> $result */
        $result = $this->callMethod('getDirectoryStats', $testDir);

        self::assertSame(3, $result['count']);
        self::assertSame(1, $result['directories']);
        self::assertSame(600, $result['size']);
        self::assertNotNull($result['oldestFile']);
        self::assertNotNull($result['newestFile']);
    }

    #[Test]
    public function getDirectoryStatsTracksFileTypes(): void
    {
        $testDir = $this->tempDir . '/filetype-test';
        mkdir($testDir, 0o777, true);

        file_put_contents($testDir . '/a.jpg', str_repeat('a', 100));
        file_put_contents($testDir . '/b.jpg', str_repeat('b', 200));
        file_put_contents($testDir . '/c.png', str_repeat('c', 50));

        /** @var array<string, mixed> $result */
        $result = $this->callMethod('getDirectoryStats', $testDir);

        self::assertArrayHasKey('jpg', $result['fileTypes']);
        self::assertArrayHasKey('png', $result['fileTypes']);
        self::assertSame(2, $result['fileTypes']['jpg']['count']);
        self::assertSame(300, $result['fileTypes']['jpg']['size']);
        self::assertSame(1, $result['fileTypes']['png']['count']);
        self::assertSame(50, $result['fileTypes']['png']['size']);
    }

    #[Test]
    public function getDirectoryStatsLimitsLargestFilesToFive(): void
    {
        $testDir = $this->tempDir . '/largest-test';
        mkdir($testDir, 0o777, true);

        for ($i = 1; $i <= 7; ++$i) {
            file_put_contents($testDir . '/file' . $i . '.jpg', str_repeat('x', $i * 100));
        }

        /** @var array<string, mixed> $result */
        $result = $this->callMethod('getDirectoryStats', $testDir);

        self::assertCount(5, $result['largestFiles']);
        // Should be sorted by size descending
        self::assertSame(700, $result['largestFiles'][0]['size']);
        self::assertSame(600, $result['largestFiles'][1]['size']);

        // Each largest file should have a human-readable size
        foreach ($result['largestFiles'] as $file) {
            self::assertArrayHasKey('sizeHuman', $file);
        }
    }

    #[Test]
    public function getDirectoryStatsSortsFileTypesBySize(): void
    {
        $testDir = $this->tempDir . '/sort-test';
        mkdir($testDir, 0o777, true);

        file_put_contents($testDir . '/small.png', str_repeat('a', 10));
        file_put_contents($testDir . '/large.jpg', str_repeat('b', 1000));

        /** @var array<string, mixed> $result */
        $result = $this->callMethod('getDirectoryStats', $testDir);

        $keys = array_keys($result['fileTypes']);
        self::assertSame('jpg', $keys[0], 'Larger file type should come first');
        self::assertSame('png', $keys[1]);

        foreach ($result['fileTypes'] as $type) {
            self::assertArrayHasKey('sizeHuman', $type);
        }
    }

    #[Test]
    public function getDirectoryStatsTracksOldestAndNewestFiles(): void
    {
        $testDir = $this->tempDir . '/time-test';
        mkdir($testDir, 0o777, true);

        file_put_contents($testDir . '/old.txt', 'old');
        touch($testDir . '/old.txt', time() - 3600);

        file_put_contents($testDir . '/new.txt', 'new');
        touch($testDir . '/new.txt', time());

        /** @var array<string, mixed> $result */
        $result = $this->callMethod('getDirectoryStats', $testDir);

        self::assertSame('old.txt', $result['oldestFile']['name']);
        self::assertSame('new.txt', $result['newestFile']['name']);
        self::assertArrayHasKey('date', $result['oldestFile']);
        self::assertArrayHasKey('date', $result['newestFile']);
        self::assertArrayHasKey('mtime', $result['oldestFile']);
        self::assertArrayHasKey('mtime', $result['newestFile']);
    }

    #[Test]
    public function getDirectoryStatsHandlesEmptyDirectory(): void
    {
        $testDir = $this->tempDir . '/empty-test';
        mkdir($testDir, 0o777, true);

        /** @var array<string, mixed> $result */
        $result = $this->callMethod('getDirectoryStats', $testDir);

        self::assertSame(0, $result['count']);
        self::assertSame(0, $result['size']);
        self::assertSame(0, $result['directories']);
        self::assertSame([], $result['largestFiles']);
        self::assertSame([], $result['fileTypes']);
        self::assertNull($result['oldestFile']);
        self::assertNull($result['newestFile']);
    }

    #[Test]
    public function getDirectoryStatsNormalizesExtensionToLowerCase(): void
    {
        $testDir = $this->tempDir . '/case-test';
        mkdir($testDir, 0o777, true);

        file_put_contents($testDir . '/image.JPG', str_repeat('x', 100));
        file_put_contents($testDir . '/photo.jpg', str_repeat('y', 200));

        /** @var array<string, mixed> $result */
        $result = $this->callMethod('getDirectoryStats', $testDir);

        self::assertArrayHasKey('jpg', $result['fileTypes']);
        self::assertSame(2, $result['fileTypes']['jpg']['count']);
    }

    #[Test]
    public function getDirectoryStatsIncludesRelativePathInLargestFiles(): void
    {
        $testDir = $this->tempDir . '/path-test';
        mkdir($testDir . '/sub', 0o777, true);

        file_put_contents($testDir . '/sub/file.jpg', str_repeat('x', 100));

        /** @var array<string, mixed> $result */
        $result = $this->callMethod('getDirectoryStats', $testDir);

        self::assertSame('sub/file.jpg', $result['largestFiles'][0]['path']);
        self::assertSame('file.jpg', $result['largestFiles'][0]['name']);
    }

    #[Test]
    #[DataProvider('formatBytesProvider')]
    public function formatBytesReturnsExpectedOutput(int $bytes, string $expected): void
    {
        self::assertSame($expected, $this->callMethod('formatBytes', $bytes));
    }

    /**
     * @return iterable<string, array{0: int, 1: string}>
     */
    public static function formatBytesProvider(): iterable
    {
        yield '0 bytes' => [0, '0 B'];
        yield '1 byte' => [1, '1 B'];
        yield '512 bytes' => [512, '512 B'];
        yield '1023 bytes' => [1023, '1023 B'];
        yield 'exactly 1 KB' => [1024, '1 KB'];
        yield '1.5 KB' => [1536, '1.5 KB'];
        yield '1023 KB' => [1024 * 1023, '1023 KB'];
        yield 'exactly 1 MB' => [1048576, '1 MB'];
        yield 'exactly 1 GB' => [1073741824, '1 GB'];
        yield 'negative' => [-100, '0 B'];
        yield '2.5 MB' => [2621440, '2.5 MB'];
        yield '1.25 KB' => [1280, '1.25 KB'];
        yield 'exactly 1 TB' => [1024 * 1024 * 1024 * 1024, '1 TB'];
        yield '1.5 TB' => [(int) (1.5 * 1024 * 1024 * 1024 * 1024), '1.5 TB'];
        yield 'fractional KB truncated to 2 decimals' => [1075, '1.05 KB'];
    }

    #[Test]
    public function getDirectoryStatsSortsFileTypesBySizeDescending(): void
    {
        $testDir = $this->tempDir . '/sort-verify-test';
        mkdir($testDir, 0o777, true);

        file_put_contents($testDir . '/tiny.gif', str_repeat('a', 5));
        file_put_contents($testDir . '/medium.png', str_repeat('b', 500));
        file_put_contents($testDir . '/large.jpg', str_repeat('c', 2000));

        /** @var array<string, mixed> $result */
        $result = $this->callMethod('getDirectoryStats', $testDir);

        $keys = array_keys($result['fileTypes']);
        self::assertSame('jpg', $keys[0]);
        self::assertSame('png', $keys[1]);
        self::assertSame('gif', $keys[2]);
    }

    #[Test]
    public function updateTimestampRecordReturnsNewRecordWhenCurrentIsNull(): void
    {
        /** @var array<string, mixed> $result */
        $result = $this->callMethod('updateTimestampRecord', null, 'file.jpg', 1000000, true);

        self::assertSame('file.jpg', $result['name']);
        self::assertSame(1000000, $result['mtime']);
        self::assertArrayHasKey('date', $result);
    }

    #[Test]
    public function updateTimestampRecordReplacesOlderWhenTrackingOldest(): void
    {
        $current = ['name' => 'current.jpg', 'mtime' => 2000, 'date' => '2020-01-01 00:33:20'];

        /** @var array<string, mixed> $result */
        $result = $this->callMethod('updateTimestampRecord', $current, 'older.jpg', 1000, true);

        self::assertSame('older.jpg', $result['name']);
        self::assertSame(1000, $result['mtime']);
    }

    #[Test]
    public function updateTimestampRecordKeepsCurrentWhenNotOlder(): void
    {
        $current = ['name' => 'current.jpg', 'mtime' => 1000, 'date' => '2020-01-01 00:16:40'];

        /** @var array<string, mixed> $result */
        $result = $this->callMethod('updateTimestampRecord', $current, 'newer.jpg', 2000, true);

        self::assertSame('current.jpg', $result['name']);
        self::assertSame(1000, $result['mtime']);
    }

    #[Test]
    public function updateTimestampRecordKeepsCurrentWhenSameTimestampForOldest(): void
    {
        $current = ['name' => 'current.jpg', 'mtime' => 1000, 'date' => '2020-01-01 00:16:40'];

        /** @var array<string, mixed> $result */
        $result = $this->callMethod('updateTimestampRecord', $current, 'same.jpg', 1000, true);

        self::assertSame('current.jpg', $result['name']);
    }

    #[Test]
    public function updateTimestampRecordReplacesNewerWhenTrackingNewest(): void
    {
        $current = ['name' => 'current.jpg', 'mtime' => 1000, 'date' => '2020-01-01 00:16:40'];

        /** @var array<string, mixed> $result */
        $result = $this->callMethod('updateTimestampRecord', $current, 'newer.jpg', 2000, false);

        self::assertSame('newer.jpg', $result['name']);
        self::assertSame(2000, $result['mtime']);
    }

    #[Test]
    public function updateTimestampRecordKeepsCurrentWhenNotNewerForNewest(): void
    {
        $current = ['name' => 'current.jpg', 'mtime' => 2000, 'date' => '2020-01-01 00:33:20'];

        /** @var array<string, mixed> $result */
        $result = $this->callMethod('updateTimestampRecord', $current, 'older.jpg', 1000, false);

        self::assertSame('current.jpg', $result['name']);
        self::assertSame(2000, $result['mtime']);
    }

    #[Test]
    public function updateTimestampRecordKeepsCurrentWhenSameTimestampForNewest(): void
    {
        $current = ['name' => 'current.jpg', 'mtime' => 2000, 'date' => '2020-01-01 00:33:20'];

        /** @var array<string, mixed> $result */
        $result = $this->callMethod('updateTimestampRecord', $current, 'same.jpg', 2000, false);

        self::assertSame('current.jpg', $result['name']);
    }

    #[Test]
    public function getDirectoryStatsHandlesSingleFile(): void
    {
        $testDir = $this->tempDir . '/single-test';
        mkdir($testDir, 0o777, true);

        file_put_contents($testDir . '/only.webp', str_repeat('x', 500));

        /** @var array<string, mixed> $result */
        $result = $this->callMethod('getDirectoryStats', $testDir);

        self::assertSame(1, $result['count']);
        self::assertSame(500, $result['size']);
        self::assertSame(0, $result['directories']);
        self::assertCount(1, $result['largestFiles']);
        self::assertSame('only.webp', $result['oldestFile']['name']);
        self::assertSame('only.webp', $result['newestFile']['name']);
    }

    // -------------------------------------------------------------------------
    // buildLargestFiles
    // -------------------------------------------------------------------------

    #[Test]
    public function buildLargestFilesSortsAndLimitsFiles(): void
    {
        $files = [];

        for ($i = 1; $i <= 7; ++$i) {
            $files[] = [
                'name' => 'file' . $i . '.jpg',
                'path' => 'file' . $i . '.jpg',
                'size' => $i * 100,
            ];
        }

        /** @var list<array<string, mixed>> $result */
        $result = $this->callMethod('buildLargestFiles', $files);

        self::assertCount(5, $result);
        self::assertSame(700, $result[0]['size']);
        self::assertSame(600, $result[1]['size']);

        foreach ($result as $file) {
            self::assertArrayHasKey('sizeHuman', $file);
        }
    }

    #[Test]
    public function buildLargestFilesHandlesEmptyArray(): void
    {
        /** @var list<array<string, mixed>> $result */
        $result = $this->callMethod('buildLargestFiles', []);

        self::assertSame([], $result);
    }

    #[Test]
    public function buildLargestFilesHandlesFewerThanFiveFiles(): void
    {
        $files = [
            ['name' => 'a.jpg', 'path' => 'a.jpg', 'size' => 200],
            ['name' => 'b.jpg', 'path' => 'b.jpg', 'size' => 100],
        ];

        /** @var list<array<string, mixed>> $result */
        $result = $this->callMethod('buildLargestFiles', $files);

        self::assertCount(2, $result);
        self::assertSame(200, $result[0]['size']);
        self::assertSame(100, $result[1]['size']);
    }
}
