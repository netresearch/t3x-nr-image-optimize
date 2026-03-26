..  include:: /Includes.rst.txt

..  _maintenance:

===========
Maintenance
===========

The extension provides a backend module accessible via
:guilabel:`Admin Tools > Processed Images Maintenance`.

..  todo::

    Add screenshot of the backend maintenance module overview.
    Dimensions: cropped to relevant area.
    Settings: Light mode, default user, clean installation.

..  _maintenance-overview:

Overview
========

View statistics about processed images:

-   File count and total size.
-   Directory count.
-   Largest files.
-   File type distribution.

..  _maintenance-system-requirements:

System requirements check
=========================

Verify all technical prerequisites and tool availability:

-   PHP version and extensions (Imagick, GD).
-   ImageMagick / GraphicsMagick capabilities (WebP, AVIF
    support).
-   Composer dependencies (Intervention Image).
-   TYPO3 version compatibility.
-   CLI tools (``magick``, ``convert``, ``identify``, ``gm``
    -- optional).

..  todo::

    Add screenshot of the system requirements check view.
    Dimensions: cropped to relevant area.
    Settings: Light mode, default user, clean installation.

..  _maintenance-clear:

Clear processed images
======================

Remove all on-demand generated images. Images are regenerated
automatically when first accessed again.

..  warning::

    After clearing processed images, expect temporarily
    increased loading times on the frontend until images are
    regenerated on demand.
