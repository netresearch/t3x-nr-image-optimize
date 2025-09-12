# TYPO3 Extension: nr_image_optimize

[![PHP](https://img.shields.io/badge/PHP-8.1%20|%208.2%20|%208.3%20|%208.4-blue.svg)](https://www.php.net/)
[![TYPO3](https://img.shields.io/badge/TYPO3-11.5%20|%2012-orange.svg)](https://typo3.org/)
[![License](https://img.shields.io/badge/License-GPL%202.0-green.svg)](LICENSE)

Advanced image optimization extension for TYPO3 CMS that provides lazy image processing, modern format support, and performance optimization.

## Features

- 🚀 **Lazy Image Processing**: Images are only processed when they are actually requested
- 🎨 **Modern Format Support**: WebP and AVIF with automatic fallback
- 📱 **Responsive Images**: Built-in ViewHelper for srcset generation
- ⚡ **Performance Optimized**: Middleware-based processing for efficiency
- 🔧 **Intervention Image**: Powered by the Intervention Image library
- 📊 **Core Web Vitals**: Improves LCP and overall page performance

## Requirements

- PHP 8.1, 8.2, 8.3, or 8.4
- TYPO3 11.5 or 12.x
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
    file="{image}" 
    width="1200" 
    height="800" 
    quality="85" 
    sizes="(max-width: 768px) 100vw, 50vw"
/>
```

### Supported Parameters

- `file`: The image file resource
- `width`: Target width in pixels
- `height`: Target height in pixels
- `quality`: JPEG/WebP quality (1-100)
- `sizes`: Responsive sizes attribute
- `format`: Output format (auto, webp, avif, jpg, png)

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

### PHP Compatibility Check

```bash
./check-php-compatibility.sh
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

This extension is licensed under the GPL-2.0-or-later license. See [LICENSE](LICENSE) file for details.

## Support

For issues and feature requests, please use the [GitHub issue tracker](https://github.com/netresearch/typo3-nr-image-optimize/issues).

## Credits

Developed by [Netresearch DTT GmbH](https://www.netresearch.de/)

## Changelog

### Version 1.0.0
- Initial release
- PHP 8.1-8.4 compatibility
- TYPO3 11.5 and 12.x support
- WebP and AVIF format support
- Lazy image processing
- Responsive image ViewHelper
