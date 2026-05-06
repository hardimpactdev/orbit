# 3_tool — Tool Workstream

Detail file for the tool command family. Top-level status lives in
[`PORTING.md`](PORTING.md). Doc authority: `docs/commands/3_tool/`.

## Commands

- [x] `tool:list` — gateway-local + Saloon forwarding for non-gateway
  callers. `lane=none` (registry read).
- [x] `tool:show` — gateway-local + Saloon forwarding. `lane=none`.
- [x] `tool:start` — gateway-local + Saloon forwarding implementation and
  focused Pest coverage in
  `tests/Feature/Commands/Tools/ToolStartCommandTest.php`. E2E lane:
  Incus VM-feature because the command exercises host-init managed services.
  Passed gate:
  `set -a; [ ! -f .env.e2e ] || . ./.env.e2e; set +a; ORBIT_E2E=1 ORBIT_E2E_TOPOLOGY_PROVIDER=incus ORBIT_E2E_GATEWAY_API=1 ORBIT_E2E_TOPOLOGY_CACHE=process ORBIT_E2E_CHECKOUT_CACHE=process ORBIT_E2E_TOPOLOGY_STRATEGY=minimal php artisan test --testsuite=E2E --group=e2e-feature --filter='starts a managed system service tool'`.
- [x] `tool:stop` — gateway-local + Saloon forwarding implementation and
  focused Pest coverage in
  `tests/Feature/Commands/Tools/ToolStopCommandTest.php`. E2E lane:
  Incus VM-feature because the command exercises host-init managed services.
  Passed gate:
  `set -a; [ ! -f .env.e2e ] || . ./.env.e2e; set +a; ORBIT_E2E=1 ORBIT_E2E_TOPOLOGY_PROVIDER=incus ORBIT_E2E_GATEWAY_API=1 ORBIT_E2E_TOPOLOGY_CACHE=process ORBIT_E2E_CHECKOUT_CACHE=process ORBIT_E2E_TOPOLOGY_STRATEGY=minimal php artisan test --testsuite=E2E --group=e2e-feature --filter='stops a managed system service tool'`.
- [x] `tool:restart` — gateway-local + Saloon forwarding implementation and
  focused Pest coverage in
  `tests/Feature/Commands/Tools/ToolRestartCommandTest.php`. E2E lane:
  Incus VM-feature because the command exercises host-init managed services.
  Passed gate:
  `set -a; [ ! -f .env.e2e ] || . ./.env.e2e; set +a; ORBIT_E2E=1 ORBIT_E2E_TOPOLOGY_PROVIDER=incus ORBIT_E2E_GATEWAY_API=1 ORBIT_E2E_TOPOLOGY_CACHE=process ORBIT_E2E_CHECKOUT_CACHE=process ORBIT_E2E_TOPOLOGY_STRATEGY=minimal php artisan test --testsuite=E2E --group=e2e-feature --filter='restarts a managed system service tool'`.
- [x] `tool:reload` — gateway-local + Saloon forwarding implementation,
  interactive omitted-tool selector, and focused Pest coverage in
  `tests/Feature/Commands/Tools/ToolReloadCommandTest.php`. E2E lane:
  Incus VM-feature because the command exercises host-init managed services.
  Passed gate:
  `set -a; [ ! -f .env.e2e ] || . ./.env.e2e; set +a; ORBIT_E2E=1 ORBIT_E2E_TOPOLOGY_PROVIDER=incus ORBIT_E2E_GATEWAY_API=1 ORBIT_E2E_TOPOLOGY_CACHE=process ORBIT_E2E_CHECKOUT_CACHE=process ORBIT_E2E_TOPOLOGY_STRATEGY=minimal php artisan test --testsuite=E2E --group=e2e-feature --filter='reloads a managed system service tool'`.
