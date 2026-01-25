# Project Creation Flow

> **Single Source of Truth** for the project creation workflow.  
> Reference this document during any refactoring to ensure consistency.

## Prerequisites

**Horizon must be running** for project creation to work. Without it, jobs won't be processed and projects will be stuck in "Initializing" state forever.

```bash
# Start Horizon (development)
cd ~/projects/orbit-web && php artisan horizon

# Verify it's running
php artisan horizon:status
```

## Core Principle

**`ProvisionPipeline` is the single source of truth** for provisioning logic.

Both entry points use the same pipeline:
1. **Web UI** → Dispatches `CreateProjectJob` to Horizon → Async
2. **CLI** → Runs `ProvisionPipeline` synchronously → Real-time output

The pipeline accepts a `ProvisionLoggerContract` interface, allowing each consumer to provide its own logger:
- **orbit-core's `ProvisionLogger`**: Uses native Laravel `event()` broadcasting
- **orbit-cli's `ProvisionLogger`**: Outputs to console + broadcasts via Pusher SDK

## Sequence Diagram (Web UI Flow)

```mermaid
sequenceDiagram
    participant U as User (Browser)
    participant C as Controller
    participant Q as Job Queue
    participant H as Horizon Worker
    participant PP as ProvisionPipeline
    participant WS as WebSocket (Reverb)
    participant DB as Database

    U->>C: POST /projects
    Note over C: Validate input

    C->>DB: Save TemplateFavorite (if template provided)
    C->>DB: Create Project (status=queued)
    C->>Q: Dispatch CreateProjectJob(project_id)
    C-->>U: Redirect with {provisioning: slug}

    Note over U: User sees "Creating..." UI
    U->>WS: Subscribe to provisioning channel

    Q->>H: CreateProjectJob.handle()
    activate H

    H->>PP: ProvisionPipeline->run()
    activate PP

    PP->>WS: Broadcast: status=creating_repo
    PP->>PP: Phase 1: Repository operations
    PP->>WS: Broadcast: status=cloning
    PP->>PP: Phase 2: Clone repository
    PP->>WS: Broadcast: status=setting_up
    PP->>PP: Phase 3: Install deps, build, migrate
    PP->>WS: Broadcast: status=building
    PP->>PP: Phase 4: Finalization
    PP->>WS: Broadcast: status=finalizing
    PP->>PP: Phase 5: Regenerate Caddy (caddy:reload)
    PP->>WS: Broadcast: status=ready

    PP-->>H: Return StepResult
    deactivate PP

    H->>DB: Update Project status
    deactivate H

    WS-->>U: Receive status=ready
    Note over U: UI updates to show project ready
```

## Sequence Diagram (CLI Flow)

```mermaid
sequenceDiagram
    participant U as User (Terminal)
    participant CLI as ProjectCreateCommand
    participant PP as ProvisionPipeline
    participant PL as ProvisionLogger (CLI)
    participant WS as WebSocket (Reverb via Pusher)
    participant DB as Database

    U->>CLI: orbit project:create my-project
    CLI->>DB: Create Project (status=queued)

    CLI->>PP: ProvisionPipeline->run(context, logger)
    activate PP

    PP->>PL: logger->broadcast('creating_repo')
    PL->>U: Console: → creating_repo
    PL->>WS: Pusher SDK → Reverb

    PP->>PP: Phase 1-5: Run provisioning steps
    Note over PL: Each step outputs to console AND broadcasts to Reverb

    PP->>PL: logger->broadcast('ready')
    PL->>U: Console: → ready
    PL->>WS: Pusher SDK → Reverb

    PP-->>CLI: Return StepResult
    deactivate PP

    CLI->>DB: Update Project status
    CLI-->>U: Success message with project URL
```

## State Machine

