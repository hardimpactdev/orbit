# DNS Commands

DNS commands manage the resolver overrides that Orbit sets on the caller machine for development DNS.

The DNS command domain is intentionally local. It helps a operator machine route
development hostnames to Orbit-managed targets for browser and CLI use. It does
not create gateway configuration, app routes, proxy routes, Cloudflare records, public
DNS records, or development DNS mappings owned by the gateway.

Development DNS mappings owned by the gateway are created by node/app provisioning and
verified through node-family doctor behavior. The DNS command family only owns
caller-local resolver overrides.

Gateway development DNS infrastructure is deliberately WireGuard-scoped. It
exists so Orbit nodes and configured control machines can resolve development
TLDs inside the Orbit network, not as a public recursive resolver. Public
resolver exposure is node-family drift and is checked by
[`doctor --family=node`](../1_node/node-doctor.md).

## State Ownership

The DNS command domain does not own a state family. DNS commands mutate
only the resolver configuration on the caller machine.

[`doctor --family=node`](../1_node/node-doctor.md) owns development TLD readiness
as provisioned by the gateway, and app-node resolver drift. DNS commands must not
create DNS doctor issues, gateway DNS configuration, or proxy route configuration.

## Domain Rules

These rules govern all DNS commands in this family.

- DNS commands affect only the caller machine.
- DNS commands must not create or mutate gateway-owned DNS configuration.
- DNS commands must not create proxy routes, app domains, Cloudflare records, or
  public DNS records.
- DNS write commands require the local OS privileges needed to update resolver
  configuration and refresh the resolver backend.
- Node-family doctor contracts own development TLD readiness provisioned by the
  gateway, and app-node resolver drift.

## Commands

The DNS family has two commands.

1. [`orbit dns:resolve-tld [tld] [target]`](1_dns-resolve-tld/dns-resolve-tld.md)
2. [`orbit dns:list`](2_dns-list/dns-list.md)
