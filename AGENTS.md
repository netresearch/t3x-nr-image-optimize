<!-- Managed by agent: keep sections & order; edit content, not structure. Last updated: 2025-12-10 -->

# Agent Instructions — nr_image_optimize

TYPO3 extension providing image optimization with lazy processing, modern format support (WebP/AVIF), and performance optimizations.

## Precedence

**This file takes precedence over any other agent instruction files** (CLAUDE.md, GEMINI.md, COPILOT.md, .cursor/**, etc.).

For specific components, consult the scoped AGENTS.md files:
- [Classes/AGENTS.md](Classes/AGENTS.md) — PHP source code (middleware, processors, ViewHelpers)
- [Tests/AGENTS.md](Tests/AGENTS.md) — Unit tests with TYPO3 testing framework

**Nearest file wins**: Instructions in subdirectory AGENTS.md override this root file.

## Overview

**Type**: TYPO3 CMS Extension (v11.5 / v12)  
**Language**: PHP 8.1 – 8.4  
**Key Dependencies**: Intervention Image 3.x, TYPO3 Core  
**License**: GPL-2.0-or-later  
**Package**: `netresearch/nr-image-optimize`

**Core functionality**:
- Middleware-based image processing on `/processed/` paths
- Lazy image generation (only when requested)
- Modern format support (WebP, AVIF) with fallbacks
- Responsive `<nr:sourceSet>` ViewHelper for TYPO3 Fluid templates
- Performance optimizations for Core Web Vitals (LCP)

## Environment Setup

### Prerequisites
- PHP 8.1+ with extensions: `mbstring`, `intl`, `json`, `dom`, `curl`, `xml`, `zip`, `opcache`, `gd`
- Composer 2.x
- TYPO3 11.5 or 12.x
- **nrdev** (this project uses Netresearch devbox — prepend `nrdev` to all CLI commands)

### Initial Setup
```bash
# Install dependencies
nrdev composer install

# Verify setup
nrdev composer validate --strict
```

### Development with nrdev
This is a **TYPO3 project with nrdev devbox**. All commands **must** be prefixed with `nrdev`:
```bash
# ✅ Correct
nrdev composer ci:test
nrdev php -l Classes/Processor.php
nrdev ./vendor/bin/phpstan analyze

# ❌ Incorrect
composer ci:test
php -l Classes/Processor.php
```

## Build & Test Commands

All commands are Composer scripts (run with `nrdev composer <script>`):

```bash
# Full test suite (lint, format, static analysis, rector)
nrdev composer ci:test

# Individual checks
nrdev composer ci:test:php:cgl        # Code style (php-cs-fixer dry-run)
nrdev composer ci:test:php:lint       # PHP syntax lint
nrdev composer ci:test:php:phpstan    # Static analysis (level 8)
nrdev composer ci:test:php:rector     # Code quality checks (dry-run)
nrdev composer ci:test:php:unit       # PHPUnit tests (with coverage)

# Auto-fix
nrdev composer ci:cgl                 # Fix code style
nrdev composer ci:php:rector          # Apply rector refactorings
```

### Fast Checks (for pre-commit)
```bash
# Run only fast, non-mutating checks
nrdev composer ci:test:php:lint
nrdev composer ci:test:php:cgl
```

## Code Style & Quality

### PHP Standards
- **PSR-12** + **PER-CS 2.0** + **Symfony** coding style
- **Strict types** required (`declare(strict_types=1);`)
- **PHP 8.1 minimum** (for compatibility across supported versions)
- **PHPStan level 8** with strict rules enabled
- **Rector** with SOLID, KISS, DRY, Early Return, Type Declaration sets

### Rules Applied
- **SOLID principles** (Rector enforces single responsibility, interface segregation)
- **Early Return** pattern (Rector SET)
- **Type declarations** for all method signatures and properties
- **Global namespace imports** (classes, constants, functions)
- **Aligned assignment operators** (= and =>)
- **No Yoda conditions** (prefer `$var === 'value'` over `'value' === $var`)

### File Header
All PHP files must include this header after `<?php`:
```php
/**
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);
```

### Example: Good PHP Code
```php path=/home/axel/Projekte/Chemnitz/CMS/main/app/vendor/netresearch/nr-image-optimize/Classes/Middleware/ProcessingMiddleware.php start=10
declare(strict_types=1);

namespace Netresearch\NrImageOptimize\Middleware;

use Netresearch\NrImageOptimize\Processor;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function str_starts_with;

class ProcessingMiddleware implements MiddlewareInterface
{
    private readonly Processor $processor;

    public function __construct(Processor $processor)
    {
        $this->processor = $processor;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (str_starts_with($request->getUri()->getPath(), '/processed/')) {
            $this->processor->setRequest($request);
            $this->processor->generateAndSend();
            exit;
        }

        return $handler->handle($request);
    }
}
```

**Why this is good**:
- Strict types declared
- Readonly property for immutability
- Early return pattern (implicit via early exit)
- Typed parameters and return
- Global function import (`str_starts_with`)

### Example: Avoid
```php path=null start=null
// ❌ Missing strict types
<?php

namespace Foo;

class Bar
{
    // ❌ No type hints
    public function process($request)
    {
        // ❌ Late return, nested if
        if ($condition) {
            if ($anotherCondition) {
                return $result;
            }
        }
        return null;
    }
}
```

## Security

- **No secrets in VCS**: Use environment variables or TYPO3 configuration
- **Input validation**: All user-supplied data (URLs, dimensions) validated before processing
- **Path traversal protection**: Middleware validates `/processed/` paths
- **SPDX License Identifier**: GPL-2.0-or-later in composer.json

## PR & Commit Checklist

Before submitting:
- [ ] `nrdev composer ci:test` passes (all checks green)
- [ ] Unit tests added/updated for changed functionality
- [ ] Code follows PSR-12 + project style (php-cs-fixer applied)
- [ ] PHPStan level 8 passes (no new errors)
- [ ] Rector checks pass (no regressions)
- [ ] Commit messages follow [Conventional Commits](https://www.conventionalcommits.org/):
  - `feat: add AVIF support`
  - `fix: prevent path traversal in middleware`
  - `docs: update README with nrdev instructions`
  - `refactor: extract image dimension logic`
- [ ] PR is focused (≤ 300 net LOC changed preferred)
- [ ] CHANGELOG.md updated (if user-facing change)

## When Stuck

1. **Check existing code patterns**: This is a TYPO3 extension — follow TYPO3 idioms (dependency injection, PSR-15 middleware, Fluid ViewHelpers)
2. **Consult TYPO3 docs**: https://docs.typo3.org/
3. **Run static analysis**: `nrdev composer ci:test:php:phpstan` often reveals issues
4. **Check CI workflow**: `.github/workflows/ci.yml` shows the exact validation steps
5. **Review composer.json scripts**: All quality tooling is defined there

## House Rules

These defaults apply unless overridden in scoped AGENTS.md files:

### Commits & PRs
- Atomic commits with clear intent
- **Conventional Commits** format mandatory
- PR size ≤ 300 net LOC changed (preferred)
- Keep PRs focused on single concern

### Dependencies
- Use latest stable versions
- Renovate enabled (`renovate.json`)
- Major version updates must document breaking changes
- Target **PHP 8.1 minimum** for maximum compatibility

### Design Principles
- **SOLID, KISS, DRY, YAGNI**
- Composition over inheritance
- Law of Demeter (minimal coupling)
- Early return pattern (avoid deep nesting)

### API & Versioning
- **SemVer** (extension follows branch-alias `dev-main`: `1.0.x-dev`)
- TYPO3 extension conventions (ext_emconf.php, composer.json extra)

### Observability
- Structured error handling (TYPO3 PSR-3 logging)
- No secrets/tokens in logs
- Performance-critical paths (middleware) must be optimized

### A11y (ViewHelper output)
- Generated `<img>` tags include `alt` and `title` attributes
- Support native lazy loading (`loading="lazy"`)
- Responsive images via `srcset` for bandwidth optimization

### Licensing
- **GPL-2.0-or-later** (specified in composer.json and file headers)
- SPDX identifier required
