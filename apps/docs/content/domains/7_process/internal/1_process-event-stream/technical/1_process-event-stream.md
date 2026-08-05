# Technical Contract: process lifecycle stream (browser gateway SSE)

[Back to internal stream documentation.](../process-event-stream.md)

**Owner:** `process`.

**Effects:** `stream`.

**Prerequisites:**
- The browser or SDK client can reach the Orbit gateway over WireGuard.
- Peer identity maps to a node with `process:read` on the serving node for the
  resolved app hostname.
- Optional `Origin` is admitted only when it matches the requested `app`
  hostname as a registered app/workspace proxy domain (default scheme/port).

## Public browser route

```http
GET /api/processes/stream?app=<hostname>
```

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `app` | query | Always. | Never. | None. | Strict hostname only (exact registered proxy route domain). No scheme, path, or port. |
| `url` | query | Never. | Always. | — | Rejected. |
| other selectors (`node`, `instance`, `workspace`, timing knobs) | query | Never. | Always on this route. | — | Rejected with `stream_app_only`. |
| `Last-Event-ID` | header | Optional native EventSource reconnect. | Never required. | None. | Accepted; **never** used to replay rows at or below the connect-time high-water mark after the fresh snapshot. |

`X-Orbit-Client` is optional and never required (native EventSource cannot set it).

## Stream output contract

Response headers include `Content-Type: text/event-stream`, `Cache-Control: no-cache`,
`Connection: keep-alive`, and `X-Accel-Buffering: no`. Heartbeats are SSE
comments (`: heartbeat`) on a heartbeat cadence independent of the internal DB
poll interval.

| Frame | SSE | Required data | Meaning |
| --- | --- | --- | --- |
| `snapshot` | `event: snapshot`, `id: <high_water>` | `app`, `context`, `processes[]` (`key`, `label`, deprecated `name`), `cursor.high_water_mark` | Full canonical process list for the app scope at connect. `id` is the durable high-water (`0` when no events). Snapshot is authoritative for current display labels. |
| `update` | `event: update`, `id: <process_events.id>` | `id`, `event`, `status`, `key`, deprecated `name`, `label` (current process label when reliably available, otherwise the durable key), scope fields | Ordered lifecycle row after the snapshot high-water. |
| `error` | `event: error` | `code`, `message`, `meta` | Terminal stream failure after open. |

Process identity in public process payloads uses `key` (stable slug) and
`label` (durable display). Deprecated `name` remains equal to `key` for
compatibility. New consumers must use `key` and `label`.

### Status and event values

| Durable `event` | Normalized `status` |
| --- | --- |
| `starting` | `starting` |
| `started` | `running` |
| `stopping` | `stopping` |
| `stopped` | `stopped` |
| `restarting` | `restarting` |
| `crashed` | `crashed` |
| `failed` | `unknown` |
| (none) | `unknown` |

Lifecycle commands record the transitional event **before** the runtime call and
the terminal event **after** success or failure (including when the driver
throws: record `failed`, then rethrow). Failed actions must not leave status
stuck at starting/stopping/restarting and must not fabricate crashed/running/stopped.

## Behavior contract

1. Resolve `app` hostname to concrete app instance (and optional workspace) plus
   serving node; authorize `process:read`.
2. In one DB transaction, capture high-water for scope
   (`instance_id`, `workspace_id` or null, `node_id`) and build the process
   list snapshot for that app context.
3. Emit `snapshot` with SSE id = high-water.
4. Tail durable `process_events` after high-water by the same scope filters
   (not a frozen process-id list) so newly configured processes stream.
5. Emit ordered `update` frames; heartbeat comments on the heartbeat interval;
   exit on client disconnect.

## Failure semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| Missing app | `app` absent. | `validation_failed`, `field=app`. |
| Non-app query | Any query key other than `app`. | `validation_failed`, `reason=stream_app_only`. |
| Authorization | Unknown peer or missing `process:read`. | `authorization_failed`. |
| Origin mismatch | Browser `Origin` not admitted for `app`. | `authorization_failed` (CORS). |
| Stream failed | Follow loop throws after open. | SSE `error` frame `process.event_stream_failed`. |

## Test mapping

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Http/Api/ProcessStreamControllerTest.php` | Auth, CORS, app-only query, snapshot id/high-water, Last-Event-ID no regression, ordered updates, new process after connect. |
| `apps/gateway/tests/Feature/Services/Processes/ProcessEventStreamerTest.php` | Scope high-water, follow after mark, post-connect process events, heartbeat vs poll. |
| `apps/gateway/tests/Feature/Actions/Processes/ProcessLifecycleTransitionEventsTest.php` | Transitional→terminal ordering; false and exception → failed/unknown. |
