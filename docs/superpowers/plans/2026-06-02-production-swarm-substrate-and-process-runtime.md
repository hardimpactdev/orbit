# Production Swarm Substrate And Process Runtime Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Align Orbit's production runtime substrate so Docker Swarm is a selectable, platform-scoped production/infra service backend for managed processes, app/workspace configured processes run through host Supervisor on the owning node, app-prod FrankenPHP services have a shared Swarm/release-mount contract to consume, and app dependency addresses work consistently from both host processes and containers without Docker-specific `.env` values.

**Architecture:** Runtime backend selection is per managed process, not a whole-node mode. A node can run Swarm services for production/infra workloads, standalone Docker processes for simpler service instances, standalone/direct app-dev runtime where appropriate, and host Supervisor programs for app/workspace processes. Runnable services such as MySQL, PostgreSQL, Redis, FrankenPHP, Horizon, OpenCode Server, and PolyScope Server are process rows or process definitions, not tool runtime instances. A process runtime registry resolves the selected runtime plus the target node's recorded platform, such as `docker-swarm` + `ubuntu_24-04`, to a platform-specific implementation. App dependency hosts use node WireGuard IP service addresses, so the same `.env` is valid for host Supervisor processes and FrankenPHP containers. WireGuard service-address readiness is provisioning/topology infrastructure; Linux self-WireGuard routing is treated as the production baseline. macOS self-route optimization is intentionally out of scope for this plan.

> **2026-06-04 direction update:** This plan predates the strict tool/process
> boundary decision in solo todo #681. Remaining and future work must treat
> managed service-tool instances, tool runtimes, `tool:install mysql`, `mysql:8`
> tool instance keys, and tool-owned lifecycle/logs for MySQL/PostgreSQL/Redis
> as superseded. Those services are process definitions and process rows. Tool
> coverage is limited to host-installed capabilities and boundary guards that
> prevent runnable services from re-entering tool lifecycle ownership.

**Tech Stack:** Laravel 13 gateway, Pest 4, Docker Engine, Docker Swarm, Supervisor, FrankenPHP, WireGuard.

---

## Prerequisite And Scope

Implement this plan after
`docs/superpowers/plans/2026-06-01-orbit-gateway-swarm-update-runner.md` has
landed. That plan establishes the production `orbit-gateway`/`orbit-scheduler`
Swarm baseline, artifact-prod/source-dev topology split, and update-runner
lifecycle.

This plan is the shared substrate correction. It owns:

- Swarm as a per-artifact production/infra backend, not a node-wide mode.
- First-class process runtime drivers, supported runtime metadata, process
  definition selection, and versioned process service instances for MySQL,
  PostgreSQL, Redis, and similar runnable services.
- Platform-scoped runtime implementations below the user-facing runtime
  family, using the existing node platform record.
- App/workspace configured processes moving from Docker sidecars to host
  Supervisor.
- App-dev Supervisor availability without converting app-dev web runtime to
  Swarm.
- WireGuard-IP service addressing for app dependency `.env` values, owned by
  node provisioning/topology readiness rather than app, process, tool, or
  database runtime prerequisites.
- Linux self-WireGuard route diagnostics and explicit macOS deferral.

This plan does not implement the app-prod FrankenPHP service renderer,
runtime UID/GID enforcement, proxy upstream change, or deploy rolling-update
flow. Those concrete app-prod changes are owned by
`docs/superpowers/plans/2026-06-02-app-prod-frankenphp-runtime-isolation.md`,
which should be implemented after this substrate plan.

## Product Decisions

1. Swarm is a production/infra artifact backend, not a node-wide mode.
   - Production app HTTP runtimes, gateway production runtimes, ingress/router Caddy, databases, Redis, websocket services, and S3-compatible services are candidates for Swarm service ownership when their role contract needs production-grade service supervision.
   - This plan implements only the shared substrate rules and process-runtime migration. Concrete app-prod HTTP runtime Swarm service rendering belongs to the app-prod FrankenPHP runtime isolation follow-up.
   - App-dev app/workspace web runtimes do not become Swarm services by default.
   - Adding the app-dev role to a node that already runs Swarm-backed infra does not disable Swarm and does not require moving existing Swarm services.

2. App/workspace configured processes run through host Supervisor.
   - Laravel Horizon, workers, schedulers, queue consumers, and user-defined process commands are Supervisor programs on the node where the process is assigned.
   - They run from the owning app/workspace path and as the resolved owning app/workspace Unix user.
   - Docker remains available for web/runtime services and infra services, but not as the default app process sidecar backend.

