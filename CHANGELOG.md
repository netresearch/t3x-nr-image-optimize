# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [2.2.2]

### Fixed

- Serve image variants when `public/processed` and/or `public/uploads` are
  symlinked to an external mount (e.g. AWS EFS on ECS via the container's
  post-deployment script). The symlink fix released in 2.2.1 only covered
  `fileadmin` (resolved via FAL storage lookup); variants under the other
  two directories still returned HTTP 400 for every uncached request
  because the parent-walk in path validation resolved them to targets
  outside the allowed-roots set. `getAllowedRoots()` now also resolves
  symlinked `public/processed` and `public/uploads` — restricted to this
  hardcoded TYPO3 namespace set to prevent an arbitrary admin-created
  symlink such as `public/etc -> /etc` from silently widening the
  allow-list. Target must be a directory (defense in depth for
  `public/uploads -> /etc/passwd` style misconfigurations) ([#70], [#76]).

### Changed

- CI: removed broken `slsa-provenance` job from the release workflow that
  referenced a non-existent shared workflow. SBOMs, Cosign signing, and
  build-provenance attestations are now handled end-to-end inside the
  reusable release workflow ([#78]).

[#70]: https://github.com/netresearch/t3x-nr-image-optimize/issues/70
[#76]: https://github.com/netresearch/t3x-nr-image-optimize/pull/76
[#78]: https://github.com/netresearch/t3x-nr-image-optimize/pull/78

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
