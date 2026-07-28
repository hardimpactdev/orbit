# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `/Users/nckrtl/shared-knowledge/projects/orbit/superpowers/2026-07-28-on-demand-runtime-hibernation.md`
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-on-demand-runtime-hibernation`
- Branch: `codex/on-demand-runtime-hibernation`

## Goal

App-instance and workspace processes on development nodes hibernate after one
hour without HTTP activity and wake on the first ordinary browser request
through stock Caddy, without a custom Caddy module or image.

## Scope

- Owned: app-instance/workspace activity state, stock-Caddy wake pre-check,
  exact-scope process start/stop orchestration, idle sweep, systemd/launchd/
  Docker on-demand lifecycle, product docs, and automated/runtime proof.
- Constraints: Caddy remains always on; node-owned and production processes
  remain persistent; wake is single-flight and bounded; awake routes continue
  serving if the gateway is unavailable; use the existing Agent transport.
- Out of scope: custom Caddy modules/images, hibernating node services or
  production workloads, arbitrary request-lifecycle accounting, and UI work.

## Proof

- Verification:
  - focused: passed - 239 Gateway tests / 1790 assertions; 27 CLI tests / 140 assertions; exact stock `caddy:2-alpine` configuration validation
  - broader: passed - `composer quality-check` at `973e551f54158c35804b5758fa88043b26450226`; profile `.orbit/quality-gates/profiles/2026-07-28T13-15-04Z-973e551f5415/gateway_pest.junit.xml`
  - runtime: passed - retained topology `dev-87b371` (`operator_gateway_app-dev`) proved two healthy application cold wakes, one-hour idle stop, warm bypass, and continuously running stock Caddy; evidence=`.orbit/evidence/runtime-proof.txt`
- Blast radius: complete - evidence=`.orbit/evidence/blast-radius.txt`; result=all app/workspace route renderers and lifecycle/restart surfaces inventoried; node-owned and production persistence remains separate
- Review: passed - human-judgment=not-required; no actionable findings remain after partial-stop, scheduler-ordering, and healthy retained-runtime corrections
- Reviewed feature tip: 973e551f54158c35804b5758fa88043b26450226
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 973e551f54158c35804b5758fa88043b26450226
- Accepted main tip: ac531b1843345a27354218d07ba35435105b167d

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
