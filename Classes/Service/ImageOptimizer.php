<?php

/**
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\NrImageOptimize\Service;

use Symfony\Component\Process\Process;
use TYPO3\CMS\Core\Resource\FileInterface;

use function array_key_exists;
use function array_merge;
use function filesize;
use function getenv;
use function in_array;
use function is_file;
use function is_string;
use function strtolower;
use function strtoupper;
use function trim;
use function unlink;

final class ImageOptimizer
{
    /** @var list<string> */
    private const SUPPORTED_EXT = ['png', 'gif', 'jpg', 'jpeg'];

    private ?string $optipng = null;

    private ?string $gifsicle = null;

    private ?string $jpegoptim = null;

    private bool $toolsResolved = false;

    /**
     * Optimiert die Datei. Liefert Ergebnisdaten zurück.
     *
     * @return array{optimized:bool, savedBytes:int, before:int, after:int, tool: string|null}
     */
    public function optimize(FileInterface $file, bool $stripMetadata = false, ?int $jpegQuality = null, bool $dryRun = false): array
    {
        $this->resolveTools();

        $ext = strtolower($file->getExtension());
        if (!in_array($ext, self::SUPPORTED_EXT, true)) {
            return ['optimized' => false, 'savedBytes' => 0, 'before' => 0, 'after' => 0, 'tool' => null];
        }

        $tool = $this->resolveToolForExtension($ext);
        if ($tool === null) {
            return ['optimized' => false, 'savedBytes' => 0, 'before' => 0, 'after' => 0, 'tool' => null];
        }

        $localPath = $file->getForLocalProcessing(true);
        if (!is_file($localPath)) {
            return ['optimized' => false, 'savedBytes' => 0, 'before' => 0, 'after' => 0, 'tool' => $tool['name']];
        }

        $sizeBefore = @filesize($localPath);
        $before     = $sizeBefore === false ? 0 : $sizeBefore;

        try {
            if ($dryRun) {
                return ['optimized' => false, 'savedBytes' => 0, 'before' => $before, 'after' => $before, 'tool' => $tool['name']];
            }

            $workPath = $localPath;

            $args = match ($tool['name']) {
                'optipng'   => ['-o2', '-quiet', $workPath],
                'gifsicle'  => ['-O3', '-b', $workPath],
                'jpegoptim' => $this->buildJpegoptimArgs($workPath, $jpegQuality, $stripMetadata),
                default     => [$workPath],
            };

            $process = new Process(array_merge([$tool['bin']], $args));
            $process->setTimeout(600.0);
            $process->run();
            if (!$process->isSuccessful()) {
                return ['optimized' => false, 'savedBytes' => 0, 'before' => $before, 'after' => $before, 'tool' => $tool['name']];
            }

            $sizeAfter = @filesize($workPath);
            $after     = $sizeAfter === false ? 0 : $sizeAfter;
            if ($after < $before) {
                $file->getStorage()->replaceFile($file, $workPath);

                return [
                    'optimized'  => true,
                    'savedBytes' => $before - $after,
                    'before'     => $before,
                    'after'      => $after,
                    'tool'       => $tool['name'],
                ];
            }

            return ['optimized' => false, 'savedBytes' => 0, 'before' => $before, 'after' => $after, 'tool' => $tool['name']];
        } finally {
            @unlink($localPath);
        }
    }

    public function hasAnyTool(): bool
    {
        $this->resolveTools();

        return ($this->optipng !== null) || ($this->gifsicle !== null) || ($this->jpegoptim !== null);
    }

    /**
     * Analyze potential optimization without modifying original file.
     * Returns the same structure as optimize(), but never writes back.
     *
     * @return array{optimized:bool, savedBytes:int, before:int, after:int, tool: string|null}
     */
    public function analyze(FileInterface $file, bool $stripMetadata = false, ?int $jpegQuality = null): array
    {
        $this->resolveTools();

        $ext = strtolower($file->getExtension());
        if (!in_array($ext, self::SUPPORTED_EXT, true)) {
            return ['optimized' => false, 'savedBytes' => 0, 'before' => 0, 'after' => 0, 'tool' => null];
        }

        $tool = $this->resolveToolForExtension($ext);
        if ($tool === null) {
            return ['optimized' => false, 'savedBytes' => 0, 'before' => 0, 'after' => 0, 'tool' => null];
        }

        $localPath = $file->getForLocalProcessing(true);
        if (!is_file($localPath)) {
            return ['optimized' => false, 'savedBytes' => 0, 'before' => 0, 'after' => 0, 'tool' => $tool['name']];
        }

        $sizeBefore = @filesize($localPath);
        $before     = $sizeBefore === false ? 0 : $sizeBefore;

        try {
            $args = match ($tool['name']) {
                'optipng'   => ['-o2', '-quiet', $localPath],
                'gifsicle'  => ['-O3', '-b', $localPath],
                'jpegoptim' => $this->buildJpegoptimArgs($localPath, $jpegQuality, $stripMetadata),
                default     => [$localPath],
            };

            $process = new Process(array_merge([$tool['bin']], $args));
            $process->setTimeout(600.0);
            $process->run();
            if (!$process->isSuccessful()) {
                return ['optimized' => false, 'savedBytes' => 0, 'before' => $before, 'after' => $before, 'tool' => $tool['name']];
            }

            $sizeAfter = @filesize($localPath);
            $after     = $sizeAfter === false ? 0 : $sizeAfter;

            if ($after < $before) {
                return [
                    'optimized'  => true,
                    'savedBytes' => $before - $after,
                    'before'     => $before,
                    'after'      => $after,
                    'tool'       => $tool['name'],
                ];
            }

            return ['optimized' => false, 'savedBytes' => 0, 'before' => $before, 'after' => $after, 'tool' => $tool['name']];
        } finally {
            @unlink($localPath);
        }
    }

    /** @return list<string> */
    public function supportedExtensions(): array
    {
        return self::SUPPORTED_EXT;
    }

    private function resolveTools(): void
    {
        if ($this->toolsResolved) {
            return;
        }

        $this->optipng       = $this->resolveBinary('optipng');
        $this->gifsicle      = $this->resolveBinary('gifsicle');
        $this->jpegoptim     = $this->resolveBinary('jpegoptim');
        $this->toolsResolved = true;
    }

    /**
     * @return array{name:string,bin:string}|null
     */
    private function resolveToolForExtension(string $ext): ?array
    {
        $map = [
            'png'  => ['name' => 'optipng', 'bin' => $this->optipng],
            'gif'  => ['name' => 'gifsicle', 'bin' => $this->gifsicle],
            'jpg'  => ['name' => 'jpegoptim', 'bin' => $this->jpegoptim],
            'jpeg' => ['name' => 'jpegoptim', 'bin' => $this->jpegoptim],
        ];
        if (!array_key_exists($ext, $map) || $map[$ext]['bin'] === null) {
            return null;
        }

        return $map[$ext];
    }

    /**
     * @return list<string>
     */
    private function buildJpegoptimArgs(string $path, ?int $quality, bool $strip): array
    {
        $args = [];
        if ($strip) {
            $args[] = '--strip-all';
        }

        if ($quality !== null) {
            $q = $quality;
            if ($q < 0) {
                $q = 0;
            } elseif ($q > 100) {
                $q = 100;
            }

            $args[] = '--max=' . $q;
        }

        $args[] = '-q';
        $args[] = $path;

        return $args;
    }

    private function resolveBinary(string $binary): ?string
    {
        $envName  = strtoupper($binary) . '_BIN';
        $override = getenv($envName);
        if (is_string($override) && $override !== '' && is_file($override)) {
            return $override;
        }

        $process = new Process(['which', $binary]);
        $process->run();
        if ($process->isSuccessful()) {
            $path = trim($process->getOutput());

            return $path !== '' ? $path : null;
        }

        return null;
    }
}
