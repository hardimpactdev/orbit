# Technical Contract: `orbit process:add [name] [command]`

[Back to public `process:add` documentation.](../process-add.md)

**Owner:** `process`.

**Effects:** `write` (gateway process configuration and derived runtime units).

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The gateway authorizes the authenticated peer for `process:add` on the resolved owning node.
- Runtime artifact rendering requires gateway reachability to the owning node.

## Signature

```bash
orbit process:add [name] [command] [--node=<node>] [--app=<app>] [--workspace=<workspace>] [--tool=<tool>] [--restart-policy=<never|on_failure|always>] [--crash-notification=<none|agent_ide>] [--runtime=<docker|supervisor|systemd>] [--start] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `name` | `[name]` | Always. | Never. | None. | Process slug: lowercase letters, digits, and hyphens only; cannot start or end with a hyphen; max 64 characters; unique within the resolved owner scope. |
| `command` | `[command]` | Always. | Never. | None. | Non-empty command string. Stored as process configuration without shell rewriting by the input adapter. |
| `node` | `--node` | Required when adding a node-owned process. | `app` or `workspace` is present. | None. | Must resolve to a node that grants `process:add`. |
| `app` | `--app` or app context | Required unless `node` is supplied or `workspace` resolves the app. | `node` is present. | Local app context when exactly one app is resolvable. | Must resolve to an app whose owning node grants `process:add`. |
| `workspace` | `--workspace` or workspace context | Required when adding a workspace-owned process. | `node` is present. | Local workspace context when exactly one workspace is resolvable. | Must resolve to a workspace whose app owning node grants `process:add`; pass `--app` when the workspace name is ambiguous. |
| `tool` | `--tool` | Optional. | Never. | `null`. | Tool slug for the installed node capability this process uses. Tools do not own lifecycle. |
| `restart_policy` | `--restart-policy` | Optional. | Never. | `never`. | One of `never`, `on_failure`, `always`. |
| `crash_notification` | `--crash-notification` | Optional. | Never. | `none`. | One of `none`, `agent_ide`. |
| `runtime` | `--runtime` | Optional. | Never. | Context default: `systemd` for node-owned processes, app runtime default for app/workspace processes. | One of `docker`, `supervisor`, `systemd`. `systemd` is valid only when `node` owns the process. |
| `start` | `--start` | Optional. | Never. | `false`. | Boolean flag. Starts rendered runtime units after applying when true. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode. |

`command` is positional here because it is required to create a process definition. The sibling `process:edit` command uses `--command=<command>` because command is one optional editable field and omission preserves the current value.

## Input Mode Contracts

- [Interactive input mode](5.1_process-add_input-mode_interactive.md)
- [Non-interactive input mode](5.2_process-add_input-mode_non-interactive.md)

## Behavior Contract

### Process Definition Creation Rules

1. Resolve target node, app, or workspace context from supplied input or local context.
2. Send the request to the gateway, which validates the authenticated peer's authorization and process name uniqueness within the owner scope.
3. Append gateway-owned process configuration after existing definitions for that owner, with command, runtime, optional tool dependency, restart policy, and crash notification policy.
4. Derive runtime-unit identities for the selected scope. Node-owned and workspace-owned processes normally derive one unit; app-owned processes derive one main-app unit plus one unit for each active workspace.
5. Render the derived runtime units on the owning node through the selected runtime backend.
6. When `--start` is present, start the rendered runtime units and record `started` events for units that start successfully.
7. Render the selected output.

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
| Invalid runtime scope | `--runtime=systemd` is supplied for an app- or workspace-owned process. | Failure (`error.code=validation_failed`, `error.meta.reason=systemd_requires_node_owned_process`). |

## Doctor Relationship

`process:add` writes process configuration and attempts initial runtime-unit apply. [`process-doctor.md`](../../process-doctor.md) owns later detection and repair of missing or divergent runtime units and lifecycle event notifier material.

## Activity Logging

The gateway API endpoint emits an activity entry for successful and failed process-configuration creation attempts.

| Field | Value |
| --- | --- |
| Type | `api:POST /processes` |
| Effect | `write` |
| Subject | Resolved `Node` for node-owned processes or `App` for app/workspace-owned processes; `none` for validation, context-resolution, or authorization failures before the owner can be logged. |
| Properties | `node` (string or null), `app` (string or null), `workspace` (string or null), `name` (string or null), and `tool` (string or null). No raw process command text, environment data, runtime output, or secrets. |
| Description | derived |

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Commands/Processes/ProcessAddCommandTest.php` | Process creation, grant denial, app resolution, defaults, duplicate names, runtime rendering, optional start, repairable warnings, and no write on validation failure. |
| `apps/gateway/tests/Feature/Commands/Processes/ProcessAddInputContractTest.php` | Required inputs, process slug validation, enum validation, default restart policy, default crash notification, and `--json` input-mode selection. |

Renderer and input-mode test mapping lives in the split companion files.
