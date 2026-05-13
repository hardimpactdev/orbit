# Technical Contract: `orbit cf-ssl:disable <zone> [--force] [--json]`

[Back to public `cf-ssl:disable` documentation.](../cf-ssl-disable.md)

**Owner:** `cf`.

**Effects:** `destructive`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway, or the command is running on the gateway.
- The current node identity is authorized for Cloudflare provider administration.
- The gateway has a Cloudflare API token configured.

## Signature

```bash
orbit cf-ssl:disable <zone> [--force] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `zone` | Argument `zone` | `Always.` | `Never.` | `None.` | Cloudflare zone ID or exact zone domain name. |
| `force` | `--force` | Required in non-interactive input mode. | `Never.` | `false` | Explicit destructive consent. |
| `json` | `--json` | `Optional.` | `Never.` | `false` | Selects the JSON renderer and non-interactive input mode. |

`cf-ssl:disable` requires destructive consent before side effects. `--json`
selects non-interactive input mode and is never destructive consent.

## Authorization By Caller Role

The CLI forwards every Cloudflare command request to the gateway. The gateway
authenticates the WireGuard peer, derives the caller role, and applies the
gateway-owned authorization policy for Cloudflare provider administration.
Control and gateway callers are authorized. App-node and unknown callers are
denied before prompts or side effects because Cloudflare commands are provider
administration, not app-local runtime work.

## Input Mode Contracts

- [Interactive input mode](5.1_cf-ssl-disable_input-mode_interactive.md)
- [Non-interactive input mode](5.2_cf-ssl-disable_input-mode_non-interactive.md)

## Behavior Contract

### SSL Disable Rules

- Resolves `zone` to a Cloudflare zone ID.
- Sets the Cloudflare SSL mode to `off`.
- Returns the resolved zone and mode in the command result.
- Does not remove origin certificates, proxy TLS artifacts, DNS records, or
  proxy routes.

### Production Safety Boundary

`off` is not an Orbit production target. This command exists for explicit
provider troubleshooting or migration away from Cloudflare SSL. It must require
destructive consent in every non-interactive path.

## Renderer Contracts

- [Human renderer](6.1_cf-ssl-disable_output-render_human.md)
- [JSON renderer](6.2_cf-ssl-disable_output-render_json.md)

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| Validation failed | `zone` lookup or destructive consent validation fails. | `error.code=validation_failed` |
| Caller role not allowed | Caller role is `app` or `unknown`. | `error.code=caller_role_not_allowed` |
| Gateway unavailable | A non-gateway caller cannot reach the gateway API. | `error.code=gateway_unavailable` |
| Authorization failed | The caller is not authorized for Cloudflare provider administration. | `error.code=authorization_failed` |
| Cloudflare unavailable | The gateway token is missing, invalid, or Cloudflare cannot be reached. | `error.code=cloudflare_unavailable` |

## Doctor Relationship

`cf-ssl:disable` may make app or proxy TLS health fail, but it does not create a
Cloudflare doctor family. [`doctor --family=proxy`](../../../8_proxy/proxy-doctor.md)
owns proxy TLS artifact health and [`doctor --family=app`](../../../5_app/app-doctor.md)
owns app-domain health.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Cloudflare/CfSslDisableCommandTest.php` | Command contract for caller-role denial, zone resolution, destructive consent, `--force`, provider authorization, provider failures, and no origin/proxy artifact mutation. |
| `tests/Feature/Commands/Cloudflare/CfSslDisableRendererTest.php` | Human and JSON renderer output, including every documented `error.code` value. |