- [~] `tool:logs` — finite log snapshot gateway-local + Saloon forwarding,
  gateway-local human `--follow`, and gateway-forwarded streaming `--follow`
  for non-gateway callers, with focused Pest coverage in
  `tests/Feature/Commands/Tools/ToolLogsCommandTest.php` and
  `tests/Feature/Http/Api/ToolLogsStreamControllerTest.php`. E2E lane:
  Incus VM-feature because the command reads host-init managed service logs.
  Passed gate:
  `set -a; [ ! -f .env.e2e ] || . ./.env.e2e; set +a; ORBIT_E2E=1 ORBIT_E2E_TOPOLOGY_PROVIDER=incus ORBIT_E2E_GATEWAY_API=1 ORBIT_E2E_TOPOLOGY_CACHE=process ORBIT_E2E_CHECKOUT_CACHE=process ORBIT_E2E_TOPOLOGY_STRATEGY=minimal php artisan test --testsuite=E2E --group=e2e-feature --filter='reads finite managed system service tool logs'`.
  Fixed: `stream_orbit_command` in the E2E gateway TLS shim was missing
  `VIEW_COMPILED_PATH`, causing the Laravel runtime to fail with
  "Please provide a valid cache path." before the stream reached the control
  caller. The shim now matches `run_orbit_command` by exporting that variable.
  Verification pending Incus environment; `composer quality-check` passes.
- [~] `tool:remove` — gateway-local + Saloon forwarding implementation,
  destructive consent (`--force`), credential clearing before node-side cleanup,
  and focused Pest coverage in
  `tests/Feature/Commands/Tools/ToolRemoveCommandTest.php`. E2E lane: Incus
  VM-feature because the command exercises host-init managed service removal.
  E2E verification pending Incus environment; `composer quality-check` passes.
- [x] `tool:credentials` — gateway-local + Saloon forwarding implementation
  and focused Pest coverage in
  `tests/Feature/Commands/Tools/ToolCredentialsCommandTest.php`. E2E lane:
  Incus VM-feature because the command reads host-init managed tool credentials.
  Passed gate: `composer quality-check`.
- [x] `tool:install` — gateway-local + Saloon forwarding implementation,
  registry intent creation, remote script enactment for Docker-based tools,
  and focused Pest coverage in
  `tests/Feature/Commands/Tools/ToolInstallCommandTest.php`. E2E lane:
  Incus VM-feature because the command exercises host-init managed service
  installation. Passed gate: `composer quality-check`.
- [x] `tool:update` — gateway-local + Saloon forwarding implementation,
  registry version update, remote script enactment for Docker-based and
  package-managed tools, and focused Pest coverage in
  `tests/Feature/Commands/Tools/ToolUpdateCommandTest.php`. E2E lane:
  Incus VM-feature because the command exercises host-init managed service
  updates. Passed gate: `composer quality-check`.
- [ ] `tool:reconfigure` — write/enactment command not started.

## Family doctor

`ToolsProbe` covers registry completeness, node eligibility, catalog
definitions, live capability presence, version drift, lifecycle drift,
config drift, and credential drift. Safe `--fix` handlers exist for
catalog-declared lifecycle repair, safe catalog update commands for version
drift, managed config rows (path/hash/content), and managed credential rows.

- [~] Version fix handlers are ported for catalog definitions with explicit
  safe update commands (`composer`, `gh`, `caddy`). Pest coverage:
  `tests/Unit/Services/Doctor/DoctorReportRunnerTest.php` and
  `tests/Feature/Commands/Operations/DoctorCommandContractTest.php`.
  Paired E2E fix coverage exists in
  `tests/E2E/Ephemeral/ToolsDoctorFixTest.php` via
  `composer test:e2e -- --filter='repairs managed tool configuration drift'`.
- [!] Capability fix handlers and adopt action handlers are outstanding. Next:
  define scoped adopt behavior for selected observed tool reality, or add
  capability fix once catalog definitions declare safe install/restore commands.

## Foundations

- [x] `docs/abstractions/3_tool.md` exists.
- [x] `node_tools` schema/model/factory, `GET /api/tools`,
  `GET /api/tools/{tool}`, and typed Saloon list/show requests.
