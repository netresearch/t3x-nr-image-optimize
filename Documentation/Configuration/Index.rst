..  include:: /Includes.rst.txt

..  _configuration:

=============
Configuration
=============

The extension works out of the box with sensible defaults.
Images are automatically optimized when accessed via the
``/processed/`` path.

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

..  confval:: height
    :name: confval-height
    :type: int|float
    :Default: 0

    Base height in pixels. ``0`` preserves aspect ratio
    relative to :confval:`width <confval-width>`.

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

    Alternative text (accessibility). HTML-escaped.

..  confval:: title
    :name: confval-title
    :type: string
    :Default: empty string

    Title attribute for the image. HTML-escaped.

..  confval:: class
    :name: confval-class
    :type: string
    :Default: empty string

    CSS classes for the ``<img>`` tag; include ``lazyload``
    to use JS lazy load.

..  confval:: attributes
    :name: confval-attributes
    :type: array
    :Default: []

    Extra HTML attributes merged into the rendered tag.

..  confval:: lazyload
    :name: confval-lazyload
    :type: boolean
    :Default: false

    Add ``loading="lazy"`` (native lazy loading).

..  confval:: sizes
    :name: confval-sizes
    :type: string
    :Default: auto, (min-width: 992px) 991px, 100vw

    Responsive ``sizes`` attribute for the generated
    ``<img>`` tag.

..  confval:: mode
    :name: confval-mode
    :type: string
    :Default: cover

    Render mode. ``cover`` resizes images to fully cover
    the given dimensions. ``fit`` resizes images to fit
    within the given dimensions.

..  confval:: responsiveSrcset
    :name: confval-responsive-srcset
    :type: boolean
    :Default: false

    Enable width-based responsive ``srcset`` instead of
    density-based ``2x`` srcset.

..  confval:: widthVariants
    :name: confval-width-variants
    :type: string|array
    :Default: 480, 576, 640, 768, 992, 1200, 1800

    Width variants for responsive ``srcset``
    (comma-separated string or array).

..  confval:: fetchpriority
    :name: confval-fetchpriority
    :type: string
    :Default: empty string

    Native HTML ``fetchpriority`` attribute. Allowed
    values: ``high``, ``low``, ``auto``. Omitted when
    empty.

..  _configuration-quality:

Encoding quality
================

The encoding quality is not configurable per ViewHelper call --
there is no ``quality`` argument. Generated URLs always carry the
default quality of ``75`` (for example
``/processed/fileadmin/image.w1200h800m0q75.jpg``), and variant
requests that carry no ``q`` value are processed with the same
default.

..  note::

    As of this version the default variant quality is 75. Earlier
    versions encoded WebP and AVIF variants at quality 100, so
    those variants are now generated with stronger compression and
    accordingly lower fidelity. The quality value is part of the
    generated file name, so all previously generated variants
    become stale and are recreated on demand under their new name.
    Use :ref:`Clear processed images <maintenance-clear>` to remove
    the obsolete files.

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

..  _configuration-trusted-storage-symlinks:

Trusted storage symlinks
=========================

..  versionadded:: 1.2.0
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

..  _configuration-additional-trusted-roots:

Additional trusted roots
=========================

..  versionadded:: 1.3.0
    The ``additionalTrustedRoots`` extension configuration setting.
    TYPO3's ``var/`` directory is now trusted automatically.

Some deployments need to serve variants of images that live under
an absolute filesystem path that is neither the public webroot,
a Local FAL storage's own base path, nor one of the hardcoded
TYPO3-internal locations (``var/``, symlinked
:file:`processed`/:file:`uploads`, or extension-published
:file:`_assets/<hash>` directories) -- for example a custom mount
managed outside of FAL.

The ``additionalTrustedRoots`` extension configuration setting
closes this gap on an explicit, per-instance, opt-in basis: a
comma-separated list of *absolute* filesystem paths that are
realpath-resolved and added to the allow-list directly.

..  code-block:: php
    :caption: config/system/additional.php

    $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_image_optimize']['additionalTrustedRoots'] = '/mnt/custom-assets';

The setting can also be edited via the backend:
*Admin Tools > Settings > Extension Configuration >
nr_image_optimize*.

..  attention::
    This widens the set of filesystem locations the processor
    will read from and publicly serve variants of. Only add paths
    you fully trust. Relative paths are rejected outright (never
    resolved against the PHP process's working directory). The
    default is empty, which keeps today's behavior unchanged for
    every installation that does not configure it.

..  _configuration-sidecar-quality:

WebP/AVIF output quality
========================

..  versionadded:: 1.3.0
    The ``qualityWebp`` and ``qualityAvif`` extension configuration
    settings.

The primary variant's quality is controlled per-request via the
``q<n>`` URL segment. The ``.webp`` and ``.avif`` sidecars previously
reused that same numeric quality, but AVIF's quality scale is steeper
than WebP's or JPEG's -- at matching numbers an AVIF file comes out
larger than the WebP sidecar, defeating the point of serving AVIF at
all.

Two extension configuration settings control sidecar quality
independently of the primary variant:

``qualityWebp`` (default ``75``)
    Output quality for the generated WebP variant.

``qualityAvif`` (default ``60``)
    Output quality for the generated AVIF variant. The lower default
    keeps AVIF variants genuinely smaller than WebP while staying
    visually comparable.

..  code-block:: php
    :caption: config/system/additional.php

    $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_image_optimize']['qualityWebp'] = 75;
    $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_image_optimize']['qualityAvif'] = 60;

The settings can also be edited via the backend:
*Admin Tools > Settings > Extension Configuration >
nr_image_optimize*.

..  attention::
    Per-format quality is not part of the processed-variant cache
    filename. Changing either setting only affects newly generated
    variants -- clear already-processed images (see
    :ref:`maintenance-clear`) to apply the new quality to existing
    ones.
