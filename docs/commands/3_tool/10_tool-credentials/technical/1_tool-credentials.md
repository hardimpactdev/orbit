# Technical Contract: `orbit tool:credentials [tool] [--app=<app>] [--node=<node>] [--json]`

[Back to public `tool-credentials` documentation.](../tool-credentials.md)

**Owner:** `tool`.

**Effects:** `read`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway, or the command is running on the gateway.
- The current node identity is authorized to inspect tool credentials for the resolved node or app.

## Signature

```bash
orbit tool:credentials [tool] [--app=<app>] [--node=<node>] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `tool` | `argument` | `Required in non-interactive input mode.` | `Never.` | `interactive selection from credential-bearing tools` | `Registered credential-bearing tool name.` |
| `node` | `--node` | `Optional.` | `Never.` | `node:default if set; otherwise --self (the calling peer).` | `Visible node slug.` |
| `app` | `--app` | `Optional.` | `Never.` | `None.` | `Visible app selector used to resolve the owning app node.` |
| `json` | `--json` | `Optional.` | `Never.` | `false` | `Selects the JSON renderer.` |

## Input Mode Contracts

- [Interactive input mode](5.1_tool-credentials_input-mode_interactive.md)
- [Non-interactive input mode](5.2_tool-credentials_input-mode_non-interactive.md)

## Behavior Contract

### Tool Configuration And Apply Rules

- Resolves a credential-bearing tool on the target node.
- Reads credential metadata from gateway configuration or the managed secret store.
- Returns generated Orbit-owned credentials for managed service tools, using
  the tool catalog's field names and endpoint shape.
- Does not rotate credentials or reconfigure the tool.

### Scope Boundaries

`tool-credentials` must not create apps, workspaces, processes, schedules,
proxy routes, firewall rules, node identities, node grants, service endpoints,
or credentials. Related drift belongs to each owning family doctor contract.

## Renderer Contracts

- [Human renderer](6.1_tool-credentials_output-render_human.md)
- [JSON renderer](6.2_tool-credentials_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Tool not found | The selected tool row or tool definition cannot be resolved. | `error.code=tool.not_found` |
| Unsupported tool action | The selected tool definition does not support this command's action. | `error.code=tool.unsupported_action` |
| Remote action failed | Gateway configuration was readable, but node inspection or apply failed. | `error.code=tool.remote_action_failed` |

## Doctor Relationship

`tool-credentials` reads gateway credential metadata or managed secret material without changing tool configuration. [`tool-doctor.md`](../../tool-doctor.md) owns the authoritative tool-family probe, issue codes, fix map, and adopt map.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Tools/ToolCredentialsCommandTest.php` | Command contract for input validation, gateway authorization, target resolution, side-effect boundaries, failure codes, and doctor handoff behavior. |
| `tests/Unit/Services/Tools/ToolCommandContractTest.php` | Shared in-memory tool command DTO shape, target resolution rules, and tool-family entity mapping. |
