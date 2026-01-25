---
date: 2026-01-22
problem_type: build_error
component: orbit-cli/phar-build
severity: critical
status: fixed
symptoms:
  - "PHP Fatal error: Uncaught Error: Class \"HardImpact\\Orbit\\OrbitServiceProvider\" not found"
  - "Error occurs in phar:///path/to/orbit.phar/vendor/laravel/framework/src/Illuminate/Foundation/Application.php:961"
  - "PHAR runs but crashes immediately on startup"
  - "Works fine when running from source with php orbit"
root_cause: orbit-core package not being included properly in PHAR build
tags: [phar, laravel-zero, service-provider, orbit-core]
---

# PHAR Build Missing orbit-core Service Provider

## Symptom
After building orbit.phar with Laravel Zero's build process, the PHAR fails on startup with:

```
PHP Fatal error:  Uncaught Error: Class "HardImpact\Orbit\OrbitServiceProvider" not found in phar:///home/nckrtl/projects/orbit-cli/builds/orbit.phar/vendor/laravel/framework/src/Illuminate/Foundation/Application.php:961
```

The error occurs when `DatabaseServiceProvider` tries to register `OrbitServiceProvider::class`.

## Investigation
1. Attempted: Verified orbit-core files exist in vendor/
   Result: Files are present at `vendor/hardimpactdev/orbit-core/src/OrbitServiceProvider.php`

2. Attempted: Built PHAR after updating to orbit-core 0.0.5
   Result: Same error persists

3. Attempted: Checked box.json configuration
   Result: Configuration includes vendor directory correctly

## Root Cause
Laravel Zero has orbit-core listed in composer.json's `extra.laravel.dont-discover` array, preventing automatic service provider discovery. When the PHAR is built, the package files may be included but the autoloader doesn't properly register the namespace.

## Solution
**✅ FIXED**: The issue was resolved by adding proper logger configuration.

**Root Issue**: Laravel Zero's PHAR environment expected a logger with a `channel()` method, but the default PSR NullLogger doesn't have this method.

**The Fix**: Created `/home/nckrtl/projects/orbit-cli/config/logging.php` with proper null logger configuration:

```php
<?php

return [
    'default' => 'null',
    
    'channels' => [
        'null' => [
            'driver' => 'monolog',
            'handler' => Monolog\Handler\NullHandler::class,
        ],
    ],
];
```

**What We Did**:
1. ✅ Removed orbit-core from `dont-discover` array in composer.json
2. ✅ Removed manual registration of OrbitServiceProvider in DatabaseServiceProvider  
3. ✅ Added config/logging.php with null logger configuration
4. ✅ PHAR now builds and runs successfully

**Result**: The PHAR is now fully functional at `~/.local/bin/orbit`

## Prevention
- Test PHAR builds whenever updating critical dependencies
- Include PHAR smoke test in CI/CD pipeline:
  ```bash
  ./builds/orbit.phar --version
  ./builds/orbit.phar sites
  ```
- Document which packages require special handling in PHAR builds
- Consider using GitHub Actions to build and test PHARs automatically

## Related
- [Laravel Zero PHAR Building Documentation](https://laravel-zero.com/docs/build-a-standalone-application)
- [Box.json Configuration Reference](https://github.com/box-project/box/blob/master/doc/configuration.md)
- Similar issues:
  - Laravel packages with service providers often have PHAR issues
  - Packages that use package discovery may need manual registration