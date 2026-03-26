# Contributing

Thank you for considering a contribution to **nr_image_optimize**.

## Getting Started

1. Fork the repository and clone your fork.
2. Install dependencies:
   ```bash
   composer install
   ```
3. Create a feature branch from `main`:
   ```bash
   git checkout -b feature/your-feature main
   ```

## Development

### Coding Standards

This project follows [PSR-12](https://www.php-fig.org/psr/psr-12/) and enforces style via PHP-CS-Fixer:

```bash
make cgl          # Check code style (dry-run)
make cgl-fix      # Auto-fix code style
```

### Static Analysis

PHPStan at level 8 and Rector are used for static analysis:

```bash
make phpstan      # Run PHPStan
make rector       # Run Rector dry-run
```

### Testing

```bash
make test         # Run unit tests
make test-fuzz    # Run fuzz tests
```

### Full CI Check

Run all checks locally before submitting:

```bash
composer ci:test
```

## Pull Requests

1. **Open an issue first** to discuss your proposed change.
2. Keep PRs focused on a single concern.
3. Ensure all CI checks pass before requesting review.
4. Follow the pull request template.

## Reporting Issues

- Use the [bug report template](https://github.com/netresearch/t3x-nr-image-optimize/issues/new?template=bug_report.md) for bugs.
- Use the [feature request template](https://github.com/netresearch/t3x-nr-image-optimize/issues/new?template=feature_request.md) for enhancements.

## Security

For security vulnerabilities, please follow the [Security Policy](SECURITY.md). Do **not** open public issues.

## License

By contributing, you agree that your contributions will be licensed under the [GPL-3.0-or-later](LICENSE) license.
