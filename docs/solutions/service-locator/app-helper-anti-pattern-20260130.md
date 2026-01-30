---
date: 2026-01-30
problem_type: architecture-anti-pattern
component: all-packages
severity: high
symptoms:
  - "Using app() helper to resolve dependencies"
  - "Hidden dependencies make testing difficult"
  - "Classes not testable in isolation"
root_cause: Using Laravel's service locator pattern instead of dependency injection
tags: [dependency-injection, service-locator, app-helper, testing, clean-code]
---

# Service Locator Anti-Pattern (app() Helper)

## Symptom
Code using `app()` helper to resolve dependencies:

```php
// BAD - Service locator pattern
$result = app(InstallComposerDependencies::class)->handle($context, $logger);
$manager = app(ServiceManager::class);
```

Problems:
- Hidden dependencies
- Hard to test (can't mock easily)
- Violates dependency inversion principle
- IDE can't track dependencies

## Root Cause
Using Laravel's service locator (`app()`) instead of constructor injection for dependencies that are known at compile time.

## Solution

### Pattern 1: Constructor Injection (Preferred)

**Before:**
```php
class ProvisionPipeline
{
    public function run(ProvisionContext $context, ProvisionLoggerContract $logger): StepResult
    {
        $result = app(InstallComposerDependencies::class)->handle($context, $logger);
        // ...
    }
}
```

**After:**
```php
final readonly class ProvisionPipeline
{
    public function __construct(
        private InstallComposerDependencies $installComposer,
        private DetectNodePackageManager $detectNode,
        // ... all dependencies
    ) {}

    public function run(ProvisionContext $context, ProvisionLoggerContract $logger): StepResult
    {
        $result = $this->installComposer->handle($context, $logger);
        // ...
    }
}
```

### Pattern 2: For Laravel Commands

**Before:**
```php
final class InstallCommand extends Command
{
    public function handle(): int
    {
        $pipeline = PHP_OS_FAMILY === 'Darwin'
            ? app(InstallMacPipeline::class)
            : app(InstallLinuxPipeline::class);
    }
}
```

**After:**
```php
final class InstallCommand extends Command
{
    public function __construct(
        private InstallMacPipeline $macPipeline,
        private InstallLinuxPipeline $linuxPipeline
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $pipeline = PHP_OS_FAMILY === 'Darwin'
            ? $this->macPipeline
            : $this->linuxPipeline;
    }
}
```

### Pattern 3: For Platform-Specific Dependencies

**Before:**
```php
$action = PHP_OS_FAMILY === 'Darwin'
    ? app(MacTrustRootCa::class)
    : app(LinuxTrustRootCa::class);
```

**After:**
```php
public function __construct(
    private MacTrustRootCa $macTrustRootCa,
    private LinuxTrustRootCa $linuxTrustRootCa
) {
    parent::__construct();
}

// In handle():
$action = PHP_OS_FAMILY === 'Darwin'
    ? $this->macTrustRootCa
    : $this->linuxTrustRootCa;
```

## When app() is Acceptable

1. **Dynamic resolution**: `app($step['action'])` when action class is determined at runtime
2. **Optional dependencies**: When a service might not be registered
3. **Service provider registration**: In `register()` and `boot()` methods
4. **Facades**: Laravel facades are an acceptable form of service locator for their intended use cases

## Prevention

### Code Review Checklist:
- [ ] No `app(ClassName::class)` calls in business logic
- [ ] All dependencies injected via constructor
- [ ] `readonly` classes for immutable dependencies
- [ ] Constructor property promotion used
- [ ] Tests use mocked dependencies, not `app()->instance()`

### IDE Detection:
Add to PHPStan config to catch `app()` usage:
```neon
parameters:
    ignoreErrors:
        # Allow app() in specific patterns
        - 
            message: '#^Call to function app\(\)#'
            path: app/Providers/
```

## Migration Stats
- **Core package**: 16 `app()` calls eliminated
- **App package**: 3 `app()` calls eliminated  
- **CLI package**: 17 `app()` calls eliminated
- **Total**: 36 service locator calls replaced with DI

## Related
- phpstan-baseline-neon-regeneration.md
- testing-dependency-injection.md
