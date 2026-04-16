<?php

/*
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\NrImageOptimize\Command;

use function is_numeric;
use function max;

use Netresearch\NrImageOptimize\Service\ImageOptimizer;

use function sprintf;
use function strtolower;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Resource\ResourceFactory;

#[AsCommand(
    name: 'nr:image:optimize',
    description: 'Optimize PNG, GIF and JPEG in TYPO3 storages (optipng, gifsicle, jpegoptim).',
)]
final class OptimizeImagesCommand extends AbstractImageCommand
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
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Analyze only, do not modify files')
            ->addOption('storages', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only process these storage UIDs (comma-separated or specify multiple times)')
            ->addOption('jpeg-quality', null, InputOption::VALUE_REQUIRED, 'JPEG quality (0-100) for jpegoptim; omit for lossless optimization')
            ->addOption('strip-metadata', null, InputOption::VALUE_NONE, 'Strip metadata (EXIF, comments) if supported by the tool');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $dryRun          = (bool) $input->getOption('dry-run');
        $strip           = (bool) $input->getOption('strip-metadata');
        $jpegQuality     = $input->getOption('jpeg-quality');
        $onlyStorageUids = $this->parseStorageUidsOption((array) $input->getOption('storages'));

        if (!$this->optimizer->hasAnyTool()) {
            $io->error('No optimization tools found (optipng, gifsicle, jpegoptim). Please install and make available in PATH.');

            return self::FAILURE;
        }

        $total = [
            'files'      => 0,
            'optimized'  => 0,
            'bytesSaved' => 0,
            'skipped'    => 0,
        ];

        $count = $this->countImages($onlyStorageUids);
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

        foreach ($this->iterateViaIndex($onlyStorageUids) as $record) {
            try {
                $label = $this->buildLabel($record);
                $progress->setMessage($this->shortenLabel($label, $messageMax));

                $file    = $this->factory->getFileObject($this->extractUid($record));
                $ext     = strtolower($file->getExtension());
                $storage = $file->getStorage();

                // Check tool availability before processing
                $tool = $this->optimizer->resolveToolFor($ext);
                if ($tool === null) {
                    $io->writeln(sprintf('<comment>Skipping %s: no suitable tool found</comment>', $file->getIdentifier()));
                    ++$total['skipped'];
                    continue;
                }

                ++$total['files'];

                // Temporarily disable permission checks for file operations
                $previousPermissions = $storage->getEvaluatePermissions();
                $storage->setEvaluatePermissions(false);

                try {
                    if ($dryRun) {
                        $io->writeln(sprintf('[DRY] Would optimize %s with %s', $file->getIdentifier(), $tool['name']));
                        continue;
                    }

                    $qualityInt = is_numeric($jpegQuality) ? (int) $jpegQuality : null;
                    $result     = $this->optimizer->optimize($file, $strip, $qualityInt, $dryRun);

                    if ($result['tool'] === null) {
                        $io->writeln(sprintf('<comment>Skipping %s: could not be processed</comment>', $file->getIdentifier()));
                        ++$total['skipped'];
                        continue;
                    }

                    if ($result['optimized']) {
                        $saved      = $result['savedBytes'];
                        $before     = $result['before'];
                        $after      = $result['after'];
                        $percentage = (100.0 * $saved) / max($before, 1);
                        $savedHuman = $this->formatMbGb($saved);
                        ++$total['optimized'];
                        $total['bytesSaved'] += $saved;
                        $progress->clear();
                        $io->writeln(sprintf('Optimized: %s (-%0.2f%%, %d ➜ %d Bytes, saved: %s) with %s', $file->getIdentifier(), $percentage, $before, $after, $savedHuman, $result['tool']));
                        $progress->display();
                    } else {
                        $progress->clear();
                        $io->writeln(sprintf('No savings: %s (tool: %s)', $file->getIdentifier(), $result['tool']));
                        $progress->display();
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
        $io->success(sprintf('Done. Files: %d, Optimized: %d, Skipped: %d, Saved: %d Bytes (%s)', $total['files'], $total['optimized'], $total['skipped'], $total['bytesSaved'], $this->formatMbGb($total['bytesSaved'])));

        return self::SUCCESS;
    }
}
