# Gateway CLI Monorepo Local Executor Implementation Plan

> **E2E Docker contract note:** This historical plan contains older examples
> for preparing individual Docker topology kinds. Current Docker E2E authority
> is `docs/testing/e2e/docker.md` and
> `docs/testing/e2e/prepared-topologies.md`: prepare composable role images,
> not per-topology image families.

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Split Orbit into a full Laravel gateway app, a Laravel Zero CLI/local-executor app, and a narrow shared core package while preserving the gateway-owned authority path and removing host `python3`/`sqlite3` helper dependencies.

**Architecture:** Every machine keeps a full `~/orbit` monorepo checkout. The host `orbit` launcher points to `apps/gateway` on the gateway and `apps/cli` on clients/workload nodes. Public CLI commands call the gateway API; gateway-dispatched node work uses token-gated internal CLI executor commands over `RemoteShell`.

**Tech Stack:** PHP 8.5 host CLI, Laravel 13 gateway, Laravel Zero CLI, Composer path repositories, Spatie package-skeleton-laravel style `packages/core`, PDO SQLite, existing `RemoteShell`, Docker/Incus prepared E2E lanes, Pest 4.

---

## Status

**Supersedes:** `docs/superpowers/plans/2026-05-24-rust-host-agent-control-plane.md`

**Source context:**

- `solo://proj/2/scratchpad/orbit-post-ingress-o--309`
- `solo://proj/2/todo/--341`
- `solo://proj/2/todo/--362`
- `solo://proj/2/todo/--363`
- `solo://proj/2/todo/--364`
- `solo://proj/2/todo/--365`
- `solo://proj/2/todo/--366`
- `docs/architecture.md`
- `docs/execution-lanes.md`
- `docs/testing/README.md`
- `docs/superpowers/plans/2026-05-21-docker-first-orbit-runtime.md`

**Current conflict:** ORBIT-EXEC-01/02 landed a two-lane model:
`RemoteHostExecutor` and `RemoteOrbitRuntimeExecutor`. Open blockers
ORBIT-EXEC-03/04/05 currently target `RemoteOrbitRuntimeExecutor` for
workspace adapter and wg-easy SQLite work. This plan changes that target for
node-local packaged helpers to a third lane: `RemoteLocalExecutor`.

**Execution rule:** Do not implement ORBIT-EXEC-03/04/05 from their current
bodies before Task 1 updates the contract and Solo comments. The current bodies
push adapter DB work into `orbit-runtime`; this plan moves it into a
gateway-dispatched host CLI executor.

**E2E rule:** Prefer prepared Docker and Incus E2E over standing live infra.
Live infra may be used for diagnosis or operator confidence, but completion
requires repeatable E2E evidence unless a precise environment blocker is
recorded in Solo.

## Decisions

1. **Full checkout everywhere**
   Every node keeps the full `~/orbit` monorepo. Do not introduce sparse
   checkout or copied app-only trees in the first implementation.

2. **Role-specific host launcher**
   `/usr/local/bin/orbit` is a wrapper. On the gateway it runs
   `~/orbit/apps/gateway`. On clients and workload nodes it runs
   `~/orbit/apps/cli`.

3. **Host PHP remains required in phase one**
   Host PHP CLI is required for `apps/cli` and the gateway host launcher.
   This is CLI/executor substrate, not a host app runtime fallback. App and
   workspace PHP still run in FrankenPHP containers.

4. **Bundled CLI is deferred**
   PHAR or PHPacker binaries are a distribution optimization after the
   monorepo split is stable. They are not part of this plan.

5. **Gateway remains the writer**
   Public CLI commands never write node or fleet state directly. They call the
   gateway API. The gateway authorizes, records, dispatches, and records the
   result.

6. **Local executor is internal**
   Internal executor commands are hidden from normal CLI help and require a
   gateway-issued operation token. Direct user invocation must fail before
   side effects.

7. **Core stays boring**
   `packages/core` contains contracts, DTOs, enums, token helpers, and JSON
   envelope utilities. It does not contain Eloquent models, gateway
   orchestration, `RemoteShell`, controllers, terminal UI, or product workflows.

8. **Prepared E2E is the primary proof**
   Docker/Incus topologies provide Orbit-capable host environments. The current
   checkout is synced into the topology during preparation or test setup; the
   topology images must not bake a stale Orbit version.

## Stop Conditions

