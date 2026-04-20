<?php

/*
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\NrImageOptimize\Tests\Unit\EventListener;

use function chmod;
use function file_put_contents;
use function is_file;

use Netresearch\NrImageOptimize\EventListener\OptimizeOnUploadListener;
use Netresearch\NrImageOptimize\Service\ImageOptimizer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

use function putenv;

use ReflectionProperty;
use RuntimeException;

use function sys_get_temp_dir;

use TYPO3\CMS\Core\Resource\Event\AfterFileAddedEvent;
use TYPO3\CMS\Core\Resource\Event\AfterFileReplacedEvent;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceStorage;

use function uniqid;
use function unlink;

/**
 * Tests for OptimizeOnUploadListener.
 *
 * Covers:
 * - Early-return branches (offline storage, unsupported extension)
 * - Re-entrancy guarding via the private $guard map
 * - Permission disable / restore in the finally block
 * - Dispatch via both AfterFileAdded and AfterFileReplaced events
 *
 * Because ImageOptimizer is declared final (and therefore cannot be mocked by
 * PHPUnit without runtime class hacks), the listener is driven with a real
 * ImageOptimizer whose binary resolution is pinned via env overrides to a
 * well-defined state. Behaviour is verified through observable storage
 * interactions plus direct inspection of the listener's re-entrancy guard
 * via reflection.
 *
 * No CoversClass attribute: final classes cannot be instrumented
 * by PCOV on PHP 8.5, causing PHPUnit coverage warnings.
 */
class OptimizeOnUploadListenerTest extends TestCase
{
    private OptimizeOnUploadListener $listener;

    private ImageOptimizer $optimizer;

    private ReflectionProperty $guardProperty;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Force ImageOptimizer into a deterministic "no tools available" state
        // so optimize() reliably takes its null-tool early return. Individual
        // tests may override the env and rebuild the optimizer.
        putenv('OPTIPNG_BIN=/nonexistent-optipng-binary');
        putenv('GIFSICLE_BIN=/nonexistent-gifsicle-binary');
        putenv('JPEGOPTIM_BIN=/nonexistent-jpegoptim-binary');

        $this->optimizer     = new ImageOptimizer();
        $this->listener      = new OptimizeOnUploadListener($this->optimizer);
        $this->guardProperty = new ReflectionProperty(OptimizeOnUploadListener::class, 'guard');
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

