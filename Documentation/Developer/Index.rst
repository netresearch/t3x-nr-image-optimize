.. include:: /Includes.rst.txt

..  _developer:

===================
Developer reference
===================

..  _developer-architecture:

Architecture
============

The extension has three entry points that share the same
optimization backend:

1.  **ProcessingMiddleware** intercepts frontend requests
    matching the ``/processed/`` path and asks the
    :ref:`Processor <developer-processor>` to create a variant
    on demand.
2.  **OptimizeOnUploadListener** reacts to PSR-14 file events
    (``AfterFileAddedEvent`` and ``AfterFileReplacedEvent``)
    and delegates to the shared
    :ref:`ImageOptimizer <developer-image-optimizer>` service
    for in-place lossless compression.
3.  **OptimizeImagesCommand** and **AnalyzeImagesCommand**
    iterate the FAL index from the CLI. Both extend
    :ref:`AbstractImageCommand <developer-commands>`, which
    provides shared database iteration, progress rendering,
    and option parsing.

The **MaintenanceController** provides the backend module for
statistics, cleanup, and system-requirement checks.

..  _developer-directory-structure:

Directory structure
===================

..  directory-tree::

    *   Classes/

        *   Command/

            *   AbstractImageCommand.php
            *   AnalyzeImagesCommand.php
            *   OptimizeImagesCommand.php

        *   Controller/

            *   MaintenanceController.php

        *   Event/

            *   ImageProcessedEvent.php
            *   VariantServedEvent.php

        *   EventListener/

            *   OptimizeOnUploadListener.php

        *   Middleware/

            *   ProcessingMiddleware.php

        *   Service/

            *   ImageManagerAdapter.php
            *   ImageManagerFactory.php
            *   ImageOptimizer.php
            *   ImageReaderInterface.php
            *   SystemRequirementsService.php

        *   Processor.php
        *   ProcessorInterface.php
        *   ViewHelpers/

            *   SourceSetViewHelper.php

    *   Configuration/

        *   Backend/

            *   Modules.php

        *   Icons.php
        *   RequestMiddlewares.php
        *   Services.yaml

..  _developer-middleware:

Processing middleware
=====================

..  php:namespace:: Netresearch\NrImageOptimize\Middleware

..  php:class:: ProcessingMiddleware

    Frontend PSR-15 middleware registered **before**
    ``typo3/cms-frontend/site`` (see
    :ref:`configuration-middleware`). Intercepts every
    request whose path starts with ``/processed/`` and
    delegates to the
    :php:class:`~Netresearch\\NrImageOptimize\\ProcessorInterface`
    implementation. Any other request is passed to the next
    handler unchanged.

..  _developer-processor:

Processor
=========

..  php:namespace:: Netresearch\NrImageOptimize

..  php:class:: ProcessorInterface

    Contract for the on-demand processor -- declares
    ``generateAndSend(ServerRequestInterface): ResponseInterface``.
    The middleware depends on this interface, so integrators
    can replace the implementation via a Services.yaml alias.

..  php:class:: Processor

    Default implementation. Parses the requested URL (see
    :ref:`configuration-url-format`) to extract path,
    dimensions, quality, and mode. Loads the original via
    the ``ImageReaderInterface`` adapter (see
    :ref:`developer-image-manager`), generates the primary
    variant plus optional
    WebP / AVIF sidecars, and streams a PSR-7 response back
    with long-lived cache headers.

    Key behaviors:

    -   Rejects path-traversal (``..``) sequences at URL
        parse time, returning HTTP 400.
    -   Clamps ``w`` / ``h`` to 1--8192 and ``q`` to 1--100.
    -   Uses TYPO3's ``LockFactory`` to serialize concurrent
        requests for the same variant; a 503 is returned if
        the lock can't be acquired within ~1 second.
    -   Respects the ``Accept`` header plus the ``skipWebP``
        and ``skipAvif`` query parameters when choosing
        which cached sidecar to serve.
    -   Dispatches :php:class:`~Netresearch\\NrImageOptimize\\Event\\ImageProcessedEvent`
        after a new variant is written and
        :php:class:`~Netresearch\\NrImageOptimize\\Event\\VariantServedEvent`
        before the response body is sent.

..  _developer-events:

PSR-14 events
=============

..  php:namespace:: Netresearch\NrImageOptimize\Event

Integrators can subscribe to the processor's lifecycle to
collect metrics, trigger cache warming, or apply additional
post-processing.

