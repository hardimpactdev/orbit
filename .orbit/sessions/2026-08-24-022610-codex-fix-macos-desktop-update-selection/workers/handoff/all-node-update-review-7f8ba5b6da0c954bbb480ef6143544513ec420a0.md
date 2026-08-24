candidate=7f8ba5b6da0c954bbb480ef6143544513ec420a0

# Delta review: all-node `update:all` (round 2)

CHECKOUT_PROOF: cwd=/home/nckrtl/orbit/.worktrees/codex-fix-macos-desktop-update-selection; branch=codex/fix-macos-desktop-update-selection; head=7f8ba5b6da0c954bbb480ef6143544513ec420a0; main=9d9d91c093136c33de09085ff2d4fffcc45cea44; status=clean

Read-only delta review of `034cfb84fe1e644ae8254fdb3b7dd2b47d672fe6..7f8ba5b6da0c954bbb480ef6143544513ec420a0`
(1 commit, `7f8ba5b6d Install Agent artifacts for every fleet-update node`,
17 files, +411/-42). No source, docs, tests, harness state, evidence, or
`.orbit/loop.md` were edited. No focused suite, `composer quality-check`, or
E2E lane was run. One narrow read-only reproduction was run for the single new
disputed branch (recorded under DEFECT 4).

The delta stays inside owned scope — fleet-update selection/artifact
predicates, the `update:all` command description, and update/version doc
wording — so this is a delta review, not a fresh review.

## Receipt and gate confirmation

- `bin/orbit-feature-proof-receipt --json` → `ok=true`, `problem=null`,
  `candidate=7f8ba5b6da0c954bbb480ef6143544513ec420a0`, `dirty=false`,
  `docs_only=false`, `gate=quality-check`, `venue=retained-incus`.
- `bin/orbit-feature-acceptance route` → `base=main`,
  `base_tip=9d9d91c093136c33de09085ff2d4fffcc45cea44`,
  `merge_base=9d9d91c093136c33de09085ff2d4fffcc45cea44`, 33 changed files.
- Quality artifact
  `.orbit/quality-gates/quality-check-2026-08-23T235032Z-753da568321f.json`:
  `exit_code=0`, `git.commit=7f8ba5b6da0c954bbb480ef6143544513ec420a0`,
  `git.dirty=false`, `duration_seconds=137`, 46 subgates, **0 failing**.
- The candidate-bound structured runtime string sits on the existing
  `Verification.runtime` row and names this candidate.

Receipt and gate are confirmed and candidate-bound.

## Prior findings — closure against the delta

| Prior finding | Status | Evidence in the delta |
| --- | --- | --- |
| DEFECT 1 — `FleetAgentArtifactProbe` short-circuit hid Agent drift on newly selected nodes | **Closed (code + tests + runtime), with a new regression — see DEFECT 4** | `FleetAgentArtifactProbe.php:19` now `! $node->isFleetUpdateEligible()`. New Pest coverage: `FleetVersionProbeTest` "counts a roleless unmanaged node as outdated when CLI matches and Agent is absent" (linux + macos dataset); `WorkloadNodeUpdaterTest` "installs the missing Agent when a roleless unmanaged Linux node CLI already matches" and "stages Desktop and Agent when a roleless unmanaged Mac CLI already matches". Runtime: `app-dev-1` prepared as exactly this case (current CLI `0.1.196`, no Agent identity), `nodeNeedsUpdate()` returned `true`, completed, recorded Agent SHA `bbf91b61…`, then returned `false`. |
| DEFECT 2 — restart-readiness barrier skipped the new node class, racing verification | **Closed** | `FleetUpdateAgentRestartReadiness.php:39` now `! $node->isFleetUpdateEligible()`. New `FleetUpdateAgentRestartReadinessTest` asserts the probe is actually sent to `http://10.6.0.96:9477/v1/commands` for a roleless unmanaged node whose `isAgentEligible()` is false. Runtime: the barrier and `FleetUpdateAgentVerifier` both passed for `app-dev-1`. |
| DEFECT 3 — no runtime evidence for a reachable new-class node or reachable-Mac Desktop staging | **Closed** | `.orbit/evidence/retained-dev-b77c00/runtime-proof.md` now carries two proofs. Linux: reachable roleless unmanaged `app-dev-1` completed install and verification. macOS: native NMBP (`macOS 27.0`, ARM64) installed and verified CLI `0ba3090a…`, Agent `ceb49b22…`, staged Desktop archive `ed8ee953…`, wrote a `0600` handoff, reported `desktop_staged=true` / `agent_installed=true`, deferred the Agent restart, and left the legacy Agent PID `73638` unchanged. Source identity was pinned by per-file SHA on both hosts. |
| POLISH 1 — Agent binary and Agent service used different predicates | **Closed** | `WorkloadNodeUpdater::agentServicePayload` now uses `shouldInstallAgentArtifact()` and calls the new `NodeAgentServicePayloadBuilder::forFleetUpdateNode()` → `NodeAgentConfigRenderer::renderForFleetUpdate()`, which fails closed on non-eligible nodes and inherits `renderConfig`'s gateway guard. `NodeAgentServicePayloadBuilderTest` and `NodeAgentConfigRendererTest` pin both paths; `WorkloadNodeUpdaterTest` now asserts a real `agent_service` payload where it previously asserted `null`. |
| POLISH 2 — unreachable `instanceof` branch and method-wide Mago suppression | **Closed** | `FleetUpdateTargetSelector::workloadNodesExcluding` is now a single `->get()->filter(...)->unique()->keyBy()->except()->sortBy()->values()` chain; the dead branch is gone and the suppression narrowed to `analysis:invalid-argument`. |
| POLISH 3 — gateway node inserted then always filtered in the Agent verifier | **Closed** | `FleetUpdateAgentVerifier::nodes()` no longer inserts `gatewayNode()`, and the `hasActiveRole('gateway')` guard in `verify()` is removed. Behavior is unchanged because `workloadNodes()` excludes gateways at the query level. |
| POLISH 4 — transport prefix match broader than the authorized pair | **Closed** | `NodeCommandTransportSelector::isFleetUpdateCommand` now matches exactly `InternalCommand::FleetUpdateInstallCli` and `InternalCommand::FleetUpdateVerify` on both the `commandId` and `argv[0]` branches. `NodeCommandTransportSelectorTest` became a dataset over both commands. |

