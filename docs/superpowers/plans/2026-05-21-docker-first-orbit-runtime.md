# Docker-First Orbit Runtime Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace Orbit's host-native PHP/Caddy/PHP-FPM substrate with a Docker-first runtime: host launcher plus `orbit-runtime`, Dockerized `orbit-caddy`, FrankenPHP PHP app containers, and Docker-backed PHP process execution.

**Architecture:** Documentation is aligned first, then failing focused tests are written, then implementation replaces each host-native runtime surface. E2E topology images are prepared early as implementation infrastructure, but actual E2E validation happens only after product code exists. The final product has one runtime model and no host PHP/PHP-FPM fallback.

**Tech Stack:** Laravel 13 CLI/API, PHP 8.5 inside containers, Docker Engine/CLI on hosts, Dockerized Caddy, FrankenPHP, Laravel Octane validation for worker mode, Pest 4, SQLite gateway state, WireGuard, existing `RemoteShell`, Docker and Incus E2E lanes.

---

## Status

**Source spec:** `docs/superpowers/specs/2026-05-21-docker-first-orbit-runtime-design.md`

**Current contract conflict:** Current docs and code still describe host PHP,
native Caddy, PHP-FPM pools, and Supervisor as the runtime model. This plan
intentionally replaces that contract.

**Execution rule:** Do not start product code changes until Task 1 product docs
are aligned and `composer docs-lint` passes.

**E2E rule:** Do not treat the migration as validated until product code is
implemented and Docker plus Incus/provision E2E lanes pass. Preparing topology
images is implementation work, not validation.

**References:**

- Design spec:
  `docs/superpowers/specs/2026-05-21-docker-first-orbit-runtime-design.md`
- FrankenPHP production docs: https://frankenphp.dev/docs/production/
- FrankenPHP Laravel docs: https://frankenphp.dev/docs/laravel/
- Laravel Octane docs: https://laravel.com/docs/13.x/octane

## Compatibility Baseline

Execute this plan in a dedicated worktree and rebase on the current mainline
before starting. Current docs, current tests, and current code in this
repository are the implementation inputs. Do not use external historical Orbit
repositories as reference material for this migration.

Temporary branch-local compatibility is allowed only while tests are being
rebuilt. The merged product must not expose a permanent PHP-FPM/FrankenPHP
runtime selector.

## Complexity

Files: 80+ | Modules: docs, node roles, tool catalog, gateway runtime, proxy,
PHP runtime, apps, workspaces, processes, deploy, scheduler, installer, E2E |
Risk: High

## File Map

### Product Docs

- Modify: `docs/architecture.md`
- Modify: `docs/tech-stack.md`
- Modify: `docs/concepts.md`
- Modify: `docs/domains/1_node/**`
- Modify: `docs/domains/2_gateway/**`
- Modify: `docs/domains/3_tool/**`
- Modify: `docs/domains/4_firewall/**`
- Modify: `docs/domains/5_app/**`
- Modify: `docs/domains/6_workspace/**`
- Modify: `docs/domains/7_process/**`
- Modify: `docs/domains/8_proxy/**`
- Modify: `docs/domains/9_schedule/**`
- Modify: `docs/domains/10_deploy/**`
- Modify: `docs/domains/11_operation/**`
- Modify: `docs/domains/14_php/**`
- Modify: `docs/domains/15_agent-ide/**`
- Modify: `TESTING.md`
- Modify: `docs/porting/testing-infrastructure.md` if present on the execution branch.

### Installer And Launcher

- Modify: `bin/install-orbit`
- Create: `bin/orbit`
- Create: `docker/orbit-runtime/Dockerfile`
- Create: `docker/orbit-runtime/entrypoint.sh`
- Create: `docker/orbit-caddy/Dockerfile`
- Create: `docker/orbit-caddy/Caddyfile`
- Modify: `config/orbit.php`

### Runtime Containers

