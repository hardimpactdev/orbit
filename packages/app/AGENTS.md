# Agent Instructions

## Project Overview

**orbit-ui** is a Laravel package that provides the web interface for the Orbit ecosystem. It contains all UI components including controllers, routes, Vue components, and assets. It depends on orbit-core for business logic and is required by orbit-web and orbit-desktop.

## Repository Locations

| Project | Location | Purpose |
|---------|----------|---------|
| orbit-ui | `~/projects/orbit-ui` (remote) | Web UI package |
| orbit-core | `~/projects/orbit-core` (remote) | Business logic package |
| orbit-web | `~/projects/orbit-web` (remote) | Web dashboard shell |
| orbit-desktop | Local Mac | NativePHP desktop shell |
| orbit-cli | `~/projects/orbit-cli` (remote) | CLI tool |

## Package Structure

```
src/
  Http/
    Controllers/             # All route handlers
      DashboardController.php
      EnvironmentController.php
      ProjectController.php
      ProvisioningController.php
      SettingsController.php
      SshKeyController.php
    Middleware/
      HandleInertiaRequests.php
      ImplicitEnvironment.php
  OrbitAppServiceProvider.php      # Package service provider
resources/
  views/
    app.blade.php            # Root Blade template (Horizon-style)
  js/
    pages/                   # Vue page components
    components/              # Reusable Vue components
    layouts/                 # App layouts
    stores/                  # Pinia stores
    composables/             # Vue composables
    types/                   # TypeScript definitions
    lib/                     # Utility libraries
    app.ts                   # Frontend entry point (configures Echo)
  css/
    app.css                  # Tailwind styles
public/
  hot                        # Vite dev server marker (gitignored)
  build/                     # Production assets (gitignored)
routes/
  web.php                    # Web routes
  api.php                    # API routes
  environment.php            # Environment-scoped routes
  mcp.php                    # MCP AI tool routes
```

## Namespace Convention

All classes use `HardImpact\Orbit\Ui` namespace:

```php
use HardImpact\Orbit\Ui\Http\Controllers\EnvironmentController;
use HardImpact\Orbit\Ui\Http\Middleware\HandleInertiaRequests;
```

Controllers import models/services from orbit-core:

```php
use HardImpact\Orbit\Core\Models\Environment;
use HardImpact\Orbit\Core\Services\Provision\ProvisionPipeline;
```

## Development Workflow

### Local Development (HMR)

```bash
cd ~/projects/orbit-ui
bun run dev                     # Creates public/hot, enables HMR
# Visit https://orbit-web.ccc   # Changes reflect instantly
```

### Production Build

```bash
cd ~/projects/orbit-ui
bun run build                   # Creates public/build/

cd ~/projects/orbit-web
php artisan vendor:publish --tag=orbit-assets --force
```

### Package Changes

1. Make changes in orbit-ui
2. Run tests: `composer test`
3. Build assets: `bun run build`
4. Commit and push
5. Update consumers: `composer update hardimpactdev/orbit-ui`

## Testing

```bash
composer test           # Run all tests
composer analyse        # PHPStan analysis
composer format         # Format with Pint
```

## Important Notes

