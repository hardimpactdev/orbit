# Review handoff: managed-update-review

candidate=d8f1c21b374da89c1784278895b61da0d5419d71

verdict=FIX

## Checkout

CHECKOUT_PROOF: cwd=/home/nckrtl/orbit/.worktrees/codex-managed-client-unified-updates; branch=codex/managed-client-unified-updates; head=d8f1c21b374da89c1784278895b61da0d5419d71; main=aa03ff60ff4d0b70ce7d47c042b875b3087fbd16; status=clean

- Worktree: `/home/nckrtl/orbit/.worktrees/codex-managed-client-unified-updates`
- Branch: `codex/managed-client-unified-updates`
- Candidate: `d8f1c21b374da89c1784278895b61da0d5419d71`
- Base: `aa03ff60ff4d0b70ce7d47c042b875b3087fbd16`
- Diff reviewed: `aa03ff60ff4d0b70ce7d47c042b875b3087fbd16..d8f1c21b374da89c1784278895b61da0d5419d71` (67 files, +2266/-122)
- `bin/orbit-feature-proof-receipt --json`: `ok=true`, `problem=null`, `dirty=false`, gate `quality-check`, artifact `.orbit/quality-gates/quality-check-2026-08-23T150605Z-5c4613c38481.json`, venue `retained-incus`.
- Broad gates were not repeated. No narrow reproduction was required; every finding below is established by reading the diff plus bounded repository search.

## DEFECT

### D1 — Desktop staging and the automatic handoff are not gated on `managed`

- Severity: blocking
- Evidence: `apps/gateway/app/Services/Operations/WorkloadNodeUpdater.php:459`
- Impact: `desktopArtifactPayload()` gates only on `NodeHostPaths::isMacosPlatform($node->platform)`.
  `Node::hasAgentIntent()` makes any node with an active Agent workload role eligible regardless of
  `managed`, so an **unmanaged** role-bearing macOS node is a legitimate `update:all` workload target and
  receives the staged desktop archive plus a `pending_desktop_update` with `install_mode: automatic`
  written into its owner config root. `apps/docs/content/tech-stack.md:465` and
  `apps/docs/content/domains/11_operation/2_update-all/update-all.md:129` both scope desktop staging to
  "reachable managed Macs", and `apps/docs/content/architecture.md:486` makes Orbit Desktop the macOS
  lifecycle owner only for managed clients. The candidate therefore takes desktop lifecycle ownership of
  a machine outside the ownership boundary this slice exists to draw.
- Smallest correction: `if (! $node->managed || ! NodeHostPaths::isMacosPlatform($node->platform)) { return null; }`,
  plus a test asserting an unmanaged role-bearing Mac gets `desktop_artifact => null` and
  `pending_desktop_update => null`.

### D2 — A desktop artifact without a same-platform Agent artifact fails post-mutation

- Severity: blocking
- Evidence: `apps/cli/app/Services/Operations/LocalFleetUpdateInstallCliAction.php:98`;
  `apps/cli/app/Services/Updates/PendingDesktopUpdateHandoff.php` (`assertArtifactIdentity`);
  `apps/gateway/app/Services/Operations/WorkloadNodeUpdater.php` (`agentArtifactPayload` returns `null`
  when the plan has no Agent artifact for the platform)
- Impact: the CLI writes `agent.sha256 = strtolower($installPayload->agentArtifact?->sha256 ?? '')`, and
  `assertArtifactIdentity()` rejects `''`. `agent_artifacts` and `desktop_artifacts` are independently
  optional in `ReleaseManifest`, so a manifest carrying `desktop_artifacts['darwin-arm64']` without a
  matching Agent artifact installs the CLI, stages the desktop archive, and only then fails with
  `fleet_update.desktop_handoff_failed: Pending desktop update agent hash is invalid`. That is a
  post-mutation failure caused by a plan the gateway already accepted, with an error that names the wrong
  cause.
- Smallest correction: return `null` from `desktopArtifactPayload()` (or reject in snapshot validation)
  when `agentArtifactPayload()` is `null` for the same platform, so the incomplete identity is caught
  before any side effect.

### D3 — The "one version/build" binding is unenforced outside the manifest generator

- Severity: blocking (missing proof for a named dangerous invariant)
- Evidence: `apps/gateway/app/Data/Operations/OperationUpdatePlanSnapshot.php:246` (`desktopArtifactMap`);
  `apps/gateway/app/Data/Operations/ReleaseManifest.php` (`desktopArtifacts`);
  `apps/gateway/app/Http/Requests/Api/UpdateAllStartApiRequest.php:32`
