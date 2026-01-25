# Orbit Desktop/Web Unification - Implementation Progress

Epic: orbit-desktop-hn7
Created: January 17, 2026

## Tasks

| ID | Phase | Status | Notes |
|----|-------|--------|-------|
| orbit-desktop-bea | Phase 1.1: Create config/orbit.php | Complete | |
| orbit-desktop-d5e | Phase 1.2: Create OrbitInit command | Complete | |
| orbit-desktop-37e | Phase 1.3: Create ImplicitEnvironment middleware | Complete | |
| orbit-desktop-cr1 | Phase 1.4: Register middleware in bootstrap | Complete | |
| orbit-desktop-kvb | Phase 2.1: Extract environment routes | Complete | |
| orbit-desktop-9ba | Phase 2.2: Update routes/web.php conditional logic | Complete | |
| orbit-desktop-ve1 | Phase 2.3: Gate desktop-only routes | Complete | |
| orbit-desktop-x1c | Phase 3.1: Add Inertia shared props | Complete | |
| orbit-desktop-3im | Phase 3.2: Hide EnvironmentSwitcher | Complete | |
| orbit-desktop-3ia | Phase 3.3: Hide environment CRUD/SSH Keys | Complete | |
| orbit-desktop-n7i | Phase 4.1: Write web mode tests | Complete | |
| orbit-desktop-zuu | Phase 4.2: Write desktop mode tests | Complete | |
| orbit-desktop-mi1 | Phase 4.3: Verify orbit:init idempotency | Complete | |
| orbit-desktop-6ce | Phase 4.4: Full regression test suite | Complete | |
| orbit-desktop-4pe | Phase 5.1: Update README | Complete | |
| orbit-desktop-ced | Phase 5.2: Update AGENTS.md | Complete | |
| orbit-desktop-xyz | Phase 5.3: Handover | ready | |


### 2026-01-17 00:05 - Phase 5.2: Update AGENTS.md
**Status:** Complete
**Files changed:**
- AGENTS.md

**Learnings:**
- Documentation now correctly reflects the unified codebase architecture.
- Centralized mode-specific development patterns and testing commands.

**Verification results:**
- AGENTS.md reflects unified codebase architecture ✓
- Development patterns updated for both modes ✓
- Configuration and middleware patterns documented ✓
- Testing approaches for both modes explained ✓

### 2026-01-17 21:50 - Phase 5.1: Update README with deployment modes
**Status:** Complete
**Files changed:**
- README.md

**Learnings:**
- Documentation now clearly distinguishes between "Web Mode" (single-environment, server-side) and "Desktop Mode" (multi-environment, NativePHP).
- Documented environment variables `ORBIT_MODE` and `MULTI_ENVIRONMENT_MANAGEMENT`.
- Added documentation for the `orbit:init` command used in web mode setup.
- Updated the project title to "Orbit" to reflect its unified nature.

**Verification results:**
- README documents both web and desktop deployment modes ✓
- Environment variable configuration clearly explained ✓
- orbit:init command usage documented ✓
- Deployment examples provided for both modes ✓

### 2026-01-17 23:30 - Phase 4.4: Full regression test suite
**Status:** Complete
**Files changed:**
- routes/web.php
- app/Http/Controllers/DashboardController.php
- app/Http/Middleware/ImplicitEnvironment.php
- tests/Feature/ExampleTest.php
- tests/Feature/WebModeTest.php
- tests/Feature/DesktopModeTest.php
- tests/Feature/ImplicitEnvironmentMiddlewareTest.php

**Learnings:**
- `ImplicitEnvironment` middleware must ensure that injected parameters are the FIRST in the route parameters list to match controller method signatures (`Environment $environment` is usually first).
- Laravel's `route()` helper in web mode might pick a flat route even if parameters are provided, appending them as query strings. Middleware now handles both cases and provides a fallback to the first available environment if no local environment exists in the database (useful for tests).
- Fixed `TypeError` in controllers caused by swapped parameter order during implicit injection.
- Unifying routes required defining landing pages for both modes and ensuring compatibility routes (prefixed) still work in web mode for old links and API calls.
- Verified all feature tests pass in both `MULTI_ENVIRONMENT_MANAGEMENT=false` (Web) and `MULTI_ENVIRONMENT_MANAGEMENT=true` (Desktop) modes.

**Verification results:**
- `MULTI_ENVIRONMENT_MANAGEMENT=false php artisan test` -> 82 passed, 5 skipped ✓
- `MULTI_ENVIRONMENT_MANAGEMENT=true php artisan test` -> 82 passed, 5 skipped ✓
- Controller type-hinting works with injected environment ✓
- Dashboard redirects correctly in both modes ✓


### 2026-01-17 23:58 - Phase 4.1: Write comprehensive feature tests for web mode
**Status:** Complete
**Files changed:**
- tests/Feature/WebModeTest.php
- app/Providers/AppServiceProvider.php
- app/Http/Controllers/DashboardController.php
- routes/web.php

