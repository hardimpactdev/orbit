# Orbit Desktop

A NativePHP/Electron desktop application for managing local and remote orbit CLI installations.

## Important: Working with Environments

**Projects/sites run on remote servers, not locally.** When referencing a URL like `https://orbit-cli.ccc/` or `https://platform11-2026.ccc/`:

1. **Look up the environment** - Check which server hosts this site (TLD indicates the server)
2. **SSH into the server** - Use `ssh user@IP` to access the server
3. **Fix issues on the remote server** - Caddy configs, project files, and orbit CLI are there

**Current environments:**
| Environment | SSH Command | TLD | Notes |
|-------------|-------------|-----|-------|
| Ubuntu VPS | `ssh launchpad@ai` | `.ccc` | Main dev server, CLI source at `~/projects/orbit-cli/` |
| Local | N/A (localhost) | `.test` | Local machine |

**Key paths on remote servers:**
- Projects: `~/projects/`
- **Orbit CLI source code**: `~/projects/orbit-cli/` - THIS IS WHERE TO MAKE CLI CHANGES
- Launchpad config: `~/.config/orbit/`
- Caddy config: `~/.config/orbit/caddy/Caddyfile`
- PHP-FPM sockets: `~/.config/orbit/php/php{version}.sock` (e.g., `php85.sock`, `php84.sock`)
- PHP-FPM pool configs: `~/.config/orbit/php/php{version}-fpm.conf`
- Worktrees config: `~/.config/orbit/worktrees.json`
- Horizon service (Linux): `/etc/systemd/system/launchpad-horizon.service`

**Important:** The orbit CLI source code lives on the remote server at `ssh launchpad@ai:~/projects/orbit-cli/`. Any changes to CLI behavior (site scanning, Caddy generation, worktrees, etc.) must be made there via SSH. See the "Orbit CLI Development" section below for the full workflow.

## Project Overview

This is a Laravel 12 application wrapped in NativePHP/Electron that provides a GUI for the orbit CLI tool. It can manage:
- Local launchpad installations (on the same machine)
- Remote launchpad installations (via SSH)
- Provisioning new servers from scratch

### Platform Support

**This is a macOS-only application.** Key macOS-specific dependencies:
- DNS resolver management via `/etc/resolver/` files
- Touch ID authentication for sudo via `pam_tid.so`
- `expect` for PTY spawning to enable Touch ID
- Assumes `dig` command is available (ships with macOS)

The remote environments being managed can run any Linux distribution (Ubuntu recommended), but the desktop app itself only runs on macOS.

## Architecture

### PHP-FPM Architecture (Current)

The orbit stack uses **PHP-FPM on the host** (not containerized) with **Caddy** as the web server:

```
┌─────────────────────────────────────────────────────────────────┐
│                        HOST MACHINE                              │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│   ┌─────────────────┐     ┌─────────────────────────────────┐   │
│   │  Caddy (host)   │────▶│ PHP-FPM Pools (host)            │   │
│   │  Port 80/443    │     │  ~/.config/orbit/php/       │   │
│   │  Single binary  │     │  ├── php85.sock                 │   │
│   └─────────────────┘     │  └── php84.sock                 │   │
│                           │                                 │   │
│                           └─────────────────────────────────┘   │
│                                                                  │
│   ┌─────────────────┐     ┌─────────────────┐                   │
│   │ Horizon (host)  │     │ Orbit CLI   │                   │
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

**Benefits of PHP-FPM architecture:**
- Single Caddy instance on host (no dual-Caddy complexity)
- PHP-FPM has direct access to CLI, Bun, git, composer
- Horizon runs natively with full host access
- Simpler debugging and log access

**Services on host:**
- **PHP-FPM**: Multiple pools (8.4, 8.5) with Unix sockets
- **Caddy**: Web server with automatic HTTPS (systemd on Linux, brew services on macOS)
- **Horizon**: Queue worker as systemd (Linux) or launchd (macOS) service

**Services in Docker:**
- PostgreSQL, Redis, Mailpit, Reverb, dnsmasq

### Communication Pattern
- **Local environments**: NativePHP backend → Direct PHP process execution
- **Remote environments**: Vue frontend → Direct HTTP to remote launchpad web app API

### Direct API Calls (Important Performance Optimization)

**Problem:** NativePHP uses `php artisan serve` which is single-threaded. When the Vue frontend makes multiple fetch calls through the NativePHP backend, requests are serialized and block each other. This caused slow page navigation (e.g., Dashboard → Projects) because Inertia navigation requests had to wait behind data-fetching API calls.

**Solution:** For remote environments, the Vue frontend calls the remote launchpad web app API directly at `https://orbit.{tld}/api/...`, bypassing the NativePHP backend entirely.

