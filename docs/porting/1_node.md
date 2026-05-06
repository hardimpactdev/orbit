# 1_node — Node Workstream

Detail file for the node command family. Top-level command status lives in
[`PORTING.md`](PORTING.md). Doc authority: `docs/commands/1_node/`.

## Workstream

- [x] Convert node command docs into current format.
- [x] Build minimal node registry read commands (`node:list`, `node:show`).
- [x] Complete `node:list` contract gaps:
  - [x] JSON renderer contract.
  - [x] Human renderer contract.
  - [x] `--role` and `--environment` filters.
  - [x] Node doctor technical contract and `NodesProbe` primitives.
  - [x] `--doctor` secondary operation.
  - [x] caller visibility/access-policy behavior.
  - [x] gateway forwarding (control/app CLI callers use typed GatewayConnector;
    E2E gate todo 254 complete).
  - [x] doctor handoff behavior.
- [x] Complete `node:show` contract gaps:
  - [x] modeled `environment`, `platform`, and node agent IDE metadata.
  - [x] JSON renderer contract (envelope shape, field contract, all error codes and metadata).
  - [x] Human renderer contract (field order, grants section, failure prose).
  - [x] caller-role resolution.
  - [x] access-policy authorization.
  - [x] gateway forwarding (control/app CLI callers use typed GatewayConnector;
    E2E gate todo 254 complete).
  - [x] interactive prompting.
  - [x] default development app-node resolution.
  - [x] real grant metadata for gateway-local and forwarded reads.
- [x] Reconcile `node:register` with product command contracts.
  - **Decision:** Retire as public command. `node:register` is an internal
    bootstrap utility only.
- [x] Port `node:update`.
  - Current implementation: `app/Console/Commands/NodeUpdateCommand.php`
  - Current docs: `docs/commands/1_node/7_node-update`
  - Current tests:
    - `tests/Feature/Commands/NodeUpdateCommandTest.php` (base contract, safety, duplicate flag)
    - `tests/Feature/Commands/Nodes/NodeUpdateCommandTest.php` (command contract)
    - `tests/Feature/Commands/Nodes/NodeUpdateHumanRendererTest.php` (human renderer contract)
    - `tests/Feature/Commands/Nodes/NodeUpdateJsonRendererTest.php` (JSON renderer contract)
    - `tests/Feature/Commands/Nodes/NodeUpdateInteractiveInputModeTest.php` (interactive input mode contract)
    - `tests/E2E/NodeUpdateTest.php` (Docker feature E2E for control-caller gateway forwarding)
  - Bootstrap slice implemented: gateway-local update with progress tree, field validation, role-incompatibility checks, and split contract tests.
  - Gateway forwarding slice implemented: configured control callers forward
    through `GatewayConnector` and typed `UpdateNodeRequest`; gateway API
    structured errors are preserved, and forwarded writes do not require or
    mutate a local target-node row.
  - E2E gate implemented: Docker feature coverage verifies a configured control
    caller updates gateway-owned app-node metadata through the Gateway API and
    reads the persisted intent back through forwarded `node:show`.
  - Interactive input mode implemented: gateway/control callers prompt for a
    missing node name, prompt for role-filtered field selection when no field
    flags are supplied, and prompt for the selected field value; app callers
    and unconfigured control callers fail before prompts.
  - Artifact re-enactment slice implemented: changed gateway intent invokes a
    node artifact re-enactment hook; hook failures preserve the committed
    intent, return success with `node.artifact_enactment_failed` warnings, and
    point operators to `doctor --family=node --fix`.
