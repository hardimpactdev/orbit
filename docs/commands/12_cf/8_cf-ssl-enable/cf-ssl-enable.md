# `orbit cf-ssl:enable`

Set Cloudflare SSL mode for a zone.

## Usage

```bash
orbit cf-ssl:enable <zone> [--mode=<mode>] [--json]
```

## Examples

```bash
orbit cf-ssl:enable example.com
orbit cf-ssl:enable example.com --mode=full
orbit cf-ssl:enable aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa --json
```

## Arguments and options

- `zone`: Cloudflare zone ID or domain name.
- `--mode=<mode>`: Cloudflare SSL mode. Allowed values: `strict`, `full`.
  Default: `strict`.
- `--json`: Return the SSL result in the shared JSON command envelope.

## What Happens

Run `orbit cf-ssl:enable <zone>` to set the Cloudflare SSL mode for the zone.

`cf-ssl:enable` asks the gateway to set the zone's Cloudflare SSL mode.
`strict` is the normal Orbit target because Cloudflare validates the origin TLS
certificate. `full` is available for migration or troubleshooting when the
origin serves HTTPS but strict validation is not ready.

`flexible` is not supported because Orbit ingress requires encrypted traffic from Cloudflare to the origin.

## Output

You will see a confirmation of the SSL mode set for the zone.

Human output confirms the selected mode. JSON output returns
`success.data.ssl`.

## Requirements

**Access:**

- The caller can reach the Orbit gateway.
- The caller is authorized for Cloudflare provider administration.
- The gateway has a Cloudflare API token configured.
- The selected zone exists in the Cloudflare account.

**Mode-specific:**

- `strict` mode requires valid origin certificates for the affected hostnames.

## Related Commands

Use these commands to disable SSL, trust the gateway certificate, or configure proxy routes.

- [`orbit cf-ssl:disable`](../9_cf-ssl-disable/cf-ssl-disable.md)
- [`orbit gateway:trust`](../../2_gateway/2_gateway-trust/gateway-trust.md)
- [`orbit proxy:add`](../../8_proxy/2_proxy-add/proxy-add.md)
- [Technical contract](technical/1_cf-ssl-enable.md)
