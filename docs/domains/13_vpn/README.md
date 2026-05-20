# VPN Commands

VPN commands administer the gateway's WireGuard backend for human/operator
access clients. They are gateway infrastructure commands, not the normal node
orchestration path.

The VPN command family owns the compound `vpn-client:*` and `vpn-web-ui:*`
command prefixes.

## State Ownership

The `vpn` command domain does not own a state family. VPN commands administer
gateway-local backend clients and the backend admin credential.

[`doctor --family=node`](../1_node/node-doctor.md) owns Orbit node WireGuard
identity, gateway-managed node peers, gateway WireGuard readiness, and stale
node-peer drift. There is no `doctor --family=vpn` contract.

## Domain Rules

These rules govern all VPN commands and their gateway-execution contract.

- VPN commands must start with a VPN compound command prefix before the colon,
  such as `vpn-client:*` or `vpn-web-ui:*`.
- VPN commands execute on the gateway host because the VPN backend is
  gateway-local infrastructure.
- Gateway callers execute the backend operation locally.
- Operator callers may initiate VPN commands only when they can SSH to the
  gateway over the Orbit/WireGuard path. This is a gateway infrastructure
  exception and does not create a general public SSH path from control to gateway.
- App-role callers are denied before prompts or side effects.
- `vpn-client:*` commands manage VPN clients for humans and operators, not Orbit node peers.

Node identity is managed separately.

- Orbit node identities are issued through [`node:new`](../1_node/1_node-new/node-new.md)
  and removed through [`node:remove`](../1_node/8_node-remove/node-remove.md),
  not through `vpn-client:new` or `vpn-client:remove`.
- `vpn-client:list` may show gateway backend peers that correspond to active
  Orbit nodes, but node peers are protected from `vpn-client:enable`,
  `vpn-client:disable`, and `vpn-client:remove`.
- A VPN client name must not collide with an active Orbit node name.
- VPN commands may pass a backend TOTP code when the gateway backend requires
  second-factor authentication.
- VPN commands do not create app routes, proxy routes, Cloudflare records,
  gateway development DNS mappings, or caller-local resolver overrides.
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
