# VPN Concepts

This document defines VPN-command-domain vocabulary and invariants. It supports
the VPN command contracts; it does not override the
[Architecture](../../ARCHITECTURE.md).

## Domain and execution

These terms define the VPN command domain and how commands reach the gateway.

- **VPN command domain:** Gateway infrastructure command domain for the
  `vpn-client:*` and `vpn-web-ui:*` command prefixes. It administers
  VPN backend clients that are local to the gateway, and the backend admin
  credential, but does not create a `vpn` state family.
- **Gateway-local VPN administration:** The product exception where VPN
  commands must run on the gateway host because the VPN backend is
  gateway-local infrastructure.
- **Gateway-local execution path:** Control-caller path that uses SSH to the
  gateway over Orbit/WireGuard, then runs the VPN command on the gateway.
  It is limited to VPN administration and is not a general public SSH path or
  an app-node orchestration path.
- **Gateway VPN backend:** WireGuard administration backend that runs on the
  gateway, used by VPN commands to read, create, enable, disable, remove, and
  authenticate VPN clients. Backend storage layout and API paths are not the
  product contract.
- **Backend TOTP code:** Numeric one-time code passed to the gateway VPN
  backend when its own second-factor authentication is required. It
  authenticates backend administration; it is not Orbit node identity,
  gateway API authorization, or destructive consent.

## Clients and classification

These terms define how VPN clients are identified and classified.

- **VPN client:** WireGuard client visible to the gateway VPN backend. VPN
  client commands may list all visible backend peers, but writes are limited to
  non-node clients.
- **VPN client name:** Stable VPN client identifier supplied to `vpn-client:*`
  commands. It must be unique among backend clients and must not collide with
  an active Orbit node name.
- **Admin VPN client:** VPN client for a human or operator, created by
  `vpn-client:new`; it is not an Orbit node. It has `kind=admin`, may receive a
  generated WireGuard client configuration, and does not create an Orbit node
  record, Orbit node identity, or node access grant.
- **Orbit node peer:** Backend peer that corresponds to an active Orbit node
  identity. It may be listed as `kind=node`, but it is protected from
  `vpn-client:enable`, `vpn-client:disable`, and `vpn-client:remove`.
- **Unknown VPN peer:** Backend peer that Orbit cannot safely classify as an
  admin VPN client or active Orbit node peer. It is reported as `kind=unknown`
  rather than treated as mutable admin-client state.
- **VPN client kind:** Renderer classification of a backend peer as `admin`,
  `node`, or `unknown`. The classification makes the node-identity boundary
  visible in human and JSON output.
- **WireGuard client configuration:** Generated WireGuard config optionally
  returned by `vpn-client:new --config` for an admin VPN client. It follows
  gateway VPN backend policy, routes DNS to the WireGuard server DNS endpoint,
  and does not include public fallback resolvers. In the standard
  `10.6.0.0/24` topology, the DNS endpoint is `10.6.0.1`; the gateway node's
  own peer address, such as `10.6.0.2`, is not the DNS endpoint.
- **VPN client enablement:** Backend peer enabled/disabled state for non-node
  VPN clients. Toggling it must not alter WireGuard keys, addresses, DNS
  policy, generated configs, Orbit node peers, or node drift.
- **VPN client removal:** Destructive deletion of a non-node backend peer after
  explicit destructive consent. It does not remove local WireGuard config files
  from operator machines and must not remove Orbit node records, grants, or
  active node peers.

## Web UI Credential

These terms cover the gateway VPN backend admin password and credential storage.

- **VPN web UI password:** Gateway VPN backend admin password changed by
  `vpn-web-ui:change-password`. It must never be printed in human output, JSON
  output, progress output, logs, or error metadata.
- **Backend admin credential:** Gateway VPN backend credential used to
  administer the backend. Rotating it is gateway administration, not doctor
  drift, node identity rotation, gateway CA rotation, or gateway API credential
  rotation.
- **Gateway-local credential storage:** Orbit-managed credential storage on the
  gateway that lets later VPN commands authenticate to the backend after a
  password rotation.

## Boundaries

These boundaries define what the VPN command domain owns and what it must not touch.

- **VPN-domain boundaries:** VPN commands own gateway-local VPN backend
  administration for human/operator clients and the VPN web UI credential. They
  do not own a state family, create `doctor --family=vpn`, manage Orbit node
  identity or node peer drift, create node access grants, create app routes,
  proxy routes, Cloudflare records, gateway development DNS mappings, or
  caller-local resolver overrides.
