# Production Swarm Substrate And Process Runtime Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Align Orbit's production runtime substrate so Docker Swarm is a selectable, platform-scoped production/infra service backend behind managed tools, app/workspace configured processes run through host Supervisor on the owning node, app-prod FrankenPHP services have a shared Swarm/release-mount contract to consume, and app dependency addresses work consistently from both host processes and containers without Docker-specific `.env` values.

**Architecture:** Runtime backend selection is per managed artifact, not a whole-node mode. A node can run Swarm services for production/infra workloads, standalone Docker services for simpler tool instances, standalone/direct app-dev runtime where appropriate, and host Supervisor programs for app/workspace processes. Managed service tools such as MySQL, PostgreSQL, and Redis resolve `tool:install <tool> --version=<major-or-version> --runtime=<runtime>` into concrete version-family tool instances with runtime intents. The runtime registry then resolves the selected runtime plus the target node's recorded platform, such as `docker-swarm` + `ubuntu_24-04`, to a platform-specific implementation. App dependency hosts use node WireGuard IP service addresses, so the same `.env` is valid for host Supervisor processes and FrankenPHP containers. Linux self-WireGuard routing is treated as the production baseline. macOS self-route optimization is intentionally out of scope for this plan.

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
- First-class tool runtime drivers, supported runtime metadata, install-time
  `--runtime` selection, and version-family tool instances for service tools.
- Platform-scoped runtime implementations below the user-facing runtime
  family, using the existing node platform record.
- App/workspace configured processes moving from Docker sidecars to host
  Supervisor.
- App-dev Supervisor availability without converting app-dev web runtime to
  Swarm.
- WireGuard-IP service addressing for app dependency `.env` values.
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

9. Managed service tools resolve into version-family tool instances.
   - The public selector remains the base tool name, for example `mysql`,
     `postgres`, or `redis`.
   - Interactive `orbit tool:install mysql` prompts for a supported version
     request when the tool definition has more than one supported version
     family.
   - Non-interactive installs use `--version=<major-or-specific-version>`, for
     example `--version=8` or `--version=8.4`.
   - The version request resolves to a version family and expected version. For
     MySQL, `--version=8` creates the internal instance key `mysql:8`; a
     specific `--version=8.4` still belongs to version family `8`.
   - A node may run one instance per `(tool, version family)` by default, such
     as MySQL 8 and MySQL 9 on the same database node.
   - Two instances for the same `(tool, version family)` on the same node are
     out of scope unless a later plan adds explicit advanced instance aliases.

10. Tool runtime is selected at install time and constrained by the tool definition.
    - Tool definitions declare their supported runtimes, for example
      `docker` and `docker-swarm`.
    - `orbit tool:install mysql --version=8.4 --runtime=docker-swarm` installs
      the MySQL 8 family through the Swarm runtime driver.
    - `orbit tool:install mysql --version=8.4 --runtime=docker` installs the
      same product instance through the standalone Docker runtime driver.
    - The resolved runtime is stored on the tool instance and reused by
      lifecycle, logs, credentials, update, reconfigure, doctor, and fix paths.
    - `tool:update` may update version/config within the stored runtime. It
      must not silently migrate a tool instance from `docker` to `docker-swarm`.
      Runtime migration requires a future explicit guarded command or
      destructive reconfigure path.

11. Runtime support is platform-scoped below the user-facing runtime name.
    - The public runtime value is a runtime family, such as `docker`,
      `docker-swarm`, `apt-systemd`, `homebrew`, or a future `kubernetes`.
    - The runtime driver registry resolves runtime family plus target
      `Node.platform` to a concrete implementation, such as
      `docker-swarm/ubuntu`, `docker/macos`, `apt-systemd/ubuntu`, or
      `homebrew/macos`.
    - `docker-swarm` on Ubuntu/Linux is the production-supported
      implementation in this plan.
    - `docker-swarm` on macOS is not production-equivalent to Linux Docker
      because Docker Desktop networking, bind mounts, WireGuard self-routing,
      and published-port semantics differ. It must fail with
      `tool.runtime_platform_unsupported` until a macOS-specific implementation
      is explicitly added.
    - Homebrew is a plausible future `homebrew/macos` runtime implementation
      for client/local tools, but it is not part of this implementation slice.
    - Tool definitions declare runtime support with platform eligibility, not
      just a flat list of runtime names.

