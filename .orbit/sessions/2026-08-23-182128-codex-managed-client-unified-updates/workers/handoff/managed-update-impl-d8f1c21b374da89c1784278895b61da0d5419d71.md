# Impl handoff: managed-update-impl

candidate=d8f1c21b374da89c1784278895b61da0d5419d71

## Checkout

- Worktree: `/home/nckrtl/orbit/.worktrees/codex-managed-client-unified-updates`
- Branch: `codex/managed-client-unified-updates`
- HEAD: `d8f1c21b374da89c1784278895b61da0d5419d71`
- Status: clean

## RED

```text
bin/orbit-gateway-pest --compact --filter='selects only active Agent-eligible|excludes the caller from remote fan-out even when the caller is a managed client'
```

Failed: managed operator was excluded from `workloadNodes`.

```text
bin/orbit-gateway-pest --compact --filter='skips a managed macOS client whose Agent is unavailable before mutation'
```

Failed: unreachable managed Mac completed instead of skipping with `orbit_desktop_not_running`.

```text
bin/orbit-cli-pest --compact --filter='remaps a streamed tool:install Agent-unavailable error in json mode'
```

Failed: streamed `--json` terminal frame kept `data.data.code=node.agent_unreachable` and omitted remediation. Renderer: `App\Commands\Concerns\StreamsGatewayProgress::renderProgressTerminalFrame`.

## GREEN

```text
bin/orbit-gateway-pest --compact tests/Unit/Services/Operations/FleetUpdateTargetSelectorTest.php tests/Feature/Services/Operations/WorkloadNodeUpdaterTest.php tests/Unit/Data/Operations/ReleaseManifestDesktopArtifactTest.php tests/Feature/Release/ReleaseManifestGeneratorTest.php tests/Feature/Services/Operations/OperationUpdatePlanStoreTest.php
bin/orbit-cli-pest --compact tests/Feature/InternalFleetUpdateInstallCliCommandTest.php tests/Unit/Services/Updates/PendingDesktopUpdateHandoffTest.php tests/Feature/Services/Updates/UpdateAllHumanProgressRendererTest.php tests/Feature/Commands/Tool/ToolListCommandTest.php tests/Feature/Commands/Tool/ToolStreamCommandTest.php
composer docs-lint
composer quality-check
```

All passed. Terminal `composer quality-check` on `d8f1c21b374da89c1784278895b61da0d5419d71` exited 0 in 136s.

## Streamed regression fix

`StreamsGatewayProgress::renderProgressTerminalFrame` remaps the terminal payload once through `AgentAvailabilityError::remapStreamPayload` before human, `--json`, and `--stream-json` output. Gateway still emits `node.agent_unreachable`. CLI public code is `orbit_agent_unavailable` with macOS remediation to open Orbit Desktop.

Covered by `ToolStreamCommandTest` for all three output modes.

Owner runtime proof on topology `dev-069669` confirmed live JSON, stream-json, and human `tool:install` output after that remap.

## Focused Mago

Ran focused Mago analyze/format/lint on changed production PHP in gateway, CLI, and core. Lint suppressions are explicit on the new desktop/handoff classes. `apps/macos/**` and `apps/agent/**` were not edited.

## Terminal quality gate

- Command: `composer quality-check`
- Commit: `d8f1c21b374da89c1784278895b61da0d5419d71`
- Result: pass
- Artifact: `.orbit/quality-gates/quality-check-2026-08-23T150605Z-5c4613c38481.json`
- Profile: `.orbit/quality-gates/profiles/2026-08-23T15-03-49Z-d8f1c21b374d`

## Proof receipt

`bin/orbit-feature-proof-receipt --json` on clean HEAD `d8f1c21b374da89c1784278895b61da0d5419d71`:

```text
ok=true
gate=quality-check
venue=retained-incus
dirty=false
artifact=.orbit/quality-gates/quality-check-2026-08-23T150605Z-5c4613c38481.json
runtime=passed
```

## Runtime proof

Owner-recorded retained-incus proof is in `.orbit/loop.md` and `.orbit/evidence/managed-client-unified-updates-retained-incus.txt`. Topology `dev-069669` is released.

Observed: roleless managed Mac selected, unmanaged peer excluded, unavailable managed Mac skipped before mutation with `orbit_desktop_not_running`, Agent-unavailable CLI output remapped in JSON/NDJSON/human, owner-only automatic desktop handoff accepted matching identity and rejected a stale build.

## Design decisions

- Target set is the union of existing role-bearing Agent-eligible nodes and `managed=true` Agent-eligible non-gateway nodes. Caller exclusion is unchanged. Unmanaged roleless clients stay out.
- Managed macOS skip uses `ProvisioningAgentReadinessProbe::isReady()` before the node lease. Reason `orbit_desktop_not_running`. After the first install mutation, failures stay failed.
- Desktop artifacts are optional. Legacy manifests without them still parse. Signature, version, platform, and architecture are required when present.
- Pending desktop update handoff is CLI-owned, owner-only (`0600`), atomic, and binds operation id, version, build id, desktop/Agent/CLI identities, signature, and install mode `automatic` for `update:all`.
- Gateway API keeps `node.agent_unreachable`. CLI remaps it to `orbit_agent_unavailable` with node, platform, and macOS remediation at the shared streamed terminal boundary.
- Native `apps/macos` lifecycle is not implemented in this slice.

## Remaining risks

- Caller-local `update:all` still updates the CLI through the existing local fan-out. Remote managed Macs get the three-artifact installer payload. A caller that is itself a managed Mac is excluded from remote fan-out by design.
- Desktop identity is staged and bound in the handoff; native app replacement and final installed-bundle verification wait for the Tauri slice.