- Any public CLI command mutates node or gateway state without a gateway API
  authorization path.
- Any internal executor command can mutate state without validating a
  gateway-issued operation token.
- `packages/core` starts depending on the gateway app, Eloquent models,
  controllers, or `RemoteShell`.
- Host PHP is documented or implemented as an app/workspace runtime fallback.
- Docker E2E blockers are "fixed" by adding host `python3` or host `sqlite3`
  as steady-state Orbit helper dependencies.
- The plan is implemented only against standing live infra without Docker or
  Incus E2E coverage.

## File Map

### Product Docs And Coordination

- Modify: `docs/architecture.md`
- Modify: `docs/tech-stack.md`
- Modify: `docs/concepts.md`
- Modify: `docs/execution-lanes.md`
- Modify: `docs/testing/README.md`
- Modify: `docs/porting/testing-infrastructure.md`
- Coordinate: `solo://proj/2/todo/--341`
- Coordinate: `solo://proj/2/todo/--364`
- Coordinate: `solo://proj/2/todo/--365`
- Coordinate: `solo://proj/2/todo/--366`

### Monorepo Layout

- Modify: `composer.json`
- Create: `apps/gateway/composer.json`
- Move: `app/` to `apps/gateway/app/`
- Move: `bootstrap/` to `apps/gateway/bootstrap/`
- Move: `config/` to `apps/gateway/config/`
- Move: `database/` to `apps/gateway/database/`
- Move: `routes/` to `apps/gateway/routes/`
- Move: `resources/` to `apps/gateway/resources/`
- Move: `tests/` to `apps/gateway/tests/`
- Move: `artisan` to `apps/gateway/artisan`
- Create: root compatibility scripts for development commands if needed.

### Core Package

- Create: `packages/core/composer.json`
- Create: `packages/core/src/OrbitCoreServiceProvider.php`
- Create: `packages/core/src/Enums/ExecutionLane.php`
- Create: `packages/core/src/Enums/OperationStatus.php`
- Create: `packages/core/src/Http/JsonEnvelope.php`
- Create: `packages/core/src/Security/OperationToken.php`
- Create: `packages/core/src/Security/OperationTokenSigner.php`
- Create: `packages/core/src/Security/OperationTokenVerifier.php`
- Create: `packages/core/tests/OperationTokenTest.php`
- Create: `packages/core/tests/JsonEnvelopeTest.php`

### CLI App

- Create: `apps/cli/composer.json`
- Create: `apps/cli/orbit`
- Create: `apps/cli/bootstrap/app.php`
- Create: `apps/cli/config/commands.php`
- Create: `apps/cli/config/orbit.php`
- Create: `apps/cli/app/Commands/OrbitCommand.php`
- Create: `apps/cli/app/Commands/Internal/VerifyExecutorCommand.php`
- Create: `apps/cli/app/Commands/Internal/WorkspaceAdapterLookupCommand.php`
- Create: `apps/cli/app/Commands/Internal/WgEasyStateCommand.php`
- Create: `apps/cli/app/Services/GatewayApiClient.php`
- Create: `apps/cli/app/Services/Executor/OperationTokenGuard.php`
- Create: `apps/cli/tests/Feature/InternalExecutorCommandTest.php`
- Create: `apps/cli/tests/Feature/PublicCommandForwardingTest.php`

### Gateway Executor Integration

- Create: `apps/gateway/app/Services/RemoteShell/RemoteLocalExecutor.php`
- Create: `apps/gateway/app/Services/RemoteShell/LocalExecutorCommandBuilder.php`
- Create: `apps/gateway/app/Services/Operations/OperationTokenFactory.php`
- Modify: `apps/gateway/app/Providers/AppServiceProvider.php`
- Modify: `apps/gateway/app/Services/Workspaces/PolyscopeWorkspaceBranchAligner.php`
- Modify: `apps/gateway/app/Services/Workspaces/PolyscopeWorkspaceDriver.php`
- Modify: `apps/gateway/app/Services/AgentIde/CoreAgentIdeWorkspacePathResolver.php`
- Modify: `apps/gateway/app/Services/Vpn/WgEasyVpnBackend.php`
- Modify: `apps/gateway/app/Services/Vpn/WgEasyServiceInstaller.php`
- Modify: `apps/gateway/app/Console/Commands/VpnCommandSupport.php`

### Installers, Launcher, And E2E

