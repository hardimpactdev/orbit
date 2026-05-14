# Technical Contract: `orbit cf-cache-rule:remove <app> [--force] [--json]`

[Back to public `cf-cache-rule:remove` documentation.](../cf-cache-rule-remove.md)

**Owner:** `cf`.

**Effects:** `destructive`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway, or the command is running on the gateway.
- The current node identity is authorized for Cloudflare provider administration.
- The gateway has a Cloudflare API token configured.
- The app exists and has a real domain that resolves to a Cloudflare zone.

## Signature

```bash
orbit cf-cache-rule:remove <app> [--force] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `app` | Argument `app` | `Always.` | `Never.` | `None.` | Existing Orbit app name with a Cloudflare-backed real domain. |
| `force` | `--force` | Required in non-interactive input mode. | `Never.` | `false` | Explicit destructive consent. |
| `json` | `--json` | `Optional.` | `Never.` | `false` | Selects the JSON renderer and non-interactive input mode. |

`cf-cache-rule:remove` requires destructive consent before side effects.
`--json` selects non-interactive input mode and is never destructive consent.

## Input Mode Contracts

- [Interactive input mode](5.1_cf-cache-rule-remove_input-mode_interactive.md)
- [Non-interactive input mode](5.2_cf-cache-rule-remove_input-mode_non-interactive.md)

## Behavior Contract

### App Zone Resolution Rules

- Resolves `app` from gateway app state.
- Resolves the app's real domain to a Cloudflare zone.
- Fails before provider mutation when the app has no real Cloudflare-backed
  domain.

### Cache Rule Removal Rules

- Removes Orbit's standard Cloudflare cache settings rule for the resolved zone.
- Fails when no matching cache rule managed by Orbit exists.
- Does not remove unrelated Cloudflare rulesets or custom operator rules.

### Scope Boundaries

`cf-cache-rule:remove` mutates Cloudflare provider cache policy only. It must
not remove app domains, DNS records, proxy routes, deployment steps, or app
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

`cf-cache-rule:remove` may affect app performance policy, but it does not create
a Cloudflare doctor family. App-domain and deployment health remain owned by
[`doctor --family=app`](../../../5_app/app-doctor.md). Ingress route health
remains owned by [`doctor --family=proxy`](../../../8_proxy/proxy-doctor.md).

## Test Mapping

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Cloudflare/CfCacheRuleRemoveCommandTest.php` | Command contract for caller-role denial, app lookup, zone resolution, matching rule removal, missing rule failure, destructive consent, `--force`, provider authorization, and no app/proxy mutations. |
| `tests/Feature/Commands/Cloudflare/CfCacheRuleRemoveRendererTest.php` | Human and JSON renderer output, including every documented `error.code` value. |
