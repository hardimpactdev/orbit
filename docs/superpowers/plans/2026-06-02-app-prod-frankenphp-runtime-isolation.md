# App-Prod FrankenPHP Runtime Isolation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make app-prod runtime user isolation real under FrankenPHP by aligning host file ownership, host-side app execution, and the FrankenPHP Swarm service task UID/GID, while removing stale PHP-FPM security drift from Orbit docs.

**Architecture:** App-prod keeps per-app unprivileged users as the app code identity, not as Docker operators. Orbit creates or updates Swarm services as the `orbit` maintenance user, resolves the app user from the app path/node fallback, and runs each app's FrankenPHP service task as that numeric UID/GID on internal port `8080`; app tasks never receive Docker socket or Docker group access. Zero-downtime deploys use normal Docker Swarm `start-first` updates: the replacement task starts and passes health, Swarm terminates the old task, the old FrankenPHP/Caddy process drains in-flight requests during `stop_grace_period`, and new requests go to the updated service task set. Product docs and doctor checks become FrankenPHP/Swarm-service-first and no longer reference FPM pool isolation as live behavior.

**Tech Stack:** Laravel 13 gateway, Laravel Zero CLI, Pest 4, Docker Engine/CLI, FrankenPHP Docker images, Orbit `RemoteShell`, `orbit-caddy`, SQLite gateway state.

---

## Prerequisite And Scope

Implement this plan after both:

- `docs/superpowers/plans/2026-06-01-orbit-gateway-swarm-update-runner.md`
- `docs/superpowers/plans/2026-06-02-production-swarm-substrate-and-process-runtime.md`

The gateway plan establishes production Swarm/update-runner behavior for the
gateway services. The substrate plan establishes Swarm as a per-artifact
production/infra backend, moves app/workspace configured processes to host
Supervisor, defines WireGuard-IP dependency addressing, and owns shared
service-tool runtime drivers, platform-specific runtime implementations, and
version-family tool instances.

This plan owns only the app-prod HTTP runtime isolation and deploy path:

- App-prod FrankenPHP service rendering and management.
- App-prod runtime UID/GID resolution and enforcement.
- App-prod proxy upstreams on internal port `8080`.
- App-prod source-release bind mounts and rolling Swarm deploy convergence.
- App-prod app doctor/fixer changes for runtime container/service isolation.

This plan must not reintroduce Docker process sidecars, Docker-specific app
`.env` dependency hosts, app-user Docker group membership, or Docker socket
mounts into app runtimes. If implementation work finds process-runtime or
service-address drift, fix that in the substrate plan first and keep this plan
focused on app-prod runtime isolation.
It must also not implement MySQL/PostgreSQL/Redis version-family tool
instances, `tool:install --runtime`, service-tool Swarm runtime drivers, or
service-tool runtime platform support; those are substrate scope.

## Product Decisions

- App-prod source ownership covers the entire configured app path for this baseline.
- FrankenPHP app containers listen on internal port `8080`, not privileged port `80`.
- App-prod PHP runtimes are Docker Swarm services, not standalone `docker run` containers.
- The app-prod backend `orbit-caddy` route is app-role-owned and routes to the stable app service DNS name; app `docs` resolves as `http://orbit-app-docs:8080`. This is separate from the gateway plan's router-colocated gateway API route and must not turn Caddy into a gateway-owned service.
- App-prod zero-downtime deploys use Swarm rolling update semantics with `start-first`, a real HTTP healthcheck, rollback on failed updates, and a finite stop grace period.
- During a successful update, Swarm starts the new task first, waits for it to become healthy, then sends `SIGTERM` to the old task. The old FrankenPHP/Caddy process should stop accepting new work, finish in-flight requests within `stop_grace_period`, and then exit cleanly.
- A brief mixed-version traffic window is acceptable while Swarm transitions the service from old task to new task. Orbit does not add custom Caddy blue/green routing for stricter atomic cutover in this baseline.
- Zero-downtime source deploys update the Swarm service to bind-mount `release_path` into `/app`. Baked Docker app deploys update the Swarm service image digest instead.
- App runtime user is derived from app path first, then node user fallback, matching deploy context:
  - `/home/<user>/...` resolves to `<user>`.
  - otherwise fallback to `nodes.user` or `orbit`.
- Deploy and `app:exec` must use the same runtime user resolver as the FrankenPHP container.
- App users must not be added to the `docker` group and must not receive `/var/run/docker.sock`.
- `current` or `live` symlinks remain for operator convenience and stable host-side inspection. In zero-downtime mode the Swarm service's mounted release path is the serving boundary.
- `current` or `live` must be updated only after the Swarm service update converges successfully. If the deployment fails or rolls back, the symlink stays on the previously active release.

## File Map

### Product Docs

