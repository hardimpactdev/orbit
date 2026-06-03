# Tools And Processes Runtime Model Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reframe Orbit runtime ownership so tools are node-level capabilities and processes are the universal lifecycle-managed runtime units.

**Architecture:** Keep the public `process` family and broaden it instead of adding a new `service` family. A tool may be installed, updated, adopted, or removed as a node capability, but start/stop/restart/logs belong to process records. A process may be node-level or attached to an app/workspace, may reference a tool with `--tool`, and may run through a runtime backend such as `supervisor`, `docker`, `systemd`, and later `docker-swarm`.

**Tech Stack:** Laravel 13 gateway, Laravel Zero CLI, Pest tests, SQLite migrations, Orbit docs under `apps/docs/content`, E2E harness under `apps/e2e`.

---

## Product Decisions

- Tools are node-level capabilities. Roles can require tools.
- Tools do not own lifecycle. A tool can have zero, one, or many related processes.
- Processes are Orbit-managed long-running units.
- A process may be node-level, app-level, or workspace-level.
- A process may reference a tool, but it does not have to.
- A process owns lifecycle, logs, restart policy, runtime backend, runtime config, command/image/env/ports/volumes, and scope.
- Do not add process `kind` or `category` yet.
- Do not add a `service` command family.
- `systemd` is the node-level Linux service process runtime; `systemctl` is only the command adapter used to control it.
- Supervisor remains available for app/workspace host commands and simpler host-side process programs where retained.
- Docker remains a valid process runtime for containerized processes such as MySQL, Redis, PostgreSQL, and FrankenPHP.
- Existing `tool:start`, `tool:stop`, `tool:restart`, and `tool:logs` behavior must move toward process-backed adapters or deprecation. Keep compatibility only where needed during migration.
- Tool lifecycle compatibility commands resolve exactly one related process row. Missing related processes fail explicitly, ambiguous related processes fail with `tool.process_ambiguous`, and `tool:update` remains capability-owned without implicit process restart.
- FrankenPHP serving an app or workspace is a process with Docker runtime, not a special app runtime lifecycle surface.
- `php artisan horizon` and `vp dev` are processes scoped to an app or workspace, commonly with Supervisor runtime and a tool dependency such as `php-cli` or `viteplus`.
- `opencode-server` and `polyscope-server` are node-level processes with Systemd runtime and tool dependencies such as `opencode` and `polyscope`; the tools are installed capabilities, not lifecycle owners.
- MySQL/PostgreSQL/Redis are processes with Docker runtime. If their client tools are represented later, those client tools are capabilities, not lifecycle units.

## Current Problem

The current Solo chain assumes app/workspace process lifecycle should move to Supervisor and then remove Docker process runtime classes. That is too narrow. The intended model is polymorphic process runtime:

- Supervisor for app/workspace host commands where retained.
- Systemd for node-level Linux service processes such as OpenCode Server and PolyScope Server.
- Docker for containerized services and app/workspace web runtimes.
- Docker Swarm later for production-grade containerized processes.

Todo `644` ("Move process lifecycle actions to Supervisor") is stale as written. It should not be implemented directly.

## File Map

### Product Docs

- Modify `apps/docs/content/product-decisions.md`
  - Add dated decision for tool/process/runtime split.
- Modify `apps/docs/content/concepts.md`
  - Update top-level concept index entries for tool, process, process runtime, runtime unit, and app runtime.
- Modify `apps/docs/content/domains/3_tool/README.md`
  - Remove lifecycle ownership from tools.
  - Describe tool lifecycle commands as transitional adapters where still present.
- Modify `apps/docs/content/domains/3_tool/tool-concepts.md`
  - Define tool as capability.
  - State tools can relate to processes but do not start/stop/restart/log themselves.
- Modify `apps/docs/content/domains/7_process/README.md`
  - Define process as universal long-running Orbit unit.
  - Document optional node/app/workspace scope.
- Modify `apps/docs/content/domains/7_process/process-concepts.md`
  - Replace app-only process definition wording with optional scope wording.
  - Document `--tool` relationship.
  - Document runtime backends.
- Modify `apps/docs/content/domains/5_app/app-concepts.md`
  - State FrankenPHP app/workspace runtime is represented as a process with Docker runtime.
  - Keep app family ownership of app registry, URL, deployment, and desired runtime config.
- Modify `apps/docs/content/domains/6_workspace/workspace-concepts.md`
  - State workspace web runtime and dev servers are processes scoped to the workspace.

### Gateway Model And Runtime

- Modify `apps/gateway/database/migrations/*processes*`
  - Add new migration instead of rewriting history.
- Modify `apps/gateway/app/Models/Process.php`
  - Add nullable scope and runtime config fields.
- Modify `apps/gateway/database/factories/ProcessFactory.php`
  - Default process to node/app-compatible Supervisor command only where tests need it.
- Create `apps/gateway/app/Services/Processes/ProcessRuntimeDrivers/ProcessRuntimeDriver.php`
  - Runtime driver interface.
- Create `apps/gateway/app/Services/Processes/ProcessRuntimeDrivers/SupervisorProcessRuntimeDriver.php`
  - Supervisor lifecycle/log implementation.
- Create `apps/gateway/app/Services/Processes/ProcessRuntimeDrivers/DockerProcessRuntimeDriver.php`
  - Docker lifecycle/log implementation.
- Create `apps/gateway/app/Services/Processes/ProcessRuntimeDrivers/SystemdProcessRuntimeDriver.php`
  - Systemd lifecycle/log implementation for node-level Linux services.
- Create `apps/gateway/app/Services/Processes/ProcessRuntimeDriverRegistry.php`
  - Runtime family to driver resolver.
