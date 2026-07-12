# Technical Contract: `orbit tool:restart <tool> [--app=<app>] [--node=<node>] [--json|--stream-json]`

[Back to public `tool-restart` documentation.](../tool-restart.md)

**Owner:** `tool`.

**Effects:** `write, stream`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway, or the command is running on the gateway.
- The current node identity has `tool:restart` granted on the resolved serving
  node. Gateway identity remains implicit.

## Signature

```bash
orbit tool:restart <tool> [--app=<app>] [--node=<node>] [--json|--stream-json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `tool` | `argument` | Always. | Never. | None. | Registered lifecycle-capable tool name. |
| `node` | `--node` | When no `--app` or local `node:default` is available. | Never. | `node:default` when set. | Visible active node slug; selected tool must satisfy its operating-system, runtime-user, TLD/route, isolation, and gateway-local constraints. |
| `app` | `--app` | Optional. | Never. | None. | Visible app selector used to resolve the owning node. |
| `json` | `--json` | Optional. | Never. | `false` | Selects the JSON renderer and non-interactive input mode. |
| `stream-json` | `--stream-json` | Optional. | Never. | `false` | Selects newline-delimited progress frames and non-interactive input mode. Mutually exclusive with `--json`. |

## Behavior Contract

### Declared Lifecycle

- Requires the selected tool definition to declare `restart`.
- Resolves exactly one direct tool-owned runtime or exactly one process row
  whose canonical `tool` value matches the selected tool.
- A direct remote runtime dispatches through Agent push. A process-backed
  runtime dispatches the native process restart action.
- The gateway-local `dns` runtime restarts `orbit-dns` directly on the active
  serving gateway. A non-gateway caller requires `tool:restart` on that
  gateway; gateway identity remains implicit. No remote target is accepted.
- Unsupported tools fail before host command execution.
- Unsupported target constraints fail before runtime execution.
- A missing or ambiguous runtime fails explicitly before runtime execution.
- Support for `restart` does not imply support for any other runtime verb.

### Scope Boundaries

`tool-restart` must not create apps, workspaces, processes, schedules, proxy
routes, firewall rules, node identities, or node grants. It may address the one
exact matching process row but never creates or repairs that row and never
falls back to a similarly named process.

## Renderer Contracts

- [Human renderer](6.1_tool-restart_output-render_human.md)
- [JSON renderer](6.2_tool-restart_output-render_json.md)

`--stream-json` uses the shared
[Stream JSON Frames](../../../README.md#stream-json-frames) contract.

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| Tool not found | The selected tool row or tool definition cannot be resolved. | `error.code=tool.not_found` |
| Unsupported tool action | The selected tool definition does not support restart. | `error.code=tool.unsupported_action` |
| Unsupported platform | The selected tool does not support the target node operating system. | `error.code=tool.unsupported_on_node` |
| Runtime missing | No direct runtime or matching process row exists. | `error.code=tool.runtime_missing` |
| Runtime ambiguous | More than one direct/process runtime target resolves. | `error.code=tool.runtime_ambiguous` |
| Agent push required | A direct remote runtime cannot be reached through Agent push. | `error.code=node_transport_required` |
| Remote action failed | Gateway configuration was readable, but node execution failed. | `error.code=tool.remote_action_failed` |

## Doctor Relationship

`tool-restart` performs a lifecycle action that an operator requested explicitly.
[`tool-doctor.md`](../../tool-doctor.md) owns the authoritative tool-family
probe, issue codes, fix map, and adopt map.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Tool/ToolWriteCommandTest.php` | CLI `tool:restart` target resolution, JSON envelope, stream request shape, and gateway error envelope pass-through. |
| `apps/gateway/tests/Feature/Http/Api/ToolLifecycleControllerTest.php` | Gateway lifecycle API success, serving-gateway grants, wrong-permission denial, unsupported tool/platform, and no-side-effect failure paths. |
| `apps/gateway/tests/Unit/Services/Tools/OrbStackToolTest.php` | OrbStack lifecycle scripts and macOS-only support. |
