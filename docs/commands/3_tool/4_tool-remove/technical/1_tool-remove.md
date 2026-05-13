# Technical Contract: `orbit tool:remove <tool> [--app=<app>] [--node=<node>] [--force] [--json]`

[Back to public `tool-remove` documentation.](../tool-remove.md)

**Owner:** `tool`.

**Effects:** `write, destructive, stream`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway, or the command is running on the gateway.
- The current node identity is authorized to manage tools for the resolved node or app.

## Signature

```bash
orbit tool:remove <tool> [--app=<app>] [--node=<node>] [--force] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `tool` | `argument` | `Always.` | `Never.` | `None.` | `Registered tool name.` |
| `node` | `--node` | `Optional.` | `Never.` | `node:default if set; otherwise --self (the calling peer).` | `Visible node slug.` |
| `app` | `--app` | `Optional.` | `Never.` | `None.` | `Visible app selector used to resolve the owning app node.` |
| `force` | `--force` | `Required in non-interactive input mode.` | `Never.` | `false` | `Explicit destructive consent.` |
| `json` | `--json` | `Optional.` | `Never.` | `false` | `Selects the JSON renderer.` |

## Input Mode Contracts

- [Interactive input mode](5.1_tool-remove_input-mode_interactive.md)
- [Non-interactive input mode](5.2_tool-remove_input-mode_non-interactive.md)

## Behavior Contract

### Tool Configuration And Apply Rules

- Verifies the tool supports managed removal.
- Requires destructive consent before side effects.
- Removes managed artifacts through the gateway.
- Removes tool-owned credential material and service endpoint configuration when the
  tool definition owns those artifacts.
- Removes gateway tool configuration after supported cleanup succeeds.
- If cleanup partially fails, Orbit keeps the gateway tool row and any
  tool-owned credential or endpoint configuration needed to retry cleanup. Removal does
  not erase configuration before managed artifacts are either removed or explicitly
  reported as requiring manual recovery.

### Scope Boundaries

`tool-remove` must not create apps, workspaces, processes, schedules, custom
proxy routes, non-tool firewall rules, node identities, or node grants.
Tool-owned endpoint cleanup is allowed only when declared by the selected tool
definition. Related drift belongs to each owning family doctor contract.

## Renderer Contracts

- [Human renderer](6.1_tool-remove_output-render_human.md)
- [JSON renderer](6.2_tool-remove_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Tool not found | The selected tool row or tool definition cannot be resolved. | `error.code=tool.not_found` |
| Unsupported tool action | The selected tool definition does not support this command's action. | `error.code=tool.unsupported_action` |
| Remote action failed | Gateway configuration was readable, but node inspection or apply failed. | `error.code=tool.remote_action_failed` |

## Doctor Relationship

`tool-remove` changes gateway tool configuration and performs command-owned cleanup only. [`tool-doctor.md`](../../tool-doctor.md) owns the authoritative tool-family probe, issue codes, fix map, and adopt map.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Tools/ToolRemoveCommandTest.php` | Command contract for input validation, gateway authorization, target resolution, side-effect boundaries, failure codes, destructive consent when applicable, and doctor handoff behavior. |
| `tests/Unit/Services/Tools/ToolCommandContractTest.php` | Shared in-memory tool command DTO shape, target resolution rules, and tool-family entity mapping. |