- Modify: `bin/orbit`
- Modify: `bin/install-orbit`
- Modify: `app/E2E/Support/DockerTopologyBuilder.php` or moved gateway path
- Modify: `app/E2E/Support/E2ECommand.php` or moved gateway path
- Modify: `docker/e2e/topology/Dockerfile`
- Modify: `docker/e2e/topology/Dockerfile.dockerignore`
- Modify: `tests/E2E/WorkspaceSetupTest.php` or moved gateway path
- Modify: `tests/Feature/E2ESupport/E2EWgEasyGatewayTest.php` or moved gateway path
- Modify: database E2E fixture tests that currently shell to host `sqlite3`.

## Implementation Tasks

### Task 1: Realign Product Contract And Solo Blockers

**Files:**
- Modify: `docs/architecture.md`
- Modify: `docs/tech-stack.md`
- Modify: `docs/concepts.md`
- Modify: `docs/execution-lanes.md`
- Modify: `docs/testing/README.md`
- Coordinate: `solo://proj/2/todo/--341`
- Coordinate: `solo://proj/2/todo/--364`
- Coordinate: `solo://proj/2/todo/--365`
- Coordinate: `solo://proj/2/todo/--366`

- [ ] **Step 1: Update execution-lanes contract**

  Add `RemoteLocalExecutor` beside the existing lanes:

  ```text
  RemoteHostExecutor:
    SSH host substrate, bootstrap, Docker/container control, host git,
    system packages, WireGuard host mutation, Caddy artifacts.

  RemoteOrbitRuntimeExecutor:
    SSH then docker exec into orbit-runtime for gateway Laravel/artisan/PDO
    work that belongs to the gateway runtime container.

  RemoteLocalExecutor:
    SSH then invoke the host-installed apps/cli internal executor command.
    It is for packaged node-local helper logic that needs host file access
    and PHP/PDO without relying on ad hoc python3/sqlite3 snippets.
  ```

- [ ] **Step 2: State the authority path**

  In `docs/architecture.md`, keep the current invariant:

  ```text
  CLI caller -> gateway API -> gateway authorization -> operation record
    -> RemoteShell to node -> token-gated local executor -> result recorded
  ```

  State that node-local CLI execution is never an authority bypass.

- [ ] **Step 3: Update host prerequisite wording**

  In `docs/tech-stack.md`, document host PHP CLI as:

  ```text
  Host PHP CLI is required for the Orbit CLI/local-executor artifact in the
  source-checkout distribution. Host PHP is not an app/workspace runtime
  fallback and must not replace FrankenPHP app/workspace containers.
  ```

- [ ] **Step 4: Update testing contract**

  In `docs/testing/README.md`, state that prepared Docker/Incus E2E is the primary
  verification path for this work. Standing live infra is diagnostic only.
  Prepared topology images provide Orbit-capable hosts; the current checkout
  is synced into those hosts during topology preparation or test setup.

- [ ] **Step 5: Add Solo comments before implementation**

  Add comments to ORBIT-EXEC-03/04/05 explaining that their target lane changes
  from `RemoteOrbitRuntimeExecutor` to `RemoteLocalExecutor`. Add a comment to
  ORBIT-RUNTIME-11 that it remains blocked by those migrations, but the planned
  fix is the local executor, not runtime-container DB mounts.

- [ ] **Step 6: Verify docs**

  Run:

  ```bash
  composer docs-lint
  ```

  Expected: `issues: 0`, `errors: 0`, `warnings: 0`.

- [ ] **Step 7: Commit**

  ```bash
  git add docs/architecture.md docs/tech-stack.md docs/concepts.md docs/execution-lanes.md docs/testing/README.md
  git commit -m "docs: add local executor lane"
  ```

### Task 2: Create Core Package Skeleton

**Files:**
- Modify: `composer.json`
- Create: `packages/core/composer.json`
- Create: `packages/core/src/OrbitCoreServiceProvider.php`
- Create: `packages/core/src/Enums/ExecutionLane.php`
- Create: `packages/core/src/Enums/OperationStatus.php`
- Create: `packages/core/src/Http/JsonEnvelope.php`
- Create: `packages/core/src/Security/OperationToken.php`
- Create: `packages/core/src/Security/OperationTokenSigner.php`
- Create: `packages/core/src/Security/OperationTokenVerifier.php`
- Create: `packages/core/tests/OperationTokenTest.php`
- Create: `packages/core/tests/JsonEnvelopeTest.php`

