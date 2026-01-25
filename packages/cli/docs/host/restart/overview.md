# host:restart

Restarts a host-level service (services running on the host machine, not in Docker).

## What It Does

1. Identifies the service type from the argument
2. Delegates to the appropriate service manager
3. Reports success or failure

## Arguments

| Argument | Description |
|----------|-------------|
| `service` | Service to restart: `caddy`, `php-{version}`, or `horizon` |

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

## Use Cases

- After configuration changes to Caddy
- After PHP configuration updates
- When a service becomes unresponsive

## Examples

```bash
orbit host:restart caddy
orbit host:restart php-8.3
orbit host:restart horizon
```

## Related Commands

- `host:start` - Start a service
- `host:stop` - Stop a service
- `restart` - Restart Docker containers (different from host services)
