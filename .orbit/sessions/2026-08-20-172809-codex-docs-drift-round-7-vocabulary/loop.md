# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `solo://proj/108/scratchpad/round-7-vocabulary-c--480`
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-docs-drift-round-7-vocabulary`
- Branch: `codex/docs-drift-round-7-vocabulary`

## Goal

Replace the 28 reconciled public Orbit workload strings that still use Project with canonical App or Instance vocabulary, with executable regression protection.

## Scope

- Owned: Schedule help, app-registration output, instance and process errors, Cloudflare progress labels, workspace-step API errors, and focused vocabulary assertions.
- Constraints: Preserve source/repository and Codex/Solo project terminology; use TDD; never run or delegate `composer test:e2e*`.
- Out of scope: Activity logging follow-up CF1, internal unmapped exceptions, and private identifiers.

## Proof

- Verification:
  - focused: passed - gateway 32 tests/221 assertions; CLI Cloudflare 11 tests/65 assertions; red proof covered five stale behaviors plus the expanded source guard
  - broader: passed - `composer quality-check`; receipt `.orbit/quality-gates/quality-check-2026-08-20T152253Z-129078fd948e.json`; all units passed; candidate clean
  - runtime: passed - candidate=88a596bf4f889104a8354d444295a570139ca5c1; venue=retained-incus; environment=dev-fixture; target=Solo terminal 2544 in topology dev-7d1566 after operator and gateway candidate sync; expected=all 28 reconciled public strings use canonical App or Instance vocabulary; observed=previous workload surfaces plus both Cloudflare cache-rule trees matched and focused API assertions covered workspace-step errors; result=passed; evidence=`.orbit/evidence/round-7-vocabulary-retained-incus.md`
- Blast radius: complete - evidence=reviewer repository-wide exact-phrase sweep plus active runtime-source quoted-string inventory; result=all 28 public workload strings canonical and no affected public surface unresolved
- Review: passed - reviewer process 2543; human-judgment=not-required; scratchpad `solo://proj/108/scratchpad/round-7-vocabulary-g--481`
- Reviewed feature tip: 88a596bf4f889104a8354d444295a570139ca5c1
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 88a596bf4f889104a8354d444295a570139ca5c1
- Accepted main tip: 56a9988f5915face461923c45311b7f3cb223cb2

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
