---
date: 2026-01-30
problem_type: naming-convention
component: service-providers
severity: moderate
symptoms:
  - "Inconsistent service provider naming across packages"
  - "UiServiceProvider in orbit-app package (confusing name)"
  - "CoreServiceProvider without Orbit prefix"
  - "OrbitServiceProvider referenced in docs but doesn't exist"
root_cause: No clear naming convention established for package service providers
tags: [service-providers, naming-convention, orbit-prefix, breaking-change]
---

# Service Provider Naming Convention: Orbit* Prefix

## Problem

The monorepo had inconsistent service provider naming:

```php
// BEFORE - Inconsistent
packages/core/src/CoreServiceProvider.php          // Missing Orbit prefix
packages/app/src/UiServiceProvider.php              // Confusing (sounds like old orbit-ui)
packages/desktop/app/Providers/NativeAppServiceProvider.php  // Different pattern
```

Documentation referenced non-existent providers:
- `OrbitServiceProvider` mentioned in AGENTS.md but never existed
- Wrong namespace examples: `HardImpact\Orbit\Models` instead of `HardImpact\Orbit\Core\Models`

## Solution

### Established Convention

Package providers use `Orbit[Package]ServiceProvider` pattern:

```php
// AFTER - Consistent
packages/core/src/OrbitCoreServiceProvider.php      // ✅ Clear
packages/app/src/OrbitAppServiceProvider.php        // ✅ Clear (not UiServiceProvider)
packages/desktop/app/Providers/NativeAppServiceProvider.php  // ✅ Kept (avoids Laravel conflict)
```

Application shells keep Laravel convention:
```php
packages/web/app/Providers/AppServiceProvider.php     // ✅ Standard Laravel
packages/cli/app/Providers/AppServiceProvider.php     // ✅ Standard Laravel  
packages/desktop/app/Providers/AppServiceProvider.php // ✅ Standard Laravel
```

### Files Changed

**Provider Classes:**
- `packages/core/src/CoreServiceProvider.php` → `OrbitCoreServiceProvider.php`
- `packages/app/src/UiServiceProvider.php` → `OrbitAppServiceProvider.php`

**Registration Updates:**
- `packages/core/composer.json` (extra.laravel.providers)
- `packages/app/composer.json` (extra.laravel.providers)
- `packages/core/tests/TestCase.php`
- `packages/app/tests/TestCase.php`
- `packages/web/routes/web.php`
- `packages/web/app/Providers/AppServiceProvider.php`
- `packages/desktop/app/Providers/AppServiceProvider.php`

**Documentation:**
- `AGENTS.md` (root)
- `packages/desktop/AGENTS.md`
- `packages/web/AGENTS.md`
- `packages/web/CLAUDE.md`
- `packages/app/AGENTS.md`
- `packages/cli/app/Data/AGENTS.md`
- `packages/cli/app/Actions/AGENTS.md`

## Naming Rules

### Package Providers (Shared)
```php
// Format: Orbit[Package]ServiceProvider
OrbitCoreServiceProvider::class   // orbit-core package
OrbitAppServiceProvider::class    // orbit-app package
// Future: OrbitCliServiceProvider, etc.
```

### Application Providers (Shells)
```php
// Keep Laravel conventions
AppServiceProvider::class                    // Main app provider
NativeAppServiceProvider::class              // Desktop-specific (avoids conflict)
DatabaseServiceProvider::class               // CLI-specific
```

## Prevention

When creating new packages:
1. Use `Orbit[Package]ServiceProvider` naming
2. Register in composer.json `extra.laravel.providers`
3. Update all AGENTS.md files immediately
4. Use consistent namespace: `HardImpact\Orbit\Core\*`

## Migration Guide

**If renaming existing providers:**
```bash
# 1. Rename file
mv CoreServiceProvider.php OrbitCoreServiceProvider.php

# 2. Update class name inside file
sed -i 's/class CoreServiceProvider/class OrbitCoreServiceProvider/g' OrbitCoreServiceProvider.php

# 3. Update composer.json
sed -i 's/CoreServiceProvider/OrbitCoreServiceProvider/g' composer.json

# 4. Update all references
grep -r "CoreServiceProvider" packages/ --include="*.php" --include="*.md"
# Update each reference

# 5. Clear caches
rm -rf vendor composer.lock
composer install
```

## Related
- namespace-missing-core-segment-20260130.md
- monorepo-quality-cleanup-20260130.md
