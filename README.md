# Orbit Desktop

A NativePHP desktop application for managing local development environments across multiple machines, powered by [Orbit CLI](https://github.com/nckrtl/orbit-cli). This is a thin shell that requires [orbit-core](https://github.com/hardimpactdev/orbit-core).

> **Note:** This is a **macOS-only** application. It relies on macOS-specific features like `/etc/resolver/` for DNS management and Touch ID for sudo authentication.

## Overview

Orbit Desktop provides a native macOS app to manage both local and remote Orbit CLI installations. For single-server web dashboard deployment, see [orbit-web](https://github.com/hardimpactdev/orbit-web).

### Features

- **Multi-Environment Management**: Manage local and remote Orbit installations from one app
- **Project Management**: Create, configure, and monitor Laravel projects
- **Service Control**: Start/stop PHP-FPM, Caddy, Redis, PostgreSQL, etc.
- **Real-time Status**: WebSocket-based updates via Laravel Reverb
- **Automatic DNS Setup**: Configures macOS DNS resolvers with Touch ID
- **Multi-Editor Support**: Open projects in Cursor, VS Code, Windsurf, Zed, etc.
- **Native Notifications**: System notifications for provisioning status

## Requirements

- macOS (for DNS resolver management with Touch ID)
- PHP 8.3+
- Node.js 18+
- Composer

## Installation

```bash
# Clone the repository
git clone https://github.com/hardimpactdev/orbit.git
cd orbit

# Install dependencies
composer install
npm install

# Run the app in development
php artisan native:serve
```

## Configuration

### Desktop Mode Settings

```env
ORBIT_MODE=desktop
MULTI_ENVIRONMENT_MANAGEMENT=true
```

### Touch ID Setup

For Touch ID authentication when updating DNS resolvers:

```bash
sudo sh -c 'echo "auth sufficient pam_tid.so" > /etc/pam.d/sudo_local'
```

## Architecture

This project is a **thin NativePHP shell** that delegates to [orbit-core](https://github.com/hardimpactdev/orbit-core):

```
orbit-desktop/
  app/
    Models/User.php                    # Only local model (auth)
    Services/NotificationService.php   # NativePHP-specific notifications
    Providers/AppServiceProvider.php   # Registers orbit-core routes
    Providers/NativeAppServiceProvider.php  # NativePHP config
  config/
    orbit.php                          # Mode configuration
    nativephp.php                      # NativePHP settings
  resources/
    views/app.blade.php                # Blade template
  vite.config.ts                       # Compiles from orbit-core
```

All business logic, controllers, services, and Vue components come from orbit-core.

### What Lives in Desktop vs Core

| Desktop (this repo) | Core (orbit-core) |
|---------------------|-------------------|
| NativePHP configuration | All Eloquent models |
| NotificationService | All services |
| User model | All controllers |
| Touch ID integration | All Vue pages/components |
| Menu bar app config | Routes and middleware |

## Development

```bash
# Start NativePHP dev server
php artisan native:serve

# Run tests
php artisan test

# Build for distribution
php artisan native:build
```

## Related Projects

- [Orbit Core](https://github.com/hardimpactdev/orbit-core) - Shared package (required)
- [Orbit Web](https://github.com/hardimpactdev/orbit-web) - Web dashboard alternative
- [Orbit CLI](https://github.com/nckrtl/orbit-cli) - The CLI tool that powers everything

## License

MIT
