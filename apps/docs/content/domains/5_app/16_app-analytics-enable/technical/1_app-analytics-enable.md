# Technical Contract: `orbit app:analytics enable`

[Back to public `app:analytics enable` documentation.](../app-analytics-enable.md)

**Owner:** `app`.

**Effects:** `write`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The authenticated peer holds `app:write` on the app's owning node.
- The target app exists in the gateway registry.
- An active router node and at least one active analytics backend node exist in
  the fleet.

## Signature

```bash
orbit app:analytics enable [app] [--host=<host>] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `app` | `[app]` | Always. | Never. | None. | Must resolve to an existing app record by name or hostname. Name match wins. |
| `host` | `--host` | Optional. | Never. | `analytics.<app-domain>` when the app has a hostname and no hosts are supplied; otherwise `[]`. | Repeatable. Plain hostnames only. Duplicates and empty values are discarded. |
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
- Set `enabled=true`.
- Store public tracking hosts. When the request omits hosts and the app has a
  hostname, store `analytics.<app-domain>`.
- Sync the private analytics service route for `analytics.orbit` on router.
- Register one public `app-analytics` route for each public host. Public routes
  must proxy only Plausible script and event paths and must preserve forwarding
  identity for event attribution.
- Return `analytics.prerequisite_failed` when the fleet has no active router,
  active analytics backend, or required ingress placement.

### Scope Boundaries

`app:analytics enable` must not create Plausible sites, generate Plausible API
tokens, inject tracking scripts, expose the dashboard publicly, or mutate the
Plausible CE process version.

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
| `app` | string | App identity slug. |
| `enabled` | boolean | Whether the binding is enabled. |
| `internal_host` | string | Private dashboard host, always `analytics.orbit`. |
| `dashboard_url` | string | Private dashboard URL, always `https://analytics.orbit`. |
| `public_hosts` | array | Public tracking hostnames bound to this app. |
| `tracking_paths` | array | Plausible script and event-ingest paths served by public routes. |

## Failure Semantics

Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| App not found | No app record matches `app`. | `error.code=app.not_found` |
| Analytics prerequisite failed | Required router, ingress, or analytics backend is missing. | `error.code=analytics.prerequisite_failed` |

## Doctor Relationship

`app:analytics enable` writes app-owned binding intent and triggers route sync.
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
| `apps/gateway/tests/Feature/Http/Api/AppAnalyticsControllerTest.php` | Enable binding creation, default host derivation, public host forwarding, prerequisite failure, authorization check, and `app.not_found` path. |
| `apps/gateway/tests/Unit/Services/Analytics/AppAnalyticsBindingServiceTest.php` | Service-level binding creation vs update, public host normalization, and disabled-to-enabled transitions. |
| `apps/cli/tests/Feature/Commands/App/AppAnalyticsEnableCommandTest.php` | CLI input validation, typed gateway request payload, human output, and JSON passthrough. |
