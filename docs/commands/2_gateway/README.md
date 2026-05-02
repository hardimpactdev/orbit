# Gateway Commands

Gateway commands manage the caller's relationship with an Orbit gateway after
gateway-owned node identity exists.

The gateway command family owns the `gateway:*` command prefix. It does not own
node provisioning, fleet membership, WireGuard peer issuance, or node drift
repair. Those behaviors remain part of the node lifecycle and node doctor
contracts.

## Domain Rules

- Gateway commands must start with the `gateway:` prefix.
- Gateway commands may read gateway-owned node identity and access policy when
  they verify the caller's gateway relationship.
- Gateway commands may write caller-local gateway configuration, trust material,
  and gateway-client metadata.
- Gateway commands must not create gateway node rows, control node rows, app
  node rows, WireGuard peer material, or node access grants.
- First-gateway bootstrap and node identity issuance belong to
  [`node:new`](../1_node/1_node-new/node-new.md).
- Node drift, gateway API reachability drift, and gateway CA mismatch checks
  belong to [`doctor --family=node`](../1_node/node-doctor.md).

## Commands

1. Existing gateway onboarding:
   [`orbit gateway:add [gateway_ip]`](1_gateway-add/gateway-add.md)
2. Local gateway CA trust repair:
   [`orbit gateway:trust`](2_gateway-trust/gateway-trust.md)
