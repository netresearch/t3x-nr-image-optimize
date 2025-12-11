<?php

/**
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\NrImageOptimize\Command;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Exception;
use Netresearch\NrImageOptimize\Service\ImageOptimizer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Terminal;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

use function count;
use function floor;
use function is_numeric;
use function is_string;
use function max;
use function number_format;
use function preg_replace;
use function sprintf;
use function strlen;
use function substr;

#[AsCommand(
    name: 'nr:image:analyze',
    description: 'Analysiert Originale und ermittelt Optimierungspotenzial (optipng, gifsicle, jpegoptim).'
)]
final class AnalyzeImagesCommand extends Command
{
    public function __construct(
        private readonly ResourceFactory $factory,
        private readonly ImageOptimizer $optimizer
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('storages', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Nur diese Storage-UIDs (Komma-separiert oder mehrfach angeben) berücksichtigen')
            ->addOption('max-width', null, InputOption::VALUE_REQUIRED, 'Zielanzeige Breite (px), Default 2560')
            ->addOption('max-height', null, InputOption::VALUE_REQUIRED, 'Zielanzeige Höhe (px), Default 1440')
            ->addOption('min-size', null, InputOption::VALUE_REQUIRED, 'Nur Dateien >= Mindestgröße in Bytes berücksichtigen, Default 512000');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $onlyStorageUids = $this->parseStorageUidsOption((array) $input->getOption('storages'));
        $maxWidth        = (int) ($input->getOption('max-width') ?? 2560);
        $maxHeight       = (int) ($input->getOption('max-height') ?? 1440);
        $minSize         = (int) ($input->getOption('min-size') ?? 512000);

        /** @var list<array<string,mixed>> $records */
        $records = $this->iterateViaIndex($onlyStorageUids);
        $count   = count($records);
        if ($count === 0) {
            $io->warning('Keine passenden Bilddateien gefunden.');

            return Command::SUCCESS;
        }

        $io->title('Image optimization — Analyse');
        $io->note(sprintf('Gefundene Bilddateien: %d (Heuristik, keine Tools, keine Änderungen).', $count));

        $total = [
            'files'          => $count,
            'improvable'     => 0,
            'bytesPotential' => 0,
            'noGain'         => 0,
        ];

        $progress = $io->createProgressBar($count);
        $progress->setFormat(' %current%/%max% [%bar%] %percent:3s%% | %elapsed:6s% | %message%');
        $progress->start();

        $termWidth  = (new Terminal())->getWidth();
        $messageMax = max(10, $termWidth - 40);

        foreach ($records as $record) {
            $label = isset($record['identifier']) && is_string($record['identifier']) && $record['identifier'] !== ''
                ? $record['identifier']
                : ('#' . ($record['uid'] ?? '?'));
            $progress->setMessage($this->shortenLabel($label, $messageMax));

            $file = $this->factory->getFileObject($record['uid']);
            $file->getStorage()->setEvaluatePermissions(false);

            if (!$file->exists()) {
                continue;
            }

            $result = $this->optimizer->analyzeHeuristic($file, $maxWidth, $maxHeight, $minSize);

            if ($result['optimized']) {
                ++$total['improvable'];
                $total['bytesPotential'] += $result['savedBytes'];
            } else {
                ++$total['noGain'];
            }

            $progress->advance();
        }

        $progress->finish();
        $io->newLine(2);

        $io->success(sprintf('Analyse fertig. Dateien: %d, Verbesserbar: %d, Ohne Potenzial: %d, Potenzial: %d Bytes (%s)', $total['files'], $total['improvable'], $total['noGain'], $total['bytesPotential'], $this->formatMbGb($total['bytesPotential'])));

        return Command::SUCCESS;
    }

    private function shortenLabel(string $text, int $maxLen): string
    {
        $plain = preg_replace('/\s+/', ' ', $text) ?? $text;
        if ($maxLen <= 3) {
            return strlen($plain) > $maxLen ? substr($plain, 0, $maxLen) : $plain;
        }
        if (strlen($plain) <= $maxLen) {
            return $plain;
        }
        $keep = (int) max(1, floor(($maxLen - 1) / 2));

        return substr($plain, 0, $keep) . '…' . substr($plain, -$keep);
    }

    private function formatMbGb(int $bytes): string
    {
        $mb = $bytes / 1048576;
        $gb = $bytes / 1073741824;

        return number_format($mb, 2) . ' MB / ' . number_format($gb, 2) . ' GB';
    }

    /**
     * @param list<string> $values
     *
     * @return list<int>
     */
    private function parseStorageUidsOption(array $values): array
    {
        $uids = [];
        foreach ($values as $val) {
            foreach (GeneralUtility::trimExplode(',', (string) $val, true) as $part) {
                if (is_numeric($part)) {
                    $uids[] = (int) $part;
                }
            }
        }

        return $uids;
    }

    /**
     * @param list<int> $onlyStorageUids
     *
     * @return list<array<string,mixed>>
     *
     * @throws Exception
     */
    private function iterateViaIndex(array $onlyStorageUids): array
    {
        $fileConn = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('sys_file');
        $qb       = $fileConn->createQueryBuilder();

        $qb->select('f.*')
            ->from('sys_file', 'f')
            ->join('f', 'sys_file_storage', 's', 's.uid = f.storage')
            ->where(
                $qb->expr()->eq('f.missing', 0),
                $qb->expr()->eq('s.is_online', 1),
                $qb->expr()->in(
                    'f.mime_type',
                    $qb->createNamedParameter(['image/jpeg', 'image/gif', 'image/png'], ArrayParameterType::STRING)
                )
            )
            ->orderBy('f.uid', 'ASC');

        if ($onlyStorageUids !== []) {
            $qb->andWhere(
                $qb->expr()->in('f.storage', $qb->createNamedParameter($onlyStorageUids, ArrayParameterType::INTEGER))
            );
        }

        return $qb->executeQuery()->fetchAllAssociative();
    }
}
