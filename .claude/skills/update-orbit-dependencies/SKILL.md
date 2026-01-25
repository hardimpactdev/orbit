---
name: update-orbit-dependencies
description: Update orbit-web package (includes orbit-core), rebuild the app, and upgrade the CLI. Use when syncing dependencies across the orbit stack.
allowed-tools: Bash(composer:*), Bash(npm:*), Bash(orbit:*), Bash(source:*), Bash(php:*), Read
---

# Update Orbit Dependencies

This skill pulls in the latest orbit-web package (which includes orbit-core), ensures the desktop app builds successfully, and upgrades the local CLI installation.

## Overview

The orbit stack dependency chain:

```
orbit-desktop (this project)
    └── hardimpactdev/orbit-web (GitHub VCS)
        └── hardimpactdev/orbit-core (transitive)
    └── vite.config.ts → compiles assets from orbit-core

orbit-cli
    └── installed locally via: orbit upgrade
```

**Note:** orbit-web depends on orbit-core, so updating orbit-web automatically pulls the latest orbit-core.

## Steps

### 1. Update orbit-web Package

Pull the latest orbit-web (and transitively orbit-core):

```bash
composer update hardimpactdev/orbit-web
```

**Expected:** Package updates. You may see "Ambiguous class resolution" warnings for classes like `DatabaseSeeder`, `UserFactory`, `AppServiceProvider`, `User`, `Controller` - these are expected and the local versions take precedence.

If there are new migrations, run them:

```bash
php artisan migrate
```

### 2. Build the Desktop App

Rebuild frontend assets (compiles from orbit-core's resources):

```bash
npm run build
```

**Expected output:** Build completes without errors (~2-3 seconds). Watch for:
- TypeScript errors
- Missing imports from orbit-core
- Vite build failures

### 3. Upgrade CLI

Upgrade the local orbit CLI to the latest release:

```bash
orbit upgrade
```

**Note:** If no new PHAR is available, you'll see "Could not find PHAR download URL" - this is normal if CLI is already at latest.

### 4. Initialize Orbit

Run orbit init to ensure configuration is current:

```bash
orbit init
```

This updates local config files, regenerates Caddyfile, and ensures Docker images are built.

### 5. Verify Everything Works

Run tests to confirm nothing broke:

```bash
php artisan test
```

**Expected:** All tests pass (WebModeTest skipped in desktop mode is normal).

## Quick Reference

Full update sequence (copy-paste friendly):

```bash
# Update orbit-web (includes orbit-core)
composer update hardimpactdev/orbit-web

# Rebuild assets
npm run build

# Upgrade and init CLI
orbit upgrade
orbit init

# Verify
php artisan test
```

## First-Time Setup

If orbit-web is not yet in composer.json:

```bash
composer config repositories.orbit-web vcs https://github.com/hardimpactdev/orbit-web
composer require hardimpactdev/orbit-web:dev-main
```

## Troubleshooting

### Composer Update Fails

Clear composer cache and retry:

```bash
composer clear-cache
composer update hardimpactdev/orbit-web
```

### Build Fails with TypeScript Errors

The build compiles assets from `vendor/hardimpactdev/orbit-core/resources/js/`. Check for:

1. **Missing types**: Run `npm run typecheck` for detailed errors
2. **Breaking changes in orbit-core**: Review the orbit-core changelog

### CLI Upgrade Fails

If `orbit upgrade` fails or no PHAR available, manually download:

```bash
curl -L -o /usr/local/bin/orbit https://github.com/nckrtl/orbit-cli/releases/latest/download/orbit.phar
chmod +x /usr/local/bin/orbit
```

### Ambiguous Class Warnings

These warnings are expected and harmless:

```
Warning: Ambiguous class resolution, "App\Models\User" was found in both...
```

The local class always takes precedence over the vendor package version.

## Files Modified

| Location | File | Change |
|----------|------|--------|
| Local | `composer.lock` | Updated orbit-web (+ orbit-core) versions |
| Local | `public/build/*` | Rebuilt assets |
| Local | `~/.config/orbit/*` | Updated by orbit init |