3. App-prod FrankenPHP services are updated through Swarm.
   - This plan records the shared contract; app-prod implementation lives in the app-prod FrankenPHP runtime isolation plan.
   - Zero-downtime production deploys use `start-first` Swarm updates.
   - A new FrankenPHP task starts for the new release or image.
   - Once the new task is healthy, Swarm stops the old task.
   - The old task receives `SIGTERM` and gets the configured grace period to drain in-flight requests.
   - Brief mixed-version traffic during the update window is acceptable.

4. Source deploys bind-mount the exact release directory.
   - This plan records the release-mount boundary; deploy manager changes live in the app-prod FrankenPHP runtime isolation plan.
   - A deploy builds and warms `/.../releases/<release-id>`.
   - The Swarm service mounts that release path into the FrankenPHP container, for example `/.../releases/<release-id>:/app`.
   - The `current` or `live` symlink remains an operator pointer to the active release.
   - The symlink is updated only after the Swarm update converges and HTTP warmup passes.
   - A failed deploy leaves `current` pointing at the previously active release.

5. App `.env` values are shared by host processes and containers.
   - Do not write Docker service names such as `postgres` or `redis` into app `.env` files.
   - Do not use context-specific Docker env overrides for Laravel dependency hosts because cached config can bake the wrong value into a release.
   - Do not use `127.0.0.1` for dependencies that must be reached from both host Supervisor and containers.
   - Use the dependency owner node's WireGuard IP for service hosts, for example `DB_HOST=10.6.0.7` and `REDIS_HOST=10.6.0.7`.

6. Linux WireGuard self-routing is the production baseline.
   - On Linux, connecting to an IP assigned to a local interface resolves through the kernel's local routing table.
   - A service on node `10.6.0.7` can use `10.6.0.7` from host Supervisor and from local Docker containers without routing through the Orbit DNS/router node.
   - Services that are consumed this way bind or publish on the node WireGuard IP, with firewall rules limiting access to local, Docker, and WireGuard sources.

7. macOS self-WireGuard routing is deferred.
   - macOS may route its own WireGuard IP through the VPN peer path instead of loopback.
   - This plan records the Linux production contract only.
   - A later macOS-specific plan will define host routes, loopback redirection, or another local development override.

8. App users do not get Docker privileges.
   - Do not add unprivileged app users to the `docker` group.
   - Orbit/operator-owned deployment actions create and update containers/services.
   - The app user's security role is app file ownership and runtime process identity, not Docker daemon access.

9. Managed service processes resolve into versioned process definitions.
   - The public service definition selector is the process definition name, for
     example `mysql`, `postgres`, or `redis`.
   - A node may run separate process rows for distinct version families, such
     as MySQL 8 and MySQL 9 on the same database node, when the definition
     supports those families.
   - The version request resolves to a version family and expected service
     version on the process definition. Service version, runtime config,
     credentials, endpoints, volumes, health checks, lifecycle, logs, and state
     belong to the process row or process definition.
   - Two process rows for the same definition and version family on the same
     node require explicit names or aliases; no hidden tool instance key is
     created.

10. Process runtime is selected when the process is defined and constrained by
    the process definition.
    - Process definitions declare their supported runtimes, for example
      `docker`, `docker-swarm`, `supervisor`, or `systemd`.
    - A MySQL process can select `runtime=docker-swarm` for a Swarm-backed
      service or `runtime=docker` for a standalone Docker service when the
      target platform supports that runtime.
    - The resolved runtime is stored on the process and reused by lifecycle,
      logs, credentials, update, reconfigure, doctor, and fix paths.
    - Process updates may update version/config within the stored runtime. They
      must not silently migrate a process from `docker` to `docker-swarm`.
      Runtime migration requires a future explicit guarded command or
      destructive reconfigure path.

11. Runtime support is platform-scoped below the user-facing runtime name.
    - The public runtime value is a runtime family, such as `docker`,
      `docker-swarm`, `supervisor`, `systemd`, or a future `kubernetes`.
    - The runtime driver registry resolves runtime family plus target
      `Node.platform` to a concrete implementation, such as
      `docker-swarm/ubuntu`, `docker/macos`, `systemd/ubuntu`, or
      `supervisor/linux`.
    - `docker-swarm` on Ubuntu/Linux is the production-supported
      implementation in this plan.
    - `docker-swarm` on macOS is not production-equivalent to Linux Docker
      because Docker Desktop networking, bind mounts, WireGuard self-routing,
      and published-port semantics differ. It must fail with
      `process.runtime_platform_unsupported` until a macOS-specific
      implementation is explicitly added.
    - Host-installed capability tools such as PHP, Composer, OpenCode, or
      PolyScope may still be referenced by processes when a process command
      depends on them, but the tool does not own lifecycle.
    - Process definitions declare runtime support with platform eligibility,
      not just a flat list of runtime names.

