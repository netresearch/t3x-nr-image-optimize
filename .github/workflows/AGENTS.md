<!-- Managed by agent: keep sections and order; edit content, not structure. Last updated: 2026-08-19 -->

# AGENTS.md — workflows

<!-- AGENTS-GENERATED:START overview -->
## Overview
GitHub Actions workflows and CI/CD automation
<!-- AGENTS-GENERATED:END overview -->

<!-- AGENTS-GENERATED:START filemap -->
## Workflow files
| File | Purpose |
|------|---------|
| `ci.yml` | Matrix CI: lint / phpstan / unit / functional / acceptance (SQLite) across PHP 8.2–8.5 × TYPO3 ^13.4 / ^14.0. Thin caller of the reusable `netresearch/typo3-ci-workflows/.github/workflows/ci.yml@main`. Per-extension matrix = intentional drift. |
| `checks.yml` | Security + quality jobs (security, gitleaks, zizmor, fuzz, license-check, CodeQL, scorecard, dependency-review, pr-quality) funnelled into one `gate` job named `All security checks`. **Byte-identical and drift-enforced across every typo3-extension** — change it via the template, not here. Any job added must also be added to `gate.needs`. |
| `check-template-drift.yml` | Enforces `checks.yml` template parity (`netresearch/.github` reusable, `template: typo3-extension`). |
| `release.yml` | Thin caller of `netresearch/typo3-ci-workflows/.github/workflows/release-typo3-extension.yml@main`. Fires on signed `v*` tag push. Creates GH Release + TER upload + docs.typo3.org publish. |
| `republish.yml` | Manual `workflow_dispatch` with `target: all | ter | docs | packagist`. Use to re-trigger one publishing channel without cutting a new release. |
| `auto-merge-deps.yml` | Auto-merge green Renovate/Dependabot PRs. |
| `labeler.yml` | PR labelling from changed paths (config: `.github/labeler.yml`). |
| `community.yml` | Stale/lock/greetings automation (`stale.yml`, `lock.yml`, `greetings.yml` reusables). |
| `harness-verify.yml` | Agent-harness consistency check — runs `Build/Scripts/verify-harness.sh` via the shared `script-check.yml` reusable. |
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
    ci.yml                    → matrix CI (calls typo3-ci-workflows/ci.yml)
    checks.yml                → security/quality gate (drift-enforced template)
    check-template-drift.yml  → template-parity enforcement for checks.yml
    release.yml               → release pipeline (calls release-typo3-extension.yml)
    republish.yml             → manual re-publish to TER/docs/packagist
    auto-merge-deps.yml       → dependency-bot automerge
    labeler.yml               → PR path-based labels
    community.yml             → stale/lock/greetings
    harness-verify.yml        → agent-harness consistency check
  labeler.yml                 → label→path config for workflows/labeler.yml
  dependabot.yml              → Renovate config is separate (see renovate.json in root)
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
- **codecov**: the patch target in `codecov.yml` accounts for extension-dependent code paths that cannot be covered in isolation.

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
## Common patterns (this repo): thin caller of a reusable workflow

```yaml
# .github/workflows/ci.yml — abridged
name: CI
on:
  push:
    branches: [main]
  pull_request:
  merge_group:
  schedule:
    - cron: '0 6 * * 1'

permissions:
  contents: read

jobs:
  ci:
    uses: netresearch/typo3-ci-workflows/.github/workflows/ci.yml@main
    permissions:
      contents: read
    with:
      php-versions: '["8.2","8.3","8.4","8.5"]'
      typo3-versions: '["^13.4","^14.0"]'
      upload-coverage: true
      run-functional-tests: true
      run-acceptance-tests: true
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
## Examples
- This repo's `ci.yml`, `release.yml`, `republish.yml` are the canonical thin-caller examples.
- Reusable workflow source: https://github.com/netresearch/typo3-ci-workflows/.github/workflows/
<!-- AGENTS-GENERATED:END examples -->

<!-- AGENTS-GENERATED:START help -->
## When stuck
- The reusable-workflow source lives in `netresearch/typo3-ci-workflows` — read its `.github/workflows/*.yml` to understand inputs/secrets/jobs.
- GitHub Actions reference: https://docs.github.com/en/actions/reference/workflow-syntax-for-github-actions
- For local dry-runs of the matrix expansion: use `act` against a checkout of the reusable repo (not this one).
<!-- AGENTS-GENERATED:END help -->
