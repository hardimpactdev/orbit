# Technical Contract: `orbit workspace-setup-step:add`

**Owner:** `workspace`.

**Effects:** `write`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The current node identity is authorized to manage workspace policy for the
  target app.
- The target app exists in gateway configuration.

[Back to the public command page.](../workspace-setup-step-add.md)

## Signature

```bash
orbit workspace-setup-step:add --command=<command> [--instance=<app.instance>] [--before=<id> | --after=<id>] [--timeout=<seconds>] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Primitive | Required when | Default | Validation |
| --- | --- | --- | --- | --- |
| `--command` | `text` | Always. | n/a | Non-empty shell command. |
| `--instance` | `text` | Unless the shared workspace selector chain resolves to a concrete instance. | Resolved through the shared workspace selector chain when omitted. | Dotted instance selectors such as `happie.nmbp` are the explicit safe write path. Bare app slugs are rejected with `error.meta.reason=instance_required`. |
| `--before` | `integer` | Optional. Mutually exclusive with `--after`. | n/a | Positive integer. Must reference an existing setup step belonging to the same app and `phase=setup`. |
| `--after` | `integer` | Optional. Mutually exclusive with `--before`. | n/a | Positive integer. Must reference an existing setup step belonging to the same app and `phase=setup`. |
| `--timeout` | `integer` | Optional. | `600` | Strict positive integer (`>= 1`). `0` is rejected before side effects with `error.code=validation_failed`, `error.meta.field=timeout`. |
| `--json` | `flag` | Optional. | `false` | n/a |

## State Model

The gateway owns workspace step policy. It stores only rows owned by an app
instance in `workspace_steps`, keyed by `(instance_id, phase, sort_order)`.

## Input Resolution

1. **Resolve Command**: Resolve `--command` from flag or interactive prompt.
2. **Resolve Instance**: Mirror the `workspace:new` precedence chain:
   - Explicit `--instance=<app.instance>`, which must be a dotted instance
     selector such as `happie.nmbp` for gateway writes. Bare app
     slugs are rejected with `error.meta.reason=instance_required`.
   - `.orbit/config` marker on the caller filesystem (installed by `app:new` /
     `instance:register` and any workspace-installed marker) that names the owning
     app slug.
   - Gateway path-ownership lookup keyed on `(caller node identity, absolute
     cwd)` that returns the app slug whose registered app path or any
     registered workspace path contains the caller's cwd.
   - Interactive prompt in interactive mode; non-interactive failure with
     `error.code=validation_failed`, `error.meta.field=instance`.
   - **Forbidden**: `workspace-setup-step:add` must not read `composer.json`,
     `package.json`, `.php-version`, or any other project file content during
     instance inference. This matches the `workspace:new` contract and
     `architecture.md` "Workspaces" project-file inspection prohibition.
3. **Validate Position**:
   - `--before` and `--after` are mutually exclusive. Supplying both fails
     with `error.code=workspace.invalid_position`.
   - When supplied, the referenced ID must be a positive integer.
   - The referenced step must exist, belong to the resolved app, and have
     `phase=setup`.
4. **Validate Timeout**: `--timeout` must be a strict positive integer. `0`
   is rejected.

## Input Mode Contracts

- [`5.1_workspace-setup-step-add_input-mode_interactive.md`](5.1_workspace-setup-step-add_input-mode_interactive.md)
- [`5.2_workspace-setup-step-add_input-mode_non-interactive.md`](5.2_workspace-setup-step-add_input-mode_non-interactive.md)

## Behavior Contract

### Setup Step Addition Rules

`workspace-setup-step:add` writes a single setup step record owned by the gateway
for one instance's workspace lifecycle. The step is *not* executed during
this command; it is applied by `workspace:new` and `workspace:setup` at
`phase=setup_steps`.

1. **Registry Write**: Creates one new instance-scoped record in the gateway
   workspace setup step policy with the resolved
   `(instance, phase=setup, command, timeout_seconds)` tuple. The new
   record receives a freshly assigned numeric `id`.
2. **Phase Assignment**: Phase is automatically `setup`. There is no
   per-record override; teardown steps are owned by
   [`workspace-teardown-step:add`](../../11_workspace-teardown-step-add/workspace-teardown-step-add.md).
3. **Order Calculation**:
   - `--before=<id>`: New step receives the referenced step's `order`. The
     referenced step and all subsequent steps in `(instance, phase=setup)` are
     incremented by one.
   - `--after=<id>`: New step receives `order + 1` of the referenced step.
     All subsequent steps are incremented by one.
   - Both omitted: New step is appended at the end of the existing list with
     `order = max(existing_order_for_instance_and_phase) + 1` (or `1` if no steps
     exist yet).
4. **Step-Record Shape**: The persisted record exposes
   `{ id, app, instance, phase, order, command, timeout_seconds }`. Steps have no
   `name`, no per-step `working_directory`, no `env_overrides`, and no
   per-step `on_failure` knob. Working directory is pinned to the workspace
   path on the owning node and exposed through `ORBIT_WORKSPACE_PATH`
   (see the [Workspaces README](../../README.md#lifecycle-step-environment)).
   Failure policy is the fail-fast contract shared across the family, resolved by
   `workspace:setup`.
5. **Idempotence**: This command is *additive*. Running the same `add` twice
   creates two separate step records (each with its own `id`). There is no
   convergence by `command` because steps are identified by `id`, not by
   command text.
6. **No Runtime Lock**: The command never blocks on, or aborts because of,
   in-flight `workspace:new` / `workspace:setup` runs. The new step takes
   effect on the next setup pipeline run that begins after the gateway
   commit. Steps already executing in an in-flight run use the policy
   snapshot the runner read at `phase=setup_steps` entry. Recovery from
   policy/runtime drift is the doctor's job, not this command's.
7. **No Filesystem Side Effects**: The command writes only to gateway
   configuration. Nodes are not contacted.

## Renderer Contracts

- [`technical/6.1_workspace-setup-step-add_output-render_human.md`](6.1_workspace-setup-step-add_output-render_human.md)
- [`technical/6.2_workspace-setup-step-add_output-render_json.md`](6.2_workspace-setup-step-add_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

- **Instance Required**: Bare app slug or path-only resolution without a
  concrete instance (`error.code=validation_failed`,
  `error.meta.field=instance`, `error.meta.reason=instance_required`).
- **Instance Not Found**: Resolved instance selector does not exist in gateway configuration
  (`error.code=instance.not_found`, `error.meta.instance`).
- **Production app unsupported**: The selected instance is served by an
  `app-prod` node (`error.code=workspace.unsupported_for_production`). No
  workspace lifecycle policy is stored.
- **Invalid Position**: Both `--before` and `--after` supplied
  (`error.code=workspace.invalid_position`,
  `error.meta.{before, after}`).
- **Step Not Found**: Referenced `--before` / `--after` ID does not exist
  for the resolved instance and `phase=setup`
  (`error.code=workspace.step_not_found`,
  `error.meta.{id, app, phase=setup}`).

### Exit Status

Uses the shared exit status policy. Success exits `0`; all documented command
failures exit with the standard command failure status (`1`). This command
defines no numeric exit codes specific to it.

## Doctor Relationship

- `workspace-setup-step:add` writes gateway-owned setup policy.
  [`doctor --family=workspace`](../../workspace-doctor.md) does not create,
  remove, or reorder step definitions. It verifies workspace runtime
  reality and assumes the step policy is the source of truth.
- Family doctor behavior is documented in
  [`workspace-doctor.md`](../../workspace-doctor.md).

## Activity Logging

The gateway API endpoint emits an activity entry for successful and failed
setup-step creation attempts.

| Field | Value |
| --- | --- |
| Type | `api:POST /workspaces/steps/{phase}` |
| Effect | `write` |
| Subject | `WorkspaceStep` when the setup step is created; `none` for validation, app-resolution, or authorization failures before a step can be logged. |
| Properties | No command-specific properties. The API activity middleware adds transport context such as method, path, client, and serving gateway node. |
| Description | derived |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Actions/Workspaces/WorkspaceStepActionsTest.php` | Setup-step append, insert, ordering, and independent phase behavior. |
| `apps/gateway/tests/Feature/Http/Api/WorkspaceStepStoreControllerTest.php` | Gateway setup-step registry write, ordering, timeout, and anchor validation. |
| `apps/cli/tests/Feature/Commands/Workspace/WorkspaceStepMutationCommandTest.php` | CLI setup-step add payload forwarding, human output, JSON phase field, and invalid-position gateway error prose. |
