---
date: 2026-01-21
problem_type: runtime_error
component: horizon/queue_processing
severity: critical
symptoms:
  - "Site creation stuck at 'Queued...' status"
  - "Site disappears after hard page refresh"
  - "ModelNotFoundException: No query results for model [HardImpact\Orbit\Models\Site]"
  - "Jobs failing immediately after dispatch"
root_cause: Multiple Horizon instances competing for jobs with different code versions
tags: [horizon, queue, job-processing, development-environment]
---

# Multiple Horizon Instances Causing Job Failures

## Symptom
Sites created through orbit-web.ccc would:
- Show "Queued..." status indefinitely
- Disappear completely after page refresh
- Have jobs fail with `ModelNotFoundException` for the Site model
- Show site IDs in failed_jobs table that don't exist in sites table

## Investigation
1. Attempted: Verified Horizon was running for orbit-web
   Result: Status showed "running" but jobs weren't processing correctly

2. Attempted: Checked if sites were being created in database
   Result: Direct API calls worked (curl, tinker) but browser requests failed

3. Attempted: Added afterCommit() to job dispatch
   Result: Didn't help - the issue wasn't transaction-related

4. Attempted: Traced the full request flow
   Result: Site was created successfully but job couldn't find it

## Root Cause
Two Horizon instances were running simultaneously:
- **Bundled Horizon** at `~/.config/orbit/web` (PID 484638) - had outdated orbit-core code
- **Dev Horizon** at `/home/nckrtl/projects/orbit-web` (PID 663934) - had current code

Both connected to the same Redis instance, so jobs were randomly picked up by either. When the bundled instance (with old code) processed a job, it would fail due to missing constants, model methods, or other code changes.

## Solution
Stop the bundled Horizon instance to ensure only the dev instance processes jobs:

```bash
# Check for multiple Horizon instances
ps aux | grep horizon | grep -v grep

# Stop the bundled instance (if not using systemd)
cd ~/.config/orbit/web && php artisan horizon:terminate

# If orbit-horizon is a systemd service (auto-restarts when killed)
sudo systemctl stop orbit-horizon

# For development, disable auto-start entirely
sudo systemctl disable orbit-horizon

# Ensure only dev Horizon is running
cd /home/nckrtl/projects/orbit-web && php artisan horizon:status
```

### Note on systemd Service
The bundled Horizon often runs as a systemd service (`orbit-horizon.service`) that auto-restarts when terminated. Using `php artisan horizon:terminate` won't work permanently - you must use `systemctl stop`.

## Prevention
- **Before starting development**: Check for and stop any bundled Horizon instances
- **Disable systemd service**: Run `sudo systemctl disable orbit-horizon` to prevent auto-start
- **Use a startup script**: Create a dev startup script that stops bundled services
- **Different Redis databases**: Configure dev to use a different Redis DB than production
- **Check process list**: Regular `ps aux | grep horizon` to spot duplicates

### Add to dev startup routine:
```bash
#!/bin/bash
# dev-setup.sh
echo "Stopping bundled services..."
sudo systemctl stop orbit-horizon 2>/dev/null || true
# Also try non-systemd method as fallback
cd ~/.config/orbit/web && php artisan horizon:terminate 2>/dev/null || true

echo "Starting dev Horizon..."
cd ~/projects/orbit-web && php artisan horizon

echo "Starting Vite dev server..."
cd ~/projects/orbit-core && bun run dev
```

### Check if systemd service exists:
```bash
systemctl status orbit-horizon
# If it shows as a service, you'll need to use systemctl commands
```

## Related
- Site creation flow documentation: `/docs/flows/site-creation.md`
- Horizon documentation: https://laravel.com/docs/horizon
- Similar issue with WebSocket connections: Multiple Reverb instances