# `orbit process-event:stream`

[Back to Process commands.](../../README.md)

**Visibility:** Internal. Hidden from the public command list.

**Purpose:** Document the process lifecycle live stream surface for browsers
and reconcile the internal process-event stream name with the gateway SSE route.

**Description:** Live process lifecycle for browsers is the gateway-origin SSE
route **`GET /api/processes/stream?app=<hostname>`**. That route is the
authoritative product surface for toolbar status: durable `process_events`,
app-only selector, WireGuard peer auth + `process:read`, and no client polling.

Internal CLI `process-event:stream` framing (node/instance filters, live unit
probes, NDJSON) is not the browser contract and must not be treated as an
alternative toolbar transport.

**Owner:** `process`.

**Effects:** `stream`, `internal`.

**Technical contract:** [`technical/1_process-event-stream.md`](technical/1_process-event-stream.md)

**Prerequisites:**
- Browser callers reach the Orbit gateway over WireGuard with `process:read` on
  the resolved serving node.
- The `app` query is a strict registered proxy-route hostname (no scheme/path/port).

## Behavior

### Browser gateway SSE (authoritative)

This is the live status transport toolbars use against the gateway.

- `GET /api/processes/stream?app=<hostname>`
- Every connect emits a full canonical snapshot at a durable high-water mark
  (SSE `id` of that mark, `0` when no events yet). Snapshot process items
  include `key`, durable `label`, and deprecated `name` (= `key`).
- Then ordered lifecycle updates from `process_events` after that mark only.
  Update frames expose `key` and `name` aliases; they include current `label`
  when the matching process row is reliably available, otherwise fall back to
  the durable key. Snapshot remains authoritative for display labels when an
  update cannot carry a current label.
- `Last-Event-ID` is accepted for native EventSource reconnect and never replays
  history after the fresh snapshot.
- Transitional durable events: `starting`, `stopping`, `restarting`.
- Terminal durable events: `started`, `stopped`, `crashed`, `failed` (status
  `unknown`).
- Scope tails by app instance + workspace|null + node so processes added after
  connect still stream.

### Constraints

These limits keep the stream read-only and app-scoped.

- Does not mutate process configuration or runtime state.
- Does not live-probe nodes for toolbar status.
- Does not accept `url` or non-`app` query selectors on the browser stream.

## Doctor Relationship

[`process-doctor.md`](../../process-doctor.md) verifies rendered runtime
artifacts. Event history itself is not desired state.
