# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `/Users/nckrtl/shared-knowledge/projects/orbit/superpowers/specs/2026-08-12-doctor-database-connection-family-design.md`
- Worktree: `/Users/nckrtl/orbit/.worktrees/refactor-doctor-database-connection-family`
- Branch: `refactor/doctor-database-connection-family`

## Goal

Doctor delegates database connection observation to a focused family service while preserving scope, progress, issues, restore, and adopt behavior.

## Scope

- Owned: database connection family probe dispatch, direct progress coverage, and runner delegation architecture checks.
- Constraints: Preserve public output, target scope, progress, failure diagnostics, restore, and adopt behavior.
- Out of scope: Database connection probe internals, repair or adopt extraction, other Doctor families, and human-only E2E lanes.

## Proof

- Verification:
  - focused: passed - 113 tests, 939 assertions; scoped Mago lint and analyze; diff check; secret scan
  - broader: passed - `composer quality-check`; evidence=`.orbit/quality-gates/profiles/2026-08-12T14-24-40Z-e9f414d40112/gateway_pest.junit.xml`
  - runtime: passed - candidate=e9f414d401126965ffa7f3da7eab132356587429; venue=retained-incus; environment=dev-fixture; command=orbit doctor --node=beast --family=database_connection --json; expected=two repeated reports preserve family output and issue order; observed=two identical drift reports with stable issue order and exit status; result=passed; evidence=`.orbit/evidence/doctor-database-connection-family-retained-incus.md`
- Blast radius: not-required - internal Doctor family-service extraction preserves the public command, transport, schema, restore, and adopt contracts
- Review: passed - Claude Opus reviewed the exact committed diff; no actionable findings; human-judgment=not-required
- Reviewed feature tip: e9f414d401126965ffa7f3da7eab132356587429
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: e9f414d401126965ffa7f3da7eab132356587429
- Accepted main tip: 021a1238efef1096ed1cc3d5f165aec85a725fa7

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
