# Technical Contract: `orbit tool:install <tool> [--app=<app>] [--node=<node>] [--status=<installed|running>] [--json]`

[Back to public `tool-install` documentation.](../tool-install.md)

**Owner:** `tool`.

**Effects:** `write, stream`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway, or the command is running on the gateway.
- The local caller role can be resolved according to the foundation `general.local_node_role` contract.
- The current node identity is authorized to manage tools for the resolved node or app.

## Signature

```bash
orbit tool:install <tool> [--app=<app>] [--node=<node>] [--status=<installed|running>] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `tool` | `argument` | `Always.` | `Never.` | `None.` | `Supported tool name.` |
| `node` | `--node` | `Required when no app or local default node resolves the target.` | `Never.` | `local node:default when configured` | `Visible node slug.` |
| `app` | `--app` | `Optional.` | `Never.` | `None.` | `Visible app selector used to resolve the owning app node.` |
| `status` | `--status` | `Optional.` | `Never.` | `installed` | `Expected lifecycle state: installed or running.` |
| `json` | `--json` | `Optional.` | `Never.` | `false` | `Selects the JSON renderer.` |

## Caller Role Behavior

All authenticated caller roles use the same gateway-owned access policy. App-node callers may manage tools only when their node identity has explicit tool-management authorization for the resolved target; management remains gateway-owned and enacted through gateway-to-node transport.

## Input Mode Contracts

No input-mode-specific contracts are required. The command does not prompt; missing required arguments or target context fail according to the shared invocation model.

## Behavior Contract

### Tool Intent And Enactment Rules

- Verifies the tool supports managed installation on the target node.
- Writes or updates gateway tool intent.
- Generates managed credential material when the tool definition owns
  credentials.
- Creates or updates tool-owned service endpoint intent when the tool
  definition declares an endpoint.
- Enacts install and configuration through the gateway.
- Starts the tool when expected status is running.
- If remote installation, configuration, or start fails after gateway intent is
  written, Orbit keeps the intended tool row and reports the node as not yet
  converged; `doctor --fix --family=tool --restore` owns later convergence when the tool
  definition declares a safe repair path.

### Scope Boundaries

`tool-install` must not create apps, workspaces, processes, schedules, custom
proxy routes, non-tool firewall rules, node identities, or node grants.
Tool-owned endpoint intent is allowed only when declared by the selected tool
definition. Related drift belongs to each owning family doctor contract.

## Renderer Contracts

- [Human renderer](6.1_tool-install_output-render_human.md)
- [JSON renderer](6.2_tool-install_output-render_json.md)

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

`tool-install` changes gateway tool intent and performs command-owned enactment only. [`tool-doctor.md`](../../tool-doctor.md) owns the authoritative tool-family probe, issue codes, fix map, and adopt map.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Tools/ToolInstallCommandTest.php` | Command contract for input validation, gateway authorization, target resolution, side-effect boundaries, failure codes, and doctor handoff behavior. |
| `tests/Unit/Services/Tools/ToolCommandContractTest.php` | Shared in-memory tool command DTO shape, target resolution rules, and tool-family entity mapping. |
