# Technical Contract: `orbit tool:reconfigure [tool] [--instance=<app.instance>] [--node=<node>] [--password=<password>] [--json|--stream-json]`

[Back to public `tool-reconfigure` documentation.](../tool-reconfigure.md)

**Owner:** `tool`.

**Effects:** `write, stream`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway, or the command is running on the gateway.
- The current node identity is authorized to manage tools for the resolved node or instance.

## Signature

```bash
orbit tool:reconfigure [tool] [--instance=<app.instance>] [--node=<node>] [--password=<password>] [--json|--stream-json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `tool` | `argument` | `Required in non-interactive input mode.` | `Never.` | `interactive selection from reconfigurable tools` | `Registered reconfigurable tool name.` |
| `node` | `--node` | `Optional.` | `Never.` | `node:default if set; otherwise --self (the calling peer).` | Visible active non-gateway node slug; selected tool must support the node operating system. |
| `instance` | `--instance` | `Optional.` | `Never.` | `None.` | `Visible instance selector used to resolve the owning node.` |
| `password` | `--password` | `Optional.` | `when the tool definition does not support password reconfiguration.` | `None.` | `Tool-definition-specific password value.` |
| `json` | `--json` | `Optional.` | `Never.` | `false` | `Selects the JSON renderer.` |
| `stream-json` | `--stream-json` | `Optional.` | `Never.` | `false` | Selects the stream JSON renderer and non-interactive input mode. Mutually exclusive with `--json`. |

## Input Mode Contracts

- [Interactive input mode](5.1_tool-reconfigure_input-mode_interactive.md)
- [Non-interactive input mode](5.2_tool-reconfigure_input-mode_non-interactive.md)

## Behavior Contract

### Tool configuration and apply rules

- Resolves a reconfigurable registered tool.
- Keeps gateway-owned configuration changes gateway-local and dispatches
  target-node setup/configuration through Agent push. The command exposes no
  node transport selector and never falls back to SSH.
- Runs setup/configuration through the gateway.
- Updates generated secrets or backend config only when the tool definition owns those values.
- Updates service endpoint configuration owned by the tool only when the tool definition
  owns that endpoint.
- After a successful reconfigure script, when the tool catalog provides a
  `credentialsScript`, runs that script through the tool script dispatcher with
  action `credentials`, requires stdout to decode as a non-empty JSON object
  (not a JSON array or scalar), and replaces stored `NodeTool` credential
  `fields` with the parsed object. Tools without a credentials script skip this
  step unchanged.
- Credential refresh failure (Agent transport failure, unsuccessful script, or
  malformed/non-object JSON) fails the reconfigure command and does not report
  `action=reconfigured`. Stored credentials stay as they were before the failed
  refresh; related-process restart does not run when credentials refresh fails.
- Reconfigure success output and logs must not include credential field values.
  Operators read credentials through `tool:credentials`.
- When a related process row exists for the tool, reconciles that managed process
  intent to the catalog `relatedProcess()` command and runtime when they differ,
  then restarts the process after successful reconfigure and (when applicable)
  successful credential refresh. Success payloads may include
  `process.command_reconciled=true` when the stored command was updated to the
  current catalog intent before restart. Credential values are never included.
- Preserves the expected version.
- Supplying `--password` for a tool that does not own password reconfiguration
  fails before config, credential, endpoint, or node artifacts are mutated.
- If remote configuration application fails after configuration changes, Orbit keeps the
  requested configuration and reports the tool as not yet converged; doctor owns later
  repair when the tool definition supports safe configuration repair.

### Scope Boundaries

`tool-reconfigure` must not create apps, instances, workspaces, processes, schedules,
custom proxy routes, non-tool firewall rules, node identities, or node grants.
Tool-owned endpoint updates are allowed only when declared by the selected tool
definition. Related drift belongs to each owning family doctor contract.

## Renderer Contracts

- [Human renderer](6.1_tool-reconfigure_output-render_human.md)
- [JSON renderer](6.2_tool-reconfigure_output-render_json.md)

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

`tool-reconfigure` changes gateway configuration only where the tool definition owns generated config, then performs command-owned apply. [`tool-doctor.md`](../../tool-doctor.md) owns the authoritative tool-family probe, issue codes, fix map, and adopt map.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Tool/ToolWriteCommandTest.php` | CLI `tool:reconfigure` stream request forwarding for password payloads, and gateway error envelope pass-through. |
| `apps/cli/tests/Feature/Commands/Tool/ToolStreamCommandTest.php` | CLI stream adapter behavior for reconfigure: final complete frame in `--json` mode, canonical stream request shape, human progress rendering, and pre-stream gateway error pass-through. |
| `apps/gateway/tests/Unit/Services/Tools/ToolCommandContractTest.php` | Shared in-memory tool command DTO shape, target resolution rules, and tool-family entity mapping. |
| `apps/gateway/tests/Unit/Services/Tools/ToolRemoteShellTransportTest.php` | Gateway `ToolReconfigurer` reconfigure dispatch, post-reconfigure credentialsScript refresh and stored-field replacement, no-script tools, credentials failure/malformed JSON honesty, related-process command/runtime reconciliation to catalog intent, and restart ordering after successful credential refresh. |
