# Technical Contract: `orbit vpn-client:new <name> [--config] [--json]`

[Back to public `vpn-client:new` documentation.](../vpn-client-new.md)

**Owner:** `vpn`.

**Effects:** `write`, `gateway-admin`.

**Prerequisites:**
- The caller is a gateway node, or an authorized control node with SSH access
  to the gateway over Orbit/WireGuard.
- The gateway VPN backend is installed and reachable on the gateway host.
- The operator can authenticate to the gateway VPN backend when TOTP is
  required.

## Signature

```bash
orbit vpn-client:new <name> [--config] [--totp=<code>] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `name` | `<name>` | Always. | Never. | None. | Stable VPN client identifier; unique among backend clients and active Orbit node names. |
| `config` | `--config` | Optional. | Never. | `false` | Includes generated WireGuard config in the command output. |
| `totp` | `--totp=<code>` | Optional. | Never. | Backend configured default when available. | Numeric one-time code accepted by the gateway VPN backend. |
| `json` | `--json` | Optional. | Never. | `false` | Selects the JSON renderer. |

## Behavior Contract

### Client Creation Rules

- Create exactly one non-node admin VPN backend client for `name`.
- Refuse duplicate backend client names.
- Refuse names that match an active Orbit node identity.
- Return the backend client ID and assigned WireGuard address.
- Set `kind=admin` for clients created through this command.
- When `--config` is present, return the generated admin-client WireGuard
  configuration.
- The generated admin-client config must contain exactly one DNS endpoint: the
  WireGuard server interface IP for the assigned peer subnet. In the standard
  `10.6.0.0/24` topology this is `DNS = 10.6.0.1`.
- The generated admin-client config must not use the gateway node peer IP, such
  as `10.6.0.2`, as DNS, and must not include public fallback resolvers such as
  `1.1.1.1`.
- Gateway dnsmasq owns development TLD routing behind that DNS endpoint.

### Node Identity Boundary

- Do not create an Orbit node record.
- Do not create node access grants.
- Do not create an Orbit node identity.
- Do not expose a public `node` client profile.
- Node WireGuard peer creation belongs to `node:new`.

### DNS And Route Boundaries

`vpn-client:new` must not create gateway development DNS mappings,
caller-local DNS overrides, app routes, proxy routes, or Cloudflare records.
The command only returns a client config that points DNS at the WireGuard
server DNS endpoint.

## Renderer Contracts

- [Human renderer](6.1_vpn-client-new_output-render_human.md)
- [JSON renderer](6.2_vpn-client-new_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Gateway SSH unavailable | A control caller cannot execute the gateway-local operation over Orbit/WireGuard SSH. | `error.code=gateway_ssh_unavailable` |
| VPN backend unavailable | The gateway VPN backend is missing, stopped, or unreachable on the gateway host. | `error.code=vpn_backend_unavailable` |
| VPN backend authentication failed | Stored backend credentials or supplied TOTP code are rejected. | `error.code=vpn_backend_auth_failed` |

## Doctor Relationship

`vpn-client:new` creates gateway VPN backend state for non-node clients. It does
not create doctor issues, fix drift, or adopt backend state.
[`doctor --family=node`](../../../1_node/node-doctor.md) owns Orbit node WireGuard
identity and gateway-managed node peer drift.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Vpn/VpnClientNewCommandTest.php` | Command contract: caller-role denial, control-caller gateway-local SSH execution, gateway execution, TOTP handling, duplicate name failure, active node name collision, config inclusion, no node records or grants, and no DNS or proxy side effects. |
| `tests/Feature/Commands/Vpn/VpnClientNewRendererTest.php` | Human and JSON renderer output, config rendering, and every documented `error.code` value. |
| `tests/Unit/Services/Vpn/WgEasyVpnBackendTest.php` | Wg-easy adapter normalization of generated client configs to `DNS = <wireguard-server-ip>`. |
