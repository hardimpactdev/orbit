# Orbit Core

A Laravel package providing shared functionality for the Orbit ecosystem - managing local development environments powered by [Orbit CLI](https://github.com/nckrtl/orbit-cli).

[![Latest Version on Packagist](https://img.shields.io/packagist/v/hardimpactdev/orbit-core.svg?style=flat-square)](https://packagist.org/packages/hardimpactdev/orbit-core)

## Overview

Orbit Core is the shared foundation for both [orbit-desktop](https://github.com/hardimpactdev/orbit) (NativePHP desktop app) and [orbit-web](https://github.com/hardimpactdev/orbit-web) (web dashboard). It contains:

- **Models**: Environment, Project, Deployment, Setting, SshKey, TemplateFavorite, UserPreference
- **Services**: OrbitCli services (ProjectService, ConfigurationService, etc.), DoctorService, SshService
- **Controllers**: All HTTP controllers for the Orbit UI
- **Middleware**: HandleInertiaRequests, ImplicitEnvironment, DesktopOnly
- **HTTP Integrations**: Saloon connectors for Orbit API communication
- **Vue Frontend**: Pages, components, layouts, stores, and composables
- **Routes**: Web and API routes with mode-aware registration
- **MCP Server**: AI tool integration via Model Context Protocol (CLI and HTTP)

## Installation

```bash
composer require hardimpactdev/orbit-core
```

### Publish Migrations

```bash
php artisan vendor:publish --tag="orbit-core-migrations"
php artisan migrate
```

### Publish Config (optional)

```bash
php artisan vendor:publish --tag="orbit-core-config"
```

## Usage

### Register Routes

In your `AppServiceProvider`:

```php
use HardImpact\Orbit\OrbitServiceProvider;

public function boot(): void
{
    OrbitServiceProvider::routes();
}
```

### Configure Mode

In your `.env`:

```env
# Web mode (single environment, flat routes)
ORBIT_MODE=web
MULTI_ENVIRONMENT_MANAGEMENT=false

# Desktop mode (multi-environment, prefixed routes)
ORBIT_MODE=desktop
MULTI_ENVIRONMENT_MANAGEMENT=true
```

### Frontend Assets

Configure Vite to compile assets from the package:

```typescript
// vite.config.ts
export default defineConfig({
    build: {
        rollupOptions: {
            input: "vendor/hardimpactdev/orbit-core/resources/js/app.ts",
        },
    },
    resolve: {
        alias: {
            "@": path.resolve(__dirname, "vendor/hardimpactdev/orbit-core/resources/js"),
        },
    },
});
```

## Architecture

### Namespace Structure

All classes use the `HardImpact\Orbit` namespace:

```
HardImpact\Orbit\
  Models\              # Eloquent models
  Services\            # Business logic
    OrbitCli\          # CLI interaction services
      Shared\          # Shared utilities
  Http\
    Controllers\       # HTTP controllers
    Middleware\        # HTTP middleware
    Integrations\      # Saloon API connectors
```

### Mode-Aware Behavior

The package supports two modes controlled by `config("orbit.multi_environment")`:

| Aspect | Web Mode | Desktop Mode |
|--------|----------|--------------|
| Routes | Flat (`/projects`) | Prefixed (`/environments/{id}/projects`) |
| Environment | Single, implicit | Multiple, explicit |
| Environment UI | Hidden | Visible |

### Service Pattern

Services return consistent response structures:

```php
[
    "success" => bool,
    "data" => mixed,
    "error" => ?string,
]
```

## MCP (Model Context Protocol)

Orbit Core includes an MCP server for AI tool integration. This enables AI assistants like Claude to interact with your local development environment.

### Features

- **10 Tools**: Status, start/stop/restart, projects, PHP version, logs, worktrees
- **4 Resources**: Config, projects, infrastructure, env-template
- **2 Prompts**: Laravel .env configuration, Horizon setup

### Transports

| Transport | Endpoint | Consumer |
|-----------|----------|----------|
| CLI (stdio) | `orbit mcp:start orbit` | orbit-cli, Claude Code |
| HTTP | `POST /orbit` | orbit-web, web integrations |

See [docs/mcp.md](docs/mcp.md) for complete documentation.

## Related Projects

- [Orbit Desktop](https://github.com/hardimpactdev/orbit) - NativePHP desktop app (requires this package)
- [Orbit Web](https://github.com/hardimpactdev/orbit-web) - Web dashboard (requires this package)
- [Orbit CLI](https://github.com/nckrtl/orbit-cli) - The CLI tool that powers local development

## License

MIT