12. Swarm improves convergence but does not promise zero-downtime singleton databases.
    - Stateless or replicated service processes may use Swarm rolling update
      behavior when their definition declares that strategy.
    - Singleton stateful processes such as MySQL, PostgreSQL, and Redis default
      to conservative stop-first or guarded update behavior unless their process
      definition declares safe replication/failover semantics.
    - Version-family instances must have distinct ports, volumes, credentials,
      service names, endpoint names, and runtime intent hashes.

---

## Current Drift To Fix

The current product docs and gateway implementation still describe Docker sidecar process units as the default for PHP app/workspace processes. That conflicts with the intended FrankenPHP/Swarm model because app `.env` values must be valid from both host Supervisor and containers, and process commands are expected to execute on the host as the app/workspace user.

Known drift points:

- `apps/docs/content/tech-stack.md`
- `apps/docs/content/concepts.md`
- `apps/docs/content/execution-lanes.md`
- `apps/docs/content/domains/7_process/README.md`
- `apps/docs/content/domains/7_process/process-concepts.md`
- `apps/docs/content/domains/6_workspace/workspace-concepts.md`
- `apps/docs/content/domains/3_tool/README.md`
- `apps/docs/content/domains/3_tool/tool-concepts.md`
- `apps/docs/content/domains/3_tool/catalog/README.md`
- `apps/docs/content/domains/18_database/README.md`
- `apps/docs/content/domains/18_database/database-concepts.md`
- process command/API docs for process definitions, runtimes, lifecycle, logs,
  credentials, and update behavior
- tool docs for negative boundary language that prevents MySQL, PostgreSQL,
  Redis, and similar runnable services from being documented as tool lifecycle
  units
- `apps/docs/content/testing/README.md`
- `apps/cli/app/Commands/Process/**`
- process HTTP API controllers under `apps/gateway/app/Http/Controllers/Api/**`
- process service definitions and runtime drivers under
  `apps/gateway/app/Services/Processes/**`
- `apps/gateway/app/Enums/Processes/ProcessRuntime.php`
- `apps/gateway/app/Models/Process.php`
- `apps/gateway/app/Actions/Apps/EnsureAppProcessRuntimeUnits.php`
- `apps/gateway/app/Actions/Processes/AddProcess.php`
- `apps/gateway/app/Actions/Processes/EditProcess.php`
- `apps/gateway/app/Renderers/Processes/SupervisorProgramRenderer.php`
- `apps/gateway/app/Renderers/Processes/ProcessDockerContainerRenderer.php`
- `apps/gateway/app/Managers/Processes/ProcessDockerRuntimeManager.php`
- `apps/gateway/app/Data/Processes/ProcessDockerContainer.php`
- `apps/gateway/app/NodeRoles/AppDevelopmentRoleBaseline.php`
- Process feature tests under `apps/gateway/tests/Feature/Services/Processes/`
- process unit tests under `apps/gateway/tests/Unit/Services/Processes/`
- tool boundary tests under `apps/gateway/tests/Unit/Services/Tools/`

---

## Implementation Tasks

### 1. Align Product Documentation

- [ ] Update the product docs so the runtime substrate contract is explicit:
  - Swarm is selected per production/infra artifact.
  - App-dev remains direct/host-backed for app/workspace dev runtime.
  - A mixed node can run Swarm-backed infra and app-dev side by side.
  - App/workspace processes are host Supervisor programs.
  - App `.env` dependency hosts must be reachable from both host processes and containers.
  - Node WireGuard IP service addresses are the Linux production default for DB/Redis-like dependencies.
  - macOS self-routing optimization is deferred.

- [ ] Remove or rewrite Docker-process-default wording in:
  - `apps/docs/content/tech-stack.md`
  - `apps/docs/content/concepts.md`
  - `apps/docs/content/execution-lanes.md`
  - `apps/docs/content/domains/7_process/README.md`
  - `apps/docs/content/domains/7_process/process-concepts.md`
  - `apps/docs/content/domains/6_workspace/workspace-concepts.md`
  - `apps/docs/content/testing/README.md`

- [ ] Add a short process-runtime rule to the process docs:

```md
App and workspace process commands run as Supervisor programs on the owning node.
They execute from the app or workspace directory and use the same `.env` values
as the HTTP runtime. Docker service names are not valid app `.env` dependency
hosts because the same release may also be executed by host Supervisor.
```

