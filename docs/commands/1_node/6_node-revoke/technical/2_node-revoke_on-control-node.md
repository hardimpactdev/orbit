# Technical Contract: `node:revoke` Authorized For Control Callers

[Back to `node:revoke` technical contract.](1_node-revoke.md)

This page describes what the gateway authorizes for callers whose
authenticated node record has role `control`.

**Effects:** `destructive`, `write` when forwarded. `none` when the gateway
rejects the request before gateway-owned side effects.

**Prerequisites:**
- The CLI has local gateway endpoint and trust configuration.
- The CLI has an active gateway-issued WireGuard identity.

**Post-input path eligibility:**
- The CLI can reach the gateway API over HTTPS through WireGuard.
- The control-role caller is authorized through gateway-owned node access
  policy to manage node access grants.

## Allowed Paths

| Context | Behavior |
| --- | --- |
| Configured CLI authenticated as a control caller with grant-management access | Resolve input locally, then forward to the gateway over HTTPS through WireGuard. |
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
- the authenticated control-caller WireGuard identity.

The gateway authenticates the caller through WireGuard identity and authorizes
the request through gateway-owned node access policy. Because `node:revoke`
mutates gateway-owned access policy, the control-role caller must have access
to the gateway node. Access to the target nodes alone does not authorize the
revocation write.

## Self-Lockout

A control-role caller may revoke its own consuming→gateway grant. The gateway
detects self-lockout by comparing `node_revoke.consuming_node` with the
authenticated caller's WireGuard identity and `node_revoke.serving_node` with
the gateway node. Self-lockout uses the same destructive consent model as any
other revocation and does not require an extra flag beyond `--force`.

When self-lockout succeeds, the gateway deletes the caller's consuming→gateway
`node_access` record. The local machine keeps its local gateway endpoint,
trusted CA, WireGuard configuration, and node record. Future Orbit commands
from that machine will fail authorization at the gateway because the caller is
no longer authorized to operate on the gateway node; recovery requires another
control or gateway caller to grant access back.

Interactive input mode renders the self-lockout confirmation label. JSON output
sets `success.data.self_lockout=true`; otherwise the field is `false`.

## Error Contract

When no gateway is configured locally, the command must stop before prompts or
side effects and show:

```text
Gateway connection is required to revoke a grant.
```

JSON mode returns a structured error with the same message.

When the gateway rejects the control node's access to manage grants, the
command must show:

```text
This control node is not authorized to revoke grants.
```

## Failure Semantics

- Fail before prompts or side effects when no gateway is configured.
- Fail before gateway-owned side effects when the gateway is unreachable.
- Fail before gateway-owned side effects when the control-role caller is not
  authorized to manage node access grants.
- Fail before side effects when `node_revoke.consuming_node` or
  `node_revoke.serving_node` is missing, invalid, or points to a non-existent
  node.

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Nodes/NodeRevokeOnControlNodeContractTest.php` | Configured control caller forwarding over HTTPS through WireGuard, no SSH-to-gateway path, forwarded request payload, gateway-node access authorization, self-lockout behavior, destructive consent coverage for self-lockout and normal revocation, gateway-unavailable failure, authorization failure, and result rendering. |
