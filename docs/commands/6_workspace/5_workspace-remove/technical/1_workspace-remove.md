# `workspace:remove` Technical Contract

[Back to public page](../workspace-remove.md)

**Owner:** `workspace`.

**Effects:** `destructive`, `write`, `stream`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The target workspace exists in the gateway workspaces registry.
- The current node identity is authorized to manage the resolved workspace or its parent app.
- The gateway uses SSH to the owning app node for artifact cleanup when
  available. SSH reachability is not a pre-intent prerequisite; if cleanup
  cannot finish after workspace intent removal, the command succeeds with
  structured warnings.

This is the canonical technical contract for the `workspace:remove` command. It
owns the signature, input resolution, behavior, and failure semantics for the
primary destructive command of the workspace family. It removes
gateway-owned workspace intent and its derived app-node artifacts.

## Signature

```bash
orbit workspace:remove [name] [--app=<app>] [--keep-files] [--force] [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `name` | `[name]` | When CWD is not inside a registered workspace path. | Never. | None. | Workspace name or slug. Must resolve to exactly one gateway workspace record (with `--app` for cross-app disambiguation). |
| `app` | `--app=<app>` | When `name` resolves to more than one workspace across apps. | Never. | None. | Parent app slug. Used to disambiguate the workspace lookup. |
| `keep_files` | `--keep-files` | Optional. | Never. | `false`. | Boolean flag. When `true`, the worktree directory is left on the app node after intent removal. |
| `force` | `--force` | Non-interactive input mode, or when an interactive caller wants to skip the confirmation prompt. | Never. | `false`. | Boolean flag. Explicit destructive consent. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode according to the shared invocation model. |

## Caller Role Behavior

`workspace:remove` is destructive cleanup. It follows the workspace domain
[Workspace Caller Role Rule](../../README.md#workspace-caller-role-rule):
control and gateway callers may remove workspaces when authorized; app-node
callers are denied before prompts or side effects.

| Caller role | Behavior |
| --- | --- |
| `control` | Forwards the removal request to the gateway over HTTPS through WireGuard when configured and authorized. |
| `gateway` | Executes the removal flow locally on the gateway when authorized. |
| `app` | Invalid for destructive workspace removal. Fail before prompts or side effects. |
| `unknown` | Invalid local context. Fail before prompts or side effects. |

## Input Resolution

1. **Caller Role:** Resolve local caller role from `general.local_node_role`.
   Unset/null resolves to `control`. Deny `app` and `unknown` before prompts
   or side effects.
2. **Target Resolution:**
   - If `name` is provided, resolve it against the gateway workspace registry,
     using `--app` to disambiguate when necessary.
   - If `name` is omitted, inspect the current working directory (CWD). If CWD
     is inside a registered workspace path, use that workspace's name and app.
     If CWD is not inside any registered workspace, fail before side effects
     with `error.code=workspace.unresolved_cwd`.
3. **Self-Targeting Detection:** If the caller's CWD is inside the resolved
   workspace's worktree, mark the invocation as self-targeting so the renderer
   can swap to the self-targeting prompt label.
4. **Destructive Consent:**
   - If `--force` is present, consent is granted.
   - If `--force` is absent and the input mode is interactive, the renderer
     must prompt for consent.
   - If `--force` is absent and the input mode is non-interactive (including
     `--json`), the command fails before side effects.

## Input Mode Contracts

- [`5.1_workspace-remove_input-mode_interactive.md`](5.1_workspace-remove_input-mode_interactive.md)
- [`5.2_workspace-remove_input-mode_non-interactive.md`](5.2_workspace-remove_input-mode_non-interactive.md)

## Behavior Contract

`workspace:remove` is a destructive-write command with cross-family cleanup
across `proxy_route`, `process`, and the workspace's own node-side
artifacts. The contract follows the resolved `app:remove` atomicity boundary:
gateway intent removal is the point of no return, and any node-side residue
afterwards is non-fatal drift.

### 1. Pre-flight

- Resolve target workspace per [Input Resolution](#input-resolution).
- Check authorization for the resolved workspace (or its parent app).
- If self-targeting (caller is inside the resolved workspace's worktree), warn
  the operator that their shell's working directory will be invalidated unless
  `--keep-files` is also set.

### 2. Destructive Consent

- Prompt for confirmation unless `--force` is supplied.
- The interactive prompt must list the major dependent artifacts that will be
  removed (proxy routes, inherited processes, FPM pool, teardown steps,
  worktree).
- When `--keep-files` is present, the prompt body must explicitly state that
  the worktree will be preserved on the app node.

### 3. Execution Sequence

The execution sequence has two phases. Phase A is the atomic gateway-intent
removal (the point of no return). Phase B is node-side enactment over SSH and
its sub-order is dictated by traffic, dependency, and lifecycle safety.

#### Phase A — Gateway intent (atomic, point of no return)

- **Step 1: Dependent intent rows.** Delete workspace-owned `proxy_route`
  records.
- **Step 2: Workspace record.** Delete the gateway `workspace` row.

Phase A commits as one atomic database transaction. After Phase A succeeds,
the workspace record is gone from gateway workspace registry scope by
definition.

#### Phase B — Node-side enactment (over `RemoteShell` SSH)

- **Step 3: Stop traffic.** Re-render the proxy backend so the workspace
  hostname stops serving requests.
- **Step 4: Stop processes.** Stop and disable inherited process units for
  this workspace. Parent app process definitions are not modified.
- **Step 5: Run teardown steps.** Execute configured workspace teardown steps
  on the app node. The worktree is still present and processes are stopped at
  this point so teardown scripts see a stable workspace lifecycle environment.
- **Step 6: Remove FPM pool.** Remove the workspace PHP-FPM pool config and
  reload PHP-FPM.
- **Step 7: Remove worktree.** Remove the workspace worktree directory on the
  app node. Skipped when `--keep-files` is set.

Each Phase B step reports an outcome of `removed`, `already_absent`, or
`failed`. `already_absent` is a clean step (registry intent and node reality
have converged) and is not surfaced as a warning. `failed` becomes a
structured warning under `success.meta.warnings[]` and the next step still
runs.

### 4. Convergence and Drift

- Once Phase A succeeds, the workspace disappears from registry-backed
  workspace command output.
- Remaining Orbit-owned artifacts that failed to clean up are reported as
  orphaned drift by the affected family doctor:
  - Orphaned worktree or FPM pool: `workspace.artifact_extra` (handled by
    [`workspace-doctor.md`](../../workspace-doctor.md)).
  - Orphaned proxy routes: `proxy_route.route_extra` (handled by the
    `proxy_route` family doctor).
  - Orphaned process: `process.runtime_unit_extra` (handled by the `process`
    family doctor).
- Workspace-owned databases are out of scope for this contract revision; see
  [Behavior Invariants](#behavior-invariants).

## Behavior Invariants

1. **Registry First:** Phase A (gateway intent removal of workspace-owned
   `proxy_route` rows and the `workspace` row) commits before any Phase B
   node-side cleanup begins.
2. **Parent App Integrity:** Removing a workspace must not remove or modify
   process definitions, FPM configuration, or proxy routes owned by the
   parent app.
3. **Worktree Cleanup:**
   - If `--keep-files` is `false`, Step 7 deletes the workspace directory on
     the app node.
   - If `--keep-files` is `true`, Step 7 is skipped and the worktree is left
     untouched.
4. **Teardown Steps:** Configured teardown steps run before FPM and worktree
   removal so they observe a stable workspace lifecycle environment.
5. **Authorization:**
   - Control and gateway callers must be authorized to manage the target
     workspace or its parent app.
   - App-node callers are denied before prompts or side effects.
6. **Idempotence Boundary:**
   - If the workspace record exists, the command proceeds.
   - If the workspace record is absent, the command fails with
     `workspace.not_found`. Already-absent removal is not a successful
     idempotent no-op.
   - If Phase A succeeds and Phase B partially fails, the command reports
     `success` with structured warnings.
7. **Database Cleanup Out of Scope:** Workspace-owned databases are not
   tracked in gateway intent in this contract revision and are never removed
   by `workspace:remove`. Any database encountered must be reported as
   `skipped` (manual cleanup); ownership must not be inferred from name,
   environment file, convention, or setup-step side effect. Express database
   cleanup as a workspace teardown step.
8. **CWD Drift:** When the workspace exists in gateway intent but the worktree
   is missing on the app node (or any other node-side artifact has already
   been removed out of band), the corresponding Phase B step reports
   `already_absent` and the command still completes Phase A and remaining
   Phase B steps. Registry intent wins; absent node-side artifacts are not
   warnings.

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| Workspace not found | Resolved `name`/`--app` does not match an existing workspace record. Already-absent removal is not idempotent. | Failure (`error.code=workspace.not_found`). |
| Ambiguous workspace name | `name` matches multiple workspaces and `--app` was not supplied. | Failure (`error.code=workspace.ambiguous_name`). |
| `name` omitted and CWD not a workspace | CWD-based resolution found no registered workspace. | Failure (`error.code=workspace.unresolved_cwd`). |
| Missing destructive consent | Non-interactive input mode and `--force` is absent. | Failure (`error.code=validation_failed`, `error.meta.field=force`). |
| Cancelled confirmation | Interactive mode where the operator declines the prompt. | Failure (`error.code=validation_failed`, `error.meta.field=force`). |
| Caller role not allowed | The caller role is `app` or `unknown`. | Failure (`error.code=caller_role_not_allowed`). |
| Gateway unavailable | A control caller has no configured gateway or cannot reach the gateway API. | Failure (`error.code=gateway_unavailable`). |
| Authorization failed | A forwarded control caller is not authorized to operate on the resolved workspace. | Failure (`error.code=authorization_failed`). |
| Phase A (gateway intent) failure | Deleting workspace-owned `proxy_route` rows or the `workspace` row itself fails. No node-side side effects have occurred. | Failure (`error.code=workspace.removal_failed`). |

Partial cleanup is **not** a command failure. Once Phase A succeeds, the
workspace record is gone from gateway workspace registry scope by definition.
Any failure during Phase B (node-side artifact cleanup) is reported as
`success` with a structured warning per affected family in
`success.meta.warnings[]`. Each warning carries `code`, `family`, `message`,
and `next_command` (typically `doctor --family=<family> --fix`). The exit code
remains `0`; the warnings are the machine-readable signal.

This atomicity boundary matches the resolved
[`app:remove`](../../../5_app/6_app-remove/technical/1_app-remove.md) and
[`node:remove`](../../../1_node/8_node-remove/technical/1_node-remove.md)
exemplars: gateway-owned intent removal is the point of no return, and
leftover node-side artifacts are convergence drift owned by the affected
family doctor — not a removal failure.

## Doctor Relationship

- Removed workspaces must no longer appear in registry-backed workspace command
  output.
- Workspace-owned artifacts remaining after a failed cleanup are detected as
  orphaned workspace drift by [`workspace-doctor.md`](../../workspace-doctor.md)
  and the affected family doctors:
  - `workspace.artifact_extra` — orphaned worktree or FPM pool
    (`doctor --family=workspace --fix`).
  - `proxy_route.route_extra` — orphaned workspace-owned proxy route
    (`doctor --family=proxy_route --fix`).
  - `process.runtime_unit_extra` — orphaned inherited process unit
    (`doctor --family=process --fix`).
- `workspace:remove` does not duplicate per-family drift item shapes; it
  points operators at the affected `doctor --family=<family> --fix` via the
  warning's `next_command`.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `tests/Feature/Actions/Workspaces/RemoveWorkspaceActionTest.php` | Phase A atomic intent removal, Phase B step ordering, `--keep-files` worktree preservation, partial cleanup warnings, and teardown step execution. |
| `tests/Feature/Concerns/ResolveWorkspaceFromCwdTest.php` | CWD-to-workspace resolution, self-targeting detection, and unresolved-CWD failure. |
| `tests/Unit/Actions/Workspaces/TeardownStepRunnerTest.php` | Teardown step ordering and execution environment. |
| `tests/Feature/Commands/Workspaces/WorkspaceRemoveCallerRoleTest.php` | Control and gateway caller allowance, app-node denial before prompts or side effects, and unknown-role failure. |
| `tests/E2E/Ephemeral/WorkspaceRemoveTest.php` | End-to-end `workspace:remove` flow with SSH artifact cleanup, `--keep-files`, `--force`, JSON envelope validation, and warning payload shape for `success.meta.warnings[]`. |
