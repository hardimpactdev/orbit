# Technical Contract: `orbit instance:analytics enable`

[Back to public `instance:analytics enable` documentation.](../instance-analytics-enable.md)

**Owner:** `instance`.

**Effects:** `write`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The authenticated peer holds `instance:write` on the selected instance's serving node.
- The target resolves to one concrete instance with a public domain and serving node.
- The singleton analytics role is active and its router-owned
  `analytics.orbit` service route exists.

## Signature

```bash
orbit instance:analytics enable [instance] [--host=<host>] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `project` | `[project.instance]` | Always. | Never. | None. | Dotted selector; bare shorthand succeeds only for exactly one eligible visible instance, otherwise `instance_required`. |
| `host` | `--host` | Optional. | Never. | `analytics.<instance-domain>`. | Repeatable up to ten unique multi-label DNS hostnames. An explicit host does not bypass the instance-domain prerequisite. |
| `json` | `--json` | Optional. | Never. | `false` | Selects the JSON renderer and non-interactive input mode. |

## Input Resolution

1. Resolve exactly one instance, its serving node, public domain, and route association. Logical-app placement is never consulted.
2. Authorize `instance:write` on that serving node before effects.
3. Normalize supplied `--host` values by trimming, lowercasing, and discarding
   duplicates.
4. Forward `public_hosts`; when omitted, derive the default from the selected instance domain.

## Behavior Contract

### Binding Enable Rules

- Resolve one concrete instance and create or update only that instance's binding.
- Serialize analytics binding mutations at the gateway so concurrent enable or
  disable requests do not compete for SQLite writes. Return a retryable busy
  failure when the bounded lock wait expires before any mutation begins. The
  lease is at least one hour and scales from the app's existing stored analytics
  route count plus the current ten-host request limit, using a 120-second
  per-route budget and a 600-second buffer.
- Set `enabled=true`.
- Store public tracking hosts; when omitted, store `analytics.<selected-instance-domain>`.
- Reject before mutation when the selected instance has no public domain.
- Require the private `analytics.orbit` service route created by analytics role
  deployment. Instance binding enable must not create or own that route.
- Register one public `app-analytics` route for each public host, apply its
  router artifact before its ingress artifact, and report success only after
  both Caddy reloads complete. Public routes must proxy only Plausible script
  and event paths and must preserve forwarding identity for event attribution.
- When a re-enable replaces hosts, remove obsolete ingress and router artifacts
  before deleting their route rows. A cleanup failure leaves the previous
  binding unchanged.
- Return one tracking endpoint object per public host with the public script
  base URL, exact generic `/js/script.js` URL, selected-instance `data-domain`,
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

`instance:analytics enable` must not create Plausible sites, generate Plausible API
tokens, inject tracking scripts, expose the dashboard publicly, or mutate the
Plausible CE process version. It must not mutate provider DNS or claim that an
accepted event was stored by Plausible.

## Renderer Contracts

- [Human renderer](6.1_instance-analytics-enable_output-render_human.md)
- [JSON renderer](6.2_instance-analytics-enable_output-render_json.md)

## API Surface

| Method | Path | Permission | Action |
| --- | --- | --- | --- |
| `POST` | `/api/instances/{instance}/analytics/enable` | `instance:write` on instance serving node | Enable the selected instance binding. |

The request body is `{"public_hosts": ["<host>", ...]}`. The array is optional.

## Response Payload

| Field | Type | Meaning |
| --- | --- | --- |
| `project` | object | Canonical project entity without placement fields. |
| `instance` | string | Selected instance name. |
| `serving_node` | string | Selected instance's serving and authorization node. |
| `binding.enabled` | boolean | Whether the binding is enabled. |
| `binding.site_domain` | string | Selected instance's canonical public domain used as Plausible `data-domain`. |
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
| Instance required | Bare shorthand is ambiguous or ineligible. | `validation_failed` with `error.meta.reason=instance_required` |
| Instance not found | No concrete instance matches. | `error.code=instance.not_found` |
| Public domain required | The selected instance has no public domain. | `error.code=analytics.domain_required` |
| Analytics prerequisite failed | Required router, ingress, or analytics backend is missing. | `error.code=analytics.prerequisite_failed` |
| Route cleanup failed | Obsolete ingress or router artifacts cannot be removed before a host replacement. The previous binding remains unchanged. | `error.code=analytics.route_cleanup_failed` |
| Route enactment failed | Router or ingress route application/reload fails. Durable binding and route intent remain available for doctor repair. | `error.code=analytics.route_enactment_failed` |
| Analytics mutation busy | Another analytics binding mutation still holds the gateway mutation lease after the bounded wait. | `error.code=analytics.mutation_busy` |

## Doctor Relationship

`instance:analytics enable` writes instance-owned binding intent and converges only public
app analytics routes.
[`doctor --family=instance`](../../instance-doctor.md) owns app binding drift and
[`doctor --family=proxy`](../../../8_proxy/proxy-doctor.md) owns route drift.

## Activity Logging

The gateway API endpoint emits an activity entry for every successful and failed
enable attempt.

| Field | Value |
| --- | --- |
| Type | `api:POST /instances/{instance}/analytics/enable` |
| Effect | `write` |
| Subject | Instance resolved from `{instance}`. |
| Properties | `action=enable`, `target_instance`, `target_instance`, `serving_node`, and `public_hosts`. |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Http/Api/AppAnalyticsControllerTest.php` | Enable binding creation, exact integration and DNS expectation payload, default host derivation, domain-required rejection, prerequisite failure, enactment failure, authorization check, and `instance.not_found` path. |
| `apps/gateway/tests/Unit/Services/Analytics/AppAnalyticsBindingServiceTest.php` | Domain prerequisite, hostname and host-count validation, lease retention beyond the former 120-second boundary, lifecycle ordering, binding creation vs update, and cleanup failure rollback. |
| `apps/cli/tests/Feature/Commands/App/AppAnalyticsEnableCommandTest.php` | CLI input validation, typed gateway request payload, progress tree, endpoint guidance, and JSON passthrough. |
