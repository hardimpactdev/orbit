# Technical Contract: `node:remove` Authorized For Gateway Callers

[Back to `node:remove` technical contract.](1_node-remove.md)

This page describes what the gateway authorizes for callers whose
authenticated node record has role `gateway`, including gateway-local CLI
execution.

**Prerequisites:**
- The gateway can read and write gateway-owned node configuration.
- The gateway can access gateway-managed WireGuard state.

## Allowed Paths

| Context | Behavior |
| --- | --- |
| Gateway-local execution | Performs removal directly. |

## Gateway Authority Rules

- The gateway is the source of truth for node records, node roles, and
  WireGuard identity.
- Gateway execution may delete durable node state directly.
- Gateway execution may remove gateway-managed WireGuard peers.
- Gateway execution must not SSH to the target node.

## Removal Flow

1. Resolve `node_remove.name`.
2. Validate the node exists and is not any gateway node.
3. Apply destructive consent.
4. Remove the gateway-managed peer from wg-easy durable state and the current
   live VPN task interface. If either step fails, keep Orbit registry state.
5. In one database transaction, delete the Orbit peer row, node access grants,
   firewall-rule registry rows, and node record without contacting the target
   or altering its live firewall.
6. Before that transaction commits, reconcile node- and proxy-owned DNS
   projections through the shared `reconcileRecords()` materializer and restart
   DNS once when artifacts changed. Do not rewrite tool-owned base
   configuration.
7. Return the result.

## Failure Semantics

- Fail before side effects when the node does not exist.
- Fail before side effects when the node is a gateway node, regardless of
  gateway count. Gateway retirement is outside `node:remove` and requires a
  future explicit gateway migration/removal flow.
- Fail before side effects when destructive consent is missing in
  non-interactive input mode or when interactive confirmation is declined.
- Return `node.wireguard_peer_removal_failed` and retain the node, peer, grants,
  and firewall rows when WireGuard detach fails.
- Return `node.dns_reconciliation_failed` and roll the registry transaction back
  when DNS projection reconciliation fails. The same command can retry safely.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Http/Api/NodeRemoveControllerTest.php` | Gateway-local authorization, destructive consent, self-removal eligibility, gateway-node denial, WireGuard and DNS retry, and registry cleanup. |
| `apps/gateway/tests/Feature/Services/Vpn/VpnDnsSwarmInstallerTest.php` | Dynamic VPN task resolution and live peer removal. |

Destructive consent coverage note: gateway API tests cover missing-consent rejection; interactive confirmation remains a CLI prompt coverage gap outside this gateway-caller page.

Focused gateway tests cover WireGuard detach failure and DNS rollback/retry behavior.
