# Technical Contract: `orbit process:update [name]`

[Back to public `process:update` documentation.](../process-update.md)

**Owner:** `process`.

**Effects:** `write` (gateway process configuration and derived runtime units).

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The gateway authorizes the authenticated peer for `process:update` on the
  resolved node or instance serving node.
- To re-render runtime artifacts, the gateway must reach that serving node.

## Signature

```bash
orbit process:update [name] [--instance=<app.instance>] [--workspace=<workspace>] [--node=<node>] [--name=<new-name>] [--label=<label>] [--command=<command>] [--restart-policy=<never|on_failure|always>] [--crash-notification=<none>] [--runtime=<docker|docker-swarm|systemd|launchd>] [--restart] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `name` | `[name]` | Always. | Never. | None. | Existing process identity slug (`key`) within the resolved owner scope. |
| `new_name` | `--name` | Optional. At least one editable field is required. | Never. | Current process slug. | Valid process slug, unique inside the resolved owner scope, and supported by the selected runtime/backend rename path. Renaming identity never rewrites the persisted display `label`. |
| `label` | `--label` / body `label` | Optional. At least one editable field is required. | Never. | Current value. | Trimmed non-empty string; max 255 characters. Updates only the display label. |
| `node` | `--node` | Required when updating a node-owned process. | `instance` or `workspace` is present. | None. | Must resolve to a node that grants process-configuration mutation. |
| `instance` | `--instance` or instance context | Required unless `node` is supplied or `workspace` resolves the instance. | `node` is present. | Local instance context when exactly one is resolvable. | Prefer `<app.instance>`. A bare app slug is valid only when it has exactly one instance. The selected instance's serving node must grant process-configuration mutation. |
| `workspace` | `--workspace` or workspace context | Required when updating a workspace-owned process. | `node` is present. | Local workspace context when exactly one workspace is resolvable. | Must resolve to a workspace and its instance whose serving node grants process-configuration mutation; pass `--instance=<app.instance>` when the workspace name is ambiguous. |
| `command` | `--command` | Optional. At least one editable field is required. | Never. | Current value. | Non-empty command string when supplied. |
| `restart_policy` | `--restart-policy` | Optional. At least one editable field is required. | Never. | Current value. | One of `never`, `on_failure`, `always`. |
| `crash_notification` | `--crash-notification` | Optional. At least one editable field is required. | Never. | Current value. | One of `none`, `none`. |
| `runtime` | `--runtime` | Optional. At least one editable field is required. | Never. | Current value. | One of `docker`, `docker-swarm`, `systemd`, `launchd`. See runtime selection below. |
| `restart` | `--restart` | Optional. | Never. | `false`. | Boolean flag. Restarts affected running runtime units after applying when true. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode. |

`command` is an option here because it is one optional editable field. Omitted editable fields preserve their current values. The sibling `process:add` command accepts `[command]` positionally because command is required to create a process definition.

`--name` is the new identity slug. `[name]` remains the current identity used to
find the process. Supplying `--name` with the same value is a no-op and does not
count as an editable field.

Omitting `--runtime` preserves the current runtime. Host-command processes
created without an explicit runtime default to `systemd` on Linux nodes and
`launchd` on macOS nodes. Managed services default to `docker` unless their
catalog entry and node platform admit another service runtime.

When `--runtime` is supplied, host-command processes use `systemd` on Linux and
`launchd` on macOS. Managed services accept `docker`, and accept
`docker-swarm` only when their catalog entry and Linux node platform admit it.
Orbit-managed Docker process rows remain lifecycle-manageable via process
commands.

## Input Mode Contracts

- [Interactive input mode](5.1_process-update_input-mode_interactive.md)
- [Non-interactive input mode](5.2_process-update_input-mode_non-interactive.md)

## Behavior Contract

### Process Definition Update Rules

1. Resolve a target node, concrete instance, or workspace context from supplied input or local context, and resolve the existing process definition within that owner scope. Reject a bare app selector with `validation_failed`, `field=instance`, and `reason=instance_required` unless that app has exactly one instance.
2. Validate that at least one editable field is supplied.
3. When `--name` is supplied, validate that the new slug is unique in the owning
   scope and that the selected runtime/backend can safely replace derived unit
   identity before gateway state changes. Identity rename does not change
   `label`.
4. When `--label` is supplied, validate the trimmed non-empty display label
   (max 255) without changing the identity slug.
5. Send the request to the gateway, which validates the authenticated peer's authorization.
6. Update gateway-owned process configuration. Rename updates are atomic at the
   gateway state layer: either the process row has the new identity and
   dependent gateway records point at it, or the previous identity remains active.
7. Re-render the runtime units that the process definition produces on the
   resolved node or instance serving node. Node-owned and workspace-owned
   processes normally derive one unit. Instance-owned processes derive one
   main-instance unit plus one unit for each active workspace belonging to that
   same instance. Canonical identities include both app and instance slugs.
   Label-only updates do not require runtime-unit identity replacement.
8. When identity changes, remove or replace derived runtime units for the
   previous identity after the current desired units have been rendered, so
   `doctor --family=process --restore`
   can converge without orphaning the replaced unit.
9. When `--restart` is present, restart affected running runtime units and
   record lifecycle events for units that restart successfully.
10. Render the selected output.

If process configuration is updated but re-rendering or optional restart fails, the command returns success with repairable process-family warnings because the requested durable configuration exists.

## Renderer Contracts

- [Human renderer](6.1_process-update_output-render_human.md)
- [JSON renderer](6.2_process-update_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Process not found | The named process does not exist for the resolved owner scope. | Failure (`error.code=process.not_found`). |
| Duplicate process name | `--name` matches another process in the resolved owner scope. | Failure (`error.code=process.name_conflict`; `error.meta.field=name`). |
| Unsupported rename | The selected runtime/backend cannot safely replace derived unit identity. | Failure (`error.code=process.rename_unsupported`; gateway state remains unchanged). |
| Invalid context | `--node` is combined with `--instance` or `--workspace`, or no node/instance/workspace context resolves. | Failure (`error.code=validation_failed`). |
| Instance required | A bare app selector resolves to more than one instance. | Failure (`error.code=validation_failed`; `error.meta.field=instance`; `error.meta.reason=instance_required`). |
| Invalid host-command container runtime | `--runtime=docker` or `--runtime=docker-swarm` is supplied for a public app- or workspace-owned host-command process. | Failure (`error.code=validation_failed`; `error.meta.reason=docker_runtime_requires_service_or_managed_process` or `docker_swarm_requires_node_owned_process`). |
| Invalid host-command platform runtime | `--runtime=systemd` is supplied for a macOS host-command process, or `--runtime=launchd` is supplied for a Linux host-command process. | Failure (`error.code=validation_failed`; `error.meta.reason=systemd_runtime_requires_linux` or `launchd_runtime_requires_macos`). |

## Doctor Relationship

`process:update` changes process configuration and attempts to re-render the runtime units derived from that definition. [`process-doctor.md`](../../process-doctor.md) owns later detection and repair of missing, divergent, or orphaned runtime units and lifecycle event notifier material.

## Activity Logging

The gateway API endpoint emits an activity entry for successful and failed process-configuration update attempts.

| Field | Value |
| --- | --- |
| Type | `api:PATCH /processes/{name}` |
| Effect | `write` |
| Subject | Resolved `Node` for node-owned processes or `Instance` for instance/workspace-owned processes; `none` for validation, context-resolution, or authorization failures before the owner can be logged. |
| Properties | `node` (string or null), `instance` (string or null), `workspace` (string or null), `old_name` (string), and `new_name` (string or null). No raw process command text, environment data, runtime output, or secrets. |
| Description | derived |

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Http/Api/ProcessUpdateControllerTest.php` | Gateway API validation, authorization, rename uniqueness, runtime cleanup, unsupported rename, and warning envelopes. |
| `apps/cli/tests/Feature/Commands/Process/ProcessWriteCommandTest.php` | Process update contract, rename uniqueness, grant authorization denial, required editable fields, app resolution, runtime unit rendering/replacement, optional restart behavior, repairable warnings, and no write on validation failure. |
| `apps/cli/tests/Feature/Commands/Process/ProcessWriteCommandTest.php` | Required inputs, editable field validation, name validation, enum validation, no-op rejection, and `--json` input-mode selection. |

Renderer and input-mode test mapping lives in the split companion files.