12. Swarm improves convergence but does not promise zero-downtime singleton databases.
    - Stateless or replicated service tools may use Swarm rolling update
      behavior when their definition declares that strategy.
    - Singleton stateful tools such as MySQL, PostgreSQL, and Redis default to
      conservative stop-first or guarded update behavior unless their tool
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
- `apps/docs/content/domains/3_tool/catalog/mysql.md`
- `apps/docs/content/domains/3_tool/catalog/postgres.md`
- `apps/docs/content/domains/3_tool/catalog/redis.md`
- `apps/docs/content/domains/3_tool/1_tool-list/tool-list.md`
- `apps/docs/content/domains/3_tool/2_tool-show/tool-show.md`
- `apps/docs/content/domains/3_tool/3_tool-install/tool-install.md`
- `apps/docs/content/domains/3_tool/7_tool-restart/tool-restart.md`
- `apps/docs/content/domains/3_tool/8_tool-logs/tool-logs.md`
- `apps/docs/content/domains/3_tool/9_tool-update/tool-update.md`
- `apps/docs/content/domains/3_tool/10_tool-credentials/tool-credentials.md`
- `apps/docs/content/domains/3_tool/11_tool-reload/tool-reload.md`
- `apps/docs/content/domains/3_tool/12_tool-reconfigure/tool-reconfigure.md`
- `apps/docs/content/testing/README.md`
- `apps/cli/app/Commands/Tool/ToolInstallCommand.php`
- `apps/cli/app/Commands/Tool/ToolGatewayCommand.php`
- `apps/cli/app/Commands/Tool/ToolActionCommand.php`
- `apps/cli/app/Commands/Tool/ToolUpdateCommand.php`
- `apps/gateway/database/migrations/2026_05_06_014625_create_node_tools_table.php`
- New gateway migration adding tool instance/runtime fields.
- `apps/gateway/app/Models/NodeTool.php`
- `apps/gateway/app/Contracts/ToolDefinition.php`
- `apps/gateway/app/Services/Tools/ToolCatalog.php`
- `apps/gateway/app/Services/Tools/ToolRegistry.php`
- `apps/gateway/app/Services/Tools/ToolInstaller.php`
- `apps/gateway/app/Services/Tools/ToolUpdater.php`
- `apps/gateway/app/Services/Tools/ToolLifecycleManager.php`
- `apps/gateway/app/Services/Tools/ToolCredentialsReader.php`
- `apps/gateway/app/Services/Tools/ToolLogReader.php`
- `apps/gateway/app/Services/Tools/ToolLogFollower.php`
- `apps/gateway/app/Services/Tools/ToolReconfigurer.php`
- `apps/gateway/app/Services/Tools/ToolPayloadMapper.php`
- `apps/gateway/app/Services/Tools/ToolsProbe.php`
- `apps/gateway/app/Services/Tools/ToolsFixer.php`
- New `apps/gateway/app/Services/Tools/Runtime/**` value objects, runtime
  drivers, runtime-driver registry, version resolver, and instance selector.
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

- [ ] Update tool product docs before implementation so the new tool contract is explicit:
  - A tool definition describes one product capability family, such as `mysql`.
  - A tool instance is one installed version family on one node, such as
    `mysql:8`.
  - `tool:install <tool> --version=<major-or-specific-version>` resolves the
    instance key and expected version.
  - `tool:install <tool> --runtime=<docker|docker-swarm>` selects an install
    runtime from the tool definition's supported runtimes.
  - Runtime support is platform-scoped: `docker-swarm` on Linux/Ubuntu and
    `docker-swarm` on macOS are separate runtime implementations behind the
    same public runtime name.
  - Unsupported runtime/platform combinations fail before side effects with
    `tool.runtime_platform_unsupported`.
  - `tool:update` updates within the stored runtime and does not migrate
    runtimes.
  - `tool:list` groups version-family instances under the base tool name in
    human output while JSON exposes stable instance fields.

- [ ] Add command-contract docs for interactive and non-interactive install behavior:

```md
In interactive mode, `orbit tool:install mysql` prompts for a version request
when the MySQL definition supports more than one version family. It prompts for
runtime when more than one runtime is supported and no node/default policy can
choose unambiguously.

In non-interactive mode, missing required version or runtime input fails before
side effects with `validation_failed`. An unsupported runtime fails before side
effects with `tool.runtime_unsupported`. A runtime supported by the tool but
not by the target node platform fails before side effects with
`tool.runtime_platform_unsupported`. A command targeting a base tool with
multiple matching instances fails with `tool.instance_required` unless
interactive input can prompt for the target version.
```

