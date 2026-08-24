candidate=034cfb84fe1e644ae8254fdb3b7dd2b47d672fe6

# Independent general review: all-node `update:all`

CHECKOUT_PROOF: cwd=/home/nckrtl/orbit/.worktrees/codex-fix-macos-desktop-update-selection; branch=codex/fix-macos-desktop-update-selection; head=034cfb84fe1e644ae8254fdb3b7dd2b47d672fe6; main=9d9d91c093136c33de09085ff2d4fffcc45cea44; status=clean

Read-only review. No code, docs, tests, harness state, evidence, or
`.orbit/loop.md` were edited. No focused suite, `composer quality-check`, or
E2E lane was rerun.

## Required proof

```text
pwd                     /home/nckrtl/orbit/.worktrees/codex-fix-macos-desktop-update-selection
git branch --show-current   codex/fix-macos-desktop-update-selection
git status --short --branch ## codex/fix-macos-desktop-update-selection...origin/main [ahead 4]
git rev-parse HEAD      034cfb84fe1e644ae8254fdb3b7dd2b47d672fe6
git rev-parse main      9d9d91c093136c33de09085ff2d4fffcc45cea44
```

Assignment identity matches: worktree, branch, candidate, base, and clean tree.

## Mandatory review questions

1. **Selection covers every active supported non-gateway node, independent of
   roles and `managed`, with caller dedup.** Yes.
   `FleetUpdateTargetSelector::workloadNodesExcluding`
   (`apps/gateway/app/Services/Operations/FleetUpdateTargetSelector.php:33-66`)
   queries active nodes, excludes gateway-role ids at the query level, filters
   on `Node::isFleetUpdateEligible()` (active + supported Agent platform +
   valid WireGuard address), then `unique('id')->keyBy('id')->except($callerNodeId)`.
   Roles and `managed` no longer participate.
   `FleetUpdateTargetSelectorTest` asserts
   `['eligible-app','managed-operator','unmanaged-operator','vpn-node']`.
2. **Pre-mutation readiness failures are skips on both platforms with stable
   reason codes, and post-mutation failures stay failures.** Yes in
   `WorkloadNodeUpdater`. `preMutationSkip`
   (`apps/gateway/app/Services/Operations/WorkloadNodeUpdater.php:218-231`) runs
   before the lease and before any install, and selects
   `orbit_desktop_not_running` on macOS/Darwin and `orbit_agent_not_running`
   elsewhere. `runRemoteUpdate` still returns `failed` for any error after the
   first side effect, and `recordSkippedResult` only registers the two
   pre-mutation reasons in `FleetUpdatePreMutationSkipRegistry`.
3. **Every reachable Linux node receives CLI and Agent, every reachable Mac
   receives Desktop, Agent, and CLI.** Payload construction is correct
   (`shouldInstallAgentArtifact` → `isFleetUpdateEligible`;
   `desktopArtifactPayload` gated on platform plus a present Agent artifact
   rather than on `managed`). The **drift gate that decides whether a node is
   touched at all was not widened** — see DEFECT 1.
4. **Verification uses the same target set and honors the skip registry.**
   `FleetUpdateVerifier::verifyWorkloadCli`, `verifyRequiredRoleImages`, and
   `FleetUpdateAgentVerifier::verify` all iterate
   `FleetUpdateTargetSelector::workloadNodes()` and skip registry entries.
   `FleetUpdateVerifierTest` now proves `operator-1` (10.44.0.15) is verified.
   The **restart-readiness barrier inside verification was not widened** — see
   DEFECT 2.
5. **Widened Agent-push and local-executor authorization limited to the exact
   internal fleet-update commands, with no generic escalation.** Yes.
   `LocalExecutorCommandBuilder::ensureCommandIsAllowedForTarget`
   (`apps/gateway/app/Services/RemoteShell/LocalExecutorCommandBuilder.php:570-583`)
   bypasses the role check only for `InternalCommand::FleetUpdateInstallCli`
   and `InternalCommand::FleetUpdateVerify`, and only when `$targetNode->isOperator()`
   (no active role assignment). `NodeCommandTransportSelector::canUseAgentPush`
   additionally requires `supportsAgentPushTransport` and an
   `internal:fleet-update:` argv[0]; `agentPushBinary` always sets
   `commandId = 'orbit.agent.binary'`, so the `commandId` branch is inert for
   the agent-push path, and `buildArgv` validates the command name against the
   allowlist before argv reaches the selector. No other command gains
   transport or authorization on a roleless unmanaged operator.
