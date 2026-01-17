# PRD: Orbit Desktop/Web Unification

Generated: January 17, 2026 (Updated with DoD)
Feature: orbit-unification

## Summary

Merge orbit-desktop and orbit-web into a single codebase that deploys as either a multi-environment desktop app or single-environment web app based on configuration. This eliminates code duplication while preserving distinct user experiences through explicit feature flags and minimal refactoring.

## Goals

- Single codebase for both desktop and web deployments
- Web mode: single local environment, flat routes, no environment UI
- Desktop mode: multiple environments, prefixed routes, environment switcher
- Zero regression in existing functionality
- Minimal refactoring - keep existing service/controller signatures

## Non-Goals

- Changing the underlying architecture (PHP-FPM, Caddy, CLI communication)
- Modifying the orbit-cli interface or commands
- Altering the NativePHP integration for desktop mode
- Refactoring service method signatures or controller parameters

## User Stories

- As a **web deployer**, I want to deploy orbit as a single-environment web app without environment management UI
- As a **desktop user**, I want to continue using the multi-environment desktop app with full functionality
- As a **developer**, I want to maintain one codebase with minimal changes to existing patterns
- As a **system administrator**, I want clear deployment modes controlled by environment variables

## Technical Approach

### Architecture

The unified codebase uses explicit feature flags and middleware injection:

```
orbit (unified codebase)
├── ORBIT_MODE=web + MULTI_ENVIRONMENT_MANAGEMENT=false
│   ├── Single Environment (is_local=true, auto-created via orbit:init)
│   ├── Flat routes: /projects, /services, /workspaces
│   ├── Middleware injects environment into route parameters
│   ├── No environment switcher UI
│   ├── No NativePHP dependency (plain Laravel Craft starter kit)
│   └── Desktop-only routes return 403
│
└── ORBIT_MODE=desktop + MULTI_ENVIRONMENT_MANAGEMENT=true
    ├── Multiple Environments (managed via UI)
    ├── Prefixed routes: /environments/{id}/projects
    ├── Route parameters provide environment naturally
    ├── Environment switcher visible
    └── NativePHP integration for native OS features
```

### Key Components

1. **Configuration System**: `config/orbit.php` with explicit feature flags
2. **Middleware Injection**: ImplicitEnvironment middleware injects environment into route parameters
3. **Conditional Routing**: Mode-specific route registration with shared route file
4. **Frontend Feature Flags**: UI components conditionally rendered based on `multi_environment` flag
5. **Preserved Signatures**: All existing service/controller method signatures unchanged
6. **Desktop-only Gate**: Middleware/controller returns 403 for desktop-only features in web mode

### Data Flow

**Web Mode:**
```
Request → ImplicitEnvironment middleware → Inject environment into route → Controller(Environment $environment) → Service(Environment $environment)
```

**Desktop Mode:**
```
Request → Route parameter {environment} → Controller(Environment $environment) → Service(Environment $environment)
```

## Implementation Phases

### Phase 1: Infrastructure (4 tasks)

**Objective:** Establish configuration system and middleware foundation

**Tasks:**
- [ ] Create `config/orbit.php` with mode and multi_environment flags
- [ ] Create `OrbitInit` command for web mode environment setup (idempotent)
- [ ] Create `ImplicitEnvironment` middleware for web mode
- [ ] Register middleware in `bootstrap/app.php`

**Affected Files:**
- `config/orbit.php` - New configuration file
- `app/Console/Commands/OrbitInit.php` - New command
- `app/Http/Middleware/ImplicitEnvironment.php` - New middleware
- `bootstrap/app.php` - Register middleware alias

**Dependencies:** None

### Phase 2: Route Restructuring (3 tasks)

**Objective:** Separate environment-scoped routes and implement conditional routing

**Tasks:**
- [ ] Extract environment-scoped routes to `routes/environment.php`
- [ ] Update `routes/web.php` with conditional logic
- [ ] Gate desktop-only routes (return 403 in web mode)

**Affected Files:**
- `routes/environment.php` - New shared route file
- `routes/web.php` - Conditional routing logic

**Dependencies:** Phase 1

### Phase 3: Frontend Updates (3 tasks)

**Objective:** Conditionally render UI components based on multi_environment flag

**Tasks:**
- [ ] Add `multi_environment` and `currentEnvironment` to Inertia shared props
- [ ] Conditionally hide EnvironmentSwitcher in Layout.vue
- [ ] Conditionally hide environment CRUD pages/links and SSH Keys

**Affected Files:**
- `app/Providers/AppServiceProvider.php` - Inertia shared props
- `resources/js/layouts/Layout.vue` - Conditional environment UI
- `resources/js/pages/environments/Index.vue` - Desktop-only
- `resources/js/pages/environments/Create.vue` - Desktop-only
- `resources/js/pages/environments/Edit.vue` - Desktop-only