```
BEFORE (slow):
Vue → fetch('/api/environments/1/status') → NativePHP (single-threaded) → SSH → CLI

AFTER (fast):
Vue → fetch('https://launchpad.ccc/api/status') → Direct to remote server
```

**Implementation:**
1. `EnvironmentController` passes `remoteApiUrl` prop to Vue pages (e.g., `https://launchpad.ccc/api`)
2. Vue pages use a `getApiUrl(path)` helper that returns the remote URL when available
3. For local environments or when TLD isn't set, falls back to NativePHP backend
4. The TLD is cached in the `environments.tld` database column

**What uses direct API calls:**
- Dashboard/Show: status, sites, config, worktrees, restart, PHP version, worktree unlink
- Projects page: list, delete, rebuild, PHP version change, provision status
- Services page: status, start/stop/restart (all and individual), logs
- Workspaces: list, delete, workspace details, add/remove projects, linked packages

**What still uses NativePHP backend:**
- SSH connection testing (testConnection) - requires SSH
- Environment provisioning - requires SSH
- Config saving with TLD changes - requires local DNS resolver updates
- CLI installation and updates - requires SSH
- DNS resolver management - requires local sudo
- Opening external URLs/editors - requires Shell::openExternal
- Project creation form - uses Inertia for validation, redirects, and orchestrator integration

**Remote API location:** `~/.config/orbit/web/` on the remote server (installed via `launchpad web:install`)

### Key Services

- **SshService** (`app/Services/SshService.php`): Handles SSH connections with ControlMaster pooling. Control sockets stored in `/tmp/launchpad-ssh/` to avoid macOS path length limits.

- **LaunchpadService** (`app/Services/LaunchpadService.php`): Wraps orbit CLI commands. Searches multiple paths for the binary (`$HOME/projects/launchpad/launchpad`, `$HOME/.local/bin/orbit`, etc.).

- **CliUpdateService** (`app/Services/CliUpdateService.php`): Manages the local CLI installation at `~/.local/bin/orbit`.

- **DnsResolverService** (`app/Services/DnsResolverService.php`): Manages macOS DNS resolver files in `/etc/resolver/`. Uses `expect` to spawn sudo with a PTY, enabling Touch ID authentication via `pam_tid.so`. Key methods:
  - `updateResolver(Environment, tld)`: Creates/updates `/etc/resolver/{tld}` pointing to the environment's DNS
  - `removeResolver(tld)`: Removes a resolver file when no longer needed
  - `getManagedResolvers()`: Lists all resolver files managed by Launchpad

- **ProvisioningService** (`app/Services/ProvisioningService.php`): Provisions new environments with the complete Orbit stack. Handles these steps:
  1. Clear old SSH host keys (prevents conflicts when environment is reset)
  2. Test root SSH connection
  3. Create `launchpad` user
  4. Setup SSH key for launchpad user
  5. Configure passwordless sudo
  6. Secure SSH (disable password auth, disable root login)
  7. Test launchpad user connection
  8. Install Docker
  9. Configure DNS (disable systemd-resolved, set to 1.1.1.1)
  10. Add Ondřej PPA for PHP (Linux)
  11. Install PHP-FPM versions (8.4, 8.5)
  12. Configure PHP-FPM pools with Unix sockets
  13. Install Caddy web server
  14. Install orbit CLI from GitHub releases
  15. Create directory structure (`~/projects`)
  16. Initialize orbit stack
  17. Configure Horizon as systemd service
  18. Start orbit services

### Models

- **Environment**: Represents a local or remote machine with launchpad installed
  - Fields: name, host, user, port, is_local, is_default, orchestrator_url, metadata, last_connected_at
  - Provisioning fields: status, provisioning_log, provisioning_error, provisioning_step, provisioning_total_steps
  - Status values: `provisioning`, `active`, `error`