- [ ] Add a short service-address rule to the database/Redis docs:

```md
For node-local production services, Orbit writes dependency hosts as the owner
node WireGuard IP. On Linux this address resolves locally when the owner node
connects to itself, while remote nodes route over WireGuard. Services bind or
publish on the WireGuard address and are firewalled away from public interfaces.
```

- [ ] Update process and tool product docs before implementation so the strict
  boundary is explicit:
  - A tool is a host-installed capability such as PHP, Composer, OpenCode, or
    PolyScope. A tool can be installed, updated, adopted, removed, configured,
    and diagnosed as capability state, but it does not own lifecycle/logs.
  - A process is a runnable unit with owner, runtime, version/config, start,
    stop, restart, logs, and state.
  - Runnable services such as MySQL, PostgreSQL, Redis, FrankenPHP, Horizon,
    OpenCode Server, and PolyScope Server are processes.
  - A process may reference a tool with `--tool=<tool>` when its command
    depends on a host-installed capability, for example `opencode serve`, but
    the process still owns lifecycle.
  - Process definitions declare supported versions and runtimes for services
    such as MySQL, PostgreSQL, and Redis.
  - Runtime support is platform-scoped: `docker-swarm` on Linux/Ubuntu and
    `docker-swarm` on macOS are separate runtime implementations behind the
    same public runtime name.
  - Unsupported process runtime/platform combinations fail before side effects
    with `process.runtime_platform_unsupported`.
  - Tool docs must include negative boundary language: MySQL, PostgreSQL, and
    Redis are not tool lifecycle units.

- [ ] Add command-contract docs for interactive and non-interactive process definition behavior:

```md
In interactive mode, `orbit process:add` prompts for required process
definition fields before side effects when a selected definition has multiple
supported versions or runtimes.

In non-interactive mode, missing required process definition, version, owner, or
runtime input fails before side effects with `validation_failed`. An unsupported
runtime fails before side effects with `process.runtime_unsupported`. A runtime
supported by the process definition but not by the target node platform fails
before side effects with `process.runtime_platform_unsupported`. A command
targeting an ambiguous process definition must fail with an explicit process
selector error unless interactive input can prompt for the target process.
```

- [ ] Run docs verification:

```bash
composer docs-lint
```

### 2. Add Process Definition And Runtime State

- [ ] Add tests proving MySQL, PostgreSQL, and Redis are cataloged as process
  service definitions, not tool definitions:
  - `ToolCatalog` does not support `mysql`, `postgres`, or `redis`.
  - `ProcessServiceDefinitionRegistry` resolves each service definition.
  - process runtime config contains no `orbit.tool` labels for those services.

- [ ] Add process definition tests proving version request resolution:
  - `8` resolves to MySQL version family `8` and latest supported MySQL 8
    version.
  - `8.4` resolves to MySQL version family `8` and expected version `8.4`.
  - unsupported major families fail before side effects.
  - specific versions outside the process definition's support policy fail
    before side effects.

- [ ] Store version, runtime, runtime config, credentials, endpoints, volumes,
  health checks, and deterministic spec hashes on process-owned state. Do not
  add service version/runtime fields to `NodeTool`.

- [ ] Process ownership must be explicit and extensible for node-level,
  role-level, app-level, workspace-level, or tool-related processes. Do not
  backfill production data for this migration; Orbit is not production-used yet.

- [ ] Run focused process definition and tool-boundary tests:

```bash
bin/orbit-gateway-pest --compact tests/Unit/Services/Processes/ProcessServiceDefinitionRegistryTest.php tests/Unit/Services/Tools/ToolCatalogTest.php tests/Unit/Services/Tools/ToolProcessBoundaryTest.php
```

### 3. Add Process Runtime Drivers And Runtime Intents

- [ ] Add platform-resolution tests for the process runtime registry:
  - `docker-swarm` + `ubuntu_24-04` resolves to the Linux Swarm
    implementation.
  - `docker-swarm` + `macos_15-4` fails with
    `process.runtime_platform_unsupported`.
  - `docker` + `ubuntu_24-04` resolves to the Linux Docker implementation.
  - `systemd` resolves only where real systemd service management is available.
  - future runtime families can register platform implementations without
    changing the public process command contract.

