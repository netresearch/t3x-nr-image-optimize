..  include:: /Includes.rst.txt

..  _introduction:

============
Introduction
============

..  _introduction-what-it-does:

What does it do?
================

The |extension_name| extension (|extension_key|) optimizes images
in TYPO3 on demand. Instead of processing every image at upload
time, images are converted and resized lazily when first
requested through the ``/processed/`` URL path.

This approach reduces server load during content editing and
ensures that only images actually viewed by visitors are
processed.

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
-   **Powered by Intervention Image.** Uses the
    `Intervention Image <https://image.intervention.io/>`__
    library for reliable image manipulation.

..  _introduction-requirements:

Requirements
============

-   PHP 8.1, 8.2, 8.3, or 8.4.
-   TYPO3 12.4.
-   Intervention Image library 3.11+ (installed automatically
    via Composer).

..  _introduction-recommended-extensions:

Recommended extensions
======================

`imageoptimizer <https://github.com/christophlehmann/imageoptimizer>`__
    Additional image optimization tooling that compresses
    uploaded and processed images with external binaries of
    your choice.
