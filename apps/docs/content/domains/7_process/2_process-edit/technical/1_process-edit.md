# Technical Contract: `orbit process:edit [name]`

[Back to public `process:edit` documentation.](../process-edit.md)

**Owner:** `process`.

**Effects:** `write` (gateway process configuration and derived runtime units).

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The gateway authorizes the authenticated peer for `process:edit` on the resolved owning node.
- To re-render runtime artifacts, the gateway must reach the owning node.

## Signature

```bash
orbit process:edit [name] [--node=<node>] [--app=<app>] [--workspace=<workspace>] [--command=<command>] [--restart-policy=<never|on_failure|always>] [--crash-notification=<none|agent_ide>] [--runtime=<docker|docker-swarm|supervisor|systemd>] [--restart] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `name` | `[name]` | Always. | Never. | None. | Existing process slug within the resolved owner scope. |
| `node` | `--node` | Required when editing a node-owned process. | `app` or `workspace` is present. | None. | Must resolve to a node that grants `process:edit`. |
| `app` | `--app` or app context | Required unless `node` is supplied or `workspace` resolves the app. | `node` is present. | Local app context when exactly one app is resolvable. | Must resolve to an app whose owning node grants `process:edit`. |
| `workspace` | `--workspace` or workspace context | Required when editing a workspace-owned process. | `node` is present. | Local workspace context when exactly one workspace is resolvable. | Must resolve to a workspace whose app owning node grants `process:edit`; pass `--app` when the workspace name is ambiguous. |
| `command` | `--command` | Optional. At least one editable field is required. | Never. | Current value. | Non-empty command string when supplied. |
| `restart_policy` | `--restart-policy` | Optional. At least one editable field is required. | Never. | Current value. | One of `never`, `on_failure`, `always`. |
| `crash_notification` | `--crash-notification` | Optional. At least one editable field is required. | Never. | Current value. | One of `none`, `agent_ide`. |
| `runtime` | `--runtime` | Optional. At least one editable field is required. | Never. | Current value. | One of `docker`, `docker-swarm`, `supervisor`, `systemd`. `supervisor` is the only public runtime for app/workspace host-command process edits. `systemd` is valid only when `node` owns the process. `docker-swarm` is valid only for node-owned managed service processes. Existing Orbit-managed Docker process rows may still be lifecycle-managed by process commands. |
| `restart` | `--restart` | Optional. | Never. | `false`. | Boolean flag. Restarts affected running runtime units after applying when true. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode. |

`command` is an option here because it is one optional editable field. Omitted editable fields preserve their current values. The sibling `process:add` command accepts `[command]` positionally because command is required to create a process definition.

## Input Mode Contracts

- [Interactive input mode](5.1_process-edit_input-mode_interactive.md)
- [Non-interactive input mode](5.2_process-edit_input-mode_non-interactive.md)

## Behavior Contract

### Process Definition Update Rules

1. Resolve target node, app, or workspace context from supplied input or local context, and resolve the existing process definition within that owner scope.
2. Validate that at least one editable field is supplied.
3. Send the request to the gateway, which validates the authenticated peer's authorization.
4. Update gateway-owned process configuration.
5. Re-render runtime units derived from the process definition. Node-owned and workspace-owned processes normally derive one unit; app-owned processes derive one main-app unit plus one unit for each active workspace.
6. When `--restart` is present, restart affected running runtime units and record lifecycle events for units that restart successfully.
7. Render the selected output.

If process configuration is updated but re-rendering or optional restart fails, the command returns success with repairable process-family warnings because the requested durable configuration exists.

## Renderer Contracts

- [Human renderer](6.1_process-edit_output-render_human.md)
- [JSON renderer](6.2_process-edit_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Process not found | The named process does not exist for the resolved owner scope. | Failure (`error.code=process.not_found`). |
| Invalid context | `--node` is combined with `--app` or `--workspace`, or no node/app/workspace context resolves. | Failure (`error.code=validation_failed`). |
| Invalid app/workspace command runtime | `--runtime=docker`, `--runtime=systemd`, or `--runtime=docker-swarm` is supplied for an app- or workspace-owned host-command process. | Failure (`error.code=validation_failed`; `error.meta.reason=docker_runtime_requires_service_or_managed_process`, `systemd_requires_node_owned_process`, or `docker_swarm_requires_node_owned_process`). |

## Doctor Relationship

`process:edit` changes process configuration and attempts to re-render the runtime units derived from that definition. [`process-doctor.md`](../../process-doctor.md) owns later detection and repair of missing or divergent runtime units and lifecycle event notifier material.

## Activity Logging

The gateway API endpoint emits an activity entry for successful and failed process-configuration edit attempts.

| Field | Value |
| --- | --- |
| Type | `api:PATCH /processes/{name}` |
| Effect | `write` |
| Subject | Resolved `Node` for node-owned processes or `App` for app/workspace-owned processes; `none` for validation, context-resolution, or authorization failures before the owner can be logged. |
| Properties | `node` (string or null), `app` (string or null), and `workspace` (string or null). No raw process command text, environment data, runtime output, or secrets. |
| Description | derived |

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Commands/Processes/ProcessEditCommandTest.php` | Process update contract, grant authorization denial, required editable fields, app resolution, re-rendering derived units, optional restart behavior, repairable warnings on post-configuration apply failure, and no write on validation failure. |
| `apps/gateway/tests/Feature/Commands/Processes/ProcessEditInputContractTest.php` | Required inputs, editable field validation, enum validation, no-op rejection, and `--json` input-mode selection. |

Renderer and input-mode test mapping lives in the split companion files.
