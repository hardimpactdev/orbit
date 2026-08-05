# Technical Contract: `orbit cf-cache-rule:remove <app> [--force] [--json]`

[Back to public `cf-cache-rule:remove` documentation.](../cf-cache-rule-remove.md)

**Owner:** `cf`.

**Effects:** `destructive`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway, or the command is running on the gateway.
- The current node identity has `cf:cache:rule:remove` on the gateway.
- The gateway has a Cloudflare API token configured.
- The named app exists and has a Cloudflare-backed `App.domain`.

## Signature

```bash
orbit cf-cache-rule:remove <app> [--force] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `app` | Argument `app` | `Always.` | `Never.` | `None.` | Bare app name. Current resolver uses `App.domain` for the Cloudflare zone. |
| `force` | `--force` | Required in non-interactive input mode. | `Never.` | `false` | Explicit destructive consent. |
| `json` | `--json` | `Optional.` | `Never.` | `false` | Selects the JSON renderer and non-interactive input mode. |

`cf-cache-rule:remove` requires destructive consent before side effects.
`--json` selects non-interactive input mode and is never destructive consent.

## Input Mode Contracts

- [Interactive input mode](5.1_cf-cache-rule-remove_input-mode_interactive.md)
- [Non-interactive input mode](5.2_cf-cache-rule-remove_input-mode_non-interactive.md)

## Behavior Contract

### App Zone Resolution Rules

- Resolves a bare app name and reads `App.domain` through
  `CloudflareZoneResolver` (current implementation).
- Fails before provider mutation when the app is missing or has no
  Cloudflare-backed domain.
- Direction (pending implementation): instance-owned domain resolution via
  dotted `app.instance` selectors.

### Cache Rule Removal Rules

- Removes Orbit's standard Cloudflare cache settings rule for the resolved zone.
- Fails when no matching cache rule managed by Orbit exists.
- Does not remove unrelated Cloudflare rulesets or custom operator rules.

### Scope Boundaries

`cf-cache-rule:remove` mutates Cloudflare provider cache policy only. It must
not remove instance domains, DNS records, proxy routes, deployment steps, or
process state.

## Renderer Contracts

- [Human renderer](6.1_cf-cache-rule-remove_output-render_human.md)
- [JSON renderer](6.2_cf-cache-rule-remove_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Cloudflare unavailable | The gateway token is missing, invalid, or Cloudflare cannot be reached. | `error.code=cloudflare_unavailable` |

## Doctor Relationship

`cf-cache-rule:remove` may affect instance performance policy, but it does not create
a Cloudflare doctor family. Instance-domain and deployment health remain owned by
[`doctor --family=instance`](../../../5_app/instance-doctor.md). Ingress route health
remains owned by [`doctor --family=proxy`](../../../8_proxy/proxy-doctor.md).

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Cloudflare/CloudflareRenderCommandsTest.php` | Human removal progress tree and success output with `--force`. |
| `apps/cli/tests/Feature/Commands/Cloudflare/CloudflareWriteCommandsTest.php` | JSON missing-consent `--force` validation and destructive DELETE forwarding with `destructive_consent`. |

There is no gateway-side coverage for this command contract: authorization denial, app lookup, zone resolution, matching rule removal, missing rule failure, provider authorization, provider failures, and app/proxy mutation guards remain coverage gaps. CLI forwarding and renderer output are covered by the linked CLI tests above.

Input-mode-specific test mapping lives in:

- [`5.1_cf-cache-rule-remove_input-mode_interactive.md`](5.1_cf-cache-rule-remove_input-mode_interactive.md#test-mapping)
- [`5.2_cf-cache-rule-remove_input-mode_non-interactive.md`](5.2_cf-cache-rule-remove_input-mode_non-interactive.md#test-mapping)

Renderer-specific test mapping lives in:

- [`6.1_cf-cache-rule-remove_output-render_human.md`](6.1_cf-cache-rule-remove_output-render_human.md#test-mapping)
- [`6.2_cf-cache-rule-remove_output-render_json.md`](6.2_cf-cache-rule-remove_output-render_json.md#test-mapping)