- **Project**: Represents a project tracked across environments
  - Fields: name, github_url

- **Deployment**: Links a project to an environment
  - Fields: project_id, environment_id, status, local_path, site_url, orchestrator_id

- **Setting**: Key-value store for app settings (editor preference, SSH keys, etc.)

- **SshKey**: Manages SSH keys for environment provisioning

### Async Project Provisioning

Project creation uses an async workflow via the bundled web app API:

1. **Desktop** submits create project form → Vue calls `POST https://orbit.{tld}/api/projects`
2. **Web App** (runs via PHP-FPM) → `ProjectController` dispatches `CreateProjectJob` to Redis queue
3. **Horizon** (runs on HOST as systemd/launchd service) → Picks up job, calls CLI `launchpad provision`
4. **CLI** handles: GitHub repo creation, git clone, composer install, bun install, migrations, Caddy reload
5. **Job** broadcasts status via Reverb (if reachable from host)
6. **Desktop** refreshes project list to see new project

**Architecture note:** The web app runs via PHP-FPM on the host and dispatches jobs to Horizon which also runs on the HOST as a system service. This is critical because:
- CLI needs access to host filesystem (`~/projects/`)
- Bun/Node need proper PATH on the host
- PHP-FPM processes run as the `launchpad` user with full host access

**Key files (remote server `~/projects/orbit-cli/web/`):**
- `app/Http/Controllers/Api/ProjectController.php` - Dispatches CreateProjectJob
- `app/Jobs/CreateProjectJob.php` - Runs CLI provision command via Horizon
- `config/horizon.php` - Queue worker configuration (timeout: 120s)

**Key files (CLI `~/projects/orbit-cli/`):**
- `app/Commands/ProvisionCommand.php` - Actual provisioning logic

**Status flow:** `provisioning` → `creating_repo` → `cloning` → `setting_up` → `installing_composer` → `installing_npm` → `building` → `finalizing` → `ready`

**Expected timing:** ~20-30 seconds for liftoff-starterkit template

**Testing provisioning:**
Use the `/test-provision` skill for step-by-step debugging, or run the test script:
```bash
bash .claude/scripts/test-provision-flow.sh test-$(date +%s) --cleanup
```

**Common issues:**
- **Job times out**: Check Horizon timeout in `config/horizon.php` (should be 120s)
- **Bun hangs**: CLI now uses `CI=1` and `--no-progress` flags to prevent hanging in non-TTY environments
- **Project not appearing**: Check `~/.config/orbit/web/storage/logs/laravel.log`
- **Reverb broadcast fails**: Web app .env must use `REVERB_HOST=localhost` (not Docker hostname)

### External Integrations

- **Editor Support**: Opens projects via SSH Remote extension
  - URL format: `{editor}://vscode-remote/ssh-remote+user@host/path?windowId=_blank`
  - Supported editors: Cursor, VS Code, VS Code Insiders, Windsurf, Antigravity, Zed

- **Browser**: Uses `Shell::openExternal()` via `/open-external` route to open URLs in system browser

## Database

### Important: NativePHP uses TWO separate databases

NativePHP maintains two separate SQLite databases:

| Database | Connection | Used By | Location |
|----------|------------|---------|----------|
| `database/database.sqlite` | `sqlite` (default) | `php artisan` commands, tests | Project directory |
| `database/nativephp.sqlite` | `nativephp` | Running desktop app (dev mode) | Project directory |

**Running Migrations:**

The `nativephp` database connection is only configured when running inside the NativePHP app context. You cannot use `php artisan migrate --database=nativephp` from the terminal.

```bash
# Standard migration (only affects database/database.sqlite)
php artisan migrate

# For the NativePHP database, you have two options:

# Option 1: Restart the app (NativePHP runs migrations on startup)
# Stop and restart: php artisan native:serve

# Option 2: Run migrations directly on the SQLite file
php artisan migrate --database=sqlite --database-path=database/nativephp.sqlite
```

**If Option 2 doesn't work**, you can create a temporary database connection in `config/database.php`:

```php
'nativephp_dev' => [
    'driver' => 'sqlite',
    'database' => database_path('nativephp.sqlite'),
],
```

Then run: `php artisan migrate --database=nativephp_dev`

