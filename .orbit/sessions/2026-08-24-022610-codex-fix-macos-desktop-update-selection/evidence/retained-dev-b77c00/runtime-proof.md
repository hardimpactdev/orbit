# Retained runtime proof

- Candidate: `f9a9f40793167ea32f6b202bef8d32cccd5fbd18`
- Base: `9d9d91c093136c33de09085ff2d4fffcc45cea44`
- Retained topology: `dev-b77c00`
- Kind: `operator_gateway_app-dev_app-prod_agent_websocket`
- Native Mac: `nick.local` (`NMBP`, macOS 27.0, ARM64)
- Candidate source identity: the aggregate SHA-256 over all 34 changed files was
  `d6b8d6e8aea24fd679ef9725cde38541260dab1a792d239729c0ed4885f8ab14`
  in both the feature worktree and the retained gateway runtime checkout.

## Retained Linux proof

The retained gateway database was prepared with `app-dev-1` as the regression
case:

- active Ubuntu AMD64 node with a valid WireGuard identity;
- reachable Agent endpoint (`HTTP 405` from its command route);
- `managed=false`;
- no role assignments;
- current CLI `0.1.196` with SHA-256
  `164e8c23c56e994e396a8cc7de148430d75f7401a989a1f267b39a0a8a819c9b`;
- no recorded Agent artifact.

`FleetVersionProbe::nodeNeedsUpdate()` returned `true`. The exact-candidate
workload phase then returned `completed` for `app-dev-1`. The Agent restart
readiness barrier and `FleetUpdateAgentVerifier` both passed. The final node
state kept `managed=false` and no roles, recorded Agent SHA-256
`bbf91b61dbe881b8719da7292f967abb99791f057a1f48d3cfc4db23e6d2a843`,
and made `FleetVersionProbe::nodeNeedsUpdate()` return `false`.

The same fan-out skipped the intentionally unreachable records without
aborting:

- `offline-linux`: `orbit_agent_not_running`
- `offline-mac`: `orbit_desktop_not_running`

The corrected gateway boundary also has deterministic candidate proof. A real
Ubuntu gateway with current image and CLI plus a Linux Agent artifact in the
plan is not fleet-update eligible. `FleetVersionProbe` reports
`outdatedCount=0` and `allCurrent()=true`. The focused suites passed 7 node
eligibility tests and 15 fleet-version tests before the full quality gate.

The topology has no scheduler Swarm service. Therefore, its unchanged public
gateway phase cannot run the full public `update:all`. The proof invoked the
exact workload updater, restart barrier, and Agent verifier against operation
run `01a030d7-de01-7127-9d72-b0455224e9ae` and its immutable update plan.

## Native NMBP proof

The action source on NMBP matched the candidate:

- `LocalFleetUpdateInstallCliAction.php`:
  `929c625263b31612a1effb42f82da37d77fb049aee2ef6efbed2e3a43afccfd0`
- `PendingDesktopUpdateHandoff.php`:
  `aa120c9737ba3400e426c1a199caaa038f5d7a0a7a1fa632cf7821b1aae38e7a`

The native action used the live-test `0.1.196` manifest and isolated proof
paths. It installed and verified:

- CLI SHA-256
  `0ba3090aab3562e6d41db36a8809410a8e48af437353ffcb3461bd85d266c1aa`;
- Agent SHA-256
  `ceb49b2287814f197c539b48f6e4625bb5039c8fe26bb4eb0dd4d4ba60ff71f0`;
- Desktop archive SHA-256
  `ed8ee953c13e93aed13b1a21dd29af98f3a30c8d88bc43a6b790127dbc54e050`.

The pending handoff was mode `0600`, identified Darwin ARM64, recorded build
`20260823T212616Z-9d9d91c09`, and used install mode `restart-ready`. The action
reported `desktop_staged=true`, `agent_installed=true`, and deferred the Agent
restart to Desktop. The existing legacy Agent kept PID `73638` before and after
the isolated proof. The real pending-handoff path was not written.

The native proof deliberately supplied `restart-ready` to exercise the selected
restart-to-update UX. Gateway-produced fleet handoffs remain `automatic`; the
candidate tests cover that separate production payload contract.

## Result

Passed. A reachable, roleless, unmanaged Linux node with only its Agent artifact
missing was updated and verified. A native unmanaged Mac staged verified
Desktop, Agent, and CLI artifacts with restart-to-update semantics. Unreachable
nodes skipped before mutation without failing the remaining fleet.
