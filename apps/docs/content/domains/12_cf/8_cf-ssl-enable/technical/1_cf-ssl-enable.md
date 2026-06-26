# Technical Contract: `orbit cf-ssl:enable <zone> [--mode=<mode>] [--json]`

[Back to public `cf-ssl:enable` documentation.](../cf-ssl-enable.md)

**Owner:** `cf`.

**Effects:** `write`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway, or the command is running on the gateway.
- The current node identity has `cf:ssl:enable` on the gateway.
- The gateway has a Cloudflare API token configured.

## Signature

```bash
orbit cf-ssl:enable <zone> [--mode=<mode>] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `zone` | Argument `zone` | `Always.` | `Never.` | `None.` | Cloudflare zone ID or exact zone domain name. |
| `mode` | `--mode` | `Optional.` | `Never.` | `strict` | `strict` or `full`. |
| `json` | `--json` | `Optional.` | `Never.` | `false` | Selects the JSON renderer. |

## Behavior Contract

### SSL Mode Rules

- Resolves `zone` to a Cloudflare zone ID.
- Sets the Cloudflare SSL mode to `strict` by default.
- Allows `full` only when explicitly requested.
- Refuses `flexible`, `off`, and any other mode through validation failure.
- Does not create origin certificates or proxy TLS artifacts.

### Origin Certificate Boundary

Strict mode requires Cloudflare to validate the origin certificate. The command
does not synchronously verify every hostname after the provider setting is
changed; hostname and proxy TLS health remain app/proxy doctor concerns.

## Renderer Contracts

- [Human renderer](6.1_cf-ssl-enable_output-render_human.md)
- [JSON renderer](6.2_cf-ssl-enable_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Cloudflare unavailable | The gateway token is missing, invalid, or Cloudflare cannot be reached. | `error.code=cloudflare_unavailable` |

## Doctor Relationship

`cf-ssl:enable` changes provider SSL mode, but it does not create a Cloudflare
doctor family. [`doctor --family=proxy`](../../../8_proxy/proxy-doctor.md) owns
proxy TLS artifact health and [`doctor --family=app`](../../../5_app/app-doctor.md)
owns app-domain health.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Cloudflare/CloudflareRenderCommandsTest.php` | Human progress tree, default strict-mode success output, and JSON renderer selection for a strict success envelope. |
| `apps/cli/tests/Feature/Commands/Cloudflare/CloudflareWriteCommandsTest.php` | CLI `cf-ssl:enable` PUT forwarding for explicit full-mode payloads. |

There is no gateway-side coverage for this command contract: authorization denial, zone resolution, provider authorization, provider failures, and refusal of flexible mode remain coverage gaps. No linked test proves that origin certificates stay unchanged. CLI rendering and forwarding behavior are covered by the linked CLI tests above.

Renderer-specific test mapping lives in:

- [`6.1_cf-ssl-enable_output-render_human.md`](6.1_cf-ssl-enable_output-render_human.md#test-mapping)
- [`6.2_cf-ssl-enable_output-render_json.md`](6.2_cf-ssl-enable_output-render_json.md#test-mapping)
