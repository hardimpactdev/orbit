# VPN Commands

VPN commands administer the active `vpn` role runtime for operator access
clients. In this version the `vpn` role is gateway-coupled. These commands run
on the gateway host and do not use the normal node orchestration path.

The VPN command family owns the compound `vpn-client:*` and `vpn-web-ui:*`
command prefixes.

## State Ownership

The `vpn` command domain does not own a state family. VPN commands administer
VPN-role runtime clients and the backend admin credential.

[`doctor --family=node`](../1_node/node-doctor.md) owns Orbit node WireGuard
identity, node peers managed by the gateway, WireGuard readiness for the `vpn`
role, node-owned record projection, and stale node-peer drift.
[`doctor --family=proxy`](../8_proxy/proxy-doctor.md) owns private `.orbit` and
exact-backend record projection. [`doctor --family=tool`](../3_tool/tool-doctor.md)
owns DNS base configuration and runtime capability. A node-family restore does
not repair tool-owned DNS drift. There is no `doctor --family=vpn` contract.

## Domain Rules

These rules govern all VPN commands and their gateway-execution contract.

- VPN commands must start with a VPN compound command prefix before the colon,
  such as `vpn-client:*` or `vpn-web-ui:*`.
- In v1 the active `vpn` role is gateway-coupled and runs on the active gateway
  node; commands still resolve the `vpn` role rather than assuming a backend
  from the gateway role name.
- Every public VPN command is gateway-backed and uses the typed gateway HTTPS API over WireGuard. The
  gateway executes the gateway-coupled VPN backend operation locally without a
  node command transport. Gateway API authorization requires `vpn:read` for list operations
  and `vpn:write` for client mutations and web UI password rotation on the
  active gateway node; `gateway-admin` (`*` on the gateway) also satisfies
  those checks.
- `vpn-client:*` commands manage VPN clients for operators, not Orbit node peers.

Node identity is managed separately.

- Orbit node identities are issued through [`node:new`](../1_node/1_node-new/node-new.md)
  and removed through [`node:remove`](../1_node/8_node-remove/node-remove.md),
  not through `vpn-client:new` or `vpn-client:remove`.
- `vpn-client:list` may show backend peers from the runtime for the active
  `vpn` role when those peers correspond to active Orbit nodes.
- Node peers are protected from `vpn-client:enable`, `vpn-client:disable`, and
  `vpn-client:remove`.
- A VPN client name must not collide with an active Orbit node name.
- VPN commands may pass a backend TOTP code.
- They do this only when the backend for the active `vpn` role requires
  second-factor authentication.
- VPN commands do not create app routes, proxy routes, Cloudflare records,
  node- or proxy-owned private DNS projections, tool-owned DNS base/runtime
  state, or caller-local resolver overrides.
- Backend implementation details, such as wg-easy storage layout or API paths,
  are not the product contract.

## VPN JSON Entities

VPN client renderers use this shape for `success.data.client` and
`success.data.clients[]`:

```json
{
  "id": "client-1",
  "name": "laptop",
  "address": "10.6.0.7",
  "enabled": true,
  "latest_handshake_at": "2026-04-26T10:00:00Z",
  "kind": "admin"
}
```

`kind` is `admin` for VPN-admin clients created through this family, `node`
when the backend peer matches an active Orbit node identity, and `unknown` when
the backend cannot classify the peer safely.

Commands that create a client may include a generated WireGuard config when the
operator requests it:

```json
{
  "id": "client-1",
  "name": "laptop",
  "address": "10.6.0.7",
  "enabled": true,
  "latest_handshake_at": null,
  "kind": "admin",
  "config": "[Interface]\n..."
}
```

## Commands

The VPN family provides the following commands.

1. [`orbit vpn-client:list`](1_vpn-client-list/vpn-client-list.md)
2. [`orbit vpn-client:new <name>`](2_vpn-client-new/vpn-client-new.md)
3. [`orbit vpn-client:enable <name>`](3_vpn-client-enable/vpn-client-enable.md)
4. [`orbit vpn-client:disable <name>`](4_vpn-client-disable/vpn-client-disable.md)
5. [`orbit vpn-client:remove <name>`](5_vpn-client-remove/vpn-client-remove.md)
6. [`orbit vpn-web-ui:change-password [password]`](6_vpn-web-ui-change-password/vpn-web-ui-change-password.md)

## Related

The following commands and doctor contracts handle Orbit node identity that the VPN family deliberately does not own.

- [`orbit node:new`](../1_node/1_node-new/node-new.md)
- [`orbit node:remove`](../1_node/8_node-remove/node-remove.md)
- [`doctor --family=node`](../1_node/node-doctor.md)
