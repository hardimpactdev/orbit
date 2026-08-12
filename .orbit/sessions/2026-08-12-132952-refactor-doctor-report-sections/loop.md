# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `/Users/nckrtl/shared-knowledge/projects/orbit/superpowers/specs/2026-08-12-doctor-report-sections-design.md`
- Worktree: `/Users/nckrtl/orbit/.worktrees/refactor-doctor-report-sections`
- Branch: `refactor/doctor-report-sections`

## Goal

Use one shared source for the repeated Doctor report sections without changing any Doctor report envelope or public contract.

## Scope

- Owned: `DoctorReportSections`, Doctor runner/progress/fleet delegation, and focused contract tests.
- Constraints: Preserve exact field order, intentional final/progress/fleet differences, issue order, and the public `DoctorReportRunner` surface.
- Out of scope: Changing the local-executor disposition omission, family orchestration, restore behavior, product documentation behavior, and human-only E2E lanes.

## Proof

- Verification:
  - focused: passed - 218 Doctor tests / 1,912 assertions; Mago format and scoped lint passed
  - broader: passed - `composer quality-check` at `.orbit/quality-gates/quality-check-2026-08-12T112751Z-ff0d71566505.json`
  - runtime: passed - candidate=ca968c43b5c7a107f40131b7b8c39921d77657f9; venue=retained-incus; environment=dev-fixture; target=dev-d58f78; expected=node fleet and stream Doctor report sections preserve their contracts; observed=single-node fleet and stream JSON retained exact scopes summaries roles dispositions and order after candidate resync; result=passed; evidence=`.orbit/evidence/doctor-report-sections-retained-incus.md`
- Blast radius: complete - evidence=bounded repository-wide inventory of Doctor scope roles summary disposition serialization and fleet consumers; result=shared gateway section ownership exists only in DoctorReportSections and remaining CLI progress state is renderer-local
- Review: passed - human-judgment=not-required
- Reviewed feature tip: ca968c43b5c7a107f40131b7b8c39921d77657f9
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: ca968c43b5c7a107f40131b7b8c39921d77657f9
- Accepted main tip: 4a521655c157e3e8a846f7be3e350156852544d1

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
