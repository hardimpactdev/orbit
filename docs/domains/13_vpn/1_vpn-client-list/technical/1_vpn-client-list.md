# Technical Contract: `orbit vpn-client:list [--json]`

[Back to public `vpn-client:list` documentation.](../vpn-client-list.md)

**Owner:** `vpn`.

**Effects:** `read`, `gateway-admin`.

**Prerequisites:**
- The caller is a gateway node, or an authorized client with SSH access
  to the active `vpn` role node over Orbit/WireGuard. In v1 that node is
  gateway-coupled.
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
- Read nodes or app-owned runtime state.

## Renderer Contracts

- [Human renderer](6.1_vpn-client-list_output-render_human.md)
- [JSON renderer](6.2_vpn-client-list_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| VPN runtime unavailable | No active `vpn` role node exists for VPN administration. | `error.code=vpn_runtime_unavailable` |
| VPN runtime SSH unavailable | An operator caller cannot execute the VPN-role runtime operation over Orbit/WireGuard SSH on the active `vpn` role node. | `error.code=vpn_runtime_ssh_unavailable` |
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
| `tests/Feature/Commands/Vpn/VpnClientListCommandTest.php` | Command contract: caller-role denial, operator-caller SSH execution to the active `vpn` role node, VPN-role runtime backend execution, backend authentication, TOTP handling, empty list success, node peer classification, and read-only behavior. |
| `tests/Feature/Commands/Vpn/VpnClientListRendererTest.php` | Human and JSON renderer output, empty state, classified client rows, and every documented `error.code` value. |
