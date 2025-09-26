Welcome! This repository contains the TYPO3 extension **`nr_image_optimize`**, which provides on-demand image optimization and responsive image helpers. Use this guide whenever you make changes so your contributions stay aligned with the project.

## Project Overview
- **Purpose**: Serve processed variants of images (including WebP/AVIF fallbacks) and generate responsive `<img>` tags via Fluid ViewHelpers.
- **Runtime**: TYPO3 v13.4 with PHP 8.2+ and the Intervention Image library (Imagick driver).
- **Key Entry Points**:
    - `Classes/Processor.php`: parses `/processed/...` requests, applies resizing/cropping, encodes variants, and streams the response.
    - `Classes/Middleware/ProcessingMiddleware.php`: TYPO3 PSR-15 middleware that delegates matching requests to the processor.
    - `Classes/ViewHelpers/SourceSetViewHelper.php`: Fluid ViewHelper that builds `srcset`/`sizes` attributes and can enable responsive width variants.
    - Configuration is mainly handled via `Configuration/Services.yaml` and `Configuration/RequestMiddlewares.php`.

## Code Style & Conventions
- Follow **PSR-12** plus the rules defined in `Build/.php-cs-fixer.dist.php`.
- Files use strict types, project header docblocks, and Symfony-style imports (global namespace imports are allowed).
- Prefer dependency injection (TYPO3 service container) over static `GeneralUtility::makeInstance` when possible. Middleware & ViewHelpers are registered as services.
- Keep functions/methods short and focused. Document non-trivial behavior with docblocks or inline comments.
- When touching Fluid templates or ViewHelpers, ensure attributes/defaults stay consistent with the README examples.

## Testing & QA
- Install dependencies with `composer install` (uses `.build/vendor`).
- Main QA commands (see `composer.json`):
    - Code style: `composer ci:test:php:cgl`
    - Linting: `composer ci:test:php:lint`
    - Static analysis: `composer ci:test:php:phpstan`
    - Rector/Fractor dry runs: `composer ci:test:php:rector`, `composer ci:test:php:fractor`
    - Unit tests: `composer ci:test:php:unit`
    - Full suite: `composer ci:test`
- These scripts assume a TYPO3 testing context; run what’s relevant for your change and report the commands you executed.

## Git Workflow Essentials

1. Branch from `main` with a descriptive name: `feature/<slug>` or `bugfix/<slug>`.
2. Run `composer ci:test` locally **before** committing.
3. Force pushes **allowed only** on your feature branch using
   `git push --force-with-lease`. Never force-push `main`.
4. Keep commits atomic; prefer checkpoints (`feat: …`, `test: …`).

## Directory Highlights
- `Build/`: Tooling configs (PHP CS Fixer, Rector, PHPStan, PHPUnit, etc.).
- `Resources/`: Public assets and localization. Respect existing naming/layout.
- `Configuration/`: TYPO3 DI & middleware registration.
- `Tests/`: Reserved for automated tests (currently empty – add here if you create tests).
- `ext_emconf.php` & `composer.json`: Extension metadata. Update versions consistently when releasing.

## Contribution Tips
- Explain how new features affect image processing (e.g., new query flags, formats, or ViewHelper attributes).
- Keep documentation (`README.rst`, changelog) in sync with code changes.
- For new services/middleware, wire them up via `Configuration/Services.yaml` or `Configuration/RequestMiddlewares.php`.
- Avoid committing generated files under `.build/` or TYPO3 caches.

By following these guidelines you’ll help other agents contribute efficiently. Happy hacking! ✨
