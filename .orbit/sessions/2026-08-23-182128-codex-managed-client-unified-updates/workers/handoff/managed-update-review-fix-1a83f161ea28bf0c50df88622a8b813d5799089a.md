# Impl handoff: managed-update-review-fix

candidate=1a83f161ea28bf0c50df88622a8b813d5799089a

## Checkout

CHECKOUT_PROOF: cwd=/home/nckrtl/orbit/.worktrees/codex-managed-client-unified-updates; branch=codex/managed-client-unified-updates; head=1a83f161ea28bf0c50df88622a8b813d5799089a; status=clean

- Worktree: `/home/nckrtl/orbit/.worktrees/codex-managed-client-unified-updates`
- Branch: `codex/managed-client-unified-updates`
- HEAD: `1a83f161ea28bf0c50df88622a8b813d5799089a`
- Status: clean
- Parent review: `d8f1c21b374da89c1784278895b61da0d5419d71` verdict=FIX

## Defects closed

1. Desktop staging and automatic handoff require `managed=true` and macOS. An unmanaged role-bearing Mac gets `desktop_artifact=null` and `pending_desktop_update=null`.
2. `desktopArtifactPayload()` returns null when the same-platform Agent artifact is absent, before any installer mutation.
3. `ReleaseManifest::desktopArtifacts()` and `OperationUpdatePlanSnapshot::assertDesktopArtifacts()` reject a desktop version that does not equal the manifest/plan target version.
4. `WorkloadNodeUpdaterTest` proves a reachable managed Mac install payload includes `desktop_artifact` (with `staged_path`) and `pending_desktop_update` (operation id, target version, build identity).
5. Deploy, process-lifecycle, and process-log Agent-unreachable meta include `platform`. CLI contracts now name public `orbit_agent_unavailable` instead of `node.agent_unreachable`. Gateway JSON remains `node.agent_unreachable`.

## Polish

- Skip registry `forget()` on `UpdateRunner::markSucceeded` and `markFailed`.
- Removed unused `preMutationSkip($operationRun)` parameter.
- Desktop payload computed once in `installPayload()` and passed into the pending-update payload.
- Staged path validated before writing bytes.
- Pre-mutation skip uses `AgentAvailabilityError::DesktopNotRunning`.
- `publicMessage()` `$meta` has `array<string, mixed>`.
- Documented `prepare_prerequisites`, `skipped_targets`, and `desktop_artifacts` success field.

## RED then GREEN

RED: version-mismatch tests, unmanaged-Mac payload test, incomplete-desktop payload test, reachable managed-Mac payload test, skip-registry `forget()`, unsafe staged path, deploy/process/log platform meta.

GREEN: those tests plus full `WorkloadNodeUpdaterTest` (33 passed), desktop CLI install tests, skipped-marker renderer test.

## Terminal quality gate

- Command: `composer quality-check`
- Commit: `1a83f161ea28bf0c50df88622a8b813d5799089a`
- Result: pass
- `git.dirty`: false
- Artifact: `.orbit/quality-gates/quality-check-2026-08-23T160355Z-5f3bd1ec2f36.json`
- Profile: `.orbit/quality-gates/profiles/2026-08-23T16-01-42Z-1a83f161ea28`
- Duration: 133s

Do not use `.orbit/quality-gates/quality-check-2026-08-23T155559Z-f9c01e28e44a.json`; that run started dirty.

## Proof receipt

`bin/orbit-feature-proof-receipt --json` on clean HEAD `1a83f161ea28bf0c50df88622a8b813d5799089a`:

```text
ok=true
problem=null
gate=quality-check
venue=retained-incus
dirty=false
artifact=.orbit/quality-gates/quality-check-2026-08-23T160355Z-5f3bd1ec2f36.json
runtime=passed
```

## Runtime proof

Owner-recorded retained-incus proof is in `.orbit/loop.md` and `.orbit/evidence/managed-client-unified-updates-retained-incus.txt`. Topology `dev-669dc2` (`operator_gateway_agent`).

Observed: roleless managed Mac selected, unmanaged peer excluded, unavailable managed Mac skipped before mutation with `orbit_desktop_not_running` and no installed CLI identity, Agent-unavailable CLI output remapped in JSON/stream-json/human with Desktop remediation, owner-only automatic desktop handoff accepted matching identity and rejected a stale build.

No `composer test:e2e*` was run.

## Remaining risks

- Native `apps/macos` install and bundle verification remain out of scope.
- Gateway still emits `node.agent_unreachable`; CLI remaps at `GatewayCommand` and streamed terminal frames.
