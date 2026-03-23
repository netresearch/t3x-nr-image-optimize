..  include:: /Includes.rst.txt

===========
Maintenance
===========

The extension provides a backend module accessible via
:guilabel:`Admin Tools > Processed Images Maintenance`.

Overview
========

View statistics about processed images:

-  File count and total size
-  Directory count
-  Largest files
-  File type distribution

System requirements check
=========================

Verify all technical prerequisites and tool availability:

-  PHP version and extensions (Imagick, GD)
-  ImageMagick / GraphicsMagick capabilities (WebP, AVIF support)
-  Composer dependencies (Intervention Image)
-  TYPO3 version compatibility
-  CLI tools (``magick``, ``convert``, ``identify``, ``gm`` -- optional)

Clear processed images
======================

Remove all on-demand generated images. Images are regenerated automatically
when first accessed again.

..  note::

   After clearing processed images, expect temporarily increased loading times
   on the frontend until images are regenerated on demand.
