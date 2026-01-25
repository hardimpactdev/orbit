# Orbit

Local PHP development environment at ~/.config/orbit/

## Commands

```bash
orbit start         # Start all services
orbit stop          # Stop all services
orbit restart       # Restart everything
orbit status        # Check service status
orbit sites         # List all sites
orbit php <site> <version>  # Set PHP version
```

## Horizon (Queue Worker)

Orbit includes a web app with Horizon for background job processing. Horizon runs in a Docker container (`orbit-horizon`).

```bash
# Check Horizon status
orbit horizon:status
docker ps | grep orbit-horizon

# Start/Stop Horizon
orbit horizon:start
orbit horizon:stop
docker restart orbit-horizon

# View logs
docker logs orbit-horizon --tail 100 -f

# Access dashboard (when running)
open https://orbit.{tld}/horizon
```

Docker configuration is at `~/.config/orbit/horizon/docker-compose.yml`.

## Direct Docker Access

```bash
# Start/stop individual services
docker compose -f ~/.config/orbit/php/docker-compose.yml up -d
docker compose -f ~/.config/orbit/postgres/docker-compose.yml down

# View logs
docker logs -f orbit-php-83
docker logs -f orbit-redis
docker logs -f orbit-horizon
```

## Host Services (systemd)

Caddy runs on the host machine via systemd, not in Docker:

```bash
# Caddy status and logs
sudo systemctl status caddy
sudo journalctl -u caddy -f

# Reload Caddy config
sudo systemctl reload caddy
```

## Sites

Paths in config.json are served as {folder}.{tld} (flat namespace, first match wins).

Set PHP version per project:

```bash
echo "8.4" > ~/projects/mysite/.php-version
```

Or via CLI:

```bash
orbit php mysite 8.4
```

Then restart: `orbit restart`

## Add a New Path

1. Edit ~/.config/orbit/config.json, add path to "paths" array
2. Edit ~/.config/orbit/php/docker-compose.yml, add volume mount
3. Run: `orbit restart`

## Config Locations

- PHP: ~/.config/orbit/php/php.ini
- Caddy: ~/.config/orbit/caddy/Caddyfile (host service, reload with `sudo systemctl reload caddy`)
- Sites: ~/.config/orbit/config.json
- DNS: ~/.config/orbit/dns/Dockerfile
- Horizon: ~/.config/orbit/horizon/docker-compose.yml
- Web app: ~/.config/orbit/web/

## Troubleshooting

```bash
# Check all services
orbit status --json | jq .

# Check Horizon specifically
orbit horizon:status
docker logs orbit-horizon --tail 50

# Check Caddy (runs on host, not Docker)
sudo systemctl status caddy
sudo journalctl -u caddy --tail 50

# Restart everything
orbit restart

# Clear config cache in Horizon container
docker exec orbit-horizon php artisan config:clear
docker restart orbit-horizon
```
