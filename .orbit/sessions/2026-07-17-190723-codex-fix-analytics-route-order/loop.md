# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: none
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-fix-analytics-route-order`
- Branch: `codex/fix-analytics-route-order`

## Goal

Public app analytics routes proxy Plausible tracking scripts and events through
the ingress/router path while returning 404 for every other public path, with
the live Mealou and Hauzer tracking endpoints working over HTTPS.

## Scope

- Owned: `ProxyRouteRenderer` public analytics Caddy output and its focused tests.
- Constraints: preserve the tracking-only public surface and the private dashboard; keep product docs aligned.
- Out of scope: unrelated proxy route behavior and application-side analytics UI.

## Proof

- Verification:
  - focused: passed - `bin/orbit-gateway-pest --compact tests/Unit/Services/Proxy/ProxyRouteRendererTest.php` (36 passed, 115 assertions); Caddy 2 `adapt --validate` accepted the tracking-only handler structure
  - broader: passed - analytics binding (11 tests), doctor (5 tests), and controller (9 tests) suites passed; gateway quality lane passed; aggregate CLI shard SIGTERM triaged by exact shard rerun (655 passed, 2280 assertions)
  - runtime: passed - retained Incus topology dev-728b2c on beast; candidate tracking paths proxied and non-tracking paths returned Caddy 404; evidence `.orbit/evidence/runtime-proof.txt`
- Blast radius: complete - evidence=independent bounded search across 103 analytics/proxy references; result=no unresolved transport, ownership, docs, caller, convergence, doctor, or test surface
- Review: passed - independent general review; human-judgment=not-required
- Reviewed feature tip: 1932c6b857c8cdd1df4c30bf292d7f59d7ede974
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 1932c6b857c8cdd1df4c30bf292d7f59d7ede974
- Accepted main tip: 9f36134b9a57be4170917e5a2cde78ddaf30a8e1

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`

Allowed states are `frame`, `build`, `prove`, `accept`, `accepted`, `land`, and
`blocked`. Review must be `passed`, `fix`, or `escalate`. Acceptance must be
`pending`, `accepted`, `not-applicable`, or `changes-requested`. A terminal
review PASS must record `human-judgment=required|not-required` and the exact
committed HEAD in `Reviewed feature tip`. Blast radius must be `not-required`
with a reason or `complete` with bounded evidence and a result before
acceptance; `gaps` returns to BUILD.
Proof files retained by the compact archive must be cited as one exact
inline-code path, for example `.orbit/evidence/runtime-proof.txt`; prose,
directories, padded code spans, and partial paths are not proof citations.
