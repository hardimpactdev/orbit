# Technical Contract: `orbit tool:restart <tool> [--app=<app>] [--node=<node>] [--json]`

[Back to public `tool-restart` documentation.](../tool-restart.md)

**Owner:** `tool`.

**Effects:** `write`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway, or the command is running on the gateway.
- The local caller role can be resolved according to the foundation `general.local_node_role` contract.
- The current node identity is authorized to manage tools for the resolved node or app.

## Signature

```bash
orbit tool:restart <tool> [--app=<app>] [--node=<node>] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `tool` | `argument` | `Always.` | `Never.` | `None.` | `Registered managed tool name.` |
| `node` | `--node` | `Required when no app or local default node resolves the target.` | `Never.` | `local node:default when configured` | `Visible node slug.` |
| `app` | `--app` | `Optional.` | `Never.` | `None.` | `Visible app selector used to resolve the owning app node.` |
| `json` | `--json` | `Optional.` | `Never.` | `false` | `Selects the JSON renderer.` |

## Caller Role Behavior

All authenticated caller roles use the same gateway-owned access policy. App-node callers may manage tools only when their node identity has explicit tool-management authorization for the resolved target; management remains gateway-owned and enacted through gateway-to-node transport.

## Input Mode Contracts

No input-mode-specific contracts are required. The command does not prompt; missing required arguments or target context fail according to the shared invocation model.

## Behavior Contract

### Tool Intent And Enactment Rules

- Verifies the registered tool is managed and restartable.
- Runs the lifecycle restart action through the gateway.
- Preserves existing gateway version and configuration intent.

### Scope Boundaries

`tool-restart` must not create apps, workspaces, processes, schedules, proxy routes, firewall rules, node identities, or node grants. Related drift belongs to each owning family doctor contract.

## Renderer Contracts

- [Human renderer](6.1_tool-restart_output-render_human.md)
- [JSON renderer](6.2_tool-restart_output-render_json.md)

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| Validation failed | Required input is missing, invalid, or forbidden with another option. | `error.code=validation_failed` |
| Gateway unavailable | The CLI cannot reach the gateway API. | `error.code=gateway_unavailable` |
| Authorization failed | The caller is not authorized to manage the selected tool target. | `error.code=authorization_failed` |
| Tool not found | The selected tool row or tool definition cannot be resolved. | `error.code=tool.not_found` |
| Unsupported tool action | The selected tool definition does not support this command's action. | `error.code=tool.unsupported_action` |
| Remote action failed | Gateway intent was readable, but node inspection or enactment failed. | `error.code=tool.remote_action_failed` |

## Doctor Relationship

`tool-restart` performs command-owned lifecycle enactment while preserving gateway version and configuration intent. [`tool-doctor.md`](../../tool-doctor.md) owns the authoritative tool-family probe, issue codes, fix map, and adopt map.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Tools/ToolRestartCommandTest.php` | Command contract for input validation, gateway authorization, target resolution, side-effect boundaries, failure codes, and doctor handoff behavior. |
| `tests/Unit/Services/Tools/ToolCommandContractTest.php` | Shared in-memory tool command DTO shape, target resolution rules, and tool-family entity mapping. |
