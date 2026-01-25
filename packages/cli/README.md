# Orbit CLI

A local PHP development environment powered by Docker. Orbit provides a simple, fast way to run PHP applications locally with automatic HTTPS, multiple PHP versions, and essential services.

## Features

- **Multiple PHP Versions** - Run PHP 8.3, 8.4, and 8.5 side-by-side via PHP-FPM
- **Automatic HTTPS** - Local SSL certificates via Caddy
- **Essential Services** - PostgreSQL, Redis, and Mailpit included
- **Simple DNS** - Automatic `.test` domain resolution
- **Per-project PHP** - Configure PHP version per project

## Installation

Download the latest release:

```bash
curl -L -o ~/.local/bin/orbit https://github.com/hardimpactdev/orbit-cli/releases/latest/download/orbit.phar
chmod +x ~/.local/bin/orbit
```

Make sure `~/.local/bin` is in your PATH.

## Quick Start

1. Initialize Orbit (first time only):
   ```bash
   orbit init
   ```

2. Start the services:
   ```bash
   orbit start
   ```

3. Trust the local CA certificate (for HTTPS):
   ```bash
   orbit trust
   ```

4. Link your project (creates a symlink in ~/projects):
   ```bash
   ln -s /path/to/your/project ~/projects/myapp
   ```

5. Visit https://myapp.test in your browser!

## Companion Web Dashboard

Orbit includes a unified web dashboard (powered by `orbit-web`) that provides a visual interface for managing your local development environment.

### Installation

The web app is bundled with the CLI and can be installed using:

```bash
orbit web:install
```

Once installed, it will be available at `https://orbit.test` (or your configured TLD).

### Features

- **Visual Project Management** - Create, delete, and monitor projects.
- **Service Control** - Start, stop, and configure services (PostgreSQL, Redis, etc.).
- **PHP Configuration** - Visual editor for PHP settings and versions.
- **Log Viewer** - Tail logs for all services and projects.
- **Real-time Updates** - Powered by Reverb for instant status updates.

## Commands

| Command | Description |
|---------|-------------|
| `orbit init` | First-time setup: creates config, pulls images, sets up DNS |
| `orbit start` | Start all Orbit services |
| `orbit stop` | Stop all Orbit services |
| `orbit restart` | Restart all Orbit services |
| `orbit status` | Show status and running services |
| `orbit projects` | List all projects with their PHP versions |
| `orbit php <project> <version>` | Set PHP version for a project (8.3, 8.4, 8.5) |
| `orbit logs` | Tail container logs |
| `orbit trust` | Install Caddy root CA for local HTTPS |
| `orbit caddy:reload` | Regenerate Caddyfile and reload Caddy |
| `orbit upgrade` | Upgrade to the latest version |
| `orbit rebuild` | Rebuild PHP images with Redis and other extensions |
| `orbit upgrade --check` | Check for available updates |
| `orbit worktrees` | List all git worktrees |
| `orbit worktree:refresh` | Auto-detect and link new worktrees |
| `orbit worktree:unlink <project> <wt>` | Remove worktree routing |
| `orbit project:create <name>` | Create project (runs synchronously with real-time output) |
| `orbit project:delete <slug>` | Delete project with cascade |
| `orbit project:list` | List all projects in scan paths |
| `orbit project:scan` | Scan for git repositories |
| `orbit project:update [path]` | Update project (git pull + deps) |
| `orbit reverb:setup` | Setup Reverb WebSocket service |
| `orbit mcp:start orbit` | Start MCP server for AI tool integration |

## MCP (Model Context Protocol)

Orbit provides an MCP server for AI tool integration, enabling AI assistants like Claude Code to interact with your local development environment.

### Setup for Claude Code

Add to your MCP configuration:

```json
{
  "mcpServers": {
    "orbit": {
      "command": "orbit",
      "args": ["mcp:start", "orbit"]
    }
  }
}
```

### Available Tools

| Tool | Description |
|------|-------------|
| `orbit_status` | Get service status and running containers |
| `orbit_start` | Start all Docker services |
| `orbit_stop` | Stop all Docker services |
| `orbit_restart` | Restart all Docker services |
| `orbit_projects` | List all registered projects |
| `orbit_php` | Get/set PHP version for a project |
| `orbit_project_create` | Create a new project |
| `orbit_project_delete` | Delete a project |
| `orbit_logs` | Get container logs |
| `orbit_worktrees` | List git worktrees |

### Resources

- `orbit://config` - Current Orbit configuration
- `orbit://projects` - All registered projects
- `orbit://infrastructure` - Service status and health
- `orbit://env-template/{type}` - Environment variable templates

### Prompts

- `configure-laravel-env` - Guide for Laravel .env configuration
- `setup-horizon` - Laravel Horizon setup guide

## Services & Ports

## Service Management

Orbit provides a declarative service management system. Services are defined as templates and can be enabled, configured, or disabled.

### Service Commands

| Command | Description |
|---------|-------------|
| `orbit service:list` | List configured services with status |
| `orbit service:list --available` | Show available service templates |
| `orbit service:enable <name>` | Enable a service with defaults |
| `orbit service:disable <name>` | Disable a service |
| `orbit service:configure <name> --set key=value` | Update service configuration |
| `orbit service:info <name>` | Show detailed service information |

