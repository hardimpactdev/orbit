# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: solo://proj/2/scratchpad/loop-experiment-data--526
- Worktree: /Users/nckrtl/orbit/.worktrees/data-only-loop-anchor
- Branch: data-only-loop-anchor

## Goal

Seed future feature loops with only mutable receipt data while preserving every executable review, proof, acceptance, and LAND protection.

## Scope

- Owned: `LOOP.md.example`, its focused gateway contract tests, and the stale `bin/orbit-prepare-worktree-test` dependency fixture discovered during verification
- Constraints: use test-first red/green proof; preserve historical loop compatibility; do not change venues, proof requirements, review, acceptance, states, or LAND
- Out of scope: venue downgrades, campaign wrappers, state-machine changes, analyzer tooling, and product documentation

## Proof

- Verification:
  - focused: passed - test-first red (3 expected footer failures) then green (303 tests, 1059 assertions), plus exact worktree seed/preservation test; evidence=`.orbit/evidence/tdd-red-data-only-loop-anchor.txt`, `.orbit/evidence/tdd-green-data-only-loop-anchor.txt`, `.orbit/evidence/prepare-worktree-test.txt`
  - broader: passed - `composer quality-check` (200s, every subgate exit 0) and evidence-only final check (no warnings); evidence=`.orbit/quality-gates/quality-check-2026-08-21T093052Z-7f0210ec22e0.json`
  - runtime: not applicable
- Blast radius: not-required - template-only teaching prose removal and isolated test-fixture correction; executable contracts and consumers are unchanged
- Review: passed - Claude Solo general reviewer process 2635 - human-judgment=not-required - no actionable findings
- Reviewed feature tip: 7d7cbb069f3e1f0864ff93c92692e06d90b06e20
- Acceptance venue: automated
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 7d7cbb069f3e1f0864ff93c92692e06d90b06e20
- Accepted main tip: 499b77507ee6319c92f6f0ec33c824e9f06baed4

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