- [ ] Add failing tests for a `ProcessRuntimeDriverRegistry`:
  - resolves `docker` to a standalone Docker process runtime driver.
  - resolves `docker-swarm` to a Swarm service process runtime driver.
  - resolves `supervisor` to a host Supervisor process runtime driver.
  - resolves `systemd` to a Linux node service process runtime driver.
  - rejects runtimes not declared by the process definition.
  - rejects runtimes not supported by the target node platform before side
    effects.

- [ ] Add or update a runtime-platform resolver that normalizes existing
  node platform strings such as `ubuntu`, `ubuntu_24-04`, `linux`, and
  `macos_15-4` into platform families and implementation keys. Use the existing
  `nodes.platform` record; do not add a second OS field.

- [ ] Add a process runtime intent that carries:
  - process name;
  - process definition;
  - expected version;
  - version family;
  - runtime;
  - service/container/program name;
  - published or bound host/port;
  - volume name/path;
  - endpoint metadata;
  - healthcheck;
  - update strategy;
  - deterministic spec hash.

- [ ] Keep macOS implementations explicitly unsupported in this slice unless a
  process definition and platform-specific implementation are added in a future
  plan. Do not treat Docker Desktop as equivalent to Linux Docker/Swarm.

- [ ] Keep singleton stateful database defaults conservative:
  - MySQL/PostgreSQL/Redis Swarm services default to guarded stop-first update
    unless the definition declares a safe replicated update strategy.
  - Stateless service processes may opt in to start-first rolling updates.

- [ ] Make runtime intent rendering allocate distinct service names, ports,
  volumes, endpoint names, and labels per process name/version family:

```text
mysql8 -> orbit-mysql8, volume orbit-mysql8, endpoint mysql8
mysql9 -> orbit-mysql9, volume orbit-mysql9, endpoint mysql9
```

- [ ] Add conflict checks before process creation/update:
  - duplicate process name on the same owner fails with `process.exists`;
  - port conflicts fail with `process.endpoint_conflict`;
  - unsupported runtime fails with `process.runtime_unsupported`;
  - unsupported version request fails with `process.version_unsupported`.

- [ ] Run focused runtime-driver tests:

```bash
bin/orbit-gateway-pest --compact tests/Unit/Services/Processes/ProcessRuntimeDriverRegistryTest.php tests/Unit/Services/Processes/ProcessRuntimeDriversTest.php
```

### 4. Update Process Commands For Definition And Runtime Selection

- [ ] Update process docs and CLI/API contracts to accept definition, version,
  runtime, owner, and optional related tool fields. Final CLI shape is owned by
  solo todo #681; until then, tests should focus on gateway process API and
  registry behavior.

```bash
orbit process:add mysql8 --definition=mysql --version=8 --runtime=docker-swarm --node=database-1 --json
orbit process:add opencode-server --runtime=systemd --tool=opencode --command="opencode serve --hostname=0.0.0.0" --node=operator-1 --json
```

- [ ] Interactive `process:add` prompts before side effects when a selected
  process definition needs a version, runtime, owner, or name.

- [ ] Non-interactive `process:add --json` fails before side effects when a
  required definition field has no unambiguous default.

- [ ] Process lifecycle/logs/credentials/reload/reconfigure/update command
  contracts target concrete process rows by process selector. Tool lifecycle
  compatibility commands may only delegate to exactly one related process and
  must fail when no related process or multiple related processes exist.

- [ ] Update gateway request DTOs, API validation, stream payloads, JSON
  renderers, and command tests so process `definition`, `version`, `runtime`,
  `owner`, and optional `tool` are stable request/response fields.

- [ ] Run CLI and gateway process command/API tests after #681 finalizes the
  command shape:

```bash
bin/orbit-gateway-pest --compact tests/Feature/Http/Api/ProcessStoreControllerTest.php tests/Feature/Http/Api/ProcessUpdateControllerTest.php tests/Feature/Http/Api/ProcessListControllerTest.php
(cd apps/cli && php vendor/bin/pest --compact tests/Feature/Commands/Process)
```

### 5. Implement MySQL/Redis Swarm And Docker Process Runtime Vertical Slice

- [ ] Add MySQL/Redis process definition tests proving two version families can
  coexist on one node as separate process rows:

```php
it('defines mysql 8 and mysql 9 as separate process service instances on one node', function (): void {
    $node = Node::factory()->database()->create();

    $mysql8 = app(ProcessServiceDefinitionRegistry::class)->resolve('mysql', [
        'name' => 'mysql8',
        'version' => '8.4',
        'runtime' => 'docker-swarm',
        'node' => $node->name,
    ]);

    $mysql9 = app(ProcessServiceDefinitionRegistry::class)->resolve('mysql', [
        'name' => 'mysql9',
        'version' => '9.0',
        'runtime' => 'docker-swarm',
        'node' => $node->name,
    ]);

    expect($mysql8->versionFamily)->toBe('8')
        ->and($mysql9->versionFamily)->toBe('9');
});
```

