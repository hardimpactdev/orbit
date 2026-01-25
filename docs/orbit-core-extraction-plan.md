# Orbit Core Extraction Plan

## Overview

Extract shared code from `orbit-desktop` into a reusable Laravel package (`orbit-core`) that can be required by both:
- **orbit-web**: Standalone web interface for remote Orbit instances
- **orbit-desktop**: NativePHP Mac app managing multiple environments

## Architecture

```
orbit-core/  (Laravel package)
├── Shared controllers, models, services
├── Shared Vue pages & components
├── Shared routes, migrations, config
└── Mode-aware (web vs desktop)

orbit-web/
├── Requires orbit-core
├── Web-specific: ImplicitEnvironment middleware
├── Single environment mode
└── Deployed on remote servers

orbit-desktop/
├── Requires orbit-core
├── Desktop-specific: NativePHP integration
├── Multi-environment management
└── Environment switcher UI
```

## What Goes Where

| Component | orbit-core | orbit-web | orbit-desktop |
|-----------|:----------:|:---------:|:-------------:|
| **Controllers** | | | |
| EnvironmentController | ✓ | - | - |
| **Models** | | | |
| Environment | ✓ | - | - |
| SshKey | ✓ | - | - |
| Setting | ✓ | - | - |
| TemplateFavorite | ✓ | - | - |
| UserPreference | ✓ | - | - |
| **Services** | | | |
| OrbitCli/* (ProjectService, etc.) | ✓ | - | - |
| DoctorService | ✓ | - | - |
| StatusService | ✓ | - | - |
| NotificationService | - | - | ✓ |
| **Routes** | | | |
| routes/environment.php | ✓ | - | - |
| **Middleware** | | | |
| ImplicitEnvironment | - | ✓ | - |
| HandleInertiaRequests (shared parts) | ✓ | extends | extends |
| **Vue Pages** | | | |
| pages/environments/* | ✓ | - | - |
| **Vue Components** | | | |
| EnvironmentSwitcher | ✓ | - | - |
| Heading, Modal, etc. | ✓ | - | - |
| **CSS** | | | |
| Tailwind theme/variables | ✓ | - | - |
| **Config** | | | |
| config/orbit.php | ✓ (publishable) | customizes | customizes |
| **Migrations** | | | |
| All environment-related | ✓ | - | - |
| **NativePHP** | | | |
| Window management | - | - | ✓ |
| Native notifications | - | - | ✓ |
| Menu bar | - | - | ✓ |

## Package Structure

```
orbit-core/
├── src/
│   ├── OrbitCoreServiceProvider.php
│   ├── Http/
│   │   ├── Controllers/
│   │   │   └── EnvironmentController.php
│   │   └── Middleware/
│   │       └── ShareOrbitData.php  (shared Inertia props)
│   ├── Models/
│   │   ├── Environment.php
│   │   ├── SshKey.php
│   │   ├── Setting.php
│   │   ├── TemplateFavorite.php
│   │   └── UserPreference.php
│   └── Services/
│       ├── OrbitCli/
│       │   ├── ProjectService.php
│       │   ├── ServiceManager.php
│       │   ├── WorkspaceService.php
│       │   └── ...
│       ├── DoctorService.php
│       └── StatusService.php
├── resources/
│   ├── js/
│   │   ├── pages/
│   │   │   └── environments/
│   │   │       ├── Dashboard.vue
│   │   │       ├── Projects.vue
│   │   │       ├── Services.vue
│   │   │       ├── Settings.vue
│   │   │       ├── Workspaces.vue
│   │   │       └── ...
│   │   └── components/
│   │       ├── EnvironmentSwitcher.vue
│   │       ├── Heading.vue
│   │       ├── Modal.vue
│   │       └── ...
│   └── css/
│       └── orbit.css  (shared theme variables)
├── routes/
│   └── environment.php
├── database/
│   └── migrations/
│       ├── create_environments_table.php
│       ├── create_ssh_keys_table.php
│       ├── create_settings_table.php
│       └── ...
├── config/
│   └── orbit.php
├── composer.json
└── README.md
```

## ServiceProvider Implementation

```php
<?php

namespace OrbitCore;

use Illuminate\Support\ServiceProvider;

class OrbitCoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/orbit.php', 'orbit');
    }

    public function boot(): void
    {
        // Routes
        $this->loadRoutesFrom(__DIR__.'/../routes/environment.php');

        // Migrations
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // Views (for Blade, if any)
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'orbit');

        // Publishable config
        $this->publishes([
            __DIR__.'/../config/orbit.php' => config_path('orbit.php'),
        ], 'orbit-config');

        // Publishable assets (Vue/CSS compiled)
        $this->publishes([
            __DIR__.'/../dist' => public_path('vendor/orbit'),
        ], 'orbit-assets');
    }
}
```

## Vite Configuration for Package Assets

The consuming apps (orbit-web, orbit-desktop) need to compile Vue/CSS from the package.

### Option A: Compile from vendor

```js
// vite.config.js in orbit-web / orbit-desktop
export default defineConfig({
    resolve: {
        alias: {
            '@orbit': path.resolve(__dirname, 'vendor/orbit-core/resources/js'),
        },
    },
    // Include package resources in build
    build: {
        rollupOptions: {
            input: [
                'resources/js/app.ts',
                'vendor/orbit-core/resources/js/pages/**/*.vue',
            ],
        },
    },
});
```

### Option B: Publish and compile

```bash
# Publish Vue sources to resources/js/vendor/orbit
php artisan vendor:publish --tag=orbit-assets

