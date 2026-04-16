.. include:: /Includes.rst.txt

..  _introduction:

============
Introduction
============

..  _introduction-what-it-does:

What does it do?
================

The *Image Optimization for TYPO3* extension (``nr_image_optimize``) compresses
images in TYPO3 on three layers:

-   **On upload.** A PSR-14 event listener runs lossless
    optimization when a file is added to, or replaced in, a
    FAL storage (via ``optipng``, ``gifsicle``,
    ``jpegoptim``).
-   **On demand in the frontend.** Resized and re-encoded
    variants are produced lazily by the
    :php:class:`~Netresearch\\NrImageOptimize\\Processor` when
    a visitor first requests the ``/processed/`` URL.
-   **In bulk from the CLI.** The :ref:`nr:image:optimize
    <usage-cli-optimize>` and :ref:`nr:image:analyze
    <usage-cli-analyze>` commands iterate the FAL index to
    operate on an entire installation.

This combination reduces server load during content editing,
keeps storage footprint small, and ensures that only images
actually viewed by visitors pay the variant-generation cost.

..  _introduction-features:

Features
========

-   **Automatic optimization on upload.** Compresses newly
    added or replaced images in place without re-encoding.
    Storages, offline drivers, and unsupported extensions are
    handled transparently.
-   **Bulk CLI commands.** ``nr:image:optimize`` walks the FAL
    index and compresses eligible images.
    ``nr:image:analyze`` reports optimization potential
    without modifying files (fast heuristic -- no binaries
    invoked).
-   **Lazy image processing.** Variants are optimized only
    when a visitor first requests them.
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
-   **Powered by Intervention Image.** Uses the
    `Intervention Image <https://image.intervention.io/>`__
    library for reliable image manipulation.

..  _introduction-requirements:

Requirements
============

-   PHP 8.2, 8.3, 8.4, or 8.5.
-   TYPO3 13.4 or 14.
-   Intervention Image library 3.11+ (installed automatically
    via Composer).

..  _introduction-optional-binaries:

Optional optimizer binaries
===========================

On-upload and CLI optimization only compress files when a
matching binary is available:

``optipng``
    Lossless PNG compression.

``gifsicle``
    Lossless GIF compression.

``jpegoptim``
    Lossless (default) or lossy JPEG compression.

The extension discovers these binaries through ``$PATH``. You
can override the resolved path per binary via the
``OPTIPNG_BIN``, ``GIFSICLE_BIN``, and ``JPEGOPTIM_BIN``
environment variables. A set-but-invalid override is treated
as authoritative: the tool is reported unavailable rather
than silently falling back to ``$PATH``.

..  _introduction-recommended-extensions:

Recommended extensions
======================

`imageoptimizer <https://github.com/christophlehmann/imageoptimizer>`__
    Alternative TYPO3 image optimization extension that
    integrates a broader set of external binaries with the
    core image processing pipeline.
