<?php

/*
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\NrImageOptimize\Tests\Unit\Service;

use function imagecreatetruecolor;
use function imagegif;
use function imagejpeg;
use function imagepng;
use function is_file;

use Netresearch\NrImageOptimize\Service\ImageOptimizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function putenv;

use ReflectionMethod;

use function str_repeat;
use function sys_get_temp_dir;

use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Resource\ResourceStorage;

use function uniqid;
use function unlink;

/**
 * Unit tests for ImageOptimizer.
 *
 * Scope: public API paths that do not require an installed optimization
 * binary — tool resolution is forced to "not available" via environment
 * overrides. The external process happy-path lives in the functional tier.
 *
 * No CoversClass attribute: final classes cannot be instrumented
 * by PCOV on PHP 8.5, causing PHPUnit coverage warnings.
 */
class ImageOptimizerTest extends TestCase
{
    private ImageOptimizer $optimizer;

    /**
     * Files created during a test run that must be cleaned up in tearDown.
     *
     * @var list<string>
     */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Force resolveBinary() into the "env override missing" branch so all
        // subsequent `which` lookups decide based on the host environment.
        // Tests that need a specific outcome set the env var themselves.
        putenv('OPTIPNG_BIN=/nonexistent-optipng-binary');
        putenv('GIFSICLE_BIN=/nonexistent-gifsicle-binary');
        putenv('JPEGOPTIM_BIN=/nonexistent-jpegoptim-binary');

