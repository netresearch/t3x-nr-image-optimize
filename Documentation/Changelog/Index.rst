..  include:: /Includes.rst.txt

..  _changelog:

=========
Changelog
=========

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
