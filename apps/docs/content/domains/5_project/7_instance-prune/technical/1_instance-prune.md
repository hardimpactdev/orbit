# Technical Contract: `orbit instance:prune [project.instance]`

[Back to public `instance:prune` documentation.](../instance-prune.md)

**Owner:** `instance`.

**Effects:** `destructive`, `write`, `stream`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The target resolves to one eligible visible instance.
- The caller has `instance:prune` on that instance's serving node.
- The caller is not `app-prod`, and the instance is served by active `app-dev`.
- The instance has an effective agent IDE source (instance override, serving-node default, or extension).

## Signature

```bash
orbit instance:prune [instance] [--dry-run] [--force] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `instance` | `[instance]` | Always. | Never. | None. | Dotted instance selector. Bare logical shorthand succeeds only for exactly one eligible visible instance; hostnames are invalid. |
| `dry_run` | `--dry-run` | Optional. | Never. | `false`. | Boolean flag. If `true`, side effects are skipped. |
| `force` | `--force` | Non-interactive mode (without `--dry-run`). | Never. | `false`. | Boolean flag. Explicit destructive consent. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive mode. |

## Input Resolution

1. **Resolve Instance:** From `[instance]` or current context. Prompt in interactive
   mode if missing. Ambiguous bare logical input fails with
   `validation_failed`, `meta.reason=instance_required`.
2. **Authorize:** Require `instance:prune` on the instance's serving node and verify
   the instance is an active `app-dev` placement.
3. **Resolve Dry Run:** From `--dry-run`.
4. **Resolve Destructive Consent:**
   - If `dry_run` is `true`, no destructive consent is needed. `--force` is ignored.
   - If `dry_run` is `false`:
     - In interactive mode, prompt for confirmation unless `--force` is present.
     - In non-interactive mode, fail if `--force` is missing.

## Input Mode Contracts

- [`5.1_instance-prune_input-mode_interactive.md`](5.1_instance-prune_input-mode_interactive.md)
- [`5.2_instance-prune_input-mode_non-interactive.md`](5.2_instance-prune_input-mode_non-interactive.md)

## Behavior Contract

`--dry-run` is intentionally part of `instance:prune`, not a convention shared with other destructive commands. This command discovers its destructive target set from
external agent IDE adapter state; previewing that computed set is a distinct
read operation before workspace removal side effects begin. Commands that
operate on an explicit named target, such as `project:remove` or
`workspace:remove`, continue to rely on confirmation/`--force` instead of a
generic dry-run contract.

Both dry-run and destructive pruning are workspace operations. The gateway
rejects an `app-prod` caller or target with
`workspace.unsupported_for_production` before adapter discovery, workspace
registry reads, locking, or cleanup.

### 1. Source Discovery
- Resolve the effective agent IDE sources for the selected instance using the
  architecture resolution chain (instance explicit setting → serving-node default →
  none). Workspace-level overrides are not part of the current resolution.
- `instance:prune` does not consult any "previous adapter" state. Adapter switches
  are owned by `instance:agent-ide`, which identifies previous-adapter cleanup
  targets before writing the new instance adapter and then removes those stale
  workspaces after the configuration write. The instance-scoped lock (see Concurrency)
  prevents an adapter switch from observing or being observed by `instance:prune`.
- Query the resolved adapters for the current list of active workspaces.
  When more than one discovery source is effective for the instance (for example, when an
  installed extension contributes additional discovery sources alongside a
  core adapter), the union of their reported workspaces is the source of
  truth.

### 2. Stale Identification
- List only workspaces owned by the selected instance.
- A workspace is "stale" only when **none** of the queried effective
  adapters report it. If at least one adapter still reports the workspace,
  it is not stale.

### 3. Cleanup Execution
If `dry_run` is `false`:
- Acquire the instance-scoped lock (see Concurrency) for the duration of the
  cleanup pass.
- For each stale workspace:
  - Apply the normal
    [`workspace:remove`](../../../6_workspace/5_workspace-remove/technical/1_workspace-remove.md)
    semantics with destructive consent already satisfied by `instance:prune`.
  - Phase A deletes workspace-owned proxy route rows and the `workspace`
    row in one gateway transaction.
  - Phase B runs the workspace removal cleanup order: stop traffic, stop
    inherited processes, run teardown steps, remove the workspace runtime container, and
    remove the worktree.
  - Agent reachability is not a pre-prune prerequisite. Residual cleanup is
    attempted through Agent push.
  - A Phase B step that cannot finish after workspace configuration removal still completes the workspace removal with warnings.
  - Partial Phase B failures become `success.meta.warnings[]` using the same
    family warning vocabulary and `next_command` handoffs as
    `workspace:remove`.
  - **Database Cleanup Current Limitation:** Databases are not removed by this
    contract revision. Per `architecture.md` §Apps, database cleanup is allowed only
    for databases explicitly tracked by Orbit as workspace-owned, and no
    such tracking mechanism exists in gateway configuration today. Every database
    encountered must be reported as `skipped` (manual cleanup) regardless
    of name, environment file, convention, or setup-step side effect.
    User-authored database removal can be expressed as a workspace
    teardown step today.

### 4. Concurrency
- `instance:prune` takes an instance-scoped lock for the duration of the run. The
  lock serializes `instance:prune` against concurrent `workspace:new`,
  `workspace:remove`, `instance:agent-ide`, and other `instance:prune` runs for the
  same instance. This guarantees that adapter resolution, workspace listing,
  and stale workspace removal observe a consistent instance state.

### 5. Convergence
- `instance:prune` is source-of-truth cleanup.
- It is NOT drift repair. Drift, such as leftover files, routes, or runtime
  units from a failed workspace removal Phase B step, is handled by the affected
  family doctor.

## Renderer Contracts

- [Human renderer](6.1_instance-prune_output-render_human.md)
- [JSON renderer](6.2_instance-prune_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Instance required | Bare logical input resolves zero or multiple eligible visible instances. | Failure (`error.code=validation_failed`, `error.meta.reason=instance_required`). |
| Instance not found | Dotted selector does not match an instance. | Failure (`error.code=instance.not_found`). |
| Production workspace boundary | The caller has `app-prod`, or the selected instance is served by `app-prod`. | Failure (`error.code=workspace.unsupported_for_production`) before adapter discovery. |
| No adapters | No effective agent IDE source exists for the instance. | Failure (`error.code=instance.no_agent_ide_adapter`). |
| Adapter query failed | Error communicating with a source-of-truth adapter. | Failure (`error.code=instance.agent_ide_query_failed`). |
| Destructive consent missing | Non-interactive mode, no `--dry-run`, and `--force` is missing. | Failure (`error.code=validation_failed`, `error.meta.field=force`). |
| Partial removal | One or more `workspace:remove` Phase B cleanup steps failed after gateway configuration removal. | Success with structured `success.meta.warnings[]` using `workspace:remove` warning semantics. |

## Doctor Relationship

- `instance:prune` cross-references [`instance-doctor.md`](../../instance-doctor.md) for instance-level drift.
- Stale workspace removal delegates to
  [`workspace:remove`](../../../6_workspace/5_workspace-remove/workspace-remove.md).
  Any drift created by failed Phase B cleanup is reported by the same family
  doctors and warning handoffs documented there.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Actions/Apps/PruneAppWorkspacesActionTest.php` | Stale workspace identification, dry-run logic, configured-adapter failure, explicit adapter selection, and delegated workspace removal outcome. |
| `apps/cli/tests/Feature/Commands/App/AppWriteCommandTest.php` | CLI prune POST, interactive destructive consent, human/json output, and dry-run tree. |
| `apps/gateway/tests/Feature/Http/Api/AppPruneControllerTest.php` | Gateway API authorization, dry-run response shape, and structured authorization failures. |

Database skipping, lock acquisition, and warning payload shape remain coverage
gaps until focused tests land.
