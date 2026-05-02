# Technical Contract: `orbit process:start [name]`

[Back to public `process:start` documentation.](../process-start.md)

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
orbit process:start [name] [--app=<app>] [--workspace=<workspace>] [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `name` | `[name]` | Always. | Never. | None. | Existing process slug within the owning app. |
| `app` | `--app` or app context | Required unless `workspace` resolves the app. | Never. | Local app context when exactly one app is resolvable. | Must resolve to an app the caller may operate. |
| `workspace` | `--workspace` or workspace context | Optional. | Never. | Local workspace context when exactly one workspace is resolvable. | Must resolve to a workspace of the selected app that the caller may operate. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode. |

## Caller Role Behavior

`process:start` follows the
[Process Caller Role Rule](../../README.md#process-caller-role-rule). It is a
runtime-lifecycle command, so app-node callers are valid when authorized for the
resolved app or workspace context.

| Role | Validity | Consequence |
| --- | --- | --- |
| `control` | `valid` | Forward the runtime action to the gateway API when authorized. |
| `gateway` | `valid` | Start the derived runtime unit on the owning app node through `RemoteShell`. |
| `app` | `valid` | Resolve local app or workspace context when available, then call the gateway API. The gateway performs the runtime action; the app-node CLI does not operate systemd directly. |
| `unknown` | `invalid` | Deny before prompts or side effects with `error.code=caller_role_not_allowed`. |

## Input Mode Contracts

- [Interactive input mode](5.1_process-start_input-mode_interactive.md)
- [Non-interactive input mode](5.2_process-start_input-mode_non-interactive.md)

## Behavior Contract

### Process Start Rules

1. Resolve caller role. Deny `unknown` callers before prompts or side effects.
2. Resolve caller authorization, target app or workspace context, and process
   definition.
3. Derive the runtime-unit identity for the selected context.
4. Start the runtime unit through the gateway on the owning app node.
5. Record and publish a durable `started` process event after successful start.
6. Render the selected output.

`process:start` does not change process intent and does not repair divergent
runtime-unit files.

## Renderer Contracts

- [Human renderer](6.1_process-start_output-render_human.md)
- [JSON renderer](6.2_process-start_output-render_json.md)

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| Caller role not allowed | The caller role is `unknown`. | Failure (`error.code=caller_role_not_allowed`). |
| Validation failed | Process, app, or workspace context is missing, invalid, or ambiguous in non-interactive input mode. | Failure (`error.code=validation_failed`). |
| Authorization failed | The caller cannot operate process runtime state for the target context. | Failure (`error.code=authorization_failed`). |
| Process not found | The named process does not exist for the owning app. | Failure (`error.code=process.not_found`). |
| Runtime action failed | The gateway cannot start the runtime unit on the owning app node. | Failure (`error.code=process.runtime_action_failed`). |

## Doctor Relationship

`process:start` operates runtime state. [`process-doctor.md`](../../process-doctor.md)
owns verification and repair of the runtime-unit artifacts that make the start
action possible.

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Processes/ProcessStartCommandTest.php` | Command contract for context resolution, app-node caller allowance through the gateway, unknown-role denial before prompts or side effects, runtime-unit derivation, successful start, durable started event recording, no process intent mutation, no direct app-node systemd operation, runtime action failure, and authorization failure. |
| `tests/Feature/Commands/Processes/ProcessStartInputContractTest.php` | Required inputs, app and workspace resolution, process resolution, and `--json` input-mode selection. |

Renderer and input-mode test mapping lives in the split companion files.
