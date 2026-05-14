# Cloudflare Commands

Cloudflare commands manage provider-side DNS, cache, and SSL settings for real
Orbit-managed domains. The command domain owns the `cf-*` provider utility
prefixes: `cf-zone:*`, `cf-dns:*`, `cf-cache:*`, `cf-cache-rule:*`, and
`cf-ssl:*`.

Cloudflare is an external provider integration. It is not the canonical Orbit
model for ingress, apps, TLS, or DNS.

## State Ownership

The `cf` command domain does not own a state family. Cloudflare provider state supports app and proxy behavior but does not replace gateway-owned Orbit configuration.

[`doctor --family=proxy`](../8_proxy/proxy-doctor.md) owns Orbit ingress route
health. [`doctor --family=app`](../5_app/app-doctor.md) owns app-domain and
deployment health that may depend on provider-side DNS, cache, or SSL state.
There is no `doctor --family=cf` contract.

## Domain Rules

These rules constrain all Cloudflare commands.

- Cloudflare commands are gateway-admin provider utilities.
- The gateway is the only Orbit node that talks directly to the Cloudflare API.
- Cloudflare API tokens are external secrets stored on the gateway.
- Callers on control nodes invoke Cloudflare commands through the gateway API and
  must be authorized for provider administration.
- App-node callers are denied before prompts or side effects. Cloudflare
  provider administration is not app-local runtime work.
- Cloudflare commands require a real configured domain in a Cloudflare zone.
  Development TLDs and DNS resolver overrides local to the caller are not valid
  Cloudflare targets.
- Cloudflare DNS writes are limited to `A` and `AAAA` records. CNAME, TXT, MX,
  CAA, SRV, and general DNS administration are outside Orbit's current scope.
- [`proxy`](../8_proxy/README.md) is the canonical Orbit ingress registry for Orbit-owned hostnames. Cloudflare DNS records and cache rules are provider-side application, not durable Orbit route configuration.
- `app:new --domain=<host>` and app-owned ingress flows are the normal path for
  Orbit-managed hostname ingress.
- Cache rules created by Orbit tell Cloudflare to respect origin
  `Cache-Control` headers. Routes with `Cache-Control: public` may be cached at
  the edge.
- `cf-ssl:enable` defaults to `strict`. `full` is an explicit migration or
  troubleshooting mode. `flexible` is out of scope because Orbit ingress
  requires encrypted traffic from Cloudflare to the origin.

## Cloudflare JSON Entities

Zone renderers use this shape for `success.data.zones[]`:

```json
{
  "id": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
  "name": "example.com",
  "status": "active"
}
```

DNS record renderers use this shape for `success.data.records[]` and
`success.data.record`:

```json
{
  "id": "record-1",
  "zone": "example.com",
  "type": "A",
  "name": "docs.example.com",
  "content": "203.0.113.10",
  "proxied": true,
  "status": "created"
}
```

Cache and SSL status renderers use command-specific status objects that include
the resolved `zone`, `action`, and any app or mode field needed by the command.

## Commands

These are the commands in the Cloudflare domain.

**Zone and DNS:**

1. [`orbit cf-zone:list`](1_cf-zone-list/cf-zone-list.md)
2. [`orbit cf-dns:list <zone>`](2_cf-dns-list/cf-dns-list.md)
3. [`orbit cf-dns:add <name> <content>`](3_cf-dns-add/cf-dns-add.md)
4. [`orbit cf-dns:remove <record-id> --zone=<zone>`](4_cf-dns-remove/cf-dns-remove.md)

**Cache:**

5. [`orbit cf-cache:flush [--zone=<zone>]`](5_cf-cache-flush/cf-cache-flush.md)
6. [`orbit cf-cache-rule:add <app>`](6_cf-cache-rule-add/cf-cache-rule-add.md)
7. [`orbit cf-cache-rule:remove <app>`](7_cf-cache-rule-remove/cf-cache-rule-remove.md)

**SSL:**

8. [`orbit cf-ssl:enable <zone>`](8_cf-ssl-enable/cf-ssl-enable.md)
9. [`orbit cf-ssl:disable <zone>`](9_cf-ssl-disable/cf-ssl-disable.md)

## Related

- [`orbit proxy:*`](../8_proxy/README.md)
- [`orbit app:*`](../5_app/README.md)
- [`orbit dns:*`](../16_dns/README.md)
