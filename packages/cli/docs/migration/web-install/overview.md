# web:install

Installs or updates the companion web app from the bundled tarball.

## What It Does

1. Locates web app bundle in CLI stubs
2. Extracts to `~/.config/orbit/web/`
3. Sets file permissions
4. Generates `.env` file with proper configuration
5. Runs database migrations
6. Updates Caddy configuration

## Options

| Option | Description |
|--------|-------------|
| `--force` | Overwrite existing installation |
| `--dry-run` | Show what would be done without making changes |

## Installation Steps

| Step | Description |
|------|-------------|
| 1 | Extract web app bundle |
| 2 | Set file permissions (755 dirs, 644 files) |
| 3 | Generate environment file |
| 4 | Run database migrations |
| 5 | Update Caddy configuration |

## Generated .env Configuration

- `ORBIT_MODE=cli` - Indicates CLI-managed instance
- SQLite database in `~/.config/orbit/`
- Redis for cache, sessions, and queue
- Reverb configuration for WebSockets

## Access URL

After installation: `https://orbit.{tld}` (e.g., `https://orbit.ccc`)

## When to Use

- During `orbit init` (called automatically)
- To reinstall/update web app: `orbit web:install --force`

## Related Commands

- `init` - Calls this automatically
- `horizon:install` - Install queue worker for web app
