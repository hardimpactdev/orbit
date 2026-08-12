# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `/Users/nckrtl/shared-knowledge/projects/orbit/superpowers/specs/2026-08-12-doctor-node-probe-coordinator-design.md`
- Worktree: `/Users/nckrtl/orbit/.worktrees/refactor-doctor-node-probe-coordinator`
- Branch: `refactor/doctor-node-probe-coordinator`

## Goal

Move normal single-node Doctor family coordination into one small service while preserving family order, issue filtering, progress, and the public report contract.

## Scope

- Owned: `DoctorNodeProbeRunner`, `DoctorReportRunner::probe()` delegation, shared node-instance inventory for app/process checks, and focused tests.
- Constraints: Preserve every public Doctor input, output, family-order, scope, issue, and failure contract. Use the existing family probes and `DoctorReportSections`.
- Out of scope: Repair, adopt, restore convergence, fleet scheduling, family probe behavior, and public documentation changes.

## Proof

- Verification:
  - focused: passed - Doctor unit area: 211 tests, 1,820 assertions; scoped Mago format/lint passed with only two pre-existing help messages in DoctorReportRunner.
  - broader: passed - `composer quality-check`; gateway receipt `.orbit/quality-gates/profiles/2026-08-12T15-04-42Z-a5ed7ed63b19/gateway_pest.log` and sibling app/package logs.
  - runtime: passed - candidate=a5ed7ed63b1940cd376ddd013aa3fa294d856231; venue=retained-incus; environment=dev-fixture; target=orbit-e2e-dev-8069b6-operator; expected=two byte-identical full gateway Doctor JSON reports with fixture drift allowed; observed=both exit 1 with identical SHA-256 mode verify six families and 10 fixture issues; result=passed; evidence=`.orbit/evidence/doctor-node-probe-coordinator-retained-incus.txt`
- Blast radius: complete - evidence=repository-wide family architecture guards and full Doctor unit inventory; result=all nine family probes now depend on DoctorNodeProbeRunner while DoctorReportRunner retains repair, adopt, restore, and fleet dependencies.
- Review: passed - Claude Opus in Solo project 45/process 2307; human-judgment=not-required.
- Reviewed feature tip: a5ed7ed63b1940cd376ddd013aa3fa294d856231
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: a5ed7ed63b1940cd376ddd013aa3fa294d856231
- Accepted main tip: 1205863d26179aebdea9a5481df5c9af2b067afc

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
