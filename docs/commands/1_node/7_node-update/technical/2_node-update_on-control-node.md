# Technical Contract: `node:update` On A Control Node

[Back to `node:update` technical contract.](1_node-update.md)

This page describes caller-role behavior when `orbit node:update` is invoked
from a control node.

**Effects:** `write` when forwarded. `none` when rejected before gateway-owned
side effects.

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

`node:update` forwards from configured control nodes to the gateway. The control
node gathers interactive input locally, applies missing-input rules locally, and
sends a typed update request to the gateway. The control node must not SSH into
the gateway for this command.

The forwarded request includes:

- `node_update.name`;
- `node_update.host` when present;
- `node_update.environment` when present;
- `node_update.public_ipv4` when present;
- `node_update.public_ipv6` when present;
- the selected output renderer;
- the authenticated control-node WireGuard identity.

The gateway authenticates the caller through WireGuard identity and authorizes
the request through gateway-owned node access policy. Because `node:update`
mutates gateway-owned fleet intent, the control node must have access to the
gateway node. Access to the target node alone does not authorize the update
write.

## Self-Update

A configured control caller may update its own control-node record when it is
authorized for the gateway node. Self-update does not require an extra flag.

## Error Contract

When no gateway is configured locally, the command must stop before prompts or
side effects and show:

```text
Gateway connection is required to update a node.
```

JSON mode returns a structured error with the same message.

When the gateway rejects the control node's access to the gateway node, the
command must show:

```text
This control node is not authorized to update nodes.
```

## Failure Semantics

- Fail before prompts or side effects when no gateway is configured.
- Fail before gateway-owned side effects when the gateway is unreachable.
- Fail before gateway-owned side effects when the control node is not
  authorized to operate on the gateway node.
- Fail before side effects when `node_update.name` is missing, invalid, or no
  supported field flags are provided in non-interactive mode.

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Nodes/NodeUpdateOnControlNodeContractTest.php` | Configured control caller forwarding over HTTPS through WireGuard, no SSH-to-gateway path, forwarded request payload, gateway-node access authorization, self-update detection through authenticated WireGuard identity, gateway-unavailable failure, authorization failure, and result rendering. |
