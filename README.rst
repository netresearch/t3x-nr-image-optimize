..  |release| image:: https://img.shields.io/github/v/release/netresearch/t3x-nr-image-optimize?sort=semver
    :target: https://github.com/netresearch/t3x-nr-image-optimize/releases/latest
..  |license| image:: https://img.shields.io/github/license/netresearch/t3x-nr-image-optimize
    :target: https://github.com/netresearch/t3x-nr-image-optimize/blob/main/LICENSE
..  |ci| image:: https://github.com/netresearch/t3x-nr-image-optimize/actions/workflows/ci.yml/badge.svg
    :target: https://github.com/netresearch/t3x-nr-image-optimize/actions/workflows/ci.yml
..  |php| image:: https://img.shields.io/badge/PHP-8.2%20|%208.3%20|%208.4-blue.svg
    :target: https://www.php.net/
..  |typo3| image:: https://img.shields.io/badge/TYPO3-12-orange.svg
    :target: https://typo3.org/

|php| |typo3| |license| |ci| |release|

================================
Image Optimization for TYPO3
================================

The ``nr_image_optimize`` extension optimizes images for TYPO3
via three operating modes: on-demand frontend processing
(lazily via middleware when first requested), on-upload
compression, and bulk CLI tools -- with support for modern
formats (WebP, AVIF), responsive ``srcset`` generation, and
automatic format negotiation.

Features
========

-   **Lazy image processing.** Images are optimized only when
    a visitor first requests them.
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
-   **On-upload compression.** Uploaded and replaced files are
    losslessly compressed in place via ``optipng``,
    ``gifsicle``, or ``jpegoptim``.
-   **Bulk CLI tools.** ``nr:image:optimize`` and
    ``nr:image:analyze`` process or report on existing FAL
    storages.

Requirements
============

-   PHP 8.2, 8.3, or 8.4.
-   TYPO3 12.
-   Intervention Image library (installed via Composer
    automatically).

Recommended extensions
======================

-   `imageoptimizer
    <https://github.com/christophlehmann/imageoptimizer>`__
    -- Optimize images with external binaries of your choice.

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

Configuration
=============

The extension works out of the box with sensible defaults.
Images are automatically optimized when accessed via the
``/processed/`` path.

ViewHelper usage
----------------

..  code-block:: html

    {namespace nr=Netresearch\NrImageOptimize\ViewHelpers}

    <nr:sourceSet path="{f:uri.image(image: image)}"
                  width="1200"
                  height="800"
                  sizes="(max-width: 768px) 100vw, 50vw"
    />

Supported parameters
--------------------

``path`` (required)
    Public path to the source image (for example
    ``/fileadmin/foo.jpg``), typically generated via
    ``f:uri.image()``.

    The ``/processed/`` pipeline handles **public files only**.
    Absolute URLs (``http://``, ``https://``, ``//``), ``data:`` URIs,
    and URLs with a query string — e.g. the tokenized eID URLs
    fal_securedownload generates for files in non-public storages —
    are passed through unchanged and rendered as a plain ``<img>``
    tag: no ``srcset``/``sizes``, no WebP/AVIF variants, but the
    URL's own access control stays intact.

``width``
    Target width in pixels. Default: ``0`` (auto from file).

``height``
    Target height in pixels. Default: ``0`` (auto, keeps ratio).

``alt``
    Alternative text (accessibility). Default: empty string.

``title``
    Title attribute for the image. Default: empty string.

``class``
    CSS classes for the ``<img>`` tag; include ``lazyload`` to use
    JS lazy load. Default: empty string.

``attributes``
    Extra HTML attributes merged into the rendered tag. Default: ``[]``.

``set``
    Responsive set in the form ``{maxWidth: {width: int, height:
    int}}``; each entry becomes a ``<source media="(max-width:
    <maxWidth>px)">`` tag. Default: ``[]``.

``sizes``
    Responsive ``sizes`` attribute.
    Default: ``auto, (min-width: 992px) 991px, 100vw``.

``mode``
    Render mode: ``cover`` (default) or ``fit``.

``lazyload``
    Add ``loading="lazy"`` (native lazy loading). Default: ``false``.

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
