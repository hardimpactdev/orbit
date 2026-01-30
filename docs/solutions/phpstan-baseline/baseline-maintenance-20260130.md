---
date: 2026-01-30
problem_type: phpstan-maintenance
component: all-packages
severity: moderate
symptoms:
  - "Baseline file has X errors that were not matched in reported errors"
  - PHPStan fails after fixing code
  - Outdated ignored errors in baseline
tags: [phpstan, static-analysis, baseline, quality-gates]
---

# PHPStan Baseline Maintenance

## Symptom
After fixing namespace issues and adding PHPDoc, PHPStan reports:

```
Ignored error pattern #^...$# was not matched in reported errors.
These errors are no longer present and should be removed from the baseline.
```

## Root Cause
PHPStan baseline (`phpstan-baseline.neon`) contains ignored errors that are no longer valid after code fixes. This is common after:
- Fixing namespace issues
- Adding @property annotations to models
- Resolving type errors

## Solution

### Method 1: Regenerate Entire Baseline (Clean Slate)

```bash
cd packages/core && vendor/bin/phpstan analyse --generate-baseline
cd packages/app && vendor/bin/phpstan analyse --generate-baseline  
cd packages/cli && vendor/bin/phpstan analyse --generate-baseline
```

**Pros:** Clean, accurate baseline
**Cons:** May re-introduce intentional ignores

### Method 2: Manual Removal of Specific Entries

1. Run PHPStan to see which entries are outdated:
```bash
cd packages/core && composer analyse
```

2. Remove outdated entries from `phpstan-baseline.neon`:
```neon
# Remove these sections:
-
    message: '#^Call to method current\(\) on an unknown class HardImpact\\Orbit\\Services\\EnvironmentManager\.$#'
    path: src/Models/Environment.php
```

### Method 3: Sed/Script Removal for Multiple Entries

```bash
# Find entries with specific pattern
grep -n "Orbit.*Services.*EnvironmentManager" phpstan-baseline.neon

# Remove lines 95-101 (example)
sed -i '95,101d' phpstan-baseline.neon
```

## Prevention

### Workflow:
1. **Before fixing**: Note current baseline size
2. **After fixing**: Run PHPStan (will report unmatched patterns)
3. **Regenerate baseline**: `--generate-baseline` flag
4. **Commit**: Include updated baseline with code fixes

### In CI/CD:
```yaml
# Fail if baseline needs updates
- name: PHPStan
  run: |
    vendor/bin/phpstan analyse
    if [ $? -ne 0 ]; then
      echo "Run: vendor/bin/phpstan analyse --generate-baseline"
      exit 1
    fi
```

## Stats from This Session

| Package | Before | After | Reduction |
|---------|--------|-------|-----------|
| Core | 467 errors | 45 errors | 90% |
| App | ~40 errors | 37 errors | 7% |
| CLI | Baseline | 0 errors | 100% |

## Related
- model-phpdoc-annotations.md
- namespace-missing-core-segment.md
