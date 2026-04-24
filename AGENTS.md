<!-- FOR AI AGENTS - Human readability is a side effect, not a goal -->
<!-- Managed by agent: keep sections and order; edit content, not structure -->
<!-- Last updated: 2026-04-24 | Last verified: never -->

# AGENTS.md

**Precedence:** the **closest `AGENTS.md`** to the files you're changing wins. Root holds global defaults only.

## Project

**`netresearch/nr-image-optimize`** — TYPO3 extension. Serves processed image variants (WebP/AVIF fallbacks, responsive `srcset`) by intercepting `/processed/*` URLs via PSR-15 middleware.

- **PHP:** >=8.2
- **TYPO3 image library:** Intervention Image. `Classes/Service/ImageManagerFactory` auto-selects Imagick when available, falls back to GD.
- **Branch layout:**
  - `main` → TYPO3 13.4 / 14 (v2.x releases)
  - `TYPO3_12` → TYPO3 12.4 LTS (v1.x releases). Port relevant fixes here. Avoid LoggerAware dependencies — TYPO3_12 uses plain `error_log(sprintf(...))` to keep the dep surface narrow.
- **Release process:** release PR from `main` / `TYPO3_12` → merge → tag signed annotated (`git tag -s vX.Y.Z`) → push tag → the `release.yml` workflow creates the GitHub Release + TER upload + docs.typo3.org publish. **Never run `gh release create`** — it makes the release tag immutable and blocks re-publishing.

## Commands (unverified)
> Source: Makefile — CI-sourced commands are most reliable

<!-- AGENTS-GENERATED:START commands -->
| Task | Command | ~Time |
|------|---------|-------|
| Typecheck (PHPStan level 10) | `composer ci:test:php:phpstan` | ~15s |
| Lint (PHP syntax) | `composer ci:test:php:lint` | ~10s |
| Format (PHP-CS-Fixer, check) | `composer ci:test:php:cgl` | ~5s |
| Format (PHP-CS-Fixer, fix) | `composer ci:cgl` | ~5s |
| Rector (dry-run) | `composer ci:test:php:rector` | ~10s |
| Fractor (dry-run) | `composer ci:test:php:fractor` | ~5s |
| Unit tests | `composer ci:test:php:unit` | ~5s |
| Functional tests | `composer ci:test:php:functional` | ~30s |
| Acceptance tests | `composer ci:test:php:acceptance` | ~15s |
| Fuzz tests | `composer ci:test:php:fuzz` | ~10s |
| Mutation testing | `composer ci:test:php:mutation` | ~2m |
| Full CI bundle (lint+phpstan+rector+fractor+unit+cgl) | `composer ci:test` | ~1m |

All scripts also work via `make` targets (see `make help`). The `make` targets use `Build/Scripts/runTests.sh` which runs inside a Docker PHP image — use `make` when your host PHP differs from CI or when Imagick isn't installed locally.
<!-- AGENTS-GENERATED:END commands -->

> If commands fail, verify against Makefile/package.json/composer.json or ask user to update.

## Response Style
- Answer first, elaborate only if needed. No sycophantic openers ("Great question!", "Absolutely!").
- For yes/no or status questions, lead with the answer.
- Skip preamble. Match response length to task complexity.

## Workflow
1. **Before coding**: Read nearest `AGENTS.md` + check Golden Samples for the area you're touching
2. **After each change**: Run the smallest relevant check (lint → typecheck → single test)
3. **Before committing**: Run full test suite if changes affect >2 files or touch shared code
4. **Before claiming done**: Run verification and **show output as evidence** — never say "try again", "should work now", "tested", "verified", or "all green" without pasted command output in the same turn

