.. include:: /Includes.rst.txt

..  _usage:

=====
Usage
=====

This chapter shows practical examples for integrating
responsive images into your Fluid templates and for operating
the command-line tools that ship with the extension.

..  _usage-namespace:

Register the namespace
======================

Add the ViewHelper namespace at the top of your Fluid
template or register it globally:

..  code-block:: html
    :caption: Inline namespace declaration

    {namespace nr=Netresearch\NrImageOptimize\ViewHelpers}

..  _usage-basic-example:

Basic responsive image
======================

..  code-block:: html
    :caption: Simple responsive image with quality setting

    <nr:sourceSet file="{image}"
                  width="1200"
                  height="800"
                  quality="85"
                  sizes="(max-width: 768px) 100vw, 50vw"
    />

..  _usage-responsive-srcset:

Responsive width-based srcset
=============================

Enable width-based ``srcset`` generation with a ``sizes``
attribute for improved responsive image handling. This is
opt-in per usage.

..  code-block:: html
    :caption: Enable responsive srcset with default variants

    <nr:sourceSet
        path="{f:uri.image(
            image: image,
            maxWidth: size,
            cropVariant: 'default'
        )}"
        width="{size}"
        height="{size * ratio}"
        alt="{image.properties.alternative}"
        lazyload="1"
        mode="fit"
        responsiveSrcset="1"
    />

..  _usage-custom-variants:

Custom width variants
---------------------

..  code-block:: html
    :caption: Specify custom breakpoints for srcset

    <nr:sourceSet
        path="{f:uri.image(
            image: image,
            maxWidth: size,
            cropVariant: 'default'
        )}"
        width="{size}"
        height="{size * ratio}"
        responsiveSrcset="1"
        widthVariants="320,640,1024,1920,2560"
        sizes="(max-width: 640px) 100vw,
               (max-width: 1024px) 75vw, 50vw"
    />

..  _usage-output-comparison:

Output comparison
=================

**Legacy mode** (``responsiveSrcset=false`` or not set):

..  code-block:: html
    :caption: Density-based 2x srcset output

    <img src="/processed/fileadmin/image.w625h250m1q100.jpg"
         srcset="/processed/fileadmin/image.w1250h500m1q100.jpg x2"
         width="625"
         height="250"
         loading="lazy">

**Responsive mode** (``responsiveSrcset=true``):

..  code-block:: html
    :caption: Width-based srcset output

    <img src="/processed/fileadmin/image.w1250h1250m1q100.png"
         srcset="/processed/fileadmin/image.w480h480m1q100.png 480w,
                 /processed/fileadmin/image.w576h576m1q100.png 576w,
                 /processed/fileadmin/image.w640h640m1q100.png 640w,
                 /processed/fileadmin/image.w768h768m1q100.png 768w,
                 /processed/fileadmin/image.w992h992m1q100.png 992w,
                 /processed/fileadmin/image.w1200h1200m1q100.png 1200w,
                 /processed/fileadmin/image.w1800h1800m1q100.png 1800w"
         sizes="auto, (min-width: 992px) 991px, 100vw"
         width="991"
         loading="lazy"
         alt="Image">

..  _usage-protected-files:

Public images only: absolute URLs are passed through
=====================================================

The ``/processed/`` endpoint is designed for **public files** only.
It resolves the given path below the public web root and writes the
generated variants as static files into :file:`public/processed/`,
where the web server delivers them directly — without any access
check.

Files in non-public FAL storages (``is_public = 0``) can therefore
not be processed. Extensions such as
`fal_securedownload <https://extensions.typo3.org/extension/fal_securedownload>`__
resolve such files to tokenized eID URLs
(``/index.php?eID=dumpFile&...``) whose delivery runs through TYPO3
and performs a permission check on every request.

The ViewHelper detects absolute URLs (``http://``, ``https://``,
``//``), ``data:`` URIs, and URLs containing a query string and
passes them through unchanged, rendering a plain ``<img>`` tag with
the URL as ``src``:

..  code-block:: html
    :caption: Output for a file from a protected storage

    <picture>
    <img src="https://example.org/index.php?eID=dumpFile&amp;t=f&amp;f=42&amp;fal_token=..."
         width="400"
         height="300"
         alt="Protected image" />
    </picture>

..  important::
    **Trade-off for passed-through URLs**

    -   No ``srcset``/``sizes`` attributes and no per-breakpoint
        ``<source>`` elements are generated — the browser always
        loads the image in its original dimensions.
    -   No WebP/AVIF variants and no quality optimization are
        applied.
    -   In return, the access control of the generating extension
        (e.g. fal_securedownload) stays fully intact, because the
        URL — including its access token — is emitted unchanged.

If you need optimized variants of images in protected storages,
generate them with TYPO3's own image processing (for example
``f:image`` or the ``ImageService``). Processed files are then
created inside the protected storage's processing folder and are
delivered through the same secure-download mechanism, keeping the
permission check intact.

..  _usage-fetchpriority:

Fetch priority for Core Web Vitals
===================================

Use the ``fetchpriority`` attribute to hint the browser
about resource prioritization, improving Largest Contentful
Paint (LCP) scores:

..  code-block:: html
    :caption: High priority for above-the-fold hero image

    <nr:sourceSet file="{heroImage}"
                  width="1920"
                  height="1080"
                  fetchpriority="high"
    />

..  _usage-cli:

Command-line tools
==================

Both commands read the FAL index directly and process eligible
image files (``image/jpeg``, ``image/gif``, ``image/png``) on
online storages. Per-file storage permission evaluation is
temporarily disabled and restored in a ``finally`` block so
long-running CLI runs don't leak state across iterations or
require a BE user context.

..  _usage-cli-optimize:

Bulk optimize images
--------------------

The ``nr:image:optimize`` command compresses every eligible
PNG, GIF, and JPEG file across all storages (or a restricted
subset) using the installed optimizer binaries. The original
file is replaced in place only when the tool produces a
smaller result.

..  code-block:: bash
    :caption: Preview what would be processed

    vendor/bin/typo3 nr:image:optimize --dry-run

..  code-block:: bash
    :caption: Compress storage 1 with lossy JPEG quality 85

    vendor/bin/typo3 nr:image:optimize \
        --storages=1 \
        --jpeg-quality=85 \
        --strip-metadata

Options:

``--dry-run``
    Only analyze; do not modify files.

``--storages``
    Restrict to specific storage UIDs. Accepts repeated
    occurrences or a comma-separated list.

``--jpeg-quality``
    Lossy JPEG quality 0--100. Omit for lossless JPEG
    optimization.

``--strip-metadata``
    Remove EXIF and comments when the tool supports it.

..  _usage-cli-analyze:

Analyze optimization potential
------------------------------

The ``nr:image:analyze`` command estimates how much disk
space could be saved by running ``nr:image:optimize`` or by
downscaling oversized originals. It is purely heuristic --
no external binaries are invoked, so it runs quickly even on
large installations.

..  code-block:: bash
    :caption: Report potential for storage 1

    vendor/bin/typo3 nr:image:analyze --storages=1

Options:

``--storages``
    Restrict to specific storage UIDs.

``--max-width`` / ``--max-height``
    Target display box (default 2560 x 1440). Images larger
    than this box are assumed to be downscaled and the
    estimate factors in the area reduction.

``--min-size``
    Skip files smaller than this many bytes (default
    512000). Prevents noise from already-tiny images.
