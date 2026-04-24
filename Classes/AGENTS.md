<!-- Managed by agent: keep sections and order; edit content, not structure. Last updated: 2026-04-24 -->

# AGENTS.md — Classes

<!-- AGENTS-GENERATED:START overview -->
## Overview
PHP source of the `nr_image_optimize` extension. Namespace `Netresearch\NrImageOptimize\` (PSR-4 from `Classes/`). TYPO3 CGL + PSR-12. PHPStan level 10.
<!-- AGENTS-GENERATED:END overview -->

<!-- AGENTS-GENERATED:START filemap -->
## Key Files
| File | Purpose |
|------|---------|
| `Classes/Processor.php` | Main dispatcher: parses `/processed/...` URL, checks allowed roots (symlink-aware), acquires lock, encodes/streams variant, dispatches `ImageProcessedEvent`/`VariantServedEvent`. |
| `Classes/ProcessorInterface.php` | DI contract for `Processor`. |
| `Classes/Middleware/ProcessingMiddleware.php` | PSR-15 middleware; delegates matching requests to `Processor`. Registered in `Configuration/RequestMiddlewares.php`. |
| `Classes/Controller/MaintenanceController.php` | TYPO3 backend module (Extbase `ActionController`). Uses `$GLOBALS['TYPO3_REQUEST']` for BE context. |
| `Classes/ViewHelpers/SourceSetViewHelper.php` | Fluid ViewHelper generating `srcset`/`sizes` markup; uses static `getimagesize()` cache keyed on resolved public path. |
| `Classes/Command/AbstractImageCommand.php` | Shared template method for image-scan commands. Final helpers (`parseStorageUidsOption`, `getIntOption`, `iterateViaIndex`, etc.). |
| `Classes/Command/AnalyzeImagesCommand.php`, `OptimizeImagesCommand.php` | Symfony console commands (auto-discovered via `Configuration/Services.yaml`). |
| `Classes/Service/ImageManagerFactory.php` | Auto-selects Imagick, falls back to GD. `final readonly class`. |
| `Classes/Service/ImageManagerAdapter.php` | Thin wrapper over Intervention `ImageManager` with a dynamic `read()`/`decode()` dispatch (v3 vs v4 compat). |
| `Classes/Service/ImageOptimizer.php` | External binary invocation (optipng/jpegoptim/cwebp/avifenc). Uses `@` on `filesize`/`unlink`/`getimagesize` by design — see `Build/phpstan.neon` path scope. |
| `Classes/Service/SystemRequirementsService.php` | Backend-module health check: binary availability, PHP extensions, composer-lock version detection. |
| `Classes/Event/ImageProcessedEvent.php`, `VariantServedEvent.php` | Immutable DTOs, `final readonly class`, constructor-only setters, named-argument callsites. |
| `Classes/EventListener/OptimizeOnUploadListener.php` | Auto-optimize on FAL upload; re-entrancy-guarded by `$storage.uid + file.identifier`. |
<!-- AGENTS-GENERATED:END filemap -->

<!-- AGENTS-GENERATED:START golden-samples -->
## Golden Samples (follow these patterns)
| Pattern | Reference |
|---------|-----------|
| New PSR-15 middleware | `Classes/Middleware/ProcessingMiddleware.php` (delegate, don't do work) |
| New service (auto-wired DI) | `Classes/Service/ImageManagerFactory.php` (`final readonly`, constructor-inject) |
| New event (immutable DTO) | `Classes/Event/VariantServedEvent.php` (`final readonly class`, named-arg ctor) |
| New Symfony command | `Classes/Command/AbstractImageCommand.php` + `AnalyzeImagesCommand.php` (extend abstract, register in `Services.yaml`) |
| New ViewHelper | `Classes/ViewHelpers/SourceSetViewHelper.php` (declare args via `initializeArguments()`, use static cache for expensive I/O) |
<!-- AGENTS-GENERATED:END golden-samples -->

<!-- AGENTS-GENERATED:START setup -->
## Setup & environment
- Install: `composer install` (uses `.build/vendor/` per `composer.json` config).
- PHP: >=8.2; TYPO3: `^13.4 || ^14.0` on `main`, `^12.4` on `TYPO3_12` branch.
- PHP extension: Intervention Image uses Imagick if available, else GD — both supported via `Classes/Service/ImageManagerFactory`.
- External binaries (optional, for `ImageOptimizer`): `optipng`, `jpegoptim`, `cwebp`, `avifenc`. Missing binaries degrade gracefully.
- No `ddev`/Docker wrapper — use `composer` directly or the `make` targets (which invoke `Build/Scripts/runTests.sh` for CI parity).
<!-- AGENTS-GENERATED:END setup -->

<!-- AGENTS-GENERATED:START structure -->
## Directory structure (this repo, verified)
```
Classes/                        → Netresearch\NrImageOptimize\ (PSR-4)
  Command/                      → Symfony console commands
  Controller/                   → MaintenanceController (backend module)
  Event/                        → ImageProcessedEvent, VariantServedEvent
  EventListener/                → OptimizeOnUploadListener
  Middleware/                   → ProcessingMiddleware (PSR-15)
  Service/                      → ImageManagerFactory, ImageManagerAdapter,
                                   ImageOptimizer, ImageReaderInterface,
                                   SystemRequirementsService
  ViewHelpers/                  → SourceSetViewHelper (Fluid)
  Processor.php                 → Main dispatcher
  ProcessorInterface.php        → DI contract
