# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: /private/tmp/claude-501/-Users-nckrtl-orbit/9d84cd44-8ab7-472d-94d1-62c9fa19f7c9/scratchpad/brief-207.md
- Worktree: /Users/nckrtl/orbit/.worktrees/solo-hardening-proxy-write-guard
- Branch: solo-hardening-proxy-write-guard

## Goal

Reject invalid ProxyRoute ownership tuples at Eloquent save time and emit
`proxy.owner_invalid` drift for unknown persisted `owner_type` values.

## Scope

- Owned: `apps/gateway` ProxyRoute write guard, ProxyRouteProbe unknown-owner drift, focused Pest coverage, proxy-concepts/proxy-doctor alignment
- Constraints: reuse existing ownership resolvers; do not change persisted owner_type vocabulary or ProxyRouteQuery public `instance` projection; skip SQLite CHECK if it needs a table rebuild
- Out of scope: live-node repair, E2E lanes, owner_type vocabulary changes, merge/push

## Proof

- Verification:
  - focused: passed - ProxyRouteOwnerInvariantTest plus ownership/probe/persist suites
  - broader: passed - `composer quality-check` on clean merged commit 99fc3a731575d5d060aa962e9079d52819b96e8d exit 0, 45/45 subgates (`.orbit/quality-gates/quality-check-2026-08-18T180933Z-24b972ae43a9.json`); pre-merge full gateway suite 6954 passed 2 skipped
  - runtime: passed - candidate=99fc3a731575d5d060aa962e9079d52819b96e8d; venue=retained-incus; environment=dev-fixture; target=orbit-e2e-dev-3c8e9b-gateway; expected=exact candidate rejects invalid ownership tuples at save and emits owner_invalid drift for unknown owner_type while registrar backfill and adoption writes stay green in the routed retained gateway environment; observed=matching ProxyRoute.php sha256 e734c37a2692 and 180 tests passed 564 assertions in the retained gateway instance; result=passed; evidence=`.orbit/evidence/solo-hardening-proxy-write-guard-retained-incus-runtime.txt`
- Blast radius: complete - evidence=repository-wide rg for ProxyRoute quiet writes and raw proxy_routes inserts plus full gateway Pest suite; result=no production saveQuietly or updateQuietly or raw insert writes ProxyRoute outside the guard, test seeding of invalid rows moved to explicit bypass helpers, unknown owner_type now visible drift, full suite 6954 passed
- Review: passed - orchestrator Claude reviewer VERDICT PASS: saving guard unsets relations before resolver validation preventing stale-relation spoofing, per-family invariants delegated to the three existing resolvers as single source of truth, unknown and persisted-instance owner types throw at save and surface as drift in checkOwnerEligibility, DB CHECK skip justified by SQLite rebuild risk; human-judgment=not-required
- Reviewed feature tip: 99fc3a731575d5d060aa962e9079d52819b96e8d
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 99fc3a731575d5d060aa962e9079d52819b96e8d
- Accepted main tip: 0ce43e0777e2020a5ed6220f6db305f3b8866a8c

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
