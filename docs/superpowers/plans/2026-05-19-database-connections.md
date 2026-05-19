# Database Connections Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add `orbit database:*` as the command family for app/workspace database connection inventory, env convergence, schema inspection, and audited SQL execution.

**Architecture:** Introduce a new `database_connection` state family. Gateway state owns reusable database connection records; apps and workspaces map to those records through a pivot with env-prefix metadata. Doctor compares gateway state to node `.env` reality, `--restore` writes gateway values to `.env`, and `--adopt` records observed `.env` values back into gateway state.

**Tech Stack:** Laravel 13, SQLite gateway DB, encrypted Eloquent casts, RemoteShell, typed gateway API, Pest, Laravel DB/PDO dynamic runtime connections.

---

## Current Decisions

- Public command prefix: `orbit database:*`.
- Doctor family key: `database_connection` to match Orbit's singular state-family convention.
- Storage:
  - `database_connections` stores reusable connection profiles.
  - `database_connection_targets` maps one connection to an app or workspace.
- Connection slugs are globally unique and editable.
- Default slug suggestions:
  - app default: `<app-slug>`
  - workspace default: `<workspace-slug>-<app-slug>`
  - extra app DB: `<app-slug>-<purpose>`
  - extra workspace DB: `<workspace-slug>-<app-slug>-<purpose>`
- Slug convention is creation help only. Doctor never flags a slug because it diverges from app/workspace names.
- Pivot stores env mapping metadata only: target, connection, `env_prefix`.
- Env mapping is prefix-based in v1:
  - `DB` maps `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`.
  - `ANALYTICS_DB` maps `ANALYTICS_DB_CONNECTION`, `ANALYTICS_DB_HOST`, etc.
- Passwords live in encrypted `credentials`, matching `NodeTool::credentials`.
- `database_connection` is adoptable. `doctor --adopt --family=database_connection` may create or update gateway connection state from observed `.env` reality.
- Existing registered apps and workspaces must enter the new family through adoption. The first rollout path is `doctor --adopt --family=database_connection`, which reads their current `.env` files and materializes matching gateway connection records plus app/workspace target rows.
- SQLite executes on the node that owns the database file through RemoteShell and an internal local command. MySQL/PostgreSQL execute from the gateway when reachable over the Orbit network.
- Query output is strict JSON for LLM use. Human mode may render summaries for non-query commands, but query/schema commands should keep machine-readable JSON as the primary path.

## Files And Responsibilities

- Modify `docs/architecture.md`: add `database_connection` state family.
- Modify `docs/concepts.md`: add database connection concepts and family key.
- Modify `docs/domains/3_tool/README.md`: replace future `db:*` wording with `database:*`.
- Create `docs/domains/18_database/README.md`: database command domain.
- Create `docs/domains/18_database/database-concepts.md`: product concepts.
- Create `docs/domains/18_database/database-doctor.md`: probe facts, issue codes, restore/adopt maps.
- Create command docs under `docs/domains/18_database/*`: contracts for `database:list`, `database:show`, `database:add`, `database:update`, `database:remove`, `database:attach`, `database:detach`, `database:query`, `database:tables`, `database:schema`, `database:describe`.
- Create `database/migrations/*_create_database_connections_table.php`.
- Create `database/migrations/*_create_database_connection_targets_table.php`.
- Create `app/Models/DatabaseConnection.php`.
- Create `app/Models/DatabaseConnectionTarget.php`.
- Modify `app/Models/App.php` and `app/Models/Workspace.php`: relationships.
- Create `app/Services/DatabaseConnections/DatabaseConnectionEnvMapper.php`: prefix to env keys.
- Create `app/Services/DatabaseConnections/EnvFileEditor.php`: parse and rewrite `.env` content safely.
- Create `app/Services/DatabaseConnections/DatabaseConnectionPayload.php`: normalized connection data shape.
- Create `app/Services/DatabaseConnections/DatabaseConnectionRegistry.php`: CRUD and target mapping operations.
- Create `app/Services/DatabaseConnections/DatabaseConnectionProbe.php`: read remote `.env` reality and diff against gateway intent.
- Create `app/Services/DatabaseConnections/DatabaseConnectionRestorer.php`: write gateway intent to `.env`.
- Create `app/Services/DatabaseConnections/DatabaseConnectionAdopter.php`: write observed `.env` reality to gateway state.
- Create `app/Services/DatabaseConnections/DatabaseQueryClassifier.php`: readonly/write classification.
- Create `app/Services/DatabaseConnections/DatabaseQueryRunner.php`: dynamic Laravel/PDO execution.
- Create `app/Services/DatabaseConnections/DatabaseSchemaInspector.php`: tables/schema/describe.
- Create `app/Console/Commands/Database*.php`: public and internal command surfaces.
- Create typed API controllers, requests, and responses under `app/Http/Controllers/Api`, `app/Http/Gateway/Requests/Database`, and `app/Http/Gateway/Responses/Database`.
- Modify `app/Services/Doctor/DoctorReportRunner.php`: add family support, probe, restore, adopt.
- Modify `app/Services/Doctor/DoctorScopeValidator.php` only if family category rules need validation changes.
- Modify `routes/api.php`: add typed database endpoints.
- Create tests under `tests/Unit/Services/DatabaseConnections`.
- Create tests under `tests/Feature/Commands/Database`.
- Create tests under `tests/Feature/Http/Api/Database`.
- Modify `tests/Feature/Commands/Operations/DoctorCommandContractTest.php` and `DoctorRoleAwareCategoriesTest.php`.
- Modify docs linter fixtures/rules only if a new domain number or family key needs registration.

