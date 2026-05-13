# Technical Contract: `orbit tool:update [tool] [--app=<app>] [--node=<node>] [--expected-version=<version>] [--json]`

[Back to public `tool-update` documentation.](../tool-update.md)

**Owner:** `tool`.

**Effects:** `write, stream`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway, or the command is running on the gateway.
- The current node identity is authorized to manage tools for the resolved node or app.

## Signature

```bash
orbit tool:update [tool] [--app=<app>] [--node=<node>] [--expected-version=<version>] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `tool` | `argument` | `Optional.` | `Never.` | `all update-capable managed tools on the node` | `Registered managed tool name.` |
| `expected_version` | `--expected-version` | `Optional.` | `when tool is omitted.` | `latest supported version` | `Supported version string.` |
| `node` | `--node` | `Optional.` | `Never.` | `node:default if set; otherwise --self (the calling peer).` | `Visible node slug.` |
| `app` | `--app` | `Optional.` | `Never.` | `None.` | `Visible app selector used to resolve the owning app node.` |
| `json` | `--json` | `Optional.` | `Never.` | `false` | `Selects the JSON renderer.` |

## Authorization By Caller Role

All authenticated caller roles use the same gateway-owned access policy. App-node callers may manage tools only when their node identity has explicit tool-management authorization for the resolved target; management remains gateway-owned and applied through gateway-to-node transport.

## Input Mode Contracts

No input-mode-specific contracts are required. The command does not prompt; missing required arguments or target context fail according to the shared invocation model.

## Behavior Contract

### Tool Configuration And Apply Rules

- Selects one tool or all update-capable managed tools on the target node.
- Verifies update support and requested version compatibility.
- Writes the gateway expected version and applies the update through the gateway.
- Reports updated, skipped, and failed tools.
- Bulk mode skips managed tools without update support and reports the skip
  reason as part of the command result. Explicitly selecting an unsupported tool
  is a command failure.
- If remote update application fails after the expected version is written, Orbit keeps
  the requested expected version and reports the tool as not yet converged; doctor
  owns later repair when the tool definition supports safe version repair.

### Scope Boundaries

`tool-update` must not create apps, workspaces, processes, schedules, proxy routes, firewall rules, node identities, or node grants. Related drift belongs to each owning family doctor contract.

## Renderer Contracts

- [Human renderer](6.1_tool-update_output-render_human.md)
- [JSON renderer](6.2_tool-update_output-render_json.md)

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| Validation failed | Required input is missing, invalid, or forbidden with another option. | `error.code=validation_failed` |
| Gateway unavailable | The CLI cannot reach the gateway API. | `error.code=gateway_unavailable` |
| Authorization failed | The caller is not authorized to manage the selected tool target. | `error.code=authorization_failed` |
| Tool not found | The selected tool row or tool definition cannot be resolved. | `error.code=tool.not_found` |
| Unsupported tool action | The selected tool definition does not support this command's action. | `error.code=tool.unsupported_action` |
| Remote action failed | Gateway configuration was readable, but node inspection or apply failed. | `error.code=tool.remote_action_failed` |

## Doctor Relationship

`tool-update` changes the gateway expected version and performs command-owned apply only. [`tool-doctor.md`](../../tool-doctor.md) owns the authoritative tool-family probe, issue codes, fix map, and adopt map.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Tools/ToolUpdateCommandTest.php` | Command contract for input validation, gateway authorization, target resolution, side-effect boundaries, failure codes, and doctor handoff behavior. |
| `tests/Unit/Services/Tools/ToolCommandContractTest.php` | Shared in-memory tool command DTO shape, target resolution rules, and tool-family entity mapping. |
