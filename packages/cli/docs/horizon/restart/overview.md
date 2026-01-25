# horizon:restart

Restarts the Horizon queue worker service.

## What It Does

1. Verifies Horizon service is installed
2. Restarts the service:
   - **Linux**: `systemctl restart orbit-horizon`
   - **macOS**: stop then start (unload + load plist)

## Options

| Option | Description |
|--------|-------------|
| `--json` | Output result as JSON |

## Behavior

- If not installed: returns error with install suggestion
- On success: service is restarted
- On failure: returns error

## Use Cases

- After updating orbit-core code
- After configuration changes
- When Horizon becomes unresponsive

## Prerequisites

- Horizon must be installed via `horizon:install`

## Related Commands

- `horizon:start` - Start the service
- `horizon:stop` - Stop the service
- `horizon:status` - Check current status
