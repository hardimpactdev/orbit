# Technical Contract: `orbit instance:websocket enable`

[Back to public `instance:websocket enable` documentation.](../instance-websocket-enable.md)

**Owner:** `instance`.

**Effects:** `write`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The authenticated peer holds `instance:write` on the selected instance's
  serving node.
- The target resolves to one concrete instance with a serving node.
- An active router node and at least one active WebSocket backend node exist in
  the fleet; the gateway enforces this and returns `websocket.prerequisite_failed`
  when either is absent.

## Signature

```bash
orbit instance:websocket enable [instance] [--host=<host>] [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `instance` | `[instance]` | Always. | Never. | None. | Dotted `<app.instance>` selector. A bare app is shorthand only when exactly one eligible visible instance exists; otherwise fail with `error.meta.reason=instance_required`. |
| `host` | `--host` | Optional. | Never. | `[]`. | Repeatable. Plain hostnames only (no scheme). Duplicates and empty values are discarded. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode. |

## Input Resolution

1. Resolve exactly one instance. A dotted selector is explicit; a bare app
   auto-resolves only a sole eligible visible instance.
2. Resolve its serving node, domain, and authorization before any binding or
   route write. Logical-app placement is never consulted.
3. Forward `public_hosts` (the `--host` values) to the gateway API.

## Behavior Contract

### WebSocket Enable Rules

1. **Instance resolution.** Resolve one concrete instance and use its
   serving node as the authorization boundary. Ambiguity fails before effects.
2. **Binding creation or update.** When no binding exists for the selected
   instance, create one and set `reverb_app_id` to the qualified `app.instance`
   selector, a 32-character
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

`instance:websocket enable` must not:
- Generate new Reverb credentials when a binding already exists.
- Remove public host routes for hosts absent from the current request. Use
  `instance:websocket disable` followed by a fresh `enable` to replace the host list.
- Touch app source, node SSH configuration, or non-WebSocket routes.

## Renderer Selection

| Mode | Renderer |
| --- | --- |
| Default | [Human output](6.1_instance-websocket-enable_output-render_human.md) |
| `--json` | [JSON output](6.2_instance-websocket-enable_output-render_json.md) |

## API Surface

| Method | Path | Permission | Action |
| --- | --- | --- | --- |
| `POST` | `/api/instances/{instance}/websocket/enable` | `instance:write` on instance serving node | Enable the selected instance binding; `{instance}` is dotted or unambiguous sole-instance shorthand. |

The request body is `{"public_hosts": ["<host>", ...]}`. The array is optional;
omit it or pass `[]` to enable without binding public hosts.

HTTP status codes: `200` for success, `404` for `instance.not_found`, `422` for
`websocket.prerequisite_failed` and validation failures, `403` for permission
denials.

## Response Payload

The gateway response returns the canonical logical `app`, selected
`instance`, `serving_node`, and resulting `binding` as separate fields:

| Field | Type | Meaning |
| --- | --- | --- |
| `app` | object | Canonical app entity with no placement fields. |
| `instance` | string | Selected instance name within `app`. |
| `serving_node` | string | Selected instance's authorization and placement node. |
| `internal_host` | string | WireGuard-internal service hostname (`websocket.orbit`). Fixed. |
| `public_hosts` | array | Ordered list of public WebSocket hostnames bound to this instance. |
| `allowed_origins` | array | Origins permitted by Reverb for this app (`https://<app_domain>`). |

## Failure Semantics

Standard failures defined in [Common Failures](../../../README.md#common-failures) apply;
command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Validation failed (instance) | `instance` is missing from the CLI invocation. | Failure — no gateway request sent. |
| Validation failed (public_hosts) | A supplied `--host` value contains `://` or exceeds 255 characters. | Failure. |
| Instance required | A bare selector resolves zero or multiple eligible instances. | `validation_failed` with `error.meta.reason=instance_required`. |
| Instance not found | No concrete instance matches `instance`. | `instance.not_found`. |
| WebSocket prerequisite failed | The fleet has no active router node or no active WebSocket backend node when the route sync runs. | Failure — no binding state written. |

## Doctor Relationship

`instance:websocket enable` writes gateway-owned binding configuration and triggers
route and runtime syncs. Binding drift — an enabled binding with no matching
runtime entry or router route — belongs to `doctor --family=instance`. See
[`instance-doctor.md`](../../instance-doctor.md) for the authoritative instance-family probe
and repair contract; doctor semantics are not restated here.

## Activity Logging

The gateway API endpoint emits an activity entry for every successful and failed
enable attempt.

| Field | Value |
| --- | --- |
| Type | `api:POST /instances/{instance}/websocket/enable` |
| Effect | `write` |
| Subject | Instance resolved from `{instance}`. |
| Properties | `action=enable`, `target_instance`, `target_instance`, `serving_node`, and `public_hosts`. |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Http/Api/AppWebSocketControllerTest.php` | Enable binding creation, public host forwarding, credential generation, prerequisite failure, authorization check, and `instance.not_found` path. |
| `apps/gateway/tests/Unit/Services/WebSockets/WebSocketBindingServiceTest.php` | Service-level binding creation vs update, credential stability on re-enable, allowed origins derivation, and public host normalization rules. |
