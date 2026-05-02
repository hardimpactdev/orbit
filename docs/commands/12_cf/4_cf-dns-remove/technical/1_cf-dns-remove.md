# Technical Contract: `orbit cf-dns:remove <record-id> --zone=<zone> [--force] [--json]`

[Back to public `cf-dns:remove` documentation.](../cf-dns-remove.md)

**Owner:** `cf`.

**Effects:** `destructive`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway, or the command is running on the gateway.
- The local caller role can be resolved according to the foundation `general.local_node_role` contract.
- The current node identity is authorized for Cloudflare provider administration.
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

## Caller Role Behavior

Control callers send the command request to the gateway. Gateway callers
execute the provider request directly. App-node and unknown callers are denied
before prompts or side effects because Cloudflare commands are provider
administration, not app-local runtime work. Authorized control and gateway
callers use the gateway-owned authorization policy for Cloudflare provider
administration.

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
- Does not delete Orbit proxy routes, app domains, local DNS resolver
  overrides, or gateway-owned DNS intent.

## Renderer Contracts

- [Human renderer](6.1_cf-dns-remove_output-render_human.md)
- [JSON renderer](6.2_cf-dns-remove_output-render_json.md)

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| Validation failed | `record-id`, `zone`, destructive consent, or address-record eligibility validation fails. | `error.code=validation_failed` |
| Caller role not allowed | Caller role is `app` or `unknown`. | `error.code=caller_role_not_allowed` |
| Gateway unavailable | A non-gateway caller cannot reach the gateway API. | `error.code=gateway_unavailable` |
| Authorization failed | The caller is not authorized for Cloudflare provider administration. | `error.code=authorization_failed` |
| Cloudflare unavailable | The gateway token is missing, invalid, or Cloudflare cannot be reached. | `error.code=cloudflare_unavailable` |

## Doctor Relationship

`cf-dns:remove` may remove provider DNS used by proxy or app hostnames, but it
does not create a Cloudflare doctor family. [`doctor --family=proxy`](../../../8_proxy/proxy-doctor.md)
owns ingress route health and [`doctor --family=app`](../../../5_app/app-doctor.md)
owns app-domain health.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Cloudflare/CfDnsRemoveCommandTest.php` | Command contract for caller-role denial, zone resolution, address-record eligibility, destructive consent, `--force`, provider authorization, provider failures, and no Orbit intent deletion. |
| `tests/Feature/Commands/Cloudflare/CfDnsRemoveRendererTest.php` | Human and JSON renderer output, including every documented `error.code` value. |
