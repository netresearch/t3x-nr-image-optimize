<!-- Managed by agent: keep sections and order; edit content, not structure. Last updated: 2026-04-24 -->

# AGENTS.md — Documentation

<!-- AGENTS-GENERATED:START overview -->
## Overview
TYPO3 extension documentation (RST format for docs.typo3.org). **Use the `typo3-docs` skill** for comprehensive guidance.
<!-- AGENTS-GENERATED:END overview -->

<!-- AGENTS-GENERATED:START filemap -->
## Key Files
| File | Purpose |
|------|---------|
| `Documentation/Index.rst` | Main entry (required) |
| `Documentation/guides.xml` | Project metadata (replaces the older `Settings.cfg`) |
| `Documentation/Includes.rst.txt` | Shared substitutions |
| `Documentation/Introduction/Index.rst` | Landing page copy |
| `Documentation/Installation/Index.rst` | Install instructions |
| `Documentation/Configuration/Index.rst` | `$EXT_CONF` keys, middleware routes |
| `Documentation/Usage/Index.rst` | ViewHelper + command usage |
| `Documentation/Maintenance/Index.rst` | Backend module docs |
| `Documentation/Developer/Index.rst` | Event dispatch, extension points |
| `Documentation/Changelog/` | Per-version release notes |
| `Documentation/Images/` | PNG/SVG assets referenced from RST |
<!-- AGENTS-GENERATED:END filemap -->

<!-- AGENTS-GENERATED:START golden-samples -->
## Golden Samples
| Pattern | Reference |
|---------|-----------|
| Main entry | `Documentation/Index.rst` |
| Cross-referenced doc page | `Documentation/Developer/Index.rst` (events) |
| Per-version release notes | `Documentation/Changelog/<X.Y.Z>.rst` |

> No screenshots have been added yet. When introducing one, use `.. figure::` with `:alt:`, `:class: with-border with-shadow`, and `:zoom: lightbox`.
<!-- AGENTS-GENERATED:END golden-samples -->

<!-- AGENTS-GENERATED:START structure -->
## Structure (this repo, verified)
```
Documentation/
├── Index.rst
├── guides.xml              (project metadata — uses the newer guides.xml format, not Settings.cfg)
├── Includes.rst.txt
├── Introduction/Index.rst
├── Installation/Index.rst
├── Configuration/Index.rst
├── Usage/Index.rst
├── Maintenance/Index.rst
├── Developer/Index.rst
├── Changelog/
└── Images/
```

This repo uses **`guides.xml`** (modern) not `Settings.cfg` (legacy). Don't regenerate to the legacy format.
<!-- AGENTS-GENERATED:END structure -->

<!-- AGENTS-GENERATED:START commands -->
## Rendering Docs
| Task | Command |
|------|---------|
| Render locally | `docker run --rm -v $(pwd):/project ghcr.io/typo3-documentation/render-guides:latest` |
| Preview | Open `Documentation-GENERATED-temp/Index.html` |
| Clean | `rm -rf Documentation-GENERATED-temp/` |
<!-- AGENTS-GENERATED:END commands -->

<!-- AGENTS-GENERATED:START patterns -->
## Key Patterns (TYPO3-specific)
- Use RST format, **not Markdown**
- Use TYPO3 directives: `confval`, `versionadded`, `deprecated`, `t3-field-list-table`
- Include code with `.. code-block:: php` or `.. literalinclude::`
- Cross-reference with `:ref:` and proper labels
- **Screenshots MANDATORY** for backend modules, config screens, UI workflows
- Store in `Documentation/Images/`, use `.. figure::` with `:zoom: lightbox`
<!-- AGENTS-GENERATED:END patterns -->

<!-- AGENTS-GENERATED:START screenshots -->
## Screenshots (MANDATORY for UI)
```rst
.. figure:: /Images/Configuration/ExtensionSettings.png
   :alt: Extension configuration showing API settings
   :zoom: lightbox
   :class: with-border with-shadow

   Configure the extension in Admin Tools > Settings
```
- Format: **PNG only**
- Zoom modes: `lightbox` (default), `gallery` (tutorials), `inline` (diagrams)
- Always include `:alt:` text
<!-- AGENTS-GENERATED:END screenshots -->

<!-- AGENTS-GENERATED:START code-style -->
## RST Style
- Headings: `=` for H1, `-` for H2, `~` for H3, `^` for H4
- Line length: ~80 characters for readability
- One sentence per line (for better diffs)
- Use `.. note::`, `.. warning::`, `.. tip::` for admonitions
- Tables: use `.. t3-field-list-table::` or grid tables
<!-- AGENTS-GENERATED:END code-style -->

<!-- AGENTS-GENERATED:START checklist -->
## PR Checklist
- [ ] RST syntax valid (renders without errors)
- [ ] All internal links resolve
- [ ] Images have `:alt:` text and `:zoom: lightbox`
- [ ] **Screenshots exist** for all backend/config/UI sections
- [ ] Code examples are tested
- [ ] Follows docs.typo3.org structure
<!-- AGENTS-GENERATED:END checklist -->

<!-- AGENTS-GENERATED:START skill-reference -->
## Skill Reference
> For RST syntax, TYPO3 directives, screenshots, and docs.typo3.org deployment:
> **Invoke skill:** `typo3-docs`
<!-- AGENTS-GENERATED:END skill-reference -->
