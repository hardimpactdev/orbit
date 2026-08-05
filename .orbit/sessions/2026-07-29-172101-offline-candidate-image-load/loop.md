# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: not required
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-offline-candidate-image-load`
- Branch: `codex/offline-candidate-image-load`

## Goal

Topology-candidate workload updates succeed without private registry credentials
by loading the checksummed role-image artifact, aliasing its exact local image
ID to the stable runtime reference, and verifying the stable alias.

## Scope

- Owned: candidate role-image artifact loading, stable alias verification, and
  their focused CLI/gateway tests.
- Constraints: retain SHA-256 verification before image load; do not add
  credentials to Beast; preserve GitHub release/version boundaries.
- Out of scope: unrelated fleet drift, registry authentication, and runtime
  image contents.

## Proof

- Verification:
  - focused: passed - CLI offline artifact test and gateway candidate alias
    test.
  - broader: passed - CLI installer file 17 passed / 128 assertions; gateway
    verifier file 11 passed / 57 assertions; scoped Mago format/analyze passed;
    exact-tip `ORBIT_QUALITY_CHECK_CPU_BUDGET=4 composer quality-check` passed
    with receipt
    `.orbit/quality-gates/quality-check-2026-07-29T151809Z-2a49ecc4d60f.json`.
  - runtime: passed - topology=dev-5bca7e; kind=operator_gateway_app-dev;
    roles=dev,gateway; evidence=`.orbit/evidence/runtime-proof.txt`.
- Blast radius: complete - evidence=`rg -n "role_image_aliases|source_image|verifyRequiredRoleImages|requiredRoleImagesToVerify|topology-candidate" apps/cli/app apps/gateway/app`; result=only the candidate artifact/alias and verifier paths consume this behavior.
- Review: passed - human-judgment=not-required; blast-radius=complete; the
  archive-first contract, exact-tip quality receipt, and retained real-Docker
  registry-unavailable proof have no unresolved findings.
- Reviewed feature tip: caf52767baf5d7c903cc2e2482cab3e68ce93d48
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: caf52767baf5d7c903cc2e2482cab3e68ce93d48
- Accepted main tip: df9a471ac3af2a555d0ed6f41805775b395b4837

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
