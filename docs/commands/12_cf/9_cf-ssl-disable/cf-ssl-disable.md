# `orbit cf-ssl:disable`

Set Cloudflare SSL mode to `off` for a zone.

## Usage

```bash
orbit cf-ssl:disable <zone> [--force] [--json]
```

## Examples

```bash
orbit cf-ssl:disable example.com
orbit cf-ssl:disable example.com --force --json
```

## Arguments And Options

- `zone`: Cloudflare zone ID or domain name.
- `--force`: Confirm disabling SSL without an interactive prompt.
- `--json`: Return the SSL result in the shared JSON command envelope.

## What Happens

`cf-ssl:disable` asks the gateway to set the zone's Cloudflare SSL mode to
`off`. This is not a normal Orbit production mode. Use it only for explicit
provider troubleshooting or migration away from Cloudflare SSL.

Disabling SSL is destructive from an availability and security perspective.
Interactive use asks for confirmation unless `--force` is supplied.
Non-interactive use, including `--json`, requires `--force`.

## Output

Human output confirms that Cloudflare SSL was disabled. JSON output returns
`success.data.ssl`.

## Requirements

- The caller can reach the Orbit gateway.
- The caller is authorized for Cloudflare provider administration.
- The gateway has a Cloudflare API token configured.
- The selected zone exists in the Cloudflare account.

## Related Commands

- [`orbit cf-ssl:enable`](../8_cf-ssl-enable/cf-ssl-enable.md)
- [`orbit proxy:list`](../../8_proxy/1_proxy-list/proxy-list.md)
- [Technical contract](technical/1_cf-ssl-disable.md)