6. **Docs, product decision, JSON output, and human output aligned.** Yes.
   The dated `PRODUCT_DECISIONS.md` entry, `1_update-all.md` Fleet Selection
   Rules and target table, `6.1_update-all_output-render_human.md` sub-stage
   vocabulary, `6.2_update-all_output-render_json.md` `updates[].reason` and
   `success.data.skipped_targets`, `update-all.md`, `node-concepts.md`, and
   `tech-stack.md` all carry both platform reasons consistently.
   `UpdateAllHumanProgressRenderer` adds `STAGE_SKIPPED_AGENT` with settle text
   `Skipped: Orbit Agent is not running`, matching the documented frame, and the
   renderer test asserts the rendered row rather than word presence.
7. **Runtime evidence sufficient for the claimed final outcome.** No — see
   DEFECT 3.

## Blast radius

`BLAST_RADIUS: complete`. This change moves a product decision, a selection
predicate, a transport boundary, and a shared skip vocabulary, so a bounded
repository-wide inventory was required before classification.

Evidence and result:

- `rg -n "activeNonGatewayRoleNodes|workloadNodesExcluding|workloadNodes\("`
  → 6 gateway consumers of the widened set (`WorkloadNodeUpdater`,
  `FleetVersionProbe`, `FleetUpdateVerifier` x2, `FleetUpdateAgentVerifier`,
  `FleetUpdateAgentRestartReadiness`). `MetricsRoleBaseline` (3 sites) uses the
  untouched role-based `activeNonGatewayRoleNodes()`, so metrics scrape-target
  ownership is unaffected. Resolved.
- `rg -n "isAgentEligible\(\)|isFleetUpdateEligible\(\)"` → 16 non-test sites.
  Two inside the fleet-update path still use the old Agent-intent predicate and
  are now inconsistent with selection: `FleetAgentArtifactProbe:19` and
  `FleetUpdateAgentRestartReadiness:39` (DEFECT 1 and DEFECT 2). A third,
  `WorkloadNodeUpdater:455` (`agentServicePayload`), is a partial mismatch
  (POLISH 1). The remaining sites — `SecurityInstallerTransport`,
  `AppSetupStepLocalExecutor`, `WorkspaceSetupStepLocalExecutor`,
  `SoloUpstreamTargetResolver`, `NodesProbe`, `NodeAgentConfigRenderer`,
  `NodeAgentServicePayloadBuilder`, `NodeUpdateController` — correctly keep
  Agent-intent semantics and must not change. Resolved.
- `rg -n "orbit_desktop_not_running|orbit_agent_not_running|DesktopNotRunning|AgentNotRunning"`
  → 4 code sites, 6 doc sites, plus tests. No consumer is left on the
  desktop-only reason, and `AgentAvailabilityError` publishes the new constant
  next to the existing one. Resolved.

Every affected surface was inspected and resolved to a conclusion, so this is
`complete` rather than `gaps`; the two inconsistent consumers are reported as
blocking defects below.

## Findings

### DEFECT 1 — newly selected nodes with a current CLI never receive the Agent (or Desktop)

