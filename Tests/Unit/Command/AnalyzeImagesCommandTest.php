<?php

/*
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\NrImageOptimize\Tests\Unit\Command;

use Doctrine\DBAL\Result;

use function file_put_contents;
use function is_file;

use Netresearch\NrImageOptimize\Command\AnalyzeImagesCommand;
use Netresearch\NrImageOptimize\Service\ImageOptimizer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

use function putenv;
use function str_repeat;

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
 * Unit tests for AnalyzeImagesCommand::execute().
 *
 * Scope: orchestration logic in execute() — reading options, handling the
 * "no matching files" early exit, iterating fixture records, dispatching to
 * the heuristic analyzer, counting improvable/no-gain buckets.
 *
 * The command is final so it cannot be subclassed. The ConnectionPool
 * consumed by countImages()/iterateViaIndex() is faked via
 * GeneralUtility::addInstance() with a pre-seeded QueryBuilder chain.
 *
 * No CoversClass attribute: final classes cannot be instrumented
 * by PCOV on PHP 8.5, causing PHPUnit coverage warnings.
 */
class AnalyzeImagesCommandTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

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
     * Queue two ConnectionPool fakes (one per call — countImages and
     * iterateViaIndex each call makeInstance once).
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
     * Build a File+Storage mock.
     *
     * @return array{file: File&MockObject, storage: ResourceStorage&MockObject}
     */
    private function createFileAndStorage(
        string $identifier,
        string $extension,
        string $localPath,
        bool $exists = true,
    ): array {
        $storage = $this->createMock(ResourceStorage::class);
        $storage->method('getEvaluatePermissions')->willReturn(true);

        $file = $this->createMock(File::class);
        $file->method('getIdentifier')->willReturn($identifier);
        $file->method('getExtension')->willReturn($extension);
        $file->method('getStorage')->willReturn($storage);
        $file->method('getForLocalProcessing')->willReturn($localPath);
        $file->method('exists')->willReturn($exists);

        return ['file' => $file, 'storage' => $storage];
    }

    /**
     * Pad a file with arbitrary bytes so analyzeHeuristic's minSize gate is
     * crossed.
     */
    private function createLargeFile(string $extension, int $size = 600_000): string
    {
        $tmp               = sys_get_temp_dir() . '/nr-ana-' . uniqid('', true) . '.' . $extension;
        $this->tempFiles[] = $tmp;
        file_put_contents($tmp, str_repeat('A', $size));

        return $tmp;
    }

    #[Test]
    public function executeReturnsSuccessAndWarnsWhenNoMatchingFiles(): void
    {
        $factory   = $this->createMock(ResourceFactory::class);
        $optimizer = new ImageOptimizer();

        $this->installConnectionPoolFake(0);

        $command = new AnalyzeImagesCommand($factory, $optimizer);

        $tester = new CommandTester($command);
        $status = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('No matching image files found', $tester->getDisplay());
    }

    #[Test]
    public function executeReportsImprovableWhenLargeImageAnalyzedAsImprovable(): void
    {
        $localPath = $this->createLargeFile('png');

        ['file' => $file] = $this->createFileAndStorage(
            '/big.png',
            'png',
            $localPath,
        );
        // Provide fallback dimensions so the scaling path is exercised.
        $file->method('getProperty')
            ->willReturnCallback(static fn (string $key): mixed => match ($key) {
                'width'  => 4000,
                'height' => 3000,
                default  => null,
            });

        $factory = $this->createMock(ResourceFactory::class);
        $factory->method('getFileObject')->willReturn($file);

        $optimizer = new ImageOptimizer();

        $this->installConnectionPoolFake(1, [
            ['uid' => 1, 'identifier' => '/big.png'],
        ]);

        $command = new AnalyzeImagesCommand($factory, $optimizer);

        $tester = new CommandTester($command);
        $status = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $status);
        $display = $tester->getDisplay();
        self::assertStringContainsString('Improvable: 1', $display);
        self::assertStringContainsString('No potential: 0', $display);
    }

    #[Test]
    public function executeAdvancesProgressWhenFileDoesNotExistOnStorage(): void
    {
        // File::exists() returns false => analyzeHeuristic is never called
        // but the progress bar still advances and the final tally is 0/0.
        $localPath = '/does/not/matter/missing.png';

        ['file' => $file] = $this->createFileAndStorage(
            '/missing.png',
            'png',
            $localPath,
            exists: false,
        );

        $factory = $this->createMock(ResourceFactory::class);
        $factory->method('getFileObject')->willReturn($file);

        $optimizer = new ImageOptimizer();

        $this->installConnectionPoolFake(1, [
            ['uid' => 1, 'identifier' => '/missing.png'],
        ]);

        $command = new AnalyzeImagesCommand($factory, $optimizer);

        $tester = new CommandTester($command);
        $status = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $status);
        $display = $tester->getDisplay();
        // Neither bucket incremented, but total files remains the count.
        self::assertStringContainsString('Files: 1', $display);
        self::assertStringContainsString('Improvable: 0', $display);
        self::assertStringContainsString('No potential: 0', $display);
    }

    #[Test]
    public function executeCountsNoGainWhenFileBelowMinSize(): void
    {
        // Small file below default minSize => analyzeHeuristic returns
        // optimized=false without doing work, so the "noGain" counter wins.
        $tmp               = sys_get_temp_dir() . '/nr-ana-' . uniqid('', true) . '.png';
        $this->tempFiles[] = $tmp;
        file_put_contents($tmp, str_repeat('A', 100));

        ['file' => $file] = $this->createFileAndStorage(
            '/tiny.png',
            'png',
            $tmp,
        );

        $factory = $this->createMock(ResourceFactory::class);
        $factory->method('getFileObject')->willReturn($file);

        $optimizer = new ImageOptimizer();

        $this->installConnectionPoolFake(1, [
            ['uid' => 1, 'identifier' => '/tiny.png'],
        ]);

        $command = new AnalyzeImagesCommand($factory, $optimizer);

        $tester = new CommandTester($command);
        $status = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $status);
        $display = $tester->getDisplay();
        self::assertStringContainsString('Improvable: 0', $display);
        self::assertStringContainsString('No potential: 1', $display);
    }

    #[Test]
    public function executeAcceptsMaxWidthMaxHeightMinSizeOptions(): void
    {
        $localPath = $this->createLargeFile('png', 1_000_000);

        ['file' => $file] = $this->createFileAndStorage(
            '/big.png',
            'png',
            $localPath,
        );
        $file->method('getProperty')
            ->willReturnCallback(static fn (string $key): mixed => match ($key) {
                'width'  => 5000,
                'height' => 4000,
                default  => null,
            });

        $factory = $this->createMock(ResourceFactory::class);
        $factory->method('getFileObject')->willReturn($file);

        $optimizer = new ImageOptimizer();

        $this->installConnectionPoolFake(1, [
            ['uid' => 1, 'identifier' => '/big.png'],
        ]);

        $command = new AnalyzeImagesCommand($factory, $optimizer);

        $tester = new CommandTester($command);
        $status = $tester->execute([
            '--max-width'  => '1024',
            '--max-height' => '768',
            '--min-size'   => '1024',
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('Improvable: 1', $tester->getDisplay());
    }

    #[Test]
    public function executeAcceptsStoragesOption(): void
    {
        $factory   = $this->createMock(ResourceFactory::class);
        $optimizer = new ImageOptimizer();

        $this->installConnectionPoolFake(0);

        $command = new AnalyzeImagesCommand($factory, $optimizer);

        $tester = new CommandTester($command);
        $status = $tester->execute([
            '--storages' => ['2,3', '4'],
        ]);

        self::assertSame(Command::SUCCESS, $status);
    }

    #[Test]
    public function executeRestoresPreviousStoragePermissions(): void
    {
        $localPath = $this->createLargeFile('png');

        $storage = $this->createMock(ResourceStorage::class);
        $storage->method('getEvaluatePermissions')->willReturn(true);

        $captured = [];
        $storage->method('setEvaluatePermissions')
            ->willReturnCallback(function (bool $value) use (&$captured): void {
                $captured[] = $value;
            });

        $file = $this->createMock(File::class);
        $file->method('getIdentifier')->willReturn('/perm.png');
        $file->method('getExtension')->willReturn('png');
        $file->method('getStorage')->willReturn($storage);
        $file->method('getForLocalProcessing')->willReturn($localPath);
        $file->method('exists')->willReturn(true);
        $file->method('getProperty')->willReturn(null);

        $factory = $this->createMock(ResourceFactory::class);
        $factory->method('getFileObject')->willReturn($file);

        $optimizer = new ImageOptimizer();

        $this->installConnectionPoolFake(1, [
            ['uid' => 1, 'identifier' => '/perm.png'],
        ]);

        $command = new AnalyzeImagesCommand($factory, $optimizer);

        $tester = new CommandTester($command);
        $tester->execute([]);

        self::assertSame([false, true], $captured);
    }
}