- Modify `apps/gateway/app/Actions/Processes/AddProcess.php`
- Modify `apps/gateway/app/Actions/Processes/EditProcess.php`
- Modify `apps/gateway/app/Actions/Processes/StartProcesses.php`
- Modify `apps/gateway/app/Actions/Processes/StopProcesses.php`
- Modify `apps/gateway/app/Actions/Processes/RestartProcesses.php`
- Modify `apps/gateway/app/Actions/Processes/ShowProcessLogs.php`
  - Use runtime driver registry instead of direct Docker/Supervisor branches.
- Modify `apps/gateway/app/Actions/Apps/EnsureAppProcessRuntimeUnits.php`
  - Keep temporary compatibility while process runtime drivers absorb Docker/Supervisor rendering.

### Gateway API And CLI

- Modify `apps/gateway/app/Http/Controllers/Api/ProcessStoreController.php`
- Modify `apps/gateway/app/Http/Controllers/Api/ProcessUpdateController.php`
  - Accept `tool`, `node`, `app`, `workspace`, and runtime config input incrementally.
- Modify CLI process commands under `apps/cli/app/Commands/**/Process*`
  - Add `--tool`, node-level process creation, and runtime config flags once gateway supports them.
- Modify gateway requests under `apps/gateway/app/Http/Gateway/Requests/Processes`
  - Carry new fields between CLI and gateway.

### Tool Lifecycle Migration

- Modify `apps/gateway/app/Http/Controllers/Api/ToolStartController.php`
- Modify `apps/gateway/app/Http/Controllers/Api/ToolStopController.php`
- Modify `apps/gateway/app/Http/Controllers/Api/ToolRestartController.php`
- Modify `apps/gateway/app/Http/Controllers/Api/ToolLogController.php`
  - Resolve the related process for compatibility or return an explicit migration/deprecation error when no process exists.
- Modify tool CLI commands under `apps/cli/app/Commands/**/Tool*`
  - Preserve current command surface during migration but route lifecycle to process-backed gateway calls.
- Modify tool definitions under `apps/gateway/app/Tools`
  - Remove direct lifecycle assumptions from capabilities over time.

### Tests

- Add/modify docs contract tests if available in `apps/docs`.
- Add gateway feature tests under `apps/gateway/tests/Feature/Http/Api/Process*ControllerTest.php`.
- Add process unit tests under `apps/gateway/tests/Unit/Services/Processes`.
- Add tool compatibility tests under `apps/gateway/tests/Feature/Http/Api/Tool*ControllerTest.php`.
- Add CLI request tests under `apps/cli/tests/Feature/Commands/Process` and `apps/cli/tests/Feature/Commands/Tool`.
- Add E2E tests under `apps/e2e/tests/Feature/Commands` only after in-memory behavior is stable.

---

## Task 1: Document The Runtime Model

**Files:**
- Modify: `apps/docs/content/product-decisions.md`
- Modify: `apps/docs/content/concepts.md`
- Modify: `apps/docs/content/domains/3_tool/README.md`
- Modify: `apps/docs/content/domains/3_tool/tool-concepts.md`
- Modify: `apps/docs/content/domains/7_process/README.md`
- Modify: `apps/docs/content/domains/7_process/process-concepts.md`
- Modify: `apps/docs/content/domains/5_app/app-concepts.md`
- Modify: `apps/docs/content/domains/6_workspace/workspace-concepts.md`

- [ ] **Step 1: Create a clean worktree**

Run from `/Users/nckrtl/orbit`:

```bash
bin/orbit-prepare-worktree solo-process-runtime-model-docs
```

Expected: `WORKTREE_PREPARED` and baseline `composer test` passes.

- [ ] **Step 2: Update the product decision ledger**

Add this exact decision near the top of `apps/docs/content/product-decisions.md`:

```markdown
- 2026-06-03 — Orbit separates tools from processes. A tool is a node-level capability that roles may require and Orbit may install, update, adopt, or remove. A process is the lifecycle-managed long-running unit; processes own start/stop/restart/logs, runtime backend, restart policy, environment, command or image configuration, and optional node/app/workspace scope. Tools do not own lifecycle because one tool can back many processes. Managed database/cache/agent/web runtimes move toward process-backed lifecycle while tool rows remain capability and expected-state records during migration.
```

- [ ] **Step 3: Rewrite tool concepts**

In `apps/docs/content/domains/3_tool/tool-concepts.md`, make the `Tool` and boundary bullets say:

```markdown
- **Tool:** Orbit product concept for a node capability Orbit installs,
  updates, adopts, removes, observes, or keeps available for other runtime
  units. A tool is not itself the lifecycle-managed unit.
- **Tool process dependency:** Optional relationship from a process to the
  tool capability it uses, such as `opencode`, `viteplus`, or `php-cli`.
  The process owns lifecycle; the tool supplies the capability.
```

Update boundaries to include:

```markdown
- **Tool-family boundaries:** Tool commands own capability inventory,
  installation, update, adoption, removal, and catalog membership. Tools do not
  own start, stop, restart, or log lifecycle directly because one tool can back
  multiple processes. During migration, existing tool lifecycle commands may
  resolve to related process lifecycle operations for compatibility.
```

- [ ] **Step 4: Rewrite process concepts**

In `apps/docs/content/domains/7_process/process-concepts.md`, replace the app-only definition with:

```markdown
- **Process definition:** Gateway-owned configuration for one Orbit-managed
  long-running unit. A process may be scoped to a node, app, or workspace.
  App and workspace processes run in the selected source/runtime context;
  node-level processes run directly against the owning node.
- **Process tool dependency:** Optional catalog tool slug used by the process,
  such as `php-cli`, `viteplus`, `opencode`, or `polyscope`. The dependency
  asserts required capability; it does not transfer lifecycle ownership to the
  tool.
```

