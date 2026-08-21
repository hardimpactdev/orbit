# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: solo://proj/120/scratchpad/focused-reliability--524
- Worktree: /Users/nckrtl/orbit/.worktrees/codex-audit-harness-acceptance
- Branch: codex/audit-harness-acceptance

## Goal

Fail closed with a precise split-slice error when one candidate diff requires
more than one orthogonal non-automated acceptance venue, while preserving all
automation-only and single-venue routing behavior.

## Scope

- Owned: `bin/orbit-loop-contract.php`, `bin/orbit-cleanup-checks.php` (finalization teaching-error propagation), focused acceptance/finalization Pest coverage, and the acceptance-venue contract in `HARNESS.md`.
- Constraints: TDD with an observed failing regression; use one exact venue for valid slices; never run or trigger `composer test:e2e*`.
- Out of scope: All other focused reliability audit remediations; those remain in roadmap loops 2 and 3.

## Proof

- Verification:
  - focused: passed - merged HEAD 79260f7ab bin/orbit-gateway-pest FeatureAcceptanceTest+FeatureFinalizationGateTest 308 passed
  - broader: passed - `.orbit/quality-gates/quality-check-2026-08-21T094326Z-df0fa6d19165.json`
  - runtime: not applicable
- Blast radius: complete - evidence=repository-wide rg for orbitLoopStrongerVenue and mixed-escalation across bin and apps/gateway/tests; result=no residual strength-ladder helper or stale mixed-escalation rows remain
- Review: passed - reviewer 2634 - human-judgment=not-required
- Reviewed feature tip: 79260f7ab2a16674907077659f09b52de38b58cf
- Acceptance venue: automated
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 79260f7ab2a16674907077659f09b52de38b58cf
- Accepted main tip: 78b023d37d24ae1c7a5880d340cd812eacaf2b59

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
