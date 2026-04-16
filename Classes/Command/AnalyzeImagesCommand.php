<?php

/*
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\NrImageOptimize\Command;

use Netresearch\NrImageOptimize\Service\ImageOptimizer;

use function sprintf;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Resource\ResourceFactory;

#[AsCommand(
    name: 'nr:image:analyze',
    description: 'Analyze originals and determine optimization potential (optipng, gifsicle, jpegoptim).',
)]
final class AnalyzeImagesCommand extends AbstractImageCommand
{
    public function __construct(
        private readonly ResourceFactory $factory,
        private readonly ImageOptimizer $optimizer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('storages', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only process these storage UIDs (comma-separated or specify multiple times)')
            ->addOption('max-width', null, InputOption::VALUE_REQUIRED, 'Target display width (px), default 2560')
            ->addOption('max-height', null, InputOption::VALUE_REQUIRED, 'Target display height (px), default 1440')
            ->addOption('min-size', null, InputOption::VALUE_REQUIRED, 'Only consider files >= minimum size in bytes, default 512000');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $onlyStorageUids = $this->parseStorageUidsOption((array) $input->getOption('storages'));
        $maxWidth        = $this->getIntOption($input->getOption('max-width'), 2560);
        $maxHeight       = $this->getIntOption($input->getOption('max-height'), 1440);
        $minSize         = $this->getIntOption($input->getOption('min-size'), 512000);

        $count = $this->countImages($onlyStorageUids);
        if ($count === 0) {
            $io->warning('No matching image files found.');

            return self::SUCCESS;
        }

        $io->title('Image optimization — Analysis');
        $io->note(sprintf('Found %d image file(s) (heuristic, no tools, no modifications).', $count));

        $total = [
            'files'          => $count,
            'improvable'     => 0,
            'bytesPotential' => 0,
            'noGain'         => 0,
        ];

        ['progress' => $progress, 'messageMax' => $messageMax] = $this->createProgress($io, $count);

        foreach ($this->iterateViaIndex($onlyStorageUids) as $record) {
            try {
                $label = $this->buildLabel($record);
                $progress->setMessage($this->shortenLabel($label, $messageMax));

                $file                = $this->factory->getFileObject($this->extractUid($record));
                $storage             = $file->getStorage();
                $previousPermissions = $storage->getEvaluatePermissions();
                $storage->setEvaluatePermissions(false);
                try {
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
                } finally {
                    $storage->setEvaluatePermissions($previousPermissions);
                }
            } finally {
                $progress->advance();
            }
        }

        $progress->finish();
        $io->newLine(2);

        $io->success(sprintf('Analysis complete. Files: %d, Improvable: %d, No potential: %d, Potential savings: %d bytes (%s)', $total['files'], $total['improvable'], $total['noGain'], $total['bytesPotential'], $this->formatMbGb($total['bytesPotential'])));

        return self::SUCCESS;
    }
}
