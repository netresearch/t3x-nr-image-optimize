..  include:: /Includes.rst.txt

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

The extension works out of the box after installation. No
additional configuration is required. Images accessed through
the ``/processed/`` path are automatically optimized by the
frontend middleware.

..  tip::

    For best results, ensure that your server has the
    **Imagick** or **GD** PHP extension installed. The backend
    maintenance module can verify all prerequisites for you
    (see :ref:`maintenance-system-requirements`).
