.. include:: /Includes.rst.txt

..  _configuration:

=============
Configuration
=============

The extension works out of the box with sensible defaults. All
three operating modes -- on-demand frontend processing, on-upload
compression, and bulk CLI -- activate automatically after
installation. This page documents the extension points that can
be tweaked.

..  _configuration-viewhelper:

SourceSetViewHelper
===================

The ``SourceSetViewHelper`` generates responsive ``<img>`` tags
with ``srcset`` attributes.

..  code-block:: html
    :caption: Basic ViewHelper usage

    {namespace nr=Netresearch\NrImageOptimize\ViewHelpers}

    <nr:sourceSet path="{f:uri.image(image: image)}"
                  width="1200"
                  height="800"
                  alt="{image.properties.alternative}"
                  sizes="(max-width: 768px) 100vw, 50vw"
                  responsiveSrcset="1"
    />

..  _configuration-parameters:

Parameters
----------

..  confval:: path
    :name: confval-path
    :type: string
    :required: true

    Public path to the source image (for example
    ``/fileadmin/foo.jpg``), typically generated via
    ``f:uri.image()``.

..  confval:: width
    :name: confval-width
    :type: int|float
    :Default: 0

    Base width in pixels for the rendered ``<img>``. ``0``
    resolves automatically from the source file.
    :ref:`Clamped by the processor <configuration-limits>` to
    1--8192 when baked into the variant URL.

..  confval:: height
    :name: confval-height
    :type: int|float
    :Default: 0

    Base height in pixels. ``0`` preserves aspect ratio
    relative to :confval:`width <confval-width>`.
    :ref:`Clamped by the processor <configuration-limits>`
    to 1--8192.

..  confval:: set
    :name: confval-set
    :type: array
    :Default: []

    Responsive set in the form
    ``{maxWidth: {width: int, height: int}}``. Each entry
    becomes a ``<source media="(max-width: <maxWidth>px)">``
    tag.

..  confval:: alt
    :name: confval-alt
    :type: string
    :Default: empty string

    Alternative text for the image. Always rendered (even
    when empty) to keep assistive-tech compatibility.

..  confval:: title
    :name: confval-title
    :type: string
    :Default: empty string

    HTML-escaped title attribute.

..  confval:: class
    :name: confval-class
    :type: string
    :Default: empty string

    CSS classes for the ``<img>`` tag. Include
    ``lazyload`` to switch from native to JS-based lazy
    loading (see :ref:`configuration-lazy-loading`).

..  confval:: mode
    :name: confval-mode
    :type: string
    :Default: cover

    Render mode. ``cover`` resizes images to fully cover
    the given dimensions (crop/fill). ``fit`` resizes images
    to fit within the given dimensions.

..  confval:: lazyload
    :name: confval-lazyload
    :type: boolean
    :Default: false

    Add ``loading="lazy"`` (native lazy loading).

..  confval:: responsiveSrcset
    :name: confval-responsive-srcset
    :type: boolean
    :Default: false

    Enable width-based responsive ``srcset`` instead of the
    density-based ``2x`` output (preserved for backward
    compatibility).

..  confval:: widthVariants
    :name: confval-width-variants
    :type: string|array
    :Default: 480, 576, 640, 768, 992, 1200, 1800

    Width variants for responsive ``srcset``
    (comma-separated string or array). Only honored when
    :confval:`responsiveSrcset <confval-responsive-srcset>`
    is enabled.

..  confval:: sizes
    :name: confval-sizes
    :type: string
    :Default: auto, (min-width: 992px) 991px, 100vw

    Responsive ``sizes`` attribute for the generated
    ``<img>`` tag.

..  confval:: fetchpriority
    :name: confval-fetchpriority
    :type: string
    :Default: empty string

    Native HTML ``fetchpriority`` attribute. Allowed
    values: ``high``, ``low``, ``auto``. Omitted when
    empty.

..  confval:: attributes
    :name: confval-attributes
    :type: array
    :Default: []

    Extra HTML attributes merged into the rendered tag.

..  note::

    Quality and output format are not exposed as ViewHelper
    arguments. Quality defaults to 75 and is baked into the
    generated ``/processed/...q<n>...`` URL; the variant's
    file extension is inherited from the source image. Use
    :ref:`the URL format <configuration-url-format>` and
    :ref:`variant negotiation <configuration-variant-negotiation>`
    to influence the served format.

..  _configuration-source-sets:

Source set configuration
========================

Define source sets per media breakpoint via the ``set``
attribute:

..  code-block:: html
    :caption: Source set with breakpoint-specific dimensions

    <nr:sourceSet
        path="{f:uri.image(
            image: image,
            width: '960',
            height: '690',
            cropVariant: 'default'
        )}"
        set="{
            480:{width: 160, height: 90},
            800:{width: 400, height: 300}
        }"
    />

..  _configuration-render-modes:

Render modes
============

``cover``
    Default. Resizes images to fully cover the provided
    width and height.

``fit``
    Resizes images so they fit within the provided width
    and height.

..  code-block:: html
    :caption: Using fit mode

    <nr:sourceSet
        path="{f:uri.image(
            image: image,
            width: '960',
            height: '690',
            cropVariant: 'default'
        )}"
        width="960"
        height="690"
        mode="fit"
    />

..  _configuration-lazy-loading:

Lazy loading
============

Both modes support lazy loading via the native
``loading="lazy"`` attribute. When using JS-based lazy
loading (``class="lazyload"``), the ``data-srcset``
attribute is added automatically.

..  _configuration-backward-compatibility:

Backward compatibility
======================

By default :confval:`responsiveSrcset <confval-responsive-srcset>`
is ``false``, preserving the existing 2x density-based
``srcset`` behavior. All existing templates continue to work
without modifications.

