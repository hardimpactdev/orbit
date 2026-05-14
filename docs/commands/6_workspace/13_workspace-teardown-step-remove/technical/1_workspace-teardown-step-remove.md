# Technical Contract: `orbit workspace-teardown-step:remove`

[Back to public `workspace-teardown-step:remove` documentation.](../workspace-teardown-step-remove.md)

**Owner:** `workspace`.

**Effects:** `destructive`, `write`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The current node identity is authorized to manage workspace policy for the
  target app.
- The target app exists in gateway configuration.
- The target step exists for the resolved `(app, phase=teardown)`.

This is the canonical technical contract for
`workspace-teardown-step:remove`. It owns the signature, input resolution,
behavior, and failure semantics.

## Signature

```bash
orbit workspace-teardown-step:remove --step=<id> [--app=<app>] [--force] [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `step` | `--step` | Always. | Never. | None. | Strict positive integer. Must reference an existing teardown-step record belonging to the resolved app and `phase=teardown`. |
| `app` | `--app` | No local context resolves to a parent app. | Never. | Cwd-inferred parent app. | Existing app slug authorized for this caller. |
| `force` | `--force` | Non-interactive input mode, or when an interactive caller wants to skip the confirmation prompt. | Never. | `false`. | Boolean flag. Explicit destructive consent. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode according to the shared invocation model. |

## Input Resolution

1. **Resolve Parent App.** Mirror the resolved
   [`workspace:new`](../../1_workspace-new/workspace-new.md) and
   [`workspace-teardown-step:add`](../../11_workspace-teardown-step-add/workspace-teardown-step-add.md)
   precedence chain:
   - Explicit `--app=<slug>`.
   - `.orbit/config` marker on the caller filesystem that names the owning app
     slug.
   - Gateway path-ownership lookup keyed on `(caller node identity,
     absolute cwd)` that returns the app slug whose registered app path or any
     registered workspace path contains the caller's cwd.
   - Interactive prompt in interactive mode; non-interactive failure with
     `error.code=validation_failed`, `error.meta.field=app`.
   - **Forbidden:** project-file inspection (`composer.json`, `package.json`,
     `.php-version`, `.env`, lockfiles, or framework manifests). This matches `ARCHITECTURE.md` "Workspaces"
     project-file inspection prohibition.
2. **Resolve `step`.** Required from `--step` or interactive prompt.
   - In interactive mode, prompt when `--step` is missing.
   - In non-interactive mode, fail before side effects when `--step` is absent
     (`error.code=validation_failed`, `error.meta.field=step`).
   - Must be a strict positive integer.
   - Must match an existing teardown-step record belonging to the resolved app
     and `phase=teardown`.
3. **Resolve `force`.** From `--force`. Default `false`.
4. **Apply Destructive Consent.**
   - If `--force` is present, destructive consent is resolved and no
     confirmation prompt is rendered.
   - In interactive mode without `--force`, render a confirmation prompt after
     the target step is valid. If the operator cancels, fail before side
     effects.
   - In non-interactive mode without `--force`, fail before side effects with
     `error.code=validation_failed`, `error.meta.field=force`.

## Input Mode Contracts

- [Interactive input mode](5.1_workspace-teardown-step-remove_input-mode_interactive.md)
- [Non-interactive input mode](5.2_workspace-teardown-step-remove_input-mode_non-interactive.md)

## Behavior Contract

### Teardown Step Removal Rules

`workspace-teardown-step:remove` deletes a single gateway-owned teardown-step
record from an app's workspace lifecycle policy and compacts the surviving
steps' `order` to a continuous sequence. The command writes only to gateway
configuration. App nodes are not contacted.

1. **Lookup.** Find the teardown-step record by
   `(step_id, app, phase=teardown)`. If not found, fail before side effects with
   `error.code=workspace.step_not_found`. Already-absent removal is not an
   idempotent success.
2. **Destructive Consent.** Apply the destructive consent rules from the
   selected input mode.
3. **Atomic Edit.** Within a single gateway transaction, scoped by an
   app-scoped policy-edit lock that serializes only concurrent policy-table
   edits:
   - Capture the removed record's `(id, app, phase, order, command,
     timeout_seconds)` tuple for the response payload.
   - Delete the teardown-step record from the gateway registry.
   - Decrement the `order` of every surviving step in the same
     `(app, phase=teardown)` whose original `order` was greater than the
     removed step's `order` by exactly one. The surviving sequence is
     contiguous from `1` to `N`.
4. **Report.** Return the removed step record, the action verb (`removed`), and
   the new total step count for `(app, phase=teardown)`.

### Scope Boundaries

`workspace-teardown-step:remove` must not:

- Block on, wait for, or fail because of an active `workspace:remove` or
  `app:prune` for the same app. The mutation is gateway-configuration only
  and takes effect for future runs. In-flight runs continue executing the ordered step
  list they snapshotted at `phase=teardown_steps` entry. The snapshot
  obligation is owned by [`workspace:remove`](../../5_workspace-remove/workspace-remove.md)
  and app pruning, not by this command.
- Mutate `workspace_runs` or `workspace_step_runs` records.
- Undo filesystem side effects, database cleanup, or any artifact produced by
  previous executions of the removed step on app nodes. Past executions remain
  visible in [`workspace:history`](../../6_workspace-history/workspace-history.md).
- Read project files (`composer.json`, `package.json`, `.php-version`, `.env`,
  lockfiles, or framework manifests) during parent-app inference.
- Remove a step belonging to `phase=setup`. Setup steps are owned by
  [`workspace-setup-step:remove`](../../10_workspace-setup-step-remove/workspace-setup-step-remove.md).

## Renderer Contracts

- [Human renderer](6.1_workspace-teardown-step-remove_output-render_human.md)
- [JSON renderer](6.2_workspace-teardown-step-remove_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Missing step ID | `--step` is absent in non-interactive mode. | Failure (`error.code=validation_failed`, `error.meta.field=step`). |
| Step not a positive integer | `--step` is non-numeric, zero, or negative. | Failure (`error.code=validation_failed`, `error.meta.field=step`, `error.meta.reason=must_be_positive_integer`). |
| Step not found | No teardown-step record matches `(step_id, app, phase=teardown)`. Already-absent removal is not idempotent. | Failure (`error.code=workspace.step_not_found`, `error.meta.{step_id, app}`). |
| App not found | Resolved app slug does not exist in gateway configuration. | Failure (`error.code=workspace.app_not_found`, `error.meta.app`). |
| App unresolved | Parent app cannot be resolved from `--app`, `.orbit/config`, or gateway path-ownership lookup, and prompting is disabled. | Failure (`error.code=validation_failed`, `error.meta.field=app`). |
| Missing destructive consent | Non-interactive input mode and `--force` is absent. | Failure (`error.code=validation_failed`, `error.meta.field=force`). |
| Cancelled confirmation | Interactive mode where the operator declines the prompt. | Failure (`error.code=validation_failed`, `error.meta.field=force`). |

### Exit Status

Uses the shared exit status policy. Success exits `0`; all documented command
failures exit with the standard command failure status (`1`). This command
defines no numeric exit codes specific to it.

## Doctor Relationship

Teardown-step definitions are gateway-owned workspace policy.
[`doctor --family=workspace`](../../workspace-doctor.md) does not create,
remove, or reorder step definitions and does not undo side effects from prior
step runs. It verifies workspace runtime reality and assumes the step policy is
the source of truth for setup and teardown. `workspace-teardown-step:remove` is
the only path that deletes a teardown-step record; doctor will not converge an
absent step into existence or a present step out of existence.

## Activity Logging

The gateway API endpoint emits an activity entry for successful and failed
teardown-step removal attempts.

| Field | Value |
| --- | --- |
| Type | `api:DELETE /workspaces/steps/{phase}/{step}` |
| Effect | `destructive` |
| Subject | `WorkspaceStep` when the teardown step is resolved before deletion; `none` for validation, not-found, or authorization failures before a step can be logged. |
| Properties | No command-specific properties. The API activity middleware adds transport context such as method, path, client, and serving gateway node. |
| Description | derived |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `tests/Feature/Actions/Workspaces/RemoveTeardownStepActionTest.php` | Atomic delete and order-compaction within `(app, phase=teardown)`, refusal to remove a `phase=setup` step, and rejection of step records that do not belong to the resolved app. |
| `tests/Feature/Commands/Workspaces/WorkspaceTeardownStepRemoveCommandTest.php` | Input resolution, `--step` validation, step-not-found as hard validation failure, destructive consent, `--json` never implying `--force`, no runtime lock against in-flight `workspace:remove`, and no history mutation. |
| `tests/Feature/Commands/Workspaces/WorkspaceTeardownStepRemoveCallerRoleTest.php` | Control and gateway peer acceptance plus app-node peer `caller_role_not_allowed` rejection before any side effects, asserted via gateway-applied authorization. |
| `tests/E2E/Ephemeral/WorkspaceTeardownStepRemoveTest.php` | Real gateway delete against a registered app with teardown steps, contiguous renumbering, JSON envelope alignment, and confirmation that an in-flight teardown run continues using its start-of-phase snapshot. |
