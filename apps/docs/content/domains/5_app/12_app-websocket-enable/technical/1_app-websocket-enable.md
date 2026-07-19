# Technical Contract: `orbit app:websocket enable`

[Back to public `app:websocket enable` documentation.](../app-websocket-enable.md)

**Owner:** `app`.

**Effects:** `write`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The authenticated peer holds `app:write` on the selected app instance's
  serving node.
- The target resolves to one concrete app instance with a serving node.
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
| `app` | `[app]` | Always. | Never. | None. | Dotted `<app.instance>` selector. A bare logical app is shorthand only when exactly one eligible visible instance exists; otherwise fail with `error.meta.reason=app_instance_required`. |
| `host` | `--host` | Optional. | Never. | `[]`. | Repeatable. Plain hostnames only (no scheme). Duplicates and empty values are discarded. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode. |

## Input Resolution

1. Resolve exactly one app instance. A dotted selector is explicit; a bare app
   auto-resolves only a sole eligible visible instance.
2. Resolve its serving node, domain, and authorization before any binding or
   route write. Logical-app placement is never consulted.
3. Forward `public_hosts` (the `--host` values) to the gateway API.

## Behavior Contract

### WebSocket Enable Rules

1. **Instance resolution.** Resolve one concrete app instance and use its
   serving node as the authorization boundary. Ambiguity fails before effects.
2. **Binding creation or update.** When no binding exists for the selected
   instance, create one and generate a binding-specific `reverb_app_id`, a 32-character
   random `reverb_app_key`, and a 48-character random `reverb_app_secret`.
   When a binding exists, update it in place and keep the existing credentials.
3. **Binding state.** Set `enabled=true`. Record the supplied public hosts as
   the canonical public host list. Derive `allowed_origins` from the selected
   instance's domain as `["https://<domain>"]`; a missing instance domain fails
   before mutation.
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
| `POST` | `/api/apps/{app}/websocket/enable` | `app:write` on instance serving node | Enable the selected instance binding; `{app}` is dotted or unambiguous sole-instance shorthand. |

The request body is `{"public_hosts": ["<host>", ...]}`. The array is optional;
omit it or pass `[]` to enable without binding public hosts.

HTTP status codes: `200` for success, `404` for `app.not_found`, `422` for
`websocket.prerequisite_failed` and validation failures, `403` for permission
denials.

## Response Payload

The gateway response returns the canonical logical `app`, selected
`app_instance`, `serving_node`, and resulting `binding` as separate fields:

| Field | Type | Meaning |
| --- | --- | --- |
| `app` | object | Canonical logical app entity with no placement fields. |
| `app_instance` | string | Selected instance name within `app`. |
| `serving_node` | string | Selected instance's authorization and placement node. |
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
| App instance required | A bare selector resolves zero or multiple eligible instances. | `validation_failed` with `error.meta.reason=app_instance_required`. |
| App instance not found | No concrete app instance matches `app`. | `app_instance.not_found`. |
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
| Subject | App instance resolved from `{app}`. |
| Properties | `action=enable`, `target_app`, `target_app_instance`, `serving_node`, and `public_hosts`. |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Http/Api/AppWebSocketControllerTest.php` | Enable binding creation, public host forwarding, credential generation, prerequisite failure, authorization check, and `app.not_found` path. |
| `apps/gateway/tests/Unit/Services/WebSockets/WebSocketBindingServiceTest.php` | Service-level binding creation vs update, credential stability on re-enable, allowed origins derivation, and public host normalization rules. |
