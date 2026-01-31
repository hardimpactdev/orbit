---
date: 2026-01-31
problem_type: code-organization
component: packages/app controllers
severity: moderate
symptoms:
  - "Controller over 1000 lines"
  - "Too many constructor dependencies"
  - "Methods covering multiple domains"
root_cause: Organic growth without refactoring
tags: [refactoring, controllers, laravel, testing]
---

# Controller Split Pattern with Test Safety

## Symptom

EnvironmentController grew to 1853 lines with 74 public methods covering:
- CRUD operations
- Service control
- Configuration
- Projects
- Workspaces
- Worktrees
- etc.

## Solution

### Step 1: Add Tests First

Before splitting, add comprehensive tests for the methods you'll move:

```php
// tests/Feature/EnvironmentServiceControlTest.php
describe('start', function () {
    it('starts all services for an environment', function () {
        $environment = Environment::factory()->create();

        $this->mock(ServiceControlService::class, function ($mock) {
            $mock->shouldReceive('start')
                ->once()
                ->andReturn(['success' => true, 'message' => 'Services started']);
        });

        $response = $this->post(route('environments.start', $environment));
        $response->assertJson(['success' => true]);
    });
});
```

### Step 2: Create New Controller

Extract cohesive method groups into focused controllers:

```php
// src/Http/Controllers/EnvironmentServiceController.php
class EnvironmentServiceController extends Controller
{
    public function __construct(
        protected ServiceControlService $serviceControl,
    ) {}

    public function start(Request $request, Environment $environment): JsonResponse
    {
        $project = $request->input('project');
        $result = $this->serviceControl->start($environment, $project);
        return response()->json($result);
    }
    // ... other service methods
}
```

### Step 3: Update Routes

Point routes to the new controller:

```php
// routes/environment.php
use HardImpact\Orbit\App\Http\Controllers\EnvironmentServiceController;

Route::post('start', [EnvironmentServiceController::class, 'start'])->name('environments.start');
```

### Step 4: Run Tests

Verify tests still pass after the move:

```bash
./vendor/bin/pest tests/Feature/EnvironmentServiceControlTest.php
```

### Step 5: Remove from Original

Only after tests pass, remove methods from original controller:
- Remove the method bodies
- Remove unused constructor dependencies
- Remove unused imports

### Step 6: Final Verification

Run full test suite and static analysis:

```bash
./vendor/bin/pest
./vendor/bin/phpstan analyse
./vendor/bin/pint
```

## Results

| Metric | Before | After |
|--------|--------|-------|
| EnvironmentController lines | 1853 | 1695 |
| New EnvironmentServiceController | - | 190 |
| Tests | 0 | 43 |

## Prevention

- Split controllers when they exceed ~500 lines
- Group by domain: ServiceController, ProjectController, ConfigController
- Add tests before any refactoring
- One PR per controller split

## Related

- Single Responsibility Principle
- Laravel Controller best practices
