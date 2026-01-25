# Split orbit-core into orbit-core + orbit-ui

## Overview
Split the monolithic orbit-core package into two packages: orbit-core (PHP business logic only) and orbit-ui (Inertia/Vue web interface). This resolves the Laravel Zero PHAR build conflicts caused by Inertia pulling in laravel/framework.

## Complexity Assessment
- Files: 200+ | Modules: service providers, routing, assets, migrations | Risk: High
- External deps: Composer, npm, GitHub API, packagist
- Breaking changes: Yes — all consumers must update dependencies and service provider references

## Namespace Decision
- **orbit-core**: `HardImpact\Orbit\Core\` (e.g., `HardImpact\Orbit\Core\Models\Project`)
- **orbit-ui**: `HardImpact\Orbit\Ui\` (e.g., `HardImpact\Orbit\Ui\Http\Controllers\ProjectController`)

## Release Strategy
- Single user (no backward compatibility needed)
- orbit-core always releases first (orbit-ui depends on it)
- Manual coordinated releases for now, CI automation later

## Research Findings

### Codebase Patterns
- **Conditional UI loading**: `src/OrbitServiceProvider.php:28-33` — Already checks `hasInertia()` but not enough
- **Clean separation exists**: Services, Models, Jobs have zero UI dependencies — Good foundation
- **Controllers 100% UI-coupled**: Every controller returns Inertia responses — Must be completely separated

### Best Practices
- **Laravel prefers monolithic packages**: [Horizon pattern](https://github.com/laravel/horizon) — We're going against this for technical necessity
- **Spatie PackageServiceProvider**: [Reduces boilerplate](https://github.com/spatie/laravel-package-tools) — Should use in both packages
- **Asset publishing complexity**: [Laravel docs](https://laravel.com/docs/11.x/packages#public-assets) — Will be more complex with two packages

### Git History Insights
- **orbit-core was extracted from orbit-desktop**: Commit 88b5a5b (2026-01-18) — Massive 23k line extraction worked
- **PHAR builds repeatedly failed**: Multiple attempts to fix service provider issues — Core problem we're solving
- **Cross-package refactoring is painful**: sites→projects rename required 3 synchronized commits — Future concern

## Concerns & Resolutions
| Concern | Resolution |
|---------|------------|
| Laravel best practice is monolithic packages | Accept technical debt for clean architectural separation |
| Asset publishing already fragile | Create automated publishing in CI/CD |
| Version coordination between packages | Use composer constraints: orbit-ui requires orbit-core ^0.1 |
| PHAR might still fail after split | Test immediately after Phase 1 before continuing |
| Controllers can't be shared between packages | Create API controllers in orbit-core if needed by CLI |

## Affected Files

### orbit-core (remove/modify)
- src/Http/Controllers/* (remove)
- src/Http/Middleware/* (remove)  
- routes/* (remove)
- resources/* (remove)
- public/* (remove)
- package.json, vite.config.ts, tsconfig.json, tailwind.config.js (remove)
- src/OrbitServiceProvider.php (modify → src/CoreServiceProvider.php)
- src/**/*.php (modify - namespace HardImpact\Orbit\ → HardImpact\Orbit\Core\)
- composer.json (modify - remove inertia, mcp, update autoload namespace)

### orbit-ui (create)
- All removed files from orbit-core
- src/UiServiceProvider.php (create)
- src/Http/**/*.php (modify - namespace HardImpact\Orbit\Ui\)
- composer.json (create - require orbit-core, inertia, mcp)
- README.md, LICENSE (create)

### Consumers (modify)
- orbit-web: composer.json, routes/web.php, .env example
- orbit-desktop: composer.json, bootstrap files
- orbit-cli: composer.json, update all imports from HardImpact\Orbit\ → HardImpact\Orbit\Core\

## Phase 1: Create orbit-ui Repository & Structure

### Tasks
- [ ] Create GitHub repository hardimpactdev/orbit-ui
- [ ] Clone orbit-core as base: `git clone orbit-core orbit-ui && cd orbit-ui && rm -rf .git && git init`
- [ ] Create composer.json with proper dependencies and `HardImpact\\Orbit\\Ui\\` autoload
- [ ] Remove non-UI files: Models/, Services/, Data/, Enums/, Jobs/, Contracts/, Console/, Events/, migrations/, config/
- [ ] Rename OrbitServiceProvider to UiServiceProvider
- [ ] Update all namespaces from `HardImpact\Orbit\` to `HardImpact\Orbit\Ui\`
- [ ] Update all imports of Models/Services to use `HardImpact\Orbit\Core\` namespace
- [ ] Create README.md explaining package purpose
- [ ] Push to GitHub

### Files
- composer.json (create)
- src/UiServiceProvider.php (create from OrbitServiceProvider)  
- src/Http/Controllers/* (modify namespaces)
- src/Http/Middleware/* (modify namespaces)
- routes/*.php (modify controller references)

### Verification
- [ ] Repository exists and is accessible
- [ ] composer.json valid: `composer validate`
- [ ] All classes use `HardImpact\Orbit\Ui\` namespace: `grep -r "namespace HardImpact\\\\Orbit\\\\Ui" src/`
- [ ] All Model/Service imports reference Core: `grep -r "use HardImpact\\\\Orbit\\\\Core" src/`

### Dependencies
- Blocked by: none
- Blocks: Phase 3

## Phase 2: Clean orbit-core Package & Update Namespace

### Tasks
- [ ] Remove all Http/, routes/, resources/, public/ directories
- [ ] Remove package.json, vite.config.ts, tsconfig.json, tailwind.config.js, postcss.config.js
- [ ] Rename OrbitServiceProvider.php to CoreServiceProvider.php
- [ ] Update ALL namespaces from `HardImpact\Orbit\` to `HardImpact\Orbit\Core\`
- [ ] Update composer.json autoload: `"HardImpact\\Orbit\\Core\\": "src/"`
- [ ] Update composer.json: remove inertia-laravel, laravel/mcp from require
- [ ] Update extra.laravel.providers to reference `HardImpact\\Orbit\\Core\\CoreServiceProvider`
- [ ] Update service provider to remove all UI registrations (routes, views, middleware, vite)
- [ ] Commit changes: "refactor: extract UI to orbit-ui, rename namespace to Core"

### Files
- src/Http/* (delete)
- routes/* (delete)
- resources/* (delete)
- public/* (delete)
- src/OrbitServiceProvider.php → src/CoreServiceProvider.php (rename & modify)
- src/**/*.php (modify all namespaces)
- database/factories/*.php (modify namespaces)
- composer.json (modify)

### Verification
- [ ] No Inertia references: `grep -r "Inertia" src/`
- [ ] All classes use Core namespace: `grep -r "namespace HardImpact\\\\Orbit\\\\Core" src/`
- [ ] Package installs without laravel/framework: `rm -rf vendor && composer install --no-dev && composer show | grep laravel/framework`
- [ ] Service provider loads in isolation

### Dependencies  
- Blocked by: none (can run parallel with Phase 1)
- Blocks: Phase 4

## Phase 3: Configure orbit-ui Service Provider & Dependencies

### Tasks
- [ ] Update UiServiceProvider to handle all UI registrations
- [ ] Add orbit-core as dependency in composer.json: `"hardimpactdev/orbit-core": "^0.1"`
- [ ] Verify all Controllers import from `HardImpact\Orbit\Core\Models\` etc.
- [ ] Update routes to use `HardImpact\Orbit\Ui\Http\Controllers\` namespace
- [ ] Configure Vite to use correct paths for package context
- [ ] Setup asset publishing from orbit-ui to consuming apps
- [ ] Test in orbit-web with local path repositories for both packages

### Files
- src/UiServiceProvider.php (modify - complete implementation)
- composer.json (modify - add orbit-core dependency)
- routes/web.php (modify - controller namespaces)
- vite.config.ts (verify paths)

### Verification
- [ ] Service provider registers routes: Check `php artisan route:list` in orbit-web
- [ ] Assets compile: `bun run build`
- [ ] Publishable assets configured: `php artisan vendor:publish --tag=orbit-assets`
- [ ] orbit-web dashboard loads correctly

### Dependencies
- Blocked by: Phase 1, Phase 2 completion  
- Blocks: Phase 5

## Phase 4: Test PHAR Build & Release orbit-core

### Tasks
- [ ] In orbit-cli: Update composer.json to use local orbit-core path
- [ ] Update ALL orbit-cli imports from `HardImpact\Orbit\` to `HardImpact\Orbit\Core\`
- [ ] Run full PHAR build: `composer install --no-dev && ./build-phar.sh`
- [ ] Verify PHAR executes without class resolution errors: `php builds/orbit.phar --version`
- [ ] Test key commands: project:create, project:list, project:delete
- [ ] If successful: Tag orbit-core v0.1.0 and push
- [ ] Create GitHub release for orbit-core

### Files
- orbit-cli/composer.json (update orbit-core constraint)
- orbit-cli/app/**/*.php (update all HardImpact\Orbit imports to HardImpact\Orbit\Core)
- orbit-core: Tag v0.1.0

### Verification
- [ ] PHAR builds without errors
- [ ] No "ambiguous class resolution" warnings  
- [ ] No "Class not found" errors when running PHAR
- [ ] `php builds/orbit.phar project:list` works
- [ ] orbit-core v0.1.0 available on packagist

### Dependencies
- Blocked by: Phase 2 completion
- Blocks: Phase 5, 6

## Phase 5: Update Consumers & Release orbit-ui

### Tasks  
- [ ] orbit-web: Update composer.json to require `hardimpactdev/orbit-ui` instead of orbit-core
- [ ] orbit-web: Update routes/web.php to use `\HardImpact\Orbit\Ui\UiServiceProvider::routes()`
- [ ] orbit-web: Run composer update and verify app works (HMR, forms, etc)
- [ ] orbit-desktop: Same updates as orbit-web (if applicable)
- [ ] Tag orbit-ui v0.1.0 and create GitHub release

### Files
- orbit-web/composer.json (modify - require orbit-ui instead of orbit-core)
- orbit-web/routes/web.php (modify - UiServiceProvider::routes())

### Verification
- [ ] orbit-web loads dashboard correctly at orbit-web.ccc
- [ ] Vite HMR works in development
- [ ] Project creation form submits successfully  
- [ ] No console errors
- [ ] orbit-ui v0.1.0 available on packagist

### Dependencies
- Blocked by: Phase 3, 4 completion
- Blocks: Phase 6

## Phase 6: Final Testing & orbit-cli Release

### Tasks
- [ ] orbit-cli: Update composer.json to require `hardimpactdev/orbit-core:^0.1.0` 
- [ ] Run full test suite: `./vendor/bin/pest`
- [ ] Build final PHAR: `composer install --no-dev && ./build-phar.sh`
- [ ] Test upgrade command with new PHAR
- [ ] Create orbit-cli release (follow normal release process)
- [ ] Test user upgrade path: `orbit upgrade`
- [ ] Update documentation about new package structure

### Files
- orbit-cli/composer.json (modify)
- orbit-cli: New release tag

### Verification
- [ ] All tests pass
- [ ] PHAR upgrades successfully
- [ ] Users can run `orbit upgrade` without errors
- [ ] All three packages have stable v0.1.0 releases

### Dependencies
- Blocked by: Phase 4, 5 completion
- Blocks: none

## Decisions Made
1. **Namespaces**: `HardImpact\Orbit\Core\` and `HardImpact\Orbit\Ui\`
2. **Cross-package refactoring**: Use workspace with symlinks, Claude Code handles multiple packages
3. **Release automation**: Manual for now, orbit-core always releases first
4. **Backward compatibility**: Not needed (single user)