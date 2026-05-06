# 2_gateway — Gateway Workstream

Detail file for the gateway command family. Top-level status lives in
[`PORTING.md`](PORTING.md). Doc authority: `docs/commands/2_gateway/`.

## Commands

- [~] `gateway:add` — control-node onboarding: derive/verify gateway IP,
  fetch CA, install trust, verify `/api/me`, persist settings, idempotent
  convergence. Pest under `tests/Feature/Commands/Gateway/`; provisioning
  E2E `tests/E2E/GatewayAddTest.php`.
  - [ ] WireGuard IP derivation from active interfaces (currently requires
    explicit `gateway_ip` argument).
  - [ ] Local node context flush after persistence (`LocalNodeContext` not
    yet in clean repo).
- [x] `gateway:trust` — ported with activity logging.

## Gateway CA

- [x] `OrbitCaService` generates, reads, and issues from a local
  gateway-root CA.
- [x] `node:new --role=gateway` invokes the gateway-local internal command
  `orbit:internal:bootstrap-gateway-local` over SSH to initialize gateway
  identity (`is_local=true`, `role=gateway`, `status=active`) and call
  `OrbitCaService::ensureRootCa()`. The control side captures the root CA
  cert, stores it locally, verifies trust installation, and persists trust
  metadata.

## Foundations

- [x] `LocalGatewaySettings` single-row Eloquent model with `current()`
  accessor.
- [x] `TrustStoreInstaller` interface with macOS/Linux implementations.
- [x] `FetchGatewayRootCa` bootstrap-safe CA fetch service.
