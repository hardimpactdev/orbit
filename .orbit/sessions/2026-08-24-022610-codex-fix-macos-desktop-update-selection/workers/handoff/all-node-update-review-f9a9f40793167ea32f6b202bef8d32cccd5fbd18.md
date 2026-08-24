candidate=f9a9f40793167ea32f6b202bef8d32cccd5fbd18

# Delta review: all-node `update:all` (round 3, final)

CHECKOUT_PROOF: cwd=/home/nckrtl/orbit/.worktrees/codex-fix-macos-desktop-update-selection; branch=codex/fix-macos-desktop-update-selection; head=f9a9f40793167ea32f6b202bef8d32cccd5fbd18; main=9d9d91c093136c33de09085ff2d4fffcc45cea44; status=clean

Read-only delta review of
`7f8ba5b6da0c954bbb480ef6143544513ec420a0..f9a9f40793167ea32f6b202bef8d32cccd5fbd18`
(1 commit, `f9a9f4079 Exclude gateway nodes from fleet-update eligibility`,
3 files, +57/-0). No source, docs, tests, harness state, evidence, or
`.orbit/loop.md` were edited. No focused suite, `composer quality-check`, or E2E
lane was run. One narrow read-only reproduction was run against the corrected
predicate, recorded below.

The delta is confined to the single predicate named in DEFECT 4 plus its two
regression tests, so it stays well inside owned scope and this remains a delta
review.

## Delta contents

- `apps/gateway/app/Models/Node.php:249-252` — `isFleetUpdateEligible()` now
  returns `false` first when `hasActiveRole('gateway')`, before the
  active/platform/WireGuard check.
- `apps/gateway/tests/Feature/Services/Operations/FleetVersionProbeTest.php` —
  new "reports all current for an Ubuntu gateway when Agent artifacts exist":
  persists a `ubuntu_24-04` gateway with matching `installed_gateway_image` and
  `installed_cli`, a plan carrying `agent_artifacts['linux-amd64']`, and asserts
  `isFleetUpdateEligible() === false`, `outdatedCount === 0`,
  `allCurrent() === true`.
- `apps/gateway/tests/Unit/Services/Nodes/NodeAgentEligibilityTest.php` — new
  "excludes gateway-role nodes from fleet-update eligibility": pins the
  gateway/workload split on the predicate itself, both on `ubuntu_24-04`.

This is exactly the smallest correction named in the prior finding, applied at
the predicate rather than at the one call site, so the trap does not survive for
the next caller.

## DEFECT 4 — closed

- Prior finding: `Node::isFleetUpdateEligible()` had no gateway exclusion, so
  `FleetVersionProbe::gatewayNeedsUpdate()` (`FleetVersionProbe.php:119`) fed a
  real `ubuntu_24-04` gateway into `FleetAgentArtifactProbe::nodeNeedsUpdate()`.
  The gateway's `installed_agent` is null by design, so the probe returned
  `true` for every plan with a `linux-amd64` Agent artifact:
  `outdatedCount` was never `0`, `UpdateRunner` always ran the full gateway
  phase on an idle fleet, and the documented
  `Done: all nodes running on <latest_version>` / `success.status = 'skipped'`
  outcome was unreachable.
- Correction verified at the predicate. Narrow read-only reproduction on this
  candidate (in-memory models with the `roleAssignments` relation set; no writes):

  ```text
  ubuntu GATEWAY     isFleetUpdateEligible=false   (was true on 7f8ba5b6d)
  ubuntu roleless    isFleetUpdateEligible=true    (selection preserved)
  macos roleless     isFleetUpdateEligible=true    (Desktop path preserved)
  macos GATEWAY      isFleetUpdateEligible=false
  ```

  The regression case flips to `false` while both node classes this feature
  exists to add stay `true`.
