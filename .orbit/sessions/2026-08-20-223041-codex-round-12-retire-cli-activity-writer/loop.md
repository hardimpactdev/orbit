# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `solo://proj/116/scratchpad/round-12-retire-dorm--511`
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-round-12-retire-cli-activity-writer`
- Branch: `codex/round-12-retire-cli-activity-writer`

## Goal

Retire the unreachable gateway-side CLI activity writer and its target
accumulator. Preserve read compatibility for stored activity rows whose channel
is `cli`, while proving current code cannot recreate the retired writer path.

## Scope

- Owned: `apps/gateway/app/Concerns/LogsCommandActivity.php`, `apps/gateway/app/Services/ActivityLogTargets.php`, `apps/gateway/mago-analyzer-baseline.toml`, `apps/gateway/mago-linter-baseline.toml`, `apps/gateway/tests/Feature/ActivityLog/CommandActivityLocalGatewayResolutionTest.php`, `apps/gateway/tests/Feature/Removal/CliActivityWriterRemovalTest.php`, `apps/gateway/tests/Unit/Services/Activity/ActivityPayloadFormatterTest.php`, and loop/evidence files.
- Constraints: preserve stored-`cli` read compatibility; use a failing Pest removal guard before production deletion; the parent goal explicitly authorizes deleting the obsolete behavior test; never run or delegate `composer test:e2e*`; preserve unrelated primary-checkout state.
- Out of scope: activity API emission, activity storage schema, product-doc wording, CLI commands, and integrated topology behavior.

## Proof

- Verification:
  - focused: passed - removal guard and stored-cli formatter coverage pass; TDD evidence=`.orbit/evidence/round-12-cli-activity-writer-tdd.md`
  - broader: passed - exact candidate `composer quality-check`; profile=`.orbit/quality-gates/profiles/2026-08-20T20-18-26Z-373324670515/gateway_pest.junit.xml`
  - runtime: passed - candidate=3733246705151d0a08bcc13b9275a790bfd5d744; venue=retained-incus; environment=dev-fixture; target=orbit-e2e-dev-6d3dd9-operator; expected=retired writer files absent and stored cli rows remain readable; observed=source-mounted launcher verified and six focused tests passed with twenty-four assertions; result=passed; evidence=`.orbit/evidence/round-12-cli-activity-writer-runtime.md`
- Blast radius: complete - evidence=repository-wide symbol and channel-writer search; result=only the removal guard names retired symbols, and no production PHP path writes channel cli
- Review: passed - human-judgment=not-required; independent general reviewer found no actionable findings after bounded repository-wide symbol and channel-writer searches
- Reviewed feature tip: 3733246705151d0a08bcc13b9275a790bfd5d744
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 3733246705151d0a08bcc13b9275a790bfd5d744
- Accepted main tip: 8408d697f5b00847d2d85071ba18447e889a34e1

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
