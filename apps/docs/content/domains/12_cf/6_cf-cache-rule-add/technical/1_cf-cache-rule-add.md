# Technical Contract: `orbit cf-cache-rule:add <app> [--json]`

[Back to public `cf-cache-rule:add` documentation.](../cf-cache-rule-add.md)

**Owner:** `cf`.

**Effects:** `write`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway, or the command is running on the gateway.
- The current node identity has `cf:cache:rule:add` on the gateway.
- The gateway has a Cloudflare API token configured.
- The selected instance exists and has a Cloudflare-backed domain.

## Signature

```bash
orbit cf-cache-rule:add <app> [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `app` | Argument `app` | `Always.` | `Never.` | `None.` | Dotted `app.instance` selector, or a bare app name that resolves to exactly one instance. |
| `json` | `--json` | `Optional.` | `Never.` | `false` | Selects the JSON renderer. |

## Behavior Contract

### App Zone Resolution Rules

- Resolves a dotted selector to one concrete instance. A bare app selector is
  accepted only when the app has exactly one instance.
- Reads the selected instance's domain through `CloudflareZoneResolver`.
- Fails before provider mutation when the instance is missing or has no
  Cloudflare-backed domain.

### Cache Rule Rules

- Creates or converges the Cloudflare cache settings ruleset for the zone.
- Installs a rule that enables cache settings and respects origin
  `Cache-Control` headers.
- Treats an equivalent existing Orbit-managed rule as successful convergence.
- Does not create custom cache behavior for specific paths.

### Scope Boundaries

`cf-cache-rule:add` writes Cloudflare provider cache policy only. It must not
mutate instance deployment steps, process state, proxy routes, or DNS records.

## Renderer Contracts

- [Human renderer](6.1_cf-cache-rule-add_output-render_human.md)
- [JSON renderer](6.2_cf-cache-rule-add_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Instance required | A bare app selector has zero or multiple instances. | `error.code=validation_failed`; `error.meta.reason=instance_required`. |
| Cloudflare unavailable | The gateway token is missing, invalid, or Cloudflare cannot be reached. | `error.code=cloudflare_unavailable` |

## Doctor Relationship

`cf-cache-rule:add` may support instance performance policy, but it does not create a
Cloudflare doctor family. Instance-domain and deployment health remain owned by
[`doctor --family=instance`](../../../5_app/instance-doctor.md). Ingress route health
remains owned by [`doctor --family=proxy`](../../../8_proxy/proxy-doctor.md).

## Activity Logging

| Field | Value |
| --- | --- |
| Type | `api:POST /api/cloudflare/cache-rules/{app}` |
| Effect | `write` |
| Subject | The authenticated gateway `Node`; `none` before identity resolution. |
| Properties | `zone` and `app` when those selectors apply; record content and credentials are not logged. |
| Description | derived |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Cloudflare/CloudflareWriteCommandsTest.php` | CLI POST forwarding to `/api/cloudflare/cache-rules/{app}` and JSON success envelope passthrough. |
| `apps/gateway/tests/Feature/Http/Api/CloudflareControllerTest.php` | Bare single-instance resolution, multi-instance rejection, and explicit dotted-instance resolution. |

Cache-rule convergence, provider authorization, provider failures, and
instance/proxy mutation guards remain coverage gaps. CLI forwarding and
envelope passthrough are covered by the linked CLI test above.

Renderer-specific test mapping lives in:

- [`6.1_cf-cache-rule-add_output-render_human.md`](6.1_cf-cache-rule-add_output-render_human.md#test-mapping)
- [`6.2_cf-cache-rule-add_output-render_json.md`](6.2_cf-cache-rule-add_output-render_json.md#test-mapping)