Update runtime artifact wording to include:

```markdown
- **Process runtime:** Backend that runs a process. Supported runtime families
  are `supervisor`, `docker`, and `systemd`; `docker-swarm` is planned.
  Supervisor is the host long-running command runner for
  app/workspace commands where retained. Docker is used for containerized
  processes such as databases, caches, and FrankenPHP app or workspace web
  runtimes. Systemd is the node-level Linux service runtime; `systemctl` is
  only the command adapter.
```

- [ ] **Step 5: Update app and workspace docs**

In `apps/docs/content/domains/5_app/app-concepts.md`, update the FrankenPHP runtime wording to include:

```markdown
The lifecycle-managed FrankenPHP runtime for a concrete app is represented as a
process with Docker or Docker Swarm runtime. The app family owns desired app
configuration, URL, source path, deployment policy, and runtime selection; the
process family owns the concrete long-running lifecycle unit.
```

In `apps/docs/content/domains/6_workspace/workspace-concepts.md`, add:

```markdown
Workspace web runtimes and long-running development commands are represented as
processes scoped to the workspace. The workspace family owns branch/path/setup
state; the process family owns start/stop/restart/log lifecycle for the
long-running units.
```

- [ ] **Step 6: Run docs verification**

Run:

```bash
composer docs-lint
```

Expected: exit code 0.

- [ ] **Step 7: Commit**

Run:

```bash
git add apps/docs/content/product-decisions.md apps/docs/content/concepts.md apps/docs/content/domains/3_tool/README.md apps/docs/content/domains/3_tool/tool-concepts.md apps/docs/content/domains/7_process/README.md apps/docs/content/domains/7_process/process-concepts.md apps/docs/content/domains/5_app/app-concepts.md apps/docs/content/domains/6_workspace/workspace-concepts.md
git commit -m "Document tools and processes runtime model"
```

---

## Task 2: Rewrite Solo Todo Chain

**Files:**
- No repo files. Use Solo MCP todos for project `2`.

- [ ] **Step 1: Rewrite stale Supervisor todo**

Update todo `644` to:

```markdown
Title: [Runtime Model 01] Document process lifecycle driver registry tests

Goal: add tests proving process lifecycle remains runtime-polymorphic and is not hard-coded to Supervisor.

Scope:
- Add tests for Supervisor-backed process lifecycle.
- Add tests for Docker-backed process lifecycle.
- Add tests proving AddProcess/EditProcess/StartProcesses/StopProcesses/RestartProcesses/ShowProcessLogs resolve a process runtime driver.
- Preserve durable process events for start/stop/restart.

Verification guard:
- Focused process lifecycle tests fail before implementation.
- Tests prove no action class directly calls ProcessDockerRuntimeManager or RemoteShell supervisorctl branches after implementation.
- Existing app/workspace process behavior remains covered.
```

- [ ] **Step 2: Rewrite stale removal todo**

Update todo `646` to:

```markdown
Title: [Runtime Model 04] Remove legacy process Docker manager direct usage

Goal: remove direct process action dependencies on ProcessDockerRuntimeManager after Docker runtime driver exists.

Scope:
- Keep Docker as a supported process runtime.
- Move Docker lifecycle/logs behind DockerProcessRuntimeDriver.
- Remove direct action-level calls to ProcessDockerRuntimeManager.
- Keep low-level Docker renderer/builder classes where Docker runtime driver still needs them.

Verification guard:
- `rg -n "ProcessDockerRuntimeManager" apps/gateway/app/Actions apps/gateway/tests/Feature/Http/Api` has no direct lifecycle action matches.
- Docker process runtime tests pass.
- Supervisor process runtime tests pass.
```

- [ ] **Step 3: Create docs implementation todo**

Create:

```markdown
Title: [Runtime Model 00] Document tools-as-capabilities and processes-as-managed-units
Priority: high
Tags: runtime-model, process, tool-runtime, docs

Body:
Goal: make product docs the authority for the new runtime model before implementation.

Scope:
- Add product decision for tools as capabilities and processes as lifecycle-managed units.
- Update tool concepts and process concepts.
- Update app/workspace docs for FrankenPHP as process-backed lifecycle.
- State no new service family and no process kind/category in this slice.

Verification guard:
- `composer docs-lint` passes.
- Docs state tools do not own start/stop/restart/log lifecycle directly.
- Docs state processes may be node/app/workspace scoped and may reference a tool.
```

- [ ] **Step 4: Create schema todo**

Create:

```markdown
Title: [Runtime Model 02] Extend process schema for node/app/workspace scoped runtime units
Priority: high
Tags: runtime-model, process, database

Body:
Goal: extend processes so they can represent node-level, app-level, and workspace-level managed units.

Scope:
- Add nullable node/workspace scope fields as needed without breaking existing app-scoped rows.
- Add optional tool dependency field.
- Add runtime_config JSON for runtime-specific payload.
- Preserve existing process rows and current app/workspace command behavior.

Verification guard:
- Migration tests prove existing app-scoped rows survive.
- Model/factory tests prove node-level, app-level, and workspace-level processes can be represented.
- Encrypted/JSON casts and defaults remain PHPStan-friendly.
```

- [ ] **Step 5: Create runtime driver todo**

Create:

