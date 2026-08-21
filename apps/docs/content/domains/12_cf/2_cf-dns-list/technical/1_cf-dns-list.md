# Technical Contract: `orbit cf-dns:list <zone> [--json]`

[Back to public `cf-dns:list` documentation.](../cf-dns-list.md)

**Owner:** `cf`.

**Effects:** `read`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway, or the command is running on the gateway.
- The current node identity has `cf:dns:list` on the gateway.
- The gateway has a Cloudflare API token configured.

## Signature

```bash
orbit cf-dns:list <zone> [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `zone` | Argument `zone` | `Always.` | `Never.` | `None.` | Cloudflare zone ID or exact zone domain name. |
| `json` | `--json` | `Optional.` | `Never.` | `false` | Selects the JSON renderer. |

## Behavior Contract

### Zone Resolution Rules

- Accepts a Cloudflare zone ID or exact zone domain name.
- Resolves a zone domain name by reading Cloudflare zones visible to the gateway
  token.
- Fails before DNS record lookup when the zone cannot be resolved.

### Provider Record Visibility

- Reads DNS records from the resolved Cloudflare zone.
- Returns record ID, type, name, content, and proxy status for every provider
  record Cloudflare returns.
- Does not filter the response to Orbit-managed records because the purpose is
  provider-state audit.

### Scope Boundaries

`cf-dns:list` must not create, update, remove, adopt, or fix DNS provider records. It must not create Orbit DNS, app, or proxy configuration.

## Renderer Contracts

- [Human renderer](6.1_cf-dns-list_output-render_human.md)
- [JSON renderer](6.2_cf-dns-list_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Cloudflare unavailable | The gateway token is missing, invalid, or Cloudflare cannot be reached. | `error.code=cloudflare_unavailable` |

## Doctor Relationship

Cloudflare DNS listing is supplemental provider visibility. It does not create a
doctor issue, fix drift, or adopt provider state. [`doctor --family=proxy`](../../../8_proxy/proxy-doctor.md)
owns ingress route health and [`doctor --family=instance`](../../../5_app/instance-doctor.md)
owns app-domain health.

## Activity Logging

| Field | Value |
| --- | --- |
| Type | `api:GET /cloudflare/zones/{zone}/dns` |
| Effect | `read` |
| Subject | The authenticated gateway `Node`; `none` before identity resolution. |
| Properties | `zone` and `app` when those selectors apply; record content and credentials are not logged. |
| Description | derived |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Cloudflare/CloudflareReadCommandsTest.php` | CLI GET forwarding to `/api/cloudflare/zones/{zone}/dns`, JSON success envelope with zone metadata, required-zone validation before gateway contact, `authorization_failed` passthrough, and `gateway_unreachable_wireguard` surfacing. |

There is no gateway-side coverage for this command contract: authorization denial at the gateway API, zone resolution, provider authorization, provider failures, and guards that prove Orbit state is not written remain coverage gaps. CLI forwarding and envelope passthrough are covered by the linked CLI test above.

Renderer-specific test mapping lives in:

- [`6.1_cf-dns-list_output-render_human.md`](6.1_cf-dns-list_output-render_human.md#test-mapping)
- [`6.2_cf-dns-list_output-render_json.md`](6.2_cf-dns-list_output-render_json.md#test-mapping)
