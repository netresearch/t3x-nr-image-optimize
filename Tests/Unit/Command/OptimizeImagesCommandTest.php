<?php

/*
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\NrImageOptimize\Tests\Unit\Command;

use function chmod;

use Doctrine\DBAL\Result;

use function file_put_contents;
use function is_file;

use Netresearch\NrImageOptimize\Command\OptimizeImagesCommand;
use Netresearch\NrImageOptimize\Service\ImageOptimizer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

use function putenv;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

use function sys_get_temp_dir;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Utility\GeneralUtility;

use function uniqid;
use function unlink;

/**
 * Unit tests for OptimizeImagesCommand::execute().
 *
 * Scope: orchestration logic in execute() — reading options, handling the
 * "no tools" error, early-exit on no matching files, iterating fixture
 * records, dispatching to ImageOptimizer, reporting per-file results.
 *
 * The command is final so it cannot be subclassed. Instead the ConnectionPool
 * used by the parent's countImages()/iterateViaIndex() helpers is faked via
 * GeneralUtility::addInstance(): each test queues a mock ConnectionPool that
 * returns a pre-seeded QueryBuilder chain. ImageOptimizer is the real class,
 * but binary resolution is pinned via env overrides to /bin/true or /bin/false
 * so no real optimization process is spawned.
 *
 * No CoversClass attribute: final classes cannot be instrumented
 * by PCOV on PHP 8.5, causing PHPUnit coverage warnings.
 */
