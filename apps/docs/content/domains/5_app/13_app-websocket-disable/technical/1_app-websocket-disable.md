# Technical Contract: `orbit app:websocket disable`

[Back to public `app:websocket disable` documentation.](../app-websocket-disable.md)

**Owner:** `app`.

**Effects:** `write`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The authenticated peer holds `app:write` on the app's owning node.
- The target app exists in the gateway registry and has an existing WebSocket
  binding.

## Signature

```bash
orbit app:websocket disable [app] [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `app` | `[app]` | Always. | Never. | None. | Must resolve to an existing app record by name or hostname. Name match wins; hostname match consulted when no name match exists. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode. |

## Input Resolution

1. Validate `app` is provided. Reject with `validation_failed` (`field=app`)
   when absent.
2. Forward the disable request to the gateway API.

## Behavior Contract

### WebSocket Disable Rules

1. **App resolution.** Resolve the app by matching `app` against app name and
   then app hostname. Return `app.not_found` when no match exists.
2. **Binding required.** Require an existing binding for the app. Return
   `websocket.binding_missing` when none exists.
3. **Binding state.** Set `enabled=false` and clear `public_hosts` to `[]`.
   The `reverb_app_id`, `reverb_app_key`, `reverb_app_secret`, and
   `allowed_origins` fields are preserved unchanged.
4. **Route sync.** Sync public host routes so the cleared `public_hosts` list
   takes effect on the router node.
5. **Runtime sync.** Sync the Reverb runtime app configuration to remove this
   app from the running Reverb node.

### Scope Boundaries

`app:websocket disable` must not:
- Delete the binding record or its Reverb credentials.
- Clear `allowed_origins`.
- Touch app source, node SSH configuration, or non-WebSocket routes.

## Renderer Selection

| Mode | Renderer |
| --- | --- |
| Default | [Human output](6.1_app-websocket-disable_output-render_human.md) |
| `--json` | [JSON output](6.2_app-websocket-disable_output-render_json.md) |

## API Surface

| Method | Path | Permission | Action |
| --- | --- | --- | --- |
| `POST` | `/api/apps/{app}/websocket/disable` | `app:write` | Disable WebSocket binding. |

The request body is empty for disable.

HTTP status codes: `200` for success, `404` for `app.not_found`, `422` for
`websocket.binding_missing`, `403` for permission denials.

## Failure Semantics

Standard failures defined in [Common Failures](../../../README.md#common-failures) apply;
command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Validation failed (app) | `app` is missing from the CLI invocation. | Failure — no gateway request sent. |
| App not found | No app record matches `app`. | Failure. |
| WebSocket binding missing | The app has no WebSocket binding record. | Failure — no state written. |

## Doctor Relationship

Binding drift — a disabled binding with stale router routes or runtime entries
still present — belongs to `doctor --family=app`. See
[`app-doctor.md`](../../app-doctor.md) for the authoritative app-family probe
and repair contract; doctor semantics are not restated here.

## Activity Logging

The gateway API endpoint emits an activity entry for every successful and failed
disable attempt.

| Field | Value |
| --- | --- |
| Type | `api:POST /apps/{app}/websocket/disable` |
| Effect | `write` |
| Subject | App record resolved from `{app}`. |
| Properties | `action=disable`, `target_app`, `public_hosts` (the cleared host list, `[]`). |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Http/Api/AppWebSocketControllerTest.php` | Disable binding state mutation, `public_hosts` cleared to `[]`, credential preservation, `websocket.binding_missing` path, authorization check, and `app.not_found` path. |
| `apps/gateway/tests/Unit/Services/WebSockets/WebSocketBindingServiceTest.php` | Service-level disable state transition and credential retention across disable. |