- Modify: `apps/docs/content/abstractions/17_security.md`
- Modify: `apps/docs/content/domains/5_app/app-concepts.md`
- Modify: `apps/docs/content/domains/5_app/app-doctor.md`
- Modify: `apps/docs/content/domains/5_app/README.md`
- Modify: `apps/docs/content/domains/5_app/10_app-exec/app-exec.md`
- Modify: `apps/docs/content/domains/10_deploy/README.md`
- Modify: `apps/docs/content/domains/10_deploy/4_deploy-run/technical/1_deploy-run.md`

### Runtime Identity

- Modify: `apps/gateway/app/Services/Apps/AppRuntimeUser.php`
- Modify: `apps/gateway/app/Services/Apps/AppExecutor.php`
- Modify: `apps/gateway/app/Services/Deploy/DeployManager.php`
- Test: `apps/gateway/tests/Unit/Services/Apps/AppRuntimeUserTest.php`
- Test: `apps/gateway/tests/Unit/Services/Deploy/DeployManagerContainerRoutingTest.php`

### App Runtime Service

- Modify: `apps/gateway/app/Services/Apps/AppRuntimeContainer.php`
- Modify: `apps/gateway/app/Services/Apps/AppRuntimeContainerRenderer.php`
- Modify: `apps/gateway/app/Services/Apps/AppRuntimeContainerManager.php`
- Modify: `apps/gateway/app/Services/Runtime/DockerCommandBuilder.php`
- Create: `apps/gateway/app/Services/Apps/AppRuntimeService.php`
- Create: `apps/gateway/app/Services/Apps/AppRuntimeServiceRenderer.php`
- Create: `apps/gateway/app/Services/Apps/AppRuntimeServiceManager.php`
- Create: `apps/gateway/app/Services/Runtime/DockerSwarmCommandBuilder.php`
- Test: `apps/gateway/tests/Unit/Services/Apps/AppRuntimeContainerRendererTest.php`
- Test: `apps/gateway/tests/Unit/Services/Apps/AppRuntimeContainerManagerTest.php`
- Test: `apps/gateway/tests/Unit/Services/Apps/AppRuntimeServiceRendererTest.php`
- Test: `apps/gateway/tests/Unit/Services/Apps/AppRuntimeServiceManagerTest.php`
- Test: `apps/gateway/tests/Unit/Services/Runtime/DockerSwarmCommandBuilderTest.php`
- Test: `apps/gateway/tests/Unit/Services/Runtime/DockerCommandBuilderTest.php`
- Test: `apps/gateway/tests/Feature/Actions/Apps/EnactAppRuntimeTest.php`

### App Doctor And Repair

- Modify: `apps/gateway/app/Services/Apps/AppsProbe.php`
- Modify: `apps/gateway/app/Services/Apps/AppsFixer.php`
- Test: `apps/gateway/tests/Unit/Services/Apps/AppsProbeTest.php`
- Test: `apps/gateway/tests/Unit/Services/Apps/AppsFixerTest.php`

### Proxy Routing

- Modify: `apps/gateway/app/Actions/Apps/EnsureAppProxyRoute.php`
- Modify: `apps/gateway/app/Services/Proxy/ProxyRouteRenderer.php`
- Test: `apps/gateway/tests/Unit/Services/Proxy/ProxyRouteRendererTest.php`
- Test: `apps/gateway/tests/Feature/Http/Api/AppRegisterControllerTest.php`

### Deploy Rolling Updates

- Modify: `apps/gateway/app/Services/Deploy/DeployManager.php`
- Test: `apps/gateway/tests/Unit/Services/Deploy/DeployManagerContainerRoutingTest.php`
- Test: `apps/gateway/tests/Unit/Services/Deploy/DeployManagerSwarmUpdateTest.php`

## Implementation Tasks

### Task 1: Align Product Docs

**Files:**
- Modify: files listed in Product Docs

- [ ] **Step 0: Confirm substrate docs are already aligned**

Before editing app-prod docs, confirm the substrate plan has already removed
Docker-process-default wording and has aligned `apps/docs/content/execution-lanes.md`.
Do not edit process-runtime defaults in this plan; app/workspace configured
process commands are host Supervisor programs by substrate contract.

- [ ] **Step 1: Replace stale FPM security key**

In `apps/docs/content/abstractions/17_security.md`, replace:

```markdown
orbit doctor --family=app --key=app.security.fpm_pool_isolation
```

with:

```markdown
orbit doctor --family=app --key=app.security.runtime_container_isolation
```

- [ ] **Step 2: Define the app-prod runtime identity contract**

Update app concepts/doctor docs with this contract:

```markdown
Production app runtime user isolation is path-derived. Orbit resolves the app
runtime user from the configured app path when it is under `/home/<user>/...`;
otherwise it falls back to the owning node's steady-state user. The resolved
user owns the configured app path and is the user for deploy, `app:exec`, and
the FrankenPHP container process UID/GID.

The resolved app user is not a Docker operator. It must not be added to the
`docker` group and must not receive the Docker socket.
```

- [ ] **Step 3: Fix app:exec docs contradiction**

In `apps/docs/content/domains/5_app/10_app-exec/app-exec.md`, keep the host PHP toolchain contract and remove the later claim that the command runs inside the app container. Use this replacement:

