---
date: 2026-01-30
problem_type: config_error
component: monorepo-package-configuration
severity: moderate
symptoms:
  - "Inconsistent PHP version requirements (^8.3 vs ^8.4)"
  - "Different dependency versions across packages"
  - "Missing pint.json files in some packages"
  - "Inconsistent PHPStan configurations"
  - "Mismatched author information"
  - "Missing .gitignore files"
  - "Incomplete quality gate scripts"
root cause: "Lack of standardized configuration patterns across monorepo packages led to maintenance burden and potential runtime issues"
tags: [monorepo, configuration, consistency, composer, quality-gates]
---

# Monorepo Package Configuration Standardization

## Symptom

Orbit monorepo had significant configuration inconsistencies across 5 packages (core, app, cli, web, desktop), creating maintenance burden and potential compatibility issues:

**PHP Versions:**
- core: `^8.3` (outdated)
- web/cli/app/desktop: `^8.4`

**Dependencies:**
- craft-ui: desktop `^0.0.3` vs app/core `^0.0.17`
- vite: desktop `^7.0.7` vs app/core `^7.3.1`
- tailwindcss: desktop `^4.0.0` vs app/core `^4.1.18`

**Tooling Configs:**
- pint.json: Only cli had it
- phpstan.neon: Different structures across packages
- .gitignore: Only cli had one

**Metadata:**
- Author names: "Nick Rutten" vs "Nick Ratel" vs "Nick Retel"
- Emails: 3 different email addresses
- web/desktop: No authors field at all

**Quality Gates:**
- Missing `composer analyse/test/format` scripts in web/desktop

## Root Cause

Monorepo packages evolved independently without:
1. Centralized configuration templates
2. Regular cross-package dependency audits
3. Standardized tooling configurations
4. Consistent metadata management

## Investigation

**Approach 1: Check each package individually**
```bash
for pkg in packages/*/composer.json; do
  echo "=== $pkg ==="
  grep -E '"php":|"name":|"authors":' "$pkg"
done
```

**Approach 2: Compare package.json files**
```bash
diff packages/app/package.json packages/desktop/package.json
```

**Result:** Revealed 8 categories of inconsistencies

## Solution

### 1. PHP Version Standardization

**File:** `packages/core/composer.json:19`
```json
// Before
"php": "^8.3"

// After
"php": "^8.4"
```

### 2. Dependency Alignment (desktop)

**File:** `packages/desktop/package.json`

Updated 10+ dependencies to match app/core:
- `@hardimpactdev/craft-ui`: `^0.0.3` → `^0.0.17`
- `@inertiajs/vue3`: `^2.3.4` → `^2.3.11`
- `laravel-echo`: `^2.2.7` → `^2.3.0`
- `vue`: `^3.5.26` → `^3.5.27`
- `vite`: `^7.0.7` → `^7.3.1`
- `tailwindcss`: `^4.0.0` → `^4.1.18`
- And more...

### 3. Pint Configuration

**Created for all packages:**

`packages/web/pint.json`:
```json
{
    "preset": "laravel",
    "exclude": [
        "storage",
        "bootstrap/cache",
        "vendor"
    ]
}
```

`packages/app/pint.json` & `packages/core/pint.json`:
```json
{
    "preset": "laravel",
    "exclude": [
        "vendor",
        "build"
    ]
}
```

### 4. PHPStan Standardization

**Standardized all configurations:**

Package format (app, core):
```neon
includes:
    - vendor/larastan/larastan/extension.neon
    - phpstan-baseline.neon

parameters:
    level: 5
    paths:
        - src
        - config
        - database
    tmpDir: build/phpstan
    checkOctaneCompatibility: true
    checkModelProperties: true
```

Project format (web, cli, desktop):
```neon
includes:
    - vendor/larastan/larastan/extension.neon
    - phpstan-baseline.neon

parameters:
    level: 5
    paths:
        - app/
        - config/
        - database/
    tmpDir: build/phpstan
```

**Created missing baselines:**
```bash
touch packages/web/phpstan-baseline.neon
touch packages/desktop/phpstan-baseline.neon
```

### 5. Author Data Standardization

**All packages now use:**
```json
"authors": [
    {
        "name": "Nick Retel",
        "email": "nick@hardimpact.dev",
        "role": "Developer"
    }
]
```

### 6. .gitignore Creation

**Created comprehensive .gitignore files:**

