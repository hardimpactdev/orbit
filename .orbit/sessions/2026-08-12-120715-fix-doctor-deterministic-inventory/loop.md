# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `/Users/nckrtl/shared-knowledge/projects/orbit/superpowers/specs/2026-08-12-doctor-deterministic-node-inventory-design.md`
- Worktree: `/Users/nckrtl/orbit/.worktrees/fix-doctor-deterministic-inventory`
- Branch: `fix/doctor-deterministic-inventory`

## Goal

Doctor reads every database-backed node inventory in an explicit stable order,
so repeated checks return family issues in the same order.

## Scope

- Owned: `apps/gateway/app/Services/Doctor/DoctorReportRunner.php`, `apps/gateway/app/Services/DatabaseConnections/DatabaseConnectionProbe.php`, their focused Pest coverage, and loop proof.
- Constraints: Preserve family order, issue content, progress, scope, fleet behavior, and restore/adopt behavior.
- Out of scope: Report-shape reconciliation, family-runner extraction, report-shaper extraction, and node-probe coordinator extraction.

## Proof

- Verification:
  - focused: passed - database ordering, Doctor inventory, runner, and fleet tests: 139 tests, 1,046 assertions; scoped Mago analysis: no issues
  - broader: passed - `composer quality-check`; gateway Pest: 5,967 tests, 50,035 assertions; evidence: `.orbit/quality-gates/profiles/2026-08-12T09-55-05Z-e1b5220c7457/gateway_pest.log`
  - runtime: passed - candidate=e1b5220c7457219839e4cc1fed9eb84946226f04; venue=retained-incus; environment=dev-fixture; command=`apps/cli/orbit doctor --all --stream-json` twice; expected=both full-fleet reports keep the same ordered family-node-key issue identities; observed=both runs returned the same 15 ordered identities with SHA-256 bb085f63c3f8b86912d8b9b77bdbac75eea5d082b2470899e112ba81a50a21a6; result=passed; evidence=`.orbit/evidence/retained-incus-doctor-inventory-order.txt`
- Blast radius: not-required - change is local to gateway Doctor inventory traversal and does not change a product decision, schema, transport, ownership boundary, or shared vocabulary
- Review: passed - independent general review found no actionable findings - human-judgment=not-required
- Reviewed feature tip: e1b5220c7457219839e4cc1fed9eb84946226f04
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: e1b5220c7457219839e4cc1fed9eb84946226f04
- Accepted main tip: 08a5b0044b2d8a3e5cb13384f2fa38b80e546a53

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
