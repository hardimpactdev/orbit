# Technical Contract: `orbit tool:logs <tool> [--app=<app>] [--node=<node>] [--lines=<count>] [--follow] [--json]`

[Back to public `tool-logs` documentation.](../tool-logs.md)

**Owner:** `tool`.

**Effects:** `read, stream`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway, or the command is running on the gateway.
- The current node identity is authorized to inspect tool logs for the resolved node or app.

## Signature

```bash
orbit tool:logs <tool> [--app=<app>] [--node=<node>] [--lines=<count>] [--follow] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `tool` | `argument` | `Always.` | `Never.` | `None.` | `Registered tool name.` |
| `node` | `--node` | `Optional.` | `Never.` | `node:default if set; otherwise --self (the calling peer).` | `Visible node slug.` |
| `app` | `--app` | `Optional.` | `Never.` | `None.` | `Visible app selector used to resolve the owning app node.` |
| `lines` | `--lines` | `Optional.` | `Never.` | `100` | `Positive integer line count.` |
| `follow` | `--follow` | `Optional.` | `with --json until a JSON stream contract exists.` | `false` | `Continue streaming log lines.` |
| `json` | `--json` | `Optional.` | `with --follow until a JSON stream contract exists.` | `false` | `Selects JSON for finite reads.` |

## Behavior Contract

### Tool configuration and apply rules

- Verifies the tool declares a log source.
- Reads finite logs or follows the log stream through the gateway.
- Does not mutate gateway tool configuration.

### Scope Boundaries

`tool-logs` must not create apps, workspaces, processes, schedules, proxy routes, firewall rules, node identities, or node grants. Related drift belongs to each owning family doctor contract.

## Renderer Contracts

- [Human renderer](6.1_tool-logs_output-render_human.md)
- [JSON renderer](6.2_tool-logs_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Tool not found | The selected tool row or tool definition cannot be resolved. | `error.code=tool.not_found` |
| Unsupported tool action | The selected tool definition does not support this command's action. | `error.code=tool.unsupported_action` |
| Remote action failed | Gateway configuration was readable, but node inspection or apply failed. | `error.code=tool.remote_action_failed` |

## Doctor Relationship

`tool-logs` reads gateway tool configuration and streams logs through the gateway without changing tool configuration. [`tool-doctor.md`](../../tool-doctor.md) owns the authoritative tool-family probe, issue codes, fix map, and adopt map.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Tools/ToolLogsCommandTest.php` | Command contract for input validation, gateway authorization, target resolution, side-effect boundaries, failure codes, and doctor handoff behavior. |
| `tests/Unit/Services/Tools/ToolCommandContractTest.php` | Shared in-memory tool command DTO shape, target resolution rules, and tool-family entity mapping. |
