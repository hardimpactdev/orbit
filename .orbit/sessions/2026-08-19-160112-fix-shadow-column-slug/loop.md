# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad:
- Worktree: /Users/nckrtl/orbit/.worktrees/fix-shadow-column-slug
- Branch: fix-shadow-column-slug

## Goal

The 2026_08_16_120000 shadow-column drop matches instances by the renamed driver_config slug the 2026_08_05 migration actually leaves behind, so a populated gateway database migrates instead of aborting every app as divergent placement.

## Scope

- Owned: `apps/gateway/database/migrations/2026_08_16_120000_drop_app_placement_shadow_columns.php` slug constant; `apps/gateway/tests/Feature/Migrations/DropAppPlacementShadowColumnsTest.php` fixtures plus a composed 08_05-then-08_16 regression on a live-shaped row
- Constraints: no data-effect change for databases where the migration already completed (only empty-apps fixture databases); divergent-placement fail-closed behavior preserved; no vocabulary-contract token regressions
- Out of scope: the release candidate rebuild and live update:all rerun that follow

## Proof

- Verification:
  - focused: passed - DropAppPlacementShadowColumnsTest 7 passed 34 assertions including the composed-chain regression, red-proofed against the previous constant reproducing the exact fleet failure message
  - broader: passed - `composer quality-check` on clean commit 36f40473eb0d698f2844a4ea6d300d04b2aedd95 exit 0, 45/45 subgates (`.orbit/quality-gates/quality-check-2026-08-19T075739Z-6f3aadfecdac.json`)
  - runtime: passed - candidate=36f40473eb0d698f2844a4ea6d300d04b2aedd95; venue=retained-incus; environment=dev-fixture; target=orbit-e2e-dev-f00f51-gateway; expected=exact candidate matches renamed-slug instances during the shadow-column drop and keeps divergent placement fail-closed in the routed retained gateway environment; observed=matching migration sha256 3502d7cbb832 and 20 tests passed 139 assertions across the drop, replay-safety, and vocabulary suites in the retained gateway instance; result=passed; evidence=`.orbit/evidence/fix-shadow-column-slug-retained-incus-runtime.txt`
- Blast radius: complete - evidence=slug audit across all pending migrations plus read-only fleet database inspection; result=only this migration compares the pre-rename slug, every other pending 2026_08 migration is slug-free, the two already-applied wave migrations verified correct on the fleet database with zero orphaned binding rows, matcher and backfill writer share the corrected constant
- Review: passed - orchestrator Claude reviewer VERDICT PASS: constant now matches the 08_05 rewrite output and the enforced runtime morph map, composed-chain regression pins the producer-consumer ordering, fail-closed divergence path retained and re-proven; human-judgment=not-required
- Reviewed feature tip: 36f40473eb0d698f2844a4ea6d300d04b2aedd95
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 36f40473eb0d698f2844a4ea6d300d04b2aedd95
- Accepted main tip: d859b4d5fa678807c97c199b12f2ce8de65f766e

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