- Consumer safety re-checked for all six consumers of the widened predicate:
  - `FleetUpdateTargetSelector:47` — gateways were already removed by
    `whereNotIn('id', $gatewayIds)`; now doubly excluded, no behavior change.
  - `FleetUpdateAgentRestartReadiness:39` and `WorkloadNodeUpdater:470` —
    only ever receive `workloadNodes()`, which is gateway-free. No change.
  - `FleetAgentArtifactProbe:19` — the fix target. Gateways short-circuit to
    `false`, restoring pre-regression `gatewayNeedsUpdate()`; workload nodes are
    unaffected because they never hold a gateway role.
  - `NodeAgentConfigRenderer:28` (`renderForFleetUpdate`) — a gateway now fails
    at the eligibility guard instead of `renderConfig`'s
    "Gateway nodes are never Orbit Agent targets" throw. Both fail closed, and
    the only caller path (`WorkloadNodeUpdater::agentServicePayload`) never sees
    a gateway.
  - `NodeCommandTransportSelector:42` — `select()` already returns
    `GatewayOnly` for gateway-role nodes before `canUseAgentPush` runs, so there
    is no reachable change; the predicate is now defense in depth.
- No N+1 introduced. The new `hasActiveRole('gateway')` call resolves in memory
  everywhere it matters: `FleetUpdateTargetSelector`, `workloadNodes()`
  consumers, and `targets->gatewayNode()` all eager-load `roleAssignments`, and
  `NodeCommandTransportSelector::select()` already issued the identical
  gateway-role check before reaching line 42.
- Test coverage now pins the previously blind fixture surface: the two new tests
  use `ubuntu_24-04` gateways, so the `debian_12` fixture platform that hid the
  regression can no longer mask it.

DEFECT 4 is closed. All prior findings from rounds 1 and 2 (DEFECT 1, 2, 3 and
POLISH 1–4) were closed in `7f8ba5b6d` and are unaffected by this delta; the
predicate change does not reopen any of them, as verified by the consumer
re-check above.

## Round-2 non-blocking note — addressed

The `install_mode` discrepancy is now explained in the evidence file: "The
native proof deliberately supplied `restart-ready` to exercise the selected
restart-to-update UX. Gateway-produced fleet handoffs remain `automatic`; the
candidate tests cover that separate production payload contract." That matches
`WorkloadNodeUpdater.php:530` and the `WorkloadNodeUpdaterTest` Mac case. No
finding remains.

## Receipt, gate, and evidence confirmation

- `bin/orbit-feature-proof-receipt --json` → `ok=true`, `problem=null`,
  `candidate=f9a9f40793167ea32f6b202bef8d32cccd5fbd18`, `dirty=false`,
  `docs_only=false`, `gate=quality-check`, `venue=retained-incus`, and a
  candidate-bound structured `runtime` string on the existing
  `Verification.runtime` row.
- `bin/orbit-feature-acceptance route` → `candidate=f9a9f4079…`,
  `base_tip=merge_base=9d9d91c093136c33de09085ff2d4fffcc45cea44`, 34 changed
  files, `venue=retained-incus`.
- Quality artifact
  `.orbit/quality-gates/quality-check-2026-08-24T001837Z-6c30635284e3.json`:
  `gate=quality-check`, `command=composer quality-check`, `mode=check`,
  `exit_code=0`, `git.commit=f9a9f40793167ea32f6b202bef8d32cccd5fbd18`,
  `git.dirty=false`, `duration_seconds=138`, 46 subgates, **0 failing**. This is
  the full gate, not a scoped rerun. The only `e2e_*` subgates present are
  static lanes (`mago_analyze`, `mago_format`, `mago_lint`, `rector`); no
  `composer test:e2e*` lane was executed.
- Focused counts in `.orbit/loop.md` verified statically against the sources:
  `NodeAgentEligibilityTest` has 7 `it()` blocks (claim: 7 passed);
  `FleetVersionProbeTest` has 13 `it()` blocks with two 2-case datasets, which
  expands to exactly 15 executed tests (claim: 15 passed). Both match.
- Evidence `.orbit/evidence/retained-dev-b77c00/runtime-proof.md` is bound to
  `f9a9f40793167ea32f6b202bef8d32cccd5fbd18`, base
  `9d9d91c093136c33de09085ff2d4fffcc45cea44`, with an aggregate SHA-256 over all
  34 changed files (`d6b8d6e8…`) matched in both the feature worktree and the
  retained gateway runtime checkout.

### Exact-candidate retained Linux evidence

