# Changelog

All notable changes to `orbit-ui` will be documented in this file.

## v0.1.2 - 2026-01-24

- fix: use implicit environment routes for project create API calls

## v0.1.1 - Add MCP Server - 2026-01-23

### Changes

- **Added**: MCP server from orbit-core (Tools, Resources, Prompts, OrbitServer)
- **Updated**: Namespaces to HardImpact\Orbit\App\Mcp
- **Removed**: Obsolete hasInertia() check from boot()
- **Fixed**: Service provider guards for Inertia and MCP class availability

MCP routes now load when laravel/mcp is available.

## v0.1.0 - 2026-01-23

First stable release of orbit-ui package

- Complete UI package split from orbit-core
- Inertia/Vue web interface for Orbit
- HMR support for development
- Built assets for production
- Service provider with route registration

## 0.0.1 - 2026-01-23

Initial release of orbit-ui package

- Configured package with HardImpact\Orbit\App namespace
- Set up Laravel service provider with Spatie Package Tools
- Includes config, views, and migration stubs