```mermaid
stateDiagram-v2
    [*] --> Queued: Project created
    Queued --> CreatingRepo: Job starts
    CreatingRepo --> Cloning: Repository ready
    Cloning --> SettingUp: Clone complete
    SettingUp --> Building: Deps installed
    Building --> Finalizing: Assets built
    Finalizing --> Ready: PHP restarted

    CreatingRepo --> Failed: Error
    Cloning --> Failed: Error
    SettingUp --> Failed: Error
    Building --> Failed: Error
    Finalizing --> Failed: Error

    Failed --> [*]: User acknowledges
    Ready --> [*]: Complete
```

## Component Responsibilities

| Component | Responsibility |
|-----------|----------------|
| **Controller** | Validate input, create Project (queued), dispatch job, return immediately |
| **CreateProjectJob** | Run ProvisionPipeline, handle errors, update Project status |
| **ProvisionPipeline** | Orchestrate provisioning actions, broadcast progress via native events |
| **ProvisionLogger** | Dispatch native Laravel broadcasting events to Reverb |
| **WebSocket** | Real-time status updates to browser (Echo configured once per app) |
| **Project** | Persist status for recovery/polling |

**Note:** The CLI's `project:create` command runs `ProvisionPipeline` synchronously with real-time console output, while web UI uses `CreateProjectJob` via Horizon. Both paths share the same provisioning logic.

## Active Environment Rules

Orbit web/desktop rely on a single active environment in the database.

- `EnvironmentManager::current()` resolves the active environment for requests.
- `POST /environments/{environment}/switch` updates the active environment.
- `/` renders the active environment dashboard in web mode via `EnvironmentController@show`.
- `/projects` uses the active environment and does not require an ID in the URL.

## API Contract

### Request
```
POST /projects
Content-Type: application/json

{
  "name": "my-project",
  "template": "laravel/laravel",
  "is_template": true,
  "visibility": "private",
  "php_version": "8.4",
  "db_driver": "pgsql",
  "session_driver": "redis",
  "cache_driver": "redis",
  "queue_driver": "redis"
}
```

### Response (Web Request)
```
302 Redirect to /environments/{active_id}/projects
Session: {provisioning: "my-project", success: "Project is being created..."}
```

### Response (API Request)
```json
HTTP 200 OK

{
  "success": true,
  "message": "Project creation queued",
  "slug": "my-project",
  "project": {
    "id": 123,
    "slug": "my-project",
    "status": "queued"
  }
}
```

## Error Handling

| Error Type | Handler | User Experience |
|------------|---------|-----------------|
| Validation error | Controller | Immediate redirect back with errors |
| Job dispatch failure | Controller | Error flash message |
| CLI failure | CreateProjectJob | WebSocket broadcasts error, Project marked failed |
| Timeout | Horizon | Project marked failed, user sees error via WebSocket |

## WebSocket Setup (Vue)

Orbit's frontend uses Laravel's official `@laravel/echo-vue` composables with a
single global Echo connection. The Reverb configuration comes from the active
environment and is injected as an Inertia prop. Component-level subscriptions are
managed by the composables and automatically cleaned up when components unmount.

Key files:
- `resources/js/app.ts` configures Echo from the `reverb` page prop
- `resources/js/composables/useProjectProvisioning.ts` subscribes via `useEchoPublic`
- `resources/js/pages/environments/Services.vue` listens for service status updates

## What NOT to Do (Web/Desktop Consumers)

1. **Never call CLI synchronously from controllers** - always dispatch a job
2. **Never branch** on `$environment->is_local` for the dispatch flow
3. **Never skip** the job queue for "faster" local execution
4. **Never return** from controller before dispatching the job

## Why Jobs Run Synchronously

The async rule applies to **web/desktop consumers**, not to how jobs execute internally.

