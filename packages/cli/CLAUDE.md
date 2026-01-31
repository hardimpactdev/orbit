# AI Agent Development Guide

This project uses **bd** (beads) for issue tracking. Run `bd onboard` to get started.

## Hierarchical Context Files

```
orbit-cli/
├── AGENTS.md              # This file - project overview
├── CLAUDE.md              # Symlink → AGENTS.md
├── app/
│   ├── AGENTS.md          # Backend overview, contexts
│   ├── Actions/
│   │   └── AGENTS.md      # Provision action patterns
│   ├── Commands/
│   │   └── AGENTS.md      # CLI command patterns
│   ├── Data/
│   │   └── AGENTS.md      # DTO patterns
│   └── Services/
│       └── AGENTS.md      # Service & platform patterns
└── tests/
    └── AGENTS.md          # Testing patterns
```

## Quick Reference: Commands

### Development

```bash
./vendor/bin/pest                              # Run tests
./vendor/bin/rector --dry-run                  # Check code transformations
./vendor/bin/pint --test                       # Check code style
./vendor/bin/phpstan analyse --memory-limit=512M  # Static analysis
```

### Issue Tracking (beads)

```bash
bd ready                                       # Find available work
bd show <id>                                   # View issue details
bd update <id> --status in_progress            # Claim work
bd close <id>                                  # Complete work
bd sync                                        # Sync with git
```

### Release Workflow

```bash
git tag v0.x.y                                 # Create version tag
git push origin v0.x.y                         # Push tag (triggers build)
orbit upgrade                                  # Update local installation
```

**Note:** Use `v` prefix for git tags (convention), but omit it in composer.json versions.

## Technology Stack

| Layer | Technology |
|-------|------------|
| Framework | Laravel Zero (CLI) |
| Testing | Pest PHP |
| Static Analysis | PHPStan |
| Code Style | Laravel Pint |
| Refactoring | Rector |
| Containers | Docker Compose (dns, reverb, postgres) |
| Web Server | Caddy |
| PHP Runtime | PHP-FPM (8.3, 8.4, 8.5) |
| Platforms | Linux, macOS |

## Database Configuration

Development and production use **separate databases** to avoid conflicts:

| Instance | Database | Config |
|----------|----------|--------|
| Dev CLI (`~/projects/orbit-cli`) | `database-dev.sqlite` | `.env` file |
| Installed CLI (`~/.local/bin/orbit`) | `database.sqlite` | Default |

**Configuration:**
- `config/database.php` reads `DB_DATABASE` env var (falls back to `~/.config/orbit/database.sqlite`)
- Dev CLI uses `.env` with `DB_DATABASE=/home/nckrtl/.config/orbit/database-dev.sqlite`
- `.env` is gitignored (create locally for development)

**Why separate databases?**
Sites created via dev CLI won't appear in production, and vice versa. This prevents confusion during development.

## Project Architecture

**Orbit CLI** - Local PHP dev environment with host PHP-FPM/Caddy and Docker-backed services.

### Directory Structure

```
app/
├── Actions/Install/     # Orbit installation steps
├── Commands/            # Artisan CLI commands
├── Concerns/            # Shared traits
├── Data/                # DTOs and value objects
├── Enums/               # PHP enums
├── Mcp/                 # Model Context Protocol
├── Providers/           # Service providers
└── Services/            # Business logic
    └── Platform/        # OS-specific adapters
```

**Note:** Site provisioning logic lives in `orbit-core`, but the CLI runs it synchronously with real-time output.

### Key Patterns

| Pattern | Location | Purpose |
|---------|----------|---------|
| Commands | `app/Commands/` | User-facing CLI interface |
| Actions | `app/Actions/Install/` | Orbit installation steps |
| Services | `app/Services/` | Shared business logic |
| Platform Adapters | `app/Services/Platform/` | Cross-platform abstraction |
| DTOs | `app/Data/` | Type-safe data containers |
| ReverbBroadcaster | `app/Services/` | WebSocket broadcasting to Reverb |
| ProvisionLogger | `app/Services/` | CLI's provisioning logger (implements `ProvisionLoggerContract`) |
| DeletionLogger | `app/Services/` | CLI's deletion logger (implements `ProvisionLoggerContract`) |

### Site Provisioning Architecture

Site provisioning uses `orbit-core`'s `ProvisionPipeline` but runs synchronously in the CLI for real-time output:

```
CLI site:create command
    ↓
Creates Site record in database (status: queued)
    ↓
Runs ProvisionPipeline synchronously (real-time console output)
    ↓
ProvisionLogger broadcasts to Reverb → Web UI updates
    ↓
Site marked ready on completion
```

The CLI provides its own `ProvisionLogger` implementation that:
1. Outputs to console for real-time feedback
2. Broadcasts to Reverb via Pusher SDK for web UI updates
3. Implements `ProvisionLoggerContract` interface from orbit-core

### Site Deletion Architecture

Site deletion uses `orbit-core`'s `DeletionPipeline` (also synchronously):

```
CLI site:delete command
    ↓
Finds Site record in database (if exists)
    ↓
Runs DeletionPipeline synchronously (real-time console output)
    ↓
DeletionLogger broadcasts to Reverb → Web UI updates
    ↓
Site record deleted from database
```

The CLI provides its own `DeletionLogger` implementation that:
1. Outputs to console for real-time feedback
2. Broadcasts to Reverb via Pusher SDK for web UI updates
3. Implements `ProvisionLoggerContract` interface from orbit-core

