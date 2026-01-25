---
date: 2026-01-23
problem_type: build-error
component: orbit-core
severity: moderate
symptoms:
  - "Vite manifest not found at: /path/to/project/public/vendor/orbit/build/manifest.json"
  - "NO PUBLISH ASSETS FROM ORBIT-CORE"
  - "The build doesn't exist in orbit-core"
root_cause: Built assets gitignored, not included in composer package
tags: [vite, assets, vendor-publish, laravel-package]
---

# Vendor:publish Assets Missing from orbit-core Package

## Symptom
When running `php artisan vendor:publish --tag=orbit-assets` in orbit-desktop or orbit-web:
```
Vite manifest not found at: /Users/nckrtl/Projects/orbit-desktop/public/vendor/orbit/build/manifest.json
```

The service provider tries to publish from `__DIR__.'/../public/build'` but nothing exists there after `composer require`.

## Investigation
1. Attempted: Check for release workflow that builds assets
   Result: No GitHub workflow exists for building on release

2. Checked: Git status of built assets
   Result: `public/build/` was gitignored in two places:
   - Root `.gitignore` had `/build` (for phpstan cache)
   - `public/.gitignore` had `build/` (blocking Vite output)

## Root Cause
The `public/.gitignore` file was ignoring `build/`, preventing built assets from being committed to the repo. When the package is installed via Composer/Packagist, there are no built assets to publish.

## Solution
1. Remove `build/` from `public/.gitignore` (keep `/build` in root for phpstan cache):

```diff
# public/.gitignore
hot
-build/
```

2. Build the assets:
```bash
cd orbit-core
bun install
bun run build
```

3. Commit the built assets:
```bash
git add public/.gitignore public/build/
git commit -m "Include built assets in repo for vendor:publish support"
git push
```

4. Create a new release:
```bash
gh release create 0.0.8 --title "Release 0.0.8" --notes "Include built assets..."
```

## Prevention
- After any UI changes in orbit-core, always run `bun run build` before releasing
- The built assets in `public/build/` must be committed with each release
- Consider adding a pre-release checklist or CI check to verify assets are built

## Related
- Laravel Horizon uses the same pattern - commits built assets to repo
- Alternative approach: Build assets in CI during release (more complex)