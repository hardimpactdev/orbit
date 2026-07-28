# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: user-reported nckrtl.nmbp cold-start 502
- Worktree: /Users/nckrtl/orbit/.worktrees/codex-fix-instance-route-convergence
- Branch: codex/fix-instance-route-convergence

## Goal

Re-enacting a logical project with multiple concrete Orbit instances converges one canonical hibernation-aware proxy route per instance, and a cold request to nckrtl.nmbp wakes its owning process group and returns the website.

## Scope

- Owned: `apps/gateway/app/Actions/Apps/EnactAppRuntime.php`, `apps/gateway/app/Actions/Apps/EnsureAppProxyRoute.php`, focused gateway tests, exact NMBP runtime proof
- Constraints: preserve the official Caddy image and standard-directive wake gate; deploy only through a release candidate; do not publish a GitHub release or version
- Out of scope: custom Caddy modules, unrelated proxy/process drift, generic command redesign

## Proof

- Verification:
  - focused: passed - 49 gateway tests, 521 assertions; 18 CLI tests, 110 assertions
  - broader: passed - composer quality-check at d6933487eb90f85fd69f18c9d989c730ad0c52d2
  - runtime: passed - retained topology dev-a6ff68 and live nckrtl.nmbp both proved an exited app-instance runtime woke on the first HTTPS request and returned HTTP 200; `.orbit/evidence/runtime-proof.txt`
- Blast radius: complete - evidence=reviewed main...HEAD ownership search across nine proxy/Caddy implementation and test files; result=exact-instance recovery, serving/router/backend cleanup, and warning selector boundaries covered
- Review: passed - human-judgment=not-required; all three prior findings closed
- Reviewed feature tip: d6933487eb90f85fd69f18c9d989c730ad0c52d2
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: d6933487eb90f85fd69f18c9d989c730ad0c52d2
- Accepted main tip: b00eaa36f54caa40ce98fabe96a8c807a48bb38b

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