The pattern is:
- **Controller** → Dispatches job → Returns immediately (async from user's perspective)
- **Job** → Runs ProvisionPipeline synchronously → That's the whole point of using a job

Jobs exist specifically to move long-running operations off the request thread. The job worker blocks while provisioning runs - this is correct and expected. The "async" is about the HTTP response, not the job execution.

**Architecture note:** Provisioning now uses native Laravel broadcasting (`ProjectProvisioningStatus` event with `ShouldBroadcastNow`) instead of CLI → Pusher SDK. This simplifies debugging (single process) and uses standard Laravel patterns.

## Related Files

### orbit-core
- `src/Http/Controllers/EnvironmentController.php:685` - `storeProject()` method
- `src/Http/Controllers/ProjectController.php` - Web entry point (`POST /projects`)
- `src/Services/EnvironmentManager.php` - Active environment resolution
- `src/Jobs/CreateProjectJob.php` - Async job, runs ProvisionPipeline
- `src/Contracts/ProvisionLoggerContract.php` - Interface for logger implementations
- `src/Services/Provision/ProvisionPipeline.php` - Main provisioning orchestrator
- `src/Services/Provision/ProvisionLogger.php` - orbit-core's logger (native events)
- `src/Services/Provision/Actions/*` - Individual provisioning steps
- `src/Events/ProjectProvisioningStatus.php` - Broadcasting event
- `src/Data/ProvisionContext.php` - Context DTO for actions
- `src/Data/StepResult.php` - Action result wrapper
- `src/Models/Project.php` - Project status tracking
- `resources/js/composables/useProjectProvisioning.ts` - WebSocket listener

### orbit-cli
- `app/Commands/ProjectCreateCommand.php` - CLI entry point, runs ProvisionPipeline sync
- `app/Services/ProvisionLogger.php` - CLI's logger (console + Pusher SDK)
- `app/Services/ReverbBroadcaster.php` - Broadcasts to Reverb via Pusher SDK

## Job Options Reference

The `CreateProjectJob` receives these options and passes them to `ProvisionPipeline`:

| Option | Purpose | Notes |
|--------|---------|-------|
| `name` | Project name | Slug derived from this |
| `org` | GitHub organization | For template/fork operations |
| `template` | Template repo | GitHub repo URL |
| `is_template` | Template vs clone | Determines RepoIntent |
| `fork` | Fork mode | Fork vs import |
| `visibility` | Repo visibility | `private` or `public` |
| `directory` | Project path | Override default project path |
| `php_version` | PHP version | e.g., `8.4` |
| `db_driver` | Database driver | `sqlite` or `pgsql` |
| `session_driver` | Session driver | |
| `cache_driver` | Cache driver | |
| `queue_driver` | Queue driver | |

Tests in `tests/Unit/Jobs/CreateProjectJobTest.php` verify job behavior.

## Browser Tests

E2E browser tests are available in `orbit-web/tests/e2e/project-creation.spec.ts`.

```bash
# Run all project creation tests
cd ~/projects/orbit-web
npx playwright test tests/e2e/project-creation.spec.ts

# Run specific test group
npx playwright test tests/e2e/project-creation.spec.ts --grep "Project Creation Form"
```

Test coverage:
- Form loading and elements
- Organization dropdown (GitHub integration)
- Form validation and submit button state
- Template detection and metadata
- Project creation submission and status tracking
- Full provisioning completion (90s timeout)

## Changelog

| Date | Change |
|------|--------|
| 2026-01-19 | Initial documentation |
| 2026-01-19 | Implemented CreateProjectJob, updated controller to dispatch async |
| 2026-01-19 | Fixed `--org` -> `--organization` flag, added CLI flag reference |
| 2026-01-19 | Consolidated `provision` into `project:create` - single command for all project creation |
| 2026-01-19 | Added Playwright e2e browser tests for project creation flow |
| 2026-01-20 | Switched to @laravel/echo-vue composables with global Echo config |
| 2026-01-22 | Moved provisioning from CLI to orbit-core ProvisionPipeline with native Laravel broadcasting |
| 2026-01-22 | Added automatic Caddy regeneration via `orbit caddy:reload` after project provisioning |
| 2026-01-22 | CLI `project:create` runs ProvisionPipeline synchronously with real-time output |
| 2026-01-22 | Added `ProvisionLoggerContract` interface for CLI/web logger implementations |
| 2026-01-22 | CLI broadcasts to Reverb via Pusher SDK for web UI updates during sync execution |
| 2026-01-22 | Active environment manager + switch route for web/desktop project creation |
| 2026-01-23 | Renamed from site to project throughout |
