# Technical Contract: `orbit process:add [name] [process_command]`

[Back to public `process:add` documentation.](../process-add.md)

**Owner:** `process`.

**Effects:** `write` (gateway process configuration and derived runtime units).

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The gateway authorizes the authenticated peer for `process:add` on the resolved owning node.
- Runtime artifact rendering requires gateway reachability to the owning node.

## Signature

```bash
orbit process:add [name] [process_command] [--app=<app>] [--workspace=<workspace>] [--node=<node>] [--tool=<tool>] [--service=<service>] [--version=<version>] [--image=<image>] [--restart-policy=<never|on_failure|always>] [--crash-notification=<none|agent_ide>] [--runtime=<docker|docker-swarm|systemd>] [--replace-container=<name>] [--force] [--start] [--no-start] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `name` | `[name]` | Always. | Never. | None. | Process slug: lowercase letters, digits, and hyphens only; cannot start or end with a hyphen; max 64 characters; unique within the resolved owner scope. |
| `process_command` | `[process_command]` | When `service` is absent. | Never. | Managed service command when `service` is present. | Non-empty command string. Stored as process configuration without shell rewriting by the input adapter. |
| `node` | `--node` | Required when adding a node-owned process. | `app` or `workspace` is present. | None. | Must resolve to a node that grants `process:add`. |
| `app` | `--app` or app context | Required unless `node` is supplied or `workspace` resolves the app. | `node` is present. | Local app context when exactly one app is resolvable. | Must resolve to an app whose owning node grants `process:add`. |
| `workspace` | `--workspace` or workspace context | Required when adding a workspace-owned process. | `node` is present. | Local workspace context when exactly one workspace is resolvable. | Must resolve to a workspace whose app owning node grants `process:add`; pass `--app` when the workspace name is ambiguous. |
| `tool` | `--tool` | Optional. | Never. | `null`. | Tool slug for the installed node capability this process uses. Tools do not own lifecycle. |
| `service` | `--service` | Optional. | When `tool` is present or when owner scope is app/workspace. | `null`. | Supported managed service identifier from the gateway service catalog. The process name does not imply the service. |
| `version` | `--version` | Optional for one-version services; required when the service has multiple version families. | When `service` is absent. | Service default when unambiguous. | Supported managed service version or version family. CLI implementation normalizes public `--version` to internal `--service-version` because Symfony reserves the global `--version` flag. |
| `image` | `--image` | Optional. | When `service` is absent or runtime is not Docker-compatible. | Resolved official image for `service` + `runtime` + `version`. | Explicit Docker image reference overriding the catalog default. |
| `restart_policy` | `--restart-policy` | Optional. | Never. | `never`. | One of `never`, `on_failure`, `always`. |
| `crash_notification` | `--crash-notification` | Optional. | Never. | `none`. | One of `none`, `agent_ide`. |
| `runtime` | `--runtime` | Optional. | Never. | `docker` for managed services; `systemd` for node-, app-, and workspace-owned host command processes. | One of `docker`, `docker-swarm`, `systemd`. App/workspace host-command processes accept `systemd`; `docker-swarm` requires node ownership. Managed services accept `docker` and `docker-swarm`. |
| `replace_containers` | repeated `--replace-container` | Optional migration cleanup for node-owned Docker managed services. | When `service` is absent, `node` is absent, or runtime is not `docker`. | Empty list. | Each value must be an explicit Docker container name. Non-interactive mode requires `--force`. The gateway removes only these named containers before writing new process configuration. |
| `force` | `--force` | Non-interactive `replace_containers`. | Never. | `false`. | Confirms destructive replacement-container cleanup without prompting. Ignored when no replacement containers are supplied. |
| `start` | `--start` | Optional redundant flag. | When `no_start` is present. | `true`. | Backward-compatible alias for default start behavior. Cannot be combined with `no_start`. |
| `no_start` | `--no-start` | Optional. | When `start` is present. | `false`. | Boolean flag. Skips starting rendered runtime units after apply. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode. |

`process_command` is positional here because it is required to create a process definition. The sibling `process:update` command uses `--command=<command>` because command is one optional editable field and omission preserves the current value. `process:edit` remains a compatibility alias for `process:update`.

## Input Mode Contracts

- [Interactive input mode](5.1_process-add_input-mode_interactive.md)
- [Non-interactive input mode](5.2_process-add_input-mode_non-interactive.md)

## Behavior Contract

### Process Definition Creation Rules

1. Resolve target node, app, or workspace context from supplied input or local context.
2. Send the request to the gateway, which validates the authenticated peer's authorization and process name uniqueness within the owner scope.
3. Validate managed-service endpoint and volume conflicts before any destructive replacement-container cleanup.
4. When explicit `replace_containers` are present, remove only those named Docker containers on the owning node.
5. Append gateway-owned process configuration after existing definitions for that owner, recording command, runtime, and policy fields.
6. Derive runtime-unit identities for the selected scope. Node-owned and workspace-owned processes normally derive one unit; app-owned processes derive one main-app unit plus one unit for each active workspace.
7. Render the derived runtime units on the owning node through the selected runtime backend.
8. Start rendered runtime units by default unless `--no-start` is present.
9. Record `started` events for units that start successfully.
10. Render the selected output.

If process configuration is written but runtime-unit apply or optional start fails, the command returns success with repairable process-family warnings because the requested durable configuration exists.

### Development Server Rules

- `process:add` stores the provided command without rewriting it for a specific frontend server.
- Development-server commands that need browser or HMR access across the Orbit network must bind to a node-reachable interface instead of loopback.
- For Vite-backed development servers, the expected command shape is `npm run dev -- --host=0.0.0.0`, or an equivalent package-manager/framework adapter command with the same bind behavior.
- Runtime units generated from the process definition receive Orbit URL and TLS environment fields, including `APP_URL`, `VITE_APP_URL`, `VITE_VALET_HOST`, `VITE_DEV_SERVER_KEY`, and `VITE_DEV_SERVER_CERT`.
- `VITE_VALET_HOST` is included for Laravel Vite and Vite Plus compatibility.
- Those toolchains read it when deriving TLS and hot-file URLs for development servers.

## Renderer Contracts

- [Human renderer](6.1_process-add_output-render_human.md)
- [JSON renderer](6.2_process-add_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Duplicate process | The resolved owner scope already has a process definition with the same name. | Failure (`error.code=process.name_collision`). |
| Invalid context | `--node` is combined with `--app` or `--workspace`, or no node/app/workspace context resolves. | Failure (`error.code=validation_failed`). |
| Invalid app/workspace command runtime | `--runtime=docker`, `--runtime=systemd`, or `--runtime=docker-swarm` is supplied for an app- or workspace-owned host-command process. | Failure (`error.code=validation_failed`; `error.meta.reason=docker_runtime_requires_service_or_managed_process`, `systemd_requires_node_owned_process`, or `docker_swarm_requires_node_owned_process`). |
| Version without managed service | `--version` is supplied without `--service`. | Failure (`error.code=validation_failed`; `error.meta.reason=process_service_version_requires_service`). |
| Invalid managed service scope | `--service` is supplied for an app- or workspace-owned process. | Failure (`error.code=validation_failed`; `error.meta.reason=process_service_requires_node_owned_process`). |
| Managed service with tool | `--service` is combined with `--tool`. | Failure (`error.code=validation_failed`; `error.meta.reason=process_service_cannot_reference_tool`). |
| Unsupported managed service | `--service` names an unsupported service. | Failure (`error.code=validation_failed`; `error.meta.reason=unsupported_value`). |
| Unsupported managed service runtime | `--service` is combined with a runtime other than `docker` or `docker-swarm`. | Failure (`error.code=validation_failed`; `error.meta.reason=process_service_runtime_unsupported`). |
| Managed service resource conflict | The managed service endpoint port or volume conflicts with another process on the node. | Failure (`error.code=validation_failed`; `error.meta.reason=endpoint_conflict` or `volume_conflict`). |
| Replacement cleanup without consent | `--replace-container` is supplied in non-interactive mode without `--force`. | Failure (`error.code=validation_failed`; `error.meta.field=force`; `error.meta.reason=destructive_consent_required`). |
| Invalid replacement cleanup scope | `--replace-container` is supplied outside a node-owned Docker managed service. | Failure (`error.code=validation_failed`; `error.meta.field=replace_containers`; `error.meta.reason=replace_container_requires_node_docker_service`). |
| Replacement cleanup failed | The gateway could not remove one explicitly named replacement container. | Failure (`error.code=process.replace_container_failed`; `error.meta.container=<name>`). No process configuration is written. |

## Doctor Relationship

`process:add` writes process configuration and attempts initial runtime-unit apply. [`process-doctor.md`](../../process-doctor.md) owns later detection and repair of missing or divergent runtime units and lifecycle event notifier material.

## Activity Logging

The gateway API endpoint emits an activity entry for successful and failed process-configuration creation attempts.

| Field | Value |
| --- | --- |
| Type | `api:POST /processes` |
| Effect | `write` |
| Subject | Resolved `Node` for node-owned processes or `App` for app/workspace-owned processes; `none` for validation, context-resolution, or authorization failures before the owner can be logged. |
| Properties | `node`, `app`, `workspace`, `name`, `tool`, and `service`. No raw command text, env, runtime output, replacement-container names, or secrets. |
| Description | derived |

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Http/Api/ProcessStoreControllerTest.php` | Process creation, grant denial, app resolution, defaults, duplicate names, managed service selector, default start, image override, replacement-container cleanup, repairable warnings, and no write on validation failure. |
| `apps/cli/tests/Feature/Commands/Process/ProcessWriteCommandTest.php` | CLI payload mapping, enum validation, default start, `--no-start`, managed service selector, and `--json` input-mode selection. |
| `apps/cli/tests/Feature/Commands/Process/ProcessAddServiceSelectorContractTest.php` | Public `--version` normalization, managed service payloads, image override, replacement-container consent, and human start-step defaults. |

Renderer and input-mode test mapping lives in the split companion files.