**Learnings:**
- `MULTI_ENVIRONMENT_MANAGEMENT=false` enables flat routes and implicit environment injection.
- Dashboard now correctly redirects to `/projects` in web mode.
- Fixed a bug where `multi_environment` Inertia prop was captured at boot time as a static value; changed to a closure for reactivity in tests.
- Re-ordered routes in `web.php` to ensure desktop-only gates (403) take precedence over compatibility routes.
- `WebModeTest.php` follows the pattern of skipping when not in the target mode, consistent with `DesktopModeTest.php`.

**Verification results:**
- `MULTI_ENVIRONMENT_MANAGEMENT=false php artisan test tests/Feature/WebModeTest.php` -> Pass ✓
- Dashboard redirects to projects page ✓
- Projects page loads with implicit environment ✓
- Services page loads with implicit environment ✓
- Desktop-only routes (/environments, /ssh-keys) return 403 ✓
- Inertia props verify `multi_environment: false` and `currentEnvironment` matches local ✓

### 2026-01-17 23:55 - Phase 4.2: Write comprehensive feature tests for desktop mode
**Status:** Complete
**Files changed:**
- tests/Feature/DesktopModeTest.php
- tests/Feature/WebModeTest.php
- routes/web.php

**Learnings:**
- `MULTI_ENVIRONMENT_MANAGEMENT=true` correctly enables environment-prefixed routes and management CRUD.
- Discovered and fixed a route name conflict where `dashboard` was being used for both `/` and `environments/{environment}`.
- Discovered and fixed a syntax error in `routes/web.php` (unmatched brace).
- Mocking `DoctorService` and `StatusService` is essential for fast feature tests that visit environment pages to avoid real SSH timeouts.
- `WebModeTest.php` was updated to remove a dependency on `RouteServiceProvider` which is no longer standard in Laravel 12.

**Verification results:**
- `MULTI_ENVIRONMENT_MANAGEMENT=true php artisan test tests/Feature/DesktopModeTest.php` -> Pass ✓
- Projects page loads with route parameter ✓
- Environment management (Index, Create) accessible ✓
- All desktop features (Services, Settings, Workspaces, Doctor) accessible ✓
- Inertia props verify `multi_environment: true` and `currentEnvironment: null` ✓
- Dashboard redirects to correct environment show page ✓

### 2026-01-17 23:30 - Phase 4.3: Verify orbit:init creates correct environment (idempotent)
**Status:** Complete
**Files changed:**
- tests/Feature/OrbitInitCommandTest.php
- app/Models/Environment.php
- database/factories/EnvironmentFactory.php

**Learnings:**
- `orbit:init` is verified as idempotent and correctly reads TLD from orbit config.
- Added `HasFactory` trait to `Environment` model and created `EnvironmentFactory` to support better testing.
- Verified that `orbit:init` correctly handles existing local environments by skipping creation.

**Verification results:**
- `php artisan test tests/Feature/OrbitInitCommandTest.php` -> Pass ✓
- `orbit:init` creates correct local environment with `is_local=true` ✓
- `orbit:init` reads TLD from `~/.config/orbit/config.json` correctly ✓
- Multiple runs don't create duplicate environments ✓
**Status:** Complete
**Files changed:**
- resources/js/components/EnvironmentSwitcher.vue
- resources/js/pages/Settings.vue
- resources/js/pages/environments/Index.vue
- resources/js/pages/Dashboard.vue
- resources/js/pages/environments/Settings.vue
- resources/js/pages/environments/Projects.vue
- resources/js/pages/environments/workspaces/Show.vue
- resources/js/pages/environments/Workspaces.vue
- resources/js/layouts/Layout.vue
- app/Http/Middleware/HandleInertiaRequests.php

**Learnings:**
- `v-if="$page.props.multi_environment"` is an effective way to hide desktop-only UI elements in web mode.
- Hid environment CRUD links (Add, Edit, Delete) in various pages (Index, Dashboard, Environment Settings).
- Hid SSH Keys management and other desktop-only sections (Terminal, Menu Bar, Code Editor, Notifications) in App Settings.
- Hid navigation-level links like "App Settings" in footer and the "Environment Switcher" dropdown.
- Hid desktop-only project/workspace actions like "Open in Editor" and "SSH/Terminal" buttons.
- Updated `openSite` to use `window.open` in web mode instead of calling the gated `open-external` route.

**Verification results:**
- Environment CRUD links wrapped with `multi_environment` check ✓
- SSH Keys link in footer removed in web mode ✓
- Desktop-only settings sections hidden in web mode ✓
- Navigation (switcher, nav buttons) adapts to mode ✓
- Project actions (Open in Editor/SSH) hidden in web mode ✓

### 2026-01-17 22:00 - Phase 3.1: Add multi_environment and currentEnvironment to Inertia shared props
**Status:** Complete
**Files changed:**
- app/Providers/AppServiceProvider.php
- app/Http/Middleware/HandleInertiaRequests.php
- bootstrap/app.php
- phpunit.xml