- Create: `app/Services/Runtime/OrbitRuntimeContainer.php`
- Create: `app/Services/Runtime/OrbitRuntimeContainerRenderer.php`
- Create: `app/Services/Runtime/OrbitCaddyContainer.php`
- Create: `app/Services/Runtime/OrbitContainerNames.php`
- Create: `app/Services/Runtime/DockerCommandBuilder.php`
- Modify: `app/Services/RuntimeBackend/RuntimeBackendProbe.php`
- Modify: `app/Data/RuntimeBackend/RuntimeBackendProbeResult.php`

### Gateway Runtime

- Modify: `app/Services/Gateway/GatewayApiRuntimeInstaller.php`
- Modify: `app/Services/Gateway/CaddyGlobalConfig.php`
- Modify: `app/Console/Commands/Internal/BootstrapGatewayLocalCommand.php`
- Modify: `app/Console/Commands/OrbitSchedulerCommand.php`
- Modify: `app/Services/Schedules/OrbitSchedulerProgramRenderer.php`
- Modify: `app/Services/Schedules/OrbitScheduler.php`

### Node Roles And Tools

- Modify: `app/Services/Nodes/Roles/NodeRoleRegistry.php`
- Modify: `app/Services/Nodes/Roles/NodeRoleBaselineConverger.php`
- Modify: `app/Services/Nodes/NodesProbe.php`
- Modify: `app/Tools/CaddyTool.php`
- Modify: `app/Tools/ComposerTool.php`
- Modify: `app/Tools/DockerTool.php`
- Modify: `app/Tools/PhpCliTool.php`
- Modify: `app/Tools/PhpTool.php`
- Modify: `app/Tools/SupervisorTool.php`
- Modify: `app/Tools/VitePlusTool.php`
- Create: `app/Tools/FrankenPhpTool.php`

### App And Workspace Runtime

- Create: `app/Enums/Apps/AppRuntimeKind.php`
- Create: `app/Enums/Apps/PhpWorkerMode.php`
- Create: `app/Data/Apps/PhpWorkerConfig.php`
- Create: `app/Services/Apps/AppRuntimeContainerRenderer.php`
- Create: `app/Services/Apps/AppRuntimeContainerManager.php`
- Create: `app/Services/Workspaces/WorkspaceRuntimeContainerRenderer.php`
- Create: `app/Services/Workspaces/WorkspaceRuntimeContainerManager.php`
- Modify: `app/Actions/Apps/EnactAppRuntime.php`
- Modify: `app/Actions/Workspaces/CreateWorkspace.php`
- Modify: `app/Actions/Workspaces/SetupWorkspace.php`
- Modify: `app/Services/Apps/AppFpmPoolRenderer.php` or delete after replacement.
- Modify: `app/Services/Workspaces/WorkspaceFpmPoolRenderer.php` or delete after replacement.
- Modify: `app/Services/Php/PhpRuntimeCatalog.php`
- Modify: `app/Services/Php/PhpRuntimeManager.php`
- Modify: `app/Services/Php/PhpFpmServiceReloader.php` or delete after replacement.

### Proxy Runtime

- Modify: `app/Services/Proxy/ProxyRouteRenderer.php`
- Modify: `app/Services/Proxy/ProxyRouteIntent.php`
- Modify: `app/Services/Proxy/ProxyRouteProbe.php`
- Modify: `app/Services/Proxy/ProxyRouteFixer.php`
- Modify: `app/Services/Proxy/ProxyRouteQuery.php`
- Modify: `app/Actions/Apps/EnsureAppProxyRoute.php`
- Modify: `app/Services/Workspaces/EnsureWorkspaceProxyRoute.php`

### Process Runtime

