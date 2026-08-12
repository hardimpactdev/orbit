# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: none
- Worktree: /Users/nckrtl/orbit/.worktrees/refactor-doctor-schedule-restorer
- Branch: refactor/doctor-schedule-restorer

## Goal

Move Doctor schedule restore handling behind one focused entry service and its gateway helper without changing node selection, schedule lookup, action order, or report output.

## Scope

- Owned: gateway and schedule-row restore routing, target node selection, schedule lookup, fixer delegation, and exact Doctor action output.
- Constraints: preserve the documented schedule issue keys, gateway fallback order, null fall-through, success/skip/failure actions, and bounded restore retry loop.
- Out of scope: schedule probing, SchedulesFixer behavior, other Doctor families, product documentation behavior, and human-only E2E lanes.

## Proof

- Verification:
  - focused: passed - 123 tests, 1,020 assertions; scoped Mago format, lint, and analyze passed
  - broader: passed - complete Doctor service and API set passed with 302 tests and 2,297 assertions; full `composer quality-check` passed with receipt `.orbit/quality-gates/quality-check-2026-08-12T174457Z-3fc923cc67e7.json`
  - runtime: passed - candidate=bcf9cfc6d5db87feb9bb53162516f6d8d82fc426; venue=retained-incus; environment=dev-fixture; command=orbit doctor --node=gateway --family=schedule --key=schedule.runtime_hibernator_missing --restore --json; expected=Doctor restores the missing runtime hibernator configuration through the extracted schedule restorer and the next exact-key verify is healthy; observed=one completed restore action converged in one pass and the next exact-key verify returned healthy with zero issues; result=passed; evidence=`.orbit/evidence/doctor-schedule-restorer-retained-incus-dev-a82d2c.md`
- Blast radius: not-required - internal gateway refactor preserves exact action payloads and changes no public contract
- Review: passed - Claude Opus exact-commit review found no issues; human-judgment=not-required
- Reviewed feature tip: bcf9cfc6d5db87feb9bb53162516f6d8d82fc426
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: bcf9cfc6d5db87feb9bb53162516f6d8d82fc426
- Accepted main tip: d45b1ca9790999e253c3b4a853376b7507168d8c

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
