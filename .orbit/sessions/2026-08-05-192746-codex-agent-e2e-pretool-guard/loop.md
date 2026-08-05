# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: /Users/nckrtl/shared-knowledge/projects/orbit/superpowers/2026-08-05-claude5-context-engineering.md (approved design, Slice 2; Solo project 79)
- Worktree: /Users/nckrtl/orbit/.worktrees/codex-agent-e2e-pretool-guard
- Branch: codex/agent-e2e-pretool-guard

## Goal

`bin/orbit-codex-pre-tool-use-hook` deterministically blocks every `composer test:e2e*` agent Bash tool command (JSON tool input and argv, including suffix, env-prefix, path, run/run-script, and chained forms) with exit 2 and a dedicated human-only teaching message, while `composer quality-check`, patch payloads, quoted prose mentions, and all existing finalization boundary behavior pass through unchanged.

## Scope

- Owned: bin/orbit-codex-pre-tool-use-hook; bin/orbit-codex-pre-tool-use-hook-test; apps/gateway/tests/Feature/E2ESupport/QualityGateArtifactsTest.php; .orbit/loop.md
- Constraints: never run, delegate, background, schedule, or trigger any composer test:e2e* command — tests only feed command strings into the hook; the enforcement boundary is the agent PreToolUse hook only, the direct human shell lane stays documented and untouched; do not alter or remove Composer E2E scripts; HARNESS.md remains the single canonical prose contract with no broad prose change; block output teaches the user-only direct shell lane without telling the agent to ask the user to run it
- Out of scope: Composer script edits; .claude/settings.json permission-list changes; prose consolidation beyond the hook; any E2E execution or delegation

## Proof

- Verification:
  - focused: passed - post-merge HEAD f854bbafdd6eccb0de18288f30b419f734bf6bc0 (main a92c01a9 merged conflict-free; candidate delta vs main unchanged at 3 files/201 insertions): `bin/orbit-codex-pre-tool-use-hook-test` exit 0 (`.orbit/evidence/e2e-guard-green-hook-test-post-merge.txt`), gateway Pest filter "keeps e2e test commands manual only" exit 0 (`.orbit/evidence/e2e-guard-green-gateway-pest-post-merge.txt`); slice RED `.orbit/evidence/e2e-guard-red-hook-test.txt` + `.orbit/evidence/e2e-guard-red-gateway-pest.txt`, pre-merge GREEN `.orbit/evidence/e2e-guard-green-hook-test.txt` + `.orbit/evidence/e2e-guard-green-gateway-pest.txt` on 7b1d237f9 incl. FeatureFinalizationGate + FeatureLand + QualityGateArtifacts 240/240; incoming main touches no hook-adjacent surface; contract reshape rationale `.orbit/evidence/e2e-guard-contract-reshape.md`
  - broader: passed - `composer quality-check` exit 0 bound to post-merge HEAD f854bbafdd6eccb0de18288f30b419f734bf6bc0, dirty false, all 45 subgates 0 (`.orbit/quality-gates/quality-check-2026-08-05T172213Z-422b67403620.json`); earlier pre-merge run exit 0 on 7b1d237f9 (`.orbit/quality-gates/quality-check-2026-08-05T170341Z-176eacd4d688.json`) after triaging first-attempt failures (gateway_mago_format diff-genuine formatted+amended; cli_pest=143 contention with narrow rerun 2508 passed; sdk_typescript_*=127 fixed by `npm ci`; no test weakened)
  - runtime: not applicable
- Blast radius: complete - evidence=deterministic repository-wide contract checks (QualityGateArtifactsTest manual-only assertions: literal ban on the four other default gate scripts, hook guard needles, executable-vector bans, agent wiring pins; plus the executable `bin/orbit-codex-pre-tool-use-hook-test` rejected/accepted matrix); result=guard enforced at the single wired PreToolUse boundary with no orphaned or contradicting reference (independent reviewer 1465, BLAST_RADIUS complete)
- Review: passed - human-judgment=not-required (independent general reviewer 1465; one FIX round: main movement a92c01a9 integrated by merge; final delta review VERDICT PASS)
- Reviewed feature tip: f854bbafdd6eccb0de18288f30b419f734bf6bc0
- Acceptance venue: automated
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: f854bbafdd6eccb0de18288f30b419f734bf6bc0
- Accepted main tip: a92c01a958ea9dae08eca8e72b8b6c4b3e6eb0f8

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