**Common Issues:**
- "No such column" or "No such table" errors in the app → The NativePHP database needs migration
- `php artisan migrate --database=nativephp` fails with "connection not configured" → This is expected, use methods above
- Data missing in app but exists in tests → The two databases are out of sync

**When to restart the app:**
- After adding new migrations (NativePHP runs them on startup)
- After changing `.env` configuration
- After modifying NativePHP config files

## Routes

### Environment Management
- `GET /environments` - List all environments
- `GET /environments/{environment}` - Show environment (or provisioning progress if status is `provisioning`)
- `POST /environments/{environment}/test-connection` - Test SSH connection
- `GET /environments/{environment}/status` - Get launchpad status
- `GET /environments/{environment}/projects` - Get projects list from CLI
- `POST /environments/{environment}/start|stop|restart` - Control orbit services
- `POST /environments/{environment}/php` - Change PHP version for a site

### Provisioning
- `GET /provision` - Show provisioning form
- `POST /provision` - Create environment and redirect to provisioning
- `POST /provision/{environment}/run` - Start provisioning (called via AJAX)
- `GET /provision/{environment}/status` - Poll provisioning status

### Worktrees
- `GET /environments/{environment}/worktrees` - List all worktrees (auto-detected from git)
- `POST /environments/{environment}/worktrees/unlink` - Remove worktree subdomain routing
- `POST /environments/{environment}/worktrees/refresh` - Re-scan for new worktrees

### Projects
- `GET /projects` - List all projects
- `GET /projects/create` - Create project form
- `GET /projects/{project}` - Show project with deployments
- `DELETE /projects/{project}` - Delete project
- `GET /projects/scan/{environment}` - Scan environment for existing projects
- `POST /projects/import/{environment}` - Import discovered project

## Common Tasks

### Adding a new orbit CLI command

1. Add method to `LaunchpadService` that calls `executeCommand($environment, 'command --json')`
2. Add controller method in `EnvironmentController`
3. Add route in `routes/web.php` under the environments prefix group
4. Add frontend component in Vue

### SSH Connection Issues

- Control sockets are stored in `/tmp/launchpad-ssh/` with hashed filenames
- PATH is prefixed for non-interactive SSH: `$HOME/.local/bin:$HOME/.bun/bin:/usr/local/bin:/usr/bin:/bin`
- Binary detection checks multiple common installation paths
- Use `sg docker -c "command"` to run Docker commands in new SSH sessions (picks up group membership)

### Provisioning Issues

- **SSH host key conflicts**: Provisioning clears old host keys before connecting
- **Docker network not created**: CLI has a bug where `launchpad init` doesn't persist the network. Provisioning creates it manually with `docker network create launchpad`
- **PHP-FPM pool configuration**: Pool configs are generated at `~/.config/orbit/php/` with Unix sockets
- **systemd-resolved conflict**: Disabled during provisioning as it uses port 53 which launchpad DNS needs
- **Horizon service**: Installed as systemd service on Linux, launchd on macOS

### DNS Resolver and TLD Changes

When a TLD is changed in environment settings, three things happen automatically:

1. **Mac DNS Resolver**: `DnsResolverService` creates/updates `/etc/resolver/{tld}` pointing to the environment's DNS (127.0.0.1 for local, host IP for remote)
2. **Remote DNS Container**: `LaunchpadService::rebuildDns()` rebuilds the dnsmasq container with correct TLD and HOST_IP environment variables
3. **Caddy Config Regeneration**: Launchpad is restarted to regenerate Caddy configuration with new domain names

When an environment is deleted, the resolver file is cleaned up (if no other environments use that TLD).

### Git Worktree Support

The app automatically detects git worktrees created by vibekanban (or manually) and makes them available as subdomains.

**How it works:**
1. Worktrees are stored at `/var/tmp/vibe-kanban/worktrees/{task-id}/{project-name}/`
2. Branches follow the pattern `vk/{task-id}` (e.g., `vk/0d16-update-homepage`)
3. Detection runs via `git worktree list --porcelain` in each site directory
4. Auto-linking creates Caddy routes for each worktree subdomain
5. Subdomain format: `{worktree-name}.{site-name}.{tld}` (e.g., `0d16-update-homepage.platform11-2026.ccc`)

