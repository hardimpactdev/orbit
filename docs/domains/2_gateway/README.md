# Gateway Commands

Gateway commands manage the caller's relationship with an Orbit gateway after
a node identity that the gateway owns has been established.

The gateway command family owns the `gateway:*` command prefix. It does not own
node provisioning, fleet membership, WireGuard peer issuance, or node drift
repair. Those behaviors remain part of the node lifecycle and node doctor
contracts.

## State Ownership

The gateway command domain does not own a state family. Gateway commands manage
the caller's client-side relationship with gateway-owned node identity, gateway
trust material, and gateway API access policy.

The gateway root CA is the trust anchor for Orbit-managed HTTPS inside the
Orbit network. The gateway owns root CA private material and route certificate
issuance. App, workspace, proxy, gateway, and tool route domains may receive
leaf certificate material that the gateway issues on their serving nodes, but route
applying and route doctor families own those artifacts. Gateway commands only
install or repair caller-local trust for the public root.

[`doctor --family=node`](../1_node/node-doctor.md) owns gateway API readiness,
gateway node identity, WireGuard identity, gateway CA mismatch checks, and node
drift repair. Gateway commands may repair caller-local gateway trust, but they
do not create a gateway doctor family.

The gateway API runtime is the gateway `orbit-runtime` container, exposed on
the Orbit network through the gateway `orbit-caddy` container. Gateway commands
verify and trust that API; they do not install host PHP, host Caddy, or
PHP-FPM gateway fallbacks.

## Domain Rules

These rules apply to all gateway commands and define the invariants the family enforces.

- Gateway commands must start with the `gateway:` prefix.
- Gateway commands may read gateway-owned node identity and access policy when
  they verify the caller's gateway relationship.
- Gateway commands may write caller-local gateway configuration, trust material,
  and gateway-client metadata.
- Gateway commands may install or refresh local trust for the gateway root CA,
  which is the root that Orbit-managed route certificates chain to.
- Gateway commands must not create gateway node rows, client rows, app
  node rows, WireGuard peer material, or node access grants.
- Gateway commands must not issue, upload, renew, or clean up TLS leaf certificates
  that are scoped to a route; that belongs to the route-owning domain and its doctor
  family.
- First-gateway bootstrap and node identity issuance belong to
  [`node:new`](../1_node/1_node-new/node-new.md).
- Node drift, gateway API reachability drift, and gateway CA mismatch checks
  belong to [`doctor --family=node`](../1_node/node-doctor.md).

## Commands

These are the two gateway-family commands available to client callers.

1. Existing gateway onboarding:
   [`orbit gateway:add [gateway_ip]`](1_gateway-add/gateway-add.md)
2. Local gateway CA trust repair:
   [`orbit gateway:trust`](2_gateway-trust/gateway-trust.md)