## File Map
<!-- AGENTS-GENERATED:START filemap -->
```
Classes/         → PHP classes (PSR-4: Netresearch\NrImageOptimize\)
  Command/         Symfony console commands (AnalyzeImages, OptimizeImages)
  Controller/      MaintenanceController (backend module)
  Event/           ImageProcessedEvent, VariantServedEvent
  EventListener/   OptimizeOnUploadListener
  Middleware/      ProcessingMiddleware (PSR-15)
  Service/         ImageManagerFactory, ImageManagerAdapter, ImageOptimizer, SystemRequirementsService
  ViewHelpers/     SourceSetViewHelper (Fluid)
  Processor.php    Main request dispatcher for /processed/* URLs
Configuration/   → TYPO3 DI + middleware + icons (Services.yaml, RequestMiddlewares.php, Icons.php, Backend/)
Resources/       → Public JS/CSS + Private Fluid templates + Language labels
Tests/           → Unit, Functional, Acceptance, Architecture (phpat), Fuzz
Documentation/   → RST sources for docs.typo3.org
Build/           → Tooling configs (phpstan.neon, PHPUnit xml, .php-cs-fixer.dist.php, rector.php, fractor.php) + Scripts/runTests.sh
.github/         → GitHub Actions workflows (release, ci, publish-to-ter, republish)
ext_emconf.php   → TYPO3 extension metadata (version, dependencies)
composer.json    → Composer metadata (authoritative version + PHP/TYPO3 constraints)
```
<!-- AGENTS-GENERATED:END filemap -->

## Golden Samples (follow these patterns)
<!-- AGENTS-GENERATED:START golden-samples -->
| For | Reference | Key patterns |
|-----|-----------|--------------|
| PSR-15 middleware | `Classes/Middleware/ProcessingMiddleware.php` | thin delegation, request matching |
| Request dispatcher | `Classes/Processor.php` | static-cache-per-public-path, logging at 400-branches, symlink-aware allowed-roots |
| Service (auto-wired) | `Classes/Service/ImageManagerFactory.php` | constructor-inject, `final readonly class`, exception contract |
| Event | `Classes/Event/VariantServedEvent.php` | immutable DTO, `final readonly class`, named-arg constructor |
| ViewHelper | `Classes/ViewHelpers/SourceSetViewHelper.php` | Fluid attribute handling, static cache for `getimagesize()` |
| Symfony command | `Classes/Command/AbstractImageCommand.php` | shared template method, `final` helpers |
| Unit test | `Tests/Unit/ProcessorTest.php` | reflection into `final` class, `#[CoversClass]` + `#[UsesClass]` attributes |
| Functional test | `Tests/Functional/ProcessorSymlinkedFileadminTest.php` | EFS-symlink fileadmin reproduction setup |
<!-- AGENTS-GENERATED:END golden-samples -->

## Heuristics (quick decisions)
<!-- AGENTS-GENERATED:START heuristics -->
| When | Do |
|------|-----|
| Adding class | Follow PSR-4 in `Classes/` (no `src/` here) |
| Adding controller | Create in `Classes/Controller/` |
| Adding service | Create in `Classes/Service/` |
| Running tasks | Check `make help` for available commands |
| Committing | Use Conventional Commits (feat:, fix:, docs:, etc.) |
| Merging PRs | Create merge commits |
| Adding dependency | Ask first - we minimize deps |
| Unsure about pattern | Check Golden Samples above |
<!-- AGENTS-GENERATED:END heuristics -->

## Repository Settings
<!-- AGENTS-GENERATED:START repo-settings -->
- **Default branch:** `main`
- **Merge strategy:** merge
- **Required checks (rulesets):** `ci / Code Style`, `ci / Functional Tests SQLite (8.2, ^13.4)`, `ci / Functional Tests SQLite (8.2, ^14.0)`, `ci / Lint (8.2)`, `ci / PHPStan (8.2, ^13.4)`, `ci / PHPStan (8.2, ^14.0)`, `ci / Rector`, `ci / Unit Tests (8.2, ^13.4)`, `ci / Unit Tests (8.2, ^14.0)`, `security / Composer Audit`
- **Active rulesets:** CI Required Checks, Copilot review for default branch
<!-- AGENTS-GENERATED:END repo-settings -->

<!-- AGENTS-GENERATED:START ci-rules -->

<!-- AGENTS-GENERATED:END ci-rules -->

## Boundaries

