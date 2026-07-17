# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: none
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-analytics-deployment-route`
- Branch: `codex/analytics-deployment-route`

## Goal

Deploying the fleet-singleton analytics role converges Plausible and the
router-owned `analytics.orbit` route with TLS, proxy doctor repairs route drift,
and removing the role removes the route and its artifacts.

## Scope

- Owned: analytics role assignment/convergence, analytics route registration,
  proxy-family analytics doctor coverage, analytics/node/proxy authority docs,
  and focused gateway tests.
- Constraints: reject a second analytics assignment before
  provisioning; keep proxy ownership of route rows, artifacts, and TLS; keep
  docs, tests, and implementation aligned; prove the result on `services1`.
- Out of scope: multiple analytics backends, analytics HA, public dashboard
  exposure, and changes to per-app analytics tracking behavior.

## Proof

- Verification:
  - focused: passed - 22 analytics route/runtime/binding tests passed with 101
    assertions.
  - broader: passed - the exact feature tip passed 4,920 gateway tests with 28,591
    assertions and the complete sequential CLI suite passed 2,293 tests with
    9,568 assertions. Docs lint, Mago format, and `git diff --check` passed.
    Full gate profiles completed every non-CLI component; the parallel CLI
    shard was externally terminated, while its complete sequential corpus
    passed. Evidence: `.orbit/evidence/runtime-proof.txt`.
  - runtime: passed - live gateway/services1 proof converged analytics.orbit to
    Plausible at 10.6.0.14:8000, issued a DNS:analytics.orbit leaf from the
    gateway CA, returned HTTP 302 over verified HTTPS, and passed the deployed
    analytics-specific proxy doctor; retained Incus also proved failure-safe
    role state before activation. Evidence:
    `.orbit/evidence/runtime-proof.txt`.
- Blast radius: complete - evidence=repository-wide analytics role, route lifecycle, and singleton-language searches plus docs lint; result=the role service is the only production owner of private route creation and removal, app binding only consumes it, and no multiple-backend contract remains.
- Review: passed - findings=none; human-judgment=not-required
- Reviewed feature tip: 4dc3974db001fc44ffd9d82e3590ec67c46692fc
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 4dc3974db001fc44ffd9d82e3590ec67c46692fc
- Accepted main tip: b222087151acfb8cbd92c26dd44996efb1bf1afd

## Status

- State: land
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`

Allowed states are `frame`, `build`, `prove`, `accept`, `accepted`, `land`, and
`blocked`. Review must be `passed`, `fix`, or `escalate`. Acceptance must be
`pending`, `accepted`, `not-applicable`, or `changes-requested`. A terminal
review PASS must record `human-judgment=required|not-required` and the exact
committed HEAD in `Reviewed feature tip`. Blast radius must record either a
concrete not-required reason or complete evidence and result before acceptance;
`gaps` returns to BUILD.
Proof files retained by the compact archive must be cited as one exact
inline-code path, such as `.orbit/evidence/runtime-proof.txt`; prose,
directories, padded code spans, and partial paths are not proof citations.
