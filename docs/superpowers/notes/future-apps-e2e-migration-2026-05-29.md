# apps/e2e E2E extraction — final split (2026-05-29)

This note records the end state of the pre-S3 E2E extraction (Solo todos
`#550`, `#551`, `#558`, `#552`, `#555`, `#556`, `#553`, `#554`) and what was
intentionally retained in `apps/gateway`. It is the companion to
`apps/docs/content/testing/README.md` (the authoritative lane map) and the
`docs/superpowers/plans/2026-05-29-pre-s3-cli-e2e-stabilization.md` plan.

## What moved to apps/e2e

`apps/e2e` is now a stock Laravel 13 application that fully owns the E2E runner
and externally-driven test suites:

- **Runner commands** (`apps/e2e/app/Console/Commands/`): `e2e:test` (lane
  planning, Docker/Incus host pools, parallel orchestration) plus
  `e2e:preflight`, `e2e:ensure-artifacts`, `e2e:prepare-base-image`,
  `e2e:prepare-docker-hosts`, `e2e:prepare-docker-runtime`,
  `e2e:prepare-docker-topology`, `e2e:prepare-topology`,
  `e2e:prepare-warm-topology`, `e2e:reap-docker`, `e2e:reap-incus`. Invoked from
  the repo root via `bin/orbit-e2e-artisan`.
- **Support layer** (`apps/e2e/app/E2E/Support/`): the topology/provider harness,
  prepared-topology contracts, lease/manifest support, Docker/Incus providers,
  WireGuard mesh, gateway API client, current-checkout overlay, etc.
- **Runner services** (`apps/e2e/app/Services/E2E/`): `DockerImageDistributor`,
  `IncusBaseImagePreparer`, `IncusImageDistributor`,
  `IncusBaseImagePreparationOptions`.
- **Externally-driven E2E test suites** (`apps/e2e/tests/Feature/`): the
  topology/provider contract tests, all app/node/gateway/operator/dns/php/
  profile/grant/runtime-backend/agent-ide command E2E, all resource
  (database/workspace/process/schedule) E2E, all infra/tool/websocket E2E, the
  retained dev-topology workflow, the deferred stragglers
  (tool-credentials, tool-lifecycle-host-init, ingress-production-topology,
  node-list-agent-topology, registry-prompt-input-mode), and `Ephemeral/*`.
- **Helpers/fixtures**: `tests/E2E/Support/Pest.php` (the shared `e2e*`
  helpers), `SqliteDatabaseFixture.php`, and `tests/Helpers/E2EEnvironment.php`.

The root Composer E2E scripts (`test:e2e`, `test:e2e:docker[:canary]`,
`test:e2e:incus`, `test:e2e:topology-contract`, `test:e2e:provision`, and the
`e2e:*` prepare/reap entry points) all run through `apps/e2e`. There is no
gateway-owned E2E runner; `e2e:*` is no longer in the gateway command surface
(removed from `GATEWAY_VISIBLE_COMMAND_PREFIXES`).

## What intentionally stayed in apps/gateway, and why

- **Gateway E2E-support unit tests** (`apps/gateway/tests/Feature/E2ESupport/**`):
  the in-memory unit tests of the support layer (e.g. `DockerTopologyBuilderTest`,
  `E2ETopologyFactoryTest`, `IncusTopologyProviderTest`, `E2ECurrentCheckoutTest`,
  `VerificationScriptsTest`, the moved-runner command/service tests' gateway
  counterparts) resolve the extracted `App\E2E\Support\*` classes through a
  temporary Composer PSR-4 bridge in `apps/gateway/composer.json`
  (`"App\\E2E\\Support\\": "../e2e/app/E2E/Support/"`). They are unit tests, not
  externally-driven E2E, so they were not in the pre-S3 move scope. Relocating
  them (and dropping the bridge) is a future cleanup, not required for S3.
- **Six support classes** still in `apps/gateway/app/E2E/Support/`: `E2ENetwork`,
  `E2ENodeProbe`, `E2EReachability`, `IncusProvider`, `ProviderPool`,
  `ProviderSelection`. They are not referenced by the `apps/e2e` runner/suites
  (verified by `apps/e2e` PHPStan); they remain with their gateway unit tests
  pending the future support-unit-test relocation.
- **Gateway `update`/`update:all` unit tests** (`apps/gateway/tests/E2E/UpdateTest.php`,
  `UpdateAllTest.php`): mock gateway services (`Process`, `RemoteShell`) and do
  not drive a real topology, so they are gateway-internal unit tests, not E2E.

## Layered E2E workflow contract

(see `docs/superpowers/plans/2026-05-29-layered-e2e-live-topology-workflow.md`
and the "Development lane invariant" in the testing README)

- **Source-checkout E2E** (Docker/Incus prepared-topology lanes overlaying the
  current checkout) is the normal feature feedback loop.
- **Retained prepared topologies** (`composer e2e:dev-topology` /
  `e2e:dev-topology:release`, owned by the `apps/e2e` runner — not gateway) are
  manual diagnosis/debug tools only; they must be explicitly released or reaped.
- **Findings from a retained topology** are codified back into ordinary
  prepared-topology Pest E2E tests; the durable assertion lives in Pest.
- **Binary acceptance** is a separate release-candidate lane that runs after
  source-checkout E2E passes; it proves the built CLI artifact and does not
  replace the source loop.
- **Provisioning** proves installer/`node:new` provisioning behavior, not the
  inner development loop.

## S3/RustFS repointing

S3/RustFS E2E coverage is added under `apps/e2e`, never
`apps/gateway/tests/E2E`. The S3 E2E todos were repointed accordingly:

- `#351` (S3 private route + credentials E2E) → `apps/e2e/tests/Feature/Commands/S3PrivateRouteTest.php`
- `#423` (S3 public ingress E2E) → `apps/e2e/tests/Feature/Commands/S3IngressRouteTest.php`
- `#424` (RustFS public-interface non-reachability) → same `apps/e2e` ingress E2E
- `#352` (final S3 verification) → `composer test:e2e:docker -- tests/Feature/Commands/S3*RouteTest.php`

All S3/RustFS todos remain blocked by `#536` (final all-green gate before
RustFS/S3); this extraction does not unblock them.
