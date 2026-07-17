# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: none
- Worktree: `/Users/nckrtl/orbit/.worktrees/websocket-valkey-default`
- Branch: `websocket-valkey-default`

## Goal

The Orbit `websocket` role uses a managed Valkey service as its canonical and
default distributed Reverb broker, including role input, persisted settings,
runtime rendering, diagnostics, and prepared-topology support.

## Scope

- Owned: websocket role docs and decision ledger; Valkey process catalog entry;
  websocket role/API/CLI settings and validation; Reverb runtime broker
  rendering and doctor output; removal of the Redis managed-service catalog
  entry and command-facing support; prepared-topology fixtures and focused tests.
- Constraints: keep Reverb's upstream `REDIS_*` environment variable names;
  require the selected provider to be an active database-role node with a
  managed Valkey process; use the existing private WireGuard service address;
  retain upstream compatibility names such as Reverb's `REDIS_*` variables and
  PHP's `redis` extension; no supported Redis service aliases or read fallbacks.
- Out of scope: multiple active websocket backends, gateway operations Reverb,
  Valkey authentication/TLS, and renaming upstream Redis-protocol compatibility
  surfaces that Orbit does not control.

## Proof

- Verification:
  - focused: passed - gateway, CLI, migration, doctor, runtime, SDK, docs, and
    native macOS regression suites passed; docs lint, format, static analysis,
    type checking, secret scan, and diff hygiene passed.
  - broader: passed - `composer quality-check` passed at
    `02f2c327d9fd49417dd56d04e4d760eeaa894d6e`; gateway 4,911 tests / 28,551
    assertions, CLI 2,285 tests / 9,529 assertions, Core 129 tests / 538
    assertions, and docs/SDK/static/format/Rector/Rust lanes passed. Pest proof:
    `.orbit/quality-gates/profiles/2026-07-17T11-07-55Z-02f2c327d9fd/gateway_pest.log`,
    `.orbit/quality-gates/profiles/2026-07-17T11-07-55Z-02f2c327d9fd/cli_pest.log`,
    `.orbit/quality-gates/profiles/2026-07-17T11-07-55Z-02f2c327d9fd/core_pest.log`.
  - host macOS: passed - the native dashboard suite passed 5 tests, including
    exact Valkey-database and Redis-rejection coverage; `npm run typecheck`
    passed after hydrating the locked SDK and macOS dependencies on this Mac.
  - runtime: passed - retained Incus topology `dev-e07ce3`, kind
    `operator_gateway_app-dev_websocket`, on `beast`; Valkey 8.1 was the only
    registered managed service, returned `PONG`, and Reverb ran with zero
    restarts while Docker published `8080` only on `10.6.0.4`. TLS connected
    from both dev and gateway hosts, and process/tool/WebSocket bind doctor
    checks were healthy. The same retained node was then converted to a running
    Redis 7.2 legacy state; the committed migration and process restore removed
    Redis, established Valkey 8.1, cleared the runtime-replacement marker, and
    left Reverb at zero restarts. Evidence: `.orbit/evidence/runtime-proof.txt`.
- Blast radius: complete - evidence=repository-wide Redis command/settings/catalog inventory plus command-catalog regeneration; result=only historical decisions, required upstream compatibility names, and explicit legacy migration fixtures remain
- Review: passed - independent reviewer - human-judgment=not-required
- Reviewed feature tip: 02f2c327d9fd49417dd56d04e4d760eeaa894d6e
- Acceptance venue: host-macos
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 02f2c327d9fd49417dd56d04e4d760eeaa894d6e
- Accepted main tip: 01ae9c9e080a32ce5d689ac433e696fd27251648

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
