# Technical Contract: `node:remove` On A Control Node

[Back to `node:remove` technical contract.](1_node-remove.md)

This page describes caller-role behavior when `orbit node:remove` is invoked
from a control node.

**Effects:** `destructive`, `write` when forwarded. `none` when rejected before
gateway-owned side effects.

**Prerequisites:**
- `general.local_node_role` is unset, `null`, or explicitly `control`.
- The control node has local gateway endpoint and trust configuration.
- The control node has an active gateway-issued WireGuard identity.

**Post-input path eligibility:**
- The control node can reach the gateway API over HTTPS through WireGuard.
- The control node is authorized through gateway-owned node access policy to
  operate on the gateway node.

## Allowed Paths

| Context | Behavior |
| --- | --- |
| Configured control node with gateway-node access | Resolve input locally, then forward to the gateway over HTTPS through WireGuard. |
| No configured gateway | Fail before prompts or side effects. |
| Gateway unavailable | Fail before side effects after input resolution and before gateway-owned mutation. |
| Not authorized for gateway node | Fail before gateway-owned mutation. |

## Forwarding Contract

`node:remove` forwards from configured control nodes to the gateway. The control
node gathers interactive input locally, applies missing-input and destructive
consent rules locally, and sends a typed remove request to the gateway. The
control node must not SSH into the gateway for this command.

The forwarded request includes:

- `node_remove.name`;
- `node_remove.destructive_consent=true`;
- `node_remove.destructive_consent_source`, either `force` or
  `interactive_confirm`;
- the selected output renderer;
- the authenticated control-node WireGuard identity.

The gateway authenticates the caller through WireGuard identity and authorizes
the request through gateway-owned node access policy. Because `node:remove`
mutates gateway-owned fleet intent, the control node must have access to the
gateway node. Access to the target node alone does not authorize the removal
write.

## Self-Removal

A configured control caller may remove its own control-node record. The gateway
detects self-removal by comparing `node_remove.name` with the authenticated
control-node WireGuard identity. Self-removal does not require an extra flag
beyond the shared destructive consent model.

When self-removal succeeds, the gateway removes the control node's gateway-owned
node record, node access grants, and WireGuard peer. The local machine keeps its
local gateway endpoint, trusted CA, local role setting, and local WireGuard
configuration. Future Orbit commands from that machine may fail because the
gateway no longer recognizes its node identity.

## Error Contract

When no gateway is configured locally, the command must stop before prompts or
side effects and show:

```text
Gateway connection is required to remove a node.
```

JSON mode returns a structured error with the same message.

When the gateway rejects the control node's access to the gateway node, the
command must show:

```text
This control node is not authorized to remove nodes.
```

## Failure Semantics

- Fail before prompts or side effects when no gateway is configured.
- Fail before gateway-owned side effects when the gateway is unreachable.
- Fail before gateway-owned side effects when the control node is not
  authorized to operate on the gateway node.
- Fail before side effects when `node_remove.name` is missing, invalid, or
  points to the gateway node.

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Nodes/NodeRemoveOnControlNodeContractTest.php` | Configured control caller forwarding over HTTPS through WireGuard, no SSH-to-gateway path, forwarded request payload, gateway-node access authorization, self-removal detection through authenticated WireGuard identity, destructive consent coverage for self-removal and normal removal, no local-settings cleanup on self-removal, gateway-unavailable failure, authorization failure, and result rendering. |
