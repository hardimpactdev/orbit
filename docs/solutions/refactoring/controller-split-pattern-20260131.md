---
date: 2026-01-31
problem_type: maintainability
component: packages/app/src/Http/Controllers
severity: moderate
symptoms:
  - "EnvironmentController.php is 1,672 lines with 77 methods"
  - "Hard to navigate and find relevant code"
  - "Single file touches 15+ service dependencies"
root_cause: Controller grew organically without periodic refactoring
tags: [refactoring, controllers, single-responsibility]
---

# Controller Split Pattern for Large Controllers

## Symptom

EnvironmentController.php grew to 1,672 lines with 77 methods, making it difficult to maintain and violating single responsibility principle.

## Investigation

1. Analyzed method groupings by responsibility
2. Identified shared code that could become traits
3. Checked for naming conflicts with existing controllers

## Root Cause

Controller accumulated functionality over time without periodic refactoring. Common in rapidly evolving features.

## Solution

### Step 1: Extract Shared Traits First

Create reusable traits for common helper methods:

```php
// app/Http/Controllers/Concerns/ProvidesRemoteApiUrl.php
trait ProvidesRemoteApiUrl
{
    protected function getRemoteApiUrl(Environment $environment): ?string
    {
        if (! $environment->is_local && $environment->tld) {
            return "https://orbit.{$environment->tld}/api";
        }
        return null;
    }
}
```

### Step 2: Extract Controllers by Responsibility

Group methods by domain and extract to focused controllers:

| Responsibility | Controller | Methods |
|----------------|------------|---------|
| CRUD + settings | EnvironmentController | 15-20 |
| Status/health | EnvironmentStatusController | 5 |
| Config management | EnvironmentConfigController | 6 |
| Project CRUD | EnvironmentProjectController | 13 |
| PHP config | PhpConfigController | 6 |
| Workspace mgmt | WorkspaceController | 10 |
| Worktree mgmt | WorktreeController | 3 |
| Package linking | PackageController | 3 |

### Step 3: Update Routes

Routes were already split between:
- `routes/environment.php` - Session-based routes (Inertia pages, forms with CSRF)
- `routes/api.php` - Stateless API routes (Vue async calls)

Update each route file to point to new controllers.

### Step 4: Verify with Quality Gates

```bash
./vendor/bin/phpstan analyse --memory-limit=512M
./vendor/bin/pest
```

## Naming Conflicts

When extracting a controller, check for existing files first:

```bash
ls -la src/Http/Controllers/ | grep -i project
```

In this case, `ProjectController.php` already existed for Saloon connector pattern (uses "active environment" approach). Named the new controller `EnvironmentProjectController.php` to avoid conflict and clarify it uses explicit environment parameter.

## Prevention

- Refactor controllers when they exceed ~500 lines
- Group related methods together for easier extraction later
- Use traits early for cross-controller shared code
- Consider domain-driven controller naming (EnvironmentXxxController)

## Related

- Dual route patterns documented in CLAUDE.md under "Direct API Calls"
- ProjectController (Saloon) vs EnvironmentProjectController (explicit environment)
