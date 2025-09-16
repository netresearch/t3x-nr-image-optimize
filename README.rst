.. |release| image:: https://img.shields.io/github/v/release/netresearch/t3x-nr-image-optimize?sort=semver
   :target: https://github.com/netresearch/t3x-nr-image-optimize/releases/latest
.. |license| image:: https://img.shields.io/github/license/netresearch/t3x-nr-image-optimize
   :target: https://github.com/netresearch/t3x-nr-image-optimize/blob/main/LICENSE
.. |ci| image:: https://github.com/netresearch/t3x-nr-image-optimize/actions/workflows/ci.yml/badge.svg
.. |php| image:: https://img.shields.io/badge/PHP-8.2%20|%208.3%20|%208.4-blue.svg
.. |typo3| image:: https://img.shields.io/badge/TYPO3-13.4-orange.svg

|php| |typo3| |license| |ci| |release|

.. _nr_image_optimize:

=====================================
🚀 TYPO3 Extension: nr_image_optimize
=====================================

The ``nr_image_optimize`` extension is an advanced TYPO3 extension for image optimization. It provides lazy image processing, modern formats, responsive images, and performance optimizations.

🎨 Features
============

- 🚀 **Lazy Image Processing**: Images are processed only when requested.
- 🎨 **Modern Format Support**: WebP and AVIF with automatic fallback.
- 📱 **Responsive Images**: Built-in ViewHelper for srcset generation.
- ⚡ **Performance Optimized**: Middleware-based processing for efficiency.
- 🔧 **Intervention Image**: Powered by the Intervention Image library.
- 📊 **Core Web Vitals**: Improves LCP and overall page performance.

🛠️ Requirements
================

- PHP 8.2, 8.3, or 8.4
- TYPO3 13.4
- Intervention Image library (installed via Composer automatically)

📌 Recommended Extensions
========================

- `https://github.com/christophlehmann/imageoptimizer`

💾 Installation
================

Via Composer (recommended)
------------------------

.. code-block:: bash

    composer require netresearch/nr-image-optimize

Manual Installation
------------------

1. Download the extension from the TYPO3 Extension Repository
2. Upload to ``typo3conf/ext/``
3. Activate the extension in the Extension Manager

⚙️ Configuration
================

The extension works out of the box with sensible defaults. Images are automatically optimized when accessed via the ``/processed/`` path.

ViewHelper Usage
----------------

.. code-block:: html

    {namespace nr=Netresearch\NrImageOptimize\ViewHelpers}

    <nr:sourceSet file="{image}"
                  width="1200"
                  height="800"
                  quality="85"
                  sizes="(max-width: 768px) 100vw, 50vw"
    />

Supported Parameters
---------------------

- ``file``: Image file resource
- ``width``: Target width in pixels
- ``height``: Target height in pixels
- ``quality``: JPEG/WebP quality (1-100)
- ``sizes``: Responsive sizes attribute
- ``format``: Output format (auto, webp, avif, jpg, png)

📐 Source Set Configuration
---------------------------

Different source sets can be defined for each media breakpoint via the ``set`` attribute:

.. code-block:: html

    <nr:sourceSet path="{f:uri.image(image: image, width: '960', height: '690', cropVariant: 'default')}"
                  set="{
                      480:{width: 160, height: 90},
                      800:{width: 400, height: 300}
                  }"
    />

🖼️ Render Modes
----------------

Two render modes are available for the ``SourceSetViewHelper``:

- **cover**: Default mode, resizes images to fully cover the provided width and height.
- **fit**: Resizes images so they fit within the provided width and height.

.. code-block:: html

    <nr:sourceSet path="{f:uri.image(image: image, width: '960', height: '690', cropVariant: 'default')}"
                  width="960"
                  height="690"
                  mode="fit"
    />

🧪 Development & Testing
========================

Unit tests ensure functionality and code quality.

.. code-block:: bash

    # Run all tests
    composer ci:test

    # Run specific tests
    composer ci:test:php:cgl     # Code style
    composer ci:test:php:lint    # PHP syntax
    composer ci:test:php:phpstan # Static analysis
    composer ci:test:php:unit    # PHPUnit tests
    composer ci:test:php:rector  # Code quality

🏗️ Architecture
================

The extension uses a middleware approach for image processing:

1. **ProcessingMiddleware**: Intercepts requests to ``/processed/`` paths
2. **Processor**: Handles image optimization and format conversion
3. **SourceSetViewHelper**: Generates responsive image markup

⚡ Performance Considerations
=============================

- Images are processed only once and cached
- Supports native browser lazy loading
- Automatic format negotiation based on Accept headers
- Optimized for CDN delivery

📄 License
===========

GPL-3.0-or-later. See `LICENSE file <LICENSE>`_ for details.

🆘 Support
==========

For issues and feature requests, please use the `GitHub issue tracker <https://github.com/netresearch/t3x-nr-image-optimize/issues>`_.

🙏 Credits
===========

Developed by `Netresearch DTT GmbH <https://www.netresearch.de/>`_
