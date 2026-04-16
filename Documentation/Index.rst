.. include:: /Includes.rst.txt

..  _start:

================================
Image Optimization for TYPO3
================================

:Extension key:
    nr_image_optimize

:Package name:
    netresearch/nr-image-optimize

:Version:
    |release|

:Language:
    en

----

The :composer:`netresearch/nr-image-optimize` extension provides
on-demand image optimization for TYPO3. Images are processed
lazily via middleware when first requested, with support for
modern formats (WebP, AVIF), responsive ``srcset`` generation,
and automatic format negotiation.

..  card-grid::
    :columns: 1
    :columns-md: 2
    :gap: 4
    :card-height: 100

    ..  card:: Introduction

        Learn what the extension does, its features, and
        system requirements.

        ..  card-footer:: :ref:`Read more <introduction>`
            :button-style: btn btn-primary stretched-link

    ..  card:: Installation

        Install via Composer or the Extension Manager.

        ..  card-footer:: :ref:`Read more <installation>`
            :button-style: btn btn-secondary stretched-link

    ..  card:: Configuration

        Configure the SourceSetViewHelper, render modes,
        responsive srcset, and lazy loading.

        ..  card-footer:: :ref:`Read more <configuration>`
            :button-style: btn btn-secondary stretched-link

    ..  card:: Usage

        Integrate responsive images into your Fluid
        templates with practical examples.

        ..  card-footer:: :ref:`Read more <usage>`
            :button-style: btn btn-secondary stretched-link

    ..  card:: Maintenance

        Manage processed images and check system
        requirements in the backend module.

        ..  card-footer:: :ref:`Read more <maintenance>`
            :button-style: btn btn-secondary stretched-link

    ..  card:: Developer reference

        Architecture overview and PHP API reference.

        ..  card-footer:: :ref:`Read more <developer>`
            :button-style: btn btn-secondary stretched-link

..  toctree::
    :maxdepth: 2
    :titlesonly:
    :hidden:

    Introduction/Index
    Installation/Index
    Configuration/Index
    Usage/Index
    Maintenance/Index
    Developer/Index
    Changelog/Index
