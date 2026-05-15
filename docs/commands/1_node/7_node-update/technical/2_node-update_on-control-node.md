# Technical Contract: `node:update` Authorized For Control Callers

[Back to `node:update` technical contract.](1_node-update.md)

This page describes what the gateway authorizes for callers whose
authenticated node record has role `control`.

**Effects:** `write` when forwarded. `none` when the gateway rejects the
request before gateway-owned side effects.

**Prerequisites:**
- The CLI has local gateway endpoint and trust configuration.
- The CLI has an active gateway-issued WireGuard identity.

**Post-input path eligibility:**
- The CLI can reach the gateway API over HTTPS through WireGuard.
- The control-role caller is authorized through gateway-owned node access
  policy to operate on the gateway node.

## Allowed Paths

| Context | Behavior |
| --- | --- |
| Configured CLI authenticated as a control caller with gateway-node access | Resolve input locally, then forward to the gateway over HTTPS through WireGuard. |
| No configured gateway | Fail before prompts or side effects. |
| Gateway unavailable | Fail before side effects after input resolution and before gateway-owned mutation. |
| Not authorized for gateway node | Gateway rejects before gateway-owned mutation. |

## Forwarding Contract

`node:update` forwards from the CLI to the gateway. The CLI gathers interactive
input locally, applies missing-input rules locally, and sends a typed update
request to the gateway. The CLI must not SSH into the gateway for this command.

The forwarded request includes:

- `node_update.name`;
- `node_update.host` when present;
- `node_update.environment` when present;
- `node_update.tld` when present;
- `node_update.public_ipv4` when present;
- `node_update.public_ipv6` when present;
- the selected output renderer;
- the authenticated control-caller WireGuard identity.

The gateway authenticates the caller through WireGuard identity and authorizes
the request through the node access policy it owns. Because `node:update`
mutates fleet configuration that the gateway owns, the control-role caller must have
access to the gateway node. Access to the target node alone does not authorize
the update write.

Forwarded payload example for a development app TLD update:

```json
{
  "tld": "test"
}
```

The control CLI does not resolve the target row locally. If a forwarded
`node_update.tld` targets a gateway or control node, the gateway rejects the
request before gateway-owned side effects with `node.field_role_incompatible`,
`meta.field=tld`, and the target role in metadata.

## Self-Update

A control-role caller may update its own control-node record when it is
authorized for the gateway node. Self-update does not require an extra flag.

## Error Contract

When no gateway is configured locally, the command must stop before prompts or
side effects and show:

```text
Gateway connection is required to update a node.
```

JSON mode returns a structured error with the same message.

When the gateway rejects the caller's access to the gateway node, the command
must show:

```text
This control node is not authorized to update nodes.
```

## Failure Semantics

- Fail before prompts or side effects when no gateway is configured.
- Fail before gateway-owned side effects when the gateway is unreachable.
- Fail before gateway-owned side effects when the control-role caller is not
  authorized to operate on the gateway node.
- Fail before side effects when `node_update.name` is missing, invalid, or no
  supported field flags are provided in non-interactive mode.
- Preserve gateway-owned `node.field_role_incompatible` errors for forwarded
  `node_update.tld` requests against non-app targets.

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Nodes/NodeUpdateOnControlNodeContractTest.php` | Configured control caller forwarding over HTTPS through WireGuard, no SSH-to-gateway path, forwarded request payload including `tld`, gateway-preserved TLD role rejection for non-app targets, gateway-node access authorization, self-update detection through authenticated WireGuard identity, gateway-unavailable failure, authorization failure, and result rendering. |