All seven prior findings are closed. The delta additionally aligned
`UpdateAllCommand::$description`, `update.md`, and `version.md` with the
decision wording — in scope and consistent.

## New finding

### DEFECT 4 — the DEFECT 1 correction makes `update:all` permanently non-idempotent

- Severity: DEFECT (blocking; regression introduced by this delta)
- File: `apps/gateway/app/Services/Operations/FleetAgentArtifactProbe.php:19`,
  reached from `apps/gateway/app/Services/Operations/FleetVersionProbe.php:119`
- Impact: `Node::isFleetUpdateEligible()` deliberately omits the gateway-role
  exclusion, because `FleetUpdateTargetSelector` removes gateways at the query
  level. `FleetVersionProbe::gatewayNeedsUpdate()` is the one caller that hands
  a **gateway** node straight to `FleetAgentArtifactProbe::nodeNeedsUpdate()`,
  bypassing that exclusion. A real gateway node is active, `ubuntu_24-04`, and
  has a valid WireGuard address, so `isFleetUpdateEligible()` is now true for
  it, and the probe proceeds instead of returning early. The gateway's
  `installed_agent` is null **by design** — `GatewayServiceUpdater::recordInstalledCli`
  (`GatewayServiceUpdater.php:233`) force-nulls it after every gateway update,
  `NodesProbe:162,1030` actively repairs any non-null value as
  `node.agent_expectation_stale`, and `WorkloadNodeUpdater::recordInstalledAgent`
  never runs for a gateway. So `nodeNeedsUpdate($gatewayNode, $plan)` returns
  `true` for every plan that carries a `linux-amd64` Agent artifact — that is,
  every real release plan.

  Consequences on a fully current fleet:
  - `FleetVersionProbe::gatewayNeedsUpdate()` is unconditionally true, so
    `FleetVersionReport::outdatedCount >= 1` and `allCurrent()` is never true.
  - `UpdateRunner::run` (`UpdateRunner.php:106-112`) therefore always calls
    `runUpdatePhases`: artifact staging, the full gateway phase
    (`forceUpdateServiceImage` + migrations), workload fan-out, and full
    verification — on every `update:all`, even when nothing changed.
  - `markSucceeded($operationRun, $plan, false)` means
    `success.status = 'succeeded'` and the `skipped: true` result flag
    (`UpdateRunner.php:313-328`) become unreachable.
  - `check-fleet-versions` can never render
    `Done: all nodes running on <latest_version>`, which
    `apps/docs/content/domains/11_operation/2_update-all/technical/6.1_update-all_output-render_human.md:41,58`
    documents as the current-fleet outcome. It permanently renders
    `Done: 1 outdated node found` on an otherwise idle fleet.

- Why the gates missed it: every gateway fixture in the affected suites pins
  `'platform' => 'debian_12'` — `FleetVersionProbeTest.php:25,71,90,111,189,225,266,349,398,436`
  and `UpdateRunnerCheckStepsTest.php:55,100,132,171` — and `debian` is not in
  `Node::AGENT_PLATFORM_PREFIXES` (`ubuntu`, `macos`, `darwin`), so the fixture
  gateway is not fleet-update eligible and the branch is never taken. The two
  all-current assertions (`FleetVersionProbeTest.php:134,465`) pass only because
  of that fixture platform. Production gateways are `ubuntu_24-04`
  (`WorkloadNodeCreationResolver` defaults and restricts to Ubuntu;
  `apps/docs/content/domains/2_gateway/1_gateway-add/technical/6.2_gateway-add_output-render_json.md:36,58,97`).
  The runtime proof cannot catch it either: that topology has no scheduler Swarm
  service, so the public gateway phase never runs, and no proof exercises an
  already-current fleet.

