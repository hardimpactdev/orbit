# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `/Users/nckrtl/shared-knowledge/projects/orbit/superpowers/specs/2026-08-11-doctor-typed-issue-contract-design.md`
- Worktree: `/Users/nckrtl/orbit/.worktrees/doctor-typed-issue-contract`
- Branch: `doctor-typed-issue-contract`

## Goal

Doctor uses one immutable, catalog-backed issue contract through its internal
verify and restore flow, while its documented JSON shape, issue ordering,
family order, progress order, and bounded restore convergence remain stable.

## Scope

- Owned: Doctor issue data and factory, Doctor report and convergence services,
  selected-issue intake, node convergence issue projection, Doctor product
  contract and decision ledger, and focused gateway tests.
- Constraints: implement the four approved slices sequentially; derive issue
  classification from the gateway catalog; preserve public report and fleet
  envelope shapes; use TDD; never run `composer test:e2e*`.
- Out of scope: family-adapter reorganization, probe/fixer ownership changes,
  family-order changes, progress redesign, or restore algorithm changes.

## Proof

- Verification:
  - focused: passed - scoped Mago analysis found no issues; affected Doctor,
    database-connection, node-convergence, DNS restore, and HTTP tests passed
    (245 tests, 2,005 assertions); `composer docs-lint` passed
  - broader: passed - `composer quality-check` passed for clean candidate
    `64a7a51fb3c6e49d1db87b06dd750afb79867e87`; artifact
    `.orbit/quality-gates/quality-check-2026-08-11T090245Z-445c0e65baf9.json`
  - runtime: passed - candidate=64a7a51fb3c6e49d1db87b06dd750afb79867e87; venue=retained-incus; environment=dev-fixture; target=nckrtl@192.168.6.20 dev-aa3f9a operator; expected=source-mounted Doctor reports catalog-backed issue fields through the real CLI and gateway; observed=corrected final candidate resynced, source hashes matched, and Doctor returned six fixture drift issues with the complete documented issue contract; result=passed; evidence=`.orbit/evidence/doctor-typed-issue-contract-retained-incus.json`
- Blast radius: complete - evidence=independent bounded repository-wide inventory of gateway producers, selected-issue intake, convergence, CLI, SDK, E2E, docs, and issue serialization consumers; result=one catalog-backed issue contract reaches every boundary, empty detail keeps its documented JSON object type, and no unresolved surface remains
- Review: passed - independent exact-tip review found no actionable findings - human-judgment=not-required
- Reviewed feature tip: 64a7a51fb3c6e49d1db87b06dd750afb79867e87
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 64a7a51fb3c6e49d1db87b06dd750afb79867e87
- Accepted main tip: 8feeef357626223e10383eed6feb56fd336e0fd0

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
