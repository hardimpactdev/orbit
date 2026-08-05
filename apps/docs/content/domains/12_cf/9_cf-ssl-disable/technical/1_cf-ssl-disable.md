# Technical Contract: `orbit cf-ssl:disable <zone> [--force] [--json]`

[Back to public `cf-ssl:disable` documentation.](../cf-ssl-disable.md)

**Owner:** `cf`.

**Effects:** `destructive`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway, or the command is running on the gateway.
- The current node identity has `cf:ssl:disable` on the gateway.
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
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Cloudflare unavailable | The gateway token is missing, invalid, or Cloudflare cannot be reached. | `error.code=cloudflare_unavailable` |

## Doctor Relationship

`cf-ssl:disable` may make app or proxy TLS health fail, but it does not create a
Cloudflare doctor family. [`doctor --family=proxy`](../../../8_proxy/proxy-doctor.md)
owns proxy TLS artifact health and [`doctor --family=instance`](../../../5_app/instance-doctor.md)
owns app-domain health.

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Cloudflare/CloudflareRenderCommandsTest.php` | Human disable progress tree and success output. |
| `apps/cli/tests/Feature/Commands/Cloudflare/CloudflareWriteCommandsTest.php` | JSON missing-consent `--force` validation and destructive disable gateway forwarding. |

There is no gateway-side coverage for this command contract: authorization denial, zone resolution, provider authorization, provider failures, and origin/proxy artifact mutation checks remain coverage gaps. CLI forwarding and renderer output are covered by the linked CLI tests above.

Input-mode-specific test mapping lives in:

- [`5.1_cf-ssl-disable_input-mode_interactive.md`](5.1_cf-ssl-disable_input-mode_interactive.md#test-mapping)
- [`5.2_cf-ssl-disable_input-mode_non-interactive.md`](5.2_cf-ssl-disable_input-mode_non-interactive.md#test-mapping)

Renderer-specific test mapping lives in:

- [`6.1_cf-ssl-disable_output-render_human.md`](6.1_cf-ssl-disable_output-render_human.md#test-mapping)
- [`6.2_cf-ssl-disable_output-render_json.md`](6.2_cf-ssl-disable_output-render_json.md#test-mapping)
