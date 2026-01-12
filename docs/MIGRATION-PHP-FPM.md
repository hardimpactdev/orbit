# Migration Complete: FrankenPHP Containers → PHP-FPM on Host

> **Status: COMPLETED** - This migration has been fully implemented. This document is kept as a historical reference for the architectural decisions made.

## Executive Summary

This document outlines the completed migration from the FrankenPHP container-based architecture to PHP-FPM running directly on the host machine with Caddy as the web server.

**Achieved Goals:**
- Simplified architecture by removing dual-Caddy setup
- Enabled direct CLI/Bun/git access from PHP processes
- Supports both macOS and Linux with unified approach
- Multi-PHP version support via FPM pools

**Inspiration:** This migration adopts patterns from [Laravel Valet](https://github.com/laravel/valet), specifically:
- Stub template pattern for FPM pool configuration ([PhpFpm.php](https://github.com/laravel/valet/blob/master/cli/Valet/PhpFpm.php))
- Version normalization logic
- Socket naming conventions

Valet is macOS-only and Nginx-based; we adapt these patterns for cross-platform (Linux + macOS) use with Caddy.

---

## Previous Architecture (FrankenPHP - Deprecated)

```
┌─────────────────────────────────────────────────────────────────┐
│                        HOST MACHINE                              │
├─────────────────────────────────────────────────────────────────┤
│   ┌─────────────────┐                                           │
│   │ External Caddy  │ ← Container, Port 80/443                  │
│   │   (container)   │                                           │
│   └────────┬────────┘                                           │
│            │ reverse_proxy                                      │
│   ┌────────┴───────────────────────────────────────────┐        │
│   │              Docker Network: launchpad              │        │
│   │  ┌──────────────┐ ┌──────────────┐ ┌──────────────┐│        │
│   │  │ FrankenPHP   │ │ FrankenPHP   │ │ FrankenPHP   ││        │
│   │  │ 8.3 (+Caddy) │ │ 8.4 (+Caddy) │ │ 8.5 (+Caddy) ││        │
│   │  │  :8083       │ │  :8084       │ │  :8085       ││        │
│   │  └──────────────┘ └──────────────┘ └──────────────┘│        │
│   │  ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌────────┐│        │
│   │  │ Postgres │ │  Redis   │ │ Mailpit  │ │ Reverb ││        │
│   │  └──────────┘ └──────────┘ └──────────┘ └────────┘│        │
│   │  ┌──────────┐                                      │        │
│   │  │ Horizon  │ (in container, complex PATH/mounts) │        │
│   │  └──────────┘                                      │        │
│   └────────────────────────────────────────────────────┘        │
│   ┌─────────────┐                                               │
│   │ DNS (dnsmasq)│ ← Host network                               │
│   └─────────────┘                                               │
└─────────────────────────────────────────────────────────────────┘
```

**Problems with current architecture:**
1. Dual Caddy (external container + embedded in FrankenPHP) adds complexity
2. PHP processes can't access host CLI tools without complex volume mounts
3. Horizon needs elaborate PATH and binary mounts to work
4. Environment variable isolation between host and containers is fragile
5. Three separate FrankenPHP containers for PHP versions

---

## Current Architecture (PHP-FPM)

```
┌─────────────────────────────────────────────────────────────────┐
│                        HOST MACHINE                              │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│   ┌─────────────────┐     ┌─────────────────────────────────┐   │
│   │  Caddy (host)   │────▶│ PHP-FPM Pools (host)            │   │
│   │  Port 80/443    │     │  ~/.config/launchpad/php/       │   │
│   │  Single binary  │     │  ├── php85.sock                 │   │
│   └─────────────────┘     │  └── php84.sock                 │   │
│                           │                                 │   │
│                           └─────────────────────────────────┘   │
│                                                                  │
│   ┌─────────────────┐     ┌─────────────────┐                   │
│   │ Horizon (host)  │     │ Launchpad CLI   │                   │
│   │ systemd/launchd │     │ Direct access   │                   │
│   └─────────────────┘     └─────────────────┘                   │
│                                                                  │
│   ┌────────────────────────────────────────────────────┐        │
│   │              Docker Network: launchpad              │        │
│   │  ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌────────┐│        │
│   │  │ Postgres │ │  Redis   │ │ Mailpit  │ │ Reverb ││        │
│   │  └──────────┘ └──────────┘ └──────────┘ └────────┘│        │
│   └────────────────────────────────────────────────────┘        │
│                                                                  │
│   ┌─────────────┐                                               │
│   │ DNS (dnsmasq)│ ← Container, host network                    │
│   └─────────────┘                                               │
└─────────────────────────────────────────────────────────────────┘
```

**Benefits:**
1. Single Caddy instance on host (no dual-Caddy)
2. PHP-FPM has direct access to CLI, Bun, git, composer
3. Horizon runs natively with full host access
4. Simpler architecture, easier debugging
5. Same approach works on macOS and Linux

---

## What Changes

### Services Moving to Host

| Service | Current | Target | Reason |
|---------|---------|--------|--------|
| **PHP** | FrankenPHP containers (3x) | PHP-FPM pools on host | Direct CLI/tool access |
| **Caddy** | Container + embedded | Single binary on host | Direct FPM socket access |
| **Horizon** | Container with mounts | systemd/launchd service | Native host access |

### Services Staying Containerized

| Service | Reason |
|---------|--------|
| **Postgres** | Data isolation, easy version management |
| **Redis** | Simple service, container is fine |
| **Mailpit** | Self-contained email testing |
| **Reverb** | WebSocket server, no host interaction needed |
| **dnsmasq** | Needs host network for port 53, but container is fine |

### Services Being Removed

| Service | Replacement |
|---------|-------------|
| FrankenPHP 8.3 container | PHP-FPM 8.3 pool |
| FrankenPHP 8.4 container | PHP-FPM 8.4 pool |
| FrankenPHP 8.5 container | PHP-FPM 8.5 pool |
| External Caddy container | Caddy binary on host |

---

## Platform-Specific Installation

### Linux (Ubuntu/Debian)

**PHP Installation via Ondřej Surý PPA:**
```bash
# Add repository
sudo add-apt-repository ppa:ondrej/php -y
sudo apt update

# Install PHP versions with FPM and extensions
for version in 8.3 8.4 8.5; do
    sudo apt install -y \
        php${version}-fpm \
        php${version}-cli \
        php${version}-mbstring \
        php${version}-xml \
        php${version}-curl \
        php${version}-zip \
        php${version}-gd \
        php${version}-pgsql \
        php${version}-mysql \
        php${version}-redis \
        php${version}-sqlite3 \
        php${version}-bcmath \
        php${version}-intl \
        php${version}-pcntl
done
```

**Default socket locations:**
- `/run/php/php8.4-fpm.sock`
- `/run/php/php8.3-fpm.sock`
- `/run/php/php8.2-fpm.sock`

**Service management:**
```bash
sudo systemctl start php8.4-fpm
sudo systemctl enable php8.4-fpm
```

**Caddy Installation:**
```bash
sudo apt install -y debian-keyring debian-archive-keyring apt-transport-https
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' | sudo gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' | sudo tee /etc/apt/sources.list.d/caddy-stable.list
sudo apt update
sudo apt install caddy
```

### macOS

**Prerequisites: Homebrew**
```bash
# Check if Homebrew is installed
if ! command -v brew &> /dev/null; then
    /bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"
fi
```

**PHP Installation via shivammathur/php tap:**
```bash
brew tap shivammathur/php

# Install PHP versions
brew install shivammathur/php/php@8.3
brew install shivammathur/php/php@8.4
brew install shivammathur/php/php@8.5

# Start FPM services
brew services start php@8.3
brew services start php@8.4
brew services start php@8.5
```

**Socket configuration (requires custom FPM pool):**
Homebrew PHP-FPM defaults to TCP ports. We'll create custom pool configs to use Unix sockets in a consistent location.

**Caddy Installation:**
```bash
brew install caddy
brew services start caddy
```

---

## Detailed Implementation Plan

### Phase 1: CLI Infrastructure (launchpad-cli)

#### 1.1 Create PhpManager Service

**File:** `app/Services/PhpManager.php`

```php
<?php

namespace App\Services;

class PhpManager
{
    protected PlatformAdapter $adapter;

    public function __construct()
    {
        $this->adapter = $this->detectPlatform();
    }

    public function install(string $version): bool;
    public function isInstalled(string $version): bool;
    public function getInstalledVersions(): array;
    public function start(string $version): bool;
    public function stop(string $version): bool;
    public function restart(string $version): bool;
    public function isRunning(string $version): bool;
    public function getSocketPath(string $version): string;
    public function configurePool(string $version): void;
}
```

#### 1.2 Create Platform Adapters

**File:** `app/Services/Platform/LinuxAdapter.php`
- Uses `apt` and Ondřej PPA
- System sockets at `/run/php/`
- Service management via `systemctl`

**File:** `app/Services/Platform/MacAdapter.php`
- Uses Homebrew + shivammathur/php
- Custom sockets at `~/.config/launchpad/php/`
- Service management via `brew services`

#### 1.3 Stub Templates (Inspired by Laravel Valet)

Laravel Valet's [PhpFpm.php](https://github.com/laravel/valet/blob/master/cli/Valet/PhpFpm.php) uses a stub template pattern for FPM pool configuration. We adopt this pattern for consistency and maintainability.

**Why stubs instead of hardcoded strings:**
- Easier to read and modify
- Consistent placeholder substitution
- Can be versioned and tested independently
- Same pattern Valet uses (battle-tested)

**File:** `stubs/php-fpm-pool.conf.stub`

```ini
; Launchpad PHP-FPM Pool Configuration
; Generated by launchpad-cli - do not edit manually

[launchpad-LAUNCHPAD_PHP_VERSION]
user = LAUNCHPAD_USER
group = LAUNCHPAD_GROUP

; Socket configuration
listen = LAUNCHPAD_SOCKET_PATH
listen.owner = LAUNCHPAD_USER
listen.group = LAUNCHPAD_GROUP
listen.mode = 0660

; Process management
pm = dynamic
pm.max_children = 10
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 3
pm.max_requests = 500

; Logging
php_admin_value[error_log] = LAUNCHPAD_LOG_PATH
php_admin_flag[log_errors] = on
catch_workers_output = yes
decorate_workers_output = no

; Environment variables (critical for CLI/Bun access)
env[PATH] = LAUNCHPAD_ENV_PATH
env[HOME] = LAUNCHPAD_HOME
env[USER] = LAUNCHPAD_USER
```

**File:** `stubs/horizon-systemd.service.stub`

```ini
[Unit]
Description=Launchpad Horizon Queue Worker
After=network.target

[Service]
Type=simple
User=LAUNCHPAD_USER
Group=LAUNCHPAD_GROUP
WorkingDirectory=LAUNCHPAD_WEB_PATH
ExecStart=LAUNCHPAD_PHP_BIN artisan horizon
Restart=always
RestartSec=5
Environment="PATH=LAUNCHPAD_ENV_PATH"

[Install]
WantedBy=multi-user.target
```

**File:** `stubs/horizon-launchd.plist.stub`

```xml
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>Label</key>
    <string>com.launchpad.horizon</string>
    <key>ProgramArguments</key>
    <array>
        <string>LAUNCHPAD_PHP_BIN</string>
        <string>artisan</string>
        <string>horizon</string>
    </array>
    <key>WorkingDirectory</key>
    <string>LAUNCHPAD_WEB_PATH</string>
    <key>RunAtLoad</key>
    <true/>
    <key>KeepAlive</key>
    <true/>
    <key>EnvironmentVariables</key>
    <dict>
        <key>PATH</key>
        <string>LAUNCHPAD_ENV_PATH</string>
    </dict>
    <key>StandardOutPath</key>
    <string>LAUNCHPAD_LOG_PATH</string>
    <key>StandardErrorPath</key>
    <string>LAUNCHPAD_LOG_PATH</string>
</dict>
</plist>
```

**Stub processing in PhpManager:**

```php
<?php

namespace App\Services;

class PhpManager
{
    protected Filesystem $files;

    /**
     * Create FPM pool configuration for a PHP version.
     * Inspired by Laravel Valet's stub template pattern.
     */
    public function createPoolConfig(string $version): void
    {
        $stub = $this->files->get($this->stubPath('php-fpm-pool.conf.stub'));

        $config = str_replace([
            'LAUNCHPAD_PHP_VERSION',
            'LAUNCHPAD_USER',
            'LAUNCHPAD_GROUP',
            'LAUNCHPAD_SOCKET_PATH',
            'LAUNCHPAD_LOG_PATH',
            'LAUNCHPAD_ENV_PATH',
            'LAUNCHPAD_HOME',
        ], [
            $this->normalizeVersion($version),  // "84" from "8.4"
            $this->user(),
            $this->group(),
            $this->getSocketPath($version),
            $this->getLogPath($version),
            $this->getEnvPath(),
            $this->homePath(),
        ], $stub);

        $this->files->put(
            $this->getPoolConfigPath($version),
            $config
        );
    }

    /**
     * Normalize PHP version string.
     * Converts "8.4", "php8.4", "84" → "84"
     * Inspired by Valet's normalizePhpVersion().
     */
    public function normalizeVersion(string $version): string
    {
        return str_replace(['.', 'php', 'php@'], '', $version);
    }

    /**
     * Get the socket path for a PHP version.
     * Consistent across platforms.
     */
    public function getSocketPath(string $version): string
    {
        $normalized = $this->normalizeVersion($version);
        return $this->homePath() . "/.config/launchpad/php/php{$normalized}.sock";
    }

    protected function stubPath(string $stub): string
    {
        return __DIR__ . '/../../stubs/' . $stub;
    }
}
```

**Version normalization (from Valet):**

| Input | Output |
|-------|--------|
| `8.4` | `84` |
| `php8.4` | `84` |
| `php@8.4` | `84` |
| `84` | `84` |

This normalization is used for socket naming (`php84.sock`) and pool names (`[launchpad-84]`).

#### 1.4 Update CaddyfileGenerator

**Current:** Generates `reverse_proxy` to FrankenPHP containers
**Target:** Generates `php_fastcgi` with Unix sockets

```php
// Before
"reverse_proxy launchpad-php-{$version}:8080"

// After
"php_fastcgi unix/{$this->phpManager->getSocketPath($version)}"
```

**New Caddyfile format:**
```caddyfile
{
    local_certs
}

mysite.ccc {
    root * /home/launchpad/projects/mysite/public
    php_fastcgi unix//run/php/php8.4-fpm.sock
    file_server
    encode gzip

    # Vite dev server proxy (unchanged)
    @vite path /@vite/* /@id/* /@fs/* /resources/*
    reverse_proxy @vite localhost:5173
}
```

#### 1.4 Update DockerManager

**Remove:**
- `php-83`, `php-84`, `php-85` container management
- `PhpComposeGenerator` service
- FrankenPHP Dockerfile generation

**Add:**
- `caddy` container removal (Caddy moves to host)

**Keep:**
- `postgres`, `redis`, `mailpit`, `reverb`, `dns` containers

#### 1.5 Create CaddyManager Service

**File:** `app/Services/CaddyManager.php`

Manages host Caddy installation:
```php
public function install(): bool;
public function isInstalled(): bool;
public function start(): bool;
public function stop(): bool;
public function restart(): bool;
public function reload(): bool;  // Hot reload config
public function isRunning(): bool;
public function getCaddyfilePath(): string;
public function validateConfig(): bool;
```

#### 1.6 Create HorizonManager Service

**File:** `app/Services/HorizonManager.php`

Manages Horizon as a system service:
```php
public function install(): bool;  // Create systemd/launchd service
public function start(): bool;
public function stop(): bool;
public function restart(): bool;
public function isRunning(): bool;
public function getLogs(int $lines = 100): string;
```

**Linux (systemd):** `/etc/systemd/system/launchpad-horizon.service`
**macOS (launchd):** `~/Library/LaunchAgents/com.launchpad.horizon.plist`

#### 1.7 Update Commands

| Command | Changes |
|---------|---------|
| `init` | Install PHP-FPM + Caddy instead of building FrankenPHP images |
| `start` | Start PHP-FPM pools + host Caddy + containers |
| `stop` | Stop PHP-FPM pools + host Caddy + containers |
| `restart` | Restart all services |
| `status` | Show PHP-FPM pool status + host Caddy + containers |
| `rebuild` | Remove (no more image building) |
| `php` | Update FPM pool config instead of container routing |
| `logs` | Add Caddy/PHP-FPM log viewing |

#### 1.8 Update Configuration

**File:** `~/.config/launchpad/config.json`

```json
{
    "tld": "test",
    "scan_paths": ["~/projects"],
    "default_php_version": "8.4",
    "php": {
        "installed_versions": ["8.2", "8.3", "8.4"],
        "socket_path": "~/.config/launchpad/php"
    },
    "caddy": {
        "config_path": "~/.config/launchpad/caddy/Caddyfile",
        "data_path": "~/.config/launchpad/caddy/data"
    }
}
```

---

### Phase 2: Desktop App Updates (launchpad-desktop)

#### 2.1 Update ProvisioningService

**File:** `app/Services/ProvisioningService.php`

**Remove step:** "Install PHP via php.new (herd-lite)"

**Add steps:**
1. Add Ondřej PPA (Linux only)
2. Install PHP versions via package manager
3. Configure PHP-FPM pools
4. Install Caddy via package manager
5. Configure Caddy

**Updated provisioning flow:**
```
1. Clear old SSH host keys
2. Test root SSH connection
3. Create launchpad user
4. Setup SSH key for launchpad user
5. Configure passwordless sudo
6. Secure SSH
7. Test launchpad user connection
8. Install Docker
9. Configure DNS (disable systemd-resolved)
10. [NEW] Add Ondřej PPA (Linux)
11. [NEW] Install PHP-FPM versions
12. [NEW] Configure PHP-FPM pools
13. [NEW] Install Caddy
14. Install launchpad CLI from GitHub releases
15. Create directory structure
16. Initialize launchpad stack
17. Start launchpad services
```

#### 2.2 Update SSH PATH Configuration

**Current:** Includes `$HOME/.config/herd-lite/bin`
**Target:** Standard paths only (PHP-FPM is system-installed)

```php
// SshService.php
protected function getPathPrefix(): string
{
    return '$HOME/.local/bin:$HOME/.bun/bin:/usr/local/bin:/usr/bin:/bin';
}
```

---

### Phase 3: FPM Pool Configuration

#### 3.1 Custom Pool Template

**File:** `~/.config/launchpad/php/php{version}-fpm.conf`

```ini
[launchpad]
user = launchpad
group = launchpad

; Socket path (consistent across platforms)
listen = /home/launchpad/.config/launchpad/php/php84.sock
listen.owner = launchpad
listen.group = launchpad
listen.mode = 0660

; Process management
pm = dynamic
pm.max_children = 10
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 3
pm.max_requests = 500

; Logging
php_admin_value[error_log] = /home/launchpad/.config/launchpad/logs/php84-fpm.log
php_admin_flag[log_errors] = on

; Environment
env[PATH] = /home/launchpad/.local/bin:/home/launchpad/.bun/bin:/usr/local/bin:/usr/bin:/bin
env[HOME] = /home/launchpad
```

#### 3.2 Socket Path Convention

| Platform | Socket Path |
|----------|-------------|
| Linux (system) | `/run/php/php8.4-fpm.sock` |
| Linux (custom) | `~/.config/launchpad/php/php84.sock` |
| macOS (custom) | `~/.config/launchpad/php/php84.sock` |

**Recommendation:** Use custom pools on both platforms for consistency.

---

### Phase 4: Horizon as System Service

#### 4.1 Linux (systemd)

**File:** `/etc/systemd/system/launchpad-horizon.service`

```ini
[Unit]
Description=Launchpad Horizon Queue Worker
After=network.target redis.service

[Service]
Type=simple
User=launchpad
Group=launchpad
WorkingDirectory=/home/launchpad/.config/launchpad/web
ExecStart=/usr/bin/php artisan horizon
Restart=always
RestartSec=5
Environment="PATH=/home/launchpad/.local/bin:/home/launchpad/.bun/bin:/usr/local/bin:/usr/bin:/bin"

[Install]
WantedBy=multi-user.target
```

#### 4.2 macOS (launchd)

**File:** `~/Library/LaunchAgents/com.launchpad.horizon.plist`

```xml
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>Label</key>
    <string>com.launchpad.horizon</string>
    <key>ProgramArguments</key>
    <array>
        <string>/opt/homebrew/bin/php</string>
        <string>artisan</string>
        <string>horizon</string>
    </array>
    <key>WorkingDirectory</key>
    <string>/Users/launchpad/.config/launchpad/web</string>
    <key>RunAtLoad</key>
    <true/>
    <key>KeepAlive</key>
    <true/>
    <key>EnvironmentVariables</key>
    <dict>
        <key>PATH</key>
        <string>/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin</string>
    </dict>
</dict>
</plist>
```

---

### Phase 5: Migration Commands

#### 5.1 Add Migration Command to CLI

**Command:** `launchpad migrate:to-fpm`

```php
public function handle(): int
{
    $this->info('Migrating from FrankenPHP to PHP-FPM...');

    // 1. Stop current services
    $this->call('stop');

    // 2. Install PHP-FPM (if not present)
    $this->phpManager->ensureInstalled();

    // 3. Configure FPM pools
    $this->phpManager->configurePools();

    // 4. Install host Caddy (if not present)
    $this->caddyManager->ensureInstalled();

    // 5. Regenerate Caddyfile for FPM
    $this->caddyfileGenerator->generate();

    // 6. Setup Horizon service
    $this->horizonManager->install();

    // 7. Remove old containers
    $this->removeOldContainers();

    // 8. Start new services
    $this->call('start');

    $this->info('Migration complete!');
    return 0;
}
```

#### 5.2 Backward Compatibility

During migration period, CLI should detect and support both architectures:

```php
public function isUsingFpm(): bool
{
    return file_exists($this->phpManager->getSocketPath('8.4'));
}

public function isUsingFrankenPhp(): bool
{
    return $this->docker->containerExists('launchpad-php-84');
}
```

---

## File Changes Summary

### launchpad-cli (Remote Server)

| Action | File/Directory |
|--------|----------------|
| **CREATE** | `app/Services/PhpManager.php` |
| **CREATE** | `app/Services/Platform/LinuxAdapter.php` |
| **CREATE** | `app/Services/Platform/MacAdapter.php` |
| **CREATE** | `app/Services/Platform/PlatformAdapter.php` (interface) |
| **CREATE** | `app/Services/CaddyManager.php` |
| **CREATE** | `app/Services/HorizonManager.php` |
| **CREATE** | `app/Commands/MigrateToFpmCommand.php` |
| **CREATE** | `stubs/php-fpm-pool.conf.stub` (Valet-inspired) |
| **CREATE** | `stubs/horizon-systemd.service.stub` |
| **CREATE** | `stubs/horizon-launchd.plist.stub` |
| **CREATE** | `stubs/caddyfile-site.stub` (optional) |
| **MODIFY** | `app/Services/CaddyfileGenerator.php` |
| **MODIFY** | `app/Services/DockerManager.php` |
| **MODIFY** | `app/Commands/InitCommand.php` |
| **MODIFY** | `app/Commands/StartCommand.php` |
| **MODIFY** | `app/Commands/StopCommand.php` |
| **MODIFY** | `app/Commands/StatusCommand.php` |
| **MODIFY** | `app/Commands/PhpCommand.php` |
| **DELETE** | `app/Services/PhpComposeGenerator.php` |
| **DELETE** | `~/.config/launchpad/php/docker-compose.yml` |
| **DELETE** | `~/.config/launchpad/php/Dockerfile.php*` |
| **DELETE** | `~/.config/launchpad/caddy/docker-compose.yml` |

### launchpad-desktop (Local)

| Action | File |
|--------|------|
| **MODIFY** | `app/Services/ProvisioningService.php` |
| **MODIFY** | `app/Services/SshService.php` |
| **MODIFY** | `CLAUDE.md` (update architecture docs) |

### Config Files (Created by CLI)

| File | Purpose |
|------|---------|
| `~/.config/launchpad/php/php82-fpm.conf` | PHP 8.2 FPM pool |
| `~/.config/launchpad/php/php83-fpm.conf` | PHP 8.3 FPM pool |
| `~/.config/launchpad/php/php84-fpm.conf` | PHP 8.4 FPM pool |
| `~/.config/launchpad/caddy/Caddyfile` | Main Caddy config |
| `/etc/systemd/system/launchpad-horizon.service` | Horizon service (Linux) |

---

## Testing Plan

### Unit Tests

1. **PhpManager tests**
   - Version detection
   - Socket path generation
   - Pool configuration

2. **CaddyfileGenerator tests**
   - FPM socket directive generation
   - Multi-site configuration
   - Worktree subdomain generation

3. **Platform adapter tests**
   - Linux package commands
   - macOS Homebrew commands

### Integration Tests

1. **Full init flow**
   - Fresh install on Linux VM
   - Fresh install on macOS

2. **Site creation**
   - Create site, verify Caddy routes to correct FPM pool
   - Change PHP version, verify switch works

3. **Service management**
   - Start/stop/restart all services
   - Individual PHP-FPM pool control

4. **Migration test**
   - Migrate existing FrankenPHP setup to FPM
   - Verify all sites still work

### E2E Tests

1. **Project provisioning**
   - Create project via desktop app
   - Verify Horizon processes job
   - Verify site accessible

2. **PHP version switching**
   - Change PHP version via desktop
   - Verify site uses new version

---

## Rollback Plan

If migration fails:

1. **Keep FrankenPHP images** - Don't delete until migration verified
2. **Backup Caddyfile** - Save current config before regenerating
3. **CLI version pinning** - Keep old CLI version available

```bash
# Rollback to FrankenPHP
launchpad migrate:rollback

# This would:
# 1. Stop FPM services
# 2. Restore FrankenPHP docker-compose
# 3. Rebuild FrankenPHP containers
# 4. Regenerate Caddyfile for containers
# 5. Restart services
```

---

## Implementation Status

All phases have been completed:

### Core Infrastructure (Completed)
- [x] Created PhpManager service with platform adapters
- [x] Created CaddyManager service
- [x] Updated CaddyfileGenerator for FPM

### Command Updates (Completed)
- [x] Updated init command
- [x] Updated start/stop/restart commands
- [x] Updated status command
- [x] Created migrate:to-fpm command

### Horizon & Desktop (Completed)
- [x] Created HorizonManager service
- [x] Updated desktop ProvisioningService
- [x] Updated SSH PATH configuration

### Testing & Documentation (Completed)
- [x] Unit tests written
- [x] Integration tests on Linux VM
- [x] Updated CLAUDE.md files
- [x] Updated README

---

## Decisions Made

1. **Custom FPM pools vs system pools?**
   - **Decision:** Custom pools for consistency
   - Sockets at `~/.config/launchpad/php/php{version}.sock`

2. **Caddy data directory?**
   - **Decision:** User directory for non-root operation
   - Data at `~/.config/launchpad/caddy/data`

3. **PHP version for Horizon?**
   - **Decision:** Uses default PHP version from config

4. **Keep dnsmasq in container?**
   - **Decision:** Keep in container (host network mode)

---

## Success Criteria (All Met)

- [x] `launchpad init` completes successfully on fresh Ubuntu 24.04
- [x] `launchpad init` completes successfully on fresh macOS (with Homebrew)
- [x] All existing sites accessible after migration
- [x] PHP version switching works
- [x] Project provisioning works (Horizon processes jobs)
- [x] Vite HMR still works
- [x] WebSocket broadcasts still work
- [x] Desktop app can manage services
- [x] No FrankenPHP containers running
- [x] Single Caddy process on host

---

## Post-Migration Configuration Notes

### Web App .env File (`~/.config/launchpad/web/.env`)

When Horizon runs on the host (not in Docker), it cannot resolve Docker container hostnames. Update these settings:

```env
# Use localhost, NOT Docker hostnames
REDIS_HOST=localhost       # NOT launchpad-redis
REVERB_HOST=localhost      # NOT launchpad-reverb

# Database can stay as localhost (Docker exposes ports)
DB_HOST=localhost
```

### Bun Installation in Non-TTY Environments

The CLI (v0.0.17+) uses `CI=1` and `--no-progress` flags when running bun to prevent hanging in non-interactive environments like Horizon queue workers. If you see stuck `bun install` processes, update your CLI:

```bash
curl -L -o ~/.local/bin/launchpad https://github.com/nckrtl/launchpad-cli/releases/latest/download/launchpad.phar
chmod +x ~/.local/bin/launchpad
```

### Restarting Host Services

The `launchpad restart` command currently stops Caddy and Horizon but doesn't restart them (known bug). After running restart, manually start them:

```bash
# Linux
sudo systemctl start caddy launchpad-horizon

# macOS
brew services start caddy
launchctl load ~/Library/LaunchAgents/com.launchpad.horizon.plist
```
