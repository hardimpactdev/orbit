---
date: 2026-01-31
problem_type: test-failure
component: packages/app tests
severity: moderate
symptoms:
  - "Tests pass individually but fail in sequence"
  - "Data from previous test appearing in current test"
  - "RefreshDatabase trait not working"
root_cause: Orchestra Testbench runs migrations manually in getEnvironmentSetUp, RefreshDatabase doesn't know how to refresh them
tags: [testing, orchestra-testbench, pest, database-isolation]
---

# Orchestra Testbench Database Isolation

## Symptom

When running Pest tests for a Laravel package using Orchestra Testbench, tests pass individually but fail when run together. Data from previous tests leaks into subsequent tests.

```
PASS  running single test
FAIL  running all tests (extra environments found)
```

## Investigation

1. Attempted: Using `RefreshDatabase` trait
   Result: Trait doesn't work because migrations are run manually in `getEnvironmentSetUp()`, not through Laravel's migration system

2. Attempted: Using `DatabaseMigrations` trait
   Result: Same issue - trait expects migrations to be in standard location

## Root Cause

Orchestra Testbench's `getEnvironmentSetUp()` method runs migrations directly:

```php
public function getEnvironmentSetUp($app)
{
    // Run migrations from orbit-core
    $coreDir = __DIR__.'/../vendor/hardimpactdev/orbit-core/database/migrations';
    foreach (\Illuminate\Support\Facades\File::allFiles($coreDir) as $migration) {
        (include $migration->getRealPath())->up();
    }
}
```

This bypasses Laravel's migration tracking, so `RefreshDatabase` doesn't know to roll back.

## Solution

Manually clean database state in `beforeEach()`:

```php
beforeEach(function () {
    // Clean database before each test
    Environment::query()->delete();

    // Set up mocks...
});
```

For multiple models, delete in order respecting foreign keys:

```php
beforeEach(function () {
    Project::query()->delete();
    Environment::query()->delete();
});
```

## Prevention

- Always add `Model::query()->delete()` in `beforeEach()` when testing with Orchestra Testbench
- Test the full test suite, not just individual tests, before committing
- Consider using SQLite `:memory:` with `--parallel` to isolate tests

## Related

- Orchestra Testbench documentation: https://packages.tools/testbench
- Pest PHP testing: https://pestphp.com
