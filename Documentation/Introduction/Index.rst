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

..  _introduction-performance:

Performance model
=================

Every image on a page costs the server twice: once when the page is
rendered, and once when a browser fetches the image. What sets this
extension apart from TYPO3 core's ``f:image`` / ``ImageService``
pipeline is *when* the expensive part -- decoding, resizing,
encoding -- happens.

**TYPO3 core** processes every referenced image *while the page
renders*, inside the request that produces the HTML. On a cold page
cache the visitor waits for all of it before the first byte of HTML
arrives, images below the fold and in hidden sliders included, and
one PHP process does the work for all of them, one after another.

**This extension** only builds a ``/processed/...`` URL string while
the page renders. Nothing is decoded or written at that point. The
work happens later, in a *separate request per image*, when -- and
only if -- the browser asks for that image. Those requests are
spread across all PHP-FPM workers, and once a variant exists the web
server serves it as a static file without touching TYPO3 at all.

..  _introduction-performance-measured:

What that is worth
------------------

The numbers below come from the benchmark that ships with this
extension (``make benchmark``, see :ref:`introduction-performance-reproduce`).
Both pipelines render the same 24 photos (3000×2000 JPEG) as 800×600
crops on otherwise identical pages; every scenario is a state a real
site is in at some point. Medians of three visits, TYPO3 14.3.6,
PHP 8.4, ImageMagick 7, Apache + PHP-FPM in Docker on a developer
laptop -- absolute values will differ on your hardware, the ratios
will not.

..  figure:: /Images/Benchmark/ttfb.svg
    :alt: Bar chart: time to first byte of the HTML document per scenario, core versus extension
    :class: with-border with-shadow
    :zoom: lightbox

    Client view -- the first byte of HTML. With a cold cache the
    visitor waits 7.9 s for core, 0.14 s for the extension.

..  figure:: /Images/Benchmark/load.svg
    :alt: Bar chart: time until the page is fully loaded per scenario, core versus extension
    :class: with-border with-shadow
    :zoom: lightbox

    Client view -- the complete page. The 24 variant requests run in
    parallel across PHP-FPM workers instead of serially inside one
    render: 8.0 s become 3.1 s, and the largest contentful paint
    moves from 8.0 s to 0.8 s.

..  figure:: /Images/Benchmark/server-cpu.svg
    :alt: Bar chart: server CPU time per visit per scenario, core versus extension
    :class: with-border with-shadow
    :zoom: lightbox

    Server view -- CPU time of the PHP-FPM container, ImageMagick
    included. Read this one honestly: on a fully viewed cold page the
    extension spends *more* CPU (14.6 s vs 7.8 s), because it writes
    three files per image -- JPEG, WebP and AVIF -- and AVIF encoding
    is expensive. That work is off the visitor's critical path and
    can be reduced with ``skipAvif`` / ``skipWebP``; it also only
    happens for images that are requested (lazy loading: 4.2 s vs
    8.2 s for the seven images the viewport needed).

..  figure:: /Images/Benchmark/variants-written.svg
    :alt: Bar chart: variant files written per visit per scenario, core versus extension
    :class: with-border with-shadow
    :zoom: lightbox

    Server view -- files written. Rendering alone writes 24 files
    with core and none with the extension. With lazy loading the
    extension processes the 7 images the browser fetched; core
    processes all 24 regardless.

..  figure:: /Images/Benchmark/php-requests.svg
    :alt: Bar chart: requests handled by PHP per visit per scenario, core versus extension
    :class: with-border with-shadow
    :zoom: lightbox

    Server view -- requests that reached TYPO3. Steady state is one
    PHP request for either pipeline; everything else is static
    files. Note the "variants purged" row: when variant files are
    deleted under a warm page cache, core's HTML points at files
    nothing regenerates -- 24 broken images, each a PHP request for
    an error page -- while the middleware simply regenerates them.

In short:

-   **First visitor after a deployment or cache flush:** HTML in
    0.14 s instead of 7.9 s; complete page in 3.1 s instead of 8.0 s.
-   **Lazy loading and hidden content** actually save work: only
    images the browser fetches are ever processed.
-   **Render time is independent of image count and size.** A page
    with 200 images renders as fast as one with two; image-heavy
    pages no longer risk ``max_execution_time`` during render.
-   **Purged or lost variants are not an outage.** They are
    regenerated on the next request instead of serving 404s until
    someone flushes the page cache.
-   **Steady state is identical:** a page-cache hit plus static files
    in both cases.
-   **Per image, the extension does more work** (three formats) and
    delivers fewer bytes (AVIF/WebP where the browser accepts them).

..  _introduction-performance-reproduce:

Reproduce it
------------

The benchmark is part of the test suite, so the claims above are
re-measured rather than remembered:

-   ``make benchmark`` provisions TYPO3, the extension, Apache and
    PHP-FPM in Docker, drives Chromium through the six scenarios
    with both pipelines and rewrites the charts in
    :file:`Documentation/Images/Benchmark/` together with the raw
    ``results.json`` (every iteration, not just the medians). Only
    Docker is required; see :file:`Tests/E2E/README.md`.
-   The suite *asserts* the architectural claims -- zero variants
    written during render, lower cold-cache TTFB, no broken images
    after a purge, fewer images processed under lazy loading -- and
    fails when they stop holding. Absolute timings are reported,
    never asserted.
-   :file:`Tests/Functional/Benchmark/RenderCostTest.php` guards the
    core claim (render writes nothing; core's ``ImageService``
    processes everything) in every CI run, without a browser.

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
-   **No image processing during page render.** Rendering only
    emits ``/processed/`` URLs; each variant is produced in its own
    request, when a browser first asks for it, and served as a static
    file from then on (see :ref:`introduction-performance`).
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
