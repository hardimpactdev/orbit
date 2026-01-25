# horizon:install

Installs Laravel Horizon as a system service for background queue processing.

## What It Does

1. Checks if Horizon service is already installed
2. Detects the operating system (Linux or macOS)
3. Creates appropriate service configuration:
   - **Linux**: systemd service unit file at `/etc/systemd/system/orbit-horizon.service`
   - **macOS**: launchd plist at `~/Library/LaunchAgents/com.orbit.horizon.plist`
4. Enables the service for automatic startup
5. Starts the Horizon service

## Options

| Option | Description |
|--------|-------------|
| `--json` | Output result as JSON |

## Behavior

- If already installed: reports success without reinstalling
- On success: service is installed, enabled, and started
- On failure: returns error with suggestion to check logs

## Platform Support

- Linux (systemd)
- macOS (launchd)
- Other platforms: unsupported, returns error

## Related Commands

- `horizon:start` - Start the service
- `horizon:stop` - Stop the service
- `horizon:status` - Check if installed/running
- `horizon:uninstall` - Remove the service
