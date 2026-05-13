# Technical Contract: `orbit process:list`

[Back to public `process:list` documentation.](../process-list.md)

**Owner:** `process`.

**Effects:** `read`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The gateway authorizes the authenticated peer to read process configuration for the target app or workspace context. `unknown` callers are denied.

## Signature

```bash
orbit process:list [--app=<app>] [--workspace=<workspace>] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `app` | `--app` or app context | Required unless `workspace` resolves the app. | Never. | Local app context when exactly one app is resolvable. | Must resolve to an app the caller may read. |
| `workspace` | `--workspace` or workspace context | Optional. | Never. | Local workspace context when exactly one workspace is resolvable. | Must resolve to a workspace of the selected app that the caller may read. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode. |

## Input Mode Contracts

- [Interactive input mode](5.1_process-list_input-mode_interactive.md)
- [Non-interactive input mode](5.2_process-list_input-mode_non-interactive.md)

## Behavior Contract

### Process Listing Rules

1. Resolve target app or workspace context from supplied input or local context.
2. Send the request to the gateway, which validates the authenticated peer's authorization.
3. Read app-owned process definitions from gateway configuration in process order.
4. Derive expected runtime-unit identities for the selected main app or workspace context.
5. Read latest durable lifecycle events for the selected runtime context when events exist.
6. Render the selected output.

`process:list` must not SSH to app nodes, run live process manager probes, mutate gateway configuration, or change runtime state.

## Renderer Contracts

- [Human renderer](6.1_process-list_output-render_human.md)
- [JSON renderer](6.2_process-list_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |

Owning app-node reachability is not part of the default list path and does not cause this command to fail.

## Doctor Relationship

`process:list` reports process configuration and latest durable lifecycle events. [`process-doctor.md`](../../process-doctor.md) owns live runtime-unit artifact verification and repair.

## Activity Logging

The gateway API endpoint emits an activity entry for successful and failed process registry reads.

| Field | Value |
| --- | --- |
| Type | `api:GET /processes` |
| Effect | `read` |
| Subject | `none` |
| Properties | No command-specific properties. The API activity middleware adds transport context such as method, path, client, and serving gateway node. |
| Description | derived |

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Processes/ProcessListCommandTest.php` | Command contract for app context resolution, workspace context resolution, app-node caller allowance through the gateway, unknown-role denial before prompts or side effects, registry-backed process listing in process order, latest durable event display, no live node probing, authorization failure, and gateway-unavailable failure. |
| `tests/Feature/Commands/Processes/ProcessListInputContractTest.php` | App and workspace input resolution, missing context failures, ambiguous context failures, and `--json` input-mode selection. |

Renderer and input-mode test mapping lives in the split companion files.
