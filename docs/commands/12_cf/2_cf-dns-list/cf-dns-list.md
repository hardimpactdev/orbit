# `orbit cf-dns:list`

List DNS records in a Cloudflare zone.

## Usage

```bash
orbit cf-dns:list <zone> [--json]
```

## Examples

```bash
orbit cf-dns:list example.com
orbit cf-dns:list aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa --json
```

## Arguments And Options

- `zone`: Cloudflare zone ID or domain name.
- `--json`: Return DNS records in the shared JSON command envelope.

## What Happens

`cf-dns:list` asks the gateway to resolve the selected Cloudflare zone and list
provider DNS records in that zone. The command is useful for auditing provider
state before adding or removing Orbit-managed address records.

The command may display non-Orbit records because Cloudflare owns the provider
zone. Listing them does not imply Orbit manages those records.

## Output

Human output renders a DNS record table. JSON output returns
`success.data.records[]`.

## Requirements

- The caller can reach the Orbit gateway.
- The caller is authorized for Cloudflare provider administration.
- The gateway has a Cloudflare API token configured.
- The selected zone exists in the Cloudflare account.

## Related Commands

- [`orbit cf-dns:add`](../3_cf-dns-add/cf-dns-add.md)
- [`orbit cf-dns:remove`](../4_cf-dns-remove/cf-dns-remove.md)
- [Technical contract](technical/1_cf-dns-list.md)