```

This extension has **no** `Classes/Domain/` (no Extbase models), **no** `Configuration/TCA/`, **no** `Configuration/TypoScript/`, **no** `Configuration/FlexForms/`. Don't invent the standard-TYPO3-extension layout here.
<!-- AGENTS-GENERATED:END structure -->

<!-- AGENTS-GENERATED:START commands -->
## Build & tests (verified against `composer.json`)
| Task | Command |
|------|---------|
| PHP lint | `composer ci:test:php:lint` |
| PHP-CS-Fixer check | `composer ci:test:php:cgl` |
| PHP-CS-Fixer fix | `composer ci:cgl` |
| PHPStan (level 10) | `composer ci:test:php:phpstan` |
| Rector (dry-run) | `composer ci:test:php:rector` |
| Fractor (dry-run) | `composer ci:test:php:fractor` |
| Unit tests | `composer ci:test:php:unit` |
| Functional tests | `composer ci:test:php:functional` |
| Acceptance tests | `composer ci:test:php:acceptance` |
| Fuzz tests | `composer ci:test:php:fuzz` |
| Full CI bundle | `composer ci:test` (lint + phpstan + rector + fractor + unit + cgl) |

`make` targets in the root (e.g. `make phpstan`, `make test-unit`) invoke `Build/Scripts/runTests.sh` inside a Docker PHP image — useful when host PHP differs from CI or when Imagick isn't installed locally.
<!-- AGENTS-GENERATED:END commands -->

<!-- AGENTS-GENERATED:START code-style -->
## Code style & conventions
- **PSR-12** + TYPO3 CGL, enforced by `composer ci:test:php:cgl` (`Build/.php-cs-fixer.dist.php`).
- `declare(strict_types=1);` in every PHP file (first non-comment line).
- Namespace: `Netresearch\NrImageOptimize\` (PSR-4 from `Classes/`). Case-sensitive — note `NrImageOptimize`, not `nr_image_optimize`.
- DI via `Configuration/Services.yaml` (auto-wire), not `GeneralUtility::makeInstance()`.
- `final` classes by default (enforced by ergebnis PHPStan rules). Mark abstract base classes abstract; mark helpers `final`.
- Named-argument constructor calls are idiomatic here (see `ergebnis.noNamedArgument` is suppressed intentionally in `Build/phpstan.neon`).
- `array_key_exists()` preferred over `isset()` in production code (see `ergebnis.noIsset` scope). Tests still use `isset()` for fixture-setup terseness.
- `@` error-suppression is allowed only in the 4 files listed in `Build/phpstan.neon` under `ergebnis.noErrorSuppression` — anywhere else it flags.

### Naming
| Type | Convention | Example |
|------|------------|---------|
| Extension key | `nr_image_optimize` | — |
| Composer name | `netresearch/nr-image-optimize` | — |
| Namespace root | `Netresearch\NrImageOptimize\` | `Netresearch\NrImageOptimize\Service\ImageOptimizer` |
| Controller | `*Controller` | `MaintenanceController` |
| ViewHelper | `*ViewHelper` | `SourceSetViewHelper` |
| Event | `*Event` | `VariantServedEvent` |
| Command | `*Command` | `OptimizeImagesCommand` |
<!-- AGENTS-GENERATED:END code-style -->

<!-- AGENTS-GENERATED:START security -->
## Security & safety (specific to this extension)
- **Path validation**: `Processor::getAllowedRoots()` is symlink-aware (`realpath()` all storage roots) — any code touching file paths in the request path must go through the allowed-roots check. See the symlink regression tests in `Tests/Functional/ProcessorSymlinkedFileadminTest.php` before changing.
- **FAL only**: use `StorageRepository` / `ResourceStorage::getFile()` — no direct `file_get_contents($requestPath)`.
- **External binaries**: `ImageOptimizer` invokes optipng/jpegoptim/cwebp/avifenc via `proc_open` with argument arrays (not shell strings) — keep it that way. Never concatenate user input into a command string.
- **nosemgrep tags**: the three `@unlink()` finally-block cleanups in `Classes/Service/ImageOptimizer.php` carry `// nosemgrep: php.lang.security.unlink-use.unlink-use` — only suppress with this exact pattern after verifying the path originates from `getForLocalProcessing(true)`.
- **Backend access**: `MaintenanceController` relies on TYPO3 backend-user session; no additional check needed for routed actions. For ad-hoc access, use `$GLOBALS['BE_USER']->check()`.
<!-- AGENTS-GENERATED:END security -->