```markdown
`app:exec` resolves the selected app and runs `<command...>` on the owning node
with the version-matched host PHP toolchain from the app source path. The
command runs as the resolved app runtime user. The app's FrankenPHP container
serves the same bind-mounted source path, but `app:exec` does not execute
inside that container.
```

- [ ] **Step 4: Document internal port 8080**

Document that PHP app runtime containers listen on `:8080` and are only reachable from node-local Docker networking through `orbit-caddy`.

- [ ] **Step 5: Document Swarm app-prod runtime semantics**

Add this deployment/runtime contract to app and deploy docs:

```markdown
Production PHP app runtimes are Docker Swarm services. The stable service name
is `orbit-app-<app>`, and the app-role-owned backend `orbit-caddy` route points
at that service DNS name on internal port `8080`. This backend route is not the
gateway router-colocated Caddy route. For source deployments, the service
bind-mounts the active release path into `/app`. For baked Docker app
deployments, the service uses the selected app image digest and does not need a
source bind mount.

Zero-downtime deploys use Swarm rolling update semantics with `start-first`,
`failure_action=rollback`, a real HTTP healthcheck, a health/monitor window,
and a finite `stop_grace_period`. Swarm starts the replacement task first and
waits for it to become healthy before terminating the old task. The old
FrankenPHP/Caddy process receives `SIGTERM`, stops accepting new work, drains
in-flight requests until they complete or the stop grace period expires, and
then exits.

A brief mixed-version traffic window is acceptable while Swarm transitions the
service from the old task to the new task. Custom Caddy blue/green routing is
not part of this baseline because normal Swarm zero-downtime behavior is
sufficient.

The `current` or `live` symlink remains an operator convenience pointer and
stable host-side inspection path. It is updated only after the Swarm service
update converges successfully. If the deployment fails or Swarm rolls back, the
symlink stays on the previously active release. In zero-downtime mode it is not
the request-serving boundary; the Swarm service mount or image is the serving
boundary.
```

- [ ] **Step 6: Run docs lint**

Run:

```bash
composer docs-lint
```

Expected: docs lint passes.

### Task 2: Centralize App Runtime User Resolution

**Files:**
- Modify: `apps/gateway/app/Services/Apps/AppRuntimeUser.php`
- Modify: `apps/gateway/app/Services/Apps/AppExecutor.php`
- Modify: `apps/gateway/app/Services/Deploy/DeployManager.php`
- Test: `apps/gateway/tests/Unit/Services/Apps/AppRuntimeUserTest.php`
- Test: `apps/gateway/tests/Unit/Services/Deploy/DeployManagerContainerRoutingTest.php`

- [ ] **Step 1: Add failing AppRuntimeUser tests**

Create `apps/gateway/tests/Unit/Services/Apps/AppRuntimeUserTest.php` covering:

```php
it('resolves production runtime user from a home path', function (): void {
    $node = Node::factory()->appProd()->create(['user' => 'orbit']);
    $app = App::factory()->for($node, 'node')->create([
        'environment' => 'production',
        'path' => '/home/docs/app',
    ]);

    expect(app(AppRuntimeUser::class)->forApp($app))->toBe('docs');
});

it('falls back to node user when the app path is not under home', function (): void {
    $node = Node::factory()->appProd()->create(['user' => 'orbit']);
    $app = App::factory()->for($node, 'node')->create([
        'environment' => 'production',
        'path' => '/srv/docs',
    ]);

    expect(app(AppRuntimeUser::class)->forApp($app))->toBe('orbit');
});

it('keeps development apps on the node steady-state user', function (): void {
    $node = Node::factory()->appDev()->create(['user' => 'orbit']);
    $app = App::factory()->for($node, 'node')->create([
        'environment' => 'development',
        'path' => '/home/docs/app',
    ]);

    expect(app(AppRuntimeUser::class)->forApp($app))->toBe('orbit');
});
```

- [ ] **Step 2: Run the failing test**

Run:

```bash
bin/orbit-gateway-pest --compact apps/gateway/tests/Unit/Services/Apps/AppRuntimeUserTest.php
```

Expected: fails because production still uses generated `orbit-<slug>-<hash>` user.

- [ ] **Step 3: Implement path-derived runtime user**

Update `AppRuntimeUser::forApp()` so production uses this rule:

```php
if ($this->isProduction($app)) {
    $path = (string) $app->path;

    if (preg_match('#^/home/([^/]+)/#', $path, $matches) === 1) {
        return $matches[1];
    }
}

$app->loadMissing('node');

return $app->node?->user ?: 'orbit';
```

Remove the generated safe-name helper if it becomes unused.

Add a production-only container hook:

```php
public function containerUserForApp(App $app): ?string
{
    if (! $this->isProduction($app)) {
        return null;
    }

    return $this->forApp($app);
}
```

- [ ] **Step 4: Reuse the resolver in deploy**

Replace `DeployManager::appUser()` and `wrapForHost()` node-user fallback with `app(AppRuntimeUser::class)->forApp($app)`.

