# Technical Contract: `orbit tool:reload <tool> [--instance=<project.instance>] [--node=<node>] [--json|--stream-json]`

[Back to public `tool-reload` documentation.](../tool-reload.md)

**Owner:** `tool`.

**Effects:** `write, stream`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway, or the command is running on the gateway.
- The current node identity is authorized to manage tools for the resolved node or instance.

## Signature

```bash
orbit tool:reload <tool> [--instance=<project.instance>] [--node=<node>] [--json|--stream-json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `tool` | `argument` | Always. | Never. | None. | Registered tool that declares `reload`. |
| `node` | `--node` | When no `--instance` or local `node:default` is available. | Never. | `node:default` when set. | Visible active node slug; selected tool must satisfy its operating-system, runtime-user, TLD/route, isolation, and gateway-local constraints. |
| `instance` | `--instance` | Optional. | Never. | None. | Visible instance selector used to resolve the owning node. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode. |
| `stream-json` | `--stream-json` | Optional. | Never. | `false`. | Selects newline-delimited progress frames and non-interactive input mode. Mutually exclusive with `--json`. |

## Behavior Contract

### Declared Reload

- Requires the selected tool definition to declare `reload`.
- Resolves exactly one direct tool-owned runtime.
- Dispatches remote direct reload through Agent push.
- A missing or ambiguous runtime fails explicitly before execution.
- Reload is not inferred from any other verb and does not fall back to a
  similarly named process.
- Process-backed reload is unsupported by the current runtime action contract.

### Scope Boundaries

`tool-reload` must not create projects, instances, workspaces, processes, schedules, proxy
routes, firewall rules, node identities, or node grants. It does not rewrite
tool configuration; use `tool:reconfigure` for that contract.

## Renderer Contracts

- [Human renderer](6.1_tool-reload_output-render_human.md)
- [JSON renderer](6.2_tool-reload_output-render_json.md)

`--stream-json` uses the shared
[Stream JSON Frames](../../../README.md#stream-json-frames) contract.

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| Tool not found | The selected tool row or definition cannot be resolved. | `error.code=tool.not_found` |
| Unsupported tool action | The selected tool does not declare reload, or only a process-backed runtime resolves. | `error.code=tool.unsupported_action` |
| Unsupported target | The selected tool does not support the resolved node constraints. | `error.code=tool.unsupported_on_node` |
| Runtime missing | No direct runtime exists. | `error.code=tool.runtime_missing` |
| Runtime ambiguous | More than one direct/process runtime target resolves. | `error.code=tool.runtime_ambiguous` |
| Agent unreachable | The direct remote runtime cannot be reached through Agent push. | `error.code=node.agent_unreachable`, `error.meta.reason=agent_push_unavailable` |
| Remote action failed | The reload command ran but did not succeed. | `error.code=tool.remote_action_failed` |

## Doctor Relationship

`tool-reload` performs an explicit operator-requested runtime action.
[`tool-doctor.md`](../../tool-doctor.md) owns the authoritative tool-family
probe, issue codes, fix map, and adopt map.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Tool/ToolWriteCommandTest.php` | CLI target resolution, JSON envelope, stream request, and gateway error pass-through. |
| `apps/gateway/tests/Feature/Http/Api/ToolLifecycleControllerTest.php` | Capability-gated reload API and runtime failure paths. |
| `apps/gateway/tests/Unit/Services/Tools/ToolCatalogTest.php` | Caddy reload script resolution and rejection when a catalog entry does not declare reload. |