- Impact: both validators require `version` to be a non-empty string but never compare it to
  `targetVersion` / the manifest `version`. `bin/orbit-release-manifest` binds the version only at
  generation time, and `POST /api/update/all/start` accepts an arbitrary `desktop_artifacts` override, so
  a plan can persist `target_version=1.2.3` alongside `desktop.version=1.2.2`.
  `PendingDesktopUpdateHandoff::assertPayload()` records both values without comparing them, and
  `assertMatchesExpected()` only checks top-level `operation_id`/`version`/`build_id`. `.orbit/loop.md`
  names "desktop, Agent, and CLI artifacts bind one version/build" as a dangerous invariant; no test
  covers it.
- Smallest correction: assert `version === $this->targetVersion` in `assertDesktopArtifacts()` and
  `version === $version` in `ReleaseManifest::desktopArtifacts()`, with a rejecting test in
  `apps/gateway/tests/Unit/Data/Operations/ReleaseManifestDesktopArtifactTest.php`.

### D4 — No gateway-side proof of the declared success transition

- Severity: blocking
- Evidence: `rg 'desktop_artifact|pending_desktop_update' apps/gateway/tests` returns only the fake
  relay body at `apps/gateway/tests/Feature/Services/Operations/WorkloadNodeUpdaterTest.php:1899` and
  manifest-parsing tests; `.orbit/evidence/managed-client-unified-updates-retained-incus.txt` covers the
  offline skip and a standalone handoff write only.
- Impact: `.orbit/loop.md` declares `success:reachable managed client verifies the selected artifacts`.
  Nothing asserts that a reachable managed Mac's install payload actually contains `desktop_artifact` and
  `pending_desktop_update` with the derived `staged_path`, the operation id, and the plan version. The
  fake relay implements `desktopArtifactFor()` but no assertion consumes it, so the whole
  gateway-to-installer desktop handoff path is unproven.
- Smallest correction: one `WorkloadNodeUpdaterTest` case asserting the emitted install payload for a
  reachable managed Mac, including `staged_path` and the pending-update identity fields.

### D5 — CLI error-code rename left 15 command contracts stale and remediation partial

- Severity: blocking
- Evidence: `apps/cli/app/Commands/GatewayCommand.php:104`;
  `rg -l 'node\.agent_unreachable' apps/docs/content` returns 15 files;
  `apps/gateway/app/Services/Deploy/DeployManager.php:474`,
  `apps/gateway/app/Services/Tools/ProcessToolLifecycleRunner.php:54`,
  `apps/gateway/app/Services/Tools/ToolLogReader.php:55`
- Impact: the remap fires for **every** gateway failure carrying `node.agent_unreachable`, not just
  Agent-lifecycle paths, so `orbit_agent_unavailable` is now the public code for tool and deploy
  commands too. Six `6.2_*_output-render_json.md` CLI JSON contracts (`tool-start`, `tool-stop`,
  `tool-restart`, `tool-reload`, `tool-logs`, `deploy-run`) plus eight technical contracts and
  `domains/README.md` still name `node.agent_unreachable` as the stable CLI `error.code`; none were
  updated. Separately, only `ToolScriptDispatcher.php:109` (this diff) and
  `OperatorNodeManager.php:62` put `platform` in meta, so on the deploy, tool-lifecycle, and tool-logs
  paths a macOS operator gets `orbit_agent_unavailable` with **no** "Open Orbit Desktop" remediation,
  contradicting `apps/docs/content/domains/1_node/node-concepts.md:506`.
- Smallest correction: add `'platform' => (string) $node->platform` to those three meta payloads, and
  update the six CLI output-render docs (and the technical contracts naming the code) to the public
  `orbit_agent_unavailable` code.

## POLISH

- `apps/docs/content/domains/11_operation/2_update-all/technical/6.2_update-all_output-render_json.md:111`
  adds `updates[].reason`, but nothing emits `data.updates` — `UpdateRunnerPipeline::updateWorkloads()`
  discards the per-node results that carry `reason`. The field actually produced is
  `success.data.skipped_targets`. This extends pre-existing `updates[]` drift rather than introducing it.
- `FleetUpdatePreMutationSkipRegistry` is a singleton that is never pruned; entries accumulate per
  operation run in long-lived queue workers. Clear the operation's entry in `UpdateRunner::markSucceeded`
  and `markFailed`.
- `WorkloadNodeUpdater::preMutationSkip()` (`apps/gateway/app/Services/Operations/WorkloadNodeUpdater.php:217`)
  takes an unused `$operationRun`.
