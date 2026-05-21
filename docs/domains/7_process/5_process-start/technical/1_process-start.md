# Technical Contract: `orbit process:start [name]`

[Back to public `process:start` documentation.](../process-start.md)

**Owner:** `process`.

**Effects:** `write` (node-runtime state only; no gateway process-configuration write).

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The gateway authorizes the authenticated peer for `process:start` on the target app's owning node.
- Runtime lifecycle actions require gateway reachability to the owning node.

## Signature

```bash
orbit process:start [name] [--app=<app>] [--workspace=<workspace>] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `name` | `[name]` | Optional. | Never. | None. | Existing process slug within the owning app when supplied. Omit to start all process definitions in process order. |
| `app` | `--app` or app context | Required unless `workspace` resolves the app. | Never. | Local app context when exactly one app is resolvable. | Must resolve to an app whose owning node grants `process:start`. |
| `workspace` | `--workspace` or workspace context | Optional. | Never. | Local workspace context when exactly one workspace is resolvable. | Must resolve to a workspace whose app owning node grants `process:start`. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode. |

## Input Mode Contracts

- [Interactive input mode](5.1_process-start_input-mode_interactive.md)
- [Non-interactive input mode](5.2_process-start_input-mode_non-interactive.md)

## Behavior Contract

### Process Start Rules

1. Resolve target app or workspace context from supplied input or local context.
2. Resolve the selected process set:
   - when `[name]` is supplied, select exactly that process definition;
   - when `[name]` is omitted, select every process definition for the app in process order.
3. Send the request to the gateway, which validates the authenticated peer's authorization.
4. Derive runtime-unit identities for the selected context.
5. Start each runtime unit through the gateway on the owning node.
6. Record and publish a durable `started` process event after each successful start.
7. Render the selected output.

`process:start` does not change process configuration and does not repair divergent runtime-unit files. In bulk mode, successful starts are not rolled back when a later process fails; the failure renderer reports partial runtime results.

## Renderer Contracts

- [Human renderer](6.1_process-start_output-render_human.md)
- [JSON renderer](6.2_process-start_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Process not found | `[name]` is supplied and the named process does not exist for the owning app. | Failure (`error.code=process.not_found`). |
| No processes configured | `[name]` is omitted and the owning app has no process definitions. | Failure (`error.code=process.none_configured`). |
| Runtime action failed | The gateway cannot start the runtime unit on the owning node. | Failure (`error.code=process.runtime_action_failed`). |

## Doctor Relationship

`process:start` operates runtime state. [`process-doctor.md`](../../process-doctor.md) owns verification and repair of the runtime-unit artifacts that make the start action possible.

## Activity Logging

The gateway API endpoint emits an activity entry for successful and failed process start attempts.

| Field | Value |
| --- | --- |
| Type | `api:POST /processes/start` |
| Effect | `write` |
| Subject | `App` when the parent app is resolved and visible; `none` for validation, app-resolution, or authorization failures before the app can be logged. |
| Properties | `app` (string or null), `workspace` (string or null), and `name` (string or null). No runtime output, backend command text, or secrets. |
| Description | derived |

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Processes/ProcessStartCommandTest.php` | Context resolution, grant authorization, process selection, runtime-unit derivation, successful start, durable event recording, partial bulk failure, and authorization failure (full scope below). |
| `tests/Feature/Commands/Processes/ProcessStartInputContractTest.php` | Required inputs, app and workspace resolution, optional process selection, all-process selection when `[name]` is omitted, and `--json` input-mode selection. |

`ProcessStartCommandTest` covers context resolution, self-grant allowance,
missing-grant denial, named and all-process selection, process-order execution,
runtime-unit derivation, successful start, durable started event recording,
partial bulk failure reporting, no configuration mutation, no direct
process-manager operation, runtime action failure, and authorization failure.

Renderer and input-mode test mapping lives in the split companion files.
