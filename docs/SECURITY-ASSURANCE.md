<!-- SPDX-License-Identifier: GPL-3.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->
# Security assurance

This document states what users of `netresearch/nr-image-optimize` can and cannot expect in terms of security, where the extension's trust boundaries are, and which code and tests counter the weaknesses that matter for it. It describes the code on `main`; when this file and the code disagree, the code wins and this file is corrected. Vulnerabilities are reported as described in [SECURITY.md](../SECURITY.md). The component map is in [ARCHITECTURE.md](ARCHITECTURE.md).

## What the extension does, security-wise

| Entry point | Who can reach it | Input | Code |
|-------------|------------------|-------|------|
| `/processed/*` frontend requests | Any HTTP client, without login. The middleware is registered before `typo3/cms-frontend/site`, so it runs without a site, page or frontend-user context | URL path, query parameters `skipWebP`, `skipAvif` and `sig` | `Configuration/RequestMiddlewares.php`, `Classes/Middleware/ProcessingMiddleware.php`, `Classes/Processor.php` |
| Backend module "Image Optimize" | Backend users with system maintainer rights (`'access' => 'systemMaintainer'`) | Module actions; a path or glob pattern for the invalidation action | `Configuration/Backend/Modules.php`, `Classes/Controller/MaintenanceController.php`, `Resources/Public/JavaScript/Maintenance.js` |
| FAL upload and replace | Backend users and API calls that add or replace a file | The uploaded file | `Classes/EventListener/OptimizeOnUploadListener.php`, `Classes/Service/ImageOptimizer.php` |
| Console commands `nr:image:analyze`, `nr:image:optimize` | Whoever can run the TYPO3 CLI on the host | Command options | `Classes/Command/` |
| Fluid ViewHelper `sourceSet` | Template authors (integrators) | ViewHelper arguments | `Classes/ViewHelpers/SourceSetViewHelper.php` |

The frontend endpoint reads an original image, resizes it with Intervention Image (Imagick when the PHP extension is loaded, else GD, `Classes/Service/ImageManagerFactory.php`), writes the variant and, unless disabled, a `.webp` and an `.avif` sidecar below `<public>/processed/`, and streams the result. Later requests for the same URL are answered from disk.

The upload listener and `nr:image:optimize` run `optipng`, `gifsicle` or `jpegoptim` on a local copy of the file and write it back through FAL only when it became smaller.

The extension stores no personal data, has no database tables of its own, no frontend or backend login of its own, and its own code makes no outbound network requests.

## Security expectations

Users can expect:

