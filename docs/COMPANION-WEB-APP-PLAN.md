# Launchpad Companion Web App - Implementation Plan

This document captures the complete plan for implementing a Laravel companion web app to handle async operations (primarily job queuing) for the orbit-cli.

## Problem Statement

The current async project creation flow is complex and fragile:

- CLI uses `at now` to spawn background processes
- Status broadcasts via Reverb from background process
- Error handling is difficult
- Hard to track progress and debug issues

## Solution Overview

Ship a Laravel companion web app alongside the CLI that:

- Handles async operations via proper Laravel queues
- Uses Horizon for job management and auto-restart
- Broadcasts status updates via Reverb (already working)
- Integrates tightly with existing orbit infrastructure

## Architecture

### High-Level Overview

```
Desktop App
    │
    └── HTTP/WebSocket to orbit.{tld}
            │
            ├── Simple queries → immediate response
            │
            └── Complex operations → Queue job
                    │
                    └── Horizon (on host) processes job
                        └── Job calls CLI commands
                        └── Job broadcasts progress via Reverb
```

### Repository Structure

The companion web app lives inside the orbit-cli repository:

```
orbit-cli/
├── app/
│   └── Commands/           ← CLI commands (Laravel Zero)
├── web/                    ← Companion Laravel app
│   ├── app/
│   │   ├── Http/
│   │   │   └── Controllers/Api/
│   │   └── Jobs/
│   │       └── CreateProjectJob.php
│   ├── routes/
│   │   └── api.php
│   ├── artisan
│   ├── composer.json
│   └── ...
└── builds/
    └── orbit.phar      ← Built CLI binary
```

**Why same repo?**

- CLI and web app stay in sync
- Single version, single release
- No coordination between separate repos

### Deployment Structure

```
~/.config/orbit/
├── web/                    ← Laravel app (replaced on update)
│   ├── app/
│   ├── artisan
│   ├── vendor/
│   └── ...
├── logs/
│   ├── horizon.log
│   └── ensure.log
└── (no database - stateless)

~/.local/bin/
└── orbit               ← CLI binary
```

## Infrastructure Services

After `orbit init`, the following services run:

### Containers (Docker)

| Service    | Purpose                                            | Restart Policy   |
| ---------- | -------------------------------------------------- | ---------------- |
| FrankenPHP | Serves web app + all user projects                 | `unless-stopped` |
| Redis      | Queues, cache, Horizon data, failed jobs           | `unless-stopped` |
| Reverb     | WebSocket broadcasting                             | `unless-stopped` |
| DNS        | `*.{tld}` resolution                               | `unless-stopped` |
| Postgres   | Database for user projects (optional but included) | `unless-stopped` |

### Host Processes

| Process | Purpose                      | Management        |
| ------- | ---------------------------- | ----------------- |
| Horizon | Queue worker, job management | Cron-based ensure |

## Horizon - Queue Management

### Why Horizon?

- Built-in process supervision (restarts crashed workers)
- Status command for health checks
- Dashboard for monitoring (bonus)
- Handles Redis auth automatically

### Where It Runs

**Horizon runs on the HOST, not in a container.**

Why? Jobs need to call `orbit` CLI commands, which need access to:

- Docker socket
- Host filesystem
- Caddy configs

### Health Check Approach

Instead of checking Redis connectivity separately, we let Horizon tell us:

```php
public function ensureHorizonRunning(): void
{
    if ($this->isHorizonRunning()) {
        return; // Already running
    }

    $this->info('Starting Horizon...');

    // Start Horizon in background
    Process::start('php ~/.config/orbit/web/artisan horizon');

    // Give it time to either connect or fail
    sleep(3);

    if ($this->isHorizonRunning()) {
        $this->info('Horizon started successfully');
    } else {
        $this->warn('Horizon failed to start (Redis may not be ready)');
    }
}

private function isHorizonRunning(): bool
{
    $result = Process::run('php ~/.config/orbit/web/artisan horizon:status');
    return str_contains($result->output(), 'Horizon is running');
}
```

**Why this works:**

- No Redis config duplication
- Handles authentication automatically
- Tests the actual thing (Horizon working), not a proxy

## Process Management - The Ensure Command

### Single Cron Entry

```cron
* * * * * orbit ensure >> ~/.config/orbit/logs/ensure.log 2>&1
```

Installed during `orbit init` in the `orbit` user's crontab.

### What `orbit ensure` Does

```php
public function handle()
{
    // 1. Is Docker running?
    if (!$this->isDockerRunning()) {
        $this->warn('Docker not running, waiting...');
        return; // Try again next minute
    }

    // 2. Are containers running? Start if not
    $this->ensureContainersRunning();

    // 3. Is Horizon running? Start if not
    $this->ensureHorizonRunning();

    $this->info('All services running');
}
```

### Handles All Recovery Scenarios

