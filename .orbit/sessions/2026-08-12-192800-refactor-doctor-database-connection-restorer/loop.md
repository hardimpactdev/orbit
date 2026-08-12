# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: none
- Worktree: /Users/nckrtl/orbit/.worktrees/refactor-doctor-database-connection-restorer
- Branch: refactor/doctor-database-connection-restorer

## Goal

Move Doctor database-connection restore handling into one focused service without changing target resolution, workspace exclusion, action order, or report output.

## Scope

- Owned: database-connection restore target lookup, missing-target creation, target node resolution, workspace-target detection, and DoctorReportRunner delegation.
- Constraints: preserve exact action fields and details, null fall-through for unsupported issues, target_type/target_id/env_prefix identity, and existing target placement rules.
- Out of scope: the bounded restore retry loop, other Doctor families, remaining-issue reconciliation, verify/adopt behavior, documentation behavior, and human-only E2E lanes.

## Proof

- Verification:
  - focused: passed - red architecture test first; focused service and runner tests passed 117 tests / 966 assertions; all Doctor service tests passed 247 tests / 1949 assertions; Doctor API controller tests passed 52 tests / 335 assertions; scoped PHP syntax and Mago checks passed
  - broader: passed - `composer quality-check` passed every app and package; gateway Pest profile is `.orbit/quality-gates/profiles/2026-08-12T17-18-06Z-f6d9cd512382/gateway_pest.junit.xml`
  - runtime: passed - candidate=f6d9cd512382fdf4e45ec4e0def7cff54e157128; venue=retained-incus; environment=dev-fixture; command=orbit doctor --node=app-dev-1 --family=database_connection --restore --json; expected=restore both database setting issues with stable target identity and converge; observed=two completed database_connection actions preserved instance target 1 and DB prefix then the next verify was healthy with zero issues; result=passed; evidence=`.orbit/evidence/doctor-database-connection-restorer-retained-incus.md`
- Blast radius: not-required - internal Doctor service extraction; action output and database_connection terms are unchanged, and the exact-commit review found no other consumer coupling
- Review: passed - Claude Opus found no actionable findings; human-judgment=not-required
- Reviewed feature tip: f6d9cd512382fdf4e45ec4e0def7cff54e157128
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: f6d9cd512382fdf4e45ec4e0def7cff54e157128
- Accepted main tip: 0321befff31f8bbf15d528ed8846259e634ebc88

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
