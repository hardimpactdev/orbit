# Technical Contract: `orbit tool:remove <tool> [--instance=<app.instance>] [--node=<node>] [--force] [--json]`

[Back to public `tool-remove` documentation.](../tool-remove.md)

**Owner:** `tool`.

**Effects:** `write, destructive, stream`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway, or the command is running on the gateway.
- The current node identity is authorized to manage tools for the resolved node
  or selected instance's serving node.

## Signature

```bash
orbit tool:remove <tool> [--instance=<app.instance>] [--node=<node>] [--force] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `tool` | `argument` | `Always.` | `Never.` | `None.` | `Registered tool name.` |
| `node` | `--node` or local `node:default` | Required when `instance` is absent. | `Never.` | `node:default` if set. | Visible active non-gateway node slug; selected tool must support the node operating system. |
| `instance` | `--instance` | `Optional.` | `Never.` | `None.` | `Visible instance selector used to resolve its serving node. Bare project shorthand is valid only when exactly one instance is visible.` |
| `force` | `--force` | Required for every non-interactive removal, including JSON mode. | `Never.` | `false` | Explicit destructive consent. |
| `json` | `--json` | `Optional.` | `Never.` | `false` | Selects the JSON renderer and non-interactive input mode; never grants consent. |

At least one target source is required: explicit `--node`, explicit `--instance`,
local `node:default`, or interactive target selection. `tool:remove` must not
fall back to the only visible non-gateway node in non-interactive mode.

## Input Mode Contracts

- [Interactive input mode](5.1_tool-remove_input-mode_interactive.md)
- [Non-interactive input mode](5.2_tool-remove_input-mode_non-interactive.md)

## Behavior Contract

### Tool configuration and apply rules

- Verifies the tool supports managed removal.
- Keeps gateway row and tool configuration cleanup local to the gateway. It
  dispatches target-node cleanup through Agent push. The command exposes no
  node transport selector and never falls back to SSH.
- Requires destructive consent before side effects. Consent source is `force`
  when `--force` is supplied or `interactive_confirm` when the operator accepts
  the prompt. Renderer selection never grants consent.
- When the tool definition declares `relatedProcess()`, removes that process
  (intent row and runtime unit) before the tool remove script. Lookup matches
  both process `name` and `tool` so a same-name foreign process is not torn
  down. Runtime-unit extras from process removal are surfaced on the tool
  remove payload as `process.warnings` / `warnings` (not dropped).
- Removes managed artifacts through the gateway.
- Removes tool-owned credential material and service endpoint configuration when the
  tool definition owns those artifacts.
- Removes gateway tool configuration after supported cleanup succeeds.
- After the tool row is deleted, removes gateway proxy routes owned by that
  tool on the target node (`config.owner_name` match): for each matching
  domain call `ProxyRouteFixer::removeExtra` (backend site + TLS/global
  cleanup) before deleting the registry row — same ordering as
  `proxy:remove --force`. Failed backend cleanup leaves the row so removal
  can be retried; remaining orphans are still classified as
  `proxy.owner_invalid` for force-remove/doctor paths.
- If cleanup partially fails, Orbit keeps the gateway tool row and any
  tool-owned credential or endpoint configuration needed to retry cleanup. Removal does
  not erase configuration before managed artifacts are either removed or explicitly
  reported as requiring manual recovery.

### Scope Boundaries

`tool-remove` must not create apps, instances, workspaces, processes, schedules, custom
proxy routes, non-tool firewall rules, node identities, or node grants.
It may remove a process the tool definition already declared via
`relatedProcess()`; that is tool-owned lifecycle cleanup, not ad-hoc process
creation. Tool-owned endpoint cleanup is allowed only when declared by the
selected tool definition. Related drift belongs to each owning family doctor contract.

### OpenClaw Removal-Only Migration

The slug `openclaw` is not catalog-supported. `tool:remove openclaw` is routed
to `LegacyOpenClawRuntimeCleanup` instead of the normal catalog remove path:

1. Resolve an active tool-host node (agent nodes qualify).
2. Remove process intent named `openclaw-gateway` or `tool=openclaw` when present
   (typed `RemoveProcess`, including runtime unit teardown).
3. Run the fixed host cleanup script via `internal:tool:run-script` action
   `remove`: stop/disable Orbit and native OpenClaw units, enumerate and kill
   listeners with **`sudo ss`** on port `18789` (privileged PID visibility),
   pkill residual agent openclaw processes, remove agent OpenClaw home state,
   then **verify** port/process/home are absent (nonzero stderr on residue).
4. Only after verified host success: remove tool-owned proxy domains with
   `owner_name=openclaw` via `ProxyRouteFixer::removeExtra` then registry
   delete, and delete any remaining `NodeTool` row named `openclaw`.

JSON success includes `legacy_runtime_cleanup=true`, `stale_record=true`,
`routes_removed`, and `tool_row_removed`. Failed host cleanup returns
`tool.remote_action_failed` and must be retried; proxy/tool rows are not
removed after a failed script. This is not install or product support.

## Renderer Contracts

- [Human renderer](6.1_tool-remove_output-render_human.md)
- [JSON renderer](6.2_tool-remove_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Tool not found | The selected tool row or tool definition cannot be resolved. | `error.code=tool.not_found` |
| Unsupported tool action | The selected tool definition does not support this command's action. | `error.code=tool.unsupported_action` |
| Remote action failed | Gateway configuration was readable, but node inspection or apply failed. | `error.code=tool.remote_action_failed` |
| Missing target | No `--node`, selected instance, local `node:default`, or interactive target selection resolved a node. | `error.code=validation_failed`, `error.meta.fields=["target"]` |
| Missing destructive consent | Non-interactive input omitted `--force`, including JSON mode. | `error.code=validation_failed`, `error.meta.field=force`, `error.meta.reason=destructive_consent_required` |

## Doctor Relationship

`tool-remove` changes gateway tool configuration and performs command-owned cleanup only. [`tool-doctor.md`](../../tool-doctor.md) owns the authoritative tool-family probe, issue codes, fix map, and adopt map.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Tool/ToolWriteCommandTest.php` | CLI `tool:remove` destructive consent (`--force` or interactive prompt), JSON non-interactive behavior, DELETE forwarding, and gateway error envelope pass-through. |
| `apps/gateway/tests/Feature/Http/Api/ToolRemoveControllerTest.php` | Gateway API consent-source handling, explicit target requirement, authorization failure, and streaming removal consent. |
| `apps/gateway/tests/Unit/Services/Tools/ToolCommandContractTest.php` | Shared in-memory tool command DTO shape, target resolution rules, and tool-family entity mapping. |
