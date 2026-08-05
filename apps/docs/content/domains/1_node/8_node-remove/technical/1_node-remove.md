# Technical Contract: `orbit node:remove [name]`

[Back to public `node:remove` documentation.](../node-remove.md)

**Owner:** `node`.

**Effects:** `destructive`, `write`.

**Prerequisites:**
- The gateway authenticates the CLI and authorizes `node:remove` with the
  `node:remove` permission on the target node. Gateway callers are implicitly
  authorized. A grant using the `gateway-admin` preset also authorizes the
  write.
- Non-gateway callers have configured gateway access as defined in
  [`2_node-remove_on-client.md`](2_node-remove_on-client.md) and a covering
  node access grant for this operation.

**Post-input path eligibility:**
- Destructive consent is resolved by interactive confirmation or `--force`.
- The target node name resolves to an existing active node record.
- The resolved node record's role is not `gateway`. No gateway node is
  removable by `node:remove`, regardless of gateway count.
- The target node may be the current caller's own node record.

## Signature

```bash
orbit node:remove [name] [--force] [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `name` | `[name]` | Always. | Never. | None. | Must match an existing active node record. |
| `force` | `--force` | Non-interactive input mode, or when an interactive caller wants to skip the confirmation prompt. | Never. | `false`. | Boolean flag. Explicit destructive consent. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode according to the shared invocation model in [`docs/domains/README.md`](../../../README.md#invocation-model). |

## Input Resolution

1. Resolve `node_remove.name` from `[name]` or the selected input mode.
   - In interactive mode, prompt when `[name]` is missing.
   - In non-interactive mode, fail before side effects when `[name]` is absent.
2. Validate `node_remove.name` immediately.
   - Must match an existing active node record.
   - Must not be any gateway node.
   - When the target node record matches the authenticated caller's identity,
     set `node_remove.removed_self=true`.
3. Resolve `node_remove.force` from `--force`. Default `false`.
4. Apply destructive consent.
   - If `--force` is present, destructive consent is resolved and no
     confirmation prompt is rendered.
   - In interactive mode without `--force`, render a confirmation prompt after
     the target node is valid. If the operator cancels, fail before side
     effects.
   - In non-interactive mode without `--force`, fail before side effects.
5. Send the typed request to the gateway over HTTPS through WireGuard. The
   gateway authenticates the caller's WireGuard identity and authorizes the
   request through gateway-owned access policy before any side effects.

## Input Mode Contracts

- [Interactive input mode](5.1_node-remove_input-mode_interactive.md)
- [Non-interactive input mode](5.2_node-remove_input-mode_non-interactive.md)

## Behavior Contract

### Removal Target Rules

- Find the node record by name. If not found, fail before side effects.
- Already-absent removal is not an idempotent success.
  - Rationale: the node record is the primary fleet identity. A missing node
    record can indicate a typo, wrong gateway/network, or concurrent removal,
    so the command reports `node.not_found` instead of treating absence as the
    desired end state.
- If the resolved node's role is `gateway`, fail before side effects. Gateway
  retirement is outside `node:remove` and requires a future explicit gateway
  migration/removal flow.
- Apply the destructive consent rules from the selected input mode.

### Gateway Configuration Cleanup Rules

- Delete all `node_access` records where the node is the consumer or the
  serving node.
- Delete all gateway-side `firewall_rules` registry rows for the removed node so
  the node record can be deleted. Do not contact the target or alter live
  firewall state.
- Delete the node record from the gateway registry.
- After deletion, rematerialize the node-owned `10-node-records.conf` and any
  affected proxy-owned `20-proxy-records.conf` from remaining gateway state.
  Use `reconcileRecords()`, the shared ownership-neutral materializer, and one
  DNS restart. This path never rewrites base `dnsmasq.conf`. Do not call
  `dns:*`, remove caller-local resolver overrides, or edit public/provider DNS.

### WireGuard Detach Rules

- Attempt to remove the node's WireGuard peer identity that the gateway manages.
- If the peer is already absent, continue without failure.
- If peer removal fails for any other reason, capture the warning as remaining
  node-family drift and continue.

### Removal Result Rules

- Return the removed node name, action, whether the removed node was the current
  caller, grant count, peer removal status, and any structured warnings.

### Scope Boundaries

`node:remove` must not:
- SSH into the target node.
- Clean apps, instances, workspaces, tools, processes, schedules, operator-managed
  firewall rules, user proxy routes, or deploy artifacts on the server.
- Block removal because downstream family state exists.
- Remove local caller settings or local WireGuard configuration when the removed
  node is the local machine.

Operators should remove or migrate apps and instances through their owning commands such as
[`app:remove`](../../../5_app/6_app-remove/app-remove.md) before removing the app
node that owns them. This is operational guidance, not a blocking precondition:
`node:remove` remains scoped to node identity, grants, and WireGuard peer
detach.

When a caller removes its own node record, the command removes the
gateway-owned node record, access grants, and WireGuard peer like any other
client removal. The command does not require an extra flag beyond the
shared destructive consent model. After success, future Orbit commands from
that machine may fail until the machine is enrolled again or cleaned up through
a future local cleanup command.

## Renderer Contracts

- [Human renderer](6.1_node-remove_output-render_human.md)
- [JSON renderer](6.2_node-remove_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Node not found | No active node record matches `name`. Already-absent removal is not idempotent. | Failure |
| Gateway node removal | The target node has role `gateway`, regardless of gateway count. | Failure |
| Missing destructive consent | Non-interactive input mode and `--force` is absent. | Failure |
| Cancelled confirmation | Interactive mode where the operator declines the prompt. | Failure |

Current removal always returns an empty warnings list. Peer removal is reported
only via `wireguard_peer_removed` (true when a peer row was deleted). Any later
stale peer on a live fleet is node-family drift handled by doctor, not by a
remove-time warning payload.

DNS projection reconciliation failure is a command failure. Removal must not
claim that node- or proxy-owned DNS artifacts are clean before the shared
materializer and runtime restart succeed.

The absent-target rule is intentionally different from `node:revoke`.
`node:revoke` validates both endpoint node identities and then makes the
relationship edge absent; an already-absent grant is safe to report as
idempotent success. `node:remove` targets the endpoint identity itself, so an
already-absent node remains a validation failure.

## Doctor Relationship

- Removed nodes disappear from the normal `doctor --family=node` scope.
- A stale WireGuard peer for a removed node is reported as `node.wireguard_peer_extra`
  by the node-family probe. See
  [`node-doctor.md`](../../node-doctor.md#node-issue-codes).
- `node.wireguard_peer_extra` is adopt-only: recovery is
  `doctor --family=node --adopt` when peer attachment is intended. It is not
  restored by `doctor --family=node --restore`. Other restorable node-family
  drift may still name `doctor --family=node --restore`.
- Orphaned downstream family state on a removed node is not reported by the
  node family. Each downstream family owns its own drift detection.

## Activity Logging

The gateway API endpoint emits an activity entry for successful and failed node
removals.

| Field | Value |
| --- | --- |
| Type | `api:DELETE /nodes/{name}` |
| Effect | `destructive` |
| Subject | Target `Node` when the node is resolved; `none` when validation or lookup fails before target resolution. |
| Properties | `target_node` is the requested node name; `removed_self` records whether the removed node was the caller; `grants_removed` records removed access edges; `wireguard_peer_removed` records whether the gateway peer detach completed. |
| Description | `Node <name> removed` |

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Node/NodeWriteCommandTest.php` | CLI delete forwarding, force gating, human and JSON renderer output, and lifecycle validation before gateway contact. |
| `apps/gateway/tests/Feature/Http/Api/NodeRemoveControllerTest.php` | Gateway remove authorization, force removal, self-removal semantics, and delete envelopes. |

Input-mode-specific test mapping lives in:

- [`5.1_node-remove_input-mode_interactive.md`](5.1_node-remove_input-mode_interactive.md#test-mapping)
- [`5.2_node-remove_input-mode_non-interactive.md`](5.2_node-remove_input-mode_non-interactive.md#test-mapping)

Renderer-specific test mapping lives in:

- [`6.1_node-remove_output-render_human.md`](6.1_node-remove_output-render_human.md#test-mapping)
- [`6.2_node-remove_output-render_json.md`](6.2_node-remove_output-render_json.md#test-mapping)

Deployment-context-specific test mapping lives in:

- [`2_node-remove_on-client.md`](2_node-remove_on-client.md#test-mapping)
- [`3_node-remove_on-gateway-node.md`](3_node-remove_on-gateway-node.md#test-mapping)
Destructive consent coverage note: routine tests cover only the mapped `--force`, destructive consent, or confirmation paths above; prompt-only variants and operator forwarding stay as coverage gaps when no path is listed.
