# Technical Contract: `orbit update:all`

[Back to public `update:all` documentation.](../update-all.md)

**Owner:** `operation`.

**Effects:** `write`, `stream`.

**Prerequisites:**
- The local caller role can be resolved according to the foundation
  [local node role setting](../../../../BLUEPRINT.md#local-node-role-setting)
  contract and the node-family
  [Local Caller Role](../../../1_node/README.md#local-caller-role)
  contract.
- `update:all` is invoked from a control or gateway caller. App-node callers are
  rejected before prompts, forwarding, or side effects.
- The caller can reach the Orbit gateway unless the caller is the gateway.
- The current node identity is authorized to update Orbit installations.
- The gateway can reach each selected non-local installation through its
  gateway-owned node execution path.

## Signature

```bash
orbit update:all [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

No required inputs exist; the command takes no positional arguments and all
options are optional.

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode. |

## Caller Role Behavior

`update:all` resolves the caller role before starting side effects. See the
node-family [Local Caller Role](../../../1_node/README.md#local-caller-role)
contract.

| Caller role | Behavior |
| --- | --- |
| `control` | Reads node intent from the Gateway API. Updates the local control checkout. The gateway is then asked to authorize the fleet update, update the gateway checkout, and dispatch updates to selected app nodes through gateway-owned `RemoteShell`. The control caller never reads a local node registry and never opens SSH connections to other nodes. |
| `gateway` | Authority path. Authorizes the fleet update locally. Reads node intent from local gateway state. Updates the gateway checkout, then dispatches to selected app nodes through gateway-owned `RemoteShell`. The command does not target control nodes (see [Fleet Selection Rules](#fleet-selection-rules)). |
| `app` | Invalid. App-node CLI availability is not fleet update permission. Fail before side effects with `This command may only be run from a control or gateway node.` |
| `unknown` | Invalid local context. Fail before side effects with a local context error. |

No role-specific companion files are required. The detailed control and gateway
paths share the same fleet update rules after the gateway authority path is
selected, and app-node denial is fully defined here.

### Intent Source

`update:all` resolves "active non-local managed Orbit installations" from
gateway node intent. The intent source depends on the caller role:

- **Control caller:** MUST fetch node intent from the Gateway API over the
  CLI-to-gateway edge. MUST NOT read any local node table on the control node,
  even when one exists for caching or offline display. Stale local copies must
  not influence target selection.
- **Gateway caller:** Reads node intent from local gateway state directly. The
  gateway is the authority for intent, so no API hop is required.

Both paths must arrive at the same selected target list when applied against
identical gateway state.

### Execution Topology

The only legal SSH edges during `update:all` are gateway-to-app-node edges
through `RemoteShell`. Specifically:

- A control caller never opens SSH connections to the gateway, to app nodes, or
  to other control nodes as part of `update:all`. The gateway performs every
  remote update.
- A gateway caller opens SSH connections only to selected app nodes.
- Control nodes are not part of the remote update topology in either path. See
  [Fleet Selection Rules](#fleet-selection-rules).

Implementations that shell out to `ssh` from the control caller, or that
construct a target list by reading a control-local node registry, violate this
contract regardless of whether the resulting fleet ends up converged.

## Input Resolution

1. Resolve caller role before side effects.
   - If the caller is an app node, fail before updating the local checkout.
   - If the caller role is `unknown`, fail before updating the local checkout.
2. Select the output renderer.
3. Authorize the fleet update through gateway-owned access policy.
4. Resolve selected non-local managed Orbit installations from active gateway
   node intent.
5. Start the fleet update sequence.

No input-mode-specific contracts are required. The command has no required
fields and does not prompt.

## Behavior Contract

### Fleet Selection Rules

- Include the caller's local Orbit checkout.
- Include active non-local managed Orbit installations from gateway node intent,
  subject to the role exclusion below.
- **Exclude every node whose role is `control`, regardless of caller.** Control
  nodes are operator workstations. They are updated locally through
  [`orbit update`](../../1_update/update.md) on each workstation and are never
  remote update targets of `update:all`. This applies even when gateway intent
  records reachability metadata for a control node.
- Exclude inactive, removed, or unknown node records.
- Exclude the caller-local installation from the gateway-selected installation
  list. The local checkout is updated once through the local target.
- Exclude nodes whose Orbit installation path is not known to gateway intent.
- Exclude app nodes whose gateway-owned `RemoteShell` transport metadata is not
  known. The gateway must have enough information to reach and update an app
  node before that node is selected.
- Apply gateway-owned authorization before updating any installation.

The expected target shape per caller role:

| Caller role | Local target | Gateway target | App-node targets | Other control-node targets |
| --- | --- | --- | --- | --- |
| `control` | The control checkout. | Yes, when the gateway is an active node distinct from the caller. | Yes, every active app node selected by the rules above. | Never. |
| `gateway` | The gateway checkout (via the local target). | N/A — the gateway is the local target. | Yes, every active app node selected by the rules above. | Never. |

### Per-Installation Update Rules

- Update each selected installation with the same local checkout update sequence
  documented by [`update`](../../1_update/technical/1_update.md).
- Remote update execution is gateway-owned node execution through `RemoteShell`.
  Control nodes do not SSH directly to the gateway, app nodes, or other control
  nodes as part of the command contract. The gateway does not SSH to control
  nodes as part of the command contract.
- Continue updating remaining installations after a target fails.
- Preserve every target result for the selected output renderer.

### Partial Failure Rules

- If every selected installation updates successfully, exit `0`.
- If one or more installations fail after any side effects begin, exit `1` and
  report both successful and failed target results.
- A local checkout failure stops the command before remote update execution.
- A remote target failure must not hide successful updates on earlier or later
  targets.

### Scope Boundaries

`update:all` must not:
- Create or remove node records.
- Mint WireGuard identities or node access grants.
- Deploy apps or run app deployment pipelines.
- Repair node, app, workspace, process, proxy route, schedule, tool, or
  firewall drift.
- Treat a successful update as doctor convergence.

## Renderer Contracts

- [Human renderer](6.1_update-all_output-render_human.md)
- [JSON renderer](6.2_update-all_output-render_json.md)

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| Caller role not allowed | Invoked from an app node. | Failure |
| Local context invalid | The local caller role is unreadable or unsupported. | Failure |
| Gateway unavailable | A control caller cannot reach the gateway. | Failure |
| Authorization failed | The current node identity is not authorized to update Orbit installations. | Failure |
| Local update failed | The caller's local checkout update fails. | Failure |
| Remote update failed | One or more selected remote installations fail to update. | Failure with partial target results |

## Doctor Relationship

- `update:all` changes Orbit installations.
- It does not verify state-family drift or runtime readiness.
- Run `doctor --family=<family>` after updates when the operator needs
  convergence verification for a specific family.
- A remote update failure may create node-family drift if a node is left on a
  different Orbit version; node doctor owns any later reachability or readiness
  diagnosis once its contract is converted in this repo.

## Activity Logging

The local CLI command emits an activity entry for successful and failed fleet
update attempts. Activity logging is best-effort and must not change the
documented command result.

| Field | Value |
| --- | --- |
| Type | `update:all` |
| Effect | `write` |
| Subject | `none`; the command updates selected Orbit installations but does not own a durable operation-family entity. |
| Properties | `scope=fleet`, `status` (`completed` or `failed`), `summary` counts, selected `targets` with target/node/role metadata, and `failed_step` for local or remote update failures. No process output, SSH output, Git output, Composer output, migration output, environment values, or secrets. |
| Description | derived |

## Test Mapping

Primary existing test owners:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/UpdateAllCommandTest.php` | Bootstrap implementation coverage for local plus registered-node update process execution. Must be expanded to cover caller-role denial, gateway authorization, JSON output, partial failure payloads, and gateway-owned remote execution boundaries. |

Required split contract tests:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Operations/UpdateAllCommandTest.php` | Fleet update contract: caller-role eligibility, selection rules, local-first behavior, per-target continuation after remote failure, and no app deployment or drift repair side effects. |
| `tests/Feature/Commands/Operations/UpdateAllJsonRendererTest.php` | JSON renderer selection, success envelope, partial failure error envelope, target result metadata, and every `error.code` value. |
| `tests/Feature/Commands/Operations/UpdateAllHumanRendererTest.php` | Progress tree shape, per-target success output, partial failure output, and local failure output. |