**CLI Commands (orbit-cli):**
- `launchpad worktrees [site] --json` - List all worktrees
- `launchpad worktree:unlink <site> <name> --json` - Remove worktree routing
- `launchpad worktree:refresh --json` - Re-scan and auto-link new worktrees

**Storage:**
- Linked worktrees stored in `~/.config/orbit/worktrees.json`
- Caddy config automatically regenerated to include worktree subdomains
- PHP-FPM has direct access to worktree paths on the host filesystem

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

## UI/Design Guidelines

### Tech Stack
- **Frontend**: Vue 3 + TypeScript + Inertia.js
- **Styling**: Tailwind CSS
- **Icons**: Lucide Vue Next

### Color Palette
- **Background**: `bg-zinc-900` (page), `bg-zinc-800/30` (cards/rows)
- **Borders**: `border-zinc-800` (outer), `border-zinc-700/50` (inner/subtle)
- **Text**: `text-white` (primary), `text-zinc-400` (secondary), `text-zinc-500` (muted)
- **Accent**: `text-lime-400` / `bg-lime-500` (success/primary action)
- **Danger**: `text-red-400` / `bg-red-400/10`
- **Warning**: `text-amber-400` / `bg-amber-400/10`

### Page Layouts

**Dashboard Pages** (Show.vue, Index pages):
- Use card structure with outer border and inner content areas
- Outer card: `border border-zinc-800 rounded-xl px-0.5 pt-4 pb-0.5`
- Section header: `px-4 mb-4` with title and optional action button
- Inner content: `border border-zinc-700/50 rounded-lg overflow-hidden`
- Content rows: `bg-zinc-800/30` with `space-y-px` for 1px gaps

**Form Pages** (Create.vue, Edit.vue):
- Simple layout with horizontal dividers, NO outer card border
- Two-column grid: `grid grid-cols-2 gap-8 py-6`
- Left column: Label + description
- Right column: Input field
- Section dividers: `<hr class="border-zinc-800" />`

### Tables

```vue
<table class="table-catalyst w-full border-separate" style="border-spacing: 0 2px;">
    <thead>
        <tr class="bg-zinc-800/30">
            <th class="px-4 py-3 text-left text-xs font-medium text-zinc-500 uppercase tracking-wider rounded-l-lg">Column</th>
            <!-- middle columns without rounded -->
            <th class="px-4 py-3 text-right text-xs font-medium text-zinc-500 uppercase tracking-wider rounded-r-lg">Actions</th>
        </tr>
    </thead>
    <tbody>
        <tr class="bg-zinc-800/30 hover:bg-zinc-700/30">
            <td class="px-4 py-3 rounded-l-lg">Content</td>
            <!-- middle columns -->
            <td class="px-4 py-3 text-right rounded-r-lg">Actions</td>
        </tr>
    </tbody>
</table>
```

Key table patterns:
- `border-separate` with `border-spacing: 0 2px` for row gaps
- Header AND data rows have same `bg-zinc-800/30` background
- First cell: `rounded-l-lg`, last cell: `rounded-r-lg`
- Header text: `text-xs font-medium text-zinc-500 uppercase tracking-wider`
- Hover state: `hover:bg-zinc-700/30`

### Buttons

```vue
<!-- Primary action (lime) -->
<button class="btn btn-secondary">Action</button>

<!-- Outline/secondary -->
<button class="btn btn-outline">Action</button>

<!-- Plain/text button -->
<button class="btn btn-plain">Cancel</button>

<!-- Small button -->
<button class="btn btn-secondary py-1 px-2 text-xs">Small</button>
```

### Form Inputs

- All inputs inherit base styling from `app.css`
- Use `font-mono` for paths, URLs, technical values
- Select dropdowns: `class="text-xs py-1 pl-2 pr-7"` for compact version

### Badges

```vue
<span class="badge badge-zinc">Label</span>
<span class="text-xs px-2 py-0.5 rounded-full bg-lime-400/10 text-lime-400 border border-lime-400/20">Status</span>
```

### Loading States

```vue
<Loader2 class="w-4 h-4 animate-spin" />
```

### Icons
- Standard size: `w-4 h-4`
- Small (in buttons): `w-3.5 h-3.5`
- Always import from `lucide-vue-next`

### Spacing
- Section margin: `mb-6`
- Inner padding: `p-5` or `px-4 py-3`
- Gap between items: `gap-2` or `gap-3`

