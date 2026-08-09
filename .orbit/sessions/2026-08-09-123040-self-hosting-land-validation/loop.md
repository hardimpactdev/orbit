# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `/Users/nckrtl/shared-knowledge/projects/orbit/superpowers/specs/2026-08-09-self-hosting-land-validation-design.md`
- Worktree: `/Users/nckrtl/orbit/.worktrees/self-hosting-land-validation`
- Branch: `self-hosting-land-validation`

## Goal

LAND derives the minimum acceptance venue from the exact accepted candidate contract in an isolated subprocess while every other finalization check remains main-owned and fail-closed.

## Scope

- Owned: `bin/orbit-codex-pre-tool-use-hook`, its main-owned candidate venue helper if extracted, focused finalization/LAND tests, and one concise `HARNESS.md` clarification.
- Constraints: TDD from the current main-derived venue mismatch; exact candidate identity and changed-file inventory; candidate contract only in an isolated PHP subprocess; precise fail-closed errors; no candidate finalizer/checker execution; preserve accepted-main cleanup base.
- Out of scope: acceptance-routing policy changes, allowlists, unrelated loop/archive/review redesign, and all `composer test:e2e*` lanes.

## Proof

- Verification:
  - focused: passed - `bin/orbit-gateway-pest --compact tests/Feature/E2ESupport/FeatureFinalizationGateTest.php tests/Feature/E2ESupport/FeatureLandTest.php tests/Feature/Architecture/McpConfigurationTest.php` (251 tests, 939 assertions); TDD red retained at `.orbit/evidence/tdd-red-candidate-venue.txt`
  - broader: passed - `composer quality-check`; evidence `.orbit/quality-gates/quality-check-2026-08-09T102737Z-e29e6a5a0774.json`
  - runtime: not applicable
- Blast radius: complete - evidence=bounded repository-wide search of `orbitLoopAcceptanceVenue`, `orbitLoopExactProofRoute`, candidate venue resolution, acceptance identity, and LAND call sites; result=finalization-only ownership change, unchanged venue vocabulary and candidate-local routing, cleanup remains bound to Accepted main tip
- Review: passed - general reviewer - human-judgment=not-required
- Reviewed feature tip: 46a6506dc649cc05f9278e487e3ee928577ddc27
- Acceptance venue: automated
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 46a6506dc649cc05f9278e487e3ee928577ddc27
- Accepted main tip: 83b8e67027f4d8b2c697ace1ea2cd3144dc3ab14

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
