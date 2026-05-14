# Technical Contract: `orbit process:add [name] [command]`

[Back to public `process:add` documentation.](../process-add.md)

**Owner:** `process`.

**Effects:** `write` (gateway process configuration and derived runtime units).

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The gateway authorizes the authenticated peer for process-configuration writes on the target app. `app` and `unknown` callers are denied.
- `control` and `gateway` callers may proceed when authorized.
- Runtime artifact rendering requires gateway reachability to the owning app node.

## Signature

```bash
orbit process:add [name] [command] [--app=<app>] [--restart-policy=<never|on_failure|always>] [--crash-notification=<none|agent_ide>] [--start] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `name` | `[name]` | Always. | Never. | None. | Process slug: lowercase letters, digits, and hyphens only; cannot start or end with a hyphen; max 64 characters; unique within the owning app. |
| `command` | `[command]` | Always. | Never. | None. | Non-empty command string. Stored as process configuration without shell rewriting by the input adapter. |
| `app` | `--app` or app context | Always. | Never. | Local app context when exactly one app is resolvable. | Must resolve to an app the caller may manage. |
| `restart_policy` | `--restart-policy` | Optional. | Never. | `never`. | One of `never`, `on_failure`, `always`. |
| `crash_notification` | `--crash-notification` | Optional. | Never. | `none`. | One of `none`, `agent_ide`. |
| `start` | `--start` | Optional. | Never. | `false`. | Boolean flag. Starts rendered runtime units after applying when true. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode. |

`command` is positional here because it is required to create a process definition. The sibling `process:edit` command uses `--command=<command>` because command is one optional editable field and omission preserves the current value.

## Input Mode Contracts

- [Interactive input mode](5.1_process-add_input-mode_interactive.md)
- [Non-interactive input mode](5.2_process-add_input-mode_non-interactive.md)

## Behavior Contract

### Process Definition Creation Rules

1. Resolve target app from supplied input or local app context.
2. Send the request to the gateway, which validates the authenticated peer's authorization and process name uniqueness within the app.
3. Append gateway-owned process configuration after existing definitions for the app, with command, restart policy, and crash notification policy.
4. Derive runtime-unit identities for the main app instance and all active workspaces.
5. Render the derived runtime units on the owning app node.
6. When `--start` is present, start the rendered runtime units and record `started` events for units that start successfully.
7. Render the selected output.

If process configuration is written but runtime-unit apply or optional start fails, the command returns success with repairable process-family warnings because the requested durable configuration exists.

### Development Server Rules

- `process:add` stores the provided command without rewriting it for a specific frontend server.
- Development-server commands that need browser or HMR access across the Orbit network must bind to a node-reachable interface instead of loopback.
- For Vite-backed development servers, the expected command shape is `npm run dev -- --host=0.0.0.0`, or an equivalent package-manager/framework adapter command with the same bind behavior.
- Runtime units generated from the process definition receive Orbit URL and TLS environment fields, including `APP_URL`, `VITE_APP_URL`, `VITE_VALET_HOST`, `VITE_DEV_SERVER_KEY`, and `VITE_DEV_SERVER_CERT`.
- `VITE_VALET_HOST` is included for Laravel Vite and Vite Plus compatibility.
- Those toolchains may use it while deriving TLS and hot-file URLs for long-running development servers.

## Renderer Contracts

- [Human renderer](6.1_process-add_output-render_human.md)
- [JSON renderer](6.2_process-add_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Duplicate process | The owning app already has a process definition with the same name. | Failure (`error.code=process.name_collision`). |

## Doctor Relationship

`process:add` writes process configuration and attempts initial runtime-unit apply. [`process-doctor.md`](../../process-doctor.md) owns later detection and repair of missing or divergent runtime units and lifecycle event notifier material.

## Activity Logging

The gateway API endpoint emits an activity entry for successful and failed process-configuration creation attempts.

| Field | Value |
| --- | --- |
| Type | `api:POST /processes` |
| Effect | `write` |
| Subject | `App` when the parent app is resolved and visible; `none` for validation, app-resolution, caller-role, or authorization failures before the app can be logged. |
| Properties | `app` (string or null) and `name` (string or null). No raw process command text, environment data, runtime output, or secrets. |
| Description | derived |

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Processes/ProcessAddCommandTest.php` | Process creation contract, caller-role denial, app resolution, default append behavior, defaults, duplicate-name failure, runtime-unit rendering, optional start behavior, repairable warnings on post-configuration apply failure, and no write on validation failure. |
| `tests/Feature/Commands/Processes/ProcessAddInputContractTest.php` | Required inputs, process slug validation, enum validation, default restart policy, default crash notification, and `--json` input-mode selection. |

Renderer and input-mode test mapping lives in the split companion files.
