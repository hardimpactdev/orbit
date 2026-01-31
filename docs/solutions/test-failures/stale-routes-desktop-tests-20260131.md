---
date: 2026-01-31
problem_type: test-failures
component: packages/desktop/tests
severity: moderate
symptoms:
  - "Expected response status code [200] but received 404"
  - "Inertia page component file [environments/Settings] does not exist"
  - "No matching handler found for Mockery_*::status()"
root_cause: Route and component naming evolved but tests weren't updated
tags: [testing, routes, inertia, mocks, refactoring]
---

# Stale Route Names and Component Paths in Desktop Tests

## Symptom

53 desktop test failures with errors like:
- Routes returning 404 for `/environments/{id}/sites`
- Inertia component assertions failing for `environments/Settings`
- Mock errors: "No matching handler found for StatusService::status()"

## Investigation

1. Routes evolved from `/sites` to `/projects` in main codebase
2. Settings page renamed to Configuration (`environments/Configuration`)
3. Inertia testing config pointed to `orbit-core` but Vue pages are in `orbit-app`
4. Mocks only defined the methods being tested, not all methods called during request

## Root Cause

Codebase refactoring renamed:
- "sites" → "projects" (routes, components, terminology)
- "settings" → "configuration" (route, component name)

Tests weren't updated to match.

## Solution

### 1. Update Route Paths

```php
// Before (broken)
$this->get("/environments/{$environment->id}/sites");
$this->get("/environments/{$environment->id}/settings");

// After (fixed)
$this->get("/environments/{$environment->id}/projects");
$this->get("/environments/{$environment->id}/configuration");
```

### 2. Update Inertia Component Assertions

```php
// Before (broken)
$response->assertInertia(fn ($page) => $page->component('environments/Sites'));
$response->assertInertia(fn ($page) => $page->component('environments/Settings'));

// After (fixed)
$response->assertInertia(fn ($page) => $page->component('environments/Projects'));
$response->assertInertia(fn ($page) => $page->component('environments/Configuration'));
```

### 3. Fix Inertia Testing Config

```php
// config/inertia.php - testing.page_paths

// Before (broken - pages aren't in orbit-core)
base_path('vendor/hardimpactdev/orbit-core/resources/js/pages'),

// After (fixed - pages are in orbit-app)
base_path('vendor/hardimpactdev/orbit-app/resources/js/pages'),
```

### 4. Add All Mock Method Expectations

```php
// Before (broken - only mocked what test asserts)
$this->mock(StatusService::class, function ($mock) {
    $mock->shouldReceive('checkInstallation')->andReturn([...]);
});

// After (fixed - mock ALL methods called during request)
$this->mock(StatusService::class, function ($mock) {
    $mock->shouldReceive('checkInstallation')->andReturn([...]);
    $mock->shouldReceive('status')->andReturn([  // Also called!
        'success' => true,
        'data' => ['services' => [], 'host_services' => []],
    ]);
});
```

### 5. Create Missing .env Files

```bash
# Prevents "file_get_contents(.env): Failed to open stream" warnings
cat > packages/desktop/.env << 'EOF'
APP_NAME=OrbitDesktop
APP_ENV=testing
APP_KEY=base64:dUl1K5d/wTJb/alq1DQPd7zsCUx9SDEhpQOfWx9jIE4=
DB_CONNECTION=sqlite
DB_DATABASE=:memory:
EOF
```

## Prevention

1. **When renaming routes/components**: Search tests for old names
   ```bash
   grep -r "sites" packages/desktop/tests/
   grep -r "Settings" packages/desktop/tests/
   ```

2. **Run tests after route refactoring**: Catch 404s immediately

3. **Mock setup rule**: When mocking a service, trace the full request path to identify ALL methods that will be called, not just the one being tested

4. **Inertia config**: When moving Vue pages between packages, update both:
   - `config/inertia.php` → `page_paths`
   - `config/inertia.php` → `testing.page_paths`

## Related

- `packages/app/routes/environment.php` - Canonical route definitions
- `packages/app/resources/js/pages/` - Vue page components
- `packages/desktop/config/inertia.php` - Inertia testing config
