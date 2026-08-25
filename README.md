# TYPO3 Extension: nr_image_optimize

[![PHP](https://img.shields.io/badge/PHP-8.2%20|%208.3%20|%208.4-blue.svg)](https://www.php.net/)
[![TYPO3](https://img.shields.io/badge/TYPO3-12-orange.svg)](https://typo3.org/)
[![License](https://img.shields.io/badge/License-GPL%203.0-green.svg)](LICENSE)

Advanced image optimization extension for TYPO3 CMS providing three operating modes: on-demand frontend processing, on-upload compression, and bulk CLI tools.

## Features

- 🚀 **Lazy Image Processing**: Images are only processed when they are actually requested
- 🎨 **Modern Format Support**: WebP and AVIF with automatic fallback
- 📱 **Responsive Images**: Built-in ViewHelper for srcset generation
- ⚡ **Performance Optimized**: Middleware-based processing for efficiency
- 🔧 **Intervention Image**: Powered by the Intervention Image library
- 📊 **Core Web Vitals**: Improves LCP and overall page performance
- 🗜️ **On-Upload Compression**: Uploaded/replaced files losslessly compressed in place via optipng, gifsicle, or jpegoptim
- 🛠️ **Bulk CLI Tools**: `nr:image:optimize` and `nr:image:analyze` process or report on existing FAL storages

## Requirements

- PHP 8.2, 8.3, or 8.4
- TYPO3 12.x
- Intervention Image library (automatically installed via Composer)

## Installation

### Via Composer (recommended)

```bash
composer require netresearch/nr-image-optimize
```

### Manual Installation

1. Download the extension from the TYPO3 Extension Repository
2. Upload to `typo3conf/ext/` directory
3. Activate the extension in the Extension Manager

## Configuration

The extension works out of the box with sensible defaults. Images are automatically optimized when accessed through the `/processed/` path.

### ViewHelper Usage

```html
{namespace nr=Netresearch\NrImageOptimize\ViewHelpers}

<nr:sourceSet 
    path="{f:uri.image(image: image)}" 
    width="1200" 
    height="800" 
    alt="{image.properties.alternative}"
    sizes="(max-width: 768px) 100vw, 50vw"
/>
```

### Supported Parameters

- `path` (required): Public path to the source image, typically generated via `f:uri.image()`
- `width`: Target width in pixels (default: `0`, auto)
- `height`: Target height in pixels (default: `0`, auto)
- `alt`: Alternative text (default: empty string)
- `title`: Title attribute (default: empty string)
- `class`: CSS classes; include `lazyload` to use JS lazy load (default: empty string)
- `attributes`: Extra HTML attributes merged into the tag (default: `[]`)
- `set`: Responsive set `{maxWidth: {width: int, height: int}}` (default: `[]`)
- `sizes`: Responsive sizes attribute (default: `auto, (min-width: 992px) 991px, 100vw`)
- `mode`: Render mode, `cover` or `fit` (default: `cover`)
- `lazyload`: Add `loading="lazy"` (default: `false`)
- `responsiveSrcset`: Enable width-based responsive srcset (default: `false`)
- `widthVariants`: Width variants for responsive srcset (default: `480, 576, 640, 768, 992, 1200, 1800`)
- `fetchpriority`: Native `fetchpriority` attribute (`high`, `low`, `auto`)

## Development

### Running Tests

```bash
# Run all tests
composer ci:test

# Run specific tests
composer ci:test:php:cgl    # Code style
composer ci:test:php:lint   # PHP syntax
composer ci:test:php:phpstan # Static analysis
composer ci:test:php:rector  # Code quality
```

## Architecture

The extension uses a middleware approach for processing images:

1. **ProcessingMiddleware**: Intercepts requests to `/processed/` paths
2. **Processor**: Handles image optimization and format conversion
3. **SourceSetViewHelper**: Generates responsive image markup

## Performance Considerations

- Images are processed only once and cached
- Supports browser-native lazy loading
- Automatic format negotiation based on Accept headers
- Optimized for CDN delivery

## License

This extension is licensed under the GPL-3.0-or-later license. See [LICENSE](LICENSE) file for details.

## Support

For issues and feature requests, please use the [GitHub issue tracker](https://github.com/netresearch/t3x-nr-image-optimize/issues).

## Credits

Developed by [Netresearch DTT GmbH](https://www.netresearch.de/)

## Changelog

See [CHANGELOG.md](CHANGELOG.md) and [`Documentation/Changelog/Index.rst`](Documentation/Changelog/Index.rst) for the full release history.
