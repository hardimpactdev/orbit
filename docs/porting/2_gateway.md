# 2_gateway — Gateway Workstream

Detail file for the gateway command family. Top-level command status lives in
[`PORTING.md`](PORTING.md). Doc authority: `docs/commands/2_gateway/`.

## Workstream

- [x] Convert gateway command docs into current format.
- [~] Port gateway trust/settings foundation (GATEWAY-2 bootstrap slice).
  - [x] `LocalGatewaySettings` single-row Eloquent model with `current()` accessor.
  - [x] `TrustStoreInstaller` interface with macOS/Linux implementations.
  - [x] `FetchGatewayRootCa` bootstrap-safe CA fetch service.
- [~] Port `gateway:add`.
  - [x] Control-node local onboarding flow: derive/verify gateway IP, fetch CA, install trust, verify `/api/me`, persist settings, idempotent convergence.
  - [x] Caller role resolution from local node registry (`Node::where('is_local', true)->value('role')`).
  - [x] Human renderer with progress tree shape per contract.
  - [x] JSON renderer with envelope shape per contract.
  - [x] Split contract tests: caller role, input contract, interactive/non-interactive input modes, JSON/human renderers.
  - [x] Ephemeral E2E lane: `tests/E2E/GatewayAddTest.php` covers control-node
    `gateway:add` against a disposable gateway VM in the `e2e-provision` lane.
  - [x] WireGuard IP derivation from active network interfaces.
    - `WireGuardGatewayAddressResolver` derives the gateway API address from
      unambiguous active `10.6.0.0/16` local interface addresses, and
      `gateway:add` now uses it before prompting or failing when `gateway_ip`
      is omitted.
  - [ ] Local node context flush after persistence (bootstrap gap: `LocalNodeContext` service not yet in clean repo).
- [x] Port `gateway:trust`.

## Gateway CA

- [~] Gateway root CA service.
  - [x] `OrbitCaService` generates, reads, and issues from a local gateway-root CA.
  - [x] Truthful CA generation hook in `node:new --role=gateway` first-gateway bootstrap.
    - Gateway-local internal command `orbit:internal:bootstrap-gateway-local` initializes
      the gateway's database identity (`is_local=true`, `role=gateway`, `status=active`)
      and calls `OrbitCaService::ensureRootCa()`.
    - `node:new --role=gateway` invokes this command over SSH after remote Orbit
      installation succeeds, captures the root CA cert, stores it locally,
      verifies local trust installation, and persists gateway trust metadata.
    - Focused tests cover CA generation, idempotence, node demotion, invalid-PEM
      rejection, trust-store install evidence/failure, idempotent repeat runs,
      and the full `node:new` bootstrap invocation path.
