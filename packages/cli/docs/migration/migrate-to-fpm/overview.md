# migrate:to-fpm

Migrates from FrankenPHP Docker containers to PHP-FPM on the host machine.

## What It Does

1. Detects current setup (FrankenPHP containers vs PHP-FPM)
2. Stops current services
3. Installs PHP-FPM if not present
4. Configures FPM pools
5. Installs Caddy on host if needed
6. Regenerates Caddyfile for FPM sockets
7. Installs Horizon service
8. Removes old Docker containers (optional)
9. Starts new services

## Options

| Option | Description |
|--------|-------------|
| `--force` | Skip confirmation prompts |
| `--keep-containers` | Keep old PHP containers after migration |
| `--json` | Output results as JSON |

## Migration Steps

| Step | Description |
|------|-------------|
| 1 | Stop current services |
| 2 | Install PHP-FPM (8.3, 8.4) |
| 3 | Configure FPM pools |
| 4 | Install host Caddy |
| 5 | Regenerate Caddyfile |
| 6 | Reload Caddy |
| 7 | Install Horizon service |
| 8 | Remove old containers |
| 9 | Start services |

## Containers Removed (Legacy Docker Setup)

- `orbit-php-82`, `orbit-php-83`, `orbit-php-84`, `orbit-php-85`
- `orbit-caddy` (replaced by host Caddy via systemd)
- `orbit-horizon`

After migration, Caddy runs on the host as a systemd service (`caddy.service`).

## When to Use

- Upgrading from Docker-based Orbit to native PHP-FPM
- Fresh install will auto-detect and use FPM

## Related Commands

- `start` - Start services after migration
- `status` - Check service status