<!-- AGENTS-GENERATED:START checklist -->
## PR/commit checklist
- [ ] `composer ci:test` passes locally (lint + phpstan + rector + fractor + unit + cgl).
- [ ] PHPStan clean without adding to `Build/phpstan-baseline.neon`. If a rule genuinely doesn't fit, add a path-scoped `identifier:`-based `ignoreErrors` entry in `Build/phpstan.neon` with a comment explaining why.
- [ ] New public methods / events / command flags reflected in `Documentation/`.
- [ ] If touching `Classes/Processor.php` or allowed-roots logic: extend `Tests/Functional/ProcessorSymlinkedFileadminTest.php` with a scenario covering your change.
- [ ] If adding DI changes (new Service/Event/EventListener): update `#[UsesClass]` attributes on relevant Functional tests (`ProcessorTest`, `ProcessingMiddlewareTest`, `ProcessorSymlinkedFileadminTest`) so strict coverage metadata stays green.
- [ ] Port to `TYPO3_12` branch if the change is a bug fix, not a v13+-only feature.
- [ ] Commit signed (`git commit -S --signoff`) — `main` branch protection requires it.
<!-- AGENTS-GENERATED:END checklist -->

<!-- AGENTS-GENERATED:START examples -->
## Patterns to Follow
> **Prefer looking at real code in this repo over generic examples.**
> See **Golden Samples** section above for files that demonstrate correct patterns.
<!-- AGENTS-GENERATED:END examples -->

<!-- AGENTS-GENERATED:START upgrade -->
## TYPO3 upgrade considerations
- Run **Extension Scanner** before upgrading: Backend → Upgrade → Scan Extension Files
- Use **Rector** for automated migrations: `vendor/bin/rector process`
- Check **deprecation log** in TYPO3 backend
- Review [TYPO3 Changelog](https://docs.typo3.org/c/typo3/cms-core/main/en-us/Index.html) for breaking changes
<!-- AGENTS-GENERATED:END upgrade -->

<!-- AGENTS-GENERATED:START help -->
## When stuck
- TYPO3 Documentation: https://docs.typo3.org
- TCA Reference: https://docs.typo3.org/m/typo3/reference-tca/main/en-us/
- Core API: https://docs.typo3.org/m/typo3/reference-coreapi/main/en-us/
- Extbase Guide: https://docs.typo3.org/m/typo3/book-extbasefluid/main/en-us/
- Check existing patterns in EXT:core or EXT:backend
- Review root AGENTS.md for project-wide conventions
<!-- AGENTS-GENERATED:END help -->

<!-- AGENTS-GENERATED:START skill-reference -->
## Skill Reference
> For TYPO3 extension standards, TER compliance, and conformance checks:
> **Invoke skill:** `typo3-conformance`
<!-- AGENTS-GENERATED:END skill-reference -->
