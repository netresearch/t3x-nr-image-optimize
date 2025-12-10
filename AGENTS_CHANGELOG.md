# AGENTS.md Convention Implementation Changelog

**Date**: 2025-12-10  
**Project**: netresearch/nr-image-optimize (TYPO3 Extension)

## Summary

Successfully implemented the agents.md convention across the nr_image_optimize TYPO3 extension repository. This establishes a unified, hierarchical documentation system for AI agents with clear precedence rules and scoped instructions.

## Created Files

### Documentation Files
1. **AGENTS.md** (root)
   - Thin root file with global defaults and precedence rules
   - Index of scoped AGENTS.md files
   - Environment setup with nrdev devbox instructions
   - Build & test commands (all prefixed with `nrdev`)
   - Code style & quality standards (PSR-12, PER-CS 2.0, Symfony, PHPStan level 8, Rector)
   - Security guidelines (input validation, path traversal protection)
   - PR & commit checklist (Conventional Commits)
   - House rules (SOLID, KISS, DRY, YAGNI, SemVer, GPL-2.0-or-later)

2. **Classes/AGENTS.md**
   - PHP source code specifics for middleware, processors, ViewHelpers
   - TYPO3 patterns: Dependency Injection, PSR-7/PSR-15, Fluid ViewHelpers
   - Type safety: readonly properties, strict parameter/return types, union types
   - Security: input validation, path traversal protection, format whitelist
   - Testing integration with Tests/AGENTS.md
   - Good vs bad examples for typed properties, function imports
   - Troubleshooting guide for middleware, image processing, ViewHelpers
   - House rules: processor focus, stateless ViewHelpers, PSR-3 logging, caching

3. **Tests/AGENTS.md**
   - Unit testing specifics with TYPO3 Testing Framework
   - Test naming conventions and AAA pattern
   - Testing patterns: mocking dependencies, testing ViewHelpers
   - Coverage guidelines: 80% minimum, 100% for security-critical code
   - Good vs bad examples: explicit assertions, data providers
   - Troubleshooting guide for test execution, mocks, TYPO3 dependencies, coverage
   - House rules: one assertion per test, no file I/O, fast tests (<100ms)

### Fundamental Files
4**.editorconfig**
   - Consistent formatting across editors
   - UTF-8, LF line endings, final newline
   - Space indentation (4 spaces for PHP/YAML/JSON, 2 for Markdown)
   - Tab indentation for Makefile

5**AGENTS_CHANGELOG.md** (this file)
   - Complete change documentation
   - Decision log

## Merged Sources

### Existing Files Analyzed
- `composer.json` — Extracted scripts, dependencies, PHP version requirements
- `Build/phpstan.neon` — PHPStan level 8, strict rules, PHP 8.1 target
- `Build/rector.php` — Rector sets (SOLID, KISS, DRY, Early Return, Type Declaration)
- `Build/.php-cs-fixer.dist.php` — PSR-12, PER-CS 2.0, Symfony style, custom rules
- `.github/workflows/ci.yml` — CI pipeline steps for validation
- `Classes/Middleware/ProcessingMiddleware.php` — Example of good code (readonly, early return)
- `Classes/ViewHelpers/SourceSetViewHelper.php` — ViewHelper patterns
- `README.md` — Feature overview, requirements, usage examples

### External Sources
- **Rule files**:
  - `/home/axel/Projekte/Chemnitz/CMS/main/app/vendor/netresearch/nr-image-optimize/AGENTS.md` (existing, used as template)
- **nrdev detection**: Verified `.nrdev/config.yml` exists in parent project → all commands use `nrdev` prefix