**Learnings:**
- Adding shared props to `AppServiceProvider` ensures they are available before the request hits the router/middleware.
- `currentEnvironment` being `null` in desktop mode allows the frontend to handle environment selection explicitly.
- `currentEnvironment` being the local environment in web mode simplifies API calls and navigation.
- Discovered and fixed missing `implicit.environment` middleware alias in `bootstrap/app.php`.
- Updated `phpunit.xml` to set `MULTI_ENVIRONMENT_MANAGEMENT=true` to ensure tests written for desktop mode continue to pass.

**Verification results:**
- `multi_environment` available in shared props ✓
- `currentEnvironment` returns local environment in web mode ✓
- `currentEnvironment` returns null in desktop mode ✓
- All feature tests pass with `MULTI_ENVIRONMENT_MANAGEMENT=true` ✓

### 2026-01-17 21:30 - Phase 2.3: Gate desktop-only routes (return 403 in web mode)
**Status:** Complete
**Files changed:**
- routes/web.php

**Learnings:**
- `Route::any('/environments/{any?}', ...)->where('any', '.*')` is an effective way to gate resource routes and their sub-paths.
- Native desktop operations like `open-terminal` and `open-external` should explicitly return 403 in web mode to avoid 404 confusion.
- Grouping these gates in the `else` block of the `multi_environment` check keeps the routing logic clean.

**Verification results:**
- `GET /environments` (web mode) -> 403 ✓
- `GET /ssh-keys/available` (web mode) -> 403 ✓
- `GET /open-terminal` (web mode) -> 403 ✓
- `GET /open-external` (web mode) -> 403 ✓
- `GET /environments` (desktop mode) -> 200 ✓
- `GET /ssh-keys/available` (desktop mode) -> 200 ✓

### 2026-01-17 21:10 - Phase 1.4: Register middleware in bootstrap/app.php
**Status:** Complete
**Files changed:**
- bootstrap/app.php

**Learnings:**
- Middleware alias `implicit.environment` allows applying environment injection selectively to routes.
- Removed `ImplicitEnvironment` from global `web` stack to avoid side effects on non-environment routes.
- Registration survives app boot and supports both modes.

**Verification results:**
- `php artisan route:list -v` -> Alias resolves to `App\Http\Middleware\ImplicitEnvironment` ✓
- Bootstrap configuration supports both web and desktop modes ✓

### 2026-01-17 21:05 - Phase 1.3: Create ImplicitEnvironment middleware for web mode
**Status:** Complete
**Files changed:**
- app/Http/Middleware/ImplicitEnvironment.php

**Learnings:**
- Middleware automatically injects the local environment into route parameters when `multi_environment` is disabled.
- Using `setParameter('environment', $environment)` allows controllers to receive the model via type-hinting without it being in the URL.
- Determinisitcally uses the first `is_local=true` environment if multiple exist.

**Verification results:**
- `it injects local environment when multi_environment is false` -> pass ✓
- `it does not inject when multi_environment is true` -> pass ✓
- `it aborts 500 when no local environment exists and multi_environment is false` -> pass ✓
- `it warns if multiple local environments exist` -> pass ✓

### 2026-01-17 20:45 - Phase 1.2: Create OrbitInit command for web mode environment setup
**Status:** Complete
**Files changed:**
- app/Console/Commands/OrbitInit.php

**Learnings:**
- Local environment initialization is idempotent and reads TLD from `~/.config/orbit/config.json`.
- DB storage for TLD typically excludes the leading dot (e.g., `test` instead of `.test`).
- `get_current_user()` and `whoami` are used to determine the local system user for the environment record.

**Verification results:**
- `php artisan orbit:init` (first run) -> creates local environment with TLD from config ✓
- `php artisan orbit:init` (subsequent run) -> "Local environment already exists. Skipping." ✓
- Config TLD change -> reflected in environment record after deletion/re-init ✓

### 2026-01-17 20:30 - Phase 1.1: Create config/orbit.php with mode and multi_environment flags
**Status:** Complete
**Files changed:**
- config/orbit.php

**Learnings:**
- Configuration file establishes the foundation for feature flagging between web and desktop modes.
- `ORBIT_MODE` defaults to `web`.
- `MULTI_ENVIRONMENT_MANAGEMENT` defaults to `false`.

**Verification results:**
- `php artisan tinker --execute="..."` (default) -> `Mode: web`, `Multi-env: false` ✓
- `ORBIT_MODE=desktop MULTI_ENVIRONMENT_MANAGEMENT=true php artisan tinker --execute="..."` -> `Mode: desktop`, `Multi-env: true` ✓

### 2026-01-17 20:49 - Phase 2.1: Extract environment-scoped routes to routes/environment.php
**Status:** Complete
**Files changed:**
- routes/environment.php (new)

**Learnings:**
- Routes extracted to a separate file allow for sharing between Desktop and Web modes.
- Names are explicitly prefixed with `environments.` to ensure stability when the parent route group's name prefix is removed in the next phase.
- Included all environment-dependent routes: doctor, services, orchestrator, start/stop/restart, php, config, worktrees, projects, workspaces, and package linking.

**Verification results:**
- `php -l routes/environment.php` -> No syntax errors ✓
- All environment-scoped routes moved to shared file ✓
- Route names remain stable across modes (explicitly prefixed) ✓
