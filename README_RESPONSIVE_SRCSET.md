# Responsive Srcset Feature

## Overview
The nr-image-optimize extension now supports responsive width-based srcset generation with sizes attribute for better responsive image handling.

## New Parameters

### responsiveSrcset
- **Type**: bool
- **Default**: false
- **Description**: Enable width-based responsive srcset generation instead of density-based (2x) srcset

### widthVariants
- **Type**: string|array
- **Default**: '500,1000,1500,2500'
- **Description**: Width variants for responsive srcset (comma-separated string or array)

### sizes
- **Type**: string
- **Default**: '(max-width: 576px) 100vw, (max-width: 768px) 50vw, (max-width: 992px) 33vw, (max-width: 1200px) 25vw, 1250px'
- **Description**: Sizes attribute for responsive images

## Usage Examples

### Enable responsive srcset with default values:
```html
<nrio:sourceSet 
    path="{f:uri.image(image: image, maxWidth: size, cropVariant: 'default')}"
    width="{size}"
    height="{size * ratio}"
    alt="{image.properties.alternative}"
    lazyload="1"
    mode="fit"
    responsiveSrcset="1"
/>
```

### Custom width variants:
```html
<nrio:sourceSet 
    path="{f:uri.image(image: image, maxWidth: size, cropVariant: 'default')}"
    width="{size}"
    height="{size * ratio}"
    alt="{image.properties.alternative}"
    lazyload="1"
    mode="fit"
    responsiveSrcset="1"
    widthVariants="320,640,1024,1920,2560"
/>
```

### Custom sizes attribute:
```html
<nrio:sourceSet 
    path="{f:uri.image(image: image, maxWidth: size, cropVariant: 'default')}"
    width="{size}"
    height="{size * ratio}"
    alt="{image.properties.alternative}"
    lazyload="1"
    mode="fit"
    responsiveSrcset="1"
    sizes="(max-width: 640px) 100vw, (max-width: 1024px) 75vw, 50vw"
/>
```

## Output Comparison

### Legacy mode (responsiveSrcset=false or not set):
```html
<img src="/processed/fileadmin/image.w625h250m1q100.jpg" 
     srcset="/processed/fileadmin/image.w1250h500m1q100.jpg x2"
     width="625" 
     height="250" 
     loading="lazy">
```

### Responsive mode (responsiveSrcset=true):
```html
<img src="/processed/fileadmin/image.w1250h1250m1q100.png"
     srcset="/processed/fileadmin/image.w500h500m1q100.png 500w,
             /processed/fileadmin/image.w1000h1000m1q100.png 1000w,
             /processed/fileadmin/image.w1500h1500m1q100.png 1500w,
             /processed/fileadmin/image.w2500h2500m1q100.png 2500w"
     sizes="(max-width: 576px) 100vw,
            (max-width: 768px) 50vw,
            (max-width: 992px) 33vw,
            (max-width: 1200px) 25vw,
            1250px"
     width="1250"
     height="1250"
     loading="lazy"
     alt="Image">
```

## Backward Compatibility
- By default, `responsiveSrcset` is set to `false`, maintaining the existing 2x density-based srcset behavior
- All existing templates will continue to work without modifications
- To enable the new responsive srcset, explicitly set `responsiveSrcset="1"` in your templates

## Lazy Loading
- Both modes support lazy loading with native `loading="lazy"` attribute
- When using JS-based lazy loading (class="lazyload"), both `data-srcset` and `data-sizes` attributes are added automatically