```markdown
Title: [Runtime Model 03] Add process runtime driver registry
Priority: high
Tags: runtime-model, process, supervisor, docker

Body:
Goal: route all process lifecycle and log behavior through runtime drivers.

Scope:
- Create ProcessRuntimeDriver interface.
- Add SupervisorProcessRuntimeDriver.
- Add DockerProcessRuntimeDriver.
- Add ProcessRuntimeDriverRegistry.
- Update lifecycle/log actions to resolve drivers by process runtime.

Verification guard:
- Focused lifecycle/log tests pass for Supervisor and Docker processes.
- Process start/stop/restart events are still recorded.
- Unsupported runtime errors fail before remote side effects.
```

- [ ] **Step 6: Create systemd process runtime todo**

Create:

```markdown
Title: [Runtime Model 05A] Add systemd process runtime for node-level Linux services
Priority: high
Tags: runtime-model, process, systemd, incus

Body:
Goal: add `systemd` as the process runtime for node-level Linux services without moving lifecycle ownership back onto tools.

Scope:
- Add `systemd` as a process runtime value. Do not name the runtime `systemctl`; `systemctl` is only the node command adapter.
- Add SystemdProcessRuntimeDriver for start/stop/restart/log lifecycle.
- Support node-level processes such as `opencode-server` and `polyscope-server` that reference installed tool capabilities with `--tool=opencode` or `--tool=polyscope`.
- Keep Supervisor available for app/workspace host commands where retained.
- Keep Docker as the runtime for containerized services and app/workspace web runtimes.
- Do not test real systemd lifecycle in the Docker E2E lane.

Verification guard:
- Focused registry and lifecycle tests cover `runtime=systemd`.
- Unsupported runtime validation still fails before remote side effects.
- Incus feature E2E covers systemd lifecycle; Docker E2E covers only command contracts, registry behavior, validation, and Docker-runtime process behavior.
- No `composer test:e2e:provision` command is required for this todo.
```

- [ ] **Step 7: Create tool lifecycle adapter todo**

Create:

```markdown
Title: [Runtime Model 05] Route tool lifecycle commands through related processes
Priority: high
Tags: runtime-model, tools, process

Body:
Goal: make tool lifecycle commands compatibility adapters over process lifecycle where possible.

Scope:
- Tool start/stop/restart/logs resolve related process rows.
- Return explicit errors when a tool has no related lifecycle process.
- Keep tool install/update/adopt/remove capability-owned.
- Do not remove tool lifecycle commands until CLI compatibility is settled.

Verification guard:
- Tool lifecycle tests prove related processes receive lifecycle calls.
- Tool update tests prove update remains tool-owned and does not restart processes implicitly.
- Multiple related processes produce an explicit ambiguous-process error.
```

- [ ] **Step 8: Create managed tool migration todo**

Create:

```markdown
Title: [Runtime Model 06] Migrate managed database and agent services to process rows
Priority: high
Tags: runtime-model, tools, process, migration

Body:
Goal: represent managed MySQL/PostgreSQL/Redis/OpenCode/PolyScope service instances as processes with tool dependencies.

Scope:
- Create process rows for managed service tool rows where lifecycle exists.
- MySQL/PostgreSQL/Redis use Docker runtime.
- OpenCode/PolyScope use Systemd runtime with process names `opencode-server` and `polyscope-server`, and tool dependencies `opencode` and `polyscope`.
- Preserve tool rows as capability/expected-state records during migration.

Verification guard:
- Migration tests prove existing NodeTool rows get related processes.
- Tool payloads can still render compatibility lifecycle state during migration.
- Process lifecycle tests prove migrated processes can start/stop/restart/log.
```

- [ ] **Step 9: Create app runtime process todo**

Create:

```markdown
Title: [Runtime Model 07] Represent FrankenPHP app and workspace runtimes as Docker processes
Priority: high
Tags: runtime-model, app, workspace, process, docker

Body:
Goal: move FrankenPHP app/workspace runtime lifecycle into process rows.

Scope:
- Represent app FrankenPHP runtime as a Docker process scoped to app.
- Represent workspace FrankenPHP runtime as a Docker process scoped to workspace.
- Preserve app/workspace command contracts that create, inspect, and repair runtime.
- Keep app/workspace families as owners of desired app/workspace config.

Verification guard:
- App runtime tests prove Docker process rows are created and reconciled.
- Workspace runtime tests prove workspace-scoped Docker process rows are created and reconciled.
- App/workspace remove tests prove related process rows and runtime artifacts are cleaned up.
```

- [ ] **Step 10: Create process-backed runtime E2E todo**

Create:

```markdown
Title: [Runtime Model 08] Add E2E coverage for process-backed tools and runtimes
Priority: high
Tags: runtime-model, process, tools, docker, systemd, incus, e2e

Body:
Goal: add prepared-topology E2E acceptance coverage for the new runtime model after tools and app/workspace runtimes have moved onto process-backed lifecycle units.

Scope:
- Add E2E tests under `apps/e2e/tests/Feature/Commands` for process-backed lifecycle behavior that must work through the real gateway API.
- Prove tool lifecycle compatibility commands route through related process rows where applicable.
- Prove tool install/update remains tool-owned and does not implicitly start/stop/restart process rows.
- Prove app/workspace runtime processes, including FrankenPHP Docker processes, can be inspected and controlled through process commands without treating them as tool rows.
- Put OpenCode/PolyScope systemd service lifecycle E2E in Incus only.
- Keep Docker E2E scoped to command contracts, registry behavior, validation, Docker-runtime process lifecycle, and scoped doctor repair of seeded drift.
- Keep tests on prepared topology feature lanes; do not add provisioning tests for this acceptance coverage.

Verification guard:
- Focused E2E tests fail before the related implementation is present.
- `composer test:e2e` passes after implementation.
- No `composer test:e2e:provision` command is required for this todo.
```

- [ ] **Step 11: Rewire blockers**

Set blockers so:

