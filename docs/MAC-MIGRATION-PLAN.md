# Mac Local Environment Migration Plan: PHP-FPM

## Overview

This plan migrates the local Mac development environment from the current setup to PHP-FPM architecture, matching the remote server setup.

> **Note:** This guide is for Apple Silicon Macs only.

## Current State (Remote Server - Completed)

The Ubuntu VPS (ai) is now running:
- **PHP-FPM**: 8.4 and 8.5 pools with Unix sockets
- **Caddy**: Host binary (systemd service)
- **Horizon**: systemd service
- **Docker**: postgres, redis, mailpit, reverb, dns

## Target State (Local Mac)

```
┌─────────────────────────────────────────────────────────────────┐
│                        MACOS HOST                                │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│   ┌─────────────────┐     ┌─────────────────────────────────┐   │
│   │  Caddy (host)   │────▶│ PHP-FPM Pools (Homebrew)        │   │
│   │  brew services  │     │  ~/.config/orbit/php/       │   │
│   │                 │     │  ├── php85.sock                 │   │
│   └─────────────────┘     │  ├── php84.sock                 │   │
│                           │  └── php83.sock                 │   │
│                           └─────────────────────────────────┘   │
│                                                                  │
│   ┌─────────────────┐     ┌─────────────────┐                   │
│   │ Horizon (host)  │     │ Orbit CLI   │                   │
│   │    launchd      │     │ ~/.local/bin    │                   │
│   └─────────────────┘     └─────────────────┘                   │
│                                                                  │
│   ┌────────────────────────────────────────────────────────┐    │
│   │              Docker Network: launchpad                  │    │
│   │  ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌────────┐    │    │
│   │  │ Postgres │ │  Redis   │ │ Mailpit  │ │ Reverb │    │    │
│   │  └──────────┘ └──────────┘ └──────────┘ └────────┘    │    │
│   └────────────────────────────────────────────────────────┘    │
│                                                                  │
│   ┌─────────────┐                                               │
│   │ DNS (dnsmasq)│ ← Container, host network (dynamic TLD)      │
│   └─────────────┘                                               │
└─────────────────────────────────────────────────────────────────┘
```

---

## Prerequisites

- Homebrew installed
- Docker Desktop running
- Orbit CLI installed at `~/.local/bin/orbit`

---

## Migration Steps

### Phase 1: Install PHP via Homebrew (Manual Prerequisite)

These are one-time system prerequisites. The CLI will use these installed versions.

```bash
# Add shivammathur/php tap (provides all PHP versions)
brew tap shivammathur/php

# Install PHP versions
brew install shivammathur/php/php@8.3
brew install shivammathur/php/php@8.4
brew install shivammathur/php/php@8.5

# Verify installations (use full paths)
/opt/homebrew/opt/php@8.3/bin/php -v
/opt/homebrew/opt/php@8.4/bin/php -v
/opt/homebrew/opt/php@8.5/bin/php -v
```

### Phase 2: Install Caddy (Manual Prerequisite)

```bash
# Install Caddy
brew install caddy

# Verify installation
caddy version
```

### Phase 3: Update Orbit CLI

Ensure the local CLI is updated to support the PHP-FPM architecture:

```bash
# Update CLI from GitHub releases
curl -L -o ~/.local/bin/orbit https://github.com/nckrtl/orbit-cli/releases/latest/download/orbit.phar
chmod +x ~/.local/bin/orbit
```

### Phase 4: Run Migration Command

The CLI handles pool configuration, Caddyfile generation, and service setup automatically:

```bash
# Run the migration
launchpad migrate:to-fpm --force

# This will:
# 1. Stop current services
# 2. Install PHP-FPM if not present
# 3. Configure PHP-FPM pools (generates pool configs from stubs)
# 4. Install host Caddy if not present
# 5. Regenerate Caddyfile for FPM sockets
# 6. Reload Caddy with new config
# 7. Install Horizon launchd service
# 8. Remove old FrankenPHP containers
# 9. Start new services
```

**What the CLI creates:**
- Pool configs at `/opt/homebrew/etc/php/8x/php-fpm.d/launchpad-8x.conf`
- Sockets at `~/.config/orbit/php/php8x.sock`
- Logs at `~/.config/orbit/logs/php8x-fpm.log`
- Horizon plist at `~/Library/LaunchAgents/com.orbit.horizon.plist`
- Caddyfile at `~/.config/orbit/caddy/Caddyfile`

