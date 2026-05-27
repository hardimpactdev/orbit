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
owns ingress route health and [`doctor --family=app`](../../../5_app/app-doctor.md)
owns app-domain health.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Commands/Cloudflare/CfZoneListCommandTest.php` | Command contract for authorization denial, gateway forwarding, provider authorization, Cloudflare token failures, empty zone lists, and no Orbit state writes. |
| `apps/gateway/tests/Feature/Commands/Cloudflare/CfZoneListRendererTest.php` | Human and JSON renderer output, including every documented `error.code` value. |