- Create: `app/Enums/Processes/ProcessRuntime.php`
- Create: `app/Services/Processes/ProcessDockerContainerRenderer.php`
- Create: `app/Services/Processes/ProcessDockerRuntimeManager.php`
- Modify: `app/Models/Process.php`
- Modify: `app/Services/Processes/ProcessRuntimeUnitResolver.php`
- Modify: `app/Services/Processes/ProcessesProbe.php`
- Modify: `app/Services/Processes/SupervisorProgramRenderer.php`
- Modify: `app/Actions/Processes/AddProcess.php`
- Modify: `app/Actions/Processes/EditProcess.php`
- Modify: `app/Actions/Processes/StartProcesses.php`
- Modify: `app/Actions/Processes/StopProcesses.php`
- Modify: `app/Actions/Processes/RestartProcesses.php`
- Modify: `app/Actions/Processes/ShowProcessLogs.php`

### Commands And API

- Create: `app/Console/Commands/AppExecCommand.php`
- Create: `app/Console/Commands/WorkspaceExecCommand.php`
- Create: `app/Console/Commands/AppWorkerCommand.php`
- Create: `app/Http/Controllers/Api/AppExecController.php`
- Create: `app/Http/Controllers/Api/WorkspaceExecController.php`
- Create: `app/Http/Controllers/Api/AppWorkerController.php`
- Create: `app/Http/Requests/Api/AppExecApiRequest.php`
- Create: `app/Http/Requests/Api/WorkspaceExecApiRequest.php`
- Create: `app/Http/Requests/Api/AppWorkerApiRequest.php`
- Modify: `app/Console/Application.php`
- Modify: `routes/api.php`
- Modify: `config/librarian-command-docs/entities/app.php`
- Modify: `config/librarian-command-docs/entities/workspace.php`
- Modify: `config/librarian-command-docs/entities/process.php`

### Database

- Create: `database/migrations/2026_05_21_130000_add_docker_first_runtime_fields.php`
- Modify: `database/factories/AppFactory.php`
- Modify: `database/factories/WorkspaceFactory.php`
- Modify: `database/factories/ProcessFactory.php`

### E2E

- Modify: `docker/e2e/topology/Dockerfile`
- Modify: `docker/e2e/topology/Dockerfile.dockerignore`
- Modify: `app/E2E/Support/DockerTopologyBuilder.php`
- Modify: `app/E2E/Support/DockerInstance.php`
- Modify: `app/E2E/Support/E2ECommand.php`
- Modify: `app/E2E/Support/E2ETopologyCapabilities.php`
- Modify: `tests/Feature/E2ESupport/DockerRuntimeImageContractTest.php`
- Modify: `tests/Feature/E2ESupport/DockerTopologyBuilderTest.php`
- Modify: `tests/E2E/PreparedTopologyContractTest.php`
- Modify: `tests/E2E/RuntimeBackendHostInitTest.php`
- Modify: `tests/E2E/RuntimeBackendSchedulerTest.php`
- Modify: `tests/E2E/PhpRuntimeCommandsTest.php`
- Modify: `tests/E2E/ProcessCommandTest.php`
- Modify: `tests/E2E/AppNewReachableTest.php`
- Modify: `tests/E2E/WorkspaceSetupTest.php`
- Create: `tests/E2E/DockerFirstRuntimeTopologyTest.php`

## Implementation Tasks

### Task 1: Align Product Documentation

**Files:**
- Modify all product docs listed in the Product Docs file map.

- [ ] **Step 1: Update `docs/tech-stack.md` as the canonical runtime contract**

Replace the host-native runtime rows with this target contract:

```markdown
| Layer | Docker-first implementation |
| --- | --- |
| Application | Laravel 13 application mounted into `orbit-runtime` |
| Runtime language | PHP 8.5 inside Orbit-managed containers |
| Persistent state | SQLite at `~/orbit/database/database.sqlite`, mounted into `orbit-runtime` on the gateway |
| Gateway API | `orbit-caddy` to `orbit-runtime` over the node Docker network; exposed only over WireGuard |
| Gateway to node | SSH through `RemoteShell` |
| Proxy | Dockerized Caddy in one `orbit-caddy` container per node |
| PHP runtime | FrankenPHP app/workspace containers |
| Host init | Docker daemon restart policy for Orbit runtime containers |
| Process manager | `process.runtime=docker` for PHP app processes; `supervisor` only where explicitly configured |
| Scheduler | Gateway scheduler loop inside `orbit-runtime` |
| Service containers | Docker for Orbit runtime containers and backing services |
| Host prerequisites | Git, Docker, Orbit launcher, WireGuard/SSH identity; VitePlus on app nodes |
```

