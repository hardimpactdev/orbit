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
  `composer test:e2e:incus -- --filter='starts a managed system service tool'`.
- [x] `tool:stop` — gateway-local + Saloon forwarding implementation and
  focused Pest coverage in
  `tests/Feature/Commands/Tools/ToolStopCommandTest.php`. E2E lane:
  Incus VM-feature because the command exercises host-init managed services.
  Passed gate:
  `composer test:e2e:incus -- --filter='stops a managed system service tool'`.
- [x] `tool:restart` — gateway-local + Saloon forwarding implementation and
  focused Pest coverage in
  `tests/Feature/Commands/Tools/ToolRestartCommandTest.php`. E2E lane:
  Incus VM-feature because the command exercises host-init managed services.
  Passed gate:
  `composer test:e2e:incus -- --filter='restarts a managed system service tool'`.
- [x] `tool:reload` — gateway-local + Saloon forwarding implementation,
  interactive omitted-tool selector, and focused Pest coverage in
  `tests/Feature/Commands/Tools/ToolReloadCommandTest.php`. E2E lane:
  Incus VM-feature because the command exercises host-init managed services.
  Passed gate:
  `composer test:e2e:incus -- --filter='reloads a managed system service tool'`.
- [x] `tool:logs` — finite log snapshot gateway-local + Saloon forwarding,
  gateway-local human `--follow`, and gateway-forwarded streaming `--follow`
  for non-gateway callers, with focused Pest coverage in
  `tests/Feature/Commands/Tools/ToolLogsCommandTest.php` and
  `tests/Feature/Http/Api/ToolLogsStreamControllerTest.php`. E2E lane:
  Incus VM-feature because the command reads host-init managed service logs.
  Passed gate:
  `composer test:e2e:incus -- --filter='reads finite managed system service tool logs'`.
  Fixed: the E2E gateway test shim must stop prepared-topology Caddy before
  binding its current-checkout HTTP/TLS test servers, and must force a concrete
  compiled-view path for shimmed command execution.
- [x] `tool:remove` — gateway-local + Saloon forwarding implementation,
  destructive consent (`--force`), credential clearing after node-side cleanup,
  and focused Pest coverage in
  `tests/Feature/Commands/Tools/ToolRemoveCommandTest.php`. E2E lane: Docker
  feature because the test validates gateway intent plus Docker compose command
  generation with a controlled Docker CLI.
  Passed gate:
  `composer test:e2e:docker -- --filter='removes a docker-managed tool'`.
  Fixed: retry material is now preserved when remote cleanup fails; credentials
  are cleared only after the node-side remove script succeeds.
- [~] `tool:credentials` — gateway-local + Saloon forwarding implementation
  and focused Pest coverage in
  `tests/Feature/Commands/Tools/ToolCredentialsCommandTest.php`. E2E lane:
  Docker feature because the command reads gateway intent and does not require
  VM-only behavior.
  Passed gate:
  `composer test:e2e:docker -- --filter='reads managed tool credentials'`.
  Remaining: reconcile catalog credential support for credential-bearing tools
  such as `opencode-server`.
- [~] `tool:install` — gateway-local + Saloon forwarding implementation,
  registry intent creation, remote script enactment for Docker-based tools,
  and focused Pest coverage in
  `tests/Feature/Commands/Tools/ToolInstallCommandTest.php`. E2E lane:
  Docker feature because the test validates gateway intent plus Docker compose
  command generation with a controlled Docker CLI. Passed gate:
  `composer test:e2e:docker -- --filter='installs a docker-managed tool'`.
  Remaining: reconcile documented credential generation, endpoint intent, and
  `--status=installed` behavior.
- [~] `tool:update` — gateway-local + Saloon forwarding implementation,
  registry version update, remote script enactment for Docker-based and
  package-managed tools, and focused Pest coverage in
  `tests/Feature/Commands/Tools/ToolUpdateCommandTest.php`. E2E lane:
  Docker feature because the test validates gateway intent plus Docker compose
  command generation with a controlled Docker CLI. Passed gate:
  `composer test:e2e:docker -- --filter='updates a docker-managed tool'`.
  Fixed: requested version intent is preserved when remote enactment fails.
  Decision captured: the CLI uses `--expected-version` because `--version`
  collides with Symfony's global console option. Remaining: align `[tool]` /
  bulk update contract, catalog update capabilities, and bulk result shape.
- [~] `tool:reconfigure` — gateway-local + Saloon forwarding implementation,
  registry config/credential update, placeholder remote script for
  polyscope-server and opencode-server, and focused Pest coverage in
  `tests/Feature/Commands/Tools/ToolReconfigureCommandTest.php`. E2E lane:
  Incus VM-feature because the command exercises host-init managed service
  reconfiguration. Remaining: align optional-tool selector contract, replace
  placeholder scripts with real tool-specific behavior, validate password
  support, and add paired E2E.

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
  `composer test:e2e:docker -- --filter='repairs managed tool configuration drift'`.
- [!] Capability fix handlers and adopt action handlers are outstanding. Next:
  define scoped adopt behavior for selected observed tool reality, or add
  capability fix once catalog definitions declare safe install/restore commands.

## Foundations

- [x] `docs/abstractions/3_tool.md` exists.
- [x] `node_tools` schema/model/factory, `GET /api/tools`,
  `GET /api/tools/{tool}`, and typed Saloon list/show requests.
- [x] Tool API action endpoints scope the requested `--node` / `--app` target
  to the authenticated caller before invoking local gateway services. Coverage:
  `tests/Feature/Http/Api/ToolTargetAuthorizationControllerTest.php`.
