# Technical Contract: `orbit cf-dns:add <name> <content> [--type=<type>] [--zone=<zone>] [--proxied] [--json]`

[Back to public `cf-dns:add` documentation.](../cf-dns-add.md)

**Owner:** `cf`.

**Effects:** `write`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway, or the command is running on the gateway.
- The local caller role can be resolved according to the foundation `general.local_node_role` contract.
- The current node identity is authorized for Cloudflare provider administration.
- The gateway has a Cloudflare API token configured.

## Signature

```bash
orbit cf-dns:add <name> <content> [--type=<type>] [--zone=<zone>] [--proxied] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `name` | Argument `name` | `Always.` | `Never.` | `None.` | Fully qualified DNS record name in a real Cloudflare-managed domain. |
| `content` | Argument `content` | `Always.` | `Never.` | `None.` | IPv4 address when `type=A`; IPv6 address when `type=AAAA`. |
| `type` | `--type` | `Optional.` | `Never.` | `A` | `A` or `AAAA`. |
| `zone` | `--zone` | `Optional.` | `Never.` | Resolved from `name`. | Cloudflare zone ID or exact zone domain name. |
| `proxied` | `--proxied` | `Optional.` | `Never.` | `false` | Boolean flag. |
| `json` | `--json` | `Optional.` | `Never.` | `false` | Selects the JSON renderer. |

## Caller Role Behavior

Control callers send the command request to the gateway. Gateway callers
execute the provider request directly. App-node and unknown callers are denied
before prompts or side effects because Cloudflare commands are provider
administration, not app-local runtime work. Authorized control and gateway
callers use the gateway-owned authorization policy for Cloudflare provider
administration.

## Input Resolution

1. Resolve and validate `name`, `content`, `type`, `zone`, and `proxied`.
2. If `zone` is omitted, derive the candidate zone from `name` by selecting the
   longest Cloudflare zone domain suffix visible to the gateway token.
3. Resolve the selected zone to a Cloudflare zone ID.
4. Validate that `content` matches the selected address record `type`.
5. Check existing address records for the same zone, name, and type before
   creating provider state.

## Input Mode Contracts

No input-mode-specific contracts are required. The command does not prompt;
missing or invalid required fields fail before side effects.

## Behavior Contract

### Address Record Rules

- Creates only `A` or `AAAA` Cloudflare DNS records.
- Sends the `proxied` flag exactly as requested.
- Returns the created provider record as the command result.
- Succeeds idempotently when an equivalent record already exists.
- Refuses to create a second record for the same zone, name, and type with
  different content or proxy setting.

### Orbit Intent Boundaries

`cf-dns:add` writes Cloudflare provider state only. It must not create app
domains, proxy routes, caller-local DNS resolver overrides, or gateway-owned DNS
intent. Orbit-owned hostnames should normally be created through app and proxy
flows that may call provider enactment internally.

## Renderer Contracts

- [Human renderer](6.1_cf-dns-add_output-render_human.md)
- [JSON renderer](6.2_cf-dns-add_output-render_json.md)

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| Validation failed | `name`, `content`, `type`, `zone`, or provider conflict validation fails. | `error.code=validation_failed` |
| Caller role not allowed | Caller role is `app` or `unknown`. | `error.code=caller_role_not_allowed` |
| Gateway unavailable | A non-gateway caller cannot reach the gateway API. | `error.code=gateway_unavailable` |
| Authorization failed | The caller is not authorized for Cloudflare provider administration. | `error.code=authorization_failed` |
| Cloudflare unavailable | The gateway token is missing, invalid, or Cloudflare cannot be reached. | `error.code=cloudflare_unavailable` |

## Doctor Relationship

`cf-dns:add` may help enact provider DNS for a proxy or app hostname, but it does
not create a Cloudflare doctor family. [`doctor --family=proxy`](../../../8_proxy/proxy-doctor.md)
owns ingress route health and [`doctor --family=app`](../../../5_app/app-doctor.md)
owns app-domain health.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Cloudflare/CfDnsAddCommandTest.php` | Command contract for caller-role denial, zone inference, A/AAAA validation, idempotent equivalent records, conflicting records, provider authorization, and no Orbit intent writes. |
| `tests/Feature/Commands/Cloudflare/CfDnsAddRendererTest.php` | Human and JSON renderer output, including every documented `error.code` value. |