- [ ] **Step 2: Update `docs/architecture.md`**

Document this component shape:

```text
CLI caller
  -> host orbit launcher
  -> local orbit-runtime container
  -> HTTPS over WireGuard
  -> gateway orbit-caddy
  -> gateway orbit-runtime
  -> RemoteShell over WireGuard
  -> node Docker runtime containers
```

State explicitly that the gateway remains the only durable writer and that the
launcher does not give local nodes authority to mutate state directly.

- [ ] **Step 3: Update `docs/concepts.md`**

Add or link these concepts to their owning docs:

```markdown
- Orbit launcher
- Orbit runtime container
- Orbit Caddy container
- App runtime container
- FrankenPHP app runtime
- Worker mode
- Worker config
- Process runtime
- Docker process runtime
- Supervisor process runtime
- Host cwd context
```

- [ ] **Step 4: Update domain docs**

Apply the spec decisions to each affected domain:

```text
node: Docker baseline, role prerequisites, Dockerized Caddy, ingress backend split
gateway: API and scheduler live in orbit-runtime
tool: Caddy/PHP/Composer shift from host tools to container capabilities; VitePlus remains host
firewall: orbit-caddy owns listeners; app containers are Docker-network backends
app: runtime_kind, php_version image selection, worker mode, app:exec
workspace: runtime containers, ORBIT_HOST_CWD, workspace:exec
process: runtime=docker|supervisor, sidecar containers, logs
proxy: route artifacts render to orbit-caddy, not host Caddy
schedule: scheduler container placement and dispatch path
deploy: composer/php/artisan steps execute inside app containers
php: PHP version management becomes image selection
agent-ide: IDEs invoke host launcher and pass cwd context
```

- [ ] **Step 5: Update testing docs**

Document that Docker-first topology images intentionally omit host PHP,
Composer, Caddy, PHP-FPM, and host Supervisor for PHP app processes. Document
that Docker E2E uses sibling containers via the host Docker socket.

- [ ] **Step 6: Run docs lint**

Run:

```bash
composer docs-lint
```

Expected: `issues: 0`, `errors: 0`.

- [ ] **Step 7: Commit docs alignment**

```bash
git add docs TESTING.md
git commit -m "docs: define docker-first orbit runtime"
```

### Task 2: Add Docker-First Topology Contracts

**Files:**
- Modify E2E files listed in the E2E file map.

- [ ] **Step 1: Write failing topology image contract tests**

Add assertions that the prepared Docker topology image has Docker CLI and lacks
host PHP/Caddy/PHP-FPM:

```text
command -v docker exits 0
command -v php exits non-zero
command -v composer exits non-zero
command -v caddy exits non-zero
command -v php-fpm exits non-zero
systemctl status caddy is not part of the success path
```

- [ ] **Step 2: Update `docker/e2e/topology/Dockerfile`**

Install only the host baseline needed for Docker-first tests:

```text
git
openssh-client
openssh-server
curl
ca-certificates
docker-cli or compatible client
```

Do not install host PHP, Composer, Caddy, or PHP-FPM.

- [ ] **Step 3: Teach Docker topology nodes to use sibling containers**

Mount the host Docker socket into topology nodes and connect managed runtime
containers to the same E2E network. The topology node container controls Docker;
it does not run Docker-in-Docker.

- [ ] **Step 4: Update E2E command helpers**

Change helpers that run `php artisan ...` inside topology nodes to run:

