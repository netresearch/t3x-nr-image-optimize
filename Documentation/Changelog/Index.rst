.. include:: /Includes.rst.txt

..  _changelog:

=========
Changelog
=========

..  _changelog-2-4-0:

2.4.0
=====

-   Added: ``Environment::getVarPath()`` (the composer-mode
    :file:`var/` directory, a sibling of :file:`public/` rather than
    nested under it) is now an allowed root for path validation, so
    TYPO3-internal generated assets (cache, lock, transient, log) are
    accepted.
-   Added: ``additionalTrustedRoots`` extension configuration --
    per-instance, opt-in, comma-separated list of absolute filesystem
    paths that are realpath-resolved and added directly to the
    path-validation allow-list. Closes the gap for integrator-trusted
    locations that are neither a FAL storage base path nor one of the
    hardcoded TYPO3-internal locations. See
    :ref:`configuration-additional-trusted-roots`.
-   Added: configurable WebP/AVIF output quality via the new
    ``qualityWebp`` (default 75) and ``qualityAvif`` (default 60)
    extension-configuration settings. Previously both variants were
    encoded at the same numeric quality as the primary image; AVIF's
    steeper quality scale made AVIF variants larger than WebP at
    matching numbers, so format negotiation ended up serving the
    biggest file. The lower AVIF default keeps AVIF variants
    genuinely smaller than WebP while staying visually comparable.
    See :ref:`configuration-sidecar-quality`. Changing either setting
    requires clearing processed images, since per-format quality is
    not part of the cache filename. Reported in
    `issue #132 <https://github.com/netresearch/t3x-nr-image-optimize/issues/132>`__.
-   Fixed: ``clear-processed-images`` (Maintenance module) failed on
    symlinked deployments. The action validated the target path with
    ``realpath()``, which resolves a :file:`processed` symlink
    (shared-directory deployment layouts, e.g. Deployer) to its
    target and never matched :file:`<public>/processed` -- so
    clearing failed on every symlinked deployment. The directory is
    now emptied in place instead of ``rmdir()`` + ``mkdir()``,
    preserving the symlink. Reported in
    `issue #131 <https://github.com/netresearch/t3x-nr-image-optimize/issues/131>`__.

..  _changelog-2-3-1:

2.3.1
=====

-   Fixed: the backend module icon and Maintenance module templates
    were not TYPO3 v14 theme-aware. The module icon
    (:file:`module-image-optimize.svg`) and extension icon
    (:file:`Extension.svg`) were flat, hard-coded tiles that did not
    adapt to the v14 backend light/dark colour scheme. The
    Maintenance module's Fluid templates also used Bootstrap utility
    classes with fixed light values (``bg-light``, ``table-light``,
    ``text-dark``), causing card headers, table heads, and code chips
    to render as light boxes on a dark backend. The module icon is
    now theme-aware via ``fill="currentColor"`` (TYPO3 v14+, with a
    legacy full-colour tile kept for v13), and the templates use
    adaptive ``bg-body-tertiary`` tokens instead.

..  _changelog-2-3-0:

2.3.0
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
    Reported in
    `issue #120 <https://github.com/netresearch/t3x-nr-image-optimize/issues/120>`__.
-   Fixed: images published via :file:`public/_assets/<hash>`
    symlinks (extension :file:`Resources/Public/` assets) were
    rejected with HTTP 400. TYPO3 core publishes each extension's
    :file:`Resources/Public/` directory by symlinking
    :file:`public/_assets/<hash>/` to a location outside the public
    webroot. ``getAllowedRoots()`` did not resolve these symlinks, so
    variant requests for e.g. an extension's default/fallback image
    failed even though the file is a legitimate part of the deployed
    application. Every immediate child of :file:`_assets` is now
    resolved individually. Reported in
    `issue #117 <https://github.com/netresearch/t3x-nr-image-optimize/issues/117>`__.

..  _changelog-2-2-4:

2.2.4
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
    :ref:`usage-protected-files` for the trade-off.
-   Fixed: backend module labels are resolved via array format.

..  _changelog-2-2-3:

2.2.3
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
    -- continue to be rejected. Reported in
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

..  _changelog-2-2-2:

2.2.2
=====

-   Added ``OptimizeOnUploadListener`` -- PSR-14 listener
    that runs ``optipng`` / ``gifsicle`` / ``jpegoptim`` on
    ``AfterFileAddedEvent`` and ``AfterFileReplacedEvent``.
    Keyed by ``storageUid . ':' . identifier`` to avoid
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
