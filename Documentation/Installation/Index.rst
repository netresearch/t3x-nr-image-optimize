.. include:: /Includes.rst.txt

..  _installation:

============
Installation
============

..  _installation-composer:

Via Composer (recommended)
==========================

..  code-block:: bash
    :caption: Install the extension via Composer

    composer require netresearch/nr-image-optimize

..  _installation-manual:

Manual installation
===================

1.  Download the extension from the
    `TYPO3 Extension Repository
    <https://extensions.typo3.org/extension/nr_image_optimize>`__.
2.  Upload to :file:`typo3conf/ext/`.
3.  Activate the extension in the Extension Manager.

..  _installation-setup:

Setup
=====

The extension works out of the box after installation:

-   The frontend middleware picks up any request to the
    ``/processed/`` path and produces a variant on demand.
-   The PSR-14 event listener for
    ``AfterFileAddedEvent`` and ``AfterFileReplacedEvent``
    runs on every new or replaced image.

No additional configuration is required.

..  tip::

    For best results, ensure that your server has the
    **Imagick** or **GD** PHP extension installed. The
    backend maintenance module can verify all prerequisites
    for you (see :ref:`maintenance-system-requirements`).

..  _installation-optional-binaries:

Optional: install optimizer binaries
====================================

The automatic on-upload compression and the
:ref:`nr:image:optimize <usage-cli-optimize>` CLI command call
out to ``optipng``, ``gifsicle``, and ``jpegoptim``. The
:ref:`nr:image:analyze <usage-cli-analyze>` command is purely
heuristic and does not require these binaries.

Install whichever tools you need and make them available in
``$PATH``:

..  code-block:: bash
    :caption: Debian/Ubuntu

    sudo apt-get install optipng gifsicle jpegoptim

..  code-block:: bash
    :caption: macOS (Homebrew)

    brew install optipng gifsicle jpegoptim

To pin a specific path (useful in containerized environments)
set one or more of the following environment variables:

``OPTIPNG_BIN``
    Absolute path to ``optipng``.

``GIFSICLE_BIN``
    Absolute path to ``gifsicle``.

``JPEGOPTIM_BIN``
    Absolute path to ``jpegoptim``.

A set-but-invalid override is authoritative: the tool is
reported unavailable. There is no silent fallback to ``$PATH``.

..  tip::

    If no supported binary is installed, the on-upload
    listener skips optimization silently. The
    ``nr:image:optimize`` CLI command, by contrast, reports
    the missing tools and exits with failure so an operator
    running a bulk job gets immediate feedback.
    ``nr:image:analyze`` works regardless because it never
    invokes an external binary.
