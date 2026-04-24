<?php

/*
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Netresearch\NrImageOptimize\Command;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Exception;

use function floor;

use Generator;

use function is_numeric;
use function is_scalar;
use function is_string;
use function max;
use function number_format;
use function preg_replace;
use function strlen;
use function substr;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Terminal;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

abstract class AbstractImageCommand extends Command
{
    /**
     * @param array<int|string, mixed> $values
     *
     * @return list<int>
     */
    final protected function parseStorageUidsOption(array $values): array
    {
        $uids = [];
        foreach ($values as $val) {
            if (!is_scalar($val)) {
                continue;
            }

            foreach (GeneralUtility::trimExplode(',', (string) $val, true) as $part) {
                if (is_numeric($part)) {
                    $uids[] = (int) $part;
                }
            }
        }

        return $uids;
    }

    /**
     * Returns an integer option value or the default if the option is not a numeric string.
     */
    final protected function getIntOption(mixed $value, int $default): int
    {
        if (is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }

    /**
     * @param list<int> $onlyStorageUids
     *
     * @throws Exception
     */
    final protected function countImages(array $onlyStorageUids): int
    {
        $fileConn = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('sys_file');
        $qb       = $fileConn->createQueryBuilder();

        $qb->count('f.uid')
            ->from('sys_file', 'f')
            ->join('f', 'sys_file_storage', 's', 's.uid = f.storage')
            ->where(
                $qb->expr()->eq('f.missing', 0),
                $qb->expr()->eq('s.is_online', 1),
                $qb->expr()->in(
                    'f.mime_type',
                    $qb->createNamedParameter(['image/jpeg', 'image/gif', 'image/png'], ArrayParameterType::STRING),
                ),
            );

        if ($onlyStorageUids !== []) {
            $qb->andWhere(
                $qb->expr()->in('f.storage', $qb->createNamedParameter($onlyStorageUids, ArrayParameterType::INTEGER)),
            );
        }

        $value = $qb->executeQuery()->fetchOne();

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param list<int> $onlyStorageUids
     *
     * @return Generator<int, array<string, mixed>>
     *
     * @throws Exception
     */
    final protected function iterateViaIndex(array $onlyStorageUids): Generator
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
                    $qb->createNamedParameter(['image/jpeg', 'image/gif', 'image/png'], ArrayParameterType::STRING),
                ),
            )
            ->orderBy('f.uid', 'ASC');

        if ($onlyStorageUids !== []) {
            $qb->andWhere(
                $qb->expr()->in('f.storage', $qb->createNamedParameter($onlyStorageUids, ArrayParameterType::INTEGER)),
            );
        }

        $result = $qb->executeQuery();
        while ($row = $result->fetchAssociative()) {
            yield $row;
        }
    }

    /**
     * @return array{progress: ProgressBar, messageMax: int}
     */
    final protected function createProgress(SymfonyStyle $io, int $count): array
    {
        $progress = $io->createProgressBar($count);
        $progress->setFormat(' %current%/%max% [%bar%] %percent:3s%% | %elapsed:6s% | %message%');
        $progress->start();

        $termWidth  = (new Terminal())->getWidth();
        $messageMax = max(10, $termWidth - 40);

        return ['progress' => $progress, 'messageMax' => $messageMax];
    }

    /**
     * @param array<string,mixed> $record
     */
    final protected function extractUid(array $record): int
    {
        $uid = $record['uid'] ?? null;

        return is_numeric($uid) ? (int) $uid : 0;
    }

    /**
     * @param array<string,mixed> $record
     */
    final protected function buildLabel(array $record): string
    {
        if (array_key_exists('identifier', $record) && is_string($record['identifier']) && $record['identifier'] !== '') {
            return $record['identifier'];
        }

        $uid = $record['uid'] ?? '?';

        return '#' . (is_scalar($uid) ? (string) $uid : '?');
    }

    final protected function shortenLabel(string $text, int $maxLen): string
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

    final protected function formatMbGb(int $bytes): string
    {
        $mb = $bytes / 1048576;
        $gb = $bytes / 1073741824;

        return number_format($mb, 2) . ' MB / ' . number_format($gb, 2) . ' GB';
    }
}