### Do NOT
- Add emojis unless explicitly requested
- Create new files unless necessary (prefer editing existing)
- Over-engineer or add unnecessary abstractions
- Add features beyond what was asked

## Development

```bash
# Install dependencies
composer install
npm install

# Run the app in development
php artisan native:serve

# Build for production
php artisan native:build

# Run migrations (IMPORTANT: run both!)
php artisan migrate                           # Standard database
php artisan migrate --database=nativephp      # NativePHP database

# Run tests
php artisan test
```

## Related Project Development

This desktop app works with two projects that live on the remote dev server. All changes to these must be made via SSH.

### Overview

```
┌─────────────────────────┐         ┌──────────────────────────────────────┐
│  Orbit Desktop      │         │  Remote Dev Server (ai)       │
│  (this repo - local)    │  SSH    │  ssh launchpad@ai             │
│                         │ ──────► │                                      │
│  - GUI for launchpad    │         │  ~/projects/orbit-cli/  ← CLI    │
│  - Calls CLI via SSH    │         │  ~/projects/orchestrator/   ← API    │
│  - Calls orchestrator   │         │  ~/projects/*               ← SITES  │
└─────────────────────────┘         └──────────────────────────────────────┘
```

| Project | Path | Purpose | Has Releases? |
|---------|------|---------|---------------|
| orbit-cli | `~/projects/orbit-cli/` | CLI tool for managing sites, Caddy, Docker | Yes - build phar, GitHub release |
| orchestrator | `~/projects/orchestrator/` | Laravel API backend, MCP server for cross-project management | No - just deploy |

---

## Orchestrator Development

The **orchestrator** is a Laravel backend that provides:
- MCP tools for git operations, project management, task tracking
- API endpoints called by the desktop app (via `orchestrator_url`)
- Cross-project management functionality

### Making Orchestrator Changes

```bash
# SSH into the dev server
ssh launchpad@ai

# Navigate to orchestrator
cd ~/projects/orchestrator

# Make changes, run tests
php artisan test

# After controller changes
php artisan waymaker:generate
```

### Key Patterns (from orchestrator CLAUDE.md)
- **Actions** (`app/Actions/`) - Business logic with single `handle()` method
- **Services** (`app/Services/`) - Infrastructure/API wrappers
- **DTOs** (`app/Data/`) - Data transfer objects using spatie/laravel-data
- Uses Waymaker for routing - NEVER edit web.php manually

---

## Orbit CLI Development

The **orbit CLI** manages sites, Caddy configs, Docker containers, and more. Source code lives on the remote dev server, NOT locally.

### Making CLI Changes

**All CLI changes must be made on the remote server:**

```bash
# SSH into the dev server
ssh launchpad@ai

# Navigate to CLI source
cd ~/projects/orbit-cli

# Make your changes, test locally
php launchpad <command>

# When ready, publish a release (see below)
```

### Building the CLI (Laravel Zero)

The CLI is a Laravel Zero application. Build using Box (Laravel Zero uses Box under the hood).

**Quick local update (no GitHub release):**
```bash
ssh launchpad@ai
cd ~/projects/orbit-cli

# Build phar using Box
~/.config/composer/vendor/bin/box compile

# Copy to local bin
cp builds/orbit.phar ~/.local/bin/orbit
```

**Note on `app:build`:** Laravel Zero has a built-in `app:build` command (`php launchpad --env=development app:build`) but its bundled Box (4.6.7) has a PHP 8.5 compatibility bug. Use the global Box (4.6.10+) directly until Laravel Zero updates their bundled version.

### CLI Release Workflow

After making changes to the CLI, publish a new release:

**1. On the remote server - Build and release:**
```bash
ssh launchpad@ai
cd ~/projects/orbit-cli

# Commit changes first
git add -A && git commit -m "Description of changes"
git push

# Build the phar
~/.config/composer/vendor/bin/box compile

# Create GitHub release with the phar attached
gh release create v1.x.x builds/orbit.phar --title "v1.x.x" --notes "Changelog"
```

**2. Update CLI on servers:**
```bash
# On each server that runs launchpad (including the dev server)
curl -L -o ~/.local/bin/orbit https://github.com/nckrtl/orbit-cli/releases/latest/download/orbit.phar
chmod +x ~/.local/bin/orbit
```