..  php:class:: ImageProcessedEvent

    Dispatched after the processor has written a new variant
    to disk. Properties (all ``public readonly``):

    ``pathOriginal`` (``string``)
        Absolute path of the source image.

    ``pathVariant`` (``string``)
        Absolute path of the variant -- the actual served
        file may be a ``.webp`` or ``.avif`` sidecar of this
        path.

    ``extension`` (``string``)
        Lowercased extension of the variant (``jpg``,
        ``png``, ``webp``, ``avif`` ...).

    ``targetWidth`` / ``targetHeight`` (``?int``)
        Final clamped dimensions in pixels, or ``null`` if
        the URL omitted the value.

    ``targetQuality`` (``int``)
        Output quality (1--100).

    ``processingMode`` (``int``)
        ``0`` = cover, ``1`` = scale / fit.

    ``webpGenerated`` / ``avifGenerated`` (``bool``)
        Whether the respective sidecar was written alongside.

..  php:class:: VariantServedEvent

    Dispatched immediately before the response body is
    streamed to the client -- both for fresh variants and for
    cache hits. Properties:

    ``pathVariant`` (``string``)
        Absolute base path of the variant being served.

    ``extension`` (``string``)
        Lowercased source extension.

    ``responseStatusCode`` (``int``)
        HTTP status code (always 200 in practice).

    ``fromCache`` (``bool``)
        ``true`` when the response reuses a previously
        generated file on disk; ``false`` when the variant
        was produced in this request.

Register listeners via ``Configuration/Services.yaml`` or the
per-class ``#[AsEventListener]`` attribute:

..  code-block:: yaml
    :caption: Configuration/Services.yaml

    services:
      Acme\Example\Listener\MetricsListener:
        tags:
          - name: event.listener
            identifier: 'acme.metrics.variant_served'
            event: Netresearch\NrImageOptimize\Event\VariantServedEvent

..  _developer-event-listener:

Upload event listener
=====================

..  php:namespace:: Netresearch\NrImageOptimize\EventListener

..  php:class:: OptimizeOnUploadListener

    PSR-14 listener registered for ``AfterFileAddedEvent``
    and ``AfterFileReplacedEvent``. Normalizes the file
    extension, short-circuits unsupported extensions and
    offline storages, and delegates to
    :php:class:`~Netresearch\\NrImageOptimize\\Service\\ImageOptimizer`.

    Re-entrancy guard: keyed by
    ``storageUid . ':' . identifier`` so two storages sharing
    an identifier (``/image.jpg`` in different driver roots)
    don't block each other, while still catching the
    ``replaceFile()`` loop that the optimizer's own write
    would otherwise trigger.

    Storage state: ``setEvaluatePermissions(false)`` is
    applied before delegation and restored to its previous
    value in a ``finally`` block so long-running CLI runs
    don't leak permission state across iterations.

..  _developer-image-optimizer:

ImageOptimizer service
======================

..  php:namespace:: Netresearch\NrImageOptimize\Service

..  php:class:: ImageOptimizer

    Shared backend used by both the event listener and the
    CLI commands. Resolves optimizer binaries via ``$PATH``,
    with env overrides (``OPTIPNG_BIN``, ``GIFSICLE_BIN``,
    ``JPEGOPTIM_BIN``). A set-but-invalid override is
    authoritative -- the tool is reported unavailable rather
    than silently falling back to ``$PATH``. ``$PATH``
    lookups also verify ``is_executable()``.

    Public API:

    ``optimize(FileInterface $file, bool $stripMetadata = false, ?int $jpegQuality = null, bool $dryRun = false): array``
        Run the appropriate tool in place and replace the
        FAL file when the result shrinks.

    ``analyze(FileInterface $file, bool $stripMetadata = false, ?int $jpegQuality = null): array``
        Same pipeline, but never writes back.

    ``analyzeHeuristic(FileInterface $file, int $maxWidth = 2560, int $maxHeight = 1440, int $minSize = 512000): array``
        Fast estimation from size and resolution. No binary
        is invoked.

    ``resolveToolFor(string $extension): ?array``
        Tool discovery for callers that want to pre-check.

    ``hasAnyTool(): bool``
        Quick check whether at least one optimizer is
        installed.

    ``supportedExtensions(): list<string>``
        Returns ``['png', 'gif', 'jpg', 'jpeg']``.

