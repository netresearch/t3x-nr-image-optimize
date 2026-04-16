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
    **Processor** to create a variant on demand.
2.  **OptimizeOnUploadListener** reacts to PSR-14 file
    events (``AfterFileAddedEvent`` and
    ``AfterFileReplacedEvent``) and delegates to the shared
    **ImageOptimizer** service for in-place lossless
    compression.
3.  **OptimizeImagesCommand** and **AnalyzeImagesCommand**
    iterate the FAL index from the CLI. Both extend
    **AbstractImageCommand**, which provides shared
    database iteration, progress rendering, and option
    parsing.

The **MaintenanceController** provides the backend module
for statistics, cleanup, and system-requirement checks.

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

    Frontend middleware registered before
    ``typo3/cms-frontend/site``. Intercepts requests whose
    path starts with ``/processed/`` and delegates image
    processing to the :php:class:`Processor` class.

..  _developer-processor:

Processor
=========

..  php:namespace:: Netresearch\NrImageOptimize

..  php:class:: Processor

    Core image processing engine. Parses the requested URL
    to extract dimensions, quality, mode, and format
    parameters. Uses the Intervention Image library for the
    actual manipulation. Processed images are cached on disk
    to avoid repeated processing.

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

    Re-entrancy guard: the listener keys its guard by
    ``storageUid + ':' + identifier`` to avoid
    cross-storage collisions while still catching the
    ``replaceFile()`` loop that the optimizer's own write
    would otherwise trigger.

    Storage state: ``setEvaluatePermissions(false)`` is
    applied before delegation and restored to its previous
    value in a ``finally`` block.

..  _developer-service:

ImageOptimizer service
======================

..  php:namespace:: Netresearch\NrImageOptimize\Service

..  php:class:: ImageOptimizer

    Shared backend used by both the event listener and the
    CLI commands. Resolves optimizer binaries via
    ``$PATH``, with env overrides (``OPTIPNG_BIN``,
    ``GIFSICLE_BIN``, ``JPEGOPTIM_BIN``). A set-but-invalid
    override is authoritative -- the tool is reported
    unavailable rather than silently falling back to
    ``$PATH``.

    Public surface:

    ``optimize(FileInterface, stripMetadata, jpegQuality, dryRun): array``
        Run the appropriate tool in place and replace the
        FAL file when the result shrinks.

    ``analyze(FileInterface, stripMetadata, jpegQuality): array``
        Same pipeline, but never writes back.

    ``analyzeHeuristic(FileInterface, maxWidth, maxHeight, minSize): array``
        Fast estimation from size and resolution. No binary
        is invoked.

    ``resolveToolFor(string extension): ?array``
        Tool discovery for callers that want to pre-check.

    ``hasAnyTool(): bool``
        Quick check whether at least one optimizer is
        installed.

    ``supportedExtensions(): list<string>``
        Returns ``['png', 'gif', 'jpg', 'jpeg']``.

..  _developer-commands:

CLI commands
============

..  php:namespace:: Netresearch\NrImageOptimize\Command

..  php:class:: AbstractImageCommand

    Base class for the image CLI commands. Exposes
    ``parseStorageUidsOption``, ``getIntOption``,
    ``extractUid``, ``countImages``, ``iterateViaIndex``
    (Generator over ``sys_file`` rows),
    ``createProgress``, ``buildLabel``, ``shortenLabel``,
    and ``formatMbGb``.

..  php:class:: OptimizeImagesCommand

    Implements ``nr:image:optimize``. Delegates to
    :php:class:`~Netresearch\\NrImageOptimize\\Service\\ImageOptimizer`
    per file and renders a Symfony progress bar with
    cumulative savings.

..  php:class:: AnalyzeImagesCommand

    Implements ``nr:image:analyze``. Uses
    ``ImageOptimizer::analyzeHeuristic()`` -- no external
    tool is invoked.

..  _developer-viewhelper:

SourceSetViewHelper
===================

..  php:namespace:: Netresearch\NrImageOptimize\ViewHelpers

..  php:class:: SourceSetViewHelper

    Fluid ViewHelper that generates ``<img>`` tags with
    ``srcset`` attributes for responsive image delivery.
    Supports both density-based (2x) and width-based
    responsive srcset modes.

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
