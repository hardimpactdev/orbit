# horizon:start

Starts the Horizon queue worker service.

## What It Does

1. Verifies Horizon service is installed
2. Checks if already running
3. Starts the service via system service manager:
   - **Linux**: `systemctl start orbit-horizon`
   - **macOS**: `launchctl load` the plist

## Options

| Option | Description |
|--------|-------------|
| `--json` | Output result as JSON |

## Behavior

- If not installed: returns error with install suggestion
- If already running: reports success (idempotent)
- On failure: returns error

## Prerequisites

- Horizon must be installed via `horizon:install`

## Related Commands

- `horizon:install` - Install the service first
- `horizon:stop` - Stop the service
- `horizon:restart` - Restart the service
- `horizon:status` - Check current status