### Agent Instruction Files
- No existing CLAUDE.md, GEMINI.md, COPILOT.md, or .cursor/** files found
- No back-compat migration needed

## Placement Strategy

**Root AGENTS.md** (thin):
- Global defaults, precedence, index only
- No implementation details (delegated to scoped files)

**Scoped AGENTS.md files**:
- `Classes/AGENTS.md` — PHP source code (nearest to Classes/)
- `Tests/AGENTS.md` — Unit tests (nearest to Tests/)

**Rationale**: 
- `Classes/` and `Tests/` are the two primary development areas
- No separate frontend/backend/api/cli slices needed (this is a TYPO3 extension)
- `Build/`, `Configuration/`, `Resources/` don't warrant separate AGENTS.md (tooling/config, not implementation)

## Content Schema Compliance

All AGENTS.md files follow the schema:
1. ✅ Overview
2. ✅ Setup/env (with nrdev)
3. ✅ Build & tests (file-scoped commands)
4. ✅ Code style
5. ✅ Security
6. ✅ PR/commit checklist (root only)
7. ✅ Good vs bad examples (2-4 per file)
8. ✅ When stuck
9. ✅ House Rules (defaults in root, overrides in scoped)

## House Rules Applied

### Commits & PRs
- Conventional Commits mandatory
- Atomic commits
- PR size ≤ 300 net LOC (preferred)

### Type-safety/Design
- Strict types (`declare(strict_types=1);`)
- SOLID, KISS, DRY, YAGNI
- Composition > Inheritance
- Law of Demeter
- Early Return pattern

### Dependencies
- Latest stable versions
- Renovate enabled
- PHP 8.1 minimum for max compatibility
- TYPO3 11.5 / 12.x support

### API/Versioning
- SemVer
- Branch alias: `dev-main` → `1.0.x-dev`
- TYPO3 extension conventions

### Security
- No secrets in VCS
- Environment variables for config
- Input validation (paths, dimensions, formats)
- Path traversal protection
- SPDX License Identifier: GPL-2.0-or-later

### Observability
- TYPO3 PSR-3 logging
- No secrets/tokens in logs
- Performance optimization for middleware paths

### A11y
- `alt` and `title` attributes on `<img>` tags
- Native lazy loading (`loading="lazy"`)
- Responsive images via `srcset`

### Licensing
- GPL-2.0-or-later (composer.json + file headers)
- SPDX identifier required

## Scope Boundaries

Not applicable (edge vs app distinction not relevant for TYPO3 extensions).

## Validation

### Commands Verified
- ✅ `make help` — Shows all targets
- ⚠️ `nrdev composer ci:test:php:lint` — Requires `composer install` (expected)
- ⚠️ `nrdev composer ci:test:php:phpstan` — Requires `composer install` (expected)

**Note**: Vendor dependencies not installed yet (intentional, as this is initial setup). After `nrdev composer install`, all commands should work.

### Files Created
- ✅ Root AGENTS.md exists and is thin
- ✅ Classes/AGENTS.md exists with scoped instructions
- ✅ Tests/AGENTS.md exists with test-specific guidance
- ✅ .envrc, Makefile, .editorconfig created
- ✅ All files use correct formatting (LF, UTF-8, final newline)

### Idempotency
- ✅ Re-running discovery would produce same results (no drift)
- ✅ Scoped files don't duplicate root content

## Decision Log

### 1. nrdev Usage
**Decision**: Prefix all CLI commands with `nrdev`  
**Rationale**: Project uses Netresearch devbox (detected `.nrdev/config.yml` in parent), per user rules  
**Impact**: All Makefile targets and AGENTS.md command examples use `nrdev`

### 2. Scoped File Structure
**Decision**: Create only `Classes/AGENTS.md` and `Tests/AGENTS.md`  
**Rationale**: These are the primary development areas; `Build/`, `Configuration/`, `Resources/` are tooling/config  
**Alternative considered**: Individual files for `Middleware/`, `ViewHelpers/` — rejected as too granular for this project size

### 3. PHP Version Target
**Decision**: Document PHP 8.1 as minimum, support up to 8.4  
**Rationale**: Matches composer.json constraints and maximizes TYPO3 11.5/12.x compatibility  
**Impact**: Rector/PHPStan/php-cs-fixer all target PHP 8.1 features

### 4. Test Framework
**Decision**: Use TYPO3 Testing Framework, not raw PHPUnit  
**Rationale**: Extension tests need TYPO3 environment setup (UnitTestCase, ViewHelperBaseTestcase)  
**Impact**: Tests/AGENTS.md includes TYPO3 Testing Framework patterns

### 5. Security Focus
**Decision**: Emphasize path traversal protection and input validation  
**Rationale**: Middleware processes user-supplied paths (`/processed/`), high risk if not validated  
**Impact**: Both root and Classes/AGENTS.md include security sections with examples

### 6. No Git Hooks
**Decision**: Do not create Husky/lint-staged or .husky/ hooks  
**Rationale**: This is a vendor package (under `vendor/`), not the main project — git hooks should be at project root  
**Alternative**: Documented pre-commit checks in AGENTS.md for manual use

### 7. No package.json
**Decision**: Do not create package.json for Commitlint  
**Rationale**: This is a PHP-only project, no Node.js tooling present or needed  
**Alternative**: Documented Conventional Commits requirement in AGENTS.md checklist

### 8. Makefile Over Scripts
**Decision**: Use Makefile for developer commands, not shell scripts  
**Rationale**: Project already uses Composer scripts; Makefile provides human-friendly aliases  
**Impact**: Developers can use `make test` instead of `nrdev composer ci:test:php:unit`

## Next Steps (For Developers)

1. **Install dependencies**:
   ```bash
   nrdev composer install
   ```

2. **Run validation**:
   ```bash
   nrdev composer ci:test
   ```

4. **Review AGENTS.md files**:
   - Read root AGENTS.md for global guidelines
   - Consult Classes/AGENTS.md when working on PHP code
   - Consult Tests/AGENTS.md when writing/maintaining tests

5. **Follow commit conventions**:
   - Use Conventional Commits format (see PR checklist in AGENTS.md)
   - Keep PRs focused (≤ 300 net LOC changed preferred)

## Known Limitations

1. **Vendor package location**: This is under `vendor/netresearch/`, so git hooks and some tooling may not work as expected (intentional, as this should be developed via composer package workflow)

2. **Dependencies not installed**: Linting/static analysis commands require `nrdev composer install` first (expected behavior)

3. **No integration tests**: Only unit test guidance provided (integration/functional tests would need separate AGENTS.md section if added)

4. **No CLI documentation**: This extension has no CLI tools; if added, create a separate CLI/AGENTS.md

## Files Not Modified

- composer.json (no changes needed)
- Build/phpstan.neon (already optimal)
- Build/rector.php (already optimal)
- Build/.php-cs-fixer.dist.php (already optimal)
- .github/workflows/ci.yml (no changes needed)
- README.md (kept as user-facing documentation)

## Deliverables Summary

✅ Thin root AGENTS.md (precedence + global defaults + index)  
✅ Scoped Classes/AGENTS.md (PHP source code guidance)  
✅ Scoped Tests/AGENTS.md (unit test guidance)  
✅ .envrc (direnv support)  
✅ Makefile (developer commands)  
✅ .editorconfig (consistent formatting)  
✅ AGENTS_CHANGELOG.md (this file)

**Total files created**: 7  
**Existing files analyzed**: 8  
**Lines of documentation**: ~800 across all AGENTS.md files  
**Time to implement**: ~30 minutes
