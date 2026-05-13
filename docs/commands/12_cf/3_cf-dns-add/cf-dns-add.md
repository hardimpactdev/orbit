# `orbit cf-dns:add`

Create an `A` or `AAAA` DNS address record in Cloudflare.

## Usage

```bash
orbit cf-dns:add <name> <content> [--type=<type>] [--zone=<zone>] [--proxied] [--json]
```

## Examples

```bash
orbit cf-dns:add docs.example.com 203.0.113.10 --zone=example.com --proxied
orbit cf-dns:add docs.example.com 2001:db8::10 --type=AAAA --zone=example.com
orbit cf-dns:add docs.example.com 203.0.113.10 --json
```

## Arguments And Options

- `name`: Fully qualified DNS record name.
- `content`: IPv4 address for `A` records or IPv6 address for `AAAA` records.
- `--type=<type>`: Address record type. Allowed values: `A`, `AAAA`.
  Default: `A`.
- `--zone=<zone>`: Cloudflare zone ID or domain name. When omitted, Orbit tries
  to resolve the zone from `name`.
- `--proxied`: Enable Cloudflare HTTP proxying for the address record.
- `--json`: Return the created or existing record in the shared JSON command envelope.

## What Happens

`cf-dns:add` asks the gateway to create one Cloudflare address record. It is
idempotent when the same zone, name, type, content, and proxy setting already
exist.

The command refuses general DNS administration. It does not create CNAME, TXT, MX, CAA, SRV, or other record types, and it does not create Orbit proxy route configuration.

## Output

Human output confirms the record outcome. JSON output returns
`success.data.record`.

## Requirements

- The caller can reach the Orbit gateway.
- The caller is authorized for Cloudflare provider administration.
- The gateway has a Cloudflare API token configured.
- The selected zone exists in the Cloudflare account.

## Related Commands

- [`orbit cf-dns:list`](../2_cf-dns-list/cf-dns-list.md)
- [`orbit cf-dns:remove`](../4_cf-dns-remove/cf-dns-remove.md)
- [`orbit proxy:add`](../../8_proxy/2_proxy-add/proxy-add.md)
- [Technical contract](technical/1_cf-dns-add.md)
