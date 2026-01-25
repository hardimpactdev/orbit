# horizon:status

Checks the current status of the Horizon service.

## What It Does

1. Checks if Horizon service is installed
2. If installed, checks if currently running
3. Reports status and suggests next action if needed

## Options

| Option | Description |
|--------|-------------|
| `--json` | Output result as JSON with `installed` and `running` booleans |

## Output States

| Installed | Running | Message |
|-----------|---------|---------|
| No | - | Not installed, suggests `horizon:install` |
| Yes | No | Not running, suggests `horizon:start` |
| Yes | Yes | Running |

## Platform Detection

- **Linux**: Checks systemd service file and `systemctl is-active`
- **macOS**: Checks plist file and `launchctl list`

## Related Commands

- `horizon:install` - Install the service
- `horizon:start` - Start the service
- `horizon:logs` - View service logs