- [ ] **Step 1: Scaffold from Spatie package shape**

  Use `spatie/package-skeleton-laravel` as the structural model: package
  `composer.json`, `src/*ServiceProvider.php`, `tests`, Pint/PHPStan compatible
  structure. Name the package `hardimpactdev/orbit-core`.

- [ ] **Step 2: Add path repository**

  Add a root Composer path repository:

  ```json
  {
      "type": "path",
      "url": "packages/core",
      "options": {
          "symlink": true
      }
  }
  ```

  Require it from the gateway and CLI apps as `hardimpactdev/orbit-core:dev-main`.

- [ ] **Step 3: Add core enums**

  `ExecutionLane` cases:

  ```php
  Host
  OrbitRuntime
  LocalExecutor
  ```

  `OperationStatus` cases:

  ```php
  Queued
  Running
  Succeeded
  Failed
  Expired
  Rejected
  ```

- [ ] **Step 4: Add JSON envelope helper**

  `JsonEnvelope` exposes:

  ```php
  success(array $data = [], array $meta = []): array
  failure(string $code, string $message, array $meta = []): array
  ```

  Both methods return stable arrays with `ok`, `data` or `error`, and `meta`.

- [ ] **Step 5: Add operation token value object**

  `OperationToken` stores:

  ```text
  id
  node
  command
  issued_at
  expires_at
  signature
  ```

  It must serialize to a compact string and parse back to the same fields.

- [ ] **Step 6: Test core contracts**

  Run:

  ```bash
  cd packages/core
  composer test
  ```

  Expected: package tests pass.

- [ ] **Step 7: Commit**

  ```bash
  git add composer.json packages/core
  git commit -m "feat: add orbit core package"
  ```

### Task 3: Add Laravel Zero CLI App

**Files:**
- Create: `apps/cli/composer.json`
- Create: `apps/cli/orbit`
- Create: `apps/cli/bootstrap/app.php`
- Create: `apps/cli/config/commands.php`
- Create: `apps/cli/config/orbit.php`
- Create: `apps/cli/app/Commands/OrbitCommand.php`
- Create: `apps/cli/app/Commands/GatewayStatusCommand.php`
- Create: `apps/cli/app/Commands/Internal/VerifyExecutorCommand.php`
- Create: `apps/cli/app/Services/GatewayApiClient.php`
- Create: `apps/cli/app/Services/Executor/OperationTokenGuard.php`
- Create: `apps/cli/tests/Feature/PublicCommandForwardingTest.php`
- Create: `apps/cli/tests/Feature/InternalExecutorCommandTest.php`

- [ ] **Step 1: Create Laravel Zero app**

  Create `apps/cli` as a Laravel Zero application. Use PHP `^8.4`, Laravel Zero
  compatible with the current Laravel ecosystem, and require
  `hardimpactdev/orbit-core` through the path repository.

- [ ] **Step 2: Add public command base**

  `OrbitCommand` must provide:

  ```text
  --json handling
  gateway API client access
  common failure rendering
  no direct state mutation helpers
  ```

- [ ] **Step 3: Add gateway status command**

  Add a small public command that calls the gateway API and renders the
  response. This proves the CLI app can be installed and tested without moving
  all product commands at once.

- [ ] **Step 4: Add hidden internal verification command**

  Add `internal:executor:verify --operation-token=... --json`.
  It must validate the operation token before returning success. Invalid,
  missing, expired, or wrong-node tokens return a failure envelope and a
  non-zero exit code.

- [ ] **Step 5: Test public and internal behavior**

  Run:

  ```bash
  cd apps/cli
  php orbit test tests/Feature/PublicCommandForwardingTest.php --compact
  php orbit test tests/Feature/InternalExecutorCommandTest.php --compact
  ```

  Expected: public command calls the fake gateway API client; internal command
  rejects missing tokens and accepts a signed test token.

- [ ] **Step 6: Commit**

  ```bash
  git add apps/cli
  git commit -m "feat: add orbit cli app"
  ```

### Task 4: Add Role-Specific Host Launcher

**Files:**
- Modify: `bin/orbit`
- Modify: `bin/install-orbit`
- Test: `tests/Feature/InstallOrbitLauncherTest.php`

