# Technical Contract: `orbit tool:restart <tool> [--app=<app>] [--node=<node>] [--json]`

[Back to public `tool-restart` documentation.](../tool-restart.md)

**Owner:** `tool`.

**Effects:** `write`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway, or the command is running on the gateway.
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
| `node` | `--node` | `Optional.` | `Never.` | `node:default` if set; otherwise gateway-known self for non-gateway callers. | `Visible node slug.` |
| `app` | `--app` | `Optional.` | `Never.` | `None.` | App slug, app domain, or `<slug>.<node-tld>` selector used to resolve the owning node. |
| `json` | `--json` | `Optional.` | `Never.` | `false` | `Selects the JSON renderer.` |

## Input Resolution

This command resolves input in this order, before lifecycle execution begins:

1. Resolve `tool` from the argument. When it is missing and the command is in
   interactive input mode, prompt with a searchable tool catalog. In
   non-interactive mode, missing `tool` fails with `validation_failed`.
2. Resolve explicit target selectors. `--app` may be an app slug, app domain,
   or `<slug>.<node-tld>` selector. When both `--app` and `--node` are supplied,
   `--app` must resolve to the same node named by `--node`; mismatch fails with
   `validation_failed` before side effects.
3. When no explicit target selector is supplied, use local `node:default` when
   configured.
4. When no explicit selector or local default exists and the caller is not the
   gateway, resolve self through gateway-known caller identity (`/api/me`, via
   `ShowGatewayIdentityRequest` or equivalent). Identity lookup failure fails
   closed before lifecycle execution.
5. In interactive input mode on the gateway, prompt for a target node when no
   explicit selector or local default exists. In non-interactive gateway mode,
   missing target fails with `validation_failed` and `meta.fields=["target"]`.

Validation completes before restart, stop, or start commands run. The command
must not use a cardinality fallback such as selecting the only visible tool node.

## Input Mode Contracts

- [Interactive input mode](5.1_tool-restart_input-mode_interactive.md)
- [Non-interactive input mode](5.2_tool-restart_input-mode_non-interactive.md)

## Behavior Contract

### Tool configuration and apply rules

- Verifies the registered tool is managed and restartable.
- Runs the lifecycle restart action through the gateway.
- Uses a native restart action when the tool definition declares one.
- When the tool definition has no native restart action but supports both stop
  and start, Orbit restarts through a stop-then-start fallback.
- When the tool has neither a native restart action nor both stop and start
  actions, the command fails with `tool.unsupported_action`.
- Preserves existing gateway expected version and configuration.
- If restart or fallback application partially fails, Orbit leaves gateway
  expected state, expected version, and stored configuration unchanged and
  reports the lifecycle action failure for doctor/manual follow-up. Node runtime
  reality may be left not converged.

### Scope Boundaries

`tool-restart` must not create apps, workspaces, processes, schedules, proxy routes, firewall rules, node identities, or node grants. Related drift belongs to each owning family doctor contract.

## Renderer Contracts

- [Human renderer](6.1_tool-restart_output-render_human.md)
- [JSON renderer](6.2_tool-restart_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Tool not found | The selected tool row or tool definition cannot be resolved. | `error.code=tool.not_found` |
| Unsupported tool action | The selected tool definition does not support this command's action. | `error.code=tool.unsupported_action` |
| Missing target | No `--node`, `--app`, local `node:default`, gateway-known self, or interactive target selection resolved a target. | `error.code=validation_failed`, `error.meta.fields=["target"]` |
| Remote action failed | Gateway configuration was readable, but node inspection or apply failed. | `error.code=tool.remote_action_failed`; `error.meta.exit_code`; `error.meta.stderr` |

## Doctor Relationship

`tool-restart` performs command-owned lifecycle application while preserving gateway expected version and configuration. [`tool-doctor.md`](../../tool-doctor.md) owns the authoritative tool-family probe, issue codes, fix map, and adopt map.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Tools/ToolRestartCommandTest.php` | Command contract for input validation, gateway authorization, target resolution, side-effect boundaries, failure codes, and doctor handoff behavior. |
| `tests/Feature/Commands/Tools/ToolRestartTargetResolutionTest.php` | Target hierarchy for `--app` selector forms, strict `--app` plus `--node` matching, `node:default`, gateway-known self fallback, and no visible-node cardinality fallback. |
| `tests/Feature/Commands/Tools/ToolRestartJsonRendererTest.php` | JSON envelope shape, success metadata, validation failures, gateway error preservation, remote-action diagnostics, and non-interactive `--json` target behavior. |
