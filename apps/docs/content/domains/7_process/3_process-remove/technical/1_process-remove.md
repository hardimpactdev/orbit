# Technical Contract: `orbit process:remove [name]`

[Back to public `process:remove` documentation.](../process-remove.md)

**Owner:** `process`.

**Effects:** `write`, `destructive` (gateway process configuration and derived runtime units).

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The gateway authorizes the authenticated peer for `process:remove` on the resolved node or instance serving node.
- Runtime artifact cleanup requires gateway reachability to that serving node.

## Signature

```bash
orbit process:remove [name] [--instance=<project.instance>] [--workspace=<workspace>] [--node=<node>] [--force] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `name` | `[name]` | Always. | Never. | None. | Existing process slug within the resolved owner scope. |
| `node` | `--node` | Required when removing a node-owned process. | `instance` or `workspace` is present. | None. | Must resolve to a node that grants `process:remove`. |
| `instance` | `--instance` or instance context | Required unless `node` is supplied or `workspace` resolves the instance. | `node` is present. | Local instance context when exactly one is resolvable. | Prefer `<project.instance>`. A bare project slug is valid only when it has exactly one instance. The selected instance's serving node must grant `process:remove`. |
| `workspace` | `--workspace` or workspace context | Required when removing a workspace-owned process. | `node` is present. | Local workspace context when exactly one workspace is resolvable. | Must resolve to a workspace and its instance whose serving node grants `process:remove`; pass `--instance=<project.instance>` when the workspace name is ambiguous. |
| `force` | `--force` | Required in non-interactive input mode. | Never. | `false`. | Boolean flag. Bypasses the interactive confirmation prompt when true. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode. It never grants destructive consent. |

Destructive consent is required before side effects. In interactive input mode, consent is an explicit confirmation prompt unless `--force` is supplied. In non-interactive input mode, `--force` is required because prompts are unavailable.

## Input Mode Contracts

- [Interactive input mode](5.1_process-remove_input-mode_interactive.md)
- [Non-interactive input mode](5.2_process-remove_input-mode_non-interactive.md)

## Behavior Contract

### Process Removal Rules

1. Resolve a target node, concrete instance, or workspace context from supplied input or local context, and resolve the existing process definition within that owner scope. Reject a bare project selector with `validation_failed`, `field=instance`, and `reason=instance_required` unless that project has exactly one instance.
2. Resolve destructive consent.
3. Send the request to the gateway, which validates the authenticated peer's authorization.
4. Stop and remove runtime units on the resolved node or instance serving node. Node-owned and workspace-owned processes normally derive one unit; instance-owned processes derive one main-instance unit plus one unit for each active workspace belonging to that same instance.
5. Remove gateway-owned process configuration.
6. Render the selected output.

Process logs and lifecycle events are not removed by this command.

If process configuration is removed but runtime-unit cleanup fails, the command returns success with repairable process-family warnings because the requested durable state transition completed.

## Renderer Contracts

- [Human renderer](6.1_process-remove_output-render_human.md)
- [JSON renderer](6.2_process-remove_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Missing destructive consent | Non-interactive input mode and `--force` is absent. | Failure (`error.code=validation_failed`, `error.meta.field=force`). |
| Process not found | The named process does not exist for the resolved owner scope. | Failure (`error.code=process.not_found`). |
| Invalid context | `--node` is combined with `--instance` or `--workspace`, or no node/instance/workspace context resolves. | Failure (`error.code=validation_failed`). |
| Instance required | A bare project selector resolves to more than one instance. | Failure (`error.code=validation_failed`; `error.meta.field=instance`; `error.meta.reason=instance_required`). |
| Cancelled | The operator declines the interactive confirmation prompt. | Failure (`error.code=validation_failed`, `error.meta.field=force`). |

## Doctor Relationship

`process:remove` removes process configuration and attempts runtime-unit cleanup. [`process-doctor.md`](../../process-doctor.md) reports leftover Orbit-owned runtime units as `process.runtime_unit_extra`.

## Activity Logging

The gateway API endpoint emits an activity entry for successful and failed process-configuration removal attempts.

| Field | Value |
| --- | --- |
| Type | `api:DELETE /processes/{name}` |
| Effect | `destructive` |
| Subject | Resolved `Node` for node-owned processes or `AppInstance` for instance/workspace-owned processes; `none` for validation, context-resolution, or authorization failures before the owner can be logged. |
| Properties | `node` (string or null), `instance` (string or null), and `workspace` (string or null). No raw process command text, runtime output, cleanup logs, or secrets. |
| Description | derived |

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Http/Api/ProcessDestroyControllerTest.php` | Gateway process removal authorization, destructive consent, app/workspace/node-owned process deletion, missing-grant denial, and process-not-found responses. |
| `apps/cli/tests/Feature/Commands/Process/ProcessWriteCommandTest.php` | CLI `process:remove` force consent handling, `--json` not granting consent, cancelled confirmation behavior, DELETE forwarding, and gateway error passthrough. |

Renderer and input-mode test mapping lives in the split companion files.