```bash
orbit ...
```

The topology `orbit` binary must be the launcher and must pass
`ORBIT_HOST_CWD`.

- [ ] **Step 5: Run focused E2E support tests**

Run:

```bash
php artisan test --compact tests/Feature/E2ESupport
```

Expected: topology support tests pass and no actual E2E lane is run yet.

### Task 3: Build Orbit Launcher And Runtime Container

**Files:**
- Modify launcher/runtime files listed in Installer And Launcher and Runtime Containers.

- [ ] **Step 1: Write failing launcher tests**

Cover:

```text
install writes an orbit launcher, not a PHP symlink
launcher passes ORBIT_HOST_CWD
launcher refuses when Docker is missing
launcher refuses when orbit-runtime is missing and the command cannot bootstrap it
launcher never falls back to host PHP
```

- [ ] **Step 2: Add `docker/orbit-runtime/Dockerfile`**

The image contains:

```text
PHP runtime for Orbit
Composer for Orbit dependency install inside the container
Git for repository-safe operations inside mounted Orbit checkout
Docker CLI for sibling container management where needed
Orbit source mounted at runtime
```

- [ ] **Step 3: Add the host launcher**

`bin/orbit` executes:

```bash
docker exec --env "ORBIT_HOST_CWD=$PWD" orbit-runtime orbit "$@"
```

The production launcher may pass UID/GID and gateway/client env values, but the
required behavior is `ORBIT_HOST_CWD` plus no host PHP fallback.

- [ ] **Step 4: Add runtime container renderer and manager**

Create services that render and apply the `orbit-runtime` container. They must
mount the Orbit checkout and gateway database path on gateway nodes.

- [ ] **Step 5: Run focused tests**

Run the launcher/runtime tests added in this task.

### Task 4: Move Gateway API And Scheduler Into `orbit-runtime`

**Files:**
- Modify Gateway Runtime files listed in the file map.

- [ ] **Step 1: Write failing gateway runtime tests**

Cover:

```text
gateway API artifact targets orbit-runtime over Docker network
gateway API does not render PHP-FPM sockets
streaming endpoints have an explicit concurrency path
scheduler is rendered inside orbit-runtime, not host Supervisor
gateway bootstrap starts orbit-runtime before orbit-caddy routes to it
```

- [ ] **Step 2: Replace PHP-FPM gateway API install behavior**

`GatewayApiRuntimeInstaller` should converge `orbit-runtime` and `orbit-caddy`
instead of native PHP-FPM sockets and host Caddy runtime paths.

- [ ] **Step 3: Replace scheduler program rendering**

Remove the host Supervisor scheduler program as the steady-state path. The
scheduler loop runs inside `orbit-runtime`.

- [ ] **Step 4: Rework streaming progress tests**

Keep the progress SSE contract unchanged, but prove it works through the
containerized API runtime.

- [ ] **Step 5: Run focused gateway tests**

Run gateway API, scheduler, and runtime backend tests.

### Task 5: Dockerize Fleet Caddy

**Files:**
- Modify Tool, Runtime, Gateway, Proxy, and role baseline files listed above.

- [ ] **Step 1: Write failing Caddy placement tests**

Cover:

```text
node baseline converges orbit-caddy container when a role needs Caddy
there is at most one standalone orbit-caddy container per node
host Caddy service is not installed or reloaded
Orbit root CA private material remains gateway-owned
FrankenPHP app containers are not route or CA owners
```

- [ ] **Step 2: Replace host Caddy tool behavior**

`CaddyTool` should describe/converge the `orbit-caddy` container. Host package
install and host service reload paths must be removed from the steady state.

- [ ] **Step 3: Render Caddy config into container mounts**

Keep the exposure-boundary split:

```text
/etc/caddy/orbit/*.caddy for internal Orbit surfaces
/etc/caddy/sites/*.caddy for app, workspace, tool, custom, and public routes
```

