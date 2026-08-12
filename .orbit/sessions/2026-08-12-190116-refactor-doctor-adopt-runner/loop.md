# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: none
- Worktree: /Users/nckrtl/orbit/.worktrees/refactor-doctor-adopt-runner
- Branch: refactor/doctor-adopt-runner

## Goal

Move Doctor adopt coordination into one focused service without changing which observed node state Orbit records, the action order, scope rules, or report output.

## Scope

- Owned: adopt family dispatch, adopt guard rules, scoped proxy observations, action shaping, and DoctorReportRunner delegation.
- Constraints: preserve node -> proxy -> firewall_rule -> database_connection order, exact action arrays, production workspace exclusion, app/workspace scope, and the existing no-action/no-re-probe behavior.
- Out of scope: verify probes, restore convergence, issue restore dispatch, command or documentation behavior, and human-only E2E lanes.

## Proof

- Verification:
  - focused: passed - architecture test failed before implementation, then 112 tests / 935 assertions and the full Doctor area 245 tests / 1940 assertions passed; scoped Mago format, lint, and analyze passed
  - broader: passed - `composer quality-check` passed; evidence=`.orbit/quality-gates/profiles/2026-08-12T16-58-21Z-bd6bd8178a44/gateway_pest.junit.xml`
  - runtime: passed - candidate=bd6bd8178a44d7490d6f5c76eb46b02a03d98eac; venue=retained-incus; environment=dev-fixture; command=orbit doctor --node=app-dev-1 --family=node --family=proxy --family=firewall_rule --family=database_connection --adopt --json; expected=preserved adopt family order and exact action fields on repeated runs; observed=both runs produced canonical action order and byte-identical JSON with the exact action fields; result=passed; evidence=`.orbit/evidence/doctor-adopt-runner-retained-incus.json`
- Blast radius: not-required - internal service extraction; exact action schema is preserved and repository-wide search found no external callers or manual construction
- Review: passed - Claude Opus found no actionable findings; human-judgment=not-required
- Reviewed feature tip: bd6bd8178a44d7490d6f5c76eb46b02a03d98eac
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: bd6bd8178a44d7490d6f5c76eb46b02a03d98eac
- Accepted main tip: d50d49011de8e02bccc90ca9d42882f10dc6f812

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