```text
Runtime Model 00 blocks Runtime Model 01.
Runtime Model 01 blocks Runtime Model 02.
Runtime Model 02 blocks Runtime Model 03.
Runtime Model 03 blocks Runtime Model 04.
Runtime Model 04 blocks Runtime Model 05A.
Runtime Model 05A blocks Runtime Model 05.
Runtime Model 05 blocks Runtime Model 06.
Runtime Model 06 blocks Runtime Model 07.
Runtime Model 07 blocks Runtime Model 08.
Runtime Model 08 blocks the remaining Swarm process/tool/app-prod runtime todos that currently assume the stale model.
```

---

## Task 3: Add Process Schema Contract Tests

**Files:**
- Create: `apps/gateway/tests/Feature/Database/ProcessRuntimeScopeSchemaTest.php`
- Modify: `apps/gateway/database/factories/ProcessFactory.php`

- [ ] **Step 1: Write failing tests**

Create `apps/gateway/tests/Feature/Database/ProcessRuntimeScopeSchemaTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\Processes\ProcessRuntime;
use App\Models\App;
use App\Models\Node;
use App\Models\Process;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('stores node level process runtime configuration', function (): void {
    $node = Node::factory()->create(['name' => 'app-1']);

    $process = Process::factory()->create([
        'node_id' => $node->id,
        'app_id' => null,
        'workspace_id' => null,
        'name' => 'mysql8',
        'runtime' => ProcessRuntime::Docker,
        'tool' => 'mysql',
        'runtime_config' => [
            'image' => 'mysql:8',
            'ports' => ['3306:3306'],
            'volumes' => ['mysql8-data:/var/lib/mysql'],
        ],
    ]);

    expect($process->refresh())
        ->node_id->toBe($node->id)
        ->app_id->toBeNull()
        ->workspace_id->toBeNull()
        ->tool->toBe('mysql')
        ->runtime_config->toBe([
            'image' => 'mysql:8',
            'ports' => ['3306:3306'],
            'volumes' => ['mysql8-data:/var/lib/mysql'],
        ]);
});

it('stores workspace scoped process runtime configuration', function (): void {
    $node = Node::factory()->create(['name' => 'app-dev-1']);
    $app = App::factory()->create(['node_id' => $node->id, 'name' => 'abc']);
    $workspace = Workspace::factory()->create(['app_id' => $app->id, 'name' => 'redesign']);

    $process = Process::factory()->create([
        'node_id' => $node->id,
        'app_id' => $app->id,
        'workspace_id' => $workspace->id,
        'name' => 'horizon-redesign',
        'runtime' => ProcessRuntime::Supervisor,
        'tool' => 'php-cli',
        'command' => 'php artisan horizon',
        'runtime_config' => [
            'directory' => '/home/orbit/apps/abc/worktrees/redesign',
        ],
    ]);

    expect($process->refresh())
        ->node_id->toBe($node->id)
        ->app_id->toBe($app->id)
        ->workspace_id->toBe($workspace->id)
        ->tool->toBe('php-cli')
        ->runtime_config->toBe([
            'directory' => '/home/orbit/apps/abc/worktrees/redesign',
        ]);
});
```

- [ ] **Step 2: Run tests to verify RED**

Run:

```bash
bin/orbit-gateway-pest --compact apps/gateway/tests/Feature/Database/ProcessRuntimeScopeSchemaTest.php
```

Expected: FAIL because `node_id`, `workspace_id`, `tool`, or `runtime_config` columns/casts do not exist yet.

- [ ] **Step 3: Implement schema**

Create a migration:

```bash
bin/orbit-gateway-artisan make:migration add_scope_and_runtime_config_to_processes_table --table=processes --no-interaction
```

Migration `up()` should include:

```php
Schema::table('processes', function (Blueprint $table): void {
    $table->foreignId('node_id')->nullable()->after('app_id')->constrained()->nullOnDelete();
    $table->foreignId('workspace_id')->nullable()->after('node_id')->constrained()->nullOnDelete();
    $table->string('tool')->nullable()->after('runtime');
    $table->json('runtime_config')->nullable()->after('tool');
});
```

Migration `down()` should drop the constrained columns and new fields.

- [ ] **Step 4: Update Process model and factory**

In `apps/gateway/app/Models/Process.php`, add fillable fields and casts:

```php
'node_id',
'workspace_id',
'tool',
'runtime_config',
```

```php
'runtime_config' => 'array',
```

Add relationships for `node()` and `workspace()`.

In `apps/gateway/database/factories/ProcessFactory.php`, default `runtime_config` to an empty array and keep `app_id` compatible.

- [ ] **Step 5: Run tests to verify GREEN**

Run:

```bash
bin/orbit-gateway-pest --compact apps/gateway/tests/Feature/Database/ProcessRuntimeScopeSchemaTest.php
```

Expected: PASS.

- [ ] **Step 6: Commit**

Run:

```bash
git add apps/gateway/database/migrations apps/gateway/app/Models/Process.php apps/gateway/database/factories/ProcessFactory.php apps/gateway/tests/Feature/Database/ProcessRuntimeScopeSchemaTest.php
git commit -m "Add process runtime scope schema"
```

---

## Task 4: Add Runtime Driver Contract Tests

**Files:**
- Create: `apps/gateway/tests/Unit/Services/Processes/ProcessRuntimeDriverRegistryTest.php`
- Create: `apps/gateway/app/Services/Processes/ProcessRuntimeDrivers/ProcessRuntimeDriver.php`
- Create: `apps/gateway/app/Services/Processes/ProcessRuntimeDrivers/SupervisorProcessRuntimeDriver.php`
- Create: `apps/gateway/app/Services/Processes/ProcessRuntimeDrivers/DockerProcessRuntimeDriver.php`
- Create: `apps/gateway/app/Services/Processes/ProcessRuntimeDriverRegistry.php`

