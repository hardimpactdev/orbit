# Cloudflare Commands

Cloudflare commands let Orbit manage public DNS, cache rules, cache flushes,
and SSL mode through the gateway's configured Cloudflare token. Spec:
[`apps/docs/content/domains/12_cf/`](../../../apps/docs/content/domains/12_cf/).

Cloudflare is for public DNS/CDN concerns. It is separate from gateway-owned
development DNS mappings and caller-local `dns:*` resolver overrides.

## Zones And DNS

```bash
orbit cf-zone:list [--json]
orbit cf-dns:list <zone> [--json]
orbit cf-dns:add <name> <content> [--type=A|AAAA] [--zone=<zone>] [--proxied] [--json]
orbit cf-dns:remove <record-id> --zone=<zone> [--force] [--json]
```

`zone` accepts the zone name or Cloudflare zone id. `cf-dns:add` manages address
records only.

## Cache

```bash
orbit cf-cache:flush [--zone=<zone>] [--json]
orbit cf-cache-rule:add <app> [--json]
orbit cf-cache-rule:remove <app> [--force] [--json]
```

Cache-rule commands operate on Orbit's standard app cache rule, not arbitrary
Cloudflare rule graphs.

## SSL

```bash
orbit cf-ssl:enable <zone> [--mode=<mode>] [--json]
orbit cf-ssl:disable <zone> [--force] [--json]
```

Use the mode accepted by the Cloudflare command contract, usually `full` unless
the app/domain contract says otherwise.
