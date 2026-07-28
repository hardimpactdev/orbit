# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: none
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-hibernation-sweep-cadence`
- Branch: `codex/hibernation-sweep-cadence`

## Goal

Development runtime hibernation is checked every ten minutes while ordinary
Orbit schedules continue to be evaluated every minute.

## Scope

- Owned: gateway hibernation cadence configuration, the dedicated runtime
  hibernator daemon and Swarm service, focused Pest coverage, and hibernation
  authority docs.
- Constraints: preserve the one-hour idle threshold, wake-on-request behavior,
  and minute-level ordinary schedule dispatch.
- Out of scope: Caddy routing, process lifecycle semantics, production and
  node-owned persistence, and prepared E2E suites.

## Proof

- Verification:
  - focused: passed - red tests failed before both cadence implementations;
    corrected dedicated-daemon, scheduler, stack, update, command inventory,
    update recovery, Doctor, and hibernation coverage passes with 180 tests and
    1436 assertions; the expanded focused suite passed with 181 tests and 1442
    assertions, and the final Doctor-fixer correction passed its exact 26 tests
    and 138 assertions;
    `composer docs-lint` passes
  - broader: passed - exact candidate `85c4a670d02d9a1b8e372cc46251002181851017`
    passes `composer quality-check`; gateway Pest receipt:
    `.orbit/quality-gates/profiles/2026-07-28T15-00-39Z-85c4a670d02d/gateway_pest.junit.xml`
  - runtime: passed - retained Incus topology `dev-293f42`
    (`operator_gateway_app-dev`) deployed both candidate Swarm services at one
    replica, proved their independent commands and placement, matched the
    candidate command hash, and observed the unchanged one-hour threshold plus
    ten-minute sweep default inside the running hibernator. The retained runtime
    proof used runtime-identical predecessor `435685aba325be2c25bca85227728e16ff583f50`;
    later commits only corrected Doctor convergence and its class-size lint;
    evidence:
    `.orbit/evidence/runtime-proof.txt`
- Blast radius: complete - evidence=`.orbit/evidence/blast-radius.txt`; result=runtime, scheduler, stack, update/recovery, Doctor, command visibility, docs, and focused coverage consumers inventoried with no gaps
- Review: passed - human-judgment=not-required; no actionable findings; genuinely absent gateway daemons redeploy the stack while race-restored services scale safely
- Reviewed feature tip: 85c4a670d02d9a1b8e372cc46251002181851017
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 85c4a670d02d9a1b8e372cc46251002181851017
- Accepted main tip: 89d11fb7948b91a56d275e778591771271204fd3

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
