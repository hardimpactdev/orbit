---
date: 2026-01-23
problem_type: build-error
component: GitHub Actions build workflow
severity: critical
symptoms:
  - "orbit-core assets missing in bundled orbit-web"
  - "404 errors on CSS/JS files after orbit upgrade"
root_cause: orbit-web bundle missing vendor:publish step for orbit-core assets
tags: [ci, build, assets, bundle]
---

# Missing orbit-core Assets in orbit-cli Bundle

## Symptom
After running `orbit upgrade`, the bundled web UI was missing CSS/JS assets, showing unstyled pages and console errors about missing files in `/vendor/orbit/build/`.

## Investigation
1. Attempted: Running bun build in orbit-web directory during CI
   Result: Failed - orbit-web has no package.json, frontend is in orbit-core
   
2. Attempted: Building assets in vendor/hardimpactdev/orbit-core
   Result: Unnecessary - orbit-core ships with pre-built assets

## Root Cause
The GitHub Actions build workflow was not running `php artisan vendor:publish --tag=orbit-assets` after installing orbit-web dependencies. While orbit-core ships with pre-built assets in `public/build/`, they need to be published to orbit-web's `public/vendor/orbit/build/` directory.

## Solution
Ensure the build workflow includes asset publishing:

```yaml
# Before (broken) - .github/workflows/build.yml
- name: Install orbit-web dependencies and build
  run: |
    cd orbit-web
    composer install --no-dev --optimize-autoloader
    CI=1 bun ci
    bun run build
    # Remove dev files before bundling
    rm -rf node_modules .git tests
    tar -czf ../orbit-cli/stubs/orbit-web-bundle.tar.gz .

# After (fixed)
- name: Install orbit-web dependencies and bundle
  run: |
    cd orbit-web
    # orbit-core ships with pre-built assets, post-install-cmd publishes them
    composer install --no-dev --optimize-autoloader
    # Remove dev files before bundling
    rm -rf .git tests
    tar -czf ../orbit-cli/stubs/orbit-web-bundle.tar.gz .
```

The fix relies on orbit-web's composer.json `post-install-cmd` which runs:
```json
"post-install-cmd": [
    "@php artisan vendor:publish --tag=orbit-assets --ansi --force"
]
```

## Prevention
- Remember that orbit-core ships with pre-built assets (no build step needed)
- Ensure CI doesn't use `--no-scripts` flag when installing orbit-web
- Verify published assets exist before bundling: `ls public/vendor/orbit/build/`
- Test the bundle locally: `orbit upgrade --check`

## Related
- orbit-core's asset publishing: `OrbitServiceProvider::registerPublishing()`
- Horizon-style package architecture pattern