- [ ] Run docs verification:

```bash
composer docs-lint
```

### 2. Add Tool Instance And Runtime Driver State

- [ ] Add migration tests proving existing rows backfill to the default instance and runtime:

```php
it('backfills existing tool rows to the default instance and runtime', function (): void {
    $node = Node::factory()->create();

    NodeTool::query()->create([
        'node_id' => $node->id,
        'name' => 'redis',
        'expected_state' => 'running',
    ]);

    $tool = NodeTool::query()->where('name', 'redis')->firstOrFail();

    expect($tool->instance_key)->toBe('redis:default')
        ->and($tool->version_family)->toBeNull()
        ->and($tool->runtime)->toBe('docker');
});
```

- [ ] Add a migration that adds nullable/backfilled tool-instance columns:
  - `instance_key`, defaulting existing rows to `<tool>:default`;
  - `version_family`, nullable for non-version-family tools;
  - `runtime`, defaulting existing Docker-backed service tools to `docker`;
  - `runtime_config`, nullable JSON for runtime-driver-owned state.

- [ ] Replace the current unique key with a uniqueness rule that permits multiple version families:

```php
$table->unique(['node_id', 'name', 'instance_key']);
```

- [ ] Keep existing single-instance tools on `instance_key=<tool>:default` so old command behavior remains stable.

- [ ] Update `NodeTool` casts/fillable/PHPDoc for the new fields and keep credentials encrypted.

- [ ] Add `ToolInstanceSelector`, `ToolVersionRequest`, and `ToolRuntimeSelection` value objects under `apps/gateway/app/Services/Tools/Runtime/`.

- [ ] Add tests proving version request resolution:
  - `8` resolves to version family `8` and latest supported MySQL 8 version.
  - `8.4` resolves to version family `8` and expected version `8.4`.
  - unsupported major families fail before side effects.
  - specific versions outside the tool definition's support policy fail before side effects.

- [ ] Extend `ToolDefinition` and `ToolCatalog` with supported runtimes and version-family metadata without duplicating `mysql-8` or `mysql-9` as separate catalog definitions.

- [ ] Add runtime metadata to MySQL, PostgreSQL, and Redis definitions first. Leave non-service and observational tools on a default/single runtime path unless their definition explicitly opts in.

- [ ] Run focused tool catalog tests:

```bash
bin/orbit-gateway-pest --compact apps/gateway/tests/Unit/Services/Tools/ToolCatalogTest.php
```

### 3. Add Tool Runtime Drivers And Runtime Intents

- [ ] Add platform-resolution tests for the runtime registry:
  - `docker-swarm` + `ubuntu_24-04` resolves to the Linux Swarm implementation;
  - `docker-swarm` + `macos_15-4` fails with
    `tool.runtime_platform_unsupported`;
  - `docker` + `ubuntu_24-04` resolves to the Linux Docker implementation;
  - future runtime families can register platform implementations without
    changing the public command contract.

- [ ] Add failing tests for a `ToolRuntimeDriverRegistry`:
  - resolves `docker` to a standalone Docker runtime driver;
  - resolves `docker-swarm` to a Swarm service runtime driver;
  - rejects runtimes not declared by the tool definition;
  - rejects runtimes not supported by the target node platform before side effects.

- [ ] Add a runtime-platform value object or resolver that normalizes existing
  node platform strings such as `ubuntu`, `ubuntu_24-04`, `linux`, and
  `macos_15-4` into platform families and implementation keys. Use the existing
  `nodes.platform` record; do not add a second OS field.

- [ ] Add a `ToolRuntimeIntent` value object that carries:
  - tool name;
  - instance key;
  - expected version;
  - version family;
  - runtime;
  - service/container name;
  - published or bound host/port;
  - volume name/path;
  - endpoint metadata;
  - healthcheck;
  - update strategy;
  - deterministic spec hash.

- [ ] Add `DockerToolRuntimeDriver` and `DockerSwarmToolRuntimeDriver` interfaces plus platform-specific implementations, starting with Linux implementations for production role nodes. The registry may expose them as the public `docker` and `docker-swarm` runtime families.

