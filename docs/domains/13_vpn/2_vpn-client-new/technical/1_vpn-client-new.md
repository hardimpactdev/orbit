# Technical Contract: `orbit vpn-client:new <name> [--config] [--json]`

[Back to public `vpn-client:new` documentation.](../vpn-client-new.md)

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
orbit vpn-client:new <name> [--config] [--totp=<code>] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `name` | `<name>` | Always. | Never. | None. | Stable VPN client identifier; unique among backend clients and active Orbit node names. |
| `config` | `--config` | Optional. | Never. | `false` | Includes generated WireGuard config in the command output. |
| `totp` | `--totp=<code>` | Optional. | Never. | Backend configured default when available. | Numeric one-time code accepted by the VPN runtime backend. |
| `json` | `--json` | Optional. | Never. | `false` | Selects the JSON renderer. |

## Behavior Contract

### Client Creation Rules

- Create exactly one admin VPN backend client for `name`; the client is not an Orbit node.
- Resolve the active `vpn` role host before backend writes begin.
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
- The gateway owns desired development/agent DNS mappings and DNS policy behind
  that endpoint. In v1 the `vpn` role DNS runtime serves and materializes those
  mappings. Stable private `.orbit` service names are router-owned service
  contracts and are not created by `vpn-client:new`.

### Node Identity Boundary

- Do not create an Orbit node record.
- Do not create node access grants.
- Do not create an Orbit node identity.
- Do not expose a public `node` client profile.
- Node WireGuard peer creation belongs to `node:new`.

### DNS and route boundaries

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
| VPN runtime unavailable | No active `vpn` role node exists for VPN administration. | `error.code=vpn_runtime_unavailable` |
| VPN runtime SSH unavailable | A non-gateway caller cannot execute the VPN-role runtime operation over Orbit/WireGuard SSH on the active `vpn` role node. | `error.code=vpn_runtime_ssh_unavailable` |
| VPN backend unavailable | The VPN runtime backend is missing, stopped, or unreachable on the active `vpn` role host. | `error.code=vpn_backend_unavailable` |
| VPN backend authentication failed | Stored backend credentials or supplied TOTP code are rejected. | `error.code=vpn_backend_auth_failed` |

## Doctor Relationship

`vpn-client:new` creates VPN runtime backend state for non-node clients. It does
not create doctor issues, fix drift, or adopt backend state.
[`doctor --family=node`](../../../1_node/node-doctor.md) owns Orbit node WireGuard
identity and node peer drift that the gateway manages through the active `vpn`
role.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Vpn/VpnClientNewCommandTest.php` | Command contract: grant denial, SSH execution to the active `vpn` role node, TOTP handling, duplicate name failure, node name collision, config inclusion. No node records, grants, DNS, or proxy side effects. |
| `tests/Feature/Commands/Vpn/VpnClientNewRendererTest.php` | Human and JSON renderer output, config rendering, and every documented `error.code` value. |
| `tests/Unit/Services/Vpn/WgEasyVpnBackendTest.php` | Wg-easy adapter normalization of generated client configs to `DNS = <wireguard-server-ip>`. |
