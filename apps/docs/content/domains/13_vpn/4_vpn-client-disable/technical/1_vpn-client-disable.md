# Technical Contract: `orbit vpn-client:disable <name> [--json]`

[Back to public `vpn-client:disable` documentation.](../vpn-client-disable.md)

**Owner:** `vpn`.

**Effects:** `write`.

**Prerequisites:**
- The caller is the gateway node, has `vpn:write` on the active gateway node,
  or has `gateway-admin` (`*`) on the active gateway node.
- Non-gateway callers can reach the active `vpn` role node over
  Orbit/WireGuard SSH. In v1 that node is gateway-coupled.
- The active `vpn` role is resolvable.
- The VPN runtime backend is installed and reachable on the active `vpn` role host.
- The operator can authenticate to the VPN runtime backend when TOTP is
  required.

## Signature

```bash
orbit vpn-client:disable <name> [--totp=<code>] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `name` | `<name>` | Always. | Never. | None. | Existing non-node VPN client name. |
| `totp` | `--totp=<code>` | Optional. | Never. | Backend configured default when available. | Numeric one-time code accepted by the VPN runtime backend. |
| `json` | `--json` | Optional. | Never. | `false` | Selects the JSON renderer. |

## Behavior Contract

### Client Disablement Rules

- Resolve the active `vpn` role host.
- Resolve `name` against VPN runtime backend clients.
- Fail when the client does not exist.
- Fail when the resolved backend peer matches an active Orbit node identity.
- Disable the backend peer when it is enabled.
- Return success when the backend peer is already disabled.
- Preserve the backend peer and generated config so it can be enabled later.

### Node Identity Boundary

`vpn-client:disable` must not disable Orbit node peers. Disabling an active node
peer would break node identity and reachability; node peer drift belongs to
`doctor --family=node`.

## Renderer Contracts

- [Human renderer](6.1_vpn-client-disable_output-render_human.md)
- [JSON renderer](6.2_vpn-client-disable_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| VPN runtime unavailable | No active `vpn` role node exists for VPN administration. | `error.code=vpn_runtime_unavailable` |
| VPN runtime SSH unavailable | A non-gateway caller cannot execute the VPN-role runtime operation over Orbit/WireGuard SSH on the active `vpn` role node. | `error.code=vpn_runtime_ssh_unavailable` |
| VPN backend unavailable | The VPN runtime backend is missing, stopped, or unreachable on the active `vpn` role host. | `error.code=vpn_backend_unavailable` |
| VPN backend authentication failed | Stored backend credentials or supplied TOTP code are rejected. | `error.code=vpn_backend_auth_failed` |

## Doctor Relationship

`vpn-client:disable` mutates VPN runtime backend state for non-node clients. It
does not create doctor issues, fix drift, or adopt backend state.
[`doctor --family=node`](../../../1_node/node-doctor.md) owns Orbit node
WireGuard identity and node peer drift that the gateway manages through the
active `vpn` role.

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Vpn/VpnCommandsTest.php` | CLI vpn-client:disable POST forwarding, idempotent success rendering, missing-client failure, and gateway error passthrough. |
| `apps/gateway/tests/Feature/Http/Api/VpnControllerActivityTest.php` | Gateway VPN client disable authorization and disablement envelopes. |

Renderer-specific test mapping lives in:

- [`6.1_vpn-client-disable_output-render_human.md`](6.1_vpn-client-disable_output-render_human.md#test-mapping)
- [`6.2_vpn-client-disable_output-render_json.md`](6.2_vpn-client-disable_output-render_json.md#test-mapping)