- [ ] Make process creation/update persist a process row and dispatch to the
  selected process runtime driver. Do not create `NodeTool` rows for MySQL,
  PostgreSQL, Redis, or other always-runnable service instances.

- [ ] Make process lifecycle managers, process credentials readers, process log
  readers/followers, `ProcessesProbe`, and process fixers resolve the concrete
  process row before dispatch.

- [ ] Add MySQL and Redis runtime-intent tests for both `docker` and
  `docker-swarm`.

- [ ] Add endpoint tests proving version-family services get distinct ports and
  endpoint names, and that conflicts fail before side effects.

- [ ] Run focused MySQL/Redis process tests and tool boundary guards:

```bash
bin/orbit-gateway-pest --compact tests/Unit/Services/Processes/ProcessServiceDefinitionRegistryTest.php tests/Feature/Http/Api/ProcessStoreControllerTest.php tests/Unit/Services/Tools/ToolCatalogTest.php tests/Unit/Services/Tools/ToolProcessBoundaryTest.php --filter='mysql|redis|runtime|definition|boundary'
```

### 6. Make Supervisor The App/Workspace Process Runtime

- [ ] Add or update tests proving Supervisor is the default for every app process:

```php
it('defaults php app processes to supervisor runtime', function () {
    $app = App::factory()->phpRuntime()->create();

    expect(ProcessRuntime::defaultForApp($app))->toBe(ProcessRuntime::Supervisor);
});
```

- [ ] Add controller tests proving the public process API no longer accepts Docker process runtime input:

```php
it('rejects docker as a process runtime', function () {
    $app = App::factory()->create();

    actingAs($this->user)
        ->postJson(route('apps.processes.store', $app), [
            'name' => 'horizon',
            'command' => 'php artisan horizon',
            'runtime' => 'docker',
        ])
        ->assertJsonValidationErrors('runtime');
});
```

- [ ] Change `ProcessRuntime::defaultForApp()` so it always returns `ProcessRuntime::Supervisor`.

- [ ] Change `apps/gateway/app/Models/Process.php` default attributes so new rows default to `supervisor`.

- [ ] Add a migration that converts existing Docker process runtime rows to Supervisor:

```php
DB::table('processes')
    ->where('runtime', 'docker')
    ->update(['runtime' => 'supervisor']);
```

- [ ] Update process store/update validation so accepted runtime values are only:

```php
['supervisor']
```

- [ ] Keep the database column during this change so old nodes can be migrated cleanly, but make `supervisor` the only public value.

### 7. Remove Docker Process Runtime Dispatch

- [ ] Update `EnsureAppProcessRuntimeUnits` so it renders Supervisor programs for app/workspace processes.

- [ ] Keep stale Docker process cleanup during convergence:
  - remove stale process containers named with the previous process runtime naming convention;
  - remove stale process runtime network artifacts created only for process sidecars;
  - then render and install the Supervisor program.

- [ ] Update `AddProcess`, `EditProcess`, `StartProcesses`, `StopProcesses`, and `RestartProcesses` so process lifecycle actions target Supervisor.

- [ ] Remove process-runtime Docker dependencies from the gateway application:
  - `apps/gateway/app/Data/Processes/ProcessDockerContainer.php`
  - `apps/gateway/app/Renderers/Processes/ProcessDockerContainerRenderer.php`
  - `apps/gateway/app/Managers/Processes/ProcessDockerRuntimeManager.php`

- [ ] Update `DockerCommandBuilder` and its tests so it no longer references `ProcessDockerContainer`.

- [ ] Update process tests so the expected converge command flow is:

```text
supervisorctl reread
supervisorctl update
supervisorctl restart <program>
```

- [ ] Run the focused process test suite:

```bash
bin/orbit-gateway-pest --compact tests/Feature/Services/Processes
```

### 8. Run Supervisor Processes As The Owning Runtime User

- [ ] Add tests proving process programs use the app/workspace runtime user rather than the generic node user:

```php
it('renders app process programs as the owning app user', function () {
    $node = Node::factory()->create(['user' => 'orbit']);
    $app = App::factory()->for($node)->create([
        'path' => '/home/docs/app',
    ]);

    $process = Process::factory()->for($app)->create([
        'command' => 'php artisan horizon',
    ]);

    $program = app(SupervisorProgramRenderer::class)->render($process);

    expect($program)->toContain('user=docs');
    expect($program)->toContain('directory=/home/docs/app');
});
```

