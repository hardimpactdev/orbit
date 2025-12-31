# Launchpad Desktop

A NativePHP/Electron desktop application for managing local and remote launchpad CLI installations.

## Project Overview

This is a Laravel 12 application wrapped in NativePHP/Electron that provides a GUI for the launchpad CLI tool. It can manage:
- Local launchpad installations (on the same machine)
- Remote launchpad installations (via SSH)

## Architecture

### Communication Pattern
- **Local servers**: Direct PHP process execution
- **Remote servers**: SSH with ControlMaster connection pooling for persistent connections

### Key Services

- **SshService** (`app/Services/SshService.php`): Handles SSH connections with ControlMaster pooling. Control sockets stored in `/tmp/launchpad-ssh/` to avoid macOS path length limits.

- **LaunchpadService** (`app/Services/LaunchpadService.php`): Wraps launchpad CLI commands. Searches multiple paths for the binary (`$HOME/projects/launchpad/launchpad`, `$HOME/.local/bin/launchpad`, etc.).

- **CliUpdateService** (`app/Services/CliUpdateService.php`): Manages the local CLI installation at `~/.local/bin/launchpad`.

### Models

- **Server**: Represents a local or remote machine with launchpad installed
  - Fields: name, host, user, port, is_local, is_default, metadata, last_connected_at

- **Setting**: Key-value store for app settings (editor preference, etc.)

### External Integrations

- **Editor Support**: Opens projects via SSH Remote extension
  - URL format: `{editor}://vscode-remote/ssh-remote+user@host/path?windowId=_blank`
  - Supported editors: Cursor, VS Code, VS Code Insiders, Windsurf, Antigravity, Zed

- **Browser**: Uses `Shell::openExternal()` via `/open-external` route to open URLs in system browser

## Common Tasks

### Adding a new launchpad CLI command

1. Add method to `LaunchpadService` that calls `executeCommand($server, 'command --json')`
2. Add controller method in `ServerController`
3. Add route in `routes/web.php` under the servers prefix group
4. Add frontend JavaScript in the relevant Blade view

### SSH Connection Issues

- Control sockets are stored in `/tmp/launchpad-ssh/` with hashed filenames
- PATH is prefixed for non-interactive SSH: `$HOME/.config/herd-lite/bin:$HOME/.local/bin:...`
- Binary detection checks multiple common installation paths

## Development

```bash
# Install dependencies
composer install
npm install

# Run the app in development
php artisan native:serve

# Build for production
php artisan native:build
```

## Related Projects

- **launchpad** (CLI): The command-line tool this app controls, typically located at `~/projects/launchpad/` on development machines
