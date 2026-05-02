# Technical Contract: `orbit tool:logs <tool> [--app=<app>] [--node=<node>] [--lines=<count>] [--follow] [--json]`

[Back to public `tool-logs` documentation.](../tool-logs.md)

**Owner:** `tool`.

**Effects:** `read, stream`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway, or the command is running on the gateway.
- The local caller role can be resolved according to the foundation `general.local_node_role` contract.
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
| `node` | `--node` | `Required when no app or local default node resolves the target.` | `Never.` | `local node:default when configured` | `Visible node slug.` |
| `app` | `--app` | `Optional.` | `Never.` | `None.` | `Visible app selector used to resolve the owning app node.` |
| `lines` | `--lines` | `Optional.` | `Never.` | `100` | `Positive integer line count.` |
| `follow` | `--follow` | `Optional.` | `with --json until a JSON stream contract exists.` | `false` | `Continue streaming log lines.` |
| `json` | `--json` | `Optional.` | `with --follow until a JSON stream contract exists.` | `false` | `Selects JSON for finite reads.` |

## Caller Role Behavior

All authenticated caller roles use the same gateway-owned access policy. App-node callers may read visible tool logs when authorized; `tool:logs` never grants write permission or local state authority.

## Input Mode Contracts

No input-mode-specific contracts are required. The command does not prompt; missing required arguments or target context fail according to the shared invocation model.

## Behavior Contract

### Tool Intent And Enactment Rules

- Verifies the tool declares a log source.
- Reads finite logs or follows the log stream through the gateway.
- Does not mutate gateway tool intent.

### Scope Boundaries

`tool-logs` must not create apps, workspaces, processes, schedules, proxy routes, firewall rules, node identities, or node grants. Related drift belongs to each owning family doctor contract.

## Renderer Contracts

- [Human renderer](6.1_tool-logs_output-render_human.md)
- [JSON renderer](6.2_tool-logs_output-render_json.md)

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| Validation failed | Required input is missing, invalid, or forbidden with another option. | `error.code=validation_failed` |
| Gateway unavailable | The CLI cannot reach the gateway API. | `error.code=gateway_unavailable` |
| Authorization failed | The caller is not authorized to inspect logs for the selected tool target. | `error.code=authorization_failed` |
| Tool not found | The selected tool row or tool definition cannot be resolved. | `error.code=tool.not_found` |
| Unsupported tool action | The selected tool definition does not support this command's action. | `error.code=tool.unsupported_action` |
| Remote action failed | Gateway intent was readable, but node inspection or enactment failed. | `error.code=tool.remote_action_failed` |

## Doctor Relationship

`tool-logs` reads gateway tool intent and streams logs through the gateway without changing tool intent. [`tool-doctor.md`](../../tool-doctor.md) owns the authoritative tool-family probe, issue codes, fix map, and adopt map.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Tools/ToolLogsCommandTest.php` | Command contract for input validation, gateway authorization, target resolution, side-effect boundaries, failure codes, and doctor handoff behavior. |
| `tests/Unit/Services/Tools/ToolCommandContractTest.php` | Shared in-memory tool command DTO shape, target resolution rules, and tool-family entity mapping. |