- Always import models/services from `HardImpact\Orbit\Core\` namespace
- Never use `App\Models\*` - always `HardImpact\Orbit\Core\Models\*`
- Routes are registered via `OrbitAppServiceProvider::routes()` in consumer apps
- Asset publishing tag is `orbit-assets`

## Controller Naming Conventions

Two patterns exist for environment-related controllers:

| Pattern | Example | When to Use |
|---------|---------|-------------|
| `EnvironmentXxxController` | `EnvironmentProjectController` | Explicit environment from route parameter |
| `XxxController` | `ProjectController` | Uses "active environment" from Saloon connector |

When creating new controllers that operate on a specific environment passed as a route parameter, prefix with `Environment` to avoid conflicts with controllers using the implicit active environment pattern.

## Horizon-Style Architecture

orbit-ui follows the Laravel Horizon pattern - a self-contained package that serves its own views and assets. Shell apps (orbit-web, orbit-desktop) are empty wrappers.

**What orbit-ui provides automatically:**
- Blade view (`orbit::app`) - set as Inertia root view
- Middleware (`HandleInertiaRequests`, `implicit.environment`) - auto-registered
- Vite configuration - hot file at `public/hot`, build at `vendor/orbit/build`
- MCP routes for AI tool integration

**Shell apps only need:**
```php
// routes/web.php
\HardImpact\Orbit\Ui\OrbitAppServiceProvider::routes();
```

## UI Conventions

### Vertical Tab Pages
When implementing settings or multi-section pages with vertical tabs:
- Remove border dividers between tabs and content (let spacing create separation)
- Don't duplicate category titles in content when tabs already show active state
- Example: Environment Settings page (`/resources/js/pages/environments/Settings.vue`)

## Mode Configuration

The package supports two modes via `config("orbit.multi_environment")`:

- **Web mode** (`false`): Single implicit environment, flat routes
- **Desktop mode** (`true`): Multiple environments, prefixed routes

## WebSocket (Echo/Reverb)

Orbit's frontend uses Laravel's official `@laravel/echo-vue` composables with a
single global Echo connection. The Reverb configuration comes from the active
environment and is injected as an Inertia prop. Component-level subscriptions are
managed by the composables and automatically cleaned up when components unmount.

Key files:
- `resources/js/app.ts` configures Echo from the `reverb` page prop
- `resources/js/composables/useSiteProvisioning.ts` subscribes via `useEchoPublic`
- `resources/js/pages/environments/Services.vue` listens for service status updates

## After Making Changes

**IMPORTANT: Always complete the full workflow below:**

1. **Test locally**: `composer test`
2. **Build assets**: `bun run build`
3. **Run static analysis**: `composer analyse`
4. **Format code**: `composer format`
5. **Commit changes**: Use descriptive commit message
6. **Push via gh CLI**: `git push`
7. **Update orbit-web**:
   ```bash
   cd ~/projects/orbit-web
   composer update hardimpactdev/orbit-ui
   php artisan vendor:publish --tag=orbit-assets --force
   ```

## Asset Publishing Gotcha

When making UI changes that you want to see on orbit-web.ccc:

1. **If Vite dev server is NOT running**: You must build AND publish
   ```bash
   cd ~/projects/orbit-ui
   bun run build
   
   cd ~/projects/orbit-web
   php artisan vendor:publish --tag=orbit-assets --force
   ```

2. **Why changes might not appear**: 
   - orbit-web serves from `public/vendor/orbit/build/`
   - These are COPIED from orbit-ui, not symlinked
   - Publishing is required after each build

3. **To verify**: Check the timestamp
   ```bash
   ls -la ~/projects/orbit-web/public/vendor/orbit/
   ```

## Vite Development Server with Caddy HTTPS Proxy

When running the Vite dev server behind Caddy for HTTPS:

1. **Use VITE_APP_URL** (not APP_URL) in package.json:
   ```json
   "dev": "sh -c 'VITE_APP_URL=https://$0 vite'"
   ```

2. **Why this matters**: craft-ui's vite config reads `VITE_APP_URL` to:
   - Configure HMR websocket to connect through proxy (`wss://domain.ccc:443`)
   - Set proper origin for CORS and asset URLs
   - Write HTTPS URL to hot file instead of `http://0.0.0.0:5173`

3. **Symptoms of incorrect config**:
   - Browser error: "Mixed Content: page loaded over HTTPS but requested insecure script"
   - HMR not working (changes don't reflect instantly)
   - Hot file contains `http://0.0.0.0:5173` instead of `https://domain.ccc`

4. **To verify it's working**:
   ```bash
   cat ~/projects/orbit-ui/public/hot  # Should show https://orbit-web.ccc
   ```