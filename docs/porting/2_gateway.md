# 2_gateway — Gateway Workstream

Detail file for the gateway command family. Top-level status lives in
[`PORTING.md`](PORTING.md). Doc authority: `docs/commands/2_gateway/`.

## Commands

- [x] `gateway:add` — control-node onboarding: derive/verify gateway IP,
  fetch CA, install trust, verify `/api/me`, persist settings, and detect
  idempotent convergence. Pest under `tests/Feature/Commands/Gateway/`;
  provisioning E2E `tests/E2E/GatewayAddTest.php`.
  - WireGuard IP derivation uses active `10.6.0.0/16` interfaces when
    `gateway_ip` is omitted.
  - Convergence requires matching settings, configured CA file/hash, and
    OS trust-store evidence.
  - `LocalGatewaySettings` is the persistence boundary; there is no
    `LocalNodeContext` cache in the clean repo to flush.
- [x] `gateway:trust` — configured-gateway trust repair, CA hash/path metadata,
  OS trust-store verification, JSON/human renderers, and activity logging.
  Pest under `tests/Feature/Commands/Gateway/`; provisioning E2E
  `tests/E2E/GatewayTrustTest.php`.

Passed command-family E2E:

- `composer test:e2e:provision -- --filter='GatewayAdd|GatewayTrust'`

## Gateway CA

- [x] `OrbitCaService` generates, reads, and issues from a local gateway-root
  CA.
- [x] `node:new --role=gateway` invokes the gateway-local internal command
  `orbit:internal:bootstrap-gateway-local` over SSH to initialize gateway
  identity (`is_local=true`, `role=gateway`, `status=active`) and call
  `OrbitCaService::ensureRootCa()`.
- [x] The control side captures the root CA cert, stores it locally, verifies
  trust installation, and persists trust metadata.

## Foundations

- [x] `LocalGatewaySettings` single-row Eloquent model with `current()`
  accessor.
- [x] `TrustStoreInstaller` interface with macOS/Linux implementations.
- [x] `FetchGatewayRootCa` bootstrap-safe CA fetch service.