- [ ] **Step 1: Add launcher contract tests**

  Test that the installer writes a wrapper targeting:

  ```text
  /home/orbit/orbit/apps/gateway on gateway nodes
  /home/orbit/orbit/apps/cli on non-gateway nodes
  ```

  The wrapper must set:

  ```text
  ORBIT_REPO=/home/orbit/orbit
  ORBIT_APP=gateway or cli
  ORBIT_HOST_CWD=$PWD
  ```

- [ ] **Step 2: Update launcher**

  Replace the current Docker-only `bin/orbit` assumption with a role-aware
  launcher. Gateway mode may still enter `orbit-runtime` for public gateway
  commands. CLI mode runs host PHP against `apps/cli/orbit`.

- [ ] **Step 3: Update installer**

  `bin/install-orbit` must install host PHP CLI and required extensions for
  `apps/cli`: `pdo_sqlite`, `openssl`, `curl`, `mbstring`, and JSON support.
  Do not install host `python3` or host `sqlite3` as Orbit helper
  dependencies.

- [ ] **Step 4: Verify launcher tests**

  Run:

  ```bash
  php artisan test --compact tests/Feature/InstallOrbitLauncherTest.php
  ```

- [ ] **Step 5: Commit**

  ```bash
  git add bin/orbit bin/install-orbit tests/Feature/InstallOrbitLauncherTest.php
  git commit -m "feat: add role-aware orbit launcher"
  ```

### Task 5: Add Gateway Operation Tokens

**Files:**
- Create: `app/Services/Operations/OperationTokenFactory.php`
- Create: `tests/Unit/Services/Operations/OperationTokenFactoryTest.php`
- Modify: `config/orbit.php`

- [ ] **Step 1: Write token factory tests**

  Assert generated tokens include:

  ```text
  operation id
  target node name/id
  internal command name
  issued timestamp
  expiry timestamp
  signature
  ```

  Assert wrong command, wrong node, expired token, and modified payload fail
  verification.

- [ ] **Step 2: Implement token factory**

  Use the core `OperationTokenSigner` and a gateway secret from config. The
  default TTL should be short, for example 120 seconds.

- [ ] **Step 3: Verify**

  Run:

  ```bash
  php artisan test --compact tests/Unit/Services/Operations/OperationTokenFactoryTest.php
  ```

- [ ] **Step 4: Commit**

  ```bash
  git add app/Services/Operations/OperationTokenFactory.php tests/Unit/Services/Operations/OperationTokenFactoryTest.php config/orbit.php
  git commit -m "feat: add operation tokens"
  ```

### Task 6: Add RemoteLocalExecutor

**Files:**
- Create: `app/Services/RemoteShell/RemoteLocalExecutor.php`
- Create: `app/Services/RemoteShell/LocalExecutorCommandBuilder.php`
- Create: `tests/Unit/Services/RemoteShell/RemoteLocalExecutorTest.php`
- Modify: `app/Providers/AppServiceProvider.php`

- [ ] **Step 1: Write executor tests**

  Assert `RemoteLocalExecutor` builds SSH scripts like:

  ```text
  orbit internal:executor:verify --operation-token='<token>' --json
  ```

  Assert it does not wrap commands in `docker exec orbit-runtime`.

- [ ] **Step 2: Preserve default binding**

  `RemoteShell` and `RemoteExecutor` must still default to `RemoteHostExecutor`
  until call sites explicitly request another lane.

- [ ] **Step 3: Implement builder**

  `LocalExecutorCommandBuilder` accepts:

  ```text
  Node
  command name
  scalar arguments
  scalar options
  operation token
  ```

  It returns a safely escaped command string for `RemoteShell`.

- [ ] **Step 4: Verify**

  Run:

  ```bash
  php artisan test --compact tests/Unit/Services/RemoteShell
  ```

- [ ] **Step 5: Commit**

  ```bash
  git add app/Services/RemoteShell/RemoteLocalExecutor.php app/Services/RemoteShell/LocalExecutorCommandBuilder.php tests/Unit/Services/RemoteShell/RemoteLocalExecutorTest.php app/Providers/AppServiceProvider.php
  git commit -m "feat: add remote local executor"
  ```

### Task 7: Migrate Workspace Adapter DB Helpers

