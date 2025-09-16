<?php

/**
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

/**
 * Simple CLI helper that verifies the presence of required external image
 * optimization tools on the current system.
 *
 * It checks that the following binaries are available in the current PATH:
 *  - jpegoptim (JPEG optimization)
 *  - optipng   (PNG optimization)
 *  - gifsicle  (GIF optimization)
 *
 * Implementation details
 * ----------------------
 * - Uses `command -v <binary>` to determine whether a binary is available.
 *   This is a POSIX shell command; it will typically work on Unix-like systems
 *   (Linux, macOS, BSD). On Windows environments, consider running this check
 *   inside WSL or provide equivalents if needed. The script itself is only a
 *   convenience check and not required at runtime.
 * - On missing binary, an error is written to STDERR and the script exits with
 *   code 1. If all binaries are found, their names are printed to STDOUT.
 *
 * Usage
 * -----
 *   php check-binaries.php
 *
 * Exit codes
 * ----------
 *   0  All required binaries were found
 *   1  One or more binaries are missing (message printed to STDERR)
 */

// List of required command-line tools for image optimization.
$binaries = [
    'jpegoptim',
    'optipng',
    'gifsicle',
];

// Verify each binary can be resolved via the user's PATH. If not, emit a clear
// error message and fail fast so setup issues are caught early.
foreach ($binaries as $bin) {
    if (!shell_exec('command -v ' . escapeshellarg($bin))) {
        fwrite(
            STDERR,
            "Error: Required binary '$bin' is not installed or not available in PATH. Please install it before continuing.\n"
        );

        exit(1);
    }

    echo "Found: $bin\n";
}