final class OptimizeImagesCommandTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Default: no tool available. Tests opt into a specific branch by
        // overriding one of these env vars with /bin/true, /bin/false, or a
        // custom stub binary.
        putenv('OPTIPNG_BIN=/nonexistent-optipng-binary');
        putenv('GIFSICLE_BIN=/nonexistent-gifsicle-binary');
        putenv('JPEGOPTIM_BIN=/nonexistent-jpegoptim-binary');
    }

    protected function tearDown(): void
    {
        putenv('OPTIPNG_BIN');
        putenv('GIFSICLE_BIN');
        putenv('JPEGOPTIM_BIN');

        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                @unlink($path); // nosemgrep: php.lang.security.unlink-use.unlink-use -- test fixture teardown of self-created tmp file
            }
        }

        $this->tempFiles = [];

        GeneralUtility::purgeInstances();

        parent::tearDown();
    }

    /**
     * Queue a ConnectionPool mock that answers count and iterate with fixture
     * data.  The helper installs two separate ConnectionPool instances — the
     * command code calls makeInstance(ConnectionPool::class) once inside
     * countImages() and once inside iterateViaIndex().
     *
     * @param list<array<string, mixed>> $records
     */
    private function installConnectionPoolFake(int $count, array $records = []): void
    {
        GeneralUtility::addInstance(ConnectionPool::class, $this->createConnectionPoolFake($count, $records));
        GeneralUtility::addInstance(ConnectionPool::class, $this->createConnectionPoolFake($count, $records));
    }

    /**
     * @param list<array<string, mixed>> $records
     */
    private function createConnectionPoolFake(int $count, array $records): ConnectionPool
    {
        $expr = $this->createMock(ExpressionBuilder::class);
        $expr->method('eq')->willReturn('');
        $expr->method('in')->willReturn('');

        $result = $this->createMock(Result::class);
        $result->method('fetchOne')->willReturn((string) $count);

        // Simulate generator behaviour via an iterator over $records.
        $queue = $records;
        $result->method('fetchAssociative')
            ->willReturnCallback(static function () use (&$queue): array|false {
                if ($queue === []) {
                    return false;
                }

                return array_shift($queue);
            });

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('count')->willReturnSelf();
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('join')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        $qb->method('expr')->willReturn($expr);
        $qb->method('createNamedParameter')->willReturn('?');
        $qb->method('executeQuery')->willReturn($result);

        $conn = $this->createMock(Connection::class);
        $conn->method('createQueryBuilder')->willReturn($qb);

        $pool = $this->createMock(ConnectionPool::class);
        $pool->method('getConnectionForTable')->willReturn($conn);

        return $pool;
    }

    /**
     * Build a File+Storage mock pair for the factory to return.
     *
     * @return array{file: File&MockObject, storage: ResourceStorage&MockObject}
     */
    private function createFileAndStorage(
        string $identifier,
        string $extension,
        string $localPath,
    ): array {
        $storage = $this->createMock(ResourceStorage::class);
        $storage->method('getEvaluatePermissions')->willReturn(true);

        $file = $this->createMock(File::class);
        $file->method('getIdentifier')->willReturn($identifier);
        $file->method('getExtension')->willReturn($extension);
        $file->method('getStorage')->willReturn($storage);
        $file->method('getForLocalProcessing')->willReturn($localPath);

        return ['file' => $file, 'storage' => $storage];
    }

    /**
     * Create a temp JPEG file so that ImageOptimizer's is_file() gate passes.
     */
    private function createTempJpeg(): string
    {
        $tmp               = sys_get_temp_dir() . '/nr-cmd-' . uniqid('', true) . '.jpg';
        $this->tempFiles[] = $tmp;
        file_put_contents($tmp, 'fake-jpeg-bytes-' . uniqid('', true));

        return $tmp;
    }

    #[Test]
    public function executeReturnsFailureWhenNoToolIsAvailable(): void
    {
        $factory   = $this->createMock(ResourceFactory::class);
        $optimizer = new ImageOptimizer();

        $command = new OptimizeImagesCommand($factory, $optimizer);

        $tester = new CommandTester($command);
        $status = $tester->execute([]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('No optimization tools found', $tester->getDisplay());
    }

    #[Test]
    public function executeReturnsSuccessAndWarnsWhenNoMatchingFiles(): void
    {
        putenv('JPEGOPTIM_BIN=/bin/true');

        $factory   = $this->createMock(ResourceFactory::class);
        $optimizer = new ImageOptimizer();

        // Only countImages is called when the result is 0 — one pool instance
        // is enough, but install two to stay consistent with other tests.
        $this->installConnectionPoolFake(0);

        $command = new OptimizeImagesCommand($factory, $optimizer);

        $tester = new CommandTester($command);
        $status = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('No matching image files found', $tester->getDisplay());
    }

    #[Test]
    public function executeSkipsRecordsWithUnsupportedExtension(): void
    {
        putenv('JPEGOPTIM_BIN=/bin/true');

        ['file' => $file] = $this->createFileAndStorage(
            '/unsupported.bmp',
            'bmp',
            '/does/not/matter',
        );

        $factory = $this->createMock(ResourceFactory::class);
        $factory->method('getFileObject')->willReturn($file);

        $optimizer = new ImageOptimizer();

        $this->installConnectionPoolFake(1, [
            ['uid' => 1, 'identifier' => '/unsupported.bmp'],
        ]);

        $command = new OptimizeImagesCommand($factory, $optimizer);

        $tester = new CommandTester($command);
        $status = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $status);
        $display = $tester->getDisplay();
        self::assertStringContainsString('Skipping /unsupported.bmp', $display);
        self::assertStringContainsString('Skipped: 1', $display);
    }

    #[Test]
    public function executeDryRunDoesNotRunOptimizer(): void
    {
        putenv('JPEGOPTIM_BIN=/bin/true');

        $localPath = $this->createTempJpeg();

        ['file' => $file, 'storage' => $storage] = $this->createFileAndStorage(
            '/photo.jpg',
            'jpg',
            $localPath,
        );

        // replaceFile must never be invoked under dry-run.
        $storage->expects(self::never())->method('replaceFile');

        $factory = $this->createMock(ResourceFactory::class);
        $factory->method('getFileObject')->willReturn($file);

        $optimizer = new ImageOptimizer();

        $this->installConnectionPoolFake(1, [
            ['uid' => 1, 'identifier' => '/photo.jpg'],
        ]);

        $command = new OptimizeImagesCommand($factory, $optimizer);

        $tester = new CommandTester($command);
        $status = $tester->execute(['--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $status);
        $display = $tester->getDisplay();
        self::assertStringContainsString('Dry-run enabled', $display);
        self::assertStringContainsString('[DRY] Would optimize /photo.jpg with jpegoptim', $display);
        // The source temp file must still exist — dry-run does not touch it.
        self::assertFileExists($localPath);
    }

    #[Test]
    public function executePrintsNoSavingsWhenToolDoesNotShrink(): void
    {
        // /bin/true exits 0 without modifying the file => after == before.
        putenv('JPEGOPTIM_BIN=/bin/true');

        $localPath = $this->createTempJpeg();

        ['file' => $file] = $this->createFileAndStorage(
            '/nochange.jpg',
            'jpg',
            $localPath,
        );

        $factory = $this->createMock(ResourceFactory::class);
        $factory->method('getFileObject')->willReturn($file);

        $optimizer = new ImageOptimizer();

        $this->installConnectionPoolFake(1, [
            ['uid' => 1, 'identifier' => '/nochange.jpg'],
        ]);

        $command = new OptimizeImagesCommand($factory, $optimizer);

        $tester = new CommandTester($command);
        $status = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $status);
        $display = $tester->getDisplay();
        self::assertStringContainsString('No savings: /nochange.jpg', $display);
        self::assertStringContainsString('Optimized: 0', $display);
    }

    #[Test]
    public function executeReportsOptimizedFileWhenToolShrinksIt(): void
    {
        // Wrapper that truncates the LAST argument (the file path for
        // jpegoptim) so the file becomes smaller — triggers the "optimized"
        // branch that calls replaceFile.
        $wrapper           = sys_get_temp_dir() . '/nr-fake-wrapper-' . uniqid('', true) . '.sh';
        $this->tempFiles[] = $wrapper;
        file_put_contents($wrapper, <<<'SHELL'
            #!/bin/sh
            # Truncate the last argument (the file path passed by jpegoptim).
            eval "last=\${$#}"
            : > "$last"
            exit 0

            SHELL);
        chmod($wrapper, 0o755);

        putenv('JPEGOPTIM_BIN=' . $wrapper);

        $localPath = $this->createTempJpeg();

        ['file' => $file, 'storage' => $storage] = $this->createFileAndStorage(
            '/shrink.jpg',
            'jpg',
            $localPath,
        );

        // Storage::replaceFile must be called on the "optimized" branch.
        $storage->expects(self::once())
            ->method('replaceFile')
            ->with($file, $localPath);

        $factory = $this->createMock(ResourceFactory::class);
        $factory->method('getFileObject')->willReturn($file);

        $optimizer = new ImageOptimizer();

        $this->installConnectionPoolFake(1, [
            ['uid' => 1, 'identifier' => '/shrink.jpg'],
        ]);

        $command = new OptimizeImagesCommand($factory, $optimizer);

        $tester = new CommandTester($command);
        $status = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $status);
        $display = $tester->getDisplay();
        self::assertStringContainsString('Optimized: /shrink.jpg', $display);
        self::assertStringContainsString('Optimized: 1', $display);
    }

    #[Test]
    public function executeReportsNoSavingsWhenToolRunFails(): void
    {
        // /bin/false exits 1 => optimize() returns optimized=false with tool.
        putenv('JPEGOPTIM_BIN=/bin/false');

        $localPath = $this->createTempJpeg();

        ['file' => $file, 'storage' => $storage] = $this->createFileAndStorage(
            '/fails.jpg',
            'jpg',
            $localPath,
        );

        // replaceFile must NOT be called when the process fails.
        $storage->expects(self::never())->method('replaceFile');

        $factory = $this->createMock(ResourceFactory::class);
        $factory->method('getFileObject')->willReturn($file);

        $optimizer = new ImageOptimizer();

        $this->installConnectionPoolFake(1, [
            ['uid' => 1, 'identifier' => '/fails.jpg'],
        ]);

        $command = new OptimizeImagesCommand($factory, $optimizer);

        $tester = new CommandTester($command);
        $status = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $status);
        // The optimizer still reports the tool name; the command logs "No
        // savings" because optimized=false.
        self::assertStringContainsString('No savings: /fails.jpg', $tester->getDisplay());
    }

    #[Test]
    public function executeAcceptsJpegQualityAndStripMetadataOptions(): void
    {
        // Validates that option parsing does not crash and the run still
        // completes. /bin/true lands us in the "No savings" branch.
        putenv('JPEGOPTIM_BIN=/bin/true');

        $localPath = $this->createTempJpeg();

        ['file' => $file] = $this->createFileAndStorage(
            '/quality.jpg',
            'jpg',
            $localPath,
        );

        $factory = $this->createMock(ResourceFactory::class);
        $factory->method('getFileObject')->willReturn($file);

        $optimizer = new ImageOptimizer();

        $this->installConnectionPoolFake(1, [
            ['uid' => 1, 'identifier' => '/quality.jpg'],
        ]);

        $command = new OptimizeImagesCommand($factory, $optimizer);

        $tester = new CommandTester($command);
        $status = $tester->execute([
            '--jpeg-quality'   => '75',
            '--strip-metadata' => true,
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('No savings: /quality.jpg', $tester->getDisplay());
    }

    #[Test]
    public function executeAcceptsStoragesOptionWithoutError(): void
    {
        // The --storages option is parsed via parseStorageUidsOption in the
        // base class; this test mainly confirms the code path doesn't crash
        // when the option is provided.
        putenv('JPEGOPTIM_BIN=/bin/true');

        $factory   = $this->createMock(ResourceFactory::class);
        $optimizer = new ImageOptimizer();

        $this->installConnectionPoolFake(0);

        $command = new OptimizeImagesCommand($factory, $optimizer);

        $tester = new CommandTester($command);
        $status = $tester->execute([
            '--storages' => ['1,2', '3'],
        ]);

        self::assertSame(Command::SUCCESS, $status);
    }

    #[Test]
    public function executeRestoresPreviousStoragePermissionsAfterProcessing(): void
    {
        putenv('JPEGOPTIM_BIN=/bin/true');

        $localPath = $this->createTempJpeg();

        $storage = $this->createMock(ResourceStorage::class);
        $storage->method('getEvaluatePermissions')->willReturn(true);

        $captured = [];
        $storage->method('setEvaluatePermissions')
            ->willReturnCallback(function (bool $value) use (&$captured): void {
                $captured[] = $value;
            });

        $file = $this->createMock(File::class);
        $file->method('getIdentifier')->willReturn('/perm.jpg');
        $file->method('getExtension')->willReturn('jpg');
        $file->method('getStorage')->willReturn($storage);
        $file->method('getForLocalProcessing')->willReturn($localPath);

        $factory = $this->createMock(ResourceFactory::class);
        $factory->method('getFileObject')->willReturn($file);

        $optimizer = new ImageOptimizer();

        $this->installConnectionPoolFake(1, [
            ['uid' => 1, 'identifier' => '/perm.jpg'],
        ]);

        $command = new OptimizeImagesCommand($factory, $optimizer);

        $tester = new CommandTester($command);
        $tester->execute([]);

        // Toggled off (false) before processing then restored (true) in finally.
        self::assertSame([false, true], $captured);
    }
}
