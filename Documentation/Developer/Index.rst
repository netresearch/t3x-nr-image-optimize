..  include:: /Includes.rst.txt

..  _developer:

===================
Developer reference
===================

..  _developer-architecture:

Architecture
============

The extension uses a middleware-based approach for image
processing:

1.  **ProcessingMiddleware** intercepts frontend requests
    matching the ``/processed/`` path.
2.  **Processor** handles image optimization, format
    conversion, and caching.
3.  **SourceSetViewHelper** generates responsive ``<img>``
    markup in Fluid templates.
4.  **MaintenanceController** provides the backend module
    for statistics and cleanup.

..  _developer-directory-structure:

Directory structure
===================

..  directory-tree::

    *   Classes/

        *   Controller/

            *   MaintenanceController.php

        *   Middleware/

            *   ProcessingMiddleware.php

        *   Service/

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
