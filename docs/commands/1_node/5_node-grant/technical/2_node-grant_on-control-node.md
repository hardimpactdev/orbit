# Technical Contract: `node:grant` On A Control Node

[Back to `node:grant` technical contract.](1_node-grant.md)

This page describes caller-role behavior when `orbit node:grant` is invoked
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

`node:grant` forwards from configured control nodes to the gateway. The control
node gathers input locally, applies missing-input rules locally, and sends a
typed grant request to the gateway. The control node must not SSH into the
gateway for this command.

The forwarded request includes:

- `node_grant.consuming_node`;
- `node_grant.serving_node`;
- the selected output renderer;
- the authenticated control-node WireGuard identity.

The gateway authenticates the caller through WireGuard identity and authorizes
the request through gateway-owned node access policy. Because `node:grant`
mutates gateway-owned fleet intent, the control node must have access to the
gateway node. Access to the target nodes alone does not authorize the grant
write.

Non-gateway callers must already hold a consuming→gateway grant to manage node
access grants. This prerequisite is enforced by gateway-owned access policy.

## Error Contract

When no gateway is configured locally, the command must stop before side effects
and show:

```text
Gateway connection is required to grant node access.
```

JSON mode returns a structured error with the same message.

When the gateway rejects the control node's access to the gateway node, the
command must show:

```text
This control node is not authorized to grant node access.
```

## Failure Semantics

- Fail before prompts or side effects when no gateway is configured.
- Fail before gateway-owned side effects when the gateway is unreachable.
- Fail before gateway-owned side effects when the control node is not
  authorized to operate on the gateway node.
- Fail before side effects when `node_grant.consuming_node` or
  `node_grant.serving_node` is missing, invalid, or violates policy.

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Nodes/NodeGrantOnControlNodeContractTest.php` | Configured control caller forwarding over HTTPS through WireGuard, no SSH-to-gateway path, forwarded request payload, gateway-node access authorization, prerequisite consuming→gateway grant enforcement, gateway-unavailable failure, authorization failure, and result rendering. |