| Scenario            | What Happens                                     |
| ------------------- | ------------------------------------------------ |
| Server reboot       | Cron runs within 1 min, starts everything        |
| Horizon crashes     | Cron detects, restarts Horizon                   |
| Container crashes   | Docker restart policy handles it                 |
| Redis not ready yet | Horizon fails to start, cron retries next minute |

### Race Condition Handling

After reboot, there's a dependency chain:

```
Docker daemon → Redis container → Horizon
```

The ensure command handles this gracefully:

1. Docker not ready → skip, try next minute
2. Containers starting → start them, continue
3. Horizon can't connect to Redis → fails fast
4. Next minute → Horizon starts successfully

Within 2-3 minutes of reboot, everything is up.

## Installation & Updates

### On `orbit init`

```bash
orbit init
# 1. Start Docker containers (FrankenPHP, Redis, Reverb, DNS, Postgres)
# 2. Copy bundled web/ to ~/.config/orbit/web/
# 3. Generate .env with known values
# 4. Run composer install (platform-specific dependencies)
# 5. Configure FrankenPHP to serve orbit.{tld}
# 6. Install cron job: * * * * * orbit ensure
# 7. Start Horizon
```

### On `orbit update`

```bash
orbit update
# 1. Download new CLI binary
# 2. Copy web/ to ~/.config/orbit/web/ (overwrite all files)
# 3. Regenerate .env (we know all values)
# 4. Run composer install (in case dependencies changed)
# 5. Restart Horizon (picks up new code)
```

### Why Composer Install on Target

Platform-specific dependencies may differ between Mac and Linux. Running `composer install` on the target ensures compatibility.

### The .env File

Generated automatically with known values:

```env
APP_NAME=Launchpad
APP_ENV=production
APP_KEY=base64:... # Generated on init
APP_DEBUG=false

# No database - stateless
DB_CONNECTION=null

# Redis for everything
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=null

# Queue
QUEUE_CONNECTION=redis
QUEUE_FAILED_DRIVER=null  # Horizon tracks in Redis

# Cache & Sessions
CACHE_STORE=redis
SESSION_DRIVER=redis

# Broadcasting
BROADCAST_CONNECTION=reverb

# Reverb (values match existing setup)
REVERB_APP_ID=orbit
REVERB_APP_KEY=orbit-key
REVERB_APP_SECRET=orbit-secret
REVERB_HOST=reverb.{tld}
REVERB_PORT=443
REVERB_SCHEME=https
```

## Stateless Design

### No Database Required

The companion web app is stateless:

- Jobs stored in Redis
- Failed jobs tracked by Horizon in Redis
- No persistent data to manage
- App files can be replaced anytime

**Benefits:**

- No migrations to run
- No database backups needed
- Simple updates (just overwrite files)
- No state conflicts

### What's in Redis

| Data            | Purpose                       |
| --------------- | ----------------------------- |
| Pending jobs    | Queue waiting to be processed |
| Active jobs     | Currently being processed     |
| Failed jobs     | Horizon's failure tracking    |
| Horizon metrics | Dashboard data                |

If Redis is flushed, job history is lost. For a dev tool, this is acceptable.

## Reserved Domain

### `orbit.{tld}` is Reserved

- Users cannot create projects named "orbit"
- CLI validates project names and rejects "orbit"
- The domain `orbit.{tld}` always serves the companion web app

### FrankenPHP Configuration

During `orbit init`, FrankenPHP/Caddy is configured to serve:

- `orbit.{tld}` → `~/.config/orbit/web/public`
- `*.{tld}` → User projects as usual

## Error Handling

### Job Failure Broadcasting

Jobs broadcast their status, including failures:

```php
class CreateProjectJob implements ShouldQueue
{
    public string $slug;

    public function handle()
    {
        try {
            $this->broadcast('creating_repo');
            Process::run("orbit repo:create {$this->slug}");

            $this->broadcast('cloning');
            Process::run("orbit site:clone {$this->slug}");

            // ... more steps ...

            $this->broadcast('ready');

        } catch (\Exception $e) {
            $this->broadcast('failed', $e->getMessage());
            throw $e; // Re-throw so Horizon marks it failed
        }
    }

    // Called automatically when job fails (after retries)
    public function failed(\Throwable $exception)
    {
        $this->broadcast('failed', $exception->getMessage());
    }

    private function broadcast(string $status, ?string $error = null)
    {
        broadcast(new ProjectProvisionStatus($this->slug, $status, $error));
    }
}
```

### Why This Works

| Scenario         | What Happens                               |
| ---------------- | ------------------------------------------ |
| Step fails       | catch block broadcasts `failed`, re-throws |
| Unexpected crash | `failed()` method broadcasts automatically |
| Success          | broadcasts `ready`                         |

### Desktop Already Handles This

The existing `useProjectProvisioning.ts` composable:

- Listens for `failed` status
- Displays error message
- Has polling fallback if WebSocket fails

No desktop changes needed for error handling.

## Jobs to Implement

### Phase 1: CreateProjectJob

The main pain point. Replaces the current `at now` background process.

