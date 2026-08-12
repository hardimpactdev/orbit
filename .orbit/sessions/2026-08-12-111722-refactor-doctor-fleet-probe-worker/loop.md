# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `/Users/nckrtl/shared-knowledge/projects/orbit/superpowers/specs/2026-08-12-doctor-fleet-probe-worker-design.md`
- Worktree: `/Users/nckrtl/orbit/.worktrees/refactor-doctor-fleet-probe-worker`
- Branch: `refactor/doctor-fleet-probe-worker`

## Goal

Extract the fleet Doctor child-process protocol and issue trust boundary from `DoctorReportRunner` without changing fleet selection, ordering, progress, fallback, or report contracts.

## Scope

- Owned: `DoctorFleetProbeWorker`, `DoctorReportRunner` delegation, and direct worker contract tests.
- Constraints: Preserve the five-node pool, 600-second timeout, array-form child command, minimal child environment, in-memory database fallback, issue canonicalization, progress parsing, and all public runner contracts.
- Out of scope: The single-node Doctor probe, the fleet orchestration loop, node and issue ordering, report shaping, controllers, commands, and product documentation.

## Proof

- Verification:
  - focused: passed - 174 tests, 1290 assertions across the worker, fleet batching, runner, projection, and Doctor HTTP controllers
  - broader: passed - `composer quality-check`; gateway test log `.orbit/quality-gates/profiles/2026-08-12T09-08-56Z-6534d94ffe3e/gateway_pest.log`
  - runtime: passed - candidate=6534d94ffe3e775f65dbfc7069cb31acd7acf8e2; venue=retained-incus; environment=dev-fixture; command=apps/cli/orbit doctor --all --family=node --stream-json; expected=both fleet nodes run concurrently with streamed progress and stable final ordering while fixture drift may exit non-zero; observed=both nodes streamed running and done, completion was out of order, final targets nodes and issues stayed ordered, and documented fixture drift exited 1; result=passed; evidence=`.orbit/evidence/retained-incus-doctor-fleet-worker.txt`
- Blast radius: complete - evidence=repository-wide search for direct runner construction and removed worker-method references; result=no manual construction, orphan callers, or public contract changes found
- Review: passed - Claude Opus independent general review; no actionable findings; human-judgment=not-required
- Reviewed feature tip: 6534d94ffe3e775f65dbfc7069cb31acd7acf8e2
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 6534d94ffe3e775f65dbfc7069cb31acd7acf8e2
- Accepted main tip: bf4490ceee5fc0102ea87c34dce360294fb0eeab

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`

Allowed states are `frame`, `build`, `prove`, `accept`, `accepted`, `land`, and
`blocked`. Review must be `passed`, `fix`, or `escalate`. Acceptance must be
`pending`, `accepted`, `not-applicable`, or `changes-requested`. A terminal
review PASS must record `human-judgment=required|not-required` and the exact
committed HEAD in `Reviewed feature tip`. Blast radius must be `not-required -
concrete reason` or `complete - evidence=repository-wide search, inventory, or
lintable check; result=summary` before acceptance; `gaps` returns to BUILD.
For stateful, lifecycle, or concrete UX work, optionally append one compact
clause on the existing Scope `Owned` row (do not add a permanent new row):
`primitive=exact requested primitive; transitions=success:terminal success|failure:terminal failure|retry:retry behavior|stop-restart:stop or restart|stale:stale-state or n/a`.
Omit the clause for ordinary/local changes. When `primitive=` or `transitions=`
is present, deterministic lint requires both fields, the five known transition
keys without duplicates or empty values, and rejects template placeholders; it
does not grade prose or decide whether the feature is stateful. Explicit `n/a`
values are fine when a transition does not apply. After FRAME, run
`bin/orbit-feature-acceptance route` for the read-only
diff-derived venue before expensive PROVE work. For non-`automated` venues,
`Verification.runtime: passed` must use one candidate-bound structured receipt
on that same single line. Required fields are candidate=, venue=, environment=,
expected=, observed=, result=passed, and evidence= as one exact inline-code path
under the worktree evidence or quality-gates trees. Use exactly one of target= or command=.
Live/production claims require exact environment=live; ordinary retained
topology may use environment=dev-fixture. Semicolons separate fields,
so values must not embed raw semicolon-delimited pseudo-fields. Known keys
only. Example evidence citation: write a real receipt and cite one exact regular
file below the worktree evidence tree (not a directory root). A failed,
excluded, still-required, or deferred final hop cannot be recorded as passed;
remain in PROVE, disarm any armed or recorded acceptance, and follow FIX ->
BUILD -> PROVE before ACCEPT. Keep a still-valid Review and Reviewed feature tip
on proof-only retries; a HEAD change still needs a refreshed review. Automated
venues keep `runtime: not applicable`. Proof files retained by the compact
archive must be cited as one exact inline-code path; prose, directories, padded
code spans, and partial paths are not proof citations.
