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
use TYPO3\CMS\Core\Resource\File;

use TYPO3\CMS\Core\Resource\FileInterface;
use function array_key_exists;
use function array_merge;
use function in_array;
use function is_file;
use function strtolower;
use function trim;

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

        $before = @filesize($localPath) ?: 0;
        if ($dryRun) {
            return ['optimized' => false, 'savedBytes' => 0, 'before' => $before, 'after' => $before, 'tool' => $tool['name']];
        }

        $args = match ($tool['name']) {
            'optipng' => array_merge(['-o2', '-quiet'], [$localPath]),
            'gifsicle' => array_merge(['-O3', '-b'], [$localPath]),
            'jpegoptim' => $this->buildJpegoptimArgs($localPath, $jpegQuality, $stripMetadata),
            default => [$localPath],
        };

        $process = new Process(array_merge([$tool['bin']], $args));
        $process->setTimeout(600.0);
        $process->run();
        if (!$process->isSuccessful()) {
            return ['optimized' => false, 'savedBytes' => 0, 'before' => $before, 'after' => $before, 'tool' => $tool['name']];
        }

        $after = @filesize($localPath) ?: 0;
        if ($after > 0 && $after < $before) {
            $file->getStorage()->replaceFile($file, $localPath);
            return [
                'optimized' => true,
                'savedBytes' => $before - $after,
                'before' => $before,
                'after' => $after,
                'tool' => $tool['name'],
            ];
        }

        return ['optimized' => false, 'savedBytes' => 0, 'before' => $before, 'after' => $after, 'tool' => $tool['name']];
    }

    public function hasAnyTool(): bool
    {
        $this->resolveTools();
        return (bool)($this->optipng || $this->gifsicle || $this->jpegoptim);
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
        $this->optipng = $this->resolveBinary('optipng');
        $this->gifsicle = $this->resolveBinary('gifsicle');
        $this->jpegoptim = $this->resolveBinary('jpegoptim');
        $this->toolsResolved = true;
    }

    /**
     * @return array{name:string,bin:string}|null
     */
    private function resolveToolForExtension(string $ext): ?array
    {
        $map = [
            'png' => ['name' => 'optipng', 'bin' => $this->optipng],
            'gif' => ['name' => 'gifsicle', 'bin' => $this->gifsicle],
            'jpg' => ['name' => 'jpegoptim', 'bin' => $this->jpegoptim],
            'jpeg' => ['name' => 'jpegoptim', 'bin' => $this->jpegoptim],
        ];
        if (!array_key_exists($ext, $map) || $map[$ext]['bin'] === null) {
            return null;
        }
        return $map[$ext];
    }

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
        $envName = strtoupper($binary) . '_BIN';
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
