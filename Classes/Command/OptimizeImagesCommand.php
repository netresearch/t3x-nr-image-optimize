<?php
/**
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\NrImageOptimize\Command;

use Doctrine\DBAL\Exception;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

use function array_key_exists;
use function array_merge;
use function in_array;
use function is_file;
use function strtolower;
use function str_starts_with;
use function trim;

#[AsCommand(
    name: 'nr:image:optimize',
    description: 'Optimiert PNG, GIF und JPEG in öffentlichen TYPO3-Storages (optipng, gifsicle, jpegoptim).'
)]
final class OptimizeImagesCommand extends Command
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
            ->addOption('jpeg-quality', null, InputOption::VALUE_REQUIRED, 'JPEG-Qualität (0-100) für jpegoptim; ohne Angabe wird verlustfrei optimiert')
            ->addOption('strip-metadata', null, InputOption::VALUE_NONE, 'Metadaten (EXIF, Kommentare) entfernen, sofern vom Tool unterstützt');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $dryRun = (bool)$input->getOption('dry-run');
        $strip = (bool)$input->getOption('strip-metadata');
        $jpegQuality = $input->getOption('jpeg-quality');

        [$optipng, $gifsicle, $jpegoptim] = [
            $this->resolveBinary('optipng'),
            $this->resolveBinary('gifsicle'),
            $this->resolveBinary('jpegoptim'),
        ];

        if (!$optipng && !$gifsicle && !$jpegoptim) {
            $io->error('Keine Optimierungstools gefunden (optipng, gifsicle, jpegoptim). Bitte installieren und im PATH verfügbar machen.');
            return Command::FAILURE;
        }

        $total = [];

        foreach ($this->iterateViaIndex() as $record) {
            $file = $this->factory->getFileObject($record['uid']);
            $ext = $file->getExtension();
            $storage = $file->getStorage();
            $storage->setEvaluatePermissions(false);
            if (!$file instanceof File) {
                continue;
            }

            // Map auf Tool
            $tool = $this->resolveToolForExtension($ext, $optipng, $gifsicle, $jpegoptim);
            if ($tool === null) {
                $io->writeln(sprintf('<comment>Überspringe %s: kein geeignetes Tool gefunden</comment>', $file->getIdentifier()));
                $total['skipped']++;
                continue;
            }

            $total['files']++;

            try {
                $localPath = $file->getForLocalProcessing(true);
            } catch (\Throwable $e) {
                $io->writeln(sprintf('<comment>Überspringe %s: konnte nicht kopieren</comment>', $file->getIdentifier()));
                $total['skipped']++;
                continue;
            }
            // Writable lokale Kopie besorgen (funktioniert für lokale und Remote-Driver)

            if (!is_file($localPath)) {
                $io->writeln(sprintf('<comment>Überspringe %s: lokale Kopie nicht verfügbar</comment>', $file->getIdentifier()));
                $total['skipped']++;
                continue;
            }

            $before = @filesize($localPath) ?: 0;

            if ($dryRun) {
                $io->writeln(sprintf('[DRY] Würde %s mit %s optimieren', $file->getIdentifier(), $tool['name']));
                continue;
            }

            $args = match ($tool['name']) {
                'optipng' => array_merge(['-o2', '-quiet'], [$localPath]),
                'gifsicle' => array_merge(['-O3', '-b'], [$localPath]),
                'jpegoptim' => $this->buildJpegoptimArgs($localPath, $jpegQuality, $strip),
                default => [$localPath],
            };

            $process = new Process(array_merge([$tool['bin']], $args));
            $process->setTimeout(600.0);
            $process->run();

            if (!$process->isSuccessful()) {
                $io->writeln(sprintf('<error>%s fehlgeschlagen für %s</error>', $tool['name'], $file->getIdentifier()));
                $io->writeln($process->getErrorOutput());
                $total['skipped']++;
                continue;
            }

            $after = @filesize($localPath) ?: 0;
            if ($after > 0 && $after < $before) {
                // Zurück ins Storage schreiben (auch für lokale Treiber sicher)
                $storage->replaceFile($file, $localPath);
                $saved = $before - $after;
                $total['optimized']++;
                $total['bytesSaved'] += $saved;
                $io->writeln(sprintf('Optimiert: %s (-%0.2f%%, %d ➜ %d Bytes) mit %s', $file->getIdentifier(), $before > 0 ? (100.0 * ($saved) / $before) : 0.0, $before, $after, $tool['name']));
            } else {
                $io->writeln(sprintf('Keine Einsparung: %s (Tool: %s)', $file->getIdentifier(), $tool['name']));
            }
        }

        $io->success(sprintf('Fertig. Dateien: %d, Optimiert: %d, Übersprungen: %d, Eingesparte Bytes: %d', $total['files'], $total['optimized'], $total['skipped'], $total['bytesSaved']));
        return Command::SUCCESS;
    }

/**
     * @param list<string> $values
     * @return list<int>
     */
    private function parseStorageUidsOption(array $values): array
    {
        $uids = [];
        foreach ($values as $val) {
            foreach (GeneralUtility::trimExplode(',', (string)$val, true) as $part) {
                if (is_numeric($part)) {
                    $uids[] = (int)$part;
                }
            }
        }
        return $uids;
    }

    private function isPublicStorage(ResourceStorage $storage): bool
    {
        if (method_exists($storage, 'isPublic')) {
            return $storage->isPublic();
        }
        $record = $storage->getStorageRecord();
        return (bool)($record['is_public'] ?? false);
    }

    /**
     * Iteriert Dateien eines Storages über den FAL-Index (sys_file), unabhängig von Browsability.
     *
     * @return array
     * @throws Exception
     */
    private function iterateViaIndex(): array
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('sys_file');

        $qb = $connection->createQueryBuilder();

        return $qb->select('*')
            ->from('sys_file')
            ->where(
                $qb->expr()->in(
                    'mime_type',
                    $qb->createNamedParameter(['image/jpeg','image/gif','image/png'], Connection::PARAM_STR_ARRAY)
                )
            )
            ->executeQuery()->fetchAllAssociative();
    }

    /**
     * Liefert ['name' => 'jpegoptim', 'bin' => '/usr/bin/jpegoptim'] oder null
     *
     * @return array{name:string,bin:string}|null
     */
    private function resolveToolForExtension(string $ext, ?string $optipng, ?string $gifsicle, ?string $jpegoptim): ?array
    {
        $map = [
            'png' => ['name' => 'optipng', 'bin' => $optipng],
            'gif' => ['name' => 'gifsicle', 'bin' => $gifsicle],
            'jpg' => ['name' => 'jpegoptim', 'bin' => $jpegoptim],
            'jpeg' => ['name' => 'jpegoptim', 'bin' => $jpegoptim],
        ];
        if (!array_key_exists($ext, $map) || $map[$ext]['bin'] === null) {
            return null;
        }
        return $map[$ext];
    }

    private function buildJpegoptimArgs(string $path, string|int|null $quality, bool $strip): array
    {
        $args = [];
        if ($strip) {
            $args[] = '--strip-all';
        }
        if ($quality !== null && $quality !== '') {
            $q = (int)$quality;
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
