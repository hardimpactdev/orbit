# Technical Contract: `orbit cf-dns:remove <record-id> --zone=<zone> [--force] [--json]`

[Back to public `cf-dns:remove` documentation.](../cf-dns-remove.md)

**Owner:** `cf`.

**Effects:** `destructive`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway, or the command is running on the gateway.
- The current node identity has `cf:dns:remove` on the gateway.
- The gateway has a Cloudflare API token configured.

## Signature

```bash
orbit cf-dns:remove <record-id> --zone=<zone> [--force] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `record_id` | Argument `record-id` | `Always.` | `Never.` | `None.` | Existing Cloudflare DNS record ID. |
| `zone` | `--zone` | `Always.` | `Never.` | `None.` | Cloudflare zone ID or exact zone domain name. |
| `force` | `--force` | Required in non-interactive input mode. | `Never.` | `false` | Explicit destructive consent. |
| `json` | `--json` | `Optional.` | `Never.` | `false` | Selects the JSON renderer and non-interactive input mode. |

`cf-dns:remove` requires destructive consent before side effects. `--json`
selects non-interactive input mode and is never destructive consent.

## Input Mode Contracts

- [Interactive input mode](5.1_cf-dns-remove_input-mode_interactive.md)
- [Non-interactive input mode](5.2_cf-dns-remove_input-mode_non-interactive.md)

## Behavior Contract

### Record Eligibility Rules

- Resolves `zone` to a Cloudflare zone ID before provider mutation.
- Fetches or verifies the selected record metadata before removal when the
  provider API makes that practical.
- Removes only `A` and `AAAA` address records.
- Refuses to remove CNAME, TXT, MX, CAA, SRV, or other non-address records.

### Provider Mutation Rules

- Sends one Cloudflare DNS record delete request for the resolved zone and
  record ID.
- Returns the removed record ID and resolved zone in the command result.
- Does not delete Orbit proxy routes, instance domains, local DNS resolver overrides, or gateway-owned DNS configuration.

## Renderer Contracts

- [Human renderer](6.1_cf-dns-remove_output-render_human.md)
- [JSON renderer](6.2_cf-dns-remove_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Cloudflare unavailable | The gateway token is missing, invalid, or Cloudflare cannot be reached. | `error.code=cloudflare_unavailable` |

## Doctor Relationship

`cf-dns:remove` may remove provider DNS used by proxy or app hostnames, but it
does not create a Cloudflare doctor family. [`doctor --family=proxy`](../../../8_proxy/proxy-doctor.md)
owns ingress route health and [`doctor --family=instance`](../../../5_app/instance-doctor.md)
owns app-domain health.

## Activity Logging

| Field | Value |
| --- | --- |
| Type | `api:DELETE /cloudflare/zones/{zone}/dns/{record}` |
| Effect | `destructive` |
| Subject | The authenticated gateway `Node`; `none` before identity resolution. |
| Properties | `zone` and `app` when those selectors apply; record content and credentials are not logged. |
| Description | derived |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Cloudflare/CloudflareWriteCommandsTest.php` | JSON missing-`--force` validation, forced DELETE forwarding with `destructive_consent`, and removed-record success envelope. |
| `apps/cli/tests/Feature/Commands/Cloudflare/CloudflareRenderCommandsTest.php` | Human removal progress tree and success output with `--force`. |

There is no gateway-side coverage for this command contract: authorization denial, zone resolution, address-record eligibility, provider authorization, provider failures, and guards that prove Orbit configuration is not deleted incorrectly remain coverage gaps. CLI forwarding and renderer output are covered by the linked CLI tests above.

Input-mode-specific test mapping lives in:

- [`5.1_cf-dns-remove_input-mode_interactive.md`](5.1_cf-dns-remove_input-mode_interactive.md#test-mapping)
- [`5.2_cf-dns-remove_input-mode_non-interactive.md`](5.2_cf-dns-remove_input-mode_non-interactive.md#test-mapping)

Renderer-specific test mapping lives in:

- [`6.1_cf-dns-remove_output-render_human.md`](6.1_cf-dns-remove_output-render_human.md#test-mapping)
- [`6.2_cf-dns-remove_output-render_json.md`](6.2_cf-dns-remove_output-render_json.md#test-mapping)
