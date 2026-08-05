# Orbit Feature Loop

- Scratchpad: solo://proj/4/scratchpad/orbit-feature-loop-e--341
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-loop-release-evidence-archive`
- Branch: `codex/loop-release-evidence-archive`

## Goal

Compact session archives retain only exact cited regular files under the
release-evidence tree, with every retained byte bound by receipt digests.

## Scope

- Owned: `bin/orbit-session-archive`; `bin/orbit-loop-contract.php`
  (`orbitLoopProofReferences` only); `bin/orbit-codex-pre-tool-use-hook`
  compact-receipt path allowlist; `apps/gateway/tests/Feature/E2ESupport/SessionArchiveTest.php`;
  separation coverage in `FeatureAcceptanceTest.php`; concise contract alignment
  in `HARNESS.md`, `.agents/skills/implementing-features/SKILL.md`, and archive
  command help. `LOOP.md.example` left unchanged (runtime receipt roots remain
  evidence/quality-gates only).
- Constraints: preserve existing `.orbit/evidence` and `.orbit/quality-gates`
  behavior; do NOT expand runtime receipt roots or
  `orbitLoopRuntimeProofEvidenceProblem`; directories, traversal, direct
  symlinks, symlinked parents, malformed/empty segments, missing paths, and
  uncited siblings remain rejected or omitted; historical archives remain valid;
  no bulk copying. Compact receipts evolve to schema_version 3; full archives
  stay schema_version 2; schema-v2 compact historical receipts remain valid with
  evidence/quality-gates proof roots only.
- Out of scope: E2E, live nodes, release publication, runtime receipt vocabulary.
- Bootstrap baseline: official prepare tool created the worktree; parallel
  composer test had one `nodes.wireguard_address` unique collision after 5635
  passes. Exact named test then passed in isolation, 5 serial retries, and full
  file 7 tests/24 assertions. Nonblocking baseline signal.

## Proof

- Verification:
  - focused: passed - schema compatibility repair GREEN (refreshed candidate)
    - RED (compat on FIX tip): historical schema-v2 cleanup exit 2; SessionArchive still wrote schema 2 vs expected 3.
    - GREEN (compat): historical schema-v2 cleanup allows release-evidence loop citation without retained bytes; schema-v3 without release-evidence binding blocked; compact writes schema 3; full writes schema 2; full SessionArchiveTest 96 passed / 525 assertions; finalization compat filters pass; `bin/orbit-codex-pre-tool-use-hook-test` passed; Mago format clean; php -l clean.
    - Fable finding repaired; closed by refreshed independent review on `19a81e8acbe9bf6f2d446c90d220d583fab0e7a7`.
  - broader: passed - `composer quality-check` exit 0 in 72s against clean candidate `19a81e8acbe9bf6f2d446c90d220d583fab0e7a7`; artifact `.orbit/quality-gates/quality-check-2026-08-05T103248Z-3735218588d0.json` sha256=`fa7e001d3d4c111b26ef8bacf0db16d6a215faa6fd753b01b4877429a94d9836`; 45/45 subgates exit 0; dirty=false.
  - runtime: not applicable
- Blast radius: complete - evidence=`rg schema_version isVersionedCompactArchive compact_archive_receipt_is_valid orbitLoopProofReferences bin/orbit-session-archive bin/orbit-codex-pre-tool-use-hook; inventory FeatureFinalizationGateTest/SessionArchiveTest schema fixtures; Fable replay of all 112 compact receipts including historical .orbit/sessions/2026-08-04-211205-gateway-leaf-caddy-restart`; result=`writers: orbit-session-archive compact=3 full=2; readers/validators: compact_archive_receipt_is_valid accepts 2|3 with schema-scoped proof roots; isVersionedCompactArchive + slug match accept 2|3 compact; all 112 compact receipts valid under schema-scoped rules; historical schema-v2 release-evidence citations without retained bytes remain allowed; runtime receipt roots unchanged`
- Review: passed - Fable general reviewer - human-judgment=not-required - prior historical schema-v2 finding closed by replaying all 112 compact receipts; no actionable findings
- Reviewed feature tip: 19a81e8acbe9bf6f2d446c90d220d583fab0e7a7
- Acceptance venue: automated
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 19a81e8acbe9bf6f2d446c90d220d583fab0e7a7
- Accepted main tip: 4b2b7105952fa1ef1641339cc26a86c91db5a08f
- Quality-gate: `.orbit/quality-gates/quality-check-2026-08-05T103248Z-3735218588d0.json`
- Release evidence: `.orbit/release-evidence/2026-08-05-loop-release-evidence-archive/fable-review.txt`
- Fable finding (history): widened `orbitLoopProofReferences` broke schema-v2 archive `2026-08-04-211205-gateway-leaf-caddy-restart` cleanup validity. Repair: compact schema_version 3; validator accepts 2+3; schema 2 proof comparison evidence|quality-gates only; schema 3 includes release-evidence. Status: closed by refreshed review.
- Main movement: main `4b2b7105952fa1ef1641339cc26a86c91db5a08f` merged at `fecbb9780085ee9536b8b535228ac607f72af3b7`. Conflicts: none.
- Route (repair candidate): `bin/orbit-feature-acceptance route` -> candidate=`19a81e8acbe9bf6f2d446c90d220d583fab0e7a7` base=`main` base_tip=`4b2b7105952fa1ef1641339cc26a86c91db5a08f` merge_base=`4b2b7105952fa1ef1641339cc26a86c91db5a08f` changed_files=`8 paths` venue=`automated`

## Status

- State: land
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