- [ ] **Step 5: Assert deploy uses path-derived app user**

Add a case to `DeployManagerContainerRoutingTest.php` where app path is `/home/docs/app` and assert the routed command contains:

```text
'sudo' '-u' 'docs'
```

- [ ] **Step 6: Run runtime user/deploy tests**

Run:

```bash
bin/orbit-gateway-pest --compact apps/gateway/tests/Unit/Services/Apps/AppRuntimeUserTest.php apps/gateway/tests/Unit/Services/Deploy/DeployManagerContainerRoutingTest.php
```

Expected: pass.

### Task 3: Move FrankenPHP App Runtime To Internal 8080

**Files:**
- Modify: `apps/gateway/app/Services/Apps/AppRuntimeContainerRenderer.php`
- Modify: `apps/gateway/app/Actions/Apps/EnsureAppProxyRoute.php`
- Modify: `apps/gateway/app/Services/Proxy/ProxyRouteRenderer.php`
- Test: `apps/gateway/tests/Unit/Services/Apps/AppRuntimeContainerRendererTest.php`
- Test: `apps/gateway/tests/Unit/Services/Proxy/ProxyRouteRendererTest.php`

- [ ] **Step 1: Update failing renderer expectations**

Change app runtime renderer tests to expect:

```php
'SERVER_NAME' => ':8080',
'SERVER_ROOT' => '/app/public',
```

and upstream URL:

```php
expect(rendererForTest()->upstreamUrl($app))->toBe('http://orbit-app-docs:8080');
```

- [ ] **Step 2: Run failing renderer test**

Run:

```bash
bin/orbit-gateway-pest --compact apps/gateway/tests/Unit/Services/Apps/AppRuntimeContainerRendererTest.php
```

Expected: fails because current renderer emits `:80` and `http://orbit-app-docs`.

- [ ] **Step 3: Implement internal port 8080**

Update `AppRuntimeContainerRenderer`:

```php
'SERVER_NAME' => ':8080',
```

and:

```php
public function upstreamUrl(App $app): string
{
    return 'http://'.$this->containerName($app).':8080';
}
```

- [ ] **Step 4: Update proxy tests**

Update app-owned proxy expectations from `http://orbit-app-<app>` to `http://orbit-app-<app>:8080` wherever PHP app runtime upstreams are asserted.

- [ ] **Step 5: Run app runtime/proxy tests**

Run:

```bash
bin/orbit-gateway-pest --compact apps/gateway/tests/Unit/Services/Apps/AppRuntimeContainerRendererTest.php apps/gateway/tests/Unit/Services/Proxy/ProxyRouteRendererTest.php
```

Expected: pass.

### Task 4: Render App-Prod Runtime As A Swarm Service

**Files:**
- Create: `apps/gateway/app/Services/Apps/AppRuntimeService.php`
- Create: `apps/gateway/app/Services/Apps/AppRuntimeServiceRenderer.php`
- Create: `apps/gateway/app/Services/Runtime/DockerSwarmCommandBuilder.php`
- Test: `apps/gateway/tests/Unit/Services/Apps/AppRuntimeServiceRendererTest.php`
- Test: `apps/gateway/tests/Unit/Services/Runtime/DockerSwarmCommandBuilderTest.php`

- [ ] **Step 1: Add failing Swarm service renderer test**

Create `apps/gateway/tests/Unit/Services/Apps/AppRuntimeServiceRendererTest.php` with a production app at `/home/docs/app` and assert the rendered service has:

```php
expect($service->name())->toBe('orbit-app-docs')
    ->and($service->image())->toBe('dunglas/frankenphp:1-php8.5-bookworm')
    ->and($service->network())->toBe('orbit-network')
    ->and($service->runtimeUser())->toBe('docs')
    ->and($service->mounts())->toContain([
        'source' => '/home/docs/app',
        'target' => '/app',
        'read_only' => false,
    ])
    ->and($service->environment())->toMatchArray([
        'SERVER_NAME' => ':8080',
        'SERVER_ROOT' => '/app/public',
    ])
    ->and($service->updateConfig())->toMatchArray([
        'order' => 'start-first',
        'parallelism' => 1,
        'failure_action' => 'rollback',
        'monitor' => '30s',
    ])
    ->and($service->rollbackConfig())->toMatchArray([
        'order' => 'start-first',
    ])
    ->and($service->stopGracePeriod())->toBe('60s')
    ->and($service->healthcheck())->toMatchArray([
        'interval' => '10s',
        'timeout' => '3s',
        'retries' => 3,
        'start_period' => '20s',
    ]);

expect($service->healthcheck()['command'])->toContain('GET / HTTP/1.1');
```

Add a second assertion in the same test file for an app with
`deploy_warmup_paths=['/up']`:

```php
expect($service->healthcheck()['command'])->toContain('GET /up HTTP/1.1');
```

- [ ] **Step 2: Create AppRuntimeService value object**

Create `AppRuntimeService` with fields matching `AppRuntimeContainer` plus Swarm-specific update config:

