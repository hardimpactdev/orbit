# Technical Contract: `node:grant` From Configured Clients

[Back to `node:grant` technical contract.](1_node-grant.md)

This page describes the configured-client forwarding path for `node:grant`.

**Effects:** `write` when forwarded. `none` when the gateway rejects the
request before gateway-owned side effects.

**Prerequisites:**
- The CLI has local gateway endpoint and trust configuration.
- The CLI has an active gateway-issued WireGuard identity.

**Post-input path eligibility:**
- The CLI can reach the gateway API over HTTPS through WireGuard.
- The caller's grant to the gateway includes `node:grant` or `*`.

## Allowed Paths

| Context | Behavior |
| --- | --- |
| Configured CLI authenticated with `node:grant` on a grant to the gateway | Resolve input locally, then forward to the gateway over HTTPS through WireGuard. |
| No configured gateway | Fail before prompts or side effects. |
| Gateway unavailable | Fail before side effects after input resolution and before gateway-owned mutation. |
| Not authorized for gateway node | Gateway rejects before gateway-owned mutation. |

## Forwarding Contract

`node:grant` forwards from the CLI to the gateway. The CLI gathers input
locally, applies missing-input rules locally, and sends a typed grant request
to the gateway. The CLI must not SSH into the gateway for this command.

The forwarded request includes:

- `node_grant.consuming_node`;
- `node_grant.serving_node`;
- `node_grant.preset` or `node_grant.permissions`;
- `node_grant.force`;
- the selected output renderer;
- the authenticated caller WireGuard identity.

The gateway authenticates the caller through WireGuard identity and authorizes
the request through the node access policy it owns. Because `node:grant`
mutates gateway-owned fleet configuration, the caller must hold `node:grant`
or `*` on a grant to the gateway. Access to the target nodes alone does not
authorize the grant write.

Non-gateway callers must already hold a consuming→gateway grant whose
permissions include `node:grant` or `*` to manage node access grants. This
prerequisite is enforced by gateway-owned access policy.

## Error Contract

When no gateway is configured locally, the command must stop before side effects
and show:

```text
Gateway connection is required to grant node access.
```

JSON mode returns a structured error with the same message.

When the gateway rejects the caller's access to the gateway node, the command
must show:

```text
This action requires the node:grant permission on a grant to the gateway.
```

## Failure Semantics

- Fail before prompts or side effects when no gateway is configured.
- Fail before gateway-owned side effects when the gateway is unreachable.
- Fail before gateway-owned side effects when the caller does not hold
  `node:grant` or `*` on a grant to the gateway.
- Fail before side effects when `node_grant.consuming_node` or
  `node_grant.serving_node` is missing, invalid, or violates policy.
- Fail before side effects when the permission input is missing, conflicts,
  references an unknown permission or preset, normalizes to an empty set, or
  requests an elevated gateway grant without consent.

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Commands/Nodes/NodeGrantOnControlNodeContractTest.php` | Configured-client forwarding, no SSH fallback, forwarded payload, `node:grant` authorization, gateway-unavailable failure, authorization failure, and result rendering. |
