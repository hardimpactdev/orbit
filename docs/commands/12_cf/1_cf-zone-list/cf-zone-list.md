# `orbit cf-zone:list`

List Cloudflare zones visible to the configured provider account.

## Usage

```bash
orbit cf-zone:list [--json]
```

## Examples

```bash
orbit cf-zone:list
orbit cf-zone:list --json
```

## Arguments and options

- `--json`: Return the zone list in the shared JSON command envelope.

## What Happens

Run `orbit cf-zone:list` to list Cloudflare zones visible to the configured API token.

`cf-zone:list` asks the gateway to read the Cloudflare zones available to the
configured API token. It is an account connectivity and discovery command for
operators before DNS, cache, or SSL provider work.

The command does not create Orbit app domains, DNS records, proxy routes, or
doctor state.

## Output

You will see a zone table with the Cloudflare zone ID, domain name, and provider status.

Human output renders a zone table with Cloudflare zone ID, domain name, and
provider status. JSON output returns `success.data.zones[]`.

## Requirements

- The caller can reach the Orbit gateway.
- The caller is authorized for Cloudflare provider administration.
- The gateway has a Cloudflare API token configured.

## Related Commands

Use these commands for DNS, cache, and SSL work after listing zones.

- [`orbit cf-dns:list`](../2_cf-dns-list/cf-dns-list.md)
- [`orbit cf-cache:flush`](../5_cf-cache-flush/cf-cache-flush.md)
- [Technical contract](technical/1_cf-zone-list.md)
