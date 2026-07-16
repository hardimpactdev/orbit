# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: none
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-fix-update-and-doctor-scope`
- Branch: `codex/fix-update-and-doctor-scope`

## Goal

Same-version immutable CLI updates replace both the gateway host launcher and
the running gateway container CLI, while app-scoped proxy Doctor reports and
restores only the requested app's route artifacts.

## Scope

- Owned:
  - gateway host CLI install and gateway service refresh
  - proxy-family Doctor app-scope selection
  - focused gateway and CLI regression coverage
- Constraints:
  - preserve artifact-identity comparison by checksum
  - keep shared node-level Caddy readiness/global checks available to scoped routes
  - do not run manual-only `composer test:e2e*` commands
- Out of scope:
  - Hauzer backend HTTP 502
  - Cloudflare edge certificate/API-token failures
  - BEAST deployment or routing

## Proof

- Verification:
  - focused: passed - gateway 227 tests/1509 assertions; CLI 72 tests/603 assertions; real gateway-container round trip and host CLI refresh 2 tests/9 assertions
  - broader: passed - `composer quality-check`; proof `.orbit/quality-gates/quality-check-2026-07-16T162636Z-da92a65aa4e6.json`
  - runtime: passed - retained Incus topology `dev-4ef733` reproduced exact router failure metadata, scoped one-run Hauzer restore, full backend -> router -> ingress enactment, Hauzer converged, and unrelated Mealou remained partial; proof `.orbit/evidence/retained-topology-proof.txt`
- Blast radius: complete - evidence=independent main...HEAD review plus proxy-route, app-instance, WebSocket, updater command-shape, and real-container regressions; result=merged production app-instance behavior composes with full re-enactment and unrelated app routes remain excluded
- Review: passed - independent exact-tip review found no findings - human-judgment=not-required
- Reviewed feature tip: d4d25f74d2256abcfa20e28cf660c7267e983ee9
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: d4d25f74d2256abcfa20e28cf660c7267e983ee9
- Accepted main tip: ea28132f2dfc042134fb89c41f0e3bd7a9780053

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`

Allowed states are `frame`, `build`, `prove`, `accept`, `accepted`, `land`, and
`blocked`. Review must be `passed`, `fix`, or `escalate`. Acceptance must be
`pending`, `accepted`, `not-applicable`, or `changes-requested`. A terminal
review PASS must record `human-judgment=required|not-required` and the exact
committed HEAD in `Reviewed feature tip`. Blast radius must be
`not-required - reason` or `complete - evidence=repository-wide search,
inventory, or lintable check; result=summary` before acceptance; `gaps` returns
to BUILD.
Proof files retained by the compact archive must be cited as one exact
inline-code path, for example `.orbit/evidence/runtime-proof.txt`; prose,
directories, padded code spans, and partial paths are not proof citations.