**Dependencies:** Phase 2

### Phase 4: Testing & Validation (4 tasks)

**Objective:** Ensure both modes work correctly without regressions

**Tasks:**
- [ ] Write feature tests for web mode (MULTI_ENVIRONMENT_MANAGEMENT=false)
- [ ] Write feature tests for desktop mode (MULTI_ENVIRONMENT_MANAGEMENT=true)
- [ ] Verify `orbit:init` creates correct environment (idempotent)
- [ ] Full regression test suite

**Affected Files:**
- `tests/Feature/WebModeTest.php` - New test file
- `tests/Feature/DesktopModeTest.php` - New test file

**Dependencies:** Phase 3

### Phase 5: Documentation (2 tasks)

**Objective:** Document unified codebase deployment modes

**Tasks:**
- [ ] Update README with deployment modes
- [ ] Update AGENTS.md

**Affected Files:**
- `README.md` - Deployment instructions
- `AGENTS.md` - Updated development patterns

**Dependencies:** Phase 4

## Resolved Decisions (DoD)

### Route Names
- **Decision:** Keep route names stable across modes (e.g., `environments.projects`)
- **Rationale:** Minimizes refactoring, existing code continues to work

### API Endpoints
- **Decision:** Keep `/api/environments/{id}/...` format in both modes
- **Rationale:** Simpler implementation, frontend receives environment ID via Inertia shared prop

### Missing Environment Behavior
- **Decision:** Hard requirement - `orbit:init` must run before web mode works
- **Rationale:** orbit-web will be bundled with orbit-cli in future; no fallback UI needed

### Desktop-Only Features (Hidden in Web Mode)
| Feature | Action |
|---------|--------|
| SSH Keys management | Hide (403 if accessed) |
| open-terminal | Hide (403 if accessed) |
| open-external | Hide (403 if accessed) |
| Environment CRUD | Hide (403 if accessed) |
| Environment Switcher | Hide (UI only) |
| Provisioning | **Keep** - needed for new projects |
| DNS Resolver | **Keep** - needed for orbit.<tld> resolution |

### NativePHP Handling
- **Decision:** NativePHP is not installed in web mode at all
- **Rationale:** orbit-web is a plain Laravel Craft starter kit; no NativePHP package dependency
- **Implementation:** Conditional require in composer.json OR separate deployment without package

### orbit:init Command
- **Decision:** Idempotent - skip if environment already exists
- **Rationale:** Safe to run multiple times during deployment

### Multiple is_local Environments
- **Decision:** Log warning, pick first (oldest) deterministically
- **Rationale:** Provides consistent behavior while alerting to data issue

### Frontend Environment ID
- **Decision:** Inertia shared prop `currentEnvironment`
- **Rationale:** Available globally to all pages for API calls

### Verification Testing
- **Decision:** Feature tests per mode
- **Rationale:** Automated coverage for dashboard, projects, services in both modes

### Authentication
- **Decision:** No auth for now
- **Rationale:** Still under heavy development; orbit-web only accessible via SSH tunnel

## Edge Cases

- **Missing TLD in web mode**: `orbit:init` reads TLD from `~/.config/orbit/config.json`, falls back to `.test`
- **Multiple local environments**: Log warning, use first `is_local=true` environment
- **Route conflicts**: Environment-scoped routes use same names, different paths per mode
- **API compatibility**: Both modes use `/api/environments/{id}/...`, web mode gets ID from shared prop
- **Database migrations**: Same schema works for both modes, web mode just has one environment record
- **NativePHP loading**: Only present when `ORBIT_MODE=desktop`; web mode has no NativePHP dependency

## Testing Strategy

- **Feature tests per mode**: Controllers work in both modes with appropriate middleware/routing
- **Unit tests**: Service layer continues to work with Environment parameters
- **Integration tests**: Full request lifecycle in both web and desktop modes
- **E2E tests**: Frontend components render correctly based on multi_environment flag

## Success Criteria

1. **Single codebase deploys as both applications** - No code duplication between modes
2. **Web mode functions identically to current orbit-web** - Single environment, flat routes, no env UI
3. **Desktop mode maintains full functionality** - Multi-environment support, NativePHP integration
4. **Zero breaking changes** - All existing service/controller signatures preserved
5. **Clear mode separation** - Desktop-only features return 403 in web mode
6. **Deployment simplicity** - Mode determined by environment variables
7. **Minimal refactoring** - Existing patterns and signatures unchanged

## Implementation Notes

### Configuration Structure

```php
// config/orbit.php
return [
    'mode' => env('ORBIT_MODE', 'web'),
    'multi_environment' => env('MULTI_ENVIRONMENT_MANAGEMENT', false),
];
```

