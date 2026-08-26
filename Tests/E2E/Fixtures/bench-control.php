<?php

/*
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

use TYPO3\CMS\Core\Information\Typo3Version;

/*
 * Benchmark control endpoint for the e2e performance suite.
 *
 * Tests/E2E/Fixtures/seed.php copies this file to public/_bench/control.php
 * of the THROW-AWAY e2e instance. It is never part of the extension and must
 * never be deployed anywhere: it deletes files and flushes caches on request.
 *
 *   GET /_bench/control.php?action=stat
 *       Server CPU time consumed so far by the PHP-FPM container (cgroup v2
 *       cpu.stat, includes ImageMagick child processes), number and size of
 *       variant files TYPO3 core (fileadmin/_processed_/) and this extension
 *       (processed/) have written, and environment versions.
 *
 *   GET /_bench/control.php?action=reset&caches=1&variants=1
 *       caches=1   flushes TYPO3's page cache group (what an editor's
 *                  "clear cache" or a deployment does)
 *       variants=1 deletes every variant file of both pipelines and the
 *                  sys_file_processedfile rows, i.e. "no image has ever been
 *                  processed"
 */

header('Content-Type: application/json');
header('Cache-Control: no-store');

$instanceRoot = dirname(__DIR__, 2);
$publicPath   = $instanceRoot . '/public';

/** @return array{0: int, 1: int} [file count, bytes] */
function countFiles(string $directory): array
{
    if (!is_dir($directory)) {
        return [0, 0];
    }

    $count = 0;
    $bytes = 0;

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($iterator as $file) {
        if ($file->isFile()) {
            ++$count;
            $bytes += $file->getSize();
        }
    }

    return [$count, $bytes];
}

function removeDirectoryContents(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    // Paths come from the directory iterator over the two hard-coded variant
    // directories above, never from the request.
    foreach ($iterator as $entry) {
        if ($entry->isDir()) {
            rmdir($entry->getPathname());
        } else {
            unlink($entry->getPathname()); // nosemgrep: php.lang.security.unlink-use.unlink-use
        }
    }
}

function cpuMicroseconds(): ?int
{
    $stat = @file_get_contents('/sys/fs/cgroup/cpu.stat');

    if ($stat === false || preg_match('/^usage_usec (\d+)/m', $stat, $match) !== 1) {
        return null;
    }

    return (int) $match[1];
}

function databaseConnection(string $instanceRoot): PDO
{
    $settings   = require_once $instanceRoot . '/config/system/settings.php';
    $connection = $settings['DB']['Connections']['Default'];

    return new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s', $connection['host'], $connection['port'] ?? 3306, $connection['dbname']),
        $connection['user'],
        $connection['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
}

function typo3Version(string $instanceRoot): string
{
    require_once $instanceRoot . '/vendor/autoload.php';

    return (new Typo3Version())->getVersion();
}

$action = $_GET['action'] ?? 'stat';

if ($action === 'reset') {
    $done = [];

    if (($_GET['variants'] ?? '0') === '1') {
        removeDirectoryContents($publicPath . '/processed');
        removeDirectoryContents($publicPath . '/fileadmin/_processed_');
        databaseConnection($instanceRoot)->exec('DELETE FROM sys_file_processedfile');
        $done[] = 'variants';
    }

    if (($_GET['caches'] ?? '0') === '1') {
        chdir($instanceRoot);
        exec('php vendor/bin/typo3 cache:flush --group pages 2>&1', $output, $exitCode);

        if ($exitCode !== 0) {
            http_response_code(500);
            echo json_encode(['error' => 'cache:flush failed', 'output' => $output]);
            exit;
        }

        $done[] = 'caches';
    }

    echo json_encode(['reset' => $done]);
    exit;
}

[$coreFiles, $coreBytes] = countFiles($publicPath . '/fileadmin/_processed_');
[$extFiles, $extBytes]   = countFiles($publicPath . '/processed');

echo json_encode([
    'cpuUsec'  => cpuMicroseconds(),
    'variants' => [
        'core' => ['files' => $coreFiles, 'bytes' => $coreBytes],
        'ext'  => ['files' => $extFiles, 'bytes' => $extBytes],
    ],
    'environment' => [
        'typo3'     => typo3Version($instanceRoot),
        'php'       => PHP_VERSION,
        'server'    => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
        'imagick'   => extension_loaded('imagick') ? (Imagick::getVersion()['versionString'] ?? 'unknown') : 'not loaded',
        'processor' => trim((string) shell_exec('magick -version 2>/dev/null | head -n 1')),
    ],
]);
