# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `/Users/nckrtl/shared-knowledge/projects/orbit/superpowers/specs/2026-08-12-doctor-family-probe-runner-design.md`
- Worktree: `/Users/nckrtl/orbit/.worktrees/refactor-doctor-family-probe-runner`
- Branch: `refactor/doctor-family-probe-runner`

## Goal

Every Doctor family uses one shared progress and recoverable failure rule that
retains issues found before node access fails and lets later families continue.

## Scope

- Owned: `apps/gateway/app/Services/Doctor/DoctorFamilyProbeRunner.php`, `apps/gateway/app/Services/Doctor/DoctorIssueFactory.php`, `apps/gateway/app/Services/Doctor/DoctorReportRunner.php`, focused Pest coverage, and loop proof.
- Constraints: Preserve family selection and order, progress frame shape, issue identity and order, partial issues, exact-key filtering, fleet behavior, and restore/adopt behavior.
- Out of scope: Family-specific service extraction, shared report shaping, node-probe coordinator extraction, and report-shape reconciliation.

## Proof

- Verification:
  - focused: passed - Doctor service and API group: 227 tests, 1,981 assertions; scoped Mago format, lint, and analyze passed
  - broader: passed - `composer quality-check`; evidence `.orbit/quality-gates/quality-check-2026-08-12T103059Z-d381ad6d0cf2.json`
  - runtime: passed - candidate=f8168fab4384b0ace8c4509da42acb693dc42570; venue=retained-incus; environment=dev-fixture; command=orbit doctor --all --stream-json on topology dev-1493d7 through Beast LAN 192.168.6.20; expected=both eligible nodes reach done and Doctor returns the final fleet report; observed=app-dev-1 and gateway reached done then Doctor returned 15 fixture drift issues with exit 1 drift_detected; result=passed; evidence=`.orbit/evidence/doctor-family-probe-runner-retained-incus.md`
- Blast radius: complete - evidence=repository-wide search for the removed family helpers and focused Doctor group; result=no stale helper references and all Doctor family, fleet, report, and API tests passed
- Review: passed - human-judgment=not-required - independent general review found no actionable findings
- Reviewed feature tip: f8168fab4384b0ace8c4509da42acb693dc42570
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: f8168fab4384b0ace8c4509da42acb693dc42570
- Accepted main tip: 23c21498054f5c18bf0a16aee8ffe508d1511431

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
