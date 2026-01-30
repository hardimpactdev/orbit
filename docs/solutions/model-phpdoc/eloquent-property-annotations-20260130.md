---
date: 2026-01-30
problem_type: phpstan-analysis
component: models
severity: moderate
symptoms:
  - "Access to an undefined property HardImpact\\Orbit\\Core\\Models\\Environment::$host"
  - "Call to an undefined static method HardImpact\\Orbit\\Core\\Models\\Environment::where()"
  - PHPStan reports property.notFound errors on Eloquent models
root_cause: Missing @property annotations for Eloquent model attributes
tags: [phpstan, models, phpdoc, eloquent, type-safety]
---

# Adding @property Annotations to Eloquent Models

## Symptom
PHPStan reports errors accessing model properties:

```
Access to an undefined property HardImpact\Orbit\Core\Models\Environment::$host
Call to an undefined static method HardImpact\Orbit\Core\Models\Environment::where()
```

Despite the code working correctly (Eloquent provides these via magic methods).

## Root Cause
PHPStan cannot infer properties from database migrations. Eloquent models use PHP's magic methods (`__get`, `__callStatic`) which static analysis tools cannot trace.

## Solution

### Add @property PHPDoc to Model Classes

**Environment Model Example:**
```php
<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Core\Models;

/**
 * @property int $id
 * @property string $name
 * @property string $host
 * @property string $user
 * @property int $port
 * @property bool $is_local
 * @property bool $is_active
 * @property string|null $tld
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class Environment extends Model
{
    // ...
}
```

### Template by Model Type

**Standard Model:**
```php
/**
 * @property int $id
 * @property string $name
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
```

**Model with UUID:**
```php
/**
 * @property string $id
 * @property string $name
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
```

**Model with Nullable Fields:**
```php
/**
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property array|null $metadata
 * @property \Carbon\Carbon|null $deleted_at
 */
```

## Automation

### Generate from Migration:
```php
// Parse migration to get columns
$migration = file_get_contents('database/migrations/2026_01_26_000001_create_environments_table.php');
// Extract column names and types
// Generate @property annotations
```

### IDE Support:
Most IDEs (PHPStorm, VS Code with Intelephense) will:
- Provide autocomplete for @property annotated fields
- Show type information on hover
- Navigate to model from usage

## Models Updated in This Session

| Model | Properties Added |
|-------|-----------------|
| Environment | 22 properties |
| Deployment | 8 properties |
| SshKey | 5 properties |
| TemplateFavorite | 10 properties |
| Setting | 3 properties |
| UserPreference | 4 properties |
| TrackedJob | 7 properties |

**Total**: 59 @property annotations added, eliminating ~420 PHPStan errors.

## Prevention

### When Creating New Models:
1. Write migration first
2. Run migration
3. Immediately add @property annotations
4. Commit model with annotations

### Code Review Checklist:
- [ ] All database columns have @property annotations
- [ ] Correct types (int, string, bool, Carbon, array)
- [ ] Nullable fields marked with `|null`
- [ ] UUID primary keys use `string` not `int`
- [ ] Relationships documented with `@property-read`

## Related
- phpstan-baseline-maintenance.md
- monorepo-model-patterns.md
