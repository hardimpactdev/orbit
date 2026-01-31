---
name: upgrade-php
description: Add support for a new PHP version to Launchpad. Use when user wants to add PHP 8.6, 8.7, etc.
allowed-tools: Bash(ssh:*), Read, Edit
---

# Upgrade PHP Version (Host PHP-FPM)

Add support for a new PHP version in the Orbit stack. PHP runs as host PHP-FPM, and Caddy runs on the host. No FrankenPHP containers.

## Prerequisites

- SSH access to the remote server (`ssh orbit@ai`)
- sudo privileges for package installs and service management

## Parameters

Ask the user for the PHP version to add (e.g., `8.6`, `8.7`).

## Steps

### 1. Update supported version lists

Update the CLI's supported versions and any validation lists:

- `packages/cli/config/orbit.php` (`php_versions` array)
- `packages/cli/app/Commands/PhpCommand.php`:
  - Update the signature help text
  - Update `$validVersions`
- `packages/cli/app/Services/Platform/MacAdapter.php`:
  - Extend the unlink list in `setDefaultPhpCli()`
  - If the new version becomes Homebrew's default, update `getBrewPhpFormula()`

Search for hard-coded version lists and update tests/docs as needed:

```bash
rg "8\.3|8\.4|8\.5" packages/cli
```

### 2. Install PHP-FPM on the host

Install the new PHP version on the target host:

```bash
# Linux (Ubuntu)
sudo apt-get update
sudo apt-get install -y php{VERSION}-fpm php{VERSION}-cli php{VERSION}-common php{VERSION}-curl php{VERSION}-mbstring php{VERSION}-xml php{VERSION}-zip php{VERSION}-gd php{VERSION}-bcmath php{VERSION}-pgsql php{VERSION}-mysql php{VERSION}-redis

# macOS
brew install php@{VERSION}
brew services start php@{VERSION}
```

### 3. Install the Orbit PHP-FPM pool

Ensure Orbit's pool config is installed and linked (via setup/migration pipeline or directly through `PhpManager::installPool()` when running locally). Restart or reload PHP-FPM after linking the pool.

### 4. Validate

Verify the new version is detected and the socket exists:

```bash
orbit status
ls ~/.config/orbit/php/php{VERSION_NO_DOT}.sock
```

### 5. Update documentation

If any docs list supported PHP versions, update them to include the new version.

## Example

To add PHP 8.6:

1. `VERSION` = `8.6`
2. `VERSION_NO_DOT` = `86`

## Files Modified

| Location | File | Change |
| --- | --- | --- |
| Repo | `packages/cli/config/orbit.php` | Add version |
| Repo | `packages/cli/app/Commands/PhpCommand.php` | Update valid versions |
| Repo | `packages/cli/app/Services/Platform/MacAdapter.php` | Update brew version handling |
