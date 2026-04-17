.. include:: /Includes.rst.txt

..  _introduction:

============
Introduction
============

..  _introduction-what-it-does:

What does it do?
================

The *Image Optimization for TYPO3* extension (``nr_image_optimize``)
compresses images in TYPO3 on three independent layers:

**On upload**
    A PSR-14 event listener runs lossless optimization whenever a
    file is added to or replaced in a FAL storage
    (``AfterFileAddedEvent`` / ``AfterFileReplacedEvent``). The
    listener delegates to the installed ``optipng`` / ``gifsicle``
    / ``jpegoptim`` binaries.

**On demand in the frontend**
    A PSR-15 middleware intercepts every request that starts with
    ``/processed/`` and delegates to the
    :php:class:`~Netresearch\\NrImageOptimize\\Processor`. The
    processor parses the URL, loads the original via
    `Intervention Image <https://image.intervention.io/>`__,
    produces a resized/recropped variant, optionally writes a
    matching WebP and AVIF sidecar, and streams the best result
    back to the client. Variants are cached on disk and served
    with long-lived HTTP caching headers.

**In bulk from the CLI**
    Two Symfony Console commands iterate the FAL index:
    :ref:`nr:image:optimize <usage-cli-optimize>` compresses every
    eligible file, :ref:`nr:image:analyze <usage-cli-analyze>`
    reports optimization potential as a fast heuristic without
    touching any file.

All three layers share a common
:php:class:`~Netresearch\\NrImageOptimize\\Service\\ImageOptimizer`
service for tool resolution and process orchestration.

..  _introduction-features:

Features
========

-   **Automatic optimization on upload.** In-place lossless
    compression without re-encoding. Unsupported extensions,
    offline storages, and missing binaries are handled
    transparently -- the listener never raises.
-   **Bulk CLI commands.** Streaming iteration over ``sys_file``
    keeps memory usage flat on large installations. Progress bar
    with cumulative savings.
-   **On-demand variant generation.** Variants are produced only
    when a visitor first requests them through the ``/processed/``
    URL.
-   **Next-gen format support.** Automatic WebP and AVIF sidecar
    generation with Accept-header-driven content negotiation and
    ``skipWebP`` / ``skipAvif`` opt-outs.
-   **Responsive images.**
    :php:class:`~Netresearch\\NrImageOptimize\\ViewHelpers\\SourceSetViewHelper`
    emits ``<img>`` tags with density-based or width-based
    ``srcset`` + ``sizes``.
-   **Render modes.** Choose between ``cover`` and ``fit`` resize
    strategies per URL.
-   **Fetch priority.** Native ``fetchpriority`` attribute
    support for Core Web Vitals (LCP) tuning.
-   **PSR-14 extension points.**
    :php:class:`~Netresearch\\NrImageOptimize\\Event\\ImageProcessedEvent`
    and
    :php:class:`~Netresearch\\NrImageOptimize\\Event\\VariantServedEvent`
    let integrators observe the pipeline.
-   **Driver abstraction.** Imagick is preferred when the
    extension is loaded; GD is used as a fallback. The
    ``ImageReaderInterface`` adapter (see
    :ref:`developer-image-manager`) hides the Intervention
    Image v3/v4 API difference.
-   **Backend maintenance module.** View statistics about
    processed images, check prerequisites, and clear the cache
    from the TYPO3 backend.
-   **Security.** Path traversal is blocked at URL parsing time,
    quality and dimension values are clamped to safe ranges,
    PSR-7 responses replace direct ``header()`` / ``exit`` calls.

..  _introduction-requirements:

Requirements
============

-   PHP 8.2, 8.3, 8.4, or 8.5.
-   TYPO3 13.4 or 14.
-   Imagick **or** GD PHP extension.
-   Intervention Image 3.11+ (installed automatically via
    Composer).

..  _introduction-optional-binaries:

Optional optimizer binaries
===========================

The on-upload listener and the CLI commands only compress files
when a matching binary is available in ``$PATH``:

``optipng``
    Lossless PNG compression.

``gifsicle``
    Lossless GIF compression.

``jpegoptim``
    Lossless (default) or lossy JPEG compression.

Paths can be pinned per binary via the ``OPTIPNG_BIN``,
``GIFSICLE_BIN``, and ``JPEGOPTIM_BIN`` environment variables. A
set-but-invalid override is treated as authoritative: the tool
is reported unavailable rather than silently falling back to
``$PATH``. ``$PATH`` lookups also verify ``is_executable()``.

See :ref:`installation-optional-binaries` for package-manager
snippets.

..  _introduction-recommended-extensions:

Recommended extensions
======================

`imageoptimizer <https://github.com/christophlehmann/imageoptimizer>`__
    Alternative TYPO3 image optimization extension that
    integrates a broader set of external binaries with the core
    image processing pipeline.
