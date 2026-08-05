# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: none
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-services1-bootstrap-updater`
- Branch: `codex/services1-bootstrap-updater`

## Goal

Live Services1 bootstrap update succeeds: gateway fleet installer uses a
two-stage contract so the currently installed old CLI first receives a CLI-only
payload (atomic candidate CLI install), then the new CLI receives the full
payload for Agent config and role images (PHP-free path).

## Scope

- Owned:
  - `apps/gateway/app/Services/Operations/FleetUpdateNodeInstaller.php`
  - `apps/gateway/app/Services/Operations/FleetUpdateInstallResultInspector.php`
  - `apps/gateway/tests/Feature/Services/Operations/WorkloadNodeUpdaterTest.php`
  - `apps/gateway/tests/Feature/Services/Operations/FleetUpdateVerifierTest.php`
  - `apps/gateway/tests/Feature/Services/Operations/UpdateRunnerManifestPlanHandoffTest.php`
  - `apps/gateway/tests/Unit/Services/Operations/FleetUpdateInstallResultInspectorTest.php`
  - `apps/docs/content/domains/11_operation/2_update-all/**`
- Constraints: preserve self-update disconnect/retry and result validation; no
  `composer test:e2e*`; retained proof on disposable topology only; do not
  mutate unrelated worktrees
- Out of scope: merge/push/archive in this step; production Services1; delete
  worktrees

## Proof

- Verification:
  - focused: passed - `bin/orbit-gateway-pest --compact tests/Feature/Services/Operations/WorkloadNodeUpdaterTest.php tests/Feature/Services/Operations/FleetUpdateVerifierTest.php tests/Feature/Services/Operations/UpdateRunnerManifestPlanHandoffTest.php tests/Unit/Services/Operations/FleetUpdateInstallResultInspectorTest.php` (41 passed), including Services1-like two-stage payload contract
  - broader: passed - `composer quality-check` artifact `.orbit/quality-gates/quality-check-2026-08-02T195844Z-ebab59af4aac.json` exit 0, exact commit `ff39519d91874160f952231efb96fce0627b682a`, dirty=false
  - runtime: passed - retained topology `dev-92f4b4` kind `operator_gateway_agent` host Beast; disposable `agent-1` old-CLI→new-CLI two-stage install with Agent config under PHP sentinel; evidence `.orbit/evidence/dev-92f4b4-services1-bootstrap-runtime-proof.md`
- Blast radius: complete - evidence=repo-wide rg of install-cli/FleetUpdateNodeInstaller/bootstrap helpers across apps+packages; result=single fleet install staging owner (`FleetUpdateNodeInstaller` via `WorkloadNodeUpdater`); no other payload-staging callers
- Review: passed - human-judgment=not-required; independent read-only review of exact `ff39519d9` VERDICT=PASS; no actionable findings; BLAST_RADIUS=complete; residuals non-blocking only (dead old inspector method; optional edge-coverage gaps)
- Reviewed feature tip: ff39519d91874160f952231efb96fce0627b682a
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: ff39519d91874160f952231efb96fce0627b682a
- Accepted main tip: ded4d32960033840415ed9ed96ee96dab28dd04c

## Status

- State: land
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
