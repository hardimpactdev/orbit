# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: user-reported bare-hostname collision on app:log
- Worktree: /Users/nckrtl/orbit/.worktrees/codex-fix-app-log-bare-hostname
- Branch: codex/fix-app-log-bare-hostname

## Goal

`orbit app:log servauto-app.nmbp` (and any other explicit bare hostname that is
an exact registered proxy domain) succeeds without requiring an https URL, even
when the same text is also a canonical `app.instance` selector; `instance:log`
remains the explicit instance-selector command.

## Scope

- Owned: apps/cli app:log command/helpers/tests; apps/docs app:log public +
  technical + renderer docs; PRODUCT_DECISIONS.md newest clarification; bundled
  Orbit skill app:log wording if stale
- Constraints: exact registered proxy hostname wins for app:log; no implicit
  https; no heuristic ambiguity; TDD with focused Pest first; preserve URL
  validation; dead preflight helpers removed only after repo-wide unused proof;
  never run composer test:e2e*; preserve unrelated work; do not touch primary
  checkout or other worktrees; stop after BUILD/PROVE with clean committed
  candidate (no ACCEPT/LAND/merge/push/deploy/cleanup)
- Out of scope: instance:log/workspace:log behavior changes; acceptance; merge;
  push; deploy; cleanup; unrelated docs

## Proof

- Verification:
  - focused: passed - red then green AppLogCommandTest (collision bare hostname; 15 passed)
  - broader: passed - composer quality-check exit_code=0 (profile 2026-08-05T20-04-14Z-be0e1d269a8b; cli_pest 2500 passed)
  - runtime: passed - candidate=6e337f0e51d8789e3c858e4e00733ed9a5b8cdd3; venue=retained-incus; environment=live; command=`./apps/cli/orbit app:log servauto-app.nmbp --lines=1 --json`; expected=exit 0 and exact proxy-host resolution without https for bare servauto-app.nmbp; observed=exit 0 target=instance/servauto-app.nmbp node=NMBP path=storage/logs/laravel.log file_exists=true line_count=1; result=passed; evidence=`.orbit/evidence/app-log-servauto-app-nmbp-live-structural.json`
- Blast radius: complete - evidence=repository-wide rg in exact checkout for removed helpers bareSelectorIsRegisteredInstance, isRegisteredInstanceSelector, instancePathEntries and stale collision-rejection language, plus scoped app:log inventory across apps/docs, apps/cli, .agents/skills, PRODUCT_DECISIONS.md; result=removed helpers have zero remaining call sites, no stale collision-rejection copy remains, and intentional ledger reversal plus aligned docs/tests/skill are consistent
- Review: passed - human-judgment=not-required
- Reviewed feature tip: 6e337f0e51d8789e3c858e4e00733ed9a5b8cdd3
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 6e337f0e51d8789e3c858e4e00733ed9a5b8cdd3
- Accepted main tip: be0e1d269a8b3b855e782077c1a6b6cda2f90804

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
[reason]` or `complete - evidence=<repository-wide search, inventory, or
lintable check>; result=[summary]` before acceptance; `gaps` returns to BUILD.
For stateful, lifecycle, or concrete UX work, optionally append one compact
clause on the existing Scope `Owned` row (do not add a permanent new row):
`primitive=[exact requested primitive]; transitions=success:[terminal success]|failure:[terminal failure]|retry:[retry]|stop-restart:[stop or restart]|stale:[stale-state or n/a]`.
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
