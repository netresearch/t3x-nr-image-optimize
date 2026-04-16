.. include:: /Includes.rst.txt

..  _changelog:

=========
Changelog
=========

..  _changelog-unreleased:

Unreleased
==========

..  versionadded:: next
    Automatic optimization on upload and bulk CLI commands
    for optimizing or analyzing the FAL image inventory.

-   Added ``OptimizeOnUploadListener`` -- PSR-14 listener
    that runs ``optipng`` / ``gifsicle`` / ``jpegoptim`` on
    ``AfterFileAddedEvent`` and ``AfterFileReplacedEvent``.
    Keyed by ``storageUid + ':' + identifier`` to avoid
    cross-storage re-entrancy collisions; restores
    ``setEvaluatePermissions`` in a ``finally`` block.
-   Added ``nr:image:optimize`` -- bulk optimization command
    with ``--dry-run``, ``--storages``, ``--jpeg-quality``,
    and ``--strip-metadata`` options. Uses a streaming
    Generator over ``sys_file`` so large installations
    don't load the full index into memory.
-   Added ``nr:image:analyze`` -- heuristic analysis
    command that estimates optimization potential without
    invoking any binary. Fast even on large installations.
-   Added ``ImageOptimizer`` service -- shared backend used
    by the listener and both CLI commands. Env overrides
    (``OPTIPNG_BIN``, ``GIFSICLE_BIN``, ``JPEGOPTIM_BIN``)
    are authoritative: a set-but-invalid override is
    reported as unavailable rather than silently falling
    back to ``$PATH``. ``$PATH`` lookups also verify
    ``is_executable()``.

..  _changelog-2-2-1:

2.2.1
=====

-   Adjusted author information in :file:`ext_emconf.php`.

..  _changelog-2-2-0:

2.2.0
=====

-   Fixed: always render ``alt`` attribute on generated
    ``<img>`` tags.
-   Expanded unit test coverage for Processor and
    SourceSetViewHelper.

..  _changelog-2-1-0:

2.1.0
=====

..  versionadded:: 2.1.0
    Width-based responsive ``srcset`` with ``sizes``
    attribute, configurable width variants, and
    ``fetchpriority`` support.

-   Added responsive width-based ``srcset`` generation as
    opt-in feature.
-   Added ``widthVariants`` parameter for custom breakpoints.
-   Added ``sizes`` parameter for responsive image sizing.
-   Added ``fetchpriority`` attribute for resource hints.
-   Optimized default ``sizes`` attribute values.

..  _changelog-2-0-1:

2.0.1
=====

-   Fixed ``declare`` statement issue preventing TER
    publishing via GitHub Actions.

..  _changelog-2-0-0:

2.0.0
=====

..  versionadded:: 2.0.0
    TYPO3 13 compatibility with PHP 8.2--8.4 support.

-   Added TYPO3 13 compatibility.
-   Added PHP 8.2, 8.3, and 8.4 support.
-   Dropped support for older TYPO3 versions.
-   Switched to Intervention Image 3.x.
-   Removed obsolete system binary checks.

..  _changelog-1-0-1:

1.0.1
=====

-   Added :file:`ext_emconf.php` for classic installation.

..  _changelog-1-0-0:

1.0.0
=====

-   Initial stable release.
-   GitHub Actions CI workflows.

..  _changelog-0-1-5:

0.1.5
=====

-   Fixed ``strtolower()`` null argument error.
-   Fixed array offset access on boolean value.
-   Allowed numeric characters in file extensions.
-   Added extension icon.
-   Corrected crop variant examples.
-   Improved lazy loading behavior.
