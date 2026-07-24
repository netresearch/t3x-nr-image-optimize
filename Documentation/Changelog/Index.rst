..  include:: /Includes.rst.txt

..  _changelog:

=========
Changelog
=========

..  _changelog-1-3-0:

1.3.0
=====

-   Added: ``additionalTrustedRoots`` extension configuration --
    per-instance, opt-in, comma-separated list of absolute
    filesystem paths, outside FAL and TYPO3-internal locations,
    that are realpath-resolved and added to the path-validation
    allow-list. TYPO3's :file:`var/` directory is now trusted
    automatically. Port of the fix on ``main`` (2.4.0). See
    :ref:`configuration-additional-trusted-roots`.
-   Added: ``qualityWebp`` (default ``75``) and ``qualityAvif``
    (default ``60``) extension configuration settings, so the
    WebP and AVIF sidecar variants can be tuned independently of
    the primary variant's quality. AVIF's steeper quality scale
    previously meant AVIF variants came out larger than WebP at
    the same numeric quality. Port of the fix on ``main`` (2.4.0).
    See :ref:`configuration-sidecar-quality`.
-   Fixed: the Maintenance module's "clear processed images"
    action failed whenever :file:`processed` was a symlink to a
    shared volume -- a common Deployer/CI deployment layout. It
    validated the target with ``realpath()``-equality, which
    resolves the symlink and never matches, so clearing failed on
    every symlinked deployment. The directory is now emptied in
    place instead of recreated, so the symlink survives. Port of
    the fix on ``main`` (2.4.0).

..  _changelog-1-2-0:

1.2.0
=====

-   Added: ``additionalTrustedStorageSymlinks`` extension
    configuration -- per-instance, opt-in, comma-separated list of
    directory names that, when found as a symlink directly inside a
    Local FAL storage's own base path (e.g.
    :file:`fileadmin/_processed_`), are resolved and added to the
    path-validation allow-list. Closes the gap where deployments
    relocate TYPO3 core's own :file:`_processed_` image cache onto
    local/ephemeral storage to keep it off shared/NFS storage,
    leaving a symlink behind that the FAL-storage basePath lookup
    cannot see. Default empty; keeps today's behaviour for every
    installation that doesn't opt in. See :ref:`configuration-trusted-storage-symlinks`.
-   Fixed: images published via :file:`public/_assets/<hash>`
    symlinks (extension :file:`Resources/Public/` assets) were
    rejected with HTTP 400. TYPO3 core publishes each extension's
    :file:`Resources/Public/` directory by symlinking
    :file:`public/_assets/<hash>/` to a location outside the public
    webroot. ``getAllowedRoots()`` did not resolve these symlinks, so
    variant requests for e.g. an extension's default/fallback image
    failed even though the file is a legitimate part of the deployed
    application. Every immediate child of :file:`_assets` is now
    resolved individually.

..  _changelog-1-1-3:

1.1.3
=====

-   Fixed: the ``sourceSet`` ViewHelper passes absolute URLs
    (``http://``, ``https://``, ``//``), ``data:`` URIs, and URLs
    carrying a query string through unchanged and renders them as a
    plain ``<img>`` tag. Previously such paths — e.g. the tokenized
    ``eID=dumpFile`` URLs `fal_securedownload
    <https://extensions.typo3.org/extension/fal_securedownload>`__
    generates for files in non-public storages — were mangled into
    broken :file:`/processed/...` variant paths. The access control
    of the generating extension stays intact; see
    :ref:`usage-protected-files` for the trade-off. Port of the
    fix on ``main`` (2.2.4).

..  _changelog-1-1-2:

1.1.2
=====

-   Fixed: silent HTTP 400 responses now log their rejection reason
    via ``error_log()`` (URL-pattern mismatch and
    path-outside-allowed-roots branches).
-   Fixed: a transient ``StorageRepository`` failure during early
    TYPO3 bootstrap no longer poisons the per-process allowed-roots
    cache; the degraded fallback is kept only for the current
    request.
-   Fixed: ``getAllowedRoots()`` is memoized per request, avoiding
    redundant lookups and repeated log lines.
-   Fixed: a filesystem-root public path (``/``) no longer rejects
    every valid path.

..  _changelog-1-1-1:

1.1.1
=====

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
-   Changed (BC): dropped PHP 8.1 support. The TYPO3_12
    maintenance branch now requires PHP 8.2 or newer
    (TYPO3 v12 itself still supports PHP 8.1, but this
    extension aligns with the ``netresearch/typo3-ci-workflows``
    tooling which requires PHP 8.2+).

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
