# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [2.2.2]

> **Versioning note.** 2.2.2 is tagged as a patch but contains ~196 commits
> since 2.2.1, including new user-facing features (CLI commands, upload
> listener, PSR-14 events, localization for 15 languages) and an
> `intervention/image` v3 → v4 dependency bump. The patch-level increment
> is a mis-tag; by SemVer this should have been 2.3.0. The tag is kept
> because 2.2.2 is already published on TER and GitHub and cannot be
> recalled. Consumers pinning to `^2.2` receive all the changes below.

### Added

- **CLI: `nr:image:analyze`** — heuristic analysis of stored images,
  reports per-image optimization potential without modifying files.
- **CLI: `nr:image:optimize`** — bulk optimization pass over FAL storages
  with filter/dry-run support. Both CLI commands share
  `AbstractImageCommand` for FAL iteration helpers.
- **Auto-optimize on upload** — new `OptimizeOnUploadListener` subscribes
  to `AfterFileAddedEvent` and `AfterFileReplacedEvent` and runs the
  optimizer inline as the file lands. **This changes default behaviour
  for any existing installation after upgrade**: uploaded bytes are no
  longer preserved verbatim — they are optimized on the way in. Runs in
  every context that dispatches these events (backend uploads, scheduler,
  CLI imports, etc.). To disable, override the listener registration in
  your site package's `Services.yaml`.
- **`ImageOptimizer` service** — binary-driven image optimization
  (jpegoptim, optipng, cwebp, avifenc, etc.) exposed as a reusable
  service. Honours `*_BIN` environment overrides with executability
  verification.
- **PSR-14 events** — `VariantServedEvent` and `ImageProcessedEvent`
  dispatched by the processor for post-processing hooks (CDN purging,
  metrics, audit logs).
- **Localization** — all hard-coded strings replaced with TYPO3
  LocalizationUtility lookups, translator context added, and translations
  synchronized for 15 TYPO3 community languages.
- **`type` attribute on `<source>` elements** in `SourceSetViewHelper`,
  so browsers can skip unsupported formats without fetching.
- **Backend maintenance module** — clear processed variants + system
  requirements overview (Imagick/GD/optimization binaries).
- **Configurable image driver** — `ImageManagerFactory` lets site
  configuration pick Imagick or GD instead of a hard-coded choice.
- **intervention/image v4** — added with an `ImageReaderInterface`
  adapter that keeps v3 compatible so both versions work out of the box.

### Fixed

