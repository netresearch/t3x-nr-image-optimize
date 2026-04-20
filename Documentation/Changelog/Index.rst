..  include:: /Includes.rst.txt

..  _changelog:

=========
Changelog
=========

..  _changelog-unreleased:

Unreleased
==========

-   Fixed: processed image requests no longer return
    HTTP 400 when :file:`fileadmin` (or any other Local
    FAL storage) is a symlink to an external location
    such as an NFS/EFS mount. ``isPathWithinAllowedRoots``
    now accepts any realpath-resolved path that lies
    within the TYPO3 public root or the realpath of any
    configured Local storage's ``basePath``. Symlinks
    placed *inside* a storage that escape every allowed
    root -- e.g. :file:`fileadmin/evil` -> :file:`/etc`
    -- continue to be rejected. Backport of the fix on
    ``main``, reported in
    `issue #70 <https://github.com/netresearch/t3x-nr-image-optimize/issues/70>`__.
-   Hardened: paths containing NUL bytes are rejected
    outright, closing a minor realpath-bypass via the
    not-yet-existing-path parent-walk branch.
-   Changed (BC for subclasses and manual instantiators):
    :php:`Netresearch\\NrImageOptimize\\Processor` gains a
    new required ``StorageRepository`` constructor
    parameter. Consumers that autowire the service (the
    default in TYPO3 12+) are unaffected; any code that
    extends the class or constructs it by hand must
    forward the new dependency.

..  _changelog-1-1-0:

1.1.0
=====

..  versionadded:: 1.1.0
    Comprehensive quality review: security hardening, performance
    improvements, backend maintenance module, responsive srcset,
    and expanded test coverage.

-   Added backend maintenance module with directory statistics,
    system requirements check, and clear processed images action.
-   Added responsive width-based ``srcset`` generation as
    opt-in feature.
-   Added ``widthVariants`` parameter for custom breakpoints.
-   Added ``sizes`` parameter for responsive image sizing.
-   Added ``fetchpriority`` attribute for resource hints.
-   Added path traversal hardening and XSS prevention.
-   Added DoS prevention via dimension and quality clamping.
-   Added HTTP caching headers (``Cache-Control: immutable``,
    ``ETag``, ``Last-Modified``).
-   Added 15 language localizations.
-   Added 33+ unit tests, fuzz tests, and functional tests.
-   Added full TYPO3 documentation structure.

..  _changelog-1-0-3:

1.0.3
=====

-   Fixed ``Processor::getValueFromMode()`` TypeError for
    non-matching URLs (crawler/bot srcset descriptors).

..  _changelog-1-0-2:

1.0.2
=====

-   Fixed nullable ``dirname`` access in
    ``SourceSetViewHelper``.

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
