# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `/Users/nckrtl/shared-knowledge/projects/orbit/superpowers/specs/2026-08-12-doctor-app-process-family-design.md`
- Worktree: `/Users/nckrtl/orbit/.worktrees/refactor-doctor-app-process-family`
- Branch: `refactor/doctor-app-process-family`

## Goal

Make each Doctor run use one stable app-instance inventory and move the app and process family probe flows behind focused service boundaries without changing public behavior.

## Scope

- Owned: Doctor app/process family probe services, single-run instance inventory reuse, runner delegation, and focused contract tests.
- Constraints: Preserve family order, progress totals, issue order and payloads, key filtering, failure conversion, public runner API, and restore/adopt behavior.
- Out of scope: Other family extraction, report envelopes, fleet orchestration, restore handlers, product behavior changes, and human-only E2E lanes.

## Proof

- Verification:
  - focused: passed - 236 Doctor unit/API tests, 2,019 assertions; scoped Mago format/lint/analyze and Rector dry-run passed
  - broader: passed - full `composer quality-check` passed at candidate `018ad26fbe39d503db615a8eccab93ba9388306d`; evidence=`.orbit/quality-gates/quality-check-2026-08-12T115121Z-c06a49ceb432.json`
  - runtime: passed - candidate=018ad26fbe39d503db615a8eccab93ba9388306d; venue=retained-incus; environment=dev-fixture; target=dev-726dce/app-dev-1; expected=public instance and process Doctor families run in canonical order from the same candidate without report contract drift; observed=instance completed before process and the healthy final report named both public families with the normal scope summary issues actions and exit-code envelope; result=passed; evidence=`.orbit/evidence/doctor-app-process-family-retained-incus.md`
- Blast radius: complete - evidence=repository-wide search; result=family inventory ownership, public/internal vocabulary, progress and failure handling, and all old/new method consumers have no unresolved contract gap
- Review: passed - independent general review found no actionable findings; human-judgment=not-required
- Reviewed feature tip: 018ad26fbe39d503db615a8eccab93ba9388306d
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 018ad26fbe39d503db615a8eccab93ba9388306d
- Accepted main tip: 017dc72c7ac659cb6e6f8a2b7e1309840e7a2011

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
