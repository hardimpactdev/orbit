# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: solo://proj/4/scratchpad/orbit-feature-loop-e--341
- Worktree: /Users/nckrtl/orbit/.worktrees/codex-loop-proof-venue-routing
- Branch: codex/loop-proof-venue-routing

## Goal

Expose a deterministic, read-only, diff-derived proof-venue route before expensive PROVE work; route repository-only TypeScript SDK packaging to automated while keeping PHP SDK, shared core, CLI, and node/runtime on retained-incus; require live/production proof receipts to use exact `environment=live`; preserve fail-closed acceptance and actor/venue separation.

## Scope

- Owned: `bin/orbit-feature-acceptance`, `bin/orbit-loop-contract.php`, `apps/gateway/tests/Feature/E2ESupport/FeatureAcceptanceTest.php`, and concise contract alignment in `HARNESS.md`, `LOOP.md.example`, `.agents/skills/implementing-features/SKILL.md`, `apps/docs/content/testing/quality-gates.md` only as needed.
- Constraints: same exact diff derivation feeds route/ready/accept; fail closed when candidate/base/merge-base/diff cannot be derived; actor automation never weakens venue; no E2E/provision/release/live mutations; no push.
- Out of scope: runtime product behavior; E2E/provision commands; release/deploy/live mutations; semantic graders; new analyzers/lanes; broad package reclassification.

## Proof

- Verification:
  - focused: passed - repair after Fable FIX 1404: `bin/orbit-gateway-pest --compact tests/Feature/E2ESupport/FeatureAcceptanceTest.php` → 121 passed (603 assertions), 51.40s; `php -l` clean; focused Mago format check clean. RED first: PHP SDK dataset expected retained-incus got automated (2 failed).
  - broader: passed - `composer quality-check` exit 0 on candidate `53fa9849b5dee995c09094846806886b91b40562` (dirty=false); artifact `.orbit/quality-gates/quality-check-2026-08-05T094141Z-5565fff6f2f6.json` sha256 `38930fea01659d333034250f9db2b525bd017193f7e48d496994f667af4cf8f1`; 45/45 subgates exit 0
  - runtime: not applicable
- Blast radius: complete - evidence=bounded sweeps of remaining repository-only claims and venue vocabulary consumers in orbit-loop-contract, pre-tool-use hook, session archive, and feature acceptance; result=no affected surface remains (only packages/sdk-typescript remains repository-only automated)
- Review: passed - Fable process 1404 - human-judgment=not-required
- Reviewed feature tip: 53fa9849b5dee995c09094846806886b91b40562
- Acceptance venue: automated
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 53fa9849b5dee995c09094846806886b91b40562
- Accepted main tip: d45b2dee05209fd0529da21d509db233208ff794

## Status

- State: land
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