```php
public function __construct(
    private readonly string $name,
    private readonly string $image,
    private readonly string $network,
    private readonly string $restartPolicy,
    private readonly string $appSlug,
    private readonly ?string $runtimeUser,
    array $environment,
    array $mounts,
    array $networkAliases,
    array $phpIni,
    array $updateConfig,
    array $rollbackConfig,
    array $healthcheck,
    private readonly string $stopGracePeriod,
) {}
```

Include `runtime_user`, `update_config`, `rollback_config`, `healthcheck`, and
`stop_grace_period` in `spec()` and the service spec hash.

- [ ] **Step 3: Create AppRuntimeServiceRenderer**

Create a renderer that mirrors `AppRuntimeContainerRenderer`, emits `SERVER_NAME=:8080`, and uses:

```php
'update_config' => [
    'order' => 'start-first',
    'parallelism' => 1,
    'failure_action' => 'rollback',
    'monitor' => '30s',
],
'rollback_config' => [
    'order' => 'start-first',
],
'healthcheck' => [
    'command' => "php -r '\$socket=@fsockopen(\"127.0.0.1\",8080,\$errno,\$errstr,2); if (! \$socket) { exit(1); } fwrite(\$socket,\"GET / HTTP/1.1\\r\\nHost: localhost\\r\\n\\r\\n\"); \$status=fgets(\$socket); exit(preg_match(\"#^HTTP/[0-9.]+ [23][0-9]{2}#\",\$status) ? 0 : 1);'",
    'interval' => '10s',
    'timeout' => '3s',
    'retries' => 3,
    'start_period' => '20s',
],
'stop_grace_period' => '60s',
```

For source apps, the mount source defaults to the current configured app path. Deploy will pass the release path for zero-downtime updates.
The healthcheck path is the first configured `deploy_warmup_paths` entry when
present; otherwise it is `/`. Strip CR/LF from the selected path before
embedding it in the healthcheck command.

- [ ] **Step 4: Add failing DockerSwarmCommandBuilder tests**

Create `DockerSwarmCommandBuilderTest.php` asserting service create/update commands include:

```text
docker service create
--name 'orbit-app-docs'
--network 'orbit-network'
--mount 'type=bind,source=/home/docs/app,target=/app'
--env 'SERVER_NAME=:8080'
--user '1001:1001'
--health-cmd 'php -r'
--health-interval '10s'
--health-timeout '3s'
--health-retries '3'
--health-start-period '20s'
--update-order 'start-first'
--update-parallelism '1'
--update-failure-action 'rollback'
--update-monitor '30s'
--rollback-order 'start-first'
--stop-grace-period '60s'
```

and service update includes the new mount or image plus:

```text
docker service update
--with-registry-auth
--update-order 'start-first'
--update-parallelism '1'
--update-failure-action 'rollback'
--update-monitor '30s'
--rollback-order 'start-first'
--stop-grace-period '60s'
```

- [ ] **Step 5: Implement DockerSwarmCommandBuilder**

Create command helpers:

```php
public function serviceInspect(string $name): string;
public function serviceCreate(AppRuntimeService $service, string $numericUser): string;
public function serviceUpdate(AppRuntimeService $service, string $numericUser): string;
public function serviceRemove(string $name): string;
public function servicePs(string $name): string;
```

Validate `$numericUser` with `/^\d+:\d+$/`.

- [ ] **Step 6: Run Swarm service renderer/builder tests**

Run:

```bash
bin/orbit-gateway-pest --compact apps/gateway/tests/Unit/Services/Apps/AppRuntimeServiceRendererTest.php apps/gateway/tests/Unit/Services/Runtime/DockerSwarmCommandBuilderTest.php
```

Expected: pass.

### Task 5: Run App Runtime Services As Numeric App UID/GID

**Files:**
- Modify: `apps/gateway/app/Services/Apps/AppRuntimeContainer.php`
- Modify: `apps/gateway/app/Services/Runtime/DockerCommandBuilder.php`
- Modify: `apps/gateway/app/Services/Apps/AppRuntimeContainerManager.php`
- Modify: `apps/gateway/app/Services/Apps/AppRuntimeService.php`
- Modify: `apps/gateway/app/Services/Apps/AppRuntimeServiceManager.php`
- Modify: `apps/gateway/app/Services/Runtime/DockerSwarmCommandBuilder.php`
- Test: `apps/gateway/tests/Unit/Services/Runtime/DockerCommandBuilderTest.php`
- Test: `apps/gateway/tests/Unit/Services/Apps/AppRuntimeContainerManagerTest.php`
- Test: `apps/gateway/tests/Unit/Services/Apps/AppRuntimeServiceManagerTest.php`
- Test: `apps/gateway/tests/Feature/Actions/Apps/EnactAppRuntimeTest.php`

- [ ] **Step 1: Add app runtime user to container intent**

Extend `AppRuntimeContainer` and `AppRuntimeService` with a nullable `runtimeUser` name. Include it in `spec()` and labels through the existing spec hash, but do not treat it as the literal Docker `--user` value.

