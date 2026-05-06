# 1_node — Node Workstream

Detail file for the node command family. Top-level status lives in
[`PORTING.md`](PORTING.md). Doc authority: `docs/commands/1_node/`.

## Commands

- [x] `node:list` — JSON/human renderers, `--role`/`--environment` filters,
  `NodesProbe` + `--doctor` handoff, caller visibility/access policy,
  Saloon control/app forwarding. Pest under `tests/Feature/Commands/Nodes/`;
  E2E `tests/E2E/NodeListTopologyTest.php`.
- [x] `node:show` — JSON/human renderers, interactive + non-interactive
  input, caller-role + access-policy, default-dev-app resolution, Saloon
  forwarding, real grant metadata. Pest under
  `tests/Feature/Commands/Nodes/NodeShow*`; E2E
  `tests/E2E/NodeShowGrantTest.php`.
- [x] `node:update` — gateway-local + Saloon forwarding + interactive
  field prompting + artifact re-enactment hook. E2E
  `tests/E2E/NodeUpdateTest.php`.
- [x] `node:default` — local read/show/set/clear with gateway-visible
  validation through `ListNodesRequest`. Control forwarding intentionally
  not wired because the product contract keeps default storage local.
  - `lane=none`: control-node-local preference command; focused Pest covers
    show/set/clear behavior and gateway-visible validation.
- [x] `node:grant` — gateway-local + Saloon forwarding via
  `GrantNodeRequest`. E2E `tests/E2E/NodeGrantTest.php`.
- [x] `node:revoke` — gateway-local + Saloon forwarding via
  `RevokeNodeRequest`, destructive consent, confirmation decline, and prompt
  abort coverage. E2E `tests/E2E/NodeRevokeTest.php`.
- [x] `node:remove` — gateway-local + Saloon forwarding via
  `RemoveNodeRequest`, grant cascade, WireGuard peer row teardown, dev-app DNS
  mapping cleanup, destructive consent, confirmation decline, and prompt abort
  coverage. E2E `tests/E2E/NodeRemoveTest.php`.
- [x] `node:agent-ide` — gateway-local + Saloon forwarding via
  `SetNodeAgentIdeRequest`. Core adapters are registry-backed; extension
  descriptors are owned by the Agent IDE family and the node command already
  consumes gateway-provided choices. E2E `tests/E2E/NodeAgentIdeTest.php`.
- [x] `node:new` — first-gateway bootstrap, configured-control forwarding,
  gateway-local app/control enrollment, WireGuard enrollment, gateway API
  verification, CA verification, development/production app provisioning, and
  interactive input mode.
  - E2E `tests/E2E/NodeNewGatewayTest.php`,
    `tests/E2E/NodeNewGatewayApiVerifyTest.php`,
    `tests/E2E/NodeNewGatewayCaVerifyTest.php`,
    `tests/E2E/NodeNewDevelopmentAppTest.php`, and
    `tests/E2E/NodeNewProductionAppTest.php`.
  - Gateway missing-row materialization is intentionally not `node:new`
    behavior. Missing gateway identity is created by first-gateway bootstrap
    or a future explicit recovery/adoption contract.
- [-] `node:register` — retired as a public command; kept as hidden
  `orbit:internal:node-register` for bootstrap use.
  - `lane=none`: internal local bootstrap utility with no public runtime
    behavior outside focused Pest coverage.

Passed command-family E2E:

- `composer test:e2e:docker -- --filter='NodeListTopology|NodeShowGrant|NodeUpdate|NodeGrant|NodeRevoke|NodeRemove|NodeAgentIde'`
- `composer test:e2e:provision -- --filter='NodeNewGateway|NodeNewGatewayApiVerify|NodeNewGatewayCaVerify|NodeNewDevelopmentApp|NodeNewProductionApp'`

## Family doctor

- [x] `NodesProbe` and `node:list --doctor` handoff are ported.
- [x] `doctor --family=node` dispatch is ported through the shared doctor
  command.
- [x] Focused coverage:
  - `tests/Unit/Services/Nodes/NodesProbeTest.php`
  - `tests/Feature/Commands/Operations/DoctorCommandContractTest.php`
  - `tests/Feature/Commands/Nodes/NodeList*`

See [`state-families-doctor.md`](state-families-doctor.md) for shared doctor
transport/status work that is not node-command-specific.

## Provisioning

- [x] SSH bootstrap.
- [x] WireGuard enrollment.
- [x] Gateway registry writes.
- [x] Local node role and identity persistence.
- [x] Orbit API vhost provisioning.
- [x] Orbit PHP-FPM pool provisioning.
- [x] Gateway-to-node SSH trust model.
- [x] Runtime-user SSH trust distribution.
- [x] Ephemeral provisioning E2E for node host-mutation flows.

## Gateway Write-Forwarding Chain

The node write-forwarding chain is implemented through typed Saloon requests:

- [x] `UpdateNodeRequest`
- [x] `GrantNodeRequest`
- [x] `RevokeNodeRequest`
- [x] `RemoveNodeRequest`
- [x] `DefaultNodeRequest` gateway API endpoints exist; command forwarding is
  intentionally not part of the current `node:default` product contract.

Do not create future FWD-* todos until the matching API-* todo is on `main`.
Do not create more than two of these chains in flight at once.
