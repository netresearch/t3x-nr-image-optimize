# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Backend maintenance module: view statistics, check system requirements, clear processed images.
- `SystemRequirementsService` for PHP extension/binary checks.
- 15 additional language localizations (cs, da, de, es, fi, fr, it, ja, nl, pl, pt, ru, sv, uk, zh).
- Responsive width-based `srcset` generation as opt-in feature (`responsiveSrcset` parameter).
- `widthVariants` parameter for custom breakpoints.
- `sizes` parameter for responsive image sizing.
- `fetchpriority` attribute for resource hints.
- Fuzz tests for the Processor.

### Fixed

- Path traversal hardening in `Processor` and `SourceSetViewHelper`.
- XSS fix: all HTML attribute values are now properly escaped with `htmlspecialchars()`.
- DoS prevention: dimensions clamped to 1–8192, quality to 1–100.
- Lock leak prevention: try/finally blocks around all lock-protected code.
- `file_get_contents` failure handling.
- `disable_functions` check now correctly tests `shell_exec`.
- Removed `@` error suppression on `shell_exec` calls.
- TOCTOU mitigation in `clearProcessedImagesAction`.
- Nullable dirname access in `SourceSetViewHelper`.

### Changed

- PSR-17 response/stream factory injection in `Processor` (replaces raw `header()`/`exit()` output).
- HTTP caching headers added: `Cache-Control: public, max-age=31536000, immutable`, `ETag`, `Last-Modified`.
- Existing-file short-circuit: already-processed images served directly without re-processing.
- Single-pass mode string parsing (was 4×), query params parsed once (was 2×).
- Static `getimagesize()` cache in `SourceSetViewHelper`.
- PSR-14 event dispatch for `ImageProcessedEvent` and `VariantServedEvent`.
- `processImage()` and `calculateTargetDimensions()` now return values instead of mutating state.
- Replaced undeclared `GuzzleHttp\Psr7\Query` dependency with native `parse_str`.
- 33+ new unit tests.

## [1.0.2]

### Fixed

- Fix nullable dirname access in SourceSetViewHelper (OPSCHEM-347).

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

[Unreleased]: https://github.com/netresearch/t3x-nr-image-optimize/compare/1.0.2...v12-4-x
[1.0.2]: https://github.com/netresearch/t3x-nr-image-optimize/compare/1.0.1...1.0.2
[1.0.1]: https://github.com/netresearch/t3x-nr-image-optimize/compare/1.0.0...1.0.1
[1.0.0]: https://github.com/netresearch/t3x-nr-image-optimize/compare/0.1.5...1.0.0
[0.1.5]: https://github.com/netresearch/t3x-nr-image-optimize/releases/tag/0.1.5
