# horizon:logs

Displays Horizon service logs.

## What It Does

1. Retrieves recent log entries from the Horizon service
2. Outputs log content to the terminal

## Options

| Option | Default | Description |
|--------|---------|-------------|
| `--lines` | 100 | Number of log lines to display |

## Log Sources

- **Linux**: `journalctl -u orbit-horizon`
- **macOS**: `~/.config/orbit/logs/horizon.log`

## Examples

```bash
# Show last 100 lines (default)
orbit horizon:logs

# Show last 500 lines
orbit horizon:logs --lines=500
```

## Related Commands

- `horizon:status` - Check if service is running
- `horizon:restart` - Restart if issues found in logs