- [x] Port `node:default`.
  - Current implementation: `app/Console/Commands/NodeDefaultCommand.php`
  - Current docs: `docs/commands/1_node/9_node-default`
  - Current tests:
    - `tests/Feature/Commands/NodeDefaultCommandTest.php` (flat base contract)
    - `tests/Feature/Commands/Nodes/NodeDefaultCommandTest.php` (split command contract)
    - `tests/Feature/Commands/Nodes/NodeDefaultHumanRendererTest.php` (human renderer contract)
    - `tests/Feature/Commands/Nodes/NodeDefaultJsonRendererTest.php` (JSON renderer contract)
  - Bootstrap slice implemented: local read/show/set/clear sub-actions, human progress tree, JSON envelope shape, caller role rejection, split contract tests.
  - Gateway API slice implemented: gateway-side show/set/clear endpoints and typed `DefaultNodeRequest`.
    This API exists on `main`, but command forwarding is not part of the active
    product contract.
  - Gateway-visible validation implemented: configured control callers validate
    `set` and discover interactive `choose` options through `ListNodesRequest`
    while keeping default storage local.
  - No command-forwarding todo is currently valid for `node:default` without a
    product-doc change.
- [x] Port `node:grant`.
  - Current implementation: `app/Console/Commands/NodeGrantCommand.php`
  - Current docs: `docs/commands/1_node/5_node-grant`
  - Current tests:
    - `tests/Feature/Commands/Nodes/NodeGrantCommandTest.php` (command contract)
    - `tests/Feature/Commands/Nodes/NodeGrantHumanRendererTest.php` (human renderer contract)
    - `tests/Feature/Commands/Nodes/NodeGrantJsonRendererTest.php` (JSON renderer contract)
    - `tests/E2E/NodeGrantTest.php` (Docker feature E2E for control-caller gateway forwarding)
  - Bootstrap slice implemented: gateway-local grant creation, idempotence, node-not-found validation, self-grant policy enforcement, caller role rejection, human and JSON renderer contracts, split contract tests.
  - Gateway API prerequisite implemented: `POST /api/nodes/grant` plus typed
    `GrantNodeRequest`.
  - Gateway forwarding slice implemented: configured control callers forward
    through `GatewayConnector` and typed `GrantNodeRequest`; gateway API
    structured errors are preserved, and forwarded grants do not require or
    mutate a local target-node row.
  - E2E gate implemented: Docker feature coverage verifies a configured control
    caller creates a gateway-owned node access grant through the Gateway API and
    reads the grant back through forwarded `node:show`.
- [~] Port `node:revoke`.
  - Current implementation: `app/Console/Commands/NodeRevokeCommand.php`
  - Current tests:
    - `tests/Feature/Commands/Nodes/NodeRevokeCommandTest.php` (command contract)
    - `tests/Feature/Commands/Nodes/NodeRevokeHumanRendererTest.php` (human renderer contract)
    - `tests/Feature/Commands/Nodes/NodeRevokeJsonRendererTest.php` (JSON renderer contract)
    - `tests/Feature/Commands/Nodes/NodeRevokeInteractiveInputModeTest.php` (interactive input mode contract)
    - `tests/E2E/NodeRevokeTest.php` (Docker feature E2E for control-caller gateway forwarding)
  - Bootstrap slice implemented: gateway-local grant revocation, idempotence, node-not-found validation, self-lockout detection, destructive consent (`--force`, interactive confirmation), caller role rejection, human progress tree and JSON renderer contracts, split contract tests.
  - Gateway API prerequisite implemented: `POST /api/nodes/revoke` plus typed
    `RevokeNodeRequest`.
  - Gateway forwarding slice implemented: configured control callers forward
    through `GatewayConnector` and typed `RevokeNodeRequest`; gateway API
    structured errors are preserved, and forwarded revocations do not require
    or mutate a local target-node row.
  - E2E gate implemented: Docker feature coverage verifies a configured control
    caller removes a gateway-owned node access grant through the Gateway API and
    reads the removed grant state back through forwarded `node:show`.
  - Contract gaps:
    - Interactive prompt testing in PHPUnit/Pest is limited by non-TTY environment; confirmation decline and prompt abort behavior are covered by command logic but not fully exercised via automated prompts.
