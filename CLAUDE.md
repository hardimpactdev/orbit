# Launchpad Desktop

A NativePHP/Electron desktop application for managing local and remote launchpad CLI installations.

## Important: Working with Environments

**Projects/sites run on remote servers, not locally.** When referencing a URL like `https://launchpad-cli.ccc/` or `https://platform11-2026.ccc/`:

1. **Look up the environment** - Check which server hosts this site (TLD indicates the server)
2. **SSH into the server** - Use `ssh user@IP` to access the server
3. **Fix issues on the remote server** - Caddy configs, project files, and launchpad CLI are there

**Current environments:**
| Environment | SSH Command | TLD | Notes |
|-------------|-------------|-----|-------|
| Ubuntu VPS | `ssh launchpad@10.8.0.16` or `ssh ai` | `.ccc` | Main dev server |
| Local | N/A (localhost) | `.test` | Local machine |

**Key paths on remote servers:**
- Projects: `~/projects/`
- **Launchpad CLI source code**: `~/projects/launchpad-cli/` - THIS IS WHERE TO MAKE CLI CHANGES
- Launchpad config: `~/.config/launchpad/`
- Caddy config: `~/.config/launchpad/caddy/Caddyfile`
- PHP Caddyfile: `~/.config/launchpad/php/Caddyfile`
- Worktrees config: `~/.config/launchpad/worktrees.json`

**Important:** The launchpad CLI source code lives on the remote server at `ssh ai:~/projects/launchpad-cli/`. Any changes to CLI behavior (site scanning, Caddy generation, worktrees, etc.) must be made there via SSH.

## Project Overview

This is a Laravel 12 application wrapped in NativePHP/Electron that provides a GUI for the launchpad CLI tool. It can manage:
- Local launchpad installations (on the same machine)
- Remote launchpad installations (via SSH)
- Provisioning new servers from scratch

## Architecture

### Communication Pattern
- **Local servers**: Direct PHP process execution
- **Remote servers**: SSH with ControlMaster connection pooling for persistent connections

### Key Services

- **SshService** (`app/Services/SshService.php`): Handles SSH connections with ControlMaster pooling. Control sockets stored in `/tmp/launchpad-ssh/` to avoid macOS path length limits.

- **LaunchpadService** (`app/Services/LaunchpadService.php`): Wraps launchpad CLI commands. Searches multiple paths for the binary (`$HOME/projects/launchpad/launchpad`, `$HOME/.local/bin/launchpad`, etc.).

- **CliUpdateService** (`app/Services/CliUpdateService.php`): Manages the local CLI installation at `~/.local/bin/launchpad`.

- **DnsResolverService** (`app/Services/DnsResolverService.php`): Manages macOS DNS resolver files in `/etc/resolver/`. Uses `expect` to spawn sudo with a PTY, enabling Touch ID authentication via `pam_tid.so`. Key methods:
  - `updateResolver(Server, tld)`: Creates/updates `/etc/resolver/{tld}` pointing to the server's DNS
  - `removeResolver(tld)`: Removes a resolver file when no longer needed
  - `getManagedResolvers()`: Lists all resolver files managed by Launchpad

- **ProvisioningService** (`app/Services/ProvisioningService.php`): Provisions new servers with the complete Launchpad stack. Handles 14 steps:
  1. Clear old SSH host keys (prevents conflicts when server is reset)
  2. Test root SSH connection
  3. Create `launchpad` user
  4. Setup SSH key for launchpad user
  5. Configure passwordless sudo
  6. Secure SSH (disable password auth, disable root login)
  7. Test launchpad user connection
  8. Install Docker
  9. Configure DNS (disable systemd-resolved, set to 1.1.1.1)
  10. Install PHP via php.new (herd-lite)
  11. Install launchpad CLI from GitHub releases
  12. Create directory structure (`~/projects`)
  13. Initialize launchpad stack
  14. Start launchpad services

### Models

- **Server**: Represents a local or remote machine with launchpad installed
  - Fields: name, host, user, port, is_local, is_default, metadata, last_connected_at
  - Provisioning fields: status, provisioning_log, provisioning_error, provisioning_step, provisioning_total_steps
  - Status values: `provisioning`, `active`, `error`

- **Setting**: Key-value store for app settings (editor preference, SSH keys, etc.)

- **SshKey**: Manages SSH keys for server provisioning

### External Integrations

- **Editor Support**: Opens projects via SSH Remote extension
  - URL format: `{editor}://vscode-remote/ssh-remote+user@host/path?windowId=_blank`
  - Supported editors: Cursor, VS Code, VS Code Insiders, Windsurf, Antigravity, Zed

- **Browser**: Uses `Shell::openExternal()` via `/open-external` route to open URLs in system browser

## Database

### Important: NativePHP uses a separate database

NativePHP copies the database when the app starts. There are two database files:
- `database/database.sqlite` - Used by artisan commands
- `database/nativephp.sqlite` - Used by the running NativePHP app

**When running migrations**, you need to restart the NativePHP app for changes to take effect. If data seems missing in the app, check if both databases are in sync.

