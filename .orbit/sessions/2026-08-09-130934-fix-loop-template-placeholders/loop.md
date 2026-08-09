# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `solo://proj/2/scratchpad/generated-loop-place--418` (plan; design is scratchpad 417)
- Worktree: `/Users/nckrtl/orbit/.worktrees/fix-loop-template-placeholders`
- Branch: `fix-loop-template-placeholders`

## Goal

Completed packets copied from `LOOP.md.example` pass compact finalization lint without editing instructional guidance, while genuine unfinished placeholders remain blocked.

## Scope

- Owned: `LOOP.md.example` and focused finalization-gate Pest coverage.
- Constraints: preserve the self-documenting footer and strict whole-packet placeholder detection; use TDD; no `composer test:e2e*` lanes.
- Out of scope: linter policy, worktree seeding transformations, acceptance venues, archive behavior, LAND behavior, and the unrelated baseline random-address collision.

## Proof

- Verification:
  - focused: passed - `bin/orbit-gateway-pest --compact tests/Feature/E2ESupport/FeatureFinalizationGateTest.php tests/Feature/Architecture/McpConfigurationTest.php` (209 tests, 793 assertions); TDD red retained at `.orbit/evidence/tdd-red-loop-template-placeholders.txt`
  - broader: passed - `composer quality-check`; evidence `.orbit/quality-gates/quality-check-2026-08-09T110620Z-f77e50cec435.json`
  - runtime: not applicable
- Blast radius: complete - evidence=bounded repository-wide `LOOP.md.example` inventory covering `bin/orbit-prepare-worktree`, finalization and acceptance classifiers, and their tests; result=one production seeding path with no secondary template, transformation, conflicting consumer, or unresolved surface
- Review: passed - general reviewer - human-judgment=not-required
- Reviewed feature tip: 70fd14a062b14298d398d53cfeee58ca477220cb
- Acceptance venue: automated
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 70fd14a062b14298d398d53cfeee58ca477220cb
- Accepted main tip: 5c232fd1878dde2aed24934f251973457a19f855

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