- [ ] **Step 1: Write failing registry tests**

Create `apps/gateway/tests/Unit/Services/Processes/ProcessRuntimeDriverRegistryTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\Processes\ProcessRuntime;
use App\Services\Processes\ProcessRuntimeDriverRegistry;
use App\Services\Processes\ProcessRuntimeDrivers\DockerProcessRuntimeDriver;
use App\Services\Processes\ProcessRuntimeDrivers\SupervisorProcessRuntimeDriver;

it('resolves supervisor and docker process runtime drivers', function (): void {
    $registry = app(ProcessRuntimeDriverRegistry::class);

    expect($registry->driver(ProcessRuntime::Supervisor))->toBeInstanceOf(SupervisorProcessRuntimeDriver::class)
        ->and($registry->driver(ProcessRuntime::Docker))->toBeInstanceOf(DockerProcessRuntimeDriver::class);
});
```

- [ ] **Step 2: Run test to verify RED**

Run:

```bash
bin/orbit-gateway-pest --compact apps/gateway/tests/Unit/Services/Processes/ProcessRuntimeDriverRegistryTest.php
```

Expected: FAIL because registry/driver classes do not exist.

- [ ] **Step 3: Implement interface and registry**

Create `ProcessRuntimeDriver` with lifecycle/log methods:

```php
<?php

declare(strict_types=1);

namespace App\Services\Processes\ProcessRuntimeDrivers;

use App\Models\App;
use App\Models\Node;
use App\Models\Process;
use App\Models\Workspace;

interface ProcessRuntimeDriver
{
    public function runtimeUnit(App $app, Process $process, ?Workspace $workspace): string;

    public function start(Node $node, string $runtimeUnit): bool;

    public function stop(Node $node, string $runtimeUnit): bool;

    public function restart(Node $node, string $runtimeUnit): bool;

    public function logScript(App $app, Process $process, ?Workspace $workspace, string $runtimeUnit, int $lines, bool $follow): string;
}
```

Create `ProcessRuntimeDriverRegistry` with explicit match on `ProcessRuntime`.

- [ ] **Step 4: Implement Supervisor and Docker drivers**

Move existing supervisorctl and Docker manager calls behind the drivers. Keep behavior identical in this task.

- [ ] **Step 5: Run registry tests**

Run:

```bash
bin/orbit-gateway-pest --compact apps/gateway/tests/Unit/Services/Processes/ProcessRuntimeDriverRegistryTest.php
```

Expected: PASS.

- [ ] **Step 6: Commit**

Run:

```bash
git add apps/gateway/app/Services/Processes/ProcessRuntimeDriverRegistry.php apps/gateway/app/Services/Processes/ProcessRuntimeDrivers apps/gateway/tests/Unit/Services/Processes/ProcessRuntimeDriverRegistryTest.php
git commit -m "Add process runtime driver registry"
```

---

## Task 5: Route Process Lifecycle Actions Through Drivers

**Files:**
- Modify: `apps/gateway/app/Actions/Processes/AddProcess.php`
- Modify: `apps/gateway/app/Actions/Processes/EditProcess.php`
- Modify: `apps/gateway/app/Actions/Processes/StartProcesses.php`
- Modify: `apps/gateway/app/Actions/Processes/StopProcesses.php`
- Modify: `apps/gateway/app/Actions/Processes/RestartProcesses.php`
- Modify: `apps/gateway/app/Actions/Processes/ShowProcessLogs.php`
- Modify: existing process API tests.

- [ ] **Step 1: Add failing assertions**

Update process start/stop/restart/log tests to cover both `runtime=supervisor` and `runtime=docker`. Assertions:

```php
expect($remoteShell->scripts)->toContain("sudo supervisorctl start 'orbit_docs_main_vite'");
```

and:

```php
expect($dockerManager->started)->toContain('orbit_docs_main_vite');
```

Use fakes where current tests already fake `RemoteShell`.

- [ ] **Step 2: Run focused tests to verify RED**

Run:

```bash
bin/orbit-gateway-pest --compact apps/gateway/tests/Feature/Http/Api/ProcessStartControllerTest.php apps/gateway/tests/Feature/Http/Api/ProcessStopControllerTest.php apps/gateway/tests/Feature/Http/Api/ProcessRestartControllerTest.php apps/gateway/tests/Feature/Http/Api/ProcessLogControllerTest.php
```

Expected: FAIL on missing driver path or missing fakes.

- [ ] **Step 3: Update actions**

Inject `ProcessRuntimeDriverRegistry` into each action and replace direct runtime matches with:

```php
$driver = $this->drivers->driver($process->runtime);
$runtimeUnit = $driver->runtimeUnit($app, $process, $workspace);
$ok = $app->node !== null && $driver->start($app->node, $runtimeUnit);
```

Use `stop`, `restart`, and `logScript` equivalents in the relevant actions.

- [ ] **Step 4: Run focused tests to verify GREEN**

Run:

```bash
bin/orbit-gateway-pest --compact apps/gateway/tests/Feature/Http/Api/ProcessStartControllerTest.php apps/gateway/tests/Feature/Http/Api/ProcessStopControllerTest.php apps/gateway/tests/Feature/Http/Api/ProcessRestartControllerTest.php apps/gateway/tests/Feature/Http/Api/ProcessLogControllerTest.php
```

Expected: PASS.

- [ ] **Step 5: Run direct usage scan**

Run:

```bash
rg -n "ProcessDockerRuntimeManager|supervisorctl (start|stop|restart)|docker logs" apps/gateway/app/Actions/Processes apps/gateway/tests/Feature/Http/Api
```