### Always Do
- Run `composer ci:test` before committing (bundles lint + phpstan + rector + fractor + unit + cgl).
- **Sign commits** with `git commit -S --signoff` — `main` branch protection requires signed commits (GitHub rejects unsigned pushes).
- Use **Conventional Commits**: `feat:`, `fix:`, `chore:`, `ci:`, `docs:`, `test:`, `refactor:`. See `git log --oneline -20` for established style.
- **Atomic commits**: one logical change per commit; each commit builds and passes tests independently.
- Show test/CI output as evidence before claiming work is complete — never say "try again", "should work now", "tested", "verified", or "all green" without pasted output.
- Verify `pwd` resolves inside the intended worktree before editing — not `.bare/`, not `~/.claude/skills/…`, not `~/.claude/plugins/cache/…`.
- Port relevant fixes from `main` to `TYPO3_12` (v12.4 LTS). Use `error_log(sprintf(...))` there instead of PSR Logger.
- Force-push only with `--force-with-lease`.
- PHP 8.2+ syntax; PSR-12 + TYPO3 CGL (enforced via `composer ci:test:php:cgl`).

### Ask First
- Adding new dependencies
- Modifying CI/CD configuration
- Changing public API signatures
- Running full e2e test suites
- Repo-wide refactoring or rewrites
- Operations that touch >3 repos (produce a dry-run plan first)

### Never Do
- Commit secrets, credentials, or sensitive data.
- Commit `composer.lock` — TYPO3 extensions are libraries; CI resolves per-matrix-cell. The file is in `.gitignore`.
- Add AI-attribution trailers (`Co-Authored-By: Claude`, "Generated with Claude Code", etc.) to commits.
- Commit baseline PHPStan errors (`Build/phpstan-baseline.neon` is `ignoreErrors: []`). Fix findings or add a path-scoped `identifier`-based `ignoreErrors` entry with a comment explaining why.
- Run `gh release create` for releases — the `release.yml` workflow handles this and a manual release makes the tag permanently immutable on GitHub.
- Use `secrets: inherit` in reusable workflows — pass each secret explicitly (supply-chain guard).
- Edit installed skill/plugin cache paths (`~/.claude/skills/`, `~/.claude/plugins/cache/`, `**/.bare/**`) — edit the source worktree.
- Push directly to `main` or `TYPO3_12` — always via PR.
- Merge a PR before all review threads are resolved and CI is fully green (including annotations — check via `gh api repos/netresearch/t3x-nr-image-optimize/commits/<SHA>/check-runs`).
- Squash commits during merge — use merge commits to preserve signed-commit chain.
- Touch `Documentation/Settings.cfg` or `ext_emconf.php` version manually — the release workflow updates these.

## Contributing (for AI agents)
- **Comprehension**: Understand the problem before submitting code. Read the linked issue, understand *why* the change is needed, not just *what* to change.
- **Context**: Every PR must explain the trade-offs considered and link to the issue it addresses. Disclose AI assistance if the project requires it.
- **Continuity**: Respond to review feedback. Drive-by PRs without follow-up will be closed.

<!-- AGENTS-GENERATED:START module-boundaries -->

<!-- AGENTS-GENERATED:END module-boundaries -->

## Codebase State
<!-- AGENTS-GENERATED:START codebase-state -->
- No `@deprecated` markers in `Classes/` as of this verification.
- Architecture enforcement: `phpat` via `Tests/Architecture/ArchitectureTest` (wired in `Build/phpstan.neon`).
- PHPStan: level 10, empty baseline (enforced clean). `Build/phpstan.neon` documents path-scoped ergebnis rule suppressions per [PR #99](https://github.com/netresearch/t3x-nr-image-optimize/pull/99) / [#100](https://github.com/netresearch/t3x-nr-image-optimize/pull/100).
<!-- AGENTS-GENERATED:END codebase-state -->

## Scoped AGENTS.md (MUST read when working in these directories)
<!-- AGENTS-GENERATED:START scope-index -->
- `./Classes/AGENTS.md` — TYPO3 extension following TYPO3 CGL and PSR-12
- `./Tests/AGENTS.md` — typo3-testing
- `./Documentation/AGENTS.md` — typo3-docs
- `./Resources/AGENTS.md` — Static resources, assets, templates, and configuration files
- `./.github/workflows/AGENTS.md` — GitHub Actions workflows and CI/CD automation
<!-- AGENTS-GENERATED:END scope-index -->

> **Agents**: When you read or edit files in a listed directory, you **must** load its AGENTS.md first. It contains directory-specific conventions that override this root file.

## When instructions conflict
The nearest `AGENTS.md` wins. Explicit user prompts override files.
- For PHP-specific patterns, follow PSR standards
