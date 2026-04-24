<!-- Managed by agent: keep sections and order; edit content, not structure. Last updated: 2026-04-24 -->

# AGENTS.md — Resources

<!-- AGENTS-GENERATED:START overview -->
## Overview
TYPO3 extension public/private resources: Fluid templates (backend module), icons, language labels, CSS/JS for the backend maintenance module.
<!-- AGENTS-GENERATED:END overview -->

<!-- AGENTS-GENERATED:START filemap -->
## Structure (this repo, verified)
```
Resources/
  Private/
    Language/
      locallang.xlf           → TYPO3 translation file (English base, keys referenced
                                  by LLL:EXT:nr_image_optimize/...)
    Templates/
      Maintenance/            → Fluid templates for the backend module
  Public/
    Css/
    Icons/                    → SVG icons registered via Configuration/Icons.php
    JavaScript/
```

There are no `FlexForms/`, `TCA/`, or frontend image assets here. This is a backend-only resource layout.
<!-- AGENTS-GENERATED:END filemap -->

<!-- AGENTS-GENERATED:START types -->
## Resource conventions
- **Fluid templates**: `Resources/Private/Templates/Maintenance/<Action>.html`. Partials/Layouts only if introduced — none yet.
- **Language**: `Resources/Private/Language/locallang.xlf` — use `LLL:EXT:nr_image_optimize/Resources/Private/Language/locallang.xlf:key` references. Never hard-code UI strings in PHP.
- **Icons**: SVGs under `Resources/Public/Icons/`, registered by identifier in `Configuration/Icons.php`. Reference via the registered identifier, not a path.
- **CSS/JS**: minimal backend-module assets under `Resources/Public/{Css,JavaScript}/`. Maintenance templates load them via `<f:be.pageRenderer includeJsFiles="..." includeCssFiles="...">` with `EXT:nr_image_optimize/Resources/Public/...` paths.
<!-- AGENTS-GENERATED:END types -->

<!-- AGENTS-GENERATED:START organization -->
## Organization conventions (TYPO3-standard)
- Directory casing is **TitleCase** (`Resources/Private/Templates/Maintenance/Index.html`) — TYPO3 expects this exactly. Don't use the generic `templates/` / `images/` lowercase layout.
- Templates: filename = action name (e.g. `MaintenanceController::indexAction()` → `Maintenance/Index.html`).
- Public assets are referenced via `EXT:nr_image_optimize/Resources/Public/...` paths, never relative paths.
<!-- AGENTS-GENERATED:END organization -->

<!-- AGENTS-GENERATED:START code-style -->
## Code style & conventions
- Keep Fluid templates declarative — business logic belongs in PHP, not in `<f:if>` chains.
- One template per controller action.
- Optimize images (`pngcrush`/`optipng` for PNG, `cwebp` for WebP) before committing — this extension is itself an image optimizer.
- XLIFF: keep `<source>` lines short, use clear keys (`labels.foo`, not `text1`).
<!-- AGENTS-GENERATED:END code-style -->

<!-- AGENTS-GENERATED:START templates -->
## Fluid template syntax
- Variable output: `{variable}` (auto-escaped) or `{variable -> f:format.raw()}` (explicit raw, only when safe).
- ViewHelpers: `<f:link.action ...>`, `<f:translate key="..."/>`, `<f:be.pageRenderer ...>`.
- Custom ViewHelper from this extension: `<nrio:sourceSet ... />` (namespace declared in template).
- Translation labels: `<f:translate key="LLL:EXT:nr_image_optimize/Resources/Private/Language/locallang.xlf:key"/>` or shorthand `{f:translate(key: '...') }`.
- Conditionals: `<f:if condition="{var}">` — keep terse, push complex logic into the controller.
<!-- AGENTS-GENERATED:END templates -->

<!-- AGENTS-GENERATED:START security -->
## Security & safety
- Never store secrets in resource files
- Validate all resource files that accept user input
- Sanitize template variables to prevent injection
- Review images/assets for embedded metadata (EXIF, etc.)
- Use CSP-safe inline styles when applicable
<!-- AGENTS-GENERATED:END security -->

<!-- AGENTS-GENERATED:START checklist -->
## PR/commit checklist
- [ ] File names are descriptive and consistent
- [ ] Images are optimized (compressed, correct size)
- [ ] Templates have documented variables
- [ ] No sensitive data in resources
- [ ] Structured files are valid (JSON, YAML syntax)
- [ ] Changes tested with consuming code
<!-- AGENTS-GENERATED:END checklist -->

<!-- AGENTS-GENERATED:START examples -->
## Reference templates in this repo
- `Resources/Private/Templates/Maintenance/Index.html` — backend module landing page with `<f:be.pageRenderer>` JS includes.
- `Resources/Private/Templates/Maintenance/SystemRequirements.html` — system requirements view with both JS and CSS includes.
<!-- AGENTS-GENERATED:END examples -->

<!-- AGENTS-GENERATED:START help -->
## When stuck
- Check how resources are consumed in the codebase
- Look for build/preprocessing scripts
- Review existing resources for patterns
- Check root AGENTS.md for project conventions
<!-- AGENTS-GENERATED:END help -->
