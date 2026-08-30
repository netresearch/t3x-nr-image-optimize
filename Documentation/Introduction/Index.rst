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

..  _introduction-performance:

Performance model
==================

TYPO3's standard image pipeline (``f:image`` / ``ImageService``)
processes every referenced image *synchronously*, during the same
PHP request that renders the page -- including images that never
end up in the visible viewport (sliders, tabs, below-the-fold
content). On a cold cache (no ``_processed_`` variant yet), that
request blocks on decode + resize + encode for every single image
before it can send its response.

The ``SourceSetViewHelper`` instead only builds a
``/processed/...`` URL string while the page renders -- no image is
decoded, resized, or written at that point. (When neither ``width``
nor ``height`` is given, it reads the source file's header once via
``getimagesize()`` to fill in the dimensions; that read is cached
per source file for the rest of the request and never decodes pixel
data.) Processing happens later, in a *separate* HTTP request, only
when ``ProcessingMiddleware`` intercepts a request for that URL --
which only happens for images the visitor's browser actually
fetches.

..  note::

    We measured this against a real TYPO3 functional-test instance,
    comparing ``SourceSetViewHelper::getResourcePath()`` (this
    extension's render-time cost) against TYPO3 core's
    ``GraphicalFunctions::imageMagickConvert()`` (the engine
    ``f:image``/``ImageService`` delegates into), forcing a fresh
    conversion on every call to rule out cache effects. On a cold
    render, the per-image gap was on the order of **1,000x to
    50,000x**, scaling with source image size -- larger photos cost
    more to decode/resize, while URL-building stays flat regardless
    of size.

    This is a *cold-render, per-image* comparison, not a universal
    guarantee: once a variant exists on disk, both approaches are
    cheap (a database lookup vs. a static file read), and the exact
    numbers will vary with server hardware, image processor
    (ImageMagick/GraphicsMagick/GD/Imagick), and source image size.

The more durable benefit isn't raw speed, though: it's *total work
avoided*. A page with 50 images where only 12 are ever scrolled
into view (or use ``loading="lazy"`` and never trigger) has TYPO3
core process all 50 at render time -- this extension processes only
the 12 that were actually requested.

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
