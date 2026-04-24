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
- **CSS/JS**: minimal backend-module assets under `Resources/Public/{Css,JavaScript}/`. Backend module pages pull them via `<f:asset.*>`.
<!-- AGENTS-GENERATED:END types -->

<!-- AGENTS-GENERATED:START organization -->
## Organization conventions
- Group resources by type: `templates/`, `images/`, `locales/`
- Use consistent naming: lowercase, hyphens for spaces
- Keep related resources together
- Version large binary assets carefully (consider Git LFS)
<!-- AGENTS-GENERATED:END organization -->

<!-- AGENTS-GENERATED:START code-style -->
## Code style & conventions
- Use descriptive file names: `user-profile-template.html` not `template1.html`
- Keep templates simple - logic belongs in code, not templates
- Use consistent indentation in structured files (JSON, YAML, XML)
- Document template variables and their expected values
- Optimize images before committing (compress, resize)
<!-- AGENTS-GENERATED:END code-style -->

<!-- AGENTS-GENERATED:START templates -->
## Template best practices
- Use clear placeholder syntax: `{{variable}}` or `${variable}`
- Document all required variables in comments or README
- Keep templates focused - one purpose per template
- Use partials/includes for reusable components
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
## Patterns to Follow
> **Prefer looking at real code in this repo over generic examples.**
> See **Golden Samples** section above for files that demonstrate correct patterns.
<!-- AGENTS-GENERATED:END examples -->

<!-- AGENTS-GENERATED:START help -->
## When stuck
- Check how resources are consumed in the codebase
- Look for build/preprocessing scripts
- Review existing resources for patterns
- Check root AGENTS.md for project conventions
<!-- AGENTS-GENERATED:END help -->
