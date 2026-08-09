# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: Solo design 419; implementation plan 420
- Worktree: /Users/nckrtl/orbit/.worktrees/bounded-test-randomness-audit
- Branch: bounded-test-randomness-audit

## Goal

Eliminate bounded random collision sources from committed PHP tests and enforce the invariant repository-wide.

## Scope

- Owned: `apps/gateway/tests/Feature/Architecture/BoundedTestRandomnessTest.php`; three gateway PHP CLI tool test files; `apps/e2e/tests/E2E/Support/Pest.php`; `apps/e2e/tests/Feature/Commands/Support/Pest.php`; `apps/e2e/tests/Feature/Commands/UpdateAllDurableOperationTest.php`; `apps/e2e/tests/Feature/E2ESupport/E2ERuntimePortHelpersTest.php`
- Constraints: TDD; synchronized E2E support copies; automated acceptance; never run `composer test:e2e*`
- Out of scope: product docs; production randomness; UUID/random-byte filesystem isolation; `ProcessFactory::sort_order`; live or retained topology proof

## Proof

- Verification:
  - focused: passed - gateway architecture and affected tool tests: 20 tests, 70 assertions; E2E support and command-support tests: 63 tests, 242 assertions; synchronized E2E helper blocks: identical; `git diff --check`: clean
  - broader: passed - `composer quality-check` passed every monorepo lane at candidate 8bfb9a5a44fc825862581d9d842b48e8172ed4f2
  - runtime: not applicable
- Blast radius: complete - evidence=repository-wide token-based architecture check over committed PHP test suites; result=no bounded random identifiers remain outside the guard's own data declaration
- Review: passed - human-judgment=not-required; general review found no actionable findings
- Reviewed feature tip: 8bfb9a5a44fc825862581d9d842b48e8172ed4f2
- Acceptance venue: automated
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 8bfb9a5a44fc825862581d9d842b48e8172ed4f2
- Accepted main tip: 7123a42f45075a4c2fa9de8d3e048b32ef8ba800

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
