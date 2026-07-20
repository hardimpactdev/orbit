# Technical Contract: `orbit process:list`

[Back to public `process:list` documentation.](../process-list.md)

**Owner:** `process`.

**Effects:** `read`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The gateway authorizes the authenticated peer for `process:read` on the resolved node or instance serving node.

## Signature

```bash
orbit process:list [--instance=<project.instance>] [--workspace=<workspace>] [--node=<node>] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `node` | `--node` | Required when listing node-owned processes. | `instance` or `workspace` is present. | None. | Must resolve to a node that grants `process:read`. |
| `instance` | `--instance` or instance context | Required unless `node` is supplied or `workspace` resolves the instance. | `node` is present. | Local instance context when exactly one is resolvable. | Prefer `<project.instance>`. A bare project slug is valid only when it has exactly one instance. The selected instance's serving node must grant `process:read`. |
| `workspace` | `--workspace` or workspace context | Optional. | `node` is present. | Local workspace context when exactly one workspace is resolvable. | Must resolve to a workspace and its instance whose serving node grants `process:read`; pass `--instance=<project.instance>` when the workspace name is ambiguous. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode. |

## Input Mode Contracts

- [Interactive input mode](5.1_process-list_input-mode_interactive.md)
- [Non-interactive input mode](5.2_process-list_input-mode_non-interactive.md)

## Behavior Contract

### Process Listing Rules

1. Resolve a target node, concrete instance, or workspace context from supplied input or local context. Reject a bare project selector with `validation_failed`, `field=instance`, and `reason=instance_required` unless that project has exactly one instance.
2. Send the request to the gateway, which validates the authenticated peer's authorization.
3. Read process definitions from gateway configuration in process order. An instance context includes only definitions owned by that instance. A workspace context includes workspace-owned definitions and instance-owned definitions inherited by that workspace.
4. Derive expected runtime-unit identities for the selected context.
5. For service process definitions, include process-owned connection metadata: definition name, version, service name, endpoint, and credential field names. Credential values are excluded.
6. Read latest durable lifecycle events for the selected runtime context when events exist.
7. Render the selected output.

`process:list` must not SSH to nodes, run live process manager probes, mutate gateway configuration, or change runtime state.

## Renderer Contracts

- [Human renderer](6.1_process-list_output-render_human.md)
- [JSON renderer](6.2_process-list_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Invalid context | `--node` is combined with `--instance` or `--workspace`, or no node/instance/workspace context resolves. | Failure (`error.code=validation_failed`). |
| Instance required | A bare project selector resolves to more than one instance. | Failure (`error.code=validation_failed`; `error.meta.field=instance`; `error.meta.reason=instance_required`). |

Instance serving-node reachability is not part of the default list path and does not cause this command to fail.

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
| `apps/gateway/tests/Feature/Http/Api/ProcessListControllerTest.php` | Gateway process listing for app, workspace, and node contexts, grant-scoped visibility, managed-service metadata, validation failures, authorization failures, and unauthenticated requests. |
| `apps/cli/tests/Feature/Commands/Process/ProcessListCommandTest.php` | CLI `process:list` `--instance`, `--workspace`, `--node`, and `--json` forwarding, JSON envelope shape, human table output, empty state, and gateway error passthrough. |

Renderer and input-mode test mapping lives in the split companion files.
