# Technical Contract: `node:remove` From Client Callers

[Back to `node:remove` technical contract.](1_node-remove.md)

This page describes how configured CLI callers that are not gateways forward
`node:remove` to the gateway.

**Effects:** `destructive`, `write` when forwarded. `none` when the gateway
rejects the request before gateway-owned side effects.

**Prerequisites:**
- The CLI has local gateway endpoint and trust configuration.
- The CLI has an active gateway-issued WireGuard identity.

**Post-input path eligibility:**
- The CLI can reach the gateway API over HTTPS through WireGuard.
- The authenticated caller is authorized by the node access policy that the
  gateway owns:
  either a `node:remove` grant on the target node or a gateway-admin grant.

## Allowed Paths

| Context | Behavior |
| --- | --- |
| Configured CLI authenticated as a granted caller | Resolve input locally, then forward to the gateway over HTTPS through WireGuard. |
| No configured gateway | Fail before prompts or side effects. |
| Gateway unavailable | Fail before side effects after input resolution and before gateway-owned mutation. |
| Missing target or gateway-admin grant | Gateway rejects before gateway-owned mutation. |

## Forwarding Contract

`node:remove` forwards from the CLI to the gateway. The CLI gathers interactive
input locally, applies missing-input and destructive consent rules locally, and
sends a typed remove request to the gateway. The CLI must not SSH into the
gateway for this command.

The forwarded request includes:

- `node_remove.name`;
- `node_remove.destructive_consent=true`;
- `node_remove.destructive_consent_source`, either `force` or
  `interactive_confirm`;
- the selected output renderer;
- the authenticated caller WireGuard identity.

The gateway authenticates the caller through WireGuard identity and authorizes
the request through the node access policy it owns. `node:remove` requires the
`node:remove` permission on the target node. A grant using the `gateway-admin`
preset also covers the operation.

## Self-Removal

A caller may remove its own client record when it has a covering grant for the
operation. The gateway detects self-removal by comparing `node_remove.name`
with the authenticated caller's WireGuard identity. Self-removal does not
require an extra flag beyond the shared destructive consent model.

When self-removal succeeds, the gateway removes the caller's gateway-owned
node record, node access grants, and WireGuard peer. The local machine keeps
its local gateway endpoint, trusted CA, and local WireGuard configuration.
Future Orbit commands from that machine may fail because the gateway no longer
recognizes its node identity.

## Error Contract

When no gateway is configured locally, the command must stop before prompts or
side effects and show:

```text
Gateway connection is required to remove a node.
```

JSON mode returns a structured error with the same message.

When the gateway rejects the caller's access to the target node, the command
must show:

```text
This node is not authorized for 'node:remove' on '<target>'.
```

## Failure Semantics

- Fail before prompts or side effects when no gateway is configured.
- Fail before gateway-owned side effects when the gateway is unreachable.
- Fail before gateway-owned side effects when the caller is missing a covering
  `node:remove` or gateway-admin grant.
- Fail before side effects when `node_remove.name` is missing, invalid, or
  points to the gateway node.

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Commands/Nodes/NodeRemoveOnOperatorNodeContractTest.php` | Operator-node caller forwarding, grant authorization, destructive consent, and result rendering (full list below). |

`NodeRemoveOnOperatorNodeContractTest` covers operator-node caller forwarding over
HTTPS (no SSH-to-gateway path), forwarded payload, grant authorization
failures, self-removal detection by WireGuard identity, destructive
consent for self-removal and normal removal, no local-settings cleanup on
self-removal, gateway-unavailable and authorization failures, and result
rendering.
