# Technical Contract: `orbit process:remove [name]`

[Back to public `process:remove` documentation.](../process-remove.md)

**Owner:** `process`.

**Effects:** `write`, `destructive` (gateway process configuration and derived runtime units).

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The gateway authorizes the authenticated peer for `process:remove` on the target app's owning node.
- Runtime artifact cleanup requires gateway reachability to the owning node.

## Signature

```bash
orbit process:remove [name] [--app=<app>] [--force] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `name` | `[name]` | Always. | Never. | None. | Existing process slug within the owning app. |
| `app` | `--app` or app context | Always. | Never. | Local app context when exactly one app is resolvable. | Must resolve to an app whose owning node grants `process:remove`. |
| `force` | `--force` | Required in non-interactive input mode. | Never. | `false`. | Boolean flag. Bypasses the interactive confirmation prompt when true. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode. It never grants destructive consent. |

Destructive consent is required before side effects. In interactive input mode, consent is an explicit confirmation prompt unless `--force` is supplied. In non-interactive input mode, `--force` is required because prompts are unavailable.

## Input Mode Contracts

- [Interactive input mode](5.1_process-remove_input-mode_interactive.md)
- [Non-interactive input mode](5.2_process-remove_input-mode_non-interactive.md)

## Behavior Contract

### Process Removal Rules

1. Resolve target app from supplied input or local app context, and resolve the existing process definition.
2. Resolve destructive consent.
3. Send the request to the gateway, which validates the authenticated peer's authorization.
4. Stop and remove derived runtime units for the main app instance and every active workspace.
5. Remove gateway-owned process configuration.
6. Render the selected output.

Historical logs and lifecycle events are not removed by this command.

If process configuration is removed but runtime-unit cleanup fails, the command returns success with repairable process-family warnings because the requested durable state transition completed.

## Renderer Contracts

- [Human renderer](6.1_process-remove_output-render_human.md)
- [JSON renderer](6.2_process-remove_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Missing destructive consent | Non-interactive input mode and `--force` is absent. | Failure (`error.code=validation_failed`, `error.meta.field=force`). |
| Process not found | The named process does not exist for the owning app. | Failure (`error.code=process.not_found`). |
| Cancelled | The operator declines the interactive confirmation prompt. | Failure (`error.code=validation_failed`, `error.meta.field=force`). |

## Doctor Relationship

`process:remove` removes process configuration and attempts runtime-unit cleanup. [`process-doctor.md`](../../process-doctor.md) reports leftover Orbit-owned runtime units as `process.runtime_unit_extra`.

## Activity Logging

The gateway API endpoint emits an activity entry for successful and failed process-configuration removal attempts.

| Field | Value |
| --- | --- |
| Type | `api:DELETE /processes/{name}` |
| Effect | `destructive` |
| Subject | `App` when the parent app is resolved and visible; `none` for validation, app-resolution, or authorization failures before the app can be logged. |
| Properties | `app` (string or null). No raw process command text, runtime output, cleanup logs, or secrets. |
| Description | derived |

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Processes/ProcessRemoveCommandTest.php` | Grant authorization denial, destructive consent, process removal, runtime-unit cleanup, historical log retention, post-configuration cleanup warnings, process-not-found failure, and no side effects on validation failure. |
| `tests/Feature/Commands/Processes/ProcessRemoveInputContractTest.php` | Required inputs, app resolution, `--force` destructive consent behavior, `--json` not granting consent, and cancelled confirmation behavior. |

Renderer and input-mode test mapping lives in the split companion files.