**Files:**
- Create: `apps/cli/app/Commands/Internal/WorkspaceAdapterLookupCommand.php`
- Modify: `app/Services/AgentIde/CoreAgentIdeWorkspacePathResolver.php`
- Modify: `app/Services/Workspaces/PolyscopeWorkspaceDriver.php`
- Modify: `app/Services/Workspaces/PolyscopeWorkspaceBranchAligner.php`
- Test: `tests/Unit/Services/AgentIde/CoreAgentIdeWorkspacePathResolverTest.php`
- Test: `tests/Unit/Services/Workspaces/PolyscopeWorkspaceBranchAlignerTest.php`
- Test: `tests/E2E/WorkspaceSetupTest.php`

- [ ] **Step 1: Write failing script assertions**

  Assert generated remote scripts contain `orbit internal:workspace-adapter`
  and do not contain:

  ```text
  python3
  python
  sqlite3
  php -r
  ```

- [ ] **Step 2: Implement CLI internal command**

  Add `internal:workspace-adapter lookup --adapter=polyscope|opencode`.
  It reads:

  ```text
  ~/.polyscope/polyscope.db
  ~/.local/share/opencode/opencode.db
  ```

  using PHP PDO SQLite from the host CLI runtime. It returns the same JSON data
  shape the current Python helpers print.

- [ ] **Step 3: Route gateway call sites**

  Replace the three Python heredocs with `RemoteLocalExecutor` calls:

  ```text
  CoreAgentIdeWorkspacePathResolver
  PolyscopeWorkspaceDriver
  PolyscopeWorkspaceBranchAligner
  ```

  Keep host git branch rename work in `RemoteHostExecutor`.

- [ ] **Step 4: Verify unit and E2E canary**

  Run:

  ```bash
  php artisan test --compact tests/Unit/Services/Workspaces tests/Unit/Services/AgentIde
  php artisan test --compact tests/E2E/WorkspaceSetupTest.php
  vendor/bin/pint --dirty --format agent
  ```

- [ ] **Step 5: Commit**

  ```bash
  git add apps/cli/app/Commands/Internal/WorkspaceAdapterLookupCommand.php app/Services/AgentIde/CoreAgentIdeWorkspacePathResolver.php app/Services/Workspaces/PolyscopeWorkspaceDriver.php app/Services/Workspaces/PolyscopeWorkspaceBranchAligner.php tests/Unit/Services/AgentIde tests/Unit/Services/Workspaces tests/E2E/WorkspaceSetupTest.php
  git commit -m "feat: migrate workspace adapters to local executor"
  ```

### Task 8: Migrate Wg-Easy SQLite Helpers

**Files:**
- Create: `apps/cli/app/Commands/Internal/WgEasyStateCommand.php`
- Modify: `app/Services/Vpn/WgEasyVpnBackend.php`
- Modify: `app/Services/Vpn/WgEasyServiceInstaller.php`
- Modify: `app/E2E/Support/E2EWgEasyGateway.php`
- Test: `tests/Feature/Services/Vpn/WgEasyServiceInstallerTest.php`
- Test: `tests/Feature/E2ESupport/E2EWgEasyGatewayTest.php`

- [ ] **Step 1: Write failing command-generation assertions**

  Assert generated commands do not contain:

  ```text
  sqlite3
  sudo sqlite3
  ```

  Assert they contain the local executor command with an operation token.

- [ ] **Step 2: Implement wg-easy internal command**

  Add internal subcommands for the specific operations now expressed as
  `sqlite3` statements:

  ```text
  internal:wg-easy state:update-user
  internal:wg-easy state:update-general
  internal:wg-easy state:ensure-writable
  ```

  Use PDO SQLite and preserve host file ownership semantics. If a command must
  change ownership, it must do so explicitly and only for the wg-easy state
  path.

- [ ] **Step 3: Route gateway services**

  Update `WgEasyVpnBackend::runSql()` and installer scripts to call
  `RemoteLocalExecutor`.

- [ ] **Step 4: Verify**

  Run:

  ```bash
  php artisan test --compact tests/Unit/Services/Vpn tests/Feature/Services/Vpn
  php artisan test --compact tests/Feature/E2ESupport/E2EWgEasyGatewayTest.php
  vendor/bin/pint --dirty --format agent
  ```

- [ ] **Step 5: Commit**

  ```bash
  git add apps/cli/app/Commands/Internal/WgEasyStateCommand.php app/Services/Vpn/WgEasyVpnBackend.php app/Services/Vpn/WgEasyServiceInstaller.php app/E2E/Support/E2EWgEasyGateway.php tests/Feature/Services/Vpn tests/Feature/E2ESupport/E2EWgEasyGatewayTest.php
  git commit -m "feat: migrate wg-easy state to local executor"
  ```

