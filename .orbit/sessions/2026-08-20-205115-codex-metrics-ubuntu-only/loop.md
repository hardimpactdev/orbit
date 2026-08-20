# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: solo://proj/113/scratchpad/metrics-ubuntu-only--499
- Worktree: /Users/nckrtl/orbit/.worktrees/codex-metrics-ubuntu-only
- Branch: codex/metrics-ubuntu-only

## Goal

Enforce the 2026-07-20 Ubuntu-only Metrics contract at role assignment and baseline convergence, with focused regression coverage and retained-topology proof.

## Scope

- Owned: `apps/gateway/app/Services/Nodes/Roles/NodeRoleRegistry.php`, `apps/gateway/app/Services/Nodes/Roles/RoleBaselines/MetricsRoleBaseline.php`, and their focused Pest tests
- Constraints: TDD; preserve unrelated worktree state; never run or delegate `composer test:e2e*`
- Out of scope: new product direction, unrelated Metrics behavior, documentation already corrected by Round 10 docs slice

## Proof

- Verification:
  - focused: passed - 144 tests, 564 assertions across the Metrics registry, baseline, and role-assignment suites; Mago format check passed
  - broader: passed - `composer quality-check` passed all ten apps and packages at candidate cf688dc0f7e496ba76f744567985242ec41424f9
  - runtime: passed - candidate=cf688dc0f7e496ba76f744567985242ec41424f9; venue=retained-incus; environment=dev-fixture; command=apps/cli/orbit node role:add gateway metrics --json on Debian fixture dev-90b6a4; expected=validation_failed before persistence; observed=exit 1 with the exact unsupported-platform message and no Metrics role in the follow-up assignment list; result=passed; evidence=`.orbit/evidence/metrics-ubuntu-only-retained-incus.md`
- Blast radius: complete - evidence=repository-wide Metrics and Debian contract search; result=only the current and superseded dated decisions, intentional rejection tests, and the Ubuntu-only baseline constant remain
- Review: passed - human-judgment=not-required; independent Claude general review found 0 blocking, 0 major, 0 minor, and 1 informational enforcement-only note; candidate identity, error contracts, legacy cleanup safety, tests, retained-runtime proof, docs alignment, and repository-wide blast radius all passed
- Reviewed feature tip: cf688dc0f7e496ba76f744567985242ec41424f9
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: cf688dc0f7e496ba76f744567985242ec41424f9
- Accepted main tip: 4c54e13bdbcb8b269723bb3e64e3b0b0237b5903

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
