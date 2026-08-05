# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: solo://proj/4/scratchpad/orbit-feature-loop-e--341
- Worktree: /Users/nckrtl/orbit/.worktrees/codex-loop-compact-index-identity
- Branch: codex/loop-compact-index-identity

## Goal

Make compact session mining honest and deterministic by carrying explicit
candidate identity, separating raw archive records from unique explicit
candidates, and surfacing orphan archive directories.

## Scope

- Owned: `bin/orbit-session-index`,
  `apps/gateway/tests/Feature/E2ESupport/SessionIndexTest.php`, plus only the
  smallest harness/help documentation required to document migration/backfill
  semantics.
- Constraints: start from `defb9434f8d264ffefe125ecc38ea10cd4497eec`; no push;
  never run `composer test:e2e*`; preserve unrelated worktrees/processes;
  never infer historical identity from Git, timestamps, later repair commits, or
  loop prose; preserve exact duplicate prevention and already-landed cross-slug
  identity reuse; do not redesign archive creation/finalization.
- Out of scope: product behavior; archive receipt schema redesign;
  duplicate/cross-slug identity rules; cleanup/deletion of orphan directories;
  inferred historical identities; generic analytics/evaluator framework; E2E;
  review/acceptance/archive/merge/cleanup ownership for this worker.

## Proof

- Verification:
  - focused: passed - `bin/orbit-gateway-pest --compact tests/Feature/E2ESupport/SessionIndexTest.php` (17 passed / 408 assertions) after trailing-newline regression; RED `.orbit/evidence/session-index-trailing-newline-red.txt`, GREEN `.orbit/evidence/session-index-trailing-newline-green.txt` (prior baseline RED/GREEN under `.orbit/evidence/session-index-red.txt` / `.orbit/evidence/session-index-green.txt`)
  - broader: passed - exact-candidate `composer quality-check` at clean HEAD `53712ac4d47b43759e2ab6627fa57653570607a1`; artifact `.orbit/quality-gates/quality-check-2026-08-05T141413Z-b3de20731ba4.json` exit_code=0, dirty=false, all 45 subgates exit 0. Prior exact-candidate broader at `964852df22205987960cfa3a8f52dc7717f37cc0` remains `.orbit/quality-gates/quality-check-2026-08-05T140306Z-376f4fcbbc37.json` (45/45 zero after npm-ci infrastructure repair of initial TS 127 failure)
  - runtime: not applicable
- Blast radius: complete - evidence=repo-wide rg of orbit-session-index|sessions/index.json, schema_version, index field names, every programmatic consumer, plus exact-candidate 45/45 quality gate; result=no unreviewed consumers or schema drift outside owned index surface
- Review: passed - human-judgment=not-required - Fable terminal re-review PASS at 53712ac4d47b43759e2ab6627fa57653570607a1, trailing-newline finding closed
- Reviewed feature tip: 53712ac4d47b43759e2ab6627fa57653570607a1
- Acceptance venue: automated
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 53712ac4d47b43759e2ab6627fa57653570607a1
- Accepted main tip: defb9434f8d264ffefe125ecc38ea10cd4497eec

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
