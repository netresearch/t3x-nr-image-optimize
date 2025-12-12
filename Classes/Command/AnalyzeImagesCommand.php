<?php

/**
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\NrImageOptimize\Command;

use Netresearch\NrImageOptimize\Service\ImageOptimizer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Resource\ResourceFactory;

use function count;
use function sprintf;

#[AsCommand(
    name: 'nr:image:analyze',
    description: 'Analysiert Originale und ermittelt Optimierungspotenzial (optipng, gifsicle, jpegoptim).'
)]
final class AnalyzeImagesCommand extends AbstractImageCommand
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

            return self::SUCCESS;
        }

        $io->title('Image optimization — Analyse');
        $io->note(sprintf('Gefundene Bilddateien: %d (Heuristik, keine Tools, keine Änderungen).', $count));

        $total = [
            'files'          => $count,
            'improvable'     => 0,
            'bytesPotential' => 0,
            'noGain'         => 0,
        ];

        ['progress' => $progress, 'messageMax' => $messageMax] = $this->createProgress($io, $count);

        foreach ($records as $record) {
            $label = $this->buildLabel($record);
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

        return self::SUCCESS;
    }
}
