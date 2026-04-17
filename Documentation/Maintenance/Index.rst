.. include:: /Includes.rst.txt

..  _maintenance:

===========
Maintenance
===========

The extension ships with both a backend module and two CLI
commands. The backend module is reachable via
:guilabel:`Admin Tools > Processed Images Maintenance`
(access restricted to users with the ``systemMaintainer``
role). The CLI commands are documented in :ref:`usage-cli`.

..  _maintenance-access:

Access control
==============

The backend module is registered under the :guilabel:`Admin
Tools` parent with ``access: systemMaintainer``. Only TYPO3
users in the ``systemMaintainer`` group (configured in
``LocalConfiguration.php``) see it in the backend navigation.

..  _maintenance-overview:

Overview
========

The :guilabel:`Overview` tab (``MaintenanceController::indexAction``)
reports statistics about the processed-images directory:

-   File count and total disk usage.
-   Number of directories under ``/processed/``.
-   The five largest files (name, size, last-modified timestamp).
-   File-type distribution (JPEG / PNG / GIF / WebP / AVIF /
    other).

The stats are computed on demand by recursively walking the
filesystem; no metadata is persisted to the database.

..  _maintenance-system-requirements:

System requirements check
=========================

The :guilabel:`System requirements` tab
(``MaintenanceController::systemRequirementsAction``) verifies
all technical prerequisites needed for successful variant
generation:

-   PHP version and loaded extensions (``imagick``, ``gd``,
    ``fileinfo``).
-   ImageMagick / GraphicsMagick capabilities reported by
    Imagick, in particular the ability to decode/encode WebP
    and AVIF. If Imagick is not installed, the page falls
    back to GD capabilities.
-   Intervention Image installed version and the driver
    selected at runtime.
-   TYPO3 version.
-   Availability of optional CLI helpers (``magick``,
    ``convert``, ``identify``, ``gm``) -- none are strictly
    required but may be useful for debugging.

Each check is rendered as a pass/warning/fail row with a
short explanation so an administrator can fix a misconfigured
host in minutes.

..  note::

    The page does not currently report on the ``optipng``,
    ``gifsicle``, or ``jpegoptim`` binaries used by the
    on-upload listener and ``nr:image:optimize``. To verify
    those, run ``nr:image:optimize --dry-run`` (see
    :ref:`usage-cli-optimize`) -- it resolves every tool up
    front and exits with an error listing the missing ones --
    or check the binaries manually on the host.
    ``nr:image:analyze`` is heuristic and never invokes
    external tools, so it cannot be used for this check.

..  _maintenance-clear:

Clear processed images
======================

The :guilabel:`Clear processed images` tab
(``MaintenanceController::clearProcessedImagesAction``)
recursively deletes every file under the configured
processed-images directory. A flash message reports the
number of files removed and the disk space freed.

..  warning::

    After clearing processed images, expect temporarily
    increased frontend load -- each variant is re-created on
    first request. Run the action during off-peak hours on
    busy sites.

..  tip::

    ``nr:image:optimize`` only touches original files in
    FAL storages; it does **not** clear the
    ``/processed/`` directory. Use this action (or the
    TYPO3 install tool's "Flush cache" entry) to discard
    cached variants after an optimization run.

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