        $this->optimizer = new ImageOptimizer();
    }

    protected function tearDown(): void
    {
        putenv('OPTIPNG_BIN');
        putenv('GIFSICLE_BIN');
        putenv('JPEGOPTIM_BIN');

        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        $this->tempFiles = [];

        parent::tearDown();
    }

    #[Test]
    public function supportedExtensionsReturnsCanonicalList(): void
    {
        self::assertSame(['png', 'gif', 'jpg', 'jpeg'], $this->optimizer->supportedExtensions());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unsupportedExtensionProvider(): array
    {
        return [
            'webp (out of scope)' => ['webp'],
            'avif (out of scope)' => ['avif'],
            'pdf'                 => ['pdf'],
            'empty string'        => [''],
            'random text'         => ['xyz'],
        ];
    }

    #[Test]
    #[DataProvider('unsupportedExtensionProvider')]
    public function optimizeReturnsNoToolForUnsupportedExtension(string $extension): void
    {
        $file = $this->createMock(FileInterface::class);
        $file->method('getExtension')->willReturn($extension);
        $file->expects(self::never())->method('getForLocalProcessing');

        $result = $this->optimizer->optimize($file);

        self::assertSame([
            'optimized'  => false,
            'savedBytes' => 0,
            'before'     => 0,
            'after'      => 0,
            'tool'       => null,
        ], $result);
    }

    #[Test]
    #[DataProvider('unsupportedExtensionProvider')]
    public function analyzeReturnsNoToolForUnsupportedExtension(string $extension): void
    {
        $file = $this->createMock(FileInterface::class);
        $file->method('getExtension')->willReturn($extension);
        $file->expects(self::never())->method('getForLocalProcessing');

        $result = $this->optimizer->analyze($file);

        self::assertSame([
            'optimized'  => false,
            'savedBytes' => 0,
            'before'     => 0,
            'after'      => 0,
            'tool'       => null,
        ], $result);
    }

    #[Test]
    public function optimizeReturnsEarlyWhenNoToolAvailable(): void
    {
        $file = $this->createMock(FileInterface::class);
        $file->method('getExtension')->willReturn('png');
        // Tool lookup fails => getForLocalProcessing must not be called.
        $file->expects(self::never())->method('getForLocalProcessing');

        $result = $this->optimizer->optimize($file);

        self::assertFalse($result['optimized']);
        self::assertNull($result['tool']);
    }

    #[Test]
    public function analyzeReturnsEarlyWhenNoToolAvailable(): void
    {
        $file = $this->createMock(FileInterface::class);
        $file->method('getExtension')->willReturn('jpg');
        $file->expects(self::never())->method('getForLocalProcessing');

        $result = $this->optimizer->analyze($file);

        self::assertFalse($result['optimized']);
        self::assertNull($result['tool']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function supportedExtensionProvider(): array
    {
        return [
            'png'                     => ['png'],
            'gif'                     => ['gif'],
            'jpg'                     => ['jpg'],
            'jpeg'                    => ['jpeg'],
            'upper JPG is lowercased' => ['JPG'],
            'upper PNG is lowercased' => ['PNG'],
        ];
    }

    #[Test]
    #[DataProvider('supportedExtensionProvider')]
    public function resolveToolForReturnsNullWhenBinaryUnavailable(string $extension): void
    {
        // Env overrides force binary lookup to fail; also, PATH-based 'which'
        // cannot resolve the binary because it's not installed in this CI env.
        self::assertNull($this->optimizer->resolveToolFor($extension));
    }

    #[Test]
    public function resolveToolForReturnsNullForUnknownExtension(): void
    {
        self::assertNull($this->optimizer->resolveToolFor('webp'));
        self::assertNull($this->optimizer->resolveToolFor(''));
    }

    #[Test]
    public function hasAnyToolReturnsFalseWhenNoBinariesAvailable(): void
    {
        self::assertFalse($this->optimizer->hasAnyTool());
    }

    #[Test]
    public function hasAnyToolReturnsTrueWhenEnvOverridePointsAtExistingFile(): void
    {
        // Create a real file and point one env override at it. The resolver
        // checks existence via is_file() and returns the override as the
        // "binary" — no execution happens here.
        $fakeBinary        = sys_get_temp_dir() . '/nr-pio-fake-optipng-' . uniqid('', true);
        $this->tempFiles[] = $fakeBinary;
        file_put_contents($fakeBinary, "#!/bin/sh\nexit 0\n");
        chmod($fakeBinary, 0o755);

        putenv('OPTIPNG_BIN=' . $fakeBinary);

        $optimizer = new ImageOptimizer();

        self::assertTrue($optimizer->hasAnyTool());

        $tool = $optimizer->resolveToolFor('png');
        self::assertNotNull($tool);
        self::assertSame('optipng', $tool['name']);
        self::assertSame($fakeBinary, $tool['bin']);
    }

    #[Test]
    public function resolveToolForLowercasesExtension(): void
    {
        $fakeBinary        = sys_get_temp_dir() . '/nr-pio-fake-jpegoptim-' . uniqid('', true);
        $this->tempFiles[] = $fakeBinary;
        file_put_contents($fakeBinary, "#!/bin/sh\nexit 0\n");
        chmod($fakeBinary, 0o755);

        putenv('JPEGOPTIM_BIN=' . $fakeBinary);

        $optimizer = new ImageOptimizer();

        $upper = $optimizer->resolveToolFor('JPEG');
        $lower = $optimizer->resolveToolFor('jpeg');

        self::assertNotNull($upper);
        self::assertNotNull($lower);
        self::assertSame($upper, $lower);
        self::assertSame('jpegoptim', $upper['name']);
    }

    #[Test]
    public function resolveToolForTreatsEmptyEnvOverrideAsMissing(): void
    {
        putenv('OPTIPNG_BIN='); // empty string => fall through to which()
        putenv('GIFSICLE_BIN=');
        putenv('JPEGOPTIM_BIN=');

        $optimizer = new ImageOptimizer();

        // 'which' cannot find the binaries in this test environment.
        self::assertNull($optimizer->resolveToolFor('png'));
        self::assertFalse($optimizer->hasAnyTool());
    }

    #[Test]
    public function analyzeHeuristicReturnsNoToolForUnsupportedExtension(): void
    {
        $file = $this->createMock(FileInterface::class);
        $file->method('getExtension')->willReturn('webp');
        $file->expects(self::never())->method('getForLocalProcessing');

        $result = $this->optimizer->analyzeHeuristic($file);

        self::assertSame([
            'optimized'  => false,
            'savedBytes' => 0,
            'before'     => 0,
            'after'      => 0,
            'tool'       => null,
        ], $result);
    }

    #[Test]
    public function analyzeHeuristicReturnsEmptyWhenLocalFileMissing(): void
    {
        $file = $this->createMock(FileInterface::class);
        $file->method('getExtension')->willReturn('png');
        $file->method('getForLocalProcessing')
            ->willReturn('/does/not/exist/path/to/file.png');

        $result = $this->optimizer->analyzeHeuristic($file);

        self::assertSame([
            'optimized'  => false,
            'savedBytes' => 0,
            'before'     => 0,
            'after'      => 0,
            'tool'       => null,
        ], $result);
    }

    #[Test]
    public function analyzeHeuristicSkipsFilesBelowMinSize(): void
    {
        $tmp  = $this->createTinyPng();
        $size = filesize($tmp);
        self::assertIsInt($size);
        self::assertLessThan(100, $size, 'Sanity: tiny PNG should be < 100 bytes');

        $file = $this->createMock(FileInterface::class);
        $file->method('getExtension')->willReturn('png');
        $file->method('getForLocalProcessing')->willReturn($tmp);

        // Default minSize (512000) is far larger than the tiny image => early return.
        $result = $this->optimizer->analyzeHeuristic($file);

        self::assertFalse($result['optimized']);
        self::assertSame(0, $result['savedBytes']);
        self::assertSame($size, $result['before']);
        self::assertSame($size, $result['after']);
        self::assertNull($result['tool']);
    }

    /**
     * @return array<string, array{0: string, 1: float}>
     */
    public static function heuristicGainProvider(): array
    {
        return [
            'jpg 15% base gain'  => ['jpg', 0.15],
            'jpeg 15% base gain' => ['jpeg', 0.15],
            'png 10% base gain'  => ['png', 0.10],
            'gif 10% base gain'  => ['gif', 0.10],
        ];
    }

    #[Test]
    #[DataProvider('heuristicGainProvider')]
    public function analyzeHeuristicAppliesBaseGainPerType(string $extension, float $expectedGain): void
    {
        $tmp    = $this->createImageOfType($extension);
        $padded = $this->padFileToMinSize($tmp, 600_000);

        $file = $this->createMock(FileInterface::class);
        $file->method('getExtension')->willReturn($extension);
        $file->method('getForLocalProcessing')->willReturn($padded);

        // maxWidth/maxHeight larger than any dimension => no scaling, only base gain.
        $result = $this->optimizer->analyzeHeuristic($file, 10_000, 10_000, 512_000);

        self::assertTrue($result['optimized']);
        self::assertGreaterThan(0, $result['savedBytes']);

        $before = $result['before'];
        $after  = $result['after'];

        $expectedAfter = (int) round($before * (1 - $expectedGain));
        // Allow for rounding
        self::assertEqualsWithDelta($expectedAfter, $after, 1);
        self::assertSame($before - $after, $result['savedBytes']);
        self::assertNull($result['tool']);
    }

    #[Test]
    public function analyzeHeuristicScalesDownLargeImages(): void
    {
        // Create a large (200x100) PNG and pad the file so size >= minSize.
        $tmp        = $this->createPngWithDimensions(200, 100);
        $paddedPath = $this->padFileToMinSize($tmp, 600_000);

        $file = $this->createMock(FileInterface::class);
        $file->method('getExtension')->willReturn('png');
        $file->method('getForLocalProcessing')->willReturn($paddedPath);

        // maxWidth 100 forces scale = 100/200 = 0.5 => areaFactor = 0.25
        // after ≈ before * 0.25 * 0.9 ≈ before * 0.225
        $result = $this->optimizer->analyzeHeuristic($file, 100, 100, 512_000);

        self::assertTrue($result['optimized']);
        $before = $result['before'];
        $after  = $result['after'];
        self::assertLessThan($before, $after);

        // Expected: before * min(100/200, 100/100)^2 * (1 - 0.10) = before * 0.25 * 0.9
        $expected = (int) round($before * 0.25 * 0.9);
        self::assertEqualsWithDelta($expected, $after, 2);
    }

    #[Test]
    public function analyzeHeuristicFallsBackToFilePropertiesWhenGetImageSizeFails(): void
    {
        // Create a non-image file with .png extension so getimagesize() fails
        // but file exists and has enough bytes to pass the minSize gate.
        $fakePng           = sys_get_temp_dir() . '/nr-pio-fake-png-' . uniqid('', true) . '.png';
        $this->tempFiles[] = $fakePng;
        file_put_contents($fakePng, str_repeat('A', 600_000));

        $file = $this->createMock(FileInterface::class);
        $file->method('getExtension')->willReturn('png');
        $file->method('getForLocalProcessing')->willReturn($fakePng);
        $file->method('getProperty')
            ->willReturnCallback(static fn (string $key): mixed => match ($key) {
                'width'  => 4000,
                'height' => 3000,
                default  => null,
            });

        $result = $this->optimizer->analyzeHeuristic($file, 2000, 1500, 512_000);

        self::assertTrue($result['optimized']);
        // With 4000x3000 scaled to 2000x1500 box, scale = 0.5; after = before * 0.25 * 0.9
        $before   = $result['before'];
        $expected = (int) round($before * 0.25 * 0.9);
        self::assertEqualsWithDelta($expected, $result['after'], 2);
    }

    #[Test]
    public function analyzeHeuristicHandlesMissingDimensionsGracefully(): void
    {
        $fakePng           = sys_get_temp_dir() . '/nr-pio-nodim-' . uniqid('', true) . '.png';
        $this->tempFiles[] = $fakePng;
        file_put_contents($fakePng, str_repeat('B', 600_000));

        $file = $this->createMock(FileInterface::class);
        $file->method('getExtension')->willReturn('png');
        $file->method('getForLocalProcessing')->willReturn($fakePng);
        $file->method('getProperty')->willReturn(null);

        // With width/height unknown (both 0) the scaling branch is skipped;
        // only the base compression gain applies.
        $result = $this->optimizer->analyzeHeuristic($file, 100, 100, 512_000);

        $before   = $result['before'];
        $expected = (int) round($before * (1 - 0.10));
        self::assertEqualsWithDelta($expected, $result['after'], 1);
    }

    #[Test]
    public function analyzeHeuristicDoesNotScaleWhenImageFitsInBox(): void
    {
        // Small 50x40 image inside a big box — no scaling applied.
        $tmp        = $this->createPngWithDimensions(50, 40);
        $paddedPath = $this->padFileToMinSize($tmp, 600_000);

        $file = $this->createMock(FileInterface::class);
        $file->method('getExtension')->willReturn('png');
        $file->method('getForLocalProcessing')->willReturn($paddedPath);

        $result   = $this->optimizer->analyzeHeuristic($file, 2560, 1440, 512_000);
        $before   = $result['before'];
        $expected = (int) round($before * 0.9);
        self::assertEqualsWithDelta($expected, $result['after'], 1);
    }

    #[Test]
    public function analyzeHeuristicCleansUpLocalProcessingCopy(): void
    {
        $tmp               = $this->createTinyPng();
        $this->tempFiles[] = $tmp;

        $file = $this->createMock(FileInterface::class);
        $file->method('getExtension')->willReturn('png');
        $file->method('getForLocalProcessing')->willReturn($tmp);

        $this->optimizer->analyzeHeuristic($file);

        // The analyzeHeuristic cleans up the local processing copy via unlink()
        // in a finally block.
        self::assertFileDoesNotExist($tmp);
    }

    #[Test]
    public function optimizeReturnsEmptyResultWhenLocalFileMissing(): void
    {
        $fake              = sys_get_temp_dir() . '/nr-pio-fake-jpegoptim-' . uniqid('', true);
        $this->tempFiles[] = $fake;
        file_put_contents($fake, "#!/bin/sh\nexit 0\n");
        chmod($fake, 0o755);
        putenv('JPEGOPTIM_BIN=' . $fake);

        $optimizer = new ImageOptimizer();

        $file = $this->createMock(FileInterface::class);
        $file->method('getExtension')->willReturn('jpg');
        $file->method('getForLocalProcessing')
            ->willReturn('/does/not/exist/path.jpg');

        $result = $optimizer->optimize($file);

        // Tool is set (resolver succeeded) but processing aborted before run.
        self::assertFalse($result['optimized']);
        self::assertSame(0, $result['before']);
        self::assertSame(0, $result['after']);
        self::assertSame('jpegoptim', $result['tool']);
    }

    #[Test]
    public function optimizeReturnsEarlyOnDryRunWithoutRunningTool(): void
    {
        // Point the env override at /bin/false — if the tool were run, the
        // process would fail. Dry-run must skip the run entirely and return
        // before/after equal to the original size.
        putenv('JPEGOPTIM_BIN=/bin/false');

        $optimizer = new ImageOptimizer();

        $tmp               = sys_get_temp_dir() . '/nr-pio-dry-' . uniqid('', true) . '.jpg';
        $this->tempFiles[] = $tmp;
        file_put_contents($tmp, str_repeat('A', 1234));
        $sizeBefore = filesize($tmp);
        self::assertIsInt($sizeBefore);

        $file = $this->createMock(FileInterface::class);
        $file->method('getExtension')->willReturn('jpg');
        $file->method('getForLocalProcessing')->willReturn($tmp);

        $result = $optimizer->optimize($file, false, null, true);

        self::assertFalse($result['optimized']);
        self::assertSame(0, $result['savedBytes']);
        self::assertSame($sizeBefore, $result['before']);
        self::assertSame($sizeBefore, $result['after']);
        self::assertSame('jpegoptim', $result['tool']);
    }

    #[Test]
    public function optimizeReportsNoSavingsWhenToolDoesNotShrinkFile(): void
    {
        // /bin/true exits 0 without modifying the file => after == before =>
        // the "no shrink" branch returns optimized=false.
        putenv('JPEGOPTIM_BIN=/bin/true');

        $optimizer = new ImageOptimizer();

        $tmp               = sys_get_temp_dir() . '/nr-pio-nogain-' . uniqid('', true) . '.jpg';
        $this->tempFiles[] = $tmp;
        file_put_contents($tmp, str_repeat('A', 2048));
        $sizeBefore = filesize($tmp);
        self::assertIsInt($sizeBefore);

        $storage = $this->createMock(ResourceStorage::class);
        // replaceFile must NOT be called when no savings occurred.
        $storage->expects(self::never())->method('replaceFile');

        $file = $this->createMock(FileInterface::class);
        $file->method('getExtension')->willReturn('jpg');
        $file->method('getForLocalProcessing')->willReturn($tmp);
        $file->method('getStorage')->willReturn($storage);

        $result = $optimizer->optimize($file);

        self::assertFalse($result['optimized']);
        self::assertSame(0, $result['savedBytes']);
        self::assertSame($sizeBefore, $result['before']);
        self::assertSame($sizeBefore, $result['after']);
        self::assertSame('jpegoptim', $result['tool']);
        // The local processing copy is cleaned up via finally/unlink.
        self::assertFileDoesNotExist($tmp);
    }

    #[Test]
    public function optimizeReportsOptimizedAndCallsReplaceFileWhenToolShrinks(): void
    {
        // Wrapper script that truncates its last argument (the file path)
        // so `after < before` and the "optimized" branch runs.
        $wrapper           = sys_get_temp_dir() . '/nr-pio-shrink-' . uniqid('', true) . '.sh';
        $this->tempFiles[] = $wrapper;
        file_put_contents($wrapper, <<<'SHELL'
            #!/bin/sh
            eval "last=\${$#}"
            : > "$last"
            exit 0

            SHELL);
        chmod($wrapper, 0o755);

        putenv('JPEGOPTIM_BIN=' . $wrapper);

        $optimizer = new ImageOptimizer();

        $tmp               = sys_get_temp_dir() . '/nr-pio-shrink-src-' . uniqid('', true) . '.jpg';
        $this->tempFiles[] = $tmp;
        file_put_contents($tmp, str_repeat('A', 4096));
        $sizeBefore = filesize($tmp);
        self::assertIsInt($sizeBefore);

        $storage = $this->createMock(ResourceStorage::class);
        $file    = $this->createMock(FileInterface::class);
        $file->method('getExtension')->willReturn('jpg');
        $file->method('getForLocalProcessing')->willReturn($tmp);
        $file->method('getStorage')->willReturn($storage);

        // Storage::replaceFile must be invoked with the original file + the
        // shrunk local path before the finally block unlinks it.
        $storage->expects(self::once())
            ->method('replaceFile')
            ->with($file, $tmp);

        $result = $optimizer->optimize($file);

        self::assertTrue($result['optimized']);
        self::assertSame($sizeBefore, $result['before']);
        self::assertSame(0, $result['after']);
        self::assertSame($sizeBefore, $result['savedBytes']);
        self::assertSame('jpegoptim', $result['tool']);
    }

    #[Test]
    public function optimizeReturnsFailureWhenProcessExitsNonZero(): void
    {
        // /bin/false exits 1 => process->isSuccessful() returns false =>
        // optimize() returns optimized=false with the tool name set.
        putenv('JPEGOPTIM_BIN=/bin/false');

        $optimizer = new ImageOptimizer();

        $tmp               = sys_get_temp_dir() . '/nr-pio-fail-' . uniqid('', true) . '.jpg';
        $this->tempFiles[] = $tmp;
        file_put_contents($tmp, str_repeat('A', 2048));
        $sizeBefore = filesize($tmp);
        self::assertIsInt($sizeBefore);

        $storage = $this->createMock(ResourceStorage::class);
        $storage->expects(self::never())->method('replaceFile');

        $file = $this->createMock(FileInterface::class);
        $file->method('getExtension')->willReturn('jpg');
        $file->method('getForLocalProcessing')->willReturn($tmp);
        $file->method('getStorage')->willReturn($storage);

        $result = $optimizer->optimize($file);

        self::assertFalse($result['optimized']);
        self::assertSame(0, $result['savedBytes']);
        self::assertSame($sizeBefore, $result['before']);
        self::assertSame($sizeBefore, $result['after']);
        self::assertSame('jpegoptim', $result['tool']);
    }

    #[Test]
    public function analyzeReturnsEmptyResultWhenLocalFileMissing(): void
    {
        $fake              = sys_get_temp_dir() . '/nr-pio-fake-jpegoptim-' . uniqid('', true);
        $this->tempFiles[] = $fake;
        file_put_contents($fake, "#!/bin/sh\nexit 0\n");
        chmod($fake, 0o755);
        putenv('JPEGOPTIM_BIN=' . $fake);

        $optimizer = new ImageOptimizer();

        $file = $this->createMock(FileInterface::class);
        $file->method('getExtension')->willReturn('jpg');
        $file->method('getForLocalProcessing')
            ->willReturn('/does/not/exist/path.jpg');

        $result = $optimizer->analyze($file);

        self::assertFalse($result['optimized']);
        self::assertSame(0, $result['before']);
        self::assertSame(0, $result['after']);
        self::assertSame('jpegoptim', $result['tool']);
    }

    #[Test]
    public function analyzeReportsNoSavingsWhenToolDoesNotShrink(): void
    {
        putenv('JPEGOPTIM_BIN=/bin/true');

        $optimizer = new ImageOptimizer();

        $tmp               = sys_get_temp_dir() . '/nr-pio-ana-nogain-' . uniqid('', true) . '.jpg';
        $this->tempFiles[] = $tmp;
        file_put_contents($tmp, str_repeat('A', 2048));
        $sizeBefore = filesize($tmp);
        self::assertIsInt($sizeBefore);

        $file = $this->createMock(FileInterface::class);
        $file->method('getExtension')->willReturn('jpg');
        $file->method('getForLocalProcessing')->willReturn($tmp);

        $result = $optimizer->analyze($file);

        self::assertFalse($result['optimized']);
        self::assertSame(0, $result['savedBytes']);
        self::assertSame($sizeBefore, $result['before']);
        self::assertSame($sizeBefore, $result['after']);
        self::assertSame('jpegoptim', $result['tool']);
        // analyze() must also clean up the local copy.
        self::assertFileDoesNotExist($tmp);
    }

    #[Test]
    public function analyzeReportsImprovableWhenToolShrinksWithoutWriteBack(): void
    {
        $wrapper           = sys_get_temp_dir() . '/nr-pio-ana-shrink-' . uniqid('', true) . '.sh';
        $this->tempFiles[] = $wrapper;
        file_put_contents($wrapper, <<<'SHELL'
            #!/bin/sh
            eval "last=\${$#}"
            : > "$last"
            exit 0

            SHELL);
        chmod($wrapper, 0o755);

        putenv('JPEGOPTIM_BIN=' . $wrapper);

        $optimizer = new ImageOptimizer();

        $tmp               = sys_get_temp_dir() . '/nr-pio-ana-src-' . uniqid('', true) . '.jpg';
        $this->tempFiles[] = $tmp;
        file_put_contents($tmp, str_repeat('A', 4096));
        $sizeBefore = filesize($tmp);
        self::assertIsInt($sizeBefore);

        // analyze() must never touch storage or call replaceFile — verify by
        // not providing a storage mock (if the method were called, the
        // default MockBuilder stub would succeed, but we can at least confirm
        // the result data matches).
        $file = $this->createMock(FileInterface::class);
        $file->method('getExtension')->willReturn('jpg');
        $file->method('getForLocalProcessing')->willReturn($tmp);
        // getStorage must NOT be accessed by analyze() — but PHPUnit mocks
        // return a fresh stub on unexpected calls, so we guard with a strict
        // expectation.
        $file->expects(self::never())->method('getStorage');

        $result = $optimizer->analyze($file);

        self::assertTrue($result['optimized']);
        self::assertSame($sizeBefore, $result['before']);
        self::assertSame(0, $result['after']);
        self::assertSame($sizeBefore, $result['savedBytes']);
        self::assertSame('jpegoptim', $result['tool']);
    }

    #[Test]
    public function analyzeReturnsFailureWhenProcessExitsNonZero(): void
    {
        putenv('JPEGOPTIM_BIN=/bin/false');

        $optimizer = new ImageOptimizer();

        $tmp               = sys_get_temp_dir() . '/nr-pio-ana-fail-' . uniqid('', true) . '.jpg';
        $this->tempFiles[] = $tmp;
        file_put_contents($tmp, str_repeat('A', 2048));
        $sizeBefore = filesize($tmp);
        self::assertIsInt($sizeBefore);

        $file = $this->createMock(FileInterface::class);
        $file->method('getExtension')->willReturn('jpg');
        $file->method('getForLocalProcessing')->willReturn($tmp);
        $file->expects(self::never())->method('getStorage');

        $result = $optimizer->analyze($file);

        self::assertFalse($result['optimized']);
        self::assertSame(0, $result['savedBytes']);
        self::assertSame($sizeBefore, $result['before']);
        self::assertSame($sizeBefore, $result['after']);
        self::assertSame('jpegoptim', $result['tool']);
    }

    #[Test]
    public function optimizeDispatchesOptipngForPngExtension(): void
    {
        // Confirms that each extension picks up the right binary. /bin/true
        // succeeds => no shrink => "no savings" branch but tool name reflects
        // the resolver's choice.
        putenv('OPTIPNG_BIN=/bin/true');

        $optimizer = new ImageOptimizer();

        $tmp               = sys_get_temp_dir() . '/nr-pio-png-' . uniqid('', true) . '.png';
        $this->tempFiles[] = $tmp;
        file_put_contents($tmp, str_repeat('A', 2048));

        $storage = $this->createMock(ResourceStorage::class);
        $file    = $this->createMock(FileInterface::class);
        $file->method('getExtension')->willReturn('png');
        $file->method('getForLocalProcessing')->willReturn($tmp);
        $file->method('getStorage')->willReturn($storage);

        $result = $optimizer->optimize($file);

        self::assertFalse($result['optimized']);
        self::assertSame('optipng', $result['tool']);
    }

    #[Test]
    public function optimizeDispatchesGifsicleForGifExtension(): void
    {
        putenv('GIFSICLE_BIN=/bin/true');

        $optimizer = new ImageOptimizer();

        $tmp               = sys_get_temp_dir() . '/nr-pio-gif-' . uniqid('', true) . '.gif';
        $this->tempFiles[] = $tmp;
        file_put_contents($tmp, str_repeat('A', 2048));

        $storage = $this->createMock(ResourceStorage::class);
        $file    = $this->createMock(FileInterface::class);
        $file->method('getExtension')->willReturn('gif');
        $file->method('getForLocalProcessing')->willReturn($tmp);
        $file->method('getStorage')->willReturn($storage);

        $result = $optimizer->optimize($file);

        self::assertFalse($result['optimized']);
        self::assertSame('gifsicle', $result['tool']);
    }

    #[Test]
    public function buildJpegoptimArgsClampsQualityAndAppliesStrip(): void
    {
        $method = new ReflectionMethod(ImageOptimizer::class, 'buildJpegoptimArgs');

        /** @var list<string> $none */
        $none = $method->invoke($this->optimizer, '/tmp/image.jpg', null, false);
        self::assertSame(['-q', '/tmp/image.jpg'], $none);

        /** @var list<string> $stripped */
        $stripped = $method->invoke($this->optimizer, '/tmp/image.jpg', null, true);
        self::assertSame(['--strip-all', '-q', '/tmp/image.jpg'], $stripped);

        /** @var list<string> $withQuality */
        $withQuality = $method->invoke($this->optimizer, '/tmp/image.jpg', 80, true);
        self::assertSame(['--strip-all', '--max=80', '-q', '/tmp/image.jpg'], $withQuality);

        /** @var list<string> $qualityTooHigh */
        $qualityTooHigh = $method->invoke($this->optimizer, '/tmp/image.jpg', 250, false);
        self::assertSame(['--max=100', '-q', '/tmp/image.jpg'], $qualityTooHigh);

        /** @var list<string> $qualityTooLow */
        $qualityTooLow = $method->invoke($this->optimizer, '/tmp/image.jpg', -50, false);
        self::assertSame(['--max=0', '-q', '/tmp/image.jpg'], $qualityTooLow);

        /** @var list<string> $qualityZero */
        $qualityZero = $method->invoke($this->optimizer, '/tmp/image.jpg', 0, false);
        self::assertSame(['--max=0', '-q', '/tmp/image.jpg'], $qualityZero);

        /** @var list<string> $qualityFullRange */
        $qualityFullRange = $method->invoke($this->optimizer, '/tmp/image.jpg', 100, false);
        self::assertSame(['--max=100', '-q', '/tmp/image.jpg'], $qualityFullRange);
    }

    /**
     * Create a 1x1 PNG for tests that need a real image on disk.
     */
    private function createTinyPng(): string
    {
        return $this->createPngWithDimensions(1, 1);
    }

    /**
     * @param int<1, max> $width
     * @param int<1, max> $height
     */
    private function createPngWithDimensions(int $width, int $height): string
    {
        $tmp               = sys_get_temp_dir() . '/nr-pio-' . uniqid('', true) . '.png';
        $this->tempFiles[] = $tmp;

        $gd = imagecreatetruecolor($width, $height);
        self::assertNotFalse($gd);
        imagepng($gd, $tmp);

        return $tmp;
    }

    private function createImageOfType(string $extension): string
    {
        $tmp               = sys_get_temp_dir() . '/nr-pio-' . uniqid('', true) . '.' . $extension;
        $this->tempFiles[] = $tmp;

        $gd = imagecreatetruecolor(5, 5);
        self::assertNotFalse($gd);
        match ($extension) {
            'png'         => imagepng($gd, $tmp),
            'gif'         => imagegif($gd, $tmp),
            'jpg', 'jpeg' => imagejpeg($gd, $tmp),
            default       => imagepng($gd, $tmp),
        };

        return $tmp;
    }

    /**
     * Pad a file so its size reaches at least $minBytes. The heuristic
     * analyzer ignores anything smaller than minSize, so we need real
     * on-disk bytes to drive the size-based branches.
     *
     * PHP caches stat results per path; fopen/fwrite do NOT invalidate that
     * cache, so a subsequent filesize() in production code would return the
     * pre-pad size. clearstatcache() after writing ensures the production
     * code sees the post-pad size.
     */
    private function padFileToMinSize(string $path, int $minBytes): string
    {
        clearstatcache(true, $path);
        $current = filesize($path);
        self::assertIsInt($current);

        if ($current < $minBytes) {
            $delta = $minBytes - $current;
            $fh    = fopen($path, 'ab');
            self::assertNotFalse($fh);
            fwrite($fh, str_repeat("\x00", $delta));
            fclose($fh);
            clearstatcache(true, $path);
        }

        return $path;
    }
}
