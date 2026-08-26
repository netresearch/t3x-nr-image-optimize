<?php

/*
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

/*
 * Seeds the throw-away e2e instance for the performance benchmark.
 *
 * Runs inside the runner's seed container (see e2e_provision_seed() in
 * Build/Scripts/runTests.conf): the TYPO3 instance is at /var/www/html,
 * this extension at /extension (read-only), MariaDB at mariadb-e2e.
 *
 * What it creates:
 *   - 24 synthetic 3000x2000 JPEG "photos" in public/fileadmin/benchmark/
 *     (plasma fractals, one seed per image so no two files are identical)
 *   - public/_bench/control.php, the reset/stat endpoint the suite calls
 *   - four pages below the root page, one per benchmark template
 */

const INSTANCE_ROOT = '/var/www/html';
const PHOTO_COUNT   = 24;
const PHOTO_WIDTH   = 3000;
const PHOTO_HEIGHT  = 2000;

$photoDir = INSTANCE_ROOT . '/public/fileadmin/benchmark';

if (!is_dir($photoDir) && !mkdir($photoDir, 0o777, true) && !is_dir($photoDir)) {
    fwrite(STDERR, "seed: cannot create {$photoDir}\n");
    exit(1);
}

// plasma:fractal at full size is slow; render at a quarter and upscale — the
// decode/resize cost the benchmark measures depends on pixel count, not detail.
for ($i = 1; $i <= PHOTO_COUNT; ++$i) {
    $target = sprintf('%s/photo-%02d.jpg', $photoDir, $i);

    if (is_file($target)) {
        continue;
    }

    $command = sprintf(
        'magick -seed %d -size %dx%d plasma:fractal -resize 400%% -quality 92 %s 2>&1',
        $i * 7919,
        PHOTO_WIDTH / 4,
        PHOTO_HEIGHT / 4,
        escapeshellarg($target),
    );

    exec($command, $output, $exitCode);

    if ($exitCode !== 0 || !is_file($target)) {
        fwrite(STDERR, "seed: generating {$target} failed:\n" . implode("\n", $output) . "\n");
        exit(1);
    }
}

$benchDir = INSTANCE_ROOT . '/public/_bench';

if (!is_dir($benchDir) && !mkdir($benchDir, 0o777, true) && !is_dir($benchDir)) {
    fwrite(STDERR, "seed: cannot create {$benchDir}\n");
    exit(1);
}

if (!copy('/extension/Tests/E2E/Fixtures/bench-control.php', $benchDir . '/control.php')) {
    fwrite(STDERR, "seed: cannot install control endpoint\n");
    exit(1);
}

$pdo = new PDO(
    'mysql:host=mariadb-e2e;port=3306;dbname=e2e_test',
    'root',
    'root',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);

$now = time();

// uid => [slug, template name]. The template name lands in the page's
// subtitle, which the TypoScript in runTests.conf reads as templateName.
$pages = [
    10 => ['core-eager', 'CoreEager'],
    11 => ['ext-eager', 'ExtEager'],
    12 => ['core-lazy', 'CoreLazy'],
    13 => ['ext-lazy', 'ExtLazy'],
    14 => ['ext-eager-jpeg', 'ExtEagerJpeg'],
];

$statement = $pdo->prepare(
    'INSERT INTO pages (uid, pid, title, subtitle, slug, doktype, hidden, deleted, tstamp, crdate)'
    . ' VALUES (?, 1, ?, ?, ?, 1, 0, 0, ?, ?)'
    . ' ON DUPLICATE KEY UPDATE subtitle = VALUES(subtitle), slug = VALUES(slug)',
);

foreach ($pages as $uid => [$slug, $template]) {
    $statement->execute([$uid, 'Benchmark ' . $template, $template, '/bench/' . $slug, $now, $now]);
}

$totalBytes = array_sum(array_map(filesize(...), glob($photoDir . '/photo-*.jpg') ?: []));

printf(
    "seed: %d photos (%dx%d, %.1f MB total), control endpoint, %d benchmark pages\n",
    PHOTO_COUNT,
    PHOTO_WIDTH,
    PHOTO_HEIGHT,
    $totalBytes / 1048576,
    count($pages),
);
