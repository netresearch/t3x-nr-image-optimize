..  include:: /Includes.rst.txt

=================================
Image Optimization for TYPO3
=================================

:Extension key:
   nr_image_optimize

:Package name:
   netresearch/nr-image-optimize

:Version:
   |release|

:Language:
   en

----

The ``nr_image_optimize`` extension provides on-demand image optimization for
TYPO3. Images are processed lazily via middleware when first requested, with
support for modern formats (WebP, AVIF), responsive ``srcset`` generation, and
automatic format negotiation.

**Key features:**

-  Lazy image processing -- images are optimized only when requested
-  Modern format support (WebP, AVIF) with automatic fallback
-  Responsive images via ``SourceSetViewHelper``
-  Middleware-based processing for performance
-  Powered by the Intervention Image library
-  Backend maintenance module for managing processed images

.. toctree::
   :maxdepth: 2
   :titlesonly:

   Installation/Index
   Configuration/Index
   Maintenance/Index