**Key flags:**
- `--force` - Skip confirmation prompt
- `--keep-db` - Don't drop the PostgreSQL database
- `--delete-repo` - Delete GitHub repository (passed to Sequence MCP)

## Code Style Guidelines

### PHP Conventions

```php
<?php

declare(strict_types=1);

namespace App\...;

final class MyClass              // Final by default
final readonly class MyAction    // Immutable actions/DTOs
```

### Architecture Rules

- **Commands** orchestrate actions and services
- **Actions** are single-purpose, return `StepResult`
- **Services** handle shared business logic
- All code must work on **both Linux and macOS**
- Use `PlatformAdapter` for OS-specific operations

### Package Dependencies

orbit-cli depends on **orbit-core** for shared business logic (Models, Services, Jobs, DTOs).

**Note**: orbit-core was split from a monolithic package to resolve Laravel Zero PHAR build conflicts. It now contains only PHP business logic, no UI components.

```php
// Import models from orbit-core
use HardImpact\Orbit\Core\Models\Project;
use HardImpact\Orbit\Core\Models\Environment;

// Import services
use HardImpact\Orbit\Core\Services\Provision\ProvisionPipeline;
use HardImpact\Orbit\Core\Services\Deletion\DeletionPipeline;

// Import data objects
use HardImpact\Orbit\Core\Data\ProvisionContext;
use HardImpact\Orbit\Core\Data\DeletionContext;
use HardImpact\Orbit\Core\Data\StepResult;

// Import contracts
use HardImpact\Orbit\Core\Contracts\ProvisionLoggerContract;
```

**Important**: Always use `HardImpact\Orbit\Core\` namespace, never `HardImpact\Orbit\`

## Web Dashboard Integration

The CLI integrates with `orbit-web` (bundled dashboard):

- Bundle: `stubs/orbit-web-bundle.tar.gz`
- Install: `orbit web:install`
- Location: `~/.config/orbit/web/`

### How orbit-web Calls the CLI

The web dashboard calls the CLI directly via `ORBIT_CLI_PATH` env var:

```
orbit-web.ccc/api/workspaces
    → CommandService::executeLocalCommand('workspaces --json')
    → Process::run('/path/to/orbit workspaces --json')
```

The `web:install` command generates `.env` with `ORBIT_CLI_PATH=~/.local/bin/orbit`.

For development, orbit-web uses `ORBIT_CLI_PATH=/home/user/projects/orbit-cli/orbit` to call the dev CLI directly (changes take effect immediately without rebuilding).

### Web Context Rules

When called from orbit-web:
- Use `--json` flag for clean JSON output
- **Never restart PHP-FPM** (causes 502)
- Use `CI=1` for package manager commands

## Quality Gates

**IMPORTANT:** Every fix must have a test.

Run before every commit:

```bash
./vendor/bin/rector --dry-run
./vendor/bin/pint --test
./vendor/bin/phpstan analyse --memory-limit=512M
./vendor/bin/pest
```

## Known Gotchas (Summary)

> Detailed explanations in subdirectory AGENTS.md files

| Gotcha | See File |
|--------|----------|
| Path repositories break CI | Root (below) |
| Bun hangs in background | `app/Actions/AGENTS.md` |
| Platform-specific commands | `app/Services/AGENTS.md` |
| PHP-FPM restart kills web requests | `app/Services/AGENTS.md` |
| JSON output must be clean | `app/Commands/AGENTS.md` |

### NEVER Use Path Repositories

Path repositories in `composer.json` break CI/CD:

```json
// NEVER DO THIS
"repositories": [{"type": "path", "url": "../orbit-core"}]
```

**Solution:** Always use Packagist: `"hardimpactdev/orbit-core": "@dev"`

**If CI is broken:**
1. Remove repositories section from composer.json
2. Delete composer.lock
3. Run `composer install`
4. Commit both files

## Host Services

**IMPORTANT:** Caddy runs on the host via systemd, NOT in Docker.

### Caddy Web Server (Linux)

```bash
sudo systemctl status caddy
sudo systemctl reload caddy      # Reload config after changes
sudo journalctl -u caddy -f      # View logs
orbit caddy:reload               # Regenerate Caddyfile AND reload Caddy
```

Config location: `~/.config/orbit/caddy/Caddyfile` (imported by `/etc/caddy/Caddyfile`)

**Note:** The `caddy:reload` command is the preferred way to update Caddy config after adding new sites. It regenerates the Caddyfile from all detected sites and reloads Caddy in one step. This is called automatically by `CreateSiteJob` during site provisioning.

### Horizon Queue Worker (Linux)

```bash
sudo systemctl status orbit-horizon
sudo systemctl restart orbit-horizon
sudo journalctl -u orbit-horizon -f
```

### Reverb WebSocket

- Docker container: `orbit-reverb`
- Port: `8080`
- Caddy proxies `reverb.{tld}` to `localhost:8080`

## Session Completion (Landing the Plane)

**When ending a session**, complete ALL steps:

1. **File issues** for remaining work
2. **Run quality gates** (if code changed)
3. **Update issue status** - close finished work
4. **PUSH TO REMOTE** - MANDATORY:
   ```bash
   git pull --rebase
   bd sync
   git push
   git status  # MUST show "up to date with origin"
   ```
5. **Verify** - All changes committed AND pushed

**CRITICAL:** Work is NOT complete until `git push` succeeds.
