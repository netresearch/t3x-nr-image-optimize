..  include:: /Includes.rst.txt

..  _maintenance:

===========
Maintenance
===========

The extension provides a backend module accessible via
:guilabel:`Admin Tools > Processed Images Maintenance`.

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

..  _maintenance-invalidate-path:

Invalidate by path
==================

Delete only the processed variants derived from a given original
file path, a directory prefix (trailing ``/``), or a glob pattern
(``*``/``?``) -- similar to a CDN cache invalidation, instead of
clearing the whole "processed" directory. Confirms before deleting
and reports the number of files removed.

..  _maintenance-clear:

Clear processed images
======================

Remove all on-demand generated images. Images are regenerated
automatically when first accessed again.

..  warning::

    After clearing processed images, expect temporarily
    increased loading times on the frontend until images are
    regenerated on demand.