# Then compile normally
npm run build
```

### Option C: NPM package for frontend (Recommended)

Package the Vue components as a separate NPM package:

```json
// orbit-core/package.json
{
    "name": "@orbit/core",
    "exports": {
        "./pages/*": "./resources/js/pages/*",
        "./components/*": "./resources/js/components/*",
        "./css": "./resources/css/orbit.css"
    }
}
```

Then in consuming apps:
```js
// resources/js/app.ts
import Projects from '@orbit/core/pages/environments/Projects.vue';
```

## Mode Detection

The package needs to know if it's running in web or desktop mode.

### Config-based (Recommended)

```php
// config/orbit.php (in package)
return [
    'mode' => env('ORBIT_MODE', 'web'),  // 'web' or 'desktop'
    'multi_environment' => env('MULTI_ENVIRONMENT_MANAGEMENT', false),
    'api_url' => env('ORBIT_API_URL'),
];
```

### Usage in package code

```php
// In controller
if (config('orbit.multi_environment')) {
    // Desktop mode: use route parameter
    $environment = $request->route('environment');
} else {
    // Web mode: use implicit environment
    $environment = Environment::where('is_local', true)->first();
}
```

```vue
<!-- In Vue component -->
<template>
    <EnvironmentSwitcher v-if="$page.props.multi_environment" />
    <StaticEnvironmentLabel v-else />
