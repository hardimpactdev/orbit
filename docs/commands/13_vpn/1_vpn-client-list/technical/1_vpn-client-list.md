# Technical Contract: `orbit vpn-client:list [--json]`

[Back to public `vpn-client:list` documentation.](../vpn-client-list.md)

**Owner:** `vpn`.

**Effects:** `read`, `gateway-admin`.

**Prerequisites:**
- The caller is a gateway node, or an authorized control node with SSH access
  to the gateway over Orbit/WireGuard.
- The gateway VPN backend is installed and reachable on the gateway host.
- The operator can authenticate to the gateway VPN backend when TOTP is
  required.

## Signature

```bash
orbit vpn-client:list [--totp=<code>] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `totp` | `--totp=<code>` | Optional. | Never. | Backend configured default when available. | Numeric one-time code accepted by the gateway VPN backend. |
| `json` | `--json` | Optional. | Never. | `false` | Selects the JSON renderer. |

## Authorization By Caller Role

Authorization is enforced by the gateway. Gateway callers execute the backend
read locally. Control callers open the gateway-local execution path over SSH
through Orbit/WireGuard, then run the backend read on the gateway. App-node and
unknown callers are denied by the gateway before backend authentication or side
effects.

## Input Mode Contracts

No input-mode-specific contracts are required. The command has no required
inputs and does not prompt.

## Behavior Contract

### Backend Inventory Rules

- Read the gateway VPN backend client inventory from the gateway host.
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

- Mutate gateway VPN backend state.
- Create, remove, enable, or disable peers.
- Verify live node reachability.
- Repair stale node peers or missing node peers.
- Read app nodes or app-owned runtime state.

## Renderer Contracts

- [Human renderer](6.1_vpn-client-list_output-render_human.md)
- [JSON renderer](6.2_vpn-client-list_output-render_json.md)

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| Caller role not allowed | Caller role is `app` or `unknown`. | `error.code=caller_role_not_allowed` |
| Gateway SSH unavailable | A control caller cannot execute the gateway-local operation over Orbit/WireGuard SSH. | `error.code=gateway_ssh_unavailable` |
| Authorization failed | The caller is not authorized for gateway VPN administration. | `error.code=authorization_failed` |
| VPN backend unavailable | The gateway VPN backend is missing, stopped, or unreachable on the gateway host. | `error.code=vpn_backend_unavailable` |
| VPN backend authentication failed | Stored backend credentials or supplied TOTP code are rejected. | `error.code=vpn_backend_auth_failed` |

## Doctor Relationship

`vpn-client:list` is an administrative backend view. It does not create doctor
issues, fix drift, or adopt backend state. [`doctor --family=node`](../../../1_node/node-doctor.md)
owns Orbit node WireGuard identity, gateway-managed node peers, and node
reachability drift.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Vpn/VpnClientListCommandTest.php` | Command contract: caller-role denial, control-caller gateway-local SSH execution, gateway execution, backend authentication, TOTP handling, empty list success, node peer classification, and read-only behavior. |
| `tests/Feature/Commands/Vpn/VpnClientListRendererTest.php` | Human and JSON renderer output, empty state, classified client rows, and every documented `error.code` value. |
