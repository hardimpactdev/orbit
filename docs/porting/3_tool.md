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
- [~] `tool:reload` — explicit non-interactive gateway-local + Saloon
  forwarding implementation and focused Pest coverage in
  `tests/Feature/Commands/Tools/ToolReloadCommandTest.php`. E2E lane:
  Incus VM-feature because the command exercises host-init managed services.
  Passed gate:
  `set -a; [ ! -f .env.e2e ] || . ./.env.e2e; set +a; ORBIT_E2E=1 ORBIT_E2E_TOPOLOGY_PROVIDER=incus ORBIT_E2E_GATEWAY_API=1 ORBIT_E2E_TOPOLOGY_CACHE=process ORBIT_E2E_CHECKOUT_CACHE=process ORBIT_E2E_TOPOLOGY_STRATEGY=minimal php artisan test --testsuite=E2E --group=e2e-feature --filter='reloads a managed system service tool'`.
  Remaining before `[x]`: interactive omitted-tool selector from visible
  reload-capable tools.
- [ ] `tool:install`, `tool:remove`, `tool:logs`, `tool:update`,
  `tool:credentials`, `tool:reconfigure` — write/enactment commands not
  started.

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
