# Technical Contract: `orbit app:analytics verify`

[Back to public `app:analytics verify` documentation.](../app-analytics-verify.md)

**Owner:** `app`.

**Effects:** `read`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The authenticated peer holds `app:read` on the app's owning node.
- The caller can resolve and reach the stored public analytics hosts.

## Signature

```bash
orbit app:analytics verify [app] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `app` | `[app]` | Always. | Never. | None. | Must resolve to an existing app record by name or hostname. |
| `json` | `--json` | Optional. | Never. | `false` | Selects the JSON renderer and non-interactive input mode. |

## Behavior Contract

### Verification Rules

- Read the enabled app analytics binding, public hosts, derived route intent,
  and provider-neutral ingress targets through the typed gateway API.
- For every stored host, resolve public `A` and `AAAA` records from the caller
  and compare the normalized answers with the configured ingress targets.
  Report matching answers as `routing=direct` and differing resolved answers
  as `routing=intermediary`; the comparison is diagnostic because a provider
  proxy can intentionally hide the origin ingress addresses.
- Probe exact HTTPS URLs only. Follow no redirects and verify the certificate
  chain and hostname.
- Require `GET /js/script.js` to complete with HTTP `200`.
- Require `GET /` to complete with HTTP `404`; any successful dashboard or
  redirect response means the public dashboard boundary is not proven.
- Mark a host ready only when route intent is registered, DNS resolves, TLS is
  verified, the script returns `200`, and the root returns `404`. Overall
  readiness requires every stored host to be ready.
- Return `analytics.public_not_ready` with the full verification payload when
  any host is incomplete.

### Read-only And Authority Boundaries

The command must not write gateway intent, repair proxy artifacts, mutate
provider DNS, change Cloudflare proxy state, create or inspect Plausible sites,
edit app source or CSP, deploy an app, launch a browser, or post `/api/event`.
`event.status=not_run` and `plausible_site.status=unchecked` are required so a
successful public-route check is not presented as event persistence proof.

## API Surface

| Method | Path | Permission | Action |
| --- | --- | --- | --- |
| `GET` | `/api/apps/{app}/analytics/verify` | `app:read` | Return stored verification context without public probes or mutation. |

## Renderer Contracts

- [Human renderer](6.1_app-analytics-verify_output-render_human.md)
- [JSON renderer](6.2_app-analytics-verify_output-render_json.md)

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| App missing | No app record matches `app`. | `error.code=app.not_found` |
| Binding missing | The app has no analytics binding. | `error.code=analytics.binding_missing` |
| Binding disabled | The stored binding is disabled or has no public hosts. | `error.code=analytics.public_not_ready` with verification data. |
| Public readiness incomplete | Route intent, DNS, TLS, script, or dashboard boundary is incomplete. | `error.code=analytics.public_not_ready` with verification data. |

## Doctor Relationship

This command observes public readiness and stored analytics route intent only.
It never fixes drift. [App Doctor](../../app-doctor.md) owns app-family drift,
while [`doctor --family=proxy`](../../../8_proxy/proxy-doctor.md) remains the
repair surface for router and ingress artifacts.

## Activity Logging

| Field | Value |
| --- | --- |
| Type | `api:GET /apps/{app}/analytics/verify` |
| Effect | `read` |
| Subject | App record resolved from `{app}`. |
| Properties | `action=verify` and `target_app`. |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Http/Api/AppAnalyticsControllerTest.php` | Verification context, route-intent status, DNS targets, binding errors, authorization, and no gateway mutation. |
| `apps/cli/tests/Feature/Commands/App/AppAnalyticsVerifyCommandTest.php` | Gateway request, caller-side probe rendering, success and not-ready exits, JSON payload, and no event request. |
| `apps/cli/tests/Feature/Services/Analytics/AppAnalyticsReadinessVerifierTest.php` | DNS comparison, TLS/script/root rules, multi-host aggregation, and read-only probe paths. |
