# Technical Contract: `orbit vpn-client:new <name> [--config] [--json]`

[Back to public `vpn-client:new` documentation.](../vpn-client-new.md)

**Owner:** `vpn`.

**Effects:** `write`, `gateway-admin`.

**Prerequisites:**
- The local caller role can be resolved according to the foundation
  `general.local_node_role` contract.
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

## Caller Role Behavior

Gateway callers create the backend client locally. Control callers open the
gateway-local execution path over SSH through Orbit/WireGuard, then create the
client on the gateway. App-node and unknown callers are denied before backend
authentication or side effects.

## Input Mode Contracts

No input-mode-specific contracts are required. `name` is a required command
argument and the command does not prompt.

## Behavior Contract

### Client Creation Rules

- Create exactly one non-node admin VPN backend client for `name`.
- Refuse duplicate backend client names.
- Refuse names that match an active Orbit node identity.
- Return the backend client ID and assigned WireGuard address.
- Set `kind=admin` for clients created through this command.
- When `--config` is present, return the generated admin-client WireGuard
  configuration.
- The generated admin-client config follows gateway VPN backend policy and may
  route client traffic through the gateway and use gateway DNS.

### Node Identity Boundary

- Do not create an Orbit node record.
- Do not create node access grants.
- Do not create an Orbit node identity.
- Do not expose a public `node` client profile.
- Node WireGuard peer creation belongs to `node:new`.

### DNS And Route Boundaries

`vpn-client:new` must not create gateway development DNS mappings,
caller-local DNS overrides, app routes, proxy routes, or Cloudflare records.

## Renderer Contracts

- [Human renderer](6.1_vpn-client-new_output-render_human.md)
- [JSON renderer](6.2_vpn-client-new_output-render_json.md)

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| Caller role not allowed | Caller role is `app` or `unknown`. | `error.code=caller_role_not_allowed` |
| Validation failed | `name` is missing, malformed, already exists, or collides with an active Orbit node name. | `error.code=validation_failed` |
| Gateway SSH unavailable | A control caller cannot execute the gateway-local operation over Orbit/WireGuard SSH. | `error.code=gateway_ssh_unavailable` |
| Authorization failed | The caller is not authorized for gateway VPN administration. | `error.code=authorization_failed` |
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
