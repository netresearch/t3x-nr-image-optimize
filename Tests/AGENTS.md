<!-- Managed by agent: keep sections and order; edit content, not structure. Last updated: 2026-04-24 -->

# AGENTS.md — Tests

<!-- AGENTS-GENERATED:START overview -->
## Overview
TYPO3 extension test suite. **Use the `typo3-testing` skill** for comprehensive guidance.
<!-- AGENTS-GENERATED:END overview -->

<!-- AGENTS-GENERATED:START filemap -->
## Test suites
| Suite | Dir | Invoker |
|-------|-----|---------|
| Unit | `Tests/Unit/` | `composer ci:test:php:unit` (uses `Build/UnitTests.xml`) |
| Functional | `Tests/Functional/` | `composer ci:test:php:functional` (uses `Build/FunctionalTests.xml`) |
| Acceptance | `Tests/Acceptance/` | `composer ci:test:php:acceptance` |
| Architecture | `Tests/Architecture/` | runs inside PHPStan via `phpat` (`composer ci:test:php:phpstan`) |
| Fuzz | `Tests/Fuzz/` | `composer ci:test:php:fuzz` |

### Key fixtures
- `Tests/Functional/ProcessorSymlinkedFileadminTest.php` — reproduces the Chemnitz EFS-symlink production layout (`public/{fileadmin,processed,uploads}` all symlinked to external mounts). Extend this when touching `Processor` allowed-roots logic.
- `Tests/Functional/Fixtures/` — DB + filesystem fixtures for functional tests.
<!-- AGENTS-GENERATED:END filemap -->

<!-- AGENTS-GENERATED:START golden-samples -->
## Golden Samples
| Pattern | Reference |
|---------|-----------|
| Unit test with reflection into `final` class | `Tests/Unit/ProcessorTest.php` |
| Functional test with real Intervention + Imagick | `Tests/Functional/ProcessorTest.php` |
| Functional test with EFS-symlink reproduction | `Tests/Functional/ProcessorSymlinkedFileadminTest.php` |
| Middleware routing test | `Tests/Functional/Middleware/ProcessingMiddlewareTest.php` |
| HTML-snapshot Acceptance test | `Tests/Acceptance/ViewHelpers/SourceSetViewHelperHtmlOutputTest.php` |
| Architecture rule (layering) | `Tests/Architecture/ArchitectureTest.php` |
| Fuzz test | `Tests/Fuzz/ProcessorFuzzTest.php` |
<!-- AGENTS-GENERATED:END golden-samples -->

<!-- AGENTS-GENERATED:START structure -->
## Test structure (this repo, verified)
```
Tests/
├── Unit/                    (no TYPO3 bootstrap beyond Environment::initialize)
│   ├── Command/              AnalyzeImages, OptimizeImages, AbstractImageCommand
│   ├── Controller/           MaintenanceController (plant ServerRequest for BE context)
│   ├── Event/                ImageProcessedEvent, VariantServedEvent
│   ├── EventListener/        OptimizeOnUploadListener
│   ├── Middleware/           ProcessingMiddleware
│   ├── Service/              ImageManagerAdapter, ImageManagerFactory,
│   │                          ImageOptimizer, SystemRequirementsService
│   ├── ViewHelpers/          SourceSetViewHelper
│   └── ProcessorTest.php
├── Functional/              (TYPO3 testing-framework; SQLite or MariaDB)
│   ├── Controller/
│   ├── Fixtures/             DB / filesystem fixtures
│   ├── Middleware/
│   ├── ViewHelpers/
│   ├── ProcessorTest.php
│   └── ProcessorSymlinkedFileadminTest.php
├── Acceptance/              (HTML snapshot, routing)
│   ├── Middleware/
│   └── ViewHelpers/
├── Architecture/            (phpat rules, runs inside PHPStan)
└── Fuzz/
```
<!-- AGENTS-GENERATED:END structure -->

<!-- AGENTS-GENERATED:START commands -->
## Running Tests
| Type | Command |
|------|---------|
| Unit tests | `composer ci:test:php:unit` or `Build/Scripts/runTests.sh -s unit` |
| Functional tests | `composer ci:test:php:functional` or `Build/Scripts/runTests.sh -s functional` |
| Single file | `Build/Scripts/runTests.sh -s unit -p Tests/Unit/Path/To/Test.php` |
| Coverage | `composer ci:test:php:unit:coverage` (HTML report → `.build/coverage/`) |
<!-- AGENTS-GENERATED:END commands -->

<!-- AGENTS-GENERATED:START patterns -->
## Key patterns (this repo)
- **Unit tests** extend `\PHPUnit\Framework\TestCase` (not `UnitTestCase`). `Environment::initialize()` is called in `setUp()` when the SUT needs TYPO3 paths.
- **Functional tests** extend `\TYPO3\TestingFramework\Core\Functional\FunctionalTestCase`.
- **Strict coverage metadata** is on (`beStrictAboutCoverageMetadata="true"` in `Build/FunctionalTests.xml`). Every functional test must carry `#[CoversClass]` AND a `#[UsesClass]` for every other prod class it executes — otherwise the test gets marked **risky** and CI fails.
  - When a PR changes DI in `Classes/Processor.php` / `Classes/Middleware/*` / `Classes/Event/*` / `Classes/Service/*`, re-check `#[UsesClass]` lists in `Tests/Functional/{ProcessorTest,ProcessingMiddlewareTest,ProcessorSymlinkedFileadminTest}.php`.
- **Coverage driver**: xdebug (not pcov). Local/CI parity is enforced. Don't switch to pcov without updating `.github/workflows/ci.yml`.
- **Imagick**: Functional tests that encode images require the `imagick` PHP extension. CI installs it via `shivammathur/setup-php --extensions: imagick`. Locally, use `make test-functional` (Docker image has Imagick) or install `pecl imagick` on host PHP.
- **Real DB**: functional tests use SQLite by default on CI. To run against MariaDB/MySQL locally, pass the DBMS via `-d`: `Build/Scripts/runTests.sh -s functional -d mariadb` (or `-d mysql`, `-d postgres`).
- **Mocks**: use PHPUnit `createMock()`. Avoid Prophecy (project uses PHPUnit's built-in mock builder).
<!-- AGENTS-GENERATED:END patterns -->

<!-- AGENTS-GENERATED:START code-style -->
## Code Style
- Test class name matches source: `MyClass` → `MyClassTest`
- Test methods: `test` prefix or `@test` annotation
- One assertion concept per test
- Use data providers for multiple similar cases
- Mock external services, never real HTTP calls
<!-- AGENTS-GENERATED:END code-style -->

<!-- AGENTS-GENERATED:START checklist -->
## PR Checklist
- [ ] All tests pass: `composer ci:test:php:unit && composer ci:test:php:functional`
- [ ] New functionality has tests
- [ ] Fixtures are minimal and focused
- [ ] No hardcoded credentials or paths
- [ ] Coverage hasn't decreased
<!-- AGENTS-GENERATED:END checklist -->

<!-- AGENTS-GENERATED:START skill-reference -->
## Skill Reference
> For comprehensive TYPO3 testing guidance including fixtures, mocking, CI setup, and runTests.sh:
> **Invoke skill:** `typo3-testing`
<!-- AGENTS-GENERATED:END skill-reference -->
