# Technical Contract: `orbit tool:reconfigure [tool] [--app=<app>] [--node=<node>] [--password=<password>] [--json]`

[Back to public `tool-reconfigure` documentation.](../tool-reconfigure.md)

**Owner:** `tool`.

**Effects:** `write, stream`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway, or the command is running on the gateway.
- The current node identity is authorized to manage tools for the resolved node or app.

## Signature

```bash
orbit tool:reconfigure [tool] [--app=<app>] [--node=<node>] [--password=<password>] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `tool` | `argument` | `Required in non-interactive input mode.` | `Never.` | `interactive selection from reconfigurable tools` | `Registered reconfigurable tool name.` |
| `node` | `--node` | `Optional.` | `Never.` | `node:default if set; otherwise --self (the calling peer).` | `Visible node slug.` |
| `app` | `--app` | `Optional.` | `Never.` | `None.` | `Visible app selector used to resolve the owning node.` |
| `password` | `--password` | `Optional.` | `when the tool definition does not support password reconfiguration.` | `None.` | `Tool-definition-specific password value.` |
| `json` | `--json` | `Optional.` | `Never.` | `false` | `Selects the JSON renderer.` |

## Input Mode Contracts

- [Interactive input mode](5.1_tool-reconfigure_input-mode_interactive.md)
- [Non-interactive input mode](5.2_tool-reconfigure_input-mode_non-interactive.md)

## Behavior Contract

### Tool configuration and apply rules

- Resolves a reconfigurable registered tool.
- Runs setup/configuration through the gateway.
- Updates generated secrets or backend config only when the tool definition owns those values.
- Updates service endpoint configuration owned by the tool only when the tool definition
  owns that endpoint.
- Preserves the expected version.
- Supplying `--password` for a tool that does not own password reconfiguration
  fails before config, credential, endpoint, or node artifacts are mutated.
- If remote configuration application fails after configuration changes, Orbit keeps the
  requested configuration and reports the tool as not yet converged; doctor owns later
  repair when the tool definition supports safe configuration repair.

### Scope Boundaries

`tool-reconfigure` must not create apps, workspaces, processes, schedules,
custom proxy routes, non-tool firewall rules, node identities, or node grants.
Tool-owned endpoint updates are allowed only when declared by the selected tool
definition. Related drift belongs to each owning family doctor contract.

## Renderer Contracts

- [Human renderer](6.1_tool-reconfigure_output-render_human.md)
- [JSON renderer](6.2_tool-reconfigure_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Tool not found | The selected tool row or tool definition cannot be resolved. | `error.code=tool.not_found` |
| Unsupported tool action | The selected tool definition does not support this command's action. | `error.code=tool.unsupported_action` |
| Remote action failed | Gateway configuration was readable, but node inspection or apply failed. | `error.code=tool.remote_action_failed` |

## Doctor Relationship

`tool-reconfigure` changes gateway configuration only where the tool definition owns generated config, then performs command-owned apply. [`tool-doctor.md`](../../tool-doctor.md) owns the authoritative tool-family probe, issue codes, fix map, and adopt map.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Commands/Tools/ToolReconfigureCommandTest.php` | Command contract for input validation, gateway authorization, target resolution, side-effect boundaries, failure codes, and doctor handoff behavior. |
| `apps/gateway/tests/Unit/Services/Tools/ToolCommandContractTest.php` | Shared in-memory tool command DTO shape, target resolution rules, and tool-family entity mapping. |