- A `/processed/` URL is only turned into file paths when it matches `Processor::URL_PATTERN`: a path without `..`, a mode segment made of `w`, `h`, `q`, `m` and digits, and one of the extensions in `Processor::EXTENSION_MIME_MAP` (`jpg`, `jpeg`, `png`, `gif`, `webp`, `avif`, `bmp`, `tif`, `tiff`, any case). The path is URL-decoded before the match. Anything else is answered with HTTP 400 (`Processor::gatherInformationBasedOnUrl()`).
- Both the original path and the variant path are resolved with `realpath()` and must lie inside an allowed root before the extension reads, writes or serves a file, including a variant already on disk (`Processor::isPathWithinAllowedRoots()`, `getAllowedRoots()`). The allowed roots are the public directory, the TYPO3 var directory, the base path of every public Local FAL storage, symlinked `processed`, `uploads` and `_assets/*` children of the public directory, and the two opt-in settings described below. Paths containing a NUL byte are rejected.
- A source file inside the base path of a non-public Local FAL storage (`sys_file_storage.is_public = 0`) is neither processed nor served, also when the storage directory lies inside the public directory, when the file is reached through a symlink, and when a variant of it is already on disk; the answer is HTTP 404 whether or not the file exists (`Processor::isInNonPublicStorage()`), or HTTP 400 when the resolved path lies outside every allowed root, as for a non-public storage outside the public directory (non-public storages are not allowed roots). When the FAL storages cannot be read, the request is answered with HTTP 503 instead (`Processor::hasStorageLookupFailed()`).
- A variant that is not on disk yet is created only when the URL carries a `sig` query parameter that is valid for its URL-decoded path; otherwise the answer is HTTP 403 before any lock is taken or file is read (`Processor::hasValidSignature()`). The signature is an HMAC-SHA1 (TYPO3 `HashService`) keyed with the installation's `encryptionKey` plus a purpose-specific secret (`Classes/Service/VariantUrlSigner.php`); without an `encryptionKey` no signature is issued or accepted. The ViewHelper signs every URL it renders. A variant already on disk is served without a signature.
- Only files whose content is a JPEG, PNG, GIF, WebP, AVIF, BMP or TIFF image, as identified by `getimagesize()` from the header bytes, are handed to the image library; anything else is answered with HTTP 400 (`ImageManagerAdapter::read()`, `UnsupportedImageTypeException`).
- Width and height taken from the URL are clamped to 1 to 8192 pixels, also after the missing side is derived from the aspect ratio, and quality to 1 to 100 (`Processor::clampDimension()`, `clampQuality()`). AVIF output is encoded with quality 99 at most.
- Animated GIFs are detected by a bounded byte scan and copied unchanged instead of being decoded (`Processor::isAnimatedGif()`, `passThroughOriginal()`).
- Error responses carry a status code only (400, 403, 404, 500, 503; the 503 after the lock attempts run out has a fixed text, the others an empty body). Details go to the TYPO3 log.
- External tools are started through Symfony Process with an argument array, never through a shell, only for files with the extension `png`, `gif`, `jpg` or `jpeg`, on the local temporary copy that FAL provides, with a timeout of 600 seconds (`ImageOptimizer::optimize()`, `analyze()`). The system requirements check hands only its own constant tool names to PHP's `shell_exec`, each quoted with `escapeshellarg` (`SystemRequirementsService::CLI_TOOLS`, `checkBinaryAvailability()`).
- The ViewHelper escapes every attribute name and value it renders with `htmlspecialchars(..., ENT_QUOTES | ENT_HTML5)` (`SourceSetViewHelper::tag()`), does not build a `/processed/` URL from a path that contains `..`, and signs every `/processed/` URL it builds (`getResourcePath()`).
- The backend module deletes files only inside `<public>/processed`, and only after that directory resolves to a directory named `processed`. The invalidation pattern is compared as a string against the files found there; it is never used as a path (`MaintenanceController::clearProcessedImagesAction()`, `invalidateProcessedVariants()`). The module's JavaScript writes server data into the page with `textContent` only.

Users cannot expect:

- Access control beyond storage publicity. The `/processed/` endpoint has no authentication and does not check frontend-user groups. It refuses files of non-public FAL storages, but a file below the public directory that belongs to no storage, or a file in a public storage that only a web-server rule protects, can be served as a variant. Variants on disk are static files the web server usually delivers without TYPO3. The ViewHelper routes only plain public paths through `/processed/`; absolute URLs, `data:` URIs and URLs with a query string, such as the tokenized download URLs of non-public storages, are rendered unchanged (`SourceSetViewHelper::isPassthroughUrl()`).
- That a URL written by hand keeps working. A `/processed/` URL without a valid `sig` parameter can only fetch a variant that is already on disk; changing the `encryptionKey` invalidates all signatures, so pages have to be rendered again (flush the page cache).
- That a variant disappears when its original is replaced or deleted. Variants are served with `Cache-Control: public, max-age=31536000, immutable` and stay on disk until they are removed with the backend module (clear or invalidate).
- Isolation from the image libraries. Decoding and encoding run in the PHP process through Imagick or GD; their security depends on the installed library versions and, for Imagick, on the host's ImageMagick policy.
- Protection against a misconfigured installation. The extension trusts its extension configuration and the process environment (see the trust boundaries below).
- Support for non-POSIX filesystems; the path checks assume `/` as separator.

## Threat model and trust boundaries