- [ ] Keep macOS implementations explicitly unsupported in this slice unless a
  tool definition and platform-specific implementation are added in a future
  plan. Do not treat Docker Desktop as equivalent to Linux Docker/Swarm.

- [ ] Keep singleton stateful database defaults conservative:
  - MySQL/PostgreSQL/Redis Swarm services default to guarded stop-first update unless the definition declares a safe replicated update strategy.
  - Stateless service tools may opt in to start-first rolling updates.

- [ ] Make runtime intent rendering allocate distinct service names, ports, volumes, endpoint names, and labels per version family:

```text
mysql:8 -> orbit-mysql-8, volume orbit-mysql-8, endpoint mysql-8
mysql:9 -> orbit-mysql-9, volume orbit-mysql-9, endpoint mysql-9
```

- [ ] Add conflict checks before install:
  - duplicate `(node, tool, version family)` fails with `tool.instance_exists`;
  - port conflicts fail with `tool.endpoint_conflict`;
  - unsupported runtime fails with `tool.runtime_unsupported`;
  - unsupported version request fails with `tool.version_unsupported`.

- [ ] Run focused runtime-driver tests:

```bash
bin/orbit-gateway-pest --compact apps/gateway/tests/Unit/Services/Tools
```

### 4. Update Tool Commands For Version And Runtime Selection

- [ ] Update `tool:install` docs and CLI signature to accept:

```bash
orbit tool:install [tool] [--app=<app>] [--node=<node>] [--version=<version>] [--runtime=<docker|docker-swarm>] [--status=installed|running] [--json]
```

- [ ] Interactive `tool:install mysql` prompts before side effects:
  - prompt ID `tool_install.version`;
  - primitive `suggest`;
  - choices from the tool definition's supported version families/specific versions;
  - prompt ID `tool_install.runtime`;
  - primitive `select`;
  - choices from the tool definition's supported runtimes after node eligibility is known.

- [ ] Non-interactive `tool:install mysql --json` fails before side effects when the tool requires a version and no default can be selected.

- [ ] Update lifecycle/logs/credentials/reload/reconfigure/update command contracts and CLI payloads to accept `--version=<major-or-specific-version>` as the concrete instance selector when multiple instances exist:

```bash
orbit tool:restart mysql --version=8
orbit tool:logs mysql --version=9
orbit tool:credentials mysql --version=8
orbit tool:update mysql --version=8 --expected-version=8.4.6
```

- [ ] A base-tool command without `--version` targets the only matching instance when exactly one exists. If multiple matching instances exist, interactive mode prompts for the version and non-interactive mode fails with `tool.instance_required`.

- [ ] Update gateway request DTOs, API validation, stream payloads, JSON renderers, and command tests so `version` and `runtime` are stable request/response fields.

- [ ] Extend the canonical tool JSON entity with:

```json
{
  "name": "mysql",
  "instance": "mysql:8",
  "version_family": "8",
  "runtime": "docker-swarm",
  "version": "8.4",
  "endpoints": []
}
```

- [ ] Run CLI and gateway command tests:

```bash
bin/orbit-gateway-pest --compact apps/gateway/tests/Feature/Http/Api/ToolInstallControllerTest.php apps/gateway/tests/Feature/Http/Api/ToolUpdateControllerTest.php apps/gateway/tests/Feature/Http/Api/ToolShowControllerTest.php apps/gateway/tests/Feature/Http/Api/ToolListControllerTest.php
php apps/cli/vendor/bin/pest --compact apps/cli/tests/Feature/Commands/Tool
```

### 5. Implement MySQL/Redis Swarm And Docker Runtime Vertical Slice

- [ ] Add MySQL/Redis install tests proving two version families can coexist on one node:

```php
it('installs mysql 8 and mysql 9 as separate tool instances on one node', function (): void {
    $node = Node::factory()->database()->create();

    $mysql8 = app(ToolInstaller::class)->install('mysql', node: $node->name, expectedState: 'running', config: [
        'version' => '8.4',
        'runtime' => 'docker-swarm',
    ]);

    $mysql9 = app(ToolInstaller::class)->install('mysql', node: $node->name, expectedState: 'running', config: [
        'version' => '9.0',
        'runtime' => 'docker-swarm',
    ]);

    expect($mysql8['tool']['instance'])->toBe('mysql:8')
        ->and($mysql9['tool']['instance'])->toBe('mysql:9');
});
```

