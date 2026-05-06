# 3_tool — Tool Workstream

Detail file for the tool command family. Top-level status lives in
[`PORTING.md`](PORTING.md). Doc authority: `docs/commands/3_tool/`.

## Commands

- [x] `tool:list` — gateway-local + Saloon forwarding for non-gateway
  callers. `lane=none` (registry read).
- [x] `tool:show` — gateway-local + Saloon forwarding. `lane=none`.
- [ ] `tool:install`, `tool:remove`, `tool:start`, `tool:stop`,
  `tool:restart`, `tool:logs`, `tool:update`, `tool:credentials`,
  `tool:reload`, `tool:reconfigure` — write/enactment commands not started.

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
  E2E close-out remains blocked until `tests/E2E/Ephemeral/ToolsDoctorFixTest.php`
  can run the destructive repair path against a disposable provisioned node.
- [!] Capability fix handlers and adopt action handlers are outstanding. Next:
  define scoped adopt behavior for selected observed tool reality, or add
  capability fix once catalog definitions declare safe install/restore commands.

## Foundations

- [x] `docs/abstractions/3_tool.md` exists.
- [x] `node_tools` schema/model/factory, `GET /api/tools`,
  `GET /api/tools/{tool}`, and typed Saloon list/show requests.