Expected: no action-level direct lifecycle matches outside runtime driver tests or fakes.

- [ ] **Step 6: Commit**

Run:

```bash
git add apps/gateway/app/Actions/Processes apps/gateway/tests/Feature/Http/Api apps/gateway/app/Services/Processes
git commit -m "Route process lifecycle through runtime drivers"
```

---

## Task 6: Add Process Tool Dependency CLI/API Inputs

**Files:**
- Modify: `apps/gateway/app/Http/Controllers/Api/ProcessStoreController.php`
- Modify: `apps/gateway/app/Http/Controllers/Api/ProcessUpdateController.php`
- Modify: `apps/cli/app/Commands/**/Process*`
- Modify: `apps/gateway/app/Http/Gateway/Requests/Processes/AddProcessRequest.php`
- Modify: `apps/gateway/app/Http/Gateway/Requests/Processes/EditProcessRequest.php`
- Modify tests under `apps/gateway/tests/Feature/Http/Api` and `apps/cli/tests/Feature/Commands/Process`.

- [ ] **Step 1: Write failing gateway tests**

Add tests proving:

```php
$response = $this->call('POST', '/api/processes', [
    'node' => 'app-1',
    'name' => 'opencode-server',
    'tool' => 'opencode',
    'runtime' => 'systemd',
    'command' => 'opencode serve -a',
], [], [], ['REMOTE_ADDR' => $callerIp]);
```

Expected JSON:

```php
$response->assertOk()
    ->assertJsonPath('success.data.process.tool', 'opencode')
    ->assertJsonPath('success.data.process.runtime', 'systemd');
```

- [ ] **Step 2: Run gateway tests to verify RED**

Run:

```bash
bin/orbit-gateway-pest --compact apps/gateway/tests/Feature/Http/Api/ProcessStoreControllerTest.php
```

Expected: FAIL because `tool`/node-level input is not accepted yet.

- [ ] **Step 3: Implement gateway inputs**

Accept `tool`, `node`, `runtime_config`, `app`, and `workspace` according to the schema from Task 3. For this slice, only validate that `tool` is a string matching an existing catalog slug when present.

- [ ] **Step 4: Update CLI process add request**

Expose:

```bash
orbit process:add opencode-server --node=app-1 --tool=opencode --runtime=systemd --command="opencode serve -a"
```

Do not add kind/category.

- [ ] **Step 5: Run focused gateway and CLI tests**

Run:

```bash
bin/orbit-gateway-pest --compact apps/gateway/tests/Feature/Http/Api/ProcessStoreControllerTest.php
php apps/cli/vendor/bin/pest --compact apps/cli/tests/Feature/Commands/Process
```

Expected: PASS.

- [ ] **Step 6: Commit**

Run:

```bash
git add apps/gateway/app/Http apps/gateway/tests/Feature/Http/Api apps/cli/app apps/cli/tests/Feature/Commands/Process
git commit -m "Add process tool dependency inputs"
```

---

## Task 7: Route Tool Lifecycle Through Processes

**Files:**
- Modify: `apps/gateway/app/Http/Controllers/Api/ToolStartController.php`
- Modify: `apps/gateway/app/Http/Controllers/Api/ToolStopController.php`
- Modify: `apps/gateway/app/Http/Controllers/Api/ToolRestartController.php`
- Modify: `apps/gateway/app/Http/Controllers/Api/ToolLogsController.php`
- Modify: related CLI tool lifecycle commands.
- Add tests under `apps/gateway/tests/Feature/Http/Api/Tool*ControllerTest.php`.

- [ ] **Step 1: Write failing compatibility tests**

Add a test that creates:

```php
Process::factory()->create([
    'node_id' => $node->id,
    'tool' => 'opencode',
    'name' => 'opencode-server',
    'runtime' => ProcessRuntime::Systemd,
    'command' => 'opencode serve -a',
]);
```

Then assert `tool:restart opencode` resolves that process and invokes process restart behavior.

- [ ] **Step 2: Add ambiguous related process test**

Create two processes with `tool=opencode` on the same node. Expected error:

```php
$response->assertStatus(422)
    ->assertJsonPath('error.code', 'tool.process_ambiguous');
```

- [ ] **Step 3: Run tests to verify RED**

Run:

```bash
bin/orbit-gateway-pest --compact apps/gateway/tests/Feature/Http/Api/ToolStartControllerTest.php apps/gateway/tests/Feature/Http/Api/ToolStopControllerTest.php apps/gateway/tests/Feature/Http/Api/ToolRestartControllerTest.php apps/gateway/tests/Feature/Http/Api/ToolLogsControllerTest.php
```

Expected: FAIL because tool lifecycle does not resolve processes.

- [ ] **Step 4: Implement adapter**

Create a small resolver:

```php
apps/gateway/app/Services/Tools/ToolRelatedProcessResolver.php
```

It resolves one process by `node_id` and `tool`, errors when none or many exist, and leaves tool update/install/remove untouched.

- [ ] **Step 5: Run focused tests**

Run:

```bash
bin/orbit-gateway-pest --compact apps/gateway/tests/Feature/Http/Api/ToolStartControllerTest.php apps/gateway/tests/Feature/Http/Api/ToolStopControllerTest.php apps/gateway/tests/Feature/Http/Api/ToolRestartControllerTest.php apps/gateway/tests/Feature/Http/Api/ToolLogControllerTest.php
```

Expected: PASS.

- [ ] **Step 6: Commit**

Run:

```bash
git add apps/gateway/app/Http/Controllers/Api/Tool*Controller.php apps/gateway/app/Services/Tools apps/gateway/tests/Feature/Http/Api/Tool*ControllerTest.php apps/cli/app
git commit -m "Route tool lifecycle through related processes"
```