..  _configuration-url-format:

Variant URL format
==================

Processed variants are served from a dedicated URL path. The
ViewHelper generates these URLs automatically, but any markup
that writes a URL of this form will be intercepted by the
:ref:`ProcessingMiddleware <developer-middleware>`:

..  code-block:: text
    :caption: URL template

    /processed/<original-path>.<mode-config>.<ext>[?<query>]

``<original-path>``
    Public path of the source image, including the
    ``/fileadmin/`` (or other storage) prefix. Path traversal
    sequences (``..``) are rejected at URL-parsing time.

``<mode-config>``
    Concatenation of one or more of:

    ``w<n>``
        Target width in pixels.

    ``h<n>``
        Target height in pixels.

    ``q<n>``
        Quality (1--100).

    ``m<n>``
        Processing mode (``0`` = cover, ``1`` = scale/fit).

``<ext>``
    Source image extension. The processor decides at
    response time whether to serve the original, the
    ``.webp`` sidecar, or the ``.avif`` sidecar based on
    the ``Accept`` header and the query flags below.

..  code-block:: text
    :caption: Example URL

    /processed/fileadmin/photos/hero.w1200h800m0q85.jpg

..  _configuration-variant-negotiation:

Variant negotiation
===================

When the processor generates a variant, it writes the original
file to disk and additionally produces a ``.webp`` and an
``.avif`` sidecar (same base name). On each request it inspects
the ``Accept`` header and returns the best match the client
supports, preferring AVIF over WebP over the original format.

Two query parameters let callers opt out of sidecar generation
for individual URLs:

``skipWebP=1``
    Do not produce or serve a WebP variant for this URL. The
    ``Content-Type`` always matches the source extension.

``skipAvif=1``
    Do not produce or serve an AVIF variant for this URL. If
    WebP is still allowed and the client supports it, WebP is
    served.

These flags are useful when specific consumers (for example
e-mail clients or legacy RSS renderers) cannot handle modern
formats.

..  _configuration-cache-headers:

Cache headers
=============

Processed variant URLs are effectively content-addressed -- any
change to dimensions, quality, or format produces a different
URL. The processor therefore responds with an immutable,
long-lived cache header:

..  code-block:: text

    Cache-Control: public, max-age=31536000, immutable

This value is a compile-time constant and not user-configurable.

..  _configuration-image-driver:

Image driver selection
======================

Intervention Image is instantiated through
:php:class:`~Netresearch\\NrImageOptimize\\Service\\ImageManagerFactory`,
which selects the best available driver at runtime:

1.  **Imagick** when the ``imagick`` PHP extension is loaded
    (preferred -- supports AVIF natively if the underlying
    ImageMagick build does).
2.  **GD** when ``imagick`` is unavailable and the ``gd``
    extension is loaded.

If neither extension is present, the factory throws a
``RuntimeException`` with a descriptive message. Use the
:ref:`backend maintenance module <maintenance-system-requirements>`
to verify driver availability on your host.

..  _configuration-middleware:

Middleware registration
=======================

:file:`Configuration/RequestMiddlewares.php` registers the
``ProcessingMiddleware`` on the frontend pipeline **before**
``typo3/cms-frontend/site``. This ordering is required so the
middleware can intercept ``/processed/`` URLs before
TYPO3's frontend routing claims them. The registration has no
user-configurable options.

..  _configuration-limits:

Processor limits
================

The processor enforces the following bounds when parsing a URL:

``MAX_DIMENSION``
    Width and height are clamped to 1--8192 pixels to prevent
    denial-of-service via excessive memory allocation.

``MIN_QUALITY`` / ``MAX_QUALITY``
    Quality is clamped to 1--100.

``LOCK_MAX_RETRIES``
    Up to 10 attempts (at 100 ms intervals) to acquire the
    per-variant processing lock before returning HTTP 503.
    Prevents duplicate work when multiple clients hit the
    same uncached variant simultaneously.

..  _configuration-trusted-storage-symlinks:

Trusted storage symlinks
=========================

..  versionadded:: 2.3.0
    The ``additionalTrustedStorageSymlinks`` extension configuration
    setting.

The processor validates that both the source image and the
target variant resolve (via ``realpath()``) to a location
inside an allowed root -- the public webroot, or a Local FAL
storage's own base path -- before reading or writing anything.
This rejects requests for images reached through a symlink that
escapes those roots (for example a symlink accidentally or
maliciously pointing at :file:`/etc`).

Some deployments relocate TYPO3 core's own
:file:`_processed_` image cache -- a subdirectory *inside* a FAL
storage's own directory, e.g. :file:`fileadmin/_processed_` --
onto local/ephemeral storage, to keep frequently-rewritten
derivative images off shared/NFS storage. That leaves only a
symlink behind inside the storage, which the FAL-storage
basePath lookup above does not see (it only resolves the
storage's own base path, never looks inside it), so variant
requests for such images are rejected even though the original
file is legitimately part of the deployed application.

The ``additionalTrustedStorageSymlinks`` extension configuration
setting closes this gap on an explicit, per-instance, opt-in
basis: a comma-separated list of directory names that, when
found as a symlink directly inside a Local FAL storage's base
path, are resolved and added to the allow-list too.

..  code-block:: php
    :caption: config/system/additional.php

    $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_image_optimize']['additionalTrustedStorageSymlinks'] = '_processed_';

The setting can also be edited via the backend:
*Admin Tools > Settings > Extension Configuration >
nr_image_optimize*.

..  attention::
    This widens the set of filesystem locations the processor
    will read from and publicly serve variants of. Only add
    well-known, infrastructure-managed names here -- never a
    name an untrusted party (e.g. an FTP-only content account)
    could create on their own. The default is empty, which
    keeps today's behavior unchanged for every installation that
    does not configure it.
