# Review handoff: managed-update-review (delta re-review)

candidate=1a83f161ea28bf0c50df88622a8b813d5799089a

verdict=PASS

## Checkout

CHECKOUT_PROOF: cwd=/home/nckrtl/orbit/.worktrees/codex-managed-client-unified-updates; branch=codex/managed-client-unified-updates; head=1a83f161ea28bf0c50df88622a8b813d5799089a; main=aa03ff60ff4d0b70ce7d47c042b875b3087fbd16; status=clean

- Prior reviewed tip: `d8f1c21b374da89c1784278895b61da0d5419d71` verdict=FIX
- Correction delta reviewed: `d8f1c21b374da89c1784278895b61da0d5419d71..1a83f161ea28bf0c50df88622a8b813d5799089a`
  (37 files, +570/-48, three commits: `78b28b9f7`, `df68319d8`, `1a83f161e`)
- Same reviewer context and process as the prior cycle, per `.agents/review-personas/general.md`.
  Delta-only review; no fresh review was required.

## Proof receipt

`bin/orbit-feature-proof-receipt --json` on clean HEAD:

```text
ok=true
problem=null
candidate=1a83f161ea28bf0c50df88622a8b813d5799089a
dirty=false
gate=quality-check
artifact=.orbit/quality-gates/quality-check-2026-08-23T160355Z-5f3bd1ec2f36.json
venue=retained-incus
runtime=passed
```

The receipt now satisfies the retained-Incus runtime requirement that was `pending` in the impl
handoff's own quoted receipt; `.orbit/loop.md` and
`.orbit/evidence/managed-client-unified-updates-retained-incus.txt` were refreshed to candidate
`1a83f161ea28bf0c50df88622a8b813d5799089a` on topology `dev-669dc2`, and the impl handoff was updated to
match. The superseded dirty artifact `quality-check-2026-08-23T155559Z-f9c01e28e44a.json` is explicitly
disclaimed in the impl handoff and was not used. Broad gates were not repeated.

## Prior defects

### D1 — Desktop staging not gated on `managed` — CLOSED

`WorkloadNodeUpdater::desktopArtifactPayload()` now returns `null` unless `$node->managed` and the
platform is macOS. Proven by `it('does not stage a desktop archive for an unmanaged role-bearing Mac')`,
which asserts a completed update with `desktop_artifact => null` and `pending_desktop_update => null`
while CLI and Agent artifacts still flow. `1_update-all.md:158` now states the managed-only rule and that
an unmanaged Mac with a workload role still receives CLI and Agent updates but not desktop identity.

### D2 — Post-mutation failure when the Agent artifact is absent — CLOSED

`desktopArtifactPayload()` returns `null` when `agentArtifactPayload()` is `null` for the same platform,
so the incomplete identity is dropped before the installer runs rather than failing after the CLI is
installed and the archive staged. Proven by
`it('omits an incomplete desktop identity when the same-platform Agent artifact is absent')`, which
asserts all three payload fields are null and the node still completes.

### D3 — Unenforced "one version/build" binding — CLOSED

Enforced at both layers: `ReleaseManifest::desktopArtifacts()` now takes `$manifestVersion` and rejects a
divergent artifact version, and `OperationUpdatePlanSnapshot::assertDesktopArtifacts()` rejects any
artifact whose `version` differs from `targetVersion`. The check sits in the constructor, so every
construction path (`fromArray`, `UpdatePlanBuilder::fromRequest` including the `desktop_artifacts` request
override, and `OperationUpdatePlan::toSnapshot`) is covered — confirmed by the callers search below. Two
rejecting tests were added in `ReleaseManifestDesktopArtifactTest`.

Regression check on the new strictness: `bin/orbit-release-manifest:25,55,351` derives the manifest
`version` and each desktop artifact `version` from the same `normalize_version()` result, so no
Orbit-generated manifest can trip the check. `UpdatePlanBuilder` defaults `targetVersion` to
`$manifest->version`, and no client in `apps/cli`, `apps/e2e`, or `packages/sdk` sends a `target_version`
override, so no current caller can fail closed. Recorded as an observation below, not a finding.

### D4 — No gateway-side proof of the success transition — CLOSED

`it('stages a desktop archive and pending automatic handoff for a reachable managed Mac')` asserts the
whole emitted install payload: relayed `artifact_url`, `sha256`, `signature`, `version`, `platform`,
`architecture`, the derived `staged_path` under `~/.local/share/orbit/updates/`, and the
`pending_desktop_update` block with `path`, `operation_id`, `version`, `build_id`, and
`install_mode=automatic`. That is the declared `success:reachable managed client verifies the selected
artifacts` transition, now deterministically proven.

### D5 — Error-code drift and partial remediation — CLOSED

