# PRD: Migrate orbit-desktop to orbit-web

Generated: January 17, 2026
Feature: orbit-web-migration

## Summary

Migrate the orbit-desktop NativePHP/Electron application to a standard Laravel web application (orbit-web) that serves as a **single-environment GUI dashboard**. orbit-web is deployed as a project at `orbit-web.<tld>` within an orbit environment and calls the existing orbit-cli/web API for all operations. This migration enables faster development iteration by removing desktop app build/packaging overhead while preserving core functionality.

## Goals

- Create single-environment GUI dashboard for orbit environments
- Leverage existing orbit-cli/web API via Saloon HTTP
- Connect to orbit-cli's existing Reverb instance for real-time updates
- Maintain local SQLite for global settings and preferences (no auth)
- Eventually replace the bundled web app inside orbit-cli

## Non-Goals

- Multi-environment management (that's desktop-only)
- Server provisioning functionality (removed)
- Running its own CLI execution (orbit-cli/web handles that)
- Authentication/multi-tenancy (single-user for now)
- Native desktop notifications
- macOS-specific features
- SSH key management (security risk with no auth)
- Filament admin interface (using Inertia/Vue only)
- Pinia state management (unnecessary complexity)

## User Stories

- As a developer, I want to manage my environment through a web interface so that I can access it from any device
- As a developer, I want to control services and projects via the web interface so that I can manage my development environment remotely
- As a developer, I want real-time status updates during provisioning so that I can monitor progress
- As a developer, I want to open projects in my local terminal via SSH URLs so that I can work on remote code locally

## Technical Approach

### Architecture

orbit-web is a **single-environment GUI dashboard** that:
1. Is deployed as a project at `orbit-web.<tld>` within an orbit environment
2. Calls the **existing orbit-cli/web API** at `https://orbit.<tld>/api` via Saloon HTTP (proper HTTPS)
3. Connects to **orbit-cli's existing Reverb** instance for WebSocket (not its own)
4. Has local SQLite for: Settings, Template Favorites, User Preferences (server-global, shared by all visitors)
5. Will eventually **replace the bundled web app** inside orbit-cli

### Architecture Diagram

```
┌─────────────────────────────────────────────────────────────────────────┐
│  User's Browser                                                         │
│       │                                                                 │
│       ▼                                                                 │
│  ┌─────────────────────┐                                                │
│  │   orbit-web         │  ← Vue/Inertia GUI (this project)              │
│  │   (orbit-web.<tld>) │                                                │
│  │   - Settings UI     │                                                │
│  │   - Projects UI     │                                                │
│  │   - Services UI     │                                                │
│  │   - SQLite (prefs)  │                                                │
│  └──────────┬──────────┘                                                │
│             │ Saloon HTTPS calls                                        │
│             ▼                                                           │
│  ┌─────────────────────┐     ┌──────────────┐     ┌──────────────┐      │
│  │   orbit-cli/web     │────▶│ orbit-redis  │◀────│ orbit-horizon│      │
│  │   (orbit.<tld>/api) │     │  (queues)    │     │  (jobs)      │      │
│  │   - API endpoints   │     └──────────────┘     └──────────────┘      │
│  │   - CLI execution   │                                   │            │
│  │   - Reverb WS       │◀──────────────────────────────────┘            │
│  └──────────┬──────────┘     WebSocket broadcasts                       │
│             │ Process::run()                                            │
│             ▼                                                           │
│  ┌─────────────────────┐                                                │
│  │   orbit CLI binary  │                                                │
│  └─────────────────────┘                                                │
└─────────────────────────────────────────────────────────────────────────┘
```

### Key Components

1. **Web Application**: Laravel 12 + Inertia + Vue 3 setup (no Filament)
2. **HTTP Integration**: Saloon HTTP client for orbit-cli/web API (single connector instance)
3. **WebSocket Client**: Laravel Echo connecting to orbit-cli's Reverb
4. **Local Storage**: SQLite for settings, preferences, template favorites (global, no auth)
5. **Terminal Helper**: OrbitSSH.app for handling SSH URLs

### Data Flow

```
Vue Frontend → Laravel Controllers → Saloon HTTP → orbit-cli/web API → orbit CLI → Environment
                      ↓
                Laravel Echo → orbit-cli Reverb → Frontend (real-time updates)
```

## FINAL DECISIONS - Features REMOVED

### 1. SSH Key Management - REMOVED
**Reason**: Security risk with no authentication
- Remove: SshKey model, migration, controller, UI, routes
- Remove all SSH key related functionality

### 2. Filament - REMOVED  
**Reason**: Using Inertia/Vue only for consistency
- Remove Filament package from orbit-web
- Use standard Laravel controllers + Inertia

### 3. Pinia State Management - REMOVED
**Reason**: Unnecessary complexity for simple app
- Remove: pinia, pinia-plugin-persistedstate packages
- Remove: stores/services.ts (no Pinia stores needed)
- Use fresh API calls to retrieve data (simple Inertia app)

### 4. Environment Management - REMOVED
**Reason**: Desktop-only feature (already removed)

### 5. Provisioning Feature - REMOVED  
**Reason**: Desktop-only feature (already removed)

## Technical Decisions

| Decision | Value | Reason |
|----------|-------|--------|
| Settings persistence | Server-global (SQLite, shared by all visitors) | No auth system |
| Reverb channels | Public channels (no auth needed) | Single environment |
| API URL | `https://orbit.<tld>/api` (proper HTTPS) | Security best practice |
| Project identifier | Slug (URL-safe, consistent with orbit-cli) | Consistency |
| Open terminal | SSH URL only (`ssh://user@host/path`) | Remote access |

## Saloon Connector Refactor

The existing `OrbitConnector::forEnvironment($tld)` pattern must be refactored:
- Use `ORBIT_API_URL` environment variable as base URL
- Remove dynamic TLD resolution  
- Remove `verify=false` (use proper HTTPS)
- Single connector instance for the environment

## Route Structure (Standardized)

Use `{slug}` consistently, add constraints, order to avoid collisions:

```php
// Dashboard
GET  /                              → Dashboard

// Projects (use slug, add constraints)
GET  /projects                      → List
GET  /projects/create               → Create form (BEFORE {slug} route)
POST /projects                      → Store
GET  /projects/{slug}/provision-status → Provision status
DELETE /projects/{slug}             → Delete
POST /projects/{slug}/rebuild       → Rebuild

// Services
GET  /services                      → List
GET  /services/available            → Available (BEFORE {service} routes)
POST /services/{service}/start
POST /services/{service}/stop
POST /services/{service}/restart
POST /services/{service}/enable
DELETE /services/{service}
PUT  /services/{service}/config
GET  /services/{service}/logs
GET  /services/{service}/info

// Host Services
POST /host-services/{service}/start
POST /host-services/{service}/stop
POST /host-services/{service}/restart
GET  /host-services/{service}/logs

// Workspaces
GET  /workspaces                    → List
GET  /workspaces/create             → Create form (BEFORE {workspace} routes)
POST /workspaces                    → Store
GET  /workspaces/{workspace}        → Show
DELETE /workspaces/{workspace}      → Delete
POST /workspaces/{workspace}/projects → Add project
DELETE /workspaces/{workspace}/projects/{project} → Remove project

// Settings (global, no auth)
GET  /settings                      → Settings page
POST /settings                      → Update

// Template Favorites
POST /template-favorites            → Store
PUT  /template-favorites/{id}       → Update
DELETE /template-favorites/{id}     → Delete

// Doctor
GET  /doctor                        → Health checks

// Orchestrator
GET  /orchestrator                  → Dashboard
POST /orchestrator/enable
POST /orchestrator/disable
GET  /orchestrator/detect
POST /orchestrator/reconcile
GET  /orchestrator/services
GET  /orchestrator/projects

// Open Terminal (SSH URL only)
POST /open-terminal                 → Returns { url: "ssh://user@host/path" }

// Config
GET  /config                        → Get orbit config
POST /config                        → Save orbit config
GET  /reverb-config                 → Get Reverb config for WebSocket

// Worktrees
GET  /worktrees                     → List
POST /worktrees/refresh
POST /worktrees/unlink

// Package Linking
GET  /projects/{slug}/linked-packages
POST /projects/{slug}/link-package
DELETE /projects/{slug}/unlink-package/{package}

// PHP Management
GET  /php/config/{version?}
POST /php/config/{version?}
POST /php
POST /php/reset

// Status/Control (environment-level)
GET  /status                        → Environment status
POST /start                         → Start all services
POST /stop                          → Stop all services
POST /restart                       → Restart all services
```

Route constraints:
```php
->where('slug', '[A-Za-z0-9._-]+')
->where('service', '[A-Za-z0-9._-]+')
->where('workspace', '[A-Za-z0-9._-]+')
```

## WebSocket Contract

```
Channel: project-provisioning.{slug} (PUBLIC - no auth)
Event: ProjectProvisionStatus
Payload: {
  status: 'pending' | 'provisioning' | 'completed' | 'failed',
  message: string,
  step?: string,
  progress?: number
}

Fallback: If WebSocket disconnects, poll GET /projects/{slug}/provision-status
```

## Migration Inventory (FINAL)

### Models (3) - COPY
- Setting.php
- TemplateFavorite.php  
- UserPreference.php

### Migrations (4) - COPY
- create_settings_table
- create_template_favorites_table
- create_user_preferences_table
- add_driver_preferences_to_template_favorites_table

### Services/OrbitCli (10) - COPY + REFACTOR
- ProjectService, StatusService, ServiceControlService, ConfigurationService
- WorkspaceService, WorktreeService, OrchestratorService, PackageService
- Shared/ConnectorService (refactor to use ORBIT_API_URL)
- Shared/CommandService (remove, not needed - all via HTTP)

### HTTP/Integrations (36) - COPY + REFACTOR
- OrbitConnector.php (refactor: use env var, remove forEnvironment)
- All 35 Request classes

### Services/TemplateAnalyzer (6) - COPY
- All files unchanged

### Other Services - COPY/ADAPT
- OrbitService.php
- DoctorService.php (call orbit doctor via API if available, or keep CLI)

### Controllers (2) - CREATE
- DashboardController (simplified)
- SettingsController (remove Mac-specific methods)

### Pages (8) - COPY + ADAPT
- Dashboard.vue (single-env view)
- Settings.vue (remove SSH key UI, Mac-specific settings)
- Projects.vue
- projects/Create.vue
- Services.vue
- Workspaces.vue, workspaces/Create.vue, workspaces/Show.vue
- Orchestrator.vue

### Components (4) - COPY
- Heading.vue
- Modal.vue
- AddServiceModal.vue
- ConfigureServiceModal.vue

### Composables (2) - COPY
- useProjectProvisioning.ts (WebSocket)
- useEcho.ts

### NO Stores - REMOVED
- Removed Pinia, use fresh API calls

### Other Frontend - COPY/ADAPT
- Layout.vue (remove macOS chrome)
- app.ts, echo.ts, axios.ts
- types/index.d.ts
- app.css

## DO NOT MIGRATE

### Models - REMOVED
- Environment.php, Project.php, Deployment.php (models)
- SshKey.php model and everything related

### Migrations - REMOVED
- All environment/server migrations
- create_ssh_keys_table

### Services - REMOVED
- SshService, ProvisioningService, NotificationService
- MacPhpFpmConfigService, DnsResolverService, CliUpdateService

### Controllers - REMOVED
- ProvisioningController, SshKeyController

### Pages - REMOVED
- Environment CRUD pages, EnvironmentCard, EnvironmentSwitcher

### Stores - REMOVED
- stores/services.ts, stores/github.ts (no Pinia)

### Config - REMOVED
- NativeAppServiceProvider, config/nativephp.php

## Dependencies

### Composer - ADD
- saloonphp/saloon ^3.14

### Composer - REMOVE
- filament/filament (if present)

### NPM - ADD  
- laravel-echo
- pusher-js
- vue-sonner

### NPM - REMOVE
- pinia
- pinia-plugin-persistedstate

**Note:** NO laravel/reverb needed - connects to orbit-cli's existing Reverb

## Environment Variables

```env
APP_URL=https://orbit-web.<tld>
DB_CONNECTION=sqlite

# Orbit API (same server, HTTPS)
ORBIT_API_URL=https://orbit.<tld>/api

# WebSocket - connect to orbit-cli's Reverb (for frontend)
VITE_REVERB_APP_KEY=<from orbit>
VITE_REVERB_HOST=orbit.<tld>
VITE_REVERB_PORT=443
VITE_REVERB_SCHEME=https
```

## Implementation Phases (7)

### Phase 1: Project Setup

**Objective:** Remove unwanted features and configure dependencies

**Tasks:**
- [ ] Remove Filament from orbit-web
- [ ] Add Saloon dependency
- [ ] Add NPM deps (laravel-echo, pusher-js, vue-sonner)
- [ ] Remove Pinia deps
- [ ] Configure .env with ORBIT_API_URL and Reverb vars

**Affected Files:**
- `composer.json` - Remove Filament, add Saloon
- `package.json` - Remove Pinia, add Echo/Pusher/Sonner
- `.env` - Configure API and Reverb URLs

**Dependencies:** None

### Phase 2: Models & Migrations

**Objective:** Copy required models and migrations (no SSH keys)

**Tasks:**
- [ ] Copy 3 models: Setting, TemplateFavorite, UserPreference
- [ ] Copy 4 migrations
- [ ] Run migrations to create tables
- [ ] Test model functionality

**Affected Files:**
- `app/Models/Setting.php`
- `app/Models/TemplateFavorite.php`
- `app/Models/UserPreference.php`
- `database/migrations/*` - 4 migrations

**Dependencies:** Phase 1

### Phase 3: HTTP Integration Layer

**Objective:** Copy Saloon HTTP integration with refactored connector

**Tasks:**
- [ ] Copy OrbitConnector (refactor to use ORBIT_API_URL env var)
- [ ] Copy all 35 Request classes
- [ ] Remove forEnvironment() pattern
- [ ] Test API connectivity

**Affected Files:**
- `app/Http/Integrations/OrbitConnector.php`
- `app/Http/Integrations/Requests/*.php` - 35 request classes

**Dependencies:** Phase 2

### Phase 4: Service Layer

**Objective:** Copy and adapt service classes for single-environment

**Tasks:**
- [ ] Copy OrbitCli services (already use Saloon)
- [ ] Copy TemplateAnalyzer services
- [ ] Adapt for single-environment (no Environment model references)
- [ ] Test service layer integration

**Affected Files:**
- `app/Services/OrbitCli/*.php` - 10 service files
- `app/Services/TemplateAnalyzer/*.php` - 6 service files
- `app/Services/OrbitService.php`
- `app/Services/DoctorService.php`

**Dependencies:** Phase 3

### Phase 5: Controllers & Routes  

**Objective:** Create simplified controllers with flattened routes

**Tasks:**
- [ ] Create DashboardController, SettingsController
- [ ] Set up flattened route structure with constraints
- [ ] Implement /open-terminal (SSH URL generation)
- [ ] Test all controller actions

**Affected Files:**
- `app/Http/Controllers/DashboardController.php`
- `app/Http/Controllers/SettingsController.php`
- `routes/web.php` - Flattened routes with constraints

**Dependencies:** Phase 4

### Phase 6: Frontend

**Objective:** Copy and adapt Vue components (no Pinia)

**Tasks:**
- [ ] Copy Layout (remove macOS chrome)
- [ ] Copy/adapt pages (no Pinia, use props/fetch)
- [ ] Copy components, composables
- [ ] Configure Echo for orbit's Reverb
- [ ] Remove all Pinia store usage

**Affected Files:**
- `resources/js/layouts/Layout.vue`
- `resources/js/pages/*.vue` - 8 main pages
- `resources/js/components/*.vue` - 4 components
- `resources/js/composables/*.ts` - 2 composables
- `resources/css/app.css`

**Dependencies:** Phase 5

### Phase 7: Testing & Documentation

**Objective:** Test all functionality and finalize deployment

**Tasks:**
- [ ] Test all API calls
- [ ] Test WebSocket provisioning updates
- [ ] Test polling fallback
- [ ] Document OrbitSSH.app installation

**Affected Files:**
- `tests/Feature/*.php` - Integration tests
- `tests/Unit/*.php` - Unit tests
- `docs/` - Documentation

**Dependencies:** Phase 6

## Edge Cases

- **WebSocket connection drops**: Reconnect automatically + polling fallback
- **SSH URL handler not installed**: Graceful fallback to copy SSH command to clipboard
- **orbit-cli API unavailable**: Clear error messaging and retry mechanisms
- **Provisioning job timeout**: Handled by orbit-cli/web, not orbit-web
- **VPN access from other machines**: SSH URLs work from any location

## Testing Strategy

- **Unit tests**: Models and service classes
- **Integration tests**: Controller actions and API endpoints
- **E2E tests**: Full workflow via web interface
- **WebSocket tests**: Real-time update delivery with fallback
- **SSH URL tests**: Terminal opening functionality

## Success Criteria

1. Dashboard shows environment status via orbit API
2. Project CRUD works (list, create, delete, rebuild)
3. Real-time provisioning status via WebSocket
4. Polling fallback works when WebSocket disconnects
5. Service control works (start, stop, restart, enable, disable)
6. Workspace management works
7. Settings persist in SQLite (global)
8. Template favorites work
9. "Open in terminal" returns correct ssh:// URLs
10. No Pinia, no Filament, no NativePHP code
11. App runs at orbit-web.<tld>
12. All API calls use HTTPS to orbit.<tld>/api

## Open Questions

None - all decisions finalized through review process.