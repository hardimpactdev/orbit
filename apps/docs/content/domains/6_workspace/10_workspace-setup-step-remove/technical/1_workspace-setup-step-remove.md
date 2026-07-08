# Technical Contract: `orbit workspace-setup-step:remove`

[Back to public `workspace-setup-step:remove` documentation.](../workspace-setup-step-remove.md)

**Owner:** `workspace`.

**Effects:** `destructive`, `write`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The current node identity is authorized to manage workspace policy for the
  target app.
- The target app exists in gateway configuration.
- The target step exists for the resolved `(app, phase=setup)`.

This is the canonical technical contract for `workspace-setup-step:remove`. It
owns the signature, input resolution, behavior, and failure semantics.

## Signature

```bash
orbit workspace-setup-step:remove --step=<id> [--app=<app>] [--force] [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `step` | `--step` | Always. | Never. | None. | Strict positive integer. Must reference an existing setup-step record belonging to the resolved app and `phase=setup`. |
| `app` | `--app` | No local context resolves to a parent app. | Never. | Cwd-inferred parent app. | Existing parent app slug or app-instance selector authorized for this caller. Dot notation such as `happie.nmbp` selects one concrete app instance for authorization/path resolution while removing policy stored on the parent app. |
| `force` | `--force` | Non-interactive input mode, or when an interactive caller wants to skip the confirmation prompt. | Never. | `false`. | Boolean flag. Explicit destructive consent. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode according to the shared invocation model. |

## Input Resolution

1. **Resolve Parent App.** Mirror the resolved
   [`workspace:new`](../../1_workspace-new/workspace-new.md) and
   [`workspace-setup-step:add`](../../8_workspace-setup-step-add/workspace-setup-step-add.md)
   precedence chain:
   - Explicit `--app=<app>`, where `<app>` may be a parent app slug or
     app-instance selector such as `happie.nmbp`.
   - `.orbit/config` marker on the caller filesystem (installed by `app:new`
     / `app:register` and any workspace-installed marker) that names the
     owning app slug.
   - Gateway path-ownership lookup keyed on `(caller node identity,
     absolute cwd)` that returns the app slug whose registered app path or
     any registered workspace path contains the caller's cwd.
   - Interactive prompt in interactive mode; non-interactive failure with
     `error.code=validation_failed`, `error.meta.field=app`.
   - **Forbidden:** project-file inspection (`composer.json`, `package.json`,
     `.php-version`, `.env`, lockfiles, or framework manifests). This matches `architecture.md` "Workspaces"
     project-file inspection prohibition.
2. **Resolve `step`.** Required from `--step` or interactive prompt.
   - In interactive mode, prompt when `--step` is missing.
   - In non-interactive mode, fail before side effects when `--step` is
     absent (`error.code=validation_failed`, `error.meta.field=step`).
   - Must be a strict positive integer.
   - Must match an existing setup-step record belonging to the resolved app
     and `phase=setup`.
3. **Resolve `force`.** From `--force`. Default `false`.
4. **Apply Destructive Consent.**
   - If `--force` is present, destructive consent is resolved and no
     confirmation prompt is rendered.
   - In interactive mode without `--force`, render a confirmation prompt
     after the target step is valid. If the operator cancels, fail before
     side effects.
   - In non-interactive mode without `--force`, fail before side effects
     with `error.code=validation_failed`, `error.meta.field=force`.

## Input Mode Contracts

- [Interactive input mode](5.1_workspace-setup-step-remove_input-mode_interactive.md)
- [Non-interactive input mode](5.2_workspace-setup-step-remove_input-mode_non-interactive.md)

## Behavior Contract

### Setup Step Removal Rules

`workspace-setup-step:remove` deletes a single gateway-owned setup-step
record from an app's workspace lifecycle policy and compacts the surviving
steps' `order` to a continuous sequence. The command writes only to gateway
configuration. Nodes are not contacted.

1. **Lookup.** Find the setup-step record by `(step_id, app, phase=setup)`.
   If not found, fail before side effects with
   `error.code=workspace.step_not_found`. Already-absent removal is not an
   idempotent success; it is an imperative validation failure.
2. **Destructive Consent.** Apply the destructive consent rules from the
   selected input mode.
3. **Atomic Edit.** Within a single gateway transaction, scoped by an
   app-scoped policy-edit lock that serializes only concurrent policy-table
   edits (it does not wait on lifecycle runs):
   - Capture the removed record's `(id, app, phase, order, command,
     timeout_seconds)` tuple for the response payload.
   - Delete the setup-step record from the gateway registry.
   - Decrement the `order` of every surviving step in the same
     `(app, phase=setup)` whose original `order` was greater than the
     removed step's `order` by exactly one. The surviving sequence is
     contiguous from `1` to `N`.
4. **Report.** Return the removed step record, the action verb (`removed`),
   and the new total step count for `(app, phase=setup)`.

### Scope Boundaries

`workspace-setup-step:remove` must not:

- Block on, wait for, or fail because of an active `workspace:setup` or
  `workspace:new` for the same app. The mutation is gateway-configuration
  only and takes effect for future runs. In-flight runs continue executing the
  ordered step list they snapshotted at `phase=setup_steps` entry. The
  snapshot obligation is owned by
  [`workspace:setup`](../../2_workspace-setup/workspace-setup.md) and
  [`workspace:new`](../../1_workspace-new/workspace-new.md), not by this
  command.
- Mutate `workspace_runs` or `workspace_step_runs` records.
- Undo filesystem side effects, installed packages, migrations, or any
  other artifact produced by previous executions of the removed step on
  nodes. Past executions remain visible in
  [`workspace:history`](../../6_workspace-history/workspace-history.md).
- Read project files (`composer.json`, `package.json`, `.php-version`, `.env`,
  lockfiles, or framework manifests) during parent-app inference.
- Remove a step belonging to `phase=teardown`. Teardown steps are owned by
  [`workspace-teardown-step:remove`](../../13_workspace-teardown-step-remove/workspace-teardown-step-remove.md).

## Renderer Contracts

- [Human renderer](6.1_workspace-setup-step-remove_output-render_human.md)
- [JSON renderer](6.2_workspace-setup-step-remove_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Missing step ID | `--step` is absent in non-interactive mode. | Failure (`error.code=validation_failed`, `error.meta.field=step`). |
| Step not a positive integer | `--step` is non-numeric, zero, or negative. | Failure (`error.code=validation_failed`, `error.meta.field=step`, `error.meta.reason=must_be_positive_integer`). |
| Step not found | No setup-step record matches `(step_id, app, phase=setup)`. Already-absent removal is not idempotent. | Failure (`error.code=workspace.step_not_found`, `error.meta.{step_id, app}`). |
| App not found | Resolved app slug does not exist in gateway configuration. | Failure (`error.code=workspace.app_not_found`, `error.meta.app`). |
| App unresolved | Parent app cannot be resolved from `--app`, `.orbit/config`, or gateway path-ownership lookup, and prompting is disabled. | Failure (`error.code=validation_failed`, `error.meta.field=app`). |
| Missing destructive consent | Non-interactive input mode and `--force` is absent. | Failure (`error.code=validation_failed`, `error.meta.field=force`). |
| Cancelled confirmation | Interactive mode where the operator declines the prompt. | Failure (`error.code=validation_failed`, `error.meta.field=force`). |

### Exit Status

Uses the shared exit status policy. Success exits `0`; all documented command
failures exit with the standard command failure status (`1`). This command
defines no numeric exit codes specific to it.

## Doctor Relationship

Setup-step definitions are gateway-owned workspace policy.
[`doctor --family=workspace`](../../workspace-doctor.md) does not create,
remove, or reorder step definitions and does not undo side effects from
prior step runs. It verifies the health of workspace runtime artifacts but
assumes the step policy is the source of truth for setup and teardown.
`workspace-setup-step:remove` is the only path that deletes a setup-step
record; doctor will not converge an absent step into existence or a
present step out of existence.

## Activity Logging

The gateway API endpoint emits an activity entry for successful and failed
setup-step removal attempts.

| Field | Value |
| --- | --- |
| Type | `api:DELETE /workspaces/steps/{phase}/{step}` |
| Effect | `destructive` |
| Subject | `WorkspaceStep` when the setup step is resolved before deletion; `none` for validation, not-found, or authorization failures before a step can be logged. |
| Properties | No command-specific properties. The API activity middleware adds transport context such as method, path, client, and serving gateway node. |
| Description | derived |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Actions/Workspaces/WorkspaceStepActionsTest.php` | Setup-step removal and order compaction. |
| `apps/gateway/tests/Feature/Http/Api/WorkspaceStepDeleteControllerTest.php` | Gateway setup-step delete response, remaining count, and contiguous sort order. |
| `apps/cli/tests/Feature/Commands/Workspace/WorkspaceStepMutationCommandTest.php` | CLI setup-step removal consent, DELETE forwarding, human hints, step-not-found prose, and JSON success. |

`WorkspaceSetupStepRemoveCommandTest` covers:

- input resolution (explicit `--app`, `.orbit/config` marker, gateway path
  lookup, interactive prompt, non-interactive failure);
- `--step` validation (missing, non-positive, not-found);
- step-not-found as a hard validation failure rather than idempotent success;
- destructive consent (`--force` skips prompt; interactive cancel fails before
  side effects; non-interactive without `--force` fails before side effects;
  `--json` never implies `--force`);
- absence of a runtime lock against in-flight `workspace:setup`.
