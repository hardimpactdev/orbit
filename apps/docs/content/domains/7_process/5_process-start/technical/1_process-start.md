# Technical Contract: `orbit process:start [name]`

[Back to public `process:start` documentation.](../process-start.md)

**Owner:** `process`.

**Effects:** `write` (node-runtime state only; no gateway process-configuration write).

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The gateway authorizes the authenticated peer for `process:start` on the resolved node or instance serving node.
- Runtime lifecycle actions require gateway reachability to that serving node.

## Signature

```bash
orbit process:start [name] [--instance=<project.instance>] [--workspace=<workspace>] [--node=<node>] [--app=<hostname>] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `name` | `[name]` | Optional. | Never. | None. | Existing process slug within the resolved runtime context when supplied. Omit to start all process definitions in process order. |
| `app` | `--app` | Optional alternate target mode for app-instance or workspace hostnames. | `node`, `instance`, or `workspace` is present. | None. | Strict hostname only (exact registered proxy-route domain; no scheme, path, or port). The selector key is `app` only. |
| `node` | `--node` | Required when starting node-owned processes. | `app`, `instance`, or `workspace` is present. | None. | Must resolve to a node that grants `process:start`. |
| `instance` | `--instance` or instance context | Required unless `node` or `app` is supplied or `workspace` resolves the instance. | `node` or `app` is present. | Local instance context when exactly one is resolvable. | Prefer `<project.instance>`. A bare project slug is valid only when it has exactly one instance. The selected instance's serving node must grant `process:start`. |
| `workspace` | `--workspace` or workspace context | Optional. | `node` or `app` is present. | Local workspace context when exactly one workspace is resolvable. | Must resolve to a workspace and its instance whose serving node grants `process:start`; pass `--instance=<project.instance>` when the workspace name is ambiguous. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode. |

## Input Mode Contracts

- [Interactive input mode](5.1_process-start_input-mode_interactive.md)
- [Non-interactive input mode](5.2_process-start_input-mode_non-interactive.md)

## Behavior Contract

### Process Start Rules

1. Resolve a target node, concrete instance, workspace, or `app` hostname context from supplied input or local context. Reject combining `app` with `node`, `instance`, or `workspace`. Reject a bare project selector with `validation_failed`, `field=instance`, and `reason=instance_required` unless that project has exactly one instance.
2. Resolve the selected process set:
   - when `[name]` is supplied, select exactly that process definition;
   - when `[name]` is omitted, select every process definition for the resolved context in process order.
3. Send the request to the gateway (`POST /api/processes/start`). The gateway authenticates the caller from the actual WireGuard peer source IP (same model as CLI/TypeScript SDK; no bearer and no client peer-IP identity header), then checks the grant, `process:start`, and target-node authorization. Browser callers that send `Origin` also pass CORS Origin admission against the requested `app` hostname; CORS never establishes identity.
4. Derive and validate every runtime-unit identity for the selected context
   before runtime or hibernation side effects begin.
5. When `[name]` is omitted for an `app-dev` instance or workspace, acquire
   the scope's hibernation lock and mark the group asleep while it starts.
6. Start each runtime unit through the gateway on the resolved node or instance serving node.
7. Record and publish a durable `started` process event after each successful start.
8. After a successful bulk development start, mark the group awake before
   releasing the hibernation lock. Named, node-owned, and `app-prod` actions do
   not change a group-level hibernation marker.
9. Render the selected output.

`process:start` does not change process configuration and does not repair divergent runtime-unit files. In bulk mode, successful starts are not rolled back when a later process fails; the failure renderer reports partial runtime results.

## Renderer Contracts

- [Human renderer](6.1_process-start_output-render_human.md)
- [JSON renderer](6.2_process-start_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Process not found | `[name]` is supplied and the named process does not exist for the resolved context. | Failure (`error.code=process.not_found`). |
| No processes configured | `[name]` is omitted and the resolved context has no process definitions. | Failure (`error.code=process.none_configured`). |
| Invalid context | `--app` is combined with `--node`, `--instance`, or `--workspace`; `--node` is combined with `--instance` or `--workspace`; or no node/instance/workspace/`app` context resolves. | Failure (`error.code=validation_failed`). |
| Instance required | A bare project selector resolves to more than one instance. | Failure (`error.code=validation_failed`; `error.meta.field=instance`; `error.meta.reason=instance_required`). |
| Runtime action failed | The gateway cannot start the runtime unit on the resolved node or instance serving node. | Failure (`error.code=process.runtime_action_failed`). |

## Doctor Relationship

`process:start` operates runtime state. [`process-doctor.md`](../../process-doctor.md) owns verification and repair of the runtime-unit artifacts that make the start action possible.

## Activity Logging

The gateway API endpoint emits an activity entry for successful and failed process start attempts.

| Field | Value |
| --- | --- |
| Type | `api:POST /processes/start` |
| Effect | `write` |
| Subject | Resolved `Node` for node-owned processes or `AppInstance` for instance/workspace contexts; `none` for validation, context-resolution, or authorization failures before the owner can be logged. |
| Properties | `app` (string or null), `node` (string or null), `instance` (string or null), `workspace` (string or null), and `name` (string or null). No runtime output, backend command text, or secrets. |
| Description | derived |

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Http/Api/ProcessStartControllerTest.php` | Gateway process:start runtime action authorization, selected process execution, durable event recording, partial runtime failure data, unsupported-runtime validation, and authorization failures. |
| `apps/cli/tests/Feature/Commands/Process/ProcessWriteCommandTest.php` | CLI process:start app/workspace/node context forwarding, optional process selection, all-process selection when `[name]` is omitted, JSON success output, and validation before gateway contact. |

`ProcessStartControllerTest.php` and `ProcessWriteCommandTest.php` cover context resolution, self-grant allowance,
missing-grant denial, named and all-process selection, process-order execution,
runtime-unit derivation, successful start, durable started event recording,
partial bulk failure reporting, no configuration mutation, no direct
process-manager operation, runtime action failure, and authorization failure.

Renderer and input-mode test mapping lives in the split companion files.
