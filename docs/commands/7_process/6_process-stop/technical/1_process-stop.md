# Technical Contract: `orbit process:stop [name]`

[Back to public `process:stop` documentation.](../process-stop.md)

**Owner:** `process`.

**Effects:** `write` (node-runtime state only; no gateway process-intent write).

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The caller role is `control`, `gateway`, or `app`; `unknown` callers are
  denied before prompts or side effects.
- The current node identity is authorized to operate process runtime state for
  the target app or workspace context.
- Runtime lifecycle actions require gateway SSH reachability to the owning app
  node.

## Signature

```bash
orbit process:stop [name] [--app=<app>] [--workspace=<workspace>] [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `name` | `[name]` | Optional. | Never. | None. | Existing process slug within the owning app when supplied. Omit to stop all process definitions in process order. |
| `app` | `--app` or app context | Required unless `workspace` resolves the app. | Never. | Local app context when exactly one app is resolvable. | Must resolve to an app the caller may operate. |
| `workspace` | `--workspace` or workspace context | Optional. | Never. | Local workspace context when exactly one workspace is resolvable. | Must resolve to a workspace of the selected app that the caller may operate. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode. |

## Caller Role Behavior

`process:stop` follows the
[Process Caller Role Rule](../../README.md#process-caller-role-rule). It is a
runtime-lifecycle command, so app-node callers are valid when authorized for the
resolved app or workspace context.

| Role | Validity | Consequence |
| --- | --- | --- |
| `control` | `valid` | Forward the runtime action to the gateway API when authorized. |
| `gateway` | `valid` | Stop the derived runtime unit on the owning app node through `RemoteShell`. |
| `app` | `valid` | Resolve local app or workspace context when available, then call the gateway API. The gateway performs the runtime action; the app-node CLI does not operate the runtime backend directly. |
| `unknown` | `invalid` | Deny before prompts or side effects with `error.code=caller_role_not_allowed`. |

## Input Mode Contracts

- [Interactive input mode](5.1_process-stop_input-mode_interactive.md)
- [Non-interactive input mode](5.2_process-stop_input-mode_non-interactive.md)

## Behavior Contract

### Process Stop Rules

1. Resolve caller role. Deny `unknown` callers before prompts or side effects.
2. Resolve caller authorization and target app or workspace context.
3. Resolve the selected process set:
   - when `[name]` is supplied, select exactly that process definition;
   - when `[name]` is omitted, select every process definition for the app in
     process order.
4. Derive runtime-unit identities for the selected context.
5. Stop each runtime unit through the gateway on the owning app node.
6. Record and publish a durable `stopped` process event after each successful
   stop.
7. Render the selected output.

`process:stop` does not change process intent and does not remove the runtime
unit artifact. In bulk mode, successful stops are not rolled back when a later
process fails; the failure renderer reports partial runtime results.

## Renderer Contracts

- [Human renderer](6.1_process-stop_output-render_human.md)
- [JSON renderer](6.2_process-stop_output-render_json.md)

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| Caller role not allowed | The caller role is `unknown`. | Failure (`error.code=caller_role_not_allowed`). |
| Validation failed | App or workspace context is missing, invalid, or ambiguous in non-interactive input mode. | Failure (`error.code=validation_failed`). |
| Authorization failed | The caller cannot operate process runtime state for the target context. | Failure (`error.code=authorization_failed`). |
| Process not found | `[name]` is supplied and the named process does not exist for the owning app. | Failure (`error.code=process.not_found`). |
| No processes configured | `[name]` is omitted and the owning app has no process definitions. | Failure (`error.code=process.none_configured`). |
| Runtime action failed | The gateway cannot stop the runtime unit on the owning app node. | Failure (`error.code=process.runtime_action_failed`). |

## Doctor Relationship

`process:stop` operates runtime state. [`process-doctor.md`](../../process-doctor.md)
owns verification and repair of the runtime-unit artifacts that make the stop
action possible.

## Activity Logging

The gateway API endpoint emits an activity entry for successful and failed
process stop attempts.

| Field | Value |
| --- | --- |
| Type | `api:POST /processes/stop` |
| Effect | `write` |
| Subject | `App` when the parent app is resolved and visible; `none` for validation, app-resolution, caller-role, or authorization failures before the app can be logged. |
| Properties | `app` (string or null), `workspace` (string or null), and `name` (string or null). No runtime output, backend command text, or secrets. |
| Description | derived |

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Processes/ProcessStopCommandTest.php` | Command contract for context resolution, app-node caller allowance through the gateway, unknown-role denial before prompts or side effects, named and all-process selection, process-order execution, runtime-unit derivation, successful stop, durable stopped event recording, partial bulk failure reporting, no process intent mutation, no direct app-node runtime backend operation, runtime action failure, and authorization failure. |
| `tests/Feature/Commands/Processes/ProcessStopInputContractTest.php` | Required inputs, app and workspace resolution, optional process selection, all-process selection when `[name]` is omitted, and `--json` input-mode selection. |

Renderer and input-mode test mapping lives in the split companion files.
