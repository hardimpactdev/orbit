# Technical Contract: `node:update` From Client Callers

[Back to `node:update` technical contract.](1_node-update.md)

This page describes how configured CLI callers that are not gateways forward
`node:update` to the gateway.

**Effects:** `write` when forwarded. `none` when the gateway rejects the
request before gateway-owned side effects.

**Prerequisites:**
- The CLI has local gateway endpoint and trust configuration.
- The CLI has an active gateway-issued WireGuard identity.

**Post-input path eligibility:**
- The CLI can reach the gateway API over HTTPS through WireGuard.
- The authenticated caller is authorized by the node access policy that the
  gateway owns:
  either a `node:update` grant on the target node or a gateway-admin grant.

## Allowed Paths

| Context | Behavior |
| --- | --- |
| Configured CLI authenticated as a granted caller | Resolve input locally, then forward to the gateway over HTTPS through WireGuard. |
| No configured gateway | Fail before prompts or side effects. |
| Gateway unavailable | Fail before side effects after input resolution and before gateway-owned mutation. |
| Missing target or gateway-admin grant | Gateway rejects before gateway-owned mutation. |

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
- the authenticated caller WireGuard identity.

The gateway authenticates the caller through WireGuard identity and authorizes
the request through the node access policy it owns. `node:update` requires the
`node:update` permission on the target node. A grant using the `gateway-admin`
preset also covers the operation.

Forwarded payload example for a development app TLD update:

```json
{
  "tld": "test"
}
```

The control CLI does not resolve the target row locally. If a forwarded
`node_update.tld` targets a gateway or client, the gateway rejects the
request before gateway-owned side effects with `node.field_role_incompatible`,
`meta.field=tld`, and the target role in metadata.

## Self-Update

A caller may update its own client record when it has a covering grant for the
operation. Self-update does not require an extra flag.

## Error Contract

When no gateway is configured locally, the command must stop before prompts or
side effects and show:

```text
Gateway connection is required to update a node.
```

JSON mode returns a structured error with the same message.

When the gateway rejects the caller's access to the target node, the command
must show:

```text
This node is not authorized for 'node:update' on '<target>'.
```

## Failure Semantics

- Fail before prompts or side effects when no gateway is configured.
- Fail before gateway-owned side effects when the gateway is unreachable.
- Fail before gateway-owned side effects when the caller is missing a covering
  `node:update` or gateway-admin grant.
- Fail before side effects when `node_update.name` is missing, invalid, or no
  supported field flags are provided in non-interactive mode.
- Preserve gateway-owned `node.field_role_incompatible` errors for forwarded
  `node_update.tld` requests against non-app targets.

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Commands/Nodes/NodeUpdateOnControlNodeContractTest.php` | Configured caller forwarding over HTTPS through WireGuard, no SSH-to-gateway path, forwarded `tld` payload, gateway-preserved TLD role rejection, grant authorization failures, self-update detection, gateway-unavailable failure, and result rendering. |
