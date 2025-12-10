<!-- Managed by agent: keep sections & order; edit content, not structure. Last updated: 2025-12-10 -->

# Agent Instructions — Classes/

PHP source code for the nr_image_optimize TYPO3 extension.

## Overview

This directory contains:
- **Middleware/ProcessingMiddleware.php**: PSR-15 middleware intercepting `/processed/` image requests
- **Processor.php**: Core image optimization logic (WebP/AVIF conversion, resizing)
- **ViewHelpers/SourceSetViewHelper.php**: Fluid ViewHelper for responsive `<picture>` / `<img>` tags

**Architecture**: PSR-15 middleware → Processor → Intervention Image library

## Setup & Build

All commands must use `nrdev` (see root AGENTS.md):

```bash
# Lint this directory
nrdev composer ci:test:php:lint

# Static analysis
nrdev composer ci:test:php:phpstan

# Format code
nrdev composer ci:cgl
```

## Code Style

Inherits from root AGENTS.md, with specifics:

### TYPO3 Patterns
- **Dependency Injection**: Constructor injection for services (e.g., `Processor` injected into `ProcessingMiddleware`)
- **PSR-7/PSR-15**: HTTP message interfaces for request/response handling
- **Fluid ViewHelpers**: Extend `AbstractViewHelper`, implement `initializeArguments()` and `render()`

### Type Safety
- **Readonly properties** for immutable dependencies (PHP 8.1+)
- **Strict parameter/return types** (no mixed, no docblock-only types)
- **Union types** where appropriate (e.g., `int|float` for dimensions)

### Example: Middleware Pattern
```php path=/home/axel/Projekte/Chemnitz/CMS/main/app/vendor/netresearch/nr-image-optimize/Classes/Middleware/ProcessingMiddleware.php start=32
public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
{
    if (str_starts_with($request->getUri()->getPath(), '/processed/')) {
        $this->processor->setRequest($request);
        $this->processor->generateAndSend();
        exit;
    }

    return $handler->handle($request);
}
```
**Pattern**: Early exit for matching path (no else needed)

### Example: ViewHelper Argument Registration
```php path=/home/axel/Projekte/Chemnitz/CMS/main/app/vendor/netresearch/nr-image-optimize/Classes/ViewHelpers/SourceSetViewHelper.php start=35
public function initializeArguments(): void
{
    parent::initializeArguments();

    $this->registerArgument('path', 'string', 'Path to the original image', true);
    $this->registerArgument('set', 'array', 'Array of image sizes', false, []);
    $this->registerArgument('width', 'int|float', 'Width of the original image', false, 0);
    $this->registerArgument('height', 'int|float', 'Height of the original image', false, 0);
    // ...
}
```
**Pattern**: Type-safe argument registration with required/optional flags and defaults

## Security

### Input Validation
- **Path traversal**: Middleware must validate that paths start with `/processed/` and don't contain `..`
- **Dimension limits**: Processor should enforce max width/height to prevent memory exhaustion
- **Format whitelist**: Only allow safe image formats (jpg, png, webp, avif, svg)

### Example: Bad (Unsafe)
```php path=null start=null
// ❌ No validation — allows path traversal
$path = $_GET['path'];
file_get_contents($path);
```

### Example: Good (Safe)
```php path=null start=null
// ✅ Validate prefix and sanitize
if (!str_starts_with($path, '/processed/')) {
    throw new \InvalidArgumentException('Invalid path');
}
$path = PathUtility::getCanonicalPath($path);
```

## Testing

Unit tests located in `Tests/Unit/` (see Tests/AGENTS.md).

When changing code here:
1. Update corresponding unit tests
2. Run `nrdev composer ci:test:php:unit`
3. Ensure coverage for new logic (use `XDEBUG_MODE=coverage`)

## Good vs Bad Examples

### Good: Typed Properties with Readonly
```php path=null start=null
class Processor
{
    public function __construct(
        private readonly ImageManager $imageManager,
        private readonly CacheManager $cache,
    ) {}
}
```

### Bad: Untyped, Mutable Properties
```php path=null start=null
class Processor
{
    // ❌ No types, no readonly
    private $imageManager;
    private $cache;
    
    public function __construct($imageManager, $cache)
    {
        $this->imageManager = $imageManager;
        $this->cache = $cache;
    }
}
```

### Good: Global Function Imports
```php path=null start=null
use function str_starts_with;
use function sprintf;
use function getimagesize;

// Later in code:
if (str_starts_with($path, '/processed/')) {
    // ...
}
```

### Bad: Namespace-Qualified Calls
```php path=null start=null
// ❌ Verbose, no static analysis benefit
if (\str_starts_with($path, '/processed/')) {
    // ...
}
```

## When Stuck

1. **Middleware not triggering?**
   - Check `Configuration/Services.yaml` — ensure middleware is registered
   - Verify path starts with `/processed/` (case-sensitive)
   - Check TYPO3 middleware order

2. **Image not processing?**
   - Verify Intervention Image is installed (`nrdev composer show intervention/image`)
   - Check PHP GD/Imagick extensions are enabled
   - Inspect error logs (TYPO3 logs to `var/log/`)

3. **ViewHelper not rendering?**
   - Ensure namespace is registered in Fluid template: `{namespace nr=Netresearch\NrImageOptimize\ViewHelpers}`
   - Check required arguments are provided (`path` is mandatory)
   - Review `$escapeOutput` and `$escapeChildren` settings

4. **Static analysis errors?**
   - Run `nrdev composer ci:test:php:phpstan` for detailed errors
   - Check phpstan-baseline.neon — may need regeneration: `nrdev composer ci:test:php:phpstan:baseline`

## House Rules

Beyond root AGENTS.md defaults:

- **Processor class**: Keep logic focused on image manipulation — delegate HTTP concerns to middleware
- **ViewHelper**: Must be **stateless** (no mutable properties between renders)
- **Error handling**: Use TYPO3 PSR-3 logging, not `echo` or `var_dump`
- **Performance**: Cache processed images (check if file exists before regenerating)