The files may be generated on the host and mounted read-only, or rendered into
a managed volume. Pick one model and use it consistently.

- [ ] **Step 4: Update proxy doctor**

Probe and restore `orbit-caddy` config and container status. Do not probe host
`caddy.service` as the expected runtime.

- [ ] **Step 5: Run focused proxy/tool tests**

Run Caddy tool, proxy renderer, proxy doctor, and node baseline tests.

### Task 6: Replace PHP-FPM With FrankenPHP App Containers

**Files:**
- Modify App And Workspace Runtime, Proxy Runtime, PHP Runtime, and Database files.

- [ ] **Step 1: Write failing app/workspace runtime tests**

Cover:

```text
PHP apps get FrankenPHP containers
static apps do not get FrankenPHP containers
workspaces for PHP apps get dedicated runtime containers
PHP version change recreates the runtime container
FPM pool files are not rendered
php_fastcgi unix socket config is not rendered
orbit-caddy proxies PHP routes to app containers over Docker network HTTP
```

- [ ] **Step 2: Add runtime data fields**

Create `database/migrations/2026_05_21_130000_add_docker_first_runtime_fields.php`
with:

```text
apps.runtime_kind string default php
apps.worker_enabled boolean default false
apps.worker_config json nullable
workspaces.worker_enabled boolean default false
workspaces.worker_config json nullable
processes.runtime string default docker
```

If existing app/workspace records need backfill, infer `runtime_kind=php` for
records with a PHP version and `static` only where current docs/commands can
prove static intent. Backfill `processes.runtime` from the owning app or
workspace runtime: PHP-owned processes become `docker`; static/non-PHP
processes become `supervisor` unless existing configuration proves they should
already be Docker-backed. New process creation must store an explicit runtime;
the database default is only a safety net for old inserts.

- [ ] **Step 3: Add container renderers**

Render app/workspace containers with:

```text
mounted source path
selected PHP version image
app/workspace environment
Docker network alias
classic FrankenPHP mode by default
approved glibc-based FrankenPHP image family, not Alpine/musl
OPcache enabled and tuned
realpath cache tuned
optional app preloading file
production-friendly php.ini values
```

- [ ] **Step 4: Add performance baseline tests**

Add tests that prove the rendered app/workspace container config uses the
approved FrankenPHP image family for both development and production. The tests
must reject Alpine/musl variants for the standard runtime path.

Also assert that the generated PHP configuration includes:

```text
opcache.enable=1
opcache.enable_cli=1 for app command containers where needed
opcache.memory_consumption set from runtime defaults or app config
opcache.max_accelerated_files set from runtime defaults or app config
realpath_cache_size set from runtime defaults or app config
realpath_cache_ttl set from runtime defaults or app config
opcache.preload only when the app has configured a preload script
```

- [ ] **Step 5: Replace FPM renderers**

Remove `AppFpmPoolRenderer`, `WorkspaceFpmPoolRenderer`, and
`PhpFpmServiceReloader` from the steady-state runtime path. Delete them once no
tests or services reference them.

- [ ] **Step 6: Update proxy rendering**

Replace `php_fastcgi unix/...` with reverse proxy targets to app/workspace
containers. Preserve static file behavior for non-PHP apps.

- [ ] **Step 7: Run focused app/workspace/proxy tests**

Run app runtime, workspace runtime, PHP command, and proxy renderer tests.

### Task 7: Add Worker Mode Commands And Validation

**Files:**
- Create/modify worker command, API, data, docs, app runtime, tests.

- [ ] **Step 1: Write failing worker command tests**

Cover:

```text
app:worker show renders disabled by default
app:worker enable validates readiness before changing state
enable refuses when Laravel Octane/FrankenPHP support is missing
enable stores worker_enabled=true and worker_config
disable stores worker_enabled=false and keeps worker_config
runtime renderer uses dedicated worker config only when enabled
```

- [ ] **Step 2: Add command surface**

