# Architecture

Agent-facing component map for `netresearch/nr-image-optimize`. Verified against the tree on 2026-08-19; when this file and the code disagree, the code wins — fix this file in the same PR.

## System overview

The extension serves processed image variants (WebP/AVIF fallbacks, responsive `srcset`) without pre-generating them: a PSR-15 middleware intercepts `/processed/*` URLs in the TYPO3 frontend stack (registered `before: typo3/cms-frontend/site` in `Configuration/RequestMiddlewares.php`) and delegates to a processor that validates the source path against symlink-aware allowed roots, encodes the variant on first request via Intervention Image (Imagick or GD), caches it on disk, and streams it with HTTP caching headers.

## Components

| Component | Path | Role |
|-----------|------|------|
| Processor | `Classes/Processor.php` | Main dispatcher: parses `/processed/...` URL, allowed-roots check, lock, encode, stream, event dispatch |
| ProcessorInterface | `Classes/ProcessorInterface.php` | DI contract; `Services.yaml` aliases it to `Processor` |
| ProcessingMiddleware | `Classes/Middleware/ProcessingMiddleware.php` | PSR-15 entry point; matches `/processed/*`, delegates to `ProcessorInterface` |
| ImageManagerFactory | `Classes/Service/ImageManagerFactory.php` | Builds Intervention `ImageManager` (Imagick if available, else GD); wired as factory in `Services.yaml` |
| ImageManagerAdapter | `Classes/Service/ImageManagerAdapter.php` | Wraps `ImageManager`; `SplFileInfo`-based decode (non-ASCII-path safe); implements `ImageReaderInterface` |
| ImageReaderInterface | `Classes/Service/ImageReaderInterface.php` | DI contract; `Services.yaml` aliases it to `ImageManagerAdapter` |
| ImageOptimizer | `Classes/Service/ImageOptimizer.php` | Invokes external binaries (optipng/jpegoptim/cwebp/avifenc) via `proc_open` argument arrays |
| SystemRequirementsService | `Classes/Service/SystemRequirementsService.php` | Backend health check: binaries, PHP extensions, library versions |
| OptimizeOnUploadListener | `Classes/EventListener/OptimizeOnUploadListener.php` | Listens to FAL `AfterFileAddedEvent` / `AfterFileReplacedEvent`; re-entrancy-guarded |
| Events | `Classes/Event/ImageProcessedEvent.php`, `Classes/Event/VariantServedEvent.php` | Immutable PSR-14 DTOs, dispatched with guarded `try/catch` |
| Commands | `Classes/Command/` | `AnalyzeImagesCommand`, `OptimizeImagesCommand` on shared `AbstractImageCommand` |
| MaintenanceController | `Classes/Controller/MaintenanceController.php` | Extbase backend module (`Configuration/Backend/Modules.php`) |
| SourceSetViewHelper | `Classes/ViewHelpers/SourceSetViewHelper.php` | Fluid `srcset`/`sizes` markup generator with static `getimagesize()` cache |

## Dependency rules

Enforced by phpat (`Tests/Architecture/ArchitectureTest.php`, runs inside PHPStan via `composer ci:test:php:phpstan`):

1. `Service\*` must not depend on `Controller\*` (layer violation).
2. `Event\*` must not depend on `Service\*` or `Controller\*` (events are pure DTOs).
3. `Middleware\*` must not depend on `Controller\*`.
4. `ViewHelpers\*` must not depend on `Controller\*`.
5. `Processor` must implement `ProcessorInterface` (keeps the DI alias valid).
6. `ImageManagerAdapter` must implement `ImageReaderInterface` (keeps the DI alias valid).

## Data flow

- **Serving**: HTTP `/processed/*` request → `ProcessingMiddleware` → `ProcessorInterface` (→ `Processor`) → allowed-roots + traversal check → cached variant hit, or encode via `ImageReaderInterface` (→ `ImageManagerAdapter` → Intervention `ImageManager` from `ImageManagerFactory`) under a lock → stream response with `Cache-Control: immutable`/`ETag`/`Last-Modified` → dispatch `ImageProcessedEvent`/`VariantServedEvent` (guarded).
- **Upload**: FAL `AfterFileAddedEvent`/`AfterFileReplacedEvent` → `OptimizeOnUploadListener` → `ImageOptimizer` (external binaries; missing binaries degrade gracefully).
- **CLI**: `AnalyzeImagesCommand`/`OptimizeImagesCommand` iterate FAL storages via `AbstractImageCommand` helpers.
- **Backend**: `MaintenanceController` renders module views from `Resources/Private/Templates/Maintenance/` and reads `SystemRequirementsService`.

## Key decisions

- Format negotiation via query parameters: `docs/adr/0001-format-negotiation-via-query-params.md`.
- PHPStan level 10 with an empty baseline; path-scoped ergebnis-rule suppressions are documented inline in `Build/phpstan.neon`.
- Non-ASCII source paths are decoded via `SplFileInfo` to bypass Intervention v4's binary-content heuristic — see `CHANGELOG.md` 2.4.2.
