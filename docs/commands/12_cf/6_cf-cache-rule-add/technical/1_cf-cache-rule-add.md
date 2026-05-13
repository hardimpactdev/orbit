# Technical Contract: `orbit cf-cache-rule:add <app> [--json]`

[Back to public `cf-cache-rule:add` documentation.](../cf-cache-rule-add.md)

**Owner:** `cf`.

**Effects:** `write`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway, or the command is running on the gateway.
- The current node identity is authorized for Cloudflare provider administration.
- The gateway has a Cloudflare API token configured.
- The app exists and has a real domain that resolves to a Cloudflare zone.

## Signature

```bash
orbit cf-cache-rule:add <app> [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `app` | Argument `app` | `Always.` | `Never.` | `None.` | Existing Orbit app name with a Cloudflare-backed real domain. |
| `json` | `--json` | `Optional.` | `Never.` | `false` | Selects the JSON renderer. |

## Behavior Contract

### App Zone Resolution Rules

- Resolves `app` from gateway app state.
- Resolves the app's real domain to a Cloudflare zone.
- Fails before provider mutation when the app has no real Cloudflare-backed
  domain.

### Cache Rule Rules

- Creates or converges the Cloudflare cache settings ruleset for the zone.
- Installs a rule that enables cache settings and respects origin
  `Cache-Control` headers.
- Treats an equivalent existing Orbit-managed rule as successful convergence.
- Does not create path-specific custom cache behavior.

### Scope Boundaries

`cf-cache-rule:add` writes Cloudflare provider cache policy only. It must not
mutate app deployment steps, app process state, proxy routes, or DNS records.

## Renderer Contracts

- [Human renderer](6.1_cf-cache-rule-add_output-render_human.md)
- [JSON renderer](6.2_cf-cache-rule-add_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Cloudflare unavailable | The gateway token is missing, invalid, or Cloudflare cannot be reached. | `error.code=cloudflare_unavailable` |

## Doctor Relationship

`cf-cache-rule:add` may support app performance policy, but it does not create a
Cloudflare doctor family. App-domain and deployment health remain owned by
[`doctor --family=app`](../../../5_app/app-doctor.md). Ingress route health
remains owned by [`doctor --family=proxy`](../../../8_proxy/proxy-doctor.md).

## Test Mapping

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Cloudflare/CfCacheRuleAddCommandTest.php` | Command contract for caller-role denial, app lookup, zone resolution, cache rule convergence, provider authorization, provider failures, and no app/proxy mutations. |
| `tests/Feature/Commands/Cloudflare/CfCacheRuleAddRendererTest.php` | Human and JSON renderer output, including every documented `error.code` value. |
