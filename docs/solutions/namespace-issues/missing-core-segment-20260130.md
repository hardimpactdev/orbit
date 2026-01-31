---
date: 2026-01-30
problem_type: namespace-mismatch
component: core-package
severity: critical
symptoms:
  - "Call to method current() on an unknown class HardImpact\\Orbit\\Services\\EnvironmentManager"
  - "Class HardImpact\\Orbit\\Services\\EnvironmentManager not found"
  - Runtime errors when calling Environment::getActive() or setAsActive()
root_cause: Namespace refactoring left old references without the \\Core\\ segment
tags: [namespace, core, environment, refactoring]
---

# Missing \\Core\\ Segment in Namespace Imports

## Symptom
After reorganizing packages into `HardImpact\Orbit\Core\` and `HardImpact\Orbit\App\` namespaces, runtime errors occurred:

```
Call to method current() on an unknown class HardImpact\Orbit\Services\EnvironmentManager
Class HardImpact\Orbit\Services\EnvironmentManager not found
```

## Root Cause
When the core package was reorganized, some files still referenced the old namespace without the `\Core\` segment:

```php
// WRONG - Old namespace
app(\HardImpact\Orbit\Services\EnvironmentManager::class)

// CORRECT - New namespace  
app(\HardImpact\Orbit\Core\Services\EnvironmentManager::class)
```

## Affected Files
- `packages/core/src/Models/Environment.php` (lines 118, 127)
- Multiple test files across packages
- Various service imports

## Solution

### 1. Find All Incorrect References
```bash
grep -r "HardImpact\\\\Orbit\\\\Services\\\\" packages/
grep -r "HardImpact\\\\Orbit\\\\Models\\\\" packages/ | grep -v "Core\\\\Models"
```

### 2. Bulk Replace
```bash
# Replace in core package
sed -i 's/HardImpact\\Orbit\\Services/HardImpact\\Orbit\\Core\\Services/g' packages/core/src/Models/Environment.php

# Replace in test files  
sed -i 's/HardImpact\\Orbit\\Models/HardImpact\\Orbit\\Core\\Models/g' packages/*/tests/*.php
```

### 3. Verify PHPStan Baseline
After fixing, regenerate baseline to remove outdated ignored errors:
```bash
cd packages/core && vendor/bin/phpstan analyse --generate-baseline
```

## Prevention

### During Namespace Refactoring:
1. **Search before refactoring**: `grep -r "use HardImpact\\Orbit\\" packages/`
2. **Update all imports** in a single commit
3. **Run PHPStan immediately** after changes
4. **Regenerate baseline** if namespace errors are expected during transition

### Code Review Checklist:
- [ ] All use statements use correct namespace
- [ ] `\Core\` segment present for core classes
- [ ] `\Ui\` segment present for UI classes  
- [ ] No references to old namespace structure
- [ ] Tests updated with correct namespaces

## Related
- phpstan-baseline-neon-regeneration.md
- monorepo-package-structure.md
