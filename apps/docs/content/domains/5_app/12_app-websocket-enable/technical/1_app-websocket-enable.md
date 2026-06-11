# Technical Contract: `orbit app:websocket enable`

[Back to public `app:websocket enable` documentation.](../app-websocket-enable.md)

**Owner:** `app`.

**Effects:** `write`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The authenticated peer holds `app:write` on the app's owning node.
- The target app exists in the gateway registry.
- An active router node and at least one active WebSocket backend node exist in
  the fleet; the gateway enforces this and returns `websocket.prerequisite_failed`
  when either is absent.

## Signature

```bash
orbit app:websocket enable [app] [--host=<host>] [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `app` | `[app]` | Always. | Never. | None. | Must resolve to an existing app record by name or hostname. Name match wins; hostname match consulted when no name match exists. |
| `host` | `--host` | Optional. | Never. | `[]`. | Repeatable. Plain hostnames only (no scheme). Duplicates and empty values are discarded. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode. |

## Input Resolution

1. Validate `app` is provided. Reject with `validation_failed` (`field=app`)
   when absent.
2. Forward `public_hosts` (the `--host` values) to the gateway API.

## Behavior Contract

### WebSocket Enable Rules

1. **App resolution.** Resolve the app by matching `app` against app name and
   then app hostname. Return `app.not_found` when no match exists.
2. **Binding creation or update.** When no binding exists for the app, create
   one and generate a `reverb_app_id` (equal to `app.name`), a 32-character
   random `reverb_app_key`, and a 48-character random `reverb_app_secret`.
   When a binding exists, update it in place and keep the existing credentials.
3. **Binding state.** Set `enabled=true`. Record the supplied public hosts as
   the canonical public host list. Derive `allowed_origins` from the app domain
   as `["https://<domain>"]`; when the app has no domain, set `allowed_origins`
   to `[]`.
4. **Route sync.** Sync the WebSocket service route on the router node and
   register a public route for each hostname in the supplied list. The fleet
   must have an active router node and at least one active WebSocket backend
   node or the operation fails with `websocket.prerequisite_failed`.
5. **Runtime sync.** Sync the Reverb runtime app configuration so the running
   Reverb node accepts connections for this app.

### Scope Boundaries

`app:websocket enable` must not:
- Generate new Reverb credentials when a binding already exists.
- Remove public host routes for hosts absent from the current request. Use
  `app:websocket disable` followed by a fresh `enable` to replace the host list.
- Touch app source, node SSH configuration, or non-WebSocket routes.

## Renderer Selection

| Mode | Renderer |
| --- | --- |
| Default | [Human output](6.1_app-websocket-enable_output-render_human.md) |
| `--json` | [JSON output](6.2_app-websocket-enable_output-render_json.md) |

## API Surface

| Method | Path | Permission | Action |
| --- | --- | --- | --- |
| `POST` | `/api/apps/{app}/websocket/enable` | `app:write` | Enable WebSocket binding. |

The request body is `{"public_hosts": ["<host>", ...]}`. The array is optional;
omit it or pass `[]` to enable without binding public hosts.

HTTP status codes: `200` for success, `404` for `app.not_found`, `422` for
`websocket.prerequisite_failed` and validation failures, `403` for permission
denials.

## Response Payload

The gateway response includes a `binding` object that carries the resulting
binding state. The binding payload returned on success:

| Field | Type | Meaning |
| --- | --- | --- |
| `app` | string | App identity slug. |
| `internal_host` | string | WireGuard-internal service hostname (`websocket.orbit`). Fixed. |
| `public_hosts` | array | Ordered list of public WebSocket hostnames bound to this app. |
| `allowed_origins` | array | Origins permitted by Reverb for this app (`https://<app_domain>`). |

## Failure Semantics

Standard failures defined in [Common Failures](../../../README.md#common-failures) apply;
command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Validation failed (app) | `app` is missing from the CLI invocation. | Failure — no gateway request sent. |
| Validation failed (public_hosts) | A supplied `--host` value contains `://` or exceeds 255 characters. | Failure. |
| App not found | No app record matches `app`. | Failure. |
| WebSocket prerequisite failed | The fleet has no active router node or no active WebSocket backend node when the route sync runs. | Failure — no binding state written. |

## Doctor Relationship

`app:websocket enable` writes gateway-owned binding configuration and triggers
route and runtime syncs. Binding drift — an enabled binding with no matching
runtime entry or router route — belongs to `doctor --family=app`. See
[`app-doctor.md`](../../app-doctor.md) for the authoritative app-family probe
and repair contract; doctor semantics are not restated here.

## Activity Logging

The gateway API endpoint emits an activity entry for every successful and failed
enable attempt.

| Field | Value |
| --- | --- |
| Type | `api:POST /apps/{app}/websocket/enable` |
| Effect | `write` |
| Subject | App record resolved from `{app}`. |
| Properties | `action=enable`, `target_app`, `public_hosts` (the hosts recorded on the binding). |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Http/Api/AppWebSocketControllerTest.php` | Enable binding creation, public host forwarding, credential generation, prerequisite failure, authorization check, and `app.not_found` path. |
| `apps/gateway/tests/Unit/Services/WebSockets/WebSocketBindingServiceTest.php` | Service-level binding creation vs update, credential stability on re-enable, allowed origins derivation, and public host normalization rules. |
