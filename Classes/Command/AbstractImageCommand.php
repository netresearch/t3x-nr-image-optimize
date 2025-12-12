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
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Terminal;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

use function floor;
use function is_numeric;
use function is_string;
use function max;
use function number_format;
use function preg_replace;
use function strlen;
use function substr;

abstract class AbstractImageCommand extends Command
{
    /**
     * @param list<string> $values
     *
     * @return list<int>
     */
    protected function parseStorageUidsOption(array $values): array
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
    protected function iterateViaIndex(array $onlyStorageUids): array
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

    /**
     * @return array{progress: ProgressBar, messageMax: int}
     */
    protected function createProgress(SymfonyStyle $io, int $count): array
    {
        $progress = $io->createProgressBar($count);
        $progress->setFormat(' %current%/%max% [%bar%] %percent:3s%% | %elapsed:6s% | %message%');
        $progress->start();

        $termWidth  = (new Terminal())->getWidth();
        $messageMax = (int) max(10, $termWidth - 40);

        return ['progress' => $progress, 'messageMax' => $messageMax];
    }

    /**
     * @param array<string,mixed> $record
     */
    protected function buildLabel(array $record): string
    {
        return isset($record['identifier']) && is_string($record['identifier']) && $record['identifier'] !== ''
            ? $record['identifier']
            : ('#' . ($record['uid'] ?? '?'));
    }

    protected function shortenLabel(string $text, int $maxLen): string
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

    protected function formatMbGb(int $bytes): string
    {
        $mb = $bytes / 1048576;
        $gb = $bytes / 1073741824;

        return number_format($mb, 2) . ' MB / ' . number_format($gb, 2) . ' GB';
    }
}