### Key CLI Paths (on remote server)

| Path | Purpose |
|------|---------|
| `~/projects/orbit-cli/` | CLI source code - make changes here |
| `~/projects/orbit-cli/app/Commands/` | CLI commands |
| `~/projects/orbit-cli/builds/orbit.phar` | Built binary (after `app:build`) |
| `~/.local/bin/orbit` | Installed CLI binary |

### How Desktop Communicates with CLI

For remote environments, the desktop app primarily uses **direct API calls** to the remote web app (`https://orbit.{tld}/api/...`), which then executes CLI commands:

1. **Vue frontend** calls remote API directly (e.g., `DELETE /api/projects/{slug}`)
2. **Remote web app** (via PHP-FPM on host) dispatches a job to Redis queue
3. **Horizon** (systemd/launchd service on host) picks up the job and runs CLI command (e.g., `launchpad project:delete`)

For operations that require SSH (provisioning, config changes with TLD), the NativePHP backend uses:
- `LaunchpadService::executeCommand()` which runs CLI commands over SSH
- Commands are executed as `launchpad <command> --json`

**If you change CLI behavior**, you must:
1. Make changes in `~/projects/orbit-cli/` on the remote server
2. Build and release a new version
3. Update the CLI on all servers that need the new version

## E2E Testing

### Desktop Flow Test

The CLI web app includes an E2E test that replicates the desktop workflow (create project, track broadcasts, delete project):

```bash
# SSH into the remote server
ssh launchpad@ai

# Run the E2E test
cd ~/projects/orbit-cli/web
php tests/e2e-desktop-flow-test.php
```

**What it tests:**
1. Creates a project via `POST /api/projects`
2. Tracks provisioning broadcasts until `ready`
3. Deletes via `DELETE /api/projects/{slug}`
4. Tracks deletion broadcasts until `deleted`

**Expected output:**
```
Provision: provisioning -> creating_repo -> cloning -> ... -> ready
Deletion:  deleting -> removing_orchestrator -> removing_files -> deleted
ALL TESTS PASSED
```

### WebSocket Broadcasting Architecture

```
CLI (ReverbBroadcaster) -> Pusher HTTP API -> Reverb container -> Caddy -> WebSocket -> Desktop
```

**Important:** Reverb WebSocket traffic is proxied through Caddy. When Caddy reloads, WebSocket connections are briefly dropped. The CLI must broadcast final status BEFORE triggering a Caddy reload.

## Related Projects

- **orbit-cli**: The command-line tool this app controls
  - Source: `ssh launchpad@ai:~/projects/orbit-cli/`
  - Releases: `https://github.com/nckrtl/orbit-cli/releases`
  - Install/Update: `curl -L -o ~/.local/bin/orbit https://github.com/nckrtl/orbit-cli/releases/latest/download/orbit.phar && chmod +x ~/.local/bin/orbit`

- **orchestrator**: Laravel API backend for cross-project management
  - Source: `ssh launchpad@ai:~/projects/orchestrator/`
  - Provides MCP tools for git, project, and task management
  - Desktop connects via `orchestrator_url` setting

## Known Issues

- **NativePHP database sync**: Migrations must be run on BOTH databases. Use `php artisan migrate --database=nativephp` to update the app's database. See the Database section above for details.
- **"No such table" errors**: Usually means the NativePHP database hasn't been migrated. Run `php artisan migrate --database=nativephp`
- **PHP-FPM socket permissions**: Ensure the launchpad user has proper permissions on `~/.config/orbit/php/` directory
- **Horizon service not starting**: Check systemd logs with `journalctl -u launchpad-horizon` (Linux) or `launchctl list | grep horizon` (macOS)
- **Web app .env hostnames**: When Horizon runs on host (not Docker), use `localhost` for `REDIS_HOST` and `REVERB_HOST` instead of Docker container names like `orbit-redis`
- **`launchpad restart` stops Caddy/Horizon**: The CLI's restart command currently stops but doesn't restart host services. Manually restart with `sudo systemctl start caddy launchpad-horizon` (Linux)
- **Bun install hangs**: Fixed in CLI v0.0.17+ with `CI=1` and `--no-progress` flags. Update CLI if experiencing this issue.
