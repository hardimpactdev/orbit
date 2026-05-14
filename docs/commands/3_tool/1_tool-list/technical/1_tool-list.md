# Technical Contract: `orbit tool:list [--app=<app>] [--node=<node>] [--json]`

[Back to public `tool-list` documentation.](../tool-list.md)

**Owner:** `tool`.

**Effects:** `read`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway, or the command is running on the gateway.
- The current node identity is authorized to inspect tool state for the resolved node or app.

## Signature

```bash
orbit tool:list [--app=<app>] [--node=<node>] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `node` | `--node` | `Optional.` | `Never.` | `None.` | `Visible node slug.` |
| `app` | `--app` | `Optional.` | `Never.` | `None.` | `Visible app selector used to resolve the owning app node.` |
| `json` | `--json` | `Optional.` | `Never.` | `false` | `Selects the JSON renderer.` |

## Behavior Contract

### Tool configuration and apply rules

- Reads gateway tool configuration visible to the caller.
- Applies node and app filters at the gateway.
- Does not inspect nodes or mutate configuration.

### Scope Boundaries

`tool-list` must not create apps, workspaces, processes, schedules, proxy routes, firewall rules, node identities, or node grants. Related drift belongs to each owning family doctor contract.

## Renderer Contracts

- [Human renderer](6.1_tool-list_output-render_human.md)
- [JSON renderer](6.2_tool-list_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

## Doctor Relationship

`tool-list` reads gateway tool configuration only. [`tool-doctor.md`](../../tool-doctor.md) owns the authoritative tool-family probe, issue codes, fix map, and adopt map.

## Activity Logging

The gateway API endpoint emits an activity entry for successful and failed
tool registry reads.

| Field | Value |
| --- | --- |
| Type | `api:GET /tools` |
| Effect | `read` |
| Subject | `none` |
| Properties | No command-specific properties. The API activity middleware adds transport context such as method, path, client, and serving gateway node. |
| Description | derived |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Tools/ToolListCommandTest.php` | Command contract for input validation, gateway authorization, target resolution, side-effect boundaries, failure codes, and doctor handoff behavior. |
| `tests/Unit/Services/Tools/ToolCommandContractTest.php` | Shared in-memory tool command DTO shape, target resolution rules, and tool-family entity mapping. |