</template>
```

## Implementation Phases

### Phase 1: Create Package Skeleton
- [ ] Create `orbit-core` GitHub repository
- [ ] Set up Laravel package structure
- [ ] Create OrbitCoreServiceProvider
- [ ] Set up composer.json with autoloading
- [ ] Set up package.json for frontend assets

### Phase 2: Extract Backend (PHP)
- [ ] Move Models to `src/Models/`
- [ ] Move EnvironmentController to `src/Http/Controllers/`
- [ ] Move OrbitCli services to `src/Services/`
- [ ] Move routes/environment.php to `routes/`
- [ ] Move migrations to `database/migrations/`
- [ ] Move config/orbit.php to `config/`
- [ ] Update namespaces throughout

### Phase 3: Extract Frontend (Vue/CSS)
- [ ] Move Vue pages to `resources/js/pages/`
- [ ] Move shared Vue components to `resources/js/components/`
- [ ] Move shared CSS to `resources/css/`
- [ ] Set up Vite plugin or NPM package structure
- [ ] Update import paths

### Phase 4: Integrate into orbit-web
- [ ] Remove extracted code from orbit-web
- [ ] Add orbit-core as composer dependency
- [ ] Configure Vite to compile package assets
- [ ] Add ImplicitEnvironment middleware (web-specific)
- [ ] Test all pages work correctly
- [ ] Deploy and verify

### Phase 5: Integrate into orbit-desktop
- [ ] Remove duplicated code from orbit-desktop
- [ ] Add orbit-core as composer dependency
- [ ] Configure Vite to compile package assets
- [ ] Keep NativePHP-specific code
- [ ] Keep multi-environment switcher
- [ ] Test all pages work correctly

## File Mapping Reference

### Controllers

| Current Location (orbit-desktop) | New Location (orbit-core) |
|----------------------------------|---------------------------|
| `app/Http/Controllers/EnvironmentController.php` | `src/Http/Controllers/EnvironmentController.php` |

### Models

| Current Location | New Location |
|------------------|--------------|
| `app/Models/Environment.php` | `src/Models/Environment.php` |
| `app/Models/SshKey.php` | `src/Models/SshKey.php` |
| `app/Models/Setting.php` | `src/Models/Setting.php` |
| `app/Models/TemplateFavorite.php` | `src/Models/TemplateFavorite.php` |
| `app/Models/UserPreference.php` | `src/Models/UserPreference.php` |

### Services

| Current Location | New Location |
|------------------|--------------|
| `app/Services/OrbitCli/*.php` | `src/Services/OrbitCli/*.php` |
| `app/Services/DoctorService.php` | `src/Services/DoctorService.php` |
| `app/Services/StatusService.php` | `src/Services/StatusService.php` |

### Vue Pages

| Current Location | New Location |
|------------------|--------------|
| `resources/js/pages/environments/*.vue` | `resources/js/pages/environments/*.vue` |

### Vue Components

| Current Location | New Location |
|------------------|--------------|
| `resources/js/components/EnvironmentSwitcher.vue` | `resources/js/components/EnvironmentSwitcher.vue` |
| `resources/js/components/Heading.vue` | `resources/js/components/Heading.vue` |
| `resources/js/components/Modal.vue` | `resources/js/components/Modal.vue` |
| (other shared components) | (same structure) |

### Routes

| Current Location | New Location |
|------------------|--------------|
| `routes/environment.php` | `routes/environment.php` |

### Migrations

| Current Location | New Location |
|------------------|--------------|
| `database/migrations/*environments*.php` | `database/migrations/` |
| `database/migrations/*ssh_keys*.php` | `database/migrations/` |
| `database/migrations/*settings*.php` | `database/migrations/` |

## Open Questions

1. **Package hosting**: Private GitHub repo with Composer VCS, or publish to Packagist?

2. **Versioning strategy**: Semantic versioning? How to handle breaking changes?

3. **Frontend asset strategy**: 
   - NPM package (@orbit/core)?
   - Compile from vendor path?
   - Publish to resources and compile?

4. **Layout component**: Does `Layout.vue` go in package or stay app-specific?
   - Package could provide a "slot-based" layout
   - Apps provide their own chrome (sidebar, header)

5. **TypeScript types**: How to share interfaces between package and apps?

6. **Testing**: 
   - Unit tests in package?
   - Feature tests in consuming apps?
   - Both?

## Notes

- The `NotificationService` stays in orbit-desktop only (uses NativePHP)
- The `ImplicitEnvironment` middleware stays in orbit-web only (injects environment for flat routes)
- Both apps extend `HandleInertiaRequests` to add their own shared props
- The package's `environment.php` routes should be flexible (prefix configurable)

## Related Files

- Current orbit-desktop: `/Users/nckrtl/Projects/orbit-desktop`
- Current orbit-web (remote): `nckrtl@ai:~/projects/orbit-web`
- This plan: `/Users/nckrtl/Projects/orbit-desktop/docs/orbit-core-extraction-plan.md`