| Boundary | Untrusted input | Control |
|----------|-----------------|---------|
| Internet client → `/processed/*` | URL path and query string | URL pattern with extension allow-list, NUL-byte rejection, `realpath()` check of original and variant path against the allowed roots, refusal of files in non-public storages, signature required to create a variant, dimension and quality clamps, one lock per URL (`LockFactory`, at most ten attempts, then 503) |
| Original file → image decoder | File content | Content-type allow-list before decoding (`ImageManagerAdapter::read()`); decoding by Intervention Image; exceptions end in a 500 without details; animated GIFs are not decoded |
| Uploaded file → external tools | File content and name | Extension allow-list, FAL temporary copy as the only path argument, argument array without shell, timeout, write-back only when smaller |
| Backend user → maintenance module | Invalidation pattern | Module access `systemMaintainer`, string match against enumerated files, `basename(realpath(...)) === 'processed'` check |
| Template author → ViewHelper | Arguments such as `path`, `alt`, `attributes` | Escaping in `tag()`, `..` check in `getResourcePath()`; URLs the template renders are signed, so they define which variants can be created |
| Integrator → extension | Extension configuration (`additionalTrustedStorageSymlinks`, `additionalTrustedRoots`, qualities, sidecar switches), environment variables `OPTIPNG_BIN`, `GIFSICLE_BIN`, `JPEGOPTIM_BIN` | Trusted. Both trusted-path settings are empty by default, `additionalTrustedRoots` accepts only absolute paths to existing directories, and a `*_BIN` override must name an executable file or the tool counts as missing (`ImageOptimizer::resolveBinary()`) |

## Secure design principles applied

- **Fail-safe defaults.** A URL that does not match the pattern, an empty list of allowed roots, and a path that cannot be resolved are all rejected (`isPathWithinAllowedRoots()` returns `false`). When the FAL storage lookup fails, it is unknown which directories belong to a non-public storage, so the request is refused with 503; the reduced list of roots is not cached across requests. A variant URL without a valid signature does not create a variant, and without an `encryptionKey` no signature is valid. The two trusted-path settings are empty by default.
- **Complete mediation.** The allowed-roots check and the non-public-storage check run on every request, before the cached-variant lookup, so a file already on disk is not served without them.
- **Defence in depth.** Path traversal is blocked twice: by the `..` exclusion in the URL pattern and by the `realpath()` comparison, which also catches symlinks that point out of the allowed roots.
- **Least privilege and small attack surface.** The middleware handles only paths that start with `/processed/` and hands every other request on unchanged. Symlink expansion in the public directory is limited to `processed`, `uploads` and the children of `_assets`, so an unrelated symlink such as `public/etc -> /etc` does not widen the allowed roots. `resolveSymlinkedDirectory()` accepts only directories, so a symlinked file cannot become a root.
- **Separation of concerns.** The middleware depends on `ProcessorInterface`, not on `Processor`; the phpat rules in `Tests/Architecture/ArchitectureTest.php` keep services, events, middleware and ViewHelpers from depending on the controller layer.
- **Robustness against extensions.** Listeners for `ImageProcessedEvent` and `VariantServedEvent` are called inside `try`/`catch`, so a failing listener cannot break image delivery.

## Countering common weaknesses