---

## Phase 1: Product Contract

### Task 1: Add Database Domain Docs

**Files:**
- Modify: `docs/architecture.md`
- Modify: `docs/concepts.md`
- Modify: `docs/domains/3_tool/README.md`
- Create: `docs/domains/18_database/README.md`
- Create: `docs/domains/18_database/database-concepts.md`
- Create: `docs/domains/18_database/database-doctor.md`

- [ ] Update `docs/architecture.md` state-family table with `database_connection`.
- [ ] Update `docs/concepts.md` product families and concept index.
- [ ] Replace `db:*` future wording in `docs/domains/3_tool/README.md` with `database:*`.
- [ ] Write database concepts: connection, connection target, env prefix, restore, adopt, query execution, SQLite locality.
- [ ] Write `database-doctor.md` with issue codes:
  - `database_connection.env_missing`
  - `database_connection.env_mismatch`
  - `database_connection.env_extra`
  - `database_connection.target_missing`
  - `database_connection.target_extra`
  - `database_connection.unverifiable`
- [ ] Define restore behavior:
  - write stored gateway connection values into selected app/workspace `.env`;
  - preserve unrelated `.env` keys;
  - never delete unrelated database-looking keys without an explicit target mapping.
- [ ] Define adopt behavior:
  - parse observed `.env` values by supported prefixes;
  - create or update `database_connections`;
  - create or update `database_connection_targets`;
  - explicitly support adopting database connection state for existing registered apps and workspaces during rollout;
  - store adopted passwords in encrypted credentials.
- [ ] Run `composer docs-lint`.
- [ ] Commit docs: `docs: add database command contract`.

### Task 2: Add Command Contracts

**Files:**
- Create command directories under `docs/domains/18_database/`.

- [ ] Document `database:list [--app=<app>] [--workspace=<workspace>] [--node=<node>] [--json]`.
- [ ] Document `database:show {connection} [--json]`.
- [ ] Document `database:add {slug} --driver=<mysql|pgsql|sqlite> ... [--json]`.
- [ ] Document `database:update {connection} ... [--json]`.
- [ ] Document `database:remove {connection} --force [--json]`.
- [ ] Document `database:attach {connection} (--app=<app>|--workspace=<workspace>) [--env-prefix=DB] [--json]`.
- [ ] Document `database:detach {connection} (--app=<app>|--workspace=<workspace>) [--env-prefix=DB] [--json]`.
- [ ] Document `database:query {target} --sql=<sql> [--connection=<slug>] [--limit=50] [--full] [--write] [--json]`.
- [ ] Document `database:tables`, `database:schema`, and `database:describe`.
- [ ] Document internal `database:query-local` as hidden/internal, stdin-payload only, strict JSON only.
- [ ] Include activity logging contracts with no raw password, no raw result rows in activity properties.
- [ ] Run `composer docs-lint`.
- [ ] Commit docs: `docs: define database commands`.

---