- [ ] Update `SupervisorProgramRenderer` to resolve:
  - app process user from the app runtime user resolver;
  - workspace process user from the workspace runtime user resolver;
  - directory from the exact app or workspace path.

- [ ] Keep the Supervisor control action owned by the Orbit/operator SSH user. Only the managed program's `user=` value becomes the app/workspace runtime user.

- [ ] Verify rendered programs for:
  - app-level process;
  - workspace-level process;
  - static app with Supervisor runtime;
  - missing path/user failure message.

### 9. Ensure App-Dev Nodes Have Supervisor Available

- [ ] Add or update role baseline tests proving app-dev converges Supervisor:

```php
it('includes supervisor in the app development role baseline', function () {
    $baseline = app(AppDevelopmentRoleBaseline::class);

    expect($baseline->tools())->toContain('supervisor');
});
```

- [ ] Update `AppDevelopmentRoleBaseline` to include Supervisor.

- [ ] Keep `AppProductionRoleBaseline` Supervisor convergence in place.

- [ ] Update node role docs so app-dev is described as:
  - host/direct app workspace runtime;
  - host Supervisor process manager;
  - compatible with Swarm-backed infra on the same node.

### 10. Define The WireGuard-IP Service Address Contract

- [ ] Add focused tests for service-address selection:

```php
it('uses the dependency owner node wireguard ip as the app database host', function () {
    $databaseNode = Node::factory()->create(['wireguard_address' => '10.6.0.7']);
    $appNode = Node::factory()->create(['wireguard_address' => '10.6.0.8']);

    $address = app(NodeWireGuardServiceAddress::class)->forServiceOn($databaseNode, $appNode);

    expect($address)->toBe('10.6.0.7');
});
```

- [ ] Create a small `NodeWireGuardServiceAddress` service that returns the owner node WireGuard IP for DB/Redis-like service hosts and fails clearly when the owner node has no WireGuard IP.

- [ ] Use that service anywhere Orbit writes app dependency host values for managed database/Redis services.

- [ ] Keep app `.env` writes identical for host Supervisor and container HTTP runtimes.

- [ ] Do not add Docker-specific dependency host overrides to FrankenPHP service environment rendering.

- [ ] Add docs stating that managed DB/Redis services must bind or publish on the owner node WireGuard IP and be protected by firewall rules.

### 11. Record Linux Self-Route Health Checks

- [ ] Add a Linux-only probe helper for node-local service diagnostics:

```bash
ip route get 10.6.0.7
```

Expected local-owner output includes a local route for the exact address, such as `local 10.6.0.7 dev lo`.

- [ ] Add the probe to process/database doctor output when the dependency host equals the current node WireGuard IP.

- [ ] The doctor must report macOS as unsupported for this local-route optimization in this plan:

```text
macOS self-WireGuard local routing is not validated by this doctor. Use the macOS follow-up plan.
```

- [ ] Do not change macOS routing in this plan.

### 12. Align Swarm Substrate Docs With Existing Gateway/App-Prod Plans

- [ ] Cross-reference this plan from:
  - `docs/superpowers/plans/2026-06-01-orbit-gateway-swarm-update-runner.md`
  - `docs/superpowers/plans/2026-06-02-app-prod-frankenphp-runtime-isolation.md`

- [ ] In product docs, describe Swarm as the production/infra artifact backend used by:
  - gateway production service updates;
  - app-prod FrankenPHP service updates;
  - Orbit-managed infra services that need durable process supervision.

- [ ] State that Swarm does not replace:
  - host Supervisor for app/workspace process commands;
  - direct app-dev workspace behavior;
  - WireGuard/VPN DNS substrate.

- [ ] State that Orbit Caddy ingress can run in Swarm, but normal app-prod zero downtime does not require custom Caddy blue/green routing because brief mixed-version Swarm traffic is accepted.

- [ ] State that backend `orbit-caddy` Swarm ownership is role-scoped. The
  gateway plan's router-colocated gateway API route remains a router-role Caddy
  concern; this plan must not imply a gateway-owned Caddy service or rewrite the
  gateway exposure-mode contract.

### 13. Verification

- [ ] Read `apps/docs/content/testing/README.md` before running integrated or E2E checks.

- [ ] Run focused tests:

