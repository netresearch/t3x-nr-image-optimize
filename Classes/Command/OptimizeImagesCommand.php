<?php

/**
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\NrImageOptimize\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;
use Throwable;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceFactory;

use function array_key_exists;
use function array_merge;
use function count;
use function filesize;
use function getenv;
use function is_file;
use function is_string;
use function max;
use function sprintf;
use function strtoupper;
use function trim;
use function unlink;

#[AsCommand(
    name: 'nr:image:optimize',
    description: 'Optimiert PNG, GIF und JPEG in TYPO3-Storages (optipng, gifsicle, jpegoptim).'
)]
final class OptimizeImagesCommand extends AbstractImageCommand
{
    public function __construct(
        private readonly ResourceFactory $factory
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Nur analysieren und anzeigen, keine Dateien verändern')
            ->addOption('storages', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Nur diese Storage-UIDs (Komma-separiert oder mehrfach angeben) berücksichtigen')
            ->addOption('jpeg-quality', null, InputOption::VALUE_REQUIRED, 'JPEG-Qualität (0-100) für jpegoptim; ohne Angabe wird verlustfrei optimiert')
            ->addOption('strip-metadata', null, InputOption::VALUE_NONE, 'Metadaten (EXIF, Kommentare) entfernen, sofern vom Tool unterstützt');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $dryRun          = (bool) $input->getOption('dry-run');
        $strip           = (bool) $input->getOption('strip-metadata');
        $jpegQuality     = $input->getOption('jpeg-quality');
        $onlyStorageUids = $this->parseStorageUidsOption((array) $input->getOption('storages'));

        [$optipng, $gifsicle, $jpegoptim] = [
            $this->resolveBinary('optipng'),
            $this->resolveBinary('gifsicle'),
            $this->resolveBinary('jpegoptim'),
        ];

        if (($optipng === null || $optipng === '' || $optipng === '0') && ($gifsicle === null || $gifsicle === '' || $gifsicle === '0') && ($jpegoptim === null || $jpegoptim === '' || $jpegoptim === '0')) {
            $io->error('Keine Optimierungstools gefunden (optipng, gifsicle, jpegoptim). Bitte installieren und im PATH verfügbar machen.');

            return self::FAILURE;
        }

        $total = [
            'files'      => 0,
            'optimized'  => 0,
            'bytesSaved' => 0,
            'skipped'    => 0,
        ];

        /** @var list<array<string,mixed>> $records */
        $records = $this->iterateViaIndex($onlyStorageUids);
        $count   = count($records);
        if ($count === 0) {
            $io->warning('No matching image files found to process.');

            return self::SUCCESS;
        }

        $io->title('Image optimization');
        if ($dryRun) {
            $io->note(sprintf('Dry-run enabled. Found %d image file(s) to evaluate.', $count));
        } else {
            $io->note(sprintf('Found %d image file(s) to process.', $count));
        }

        ['progress' => $progress, 'messageMax' => $messageMax] = $this->createProgress($io, $count);

        foreach ($records as $record) {
            $label = $this->buildLabel($record);
            $progress->setMessage($this->shortenLabel($label, $messageMax));

            $file    = $this->factory->getFileObject($record['uid']);
            $ext     = $file->getExtension();
            $storage = $file->getStorage();
            $storage->setEvaluatePermissions(false);
            if (!$file instanceof File) {
                continue;
            }

            // Map auf Tool
            $tool = $this->resolveToolForExtension($ext, $optipng, $gifsicle, $jpegoptim);
            if ($tool === null) {
                $io->writeln(sprintf('<comment>Überspringe %s: kein geeignetes Tool gefunden</comment>', $file->getIdentifier()));
                ++$total['skipped'];
                continue;
            }

            ++$total['files'];

            try {
                $localPath = $file->getForLocalProcessing(true);
            } catch (Throwable) {
                $io->writeln(sprintf('<comment>Überspringe %s: konnte nicht kopieren</comment>', $file->getIdentifier()));
                ++$total['skipped'];
                continue;
            }

            // Writable lokale Kopie besorgen (funktioniert für lokale und Remote-Driver)

            if (!is_file($localPath)) {
                $io->writeln(sprintf('<comment>Überspringe %s: lokale Kopie nicht verfügbar</comment>', $file->getIdentifier()));
                ++$total['skipped'];
                continue;
            }

            $sizeBefore = @filesize($localPath);
            $before     = $sizeBefore === false ? 0 : $sizeBefore;

            try {
                if ($dryRun) {
                    $io->writeln(sprintf('[DRY] Würde %s mit %s optimieren', $file->getIdentifier(), $tool['name']));
                    continue;
                }

                $args = match ($tool['name']) {
                    'optipng'   => ['-o2', '-quiet', $localPath],
                    'gifsicle'  => ['-O3', '-b', $localPath],
                    'jpegoptim' => $this->buildJpegoptimArgs($localPath, $jpegQuality, $strip),
                    default     => [$localPath],
                };

                $process = new Process(array_merge([$tool['bin']], $args));
                $process->setTimeout(600.0);
                $process->run();

                if (!$process->isSuccessful()) {
                    $progress->clear();
                    $io->writeln(sprintf('<error>%s fehlgeschlagen für %s</error>', $tool['name'], $file->getIdentifier()));
                    $io->writeln($process->getErrorOutput());
                    $progress->display();
                    ++$total['skipped'];
                    continue;
                }

                $sizeAfter = @filesize($localPath);
                $after     = $sizeAfter === false ? 0 : $sizeAfter;
                if ($after < $before) {
                    $storage->replaceFile($file, $localPath);
                    $saved = $before - $after;
                    ++$total['optimized'];
                    $total['bytesSaved'] += $saved;
                    $percentage = (100.0 * $saved) / max($before, 1);
                    $savedHuman = $this->formatMbGb($saved);
                    $progress->clear();
                    $io->writeln(sprintf('Optimiert: %s (-%0.2f%%, %d ➜ %d Bytes, gespart: %s) mit %s', $file->getIdentifier(), $percentage, $before, $after, $savedHuman, $tool['name']));
                    $progress->display();
                } else {
                    $progress->clear();
                    $io->writeln(sprintf('Keine Einsparung: %s (Tool: %s)', $file->getIdentifier(), $tool['name']));
                    $progress->display();
                }
            } finally {
                @unlink($localPath);
            }

            $progress->advance();
        }

        $progress->finish();
        $io->newLine(2);
        $io->success(sprintf('Fertig. Dateien: %d, Optimiert: %d, Übersprungen: %d, Eingespart: %d Bytes (%s)', $total['files'], $total['optimized'], $total['skipped'], $total['bytesSaved'], $this->formatMbGb($total['bytesSaved'])));

        return self::SUCCESS;
    }

    /**
     * Liefert ['name' => 'jpegoptim', 'bin' => '/usr/bin/jpegoptim'] oder null.
     *
     * @return array{name:string,bin:string}|null
     */
    private function resolveToolForExtension(string $ext, ?string $optipng, ?string $gifsicle, ?string $jpegoptim): ?array
    {
        $map = [
            'png'  => ['name' => 'optipng', 'bin' => $optipng],
            'gif'  => ['name' => 'gifsicle', 'bin' => $gifsicle],
            'jpg'  => ['name' => 'jpegoptim', 'bin' => $jpegoptim],
            'jpeg' => ['name' => 'jpegoptim', 'bin' => $jpegoptim],
        ];
        if (!array_key_exists($ext, $map) || $map[$ext]['bin'] === null) {
            return null;
        }

        return $map[$ext];
    }

    /**
     * @return list<string>
     */
    private function buildJpegoptimArgs(string $path, string|int|null $quality, bool $strip): array
    {
        $args = [];
        if ($strip) {
            $args[] = '--strip-all';
        }

        if ($quality !== null && $quality !== '') {
            $q = (int) $quality;
            if ($q < 0) {
                $q = 0;
            } elseif ($q > 100) {
                $q = 100;
            }

            $args[] = '--max=' . $q; // lossy, gewünschte Zielqualität
        }

        // jpegoptim schreibt in-place, -q reduziert Ausgabe
        $args[] = '-q';
        $args[] = $path;

        return $args;
    }

    private function resolveBinary(string $binary): ?string
    {
        // Erlaubt Override per ENV, z.B. OPTIPNG_BIN, GIFSICLE_BIN, JPEGOPTIM_BIN
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
