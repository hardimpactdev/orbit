# orbit-web

Empty Laravel 12 shell for orbit-core. All UI, routes, and assets come from the package.

## Architecture

orbit-web is intentionally minimal - just Laravel boilerplate + `composer require orbit-core`.

```
orbit-web/
├── app/Providers/
│   ├── AppServiceProvider.php     ← calls OrbitAppServiceProvider::routes()
│   └── HorizonServiceProvider.php
├── bootstrap/
│   └── app.php                    ← registers HandleInertiaRequests middleware
├── config/
├── database/
├── public/
│   └── vendor/orbit/build/        ← published assets (production)
├── routes/
│   └── web.php                    ← empty (orbit-core provides routes)
├── .env
└── composer.json
```

**No frontend files** - no `resources/js`, `resources/css`, `resources/views`, `vite.config.js`, `package.json`.

## How It Works

1. **Routes**: `OrbitAppServiceProvider::routes()` registers all routes from orbit-core
2. **Views**: orbit-core provides `resources/views/app.blade.php` via `loadViewsFrom()`
3. **Assets**: In dev, Vite serves from orbit-core's dev server. In prod, published to `public/vendor/orbit/build/`
4. **Middleware**: `HandleInertiaRequests` comes from orbit-core

## Development

**You don't develop here.** All UI development happens in orbit-core.

```bash
# Start dev server in orbit-core
cd ~/projects/orbit-core
bun run dev

# View in browser
open https://orbit-web.ccc
```

HMR works because orbit-core's service provider configures `Vite::useHotFile()` to point to the package's hot file.

## Production

```bash
# Build assets in orbit-core
cd ~/projects/orbit-core
bun run build

# Publish to this shell
cd ~/projects/orbit-web
php artisan vendor:publish --tag=orbit-assets --force
```

## Key Files

| File | Purpose |
|------|---------|
| `app/Providers/AppServiceProvider.php` | Calls `OrbitAppServiceProvider::routes()` |
| `bootstrap/app.php` | Registers `HandleInertiaRequests` middleware |
| `config/orbit.php` | Published orbit-core config |
| `.env` | Environment config (ORBIT_MODE, ORBIT_CLI_PATH, etc.) |

## Commands

```bash
# Update orbit-core
composer update hardimpactdev/orbit-core

# Publish config
php artisan vendor:publish --tag=orbit-config

# Publish assets (production)
php artisan vendor:publish --tag=orbit-assets --force

# Run Horizon
php artisan horizon
```

## Environment Variables

Key orbit-specific variables in `.env`:

```
ORBIT_MODE=web
ORBIT_CLI_PATH=/home/nckrtl/projects/orbit-cli/orbit  # Path to orbit CLI executable
DB_DATABASE=/home/nckrtl/.config/orbit/database.sqlite  # Shared with CLI
VITE_REVERB_HOST=reverb.ccc
```

For development:
- `ORBIT_CLI_PATH` points to the orbit-cli project (changes take effect immediately)
- `DB_DATABASE` points to the CLI's database (shared data)

## Database Migrations

**orbit-web is the migration runner for development.** Run migrations here to update the shared database:

```bash
cd ~/projects/orbit-web
php artisan migrate
```

This runs orbit-core's migrations against the shared CLI database.

## Testing

Tests live in orbit-core. This shell only needs basic smoke tests.

```bash
php artisan test
```

## Related Projects

- **orbit-core**: The actual product - all UI, routes, controllers, assets
- **orbit-cli**: CLI tool that bundles orbit-web
- **orbit-desktop**: NativePHP shell (also uses orbit-core)