- [ ] Make `ToolInstaller` create/update a `NodeTool` row keyed by `(node, tool, instance_key)` and dispatch to the selected runtime driver instead of directly asking the catalog for a shell script.

- [ ] Make `ToolUpdater`, `ToolLifecycleManager`, `ToolReconfigurer`, `ToolCredentialsReader`, `ToolLogReader`, `ToolLogFollower`, `ToolsProbe`, and `ToolsFixer` resolve the concrete instance before dispatch.

- [ ] Keep the old default-instance path working for tools that have no version-family support.

- [ ] Add MySQL and Redis runtime-intent tests for both `docker` and `docker-swarm`.

- [ ] Add endpoint tests proving version-family services get distinct ports and endpoint names, and that conflicts fail before side effects.

- [ ] Run focused MySQL/Redis tool tests:

```bash
bin/orbit-gateway-pest --compact apps/gateway/tests/Unit/Services/Tools apps/gateway/tests/Feature/Http/Api/ToolInstallControllerTest.php --filter='mysql|redis|runtime|instance'
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
bin/orbit-gateway-pest --compact apps/gateway/tests/Feature/Services/Processes
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
bin/orbit-gateway-pest --compact apps/gateway/tests/Feature/Services/Processes
bin/orbit-gateway-pest --compact apps/gateway/tests/Unit/Services/Tools apps/gateway/tests/Feature/Http/Api/ToolInstallControllerTest.php apps/gateway/tests/Feature/Http/Api/ToolUpdateControllerTest.php apps/gateway/tests/Feature/Http/Api/ToolShowControllerTest.php apps/gateway/tests/Feature/Http/Api/ToolListControllerTest.php
php apps/cli/vendor/bin/pest --compact apps/cli/tests/Feature/Commands/Tool
bin/orbit-gateway-pest --compact --filter=AppDevelopmentRoleBaseline
bin/orbit-gateway-pest --compact --filter=NodeWireGuardServiceAddress
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

- [ ] Run provisioning/E2E verification when implementation changes host mutation, Supervisor convergence, Swarm services, or managed DB/Redis service binding:

```bash
composer test:e2e:provision
```

---

## Acceptance Criteria

- Product docs no longer state that PHP app/workspace processes default to Docker runtime units.
- Runtime execution lane docs no longer describe app/workspace/process
  containers as the default process-management baseline.
- Tool docs distinguish base tool definitions from installed version-family
  tool instances.
- `tool:install <tool>` supports interactive version/runtime selection before
  side effects.
- `tool:install <tool> --version=<major-or-specific-version>
  --runtime=<docker|docker-swarm>` resolves a concrete instance and runtime.
- Tool definitions declare supported runtimes, and unsupported runtimes fail
  before side effects.
- Runtime support is resolved by runtime family plus `Node.platform`; unsupported
  runtime/platform combinations fail before side effects with
  `tool.runtime_platform_unsupported`.
- `docker-swarm` on Ubuntu/Linux is production-supported in this slice, while
  macOS Docker Desktop/Swarm semantics remain unsupported until a
  platform-specific implementation is designed.
- A node can run MySQL 8 and MySQL 9 as separate `mysql` tool instances with
  distinct ports, volumes, credentials, endpoints, and runtime intent hashes.
- `tool:update` updates within the stored runtime and does not silently migrate
  `docker` instances to `docker-swarm`.
- New and updated app/workspace processes converge as Supervisor programs.
- The public process API rejects `runtime=docker`.
- Existing `runtime=docker` process rows migrate to `supervisor`.
- Supervisor programs run as the resolved app/workspace user and from the exact app/workspace directory.
- App-dev nodes converge Supervisor without turning app-dev web runtime into Swarm.
- Swarm-backed infra services and direct app-dev behavior can coexist on the same node.
- App `.env` dependency hosts are not Docker service names and are valid from host Supervisor and FrankenPHP containers.
- Managed DB/Redis service hosts use owner node WireGuard IPs.
- Linux node-local access to the node's own WireGuard IP is documented and diagnostically checked.
- macOS WireGuard self-route behavior is explicitly deferred to a separate plan.
- App-prod FrankenPHP zero downtime is documented as Swarm `start-first` with
  health checks and graceful old-task draining; concrete implementation remains
  in the app-prod runtime isolation follow-up.