`packages/web/.gitignore` (Laravel project):
- Laravel: storage, bootstrap/cache, public/build, .env
- Node: node_modules/
- IDE: .idea, .vscode
- OS: .DS_Store

`packages/app/.gitignore` & `packages/core/.gitignore` (packages):
- /vendor, /build
- IDE files
- Testing coverage

`packages/desktop/.gitignore` (NativePHP):
- All Laravel exclusions
- NativePHP: /native, /dist

`packages/cli/.gitignore` (Laravel Zero):
- /var (Laravel Zero)
- Standard exclusions

### 7. Changeset Configuration

**File:** `.changeset/config.json`

Fixed package name inconsistencies:
```json
// Before
"fixed": [
    [
        "hardimpactdev/orbit-core",
        "hardimpactdev/orbit-cli",
        "@hardimpactdev/orbit-app",  // Wrong: @ prefix
        "hardimpactdev/orbit-web",
        "hardimpactdev/orbit-desktop"
    ]
]

// After
"fixed": [
    [
        "hardimpactdev/orbit-core",
        "hardimpactdev/orbit-cli",
        "hardimpactdev/orbit-app",
        "hardimpactdev/orbit-web",
        "hardimpactdev/orbit-desktop"
    ]
]
```

### 8. Quality Gate Scripts

**Added to web and desktop:**

`packages/web/composer.json`:
```json
"scripts": {
    "analyse": "vendor/bin/phpstan analyse",
    "test": "vendor/bin/pest",
    "format": "vendor/bin/pint"
}
```

`packages/desktop/composer.json`:
```json
"scripts": {
    "analyse": "vendor/bin/phpstan analyse",
    "format": "vendor/bin/pint"
    // Note: "test" already existed
}
```

Also added `larastan/larastan` to web's require-dev.

## Prevention

### Package Consistency Checklist

When creating or updating packages:

**composer.json:**
- [ ] PHP version matches other packages (`^8.4`)
- [ ] Authors block with correct name/email
- [ ] License: MIT
- [ ] Scripts: analyse, test, format

**Tooling Configs:**
- [ ] pint.json with laravel preset
- [ ] phpstan.neon with baseline include
- [ ] .gitignore for package type

**Dependencies:**
- [ ] Check versions match other packages
- [ ] Run `composer outdated` across all

### Verification Commands

```bash
# Check PHP versions
grep -r '"php":' packages/*/composer.json

# Check authors
grep -A3 '"authors"' packages/*/composer.json

# Check scripts
grep '"analyse"\|"test"\|"format"' packages/*/composer.json

# Check pint.json exists
ls packages/*/pint.json

# Check .gitignore exists
ls packages/*/.gitignore
```

### Monthly Audit Script

```bash
#!/bin/bash
# monorepo-audit.sh

echo "=== PHP Versions ==="
grep -h '"php":' packages/*/composer.json | sort | uniq -c

echo "=== craft-ui versions ==="
grep -h '"@hardimpactdev/craft-ui"' packages/*/package.json 2>/dev/null | sort | uniq -c

echo "=== Vite versions ==="
grep -h '"vite"' packages/*/package.json 2>/dev/null | sort | uniq -c

echo "=== Missing pint.json ==="
for pkg in packages/*/; do
  [ ! -f "$pkg/pint.json" ] && echo "$pkg"
done

echo "=== Missing .gitignore ==="
for pkg in packages/*/; do
  [ ! -f "$pkg/.gitignore" ] && echo "$pkg"
done
```

## Related

- This is a follow-up to the comprehensive quality cleanup in `monorepo-quality-cleanup-20260130.md`
- See `docs/solutions/code-quality/` for strict types enforcement
- See `docs/solutions/patterns/` for service provider naming conventions

## Files Modified

- `packages/core/composer.json` (PHP version)
- `packages/desktop/package.json` (dependencies)
- `packages/web/pint.json` (created)
- `packages/app/pint.json` (created)
- `packages/core/pint.json` (created)
- `packages/desktop/pint.json` (created)
- `packages/cli/.gitignore` (expanded)
- `packages/web/.gitignore` (created)
- `packages/app/.gitignore` (created)
- `packages/core/.gitignore` (created)
- `packages/desktop/.gitignore` (created)
- `packages/*/phpstan*.neon*` (standardized)
- `packages/*/phpstan-baseline.neon` (created where missing)
- `packages/*/composer.json` (authors, scripts)
- `.changeset/config.json` (package names)

**Status: ✅ COMPLETE - All packages consistently configured**
