# reverb:setup

Sets up Laravel Reverb WebSocket service for real-time features.

## What It Does

1. Creates Reverb config directory
2. Copies Docker configuration files
3. Generates environment with app credentials
4. Updates Orbit config with Reverb settings
5. Enables Reverb service
6. Regenerates Caddyfile with Reverb route
7. Optionally builds and starts the container

## Options

| Option | Description |
|--------|-------------|
| `--enable` | Enable and start the Reverb service immediately |
| `--disable` | Disable and stop the Reverb service |
| `--app-id` | Custom app ID (default: orbit) |
| `--app-key` | Custom app key (default: orbit-key) |
| `--app-secret` | Custom app secret (default: orbit-secret) |

## Generated Configuration

| Setting | Default |
|---------|---------|
| App ID | orbit |
| App Key | orbit-key |
| App Secret | orbit-secret |
| Host | reverb.{tld} |
| Port | 443 (via Caddy) |

## Access URL

WebSocket URL: `wss://reverb.{tld}`

## Laravel App Configuration

Add to your app's `.env`:
```
REVERB_APP_ID=orbit
REVERB_APP_KEY=orbit-key
REVERB_APP_SECRET=orbit-secret
REVERB_HOST=reverb.ccc
REVERB_PORT=443
REVERB_SCHEME=https
```

## Examples

```bash
# Setup Reverb (config only)
orbit reverb:setup

# Setup and start immediately
orbit reverb:setup --enable

# Disable Reverb
orbit reverb:setup --disable

# Custom credentials
orbit reverb:setup --app-id=myapp --app-key=mykey --app-secret=mysecret
```

## Related Commands

- `start` - Starts Reverb along with other services
- `service:enable reverb` - Enable Reverb service
