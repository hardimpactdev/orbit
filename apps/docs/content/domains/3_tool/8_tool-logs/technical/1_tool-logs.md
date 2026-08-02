# Technical Contract: `orbit tool:logs <tool> [--instance=<project.instance>] [--node=<node>] [--lines=<lines>] [--json]`

[Back to public `tool-logs` documentation.](../tool-logs.md)

**Owner:** `tool`.

**Effects:** `read`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway, or the command is running on the gateway.
- The current node identity has `tool:logs` (or the implying `tool:read`)
  granted on the resolved serving node. Gateway identity remains implicit.

## Signature

```bash
orbit tool:logs <tool> [--instance=<project.instance>] [--node=<node>] [--lines=<lines>] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `tool` | `argument` | Always. | Never. | None. | Registered tool that declares `logs`. |
| `node` | `--node` | When no `--instance` or local `node:default` is available. | For gateway-local tools, any node other than the active serving gateway. | `node:default` when set. | Visible active node slug; selected tool must satisfy its operating-system, runtime-user, TLD/route, isolation, and gateway-local constraints. |
| `instance` | `--instance` | Optional. | For gateway-local tools. | None. | Visible instance selector used to resolve the owning node. |
| `lines` | `--lines` | Optional. | Never. | `100`. | Positive integer. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode. |

## Behavior Contract

### Declared Logs

- Requires the selected tool definition to declare `logs`.
- Resolves exactly one direct tool-owned runtime or exactly one process row
  whose canonical `tool` value matches the selected tool.
- A direct remote runtime reads through Agent push via
  `internal:tool:run-script` with action `logs` (an allowed tool-run action). A
  process-backed runtime uses the native process log reader.
- The gateway-local `dns` runtime reads `orbit-dns` directly on the active
  serving gateway. A non-gateway caller requires a grant on that gateway;
  gateway identity remains implicit. No remote target is accepted.
- A missing or ambiguous runtime fails explicitly before reading logs.
- The command returns a bounded retained-log snapshot and does not follow.

### Scope Boundaries

`tool-logs` must not mutate tool or process intent, create runtime rows, repair
drift, or fall back to a similarly named process.

## Renderer Contracts

- [Human renderer](6.1_tool-logs_output-render_human.md)
- [JSON renderer](6.2_tool-logs_output-render_json.md)

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| Tool not found | The selected tool row or definition cannot be resolved. | `error.code=tool.not_found` |
| Unsupported tool action | The selected tool does not declare logs. | `error.code=tool.unsupported_action` |
| Unsupported target | The selected tool does not support the resolved node constraints. | `error.code=tool.unsupported_on_node` |
| Runtime missing | No direct runtime or matching process row exists. | `error.code=tool.runtime_missing` |
| Runtime ambiguous | More than one direct/process runtime target resolves. | `error.code=tool.runtime_ambiguous` |
| Agent unreachable | A direct remote runtime cannot be reached through Agent push. | `error.code=node.agent_unreachable`, `error.meta.reason=agent_push_unavailable` |
| Log read failed | The resolved runtime backend cannot read logs. | `error.code=tool.remote_action_failed` |

## Doctor Relationship

`tool-logs` observes a runtime explicitly and does not repair it.
[`tool-doctor.md`](../../tool-doctor.md) owns tool-family drift, while the
process doctor owns drift in a resolved process row.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Tool/ToolWriteCommandTest.php` | CLI target, lines, human output, and JSON envelope behavior. |
| `apps/gateway/tests/Feature/Http/Api/ToolLifecycleControllerTest.php` | Gateway-local DNS logs, serving-gateway grants, wrong-permission denial, and capability-gated runtime resolution. |
| `apps/gateway/tests/Unit/Services/Tools/ToolLogReaderTest.php` | Failed log reads preserve useful stdout when stderr is empty (including `docker logs ... 2>&1`). |
| `apps/cli/tests/Unit/Services/Tools/LocalToolRunScriptActionTest.php` | `internal:tool:run-script` accepts action `logs` so remote `tool:logs` payloads validate. |