## Phase 2: Schema And Models

### Task 3: Add Database Connection Tables

**Files:**
- Create: `database/migrations/*_create_database_connections_table.php`
- Create: `database/migrations/*_create_database_connection_targets_table.php`
- Test: `tests/Feature/Database/DatabaseConnectionSchemaTest.php`

- [ ] Write a failing schema test for columns, casts, uniqueness, and cascade behavior.
- [ ] Create `database_connections`:
  - `id`
  - `node_id` nullable FK to `nodes`
  - `slug` unique
  - `driver`
  - `host` nullable
  - `port` nullable integer
  - `database` nullable
  - `path` nullable for SQLite
  - `username` nullable
  - `credentials` nullable text for encrypted array
  - timestamps
- [ ] Create `database_connection_targets`:
  - `id`
  - `database_connection_id` FK cascade
  - `app_id` nullable FK cascade
  - `workspace_id` nullable FK cascade
  - `env_prefix`
  - timestamps
  - unique `app_id, env_prefix` when `app_id` is present
  - unique `workspace_id, env_prefix` when `workspace_id` is present
- [ ] Enforce exactly one of `app_id` or `workspace_id` in model validation/service code.
- [ ] Run `php artisan test --compact tests/Feature/Database/DatabaseConnectionSchemaTest.php`.
- [ ] Commit schema: `feat: add database connection tables`.

### Task 4: Add Models And Relationships

**Files:**
- Create: `app/Models/DatabaseConnection.php`
- Create: `app/Models/DatabaseConnectionTarget.php`
- Modify: `app/Models/App.php`
- Modify: `app/Models/Workspace.php`
- Test: `tests/Feature/Models/DatabaseConnectionTest.php`

- [ ] Write failing model tests for encrypted credentials, app target relation, workspace target relation, and slug uniqueness.
- [ ] Add `DatabaseConnection` with `credentials => encrypted:array` cast.
- [ ] Add `DatabaseConnectionTarget` with `connection`, `app`, and `workspace` relations.
- [ ] Add app/workspace `databaseConnectionTargets()` and `databaseConnections()` relations.
- [ ] Run `php artisan test --compact tests/Feature/Models/DatabaseConnectionTest.php`.
- [ ] Commit models: `feat: model database connections`.

---

## Phase 3: Env Mapping And Doctor

### Task 5: Implement Env Mapping

**Files:**
- Create: `app/Services/DatabaseConnections/DatabaseConnectionEnvMapper.php`
- Create: `app/Services/DatabaseConnections/EnvFileEditor.php`
- Create: `app/Services/DatabaseConnections/DatabaseConnectionPayload.php`
- Test: `tests/Unit/Services/DatabaseConnections/DatabaseConnectionEnvMapperTest.php`
- Test: `tests/Unit/Services/DatabaseConnections/EnvFileEditorTest.php`

- [ ] Write failing tests for `DB` and `ANALYTICS_DB` prefixes.
- [ ] Mapper renders keys for driver, host, port, database, username, password, and path.
- [ ] Editor parses quoted and unquoted values.
- [ ] Editor updates existing keys and appends missing keys.
- [ ] Editor preserves unrelated keys and comments.
- [ ] Run `php artisan test --compact tests/Unit/Services/DatabaseConnections`.
- [ ] Commit env services: `feat: map database connections to env`.

### Task 6: Implement Probe, Restore, Adopt

**Files:**
- Create: `app/Services/DatabaseConnections/DatabaseConnectionProbe.php`
- Create: `app/Services/DatabaseConnections/DatabaseConnectionRestorer.php`
- Create: `app/Services/DatabaseConnections/DatabaseConnectionAdopter.php`
- Modify: `app/Services/Doctor/DoctorReportRunner.php`
- Modify: `app/Services/Doctor/DoctorScopeValidator.php`
- Test: `tests/Unit/Services/DatabaseConnections/DatabaseConnectionProbeTest.php`
- Test: `tests/Unit/Services/DatabaseConnections/DatabaseConnectionRestorerTest.php`
- Test: `tests/Unit/Services/DatabaseConnections/DatabaseConnectionAdopterTest.php`
- Test: `tests/Unit/Services/Doctor/DoctorReportRunnerTest.php`

