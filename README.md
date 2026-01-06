# Launchpad Desktop

A NativePHP/Electron desktop application for managing local and remote [Launchpad CLI](https://github.com/nckrtl/launchpad-cli) installations.

## Features

- **Local Environment Management**: Control your local Launchpad installation directly
- **Remote Server Management**: Manage remote Launchpad installations via SSH
- **Server Provisioning**: Provision new Ubuntu servers with the complete Launchpad stack
- **Automatic DNS Setup**: Configures macOS DNS resolvers with Touch ID authentication
- **Multi-Editor Support**: Open projects in Cursor, VS Code, Windsurf, Zed, and more

## Requirements

- macOS (for DNS resolver management with Touch ID)
- PHP 8.3+
- Node.js 18+
- Composer

## Installation

```bash
# Clone the repository
git clone https://github.com/nckrtl/launchpad-desktop.git
cd launchpad-desktop

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

### DNS Resolution

When you configure a TLD (e.g., `.test`, `.dev`) for an environment:

1. **Mac Resolver**: Creates `/etc/resolver/{tld}` pointing to the server's DNS
2. **Remote DNS Container**: Rebuilds the dnsmasq container with the correct TLD
3. **Caddy Config**: Restarts Launchpad to regenerate Caddy configuration

### Communication

- **Local servers**: Direct PHP process execution
- **Remote servers**: SSH with ControlMaster connection pooling

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

- [Launchpad CLI](https://github.com/nckrtl/launchpad-cli) - The command-line tool this app controls

## License

MIT
