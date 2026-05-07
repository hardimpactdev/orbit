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
- [x] `tool:credentials` — gateway-local + Saloon forwarding implementation
  and focused Pest coverage in
  `tests/Feature/Commands/Tools/ToolCredentialsCommandTest.php`. E2E lane:
  Docker feature because the command reads gateway intent and does not require
  VM-only behavior.
  Passed gate:
  `composer test:e2e:docker -- --filter='reads managed tool credentials'`.
  Fixed: catalog credential support for `opencode-server` (adds `credentials`
  capability); `polyscope-server` correctly remains unsupported.
- [x] `tool:install` — gateway-local + Saloon forwarding implementation,
  registry intent creation, remote script enactment for Docker-based and
  user-systemd tools (opencode-server, polyscope-server), credential generation
  via post-install credentials script, and focused Pest coverage in
  `tests/Feature/Commands/Tools/ToolInstallCommandTest.php`. E2E lane:
  Docker feature because the test validates gateway intent plus Docker compose
  command generation with a controlled Docker CLI. Passed gate:
  `composer test:e2e:docker -- --filter='installs a docker-managed tool'`.
  Fixed: added `credentialsScript()` to `ToolCatalog` and credential capture
  in `ToolInstaller` for tools that declare credential support (opencode-server).
  Added install scripts for opencode-server and polyscope-server matching the
  old-repo systemd user unit pattern.
  Deferred: tool-owned proxy route creation during install — the old repo does
  not implement this and the current architecture has no tool endpoint
  declaration mechanism; documented in `solo://proj/2/scratchpad/porting-deviations--143`.
- [x] `tool:update` — gateway-local + Saloon forwarding implementation,
  registry version update, remote script enactment for Docker-based and
  package-managed tools, optional `[tool]` argument for bulk update, and
  focused Pest coverage in
  `tests/Feature/Commands/Tools/ToolUpdateCommandTest.php`. E2E lane:
  Docker feature because the test validates gateway intent plus Docker compose
  command generation with a controlled Docker CLI. Passed gate:
  `composer test:e2e:docker -- --filter='updates a docker-managed tool'`.
  Fixed: requested version intent is preserved when remote enactment fails.
  Decision captured: the CLI uses `--expected-version` because `--version`
  collides with Symfony's global console option.
  Fixed: made `[tool]` optional; added `POST /api/tools/update` bulk endpoint
  (`ToolUpdateBulkController`), `UpdateToolsBulkRequest` gateway request,
  `ToolUpdater::updateAll()` that iterates managed tools and updates each
  with a declared `latestSupportedVersion()`, and bulk result shape with
  `updated`/`skipped`/`failed` arrays. Added `latestSupportedVersion()` to
  `ToolCatalog` (returns null for all current tools; future tools may declare
  explicit latest versions).
- [x] `tool:reconfigure` — gateway-local + Saloon forwarding implementation,
  registry config/credential update, real remote scripts for polyscope-server
  and opencode-server, and focused Pest coverage in
  `tests/Feature/Commands/Tools/ToolReconfigureCommandTest.php`. E2E lane:
  Docker feature because the command validates gateway intent plus reconfigure
  script generation with a controlled Docker CLI.
  Passed gate:
  `composer test:e2e:docker -- --filter='reconfigures a managed tool on a node'`.
  Fixed: replaced placeholder echo scripts with real systemd user unit
  reconfiguration; `ToolReconfigurer` now merges password into config array
  before script generation so `opencode-server` reconfigure includes auth env.

## Family doctor

`ToolsProbe` covers registry completeness, node eligibility, catalog
definitions, live capability presence, version drift, lifecycle drift,
config drift, and credential drift. Safe `--fix` handlers exist for
catalog-declared lifecycle repair, safe catalog update commands for version
drift, managed config rows (path/hash/content), and managed credential rows.

- [x] Version fix handlers are ported for catalog definitions with explicit
  safe update commands (`composer`, `gh`, `caddy`). Pest coverage:
  `tests/Unit/Services/Doctor/DoctorReportRunnerTest.php` and
  `tests/Feature/Commands/Operations/DoctorCommandContractTest.php`.
  Paired E2E fix coverage exists in
  `tests/E2E/Ephemeral/ToolsDoctorFixTest.php` via
  `composer test:e2e:docker -- --filter='repairs managed tool configuration drift'`.
- [x] Capability fix handlers — `ToolsFixer` now maps `tool.capability_missing`
  to `ToolCatalog::installScript()` for Docker-managed tools (redis, mailpit,
  reverb, postgres, mysql). Pest coverage:
  `tests/Unit/Services/Tools/ToolsFixerTest.php` and
  `tests/Feature/Commands/Operations/DoctorCommandContractTest.php`.
  Passed gate:
  `php artisan test --compact --filter='lets fix mode install missing tools'`.
- [-] Adopt action handlers — intentionally not implemented. `ToolsProbe` is
  DB-driven (per-row introspection via `NodeTool` records) and does not scan
  the node for "extra" tools. This matches the old-repo design decision that
  there is no practical "list every installed tool" shell script across all
  tools (php, docker, caddy, node, gh, composer, etc.). Tool adoption is
  therefore out of scope for the clean rebuild.

## Foundations

- [x] `docs/abstractions/3_tool.md` exists.
- [x] `node_tools` schema/model/factory, `GET /api/tools`,
  `GET /api/tools/{tool}`, and typed Saloon list/show requests.
- [x] Tool API action endpoints scope the requested `--node` / `--app` target
  to the authenticated caller before invoking local gateway services. Coverage:
  `tests/Feature/Http/Api/ToolTargetAuthorizationControllerTest.php`.