Implement:

```bash
orbit app:worker show <app>
orbit app:worker enable <app>
orbit app:worker disable <app>
```

JSON output must include:

```json
{
  "app": "docs",
  "worker_enabled": true,
  "worker_config": {
    "workers": "auto",
    "max_requests": 500,
    "max_consecutive_failures": 3
  }
}
```

- [ ] **Step 3: Add readiness validator**

For Laravel apps, validator must prove Octane with FrankenPHP support is
available. On failure, return validation error and leave state unchanged.

- [ ] **Step 4: Wire runtime renderer**

Classic mode remains default. Worker mode uses only the dedicated worker config
when `worker_enabled=true`.

- [ ] **Step 5: Run worker command tests**

Run focused app worker command/API/runtime tests.

### Task 8: Add Process Runtime Selection

**Files:**
- Modify Process Runtime files and command/API docs/tests.

- [ ] **Step 1: Write failing process runtime tests**

Cover:

```text
new PHP app processes default to runtime=docker
static app processes default to runtime=supervisor unless overridden
process add/edit accepts runtime=docker|supervisor
docker process runtime creates sidecar container
process logs reads Docker logs for docker runtime
process start/stop/restart dispatches by runtime
```

- [ ] **Step 2: Add `ProcessRuntime` enum and migration support**

Use values:

```text
docker
supervisor
```

- [ ] **Step 3: Add Docker process renderer**

Render process containers using the app/workspace runtime image, mounted source,
env, cwd, and command.

- [ ] **Step 4: Keep Supervisor as explicit runtime**

Do not remove Supervisor paths until all existing non-PHP use cases have a
Docker replacement or a documented reason to remain host-side.

- [ ] **Step 5: Run focused process tests**

Run process command, process probe, process logs, and runtime unit tests.

### Task 9: Route PHP Commands Through Runtime Containers

**Files:**
- Modify command/API/deploy/workspace/app files listed above.

- [ ] **Step 1: Write failing exec command tests**

Cover:

```text
app:exec runs command inside selected app container
workspace:exec runs command inside selected workspace container
composer install is routed through app/workspace container
php artisan is routed through app/workspace container
deploy steps using PHP/Composer/Artisan use app container
workspace setup steps use workspace container
production deploy runs Composer optimized autoload and Laravel optimize inside the app container
optional HTTP warmup runs against the new web container before traffic is routed
ORBIT_HOST_CWD can resolve app/workspace context without mounting all apps into orbit-runtime
```

- [ ] **Step 2: Implement explicit exec commands**

Add:

```bash
orbit app:exec <app> -- composer install
orbit app:exec <app> -- php artisan migrate
orbit workspace:exec <workspace> -- php artisan test
```

- [ ] **Step 3: Update deploy and workspace setup**

Any step that shells into PHP, Composer, or Artisan must run through the
selected app/workspace container. Frontend/VitePlus steps remain host-side in
v1.

Production PHP deploys must include the performance baseline:

```bash
composer install --no-dev --optimize-autoloader
php artisan optimize
```

When the app config defines HTTP warmup paths, Orbit should hit those paths on
the new container before `orbit-caddy` routes production traffic to it. This is
separate from CLI warmup because the web runtime owns the request-path OPcache
state.

- [ ] **Step 4: Update local context resolution**

Use `ORBIT_HOST_CWD` as the source of cwd context. Do not mount all app paths
into `orbit-runtime` for discovery.

- [ ] **Step 5: Run focused command/deploy/workspace tests**

Run exec command, deploy command, workspace setup, and context resolution tests.

### Task 10: Remove Host Runtime Assumptions

**Files:**
- Audit every file touched above plus host runtime references found by `rg`.

- [ ] **Step 1: Search for host-native assumptions**

Run:

```bash
rg "php-fpm|PHP-FPM|php_fastcgi|caddy.service|systemctl reload caddy|supervisorctl|composer install|php artisan" app tests docs config bin docker
```