- [~] Port `node:remove`.
  - Files:
    - `app/Console/Commands/NodeRemoveCommand.php` (gateway-local bootstrap slice)
    - `tests/Feature/Commands/Nodes/NodeRemoveCommandTest.php` (command contract)
    - `tests/Feature/Commands/Nodes/NodeRemoveHumanRendererTest.php` (human renderer contract)
    - `tests/Feature/Commands/Nodes/NodeRemoveJsonRendererTest.php` (JSON renderer contract)
    - `tests/Feature/Commands/Nodes/NodeRemoveInteractiveInputModeTest.php` (interactive input mode contract)
    - `tests/E2E/NodeRemoveTest.php` (Docker feature E2E for control-caller gateway forwarding)
  - Bootstrap slice implemented: gateway-local node removal, grant cascade (consumer and serving directions), node-not-found validation (NOT idempotent), gateway-node rejection, destructive consent (`--force`, interactive confirmation), caller role rejection, human progress tree and JSON renderer contracts, split contract tests.
  - Gateway API prerequisite implemented: `DELETE /api/nodes/{name}` plus typed
    `RemoveNodeRequest`.
  - Gateway forwarding slice implemented: configured control callers forward
    through `GatewayConnector` and typed `RemoveNodeRequest`; gateway API
    structured errors are preserved, and forwarded removals do not require or
    mutate a local target-node row.
  - E2E gate implemented: Docker feature coverage verifies a configured control
    caller removes a gateway-owned app-node record through the Gateway API and
    reads the removed state back through forwarded `node:show`.
  - Contract gaps:
    - [!] DNS mapping cleanup for dev-app nodes is blocked until the clean repo
      has gateway-owned development DNS mapping state. Current `dns:*`
      commands intentionally manage caller-local resolver overrides only and
      do not own gateway development DNS mappings. Next concrete action: design
      the gateway-owned development DNS mapping model/API used by node
      provisioning, node removal, and node doctor.
    - Interactive prompt testing in PHPUnit/Pest is limited by non-TTY environment; confirmation decline and prompt abort behavior are covered by command logic but not fully exercised via automated prompts.
- [~] Port `node:agent-ide`.
  - Current implementation: `app/Console/Commands/NodeAgentIdeCommand.php`
  - Current docs: `docs/commands/1_node/10_node-agent-ide`
  - Current tests:
    - `tests/Feature/Commands/Nodes/NodeAgentIdeCommandTest.php` (command contract)
    - `tests/Feature/Commands/Nodes/NodeAgentIdeInteractiveInputModeTest.php` (interactive input mode contract)
    - `tests/Feature/Http/Api/NodeAgentIdeControllerTest.php` (gateway API and activity)
    - `tests/Feature/Commands/Nodes/NodeShowJsonRendererTest.php` (node-show agent IDE metadata)
    - `tests/Feature/Http/Api/NodeShowControllerTest.php` (gateway API show metadata)
  - Bootstrap slice implemented: gateway-local set/clear/converged writes,
    configured control forwarding through `GatewayConnector` and typed
    `SetNodeAgentIdeRequest`, gateway API endpoint, unsupported-adapter and
    node-not-found errors, app-caller denial, node-show metadata, and activity
    logging. Docker feature E2E verifies the configured control-caller
    forwarding path and node-show metadata convergence.
  - Contract gaps:
    - [!] Extension-registered adapter registry beyond the core adapters
      (`opencode`, `polyscope`) and reserved `none` token is blocked until the
      clean repo has a gateway-owned extension registration surface to persist
      and list agent IDE adapters. Next concrete action: design the shared
      adapter registry used by both `node:agent-ide` and `app:agent-ide`, then
      expose a typed gateway request for configured control callers to fetch
      prompt choices from the gateway.
