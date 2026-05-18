# Technical Contract: `orbit tool:start <tool> [--app=<app>] [--node=<node>] [--json]`

[Back to public `tool-start` documentation.](../tool-start.md)

**Owner:** `tool`.

**Effects:** `write`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway, or the command is running on the gateway.
- The current node identity is authorized to manage tools for the resolved node or app.

## Signature

```bash
orbit tool:start <tool> [--app=<app>] [--node=<node>] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `tool` | `argument` | `Always.` | `Never.` | `None.` | `Registered managed tool name.` |
| `node` | `--node` | `Optional.` | `Never.` | `node:default` if set; otherwise self from gateway-known caller identity. | `Visible node slug.` |
| `app` | `--app` | `Optional.` | `Never.` | `None.` | Visible app slug, domain, or `<slug>.<node-tld>` selector used to resolve the owning app node. |
| `json` | `--json` | `Optional.` | `Never.` | `false` | `Selects the JSON renderer.` |

Target resolution is strict:

1. If `--app` is present, resolve the app selector to its owning node.
2. If `--app` and `--node` are both present, the resolved app node must match
   the supplied node or the command fails with `validation_failed`.
3. If `--app` is absent and `--node` is present, use `--node`.
4. If neither explicit target exists, use local `node:default` when configured.
5. If no explicit target or local default exists, resolve self from `/api/me`
   using the gateway-known caller identity.
6. If none of the above yields a target, fail with `validation_failed` and
   `error.meta.fields=["target"]`.

`--json` and non-TTY execution use non-interactive input mode: they do not
prompt for missing `tool` or target values.

## Behavior Contract

### Tool configuration and apply rules

- Verifies the registered tool is managed and startable.
- Updates expected lifecycle state to running.
- Starts the tool through its lifecycle backend.
- If start application fails after the expected lifecycle state changes, Orbit keeps the
  new expected running state and reports the tool as not yet converged; doctor
  owns later repair when the tool definition supports safe lifecycle repair.

### Scope Boundaries

`tool-start` must not create apps, workspaces, processes, schedules, proxy routes, firewall rules, node identities, or node grants. Related drift belongs to each owning family doctor contract.

## Renderer Contracts

- [Interactive input mode](5.1_tool-start_input-mode_interactive.md)
- [Non-interactive input mode](5.2_tool-start_input-mode_non-interactive.md)
- [Human renderer](6.1_tool-start_output-render_human.md)
- [JSON renderer](6.2_tool-start_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Tool not found | The selected tool row or tool definition cannot be resolved. | `error.code=tool.not_found` |
| Unsupported tool action | The selected tool definition does not support this command's action. | `error.code=tool.unsupported_action` |
| Remote action failed | Gateway configuration was readable, but node inspection or apply failed. | `error.code=tool.remote_action_failed`; `error.meta.exit_code`; `error.meta.stderr` |
| Missing target | No `--app`, `--node`, local `node:default`, self identity, or interactive selection resolved a target. | `error.code=validation_failed`; `error.meta.fields=["target"]` |
| App/node mismatch | `--app` resolves to a different node than `--node`. | `error.code=validation_failed`; `error.meta.field="app"`; `error.meta.reason="target_mismatch"` |

## Doctor Relationship

`tool-start` changes the gateway expected lifecycle state and performs command-owned apply only. [`tool-doctor.md`](../../tool-doctor.md) owns the authoritative tool-family probe, issue codes, fix map, and adopt map.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Tools/ToolStartCommandTest.php` | Command contract for input validation, gateway authorization, target resolution, side-effect boundaries, failure codes, and doctor handoff behavior. |
| `tests/Feature/Commands/Tools/ToolStartJsonRendererTest.php` | JSON envelope shape, canonical tool entity, validation failures, gateway error preservation, remote-action metadata, and non-interactive `--json` behavior. |
| `tests/Unit/Services/Tools/ToolCommandContractTest.php` | Shared clean-repo tool behavior: payload mapping, registry-only observed-state rules, registry target/filter behavior, and shared failure shape. |
