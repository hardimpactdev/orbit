# Gateway API Client And Transport Workstream

## GATEWAY-API-0 Decision: Gateway API Client Transport

**Status:** Reversed — adopt `saloonphp/saloon` as the gateway API transport.

**Original decision (superseded):** thin `GatewayClient` wrapper over Laravel's
`Http` facade with a hand-rolled `GatewayRequest` interface,
`GatewayRequestSender`, and `GatewayResponseParser`.

**Version note:** `saloonphp/saloon` v4.0.0 is the selected dependency. The plan
originally targeted Saloon v3, but Packagist security advisories blocked v3
resolution against PHP 8.5 / Laravel 13. v4.0.0 resolves cleanly and provides
the same connector/request/DTO architecture.

**Reversal rationale:**

1. **The hand-rolled abstraction was reinventing Saloon poorly.** The clean repo's
   `GatewayRequest` interface (`method()/path()/query()/data()`) is a literal
   subset of Saloon's `Request` shape, with weaker mocking, no plugin pipeline,
   no typed response DTOs, and no community familiarity.

2. **Cost of the abstraction was already paid.** With 7 typed requests + a
   sender + an envelope parser + an interface in place, we were carrying
   abstraction cost equivalent to a Saloon footprint without any of its
   benefits. The cheapest moment to switch was while the surface was still
   small — exactly the moment we were in.

3. **Off-the-shelf > home-grown for proven patterns.** Saloon is the de facto
   standard for typed HTTP clients in Laravel. New contributors recognize it.
   Plugin ecosystems (logging, retries, OAuth) are first-class. The "we don't
   need a taxonomy yet" framing in the original decision cuts the other way:
   if a taxonomy is inevitable, build it on the well-trodden tool.

4. **Typed responses unlock real value.** Saloon's `createDtoFromResponse()`
   replaces `array` plumbing with typed DTOs at every caller — caller code
   reads `$dto->nodes` instead of `$response->data()['nodes'] ?? []`.

**Implementation footprint:**

- `App\Http\Gateway\GatewayConnector` — single connector, base URL + CA verify
  + correlation header plugin from `LocalGatewaySettings::current()`
- `App\Http\Gateway\GatewayRequest` — abstract base with envelope-aware
  `hasRequestFailed()` / `getRequestException()` / `unwrapData()` helpers
- `App\Http\Gateway\GatewayApiException` — thrown on envelope errors
- `App\Http\Gateway\Plugins\HasCorrelationHeader` — Saloon plugin trait for
  `X-Orbit-Request-Id` and `X-Orbit-Client` headers
- `App\Http\Gateway\Requests\<Family>\` — per-endpoint typed Saloon requests
- `App\Http\Gateway\Responses\<Family>\` — typed response DTOs

**Out of scope for the migration:**

- `FetchGatewayRootCa` stays on `Http`. It runs before the CA exists, has
  unique connector requirements (no verify, redirect handling), and gains
  nothing from a Saloon migration.
- `GatewayApiRuntimeInstaller` is a server-side install helper, not a client.

**Reusable implementation guidance:** see
[`../abstractions/cross-cutting.md`](../abstractions/cross-cutting.md) for the
Saloon-based gateway transport pattern.

## Remaining workstream items

- [x] Decide the clean-rebuild transport approach before adding packages.
- [x] Create thin `GatewayClient` wrapper (superseded by Saloon and removed).
- [x] Port gateway API envelope conventions.
- [x] Port request correlation header support.
- [x] Port typed gateway request sender.
- [x] Port WireGuard identity middleware.
- [x] Port `/api/me`.
- [x] Migrate gateway transport from hand-rolled `GatewayClient` /
  `GatewayRequestSender` to Saloon (`saloonphp/saloon` v4.0.0). Single
  `GatewayConnector` with abstract `GatewayRequest` base handles envelope
  unwrapping and typed `GatewayApiException`. Per-endpoint Saloon `Request`
  subclasses return typed DTOs from `App\Http\Gateway\Responses\<Family>\`.
- [x] Port node API controllers and typed client requests.
  - Implemented with Saloon request/DTO classes for list, show, create,
    grant, revoke, remove, update, and default-node API shapes.
  - Command forwarding paths covered: `node:list`, `node:show`, `node:new`
    app-node creation forwarding, `node:grant`, `node:revoke`, `node:remove`,
    `node:update`, and `node:default` app-node discovery/validation.
  - The old hand-rolled client/request/parser tests have been deleted in favor
    of `tests/Unit/Http/Gateway/Requests/Nodes/*` plus command feature tests
    using Saloon `MockClient`.
- [x] Port app API controllers and typed client requests.
  - Implemented with gateway API controllers plus Saloon request/DTO classes
    for list, show, create, register, root update, remove, and agent IDE
    override endpoints. Command forwarding paths use typed gateway requests;
    command, API, and gateway-client coverage lives alongside the app command
    slices.
- [x] Port workspace API controllers and typed client requests.
  - [x] `workspace:list` API controller plus Saloon request/DTO.
  - [x] `workspace:show` API controller plus Saloon request/DTO for named
    registry lookup.
  - [x] `workspace:history` API controller plus Saloon request/DTO.
  - [x] `workspace:log` API controller plus Saloon request/DTO.
  - [x] `workspace-setup-step:list` and `workspace-teardown-step:list` API
    controller plus Saloon request/DTO.
  - [x] `workspace-setup-step:add` and `workspace-teardown-step:add` API
    controller plus Saloon request/DTO.
  - [x] `workspace-setup-step:remove` and `workspace-teardown-step:remove` API
    controller plus Saloon request/DTO.
- [x] Port process API controllers and typed client requests.
  - Implemented with gateway API controllers plus Saloon request/DTO classes
    for list, add, edit, remove, start, stop, restart, and logs endpoints.
    Command forwarding paths use typed gateway requests; command, API, and
    gateway-client coverage lives alongside the process command slices.
- [x] Port tool/service API controllers and typed client requests after tool
  docs are converted.
  - [x] Tool abstraction seed exists at `docs/abstractions/3_tool.md`.
  - [x] Tool read API foundation exists for gateway-owned registry reads:
    `node_tools` schema/model/factory, `GET /api/tools`,
    `GET /api/tools/{tool}`, and typed Saloon list/show requests.
  - [x] `tool:list` and `tool:show` commands are wired through the typed
    gateway requests for non-gateway callers and local registry reads on the
    gateway.
- [~] Port doctor API controllers and typed client requests after doctor docs
  are converted.
  - [x] Verify-mode doctor bootstrap exists with `doctor --family=node --json`,
    `POST /api/doctor/run`, typed Saloon request/DTO, and in-memory node-family
    probe orchestration.
  - [x] Generic `--fix` / `--adopt` mode routing reaches family dispatch and
    records unsupported issue actions as skipped without running side effects.
  - [!] Streaming progress and family-owned fix/adopt action handlers remain
    blocked until each family action map is ported.
- [x] Port long-running SSE progress primitives.
  - Server-side progress primitives are available through the shared
    `ProgressReporter` contract, SSE/null reporters, event-stream emitter, and
    streamed response factory. Current tests cover frame emission, failure
    conversion, and reporter restoration. Client-side long-running stream
    consumers should be ported with the first command that needs cross-node
    interactive streaming.