### Environment Variables

```env
# orbit-web
ORBIT_MODE=web
MULTI_ENVIRONMENT_MANAGEMENT=false

# orbit-desktop
ORBIT_MODE=desktop
MULTI_ENVIRONMENT_MANAGEMENT=true
```

### Middleware Injection

```php
// ImplicitEnvironment middleware (web mode only)
public function handle($request, Closure $next)
{
    // Only active in web mode
    if (config('orbit.multi_environment')) {
        return $next($request);
    }

    $environment = Environment::where('is_local', true)->first();
    
    if (!$environment) {
        abort(500, 'No local environment found. Run: php artisan orbit:init');
    }
    
    // Warn if multiple is_local environments exist
    $count = Environment::where('is_local', true)->count();
    if ($count > 1) {
        Log::warning("Multiple is_local=true environments found ({$count}). Using first.");
    }
    
    // Inject into route so controllers receive it as parameter
    $request->route()->setParameter('environment', $environment);
    
    return $next($request);
}
```

### Route Structure

```php
// routes/web.php
if (config('orbit.multi_environment')) {
    // Desktop: Environment management + prefixed routes
    Route::resource('environments', EnvironmentController::class);
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    
    // Desktop-only routes
    Route::post('/open-terminal', ...);
    Route::post('/open-external', ...);
    Route::resource('ssh-keys', SshKeyController::class);
    
    Route::prefix('environments/{environment}')
        ->group(base_path('routes/environment.php'));
} else {
    // Web: Flat routes, middleware injects implicit environment
    Route::middleware('implicit.environment')
        ->group(base_path('routes/environment.php'));
    
    // Desktop-only routes return 403 in web mode
    Route::any('/environments/{any?}', fn() => abort(403))->where('any', '.*');
    Route::any('/ssh-keys/{any?}', fn() => abort(403))->where('any', '.*');
}
```

```php
// routes/environment.php (shared - controllers receive Environment from route/middleware)
Route::get('/', [DashboardController::class, 'show'])->name('environment.dashboard');
Route::get('projects', [ProjectController::class, 'index'])->name('projects.index');
// ... all environment-scoped routes
// Controllers use: public function index(Environment $environment)
```

### Inertia Shared Props

```php
// AppServiceProvider::boot()
Inertia::share([
    'multi_environment' => config('orbit.multi_environment'),
    'currentEnvironment' => fn () => config('orbit.multi_environment') 
        ? null 
        : Environment::where('is_local', true)->first(),
]);
```

### Deployment Examples

**orbit-web:**
```bash
ORBIT_MODE=web
MULTI_ENVIRONMENT_MANAGEMENT=false

composer install && npm install  # No NativePHP, use npm for Craft
php artisan migrate
php artisan orbit:init
npm run build
```

**orbit-desktop:**
```bash
ORBIT_MODE=desktop
MULTI_ENVIRONMENT_MANAGEMENT=true

# Standard NativePHP build
```

## Verification Criteria

### Phase 1 Verification
- [ ] `config('orbit.mode')` returns correct value based on env
- [ ] `config('orbit.multi_environment')` returns correct boolean
- [ ] `php artisan orbit:init` creates local environment with TLD from orbit config
- [ ] `php artisan orbit:init` is idempotent (running twice doesn't error or duplicate)
- [ ] ImplicitEnvironment middleware injects environment into route parameter

### Phase 2 Verification
- [ ] Web mode: routes are flat (e.g., `/projects`)
- [ ] Desktop mode: routes are prefixed (e.g., `/environments/1/projects`)
- [ ] Route names remain stable across modes (e.g., `route('projects.index')` works in both)
- [ ] Controllers receive Environment instance in both modes
- [ ] Desktop-only routes return 403 in web mode

### Phase 3 Verification
- [ ] Web mode: no environment switcher visible in UI
- [ ] Desktop mode: environment switcher visible in UI
- [ ] Web mode: no environment CRUD links visible
- [ ] Web mode: no SSH Keys link visible
- [ ] `currentEnvironment` Inertia prop available in web mode for API calls

### Phase 4 Verification
- [ ] Feature test: web mode dashboard loads with implicit environment
- [ ] Feature test: web mode projects page works
- [ ] Feature test: desktop mode projects page works with route parameter
- [ ] Feature test: desktop-only routes return 403 in web mode
- [ ] All existing features work in both modes (no regressions)

## Future Considerations

- **orbit-cli bundling**: orbit-web will eventually be bundled with orbit-cli, making `orbit:init` part of CLI installation
- **Authentication**: May add auth layer when orbit-web becomes publicly accessible
- **Multi-tenant**: Current design supports future multi-environment web mode if needed