- [ ] Probe reads app/workspace `.env` through `RemoteShell` for hosted targets and local filesystem for gateway-local targets.
- [ ] Probe reports missing, divergent, extra, and unverifiable issues using `database_connection.*` codes.
- [ ] Restore writes gateway values into the target `.env`.
- [ ] Adopt creates/updates connection rows and target rows from observed `.env`.
- [ ] Adopt covers current app and workspace records that predate the `database_connection` family.
- [ ] Adopt stores passwords in encrypted credentials.
- [ ] Add `database_connection` to supported doctor families and app-node category sets.
- [ ] Keep database-only nodes out unless they host app/workspace targets later.
- [ ] Run focused unit tests.
- [ ] Commit doctor services: `feat: add database connection doctor family`.

---

## Phase 4: Registry Commands And API

### Task 7: Implement Connection Registry Service

**Files:**
- Create: `app/Services/DatabaseConnections/DatabaseConnectionRegistry.php`
- Test: `tests/Unit/Services/DatabaseConnections/DatabaseConnectionRegistryTest.php`

- [ ] Add list/show/create/update/remove operations.
- [ ] Add attach/detach operations for app and workspace targets.
- [ ] Validate slug syntax with existing slug convention.
- [ ] Validate driver values: `mysql`, `pgsql`, `sqlite`.
- [ ] Validate SQLite requires `node_id` and `path`; TCP drivers require `host`, `port`, `database`, `username`.
- [ ] Run `php artisan test --compact tests/Unit/Services/DatabaseConnections/DatabaseConnectionRegistryTest.php`.
- [ ] Commit registry: `feat: manage database connection registry`.

### Task 8: Implement Registry Commands And Typed API

**Files:**
- Create: `app/Console/Commands/DatabaseListCommand.php`
- Create: `app/Console/Commands/DatabaseShowCommand.php`
- Create: `app/Console/Commands/DatabaseAddCommand.php`
- Create: `app/Console/Commands/DatabaseUpdateCommand.php`
- Create: `app/Console/Commands/DatabaseRemoveCommand.php`
- Create: `app/Console/Commands/DatabaseAttachCommand.php`
- Create: `app/Console/Commands/DatabaseDetachCommand.php`
- Create API request/response/controller classes under database namespaces.
- Modify: `routes/api.php`
- Test: `tests/Feature/Commands/Database/*`
- Test: `tests/Feature/Http/Api/Database/*`

- [ ] Commands follow gateway/local caller pattern used by `tool:*` and `app:*`.
- [ ] JSON envelope uses one top-level `success` or `error`.
- [ ] Human output never prints passwords except explicit credential-read paths if documented.
- [ ] `database:remove` requires `--force` in non-interactive mode.
- [ ] Activity properties include connection slug, target app/workspace, driver, and outcome only. No password.
- [ ] Run command and API tests.
- [ ] Commit commands: `feat: add database registry commands`.

---

## Phase 5: Query And Schema Commands

### Task 9: Implement Query Runner

**Files:**
- Create: `app/Services/DatabaseConnections/DatabaseQueryClassifier.php`
- Create: `app/Services/DatabaseConnections/DatabaseQueryRunner.php`
- Create: `app/Services/DatabaseConnections/DatabaseSchemaInspector.php`
- Create: `app/Console/Commands/Internal/DatabaseQueryLocalCommand.php`
- Test: `tests/Unit/Services/DatabaseConnections/DatabaseQueryClassifierTest.php`
- Test: `tests/Feature/Commands/Database/DatabaseQueryLocalCommandTest.php`

- [ ] Classifier allows readonly SQL by default.
- [ ] Writes require `--write`.
- [ ] Query runner builds dynamic Laravel database connections and purges them after use.
- [ ] Default row limit is 50.
- [ ] Hard limit without `--full` is 500.
- [ ] Hard limit with `--full` is 10000.
- [ ] Default timeout is 10 seconds.
- [ ] Default max JSON size is 1 MB.
- [ ] Local command accepts connection payload on stdin, never password argv.
- [ ] Local command writes strict JSON to stdout and diagnostics to stderr.
- [ ] Run query runner tests.
- [ ] Commit runner: `feat: execute database queries`.

### Task 10: Implement Public Query Commands