```php
class CreateProjectJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $slug,
        public string $template,
        public string $dbDriver,
        public string $visibility,
    ) {}

    public function handle()
    {
        // Each step broadcasts status and calls CLI
        $this->broadcast('creating_repo');
        $this->runCli("repo:create {$this->slug} --visibility={$this->visibility}");

        $this->broadcast('cloning');
        $this->runCli("site:clone {$this->slug}");

        $this->broadcast('setting_up');
        $this->runCli("site:setup {$this->slug} --template={$this->template}");

        $this->broadcast('installing_composer');
        // ... run composer install

        $this->broadcast('installing_npm');
        // ... run npm install

        $this->broadcast('building');
        // ... run npm build

        $this->broadcast('finalizing');
        $this->runCli("caddy:reload");

        $this->broadcast('ready');
    }

    private function runCli(string $command): void
    {
        $result = Process::run("orbit {$command}");
        if (!$result->successful()) {
            throw new \Exception("CLI command failed: {$command}\n{$result->errorOutput()}");
        }
    }
}
```

### Future Jobs (As Needed)

- `DeleteProjectJob` - Clean project deletion
- `UpdateProjectJob` - Pull latest, rebuild
- Other long-running operations

## API Endpoints

### Companion Web App Routes

```php
// routes/api.php

Route::post('/projects', [ProjectController::class, 'store']);
// Dispatches CreateProjectJob, returns immediately

Route::delete('/projects/{slug}', [ProjectController::class, 'destroy']);
// Dispatches DeleteProjectJob, returns immediately

Route::get('/projects/{slug}/status', [ProjectController::class, 'status']);
// Returns current job status (polling fallback)
```

### Controller Example

```php
class ProjectController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'slug' => 'required|string',
            'template' => 'required|string',
            'db_driver' => 'required|in:mysql,pgsql,sqlite',
            'visibility' => 'required|in:public,private',
        ]);

        // Reject reserved name
        if ($validated['slug'] === 'orbit') {
            return response()->json(['error' => 'Name "orbit" is reserved'], 422);
        }

        CreateProjectJob::dispatch(
            $validated['slug'],
            $validated['template'],
            $validated['db_driver'],
            $validated['visibility'],
        );

        return response()->json(['status' => 'queued', 'slug' => $validated['slug']]);
    }
}
```

## Desktop App Integration

### What Changes

| Current                                 | New                            |
| --------------------------------------- | ------------------------------ |
| SSH → CLI → `at now` background process | HTTP → Web app API → Queue job |
| Complex, fragile                        | Standard Laravel patterns      |

### What Stays the Same

- WebSocket connection to Reverb (same `useProjectProvisioning.ts`)
- Polling fallback (same endpoints, different host)
- Status event format (same structure)
- Error handling (same `failed` status)

### URL Discovery

Desktop already knows the environment's TLD. The web app URL is always:

```
https://orbit.{tld}
```

No additional configuration needed.

## Authentication

### Current Decision: Skip

- Environments are on VPN (network-level security)
- Adding tokens adds complexity without clear benefit
- Can add later if needed (middleware + token header)

### Future Option

If auth is needed later:

```php
// Middleware
if ($request->header('Authorization') !== 'Bearer ' . config('orbit.api_token')) {
    abort(401);
}
```

Token would be:

- Generated on `orbit init`
- Stored in `~/.config/orbit/api_token`
- Desktop retrieves it and includes in requests

## User Model

### Provisioning Flow

1. **Root user** - Initial server provisioning (create users, install packages)
2. **orbit user** - Everything else

### Cron Job Ownership

The ensure cron runs as `orbit` user:

- Has Docker group membership
- Owns the web app files
- Can run Horizon
- No root needed for daily operations

## Testing the Setup

### Verify Installation

```bash
# Check containers
docker ps | grep orbit

# Check Horizon
orbit horizon:status

# Check web app
curl https://orbit.{tld}/api/health

# Check cron
crontab -l | grep orbit
```

### Test Project Creation

```bash
# Via API
curl -X POST https://orbit.{tld}/api/projects \
  -H "Content-Type: application/json" \
  -d '{"slug":"test-project","template":"laravel/laravel","db_driver":"pgsql","visibility":"private"}'

# Watch Horizon
php ~/.config/orbit/web/artisan horizon:status

# Check logs
tail -f ~/.config/orbit/logs/horizon.log
```

## Summary

This plan provides:

- **Clean separation**: CLI for commands, web app for async
- **Reliable queuing**: Laravel + Horizon, battle-tested
- **Auto-recovery**: Cron-based ensure handles crashes and reboots
- **Simple updates**: Overwrite files, regenerate .env, done
- **No database**: Stateless design, Redis handles everything
- **Existing infrastructure**: Uses same Reverb, same event format
- **Minimal desktop changes**: Same WebSocket, same status events

The companion web app is a thin async layer that leverages Laravel's strengths while keeping the CLI as the source of truth for all orbit operations.
