..  include:: /Includes.rst.txt

..  _introduction:

============
Introduction
============

..  _introduction-what-it-does:

What does it do?
================

The |extension_name| extension (|extension_key|) optimizes images
in TYPO3. Three operating modes activate automatically after
installation:

-   **On-demand frontend processing.** Images are converted and
    resized lazily when first requested through the
    ``/processed/`` URL path, so only images actually viewed by
    visitors are processed.
-   **On-upload compression.** Newly uploaded or replaced files
    are losslessly compressed in place (via ``optipng``,
    ``gifsicle``, or ``jpegoptim``, whichever is installed) by
    an event listener, without changing the file's dimensions
    or format.
-   **Bulk CLI.** The ``nr:image:optimize`` and
    ``nr:image:analyze`` console commands scan existing FAL
    storages. See :ref:`Usage <usage>`.

..  _introduction-features:

Features
========

-   **Lazy image processing.** Images are optimized only when
    a visitor first requests them.
-   **Modern format support.** Automatic WebP and AVIF
    conversion with fallback to original formats.
-   **Responsive images.** Built-in ``SourceSetViewHelper`` for
    ``srcset`` and ``sizes`` generation.
-   **Render modes.** Choose between ``cover`` and ``fit``
    resize strategies.
-   **Width-based srcset.** Optional responsive ``srcset`` with
    configurable width variants and ``sizes`` attribute.
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
-   **Powered by Intervention Image.** Uses the
    `Intervention Image <https://image.intervention.io/>`__
    library for reliable image manipulation.

..  _introduction-requirements:

Requirements
============

-   PHP 8.2, 8.3, or 8.4.
-   TYPO3 12.4.
-   Intervention Image library, version 3.7.2 or 3.11.1
    (installed automatically via Composer).
-   Optional, for on-upload/CLI compression: ``optipng``,
    ``gifsicle``, and/or ``jpegoptim`` on the ``$PATH`` (or
    pointed to via the ``OPTIPNG_BIN``/``GIFSICLE_BIN``/
    ``JPEGOPTIM_BIN`` environment variables). Missing binaries
    degrade gracefully -- that format is simply skipped.

..  _introduction-recommended-extensions:

Recommended extensions
======================

`imageoptimizer <https://github.com/christophlehmann/imageoptimizer>`__
    Additional image optimization tooling that compresses
    uploaded and processed images with external binaries of
    your choice.
