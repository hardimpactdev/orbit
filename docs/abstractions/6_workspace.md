# Workspace Implementation Patterns

Read this with `docs/abstractions/cross-cutting.md` before implementing
workspace command ports.

Product behavior remains owned by `docs/commands/6_workspace/**` and the
top-level product docs.

## Domain Constraints

- The gateway is the source of truth for workspace registry intent.
- Workspaces belong to apps, not directly to nodes. The owning node is inherited
  from the parent app.
- Workspace names are canonical identity slugs and are unique within the parent
  app, not globally.
- Workspace hostnames are derived by prepending the workspace slug to the
  parent app hostname.
- Workspace PHP version is gateway-tracked intent. A null workspace
  `php_version` means the workspace inherits the parent app PHP version.
- Workspace lifecycle status is registry intent only. It is not setup-run status
  and not a live readiness probe.
- Registry read commands must not SSH into app nodes, probe runtime health, or
  repair drift. Live workspace reality belongs to `doctor --family=workspace`.
- Workspace writes are gateway-owned writes. Control callers use the typed
  gateway API over WireGuard; gateway callers execute locally and may enact
  node-side artifacts through the gateway-owned `RemoteShell` edge when the
  command contract requires it.
- App-node callers may run workspace reads when authorized. `workspace:setup` is
  the only current app-node workspace write exception; other workspace writes
  remain gateway-owned unless a command contract documents a future exception.
- Workspace commands own workspace identity, setup and teardown policy,
  workspace PHP override, workspace history, and workspace-derived hostname
  intent. They do not own proxy convergence, inherited process-unit
  convergence, app intent, schedule definitions, or node-level firewall policy.

## Schema Seed

The first schema/model slice should model gateway intent and durable history
without implementing remote enactment:

- `workspaces`
  - `app_id`
  - `name`
  - `path`
  - `php_version` nullable inheritance override
  - `agent_ide` nullable adapter captured when a workspace is created
  - `agent_ide_workspace_id` nullable adapter-side identifier
  - `lifecycle_status` with current values `expected` and `setup-pending`
  - per-app unique index on `app_id`, `name`
- `workspace_steps`
  - `app_id`
  - `phase` with current values `setup` and `teardown`
  - `sort_order`
  - `command`
  - `timeout_seconds`
- `workspace_runs`
  - `workspace_id`
  - `phase`
  - `status`
  - `step_set_hash` nullable
  - `started_at` and `completed_at`
- `workspace_run_steps`
  - `workspace_run_id`
  - `workspace_step_id` nullable so deleted step definitions do not destroy
    historical output
  - `command`
  - `exit_code` nullable
  - `output` nullable
  - `started_at` and `completed_at`

Old Orbit had additional migration churn from `worktree_*` names, workspace
mirrors, and branch fields. The clean rebuild should create the current
workspace names directly and should not reintroduce removed `branch` or
`workspace_mirrors` tables unless current command docs regain those concepts.

## Model Pattern

- `Workspace` belongs to `App` and has many `WorkspaceRun` records.
- `Workspace` should expose `effectivePhpVersion()` by returning its explicit
  PHP version first, then the parent app PHP version.
- `Workspace` should expose canonical entity data through a small formatter or
  method only when a command needs it; keep the canonical JSON entity aligned
  with `docs/commands/6_workspace/README.md#workspace-json-entity`.
- `WorkspaceStep` belongs to `App`, casts `phase`, and owns ordered insertion
  and compaction helpers for setup/teardown policy commands.
- `WorkspaceRun` belongs to `Workspace`, has many ordered run steps, and casts
  `phase`, `started_at`, and `completed_at`.
- `WorkspaceRunStep` belongs to `WorkspaceRun` and optionally belongs to
  `WorkspaceStep`.

## Read Command Pattern

- `workspace:list` reads visible workspace registry intent, applies scalar
  `--app` and `--node` filters, and sorts by node name, parent app name, then
  workspace name.
- `workspace:show` resolves one visible workspace by `(app, workspace)` when
  necessary because workspace names are only unique within an app.
- `workspace:history` and `workspace:log` read durable run history from the
  gateway database and do not inspect node-side logs.
- Gateway callers read gateway database state locally.
- Control and app callers forward reads through typed Saloon requests under
  `App\Http\Gateway\Requests\Workspaces` and consume DTOs under
  `App\Http\Gateway\Responses\Workspaces`.
- Workspace read API endpoints return the standard `success` / `error` envelope
  and must preserve structured gateway API errors through `GatewayApiException`.

## Write Command Pattern

- `workspace:new` writes gateway workspace intent before downstream setup work.
  After durable intent exists, retryable remote enactment drift is reported as
  `success.meta.warnings[]` and repaired through doctor.
- `workspace:setup` converges an existing workspace's node-side artifacts and
  runs setup steps with the lifecycle environment from the workspace README.
- `workspace:remove` deletes workspace intent and then performs best-effort
  cleanup of workspace-owned artifacts. Retryable cleanup drift is reported as
  warnings that point to `doctor --fix --family=workspace --restore`.
- Setup/teardown step mutation commands operate on gateway-owned step policy and
  should not execute shell commands when adding, listing, or removing step
  definitions.

## Evidence Pointers

- `docs/commands/6_workspace/README.md`
- `docs/commands/6_workspace/workspace-concepts.md`
- `docs/commands/6_workspace/workspace-doctor.md`
- `docs/commands/6_workspace/1_workspace-new`
- `docs/commands/6_workspace/3_workspace-list`
- `docs/commands/6_workspace/4_workspace-show`
- `docs/commands/6_workspace/5_workspace-remove`
- `docs/abstractions/cross-cutting.md`
- Old evidence: `../orbit-old-may/app/Models/Workspace.php`
- Old evidence: `../orbit-old-may/app/Models/WorkspaceStep.php`
- Old evidence: `../orbit-old-may/app/Models/WorkspaceRun.php`
- Old evidence: `../orbit-old-may/app/Models/WorkspaceRunStep.php`
- Old evidence: `../orbit-old-may/database/migrations/*workspace*`
- Old evidence: `../orbit-old-may/database/migrations/*worktree*`
