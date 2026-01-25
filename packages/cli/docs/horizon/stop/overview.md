# horizon:stop

Stops the Horizon queue worker service.

## What It Does

1. Checks if Horizon service is installed
2. Checks if currently running
3. Stops the service via system service manager:
   - **Linux**: `systemctl stop orbit-horizon`
   - **macOS**: `launchctl unload` the plist

## Options

| Option | Description |
|--------|-------------|
| `--json` | Output result as JSON |

## Behavior

- If not installed: reports success (nothing to stop)
- If not running: reports success (idempotent)
- On failure: returns error

## Related Commands

- `horizon:start` - Start the service
- `horizon:restart` - Restart the service
- `horizon:status` - Check current status
- `horizon:uninstall` - Remove the service entirely
