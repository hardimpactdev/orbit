# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: Solo project 27; Claude Opus review process 2280
- Worktree: /Users/nckrtl/orbit/.worktrees/refactor-doctor-process-restorer
- Branch: refactor/doctor-process-restorer

## Goal

Doctor process restore behavior is owned by one focused service while the public Doctor runner, action receipts, safety checks, and convergence behavior remain unchanged.

## Scope

- Owned: Extract process-family restore dispatch and helpers from DoctorReportRunner; keep DoctorProcessRestoreSupport authoritative; correct the stale process Doctor fix-map text.
- Constraints: Preserve exact issue/action payloads, ordering, warnings, event recording, safe extra-runtime removal checks, and public DoctorReportRunner behavior.
- Out of scope: Change process restore semantics, change convergence, split process probing, add interfaces, or run human-only E2E lanes.

## Proof

- Verification:
  - focused: passed - 119 Doctor tests, 999 assertions
  - broader: passed - 272 Doctor/process tests, 2,189 assertions; docs lint and full `composer quality-check` passed at candidate `948142ba54fb3d74b8cd3be33a5012c66b247a73`
  - runtime: passed - candidate=948142ba54fb3d74b8cd3be33a5012c66b247a73; venue=retained-incus; environment=dev-fixture; target=app-dev-1 process.runtime_unit_missing restore; expected=Doctor restores the removed node-owned systemd unit and a repeat restore makes no changes; observed=Doctor fixed one missing unit and converged, fresh verify was healthy, repeat restore fixed zero with no actions; result=passed; evidence=`.orbit/evidence/doctor-process-restorer-retained-incus.md`
- Blast radius: complete - evidence=bounded repository-wide inventories for DoctorReportRunner, DoctorProcessRestorer, NodeProcessResolver, deleted process-helper symbols, restore-support codes, and process Doctor authority; result=the runner remains the public orchestrator, process restore dispatch and helpers have one active owner, support/catalog/docs/tests align, and no unresolved executable caller or ownership gap remains
- Review: passed - human-judgment=not-required
- Reviewed feature tip: 948142ba54fb3d74b8cd3be33a5012c66b247a73
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 948142ba54fb3d74b8cd3be33a5012c66b247a73
- Accepted main tip: e0633b82131bc352cf1dcc9ab3fc49794060b858

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
