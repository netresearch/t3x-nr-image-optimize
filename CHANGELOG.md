# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- Serve images from storage directories that are symlinks (e.g. `fileadmin`
  mounted on AWS EFS/NFS) instead of returning HTTP 400 for every uncached
  variant. Path validation now resolves every configured Local FAL storage
  base path in addition to the TYPO3 public root, while still rejecting
  paths that escape those roots via symlinks inside a storage ([#70]).

[#70]: https://github.com/netresearch/t3x-nr-image-optimize/issues/70

## [2.2.1]

### Changed

- Adjusted author information in `ext_emconf.php`.

## [2.2.0]

### Fixed

- Always render `alt` attribute on generated `<img>` tags.

### Changed

- Expanded unit test coverage for Processor and SourceSetViewHelper.
- Updated PHP-CS-Fixer configuration.
- Updated dev dependencies.

## [2.1.0]

### Added

- Responsive width-based `srcset` generation as opt-in feature.
- `widthVariants` parameter for custom breakpoints.
- `sizes` parameter for responsive image sizing.
- `fetchpriority` attribute for resource hints.

### Changed

- Optimized default `sizes` attribute values.

## [2.0.1]

### Fixed

- Removed `declare` statement preventing TER publishing via GitHub Actions.

## [2.0.0]

### Added

- TYPO3 13 compatibility.
- PHP 8.2, 8.3, and 8.4 support.
- License file.

### Changed

- Switched to Intervention Image 3.x.

### Removed

- Support for older TYPO3 versions.
- Obsolete system binary checks.

## [1.0.1]

### Added

- `ext_emconf.php` for classic installation.

## [1.0.0]

### Added

- Initial stable release.
- GitHub Actions CI workflows.

## [0.1.5]

### Fixed

- `strtolower()` null argument error.
- Array offset access on boolean value.

### Added

- Allowed numeric characters in file extensions.
- Extension icon.

### Changed

- Corrected crop variant examples.
- Improved lazy loading behavior.

[Unreleased]: https://github.com/netresearch/t3x-nr-image-optimize/compare/2.2.1...HEAD
[2.2.1]: https://github.com/netresearch/t3x-nr-image-optimize/compare/2.2.0...2.2.1
[2.2.0]: https://github.com/netresearch/t3x-nr-image-optimize/compare/2.1.0...2.2.0
[2.1.0]: https://github.com/netresearch/t3x-nr-image-optimize/compare/2.0.1...2.1.0
[2.0.1]: https://github.com/netresearch/t3x-nr-image-optimize/compare/2.0.0...2.0.1
[2.0.0]: https://github.com/netresearch/t3x-nr-image-optimize/compare/1.0.1...2.0.0
[1.0.1]: https://github.com/netresearch/t3x-nr-image-optimize/compare/1.0.0...1.0.1
[1.0.0]: https://github.com/netresearch/t3x-nr-image-optimize/compare/0.1.5...1.0.0
[0.1.5]: https://github.com/netresearch/t3x-nr-image-optimize/releases/tag/0.1.5