`app-dev-1` prepared as the regression case: active Ubuntu AMD64, valid
WireGuard identity, reachable Agent (`HTTP 405`), `managed=false`, no role
assignments, current CLI `0.1.196` (`164e8c23…`), no recorded Agent artifact.
`FleetVersionProbe::nodeNeedsUpdate()` returned `true`, the workload phase
returned `completed`, the Agent restart readiness barrier and
`FleetUpdateAgentVerifier` both passed, the node recorded Agent SHA
`bbf91b61…` while keeping `managed=false` and no roles, and
`nodeNeedsUpdate()` then returned `false`. The same fan-out skipped
`offline-linux` (`orbit_agent_not_running`) and `offline-mac`
(`orbit_desktop_not_running`) without aborting. This directly exercises the
claimed final outcome for the node class the feature adds.

### Native NMBP evidence

Action source pinned by per-file SHA on the Mac
(`LocalFleetUpdateInstallCliAction.php` `929c6252…`,
`PendingDesktopUpdateHandoff.php` `aa120c97…`). Installed and verified CLI
`0ba3090a…`, Agent `ceb49b22…`, and staged Desktop archive `ed8ee953…`. The
handoff was mode `0600`, Darwin ARM64, build `20260823T212616Z-9d9d91c09`;
the action reported `desktop_staged=true` and `agent_installed=true`, deferred
the Agent restart to Desktop, and left the legacy Agent at PID `73638`. The real
pending-handoff path was not written.

### Ubuntu gateway boundary evidence

The evidence states plainly that this boundary is proven deterministically by
focused tests rather than by a live idle `update:all`: a real Ubuntu gateway
with current image and CLI plus a Linux Agent artifact in the plan is not
fleet-update eligible, and `FleetVersionProbe` reports `outdatedCount=0` with
`allCurrent()=true`. That is adequate and honestly scoped. The boundary is a
pure predicate decision over persisted state and the immutable plan — it makes
no reachability or convergence claim — and the new feature test drives the real
`FleetVersionProbe` against a persisted `ubuntu_24-04` gateway, which is the
production code path. A live idle run is impossible in this topology anyway,
because it has no scheduler Swarm service and so cannot complete the unchanged
public gateway phase. The evidence says so rather than overclaiming.

## Blast radius

`BLAST_RADIUS: complete`. The delta changes a shared predicate consumed across
the fleet-update path, so the bounded inventory was re-run on this candidate.

Evidence and result:

- `rg -n "isFleetUpdateEligible\(\)" --glob '!**/tests/**' --glob '!**/generated/**'`
  → 7 sites (1 definition, 6 consumers). Each consumer re-checked individually
  above; all six are either already gateway-free, independently guarded, or the
  intended fix target. No consumer loses a node it must keep, and no consumer
  gains one it must not. Resolved.
- `rg -n "agentArtifacts->nodeNeedsUpdate" apps/gateway/app` → 2 call sites.
  `FleetVersionProbe:119` is the gateway-carrying one and is now correctly
  short-circuited; `FleetVersionProbe:127` only receives `workloadNodes()`.
  Resolved.
- `rg -n "isAgentEligible\(\)" --glob '!**/tests/**' --glob '!**/generated/**'`
  → 9 remaining consumers, all outside the fleet-update path
  (`NodeUpdateController`, `WorkspaceSetupStepLocalExecutor`,
  `AppSetupStepLocalExecutor`, `SoloUpstreamTargetResolver`,
  `NodeCommandTransportSelector:38`, `SecurityInstallerTransport`,
  `NodeAgentConfigRenderer::render`, `NodeAgentServicePayloadBuilder::forNode`,
  `NodesProbe`). All correctly retain Agent-intent semantics and are untouched
  by this delta. Resolved.
- Skip vocabulary, docs, product decision, JSON and human renderers are
  unchanged by this delta and remain aligned as verified in rounds 1 and 2.
  Resolved.

Every affected surface was inspected and resolved. No surface remains
unresolved, and no actionable finding remains.

## Human judgment

`HUMAN_JUDGMENT: not-required`. Proof leaves no prepared experience requiring
human judgment about intent, UX, or real-world behavior. The macOS
restart-to-update experience that would need a person is explicitly a separate
native slice; what this slice owes — staged Desktop, Agent, and CLI bytes plus
the owner-only handoff — was observed directly on NMBP, and every remaining
check is a deterministic command an agent can run and inspect.

## Required final lines

```text
BLAST_RADIUS: complete
HUMAN_JUDGMENT: not-required
VERDICT: PASS
```