        parent::tearDown();
    }

    /**
     * @return array<string, true>
     */
    private function readGuard(): array
    {
        /** @var array<string, true> $value */
        $value = $this->guardProperty->getValue($this->listener);

        return $value;
    }

    /**
     * Build a FileInterface mock bound to a ResourceStorage mock with the
     * given pre-wired state.
     *
     * @return array{file: FileInterface&MockObject, storage: ResourceStorage&MockObject}
     */
    private function createFileAndStorage(
        string $identifier,
        string $extension,
        bool $isOnline = true,
        bool $previousPermissions = true,
    ): array {
        $storage = $this->createMock(ResourceStorage::class);
        $storage->method('isOnline')->willReturn($isOnline);
        $storage->method('getEvaluatePermissions')->willReturn($previousPermissions);

        $file = $this->createMock(FileInterface::class);
        $file->method('getIdentifier')->willReturn($identifier);
        $file->method('getExtension')->willReturn($extension);
        $file->method('getStorage')->willReturn($storage);

        return ['file' => $file, 'storage' => $storage];
    }

    #[Test]
    public function optimizeAfterAddEntersFullHandlerForSupportedFile(): void
    {
        ['file' => $file, 'storage' => $storage] = $this->createFileAndStorage(
            '/uploaded.png',
            'png',
            previousPermissions: true,
        );

        $captured = [];
        $storage->method('setEvaluatePermissions')
            ->willReturnCallback(function (bool $value) use (&$captured): void {
                $captured[] = $value;
            });

        $folder = $this->createMock(Folder::class);
        $event  = new AfterFileAddedEvent($file, $folder);

        $this->listener->optimizeAfterAdd($event);

        // Entry path: permissions toggled off then restored via the finally block.
        self::assertSame([false, true], $captured);
        self::assertSame([], $this->readGuard(), 'Guard should be cleared after processing');
    }

    #[Test]
    public function optimizeAfterReplaceEntersFullHandlerForSupportedFile(): void
    {
        ['file' => $file, 'storage' => $storage] = $this->createFileAndStorage(
            '/replaced.jpg',
            'jpg',
        );

        $captured = [];
        $storage->method('setEvaluatePermissions')
            ->willReturnCallback(function (bool $value) use (&$captured): void {
                $captured[] = $value;
            });

        $event = new AfterFileReplacedEvent($file, '/tmp/local.jpg');

        $this->listener->optimizeAfterReplace($event);

        self::assertSame([false, true], $captured);
        self::assertSame([], $this->readGuard());
    }

    #[Test]
    public function storageOfflineSkipsOptimize(): void
    {
        ['file' => $file, 'storage' => $storage] = $this->createFileAndStorage(
            '/offline.png',
            'png',
            isOnline: false,
        );

        $storage->expects(self::never())->method('setEvaluatePermissions');
        $storage->expects(self::never())->method('getEvaluatePermissions');

        $folder = $this->createMock(Folder::class);
        $event  = new AfterFileAddedEvent($file, $folder);

        $this->listener->optimizeAfterAdd($event);

        self::assertSame([], $this->readGuard());
    }

    #[Test]
    public function unsupportedExtensionSkipsOptimize(): void
    {
        ['file' => $file, 'storage' => $storage] = $this->createFileAndStorage(
            '/document.pdf',
            'pdf',
        );

        $storage->expects(self::never())->method('setEvaluatePermissions');
        $storage->expects(self::never())->method('getEvaluatePermissions');

        $folder = $this->createMock(Folder::class);
        $event  = new AfterFileAddedEvent($file, $folder);

        $this->listener->optimizeAfterAdd($event);

        self::assertSame([], $this->readGuard());
    }

    #[Test]
    public function uppercaseExtensionIsNormalizedAndProcessed(): void
    {
        ['file' => $file, 'storage' => $storage] = $this->createFileAndStorage(
            '/photo.JPEG',
            'JPEG',
        );

        $setCalls = 0;
        $storage->method('setEvaluatePermissions')
            ->willReturnCallback(function () use (&$setCalls): void {
                ++$setCalls;
            });

        $folder = $this->createMock(Folder::class);
        $event  = new AfterFileAddedEvent($file, $folder);

        $this->listener->optimizeAfterAdd($event);

        // If extension normalization failed, we would have bailed before
        // touching permissions at all.
        self::assertSame(2, $setCalls, 'Expected permissions toggled off and restored');
    }

    #[Test]
    public function reEntrancyGuardBlocksNestedInvocationForSameIdentifier(): void
    {
        $identifier = '/recursive.png';

        // Install a real binary (a shell stub that does nothing) so the real
        // optimizer proceeds beyond the null-tool branch. The nested call
        // comes from within getForLocalProcessing(), which we intercept via
        // the mock to fire the AfterFileReplacedEvent for the same file.
        $fakeOptipng       = sys_get_temp_dir() . '/nr-pio-fake-optipng-' . uniqid('', true);
        $this->tempFiles[] = $fakeOptipng;
        file_put_contents($fakeOptipng, "#!/bin/sh\nexit 0\n");
        chmod($fakeOptipng, 0o755);
        putenv('OPTIPNG_BIN=' . $fakeOptipng);

        // Rebuild the optimizer/listener now that the binary exists.
        $this->optimizer = new ImageOptimizer();
        $this->listener  = new OptimizeOnUploadListener($this->optimizer);

        ['file' => $file, 'storage' => $storage] = $this->createFileAndStorage(
            $identifier,
            'png',
        );

        // getForLocalProcessing is called by optimize() once the tool resolves.
        // Use that hook to fire a nested replacement event for the same file —
        // the listener's guard must short-circuit the nested handle().
        $nestedInvocationCount = 0;
        $file->method('getForLocalProcessing')
            ->willReturnCallback(function () use ($file, &$nestedInvocationCount): string {
                $nestedEvent = new AfterFileReplacedEvent($file, '/tmp/nested.png');
                $this->listener->optimizeAfterReplace($nestedEvent);
                ++$nestedInvocationCount;

                return '/does/not/exist/nested-source.png';
            });

        $folder = $this->createMock(Folder::class);
        $event  = new AfterFileAddedEvent($file, $folder);

        $this->listener->optimizeAfterAdd($event);

        self::assertSame(1, $nestedInvocationCount, 'Outer optimize() should run once');
        // Guard is empty again after the outer handle() finishes; no entries
        // remain from the blocked nested call.
        self::assertSame([], $this->readGuard());
    }

    #[Test]
    public function guardIsClearedAfterProcessingSoFollowupCallWorks(): void
    {
        ['file' => $file, 'storage' => $storage] = $this->createFileAndStorage(
            '/cleared.png',
            'png',
        );

        $setCalls = 0;
        $storage->method('setEvaluatePermissions')
            ->willReturnCallback(function () use (&$setCalls): void {
                ++$setCalls;
            });

        $folder = $this->createMock(Folder::class);

        $this->listener->optimizeAfterAdd(new AfterFileAddedEvent($file, $folder));
        self::assertSame([], $this->readGuard());

        $this->listener->optimizeAfterAdd(new AfterFileAddedEvent($file, $folder));
        self::assertSame([], $this->readGuard());

        // Each invocation toggles permissions twice (off + restore).
        self::assertSame(4, $setCalls);
    }

    #[Test]
    public function previousPermissionsAreRestoredWhenOptimizerThrows(): void
    {
        // Install a fake binary so the optimizer proceeds past the null-tool
        // branch, then wire the file to throw during getForLocalProcessing —
        // this simulates an error path that must still restore permissions.
        $fakeOptipng       = sys_get_temp_dir() . '/nr-pio-fake-optipng-' . uniqid('', true);
        $this->tempFiles[] = $fakeOptipng;
        file_put_contents($fakeOptipng, "#!/bin/sh\nexit 0\n");
        chmod($fakeOptipng, 0o755);
        putenv('OPTIPNG_BIN=' . $fakeOptipng);

        $this->optimizer = new ImageOptimizer();
        $this->listener  = new OptimizeOnUploadListener($this->optimizer);

        ['file' => $file, 'storage' => $storage] = $this->createFileAndStorage(
            '/throws.png',
            'png',
            previousPermissions: true,
        );

        $captured = [];
        $storage->method('setEvaluatePermissions')
            ->willReturnCallback(function (bool $value) use (&$captured): void {
                $captured[] = $value;
            });

        $file->method('getForLocalProcessing')
            ->willThrowException(new RuntimeException('boom'));

        $folder = $this->createMock(Folder::class);
        $event  = new AfterFileAddedEvent($file, $folder);

        try {
            $this->listener->optimizeAfterAdd($event);
            self::fail('Expected RuntimeException was not thrown');
        } catch (RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }

        // finally must restore the previous "true" permission state.
        self::assertSame([false, true], $captured);
        self::assertSame([], $this->readGuard(), 'Guard must be cleared even on error');
    }

    #[Test]
    public function previousPermissionsFalseIsRestoredAsFalse(): void
    {
        ['file' => $file, 'storage' => $storage] = $this->createFileAndStorage(
            '/preserve.png',
            'png',
            previousPermissions: false,
        );

        $captured = [];
        $storage->method('setEvaluatePermissions')
            ->willReturnCallback(function (bool $value) use (&$captured): void {
                $captured[] = $value;
            });

        $folder = $this->createMock(Folder::class);
        $event  = new AfterFileAddedEvent($file, $folder);

        $this->listener->optimizeAfterAdd($event);

        self::assertSame([false, false], $captured);
    }

    #[Test]
    public function guardAllowsDifferentIdentifiersToBeProcessed(): void
    {
        ['file' => $fileA, 'storage' => $storageA] = $this->createFileAndStorage(
            '/different-a.png',
            'png',
        );
        ['file' => $fileB, 'storage' => $storageB] = $this->createFileAndStorage(
            '/different-b.png',
            'png',
        );

        $setCalls = 0;
        $storageA->method('setEvaluatePermissions')
            ->willReturnCallback(function () use (&$setCalls): void {
                ++$setCalls;
            });
        $storageB->method('setEvaluatePermissions')
            ->willReturnCallback(function () use (&$setCalls): void {
                ++$setCalls;
            });

        $folder = $this->createMock(Folder::class);
        $this->listener->optimizeAfterAdd(new AfterFileAddedEvent($fileA, $folder));
        $this->listener->optimizeAfterAdd(new AfterFileAddedEvent($fileB, $folder));

        // Both entered the handler because their identifiers differ.
        self::assertSame(4, $setCalls);
        self::assertSame([], $this->readGuard());
    }
}
