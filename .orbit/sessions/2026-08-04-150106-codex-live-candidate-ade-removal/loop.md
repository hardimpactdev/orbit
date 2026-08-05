# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: live-candidate ADE removal packaging fix (release-blocking)
- Worktree: /Users/nckrtl/orbit/.worktrees/codex-live-candidate-ade-removal
- Branch: codex/live-candidate-ade-removal

## Goal

Remove stale gateway Docker packaging references to the deleted
`apps/gateway/resources/node-scripts` directory so `bin/orbit-release-candidate
build` can build the gateway image from current main, with deterministic
regression coverage that fails when a host-context Dockerfile COPY or matching
dockerignore force-include references a nonexistent source path. Keep E2E
gateway artifact fingerprint path lists aligned so they do not encode the
deleted path as a permanent `missing` hash input.

## Scope

- Owned: `docker/orbit-gateway/Dockerfile`, `docker/orbit-gateway/Dockerfile.dockerignore`, `apps/gateway/tests/Feature/Runtime/OrbitGatewayImageContractTest.php`, `apps/e2e/app/E2E/Support/E2EArtifactBuildFingerprint.php`, `apps/e2e/app/E2E/Support/E2EProvisionFingerprint.php`, owning E2ESupport fingerprint tests
- Constraints: no VERSION bump; no candidate rebuild/publish; no merge/push; no `composer test:e2e*`; preserve prior live baseline and failed build log evidence under `.orbit/release-evidence/2026-08-04-live-candidate-ade-removal/`; do not recreate empty `node-scripts` or restore deleted Agent-IDE script
- Out of scope: live update:all, legacy tool:remove, runtime promote, GitHub release, bulk doctor/drift restoration

## Proof

- Verification:
  - focused: passed - OrbitGatewayImageContractTest (6 passed); apps/e2e pest E2EArtifactBuildFingerprintTest + E2EProvisionCheckpointTest (19 passed); evidence `.orbit/release-evidence/2026-08-04-live-candidate-ade-removal/13-review-fix-gateway-image-tests.txt`, `.orbit/release-evidence/2026-08-04-live-candidate-ade-removal/13-review-fix-e2e-support-tests.txt`
  - broader: passed - `composer quality-check` exit 0 on exact feature HEAD `8ae06ade95405b5c08eccbc6056d74ee1f5b334a`; log `.orbit/release-evidence/2026-08-04-live-candidate-ade-removal/14-quality-check-review-fix.txt`; final-check `.orbit/release-evidence/2026-08-04-live-candidate-ade-removal/15-quality-gate-final-check-review-fix.txt`
  - runtime: passed - packaging declarative contract on exact HEAD 8ae06ade95405b5c08eccbc6056d74ee1f5b334a: host-context Dockerfile COPY sources exist (non-vacuous including apps/gateway/app and VERSION); stale node-scripts COPY/dockerignore/fingerprint inputs removed; focused OrbitGatewayImageContractTest and E2ESupport fingerprint tests green; quality-check exit 0; red blocked-build evidence retained at `.orbit/release-evidence/2026-08-04-live-candidate-ade-removal/06-candidate-build.log`; live candidate image rebuild is intentional release follow-on after LAND
- Blast radius: complete - evidence=`repository-wide node-scripts/orbit-notify-exit inventory on 8ae06ade95405b5c08eccbc6056d74ee1f5b334a plus Dockerfile/dockerignore COPY existence test and E2E fingerprint path alignment`; result=stale packaging and fingerprint missing-hash inputs removed; remaining node-scripts refs are negative test assertions only
- Review: passed - human-judgment=not-required; independent general re-review VERDICT=PASS; BLAST_RADIUS=complete; no actionable findings on exact candidate 8ae06ade95405b5c08eccbc6056d74ee1f5b334a
- Reviewed feature tip: 8ae06ade95405b5c08eccbc6056d74ee1f5b334a
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 8ae06ade95405b5c08eccbc6056d74ee1f5b334a
- Accepted main tip: a9fb5932d4b78e04577ce4142c486a90e3bbd764

## Status

- State: land
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
