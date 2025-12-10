# AGENTS.md Quick Start Guide

**For**: Developers and AI agents working on nr_image_optimize  
**Updated**: 2025-12-10

## What is this?

This project follows the **agents.md convention** — a hierarchical documentation system for AI-assisted development. Each `AGENTS.md` file provides context-specific instructions for code generation, testing, and best practices.

## File Structure

```
nr-image-optimize/
├── AGENTS.md              ← START HERE (global defaults, precedence rules)
├── Classes/AGENTS.md      ← PHP source code guidelines
├── Tests/AGENTS.md        ← Unit testing guidelines
├── Makefile               ← Developer commands (make help)
├── .envrc                 ← Environment setup (direnv)
└── .editorconfig          ← Code formatting rules
```

## Precedence Rules

**Nearest file wins**: 
- Classes/AGENTS.md overrides root AGENTS.md for PHP code
- Tests/AGENTS.md overrides root AGENTS.md for tests
- Root AGENTS.md provides defaults for everything else

## Quick Commands

```bash
# Show all available commands
make help

# Install dependencies
make install          # or: nrdev composer install

# Linting & formatting
make lint             # Check PHP syntax
make format           # Fix code style

# Static analysis
make typecheck        # Run PHPStan (level 8)

# Testing
make test             # Run unit tests

# Full CI suite
make ci               # Run all checks

# Rector refactoring
make rector           # Apply refactorings
make rector-dry       # Preview refactorings
```

**Important**: All commands use `nrdev` prefix (Netresearch devbox).

## Key Conventions

### Code Style
- **PHP 8.1+** (strict types required)
- **PSR-12** + **PER-CS 2.0** + **Symfony** style
- **PHPStan level 8** (strict)
- **Rector** (SOLID, KISS, DRY, Early Return)

### File Header (Required)
```php
<?php

/**
 * This file is part of the package netresearch/nr-image-optimize.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);
```

### Commit Format (Required)
Use [Conventional Commits](https://www.conventionalcommits.org/):
- `feat: add AVIF support`
- `fix: prevent path traversal`
- `docs: update README`
- `refactor: extract dimension logic`
- `test: add middleware tests`

### PR Checklist
- [ ] `make ci` passes
- [ ] Tests added/updated
- [ ] Commit messages follow convention
- [ ] PR ≤ 300 net LOC (preferred)

## Common Patterns

### Good: Readonly Properties
```php
class ProcessingMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Processor $processor
    ) {}
}
```

### Good: Early Return
```php
public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
{
    if (str_starts_with($request->getUri()->getPath(), '/processed/')) {
        // Handle processed images
        exit;
    }

    return $handler->handle($request);
}
```

### Good: Global Function Imports
```php
use function str_starts_with;
use function sprintf;

if (str_starts_with($path, '/processed/')) {
    // ...
}
```

## Where to Look

| Task | File |
|------|------|
| Overall guidelines | `AGENTS.md` |
| PHP code rules | `Classes/AGENTS.md` |
| Test writing | `Tests/AGENTS.md` |
| Commands | `Makefile` |
| Code formatting | `.editorconfig` |
| Build config | `composer.json`, `Build/*` |

## Troubleshooting

**Command not found?**  
→ Run `nrdev composer install` first

**Static analysis errors?**  
→ Run `make typecheck` for details

**Middleware not working?**  
→ See Classes/AGENTS.md "When Stuck" section

**Tests failing?**  
→ See Tests/AGENTS.md "When Stuck" section

## Need More Details?

Read the full AGENTS.md files:
1. Start with root `AGENTS.md`
2. Dive into `Classes/AGENTS.md` for PHP specifics
3. Check `Tests/AGENTS.md` for testing guidance

Each file includes:
- Setup instructions
- Code style examples
- Security guidelines
- Good vs bad patterns
- Troubleshooting tips

## Contributing

1. Read relevant AGENTS.md file(s)
2. Follow code style conventions
3. Write tests for new features
4. Run `make ci` before committing
5. Use Conventional Commits format
6. Keep PRs focused and small

---

**Questions?** Check the "When Stuck" sections in AGENTS.md files or consult the TYPO3 documentation at https://docs.typo3.org/
