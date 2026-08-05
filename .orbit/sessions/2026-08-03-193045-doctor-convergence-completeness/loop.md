# Orbit Feature Loop

- Scratchpad: none
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-doctor-convergence-completeness`
- Branch: `codex/doctor-convergence-completeness`

## Goal

Node-scoped `orbit doctor --restore` is convergence-complete: the restore command’s own authoritative re-probe must observe mutations and return healthy/converged with no residual restorable issues, under exact scope fences. DoctorRestoreSupport is family-owned dispatch support (not a passive allowlist). Retained checkout supplies gateway-derived public CA without client HTTP CA hop.

## Scope

- Owned: Doctor catalog/restore/convergence, harness CA control-path, docs/PRODUCT_DECISIONS alignment
- Out of scope: inventing unsafe restorers; feature not landed to main until LAND

## Proof

- Verification:
  - focused: passed - Doctor restore suites including converges node.agent_expectation_stale
  - broader: passed - composer quality-check on d8b302c613d0f5cbbf3f6395ec289223fe893e26 dirty=false via `.orbit/quality-gates/quality-check-2026-08-03T172313Z-eefa12a4ceee.json`
  - runtime: passed - retained-incus operator_gateway controlled node.agent_expectation_stale via `.orbit/evidence/doctor-retained-incus-proof-dev-4d398b.md` start `.orbit/evidence/incus-start-operator_gateway-d8b302c61.json` restore `.orbit/evidence/doctor-restore-agent-dev-4d398b.json` after-verify `.orbit/evidence/doctor-verify-agent-after-dev-4d398b.json` reap `.orbit/evidence/incus-stop-dev-4d398b.json`
- Blast radius: complete - evidence=DoctorIssueCatalogInventoryTest/DoctorRestoreSupportTest family support maps and apply-path binding; multi-pass Node re-resolve regression; monorepo quality-check; result=genuine_drift bound to real dispatch, same-request re-probe authoritative, activation UI preserved via main merge
- Review: passed - human-judgment=not-required
- Reviewed feature tip: d8b302c613d0f5cbbf3f6395ec289223fe893e26
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: d8b302c613d0f5cbbf3f6395ec289223fe893e26
- Accepted main tip: 6dbcbd2d0292db36f7645a6fc8e2e9fa86c1e340

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
