---
name: test-provision
description: Test the full project provisioning flow. Use when verifying provisioning changes or debugging provision issues.
allowed-tools: Bash(ssh:*), Bash(curl:*), Read
---

# Test Project Provisioning

This skill verifies the full project provisioning flow works correctly via the API.

## Quick Test (recommended)

Run the test script with automatic cleanup:

```bash
bash .claude/scripts/test-provision-flow.sh test-$(date +%s) --cleanup
```

Or keep the project for inspection:

```bash
bash .claude/scripts/test-provision-flow.sh my-test-project
```

## Architecture Overview

```
Desktop App                    Remote Server (ai)
┌─────────────┐               ┌──────────────────────────────────────┐
│ Vue Form    │               │                                      │
│     │       │    HTTPS      │  PHP Container (orbit-php-XX)    │
│     ▼       │ ─────────────►│  └─ Web App API                      │
│ POST /api/  │               │      └─ ProjectController            │
│  projects   │               │          └─ dispatch(CreateProjectJob)
└─────────────┘               │                    │                 │
                              │                    ▼ Redis Queue     │
                              │  ┌─────────────────────────────────┐ │
                              │  │ HOST (supervisord)              │ │
                              │  │  └─ Horizon Worker               │ │
                              │  │      └─ CreateProjectJob         │ │
                              │  │          └─ orbit provision  │ │
                              │  └─────────────────────────────────┘ │
                              └──────────────────────────────────────┘
```

## Manual Testing Steps

### Step 1: Create Project via API

```bash
# Clean up any existing project first
ssh orbit@ai 'gh repo delete nckrtl/test-api --yes 2>/dev/null; rm -rf ~/projects/test-api'

# Create via API
curl -s -X POST https://orbit.ccc/api/projects \
  -H "Content-Type: application/json" \
  -d '{"name": "test-api", "template": "hardimpactdev/liftoff-starterkit", "db_driver": "pgsql", "visibility": "private"}'
```

Expected response:

```json
{
    "success": true,
    "status": "provisioning",
    "slug": "test-api",
    "message": "Project provisioning started."
}
```

### Step 2: Monitor Job Execution

```bash
# Watch the web app logs for job progress
ssh orbit@ai 'tail -f ~/.config/orbit/web/storage/logs/laravel.log | grep -E "CreateProjectJob|test-api"'
```

Expected log entries:

```
CreateProjectJob: Running {"slug":"test-api","command":"..."}
CreateProjectJob: Completed {"slug":"test-api"}
```

### Step 3: Verify Project Created

```bash
# Check if project folder exists with .env
ssh orbit@ai 'ls -la ~/projects/test-api/.env'

# Check if site responds
curl -s -o /dev/null -w "%{http_code}" https://test-api.ccc/

# Check if project appears in API
curl -s https://orbit.ccc/api/projects | jq '.data.projects[] | select(.name=="test-api")'
```

### Step 4: Cleanup

```bash
ssh orbit@ai 'rm -rf ~/projects/test-api && gh repo delete nckrtl/test-api --yes'
```

## Expected Timings

| Step                      | Time        |
| ------------------------- | ----------- |
| API dispatch + job pickup | ~1-2s       |
| GitHub repo creation      | ~3-5s       |
| Git clone                 | ~2s         |
| Composer install          | ~4s         |
| Bun install               | ~1-2s       |
| Bun build                 | ~4-5s       |
| Migrations + finalize     | ~2s         |
| **Total**                 | **~20-30s** |

**Important:** If provisioning takes >60s, something is wrong.

## Debugging

### Job Not Running

Check Horizon status:

```bash
ssh orbit@ai 'cd ~/.config/orbit/web && php artisan horizon:status'
```

Check for failed jobs:

```bash
ssh orbit@ai 'cd ~/.config/orbit/web && php artisan queue:failed'
```

Restart Horizon:

```bash
ssh orbit@ai 'cd ~/.config/orbit/web && php artisan horizon:terminate'
# Supervisord will restart it automatically
```

### Bun Install Hangs

Check if bun is in PATH:

```bash
ssh orbit@ai 'ls -la ~/.bun/bin/bun'
```

Verify CreateProjectJob has correct PATH:

```bash
ssh orbit@ai 'grep -A5 "PATH" ~/.config/orbit/web/app/Jobs/CreateProjectJob.php'
```

The PATH must include `{$home}/.bun/bin` (NOT `$home/home/orbit/.bun/bin`).

### Job Times Out

Check Horizon timeout setting:

```bash
ssh orbit@ai 'grep timeout ~/.config/orbit/web/config/horizon.php'
```

Should be `'timeout' => 120` (120 seconds).

### CLI Not Found in Container

The CLI should be mounted into PHP containers:

```bash
ssh orbit@ai 'grep orbit ~/.config/orbit/php/docker-compose.yml'
```

Should show: `~/.local/bin/orbit:/usr/local/bin/orbit:ro`

## Historical Fixes (Jan 2026)

### PATH Bug in CreateProjectJob

- **Issue**: Bun install hung indefinitely
- **Cause**: PATH was malformed as `/home/orbit/home/orbit/.bun/bin`
- **Fix**: Changed to proper interpolation `{$home}/.bun/bin`

### ProjectController Using `at now`

- **Issue**: Projects never created via API
- **Cause**: `at now` doesn't work from Docker container
- **Fix**: Changed to dispatch CreateProjectJob via Horizon

### Broadcast Exceptions Failing Jobs

- **Issue**: Jobs failed with "Could not resolve host: reverb.ccc"
- **Cause**: Horizon runs on HOST which doesn't use orbit DNS
- **Fix**: Made broadcast() catch exceptions (non-blocking)

## Key Files

| Location       | File                                                                 | Purpose                    |
| -------------- | -------------------------------------------------------------------- | -------------------------- |
| Remote Web App | `~/.config/orbit/web/app/Jobs/CreateProjectJob.php`                  | Horizon job that calls CLI |
| Remote Web App | `~/.config/orbit/web/app/Http/Controllers/Api/ProjectController.php` | API endpoint               |
| Remote Web App | `~/.config/orbit/web/config/horizon.php`                             | Queue timeout settings     |
| Remote CLI     | `~/projects/orbit-cli/app/Commands/ProvisionCommand.php`             | Actual provisioning        |
| Desktop        | `.claude/scripts/test-provision-flow.sh`                             | Test script                |
