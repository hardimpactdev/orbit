# Technical Contract: `orbit instance:analytics verify`

[Back to public `instance:analytics verify` documentation.](../instance-analytics-verify.md)

**Owner:** `instance`.

**Effects:** `read`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The authenticated peer holds `instance:read` on the selected instance's serving node.
- The caller can resolve and reach the stored public analytics hosts.

## Signature

```bash
orbit instance:analytics verify [instance] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `app` | `[app.instance]` | Always. | Never. | None. | Dotted selector; bare shorthand succeeds only for exactly one eligible visible instance, otherwise `instance_required`. |
| `json` | `--json` | Optional. | Never. | `false` | Selects the JSON renderer and non-interactive input mode. |

## Behavior Contract

### Verification Rules

- Resolve one concrete instance, authorize its serving node, and read only
  that instance's enabled binding, public hosts, route intent, and ingress targets.
- For every stored host, resolve public `A` and `AAAA` records from the caller
  and compare the normalized answers with the configured ingress targets.
  Report matching answers as `routing=direct` and differing resolved answers
  as `routing=intermediary`; the comparison is diagnostic because a provider
  proxy can intentionally hide the origin ingress addresses.
- Reject IP literals and single-label stored hosts before DNS resolution.
  Retain only explicitly classified global-unicast DNS answers. Discard private,
  shared, benchmark, documentation, multicast, reserved, and transition-range
  answers; when no approved answer remains, report `dns.status=unsafe` and do
  not open a connection.
- Probe exact HTTPS URLs only. Follow no redirects and verify the certificate
  chain and hostname. Pin each request to the approved public DNS answers with
  cURL resolve entries so a second resolver lookup cannot redirect the probe.
- Require `GET /js/script.js` to complete with HTTP `200`.
- Require `GET /` to complete with HTTP `404`; any successful dashboard or
  redirect response means the public dashboard boundary is not proven.
- Mark a host ready only when route intent is registered, DNS resolves, TLS is
  verified, the script returns `200`, and the root returns `404`. Overall
  readiness requires every stored host to be ready.
- Return `analytics.public_not_ready` with the full verification payload when
  any host is incomplete.

### Read-only and authority boundaries

The command must not write gateway intent, repair proxy artifacts, mutate
provider DNS, change Cloudflare proxy state, create or inspect Plausible sites,
edit app source or CSP, deploy an app, launch a browser, or post `/api/event`.
`event.status=not_run` and `plausible_site.status=unchecked` are required so a
successful public-route check is not presented as event persistence proof.

## API Surface

| Method | Path | Permission | Action |
| --- | --- | --- | --- |
| `GET` | `/api/instances/{instance}/analytics/verify` | `instance:read` on instance serving node | Return the selected instance's verification context without probes or mutation. |

## Renderer Contracts

- [Human renderer](6.1_instance-analytics-verify_output-render_human.md)
- [JSON renderer](6.2_instance-analytics-verify_output-render_json.md)

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| Instance required | Bare shorthand is ambiguous or ineligible. | `validation_failed` with `error.meta.reason=instance_required` |
| Instance missing | No concrete instance matches. | `error.code=instance.not_found` |
| Binding missing | The selected instance has no analytics binding. | `error.code=analytics.binding_missing` |
| Binding disabled | The stored binding is disabled or has no public hosts. | `error.code=analytics.public_not_ready` with verification data. |
| Public readiness incomplete | Route intent, public-safe DNS, TLS, script, or dashboard boundary is incomplete. | `error.code=analytics.public_not_ready` with verification data. |

## Doctor Relationship

This command observes public readiness and stored analytics route intent only.
It never fixes drift. [Instance Doctor](../../instance-doctor.md) owns instance-family drift,
while [`doctor --family=proxy`](../../../8_proxy/proxy-doctor.md) remains the
repair surface for router and ingress artifacts.

## Activity Logging

| Field | Value |
| --- | --- |
| Type | `api:GET /instances/{instance}/analytics/verify` |
| Effect | `read` |
| Subject | Instance resolved from `{instance}`. |
| Properties | `action=verify`, `target_instance`, and `public_hosts`. |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Http/Api/AppAnalyticsControllerTest.php` | Verification context, route-intent status, DNS targets, binding errors, authorization, and no gateway mutation. |
| `apps/cli/tests/Feature/Commands/App/AppAnalyticsVerifyCommandTest.php` | Gateway request, caller-side probe rendering, success and not-ready exits, JSON payload, and no event request. |
| `apps/cli/tests/Feature/Services/Analytics/AppAnalyticsReadinessVerifierTest.php` | DNS comparison, unsafe-answer rejection, pinned HTTPS addresses, TLS/script/root rules, multi-host aggregation, and read-only probe paths. |
| `apps/cli/tests/Feature/Services/Analytics/GlobalUnicastIpAddressTest.php` | Explicit global-unicast allow-list behavior across IPv4 and IPv6 special-purpose ranges. |
