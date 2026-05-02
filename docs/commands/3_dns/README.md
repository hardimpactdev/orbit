# DNS Commands

DNS commands manage caller-local development DNS resolver overrides.

The DNS command domain is intentionally local. It helps a control machine route
development hostnames to Orbit-managed targets for browser and CLI use. It does
not create gateway intent, app routes, proxy routes, Cloudflare records, public
DNS records, or gateway-owned development DNS mappings.

Gateway-owned development DNS mappings are created by node/app provisioning and
verified through node-family doctor behavior. The DNS command family only owns
caller-local resolver overrides.

## Domain Rules

- DNS commands affect only the caller machine.
- DNS commands must not create or mutate gateway-owned DNS intent.
- DNS commands must not create proxy routes, app domains, Cloudflare records, or
  public DNS records.
- DNS write commands require the local OS privileges needed to update resolver
  configuration and refresh the resolver backend.
- Node-family doctor contracts own gateway-provisioned development TLD
  readiness and app-node resolver drift.

## Commands

1. [`orbit dns:resolve-tld [tld] [target]`](1_dns-resolve-tld/dns-resolve-tld.md)
2. [`orbit dns:list`](2_dns-list/dns-list.md)
