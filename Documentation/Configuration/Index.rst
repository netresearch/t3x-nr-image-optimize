..  include:: /Includes.rst.txt

=============
Configuration
=============

The extension works out of the box with sensible defaults. Images are
automatically optimized when accessed via the ``/processed/`` path.

SourceSetViewHelper
===================

The ``SourceSetViewHelper`` generates responsive ``<img>`` tags with ``srcset``
attributes.

..  code-block:: html

    {namespace nr=Netresearch\NrImageOptimize\ViewHelpers}

    <nr:sourceSet file="{image}"
                  width="1200"
                  height="800"
                  quality="85"
                  sizes="(max-width: 768px) 100vw, 50vw"
    />

Parameters
----------

``file``
   Image file resource.

``width``
   Target width in pixels.

``height``
   Target height in pixels.

``quality``
   JPEG/WebP quality (1--100).

``sizes``
   Responsive ``sizes`` attribute.

``format``
   Output format: ``auto``, ``webp``, ``avif``, ``jpg``, ``png``.

``mode``
   Render mode: ``cover`` (default) or ``fit``.

``responsiveSrcset``
   Enable width-based responsive ``srcset`` instead of density-based ``2x``.
   Default: ``false``.

``widthVariants``
   Width variants for responsive ``srcset`` (comma-separated string or array).
   Default: ``480, 576, 640, 768, 992, 1200, 1800``.

``fetchpriority``
   Native HTML ``fetchpriority`` attribute (``high``, ``low``, ``auto``).

Source set configuration
========================

Define source sets per media breakpoint via the ``set`` attribute:

..  code-block:: html

    <nr:sourceSet path="{f:uri.image(image: image, width: '960', height: '690', cropVariant: 'default')}"
                  set="{
                      480:{width: 160, height: 90},
                      800:{width: 400, height: 300}
                  }"
    />

Render modes
============

``cover``
   Default. Resizes images to fully cover the provided width and height.

``fit``
   Resizes images so they fit within the provided width and height.

..  code-block:: html

    <nr:sourceSet path="{f:uri.image(image: image, width: '960', height: '690', cropVariant: 'default')}"
                  width="960"
                  height="690"
                  mode="fit"
    />

Responsive width-based srcset
==============================

Enable width-based ``srcset`` generation with a ``sizes`` attribute for
improved responsive image handling. This is opt-in per usage.

..  code-block:: html

    <nrio:sourceSet
        path="{f:uri.image(image: image, maxWidth: size, cropVariant: 'default')}"
        width="{size}"
        height="{size * ratio}"
        alt="{image.properties.alternative}"
        lazyload="1"
        mode="fit"
        responsiveSrcset="1"
    />

Custom width variants and sizes:

..  code-block:: html

    <nrio:sourceSet
        path="{f:uri.image(image: image, maxWidth: size, cropVariant: 'default')}"
        width="{size}"
        height="{size * ratio}"
        responsiveSrcset="1"
        widthVariants="320,640,1024,1920,2560"
        sizes="(max-width: 640px) 100vw, (max-width: 1024px) 75vw, 50vw"
    />

Lazy loading
============

Both modes support lazy loading via the native ``loading="lazy"`` attribute.
When using JS-based lazy loading (``class="lazyload"``), the ``data-srcset``
attribute is added automatically.

Backward compatibility
======================

By default ``responsiveSrcset`` is ``false``, preserving the existing 2x
density-based ``srcset`` behavior. All existing templates continue to work
without modifications.
