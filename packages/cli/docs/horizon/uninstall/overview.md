# horizon:uninstall

Removes the Horizon system service.

## What It Does

1. Checks if Horizon service is installed
2. Stops the service if running
3. Removes the service configuration:
   - **Linux**: Deletes systemd service file, runs `daemon-reload`
   - **macOS**: Deletes launchd plist file

## Options

| Option | Description |
|--------|-------------|
| `--json` | Output result as JSON |

## Behavior

- If not installed: reports success (idempotent)
- Stops service before removal to ensure clean uninstall
- Does not remove Horizon logs

## What Gets Removed

| Platform | File Removed |
|----------|--------------|
| Linux | `/etc/systemd/system/orbit-horizon.service` |
| macOS | `~/Library/LaunchAgents/com.orbit.horizon.plist` |

## Related Commands

- `horizon:install` - Reinstall the service
- `horizon:stop` - Just stop without uninstalling
