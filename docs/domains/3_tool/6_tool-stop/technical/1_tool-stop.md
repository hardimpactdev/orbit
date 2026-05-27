# Technical Contract: `orbit tool:stop <tool> [--app=<app>] [--node=<node>] [--json]`

[Back to public `tool-stop` documentation.](../tool-stop.md)

**Owner:** `tool`.

**Effects:** `write`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway, or the command is running on the gateway.
- The current node identity is authorized to manage tools for the resolved node or app.

## Signature

```bash
orbit tool:stop <tool> [--app=<app>] [--node=<node>] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `tool` | `argument` | `Always.` | `Never.` | `None.` | `Registered managed tool name.` |
| `node` | `--node` | `Optional.` | `Never.` | `node:default` if set; otherwise gateway-known caller identity. | `Visible node slug.` |
| `app` | `--app` | `Optional.` | `Never.` | `None.` | Visible app selector: app slug, app domain, or `<slug>.<node-tld>`. |
| `json` | `--json` | `Optional.` | `Never.` | `false` | `Selects the JSON renderer.` |

Target resolution happens before side effects in this order:

1. `--app` resolves the owning node. If `--node` is also present, it must
   resolve to the same node.
2. `--node` resolves the target directly.
3. Local `node:default` is used when no explicit target is supplied.
4. Gateway-known caller identity is used as self fallback when the caller has no
   explicit/default target.

The command must not select a target by cardinality, such as "the only visible
node."

## Behavior Contract

### Tool configuration and apply rules

- Verifies the registered tool is managed and stoppable.
- Updates expected lifecycle state to installed.
- Stops the tool through its lifecycle backend.
- If stop application fails after the expected lifecycle state changes, Orbit keeps the
  new expected installed state and reports the tool as not yet converged; doctor
  owns later repair when the tool definition supports safe lifecycle repair.

### Scope Boundaries

`tool-stop` must not create apps, workspaces, processes, schedules, proxy routes, firewall rules, node identities, or node grants. Related drift belongs to each owning family doctor contract.

## Renderer Contracts

- [Interactive input mode](5.1_tool-stop_input-mode_interactive.md)
- [Non-interactive input mode](5.2_tool-stop_input-mode_non-interactive.md)
- [Human renderer](6.1_tool-stop_output-render_human.md)
- [JSON renderer](6.2_tool-stop_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Tool not found | The selected tool row or tool definition cannot be resolved. | `error.code=tool.not_found` |
| Unsupported tool action | The selected tool definition does not support this command's action. | `error.code=tool.unsupported_action` |
| App/node target mismatch | `--app` resolves to a different node than `--node`. | `error.code=validation_failed`, `error.meta.reason=target_mismatch` |
| Remote action failed | Gateway configuration was readable, but node inspection or apply failed. | `error.code=tool.remote_action_failed`, with `error.meta.exit_code` and `error.meta.stderr` when available. |

## Doctor Relationship

`tool-stop` changes the gateway expected lifecycle state and performs command-owned apply only. [`tool-doctor.md`](../../tool-doctor.md) owns the authoritative tool-family probe, issue codes, fix map, and adopt map.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Commands/Tools/ToolStopCommandTest.php` | Command contract for input validation, gateway authorization, target resolution, side-effect boundaries, failure codes, and doctor handoff behavior. |
| `apps/gateway/tests/Feature/Commands/Tools/ToolStopJsonRendererTest.php` | JSON envelope shape, canonical tool entity, renderer metadata, non-interactive target validation, and gateway error preservation. |
| `apps/gateway/tests/Unit/Services/Tools/ToolCommandContractTest.php` | Shared tool-family behavior such as canonical entity mapping, registry-only observed-state rules, registry target/filter behavior, and shared failure shape. |