Expected spec shape addition:

```php
'runtime_user' => $this->runtimeUser,
```

- [ ] **Step 2: Emit runtimeUser from renderer**

Add `AppRuntimeUser $appRuntimeUser` as a constructor dependency on `AppRuntimeContainerRenderer`. Set `runtimeUser` to `$this->appRuntimeUser->containerUserForApp($app)`. Development apps must render `runtimeUser=null` and must not emit Docker `--user` in this plan.

- [ ] **Step 3: Add DockerCommandBuilder user option**

Add an optional Docker user argument to `runDetached()` and `buildRunOrCreate()`:

```php
public function runDetached(OrbitRuntimeContainer|OrbitCaddyContainer|AppRuntimeContainer|WorkspaceRuntimeContainer|ProcessDockerContainer|WebSocketRuntimeContainer $container, ?string $user = null): string
```

Use the same union type for `buildRunOrCreate()`. When `$user !== null`, validate it with:

```php
if (preg_match('/^\d+:\d+$/', $user) !== 1) {
    throw new InvalidArgumentException('Docker user must be a numeric uid:gid pair.');
}
```

and emit:

```php
$parts[] = '--user';
$parts[] = $this->quote($user);
```

- [ ] **Step 4: Add failing Docker builder test**

Add a test that calls:

```php
$command = (new DockerCommandBuilder)->runDetached($container, '1001:1001');

expect($command)->toContain('--user '.escapeshellarg('1001:1001'));
```

and a test that non-numeric users throw `InvalidArgumentException`.

- [ ] **Step 5: Resolve UID/GID in app runtime managers**

Before creating/recreating an app runtime container or service with a non-null runtime user, run a remote shell script:

```sh
set -e
uid="$(id -u 'docs')"
gid="$(id -g 'docs')"
printf '%s:%s\n' "$uid" "$gid"
```

Use the returned `uid:gid` as the Docker or Swarm builder user argument.

- [ ] **Step 6: Map missing runtime user to app security drift**

Create `apps/gateway/app/Services/Apps/AppRuntimeUserUnavailableException.php` for UID/GID resolution failures. Map that exception to `app.security.system_user` in `EnactAppRuntime`, where runtime apply exceptions are converted to app drift warnings.

- [ ] **Step 7: Force recreate/update on runtime isolation fixes**

Ensure `AppsFixer` does not no-op when the container hash matches but `.Config.User` is wrong. Add a `forceRecreate` argument to the manager:

```php
public function apply(Node $node, AppRuntimeContainer $container, bool $forceRecreate = false): AppRuntimeContainerApplyOutcome
{
    $this->ensureNetwork($node, $container);
    $inspection = $this->inspect($node, $container);

    if ($forceRecreate && $inspection !== null) {
        $this->runRequired(
            $node,
            $this->commands->containerRemove($container->name()),
            "remove {$container->name()} container for forced recreate",
        );

        $inspection = null;
    }

    // Continue with the current image preflight and create/start/mismatch flow.
}
```

Then use this call for `app.security.runtime_container_isolation`:

```php
$this->appRuntimeContainerManager->apply($node, $container, forceRecreate: true);
```

For app-prod Swarm services, call:

```php
$this->appRuntimeServiceManager->apply($node, $service, forceUpdate: true);
```

- [ ] **Step 8: Run app runtime container tests**

Run:

```bash
bin/orbit-gateway-pest --compact apps/gateway/tests/Unit/Services/Runtime/DockerCommandBuilderTest.php apps/gateway/tests/Unit/Services/Apps/AppRuntimeContainerManagerTest.php apps/gateway/tests/Feature/Actions/Apps/EnactAppRuntimeTest.php
```

Expected: pass.

### Task 6: Detect Container/Service User Drift In App Doctor

**Files:**
- Modify: `apps/gateway/app/Services/Apps/AppsProbe.php`
- Test: `apps/gateway/tests/Unit/Services/Apps/AppsProbeTest.php`

- [ ] **Step 1: Extend probe output with container/service user state**

Add two observed fields to the introspection script:

```sh
expected_container_user=''
container_user_matches=1
if [ -n "$RUNTIME_USER" ] && id -u "$RUNTIME_USER" >/dev/null 2>&1; then
    uid="$(id -u "$RUNTIME_USER")"
    gid="$(id -g "$RUNTIME_USER")"
    expected_container_user="$uid:$gid"
fi

if [ "$RUNTIME_KIND" = "php" ] && [ "$container_exists" -eq 1 ] && [ -n "$expected_container_user" ]; then
    observed_container_user="$(docker inspect --format '{{.Spec.TaskTemplate.ContainerSpec.User}}' "$RUNTIME_CONTAINER_NAME" 2>/dev/null || docker container inspect --format '{{.Config.User}}' "$RUNTIME_CONTAINER_NAME" 2>/dev/null || printf '')"
    if [ "$observed_container_user" != "$expected_container_user" ]; then
        container_user_matches=0
    fi
fi
```

Add parsed snapshot keys:

