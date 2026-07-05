# Technical Contract: `orbit tool:show <tool> [--app=<app>] [--node=<node>] [--node-transport=<transport>] [--live] [--json]`

[Back to public `tool-show` documentation.](../tool-show.md)

**Owner:** `tool`.

**Effects:** `read`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway, or the command is running on the gateway.
- The current node identity is authorized to inspect tool state for the resolved node or app.

## Signature

```bash
orbit tool:show <tool> [--app=<app>] [--node=<node>] [--node-transport=<transport>] [--live] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `tool` | `argument` | `Always.` | `Never.` | `None.` | `Tool name from Orbit's tool catalog.` |
| `node` | `--node` | `Required when no `--app`, local `node:default`, or interactive target selection resolves a target.` | `Never.` | `node:default if set.` | Visible active non-gateway node slug; selected tool must support the node operating system. |
| `node_transport` | `--node-transport` | Optional. | Never. | `auto`. | One of `auto`, `agent-push`, or `transitional-ssh-fallback`. |
| `app` | `--app` | `Optional.` | `Never.` | `None.` | `Visible app selector used to resolve the owning node.` |
| `live` | `--live` | `Optional.` | `Never.` | `false` | `Request gateway live inspection.` |
| `json` | `--json` | `Optional.` | `Never.` | `false` | `Selects the JSON renderer.` |

## Behavior Contract

### Tool configuration and apply rules

- Resolves the target node from `--node`, `--app`, local `node:default`, or a
  documented interactive selection. Non-interactive mode (`--json` or a
  non-TTY stdout) fails with `validation_failed` when none of those sources is
  available.
- Reads the selected tool row from gateway configuration.
- Requests live state only when `--live` is present. `--live` is the single
  source of opt-in live state for this command; without it, no remote
  shell/process inspection runs, JSON keeps `observed_state: null`, and human
  output omits the observed/live section.
- Performs live inspection through the gateway-owned tool-show live inspector.
  Gateway-local CLI reads and forwarded gateway API reads both use this shared
  boundary so `--live` and `live=1` cannot diverge.
- Does not mutate gateway configuration or node artifacts.

### Scope Boundaries

`tool-show` must not create apps, workspaces, processes, schedules, proxy routes, firewall rules, node identities, or node grants. Related drift belongs to each owning family doctor contract.

## Renderer Contracts

- [Human renderer](6.1_tool-show_output-render_human.md)
- [JSON renderer](6.2_tool-show_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Tool not found | The selected tool row or tool definition cannot be resolved. | `error.code=tool.not_found` |
| Unsupported tool action | The selected tool definition does not support this command's action. | `error.code=tool.unsupported_action` |
| Remote action failed | Gateway configuration was readable, but node inspection or apply failed. | `error.code=tool.remote_action_failed` |

## Doctor Relationship

`tool-show` reads gateway tool configuration and may request live node inspection only when `--live` is present. [`tool-doctor.md`](../../tool-doctor.md) owns the authoritative tool-family probe, issue codes, fix map, and adopt map.

## Activity Logging

The gateway API endpoint emits an activity entry for successful and failed
tool registry reads.

| Field | Value |
| --- | --- |
| Type | `api:GET /tools/{tool}` |
| Effect | `read` |
| Subject | `NodeTool` when the selected tool row is visible and resolved; `none` for not-found or hidden tool responses. |
| Properties | No command-specific properties. The API activity middleware adds transport context such as method, path, client, and serving gateway node. |
| Description | derived |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Tool/ToolShowCommandTest.php` | CLI target resolution, `--live` query forwarding, human detail rendering, JSON envelope passthrough, local validation, and gateway/WireGuard failure passthrough. |
| `apps/gateway/tests/Feature/Http/Api/ToolShowControllerTest.php` | Gateway tool registry reads by tool/node, app selector resolution, not-found and unsupported-tool failures, hidden selector rejection, and authorization failures. |
| `apps/gateway/tests/Unit/Services/Tools/ToolCommandContractTest.php` | Shared in-memory tool command DTO shape, target resolution rules, and tool-family entity mapping. |
