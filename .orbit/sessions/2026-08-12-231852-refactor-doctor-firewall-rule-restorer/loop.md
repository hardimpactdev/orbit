# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: Solo project `60`
- Worktree: `/Users/nckrtl/orbit/.worktrees/refactor-doctor-firewall-rule-restorer`
- Branch: `refactor/doctor-firewall-rule-restorer`

## Goal

Move firewall rule recovery out of `DoctorReportRunner` without changing rule lookup, restore actions, failure actions, or convergence.

## Scope

- Owned: firewall rule recovery delegation, focused contract tests, and architecture checks
- Constraints: preserve node-scoped rule lookup, existing action envelopes, and current verify/restore behavior
- Out of scope: firewall probing, firewall adoption, tool recovery, node recovery, DNS recovery, and product behavior changes

## Proof

- Verification:
  - focused: passed - focused restorer, runner, API, and fixer suites: 158 tests, 1268 assertions
  - broader: passed - Doctor unit, feature, and API suites: 328 tests, 2343 assertions; full quality check passed with receipt `.orbit/quality-gates/quality-check-2026-08-12T211233Z-32036a21d979.json`
  - runtime: passed - candidate=b9873556bb4ea69b43d7a0346f815b2af7f36173; venue=retained-incus; environment=dev-fixture; target=app-dev-1; expected=Doctor detects the missing managed firewall rule, restores it, and converges; observed=one rule_missing issue became healthy with fixed=1 after one restore pass; result=passed; evidence=`.orbit/evidence/doctor-firewall-rule-restorer-dev-4b5135.json`
- Blast radius: complete - evidence=repository-wide search and Claude Opus review; result=no old production references remain and dependency direction is runner to firewall restorer to fixer
- Review: passed - Claude Opus found no actionable issue - human-judgment=not-required
- Reviewed feature tip: b9873556bb4ea69b43d7a0346f815b2af7f36173
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: b9873556bb4ea69b43d7a0346f815b2af7f36173
- Accepted main tip: 900d6173655a5a367edb7aa4b566a576c54890f9

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