```php
'container_user_matches' => $containerUserMatches === '1',
'expected_container_user' => $expectedContainerUser,
```

- [ ] **Step 2: Report app.security.runtime_container_isolation for user mismatch**

Update `checkProductionSecurity()` so this condition reports isolation drift:

```php
($observed['container_user_matches'] ?? true) === false
```

Include detail:

```php
'expected_user' => $observed['expected_container_user'] ?? null,
```

- [ ] **Step 3: Add probe tests**

Add cases:

```php
it('detects production app runtime container user drift', function (): void {
    $node = appNode([], role: 'app-prod');
    $app = App::factory()->for($node, 'node')->create([
        'environment' => 'production',
        'path' => '/home/docs/app',
    ]);

    $snapshot = new ProbeSnapshot([
        'docs' => convergedRuntimeSnapshot([
            'container_user_matches' => false,
            'expected_container_user' => '1001:1001',
        ]),
    ]);

    $drift = (new AppsProbe)->diff($app, $snapshot);

    expect(issue($drift, 'app.security.runtime_container_isolation'))->not->toBeNull();
});
```

- [ ] **Step 4: Run app probe tests**

Run:

```bash
bin/orbit-gateway-pest --compact apps/gateway/tests/Unit/Services/Apps/AppsProbeTest.php
```

Expected: pass.

### Task 7: Add Swarm Rolling Deployment Semantics

**Files:**
- Modify: `apps/gateway/app/Services/Deploy/DeployManager.php`
- Test: `apps/gateway/tests/Unit/Services/Deploy/DeployManagerSwarmUpdateTest.php`
- Test: `apps/gateway/tests/Unit/Services/Deploy/DeployManagerContainerRoutingTest.php`

- [ ] **Step 1: Add failing deploy Swarm update test**

Create `DeployManagerSwarmUpdateTest.php` asserting a successful PHP production deploy:

```php
expect($shell->runs)->toContainShellCommand("docker service update")
    ->and($shell->runs)->toContainShellCommand("--update-order 'start-first'")
    ->and($shell->runs)->toContainShellCommand("--update-parallelism '1'")
    ->and($shell->runs)->toContainShellCommand("--update-failure-action 'rollback'")
    ->and($shell->runs)->toContainShellCommand("--update-monitor '30s'")
    ->and($shell->runs)->toContainShellCommand("--rollback-order 'start-first'")
    ->and($shell->runs)->toContainShellCommand("--stop-grace-period '60s'")
    ->and($shell->runs)->toContainShellCommand("--health-cmd 'php -r")
    ->and($shell->runs)->toContainShellCommand("source=/home/docs/app/releases/")
    ->and($shell->runs)->toContainShellCommand("target=/app")
    ->and($shell->runs)->toContainShellCommand("--user '");
```

The exact helper can be a local collection assertion over `$shell->runs[*]['script']`.
Add assertions that the symlink update script for `current` or `live` appears
after the successful Swarm convergence check and after HTTP warmup:

```php
$serviceUpdateIndex = scriptIndex($shell->runs, 'docker service update');
$convergenceIndex = scriptIndex($shell->runs, 'docker service inspect');
$httpWarmupIndex = scriptIndex($shell->runs, 'curl');
$symlinkIndex = scriptIndex($shell->runs, 'ln -sfn');

expect($symlinkIndex)->toBeGreaterThan($serviceUpdateIndex)
    ->and($symlinkIndex)->toBeGreaterThan($convergenceIndex)
    ->and($symlinkIndex)->toBeGreaterThan($httpWarmupIndex);
```

Add a failure-path assertion where Swarm reports rollback or update failure:

```php
expect($shell->runs)->not->toContainShellCommand('ln -sfn');
expect($deploymentRun->fresh()->status)->toBe(DeploymentRunStatus::Failed);
```

- [ ] **Step 2: Build release-specific service intent after warmup**

After user steps and built-in warmup succeed, render an app runtime Swarm service whose source mount is:

```php
$context['release_path']
```

when the release path exists and the app uses zero-downtime source deployment. The service name remains stable:

```text
orbit-app-<app>
```

- [ ] **Step 3: Apply service update**

Call `AppRuntimeServiceManager::apply()` with the release-specific service intent. This manager uses Swarm `service create` when absent and `service update` when present.

For `service update`, apply these semantics:

1. `docker service update` uses `--update-order start-first`, healthcheck flags,
   rollback flags, and `--stop-grace-period 60s`.
2. Swarm starts the replacement task first and waits for the healthcheck.
3. After the replacement task is healthy, Swarm sends `SIGTERM` to the old
   task. FrankenPHP/Caddy drains in-flight requests during the stop grace
   period.
4. `AppRuntimeServiceManager::apply()` waits for the service update to reach a
   converged state using `docker service inspect` or `docker service ps`.
5. If Swarm reports rollback, rejected tasks, or update failure, the deployment
   run is marked failed before HTTP warmup.

- [ ] **Step 4: Keep HTTP warmup before completing the run**

