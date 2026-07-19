# DNS Commands

DNS commands manage the resolver overrides that Orbit sets on the caller
machine for development DNS.

The DNS command domain is intentionally local. It helps an operator machine route
development hostnames to Orbit-managed targets for browser and CLI use. It does
not create gateway configuration, app routes, proxy routes, Cloudflare records,
public DNS records, node-family record projections, proxy-family private
`.orbit` and exact-backend projections, or DNS tool runtime state.

The node family owns `dnsmasq.d/10-node-records.conf`; the proxy family owns
`dnsmasq.d/20-proxy-records.conf`; and the `dns` tool owns base `dnsmasq.conf`
plus runtime capability. Those are not caller-local DNS entries. The DNS
command family only owns caller-local resolver overrides.

Gateway development DNS infrastructure is deliberately WireGuard-scoped. It
exists so Orbit nodes and configured clients can resolve development
TLDs inside the Orbit network, not as a public recursive resolver. Public
resolver exposure violates the tool-owned listener boundary; it is not a
caller-local `dns:*` concern.

## State Ownership

The DNS command domain does not own a state family. DNS commands mutate
only the resolver configuration on the caller machine.

[`doctor --family=node`](../1_node/node-doctor.md),
[`doctor --family=proxy`](../8_proxy/proxy-doctor.md), and
[`doctor --family=tool`](../3_tool/tool-doctor.md) own their respective record
projections and DNS runtime facts. DNS commands must not create DNS doctor
issues, gateway DNS configuration, private service names, or proxy route
configuration.

## Domain Rules

These rules govern all DNS commands in this family.

- DNS commands affect only the caller machine.
- DNS commands must not create or mutate node/proxy record projections or
  tool-owned DNS base/runtime configuration.
- DNS commands must not create proxy routes, app domains, Cloudflare records, or
  public DNS records.
- DNS write commands require the local OS privileges needed to update resolver
  configuration and refresh the resolver backend.
- Node, proxy, and tool doctor contracts own their respective DNS facts.

## Commands

The DNS family has two commands.

1. [`orbit dns:resolve-tld [tld] [target]`](1_dns-resolve-tld/dns-resolve-tld.md)
2. [`orbit dns:list`](2_dns-list/dns-list.md)
