..  include:: /Includes.rst.txt

..  _developer:

===================
Developer reference
===================

..  _developer-architecture:

Architecture
============

The extension has three entry points that share the same
``ImageOptimizer`` service where applicable:

1.  **ProcessingMiddleware** intercepts frontend requests
    matching the ``/processed/`` path and delegates to
    **Processor**, which handles image optimization, format
    conversion, and caching.
2.  **OptimizeOnUploadListener** reacts to PSR-14 file events
    (``AfterFileAddedEvent`` and ``AfterFileReplacedEvent``)
    and delegates to **ImageOptimizer** for in-place lossless
    compression via ``optipng``/``gifsicle``/``jpegoptim``.
3.  **OptimizeImagesCommand** and **AnalyzeImagesCommand**
    iterate the FAL index from the CLI, extending
    **AbstractImageCommand** for shared iteration and option
    parsing. See :ref:`Usage <usage-cli>`.

**SourceSetViewHelper** generates responsive ``<img>`` markup
in Fluid templates. **MaintenanceController** provides the
backend module for statistics and cleanup.

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

            *   ImageOptimizer.php
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
    parameters. Uses the Intervention Image library for
    actual image manipulation. Processed images are cached
    on disk to avoid repeated processing.

..  _developer-viewhelper:

SourceSetViewHelper
===================

..  php:namespace:: Netresearch\NrImageOptimize\ViewHelpers

..  php:class:: SourceSetViewHelper

    Fluid ViewHelper that generates ``<img>`` tags with
    ``srcset`` attributes for responsive image delivery.
    Supports both density-based (2x) and width-based
    responsive srcset modes.

..  _developer-image-optimizer:

ImageOptimizer
==============

..  php:namespace:: Netresearch\NrImageOptimize\Service

..  php:class:: ImageOptimizer

    Shared service that shells out to ``optipng``,
    ``gifsicle``, or ``jpegoptim`` (whichever is resolvable
    on the ``$PATH``, or via the
    ``OPTIPNG_BIN``/``GIFSICLE_BIN``/``JPEGOPTIM_BIN``
    environment variables) via ``proc_open`` with argument
    arrays. Used by both **OptimizeOnUploadListener** and
    **OptimizeImagesCommand**. A missing binary degrades
    gracefully -- that format is simply skipped.

..  _developer-event-listener:

OptimizeOnUploadListener
========================

..  php:namespace:: Netresearch\NrImageOptimize\EventListener

..  php:class:: OptimizeOnUploadListener

    PSR-14 listener for ``AfterFileAddedEvent`` and
    ``AfterFileReplacedEvent``. Compresses newly
    uploaded/replaced files in place via
    :ref:`ImageOptimizer <developer-image-optimizer>`,
    guarded against re-entrancy by storage UID + file
    identifier.

..  _developer-commands:

Console commands
=================

..  php:namespace:: Netresearch\NrImageOptimize\Command

..  php:class:: AbstractImageCommand

    Shared template method for both commands below: storage
    UID parsing, FAL index iteration, and progress rendering.

..  php:class:: OptimizeImagesCommand

    Backs ``nr:image:optimize``. See
    :ref:`Usage <usage-cli-optimize>`.

..  php:class:: AnalyzeImagesCommand

    Backs ``nr:image:analyze``. See
    :ref:`Usage <usage-cli-analyze>`.

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
