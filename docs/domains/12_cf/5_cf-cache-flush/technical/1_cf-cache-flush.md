# Technical Contract: `orbit cf-cache:flush [--zone=<zone>] [--json]`

[Back to public `cf-cache:flush` documentation.](../cf-cache-flush.md)

**Owner:** `cf`.

**Effects:** `write`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway, or the command is running on the gateway.
- The current node identity has `cf:cache:flush` on the gateway.
- The gateway has a Cloudflare API token configured.

## Signature

```bash
orbit cf-cache:flush [--zone=<zone>] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `zone` | `--zone` or prompt `cf_cache_flush_zone` | Required in non-interactive input mode. | `Never.` | Prompted in interactive input mode. | Cloudflare zone ID, exact zone domain name, or Orbit app name with a configured Cloudflare zone. |
| `json` | `--json` | `Optional.` | `Never.` | `false` | Selects the JSON renderer and non-interactive input mode. |

## Input Resolution

1. Resolve `zone` from `--zone`, or from prompt `cf_cache_flush_zone` in
   interactive input mode.
2. If the value matches an Orbit app name with a configured Cloudflare zone,
   use that app's zone.
3. Otherwise resolve the value as a Cloudflare zone ID or exact zone domain
   name.
4. Fail before provider mutation when the zone cannot be resolved.

## Input Mode Contracts

- [Interactive input mode](5.1_cf-cache-flush_input-mode_interactive.md)
- [Non-interactive input mode](5.2_cf-cache-flush_input-mode_non-interactive.md)

## Behavior Contract

### Cache Purge Rules

- Sends one Cloudflare purge request for the resolved zone.
- Purges all cached files for the zone.
- Returns the resolved zone and `action=flush`.
- Does not create or remove cache rules.

### Scope Boundaries

`cf-cache:flush` mutates provider cache state only. It must not deploy apps,
restart services, mutate proxy routes, or mutate app deployment history.

## Renderer Contracts

- [Human renderer](6.1_cf-cache-flush_output-render_human.md)
- [JSON renderer](6.2_cf-cache-flush_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Cloudflare unavailable | The gateway token is missing, invalid, or Cloudflare cannot be reached. | `error.code=cloudflare_unavailable` |

## Doctor Relationship

`cf-cache:flush` does not create a doctor issue, fix drift, or adopt provider
state. App deployment health remains owned by [`doctor --family=app`](../../../5_app/app-doctor.md).
Ingress route health remains owned by [`doctor --family=proxy`](../../../8_proxy/proxy-doctor.md).

## Test Mapping

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Cloudflare/CfCacheFlushCommandTest.php` | Command contract for authorization denial, zone resolution, app-name resolution, provider authorization, provider failures, and no deploy or proxy mutations. |
| `tests/Feature/Commands/Cloudflare/CfCacheFlushInputModeTest.php` | Interactive zone prompt and non-interactive missing-zone failure. |
| `tests/Feature/Commands/Cloudflare/CfCacheFlushRendererTest.php` | Human and JSON renderer output, including every documented `error.code` value. |
