# Changelog

All notable changes to `orbit-core` will be documented in this file.

## v0.1.5 - 2026-01-24

- fix: deployments table foreign key constraint after servers->environments rename

## v0.1.3 - Add missing dependencies - 2026-01-23

### Fixes

- Add illuminate/database dependency (required for Eloquent models)
- Add illuminate/process dependency (required for Process facade)

## v0.1.1 - Move MCP to orbit-ui - 2026-01-23

### Changes

- **Removed**: MCP code (Tools, Resources, Prompts, OrbitServer) moved to orbit-ui
- **Fixed**: TestCase namespace references for OrbitCoreServiceProvider
- orbit-core now remains CLI-compatible without laravel/mcp dependency

This is a breaking change if you were using MCP directly from orbit-core. Update to orbit-ui for MCP functionality.

## v0.1.0 - Core namespace split - 2026-01-23

Breaking change: Namespace changed from HardImpact\Orbit\ to HardImpact\Orbit\Core
This release separates business logic from UI components. All Models, Services, Jobs, Data classes are now in the Core namespace.

orbit-cli and orbit-ui consumers must update their imports.

## Release 0.0.8 - 2026-01-23

Include built assets in repo for vendor:publish support

Now orbit-desktop and orbit-web can use:

```
php artisan vendor:publish --tag=orbit-assets --force





```
without needing to build assets locally.

## Release 0.0.7 - 2026-01-23

### Changes

- Fix: Compute client-side Reverb host dynamically from APP_URL TLD
- Server-side PHP connects to 127.0.0.1:8080 directly
- Client-side browsers connect via reverb.<tld> through Caddy
- Fix bun run dev command in documentation

## Release 0.0.6 - 2026-01-23

### Added

- Directory picker for Site Paths configuration - click the folder icon to browse and select directories via a modal interface
- Toast notifications for configuration save feedback

### Fixed

- Config loading now uses correct `/api/` prefix (was returning 405 error)

## Release 0.0.5 - 2026-01-22

### What's Changed

- Fix TypeScript error in Layout.vue navigation check
- Remove unused lang/php_en.json file
- Improve optional chaining for navigation footer items

### Bug Fixes

- Fixed TypeScript compilation error when building assets
- Added proper null checking for navigation.app.footer.items

## Unreleased

### Added

- Per-environment editor preference - each environment can now have its own preferred code editor for "Open in Editor" URLs. Falls back to global setting if not configured.

## 0.0.1 - 2026-01-18

Initial release of orbit-core package
