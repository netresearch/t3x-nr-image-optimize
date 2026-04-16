..  |release| image:: https://img.shields.io/github/v/release/netresearch/t3x-nr-image-optimize?sort=semver
    :target: https://github.com/netresearch/t3x-nr-image-optimize/releases/latest
..  |license| image:: https://img.shields.io/github/license/netresearch/t3x-nr-image-optimize
    :target: https://github.com/netresearch/t3x-nr-image-optimize/blob/main/LICENSE
..  |ci| image:: https://github.com/netresearch/t3x-nr-image-optimize/actions/workflows/ci.yml/badge.svg
    :target: https://github.com/netresearch/t3x-nr-image-optimize/actions/workflows/ci.yml
..  |codecov| image:: https://codecov.io/gh/netresearch/t3x-nr-image-optimize/branch/main/graph/badge.svg
    :target: https://app.codecov.io/gh/netresearch/t3x-nr-image-optimize
..  |php| image:: https://img.shields.io/badge/PHP-8.2%20|%208.3%20|%208.4%20|%208.5-blue.svg
    :target: https://www.php.net/
..  |typo3| image:: https://img.shields.io/badge/TYPO3-13.4%20|%2014-orange.svg
    :target: https://typo3.org/

|php| |typo3| |license| |ci| |codecov| |release|

================================
Image Optimization for TYPO3
================================

The ``nr_image_optimize`` extension provides image optimization
for TYPO3 on three layers:

-   **On upload.** Lossless optimization runs automatically when
    images are added to, or replaced in, a FAL storage (via
    ``optipng``, ``gifsicle``, ``jpegoptim``).
-   **On demand in the frontend.** Variants are processed lazily
    when first requested through the ``/processed/`` URL, with
    support for modern formats (WebP, AVIF), responsive
    ``srcset`` generation, and automatic format negotiation.
-   **In bulk from the CLI.** Two console commands iterate the
    FAL index to optimize or report optimization potential on
    the entire installation.

Features
========

-   **Automatic optimization on upload.** PSR-14 event listener
    compresses newly added or replaced images in place without
    re-encoding. Storages, offline drivers, and unsupported
    extensions are handled transparently.
-   **Bulk CLI commands.** ``nr:image:optimize`` walks the FAL
    index and compresses eligible images. ``nr:image:analyze``
    reports optimization potential without modifying files
    (heuristic, no binaries invoked).
-   **Lazy frontend processing.** Variants are created only when
    a visitor first requests them through the ``/processed/``
    URL.
-   **Modern format support.** Automatic WebP and AVIF
    conversion with fallback to original formats.
-   **Responsive images.** Built-in ``SourceSetViewHelper``
    for ``srcset`` and ``sizes`` generation.
-   **Render modes.** Choose between ``cover`` and ``fit``
    resize strategies.
-   **Width-based srcset.** Optional responsive ``srcset``
    with configurable width variants and ``sizes`` attribute.
-   **Fetch priority.** Native ``fetchpriority`` attribute
    support for Core Web Vitals optimization.
-   **Middleware-based processing.** Lightweight frontend
    middleware intercepts ``/processed/`` requests.
-   **Backend maintenance module.** View statistics, check
    system requirements, and clear processed images.

Requirements
============

-   PHP 8.2, 8.3, 8.4, or 8.5.
-   TYPO3 13.4 or 14.
-   Intervention Image library (installed via Composer
    automatically).

Optional (for on-upload and CLI optimization)
---------------------------------------------

Install one or more of the following binaries and make them
available in ``$PATH`` to enable lossless compression:

-   ``optipng`` -- for PNG files.
-   ``gifsicle`` -- for GIF files.
-   ``jpegoptim`` -- for JPEG files.

Paths can be overridden per binary via the ``OPTIPNG_BIN``,
``GIFSICLE_BIN``, and ``JPEGOPTIM_BIN`` environment variables.
A set-but-invalid override is treated as authoritative (tool
reported unavailable); there is no silent fallback to ``$PATH``.

Installation
============

Via Composer (recommended)
--------------------------

..  code-block:: bash

    composer require netresearch/nr-image-optimize

Manual installation
-------------------

1.  Download the extension from the
    `TYPO3 Extension Repository
    <https://extensions.typo3.org/extension/nr_image_optimize>`__.
2.  Upload to ``typo3conf/ext/``.
3.  Activate the extension in the Extension Manager.

Automatic optimization on upload
================================

