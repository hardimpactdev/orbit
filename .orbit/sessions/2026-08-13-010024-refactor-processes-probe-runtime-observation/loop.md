# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: Solo project 63, Claude Opus process 2340
- Worktree: `/Users/nckrtl/orbit/.worktrees/refactor-processes-probe-runtime-observation`
- Branch: `refactor/processes-probe-runtime-observation`

## Goal

Extract live-result comparison from `ProcessesProbe` into focused services
while preserving every process Doctor issue, detail, guard, and emission
order.

## Scope

- Owned: `ProcessesProbe`, focused runtime-context, availability, definition,
  liveness, policy, extra-unit, and shared-detail services, focused process
  Doctor architecture coverage, and exact Mago baseline entries made obsolete
  by the extraction.
- Constraints: preserve Docker, Docker Swarm, systemd, launchd, app/workspace
  scope, Agent-push transport, issue detail, and issue order; use constructor
  injection; do not run human-only E2E commands.
- Out of scope: the never-implemented `process.runtime_unit_unloaded` behavior
  and stale crash-notifier documentation; each remains a separate next slice.

## Proof

- Verification:
  - focused: passed - 59 tests, 305 assertions; scoped Mago lint and analysis
    pass without baseline entries
  - broader: passed - 175 tests, 1,254 assertions across Doctor report, process
    probe, runtime context, and runtime-unit specification coverage
  - quality: passed - `composer quality-check`; artifact
    `.orbit/quality-gates/quality-check-2026-08-12T225237Z-4f6229365c78.json`
  - runtime: passed - candidate=408cd0b7cda233ef98fbe8c98c37ee965141f871; venue=retained-incus; environment=dev-fixture; command=orbit doctor --node=app-dev-1 --family=process --json; expected=the candidate inspects the active valkey Docker process without transport or comparison errors; observed=Doctor healthy with zero issues and exit code 0; result=passed; evidence=`.orbit/evidence/process-runtime-diff-retained-incus.json`
- Blast radius: complete - evidence=repository-wide caller and test inventory; result=the production caller remains DoctorProcessFamilyProbe, issue order remains explicit, and no hidden app() lookup remains in the changed services
- Review: passed - human-judgment=not-required; Claude Opus found no actionable
  findings
- Reviewed feature tip: 408cd0b7cda233ef98fbe8c98c37ee965141f871
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 408cd0b7cda233ef98fbe8c98c37ee965141f871
- Accepted main tip: 60bcacabb93123dee9e4f26e5d38b2348edc4dfc

## Status

- State: accepted
- Blocker: none

## Required verification

- Retained topology proof: passed - topology id=dev-abc1c2;
  kind=operator_gateway_app-dev; inspected roles=operator,gateway,dev;
  node=app-dev-1; command=`orbit doctor --node=app-dev-1 --family=process
  --json`; evidence=Solo project 63 process 2341 and
  `.orbit/evidence/process-runtime-diff-retained-incus.json`; result=healthy,
  zero issues, exit code 0
- `composer quality-check`: passed - candidate 408cd0b7c; artifact
  `.orbit/quality-gates/quality-check-2026-08-12T225237Z-4f6229365c78.json`

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
