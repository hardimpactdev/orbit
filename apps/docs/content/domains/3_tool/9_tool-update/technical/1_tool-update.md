# Technical Contract: `orbit tool:update [tool] [--instance=<app.instance>] [--node=<node>] [--expected-version=<version>] [--json|--stream-json]`

[Back to public `tool-update` documentation.](../tool-update.md)

**Owner:** `tool`.

**Effects:** `write, stream`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway, or the command is running on the gateway.
- The current node identity is authorized to manage tools for the resolved node or instance.

## Signature

```bash
orbit tool:update [tool] [--instance=<app.instance>] [--node=<node>] [--expected-version=<version>] [--json|--stream-json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `tool` | `argument` | `Optional.` | `Never.` | `all update-capable managed tools on the node` | `Registered managed tool name.` |
| `expected_version` | `--expected-version` | `Optional.` | `when tool is omitted.` | `latest supported version` | Supported version string for the selected tool definition. |
| `node` | `--node` | `Optional.` | `Never.` | `node:default if set; otherwise --self (the calling peer).` | Visible active non-gateway node slug; selected tool must support the node operating system. |
| `instance` | `--instance` | `Optional.` | `Never.` | `None.` | `Visible instance selector used to resolve the owning node.` |
| `json` | `--json` | `Optional.` | `Never.` | `false` | `Selects the JSON renderer.` |
| `stream-json` | `--stream-json` | `Optional.` | `Never.` | `false` | Selects the stream JSON renderer and non-interactive input mode. Mutually exclusive with `--json`. |

## Behavior Contract

### Tool configuration and apply rules

- Selects one tool or all update-capable managed tools on the target node.
- Verifies update support before writing.
- Keeps expected-version writes gateway-local and dispatches target-node update
  actions through Agent push. The command exposes no node transport selector
  and never falls back to SSH.
- Writes the gateway expected version and applies the update through the gateway.
- Reports updated, skipped, and failed tools.
- Bulk mode skips managed tools without update support and reports the skip
  reason as part of the command result. Explicitly selecting an unsupported tool
  is a command failure.
- If remote update application fails after the expected version is written, Orbit keeps
  the requested expected version and reports the tool as not yet converged; doctor
  owns later repair when the tool definition supports safe version repair.

### Scope Boundaries

`tool-update` must not create apps, instances, workspaces, processes, schedules, proxy routes, firewall rules, node identities, or node grants. Related drift belongs to each owning family doctor contract.

## Renderer Contracts

- [Human renderer](6.1_tool-update_output-render_human.md)
- [JSON renderer](6.2_tool-update_output-render_json.md)

`--stream-json` uses the shared
[Stream JSON Frames](../../../README.md#stream-json-frames) contract.

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Tool not found | The selected tool row or tool definition cannot be resolved. | `error.code=tool.not_found` |
| Unsupported tool action | The selected tool definition does not support this command's action. | `error.code=tool.unsupported_action` |
| Remote action failed | Gateway configuration was readable, but node inspection or apply failed. | `error.code=tool.remote_action_failed` |

## Doctor Relationship

`tool-update` changes the gateway expected version and performs command-owned apply only. [`tool-doctor.md`](../../tool-doctor.md) owns the authoritative tool-family probe, issue codes, fix map, and adopt map.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Tool/ToolWriteCommandTest.php` | CLI `tool:update` stream request forwarding for single-tool and bulk payloads, and gateway error envelope pass-through. |
| `apps/cli/tests/Feature/Commands/Tool/ToolStreamCommandTest.php` | CLI stream adapter behavior for update: final complete frame in `--json` mode, canonical stream request shape, human progress rendering, and pre-stream gateway error pass-through. |
| `apps/cli/tests/Feature/Commands/Tool/ToolUpdateStreamTransportTest.php` | CLI stream requests omit node transport preference headers because `tool:update` has no public transport selector. |
| `apps/gateway/tests/Feature/Http/Api/ToolUpdateControllerTest.php` | Gateway update API expected-version writes, unsupported service-tool rejection, and service-style instance selector rejection. |
| `apps/gateway/tests/Unit/Services/Tools/ToolCommandContractTest.php` | Shared in-memory tool command DTO shape, target resolution rules, and tool-family entity mapping. |
