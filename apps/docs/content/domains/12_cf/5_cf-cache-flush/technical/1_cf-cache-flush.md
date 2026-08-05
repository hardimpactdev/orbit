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
| `zone` | `--zone` or prompt `cf_cache_flush_zone` | Required in non-interactive input mode. | `Never.` | Prompted in interactive input mode. | Zone ID, exact zone domain, or instance selector whose domain maps to a Cloudflare zone. |
| `json` | `--json` | `Optional.` | `Never.` | `false` | Selects the JSON renderer and non-interactive input mode. |

## Input Resolution

1. Resolve `zone` from `--zone`, or from prompt `cf_cache_flush_zone` in
   interactive input mode.
2. If the value resolves to a concrete Orbit instance with an instance-owned
   domain mapped to a Cloudflare zone, use that instance's zone. Bare project
   shorthand is valid only when exactly one instance is visible. Projects store
   no domain.
3. Otherwise resolve the value as a Cloudflare zone ID or exact zone domain
   name.
4. Fail before provider mutation when the zone cannot be resolved.
5. Product contract resolves zones from instance-owned domains only. Projects
   store no domain.

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
state. Instance deployment health remains owned by [`doctor --family=instance`](../../../5_project/instance-doctor.md).
Ingress route health remains owned by [`doctor --family=proxy`](../../../8_proxy/proxy-doctor.md).

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Cloudflare/CloudflareWriteCommandsTest.php` | CLI zone and app resolution, interactive zone prompt, non-interactive missing-zone validation, and gateway forwarding. |
| `apps/cli/tests/Feature/Commands/Cloudflare/CloudflareRenderCommandsTest.php` | Human progress-tree flush output and JSON validation and error rendering. |

There is no gateway-side coverage for this command contract: `CloudflareController::flushCache` has no dedicated routine gateway feature test owner. CLI forwarding, zone resolution, and renderer output are covered by the linked CLI tests above.

Input-mode-specific test mapping lives in:

- [`5.1_cf-cache-flush_input-mode_interactive.md`](5.1_cf-cache-flush_input-mode_interactive.md#test-mapping)
- [`5.2_cf-cache-flush_input-mode_non-interactive.md`](5.2_cf-cache-flush_input-mode_non-interactive.md#test-mapping)

Renderer-specific test mapping lives in:

- [`6.1_cf-cache-flush_output-render_human.md`](6.1_cf-cache-flush_output-render_human.md#test-mapping)
- [`6.2_cf-cache-flush_output-render_json.md`](6.2_cf-cache-flush_output-render_json.md#test-mapping)