## Routes

### Server Management
- `GET /servers` - List all servers
- `GET /servers/{server}` - Show server (or provisioning progress if status is `provisioning`)
- `POST /servers/{server}/test-connection` - Test SSH connection
- `GET /servers/{server}/status` - Get launchpad status
- `POST /servers/{server}/start|stop|restart` - Control launchpad services
- `POST /servers/{server}/php` - Change PHP version for a site

### Provisioning
- `GET /provision` - Show provisioning form
- `POST /provision` - Create server and redirect to provisioning
- `POST /provision/{server}/run` - Start provisioning (called via AJAX)
- `GET /provision/{server}/status` - Poll provisioning status

### Worktrees
- `GET /servers/{server}/worktrees` - List all worktrees (auto-detected from git)
- `POST /servers/{server}/worktrees/unlink` - Remove worktree subdomain routing
- `POST /servers/{server}/worktrees/refresh` - Re-scan for new worktrees

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
- Use `sg docker -c "command"` to run Docker commands in new SSH sessions (picks up group membership)

### Provisioning Issues

- **SSH host key conflicts**: Provisioning clears old host keys before connecting
- **Docker network not created**: CLI has a bug where `launchpad init` doesn't persist the network. Provisioning creates it manually with `docker network create launchpad`
- **PHP installation**: Uses php.new which requires `TERM=xterm` to be set
- **systemd-resolved conflict**: Disabled during provisioning as it uses port 53 which launchpad DNS needs

### DNS Resolver and TLD Changes

When a TLD is changed in environment settings, three things happen automatically:

1. **Mac DNS Resolver**: `DnsResolverService` creates/updates `/etc/resolver/{tld}` pointing to the server's DNS (127.0.0.1 for local, server IP for remote)
2. **Remote DNS Container**: `LaunchpadService::rebuildDns()` rebuilds the dnsmasq container with correct TLD and HOST_IP environment variables
3. **Caddy Config Regeneration**: Launchpad is restarted to regenerate Caddy configuration with new domain names

When an environment is deleted, the resolver file is cleaned up (if no other servers use that TLD).

### Git Worktree Support

The app automatically detects git worktrees created by vibekanban (or manually) and makes them available as subdomains.

**How it works:**
1. Worktrees are stored at `/var/tmp/vibe-kanban/worktrees/{task-id}/{project-name}/`
2. Branches follow the pattern `vk/{task-id}` (e.g., `vk/0d16-update-homepage`)
3. Detection runs via `git worktree list --porcelain` in each site directory
4. Auto-linking creates Caddy routes for each worktree subdomain
5. Subdomain format: `{worktree-name}.{site-name}.{tld}` (e.g., `0d16-update-homepage.platform11-2026.ccc`)

**CLI Commands (launchpad-cli):**
- `launchpad worktrees [site] --json` - List all worktrees
- `launchpad worktree:unlink <site> <name> --json` - Remove worktree routing
- `launchpad worktree:refresh --json` - Re-scan and auto-link new worktrees

**Storage:**
- Linked worktrees stored in `~/.config/launchpad/worktrees.json`
- Caddy config automatically regenerated to include worktree subdomains
- PHP containers mount `/var/tmp/vibe-kanban/worktrees` at `/worktrees`

**UI:**
- Sites with worktrees show a badge with count
- Click the arrow to expand and see worktree subdomains
- Each worktree row has Open, Editor, and Unlink buttons

### Touch ID for sudo

The app uses `expect` to spawn sudo commands in a pseudo-terminal (PTY), which enables Touch ID authentication. This requires `/etc/pam.d/sudo_local` to exist with:

```
auth sufficient pam_tid.so
```

Create it with: `sudo sh -c 'echo "auth sufficient pam_tid.so" > /etc/pam.d/sudo_local'`

The expect script approach is necessary because:
- NativePHP runs in a non-TTY context (no terminal)
- `sudo -S` (stdin password) doesn't trigger Touch ID
- `osascript` with administrator privileges shows a password dialog, not Touch ID
- Only a proper PTY (created by `expect`) triggers `pam_tid.so` correctly

## Development

```bash
# Install dependencies
composer install
npm install

# Run the app in development
php artisan native:serve

# Build for production
php artisan native:build

# Run migrations (affects database.sqlite, restart app for nativephp.sqlite)
php artisan migrate
```

## Related Projects

- **launchpad-cli** (`github.com/nckrtl/launchpad-cli`): The command-line tool this app controls
  - Releases available at: `https://github.com/nckrtl/launchpad-cli/releases`
  - Install locally: `curl -L -o ~/.local/bin/launchpad https://github.com/nckrtl/launchpad-cli/releases/latest/download/launchpad.phar && chmod +x ~/.local/bin/launchpad`

## Known Issues

- **CLI Docker network bug**: The `launchpad init` command doesn't persist the Docker network. Workaround is to manually create it: `docker network create launchpad`
- **NativePHP database sync**: Migrations only affect `database.sqlite`. The NativePHP app uses `nativephp.sqlite` which is copied on app start. Restart the app after migrations.
