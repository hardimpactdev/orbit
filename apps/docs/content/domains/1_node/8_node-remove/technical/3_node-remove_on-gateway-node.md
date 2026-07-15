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
4. Delete node access grants.
5. Remove the gateway-managed WireGuard peer.
6. Delete the node's firewall-rule registry rows from gateway state without
   contacting the target or altering its live firewall.
7. Remove development DNS mappings that the gateway owns for development nodes.
8. Delete the node record.
9. Return the result.

## Failure Semantics

- Fail before side effects when the node does not exist.
- Fail before side effects when the node is a gateway node, regardless of
  gateway count. Gateway retirement is outside `node:remove` and requires a
  future explicit gateway migration/removal flow.
- Fail before side effects when destructive consent is missing in
  non-interactive input mode or when interactive confirmation is declined.
- Report partial WireGuard detach as a structured warning in the success
  response.
- Report partial development DNS cleanup as a structured warning in the success
  response.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Http/Api/NodeRemoveControllerTest.php` | Gateway-local remove authorization, destructive consent, self-removal, gateway-node denial, not-found envelopes, and grant cleanup. |
| `apps/gateway/tests/Feature/Http/Api/NodeRemoveDevelopmentDnsWarningTest.php` | Gateway API development DNS warning payload and no-warning cleanup success. |

Destructive consent coverage note: gateway API tests cover missing-consent rejection; interactive confirmation remains a CLI prompt coverage gap outside this gateway-caller page.

Warning payload coverage note: development DNS warning payload shape is covered by the linked API test; WireGuard detach warning variants stay coverage gaps until focused tests land.
