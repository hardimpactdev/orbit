# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: none
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-process-sse-sdk`
- Branch: `codex/process-sse-sdk`

## Goal

Browser toolbars subscribe to gateway-origin SSE at `GET /api/processes/stream?app={hostname}` with durable DB-cursor process lifecycle events (snapshot then ordered updates), truthful transitional statuses (`starting`/`stopping`/`restarting`) before runtime and terminal `running`/`stopped`/`crashed`/`unknown` after (including failure closure as `failed`→`unknown`). TypeScript SDK 0.2.0 exposes a typed native EventSource subscriber. No client polling.

## Scope

- Owned:
  - `apps/gateway` process event types/status, lifecycle RecordProcessEvent paths, SSE stream controller/streamer/CORS/OpenAPI
  - `packages/sdk-typescript` (private in-tree, version 0.2.0, not released)
  - `apps/docs/content` process/tech-stack contracts and `PRODUCT_DECISIONS.md`
- Constraints:
  - app is only browser stream selector; never accept `url`
  - Preserve GET list and POST lifecycle selector behavior
  - Same Origin/CORS + WireGuard/grant `process:read`; stream must not require `X-Orbit-Client`
  - Durable `process_events` cursor; cross-worker via DB not pub/sub
  - No SDK npm publish, deploy, live fleet mutation, or VERSION change in this land
  - No `composer test:e2e*`
- Out of scope:
  - Laravel Toolbar PHP controller/filesystem watcher
  - Client polling fallback
  - npm release of sdk-typescript
  - Operations WebSocket migration of this surface

## Proof

- Verification:
  - focused: passed - gateway lifecycle/stream/list filter 67 tests / 282 assertions on exact tip `b47a9063ca9ed6403e89eeae85d28e5c1453424b`; packages/sdk-typescript `npm run test:runtime` 10/10 + typecheck clean; multi-context process:update --restart workspace_id scoping regression included
  - broader: passed - `composer quality-check` exit 0 on clean dirty=false tip `b47a9063ca9ed6403e89eeae85d28e5c1453424b` all subgates 0 via `.orbit/quality-gates/quality-check-2026-08-04T135348Z-6f5b9c953b96.json`
  - runtime: passed - independent review PASS on exact tip; gateway ProcessStreamController/ProcessEventStreamer/lifecycle Pest plus quality-check exercise the SSE and durable-event contract on that HEAD; no retained topology id claimed for this land slice
- Blast radius: complete - evidence=repository-wide process stream/lifecycle surfaces (gateway SSE controller/streamer/scope, RecordProcessEvent start/stop/restart/edit multi-unit paths, process list status scope, OpenAPI public_sdk stream op, TypeScript EventSource subscriber, process domain docs and PRODUCT_DECISIONS); result=per-unit workspace scoping for multi-context restart, SDK path/cursor validation, and deploy activator exclusion closed with no residual review findings
- Review: passed - human-judgment=not-required - VERDICT=PASS BLAST_RADIUS=complete
- Reviewed feature tip: b47a9063ca9ed6403e89eeae85d28e5c1453424b
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: b47a9063ca9ed6403e89eeae85d28e5c1453424b
- Accepted main tip: 5ae00b6e2eca96dea82d80c3ecae6fc68f6a5eee

## Status

- State: land
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
