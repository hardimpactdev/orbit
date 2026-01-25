# host:stop

Stops a host-level service (services running on the host machine, not in Docker).

## What It Does

1. Identifies the service type from the argument
2. Delegates to the appropriate service manager
3. Reports success or failure

## Arguments

| Argument | Description |
|----------|-------------|
| `service` | Service to stop: `caddy`, `php-{version}`, or `horizon` |

## Options

| Option | Description |
|--------|-------------|
| `--json` | Output result as JSON |

## Supported Services

| Service | Manager | Description |
|---------|---------|-------------|
| `caddy` | CaddyManager | Web server/reverse proxy |
| `php-8.1`, `php-8.2`, etc. | PhpManager | PHP-FPM for specific version |
| `horizon` | HorizonManager | Laravel queue worker |

## Examples

```bash
orbit host:stop caddy
orbit host:stop php-8.3
orbit host:stop horizon
```

## Related Commands

- `host:start` - Start a service
- `host:restart` - Restart a service
- `stop` - Stop Docker containers (different from host services)
