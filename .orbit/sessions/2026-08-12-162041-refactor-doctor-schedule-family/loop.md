# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `/Users/nckrtl/shared-knowledge/projects/orbit/superpowers/specs/2026-08-12-doctor-schedule-family-design.md`
- Worktree: `/Users/nckrtl/orbit/.worktrees/refactor-doctor-schedule-family`
- Branch: `refactor/doctor-schedule-family`

## Goal

Doctor delegates schedule observation to a focused family service while preserving target selection, issue order, progress, and repair behavior.

## Scope

- Owned: schedule family probe orchestration, schedule inventory, shared schedule-to-node resolution, direct coverage, and runner delegation architecture checks.
- Constraints: Preserve public output, gateway-first check order, progress counts, failure diagnostics, issue order, and restore behavior.
- Out of scope: Schedule repair extraction, node family extraction, other Doctor families, and human-only E2E lanes.

## Proof

- Verification:
  - focused: passed - 115 tests, 966 assertions; scoped Mago format, lint, and analyze; diff and secret checks passed
  - broader: passed - `composer quality-check`; evidence `.orbit/quality-gates/profiles/2026-08-12T14-15-45Z-6aa586ef60e4/gateway_pest.junit.xml`
  - runtime: passed - candidate=6aa586ef60e47b503cc0b8a65c9ca360bb075d0c; venue=retained-incus; environment=dev-fixture; command=orbit doctor --node=gateway --family=schedule --json; expected=two identical exit-1 reports with schedule.runtime_backend_unavailable followed by schedule.runtime_hibernator_missing; observed=both exits 1, exact byte match, SHA-256 c2cf7f8ed004e1effd5476f09ca0246ff567993c82eb91e5d7d3bc56f1079f0e; result=passed; evidence=`.orbit/evidence/doctor-schedule-family-retained-incus.md`
- Blast radius: complete - evidence=repository-wide caller search, direct family and ordering tests, full runner characterization, full quality check, and retained Incus proof; result=no orphaned schedule-probe ownership, shared node resolution is used by probe and repair, and public Doctor output is unchanged
- Review: passed - Claude Opus found no actionable findings; human-judgment=not-required
- Reviewed feature tip: 6aa586ef60e47b503cc0b8a65c9ca360bb075d0c
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 6aa586ef60e47b503cc0b8a65c9ca360bb075d0c
- Accepted main tip: 0c8676c426c0e6a8954b1a40116c7a5ae6da1bbe

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
