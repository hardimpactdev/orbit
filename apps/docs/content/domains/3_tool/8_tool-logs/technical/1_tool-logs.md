# Technical Contract: `orbit tool:logs <tool> [--app=<app>] [--node=<node>] [--lines=<count>] [--follow] [--json]`

[Back to public `tool-logs` documentation.](../tool-logs.md)

**Owner:** `tool`.

**Effects:** `read, stream`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway, or the command is running on the gateway.
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
| `node` | `--node` | `Optional.` | `Never.` | `node:default` if set; otherwise gateway-known self for non-gateway callers. | `Visible app-role slug.` |
| `app` | `--app` | `Optional.` | `Never.` | `None.` | `Visible app selector used to resolve the owning node.` |
| `lines` | `--lines` | `Optional.` | `Never.` | `100` | `Positive integer line count.` |
| `follow` | `--follow` | `Optional.` | `with --json until a JSON stream contract exists.` | `false` | `Continue streaming log lines.` |
| `json` | `--json` | `Optional.` | `with --follow until a JSON stream contract exists.` | `false` | `Selects JSON for finite reads.` |

## Input Resolution

Target resolution follows this hierarchy:

1. Explicit `--app` selector. The selector may be an app slug, an app domain,
   or an app slug plus node TLD such as `docs.dev1`. If `--node` is also
   supplied, the app's owning node must match it.
2. Explicit `--node` selector.
3. Local `node:default`, when configured.
4. Gateway-known caller identity for non-gateway callers.

`tool:logs` must not fall back to the only visible tool node. Non-interactive
input without an explicit selector, local `node:default`, or gateway-known self
fails with `validation_failed` and `error.meta.fields=["target"]`.

## Behavior Contract

### Tool configuration and compatibility log rules

- Verifies the tool has a related log source.
- Resolves the related managed process or transitional backend for that tool
  capability.
- Reads finite logs or follows the related log stream through the gateway.
- Does not mutate gateway tool configuration.

### Scope Boundaries

`tool-logs` must not create apps, workspaces, processes, schedules, proxy routes, firewall rules, node identities, or node grants. Related drift belongs to each owning family doctor contract.

## Renderer Contracts

- [Human renderer](6.1_tool-logs_output-render_human.md)
- [JSON renderer](6.2_tool-logs_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Tool not found | The selected tool row or tool definition cannot be resolved. | `error.code=tool.not_found` |
| Unsupported tool action | The selected tool definition does not support this command's action. | `error.code=tool.unsupported_action` |
| Remote action failed | Gateway configuration was readable, but node inspection or apply failed. | `error.code=tool.remote_action_failed` |
| Missing target source | No `--node`, `--app`, local `node:default`, or gateway-known self resolved a target. | `error.code=validation_failed`; `error.meta.fields=["target"]` |
| Target mismatch | `--app` resolves to a different node than `--node`. | `error.code=validation_failed`; `error.meta.reason=target_mismatch` |

## Doctor Relationship

`tool-logs` is a compatibility log adapter. It reads gateway tool configuration
to resolve the related process or legacy backend and streams logs through the
gateway without changing tool configuration. [`tool-doctor.md`](../../tool-doctor.md)
owns the authoritative tool-family probe, issue codes, fix map, and adopt map.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Commands/Tools/ToolLogsCommandTest.php` | Command contract for input validation, gateway authorization, target resolution, side-effect boundaries, failure codes, and doctor handoff behavior. |
| `apps/gateway/tests/Feature/Commands/Tools/ToolLogsJsonRendererTest.php` | JSON renderer shape, finite metadata, `--follow --json` rejection, missing target metadata, and gateway error preservation. |
| `apps/gateway/tests/Feature/Commands/Tools/ToolLogsTargetResolutionTest.php` | Target hierarchy, app selector forms, selector mismatch metadata, node default, gateway-known self, and no only-visible-node fallback. |
| `apps/gateway/tests/Feature/Http/Api/ToolLogsControllerTest.php` | Gateway API target resolver rejects missing explicit target instead of using visible-node cardinality. |