- [~] Port `node:new`.
  - Current implementation: `app/Console/Commands/NodeNewCommand.php`
  - Current tests:
    - `tests/Feature/Commands/NodeNewCommandTest.php` (base first-gateway,
      installer, convergence, trust, platform, and forwarding contract)
    - `tests/Feature/Commands/Nodes/NodeNewCallerRoleTest.php` (caller-role
      resolution)
    - `tests/Feature/Commands/Nodes/NodeNewInteractiveInputModeTest.php`
      (interactive input mode contract)
  - [x] Bootstrap host installer exists and is used before Orbit runs on a
    fresh gateway host.
  - [x] First-gateway command path validates required non-interactive input.
  - [x] First-gateway command path ships current Orbit source to the target over
    SSH and runs the installer there.
  - [x] First-gateway command path records bootstrap gateway and local control
    registry rows.
  - [x] First-gateway bootstrap creates/verifies a steady-state runtime user
    (`orbit`) through the bootstrap SSH user and installs Orbit under that user.
  - [x] First-gateway ephemeral E2E lane (`composer test:e2e:provision --filter='NodeNewGateway'`) verifies
    end-to-end provisioning against disposable Incus VMs.
  - [x] First-gateway WireGuard enrollment E2E lane (`composer test:e2e:provision -- --filter='NodeNewWireGuard'`)
    verifies gateway/control WireGuard interfaces, gateway registry peer rows,
    gateway API reachability over WireGuard, and idempotent gateway convergence.
  - [x] First-gateway bootstrap invokes gateway-local internal command over SSH
    to initialize gateway node identity (`is_local=true`) and generate root CA.
  - [x] First-gateway bootstrap provisions the gateway-local Orbit API runtime
    using Caddy and an Orbit-owned PHP-FPM pool before verifying `/api/me` from
    the initiating control node.
  - [x] First-gateway bootstrap captures gateway root CA from remote command
    output and stores it locally for control-node trust.
  - [x] Interactive input mode.
  - [~] Gateway-connected forwarding from configured control nodes.
    - [x] App-node creation forwarding.
    - [x] Control-node enrollment forwarding.
    - [x] Gateway convergence forwarding.
    - [!] Gateway adoption forwarding is blocked until gateway-local adoption
      can safely prove compatible already-provisioned host identity.
  - [~] Gateway-local app and control enrollment paths.
    - [x] App-node provisioning path.
    - [x] Control-node enrollment path with WireGuard config return.
    - [x] Gateway convergence path.
    - [!] Gateway adoption path is blocked on node-family adoption probes.
      Local platform record adoption and unambiguous WireGuard address adoption
      are implemented, and app runtime readiness now produces adoption
      verification/conflict results. `node:new` still needs a compatible
      gateway/app host boundary for missing/extra WireGuard identity before it
      can safely adopt already-provisioned hosts. The old repo skipped these
      cases, and the clean repo can currently inspect only gateway registry
      peer rows, not live WireGuard peer reality or remote node identity
      artifacts. Registry-only peer rows are insufficient to attach or recreate
      node identity safely. A read-only `WireGuardPeerRealityProbe` now parses
      live `wg show <interface> allowed-ips` output by public key. Next concrete
      action: wire that probe into node-family adoption so missing/extra peer
      adoption is enabled only for proven compatible identities, then wire
      `node:new` adoption through the verified node-family adoption result.
  - [x] Real platform detection for first-gateway bootstrap (todo 274).
  - [x] Full documented JSON success state after WireGuard/API work lands.
- [~] Restore node provisioning support:
  - [~] SSH bootstrap
  - [~] WireGuard enrollment
    - [x] `WireGuardPeer` model, migration, and `WireGuardKeyGenerator` service (todo 271).
    - [x] Node enrollment hook and gateway interface configuration (todo 268).
  - [~] gateway registry writes
  - [~] local node role and identity persistence
  - [x] Orbit API vhost provisioning
  - [x] Orbit PHP-FPM pool provisioning
  - [x] gateway-to-node SSH trust model
- [x] Distribute SSH trust to the runtime user so control nodes can SSH as
  `orbit` after first-gateway provisioning.
