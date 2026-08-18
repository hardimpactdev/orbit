# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: /private/tmp/claude-501/-Users-nckrtl-orbit/9d84cd44-8ab7-472d-94d1-62c9fa19f7c9/scratchpad/brief-208.md
- Worktree: /Users/nckrtl/orbit/.worktrees/solo-hardening-proxy-tuple-dedup
- Branch: solo-hardening-proxy-tuple-dedup

## Goal

Extract one authoritative public analytics/websocket proxy route tuple definition consumed by both registrars and PublicBindingProxyRouteOwnership, and replace ProxyRouteInstanceOwnershipArchitectureTest source-substring assertions with registrar-write plus validator-accept/reject behavioral coverage. No change to rendered routes or validation outcomes.

## Scope

- Owned: apps/gateway/app/Services/Proxy public-binding tuple definition; AnalyticsRouteRegistrar and WebSocketRouteRegistrar public-route writers; PublicBindingProxyRouteOwnership; apps/gateway/tests/Unit/ProxyRouteInstanceOwnershipArchitectureTest.php
- Constraints: pure refactor; existing proxy ownership suites including ProxyRouteOwnerInvariantTest stay green; work only in this worktree; do not merge or push
- Out of scope: service-route configs, S3 public routes, live nodes, E2E lanes, merge to main

## Proof

- Verification:
  - focused: passed - `bin/orbit-gateway-pest --compact tests/Unit/ProxyRouteInstanceOwnershipArchitectureTest.php` 36 passed 70 assertions; focused proxy ownership bundle 133 passed on merged tip 699526a11
  - broader: passed - `composer quality-check` on clean merged commit 699526a112cc145d6c7565fc9b60561e0e2c8fd6 exit 0, 45/45 subgates (`.orbit/quality-gates/quality-check-2026-08-18T182950Z-f89eb9733c00.json`); pre-merge full gateway suite 6991 passed 2 skipped
  - runtime: passed - candidate=699526a112cc145d6c7565fc9b60561e0e2c8fd6; venue=retained-incus; environment=dev-fixture; target=orbit-e2e-dev-612b96-gateway; expected=exact candidate keeps registrar-written public binding routes validator-accepted and each mutated ownership field validator-rejected in the routed retained gateway environment; observed=matching PublicBindingProxyRouteDefinition sha256 02bf22547eb4 and 76 tests passed 217 assertions in the retained gateway instance; result=passed; evidence=`.orbit/evidence/solo-hardening-proxy-tuple-dedup-retained-incus-runtime.txt`
- Blast radius: complete - evidence=rg inventory of PublicBindingProxyRouteDefinition consumers plus full gateway Pest suite; result=definition consumed by both registrars and the validator with no remaining byte-duplicated public config arrays, service-route configs and S3 routes untouched, substring architecture assertions replaced by registrar-write validator-accept-reject behavioral coverage for both families, full suite 6991 passed
- Review: passed - orchestrator Claude reviewer VERDICT PASS: single authoritative tuple definition with private constructor and closed forOwnerType consuming registrar constants, behavioral round-trip tests supersede substring checks, deleted substring guards for resolver internals remain covered by registrar and EnsureAppProxyRoute suites per handoff; human-judgment=not-required
- Reviewed feature tip: 699526a112cc145d6c7565fc9b60561e0e2c8fd6
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 699526a112cc145d6c7565fc9b60561e0e2c8fd6
- Accepted main tip: ab7e437ed0871688222b575a83c5b6bab79a18e0

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
