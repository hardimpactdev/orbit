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
- [x] `node:update` — gateway-local + Saloon forwarding + interactive +
  artifact re-enactment hook. E2E `tests/E2E/NodeUpdateTest.php`.
- [x] `node:default` — local read/show/set/clear with gateway-visible
  validation through `ListNodesRequest`. Control forwarding intentionally
  not wired (product decision keeps default storage local).
- [x] `node:grant` — gateway-local + Saloon forwarding via
  `GrantNodeRequest`. E2E `tests/E2E/NodeGrantTest.php`.
- [~] `node:revoke` — gateway-local + Saloon forwarding via
  `RevokeNodeRequest`. E2E `tests/E2E/NodeRevokeTest.php`.
  - [ ] Automated TTY confirmation prompt coverage (PHPUnit non-TTY limitation).
- [~] `node:remove` — gateway-local + Saloon forwarding via
  `RemoveNodeRequest`. E2E `tests/E2E/NodeRemoveTest.php`.
  - [ ] WireGuard peer teardown for removed gateway/app nodes.
  - [ ] DNS mapping cleanup for dev-app nodes.
  - [ ] Automated TTY confirmation prompt coverage.
- [~] `node:agent-ide` — gateway-local + Saloon forwarding via
  `SetNodeAgentIdeRequest`. E2E `tests/E2E/NodeAgentIdeTest.php`.
  - [!] Extension-registered adapter registry beyond `opencode`/`polyscope`
    /`none` blocked on a gateway-owned extension registration surface.
- [~] `node:new` — first-gateway bootstrap slice. Provisioning E2E
  `tests/E2E/NodeNewGatewayTest.php` plus
  `NodeNewGatewayCaVerifyTest.php` and `NodeNewGatewayApiVerifyTest.php`.
  - [x] First-gateway provisioning (host installer, steady-state `orbit`
    user, WireGuard enrollment, root CA, `/api/me` reachability, platform
    detection, JSON success state).
  - [ ] Interactive input mode.
  - [ ] Configured-control forwarding through Gateway API.
  - [ ] Gateway-local app and control enrollment paths (non-bootstrap).
- [-] `node:register` — retired as public command; kept as internal
  bootstrap utility used by `node:new`.

## Family doctor

`NodesProbe` + `node:list --doctor` handoff ported. Outstanding:

- [ ] Live gateway runtime/API readiness check.
- [ ] Local TLD reality / DNS mapping reality.
- [ ] PHP default verification.

See [`state-families-doctor.md`](state-families-doctor.md).

## Provisioning follow-ups

- [~] WireGuard enrollment — gateway interface, peer rows, and
  `WireGuardKeyGenerator` are merged. Live gateway-interface reality check
  outstanding.
- [~] Gateway registry writes and local node identity persistence — partial.
- [ ] Orbit API vhost + PHP-FPM pool provisioning.
- [ ] Gateway-to-node SSH trust model.
- [ ] Distribute SSH trust to the runtime user so control nodes can SSH as
  `orbit` after first-gateway provisioning.
