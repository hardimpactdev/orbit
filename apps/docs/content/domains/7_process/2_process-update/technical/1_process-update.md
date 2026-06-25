# Technical Contract: `orbit process:update [name]`

[Back to public `process:update` documentation.](../process-update.md)

**Owner:** `process`.

**Effects:** `write` (gateway process configuration and derived runtime units).

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The gateway authorizes the authenticated peer for `process:update` on the
  resolved owning node.
- To re-render runtime artifacts, the gateway must reach the owning node.

## Signature

```bash
orbit process:update [name] [--app=<app>] [--workspace=<workspace>] [--node=<node>] [--name=<new-name>] [--command=<command>] [--restart-policy=<never|on_failure|always>] [--crash-notification=<none|agent_ide>] [--runtime=<docker|docker-swarm|systemd>] [--restart] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `name` | `[name]` | Always. | Never. | None. | Existing process slug within the resolved owner scope. |
| `new_name` | `--name` | Optional. At least one editable field is required. | Never. | Current process slug. | Valid process slug, unique inside the resolved owner scope, and supported by the selected runtime/backend rename path. |
| `node` | `--node` | Required when updating a node-owned process. | `app` or `workspace` is present. | None. | Must resolve to a node that grants process-configuration mutation. |
| `app` | `--app` or app context | Required unless `node` is supplied or `workspace` resolves the app. | `node` is present. | Local app context when exactly one app is resolvable. | Must resolve to an app whose owning node grants process-configuration mutation. |
| `workspace` | `--workspace` or workspace context | Required when updating a workspace-owned process. | `node` is present. | Local workspace context when exactly one workspace is resolvable. | Must resolve to a workspace whose app owning node grants process-configuration mutation; pass `--app` when the workspace name is ambiguous. |
| `command` | `--command` | Optional. At least one editable field is required. | Never. | Current value. | Non-empty command string when supplied. |
| `restart_policy` | `--restart-policy` | Optional. At least one editable field is required. | Never. | Current value. | One of `never`, `on_failure`, `always`. |
| `crash_notification` | `--crash-notification` | Optional. At least one editable field is required. | Never. | Current value. | One of `none`, `agent_ide`. |
| `runtime` | `--runtime` | Optional. At least one editable field is required. | Never. | Current value. | One of `docker`, `docker-swarm`, `systemd`. App/workspace host-command processes accept `systemd`; `docker-swarm` requires node ownership. Orbit-managed Docker process rows remain lifecycle-manageable via process commands. |
| `restart` | `--restart` | Optional. | Never. | `false`. | Boolean flag. Restarts affected running runtime units after applying when true. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode. |

`command` is an option here because it is one optional editable field. Omitted editable fields preserve their current values. The sibling `process:add` command accepts `[command]` positionally because command is required to create a process definition.

`--name` is the new identity slug. `[name]` remains the current identity used to
find the process. Supplying `--name` with the same value is a no-op and does not
count as an editable field.

## Input Mode Contracts

- [Interactive input mode](5.1_process-update_input-mode_interactive.md)
- [Non-interactive input mode](5.2_process-update_input-mode_non-interactive.md)

## Behavior Contract

### Process Definition Update Rules

1. Resolve target node, app, or workspace context from supplied input or local context, and resolve the existing process definition within that owner scope.
2. Validate that at least one editable field is supplied.
3. When `--name` is supplied, validate that the new slug is unique in the owning
   scope and that the selected runtime/backend can safely replace derived unit
   identity before gateway state changes.
4. Send the request to the gateway, which validates the authenticated peer's authorization.
5. Update gateway-owned process configuration. Rename updates are atomic at the
   gateway state layer: either the process row has the new identity and
   dependent gateway records point at it, or the previous identity remains active.
6. Re-render the runtime units that the process definition produces. Node-owned
   and workspace-owned processes normally derive one unit. App-owned processes
   derive one main-app unit plus one unit for each active workspace.
7. When identity changes, remove or replace derived runtime units for the
   previous identity after the current desired units have been rendered, so
   `doctor --family=process --restore`
   can converge without orphaning the replaced unit.
8. When `--restart` is present, restart affected running runtime units and
   record lifecycle events for units that restart successfully.
9. Render the selected output.

If process configuration is updated but re-rendering or optional restart fails, the command returns success with repairable process-family warnings because the requested durable configuration exists.

## Renderer Contracts

- [Human renderer](6.1_process-update_output-render_human.md)
- [JSON renderer](6.2_process-update_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| `apps/gateway/tests/Feature/Http/Api/ProcessUpdateControllerTest.php` | Gateway API validation, authorization, rename uniqueness, runtime cleanup, unsupported rename, and warning envelopes. |
| Process not found | The named process does not exist for the resolved owner scope. | Failure (`error.code=process.not_found`). |
| Duplicate process name | `--name` matches another process in the resolved owner scope. | Failure (`error.code=process.name_conflict`; `error.meta.field=name`). |
| Unsupported rename | The selected runtime/backend cannot safely replace derived unit identity. | Failure (`error.code=process.rename_unsupported`; gateway state remains unchanged). |
| Invalid context | `--node` is combined with `--app` or `--workspace`, or no node/app/workspace context resolves. | Failure (`error.code=validation_failed`). |
| Invalid app/workspace command runtime | `--runtime=docker`, `--runtime=systemd`, or `--runtime=docker-swarm` is supplied for an app- or workspace-owned host-command process. | Failure (`error.code=validation_failed`; `error.meta.reason=docker_runtime_requires_service_or_managed_process`, `systemd_requires_node_owned_process`, or `docker_swarm_requires_node_owned_process`). |

## Doctor Relationship

`process:update` changes process configuration and attempts to re-render the runtime units derived from that definition. [`process-doctor.md`](../../process-doctor.md) owns later detection and repair of missing, divergent, or orphaned runtime units and lifecycle event notifier material.

## Activity Logging

The gateway API endpoint emits an activity entry for successful and failed process-configuration update attempts.

| Field | Value |
| --- | --- |
| `apps/gateway/tests/Feature/Http/Api/ProcessUpdateControllerTest.php` | Gateway API validation, authorization, rename uniqueness, runtime cleanup, unsupported rename, and warning envelopes. |
| `apps/gateway/tests/Feature/Http/Api/ProcessUpdateControllerTest.php` | Gateway API validation, authorization, rename uniqueness, runtime cleanup, unsupported rename, and warning envelopes. |
| Type | `api:PATCH /processes/{name}` |
| Effect | `write` |
| Subject | Resolved `Node` for node-owned processes or `App` for app/workspace-owned processes; `none` for validation, context-resolution, or authorization failures before the owner can be logged. |
| Properties | `node` (string or null), `app` (string or null), `workspace` (string or null), `old_name` (string), and `new_name` (string or null). No raw process command text, environment data, runtime output, or secrets. |
| Description | derived |

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Http/Api/ProcessUpdateControllerTest.php` | Gateway API validation, authorization, rename uniqueness, runtime cleanup, unsupported rename, and warning envelopes. |
| `apps/cli/tests/Feature/Commands/Process/ProcessWriteCommandTest.php` | Process update contract, rename uniqueness, grant authorization denial, required editable fields, app resolution, runtime unit rendering/replacement, optional restart behavior, repairable warnings, and no write on validation failure. |
| `apps/cli/tests/Feature/Commands/Process/ProcessWriteCommandTest.php` | Required inputs, editable field validation, name validation, enum validation, no-op rejection, and `--json` input-mode selection. |

Renderer and input-mode test mapping lives in the split companion files.
