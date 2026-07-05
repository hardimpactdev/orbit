# Technical Contract: `orbit tool:start <tool> [--app=<app>] [--node=<node>] [--node-transport=<transport>] [--json|--stream-json]`

[Back to public `tool-start` documentation.](../tool-start.md)

**Owner:** `tool`.

**Effects:** `write, stream`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway, or the command is running on the gateway.
- The current node identity is authorized to manage tools for the resolved node or app.

## Signature

```bash
orbit tool:start <tool> [--app=<app>] [--node=<node>] [--node-transport=<transport>] [--json|--stream-json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `tool` | `argument` | Always. | Never. | None. | Registered lifecycle-capable tool name. |
| `node` | `--node` | When no `--app` or local `node:default` is available. | Never. | `node:default` when set. | Visible active non-gateway node slug; selected tool must support the node operating system. |
| `node_transport` | `--node-transport` | Optional. | Never. | `auto`. | One of `auto`, `agent-push`, or `transitional-ssh-fallback`. |
| `app` | `--app` | Optional. | Never. | None. | Visible app selector used to resolve the owning node. |
| `json` | `--json` | Optional. | Never. | `false` | Selects the JSON renderer and non-interactive input mode. |
| `stream-json` | `--stream-json` | Optional. | Never. | `false` | Selects newline-delimited progress frames and non-interactive input mode. Mutually exclusive with `--json`. |

## Behavior Contract

### Tool-Owned Lifecycle

- Dispatches the selected tool definition's explicit start script.
- The initial supported tool is `orbstack` on macOS.
- Unsupported tools fail before host command execution.
- Unsupported target operating systems fail before host command execution.
- The command does not create, update, or route through process rows.
- `tool:start` does not imply support for tool log streaming or reload commands.

### Scope Boundaries

`tool-start` must not create apps, workspaces, processes, schedules, proxy
routes, firewall rules, node identities, node grants, or related-process
compatibility adapters. Related drift belongs to each owning family doctor
contract.

## Renderer Contracts

- [Human renderer](6.1_tool-start_output-render_human.md)
- [JSON renderer](6.2_tool-start_output-render_json.md)

`--stream-json` uses the shared
[Stream JSON Frames](../../../README.md#stream-json-frames) contract.

## Failure Semantics

Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Tool not found | The selected tool row or tool definition cannot be resolved. | `error.code=tool.not_found` |
| Unsupported tool action | The selected tool definition does not support start. | `error.code=tool.unsupported_action` |
| Unsupported platform | The selected tool does not support the target node operating system. | `error.code=tool.unsupported_on_node` |
| Remote action failed | Gateway configuration was readable, but node execution failed. | `error.code=tool.remote_action_failed` |

## Doctor Relationship

`tool-start` performs a lifecycle action that an operator requested explicitly.
[`tool-doctor.md`](../../tool-doctor.md) owns the authoritative tool-family
probe, issue codes, fix map, and adopt map.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Tool/ToolWriteCommandTest.php` | CLI `tool:start` target resolution, JSON envelope, stream request shape, and gateway error envelope pass-through. |
| `apps/gateway/tests/Feature/Http/Api/ToolLifecycleControllerTest.php` | Gateway lifecycle API success, unsupported tool, unsupported platform, and no-side-effect failure paths. |
| `apps/gateway/tests/Unit/Services/Tools/OrbStackToolTest.php` | OrbStack lifecycle scripts and macOS-only support. |
| `apps/gateway/tests/Unit/Services/Tools/ToolCatalogTest.php` | Catalog `lifecycleScript()` returns OrbStack start scripts and returns `null` for non-lifecycle Docker. |
