# Technical Contract: `orbit app:analytics enable`

[Back to public `app:analytics enable` documentation.](../app-analytics-enable.md)

**Owner:** `app`.

**Effects:** `write`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The authenticated peer holds `app:write` on the app's owning node.
- The target app exists in the gateway registry.
- The target app has a configured public domain.
- The singleton analytics role is active and its router-owned
  `analytics.orbit` service route exists.

## Signature

```bash
orbit app:analytics enable [app] [--host=<host>] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `app` | `[app]` | Always. | Never. | None. | Must resolve to an existing app record by name or hostname. Name match wins. |
| `host` | `--host` | Optional. | Never. | `analytics.<app-domain>`. | Repeatable up to ten unique multi-label DNS hostnames. URLs, IP addresses, and single-label names are rejected. Duplicates and empty values are discarded. An explicit host does not bypass the app-domain prerequisite. |
| `json` | `--json` | Optional. | Never. | `false` | Selects the JSON renderer and non-interactive input mode. |

## Input Resolution

1. Validate `app` is provided. Reject with `validation_failed` (`field=app`)
   when absent.
2. Normalize supplied `--host` values by trimming, lowercasing, and discarding
   duplicates.
3. Forward `public_hosts` to the gateway API. When no hosts were supplied, the
   gateway derives the default from the app hostname.

## Behavior Contract

### Binding Enable Rules

- Resolve the app by matching `app` against app name and then app hostname.
  Return `app.not_found` when no match exists.
- Create the app analytics binding when none exists, or update the existing
  binding in place.
- Serialize analytics binding mutations at the gateway so concurrent enable or
  disable requests do not compete for SQLite writes. Return a retryable busy
  failure when the bounded lock wait expires before any mutation begins. The
  lease is at least one hour and scales from the app's existing stored analytics
  route count plus the current ten-host request limit, using a 120-second
  per-route budget and a 600-second buffer. This also covers bindings whose
  stored route count predates and exceeds the current input limit.
- Set `enabled=true`.
- Store public tracking hosts. When the request omits hosts and the app has a
  hostname, store `analytics.<app-domain>`.
- Reject the operation before creating or updating binding state when the app
  has no configured public domain, including when explicit hosts were supplied.
- Require the private `analytics.orbit` service route created by analytics role
  deployment. App binding enable must not create or own that route.
- Register one public `app-analytics` route for each public host, apply its
  router artifact before its ingress artifact, and report success only after
  both Caddy reloads complete. Public routes must proxy only Plausible script
  and event paths and must preserve forwarding identity for event attribution.
- When a re-enable replaces hosts, remove obsolete ingress and router artifacts
  before deleting their route rows. A cleanup failure leaves the previous
  binding unchanged.
- Return one tracking endpoint object per public host with the public script
  base URL, exact generic `/js/script.js` URL, canonical app `data-domain`,
  exact script snippet, and `/api/event` endpoint.
- Return the selected ingress node and its configured public IPv4/IPv6 values
  as provider-neutral expected `A`/`AAAA` targets. Do not claim that provider
  records exist or prescribe provider record IDs, TTL, or proxy state.
- Return completed router and ingress enactment separately from public
  readiness. Public readiness is always `not_verified` on enable because the
  command does not query public DNS, TLS, Plausible site state, or app source.
- Return `analytics.prerequisite_failed` when the fleet has no active router,
  active analytics backend, or required ingress placement.

### Scope Boundaries

`app:analytics enable` must not create Plausible sites, generate Plausible API
tokens, inject tracking scripts, expose the dashboard publicly, or mutate the
Plausible CE process version. It must not mutate provider DNS or claim that an
accepted event was stored by Plausible.

## Renderer Contracts

- [Human renderer](6.1_app-analytics-enable_output-render_human.md)
- [JSON renderer](6.2_app-analytics-enable_output-render_json.md)

## API Surface

| Method | Path | Permission | Action |
| --- | --- | --- | --- |
| `POST` | `/api/apps/{app}/analytics/enable` | `app:write` | Enable app analytics binding. |

The request body is `{"public_hosts": ["<host>", ...]}`. The array is optional.

## Response Payload

| Field | Type | Meaning |
| --- | --- | --- |
| `binding.app` | string | App identity slug. |
| `binding.enabled` | boolean | Whether the binding is enabled. |
| `binding.site_domain` | string | Canonical app domain used as Plausible `data-domain`. |
| `binding.internal_host` | string | Private dashboard host, always `analytics.orbit`. |
| `binding.dashboard_url` | string | Private dashboard URL, always `https://analytics.orbit`. |
| `binding.public_hosts` | array | Public tracking hostnames bound to this app. |
| `binding.tracking_paths` | array | Plausible script and event-ingest paths served by public routes. |
| `binding.tracking_endpoints` | array | Per-host objects containing `host`, compatible `script_base_url`, exact `script_url`, `event_endpoint`, `data_domain`, and `snippet`. |
| `route_enactment` | object | `status=completed` plus the enacted `router` and `ingress` placements. |
| `dns_expectation` | object | Public hosts, ingress node, configured address targets, and `provider_managed=false`. |
| `public_readiness` | object | Explicit `not_verified` state with unchecked DNS, TLS, script, dashboard, event, and Plausible-site facts. |
| `remaining_actions` | array | Stable action codes for provider DNS, Plausible site, app integration, and public verification. |

## Failure Semantics

Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| App not found | No app record matches `app`. | `error.code=app.not_found` |
| Public domain required | The app has no configured public domain. | `error.code=analytics.domain_required` |
| Analytics prerequisite failed | Required router, ingress, or analytics backend is missing. | `error.code=analytics.prerequisite_failed` |
| Route cleanup failed | Obsolete ingress or router artifacts cannot be removed before a host replacement. The previous binding remains unchanged. | `error.code=analytics.route_cleanup_failed` |
| Route enactment failed | Router or ingress route application/reload fails. Durable binding and route intent remain available for doctor repair. | `error.code=analytics.route_enactment_failed` |
| Analytics mutation busy | Another analytics binding mutation still holds the gateway mutation lease after the bounded wait. | `error.code=analytics.mutation_busy` |

## Doctor Relationship

`app:analytics enable` writes app-owned binding intent and converges only public
app analytics routes.
[`doctor --family=app`](../../app-doctor.md) owns app binding drift and
[`doctor --family=proxy`](../../../8_proxy/proxy-doctor.md) owns route drift.

## Activity Logging

The gateway API endpoint emits an activity entry for every successful and failed
enable attempt.

| Field | Value |
| --- | --- |
| Type | `api:POST /apps/{app}/analytics/enable` |
| Effect | `write` |
| Subject | App record resolved from `{app}`. |
| Properties | `action=enable`, `target_app`, and `public_hosts`. |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Http/Api/AppAnalyticsControllerTest.php` | Enable binding creation, exact integration and DNS expectation payload, default host derivation, domain-required rejection, prerequisite failure, enactment failure, authorization check, and `app.not_found` path. |
| `apps/gateway/tests/Unit/Services/Analytics/AppAnalyticsBindingServiceTest.php` | Domain prerequisite, hostname and host-count validation, lease retention beyond the former 120-second boundary, lifecycle ordering, binding creation vs update, and cleanup failure rollback. |
| `apps/cli/tests/Feature/Commands/App/AppAnalyticsEnableCommandTest.php` | CLI input validation, typed gateway request payload, progress tree, endpoint guidance, and JSON passthrough. |
