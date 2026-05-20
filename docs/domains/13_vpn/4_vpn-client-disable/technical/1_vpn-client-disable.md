# Technical Contract: `orbit vpn-client:disable <name> [--json]`

[Back to public `vpn-client:disable` documentation.](../vpn-client-disable.md)

**Owner:** `vpn`.

**Effects:** `write`, `gateway-admin`.

**Prerequisites:**
- The caller is a gateway node, or an authorized client with SSH access
  to the gateway over Orbit/WireGuard.
- The gateway VPN backend is installed and reachable on the gateway host.
- The operator can authenticate to the gateway VPN backend when TOTP is
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
| `totp` | `--totp=<code>` | Optional. | Never. | Backend configured default when available. | Numeric one-time code accepted by the gateway VPN backend. |
| `json` | `--json` | Optional. | Never. | `false` | Selects the JSON renderer. |

## Behavior Contract

### Client Disablement Rules

- Resolve `name` against gateway VPN backend clients.
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
| Gateway SSH unavailable | A operator caller cannot execute the gateway-local operation over Orbit/WireGuard SSH. | `error.code=gateway_ssh_unavailable` |
| VPN backend unavailable | The gateway VPN backend is missing, stopped, or unreachable on the gateway host. | `error.code=vpn_backend_unavailable` |
| VPN backend authentication failed | Stored backend credentials or supplied TOTP code are rejected. | `error.code=vpn_backend_auth_failed` |

## Doctor Relationship

`vpn-client:disable` mutates gateway VPN backend state for non-node clients. It
does not create doctor issues, fix drift, or adopt backend state.
[`doctor --family=node`](../../../1_node/node-doctor.md) owns Orbit node
WireGuard identity and node peer drift that the gateway manages.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Vpn/VpnClientDisableCommandTest.php` | Command contract: caller-role denial, operator-caller gateway-local SSH execution, gateway execution, TOTP handling, existing enabled client disablement, already-disabled success, missing client failure, active node peer protection, and no node configuration writes. |
| `tests/Feature/Commands/Vpn/VpnClientDisableRendererTest.php` | Human and JSON renderer output and every documented `error.code` value. |