HTTP warmup requests should target the stable service/upstream after the Swarm update. If Swarm update fails or rolls back, mark the deployment run failed before HTTP warmup.

- [ ] **Step 5: Preserve release symlink for operator convenience**

Update `current` or `live` only after:

1. all user deployment steps succeeded;
2. built-in Composer/Laravel warmup succeeded;
3. the Swarm service update converged successfully;
4. HTTP warmup against the stable service/upstream succeeded.

Use this host-side shape:

```bash
ln -sfn '{{ release_path }}' '{{ live_path }}'
```

If any earlier phase fails, skip the symlink update. The symlink must continue
to point at the last successfully active release. Orbit's zero-downtime runtime
service must not rely on that symlink as the serving boundary.

- [ ] **Step 6: Run deploy Swarm tests**

Run:

```bash
bin/orbit-gateway-pest --compact apps/gateway/tests/Unit/Services/Deploy/DeployManagerSwarmUpdateTest.php apps/gateway/tests/Unit/Services/Deploy/DeployManagerContainerRoutingTest.php
```

Expected: pass.

### Task 8: Keep Whole App Path Ownership Repair

**Files:**
- Modify: `apps/gateway/app/Services/Apps/AppsFixer.php`
- Test: `apps/gateway/tests/Unit/Services/Apps/AppsFixerTest.php`

- [ ] **Step 1: Confirm current ownership repair matches product decision**

Keep whole app path repair:

```sh
sudo chown -R "$runtime_user:$runtime_user" "$app_path"
sudo chmod -R go-w "$app_path"
```

This is the current baseline. Do not narrow to only `storage` and `bootstrap/cache` in this plan.

- [ ] **Step 2: Update tests for path-derived runtime user**

Change app security fixer tests to use an app path such as:

```php
'path' => '/home/docs/app',
```

and assert generated scripts contain:

```text
useradd --system
'docs'
chown -R
'/home/docs/app'
```

- [ ] **Step 3: Run fixer tests**

Run:

```bash
bin/orbit-gateway-pest --compact apps/gateway/tests/Unit/Services/Apps/AppsFixerTest.php
```

Expected: pass.

### Task 9: Verification Sweep

**Files:**
- All files touched by this plan

- [ ] **Step 1: Run focused checks**

Run:

```bash
bin/orbit-gateway-pest --compact apps/gateway/tests/Unit/Services/Apps/AppRuntimeUserTest.php
bin/orbit-gateway-pest --compact apps/gateway/tests/Unit/Services/Apps/AppRuntimeContainerRendererTest.php
bin/orbit-gateway-pest --compact apps/gateway/tests/Unit/Services/Apps/AppRuntimeServiceRendererTest.php
bin/orbit-gateway-pest --compact apps/gateway/tests/Unit/Services/Runtime/DockerCommandBuilderTest.php
bin/orbit-gateway-pest --compact apps/gateway/tests/Unit/Services/Runtime/DockerSwarmCommandBuilderTest.php
bin/orbit-gateway-pest --compact apps/gateway/tests/Unit/Services/Apps/AppRuntimeContainerManagerTest.php
bin/orbit-gateway-pest --compact apps/gateway/tests/Unit/Services/Apps/AppRuntimeServiceManagerTest.php
bin/orbit-gateway-pest --compact apps/gateway/tests/Unit/Services/Apps/AppsProbeTest.php
bin/orbit-gateway-pest --compact apps/gateway/tests/Unit/Services/Apps/AppsFixerTest.php
bin/orbit-gateway-pest --compact apps/gateway/tests/Unit/Services/Deploy/DeployManagerContainerRoutingTest.php
bin/orbit-gateway-pest --compact apps/gateway/tests/Unit/Services/Deploy/DeployManagerSwarmUpdateTest.php
bin/orbit-gateway-pest --compact apps/gateway/tests/Unit/Services/Proxy/ProxyRouteRendererTest.php
bin/orbit-gateway-pest --compact apps/gateway/tests/Feature/Actions/Apps/EnactAppRuntimeTest.php
composer docs-lint
```

Expected: all pass.

- [ ] **Step 2: Format PHP**

Run:

```bash
bin/orbit-gateway-vendor-bin pint --dirty --format agent
```

Expected: no remaining dirty formatting changes beyond Pint's fixes.

- [ ] **Step 3: Run broad quality gate**

Run:

```bash
composer quality-check
```

Expected: pass. If this fails outside the touched surface, record the unrelated failure and keep the focused checks as the evidence for this change.

## Notes For Implementers

- Do not add app runtime users to the `docker` group.
- Do not mount `/var/run/docker.sock` into app runtime containers.
- Do not publish app runtime container ports.
- Use numeric Docker `--user <uid>:<gid>`, not a username.
- If the app path is `/home/orbit/apps/<app>`, the path-derived app user is `orbit`; that is not per-app OS-user isolation. Per-app isolation requires app-prod paths under the intended app user's home, such as `/home/docs/app`.
- PHP-FPM references that state negative behavior are valid. Stale references that name FPM pools as active app/workspace security artifacts are not.
