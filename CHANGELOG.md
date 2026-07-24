# 1.3.0

## FEATURE

- **`additionalTrustedRoots` extension configuration, and TYPO3's `var/`
  directory trusted automatically.** Per-instance, opt-in, comma-separated
  list of absolute filesystem paths, outside FAL and TYPO3-internal
  locations, that are realpath-resolved and added to the path-validation
  allow-list -- for locations not covered by FAL storage or the built-in
  TYPO3 paths (e.g. a custom mount reached via a symlink under the public
  webroot). `var/` (a sibling of `public/` in composer-mode installs, where
  TYPO3's own processing mechanisms generate transient/cache assets) is now
  trusted without any configuration. Port of the main-branch feature
  released in 2.4.0.
  See [#135](https://github.com/netresearch/t3x-nr-image-optimize/pull/135).

- **Configurable `qualityWebp`/`qualityAvif` extension configuration.**
  Previously both sidecar formats were encoded at the same numeric quality as
  the primary image; AVIF's steeper quality scale meant AVIF variants often
  came out larger than WebP at the same setting, so format negotiation ended
  up serving the biggest file instead of the smallest. New `qualityWebp`
  (default 75) and `qualityAvif` (default 60) settings let each format be
  tuned independently. Port of the main-branch feature released in 2.4.0.
  See [#134](https://github.com/netresearch/t3x-nr-image-optimize/pull/134).

## BUGFIX

- **Clearing processed images failed on symlinked "processed" deployments.**
  The Maintenance module's "clear processed images" action validated
  `processed` with `realpath()`-equality against the public path, which fails
  whenever `processed` is a symlink to a shared volume (a common
  Deployer/CI deployment layout) -- clearing failed on every symlinked
  deployment. The directory is now emptied in place (children removed,
  directory/symlink itself preserved) instead of being removed and
  recreated. Port of the main-branch fix released in 2.4.0.
  See [#133](https://github.com/netresearch/t3x-nr-image-optimize/pull/133).

## Contributors

- Axel Seemann
- Gitsko

# 1.2.0

## FEATURE

- **`additionalTrustedStorageSymlinks` extension configuration.** Per-instance,
  opt-in, comma-separated list of directory names that, when found as a
  symlink directly inside a Local FAL storage's own base path (e.g.
  `fileadmin/_processed_`), are resolved and added to the path-validation
  allow-list. Closes the gap where deployments relocate TYPO3 core's own
  `_processed_` image cache onto local/ephemeral storage to keep it off
  shared/NFS storage, leaving a symlink behind that the FAL-storage basePath
  lookup cannot see. Default empty; keeps today's behaviour for every
  installation that doesn't opt in.
  See [#124](https://github.com/netresearch/t3x-nr-image-optimize/pull/124).

## BUGFIX

- **Images published via `public/_assets/<hash>` symlinks (extension
  `Resources/Public/` assets) were rejected with HTTP 400.** TYPO3 core
  publishes each extension's `Resources/Public/` directory by symlinking
  `public/_assets/<hash>/` to a location outside the public webroot.
  `getAllowedRoots()` did not resolve these symlinks, so variant requests for
  e.g. an extension's default/fallback image failed even though the file is a
  legitimate part of the deployed application. Every immediate child of
  `_assets` is now resolved individually.
  See [#119](https://github.com/netresearch/t3x-nr-image-optimize/pull/119).

## Contributors

- Axel Seemann

# 1.1.3

## BUGFIX

- **SourceSetViewHelper: non-processable URLs are passed through unchanged.**
  Absolute URLs (`http://`, `https://`, `//`), `data:` URIs, and URLs
  carrying a query string — e.g. the tokenized `eID=dumpFile` URLs
  fal_securedownload generates for files in non-public FAL storages — are
  now rendered as a plain `<img>` tag (no `srcset`/`sizes`, no `<source>`
  elements). Previously `getResourcePath()` mangled them into broken
  `/processed/https:...` variant paths, and routing them through the
  `/processed/` pipeline would have bypassed their permission check
  (variants are written as static files below the public web root). The
  access control of the generating extension stays intact. Port of the
  main-branch fix released in 2.2.4.
  See [#111](https://github.com/netresearch/t3x-nr-image-optimize/pull/111).

## Contributors

- Axel Seemann

# 1.1.2

## BUGFIX

- **#70 follow-up diagnosability & cache robustness.** The 1.1.0/1.1.1 fixes
  silently returned HTTP 400 to clients when path validation rejected a
  request, leaving admins no signal to diagnose from. `Processor::generateAndSend()`
  now logs both 400 branches via `error_log()` so the rejection reason
  reaches the SAPI error log: the URL-pattern-mismatch path with a short
  one-line message (scanners hit this constantly, so a longer dump would
  drown out genuine issues) and the path-outside-allowed-roots path with
  full diagnostic context (url, pathOriginal, pathVariant, which check
  failed, allowedRoots, publicPath). Both lines are written at the same
  SAPI level — `error_log()` does not expose distinct severity tiers; the
  two paths are distinguishable by message content, not log level.
- **Cache poisoning on transient `StorageRepository` failure.** Previously a
  single `findAll()` throw during early TYPO3 bootstrap (TCA not yet loaded,
  DB hiccup, cache rebuild) populated the per-process allowed-roots cache
  with a degraded fallback (public root only, without FAL storages), and
  every subsequent storage-backed variant request in the same PHP-FPM
  worker returned 400 until the worker recycled. The degraded fallback is
  now kept only for the current request; the next request retries the
  lookup, so a transient failure no longer sticks.
- **Redundant work + log floods on a single request.** `getAllowedRoots()`
  was invoked three times per failing request (once per `pathOriginal`,
  `pathVariant`, and for the log context), each retry re-invoking
  `findAll()` and re-emitting the unavailable-log line. Added per-request
  memoization so the lookup runs at most once per request.
- **Filesystem-root as public path.** When `Environment::getPublicPath()`
  returns `/` (minimal container setups), the prefix check compared against
  literal `//` which no real absolute path starts with, rejecting every
  valid path. Detect this case and accept any absolute path while still
  rejecting relative paths.
  See [#88](https://github.com/netresearch/t3x-nr-image-optimize/pull/88).

## TESTS

- New functional test `ProcessorSymlinkedFileadminTest` reproduces the
  exact Chemnitz AWS/ECS + EFS production layout (all three of
  `public/fileadmin`, `public/processed`, `public/uploads` symlinked to
  `/mnt/efs/cms/...`) against the real DI container and FAL LocalDriver.
  Empirically verified to fail when either the #70 core or the #76
  follow-up fix is disabled, so this closes the end-to-end loop the prior
  unit tests missed.
- New unit regression
  `isPathWithinAllowedRootsDoesNotCacheDegradedFallbackOnStorageThrow`
  covers the bootstrap-race cache-poisoning semantics described above.

## CI

- Coverage driver switched from pcov to xdebug via
  `coverage-tool: xdebug` on the reusable `netresearch/typo3-ci-workflows`
  ci.yml. Matches the local dev driver (`XDEBUG_MODE=coverage`) and gives
  branch + path coverage instead of pcov's line-only signal. ~2-3 min
  extra runtime across the matrix.
- Functional tests now also run in CI (previously skipped because
  `run-functional-tests` defaulted to false; now enabled together with
  `imagick` + `gd` PHP extensions required by the Intervention\Image
  driver).
- `Build/Scripts/runTests.sh` builds a thin derived Docker image
  (`nr-image-optimize-testing-php${PHP_VERSION}`) that adds Imagick
  on top of the upstream `ghcr.io/typo3/core-testing-*` image via
  `pecl install imagick` + `docker-php-ext-enable`, so functional tests
  run locally too without CI-only workarounds.

## Contributors

- Sebastian Mendel

# 1.1.1

## BUGFIX

- Serve image variants when `public/processed` and/or `public/uploads` are
  symlinked to an external mount (e.g. AWS EFS on ECS via the container's
  post-deployment script). The symlink fix released in 1.1.0 only covered
  `fileadmin` (resolved via FAL storage lookup); variants under the other
  two directories still returned HTTP 400 for every uncached request
  because the parent-walk in path validation resolved them to targets
  outside the allowed-roots set. `getAllowedRoots()` now also resolves
  symlinked `public/processed` and `public/uploads` — restricted to this
  hardcoded TYPO3 namespace set to prevent an arbitrary admin-created
  symlink such as `public/etc -> /etc` from silently widening the
  allow-list. Target must be a directory (defense in depth for
  `public/uploads -> /etc/passwd` style misconfigurations).
  See [#70](https://github.com/netresearch/t3x-nr-image-optimize/issues/70),
  [#77](https://github.com/netresearch/t3x-nr-image-optimize/pull/77).

## Contributors

- Sebastian Mendel

# 1.1.0

## MISC

- 7d13283 fix: backport comprehensive quality review (PR #52) to TYPO3 12 (#54)
- 016e7f2 NEXT-95: fix version constants in SystemRequirementsService for TYPO3 12
- a30015a NEXT-95: fix code quality issues and CS violations
- 58192b7 NEXT-95: fix review comments for TYPO3 12 backport
- 50d1ebc fix: comprehensive quality review with 29 agent passes (#52)

## Contributors

- Axel Seemann
- Sebastian Mendel
- axel.seemann@netresearch.de

# 1.0.3

## BUGFIX

- 152c6ce OPSCHEM-347: [BUGFIX] Fix TypeError in Processor::getValueFromMode() for non-matching URLs

## Contributors

- Rico Sonntag

# 1.0.2

## MISC

- 6b72dc3 OPSCHEM-347: [Fix] Fix nullable dirname access in SourceSetViewHelper

## Contributors

- axel.seemann@netresearch.de

# 1.0.1

## TASK

- 31872bd [TASK] Add ext_emconf file

## Contributors

- Gitsko

# 1.0.0

## TASK

- dadff15 [TASK] Add github workflows, fix php linter errors
- 5bd1c28 [TASK] Add pipeline checks fot github actions

## MISC

- bd3dbfa [Fix] Fix tailor pipeline to user version w/o v in version
- 31d1121 [Fix] Fix pipline check for php versions

## Contributors

- Gitsko

# 0.1.5

## MISC

- 6a2678e Fix "strtolower(): Argument #1 ($string) must be of type string, null given"
- 41a6905 Allow possible file extensions include numbers
- ba1e265 Fix "Trying to access array offset on value of type bool"
- ca6a25f chore(deps): update dependency ssch/typo3-rector to v3
- 1373828 fix(deps): update dependency intervention/image to 3.7.2 || 3.11.1
- a025c2d Add renovate.json
- a60b13b Add missing extension icon
- 38723a4 CHEM-422: Correct examples for cropVariants in basic installations
- dc20ea3 CHEM-288: Change lazyload behaviour. Iptimize readme. Add additional attribute attribute.
- 9b61f07 Update README.rst. Fix Codeblock in example.
- 92acca4 OPSFX-259: Disable on the fly image optimization via optipng etc. due to massive performance issues.
- d73b4a8 Update readme regaridng usage of the viewhelper
- aadeb33 FX-864: Describe the render modes in readme.
- ddc0dab FX-864: Implement Middleware ans Processing Logic
- bbdeab9 FX-864: Fix extension key in composer.json
- 322ef79 Fix codestyle.
- 476536d FX-864: Fix code sytle.
- b64fa1e FX-864: Fix ci pipeline
- 10d78e8 FX-864: Build base for developing the extension.
- e80213c FX-864: Add Processor class.
- b78d36a FX-864: Add basic extenison files.
- 28b0863 Fix package name.
- 2988b23 Initial Commit

## Contributors

- Axel Seemann
- Renovate Bot
- Renovate Bot
- Rico Sonntag
- Sebastian Koschel

