# Agent Instructions

This project uses **bd** (beads) for issue tracking. Run `bd onboard` to get started.

## Quick Reference

```bash
bd ready              # Find available work
bd show <id>          # View issue details
bd update <id> --status in_progress  # Claim work
bd close <id>         # Complete work
bd sync               # Sync with git
```

## Project Overview

**orbit-desktop** is a thin NativePHP shell that provides a macOS desktop app for managing Orbit CLI installations. All business logic comes from the [orbit-core](https://github.com/hardimpactdev/orbit-core) package.

## Repository Locations

| Project | Location | Purpose |
|---------|----------|---------|
| orbit-core | `~/projects/orbit-core` (remote) | Shared Laravel package |
| orbit-web | `~/projects/orbit-web` (remote) | Web dashboard shell |
| orbit-desktop | `/Users/nckrtl/Projects/orbit-desktop` (local) | NativePHP desktop shell |
| orbit-cli | `~/projects/orbit-cli` (remote) | CLI tool |

**Remote server access:**
```bash
ssh ai                  # Via SSH config
```

## Project Structure

```
orbit-desktop/
  app/
    Models/User.php                    # Only local model
    Services/NotificationService.php   # NativePHP-specific
    Providers/
      AppServiceProvider.php           # Registers orbit-core routes
      NativeAppServiceProvider.php     # NativePHP config
  config/
    orbit.php                          # Mode configuration
    nativephp.php                      # NativePHP settings
  resources/
    views/app.blade.php                # Blade template
  tests/                               # All tests
  vite.config.ts                       # Compiles assets from orbit-core
  composer.json                        # Requires hardimpactdev/orbit-core
```

## Key Configuration

### Desktop Mode Settings

```env
ORBIT_MODE=desktop
MULTI_ENVIRONMENT_MANAGEMENT=true
```

### Route Registration

Routes are registered in `AppServiceProvider`:

```php
use HardImpact\Orbit\OrbitAppServiceProvider;

public function boot(): void
{
    OrbitAppServiceProvider::routes();
}
```

### Vite Configuration

Assets are compiled from orbit-core:

```typescript
build: {
    rollupOptions: {
        input: "vendor/hardimpactdev/orbit-core/resources/js/app.ts",
    },
}
```

## Important Architecture Notes

- This is a **thin shell** - do NOT add business logic here
- All controllers, services, models (except User) come from orbit-core
- Only NativePHP-specific code belongs here (NotificationService, menu bar config)
- If you need to change functionality, update orbit-core instead
- Always update orbit-core after making changes: `composer update hardimpactdev/orbit-core`

## Desktop Mode Behavior

In desktop mode (`MULTI_ENVIRONMENT_MANAGEMENT=true`):
- Routes are prefixed: `/environments/{id}/projects`
- Environment switcher UI is visible
- SSH key management is available
- Native notifications via NativePHP

## New Workspace Setup

When setting up orbit-desktop in a new workspace (e.g., Conductor):

```bash
# 1. Install dependencies
composer install
npm install
npm install @laravel/echo-vue

# 2. Create environment
cp .env.example .env
php artisan key:generate

# 3. Copy pre-built orbit-core assets from remote
mkdir -p public/vendor/orbit
scp -r nckrtl@ai:~/projects/orbit-web/public/vendor/orbit/build public/vendor/orbit/

# 4. Patch NativePHP for PHP 8.5 (if needed)
# Edit vendor/nativephp/electron/src/Traits/ExecuteCommand.php
# Change: PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION
# To: config('nativephp.binary_version', PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION)
```

**Why pre-built assets?** orbit-core has linked dependencies (craft-ui) only available on the remote server. We use pre-built assets like orbit-web does, without running vite locally.

**PHP 8.5 note:** NativePHP php-bin only has 8.3/8.4 binaries. The .env sets `NATIVEPHP_PHP_BINARY_VERSION=8.4` but the vendor code ignores config until patched.

## Build, Lint, and Test Commands

### Frontend (Vue + TypeScript)

```bash
npm run dev           # Start Vite dev server
npm run build         # Build for production
npm run typecheck     # TypeScript type checking
```

### Backend (PHP/Laravel)

```bash
php artisan test                      # Run all tests
php artisan test tests/Browser/       # Run browser tests (Pest + Playwright)
php artisan test --filter=testName    # Run specific test
phpstan analyse                       # PHPStan static analysis
```

### NativePHP

```bash
php artisan native:serve    # Run in development
php artisan native:build    # Build for distribution
```

## Testing

Tests use orbit-core namespaces:

```php
use HardImpact\Orbit\Core\Models\Environment;
use HardImpact\Orbit\Core\Services\OrbitCli\ProjectService;

// Use the helper function for creating environments
$environment = createEnvironment(['is_local' => true]);
```

### Mocking Services

When mocking orbit-core services:

```php
$this->mock(\HardImpact\Orbit\Core\Services\DoctorService::class, function ($mock) {
    $mock->shouldReceive('runChecks')->andReturn(['success' => true]);
});
```

## Orbit CLI Development

The orbit-cli source code is developed on a remote server.

**When CLI changes are needed:**

1. SSH into the remote machine: `ssh ai`
2. Navigate to CLI source: `cd ~/projects/orbit-cli`
3. Make your changes
4. Run tests: `php orbit test`
5. Release a new version
6. Update local CLI: `orbit upgrade` (on this Mac)

## After orbit-core Updates

When orbit-core is updated:

```bash
composer update hardimpactdev/orbit-core
npm run build
php artisan migrate  # If new migrations
```

## Known Gotchas

### NativePHP Uses npm (Not Bun)

NativePHP doesn't support bun. Always use npm for this project:

```bash
npm install    # NOT bun install
npm run dev    # NOT bun run dev
```

### Test Namespaces

All orbit-core classes use `HardImpact\Orbit\*` namespace, not `App\*`:

```php
// Correct
use HardImpact\Orbit\Core\Models\Environment;

// Wrong - will fail
use App\Models\Environment;
```

### Inertia Page Paths

The `config/inertia.php` includes orbit-core's page paths for testing:

```php
'testing' => [
    'page_paths' => [
        resource_path('js/Pages'),
        base_path('vendor/hardimpactdev/orbit-core/resources/js/pages'),
    ],
],
```

## Landing the Plane (Session Completion)

**When ending a work session**, you MUST complete ALL steps below:

1. **Run quality gates** - Tests must pass
2. **PUSH TO REMOTE** - This is MANDATORY:
    ```bash
    git pull --rebase
    git push
    git status  # MUST show "up to date with origin"
    ```
3. **Verify** - All changes committed AND pushed

**CRITICAL:** Work is NOT complete until `git push` succeeds.