---

## Task 8: Migrate Managed Tool Services To Processes

**Files:**
- Create migration in `apps/gateway/database/migrations`
- Modify tool payload and lifecycle mapping code.
- Add migration tests.

- [ ] **Step 1: Write failing migration tests**

Create tests with existing `NodeTool` rows for:

```php
mysql
postgres
redis
opencode-server
polyscope-server
```

Expected related `Process` rows:

```php
expect(Process::query()->where('tool', 'mysql')->where('runtime', 'docker')->exists())->toBeTrue();
expect(Process::query()->where('tool', 'opencode')->where('runtime', 'systemd')->exists())->toBeTrue();
```

- [ ] **Step 2: Run tests to verify RED**

Run:

```bash
bin/orbit-gateway-pest --compact apps/gateway/tests/Feature/Database
```

Expected: FAIL for missing migration behavior.

- [ ] **Step 3: Implement migration/backfill**

Backfill process rows for managed service tools only. Do not delete `node_tools` rows. Store minimal runtime config needed to preserve current behavior.

- [ ] **Step 4: Run migration tests**

Run:

```bash
bin/orbit-gateway-pest --compact apps/gateway/tests/Feature/Database
```

Expected: PASS.

- [ ] **Step 5: Commit**

Run:

```bash
git add apps/gateway/database/migrations apps/gateway/tests/Feature/Database apps/gateway/app
git commit -m "Backfill managed tool services as processes"
```

---

## Task 9: Represent FrankenPHP Runtime As Processes

**Files:**
- Modify app runtime actions/services under `apps/gateway/app/Services/Apps` and `apps/gateway/app/Actions/Apps`
- Modify workspace runtime actions/services under `apps/gateway/app/Actions/Workspaces` and `apps/gateway/app/Services/Workspaces`
- Add app/workspace runtime tests.

- [ ] **Step 1: Write failing app runtime process tests**

Assert app registration/enactment creates a Docker process:

```php
expect(Process::query()
    ->where('app_id', $app->id)
    ->where('name', 'frankenphp-'.$app->name)
    ->where('runtime', ProcessRuntime::Docker)
    ->exists())->toBeTrue();
```

- [ ] **Step 2: Write failing workspace runtime process tests**

Assert workspace creation/enactment creates a Docker process scoped to workspace:

```php
expect(Process::query()
    ->where('workspace_id', $workspace->id)
    ->where('runtime', ProcessRuntime::Docker)
    ->exists())->toBeTrue();
```

- [ ] **Step 3: Run tests to verify RED**

Run:

```bash
bin/orbit-gateway-pest --compact apps/gateway/tests/Feature/Http/Api apps/gateway/tests/Feature/Services
```

Expected: FAIL for missing process rows.

- [ ] **Step 4: Implement app/workspace process creation**

Create or update process rows when app/workspace runtime desired state changes. Keep app/workspace public commands unchanged in this task.

- [ ] **Step 5: Run focused tests**

Run:

```bash
bin/orbit-gateway-pest --compact apps/gateway/tests/Feature/Http/Api apps/gateway/tests/Feature/Services
```

Expected: PASS.

- [ ] **Step 6: Commit**

Run:

```bash
git add apps/gateway/app/Actions/Apps apps/gateway/app/Services/Apps apps/gateway/app/Actions/Workspaces apps/gateway/app/Services/Workspaces apps/gateway/tests
git commit -m "Represent FrankenPHP runtimes as processes"
```

---

## Task 10: Final Verification And E2E

**Files:**
- No new files unless fixes are required.

- [ ] **Step 1: Run Pint**

Run:

```bash
bin/orbit-gateway-vendor-bin pint --dirty --format agent
```

Expected: exit code 0.

- [ ] **Step 2: Run focused process and tool tests**

Run:

```bash
bin/orbit-gateway-pest --compact apps/gateway/tests/Feature/Http/Api/ProcessStoreControllerTest.php apps/gateway/tests/Feature/Http/Api/ProcessStartControllerTest.php apps/gateway/tests/Feature/Http/Api/ProcessStopControllerTest.php apps/gateway/tests/Feature/Http/Api/ProcessRestartControllerTest.php apps/gateway/tests/Feature/Http/Api/ProcessLogControllerTest.php apps/gateway/tests/Feature/Http/Api/ToolStartControllerTest.php apps/gateway/tests/Feature/Http/Api/ToolStopControllerTest.php apps/gateway/tests/Feature/Http/Api/ToolRestartControllerTest.php apps/gateway/tests/Feature/Http/Api/ToolLogControllerTest.php apps/gateway/tests/Feature/Database
```

Expected: PASS.

- [ ] **Step 3: Run broad quality gate**

Run:

```bash
composer quality-check
```

Expected: PASS.

- [ ] **Step 4: Run E2E**

Run:

```bash
composer test:e2e
```

Expected: Docker and Incus prepared-topology feature lanes pass.

- [ ] **Step 5: Do not run provision aggregate**

Do not run:

```bash
composer test:e2e:provision
```

Provider-specific provision commands require explicit scope and user approval.

---

## Self-Review

- Spec coverage: The plan covers documentation, Solo chain rewrite, schema, runtime drivers, lifecycle actions, tool dependency input, tool lifecycle migration, managed tool backfill, FrankenPHP process representation, and verification.
- Placeholder scan: No TBD/TODO placeholders remain.
- Type consistency: The plan consistently uses `tool`, `runtime_config`, `node_id`, `app_id`, and `workspace_id` as the process fields. These names should be validated during implementation against existing conventions before code is committed.
- Scope check: The plan intentionally excludes process kind/category and a new service command family.
