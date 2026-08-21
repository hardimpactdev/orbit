# Technical Contract: `orbit cf-zone:list [--json]`

[Back to public `cf-zone:list` documentation.](../cf-zone-list.md)

**Owner:** `cf`.

**Effects:** `read`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway, or the command is running on the gateway.
- The current node identity has `cf:zone:list` on the gateway.
- The gateway has a Cloudflare API token configured.

## Signature

```bash
orbit cf-zone:list [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `json` | `--json` | `Optional.` | `Never.` | `false` | Selects the JSON renderer. |

## Behavior Contract

### Provider Visibility Rules

- Reads the Cloudflare zones visible to the gateway's configured API token.
- Returns the provider zone ID, zone name, and provider status for each zone.
- Preserves the provider ordering only when Cloudflare supplies a stable order;
  otherwise renderers may sort by zone name for readability.
- Does not cache the zone list as durable Orbit state.

### Scope Boundaries

`cf-zone:list` must not create, update, remove, adopt, or fix Cloudflare provider state. It must not create Orbit proxy, app, or DNS configuration.

## Renderer Contracts

- [Human renderer](6.1_cf-zone-list_output-render_human.md)
- [JSON renderer](6.2_cf-zone-list_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Cloudflare unavailable | The gateway token is missing, invalid, or Cloudflare cannot be reached. | `error.code=cloudflare_unavailable` |

## Doctor Relationship

Cloudflare provider discovery is supplemental. `cf-zone:list` does not create a
doctor issue, fix drift, or adopt provider state. [`doctor --family=proxy`](../../../8_proxy/proxy-doctor.md)
owns ingress route health and [`doctor --family=instance`](../../../5_app/instance-doctor.md)
owns app-domain health.

## Activity Logging

| Field | Value |
| --- | --- |
| Type | `api:GET /cloudflare/zones` |
| Effect | `read` |
| Subject | The authenticated caller `Node`; `none` before identity resolution. |
| Properties | `zone` and `app` when those selectors apply; record content and credentials are not logged. |
| Description | derived |

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Cloudflare/CloudflareReadCommandsTest.php` | CLI zone list forwarding, human table and empty-state output, and JSON meta count output. |
| `apps/gateway/tests/Feature/Http/Api/CloudflareControllerTest.php` | Gateway zone listing authorization, provider authorization, Cloudflare token failures, and empty zone lists. |

Renderer-specific test mapping lives in:

- [`6.1_cf-zone-list_output-render_human.md`](6.1_cf-zone-list_output-render_human.md#test-mapping)
- [`6.2_cf-zone-list_output-render_json.md`](6.2_cf-zone-list_output-render_json.md#test-mapping)
