# Technical Contract: `orbit process:edit [name]`

[Back to public `process:edit` documentation.](../process-edit.md)

**Owner:** `process`.

**Effects:** `write` (gateway process configuration and derived runtime units).

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The gateway authorizes the authenticated peer for process-configuration writes on the target app. `app` and `unknown` callers are denied; `control` and `gateway` callers may proceed when authorized.
- Runtime artifact re-rendering requires gateway reachability to the owning app node.

## Signature

```bash
orbit process:edit [name] [--app=<app>] [--command=<command>] [--restart-policy=<never|on_failure|always>] [--crash-notification=<none|agent_ide>] [--restart] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `name` | `[name]` | Always. | Never. | None. | Existing process slug within the owning app. |
| `app` | `--app` or app context | Always. | Never. | Local app context when exactly one app is resolvable. | Must resolve to an app the caller may manage. |
| `command` | `--command` | Optional. At least one editable field is required. | Never. | Current value. | Non-empty command string when supplied. |
| `restart_policy` | `--restart-policy` | Optional. At least one editable field is required. | Never. | Current value. | One of `never`, `on_failure`, `always`. |
| `crash_notification` | `--crash-notification` | Optional. At least one editable field is required. | Never. | Current value. | One of `none`, `agent_ide`. |
| `restart` | `--restart` | Optional. | Never. | `false`. | Boolean flag. Restarts affected running runtime units after applying when true. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode. |

`command` is an option here because it is one optional editable field. Omitted editable fields preserve their current values. The sibling `process:add` command accepts `[command]` positionally because command is required to create a process definition.

## Authorization By Caller Role

`process:edit` follows the [process Authorization By Caller Role rule](../../README.md#authorization-by-caller-role). It mutates app-owned process configuration, so only `control` and `gateway` callers are allowed by the gateway.

| Role | Gateway decision | Consequence |
| --- | --- | --- |
| `control` | `allow` | Gateway accepts the request when the peer is authorized for the target app and re-applies runtime units on the owning app node. |
| `gateway` | `allow` | Gateway accepts the request and re-applies runtime units on the owning app node. |
| `app` | `deny` | Gateway returns `error.code=caller_role_not_allowed`. |
| `unknown` | `deny` | Gateway returns `error.code=caller_role_not_allowed`. |

## Input Mode Contracts

- [Interactive input mode](5.1_process-edit_input-mode_interactive.md)
- [Non-interactive input mode](5.2_process-edit_input-mode_non-interactive.md)

## Behavior Contract

### Process Definition Update Rules

1. Resolve target app from supplied input or local app context, and resolve the existing process definition.
2. Validate that at least one editable field is supplied.
3. Send the request to the gateway, which validates the authenticated peer's authorization.
4. Update gateway-owned process configuration.
5. Re-render the main app runtime unit and every active workspace runtime unit derived from the process definition.
6. When `--restart` is present, restart affected running runtime units and record lifecycle events for units that restart successfully.
7. Render the selected output.

If process configuration is updated but re-rendering or optional restart fails, the command returns success with repairable process-family warnings because the requested durable configuration exists.

## Renderer Contracts

- [Human renderer](6.1_process-edit_output-render_human.md)
- [JSON renderer](6.2_process-edit_output-render_json.md)

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| Caller role not allowed | The gateway determines the authenticated peer's caller role is `app` or `unknown`. | Failure (`error.code=caller_role_not_allowed`). |
| Validation failed | Missing `name` or `app`, invalid enum value, empty `command`, or no editable fields supplied. | Failure (`error.code=validation_failed`). |
| Authorization failed | The caller cannot manage process configuration for the target app. | Failure (`error.code=authorization_failed`). |
| Process not found | The named process does not exist for the owning app. | Failure (`error.code=process.not_found`). |
| Gateway unavailable | The CLI cannot reach the gateway API before configuration is changed. | Failure (`error.code=gateway_unavailable`). |

## Doctor Relationship

`process:edit` changes process configuration and attempts to re-render derived runtime units. [`process-doctor.md`](../../process-doctor.md) owns later detection and repair of missing or divergent runtime units and lifecycle event notifier material.

## Activity Logging

The gateway API endpoint emits an activity entry for successful and failed process-configuration edit attempts.

| Field | Value |
| --- | --- |
| Type | `api:PATCH /processes/{name}` |
| Effect | `write` |
| Subject | `App` when the parent app is resolved and visible; `none` for validation, app-resolution, caller-role, or authorization failures before the app can be logged. |
| Properties | `app` (string or null). No raw process command text, environment data, runtime output, or secrets. |
| Description | derived |

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Processes/ProcessEditCommandTest.php` | Command contract for process updates, caller-role denial before prompts or side effects, required editable fields, app resolution, re-rendering derived units, optional restart behavior, repairable warnings after post-configuration apply failure, and no configuration write on validation failure. |
| `tests/Feature/Commands/Processes/ProcessEditInputContractTest.php` | Required inputs, editable field validation, enum validation, no-op rejection, and `--json` input-mode selection. |

Renderer and input-mode test mapping lives in the split companion files.
