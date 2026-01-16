# Orbit Desktop

A NativePHP/Electron desktop application for managing local and remote [Orbit CLI](https://github.com/nckrtl/orbit-cli) installations.

> **Note:** This is a **macOS-only** application. It relies on macOS-specific features like `/etc/resolver/` for DNS management and Touch ID for sudo authentication. Remote environments can run any Linux distribution.

## Features

- **Local Environment Management**: Control your local Launchpad installation directly
- **Remote Server Management**: Manage remote Launchpad installations via SSH
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
3. **Caddy Config**: Restarts Launchpad to regenerate Caddy configuration

### Communication

- **Local environments**: Direct PHP process execution
- **Remote environments**: Direct API calls to `https://orbit.{tld}/api/...` for performance, SSH for provisioning

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