- `desktopArtifactPayload()` runs twice per macOS node — once from `installPayload()` and once from
  `pendingDesktopUpdatePayload()` (line 491) — each re-entering `artifactForKind()`/`stageArtifact()`.
  Compute once and pass it down.
- `LocalFleetUpdateInstallCliAction::stageDesktopArchive()` writes to `staged_path` before
  `PendingDesktopUpdateHandoff::assertSafeStagedPath()` validates it. Validate the path before writing.
- `AgentAvailabilityError::DesktopNotRunning` is never used; `WorkloadNodeUpdater.php:230,241,245`
  hardcodes the `'orbit_desktop_not_running'` literal.
- `AgentAvailabilityError::publicMessage()` `$meta` lacks an `array<string, mixed>` docblock while every
  sibling method has one.
- `runtime_config.prepare_prerequisites`
  (`apps/gateway/app/Services/Processes/ProcessRuntimeDrivers/DockerProcessRuntimeDriver.php:116`) is a
  production process-schema key with no `apps/docs/content/` coverage. Default behavior is unchanged.
- `apps/cli/tests/Feature/Services/Updates/UpdateAllHumanProgressRendererTest.php` asserts word presence
  only. The primitive is correct by construction — `STATE_SKIPPED` maps to the orange `●` row at
  `UpdateAllHumanProgressRenderer.php:1029` — but the test should assert the settled row including the
  marker.
- `UpdateRunner::markSucceeded()` emits `desktop_artifacts` unconditionally while `cli_artifacts` and
  `agent_artifacts` stay gated on `usesTopologyCandidateManifest()`; the field is also undocumented in
  the success shape.

## Verified as sound

- The pre-mutation skip precedes lease acquisition and any remote call: zero active leases and null
  `installed_cli` in both the focused test and the retained-Incus evidence.
- Only the pre-mutation path ever sets `reason`; `runRemoteUpdate()` results never carry one, so a
  started mutation cannot be relabeled `skipped`. The dedicated post-mutation-failure test confirms it.
- Caller de-duplication now works correctly: `->keyBy('id')->except(...)` replaces the previous
  positional `except()`, and the union with managed clients is deduplicated by `id`.
- `FleetVersionProbe` reads tracked state only, so a skipped Mac cannot fail the check phase.
- `FleetUpdateVerifier`, `FleetUpdateAgentVerifier`, and `FleetUpdateAgentRestartReadiness` all consult
  the skip registry, so a skipped target is not verified.
- The handoff write is atomic (temp file + `rename`), 0600, under a 0700 directory, with absolute-path,
  null-byte, `..`, basename, and directory-prefix guards on both the handoff and staged paths.
- The retained-Incus fixture repair (source staging away from the 0700 worktree, Valkey archive preload,
  `prepare_prerequisites` opt-out) is well scoped, explained in the linked handoffs, and consistent with
  the owned scope.

## Blast radius

`BLAST_RADIUS: gaps`.

The candidate changes a shared schema (`operation_update_plans.desktop_artifacts`, release-manifest
`desktop_artifacts`), a transport shape (install payload keys `desktop_artifact` and
`pending_desktop_update`), an ownership boundary (macOS Agent lifecycle), and shared vocabulary
(`orbit_agent_unavailable`, `orbit_desktop_not_running`). Bounded repository-wide checks run:

- `rg -l 'node\.agent_unreachable' apps/docs/content` → 15 files, 6 of them CLI JSON output contracts.
  Result: unresolved (D5).
- `rg -n 'node\.agent_unreachable|agentUnreachable' apps/gateway/app packages` → producers enumerated;
  three carry `node` without `platform`. Result: unresolved (D5).
- `rg -n "'agent_artifact'" apps packages` → install-payload consumers;
  `FleetUpdateInstallResultInspector` was updated for the new keys. Result: resolved.
- `rg -n 'new OperationUpdatePlanSnapshot\(' apps packages` → two call sites, both named-argument;
  optional parameters appended last, so no positional breakage. Result: resolved.
- `rg -n 'orbit_desktop_not_running|orbit_agent_unavailable' apps packages bin` → no conflicting
  consumer semantics. Result: resolved.
- `rg -n 'prepare_prerequisites' apps/docs/content apps/gateway/app apps/e2e` → production key with no
  product documentation. Result: unresolved (POLISH).

## Required final lines

BLAST_RADIUS: gaps
HUMAN_JUDGMENT: not-required
VERDICT: FIX
