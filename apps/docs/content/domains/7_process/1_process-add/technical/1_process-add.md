# Technical Contract: `orbit process:add [name] [process_command]`

[Back to public `process:add` documentation.](../process-add.md)

**Owner:** `process`.

**Effects:** `write` (gateway process configuration and derived runtime units).

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The gateway authorizes the authenticated peer for `process:add` on the resolved node or instance serving node.
- Runtime artifact rendering requires gateway reachability to that serving node.

## Signature

```bash
orbit process:add [name] [process_command] [--instance=<app.instance>] [--workspace=<workspace>] [--node=<node>] [--label=<label>] [--tool=<tool>] [--service=<service>] [--version=<version>] [--database=<name>] [--username=<name>] [--published-port=<port>] [--image=<image>] [--bind=<wireguard|loopback>] [--restart-policy=<never|on_failure|always>] [--crash-notification=<none>] [--runtime=<docker|docker-swarm|systemd|launchd>] [--replace-container=<name>] [--force] [--no-start] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `name` | `[name]` | Always. | Never. | None. | Process identity slug (`key`): lowercase letters, digits, and hyphens only; cannot start or end with a hyphen; max 64 characters; unique within the resolved owner scope. |
| `label` | `--label` / body `label` | Optional. | Never. | The process identity key (`name`) when omitted. | Trimmed non-empty string; max 255 characters. |
| `process_command` | `[process_command]` | When `service` is absent. | Never. | Managed service command when `service` is present. | Non-empty command string. Stored as process configuration without shell rewriting by the input adapter. |
| `node` | `--node` | Required when adding a node-owned process. | `instance` or `workspace` is present. | None. | Must resolve to a node that grants `process:add`. |
| `instance` | `--instance` or instance context | Required unless `node` is supplied or `workspace` resolves the instance. | `node` is present. | Local instance context when exactly one is resolvable. | Prefer `<app.instance>`. A bare app slug is valid only when it has exactly one instance. The selected instance's serving node must grant `process:add`. |
| `workspace` | `--workspace` or workspace context | Required when adding a workspace-owned process. | `node` is present. | Local workspace context when exactly one workspace is resolvable. | Must resolve to a workspace and its instance whose serving node grants `process:add`; pass `--instance=<app.instance>` when the workspace name is ambiguous. |
| `tool` | `--tool` | Optional. | Never. | `null`. | Tool slug for the installed node capability this process uses. Tools do not own lifecycle. |
| `service` | `--service` | Optional. | When `tool` is present or when owner scope is instance/workspace. | `null`. | Supported managed service identifier from the gateway service catalog. The process name does not imply the service. |
| `version` | `--version` | Optional for one-version services; required when the service has multiple version families. | When `service` is absent. | Service default when unambiguous. | Supported managed service version or version family. CLI implementation normalizes public `--version` to internal `--service-version` because Symfony reserves the global `--version` flag. |
| `database` | `--database` / `service_options.database` | `service=postgres`. | Every other service or host-command process. | None. | Lowercase PostgreSQL identifier containing letters, digits, and underscores, starting with a letter or underscore, max 63 characters. |
| `username` | `--username` / `service_options.username` | `service=postgres`. | Every other service or host-command process. | None. | Lowercase PostgreSQL identifier containing letters, digits, and underscores, starting with a letter or underscore, max 63 characters. |
| `published_port` | `--published-port` / `service_options.published_port` | `service=postgres`. | Host-command processes and multi-port managed services. | None for `postgres`; catalog default port for other single-port services. | Integer from 1 through 65535. Optional override for single-port managed services such as `valkey`; the container target port stays the catalog value. |
| `binds` | repeated `--bind` | Optional for node-owned Docker managed services. | Host-command processes; instance/workspace ownership; `runtime=docker-swarm`; when `service` is absent. | `["wireguard"]` when omitted. | Each value must be exactly `wireguard` or `loopback`. Empty strings and unsupported values fail with `validation_failed` (`field=bind`). Duplicates normalize. Explicit selectors replace the WireGuard-only default. Arbitrary IP addresses or interface names are never accepted. |
| `image` | `--image` | Optional. | When `service` is absent or runtime is not Docker-compatible. | Resolved official image for `service` + `runtime` + `version`. | Explicit Docker image reference overriding the catalog default. A PostgreSQL override must expose a tag whose major matches the selected version family; an override cannot represent a different major while retaining stale metadata. |
| `restart_policy` | `--restart-policy` | Optional. | Never. | `never`. | One of `never`, `on_failure`, `always`. |
| `crash_notification` | `--crash-notification` | Optional. | Never. | `none`. | The only supported value is `none`. |
| `runtime` | `--runtime` | Optional. | Never. | `docker` for managed services; `systemd` for Linux node-, app-, and workspace-owned host command processes; `launchd` for macOS node-, app-, and workspace-owned host command processes. | One of `docker`, `docker-swarm`, `systemd`, `launchd`. Host-command processes use `systemd` on Linux and `launchd` on macOS. Managed services accept `docker`, and accept `docker-swarm` only when their catalog entry and Linux node platform admit it. |
| `replace_containers` | repeated `--replace-container` | Optional migration cleanup for node-owned Docker managed services. | When `service` is absent, `node` is absent, or runtime is not `docker`. | Empty list. | Each value must be an explicit Docker container name. Non-interactive mode requires `--force`. The gateway removes only these named containers before writing new process configuration. |
| `force` | `--force` | Non-interactive `replace_containers`. | Never. | `false`. | Confirms destructive replacement-container cleanup without prompting. Ignored when no replacement containers are supplied. |
| `no_start` | `--no-start` | Optional. | Never. | `false`. | Boolean flag. Skips starting rendered runtime units after apply. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode. |

`process_command` is positional here because it is required to create a process definition. The sibling `process:update` command uses `--command=<command>` because command is one optional editable field and omission preserves the current value.

## Input Mode Contracts

- [Interactive input mode](5.1_process-add_input-mode_interactive.md)
- [Non-interactive input mode](5.2_process-add_input-mode_non-interactive.md)

## Behavior Contract

### Process Definition Creation Rules

1. Resolve a target node, concrete instance, or workspace context from supplied input or local context. Reject a bare app selector with `validation_failed`, `field=instance`, and `reason=instance_required` unless that app has exactly one instance.
2. Send the request to the gateway, which validates the authenticated peer's authorization and process name uniqueness within the owner scope.
3. Resolve typed managed-service options and normalized publish binds
   (`wireguard` and/or `loopback`), then validate every selected host and
   published port plus volume names against every process on the node before any
   runtime, configuration, or destructive replacement-container effect.
   Omitted binds default to WireGuard-only. `loopback` publishes on host-local
   `127.0.0.1` and is not reachable as `127.0.0.1` from another container.
   `wireguard` publishes on the node's WireGuard service address. When both are
   selected, every service target port is published on both hosts at the same
   published port. The primary `endpoint` prefers WireGuard when selected;
   otherwise it is loopback. Every selected bind appears in `endpoints`.
4. When explicit `replace_containers` are present, remove only those named Docker containers on the resolved node.
5. Resolve the runtime backend. Host-command processes default to `systemd` on
   Linux nodes and `launchd` on macOS nodes. Managed services default to
   `docker` unless their catalog entry and node platform admit another service
   runtime.
6. Append gateway-owned process configuration after existing definitions for that owner, recording command, runtime, policy fields, and durable display `label` (defaulting to the identity key when omitted).
7. Derive runtime-unit identities for the selected scope. Node-owned and workspace-owned processes normally derive one unit. Instance-owned processes derive one main-instance unit plus one unit for each registered workspace belonging to that same instance. Canonical identities include both app and instance slugs.
8. Render the derived runtime units on the resolved node or instance serving node through the selected runtime backend.
9. Start rendered runtime units by default unless `--no-start` is present. When
   starting, record and publish a durable transitional `starting` event before
   each runtime call, then a terminal `started` event on success or `failed`
   when the backend returns false or throws (same starting→started/failed
   pattern as `process:start`).
10. Render the selected output.

If process configuration is written but runtime-unit apply or optional start fails, the command returns success with repairable process-family warnings because the requested durable configuration exists.

### Development Server Rules

- `process:add` stores the provided command without rewriting it for a specific frontend server.
- Development-server commands that need browser or HMR access across the Orbit network must bind to a node-reachable interface instead of loopback.
- For Vite-backed development servers, the expected command shape is `npm run dev -- --host=0.0.0.0`, or an equivalent package-manager/framework adapter command with the same bind behavior.
- Runtime units generated from the process definition receive Orbit URL and TLS environment fields, including `APP_URL`, `VITE_APP_URL`, `VITE_VALET_HOST`, `VITE_DEV_SERVER_KEY`, and `VITE_DEV_SERVER_CERT`.
- `VITE_VALET_HOST` is included for Herd/Valet-style Laravel Vite compatibility when app config keys off a host.
- `VITE_DEV_SERVER_KEY` and `VITE_DEV_SERVER_CERT` are included for Laravel Vite's standard env-provided certificate bridge.

## Renderer Contracts

- [Human renderer](6.1_process-add_output-render_human.md)
- [JSON renderer](6.2_process-add_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Duplicate process | The resolved owner scope already has a process definition with the same name. | Failure (`error.code=process.name_collision`). |
| Invalid context | `--node` is combined with `--instance` or `--workspace`, or no node/instance/workspace context resolves. | Failure (`error.code=validation_failed`). |
| Instance required | A bare app selector resolves to more than one instance. | Failure (`error.code=validation_failed`; `error.meta.field=instance`; `error.meta.reason=instance_required`). |
| Invalid host-command container runtime | `--runtime=docker` or `--runtime=docker-swarm` is supplied for a public app- or workspace-owned host-command process. | Failure (`error.code=validation_failed`; `error.meta.reason=docker_runtime_requires_service_or_managed_process` or `docker_swarm_requires_node_owned_process`). |
| Invalid host-command platform runtime | `--runtime=systemd` is supplied for a macOS host-command process, or `--runtime=launchd` is supplied for a Linux host-command process. | Failure (`error.code=validation_failed`; `error.meta.reason=systemd_runtime_requires_linux` or `launchd_runtime_requires_macos`). |
| Version without managed service | `--version` is supplied without `--service`. | Failure (`error.code=validation_failed`; `error.meta.reason=process_service_version_requires_service`). |
| Missing or invalid PostgreSQL option | `service=postgres` omits or supplies an invalid database, username, or published port. | Failure (`error.code=validation_failed`; `error.meta.field=service_options.<field>`). No runtime or configuration effect occurs. |
| PostgreSQL options for another service | PostgreSQL initialization options are supplied when `service` is absent or not `postgres`. | Failure (`error.code=validation_failed`; `error.meta.field=service_options`; `error.meta.reason=process_service_options_unsupported`). |
| PostgreSQL image major mismatch | An explicit PostgreSQL image tag does not retain the selected PostgreSQL version family. | Failure (`error.code=validation_failed`; `error.meta.field=image`; `error.meta.reason=process_service_image_version_mismatch`). |
| Invalid managed service scope | `--service` is supplied for an app- or workspace-owned process. | Failure (`error.code=validation_failed`; `error.meta.reason=process_service_requires_node_owned_process`). |
| Managed service with tool | `--service` is combined with `--tool`. | Failure (`error.code=validation_failed`; `error.meta.reason=process_service_cannot_reference_tool`). |
| Unsupported managed service | `--service` names an unsupported service. | Failure (`error.code=validation_failed`; `error.meta.reason=unsupported_value`). |
| Unsupported managed service runtime | `--service` is combined with a runtime other than `docker` or `docker-swarm`. | Failure (`error.code=validation_failed`; `error.meta.reason=process_service_runtime_unsupported`). |
| Managed service resource conflict | Any selected bind host and published port or volume conflicts with another process on the node. | Failure (`error.code=validation_failed`; `error.meta.reason=endpoint_conflict` or `volume_conflict`). |
| Invalid publish bind | `--bind` is empty, unsupported, an IP/interface, used without a node-owned Docker managed service, or combined with Docker Swarm. | Failure (`error.code=validation_failed`; `error.meta.field=bind`; reasons such as `unsupported_value`, `required`, `process_bind_requires_node_docker_service`, or `process_bind_requires_docker_runtime`). |
| Replacement cleanup without consent | `--replace-container` is supplied in non-interactive mode without `--force`. | Failure (`error.code=validation_failed`; `error.meta.field=force`; `error.meta.reason=destructive_consent_required`). |
| Invalid replacement cleanup scope | `--replace-container` is supplied outside a node-owned Docker managed service. | Failure (`error.code=validation_failed`; `error.meta.field=replace_containers`; `error.meta.reason=replace_container_requires_node_docker_service`). |
| Replacement cleanup failed | The gateway could not remove one explicitly named replacement container. | Failure (`error.code=process.replace_container_failed`; `error.meta.container=<name>`). No process configuration is written. |

## Doctor Relationship

`process:add` writes process configuration and attempts initial runtime-unit apply. [`process-doctor.md`](../../process-doctor.md) owns later detection and repair of missing or divergent runtime units.

## Activity Logging

The gateway API endpoint emits an activity entry for successful and failed process-configuration creation attempts.

| Field | Value |
| --- | --- |
| Type | `api:POST /processes` |
| Effect | `write` |
| Subject | Resolved `Node` for node-owned processes or `Instance` for instance/workspace-owned processes; `none` for validation, context-resolution, or authorization failures before the owner can be logged. |
| Properties | `node`, `instance`, `workspace`, `name`, `tool`, and `service`. No raw command text, service options, env, runtime output, replacement-container names, or secrets. |
| Description | derived |

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Http/Api/ProcessStoreControllerTest.php` | Process creation, grant denial, app resolution, defaults, duplicate names, managed service selector, default start, image override, replacement-container cleanup, repairable warnings, and no write on validation failure. |
| `apps/cli/tests/Feature/Commands/Process/ProcessWriteCommandTest.php` | CLI payload mapping, enum validation, default start, `--no-start`, managed service selector, and `--json` input-mode selection. |
| `apps/cli/tests/Feature/Commands/Process/ProcessAddServiceSelectorContractTest.php` | Public `--version` normalization, managed service payloads, image override, replacement-container consent, and human start-step defaults. |
| `apps/cli/tests/Feature/Commands/Process/ProcessBindContractTest.php` | CLI `--bind` mapping, normalization, and pre-gateway validation for add/update. |
| `apps/gateway/tests/Unit/Services/Processes/ProcessServiceCatalogBindTest.php` | Catalog bind host resolution, dual-publish ports, endpoint priority, and legacy inference. |

Renderer and input-mode test mapping lives in the split companion files.