### Task 9: Close Remaining Host Tool Leaks

**Files:**
- Modify: `app/Console/Commands/VpnCommandSupport.php`
- Modify: database E2E fixture helpers under `tests/E2E/`
- Test: `tests/Feature/Commands/Vpn/*`
- Test: `tests/E2E/DatabaseTablesTest.php`
- Test: `tests/E2E/DatabaseSchemaTest.php`
- Test: `tests/E2E/DatabaseDescribeTest.php`

- [ ] **Step 1: Fix VPN forwarding path**

  Replace host `php artisan ...` forwarding with the correct executor:

  ```text
  RemoteOrbitRuntimeExecutor for gateway Laravel/artisan work
  RemoteLocalExecutor only for node-local packaged helper work
  ```

- [ ] **Step 2: Fix database fixture creation**

  Replace host `sqlite3` fixture setup with PHP/PDO. If fixture setup happens
  on the test orchestrator, local PHP/PDO is acceptable. If it happens inside
  a prepared topology node, use the CLI local executor.

- [ ] **Step 3: Verify**

  Run:

  ```bash
  php artisan test --compact tests/Feature/Commands/Vpn tests/Unit/Console/Commands
  php artisan test --compact tests/E2E/DatabaseTablesTest.php tests/E2E/DatabaseSchemaTest.php tests/E2E/DatabaseDescribeTest.php
  vendor/bin/pint --dirty --format agent
  ```

- [ ] **Step 4: Commit**

  ```bash
  git add app/Console/Commands/VpnCommandSupport.php tests/E2E/DatabaseTablesTest.php tests/E2E/DatabaseSchemaTest.php tests/E2E/DatabaseDescribeTest.php
  git commit -m "fix: remove remaining host tool leaks"
  ```

### Task 10: Update E2E Topology For Monorepo CLI

**Files:**
- Modify: `docker/e2e/topology/Dockerfile`
- Modify: `docker/e2e/topology/Dockerfile.dockerignore`
- Modify: `app/E2E/Support/DockerTopologyBuilder.php`
- Modify: `app/E2E/Support/E2ECommand.php`
- Modify: `docs/testing/README.md`
- Test: `tests/Feature/E2ESupport/DockerTopologyBuilderTest.php`
- Test: `tests/E2E/PreparedTopologyContractTest.php`

- [ ] **Step 1: Keep topology as environment substrate**

  Ensure Docker topology images install the host substrate needed by the CLI:

  ```text
  PHP CLI
  pdo_sqlite extension
  openssl/curl/mbstring/json support
  Docker CLI/daemon support required by current topology
  SSH/WireGuard substitutes required by Docker provider
  ```

  Do not add `python3` or `sqlite3` as Orbit helper dependencies.

- [ ] **Step 2: Sync current checkout**

  Ensure prepared topology setup copies or mounts the current checkout into
  `~/orbit`. The topology image must not contain a baked Orbit source tree.

- [ ] **Step 3: Install per role**

  During topology setup:

  ```text
  gateway nodes: composer install in apps/gateway and point orbit wrapper there
  client/workload nodes: composer install in apps/cli and point orbit wrapper there
  ```

- [ ] **Step 4: Verify topology contract**

  Run:

  ```bash
  composer e2e:prepare-docker-runtime -- --force
  composer e2e:prepare-docker-topology -- --force operator-gateway-appdev
  composer test:e2e:topology-contract
  ```

- [ ] **Step 5: Commit**

  ```bash
  git add docker/e2e/topology app/E2E/Support docs/testing/README.md tests/Feature/E2ESupport tests/E2E/PreparedTopologyContractTest.php
  git commit -m "test: prepare e2e topology for monorepo cli"
  ```

### Task 11: Relocate Gateway App Under Apps

**Files:**
- Move: `app/` to `apps/gateway/app/`
- Move: `bootstrap/` to `apps/gateway/bootstrap/`
- Move: `config/` to `apps/gateway/config/`
- Move: `database/` to `apps/gateway/database/`
- Move: `routes/` to `apps/gateway/routes/`
- Move: `resources/` to `apps/gateway/resources/`
- Move: `tests/` to `apps/gateway/tests/`
- Move: `artisan` to `apps/gateway/artisan`
- Modify: `composer.json`
- Create: `apps/gateway/composer.json`
- Modify: CI, E2E, and script references that assume gateway lives at repo root.