**Files:**
- Create: `app/Console/Commands/DatabaseQueryCommand.php`
- Create: `app/Console/Commands/DatabaseTablesCommand.php`
- Create: `app/Console/Commands/DatabaseSchemaCommand.php`
- Create: `app/Console/Commands/DatabaseDescribeCommand.php`
- Create typed API classes/controllers.
- Test: `tests/Feature/Commands/Database/DatabaseQueryCommandTest.php`
- Test: `tests/Feature/Commands/Database/DatabaseSchemaCommandTest.php`

- [ ] Resolve target as app, workspace, or connection slug.
- [ ] If target has one mapped connection, use it.
- [ ] If target has multiple and one has `env_prefix=DB`, use it.
- [ ] Otherwise require `--connection=<slug>`.
- [ ] MySQL/PostgreSQL execute centrally through Laravel DB/PDO.
- [ ] SQLite executes on the owning node through RemoteShell calling hidden `database:query-local`.
- [ ] Return compact JSON by default with truncation metadata.
- [ ] Return write JSON with affected row count and audit id.
- [ ] Reject mixed stdout logs.
- [ ] Run command/API tests.
- [ ] Commit query commands: `feat: add database query commands`.

---

## Phase 6: Audit, Docs, And Verification

### Task 11: Wire Activity And Audit Detail

**Files:**
- Modify command classes from Phase 4 and Phase 5.
- Possibly create: `app/Services/DatabaseConnections/DatabaseAuditPayload.php`
- Test: `tests/Feature/Commands/Database/DatabaseActivityLogTest.php`

- [ ] Log actor, app/workspace/connection slug, node, driver, query type, mode, row counts, truncation, execution time, exit status.
- [ ] Do not log passwords or full result rows.
- [ ] For SQL text, log raw SQL only if product docs explicitly allow it. Otherwise log hash plus normalized query type.
- [ ] Run activity tests.
- [ ] Commit audit: `feat: audit database operations`.

### Task 12: Final Verification

**Files:**
- All touched files.

- [ ] Run `composer docs-lint`.
- [ ] Run focused database tests:
  ```bash
  php artisan test --compact tests/Unit/Services/DatabaseConnections tests/Feature/Commands/Database tests/Feature/Http/Api/Database
  ```
- [ ] Run doctor contract tests:
  ```bash
  php artisan test --compact tests/Feature/Commands/Operations/DoctorCommandContractTest.php tests/Feature/Commands/Operations/DoctorRoleAwareCategoriesTest.php tests/Unit/Services/Doctor/DoctorReportRunnerTest.php
  ```
- [ ] Run style on touched files:
  ```bash
  vendor/bin/pint --dirty --format agent
  ```
- [ ] Run broad check if focused tests pass:
  ```bash
  composer quality-check
  ```
- [ ] Add an E2E smoke if schema/query commands touch real remote execution paths:
  ```bash
  composer test:live
  ```
- [ ] Commit final fixes: `chore: verify database commands`.

---

## Out Of Scope

- Creating or dropping physical MySQL/PostgreSQL databases.
- Backup/restore workflows.
- Arbitrary env key maps beyond prefix-based mapping.
- SSH tunneling for SQLite.
- External `mysql`, `psql`, or `sqlite3` client binaries.
- Per-driver command families such as `mysql:*` or `postgres:*`.

## Risks

- Adopting passwords from `.env` is intentionally powerful; activity logs must not leak secret values.
- Remote `.env` mutation must preserve unrelated keys.
- SQLite query execution needs a stdin-payload internal command so passwords and paths do not appear in argv.
- `database_connection` as a new doctor family touches global category validation and docs linting.

## Open Questions

- Should raw SQL be stored in activity properties, or should audit store only query type plus a hash? Recommendation: store full SQL in a dedicated database audit record only if access controls make it safe; keep activity properties redacted.
- Should first implementation include `database:remove`, or defer deletion until list/show/add/update/attach/detach/query are stable? Recommendation: include it because stale credentials need an explicit cleanup path.

## Self-Review

- Spec coverage: command prefix, reusable connections, pivot env prefix, doctor restore/adopt, SQLite locality, JSON query output, limits, readonly default, and audit are covered.
- Placeholder scan: no placeholder markers or unowned implementation steps remain.
- Type consistency: family key is `database_connection`; table names are `database_connections` and `database_connection_targets`; command domain is `database`.
