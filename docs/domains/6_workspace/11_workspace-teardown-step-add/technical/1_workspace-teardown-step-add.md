# Technical Contract: `orbit workspace-teardown-step:add`

**Owner:** `workspace`.

**Effects:** `write`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The current node identity is authorized to manage workspace policy for the
  target app.
- The target app exists in gateway configuration.

[Back to the public command page.](../workspace-teardown-step-add.md)

This command registers a gateway-owned teardown step for an app's workspace
lifecycle. It mirrors `workspace-setup-step:add` exactly except for the
lifecycle phase (`teardown` vs `setup`) and the read sites
(`workspace:remove` and `app:prune` instead of `workspace:new` and
`workspace:setup`).

## Signature

```bash
orbit workspace-teardown-step:add --command=<command> [--app=<app>] [--before=<id> | --after=<id>] [--timeout=<seconds>] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Primitive | Required when | Default | Validation |
| --- | --- | --- | --- | --- |
| `--command` | `text` | Always. | n/a | Non-empty shell command. |
| `--app` | `text` | No local context resolves to a parent app. | Cwd-inferred parent app | Existing app slug authorized for this caller. |
| `--before` | `integer` | Optional. Mutually exclusive with `--after`. | n/a | Positive integer. Must reference an existing teardown step belonging to the same app and `phase=teardown`. |
| `--after` | `integer` | Optional. Mutually exclusive with `--before`. | n/a | Positive integer. Must reference an existing teardown step belonging to the same app and `phase=teardown`. |
| `--timeout` | `integer` | Optional. | `600` | Strict positive integer (`>= 1`). `0` is rejected before side effects with `error.code=validation_failed`, `error.meta.field=timeout`. |
| `--json` | `flag` | Optional. | `false` | n/a |

## Input Resolution

1. **Resolve Command**: Resolve `--command` from flag or interactive prompt.
2. **Resolve Parent App**: Mirror the `workspace:new` precedence chain:
   - Explicit `--app=<slug>`.
   - `.orbit/config` marker on the caller filesystem (installed by `app:new` /
     `app:register` and any workspace-installed marker) that names the owning
     app slug.
   - Gateway path-ownership lookup keyed on `(caller node identity, absolute
     cwd)` that returns the app slug whose registered app path or any
     registered workspace path contains the caller's cwd.
   - Interactive prompt in interactive mode; non-interactive failure with
     `error.code=validation_failed`, `error.meta.field=app`.
   - **Forbidden**: `workspace-teardown-step:add` must not read
     `composer.json`, `package.json`, `.php-version`, or any other project
     file content during parent-app inference. This matches the
     `workspace:new` contract and `architecture.md` "Workspaces" project-file
     inspection prohibition.
3. **Validate Position**:
   - `--before` and `--after` are mutually exclusive. Supplying both fails
     with `error.code=workspace.invalid_position`.
   - When supplied, the referenced ID must be a positive integer.
   - The referenced step must exist, belong to the resolved app, and have
     `phase=teardown`.
4. **Validate Timeout**: `--timeout` must be a strict positive integer. `0`
   is rejected.

## Input Mode Contracts

- [`5.1_workspace-teardown-step-add_input-mode_interactive.md`](5.1_workspace-teardown-step-add_input-mode_interactive.md)
- [`5.2_workspace-teardown-step-add_input-mode_non-interactive.md`](5.2_workspace-teardown-step-add_input-mode_non-interactive.md)

## Behavior Contract

### Teardown Step Addition Rules

`workspace-teardown-step:add` writes a single teardown step record owned by
the gateway for an app's workspace lifecycle. The step is *not* executed during
this command; it is applied by `workspace:remove` and `app:prune` at the
teardown phase, before destructive workspace cleanup.

1. **Registry Write**: Creates one new record in the gateway workspace
   teardown step policy with the resolved
   `(app, phase=teardown, command, timeout_seconds)` tuple. The new record
   receives a freshly assigned numeric `id`.
2. **Phase Assignment**: Phase is automatically `teardown`. There is no
   per-record override; setup steps are owned by
   [`workspace-setup-step:add`](../../8_workspace-setup-step-add/workspace-setup-step-add.md).
3. **Order Calculation**:
   - `--before=<id>`: New step receives the referenced step's `order`. The
     referenced step and all subsequent steps in `(app, phase=teardown)`
     are incremented by one.
   - `--after=<id>`: New step receives `order + 1` of the referenced step.
     All subsequent steps are incremented by one.
   - Both omitted: New step is appended at the end of the existing list
     with `order = max(existing_order_for_app_and_phase) + 1` (or `1` if no
     teardown steps exist yet).
4. **Step-Record Shape**: The persisted record exposes
   `{ id, app, phase, order, command, timeout_seconds }`. Steps have no
   `name`, no per-step `working_directory`, no `env_overrides`, and no
   per-step `on_failure` knob. Working directory is pinned to the workspace
   path on the owning node and exposed through `ORBIT_WORKSPACE_PATH`
   (see the [Workspaces README](../../README.md#lifecycle-step-environment)).
5. **Idempotence**: This command is *additive*. Running the same `add` twice
   creates two separate step records (each with its own `id`). There is no
   convergence by `command` text because steps are identified by `id`.
6. **No Runtime Lock**: The command never blocks on, or aborts because of,
   in-flight `workspace:remove` / `app:prune` runs. The new step takes
   effect on the next teardown pipeline run that begins after the gateway
   commit. Steps already executing in an in-flight run use the policy
   snapshot the runner read at teardown-phase entry. Recovery from
   policy/runtime drift is the doctor's job, not this command's.
7. **No Filesystem Side Effects**: The command writes only to gateway
   configuration. Nodes are not contacted.
8. **Consumer Failure Semantics**: When a teardown step fails during
   `workspace:remove` or `app:prune`, the failure is reported as a
   structured non-fatal warning under `success.meta.warnings[]` of the
   consumer command (code `workspace.teardown_step_failed`). Subsequent
   teardown steps still run, and Phase B continues with runtime container and worktree
   removal. This contract is owned by `workspace:remove` and `app:prune`;
   `workspace-teardown-step:add` itself never observes the warning.
9. **Lifecycle Ordering Guarantees**: Teardown steps run before runtime container removal
   and worktree removal during `workspace:remove` Phase B, so they observe
   `ORBIT_WORKSPACE_PATH`, `ORBIT_APP_PATH`, and any workspace database
   intact. Workspace databases are explicitly left untouched by Orbit
   itself; teardown steps are the designated place for database cleanup.
   Public traffic to the workspace URL has already been cut earlier in
   Phase B; teardown scripts that need the workspace's HTTP surface must
   target `127.0.0.1` or runtime container directly.

## Renderer Contracts

- [`technical/6.1_workspace-teardown-step-add_output-render_human.md`](6.1_workspace-teardown-step-add_output-render_human.md)
- [`technical/6.2_workspace-teardown-step-add_output-render_json.md`](6.2_workspace-teardown-step-add_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

- **App Not Found**: Resolved app slug does not exist in gateway configuration
  (`error.code=workspace.app_not_found`, `error.meta.app`).
- **Invalid Position**: Both `--before` and `--after` supplied
  (`error.code=workspace.invalid_position`,
  `error.meta.{before, after}`).
- **Step Not Found**: Referenced `--before` / `--after` ID does not exist
  for the resolved app and `phase=teardown`
  (`error.code=workspace.step_not_found`,
  `error.meta.{id, app, phase=teardown}`).

### Exit Status

Uses the shared exit status policy. Success exits `0`; all documented command
failures exit with the standard command failure status (`1`). This command
defines no numeric exit codes specific to it.

## Doctor Relationship

- `workspace-teardown-step:add` writes gateway-owned teardown policy.
  [`doctor --family=workspace`](../../workspace-doctor.md) does not create,
  remove, or reorder step definitions. It verifies workspace runtime
  reality and assumes the step policy is the source of truth.
- Family doctor behavior is documented in
  [`workspace-doctor.md`](../../workspace-doctor.md).

## Activity Logging

The gateway API endpoint emits an activity entry for successful and failed
teardown-step creation attempts.

| Field | Value |
| --- | --- |
| Type | `api:POST /workspaces/steps/{phase}` |
| Effect | `write` |
| Subject | `WorkspaceStep` when the teardown step is created; `none` for validation, app-resolution, or authorization failures before a step can be logged. |
| Properties | No command-specific properties. The API activity middleware adds transport context such as method, path, client, and serving gateway node. |
| Description | derived |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `tests/Feature/Actions/Workspaces/AddTeardownStepActionTest.php` | Registry write for `(app, phase=teardown, command, timeout_seconds)`, freshly assigned `id`, append-by-default order calculation, `--before` / `--after` insertion with subsequent-step renumbering, and rejection of step-record fields (`name`, `working_directory`, `env_overrides`, `on_failure`). |
| `tests/Feature/Commands/Workspaces/WorkspaceTeardownStepAddCommandTest.php` | Input resolution chain, `--before`/`--after` mutual exclusivity, `--timeout` positive validation including `0` rejection, additive re-run behavior, `success.meta.warnings[]` shape, and no runtime lock against in-flight `workspace:remove`. |
| `tests/Feature/Commands/Workspaces/WorkspaceTeardownStepAddCommandTest.php` | Gateway forwarding and `workspace:write` authorization failures before any side effects. |
| `tests/E2E/Ephemeral/WorkspaceTeardownStepAddTest.php` | Real gateway write against a registered app, append/insert/order verification for `phase=teardown`, and JSON envelope alignment. |