All four `agent_push_unavailable` producers now carry `platform`: `ToolScriptDispatcher.php:109`,
`ProcessToolLifecycleRunner.php:55`, `ToolLogReader.php:66`, and `DeployManager.php:475`. Each is
asserted — `AgentUnreachablePlatformMetaTest` (lifecycle and logs),
`DeployManagerContainerRoutingTest` (deploy), and `ToolLifecycleControllerTest` (gateway JSON). The 13
product docs that named `node.agent_unreachable` as the CLI failure code now name
`orbit_agent_unavailable`, and the output-render pages additionally document `error.meta.platform` and the
macOS `error.meta.remediation`. `ToolLogReader::read()` was split into `readTarget()` to stay within lint
limits; the split is behavior-preserving.

## POLISH (non-blocking, carried forward)

- `6.2_update-all_output-render_json.md:111` still documents `updates[].reason`, but nothing emits
  `data.updates` — `UpdateRunnerPipeline::updateWorkloads()` discards the per-node results that carry
  `reason`. The produced field is `success.data.skipped_targets`, now correctly documented alongside it.
  This is pre-existing `updates[]` drift, not introduced by either candidate.
- `desktopArtifactPayload()` now calls `agentArtifactPayload()`, and `installPayload()` calls it again for
  the `agent_artifact` field, so a managed Mac re-enters `artifactForKind()`/`stageArtifact()` twice for
  the Agent artifact. The prior duplicate-desktop-relay polish was fixed by hoisting `$desktopArtifact`;
  the same hoist would close this one.
- Two `node.agent_unreachable` references remain in `6.2_tool-logs_*` and `6.2_tool-reload_*` Test Mapping
  rows. Both describe what `ToolLifecycleControllerTest` asserts about the **gateway** JSON envelope,
  which intentionally keeps that code, so they are accurate — but a reader sees both codes on one page.
  A half-sentence noting the gateway/CLI split would remove the ambiguity.
- `process-concepts.md:107` documents `runtime_config.prepare_prerequisites` as four extra prose lines
  inside the existing `- **Docker process runtime:**` bullet. The concept-index `- **Term:**` bullet form
  is preserved correctly, but the bullet is now fat; per project docs convention this belongs in a
  subsection rather than a longer bullet.

## Observation (not a finding)

`target_version` remains an accepted request override in `UpdateAllStartApiRequest`. If a future caller
ever supplies one that diverges from the resolved manifest version while that manifest carries desktop
artifacts, plan construction now throws and `update:all` fails closed for the whole fleet, not just for
macOS. No current caller does this, and fail-closed is the correct behavior for an immutable identity, so
no change is requested — recorded so the constraint is visible if that override is ever wired up.

## Verified as still sound in the delta

- The pre-mutation skip still precedes lease acquisition and any remote call, and remains the only path
  that sets `reason`, so a started mutation still cannot be relabeled skipped.
- `FleetUpdatePreMutationSkipRegistry::forget()` is called in `markSucceeded` only after `$result` is
  built and both `appendComplete` and `succeeded` have run, so the skip map is still emitted before it is
  cleared. `FleetUpdatePreMutationSkipRegistryTest` proves per-operation isolation.
- `assertSafeStagedPath()` widened from private to public solely so
  `LocalFleetUpdateInstallCliAction::stageDesktopArchive()` can validate before writing bytes; the
  internal `assertPayload()` call site is unchanged, and the new test asserts neither the archive nor the
  handoff exists after rejection.
- `UpdateAllHumanProgressRendererTest` now asserts the whole settled row
  (`/●\s+mini\s+Skipped: Orbit Desktop is not running/`) rather than word presence, matching the
  documented orange `●` primitive.
- The pre-mutation skip reason is now the shared `AgentAvailabilityError::DesktopNotRunning` constant; no
  string literal remains in gateway or CLI application code.

## Blast radius

`BLAST_RADIUS: complete`.

The candidate affects a product decision, an ownership boundary, transport, shared vocabulary, and shared
schema, so the classification requires evidence beyond the diff. Bounded repository-wide checks run on
the corrected tip:

- `rg -n 'node\.agent_unreachable' apps/docs/content` → 2 hits, both gateway-test-mapping rows describing
  the gateway envelope that intentionally keeps the code. Down from 15 files. Result: resolved.
- `rg -n 'agent_push_unavailable' apps/gateway/app -A4` → 4 producers, all now emitting `platform`.
  Result: resolved.
- `rg -n 'desktopArtifactMap|assertDesktopArtifacts' apps packages` → every construction path funnels
  through the `OperationUpdatePlanSnapshot` constructor, so no path bypasses the version binding.
  Result: resolved.
- `rg -n 'assertSafeStagedPath' apps packages` → 3 sites; the widened visibility has exactly one new
  external caller. Result: resolved.
- `rg -n "'orbit_desktop_not_running'" apps/gateway/app apps/cli/app packages/core/src` → only the shared
  constant declaration. Result: resolved.
- `rg -n 'target_version' apps/cli/app apps/e2e/app packages/sdk/src` → no client sends the override, so
  the stricter plan validation cannot fail closed for a current caller. Result: resolved.
- `rg -n 'prepare_prerequisites' apps/docs/content` → now documented in `process-concepts.md`.
  Result: resolved (POLISH on bullet weight only).

No affected surface remains unresolved.

## Required final lines

BLAST_RADIUS: complete
HUMAN_JUDGMENT: not-required
VERDICT: PASS
