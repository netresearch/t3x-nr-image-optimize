<!-- Managed by agent: keep sections & order; edit content, not structure. Last updated: 2025-12-10 -->

# Agent Instructions — Tests/

Unit tests for the nr_image_optimize TYPO3 extension.

## Overview

Test structure:
- **Tests/Unit/**: PHPUnit tests for Classes/ components
- Uses **TYPO3 Testing Framework** (v6/v7/v8 depending on TYPO3 version)
- PHPUnit configuration: `Build/UnitTests.xml`

## Setup & Build

Run tests with nrdev (see root AGENTS.md):

```bash
# Run all unit tests
nrdev composer ci:test:php:unit

# Run specific test file
nrdev .build/bin/phpunit -c Build/UnitTests.xml Tests/Unit/Middleware/ProcessingMiddlewareTest.php

# Run with coverage
XDEBUG_MODE=coverage nrdev .build/bin/phpunit -c Build/UnitTests.xml --coverage-html .build/coverage
```

## Code Style

Inherits from root AGENTS.md, plus:

### Test Naming
- Test class: `{ClassName}Test.php` (e.g., `ProcessorTest.php`)
- Test method: `test{MethodName}{Scenario}` (e.g., `testProcessValidImagePath`)
- Use descriptive scenarios: `testProcessThrowsExceptionForInvalidPath`

### Test Structure (AAA Pattern)
```php path=null start=null
public function testProcessValidImagePath(): void
{
    // Arrange
    $request = $this->createMock(ServerRequestInterface::class);
    $processor = new Processor(/* dependencies */);
    
    // Act
    $result = $processor->process($request);
    
    // Assert
    self::assertInstanceOf(ResponseInterface::class, $result);
}
```

### TYPO3 Testing Framework
```php path=null start=null
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

class ProcessorTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Test setup
    }
}
```

## Testing Patterns

### Mocking Dependencies
```php path=null start=null
// Good: Mock only external dependencies
public function testMiddlewareCallsProcessor(): void
{
    $processor = $this->createMock(Processor::class);
    $processor->expects(self::once())
        ->method('setRequest');
    
    $middleware = new ProcessingMiddleware($processor);
    // ...
}
```

### Testing ViewHelpers
```php path=null start=null
use TYPO3\TestingFramework\Fluid\Unit\ViewHelpers\ViewHelperBaseTestcase;

class SourceSetViewHelperTest extends ViewHelperBaseTestcase
{
    public function testRenderGeneratesImgTag(): void
    {
        $viewHelper = new SourceSetViewHelper();
        $viewHelper->setArguments([
            'path' => '/images/test.jpg',
            'width' => 800,
            'height' => 600,
        ]);
        
        $result = $viewHelper->render();
        
        self::assertStringContainsString('<img', $result);
        self::assertStringContainsString('src=', $result);
    }
}
```

## Good vs Bad Examples

### Good: Explicit Assertions
```php path=null start=null
self::assertSame(200, $response->getStatusCode());
self::assertStringContainsString('/processed/', $generatedPath);
self::assertCount(3, $imageSet);
```

### Bad: Vague Assertions
```php path=null start=null
// ❌ Too vague, doesn't verify behavior
self::assertTrue(true);
self::assertNotNull($result);
```

### Good: Data Providers
```php path=null start=null
/**
 * @dataProvider invalidPathProvider
 */
public function testProcessRejectsInvalidPaths(string $invalidPath): void
{
    // Test logic
}

public function invalidPathProvider(): array
{
    return [
        'path traversal' => ['../../../etc/passwd'],
        'absolute path' => ['/etc/passwd'],
        'no prefix' => ['images/test.jpg'],
    ];
}
```

### Bad: Duplicate Test Logic
```php path=null start=null
// ❌ Duplicated tests instead of data provider
public function testProcessRejectsPathTraversal(): void { /* ... */ }
public function testProcessRejectsAbsolutePath(): void { /* ... */ }
public function testProcessRejectsNoPrefix(): void { /* ... */ }
```

## Coverage Guidelines

- **Minimum**: 80% line coverage for Classes/
- **Critical paths**: 100% coverage for security-sensitive code (path validation, input sanitization)
- **Skip coverage**: Getters/setters with no logic, deprecated methods

### Exclude from Coverage
```php path=null start=null
/**
 * @codeCoverageIgnore
 */
public function getLegacyProperty(): string
{
    return $this->legacyProperty;
}
```

## When Stuck

1. **Test not running?**
   - Verify PHPUnit config: `Build/UnitTests.xml`
   - Check test class extends `UnitTestCase`
   - Ensure test method is public and starts with `test`

2. **Mock not working?**
   - Use `createMock()` for interfaces, `createPartialMock()` for classes with some real methods
   - Verify mock expectations: `expects(self::once())`

3. **TYPO3 dependencies failing?**
   - Check TYPO3 version compatibility in composer.json
   - Use Testing Framework's `UnitTestCase` for proper TYPO3 environment

4. **Coverage incomplete?**
   - Run with coverage HTML: `XDEBUG_MODE=coverage nrdev composer ci:test:php:unit`
   - Open `.build/coverage/index.html` to identify untested lines

## House Rules

Beyond root AGENTS.md defaults:

- **One assertion per test** (when feasible) — makes failures easier to diagnose
- **No real file I/O in unit tests** — use mocks or vfsStream for filesystem operations
- **Fast tests** — unit tests should run in < 100ms each (use integration tests for slow operations)
- **Test class in same namespace** as source class (e.g., `Tests\Unit\Middleware\ProcessingMiddlewareTest` for `Classes\Middleware\ProcessingMiddleware`)
