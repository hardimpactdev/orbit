# Technical Contract: `orbit app:prune [app]`

[Back to public `app:prune` documentation.](../app-prune.md)

**Owner:** `app`.

**Effects:** `destructive`, `write`, `stream`.

**Prerequisites:**
- The CLI caller role is `control` or `gateway`. App-node callers are denied
  before prompts or side effects with `error.code=caller_role_not_allowed`.
- The target app name or hostname must resolve to exactly one gateway app record.
- The caller is authorized to manage the target app.
- At least one agent IDE adapter is configured for the app (directly, inherited from the node, or as an extension).

## Signature

```bash
orbit app:prune [app] [--dry-run] [--force] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `app` | `[app]` | Always. | Never. | None. | Must resolve to an existing app record. |
| `dry_run` | `--dry-run` | Optional. | Never. | `false`. | Boolean flag. If `true`, side effects are skipped. |
| `force` | `--force` | Non-interactive mode (without `--dry-run`). | Never. | `false`. | Boolean flag. Explicit destructive consent. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive mode. |

## Caller Role Behavior

| Caller role | Behavior |
| --- | --- |
| `control` | Resolves app locally, then forwards the prune request to the gateway. |
| `gateway` | Executes the pruning logic locally. |
| `app` | App-node callers are denied. Fail before prompts or side effects with `error.code=caller_role_not_allowed`. |
| `unknown` | Unsupported or unreadable local caller role. Fail before prompts or side effects with `error.code=caller_role_not_allowed`. |

## Input Resolution

1. **Resolve Caller Role:** Deny `app` and `unknown` callers.
2. **Resolve App:** From `[app]` or current context. Prompt in interactive mode if missing.
3. **Resolve Dry Run:** From `--dry-run`.
4. **Resolve Destructive Consent:**
   - If `dry_run` is `true`, no destructive consent is needed. `--force` is ignored.
   - If `dry_run` is `false`:
     - In interactive mode, prompt for confirmation unless `--force` is present.
     - In non-interactive mode, fail if `--force` is missing.

## Input Mode Contracts

- [`5.1_app-prune_input-mode_interactive.md`](5.1_app-prune_input-mode_interactive.md)
- [`5.2_app-prune_input-mode_non-interactive.md`](5.2_app-prune_input-mode_non-interactive.md)

## Behavior Contract

`--dry-run` is intentionally part of `app:prune`, not a family-wide destructive
command convention. This command discovers its destructive target set from
external agent IDE adapter state; previewing that computed set is a distinct
read operation before workspace removal side effects begin. Commands that
operate on an explicit named target, such as `app:remove` or
`workspace:remove`, continue to rely on confirmation/`--force` instead of a
generic dry-run contract.

### 1. Source Discovery
- Resolve the currently effective agent IDE adapters for the app using the
  blueprint resolution chain (app explicit setting → owning node default →
  none). Workspace-level overrides are not part of the current resolution.
- `app:prune` does not consult any "previous adapter" state. Adapter switches
  are owned by `app:agent-ide`, which identifies previous-adapter cleanup
  targets before writing the new app adapter and then removes those stale
  workspaces after the intent write. The app-scoped lock (see Concurrency)
  prevents an adapter switch from observing or being observed by `app:prune`.
- Query the resolved adapters for the current list of active workspaces.
  When more than one adapter is effective for the app (for example, when an
  installed extension contributes additional discovery sources alongside a
  core adapter), the union of their reported workspaces is the source of
  truth.

### 2. Stale Identification
- List all workspaces currently tracked by Orbit for the app.
- A workspace is "stale" only when **none** of the queried effective
  adapters report it. If at least one adapter still reports the workspace,
  it is not stale.

### 3. Cleanup Execution
If `dry_run` is `false`:
- Acquire the app-scoped lock (see Concurrency) for the duration of the
  cleanup pass.
- For each stale workspace:
  - Apply the normal
    [`workspace:remove`](../../../6_workspace/5_workspace-remove/technical/1_workspace-remove.md)
    semantics with destructive consent already satisfied by `app:prune`.
  - Phase A deletes workspace-owned proxy route rows and the `workspace`
    row in one gateway transaction.
  - Phase B runs the workspace removal cleanup order: stop traffic, stop
    inherited processes, run teardown steps, remove the workspace FPM pool, and
    remove the worktree.
  - App-node SSH cleanup reachability is not a pre-prune prerequisite. If Phase
    B cannot finish after workspace intent removal, the workspace removal still
    succeeds with warnings.
  - Partial Phase B failures become `success.meta.warnings[]` using the same
    family warning vocabulary and `next_command` handoffs as
    `workspace:remove`.
  - **Database Cleanup Current Limitation:** Databases are not removed by this
    contract revision. Per `ARCHITECTURE.md` §Apps, database cleanup is allowed only
    for databases explicitly tracked by Orbit as workspace-owned, and no
    such tracking mechanism exists in gateway intent today. Every database
    encountered must be reported as `skipped` (manual cleanup) regardless
    of name, environment file, convention, or setup-step side effect.
    User-authored database removal can be expressed as a workspace
    teardown step today.

### 4. Concurrency
- `app:prune` takes an app-scoped lock for the duration of the run. The
  lock serializes `app:prune` against concurrent `workspace:new`,
  `workspace:remove`, `app:agent-ide`, and other `app:prune` runs for the
  same app. This guarantees that adapter resolution, workspace listing,
  and stale workspace removal observe a consistent app state.

### 5. Convergence
- `app:prune` is source-of-truth cleanup.
- It is NOT drift repair. Drift, such as leftover files, routes, or runtime
  units from a failed workspace removal Phase B step, is handled by the affected
  family doctor.

## Renderer Contracts

- [Human renderer](6.1_app-prune_output-render_human.md)
- [JSON renderer](6.2_app-prune_output-render_json.md)

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| App not found | `app` does not match any record. | Failure (`error.code=app.not_found`). |
| Caller role not allowed | Caller role is `app` or `unknown`. | Failure (`error.code=caller_role_not_allowed`). |
| Authorization failed | Caller is not authorized to manage the app. | Failure (`error.code=authorization_failed`). |
| No adapters | No agent IDE adapters configured for the app. | Failure (`error.code=app.no_agent_ide_adapter`). |
| Adapter query failed | Error communicating with a source-of-truth adapter. | Failure (`error.code=app.agent_ide_query_failed`). |
| Destructive consent missing | Non-interactive mode, no `--dry-run`, and `--force` is missing. | Failure (`error.code=validation_failed`, `error.meta.field=force`). |
| Partial removal | One or more `workspace:remove` Phase B cleanup steps failed after gateway intent removal. | Success with structured `success.meta.warnings[]` using `workspace:remove` warning semantics. |

## Doctor Relationship

- `app:prune` cross-references [`app-doctor.md`](../../app-doctor.md) for app-level drift.
- Stale workspace removal delegates to
  [`workspace:remove`](../../../6_workspace/5_workspace-remove/workspace-remove.md).
  Any drift created by failed Phase B cleanup is reported by the same family
  doctors and warning handoffs documented there.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `tests/Feature/Actions/Apps/PruneAppWorkspacesActionTest.php` | Stale workspace identification, dry-run logic, delegation to workspace removal semantics, database skipping behavior, and lock acquisition. |
| `tests/Feature/Commands/AppPruneCommandTest.php` | CLI contract: arguments, options, destructive consent, interactive prompts, renderer selection, `workspace:remove` warning propagation, and warning payload shape for `success.meta.warnings[]`. |
| `tests/E2E/Ephemeral/AppPruneTest.php` | End-to-end execution with real agent IDE adapters (mocked or ephemeral) and node-side artifact verification. |