- **Symlinked FAL storage directories** — path validation now resolves
  every configured Local FAL storage base path in addition to the TYPO3
  public root, so `fileadmin` mounted from NFS/EFS is servable while
  symlinks inside storages that escape to unrelated locations are still
  rejected. Reported as issue [#70]: the new `isPathWithinPublicRoot()`
  hardening (introduced elsewhere in this release) rejected every
  uncached variant on AWS/EFS-style deployments, because `realpath()`
  resolved the variant path through the symlink to a target outside
  the public root. Fix landed in [#71].
- **Serve image variants when `public/processed` / `public/uploads` are
  symlinked to an external mount** (AWS EFS on ECS, NFS). The fix above
  covers `fileadmin` via the FAL storage lookup, but the AWS/ECS
  post-deployment script also symlinks `public/processed` and
  `public/uploads` to the shared mount, and neither is a FAL storage.
  Variants under either directory kept returning HTTP 400 because the
  parent-walk in path validation resolved them to targets outside the
  allowed-roots set. `getAllowedRoots()` now also resolves symlinked
  `public/processed` and `public/uploads` — restricted to this hardcoded
  TYPO3 namespace set so an arbitrary admin-created symlink such as
  `public/etc -> /etc` does NOT silently widen the allow-list. Targets
  must be directories (defense in depth for `public/uploads -> /etc/passwd`
  style misconfigurations) ([#76], also resolves [#70]).
- **Allowed-roots cache keyed by public path** — functional-test
  environments and long-running workers that reinitialise
  `Environment` no longer get stale allowed-roots from a previous
  bootstrap.
- **Zero-byte variant files** — `buildFileResponse` treats them as
  "not present" and falls through the AVIF → WebP → primary fallback
  chain. Previously an Imagick build without AVIF encoder would write
  an empty `.avif`, and that empty response was served to the client in
  preference to the valid primary format.
- **Path traversal / DoS / lock-leak hardening** — URL pattern rejects
  `..` sequences, dimension/quality inputs are clamped to safe ranges,
  locks are released in a `finally` even on processing errors, and
  `StorageRepository` failures are caught so path validation degrades
  gracefully to the public root.
- **On-upload re-entrancy guard** keyed by storage UID + file identifier
  (FAL identifiers are only unique within a storage, not globally).
- **`ImageOptimizer` accepts invalid `*_BIN` env overrides as
  authoritative** but verifies executability before invoking, so a
  mistyped override fails fast instead of silently falling back to the
  default binary.

### Changed

- **PHPStan raised to level 10** with a zero-error baseline.
- **phpat architecture tests** enforce the layer constraints (middleware
  → processor → services, no reverse dependencies).
- **Mutation testing** (Infection) added with per-PR and full-suite
  configurations; the suite now reaches a 96 % mutation score.
- **Docker-based `runTests.sh`** test runner, with `Makefile` targets
  delegating to it so local and CI environments behave identically.
- **CaptainHook** pre-commit + pre-push hooks wired via composer.
- **Codecov** coverage reporting + patch target enforcement.
- **Documentation** restructured following TYPO3 docs standards, covers
  the whole extension surface (README + `Documentation/`), and renders
  on docs.typo3.org.
- **CI**: migrated to the org-wide `netresearch/typo3-ci-workflows`
  reusable workflows; consolidated per-concern caller workflows; added
  org-wide gitleaks + CodeQL (actions) scanning; removed broken
  `slsa-provenance` job that referenced a non-existent shared workflow
  (SBOMs, Cosign signing, and build-provenance attestations are now
  handled end-to-end inside the reusable release workflow, [#78]).

### Upgrading

> **Behaviour change — read before upgrading.** `OptimizeOnUploadListener`
> is registered by default and runs inline on every `AfterFileAddedEvent`
> and `AfterFileReplacedEvent`. Existing uploads will start getting
> optimized transparently. If your workflow depends on uploads being
> stored byte-for-byte as they arrived, disable the listener in your
> site package's `Services.yaml` before upgrading:
>
> ```yaml
> services:
>   Netresearch\NrImageOptimize\EventListener\OptimizeOnUploadListener:
>     tags: []
> ```

[#70]: https://github.com/netresearch/t3x-nr-image-optimize/issues/70
[#71]: https://github.com/netresearch/t3x-nr-image-optimize/pull/71
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

[Unreleased]: https://github.com/netresearch/t3x-nr-image-optimize/compare/v2.2.2...HEAD
[2.2.2]: https://github.com/netresearch/t3x-nr-image-optimize/compare/2.2.1...v2.2.2
[2.2.1]: https://github.com/netresearch/t3x-nr-image-optimize/compare/2.2.0...2.2.1
[2.2.0]: https://github.com/netresearch/t3x-nr-image-optimize/compare/2.1.0...2.2.0
[2.1.0]: https://github.com/netresearch/t3x-nr-image-optimize/compare/2.0.1...2.1.0
[2.0.1]: https://github.com/netresearch/t3x-nr-image-optimize/compare/2.0.0...2.0.1
[2.0.0]: https://github.com/netresearch/t3x-nr-image-optimize/compare/1.0.1...2.0.0
[1.0.1]: https://github.com/netresearch/t3x-nr-image-optimize/compare/1.0.0...1.0.1
[1.0.0]: https://github.com/netresearch/t3x-nr-image-optimize/compare/0.1.5...1.0.0
[0.1.5]: https://github.com/netresearch/t3x-nr-image-optimize/releases/tag/0.1.5