- [ ] **Step 1: Move the app mechanically**

  Use `git mv` for the gateway Laravel app files so history is preserved.

- [ ] **Step 2: Add gateway composer file**

  `apps/gateway/composer.json` starts from the current root `composer.json`,
  requires `hardimpactdev/orbit-core`, and keeps gateway-only dependencies.

- [ ] **Step 3: Keep root scripts as developer shims**

  Root Composer scripts should forward to `apps/gateway` so existing local
  commands keep working during the transition:

  ```text
  composer test
  composer docs-lint
  composer test:e2e:docker
  ```

- [ ] **Step 4: Verify broad gateway behavior**

  Run:

  ```bash
  composer docs-lint
  composer test
  composer test:e2e:docker:canary
  vendor/bin/pint --dirty --format agent
  ```

- [ ] **Step 5: Commit**

  ```bash
  git add .
  git commit -m "refactor: move gateway app into apps"
  ```

### Task 12: Docker E2E Gate For ORBIT-RUNTIME-11

**Files:**
- No planned product files unless tests reveal defects in the migrated paths.
- Coordinate: `solo://proj/2/todo/--341`

- [ ] **Step 1: Prepare runtime image**

  Run:

  ```bash
  composer e2e:prepare-docker-runtime -- --force
  ```

- [ ] **Step 2: Prepare focused Docker topologies**

  Run:

	  ```bash
	  composer e2e:prepare-docker-topology -- --force operator-gateway-appdev
	  composer e2e:prepare-docker-topology -- --force operator-gateway-appprod-ingress
	  ```

- [ ] **Step 3: Run topology contract**

  Run:

  ```bash
  composer test:e2e:topology-contract
  ```

- [ ] **Step 4: Run Docker E2E**

  Run:

  ```bash
  composer test:e2e:docker
  ```

  Expected: the Docker lane is not blocked by host `python3`, host `sqlite3`,
  or host `php artisan` forwarding leaks.

- [ ] **Step 5: Update Solo**

  If the lane passes, comment on ORBIT-RUNTIME-11 with exact commands and
  results. If an environment blocker remains, record the exact failing command,
  exit code, and output excerpt, and keep the todo open or blocked.

### Task 13: Incus And Provisioning Confidence Gate

**Files:**
- No planned product files unless tests reveal defects in host install or
  provision paths.

- [ ] **Step 1: Run Incus feature lane when host behavior is touched**

  Run:

  ```bash
  composer test:e2e:incus
  ```

  Required when launcher installation, host PHP baseline, SSH behavior, sudo,
  package installation, or filesystem ownership changes.

- [ ] **Step 2: Run provisioning lane when installer behavior changes**

  Run:

  ```bash
  composer test:e2e:provision
  ```

  Required for `bin/install-orbit`, host PHP package baseline, wrapper
  installation, or `node:new` behavior changes.

- [ ] **Step 3: Record evidence**

  Add the Incus/provision results to the relevant Solo todo comments. Do not
  use standing live infra as a substitute for these gates.

## Rollout Strategy

1. Merge docs and Solo retargeting first.
2. Add `packages/core` and `apps/cli` without moving the gateway.
3. Add operation-token and `RemoteLocalExecutor` primitives.
4. Migrate workspace adapter helpers as the first canary.
5. Migrate wg-easy and database fixture helpers.
6. Update prepared Docker topology for host PHP CLI and role-specific launchers.
7. Run Docker E2E to unblock ORBIT-RUNTIME-11.
8. Move the gateway into `apps/gateway` after the executor boundary is proven.
9. Run Incus/provision gates for installer and host-baseline confidence.

## Verification Summary

Minimum final verification before marking this plan complete:

```bash
composer docs-lint
composer test
composer e2e:prepare-docker-runtime -- --force
composer e2e:prepare-docker-topology -- --force operator-gateway-appdev
composer e2e:prepare-docker-topology -- --force operator-gateway-appprod-ingress
composer test:e2e:topology-contract
composer test:e2e:docker
composer test:e2e:incus
```

Run `composer test:e2e:provision` when installer or provisioning paths change.

## Live Infra Policy

Standing live infra such as Beast may be used to inspect failures, compare
operator experience, or validate a suspected environment issue. It is not the
completion gate. A task is complete only when the relevant in-memory and
prepared E2E lanes pass, or when Solo records a precise repeatable blocker.