### Available Services

| Service | Category | Default Port | Description |
|---------|----------|--------------|-------------|
| postgres | database | 5432 | PostgreSQL database |
| mysql | database | 3306 | MySQL database |
| redis | cache | 6379 | Redis cache/session store |
| mailpit | mail | 1025/8025 | Email testing (SMTP/Web UI) |
| meilisearch | search | 7700 | Full-text search engine |
| reverb | websocket | 8080 | Laravel Reverb WebSocket |
| dns | core | 53 | Local DNS resolver |

### Examples

```bash
# List current services
orbit service:list

# Enable MySQL database
orbit service:enable mysql

# Change PostgreSQL to version 16
orbit service:configure postgres --set version=16

# Change Redis max memory
orbit service:configure redis --set maxmemory=512mb

# Show service details
orbit service:info postgres
```

### Configuration

Services are configured in `~/.config/orbit/services.yaml`. Each service can specify:

- `enabled` - Whether the service is active
- `version` - Service version (from available versions)
- `port` - Port mapping
- `environment` - Environment variables
- Additional service-specific options

Changes to services.yaml automatically regenerate `docker-compose.yaml`.

| Service | Port(s) | Description |
|---------|---------|-------------|
| Caddy | 80, 443 | Web server with automatic HTTPS |
| PHP 8.3 | - | PHP-FPM pool |
| PHP 8.4 | - | PHP-FPM pool |
| PHP 8.5 | - | PHP-FPM pool |
| PostgreSQL | 5432 | Database server |
| Redis | 6379 | Cache server |
| Mailpit | 1025, 8025 | Mail catcher (SMTP: 1025, Web UI: 8025) |
| DNS | 53 | Local DNS resolver for .test domains |

## Configuration

Orbit stores its configuration at `~/.config/orbit/config.json`. You can customize:

### Paths

Add directories to scan for projects:

```json
{
  "paths": ["~/projects", "~/clients"]
}
```

### Custom Project Paths

Override the auto-detected path for any project. Useful for nested projects:

```json
{
  "projects": {
    "myproject": {
      "path": "~/projects/monorepo/apps/myproject"
    }
  }
}
```

### Default PHP Version

```json
{
  "default_php_version": "8.4"
}
```

### TLD

Change the top-level domain (default: `test`):

```json
{
  "tld": "local"
}
```


## Git Worktree Support

Orbit automatically detects git worktrees and creates subdomains:

```bash
# Create a worktree for your project
cd ~/projects/myapp
git worktree add ../myapp-feature-auth feature/auth

# Refresh to pick up the new worktree
orbit worktree:refresh

# Access via subdomain
# https://feature-auth.myapp.test
```

Worktrees are served from `<worktree-name>.<project>.test`.

## Requirements

### Required

| Dependency | macOS | Linux |
|------------|-------|-------|
| PHP >= 8.2 | `php.new` or Homebrew | `php.new` or apt |
| Docker | OrbStack (recommended) or Docker Desktop | docker.io |
| Composer | Homebrew | apt |
| Supervisor | Homebrew | apt (for Horizon queue worker) |

### Optional

| Dependency | Purpose |
|------------|---------|
| dig | DNS debugging (built-in on macOS, `apt install dnsutils` on Linux) |

**Note:** The `orbit init` command will check for and automatically install missing prerequisites.

## Platform Support

Orbit CLI supports both **Linux** and **macOS**. Platform-specific functionality is handled automatically through `PlatformService`.

## Development

### Setup

```bash
git clone https://github.com/hardimpactdev/orbit-cli.git
cd orbit-cli
composer install

# Enable git hooks
git config core.hooksPath .githooks

# For local development, symlink instead of downloading PHAR
ln -s $(pwd)/orbit ~/.local/bin/orbit
# Ensure ~/.local/bin is in your PATH
```

### Quality Tools

| Tool | Command | Description |
|------|---------|-------------|
| PHPStan | `./vendor/bin/phpstan analyse` | Static analysis (level 5) |
| Rector | `./vendor/bin/rector` | Automated refactoring |
| Pint | `./vendor/bin/pint` | Code formatting |
| Pest | `./vendor/bin/pest` | Test suite |

### Running Checks

```bash
# Run all checks (same as pre-commit hook)
./vendor/bin/rector --dry-run
./vendor/bin/pint --test
./vendor/bin/phpstan analyse --memory-limit=512M
./vendor/bin/pest
```

### Pre-commit Hook

The project includes a pre-commit hook that runs all quality checks before each commit. Enable it with:

```bash
git config core.hooksPath .githooks
```

### CI

GitHub Actions runs the full quality check suite on every push and PR to main.

### Releasing

After fixes are verified (all tests pass):

```bash
# 1. Run all quality checks
./vendor/bin/rector --dry-run && ./vendor/bin/pint --test && ./vendor/bin/phpstan analyse --memory-limit=512M && ./vendor/bin/pest

# 2. Create and push tag (GitHub Actions builds PHAR)
git tag v0.x.y
git push origin v0.x.y

# 3. Update local installation
orbit upgrade  # For PHAR installs
# Or just `git pull` for symlink dev setup
```

## License

MIT License