- Narrow reproduction (read-only, no writes, single disputed branch):

  ```text
  php apps/gateway/artisan tinker --execute '
    foreach (["ubuntu_24-04", "debian_12", "macos_15-5"] as $p) { ... isFleetUpdateEligible() ... }'

  ubuntu_24-04   isFleetUpdateEligible=true     <- real gateway platform
  debian_12      isFleetUpdateEligible=false    <- test-fixture gateway platform
  macos_15-5     isFleetUpdateEligible=true
  ```

- Smallest correction: add the gateway exclusion to the predicate itself —
  `Node::isFleetUpdateEligible()` returns false when `hasActiveRole('gateway')`.
  That is safe for every other consumer: `FleetUpdateTargetSelector` and
  `FleetUpdateAgentRestartReadiness` already operate on gateway-free sets,
  `NodeCommandTransportSelector::select` short-circuits gateways to
  `GatewayOnly` before reaching `canUseAgentPush`, and
  `NodeAgentConfigRenderer::renderConfig` already throws on gateways. Guarding
  `FleetVersionProbe.php:119` instead is equivalent but leaves the predicate a
  trap for the next caller. Add one `FleetVersionProbeTest` case with an
  `ubuntu_24-04` gateway, matching gateway image and CLI, and a plan carrying
  `agent_artifacts['linux-amd64']`, asserting `outdatedCount === 0`.

## Non-blocking note

- The native NMBP proof wrote `install_mode = restart-ready`, while
  `WorkloadNodeUpdater::pendingDesktopUpdatePayload` (`WorkloadNodeUpdater.php:530`)
  hardcodes `'automatic'`. `PendingDesktopUpdateHandoff` accepts both
  (`InstallModeRestartReady`, `InstallModeAutomatic`), and the gateway-produced
  `install_mode => 'automatic'` is pinned by the new
  `WorkloadNodeUpdaterTest` Mac case, so nothing is broken. It only means the
  native proof exercised the CLI-side staging with its own payload rather than
  the gateway-produced one. Worth a sentence in the evidence file so a later
  reader does not read `restart-ready` as the gateway contract. Not blocking.

## Blast radius

`BLAST_RADIUS: complete`. The delta moves the same predicate, transport, and
shared-vocabulary surfaces, so the bounded inventory was re-run on this
candidate.

Evidence and result:

- `rg -n "isFleetUpdateEligible\(\)" --glob '!**/tests/**' --glob '!**/generated/**'`
  → 7 sites (1 definition, 6 consumers): `NodeCommandTransportSelector:42`,
  `FleetUpdateAgentRestartReadiness:39`, `NodeAgentConfigRenderer:28`,
  `FleetUpdateTargetSelector:47`, `WorkloadNodeUpdater:470`,
  `FleetAgentArtifactProbe:19`. Five receive only gateway-free node sets or are
  independently guarded; `FleetAgentArtifactProbe` is the one that can receive a
  gateway node. Resolved as DEFECT 4.
- `rg -n "agentArtifacts->nodeNeedsUpdate|FleetAgentArtifactProbe"` → the single
  gateway-carrying call site is `FleetVersionProbe.php:119`
  (`gatewayNeedsUpdate`); `FleetVersionProbe.php:127` (`nodeNeedsUpdate`) only
  receives `workloadNodes()`. Resolved as DEFECT 4.
- `rg -n "isAgentEligible\(\)"` on this candidate → no remaining stale
  Agent-intent predicate inside the fleet-update path; the surviving sites
  (`SecurityInstallerTransport`, `AppSetupStepLocalExecutor`,
  `WorkspaceSetupStepLocalExecutor`, `SoloUpstreamTargetResolver`, `NodesProbe`,
  `NodeAgentConfigRenderer::render`, `NodeAgentServicePayloadBuilder::forNode`,
  `NodeUpdateController`, `NodeCommandTransportSelector:38`) correctly keep
  Agent-intent semantics. Resolved.
- Skip vocabulary (`orbit_desktop_not_running` / `orbit_agent_not_running`) is
  unchanged by this delta and stays aligned across code, docs, and the decision
  entry. Resolved.

Every affected surface was inspected and resolved to a conclusion, so this is
`complete` rather than `gaps`; the one unresolved behavior is reported as
DEFECT 4.

## Human judgment

`HUMAN_JUDGMENT: not-required`. The remaining verification is a deterministic
command an agent can run and inspect: after the DEFECT 4 fix, probe
`FleetVersionProbe::gatewayNeedsUpdate()` (or run `update:all` twice) against a
current fleet whose gateway is `ubuntu_24-04`, and confirm
`check-fleet-versions` reports `Done: all nodes running on <version>` with
`success.status = 'skipped'`. The macOS Desktop half is already covered by the
native NMBP proof.

## Required final lines

```text
BLAST_RADIUS: complete
HUMAN_JUDGMENT: not-required
VERDICT: FIX
```