```bash
bin/orbit-gateway-pest --compact tests/Feature/Services/Processes
bin/orbit-gateway-pest --compact tests/Unit/Services/Processes/ProcessServiceDefinitionRegistryTest.php tests/Unit/Services/Processes/ProcessRuntimeDriverRegistryTest.php tests/Unit/Services/Processes/ProcessRuntimeDriversTest.php tests/Unit/Services/Processes/ProcessDockerContainerRendererTest.php tests/Unit/Services/Processes/ProcessesProbeTest.php
bin/orbit-gateway-pest --compact tests/Feature/Http/Api/ProcessStoreControllerTest.php tests/Feature/Http/Api/ProcessListControllerTest.php tests/Feature/Http/Api/ProcessUpdateControllerTest.php
bin/orbit-gateway-pest --compact tests/Unit/Services/Tools/ToolCatalogTest.php tests/Unit/Services/Tools/ToolProcessBoundaryTest.php
(cd apps/cli && php vendor/bin/pest --compact tests/Feature/Commands/Process)
bin/orbit-gateway-pest --compact --filter=AppDevelopmentRoleBaseline
bin/orbit-gateway-pest --compact --filter=NodeWireGuardServiceAddress
bin/orbit-gateway-pest --compact tests/Unit/Services/Nodes/NodeWireGuardSelfRouteProbeTest.php tests/Unit/Services/DatabaseConnections/DatabaseConnectionProbeTest.php tests/Unit/Services/DatabaseConnections/DatabaseConnectionRestorerTest.php tests/Unit/Services/DatabaseConnections/DatabaseConnectionEnvMapperTest.php tests/Unit/Services/Doctor/DoctorReportRunnerTest.php
```

- [ ] Run formatting after PHP edits:

```bash
bin/orbit-gateway-vendor-bin pint --dirty --format agent
```

- [ ] Run docs lint:

```bash
composer docs-lint
```

- [ ] Run full quality check before handoff:

```bash
composer quality-check
```

- [ ] Run `composer test:e2e` when implementation changes integrated topology
      behavior for prepared Docker/Incus feature lanes.

```bash
composer test:e2e
```

- [ ] Run provider-specific provisioning verification only when implementation
      changes installer behavior, fresh host mutation, image/topology
      preparation, WireGuard provisioning, systemd provisioning, or production
      artifact preparation. Do not run provider provision gates as a generic
      post-`composer test:e2e` step, and do not run provision gates for a
      documentation-only verification sweep.

---

## Acceptance Criteria

- Product docs no longer state that PHP app/workspace processes default to Docker runtime units.
- Runtime execution lane docs no longer describe app/workspace/process
  containers as the default process-management baseline.
- Tool docs distinguish host-installed capabilities from runnable processes and
  explicitly prevent MySQL, PostgreSQL, Redis, FrankenPHP, Horizon, OpenCode
  Server, and PolyScope Server from being documented as tool lifecycle units.
- Process command/API docs support interactive process definition, version,
  owner, and runtime selection before side effects.
- Process definitions declare supported runtimes, and unsupported runtimes fail
  before side effects.
- Runtime support is resolved by runtime family plus `Node.platform`; unsupported
  runtime/platform combinations fail before side effects with
  `process.runtime_platform_unsupported`.
- `docker-swarm` on Ubuntu/Linux is production-supported in this slice, while
  macOS Docker Desktop/Swarm semantics remain unsupported until a
  platform-specific implementation is designed.
- A node can run MySQL 8 and MySQL 9 as separate `mysql` process rows with
  distinct ports, volumes, credentials, endpoints, and runtime intent hashes.
- Process update behavior updates within the stored runtime and does not
  silently migrate `docker` processes to `docker-swarm`.
- New and updated app/workspace processes converge as Supervisor programs.
- The public process API rejects `runtime=docker`.
- Existing `runtime=docker` process rows migrate to `supervisor`.
- Supervisor programs run as the resolved app/workspace user and from the exact app/workspace directory.
- App-dev nodes converge Supervisor without turning app-dev web runtime into Swarm.
- Swarm-backed infra services and direct app-dev behavior can coexist on the same node.
- App `.env` dependency hosts are not Docker service names and are valid from host Supervisor and FrankenPHP containers.
- Managed DB/Redis process service hosts use owner node WireGuard IPs.
- WireGuard service-address readiness is provisioning/topology infrastructure,
  not an app, process, tool, or database runtime prerequisite.
- Linux node-local access to the node's own WireGuard IP is documented and diagnostically checked.
- macOS WireGuard self-route behavior is explicitly deferred to a separate plan.
- App-prod FrankenPHP zero downtime is documented as Swarm `start-first` with
  health checks and graceful old-task draining; concrete implementation remains
  in the app-prod runtime isolation follow-up.
