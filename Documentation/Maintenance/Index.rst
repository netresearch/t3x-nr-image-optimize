.. include:: /Includes.rst.txt

..  _maintenance:

===========
Maintenance
===========

The extension ships both a backend module and two CLI
commands. The backend module is reachable via
:guilabel:`Admin Tools > Processed Images Maintenance`. The
CLI commands are documented in :ref:`usage-cli`.

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
-   CLI tools (``magick``, ``convert``, ``identify``,
    ``gm`` -- optional).

The page does not currently report on ``optipng`` /
``gifsicle`` / ``jpegoptim``; for those, run
``nr:image:analyze`` (see :ref:`usage-cli-analyze`) or check
the binaries manually on the host.

..  _maintenance-clear:

Clear processed images
======================

Remove all on-demand generated images. Images are regenerated
automatically when first accessed again.

..  warning::

    After clearing processed images, expect temporarily
    increased loading times on the frontend until images are
    regenerated on demand.

..  _maintenance-cli:

Command-line maintenance
========================

Two console commands complement the backend module:

:ref:`nr:image:optimize <usage-cli-optimize>`
    Lossless (or optionally lossy) bulk compression of every
    PNG, GIF, and JPEG file in the FAL index.

:ref:`nr:image:analyze <usage-cli-analyze>`
    Fast heuristic report that estimates optimization
    potential without touching files or running external
    tools.

..  tip::

    Run ``nr:image:analyze`` first to decide whether the
    optimization run is worth the I/O. Then schedule
    ``nr:image:optimize`` as a periodic task (for example
    through the TYPO3 scheduler or a cron job).