### Phase 5: Trust Local CA Certificate

```bash
# Caddy generates its own CA for local HTTPS
# Trust it in the system keychain
sudo security add-trusted-cert -d -r trustRoot -k /Library/Keychains/System.keychain \
    ~/Library/Application\ Support/Caddy/pki/authorities/local/root.crt
```

### Phase 6: Verify Services

```bash
# Check launchpad status
launchpad status

# Expected output:
# Architecture: php-fpm
# Services:
#   php-85: running (php-fpm)
#   php-84: running (php-fpm)
#   php-83: running (php-fpm)
#   caddy: running (host)
#   horizon: running (launchd)
#   dns: running (docker)
#   postgres: running (docker)
#   redis: running (docker)
#   mailpit: running (docker)

# Verify sockets are created
ls -la ~/.config/orbit/php/*.sock

# Test a site
curl -sk https://your-site.test/
```

---

## Key Differences: Linux vs macOS

| Component | Linux (Ubuntu) | macOS |
|-----------|----------------|-------|
| PHP Source | Ondřej PPA (`apt`) | shivammathur/php (`brew`) |
| PHP-FPM Service | `systemctl` | `brew services` |
| Caddy Service | `systemctl` | `brew services` |
| Horizon Service | systemd unit file | launchd plist |
| Default User | `launchpad` | Your username |
| PHP Binary Path | `/usr/bin/php8.x` | `/opt/homebrew/opt/php@8.x/bin/php` |
| Socket Permissions | `launchpad:launchpad` | `$USER:staff` |
| Pool Config Dir | `/etc/php/8.x/fpm/pool.d/` | `/opt/homebrew/etc/php/8.x/php-fpm.d/` |

---

## Troubleshooting

### PHP-FPM Socket Not Created

```bash
# Check PHP-FPM master process logs
tail -f /opt/homebrew/var/log/php-fpm.log

# Check pool-specific logs
tail -f ~/.config/orbit/logs/php85-fpm.log

# Check if service is running
brew services list | grep php

# Validate pool config syntax
/opt/homebrew/opt/php@8.5/sbin/php-fpm -tt

# Restart service
brew services restart php@8.5
```

### Caddy Permission Denied on Socket

```bash
# Ensure socket has correct permissions
chmod 660 ~/.config/orbit/php/*.sock

# Add your user to the socket group if needed
# (Usually not needed on macOS with staff group)
```

### Sites Return 502

```bash
# Check Caddy logs
tail -f /opt/homebrew/var/log/caddy.log

# Verify socket exists
ls -la ~/.config/orbit/php/php85.sock

# Check PHP-FPM is running
pgrep -f php-fpm
```

### Horizon Not Starting

```bash
# Plist location
ls -la ~/Library/LaunchAgents/com.orbit.horizon.plist

# Check launchd status
launchctl list | grep horizon

# Manual load/unload
launchctl unload ~/Library/LaunchAgents/com.orbit.horizon.plist
launchctl load -w ~/Library/LaunchAgents/com.orbit.horizon.plist

# View logs
tail -f ~/.config/orbit/logs/horizon.log

# Manually start for debugging
php ~/.config/orbit/web/artisan horizon
```

---

## Rollback

If migration fails, you can revert to the previous setup:

```bash
# Stop PHP-FPM services
brew services stop php@8.5
brew services stop php@8.4
brew services stop php@8.3

# Stop host Caddy
brew services stop caddy

# Unload Horizon
launchctl unload ~/Library/LaunchAgents/com.orbit.horizon.plist

# Remove symlinked pool configs if they interfere
rm -f /opt/homebrew/etc/php/8.5/php-fpm.d/launchpad-*.conf
rm -f /opt/homebrew/etc/php/8.4/php-fpm.d/launchpad-*.conf
rm -f /opt/homebrew/etc/php/8.3/php-fpm.d/launchpad-*.conf

# Restart old container-based services (if applicable)
# launchpad start --frankenphp
```

---

## Post-Migration Checklist

- [ ] All PHP versions installed and running
- [ ] PHP-FPM sockets created at `~/.config/orbit/php/`
- [ ] Caddy running and serving sites
- [ ] Horizon processing queue jobs
- [ ] Docker services (postgres, redis, etc.) running
- [ ] DNS resolution working (via DNS container)
- [ ] Local CA certificate trusted
- [ ] `launchpad status` shows all services healthy
- [ ] Test sites accessible via HTTPS
