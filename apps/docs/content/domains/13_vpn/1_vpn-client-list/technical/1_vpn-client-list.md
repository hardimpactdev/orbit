# Technical Contract: `orbit vpn-client:list [--json]`

[Back to public `vpn-client:list` documentation.](../vpn-client-list.md)

**Owner:** `vpn`.

**Effects:** `read`.

**Prerequisites:**
- The caller is the gateway node, has `vpn:read` on the active gateway node,
  or has `gateway-admin` (`*`) on the active gateway node.
- This public VPN command reaches the gateway through the typed HTTPS API over
  WireGuard. Caller identity changes authorization, not transport.
- The active `vpn` role is resolvable.
- The VPN runtime backend is installed and reachable on the active `vpn` role host.
- The operator can authenticate to the VPN runtime backend when TOTP is
  required.

## Signature

```bash
orbit vpn-client:list [--totp=<code>] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `totp` | `--totp=<code>` | Optional. | Never. | Backend configured default when available. | Numeric one-time code accepted by the VPN runtime backend. |
| `json` | `--json` | Optional. | Never. | `false` | Selects the JSON renderer. |

## Behavior Contract

### Backend Inventory Rules

- Resolve the active `vpn` role host.
- Read the VPN runtime backend client inventory from that host.
- Return every backend peer visible to Orbit's VPN backend credentials.
- Preserve backend client IDs when available.
- Normalize backend client addresses to the `address` field.
- Normalize handshake timestamps to ISO-8601 strings or `null`.
- Classify active Orbit node peers as `kind=node` when their backend name or
  address matches an active node record.
- Classify public VPN-admin clients created through this command family as
  `kind=admin`.
- Use `kind=unknown` when a backend peer cannot be safely classified.

### Scope Boundaries

`vpn-client:list` must not:

- Mutate VPN runtime backend state.
- Create, remove, enable, or disable peers.
- Verify live node reachability.
- Repair stale node peers or missing node peers.
- Read nodes or instance-owned runtime state.

## Renderer Contracts

- [Human renderer](6.1_vpn-client-list_output-render_human.md)
- [JSON renderer](6.2_vpn-client-list_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| VPN runtime unavailable | No active `vpn` role node exists for VPN administration. | `error.code=vpn_runtime_unavailable` |
| VPN backend unavailable | The VPN runtime backend is missing, stopped, or unreachable on the active `vpn` role host. | `error.code=vpn_backend_unavailable` |
| VPN backend authentication failed | Stored backend credentials or supplied TOTP code are rejected. | `error.code=vpn_backend_auth_failed` |

## Doctor Relationship

`vpn-client:list` is an administrative backend view. It does not create doctor
issues, fix drift, or adopt backend state. [`doctor --family=node`](../../../1_node/node-doctor.md)
owns Orbit node WireGuard identity, gateway-managed node peers, VPN-role
runtime drift, and node reachability drift.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Vpn/VpnCommandsTest.php` | CLI `vpn-client:list` gateway request forwarding, TOTP query propagation, human and JSON renderer output, empty state, classified client rows, and gateway error envelope passthrough. |
| `apps/gateway/tests/Feature/Http/Api/VpnControllerActivityTest.php` | Gateway VPN API activity logging, runtime-unavailable failures, read-grant enforcement, and fake-backend routing. |
| `apps/gateway/tests/Unit/Services/Vpn/VpnClientManagerTest.php` | Active node peer classification by name and address. |
