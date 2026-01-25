---
date: 2026-01-23
problem_type: build-error
component: NativePHP/Electron startup
severity: critical
symptoms:
  - "TypeError [ERR_INVALID_ARG_TYPE]: The \"path\" argument must be of type string. Received undefined"
  - "Unable to locate file in Vite manifest: vendor/hardimpactdev/orbit-core/resources/js/app.ts"
root_cause: PHP version mismatch and asset configuration
tags: [nativephp, vite, orbit-core, php-version]
---

# NativePHP Startup Failures

## Symptoms

1. PHP binary path undefined error:
```
TypeError [ERR_INVALID_ARG_TYPE]: The "path" argument must be of type string. Received undefined
    at join (node:path:1339:7)
    at file://.../vendor/nativephp/electron/resources/js/php.js:54:22
```

2. Vite manifest mismatch:
```
Illuminate\Foundation\ViteException
Unable to locate file in Vite manifest: vendor/hardimpactdev/orbit-core/resources/js/app.ts
```

## Investigation

1. **PHP version issue**: System runs PHP 8.5, but NativePHP php-bin only has 8.3/8.4 binaries
   - `ExecuteCommand.php` hardcodes `PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION`
   - Ignores `NATIVEPHP_PHP_BINARY_VERSION` env var and config

2. **Vite manifest issue**: Tried running vite dev to compile orbit-core
   - Failed: orbit-core has linked local dependency `@hardimpactdev/craft-ui` not available locally

## Root Cause

1. **PHP version**: NativePHP's `ExecuteCommand` trait doesn't read from config
2. **Assets**: orbit-core needs pre-built assets, not local compilation (dependencies unavailable)
3. **Blade paths**: Local blade used wrong paths that don't match orbit-core's manifest

## Solution

### 1. Patch NativePHP ExecuteCommand (vendor patch)

```php
// vendor/nativephp/electron/src/Traits/ExecuteCommand.php

// Before (broken)
$envs = [
    'install' => [
        'NATIVEPHP_PHP_BINARY_VERSION' => PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION,
        ...

// After (fixed)
$phpVersion = config('nativephp.binary_version', PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION);

$envs = [
    'install' => [
        'NATIVEPHP_PHP_BINARY_VERSION' => $phpVersion,
        ...
```

### 2. Copy pre-built assets from orbit-web

```bash
mkdir -p public/vendor/orbit
scp -r nckrtl@ai:~/projects/orbit-web/public/vendor/orbit/build public/vendor/orbit/
```

### 3. Remove vite from native:dev script

```json
// composer.json - before
"native:dev": [
    "...",
    "npx concurrently ... \"npm run dev\" --names=app,queue,vite"
]

// after
"native:dev": [
    "...",
    "npx concurrently ... --names=app,queue"
]
```

### 4. Fix blade template paths

```php
// resources/views/app.blade.php

// Before (broken - paths don't match manifest)
@vite(['vendor/hardimpactdev/orbit-core/resources/js/app.ts', 'vendor/hardimpactdev/orbit-core/resources/css/app.css'])

// After (matches manifest)
@vite(['resources/js/app.ts'])
```

## Prevention

- When setting up new workspace, run setup checklist (see AGENTS.md)
- Consider submitting PR to NativePHP to fix config reading
- Create composer patch for ExecuteCommand.php to survive updates
- Pre-built assets need to be copied when orbit-core updates

## Related

- orbit-web uses same pre-built asset pattern (no vite)
- OrbitServiceProvider configures `Vite::useBuildDirectory('vendor/orbit/build')`