- [!] Restore ephemeral node E2E before treating provisioning and host-mutation
  flows as fully verified.

## First-Gateway Provisioning Split Candidates

Use these candidates in order when provisioning work becomes the active lane:

0. `NODENEW-WG-FOUNDATION-1` (todo 271) is merged: `WireGuardPeer`,
   `wireguard_peers`, factories, and `WireGuardKeyGenerator` are available for
   the enrollment slice.
1. `NODENEW-WIREGUARD-ENROLL-1` (todo 268) is implemented: first-gateway
   bootstrap generates gateway/control keys, persists gateway-side peer rows
   plus the local initiating-control peer, writes the gateway `wg-orbit` config
   through `orbit:internal:bootstrap-gateway-local`, and starts/enables the
   interface. The paired `e2e-provision` gate passed in the full provision
   lane.
2. `NODENEW-GATEWAY-API-VERIFY-1` (todo 283) is implemented: first-gateway
   bootstrap verifies `GET /api/me` over the gateway WireGuard IP after
   WireGuard, CA trust, and local gateway settings are in place. The paired
   `e2e-provision` gate passed in the full provision lane.
3. `NODENEW-GATEWAY-CA-VERIFY-1` (todo 272) is implemented: first-gateway
   bootstrap verifies and installs gateway CA trust, stores local trust
   metadata, exposes JSON trust evidence, and keeps repeat runs idempotent.
   The paired provisioning E2E gate passed in the full provision lane.
4. `NODENEW-PLATFORM-DETECT-1` (todo 274) is implemented: gateway and local
   control platform identifiers are detected and persisted during first-gateway
   bootstrap. A dedicated platform-detection provisioning gate remains
   non-blocking follow-up coverage.
5. `NODENEW-JSON-SUCCESS-1` is implemented: first-gateway bootstrap and
   compatible repeat/convergence JSON success payloads now reflect the
   documented state, including no SSH transport claim for already-provisioned
   convergence.

## Gateway Write-Forwarding Chain

Each step adds a new write API endpoint and the matching command-forwarding
slice. Order matters because each adds a new write API endpoint.

1. `NODE-API-UPDATE-1` is implemented: gateway-side `PUT /api/nodes/{name}` +
   `UpdateNodeRequest`.
2. `NODE-UPDATE-FWD-1` is implemented: configured control callers forward
   `node:update` through `UpdateNodeRequest`, preserve structured gateway API
   errors, avoid local target-node writes, and have paired Docker feature E2E
   coverage.
3. `NODE-API-DEFAULT-1` is implemented: gateway-side `GET|PUT|DELETE
   /api/nodes/default` + `DefaultNodeRequest`.
4. `NODE-DEFAULT-FWD-1` is invalidated/deferred: the current command contract
   keeps `node:default` as a control-node-local preference. Do not wire command
   forwarding unless the command docs are intentionally changed first.
5. `NODE-API-GRANT-1` is implemented: gateway-side `POST /api/nodes/grant` +
   `GrantNodeRequest`.
6. `NODE-GRANT-FWD-1` is implemented: configured control callers forward
   `node:grant` through `GrantNodeRequest`; paired Docker feature E2E coverage
   verifies the forwarded grant and read-back path.
7. `NODE-API-REVOKE-1` and `NODE-REVOKE-FWD-1` are implemented: configured
   control callers forward through `RevokeNodeRequest`, preserve structured
   gateway API errors, and do not require local target-node rows.
8. `NODE-API-REMOVE-1` and `NODE-REMOVE-FWD-1` are implemented: configured
   control callers forward through `RemoveNodeRequest`, preserve structured
   gateway API errors, and do not require local target-node rows. WireGuard
   peer teardown and DNS cleanup stay tracked as later destructive cleanup
   follow-ups.

Do not create future FWD-* todos until the matching API-* todo is on `main`.
Do not create more than 2 of these chains in flight at once.