After installation the PSR-14 event listener is active out of
the box. Whenever an image is added or replaced on an online
storage (for example via the backend file module or the
REST API) the extension:

1.  Normalizes the file extension (case-insensitive).
2.  Checks whether an optimizer binary for that extension is
    installed.
3.  Runs the tool in place via the ``ImageOptimizer`` service.
4.  Restores the storage's permission-evaluation state and
    clears its re-entrancy guard before returning.

No configuration is required. If no optimizer binary is
available the listener silently skips the file.

CLI commands
============

Bulk optimize existing images
-----------------------------

..  code-block:: bash

    # Dry-run: report what would be processed.
    vendor/bin/typo3 nr:image:optimize --dry-run

    # Optimize all eligible images in storage 1 with lossy JPEG
    # quality 85 and EXIF stripping.
    vendor/bin/typo3 nr:image:optimize \
        --storages=1 \
        --jpeg-quality=85 \
        --strip-metadata

Supported options:

``--dry-run``
    Analyze only, do not modify files.

``--storages``
    Restrict to specific storage UIDs (comma-separated or
    repeated).

``--jpeg-quality``
    Lossy JPEG quality 0--100. Omit for lossless optimization.

``--strip-metadata``
    Remove EXIF and comments when the tool supports it.

Analyze optimization potential
------------------------------

..  code-block:: bash

    vendor/bin/typo3 nr:image:analyze \
        --storages=1 \
        --max-width=2560 \
        --max-height=1440 \
        --min-size=512000

The analyzer never invokes an external tool. It estimates
savings from file size and resolution and reports how much
disk space could be recovered by running
``nr:image:optimize``.

Supported options:

``--storages``
    Restrict to specific storage UIDs.

``--max-width`` / ``--max-height``
    Target display box. Images larger than this box are
    assumed to be downscaled by ``nr:image:optimize`` and
    the estimate factors in the area reduction.

``--min-size``
    Skip files smaller than this many bytes (default
    512 000). Prevents reporting on already-tiny images.

Configuration
=============

The extension works out of the box with sensible defaults.
Images accessed via the ``/processed/`` path are automatically
optimized by the frontend middleware; uploaded images are
compressed by the event listener.

ViewHelper usage
----------------

..  code-block:: html

    {namespace nr=Netresearch\NrImageOptimize\ViewHelpers}

    <nr:sourceSet file="{image}"
                  width="1200"
                  height="800"
                  quality="85"
                  sizes="(max-width: 768px) 100vw, 50vw"
    />

Supported parameters
--------------------

``file``
    Image file resource.

``path``
    Pre-resolved image URI (alternative to ``file``).

``width``
    Target width in pixels.

``height``
    Target height in pixels.

``quality``
    JPEG/WebP quality (1--100).

``sizes``
    Responsive ``sizes`` attribute.

``format``
    Output format: ``auto``, ``webp``, ``avif``, ``jpg``,
    ``png``.

``mode``
    Render mode: ``cover`` (default) or ``fit``.

``responsiveSrcset``
    Enable width-based responsive ``srcset`` instead of
    density-based ``2x``. Default: ``false``.

``widthVariants``
    Width variants for responsive ``srcset``
    (comma-separated string or array).
    Default: ``480, 576, 640, 768, 992, 1200, 1800``.

``fetchpriority``
    Native HTML ``fetchpriority`` attribute (``high``,
    ``low``, ``auto``).

Documentation
=============

Full documentation is available in the ``Documentation/``
directory and is rendered on
`docs.typo3.org
<https://docs.typo3.org/p/netresearch/nr-image-optimize/main/en-us/>`__.

Development and testing
=======================

..  code-block:: bash

    # Run all tests
    composer ci:test

    # Run specific tests
    composer ci:test:php:cgl      # Code style
    composer ci:test:php:lint     # PHP syntax
    composer ci:test:php:phpstan  # Static analysis
    composer ci:test:php:unit     # PHPUnit tests
    composer ci:test:php:rector   # Code quality

    # Dockerized test runner (also used by CI)
    Build/Scripts/runTests.sh -s unit -p 8.4

License
=======

GPL-3.0-or-later. See the
`LICENSE file <LICENSE>`_ for details.

Support
=======

For issues and feature requests, please use the
`GitHub issue tracker
<https://github.com/netresearch/t3x-nr-image-optimize/issues>`_.

Credits
=======

Developed by
`Netresearch DTT GmbH <https://www.netresearch.de/>`_.
