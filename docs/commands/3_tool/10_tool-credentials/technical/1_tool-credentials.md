# Technical Contract: `orbit tool:credentials [tool] [--node=<node>] [--app=<app>] [--json]`

[Back to public `tool-credentials` documentation.](../tool-credentials.md)

**Owner:** `tool`.

**Effects:** `read`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway, or the command is running on the gateway.
- The local caller role can be resolved according to the foundation `general.local_node_role` contract.
- The current node identity is authorized to inspect tool credentials for the resolved node or app.

## Signature

```bash
orbit tool:credentials [tool] [--node=<node>] [--app=<app>] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `tool` | `argument` | `Required in non-interactive input mode.` | `Never.` | `interactive selection from credential-bearing tools` | `Registered credential-bearing tool name.` |
| `node` | `--node` | `Required when no app or local default node resolves the target.` | `Never.` | `local node:default when configured` | `Visible node slug.` |
| `app` | `--app` | `Optional.` | `Never.` | `None.` | `Visible app selector used to resolve the owning app node.` |
| `json` | `--json` | `Optional.` | `Never.` | `false` | `Selects the JSON renderer.` |

## Caller Role Behavior

All authenticated caller roles use the same gateway-owned access policy. App-node callers may read tool credentials only when credential access is authorized for the resolved target; `tool:credentials` never grants write permission or local state authority.

## Input Mode Contracts

- [Interactive input mode](5.1_tool-credentials_input-mode_interactive.md)
- [Non-interactive input mode](5.2_tool-credentials_input-mode_non-interactive.md)

## Behavior Contract

### Tool Intent And Enactment Rules

- Resolves a credential-bearing tool on the target node.
- Reads credential metadata from gateway intent or the managed secret store.
- Does not rotate credentials or reconfigure the tool.

### Scope Boundaries

`tool-credentials` must not create apps, workspaces, processes, schedules, proxy routes, firewall rules, node identities, or node grants. Related drift belongs to each owning family doctor contract.

## Renderer Contracts

- [Human renderer](6.1_tool-credentials_output-render_human.md)
- [JSON renderer](6.2_tool-credentials_output-render_json.md)

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| Validation failed | Required input is missing, invalid, or forbidden with another option. | `error.code=validation_failed` |
| Gateway unavailable | The CLI cannot reach the gateway API. | `error.code=gateway_unavailable` |
| Authorization failed | The caller is not authorized to inspect credentials for the selected tool target. | `error.code=authorization_failed` |
| Tool not found | The selected tool row or tool definition cannot be resolved. | `error.code=tool.not_found` |
| Unsupported tool action | The selected tool definition does not support this command's action. | `error.code=tool.unsupported_action` |
| Remote action failed | Gateway intent was readable, but node inspection or enactment failed. | `error.code=tool.remote_action_failed` |

## Doctor Relationship

`tool-credentials` reads gateway credential metadata or managed secret material without changing tool intent. [`tool-doctor.md`](../../tool-doctor.md) owns the authoritative tool-family probe, issue codes, fix map, and adopt map.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Tools/ToolCredentialsCommandTest.php` | Command contract for input validation, gateway authorization, target resolution, side-effect boundaries, failure codes, and doctor handoff behavior. |
| `tests/Unit/Services/Tools/ToolCommandContractTest.php` | Shared in-memory tool command DTO shape, target resolution rules, and tool-family entity mapping. |
