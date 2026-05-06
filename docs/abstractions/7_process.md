# Process Implementation Patterns

Read this with `docs/abstractions/cross-cutting.md` before implementing process
command ports.

Product behavior remains owned by `docs/commands/7_process/**` and the top-level
product docs.

## Domain Constraints

- The gateway is the source of truth for process definitions.
- Process definitions belong to apps and are inherited by every workspace for
  the app.
- Runtime units are derived Supervisor programs. They are physical artifacts,
  not registry models.
- Runtime unit names use `orbit_<app>_<workspace|main>_<process>`. The `orbit_`
  prefix marks Orbit ownership, and underscores are reserved as backend segment
  delimiters.
- Process command reads report gateway intent plus latest durable process
  events. They do not synchronously probe the runtime backend.
- Runtime lifecycle commands and runtime-unit enactment are gateway-owned node
  work. Gateway callers use the local database plus the gateway-owned
  `RemoteShell` edge to the owning app node. Control and app callers use typed
  gateway API requests.
- App-node callers may run process reads, logs, and lifecycle actions when
  authorized. They may not mutate process intent.
- Crash lifecycle events are durable history. Process doctor may use them for
  explanation, but event rows are not desired runtime-unit state.
- Process doctor owns Supervisor runtime-unit drift and crash hook material. It
  must not duplicate app source, workspace source, proxy, schedule, tool,
  firewall, or node reachability issue codes.

## Schema And Model Pattern

- `processes`
  - `app_id`
  - `name`
  - `command`
  - `restart_policy`
  - `crash_notification`
  - `sort_order`
- `process_events`
  - `node_id`
  - `app_id`
  - `workspace_id` nullable
  - `process_id` nullable
  - `runtime_unit`
  - event fields such as `type`, `event_id`, `exit_code`, `exit_status`,
    `occurred_at`, and `payload`

`Process` belongs to `App`; `ProcessEvent` belongs to node, app, optional
workspace, and optional process. Process definitions remain app-level intent
even when actions target a workspace runtime context.

## Runtime Backend Pattern

- Use `App\Services\Processes\SupervisorProgramRenderer` for process-specific
  Supervisor program definitions, names, config content, and install scripts.
- Use `App\Services\RuntimeBackend\SupervisorProgramRenderer` only for generic
  Supervisor file rendering.
- Use `App\Services\RuntimeBackend\RuntimeBackendProbe` for the shared
  Supervisor availability command before runtime-unit enactment or doctor
  runtime checks.
- Use `App\Services\Processes\ProcessRuntimeUnitPayload` when command responses
  need the derived runtime-unit list.
- Runtime unit fan-out includes the main app context plus every workspace for
  the app. Workspace names are context identity only; process definitions are
  not stored per workspace.

## Command Pattern

- `process:list` reads gateway intent, resolves app/workspace context, expands
  the expected runtime unit name for the selected context, and includes latest
  durable lifecycle event data when present.
- `process:add`, `process:edit`, and `process:remove` mutate gateway intent
  first. Runtime-unit enactment or cleanup happens after durable intent and
  reports retryable process-family warnings when the backend cannot converge.
- `process:start`, `process:stop`, and `process:restart` operate on derived
  Supervisor program names for the resolved main app or workspace context and
  record durable lifecycle events after successful runtime actions.
- `process:logs` reads the runtime backend's stdout/stderr capture for the
  resolved Supervisor program. Follow mode is a stream and is not compatible
  with `--json`.
- Process commands use the shared gateway API transport and standard
  `success` / `error` envelopes.

## Doctor Pattern

- `ProcessesProbe` should check registry intent first, then owner app and
  workspace expansion, then runtime backend availability.
- If the runtime backend is unavailable, the probe reports
  `process.runtime_backend_unavailable` and skips downstream runtime-unit
  presence/content checks for that node.
- Expected runtime-unit config should be compared against
  `SupervisorProgramRenderer::render()` and the generic renderer's config path.
- Runtime-unit extras are limited to Orbit-owned Supervisor programs whose
  `orbit_<app>_<workspace|main>_<process>` identity no longer maps to active
  app, workspace, and process intent.
- Restart policy and environment drift are reported as process-family issue
  codes because they are rendered from process/app/workspace/node intent.
- Event notifier checks belong to process doctor only for process definitions
  whose crash-notification policy requires crash reporting.
- Process doctor has no adoption path for Supervisor programs. Operators update
  intent with process commands when observed command or policy should become
  desired state.

## Evidence Pointers

- `docs/commands/7_process/README.md`
- `docs/commands/7_process/process-concepts.md`
- `docs/commands/7_process/process-doctor.md`
- `docs/commands/7_process/1_process-add`
- `docs/commands/7_process/2_process-edit`
- `docs/commands/7_process/3_process-remove`
- `docs/commands/7_process/4_process-list`
- `docs/commands/7_process/5_process-start`
- `docs/commands/7_process/6_process-stop`
- `docs/commands/7_process/7_process-restart`
- `docs/commands/7_process/8_process-logs`
- `docs/abstractions/cross-cutting.md`
- Old evidence: `../orbit-old-may/app/Services/Processes/ProcessProbe.php`
- Old evidence: `../orbit-old-may/app/Services/Processes/ProcessEnactor.php`
- Old evidence: `../orbit-old-may/app/Services/Processes/ProcessUnitRenderer.php`
- Old evidence: `../orbit-old-may/app/Services/Processes/UnitNameParser.php`
- Old evidence: `../orbit-old-may/tests/Feature/Processes/ProcessProbeTest.php`
- Old evidence: `../orbit-old-may/tests/Feature/Doctor/ProcessFamilyDoctorContractTest.php`
