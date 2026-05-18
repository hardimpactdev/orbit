# Cloudflare Concepts

This document defines Cloudflare-command-domain vocabulary and invariants. It
supports the Cloudflare command contracts; it does not override the
[Architecture](../../architecture.md).

## Domain and provider authority

These terms define the Cloudflare command domain and its relationship to the gateway.

- **Cloudflare command domain:** Provider utility command domain for the
  `cf-zone:*`, `cf-dns:*`, `cf-cache:*`, `cf-cache-rule:*`, and `cf-ssl:*`
  command prefixes. It manages provider-side DNS, cache, and SSL settings but
  does not create a `cf` state family.
- **Cloudflare provider integration:** External DNS/CDN provider integration used for real production domains. It supports app and proxy behavior. It does not replace gateway-owned Orbit app, proxy, TLS, or DNS configuration.
- **Provider administration:** Gateway-admin workflow that reads or mutates
  Cloudflare provider state through the gateway. Operator callers must go through
  the gateway; app-node callers are denied before prompts or side effects.
- **Cloudflare API token:** External provider secret stored on the gateway as
  `CLOUDFLARE_API_TOKEN` and used only by the gateway when calling the
  Cloudflare API. `CLOUDFLARE_API_EMAIL` is optional compatibility for
  Cloudflare global API key authentication.
- **Real Cloudflare-backed domain:** Public domain that belongs to a
  Cloudflare zone visible to the gateway token. Development TLDs and
  DNS overrides local to the caller are not Cloudflare targets.

## Zones and DNS

These terms describe Cloudflare zones and DNS record management.

- **Cloudflare zone:** Provider zone visible to the gateway's configured API
  token. Commands may resolve a zone by provider zone ID or exact zone domain
  name.
- **Provider DNS record:** DNS record stored in Cloudflare provider state. CF DNS list commands expose provider records for audit and do not treat them as Orbit DNS configuration.
- **Address record:** Cloudflare `A` or `AAAA` record. CF DNS write commands
  are limited to address records; CNAME, TXT, MX, CAA, SRV, and broad DNS
  administration are outside the current Orbit CF scope.
- **Proxied DNS record:** Provider DNS record whose Cloudflare proxy flag is enabled. Orbit passes the requested proxied setting to Cloudflare. Orbit ingress configuration remains in the proxy/app domains.
- **Provider DNS application:** Cloudflare DNS state change that may support an Orbit app or proxy hostname. It is provider-side application, not durable Orbit route, app, or DNS configuration.

## Cache

These terms describe Cloudflare cache operations and rules.

- **Provider cache purge:** Cloudflare cache mutation that purges cached files
  for a resolved zone. It does not deploy apps, mutate proxy routes, or repair
  app/runtime drift.
- **Cloudflare cache rule:** Provider cache settings rule created for an app's
  real Cloudflare-backed domain.
- **Origin Cache-Control respect:** Cache-rule behavior where Cloudflare honors
  origin `Cache-Control` headers, allowing routes with public cache headers to
  be cached at the edge.

## SSL

These terms describe Cloudflare SSL modes and their product boundaries.

- **Cloudflare SSL mode:** Provider zone SSL setting managed by `cf-ssl:*`.
  `strict` is the default Orbit target, `full` is explicit migration or
  troubleshooting mode, and `off` is destructive troubleshooting or migration
  away from Cloudflare SSL.
- **Strict SSL mode:** Cloudflare mode that requires encrypted
  Cloudflare-to-origin traffic and validates the origin certificate.
- **Full SSL mode:** Cloudflare mode that keeps encrypted Cloudflare-to-origin
  traffic but is less strict than certificate-validating strict mode. It is
  allowed only when explicitly requested.
- **Flexible SSL exclusion:** Product boundary that rejects Cloudflare flexible
  SSL because Orbit ingress requires encrypted traffic from Cloudflare to the
  origin.
- **Origin certificate boundary:** CF SSL commands change provider SSL mode but
  do not create origin certificates, proxy TLS artifacts, DNS records, or Orbit
  proxy routes.

## Boundaries

These terms define what Cloudflare commands own and exclude.

- **Cloudflare-domain boundaries:** Cloudflare commands own gateway-mediated
  provider visibility and provider-side DNS, cache, and SSL mutations for real
  Cloudflare-backed domains.
- **Cloudflare-domain exclusions:** Cloudflare commands do not own a state
  family, create Orbit app domains or proxy routes, replace `proxy` as the
  canonical ingress registry, manage development TLDs, store provider records
  as Orbit configuration, or create a `doctor --family=cf` contract.
