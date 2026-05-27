# Technical Contract: `orbit update:all`

[Back to public `update:all` documentation.](../update-all.md)

**Owner:** `operation`.

**Effects:** `write`, `stream`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The gateway authorizes the calling WireGuard peer with gateway-admin authority
  (`*` on the active gateway node).
- The gateway can reach each selected non-local installation through its node execution path.

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

## Input Resolution

1. Select the output renderer.
2. Call the gateway to authorize gateway-admin authority and resolve selected
   non-local managed Orbit installations from active gateway node configuration.
3. Start the fleet update sequence through the selected output renderer's
   execution contract.
4. After the gateway-local checkout succeeds, selected remote app-role
   installations are updated with bounded parallelism, up to four targets at a
   time.

The command has no required fields and does not prompt. Renderer-specific
execution details live in the renderer contracts.

## Behavior Contract

### Fleet Selection Rules

- Include the caller's local Orbit checkout.
- Include active non-local managed Orbit installations from gateway node
  configuration when the gateway has both an Orbit installation path and enough
  `RemoteShell` transport metadata to reach the node.
- **Exclude every node whose role is `control`, regardless of caller.** Control
  nodes are operator workstations that update locally through
  [`orbit update`](../../1_update/update.md) on each workstation.
- Exclude inactive, removed, unknown, or caller-local node records from the
  gateway-selected installation list. The local checkout is updated once through
  the local target.
- Apply gateway-owned authorization before updating any installation.

The expected target shape per calling context:

| Calling context | Local target | Gateway target | App-role targets | Other client targets |
| --- | --- | --- | --- | --- |
| Non-gateway caller with gateway-admin authority | The caller-local checkout. | Yes, when the gateway is an active node distinct from the caller. | Yes, every active node selected by the rules above. | Never. |
| Gateway caller | The gateway checkout (via the local target). | N/A — the gateway is the local target. | Yes, every active node selected by the rules above. | Never. |

### Per-Installation Update Rules

- Update each selected installation with the same local checkout update sequence
  documented by [`update`](../../1_update/technical/1_update.md).
- Remote update execution is gateway-owned node execution through `RemoteShell`.
  Clients do not SSH directly to the gateway, nodes, or other control
  nodes as part of the command contract. The gateway does not SSH to control
  nodes as part of the command contract.
- Update the caller-local checkout and gateway-local checkout as independent
  selected targets when both are selected.
- After the gateway-local update succeeds, selected remote app-role
  installations are updated in parallel, up to four targets at a time. Each
  individual target still runs `Pulling source`, `Installing dependencies`, and
  `Running migrations` in that order.
- Continue updating remaining installations after a target fails.
- Preserve every target result for the selected output renderer in selected
  target order, regardless of the order in which parallel workers finish.

### Partial Failure Rules

- If every selected installation updates successfully, report a full fleet
  success. If one or more installations fail after side effects begin, report
  both successful and failed target results.
- When the caller-local checkout fails, do not start app-role execution.
- When update of the gateway checkout fails, do not start app-role execution.
  When a node with an app role fails, do not hide successful app-role updates and do not
  cancel unrelated in-flight app-role updates.

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
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Local update failed | The caller's local checkout update fails. | Failure |
| Gateway update failed | The selected gateway checkout fails to update. | Failure with any completed target results; app-role targets are not started |
| App-role update failed | One or more selected app-role installations fail to update. | Failure with partial target results |

The shared [Exit Status](../../../README.md#exit-status) policy applies. Partial
fleet failures are Orbit-handled command failures.

## Doctor Relationship

- `update:all` changes Orbit installations.
- It does not verify state-family drift or runtime readiness.
- Run `doctor --family=<family>` after updates to verify convergence for a specific family.
- A remote update failure may leave a node on a different Orbit version.
- Such a version mismatch creates node-family drift.
- Node doctor owns any later reachability or readiness diagnosis once its contract is converted in this repo.

## Activity Logging

The local CLI command emits an activity entry for successful and failed fleet
update attempts. Activity logging is best-effort and must not change the
documented command result.

| Field | Value |
| --- | --- |
| Type | `update:all` |
| Effect | `write` |
| Subject | `none`; the command updates selected Orbit installations but does not own a durable operation-family entity. |
| Properties | `scope=fleet`, `status` (`completed` or `failed`), `summary` counts, selected `targets` with target/node/role metadata, and `failed_step` for local or remote update failures. No process output, SSH output, Git output, `orbit-runtime` Composer output, migration output, environment values, or secrets. |
| Description | derived |

## Test Mapping

Primary existing test owners:

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Commands/UpdateAllCommandTest.php` | Bootstrap coverage for local plus registered-node update execution. Expand to cover: gateway-admin denial, gateway authorization, JSON output, partial failure payloads, and gateway-owned remote execution boundaries. |

Required split contract tests:

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Commands/Operations/UpdateAllCommandTest.php` | Fleet update contract: gateway-authorized peer eligibility, selection rules, local-first behavior, per-target continuation after remote failure, and no app deployment or drift repair side effects. |
| `apps/gateway/tests/Feature/Commands/Operations/UpdateAllJsonRendererTest.php` | JSON renderer selection, success envelope, partial failure error envelope, target result metadata, and every `error.code` value. |
| `apps/gateway/tests/Feature/Commands/Operations/UpdateAllHumanRendererTest.php` | Progress tree shape, per-target success output, partial failure output, and local failure output. |
