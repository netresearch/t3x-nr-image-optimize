.. _nr_image_optimize:

=========================
NrImageOptimize Extension
=========================

The `NrImageOptimize` extension is a TYPO3 extension designed to optimize images and generate responsive image sets.
It provides various ViewHelpers to facilitate image optimization and responsive image handling.

Features
========

- **Image Optimization**: Automatically optimizes images for better performance.
- **Responsive Image Sets**: Generates responsive image sets using the `SourceSetViewHelper`.
- **Lazy Loading**: Supports lazy loading of images to improve page load times.
- **Customizable**: Allows customization of image processing parameters.

Installation
============

1. Add the extension to your TYPO3 installation using Composer:

   .. code-block:: bash

      composer require netresearch/nr_image_optimize

2. Activate the extension in the TYPO3 Extension Manager.

Usage
=====

ViewHelpers
-----------

The extension provides several ViewHelpers to optimize and handle images:

- **SourceSetViewHelper**: Generates responsive image sets.

Example
-------

Here is an example of how to use the `SourceSetViewHelper` in your Fluid templates:

.. code-block:: html

    <div data-namespace-typo3-fluid="true"
        xmlns:nrio="http://typo3.org/ns/Netresearch/NrImageOptimize/ViewHelpers" >
        <nrio:sourceSet path="{f:uri.image(image: image, width: '960', height: '690', cropVariant: 'default')}"
                       width="960"
                       height="690"
                       alt="Image description"
                       class="image"
                       lazyload="1"
                       set="{480:{width: 160, height: 90}}
        />
    </div>


Source set configuration
------------------------

You can define differnt source sets for each media brakepoints by pass the information via the `set` attribute.

.. code-block:: html

    <div data-namespace-typo3-fluid="true"
        xmlns:nrio="http://typo3.org/ns/Netresearch/NrImageOptimize/ViewHelpers" >
        <nrio:sourceSet path="{f:uri.image(image: image, width: '960', height: '690', cropVariant: 'default')}"
                       <!-- other attributes -->
                       set="{
                            480:{width: 160, height: 90}
                            800:{width: 400, height: 300}
                       }
        />
    </div>

The first number represents the maximum width of the view port in pixel, the hight and the width defining the target image size for the picture.


Render-modes
------------
There are 2 render-modes available for the `SourceSetViewHelper` at the moment.

- **cover**: The default render-mode will resize the images so they cover the provided width and height fully.
- **fit**: The fit render-mode will resize the images so they fit into the provided width and height.

.. code-block:: html

    <div data-namespace-typo3-fluid="true"
        xmlns:nrio="http://typo3.org/ns/Netresearch/NrImageOptimize/ViewHelpers" >
        <nrio:sourceSet path="{f:uri.image(image: image, width: '960', height: '690', cropVariant: 'default')}"
                       width="960"
                       height="690"
                       <!-- other attributes -->
                       mode="fit"
        />
    </div>

Testing
=======

Unit tests are provided to ensure the functionality and the codestyle of the extension. To run the tests, use the following command:

.. code-block:: bash

   composer ci:test

Contributing
============

Contributions are welcome! Please submit your pull requests to the GitHub repository.
