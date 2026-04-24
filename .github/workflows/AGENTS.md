<!-- Managed by agent: keep sections and order; edit content, not structure. Last updated: 2026-04-24 -->

# AGENTS.md — workflows

<!-- AGENTS-GENERATED:START overview -->
## Overview
GitHub Actions workflows and CI/CD automation
<!-- AGENTS-GENERATED:END overview -->

<!-- AGENTS-GENERATED:START filemap -->
## Key Files
| File | Purpose |
|------|---------|
| `ci.yml` | Matrix CI: lint / phpstan / unit / functional (SQLite) across PHP 8.2–8.5 × TYPO3 13.4 / 14. Thin caller of the reusable `netresearch/typo3-ci-workflows/.github/workflows/ci.yml@main`. |
| `release.yml` | Thin caller of `netresearch/typo3-ci-workflows/.github/workflows/release-typo3-extension.yml@main`. Fires on signed `v*` tag push. Creates GH Release + TER upload + docs.typo3.org publish. |
| `republish.yml` | Manual `workflow_dispatch` with `target: all | ter | docs | packagist`. Use to re-trigger one publishing channel without cutting a new release. |
| `auto-merge-deps.yml` | Auto-merge green Renovate/Dependabot PRs. |
| `community.yml` | Issue/PR labelling + greeter. |
<!-- AGENTS-GENERATED:END filemap -->

<!-- AGENTS-GENERATED:START golden-samples -->
## Architecture rule
Project workflows should be **thin callers** of reusable workflows in `netresearch/typo3-ci-workflows`. Don't add inline `jobs:` that duplicate reusable-workflow logic — open a PR on `typo3-ci-workflows` instead.
<!-- AGENTS-GENERATED:END golden-samples -->

<!-- AGENTS-GENERATED:START structure -->
## Directory structure (this repo, verified)
```
.github/
  workflows/
    ci.yml                 → matrix CI (calls typo3-ci-workflows/ci.yml)
    release.yml            → release pipeline (calls release-typo3-extension.yml)
    republish.yml          → manual re-publish to TER/docs/packagist
    auto-merge-deps.yml    → dependency-bot automerge
    community.yml          → labels/greeter
  dependabot.yml           → Renovate config is separate (see renovate.json in root)
```
No `actions/` local composite actions. All reusable logic lives in `netresearch/typo3-ci-workflows`.
<!-- AGENTS-GENERATED:END structure -->

<!-- AGENTS-GENERATED:START code-style -->
## Workflow conventions (this repo)
- **Caller workflows reference reusables by tag**, e.g. `uses: netresearch/typo3-ci-workflows/.github/workflows/ci.yml@main`. The reusable repo is the place where actions are SHA-pinned; consumers don't re-pin.
- **No local reusables**: don't create `.github/workflows/reusable-*.yml` here. New shared logic goes upstream in `netresearch/typo3-ci-workflows` as a PR.
- **Minimal permissions**: each `permissions:` block lists only what the job needs; never `write-all`.
- **Never `secrets: inherit`**: pass each secret explicitly into the reusable workflow call. Supply-chain hygiene — limits blast radius if any action in the chain is compromised.
- **Required-checks list** is enforced by the `CI Required Checks` ruleset (see root AGENTS.md → Repository Settings). Any new matrix cell that becomes "required" must be added to that ruleset, not assumed.

### Naming
| Type | Convention | Example |
|------|------------|---------|
| Workflow file | `<purpose>.yml` (lowercase, hyphens) | `ci.yml`, `release.yml`, `auto-merge-deps.yml` |
| Workflow `name:` | Title Case | `CI`, `Release`, `Republish` |
| Job ID | kebab-case | `lint`, `phpstan`, `unit-tests` |
| Step `name:` | Sentence case | `Install dependencies` |
| Secret | SCREAMING_SNAKE | `TER_API_TOKEN`, `CODECOV_TOKEN` |
<!-- AGENTS-GENERATED:END code-style -->

<!-- AGENTS-GENERATED:START patterns -->
## Pattern (this repo): thin caller of a reusable workflow

```yaml
# .github/workflows/ci.yml — abridged
name: CI
on:
  push:
    branches: [main, TYPO3_12]
  pull_request:

permissions:
  contents: read

jobs:
  ci:
    uses: netresearch/typo3-ci-workflows/.github/workflows/ci.yml@main
    with:
      run-functional-tests: true
      functional-test-db: 'sqlite'
      php-extensions: 'intl, mbstring, xml, imagick, gd'
      coverage-tool: 'xdebug'
    secrets:
      CODECOV_TOKEN: ${{ secrets.CODECOV_TOKEN }}   # explicit pass-through; never `secrets: inherit`
```

The reusable workflow handles matrix expansion, action pinning, and PHPStan/PHPUnit invocation. To change matrix dimensions or add a tool, open a PR on `netresearch/typo3-ci-workflows` rather than forking the logic here.
<!-- AGENTS-GENERATED:END patterns -->

<!-- AGENTS-GENERATED:START security -->
## Security & safety
- Never `secrets: inherit` — pass each secret explicitly into the reusable-workflow call.
- Action pinning lives in the reusable workflows (`netresearch/typo3-ci-workflows`); consumers reference reusables by tag.
- Minimal `permissions:` block per job. Default to `contents: read`; add `pull-requests: write` only where needed (auto-approve, labeller).
- Don't echo secrets. If a step constructs a secret-derived value, use `::add-mask::` before the first emission.
<!-- AGENTS-GENERATED:END security -->

<!-- AGENTS-GENERATED:START checklist -->
## PR/commit checklist
- [ ] Workflow syntax valid (`actionlint` runs in CI via reviewdog, `fail_level: error`).
- [ ] No `secrets: inherit`. Each secret passed explicitly.
- [ ] If a new check needs to be merge-blocking, add it to the `CI Required Checks` ruleset (admin action).
- [ ] If duplicating logic that exists in `netresearch/typo3-ci-workflows`, open the PR there instead.
<!-- AGENTS-GENERATED:END checklist -->

<!-- AGENTS-GENERATED:START examples -->
## Reference
- This repo's `ci.yml`, `release.yml`, `republish.yml` are the canonical thin-caller examples.
- Reusable workflow source: https://github.com/netresearch/typo3-ci-workflows/.github/workflows/
<!-- AGENTS-GENERATED:END examples -->

<!-- AGENTS-GENERATED:START help -->
## When stuck
- The reusable-workflow source lives in `netresearch/typo3-ci-workflows` — read its `.github/workflows/*.yml` to understand inputs/secrets/jobs.
- GitHub Actions reference: https://docs.github.com/en/actions/reference/workflow-syntax-for-github-actions
- For local dry-runs of the matrix expansion: use `act` against a checkout of the reusable repo (not this one).
<!-- AGENTS-GENERATED:END help -->