Every match must be one of:

```text
historical note
explicit non-goal
Supervisor runtime path
containerized command path
test asserting old behavior is gone
```

- [ ] **Step 2: Delete or quarantine dead FPM paths**

Remove steady-state FPM renderers, reloaders, tests, and docs. Do not leave
unused compatibility classes.

- [ ] **Step 3: Update command docs config**

Run Librarian docs generation/lint commands used by the repo so new command
contracts are reflected in `docs/domains/**`.

- [ ] **Step 4: Run focused non-E2E suite**

Run:

```bash
php artisan test --compact
vendor/bin/pint --dirty --format agent
composer docs-lint
```

Expected: all pass.

### Task 11: Run Docker E2E Validation

**Files:**
- Modify E2E tests listed in the E2E file map as failures are discovered.

- [ ] **Step 1: Prepare Docker runtime/topology images**

Run:

```bash
composer e2e:prepare-docker-runtime
composer e2e:prepare-docker-topology
```

- [ ] **Step 2: Run topology contract lane**

Run:

```bash
composer test:e2e:topology-contract
```

Expected: topology proves no host PHP/Caddy/PHP-FPM assumptions.

- [ ] **Step 3: Run Docker E2E lane**

Run:

```bash
composer test:e2e:docker
```

Expected coverage:

```text
gateway API through orbit-caddy/orbit-runtime
app-development PHP workspace through FrankenPHP container
app-production PHP app through ingress/app-production route
static app without FrankenPHP container
process runtime docker sidecar
app:exec and workspace:exec
worker enable refusal and success path where fixture app is Octane-ready
```

- [ ] **Step 4: Fix only real Docker-first defects**

Do not reintroduce host PHP or host Caddy to make E2E pass.

### Task 12: Run Incus/Provision And Cutover Rehearsal

**Files:**
- Modify provision/E2E/runtime files only as failures show real gaps.

- [ ] **Step 1: Run Incus E2E**

Run:

```bash
composer test:e2e:incus
```

- [ ] **Step 2: Run provision lane**

Run:

```bash
composer test:e2e:provision
```

- [ ] **Step 3: Rehearse conversion on disposable nodes**

Use disposable gateway, app-development, app-production, ingress, and
database nodes. Verify:

```text
host PHP absent after conversion
host Caddy absent after conversion
host PHP-FPM absent after conversion
orbit launcher works
orbit-runtime healthy
orbit-caddy healthy
PHP app reachable
static app reachable
queue/process sidecar reachable
doctor verify clean
```

- [ ] **Step 4: Run final quality check**

Run:

```bash
composer quality-check
composer test:e2e
```

- [ ] **Step 5: Decide go/no-go**

Go only if docs, focused tests, Docker E2E, Incus/provision E2E, and disposable
conversion all pass. No-go means keep PHP-FPM as the current winner and do not
merge a permanent dual-runtime compromise.

## Resolved Decisions And Stop Conditions

- Image strategy: v1 builds local development/test images from the Orbit
  checkout. Versioned registry images may be added later, but implementation
  must not block on a registry. Stop and reconcile only if deployment docs
  require registry-published runtime images before Docker-first can merge.
- Worker mode: app worker mode ships first. Workspace worker mode waits for a
  separate explicit workflow unless the workspace runtime implementation needs
  the same schema fields for consistency.
- Process runtime: `supervisor` remains an explicit residual runtime for
  supported non-PHP host-side cases. PHP app/workspace processes default to
  Docker.
- Diagnostics: low-level Docker status/log inspection belongs in doctor and
  targeted runtime diagnostics, not every product command output.
- E2E topology: Docker E2E uses sibling containers through the host Docker
  socket, not Docker-in-Docker.
- Role naming: the public edge role is `ingress`. Historical filenames may
  still include the legacy public-edge term, but product docs, code
  identifiers, commands, JSON fields, and tests must use `ingress`.