..  _developer-image-manager:

ImageManager abstraction
========================

..  php:class:: ImageManagerFactory

    Selects the best available Intervention Image driver
    (Imagick over GD). See
    :ref:`configuration-image-driver` for behavior and
    failure modes.

..  php:class:: ImageReaderInterface

    Version-agnostic abstraction around Intervention Image's
    loading API. v3 uses ``ImageManager::read()``, v4 uses
    ``ImageManager::decode()``; this interface hides that
    difference so consumers and static analysis see a single
    stable contract.

..  php:class:: ImageManagerAdapter

    Default implementation. Dispatches to whichever method is
    present on the installed ``ImageManager``, letting the
    extension support Intervention Image ``^3 || ^4``
    simultaneously without version-conditional code paths.

..  _developer-system-requirements:

SystemRequirementsService
=========================

..  php:class:: SystemRequirementsService

    Produces the data shown on the
    :ref:`System requirements <maintenance-system-requirements>`
    tab of the backend module. Inspects:

    -   PHP version and loaded extensions (``imagick``,
        ``gd``, ``fileinfo``).
    -   ImageMagick / GraphicsMagick capabilities as
        reported by Imagick (WebP, AVIF support).
    -   Intervention Image installed version and the active
        driver (Imagick or GD).
    -   TYPO3 version.
    -   Availability of optional CLI tools (``magick``,
        ``convert``, ``identify``, ``gm``).

    Returns a structured array the controller renders into a
    Fluid template.

..  _developer-commands:

CLI commands
============

..  php:namespace:: Netresearch\NrImageOptimize\Command

..  php:class:: AbstractImageCommand

    Base class for the image CLI commands. Exposes
    ``parseStorageUidsOption``, ``getIntOption``,
    ``extractUid``, ``countImages``, ``iterateViaIndex``
    (Generator over ``sys_file`` rows -- constant memory),
    ``createProgress``, ``buildLabel``, ``shortenLabel``,
    and ``formatMbGb``.

..  php:class:: OptimizeImagesCommand

    Implements ``nr:image:optimize``. Delegates to
    :php:class:`~Netresearch\\NrImageOptimize\\Service\\ImageOptimizer`
    per file and renders a Symfony progress bar with
    cumulative savings. See :ref:`usage-cli-optimize`.

..  php:class:: AnalyzeImagesCommand

    Implements ``nr:image:analyze``. Uses
    ``ImageOptimizer::analyzeHeuristic()`` -- no external
    tool is invoked. See :ref:`usage-cli-analyze`.

..  _developer-viewhelper:

SourceSetViewHelper
===================

..  php:namespace:: Netresearch\NrImageOptimize\ViewHelpers

..  php:class:: SourceSetViewHelper

    Fluid ViewHelper that generates ``<img>`` tags with
    ``srcset`` attributes for responsive image delivery.
    Supports both density-based (2x) and width-based
    responsive srcset modes. See :ref:`usage` for examples
    and :ref:`configuration-viewhelper` for the parameter
    reference.

..  _developer-controller:

MaintenanceController
=====================

..  php:namespace:: Netresearch\NrImageOptimize\Controller

..  php:class:: MaintenanceController

    Extbase controller powering the backend module. Three
    actions:

    ``indexAction``
        Overview of the processed-images directory: file
        count, total size, largest files, type distribution.

    ``systemRequirementsAction``
        Renders the data produced by
        :php:class:`~Netresearch\\NrImageOptimize\\Service\\SystemRequirementsService`.

    ``clearProcessedImagesAction``
        Recursively deletes every file under the configured
        processed-images directory. A flash message reports
        the freed space.

    Module registration (access limited to ``systemMaintainer``)
    lives in :file:`Configuration/Backend/Modules.php`.

..  _developer-testing:

Testing
=======

..  code-block:: bash
    :caption: Run the full test suite

    composer ci:test

Individual test commands:

..  code-block:: bash
    :caption: Available test commands

    composer ci:test:php:cgl      # Code style
    composer ci:test:php:lint     # PHP syntax
    composer ci:test:php:phpstan  # Static analysis
    composer ci:test:php:unit     # PHPUnit tests
    composer ci:test:php:rector   # Code quality

..  code-block:: bash
    :caption: Docker-based matrix runner (also used by CI)

    Build/Scripts/runTests.sh -s unit -p 8.4
    Build/Scripts/runTests.sh -s phpstan -p 8.4
