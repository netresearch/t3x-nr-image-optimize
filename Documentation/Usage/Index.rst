..  include:: /Includes.rst.txt

..  _usage:

=====
Usage
=====

This chapter shows practical examples for integrating
responsive images into your Fluid templates.

..  _usage-namespace:

Register the namespace
======================

Add the ViewHelper namespace at the top of your Fluid
template or register it globally:

..  code-block:: html
    :caption: Inline namespace declaration

    {namespace nr=Netresearch\NrImageOptimize\ViewHelpers}

..  _usage-basic-example:

Basic responsive image
======================

..  code-block:: html
    :caption: Simple responsive image with quality setting

    <nr:sourceSet file="{image}"
                  width="1200"
                  height="800"
                  quality="85"
                  sizes="(max-width: 768px) 100vw, 50vw"
    />

..  _usage-responsive-srcset:

Responsive width-based srcset
=============================

Enable width-based ``srcset`` generation with a ``sizes``
attribute for improved responsive image handling. This is
opt-in per usage.

..  code-block:: html
    :caption: Enable responsive srcset with default variants

    <nrio:sourceSet
        path="{f:uri.image(
            image: image,
            maxWidth: size,
            cropVariant: 'default'
        )}"
        width="{size}"
        height="{size * ratio}"
        alt="{image.properties.alternative}"
        lazyload="1"
        mode="fit"
        responsiveSrcset="1"
    />

..  _usage-custom-variants:

Custom width variants
---------------------

..  code-block:: html
    :caption: Specify custom breakpoints for srcset

    <nrio:sourceSet
        path="{f:uri.image(
            image: image,
            maxWidth: size,
            cropVariant: 'default'
        )}"
        width="{size}"
        height="{size * ratio}"
        responsiveSrcset="1"
        widthVariants="320,640,1024,1920,2560"
        sizes="(max-width: 640px) 100vw,
               (max-width: 1024px) 75vw, 50vw"
    />

..  _usage-output-comparison:

Output comparison
=================

**Legacy mode** (``responsiveSrcset=false`` or not set):

..  code-block:: html
    :caption: Density-based 2x srcset output

    <img src="/processed/fileadmin/image.w625h250m1q100.jpg"
         srcset="/processed/fileadmin/image.w1250h500m1q100.jpg x2"
         width="625"
         height="250"
         loading="lazy">

**Responsive mode** (``responsiveSrcset=true``):

..  code-block:: html
    :caption: Width-based srcset output

    <img src="/processed/fileadmin/image.w1250h1250m1q100.png"
         srcset="/processed/fileadmin/image.w480h480m1q100.png 480w,
                 /processed/fileadmin/image.w576h576m1q100.png 576w,
                 /processed/fileadmin/image.w640h640m1q100.png 640w,
                 /processed/fileadmin/image.w768h768m1q100.png 768w,
                 /processed/fileadmin/image.w992h992m1q100.png 992w,
                 /processed/fileadmin/image.w1200h1200m1q100.png 1200w,
                 /processed/fileadmin/image.w1800h1800m1q100.png 1800w"
         sizes="auto, (min-width: 992px) 991px, 100vw"
         width="991"
         loading="lazy"
         alt="Image">

..  _usage-fetchpriority:

Fetch priority for Core Web Vitals
===================================

Use the ``fetchpriority`` attribute to hint the browser
about resource prioritization, improving Largest Contentful
Paint (LCP) scores:

..  code-block:: html
    :caption: High priority for above-the-fold hero image

    <nr:sourceSet file="{heroImage}"
                  width="1920"
                  height="1080"
                  fetchpriority="high"
    />