- Severity: DEFECT (blocking)
- File: `apps/gateway/app/Services/Operations/FleetAgentArtifactProbe.php:19`
- Impact: `FleetAgentArtifactProbe::nodeNeedsUpdate()` returns `false` for any
  node that is not `isAgentEligible()`. `WorkloadNodeUpdater::runRemoteUpdate`
  short-circuits on `FleetVersionProbe::nodeNeedsUpdate` before building any
  payload. For a roleless `managed=false` node whose `installed_cli` already
  matches the target: `nodeNeedsCliUpdate` is false, the agent probe returns
  false on the `isAgentEligible()` short-circuit, and
  `candidateRuntimeNeedsUpdate` is false with no app role. The node reports
  `status=skipped` / "already up to date" and the Agent artifact is never
  installed. On a Mac in the same state, Desktop staging and the pending
  handoff never happen either. This is the realistic migration case — an
  operator workstation already on the target version through `orbit update` —
  and it directly contradicts the Goal ("updates each reachable Linux node with
  CLI and Agent and each reachable Mac with Desktop, Agent, and CLI").
  `FleetVersionReport.outdatedCount` under-reports the same nodes, so the check
  phase also understates the fleet. Existing coverage misses this because every
  new-class test in `WorkloadNodeUpdaterTest` builds a factory node with
  `installed_cli` null, which forces `nodeNeedsCliUpdate` true.
- Smallest correction: gate `FleetAgentArtifactProbe::nodeNeedsUpdate` on
  `$node->isFleetUpdateEligible()` — the same predicate
  `WorkloadNodeUpdater::shouldInstallAgentArtifact` uses — and add a
  `WorkloadNodeUpdaterTest` case with `installed_cli` matching the plan and
  `installed_agent` null on a roleless `managed=false` node.

### DEFECT 2 — Agent restart is never waited for on newly selected nodes, so verification can spuriously fail the whole fleet

- Severity: DEFECT (blocking)
- File: `apps/gateway/app/Services/Operations/FleetUpdateAgentRestartReadiness.php:39`
- Impact: `wait()` still filters `! $node->isAgentEligible()`, so the exact node
  class this feature adds is skipped by the readiness barrier. Those nodes do
  receive a replaced Agent binary, and the installer schedules
  `systemctl restart orbit-agent` through
  `systemd-run --unit=... --on-active=5s` in
  `LocalFleetUpdateInstallCliAction::installScript()`
  (`restart_agent_service_if_present`). `FleetUpdateVerifier::verifyWorkloadCli`
  then dispatches `internal:fleet-update:verify cli` to them and
  `FleetUpdateAgentVerifier::verify` dispatches `internal:fleet-update:verify
  agent`, both over agent push — the only transport
  `NodeCommandTransportSelector` will return for a non-gateway node — and
  `RemoteLocalExecutor` performs no connection retry. The only spacing is the
  global 6000 ms `orbit.updates.agent_restart_settle_milliseconds` sleep, so if
  a newly selected node is late in the fan-out its scheduled restart lands
  inside the verification window, the agent-push connection fails, and the run
  ends in `cli_verification_failed` or `agent_verification_failed` — a terminal
  failure of an otherwise healthy fleet update. macOS is unaffected because
  `ORBIT_DEFER_AGENT_RESTART_TO_DESKTOP=1` defers the restart to Orbit Desktop.
- Smallest correction: use the same predicate as
  `WorkloadNodeUpdater::shouldInstallAgentArtifact` in `wait()`, i.e. replace
  `! $node->isAgentEligible()` with `! $node->isFleetUpdateEligible()`.

### DEFECT 3 — runtime proof does not exercise the claimed final outcome for the new node class

- Severity: DEFECT (blocking; missing proof for a named dangerous invariant)
- Evidence refs: `.orbit/evidence/retained-dev-b77c00/runtime-proof.md`;
  `.orbit/loop.md` `Proof.Verification.runtime`;
  `.orbit/workers/handoff/impl-3-034cfb84fe1e644ae8254fdb3b7dd2b47d672fe6.md`
- Impact: every node that **completed** in the retained proof (`app-dev-1`,
  `app-prod-1`) is role-bearing and was therefore already `isAgentEligible()`
  before this change — the pre-existing path. All three records that this change
  newly covers (`agent-1`, `offline-linux`, `offline-mac`) skipped before
  mutation. No reachable roleless `managed=false` node ever completed an
  install, so the transport escalation in `NodeCommandTransportSelector`, the
  operator bypass in `LocalExecutorCommandBuilder`, the null `agent_service`
  payload path, and the entire post-install verification path for the new class
  have zero runtime evidence — which is also why DEFECT 2 survived the proof.
  Separately, `.orbit/loop.md` Scope names "platform-correct Desktop payloads"
  as a dangerous invariant, and no reachable Mac staged a Desktop archive or
  wrote `pending-desktop-update.json` at runtime; `offline-mac` only proved the
  skip reason. Recording `Verification.runtime: passed` overstates what the
  candidate-bound receipt actually exercised, and the receipt's own `expected=`
  clause narrows the claim to Linux while the Goal claims both platforms.
- Smallest correction: after the code fixes, run the exact candidate against at
  least one reachable roleless `managed=false` Linux node through install **and**
  verification, and one reachable macOS node through Desktop staging plus the
  written `pending-desktop-update.json`; then re-record the candidate-bound
  structured runtime receipt on the existing `Verification.runtime` row with
  that observation. Both outcomes are deterministic and agent-inspectable.

### POLISH 1 — Agent binary and Agent service payloads use different predicates

- Severity: POLISH
- File: `apps/gateway/app/Services/Operations/WorkloadNodeUpdater.php:455`
- Impact: `agentServicePayload()` still gates on `isAgentEligible()` while
  `agentArtifactPayload()` gates on `isFleetUpdateEligible()`. A newly selected
  Linux node therefore receives a new Agent binary with an empty
  `agent_service`, so `install_agent_config` and
  `converge_agent_systemd_service` both no-op on empty environment values. The
  node still restarts through the existing unit, so nothing is broken today,
  but config and unit convergence silently stop applying to exactly the nodes
  this feature added. Two halves of one artifact should share one predicate.
- Smallest correction: pick one predicate for both halves, or state in
  `1_update-all.md` that newly selected roleless nodes intentionally keep their
  existing Agent unit and config unchanged.

### POLISH 2 — unreachable defensive branch and over-broad Mago suppression

- Severity: POLISH
- File: `apps/gateway/app/Services/Operations/FleetUpdateTargetSelector.php:31,50`
- Impact: `! $node instanceof Node` cannot be true when iterating an
  `Illuminate\Database\Eloquent\Collection<int, Node>`, and the
  `@mago-expect analysis:redundant-condition` annotation is applied to the whole
  method rather than to the one expression, so it will also mask a future real
  redundant condition in `workloadNodesExcluding`.
- Smallest correction: drop the branch and the method-level suppression.

### POLISH 3 — gateway node inserted then unconditionally filtered

- Severity: POLISH
- File: `apps/gateway/app/Services/Operations/FleetUpdateAgentVerifier.php:26,68-71`
- Impact: `nodes()` still inserts `targets->gatewayNode()` into the map, which
  the new `hasActiveRole('gateway')` guard at line 26 then always removes. The
  behavior is unchanged (the gateway was already excluded before, because
  `hasAgentIntent()` is false for a gateway), but the dead insertion makes the
  gateway exclusion look conditional when it is absolute.
- Smallest correction: remove either the gateway insertion in `nodes()` or the
  role guard in `verify()`, keeping one explicit exclusion.

### POLISH 4 — transport prefix match is broader than the authorized command pair

- Severity: POLISH
- File: `apps/gateway/app/Services/NodeCommandTransport/NodeCommandTransportSelector.php:45-55`
- Impact: the transport escalation matches any `internal:fleet-update:` prefix
  while the authorization escalation enumerates exactly
  `FleetUpdateInstallCli` and `FleetUpdateVerify`. Today `InternalCommand` holds
  only those two, so the two boundaries agree and there is no live escalation,
  but a future third `internal:fleet-update:*` command would silently inherit
  agent-push transport on roleless unmanaged operators while the authorization
  list stayed closed.
- Smallest correction: match on the same two `InternalCommand` cases in
  `isFleetUpdateCommand()` so both boundaries move together.

## Human judgment

`HUMAN_JUDGMENT: not-required`. The remaining acceptance actions are
deterministic commands an agent can run and inspect: re-run the exact candidate
against a reachable roleless `managed=false` Linux node and a reachable macOS
node, then read the recorded installed identities, the staged Desktop archive,
and `pending-desktop-update.json`. Native Orbit Desktop restart consumption is
explicitly out of this slice.

## Required final lines

```text
BLAST_RADIUS: complete
HUMAN_JUDGMENT: not-required
VERDICT: FIX
```
