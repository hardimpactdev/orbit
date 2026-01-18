# Orbit

A unified codebase for managing [Orbit CLI](https://github.com/nckrtl/orbit-cli) installations, supporting both single-environment web deployment and multi-environment desktop management.

> **Note:** This is a **macOS-only** application. It relies on macOS-specific features like `/etc/resolver/` for DNS management and Touch ID for sudo authentication. Remote environments can run any Linux distribution.

## Features

- **Local Environment Management**: Control your local Orbit CLI installation directly
- **Remote Server Management**: Manage remote Orbit CLI installations via SSH
- **Server Provisioning**: Provision new Ubuntu servers with the complete Orbit stack (PHP-FPM, Caddy, Docker services)
- **Automatic DNS Setup**: Configures macOS DNS resolvers with Touch ID authentication
- **Multi-Editor Support**: Open projects in Cursor, VS Code, Windsurf, Zed, and more
- **Real-time Status**: WebSocket-based updates for project provisioning and service status

## Requirements

- macOS (for DNS resolver management with Touch ID)
- PHP 8.3+
- Node.js 18+
- Composer

## Installation

```bash
# Clone the repository
git clone https://github.com/nckrtl/orbit-desktop.git
cd orbit-desktop

# Install dependencies
composer install
npm install

# Run the app in development
php artisan native:serve
```

## Deployment Modes

Orbit supports two deployment modes controlled by environment variables:

### Web Mode (Single Environment)
For deploying as a web application on a server:

```env
ORBIT_MODE=web
MULTI_ENVIRONMENT_MANAGEMENT=false
```

**Setup:**
```bash
composer install && npm install
php artisan migrate
php artisan orbit:init  # Creates local environment
npm run build
```

**Characteristics:**
- Flat routes: `/projects`, `/services`, etc.
- No environment switcher UI
- Manages only the local environment
- No NativePHP dependency

### Desktop Mode (Multi-Environment)
For running as a NativePHP desktop application:

```env
ORBIT_MODE=desktop
MULTI_ENVIRONMENT_MANAGEMENT=true
```

**Characteristics:**
- Prefixed routes: `/environments/{id}/projects`
- Environment switcher UI visible
- Manages multiple local and remote environments
- Full NativePHP integration

## Configuration

### config/orbit.php
```php
return [
    'mode' => env('ORBIT_MODE', 'web'),
    'multi_environment' => env('MULTI_ENVIRONMENT_MANAGEMENT', false),
];
```

### orbit:init Command
Creates the local environment for web mode:
```bash
php artisan orbit:init
```
- Idempotent (safe to run multiple times)
- Reads TLD from `~/.config/orbit/config.json`
- Falls back to `.test` if not found

## macOS Touch ID Setup

For Touch ID authentication when updating DNS resolvers, create `/etc/pam.d/sudo_local`:

```bash
sudo sh -c 'echo "auth sufficient pam_tid.so" > /etc/pam.d/sudo_local'
```

This enables Touch ID for sudo commands, which the app uses to manage `/etc/resolver/` files.

## How It Works

### Architecture

The Orbit stack uses **PHP-FPM on the host** with **Caddy** as the web server:

- **PHP-FPM**: Multiple pools (8.4, 8.5) with Unix sockets at `~/.config/orbit/php/`
- **Caddy**: Single binary on host serving sites with automatic HTTPS
- **Horizon**: Queue worker as systemd (Linux) or launchd (macOS) service
- **Docker**: PostgreSQL, Redis, Mailpit, Reverb, dnsmasq remain containerized

### DNS Resolution

When you configure a TLD (e.g., `.test`, `.ccc`) for an environment:

1. **Mac Resolver**: Creates `/etc/resolver/{tld}` pointing to the server's DNS
2. **Remote DNS Container**: Rebuilds the dnsmasq container with the correct TLD
3. **Caddy Config**: Restarts Orbit to regenerate Caddy configuration

### Communication

- **Local environments**: Direct PHP process execution via NativePHP backend.
- **Remote environments**: Direct API calls from Vue to the remote Orbit Web API (`https://orbit.{tld}/api/...`) for optimal performance. SSH is used primarily for initial provisioning and low-level configuration.
- **Real-time updates**: Leverages Laravel Reverb on the remote server to broadcast status changes (provisioning progress, service status) directly to the Desktop app.

## Development

```bash
# Run in development mode
php artisan native:serve

# Build for production
php artisan native:build

# Run migrations
php artisan migrate
```

## Related Projects

- [Orbit CLI](https://github.com/nckrtl/orbit-cli) - The command-line tool this app controls

## License

MIT
