# Gateway API Client And Transport Workstream

## Decision (GATEWAY-API-0)

`saloonphp/saloon` v4.0.0 is the gateway API transport. The original plan
of a hand-rolled `GatewayClient` over Laravel's `Http` facade was reversed
because the hand-rolled `GatewayRequest` interface was already a literal
subset of Saloon's, with weaker mocking, no plugin pipeline, and no typed
response DTOs. Saloon is the de facto Laravel typed-HTTP client; switching
while the surface was small was the cheapest moment.

## Footprint

- `App\Http\Gateway\GatewayConnector` — single connector; base URL + CA
  verify + correlation header plugin from `LocalGatewaySettings::current()`.
- `App\Http\Gateway\GatewayRequest` — abstract base with envelope-aware
  `hasRequestFailed()` / `getRequestException()` / `unwrapData()`.
- `App\Http\Gateway\GatewayApiException` — thrown on envelope errors.
- `App\Http\Gateway\Plugins\HasCorrelationHeader` — Saloon plugin trait
  for `X-Orbit-Request-Id` and `X-Orbit-Client`.
- `App\Http\Gateway\Requests\<Family>\` — per-endpoint typed Saloon
  requests; responses under `App\Http\Gateway\Responses\<Family>\`.

Out of scope: `FetchGatewayRootCa` stays on `Http` (runs before CA exists,
needs no-verify + redirect handling). `GatewayApiRuntimeInstaller` is a
server-side install helper, not a client.

Reusable implementation guidance: see
[`../abstractions/cross-cutting.md`](../abstractions/cross-cutting.md) for
the Saloon-based gateway transport pattern.

## Per-family transport status

- [x] Node — list, show, create, grant, revoke, remove, update, default
  (Saloon request/DTO + command forwarding).
- [x] App — list, show, create, register, root update, remove, agent-IDE
  override.
- [x] Workspace — list, show, history, log, setup-step
  add/list/remove, teardown-step add/list/remove.
- [x] Process — list, add, edit, remove, start, stop, restart, logs.
- [x] Schedule — add, list, show, remove, run, logs, scheduler heartbeat,
  run-history intake, registry sync.
- [x] Tool — list, show.
- [x] Activity — list, show.
- [x] Profile — `ShowProfileRequest` (gateway-origin profile execution).
- [~] Doctor — `RunDoctorRequest` for verify-mode runs covers `node`,
  `proxy`, `firewall_rule`, `tool`, `schedule`. `app`/`workspace`/`process`
  not yet wired into the dispatcher. Streaming progress and family-owned
  fix/adopt handlers remain blocked per family.

## Cross-cutting

- [x] Gateway API envelope conventions, request correlation header,
  WireGuard identity middleware, `/api/me`.
- [x] Long-running SSE progress primitives: `ProgressReporter` contract,
  SSE/null reporters, event-stream emitter, response factory. Client-side
  stream consumers will be added with the first command that needs
  cross-node interactive streaming.
