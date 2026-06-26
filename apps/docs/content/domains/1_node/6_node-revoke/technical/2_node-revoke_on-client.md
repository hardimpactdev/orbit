# Technical Contract: `node:revoke` From Configured Clients

[Back to `node:revoke` technical contract.](1_node-revoke.md)

This page describes the configured-client forwarding path for `node:revoke`.

**Effects:** `destructive`, `write` when forwarded. `none` when the gateway
rejects the request before gateway-owned side effects.

**Prerequisites:**
- The CLI has local gateway endpoint and trust configuration.
- The CLI has an active gateway-issued WireGuard identity.

**Post-input path eligibility:**
- The CLI can reach the gateway API over HTTPS through WireGuard.
- The caller's grant to the gateway includes `node:revoke` or `*`.

## Allowed Paths

| Context | Behavior |
| --- | --- |
| Configured CLI authenticated with `node:revoke` on a grant to the gateway | Resolve input locally, then forward to the gateway over HTTPS through WireGuard. |
| No configured gateway | Fail before prompts or side effects. |
| Gateway unavailable | Fail before side effects after input resolution and before gateway-owned mutation. |
| Not authorized for grant management | Gateway rejects before gateway-owned mutation. |

## Forwarding Contract

`node:revoke` forwards from the CLI to the gateway. The CLI gathers interactive
input locally, applies missing-input and destructive consent rules locally, and
sends a typed revoke request to the gateway. The CLI must not SSH into the
gateway for this command.

The forwarded request includes:

- `node_revoke.consuming_node`;
- `node_revoke.serving_node`;
- `node_revoke.destructive_consent=true`;
- `node_revoke.destructive_consent_source`, either `force` or
  `interactive_confirm`;
- the selected output renderer;
- the authenticated caller WireGuard identity.

The gateway authenticates the caller through WireGuard identity and authorizes
the request through the node access policy it owns. Because `node:revoke`
mutates access policy that the gateway owns, the caller must hold
`node:revoke` or `*` on a grant to the gateway. Access to the target nodes alone does not authorize the
revocation write.

## Self-Lockout

A caller may revoke its own consuming→gateway grant when the grant includes
`node:revoke` or `*`. The gateway
detects self-lockout by comparing `node_revoke.consuming_node` with the
authenticated caller's WireGuard identity and `node_revoke.serving_node` with
the gateway node. Self-lockout uses the same destructive consent model as any
other revocation and does not require an extra flag beyond `--force`.

When self-lockout succeeds, the gateway deletes the caller's consuming→gateway
`node_access` record. The local machine keeps its local gateway endpoint,
trusted CA, WireGuard configuration, and node record. Future Orbit commands
from that machine will fail authorization at the gateway because the caller lacks
authorization to operate on the gateway node; recovery is to run
`node:grant <caller> <gateway>` from the gateway itself.

Interactive input mode renders the self-lockout confirmation label. JSON output
sets `success.data.self_lockout=true`; otherwise the field is `false`.

This is only an authorization edge in the operator API. Gateway-local direct mutation is
a gateway-owned write path and does not report `self_lockout=true`.

## Error Contract

When no gateway is configured locally, the command must stop before prompts or
side effects and show:

```text
Gateway connection is required to revoke a grant.
```

JSON mode returns a structured error with the same message.

When the gateway rejects the client's access to manage grants, the
command must show:

```text
This action requires the node:revoke permission on a grant to the gateway.
```

## Failure Semantics

- Fail before prompts or side effects when no gateway is configured.
- Fail before gateway-owned side effects when the gateway is unreachable.
- Fail before gateway-owned side effects when the caller does not hold
  `node:revoke` or `*` on a grant to the gateway.
- Fail before side effects when `node_revoke.consuming_node` or
  `node_revoke.serving_node` is missing, invalid, or points to a non-existent
  node.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Node/NodeWriteCommandTest.php` | Client-context node:revoke forwarding and rendered output. |

There is no gateway-side coverage for this command-local mapping: input handling and renderer behavior live in `apps/cli`. Gateway API behavior is mapped in the command contract file when a gateway-side surface exists.

Destructive consent coverage note: routine tests cover only the mapped `--force`, destructive consent, or confirmation paths above; prompt-only variants and operator forwarding stay as coverage gaps when no path is listed.
