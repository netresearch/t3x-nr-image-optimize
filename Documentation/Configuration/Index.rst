..  include:: /Includes.rst.txt

..  _configuration:

=============
Configuration
=============

The extension works out of the box with sensible defaults.
Images are automatically optimized when accessed via the
``/processed/`` path. All configuration happens through
ViewHelper attributes in your Fluid templates.

..  _configuration-viewhelper:

SourceSetViewHelper
===================

The ``SourceSetViewHelper`` generates responsive ``<img>`` tags
with ``srcset`` attributes.

..  code-block:: html
    :caption: Basic ViewHelper usage

    {namespace nr=Netresearch\NrImageOptimize\ViewHelpers}

    <nr:sourceSet file="{image}"
                  width="1200"
                  height="800"
                  quality="85"
                  sizes="(max-width: 768px) 100vw, 50vw"
    />

..  _configuration-parameters:

Parameters
----------

..  confval:: file
    :name: confval-file
    :type: object
    :required: true

    Image file resource (FAL file reference). Either ``file``
    or ``path`` must be provided.

..  confval:: path
    :name: confval-path
    :type: string

    URI string path to the image, typically generated via
    ``f:uri.image()``. Use ``path`` instead of ``file`` when
    passing a pre-resolved image URI. Either ``file`` or
    ``path`` must be provided.

..  confval:: width
    :name: confval-width
    :type: integer

    Target width in pixels.

..  confval:: height
    :name: confval-height
    :type: integer

    Target height in pixels.

..  confval:: quality
    :name: confval-quality
    :type: integer
    :Default: 100

    JPEG/WebP quality (1--100).

..  confval:: sizes
    :name: confval-sizes
    :type: string

    Responsive ``sizes`` attribute for the generated
    ``<img>`` tag.

..  confval:: format
    :name: confval-format
    :type: string
    :Default: auto

    Output format. Allowed values: ``auto``, ``webp``,
    ``avif``, ``jpg``, ``png``.

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

    Native HTML ``fetchpriority`` attribute. Allowed
    values: ``high``, ``low``, ``auto``. Omitted when
    empty.

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