| Weakness (CWE / OWASP) | Counter | Evidence |
|------------------------|---------|----------|
| Path traversal (CWE-22, A01:2021) | URL pattern without `..`, `realpath()` against the allowed roots, `..` check in the ViewHelper | `Tests/Unit/ProcessorTest.php` (`gatherInformationBasedOnUrlReturnsNullForNonMatchingUrls`, `generateAndSendReturns400WhenPathEscapesPublicRoot`, `isPathWithinAllowedRootsRequiresDirectorySeparatorInPrefix`), `Tests/Unit/ViewHelpers/SourceSetViewHelperTest.php` (`getResourcePathRejectsPathTraversal`) |
| Link following (CWE-59) | Symlinks resolved before the root comparison; only known public children expanded; only directories accepted | `Tests/Unit/ProcessorTest.php` (`isPathWithinAllowedRootsRejectsSymlinkEscapingAllowedRoots`, `isPathWithinAllowedRootsOnlyExpandsKnownPublicChildren`, `isPathWithinAllowedRootsRejectsTraversalThroughSymlinkedPublicChildren`), `Tests/Functional/ProcessorSymlinkedFileadminTest.php` |
| Improper access control (CWE-284) for files of non-public storages | Storage publicity checked on the resolved source path before the cache lookup and before processing | `Tests/Functional/ProcessorAccessTest.php` (`variantOfFileInNonPublicStorageIsNotCreated`, `existingVariantOfFileInNonPublicStorageIsNotServed`), `Tests/Unit/ProcessorTest.php` (`variantOfFileInNonPublicStorageIsRefusedEvenWhenCached`, `fileReachedThroughSymlinkIntoNonPublicStorageIsRefused`, `requestIsRefusedWhenStoragesCannotBeRead`) |
| NUL byte in path (CWE-158) | Rejected before any filesystem call | `isPathWithinAllowedRootsRejectsPathsWithNullByte` |
| OS command injection (CWE-78) | Argument arrays through Symfony Process; constant tool names quoted with `escapeshellarg` in the requirements check | `Classes/Service/ImageOptimizer.php`, `Classes/Service/SystemRequirementsService.php`, `Tests/Unit/Service/ImageOptimizerTest.php` |
| Cross-site scripting (CWE-79, A03:2021) | Attribute escaping in the ViewHelper; `textContent` in the module JavaScript | `SourceSetViewHelperTest` (`renderEscapesHtmlInAltAndTitle`, `tagEscapesSingleQuotesIn*`), `Tests/Functional/ViewHelpers/SourceSetViewHelperTest.php` |
| SQL injection (CWE-89) | The commands query `sys_file` through the QueryBuilder with named parameters | `Classes/Command/AbstractImageCommand.php` |
| Improper input validation (CWE-20) | Single-pass parsing of the mode string into integers, clamping, fuzzing of the URL and mode parsers with random input | `Tests/Fuzz/ProcessorFuzzTest.php`, run on every pull request |
| Resource consumption of a single request (CWE-400) | Dimensions of one variant bounded to 8192 × 8192; concurrent requests for the same URL serialised by a lock; animated GIFs not decoded | `ProcessorTest` (`gatherInformationClampsDimensionsToMaximum`, `clampDimensionClampsToRange`, `clampQualityClampsToRange`) |
| Allocation of resources without limits (CWE-770) | Only signed URLs create variants, so the set of variants is the set the site's templates render | `Tests/Functional/ProcessorAccessTest.php` (`variantRequestWithoutSignatureIsRefused`, `variantRequestWithSignatureOfAnotherVariantIsRefused`), `Tests/Unit/Service/VariantUrlSignerTest.php`, `Tests/Functional/ViewHelpers/SourceSetViewHelperTest.php` (`renderedVariantUrlIsAcceptedByTheProcessor`) |
| Processing of unexpected file types (CWE-20) | Extension allow-list in the URL pattern; content-type allow-list before decoding | `Tests/Unit/Service/ImageManagerAdapterTest.php` (`readRefusesFileWhoseContentIsNotASupportedImage`), `Tests/Functional/ProcessorAccessTest.php` (`sourceThatIsNotAnImageIsNotProcessed`, `sourceWithUnsupportedExtensionIsNotProcessed`) |
| Information exposure through error messages (CWE-209) | Status codes without details; details only in the log | `Classes/Processor.php` (`generateAndSend()`) |
| Unintended deletion (CWE-73) | Deletion limited to files enumerated inside the resolved `processed` directory | `Tests/Unit/Controller/MaintenanceControllerTest.php`, `Tests/Functional/Controller/MaintenanceControllerTest.php` (`invalidatePathActionRejectsUnexpectedSymlinkTarget`) |
| Hard-coded credentials (CWE-798) | None in the code; Betterleaks scans every pull request | `.github/workflows/checks.yml` |
| Vulnerable and outdated components (A06:2021) | Composer Audit and Dependency Review on every pull request; Renovate update pull requests | `.github/workflows/checks.yml`, `renovate.json` |

## Verification

The tests named above run in CI on every pull request (`.github/workflows/ci.yml`, `.github/workflows/checks.yml`). PHPStan runs at level 10 with an empty baseline, and Opengrep fails a pull request on the findings the organisation's [static analysis rule](https://github.com/netresearch/.github/blob/main/SECURITY.md#static-analysis-sast) makes blocking. The full list of pull-request checks is in [CONTRIBUTING.md](../CONTRIBUTING.md#governance-and-policies). Locally:

```bash
composer ci:test:php:unit
composer ci:test:php:functional
composer ci:test:php:fuzz
composer ci:test:php:phpstan
```
