# Orbit Gateway Swarm Update Runner Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the production `orbit-runtime` gateway artifact with a packaged `orbit-gateway` image, run the gateway API and scheduler as Swarm services, and make `orbit update:all` a durable operation executed by a one-shot runner from the target image.

**Architecture:** This plan builds on the merged `docs/superpowers/plans/2026-06-01-source-mounted-live-topologies.md` contracts. Mutable gateway state already lives under `ORBIT_CONFIG_ROOT`, node-local internal executor tokens verify through the gateway API, and source-mounted topology work is separated from packaged artifact verification. The new update path starts a durable operation, launches an `orbit-gateway:<target>` one-shot runner, lets that runner update Swarm services and workload nodes, and lets operator CLIs reconnect to persisted progress events across gateway replacement.

**Tech Stack:** Laravel 13 gateway, Laravel Zero CLI, Pest 4, Docker Engine Swarm services, `docker stack deploy` / `docker service update`, FrankenPHP gateway image, SQLite-backed operation journal, Server-Sent Events.

---

## Prerequisite

The source-mounted live topology plan has landed on main. This plan depends on these completed contracts:

- `docs/superpowers/plans/2026-06-01-source-mounted-live-topologies.md`
- gateway state root under `ORBIT_CONFIG_ROOT`
- direct source CLI entrypoint in development topologies
- gateway-backed internal executor token introspection
- removal of node-local executor shared secret

This plan intentionally changes production packaging and update behavior. Keep source-mounted Docker/Incus development lanes intact.

The implementation should treat the source-mounted plan as the completed foundation, not as work to redo here. Development and E2E source-mounted topologies keep pointing at the worktree source; this plan changes the production artifact lane and the fleet update lifecycle built on top of the new state-root and token-introspection contracts.

Some historical task snippets in the source-mounted plan predate the merged implementation. When this plan disagrees with those snippets, use the merged code and tests as authority. In particular, gateway `.env` lives at `$ORBIT_CONFIG_ROOT/.env`, gateway SQLite lives at `$ORBIT_CONFIG_ROOT/gateway.sqlite`, and framework storage remains app-local under `apps/gateway/storage`.

## Product Decisions

- Rename the first-party gateway runtime artifact from `orbit-runtime` to `orbit-gateway`.
- Publish `orbit-gateway:<version>` as a first-party FrankenPHP image with gateway code bundled.
- Publish production gateway images as `ghcr.io/hardimpactdev/orbit-gateway:<version>` and `ghcr.io/hardimpactdev/orbit-gateway:latest`; update plans must use digest-pinned image references from the release manifest for production runs.
- Publish the release manifest JSON as a GitHub Release asset for the matching Orbit release.
- Build the public `orbit-gateway` image from a clean monorepo context. The Dockerfile-specific ignore file must exclude host `.env` files, vendor directories, framework storage, local SQLite files, node modules, and Git metadata before any `COPY` runs.
- Keep the monorepo depth inside the image (`/srv/orbit/apps/gateway` and `/srv/orbit/packages/core`) so the gateway path repository `../../packages/core` resolves naturally during a clean `composer install`.
- FrankenPHP request handling reports `PHP_SAPI === 'frankenphp'`. Any gateway SSE/progress emitter must flush for that SAPI so operation-follow streams are live under the production image.
- Run gateway API as a Swarm service named `orbit-gateway`.
- Run the scheduler as a Swarm service named `orbit-scheduler`, using the same `orbit-gateway:<version>` image with command `php artisan orbit-scheduler`.
- Treat production gateway hosts as Docker/Swarm hosts, not PHP application hosts. A production gateway host needs Docker Engine, Swarm, the self-contained Orbit CLI, and `ORBIT_CONFIG_ROOT`; it does not need host PHP, Composer, or a gateway source checkout.
- Split live topology execution into two explicit lanes:
  - `source-dev`: fast development topology. VMs provide PHP for source CLI execution and run `/usr/local/bin/orbit` from the synced worktree for immediate CLI feedback. Composer dependencies are hydrated by host Composer when present or a helper image when absent.
  - `artifact-prod`: production-grade topology. VMs use native CLI binaries and published/built images. Host PHP/Composer is installed only when a node has an `app-dev` or `app-prod` role; gateway-only, database-only, S3-only, websocket-only, and operator-only nodes do not need host PHP.
- `source-dev` and `artifact-prod` are new lane labels introduced by this plan; current code still has provider/topology artifact lanes that must be adapted.
- Operation tokens are signed and verified with the gateway `APP_KEY`; do not introduce a separate operation-token signing secret or gateway identity env variable.
- The gateway role alone does not own `orbit-caddy`. `orbit-caddy` belongs to router, app, agent, and ingress role baselines. When router and gateway roles are co-located, the router-owned `orbit-caddy` is allowed to front the gateway API as the node's only host `80/443` listener.
- The gateway API certificate remains a gateway-issued leaf certificate signed by the Orbit root CA. Exposure mode changes where that leaf is mounted: `orbit-caddy` presents it in router-colocated mode, and `orbit-gateway` presents it in direct mode. The trust anchor clients use does not change.
- Support two gateway API exposure modes:
  - `router-colocated`: selected when the gateway node also has the router role. `orbit-caddy` owns host `80/443`, mounts the gateway leaf cert/key from the gateway config root, accepts gateway API traffic from the Orbit/WireGuard control plane, and reverse-proxies to `orbit-gateway` on the shared attachable overlay `orbit-network`. `orbit-gateway` must not publish host ports in this mode.
  - `gateway-direct`: selected when the router role is absent from the gateway node or moved to a dedicated router node. `orbit-gateway` publishes its HTTPS endpoint through Swarm ingress so `start-first` replacement remains possible, and the installer/firewall layer permits that published endpoint only through the gateway WireGuard interface or gateway WireGuard CIDR.
- Gateway API access control depends on exposure mode. `gateway-direct` uses a Docker-aware firewall path, such as provider firewall rules plus `nftables`/`iptables` rules that apply to Docker published-port traffic, not plain UFW-only rules. `router-colocated` does not apply a blanket host `443` deny because router Caddy needs public `443` for app ingress; instead the generated gateway Caddy route must reject non-WireGuard clients before proxying.
- Router-colocated gateway Caddy routes filter the immediate peer with `remote_ip`, not `client_ip`, so the route does not depend on global `trusted_proxies` behavior. The route strips caller-supplied forwarding identity and injects the observed WireGuard peer before proxying to Laravel.
- The initial production gateway Swarm contract is gateway-local: `orbit-gateway` and `orbit-scheduler` are pinned to the gateway node that owns `ORBIT_CONFIG_ROOT`, SQLite, CA material, and the gateway leaf key. Multi-node gateway HA is out of scope for this plan.
- A gateway node does not imply a router role. If a topology co-locates router and gateway roles, the node uses `router-colocated` exposure; otherwise it uses `gateway-direct`.
- Keep the VPN/DNS substrate outside Swarm in this plan. `wg-easy` and `orbit-dns` must move together in a dedicated follow-up because `orbit-dns` currently shares the `wg-easy` network namespace.
- Make `orbit update:all` durable: the gateway creates an operation row and event journal, launches a one-shot update runner from the target image, and returns/follows an operation stream.
- Guard update mutations with expiring resource leases, not permanent locks. A node, gateway service, scheduler service, or fleet update can only be updated by one fresh lease owner at a time; expired leases are reclaimable.
- Every update entry point that mutates a node, gateway service, scheduler service, or fleet update must acquire the matching lease before it starts remote or Docker-side mutation.
- The one-shot runner owns the full gateway/fleet update attempt. It succeeds or fails and writes terminal operation state.
- The operator CLI follows persisted operation events and reconnects after gateway API replacement.
- The runner container and live gateway service share `$ORBIT_CONFIG_ROOT/gateway.sqlite`; the gateway SQLite connection must use WAL journal mode and a bounded busy timeout so runner writes and API event-stream reads do not fail with avoidable lock errors.
- Use Swarm's `start-first` update order for `orbit-gateway`.
- Use singleton `stop-first` replacement for `orbit-scheduler`; never briefly run old and new scheduler loops unless a later contract explicitly allows it.
- Workload node updates remain gateway-owned remote execution over SSH/RemoteShell after the new gateway API is healthy.
- Each `update:all` operation persists an immutable update plan before the one-shot runner starts. The plan is keyed to `operation_run_id` and records target version, digest-pinned gateway image, manifest version/source, and CLI artifact URLs plus hashes. The runner reads that persisted plan; it must not re-resolve mutable release tags or manifests mid-run.

## File Map

- Product docs:
  - `apps/docs/content/architecture.md`
  - `apps/docs/content/tech-stack.md`
  - `apps/docs/content/concepts.md`
  - `apps/docs/content/execution-lanes.md`
  - `apps/docs/content/domains/1_node/node-concepts.md`
  - `apps/docs/content/domains/2_gateway/README.md`
  - `apps/docs/content/domains/9_schedule/README.md`
  - `apps/docs/content/domains/9_schedule/schedule-concepts.md`
  - `apps/docs/content/domains/9_schedule/schedule-doctor.md`
  - `apps/docs/content/domains/11_operation/1_update/**`
  - `apps/docs/content/domains/11_operation/2_update-all/**`
- Gateway image:
  - Create: `docker/orbit-gateway/Dockerfile`
  - Create: `docker/orbit-gateway/Dockerfile.dockerignore`
  - Create: `docker/orbit-gateway/entrypoint.sh`
  - Create: `docker/orbit-gateway/Caddyfile`
  - Create: `docker/orbit-gateway/healthcheck.sh`
  - Modify: `apps/gateway/app/Console/Commands/Internal/BuildRuntimeImagesCommand.php`
  - Remove after compatibility bridge: `docker/orbit-runtime/Dockerfile`
  - Remove after compatibility bridge: `docker/orbit-runtime/entrypoint.sh`
- Gateway Swarm services:
  - Create: `apps/gateway/app/Services/Gateway/GatewaySwarmStackRenderer.php`
  - Create: `apps/gateway/app/Services/Gateway/GatewaySwarmManager.php`
  - Create: `apps/gateway/app/Services/Gateway/GatewayImageReference.php`
  - Create: `apps/gateway/app/Services/Gateway/GatewayExposureMode.php`
  - Create: `apps/gateway/app/Services/Gateway/GatewayCaddyRouteRenderer.php`
  - Modify: `apps/gateway/app/Services/Gateway/GatewayApiRuntimeInstaller.php`
  - Modify: `apps/gateway/app/Services/Runtime/OrbitContainerNames.php`
  - Modify: `bin/install-orbit`
- Scheduler:
  - Modify: `apps/gateway/app/Services/Schedules/OrbitSchedulerProgramRenderer.php`
  - Modify: `apps/gateway/app/Services/Schedules/SchedulesProbe.php`
  - Modify: `apps/gateway/app/Services/Schedules/SchedulesFixer.php`
- Operation journal:
  - Create: `apps/gateway/database/migrations/2026_06_01_000000_create_operation_events_table.php`
  - Create: `apps/gateway/app/Models/OperationEvent.php`
  - Create: `apps/gateway/app/Services/Operations/OperationEventRecorder.php`
  - Create: `apps/gateway/app/Services/Operations/OperationEventStreamer.php`
  - Modify: `apps/gateway/app/Support/Streaming/ProgressEventStreamEmitter.php`
  - Modify: `apps/gateway/app/Services/Operations/ResultBoundaryRedactionPolicy.php`
  - Modify: `apps/gateway/config/database.php`
- Operation update leases:
  - Create: `apps/gateway/database/migrations/2026_06_01_000100_create_operation_update_leases_table.php`
  - Create: `apps/gateway/app/Models/OperationUpdateLease.php`
  - Create: `apps/gateway/app/Services/Updates/UpdateLeaseConflict.php`
  - Create: `apps/gateway/app/Services/Updates/UpdateLeaseManager.php`
  - Create: `apps/gateway/app/Services/Updates/UpdateLeaseHeartbeat.php`
- Update operation API:
  - Create: `apps/gateway/app/Http/Controllers/Api/UpdateAllStartController.php`
  - Create: `apps/gateway/database/migrations/2026_06_01_000200_create_operation_update_plans_table.php`
  - Create: `apps/gateway/app/Models/OperationUpdatePlan.php`
  - Create: `apps/gateway/app/Services/Updates/UpdatePlanSnapshot.php`
  - Create: `apps/gateway/app/Services/Updates/UpdatePlanStore.php`
  - Create: `apps/gateway/app/Services/Updates/UpdatePlanBuilder.php`
  - Create: `apps/gateway/app/Http/Controllers/Api/OperationEventStreamController.php`
  - Create: `apps/gateway/app/Services/Updates/UpdateRunnerLauncher.php`
  - Modify: `apps/gateway/app/Http/Controllers/Api/UpdateAllController.php`
  - Modify: `apps/gateway/routes/api.php`
- Update runner:
  - Create: `apps/gateway/app/Console/Commands/Internal/UpdateRunnerCommand.php`
  - Create: `apps/gateway/app/Services/Updates/FleetUpdateRunner.php`
  - Create: `apps/gateway/app/Services/Updates/GatewayServiceUpdater.php`
  - Create: `apps/gateway/app/Services/Updates/WorkloadNodeUpdater.php`
  - Create: `apps/gateway/app/Services/Updates/FleetUpdateVerifier.php`
  - Create: `apps/gateway/app/Services/Updates/ReleaseManifest.php`
  - Create: `apps/gateway/app/Services/Updates/ReleaseManifestResolver.php`
  - Modify: `apps/gateway/app/Services/OrbitUpdater.php`
- CLI:
  - Modify: `apps/cli/app/Commands/Operation/UpdateAllCommand.php`
  - Modify: `apps/cli/app/Services/GatewayStreamClient.php`
  - Create: `apps/cli/app/Services/Operations/GatewayOperationFollower.php`
- Release/publish:
  - Modify: `.github/workflows/orbit-cli-binary.yml` or create `.github/workflows/orbit-release.yml`
  - Modify: `apps/e2e/app/Console/Commands/E2EEnsureArtifactsCommand.php`
  - Modify: `apps/e2e/app/Console/Commands/E2EPrepareDockerRuntimeCommand.php`
  - Modify: `apps/e2e/app/Services/E2E/DockerImageDistributor.php`
  - Modify: `apps/e2e/app/E2E/Support/E2ETopologyArtifactNamespace.php`
  - Modify: `apps/e2e/app/E2E/Support/OrbitCliBinaryBundle.php`

---

### Task 1: Align Product Docs For `orbit-gateway`

**Files:**
- Modify: files listed under Product docs in File Map

- [ ] **Step 1: Update architecture and concepts**

Replace `orbit-runtime` as the gateway control-plane term with `orbit-gateway`.

Also update first-bootstrap and node-role docs so they no longer imply the gateway role automatically includes router behavior. Historical topologies may still co-locate gateway, VPN/DNS, and router roles, but that is a topology choice, not a gateway role contract. When router and gateway roles co-locate, docs must say the node uses router-colocated exposure: router-owned `orbit-caddy` owns host `80/443` and proxies gateway API traffic to `orbit-gateway` on the shared attachable overlay `orbit-network`. When the router role is absent from the gateway node, docs must say the node uses gateway-direct exposure: `orbit-gateway` publishes gateway API `443`.

Required wording:

```markdown
- **Orbit gateway image:** first-party `orbit-gateway:<version>` image that bundles the Laravel gateway app and serves the private gateway API through FrankenPHP.
- **Orbit gateway service:** Swarm service named `orbit-gateway`; it runs the gateway API through bundled FrankenPHP/Caddy and is reachable only over the Orbit/WireGuard control plane, either directly or through router-owned `orbit-caddy` depending on exposure mode.
- **Gateway API exposure modes:** `router-colocated` means router-owned `orbit-caddy` owns host `80/443`, presents the gateway API leaf cert, rejects non-WireGuard gateway API clients with an immediate-peer `remote_ip` matcher, and proxies to `orbit-gateway` on the shared attachable overlay `orbit-network`; `gateway-direct` means `orbit-gateway` publishes gateway API `443` itself because no router Caddy is co-located.
- **Gateway API TLS:** the gateway issues a leaf certificate from the Orbit root CA, with SANs covering the configured gateway URL and gateway WireGuard IP. The active exposure endpoint mounts that leaf cert/key from `$ORBIT_CONFIG_ROOT/certs` and presents it to clients. In router-colocated mode, router-owned `orbit-caddy` mounts `$ORBIT_CONFIG_ROOT/certs` read-only at `/run/orbit-gateway-certs`; in gateway-direct mode, `orbit-gateway` reads the same files from its config-root bind.
- **Orbit Scheduler service:** Swarm service named `orbit-scheduler`; it uses the same `orbit-gateway:<version>` image and runs `php artisan orbit-scheduler` as a singleton.
- **Orbit Caddy router:** `orbit-caddy` is owned by router, app, agent, and ingress role baselines. It is not part of the gateway role, but it fronts the gateway API in router-colocated exposure mode.
- **Gateway/router placement:** the production gateway Swarm contract pins `orbit-gateway` and `orbit-scheduler` to the gateway node that owns the config root and CA material. A node may co-locate router and gateway roles only when gateway exposure mode is `router-colocated`; otherwise router and gateway roles use separate nodes.
- **Production gateway host:** Docker/Swarm host with the self-contained Orbit CLI and persistent `ORBIT_CONFIG_ROOT`. It does not require host PHP, Composer, or a gateway source checkout.
- **Source-dev topology:** development-only topology where each VM provides PHP for source CLI execution, receives the current worktree, links `/usr/local/bin/orbit` to `<source>/apps/cli/orbit`, and hydrates Composer dependencies through host Composer when present or a helper image when absent.
- **Artifact-prod topology:** production-grade topology where each VM uses the native Orbit CLI binary and first-party images. This lane validates the real release/update artifacts. Host PHP/Composer is role-scoped: install it for `app-dev` and `app-prod` nodes, and do not install it for gateway-only, database-only, S3-only, websocket-only, or operator-only nodes.
- **Gateway config root:** gateway `.env` lives at `$ORBIT_CONFIG_ROOT/.env`, gateway SQLite lives at `$ORBIT_CONFIG_ROOT/gateway.sqlite`, and framework storage remains app-local under `apps/gateway/storage`.
- **Operation tokens:** internal executor operation tokens are signed and verified with gateway `APP_KEY`.
```

- [ ] **Step 2: Update schedule docs**

Change schedule docs to say the scheduler is a gateway-only Swarm service, not a detached process inside `orbit-runtime`.

Required rules:

```markdown
- The scheduler runs as exactly one `orbit-scheduler` Swarm service replica.
- The scheduler image version must match the active `orbit-gateway` service image version.
- Gateway updates stop or replace the scheduler before/after migrations so old and new scheduler loops do not overlap.
- `doctor --family=schedule` verifies Swarm service state and scheduler heartbeat.
```

- [ ] **Step 3: Update operation/update docs**

Update `update` and `update:all` contracts:

```markdown
- `orbit update` updates only the caller-local CLI binary and verifies it.
- `orbit update:all` updates the caller-local CLI first, then starts a durable gateway-owned fleet update operation.
- The gateway launches a one-shot update runner from the target `orbit-gateway:<version>` image.
- Operator progress follows `/api/operations/{operation}/events` and may reconnect while the gateway API service is replaced.
- Gateway self-update uses local Docker/Swarm control, not SSH to itself.
- Workload nodes are updated only after the new gateway API and scheduler path are healthy.
- Update mutation uses expiring leases. A fresh lease blocks concurrent updates for the same node or gateway resource; an expired lease may be reclaimed so a crashed runner cannot block future updates forever.
- All node update paths, including future targeted node updates, must acquire a node lease before they run SSH/RemoteShell mutation.
- A node lease conflict fails the update operation with `update.node_locked` and includes the conflicting operation id and lease expiry time.
```

- [ ] **Step 4: Run docs lint**

Run:

```bash
composer docs-lint
```

Expected: two librarian JSON lines with `"result":"passed"` and zero issues.

- [ ] **Step 5: Commit**

```bash
git add apps/docs/content
git commit -m "Document orbit-gateway Swarm update contract"
```

---

### Task 2: Build Packaged `orbit-gateway` Image

**Files:**
- Create: `docker/orbit-gateway/Dockerfile`
- Create: `docker/orbit-gateway/Dockerfile.dockerignore`
- Create: `docker/orbit-gateway/entrypoint.sh`
- Create: `docker/orbit-gateway/Caddyfile`
- Create: `docker/orbit-gateway/healthcheck.sh`
- Modify: `apps/gateway/app/Console/Commands/Internal/BuildRuntimeImagesCommand.php`
- Test: `apps/gateway/tests/Feature/Runtime/OrbitGatewayImageContractTest.php`

- [ ] **Step 1: Write image contract test**

Create `OrbitGatewayImageContractTest.php` with assertions that the Dockerfile:

```php
expect($dockerfile)
    ->toContain('FROM dunglas/frankenphp')
    ->toContain('WORKDIR /srv/orbit/apps/gateway')
    ->toContain('COPY apps/gateway /srv/orbit/apps/gateway')
    ->toContain('COPY packages/core /srv/orbit/packages/core')
    ->toContain('composer install')
    ->toContain('docker')
    ->toContain('orbit-gateway-entrypoint')
    ->not->toContain('VOLUME ["/opt/orbit"]');
```

Assert the Dockerfile-specific ignore file exists and blocks host secrets and build-only artifacts from entering the public image build context:

```php
$ignore = File::get(repo_path('docker/orbit-gateway/Dockerfile.dockerignore'));

expect($ignore)
    ->toContain('**/.env')
    ->toContain('**/vendor')
    ->toContain('**/storage/*.sqlite')
    ->toContain('**/storage/logs')
    ->toContain('**/node_modules')
    ->toContain('.git');
```

Assert the build command uses the new Dockerfile and tag. Do not reject a
literal `orbit-runtime:current` deprecation warning in the command source:

```php
expect($commandSource)
    ->toContain('docker/orbit-gateway/Dockerfile')
    ->toContain('orbit-gateway:current')
    ->not->toContain('docker/orbit-runtime/Dockerfile');
```

- [ ] **Step 2: Run failing test**

```bash
bin/orbit-gateway-pest --compact apps/gateway/tests/Feature/Runtime/OrbitGatewayImageContractTest.php
```

Expected: FAIL because `docker/orbit-gateway/Dockerfile` does not exist.

- [ ] **Step 3: Add Dockerfile**

Use this shape:

```dockerfile
FROM dunglas/frankenphp:1-php8.5-bookworm

ENV APP_ENV=production \
    APP_DEBUG=false \
    ORBIT_CONFIG_ROOT=/home/orbit/.config/orbit \
    ORBIT_GATEWAY_TLS_CERT=/home/orbit/.config/orbit/certs/gateway.crt \
    ORBIT_GATEWAY_TLS_KEY=/home/orbit/.config/orbit/certs/gateway.key \
    ORBIT_GATEWAY_HEALTH_PORT=8080 \
    SERVER_NAME=:443

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        bash ca-certificates curl git libcurl4-openssl-dev libicu-dev \
        libonig-dev libsqlite3-dev libzip-dev openssh-client procps sudo tini unzip zip \
    && docker-php-ext-install bcmath curl intl mbstring pcntl pdo_sqlite zip \
    && useradd --create-home --home-dir /home/orbit --shell /usr/sbin/nologin orbit \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY --from=docker:cli /usr/local/bin/docker /usr/local/bin/docker
COPY --from=docker:cli /usr/local/libexec/docker/cli-plugins/docker-compose /usr/local/libexec/docker/cli-plugins/docker-compose

WORKDIR /srv/orbit/apps/gateway
COPY apps/gateway /srv/orbit/apps/gateway
COPY packages/core /srv/orbit/packages/core
COPY docker/orbit-gateway/Caddyfile /etc/caddy/Caddyfile
COPY docker/orbit-gateway/entrypoint.sh /usr/local/bin/orbit-gateway-entrypoint
COPY docker/orbit-gateway/healthcheck.sh /usr/local/bin/orbit-gateway-healthcheck

RUN chmod 755 /usr/local/bin/orbit-gateway-entrypoint /usr/local/bin/orbit-gateway-healthcheck \
    && composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-progress

ENTRYPOINT ["/usr/bin/tini", "--", "/usr/local/bin/orbit-gateway-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
```

`/home/orbit/.config/orbit` is the container mount path for gateway mutable state. The `orbit` user exists so the path is meaningful, but the initial implementation may still run the process as root when the Docker socket is mounted for one-shot update runners.

Add `docker/orbit-gateway/Dockerfile.dockerignore`:

```dockerignore
.git
**/.env
**/.env.*
**/vendor
**/node_modules
**/storage/*.sqlite
**/storage/logs
**/storage/framework/cache
**/storage/framework/sessions
**/storage/framework/views
apps/gateway/database/*.sqlite
apps/gateway/bootstrap/cache/*.php
```

- [ ] **Step 4: Add entrypoint and healthcheck**

`entrypoint.sh`:

```bash
#!/usr/bin/env bash
set -Eeuo pipefail

install -d -m 0700 "${ORBIT_CONFIG_ROOT}"
exec "$@"
```

`healthcheck.sh`:

```bash
#!/usr/bin/env bash
set -Eeuo pipefail
curl -fsS "http://127.0.0.1:${ORBIT_GATEWAY_HEALTH_PORT:-8080}/up" >/dev/null
```

`Caddyfile`:

```caddyfile
{
    admin off
}

:443 {
    tls {$ORBIT_GATEWAY_TLS_CERT} {$ORBIT_GATEWAY_TLS_KEY}
    root * /srv/orbit/apps/gateway/public
    php_server
}

:8080 {
    root * /srv/orbit/apps/gateway/public
    php_server
}
```

- [ ] **Step 5: Rename build command behavior**

Modify `BuildRuntimeImagesCommand` to build `orbit-gateway:current` from `docker/orbit-gateway/Dockerfile`.

Keep a temporary warning if old `orbit-runtime:current` exists:

```php
$this->warn('orbit-runtime:current is deprecated; use orbit-gateway:current.');
```

- [ ] **Step 6: Run focused test and image build**

```bash
bin/orbit-gateway-pest --compact apps/gateway/tests/Feature/Runtime/OrbitGatewayImageContractTest.php
bin/orbit-gateway-artisan orbit:internal:build-runtime-images --force
docker run --rm --entrypoint sh orbit-gateway:current -lc 'test ! -f /srv/orbit/apps/gateway/.env && test ! -f /srv/orbit/apps/gateway/database/database.sqlite && test -d /srv/orbit/apps/gateway/vendor'
```

Expected: test passes; Docker has `orbit-gateway:current`; the image contains a clean Composer-installed vendor directory but not host `.env` or local SQLite files.

- [ ] **Step 7: Format and commit**

```bash
bin/orbit-gateway-vendor-bin pint --dirty --format agent
git add docker/orbit-gateway apps/gateway/app/Console/Commands/Internal/BuildRuntimeImagesCommand.php apps/gateway/tests/Feature/Runtime/OrbitGatewayImageContractTest.php
git commit -m "Build packaged orbit-gateway image"
```

---

### Task 3: Add Gateway Swarm Stack Renderer

**Files:**
- Create: `apps/gateway/app/Services/Gateway/GatewayImageReference.php`
- Create: `apps/gateway/app/Services/Gateway/GatewayExposureMode.php`
- Create: `apps/gateway/app/Services/Gateway/GatewaySwarmStackRenderer.php`
- Create: `apps/gateway/app/Services/Gateway/GatewaySwarmManager.php`
- Test: `apps/gateway/tests/Unit/Services/Gateway/GatewaySwarmStackRendererTest.php`
- Test: `apps/gateway/tests/Unit/Services/Gateway/GatewaySwarmManagerTest.php`

- [ ] **Step 1: Write stack renderer tests**

Assert direct-mode stack YAML contains:

```php
$yaml = $renderer->render(
    GatewayImageReference::fromImage('ghcr.io/hardimpactdev/orbit-gateway:v1.2.3'),
    GatewayExposureMode::GatewayDirect,
);

expect($yaml)
    ->toContain('orbit-gateway:')
    ->toContain('image: ghcr.io/hardimpactdev/orbit-gateway:v1.2.3')
    ->toContain('aliases:')
    ->toContain('- orbit-gateway')
    ->toContain('order: start-first')
    ->toContain('failure_action: rollback')
    ->toContain('monitor: 60s')
    ->toContain('orbit-scheduler:')
    ->toContain('command:')
    ->toContain('php')
    ->toContain('artisan')
    ->toContain('orbit-scheduler')
    ->toContain('order: stop-first')
    ->toContain('/var/run/docker.sock:/var/run/docker.sock')
    ->toContain('${ORBIT_CONFIG_ROOT:-/home/orbit/.config/orbit}:/home/orbit/.config/orbit')
    ->toContain('target: 443')
    ->toContain('published: 443')
    ->toContain('mode: ingress')
    ->toContain('placement:')
    ->toContain('node.labels.orbit.role.gateway == true')
    ->not->toContain('orbit-caddy:')
    ->not->toContain('published: 80')
    ->not->toContain('/etc/caddy')
    ->not->toContain('/etc/orbit');
```

Assert router-colocated stack YAML does not publish gateway host ports:

```php
$yaml = $renderer->render(
    GatewayImageReference::fromImage('ghcr.io/hardimpactdev/orbit-gateway:v1.2.3'),
    GatewayExposureMode::RouterColocated,
);

expect($yaml)
    ->toContain('orbit-gateway:')
    ->toContain('aliases:')
    ->toContain('- orbit-gateway')
    ->toContain('ORBIT_GATEWAY_HEALTH_PORT: "8080"')
    ->toContain('placement:')
    ->toContain('node.labels.orbit.role.gateway == true')
    ->not->toContain('published: 443')
    ->not->toContain('target: 443')
    ->not->toContain('mode: ingress')
    ->not->toContain('orbit-caddy:');
```

- [ ] **Step 2: Add image reference and exposure mode value objects**

```php
final readonly class GatewayImageReference
{
    public function __construct(public string $image)
    {
        if (trim($image) === '') {
            throw new InvalidArgumentException('Gateway image cannot be empty.');
        }
    }

    public static function current(): self
    {
        return new self('orbit-gateway:current');
    }

    public static function fromImage(string $image): self
    {
        return new self($image);
    }
}
```

```php
enum GatewayExposureMode: string
{
    case RouterColocated = 'router-colocated';
    case GatewayDirect = 'gateway-direct';

    public function publishesGatewayPort(): bool
    {
        return $this === self::GatewayDirect;
    }
}
```

- [ ] **Step 3: Add stack renderer**

Render a Swarm stack file from `GatewaySwarmStackRenderer::render(GatewayImageReference $image, GatewayExposureMode $exposureMode)`.

Direct mode includes published gateway `443`:

```yaml
version: "3.8"
services:
  orbit-gateway:
    image: <image>
    networks:
      orbit-network:
        aliases:
          - orbit-gateway
    environment:
      APP_ENV: production
      APP_DEBUG: "false"
      ORBIT_CONFIG_ROOT: /home/orbit/.config/orbit
      ORBIT_GATEWAY_TLS_CERT: /home/orbit/.config/orbit/certs/gateway.crt
      ORBIT_GATEWAY_TLS_KEY: /home/orbit/.config/orbit/certs/gateway.key
      ORBIT_GATEWAY_HEALTH_PORT: "8080"
    ports:
      - target: 443
        published: 443
        protocol: tcp
        mode: ingress
    volumes:
      - ${ORBIT_CONFIG_ROOT:-/home/orbit/.config/orbit}:/home/orbit/.config/orbit
      - /var/run/docker.sock:/var/run/docker.sock
    healthcheck:
      test: ["CMD", "orbit-gateway-healthcheck"]
      interval: 5s
      timeout: 3s
      retries: 12
      start_period: 10s
    deploy:
      replicas: 1
      placement:
        constraints:
          - node.labels.orbit.role.gateway == true
      update_config:
        parallelism: 1
        order: start-first
        failure_action: rollback
        monitor: 60s
      rollback_config:
        parallelism: 1
        order: start-first
        monitor: 60s
  orbit-scheduler:
    image: <image>
    command: ["php", "artisan", "orbit-scheduler"]
    networks: [orbit-network]
    environment:
      APP_ENV: production
      APP_DEBUG: "false"
      ORBIT_CONFIG_ROOT: /home/orbit/.config/orbit
    volumes:
      - ${ORBIT_CONFIG_ROOT:-/home/orbit/.config/orbit}:/home/orbit/.config/orbit
      - /var/run/docker.sock:/var/run/docker.sock
    deploy:
      replicas: 1
      placement:
        constraints:
          - node.labels.orbit.role.gateway == true
      update_config:
        parallelism: 1
        order: stop-first
        failure_action: rollback
networks:
  orbit-network:
    external: true
```

Router-colocated mode renders the same services, volumes, placement, healthcheck, `orbit-network` alias, and network attachment, but omits the `ports:` block from `orbit-gateway`. The explicit `orbit-gateway` network alias is required because Docker stack deploy prefixes service names with the stack name; router-owned `orbit-caddy` must be able to resolve `http://orbit-gateway:8080` on the attachable `orbit-network`.

- [ ] **Step 4: Add Swarm manager**

Implement methods:

```php
public function ensureSwarm(): void
public function ensureGatewayNodeLabel(): void
public function ensureAttachableOverlayNetwork(string $network = 'orbit-network'): void
public function writeStackFile(string $contents): string
public function deployStack(string $stackFile, string $stack = 'orbit'): void
public function updateServiceImage(string $service, GatewayImageReference $image, string $order): void
public function scaleService(string $service, int $replicas): void
public function serviceImage(string $service): ?string
```

Command shapes:

```bash
docker info --format '{{.Swarm.LocalNodeState}}'
docker swarm init
docker node update --label-add orbit.role.gateway=true self
docker network inspect --format '{{.Driver}} {{.Scope}} {{.Attachable}}' orbit-network
docker network create --driver overlay --attachable orbit-network
docker stack deploy -c <stack-file> orbit
docker service inspect orbit_orbit-gateway
docker service inspect orbit_orbit-scheduler
docker service update --image <image> --update-order start-first --update-failure-action rollback --update-monitor 60s orbit_orbit-gateway
docker service update --image <image> --update-order stop-first --update-failure-action rollback --update-monitor 60s orbit_orbit-scheduler
docker service scale orbit_orbit-scheduler=0
```

`ensureAttachableOverlayNetwork()` must not assume an existing `orbit-network` is usable. It must inspect the existing network first:

- If `orbit-network` is absent, create it as `--driver overlay --attachable`.
- If it exists with driver `overlay`, scope `swarm`, and attachable enabled, reuse it.
- If it exists as a legacy bridge/local runtime network, fail with an explicit migration error. The first implementation must not attempt an implicit migration because detaching/stopping legacy Orbit containers and recreating router-owned `orbit-caddy` can interrupt unrelated role traffic. A later explicit migration command can own that rollout.

The implementation must never silently keep a bridge `orbit-network` for router-colocated gateway exposure, because a standalone router Caddy container cannot reach the Swarm service through a local bridge. Tests must cover absent network creation, valid overlay reuse, and legacy bridge rejection with the exact operator-facing migration message.

- [ ] **Step 5: Run tests**

```bash
bin/orbit-gateway-pest --compact apps/gateway/tests/Unit/Services/Gateway/GatewaySwarmStackRendererTest.php apps/gateway/tests/Unit/Services/Gateway/GatewaySwarmManagerTest.php
```

Expected: PASS.

- [ ] **Step 6: Format and commit**

```bash
bin/orbit-gateway-vendor-bin pint --dirty --format agent
git add apps/gateway/app/Services/Gateway/GatewayImageReference.php apps/gateway/app/Services/Gateway/GatewayExposureMode.php apps/gateway/app/Services/Gateway/GatewaySwarmStackRenderer.php apps/gateway/app/Services/Gateway/GatewaySwarmManager.php apps/gateway/tests/Unit/Services/Gateway
git commit -m "Render orbit-gateway Swarm stack"
```

---

### Task 4: Install Gateway Swarm Services

**Files:**
- Modify: `bin/install-orbit`
- Modify: `apps/gateway/app/Services/Gateway/GatewayApiRuntimeInstaller.php`
- Create: `apps/gateway/app/Services/Gateway/GatewayCaddyRouteRenderer.php`
- Test: `apps/gateway/tests/Feature/Runtime/InstallOrbitDockerFirstTest.php`
- Test: `apps/gateway/tests/Feature/Services/Gateway/GatewayApiRuntimeInstallerTest.php`

- [ ] **Step 1: Update installer tests**

Split assertions by artifact. No single `$script` string should be expected to contain Bash installer tokens, PHP method names, Swarm YAML, and Caddy route text.

`InstallOrbitDockerFirstTest` reads `bin/install-orbit` and must assert only Bash installer behavior:

```php
expect($script)
    ->toContain('docker swarm init')
    ->toContain('docker node update --label-add orbit.role.gateway=true self')
    ->toContain('docker network create --driver overlay --attachable orbit-network')
    ->toContain('ORBIT_GATEWAY_IMAGE_ARCHIVE')
    ->toContain('docker_cli load -i "$GATEWAY_IMAGE_ARCHIVE"')
    ->toContain('docker_cli pull "$GATEWAY_IMAGE"')
    ->toContain('docker_cli image inspect "$GATEWAY_IMAGE"')
    ->toContain('docker stack deploy')
    ->toContain('orbit-gateway:current')
    ->toContain('GATEWAY_ENV_FILE="$CONFIG_ROOT/.env"')
    ->toContain('GATEWAY_DATABASE_FILE="$CONFIG_ROOT/gateway.sqlite"')
    ->toContain('DB_BUSY_TIMEOUT=5000')
    ->toContain('DB_JOURNAL_MODE=WAL')
    ->toContain('DB_SYNCHRONOUS=NORMAL')
    ->toContain('APP_KEY=base64:')
    ->toContain('SESSION_DRIVER=file')
    ->not->toContain('orbit-runtime:current')
    ->not->toContain('--name orbit-runtime')
    ->not->toContain('composer install')
    ->not->toContain('git pull')
    ->not->toContain('apt-get install php')
    ->not->toContain('apt install php');
```

`GatewayCaddyRouteRendererTest` renders the router-colocated route and asserts Caddy text:

```php
$route = app(GatewayCaddyRouteRenderer::class)->render(
    serverNames: ['10.6.0.1', 'gateway.orbit.test'],
    wireguardCidr: '10.6.0.0/24',
);

expect($route)
    ->toContain('reverse_proxy http://orbit-gateway:8080')
    ->toContain('remote_ip')
    ->not->toContain('client_ip')
    ->toContain('abort @notWireGuard')
    ->toContain('request_header -X-Forwarded-For')
    ->toContain('request_header -X-Real-IP')
    ->toContain('request_header -Forwarded')
    ->toContain('request_header -X-Orbit-WireGuard-Ip')
    ->toContain('header_up X-Orbit-WireGuard-Ip {remote_host}')
    ->toContain('/run/orbit-gateway-certs/gateway.crt')
    ->toContain('/run/orbit-gateway-certs/gateway.key');
```

`GatewayApiRuntimeInstallerTest` must fake `Process`, collect commands, and assert command order/behavior. It should not search source for PHP method names such as `convergeRouterOwnedOrbitCaddy`.

```php
$commands = [];

Process::fake(function ($process) use (&$commands) {
    $commands[] = $process->command;

    return Process::result();
});

app(GatewayApiRuntimeInstaller::class)->install('10.6.0.2');

expect($commands)->sequence(
    fn (string $command) => str_contains($command, 'docker swarm init') || str_contains($command, 'docker info'),
    fn (string $command) => str_contains($command, 'docker image inspect') || str_contains($command, 'docker pull') || str_contains($command, 'docker load'),
    fn (string $command) => str_contains($command, 'docker stack deploy'),
);
```

`GatewaySwarmStackRendererTest` remains Task 3's responsibility and asserts service specs/YAML, including published ports and network aliases. Do not duplicate those YAML assertions in installer tests.

- [ ] **Step 2: Narrow production gateway host prerequisites**

The production gateway installer must only require these host capabilities:

```text
docker
docker swarm
orbit self-contained CLI binary
ORBIT_CONFIG_ROOT
system service support for Docker
```

The installer must not install or require host PHP, Composer, or a gateway Git checkout. All gateway app PHP, Composer dependencies, Artisan commands, migrations, scheduler execution, and update runner code execute inside `orbit-gateway:<version>` containers.

- [ ] **Step 3: Bootstrap config-root state before migrations**

Before running any packaged gateway container that can boot Laravel or run migrations, preserve the current flat config-root contract:

```bash
CONFIG_ROOT="${ORBIT_CONFIG_ROOT:-$HOME/.config/orbit}"
GATEWAY_ENV_FILE="$CONFIG_ROOT/.env"
GATEWAY_DATABASE_FILE="$CONFIG_ROOT/gateway.sqlite"

write_env_var() {
    key="$1"
    value="$2"
    tmp_file="${GATEWAY_ENV_FILE}.tmp.$$"

    if grep -q "^${key}=" "$GATEWAY_ENV_FILE" 2>/dev/null; then
        grep -v "^${key}=" "$GATEWAY_ENV_FILE" > "$tmp_file"
    else
        cp "$GATEWAY_ENV_FILE" "$tmp_file"
    fi

    printf '%s=%s\n' "$key" "$value" >> "$tmp_file"
    mv "$tmp_file" "$GATEWAY_ENV_FILE"
}

install -d -m 0700 "$CONFIG_ROOT"
touch "$GATEWAY_DATABASE_FILE"

if [ ! -f "$GATEWAY_ENV_FILE" ]; then
    {
        printf 'APP_ENV=production\n'
        printf 'APP_DEBUG=false\n'
    } > "$GATEWAY_ENV_FILE"
    chmod 0600 "$GATEWAY_ENV_FILE"
fi

if ! grep -q '^APP_KEY=base64:' "$GATEWAY_ENV_FILE" 2>/dev/null; then
    write_env_var "APP_KEY" "base64:$(openssl rand -base64 32)"
fi

write_env_var "DB_CONNECTION" "sqlite"
write_env_var "DB_DATABASE" "$GATEWAY_DATABASE_FILE"
write_env_var "DB_BUSY_TIMEOUT" "5000"
write_env_var "DB_JOURNAL_MODE" "WAL"
write_env_var "DB_SYNCHRONOUS" "NORMAL"
write_env_var "SESSION_DRIVER" "file"
```

The packaged `orbit-gateway` image must not move `.env` to `$ORBIT_CONFIG_ROOT/gateway/.env` or SQLite to `$ORBIT_CONFIG_ROOT/gateway/database`.

`apps/gateway/config/database.php` must read these values for the SQLite connection:

```php
'busy_timeout' => env('DB_BUSY_TIMEOUT', 5000),
'journal_mode' => env('DB_JOURNAL_MODE', 'WAL'),
'synchronous' => env('DB_SYNCHRONOUS', 'NORMAL'),
```

- [ ] **Step 4: Replace `start_runtime_container` in installer**

Rename installer steps:

```text
Prepare orbit-gateway image
Run migrations with orbit-gateway
Deploy orbit-gateway Swarm stack
Verify orbit-gateway service
```

Production gateway hosts do not have a source checkout, so "Prepare orbit-gateway image" must acquire the image before any `--pull never` command runs. Supported acquisition paths:

```bash
GATEWAY_IMAGE="${ORBIT_GATEWAY_IMAGE:-orbit-gateway:current}"
GATEWAY_IMAGE_ARCHIVE="${ORBIT_GATEWAY_IMAGE_ARCHIVE:-}"

if [ -n "$GATEWAY_IMAGE_ARCHIVE" ]; then
    docker_cli load -i "$GATEWAY_IMAGE_ARCHIVE"
elif ! docker_cli image inspect "$GATEWAY_IMAGE" >/dev/null 2>&1; then
    docker_cli pull "$GATEWAY_IMAGE"
fi

docker_cli image inspect "$GATEWAY_IMAGE" >/dev/null
```

Artifact-prod installs should set `ORBIT_GATEWAY_IMAGE` to the digest-pinned `ghcr.io/hardimpactdev/orbit-gateway@sha256:...` reference from the release manifest. Local/source-dev installs may still use `orbit-gateway:current` after `BuildRuntimeImagesCommand` builds it locally.

Use one-shot migration command:

```bash
docker run --rm --pull never \
  --env "ORBIT_CONFIG_ROOT=/home/orbit/.config/orbit" \
  --mount "type=bind,source=$CONFIG_ROOT,target=/home/orbit/.config/orbit" \
  --mount "type=bind,source=/var/run/docker.sock,target=/var/run/docker.sock" \
  "$GATEWAY_IMAGE" \
php artisan migrate --force --no-interaction
```

- [ ] **Step 5: Install gateway API TLS material and exposure-specific ingress**

Issue the gateway API leaf with the existing Orbit root CA, then copy that leaf into the config root. The active exposure endpoint presents this cert: router-owned `orbit-caddy` in `router-colocated` mode, or `orbit-gateway` in `gateway-direct` mode.

`$gatewayUrlHost` must be the parsed hostname or IP address only. It must never include a scheme, path, or port. The certificate SANs must include both the configured gateway hostname when present and the gateway WireGuard IP.

```php
$leaf = $orbitCa->issueLeaf($gatewayUrlHost, [$wireguardAddress]);
```

The issued certificate must chain to the same root CA PEM distributed by `gateway:add` / `gateway:trust`, and its SANs must cover the endpoint clients actually use, usually the gateway WireGuard IP and any configured gateway hostname.

```bash
GATEWAY_CERT_DIR="$CONFIG_ROOT/certs"
GATEWAY_CERT_FILE="$GATEWAY_CERT_DIR/gateway.crt"
GATEWAY_KEY_FILE="$GATEWAY_CERT_DIR/gateway.key"

install -d -m 0700 "$GATEWAY_CERT_DIR"
install -m 0644 "$ISSUED_GATEWAY_CERT" "$GATEWAY_CERT_FILE"
install -m 0600 "$ISSUED_GATEWAY_KEY" "$GATEWAY_KEY_FILE"
```

In `gateway-direct` mode, because Swarm ingress listens on the published port for any IP assigned to the node, preserve the product-level private API contract with Docker-aware firewall rules. Plain UFW-only rules are not enough because Docker published-port traffic may bypass or be reordered ahead of ordinary host firewall chains.

The installer must allow gateway API TCP `443` from the gateway WireGuard interface or WireGuard CIDR and deny it from public/provider interfaces using rules that apply to Docker ingress traffic. The initial implementation uses provider firewall rules plus the Docker `DOCKER-USER` chain for Docker's iptables-backed firewall path.

Required firewall intent:

```text
allow tcp 443 in on wg-orbit
allow tcp 443 from <wireguard-cidr>
deny tcp 443 from public interfaces
```

Initial Linux implementation should install idempotent `DOCKER-USER` rules for Docker's iptables-backed firewall path:

```bash
iptables -C DOCKER-USER -i wg-orbit -p tcp --dport 443 -j ACCEPT 2>/dev/null || iptables -I DOCKER-USER 1 -i wg-orbit -p tcp --dport 443 -j ACCEPT
iptables -C DOCKER-USER -s "$WIREGUARD_CIDR" -p tcp --dport 443 -j ACCEPT 2>/dev/null || iptables -I DOCKER-USER 2 -s "$WIREGUARD_CIDR" -p tcp --dport 443 -j ACCEPT
iptables -C DOCKER-USER -p tcp --dport 443 -j DROP 2>/dev/null || iptables -A DOCKER-USER -p tcp --dport 443 -j DROP
```

If Docker is configured with a non-iptables firewall backend, the installer must either install the equivalent nftables rules before Docker ingress accepts public traffic or fail with an explicit unsupported firewall backend error.

In `router-colocated` mode, `orbit-gateway` must not publish host ports. The router-owned `orbit-caddy` stays the only host `80/443` listener, joins the attachable `orbit-network`, mounts the gateway leaf cert/key, and routes only WireGuard clients to the gateway service.

The gateway cert mount is explicit and read-only:

```text
$CONFIG_ROOT/certs:/run/orbit-gateway-certs:ro
ORBIT_GATEWAY_TLS_CERT=/run/orbit-gateway-certs/gateway.crt
ORBIT_GATEWAY_TLS_KEY=/run/orbit-gateway-certs/gateway.key
```

The route must filter the immediate peer with `remote_ip`, strip caller-supplied forwarding identity, and inject the observed WireGuard peer before proxying:

```caddyfile
{$ORBIT_GATEWAY_SERVER_NAMES} {
    tls {$ORBIT_GATEWAY_TLS_CERT} {$ORBIT_GATEWAY_TLS_KEY}

    @notWireGuard not remote_ip {$ORBIT_WIREGUARD_CIDR}
    abort @notWireGuard

    request_header -X-Forwarded-For
    request_header -X-Real-IP
    request_header -Forwarded
    request_header -X-Orbit-WireGuard-Ip

    reverse_proxy http://orbit-gateway:8080 {
        flush_interval -1
        header_up Host {host}
        header_up X-Forwarded-Proto https
        header_up X-Orbit-WireGuard-Ip {remote_host}
    }
}
```

`ORBIT_GATEWAY_SERVER_NAMES` must be generated as valid Caddy site addresses for the gateway WireGuard IP and configured gateway hostname. The gateway WireGuard IP route must serve the Orbit CA leaf even while ordinary public app/router host routes remain available on the same router-owned `orbit-caddy`.

The Caddy route is a router-role concern, but the gateway installer may render or request it when the node has both gateway and router roles. It must not start a second gateway-owned Caddy container. Router-colocated convergence must prove `orbit-caddy` can read `/run/orbit-gateway-certs/gateway.crt` and `/run/orbit-gateway-certs/gateway.key` before reloading Caddy.

Adding the cert bind and `ORBIT_GATEWAY_TLS_*` environment values is a router-owned `orbit-caddy` container-spec change, not a live `docker network connect`-only update. Router-role Caddy convergence must compare the expected container spec, recreate the existing router-owned `orbit-caddy` when the `/run/orbit-gateway-certs` mount or TLS env vars are missing, then reconnect it to the attachable `orbit-network` if needed. This recreation still belongs to router-role convergence; the gateway installer may request it but must not create a second Caddy container or own router lifecycle outside the colocated route handoff.

Direct-mode tests must assert behavior by artifact:

```php
expect($directStack->publishedPorts())->toContain(['target' => 443, 'published' => 443])
    ->and($directStack->service('orbit-gateway')->mounts())->toContain([
        'source' => '$CONFIG_ROOT/certs',
        'target' => '/run/orbit-gateway-certs',
        'read_only' => true,
    ]);

Process::assertRan(fn ($process): bool => str_contains($process->command, 'DOCKER-USER')
    && str_contains($process->command, 'wg-orbit')
    && str_contains($process->command, '--dport 443'));

Process::assertNotRan(fn ($process): bool => str_contains($process->command, 'tee /etc/caddy/orbit/orbit-api.caddy'));
```

Router-colocated tests must assert behavior by artifact:

```php
expect($routerColocatedStack->service('orbit-gateway')->publishedPorts())->toBeEmpty();

expect($routerCaddyContainer->mounts())->toContain([
    'source' => '$CONFIG_ROOT/certs',
    'target' => '/run/orbit-gateway-certs',
    'read_only' => true,
]);

expect($route)
    ->toContain('reverse_proxy http://orbit-gateway:8080')
    ->toContain('remote_ip')
    ->not->toContain('client_ip')
    ->toContain('abort @notWireGuard')
    ->toContain('request_header -X-Forwarded-For')
    ->toContain('request_header -X-Real-IP')
    ->toContain('request_header -Forwarded')
    ->toContain('request_header -X-Orbit-WireGuard-Ip')
    ->toContain('header_up X-Orbit-WireGuard-Ip {remote_host}');

Process::assertRan(fn ($process): bool => str_contains($process->command, 'test -r /run/orbit-gateway-certs/gateway.crt'));
Process::assertRan(fn ($process): bool => str_contains($process->command, 'test -r /run/orbit-gateway-certs/gateway.key'));
Process::assertNotRan(fn ($process): bool => str_contains($process->command, 'iptables -A DOCKER-USER -p tcp --dport 443 -j DROP'));
```

Router-colocated tests must assert the router-owned `orbit-caddy` is attached to `orbit-network`, but must not require one specific command shape. A recreated Caddy container may attach through `docker run --network orbit-network`; an already-running container may attach through `docker network connect orbit-network orbit-caddy`.

Installer unit tests must also cover hostname normalization behavior: a configured gateway URL such as `https://gateway.orbit.test:9443/api` is reduced to `gateway.orbit.test` before certificate issuance, while the WireGuard IP is added as an IP SAN.

Mode-transition tests must cover stale artifact cleanup:

```php
$routerColocated = $installer->planTransition(GatewayExposureMode::RouterColocated);

expect($routerColocated->commands)->toContainCommand('iptables -D DOCKER-USER')
    ->not->toContainCommand('iptables -A DOCKER-USER -p tcp --dport 443 -j DROP');

expect($routerColocated->steps)->toEqual([
    'deploy-orbit-gateway-without-published-ports',
    'wait-for-healthy-gateway-service',
    'remove-direct-mode-firewall',
    'converge-router-owned-caddy-route',
]);

$gatewayDirect = $installer->planTransition(GatewayExposureMode::GatewayDirect);

expect($gatewayDirect->hostPortChecks)->toEqualCanonicalizing(['tcp/80', 'tcp/443', 'udp/443'])
    ->and($gatewayDirect->commands)->not->toContainCommand('docker run -d --name orbit-caddy')
    ->and($gatewayDirect->commands)->not->toContainCommand('docker create --name orbit-caddy');

expect($gatewayDirect->steps)->toEqual([
    'prove-router-caddy-released-host-ports',
    'remove-router-caddy-gateway-route',
    'install-direct-mode-firewall',
    'deploy-orbit-gateway-with-published-443',
    'wait-for-healthy-gateway-service',
]);
```

Create a local `toContainCommand()` expectation helper if no equivalent helper already exists. The tests should inspect planned command/step DTOs or faked `Process` invocations, not source-code substrings.

E2E artifact-prod verification must cover both exposure modes:

```text
router-colocated: curl --cacert <gateway-ca.pem> https://<gateway-wireguard-ip>/up reaches router Caddy, verifies against the configured gateway CA PEM, and proxies to orbit-gateway over orbit-network; non-WireGuard clients are rejected by the gateway route while ordinary router/app hosts remain available on public 443.
gateway-direct: https://<gateway-wireguard-ip> reaches orbit-gateway directly with verification against the configured gateway CA PEM, and the same API port is not reachable through the gateway provider/public interface.
```

Both modes must prove the mounted `$CONFIG_ROOT/certs/gateway.crt` verifies against the configured gateway CA PEM and includes the gateway WireGuard IP and configured hostname SANs.

- [ ] **Step 6: Update gateway runtime installer**

`GatewayApiRuntimeInstaller` should call `GatewaySwarmManager` and `GatewaySwarmStackRenderer`, not `OrbitRuntimeContainerRenderer`.

Required common setup:

```php
$exposureMode = $this->resolveGatewayExposureMode($nodeRoles);

$manager->ensureSwarm();
$manager->ensureGatewayNodeLabel();
$manager->ensureAttachableOverlayNetwork();
$this->installGatewayApiCertificate($wireguardAddress, $configRoot);
```

`router-colocated` transition sequence:

```php
$stackFile = $manager->writeStackFile($renderer->render(GatewayImageReference::current(), GatewayExposureMode::RouterColocated));
$manager->deployStack($stackFile);
$manager->waitForHealthyGatewayService('orbit_orbit-gateway');

$this->removeGatewayApiWireguardFirewall();
$this->convergeRouterOwnedOrbitCaddy($configRoot);
$this->ensureRouterCaddyGatewayRoute($wireguardAddress, $wireguardCidr, $configRoot);
```

This order is required for `gateway-direct` -> `router-colocated`: the gateway stack must first drop the published `443` port and become healthy on the internal health port before router-owned `orbit-caddy` is recreated or reloaded as the host `80/443` listener. Only after the no-ports gateway service is healthy may direct-mode `DOCKER-USER` rules be removed and router Caddy converged.

`gateway-direct` transition sequence:

```php
$this->ensureRouterCaddyReleasedHostPortsOrFail();
$this->removeRouterCaddyGatewayRouteIfPresent();
$this->ensureGatewayApiWireguardFirewall($wireguardAddress, 'wg-orbit');

$stackFile = $manager->writeStackFile($renderer->render(GatewayImageReference::current(), GatewayExposureMode::GatewayDirect));
$manager->deployStack($stackFile);
$manager->waitForHealthyGatewayService('orbit_orbit-gateway');
```

This order is required for `router-colocated` -> `gateway-direct`: direct exposure is valid only when the router role is absent from the gateway node or has already been moved away. `ensureRouterCaddyReleasedHostPortsOrFail()` must verify router-owned `orbit-caddy` no longer owns host `tcp/80`, `tcp/443`, or `udp/443` on the gateway node. If it still publishes any of those ports, the installer must fail with an explicit router-role cleanup error rather than racing `orbit-gateway` for fixed port `443`. The direct-mode firewall is installed before deploying the published-port gateway service so public/provider interfaces never get an unguarded API listener.

`GatewayApiRuntimeInstaller` must stop using `OrbitCaddyContainer`, `CaddyGlobalConfig`, and `CaddyTool` as a gateway-owned API container path. In router-colocated mode, it may call router-role Caddy configuration helpers only to add the gateway API route to the existing router-owned `orbit-caddy`.

Exposure transition cleanup requirements:

- Switching from `gateway-direct` to `router-colocated` deploys the no-ports gateway stack first, then removes the direct-mode gateway API `DOCKER-USER` deny/allow rules before router Caddy continues to own public `443`.
- Switching from `router-colocated` to `gateway-direct` removes or disables the generated gateway API Caddy route only after proving router-owned `orbit-caddy` no longer owns host `tcp/80`, `tcp/443`, or `udp/443` on the gateway node. It must not start or recreate `orbit-caddy`; router role convergence owns the router container lifecycle.

- [ ] **Step 7: Run focused tests**

```bash
bin/orbit-gateway-pest --compact apps/gateway/tests/Feature/Runtime/InstallOrbitDockerFirstTest.php apps/gateway/tests/Feature/Services/Gateway/GatewayApiRuntimeInstallerTest.php
```

Expected: PASS.

- [ ] **Step 8: Format and commit**

```bash
bin/orbit-gateway-vendor-bin pint --dirty --format agent
git add bin/install-orbit apps/gateway/app/Services/Gateway/GatewayApiRuntimeInstaller.php apps/gateway/app/Services/Gateway/GatewayCaddyRouteRenderer.php apps/gateway/tests/Feature/Runtime/InstallOrbitDockerFirstTest.php apps/gateway/tests/Feature/Services/Gateway/GatewayApiRuntimeInstallerTest.php
git commit -m "Install orbit-gateway as Swarm services"
```

---

### Task 5: Move Scheduler Doctor/Fix To Swarm Service State

**Files:**
- Modify: `apps/gateway/app/Services/Schedules/OrbitSchedulerProgramRenderer.php`
- Modify: `apps/gateway/app/Services/Schedules/SchedulesProbe.php`
- Modify: `apps/gateway/app/Services/Schedules/SchedulesFixer.php`
- Test: `apps/gateway/tests/Unit/Services/Schedules/SchedulesProbeTest.php`
- Test: `apps/gateway/tests/Unit/Services/Schedules/SchedulesFixerTest.php`

- [ ] **Step 1: Update tests**

Expected probe command shape:

```bash
docker service inspect orbit_orbit-scheduler
docker service ps orbit_orbit-scheduler --format '{{.CurrentState}} {{.Error}}'
```

Expected fixer command shape:

```bash
docker service update --force orbit_orbit-scheduler
```

No test should expect:

```bash
docker exec orbit-runtime
docker exec --detach orbit-runtime
```

- [ ] **Step 2: Replace renderer install script**

`OrbitSchedulerProgramRenderer::installScript()` should no longer restart a container and detach a process inside it. It should verify or redeploy the Swarm service:

```bash
set -e
sudo docker service inspect orbit_orbit-scheduler >/dev/null
sudo docker service update --force orbit_orbit-scheduler >/dev/null
```

The scheduler is now a gateway-local singleton Swarm service, not a per-node process program. Remove the `Node $node` argument from scheduler renderer/probe/fixer methods when it only exists to scope the old per-node runtime container. If a public caller still passes a node for command compatibility, keep that translation at the caller boundary and make the Swarm scheduler service implementation node-agnostic.

- [ ] **Step 3: Update probe parser**

Map Swarm state:

```text
Running -> running
missing service -> missing
service exists with no running task -> stopped
```

Heartbeat freshness remains gateway DB state.

- [ ] **Step 4: Run focused tests**

```bash
bin/orbit-gateway-pest --compact apps/gateway/tests/Unit/Services/Schedules/SchedulesProbeTest.php apps/gateway/tests/Unit/Services/Schedules/SchedulesFixerTest.php
```

Expected: PASS.

- [ ] **Step 5: Format and commit**

```bash
bin/orbit-gateway-vendor-bin pint --dirty --format agent
git add apps/gateway/app/Services/Schedules/OrbitSchedulerProgramRenderer.php apps/gateway/app/Services/Schedules/SchedulesProbe.php apps/gateway/app/Services/Schedules/SchedulesFixer.php apps/gateway/tests/Unit/Services/Schedules
git commit -m "Manage scheduler as Swarm service"
```

---

### Task 6: Add Durable Operation Event Journal

**Files:**
- Create: `apps/gateway/database/migrations/2026_06_01_000000_create_operation_events_table.php`
- Create: `apps/gateway/app/Models/OperationEvent.php`
- Create: `apps/gateway/app/Services/Operations/OperationEventRecorder.php`
- Create: `apps/gateway/app/Services/Operations/OperationEventStreamer.php`
- Modify: `apps/gateway/app/Support/Streaming/ProgressEventStreamEmitter.php`
- Test: `apps/gateway/tests/Feature/Services/Operations/OperationEventRecorderTest.php`
- Test: `apps/gateway/tests/Feature/Services/Operations/OperationEventStreamerTest.php`

- [ ] **Step 1: Write recorder tests**

Test sequence allocation:

```php
$run = app(OperationRunRecorder::class)->queued(
    operationId: (string) Str::uuid(),
    lane: 'gateway',
    operationType: 'update:all',
);
$recorder = app(OperationEventRecorder::class);

$first = $recorder->tree($run, 'Updating Orbit', [['key' => 'gateway', 'label' => 'Update gateway']]);
$second = $recorder->step($run, 'gateway', 'running', 'Updating gateway');

expect($first->sequence)->toBe(1)
    ->and($second->sequence)->toBe(2)
    ->and($second->event)->toBe('step');
```

Test redaction rejects secrets using the real policy rules. The current policy rejects forbidden key names and PEM blocks in string values; it does not reject arbitrary free-text message values such as `password=secret`.

```php
expect(fn () => $recorder->complete($run, 0, ['password' => 'secret']))
    ->toThrow(OperationPayloadRejected::class);

expect(fn () => $recorder->step($run, 'gateway', 'fail', "-----BEGIN PRIVATE KEY-----\nsecret\n-----END PRIVATE KEY-----"))
    ->toThrow(OperationPayloadRejected::class);
```

Do not add free-text secret-pattern scanning in this task unless a separate product decision accepts the false-positive risk.

- [ ] **Step 2: Add migration**

Schema:

```php
Schema::create('operation_events', function (Blueprint $table): void {
    $table->id();
    $table->uuid('operation_run_id')->index();
    $table->uuid('operation_id')->index();
    $table->unsignedInteger('sequence');
    $table->string('event');
    $table->json('payload');
    $table->timestamps();

    $table->foreign('operation_run_id')->references('id')->on('operation_runs')->cascadeOnDelete();
    $table->unique(['operation_id', 'sequence']);
});
```

- [ ] **Step 3: Add model**

```php
class OperationEvent extends Model
{
    protected $fillable = ['operation_run_id', 'operation_id', 'sequence', 'event', 'payload'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'sequence' => 'integer'];
    }
}
```

- [ ] **Step 4: Add recorder**

Methods:

```php
public function tree(OperationRun $run, string $title, array $steps): OperationEvent
public function step(OperationRun $run, string $key, string $status, ?string $message = null): OperationEvent
public function complete(OperationRun $run, int $exitCode, array $data = []): OperationEvent
public function error(OperationRun $run, string $message, int $exitCode = 1, array $data = []): OperationEvent
```

Inject `ResultBoundaryRedactionPolicy` into `OperationEventRecorder` and call the instance method before writing:

```php
$this->redaction->assertSafe($payload, 'progress');
```

- [ ] **Step 5: Add streamer**

`OperationEventStreamer::eventsAfter(string $operationId, int $sequence): iterable` returns ordered events with `sequence > $sequence`.

`OperationEventStreamer::hasTerminalEvent(string $operationId): bool` returns true after `complete` or `error`.

- [ ] **Step 6: Add SSE id support**

Extend `ProgressEventStreamEmitter` with:

```php
public function event(string $event, array $payload, ?int $id = null): void
```

When `$id !== null`, emit:

```text
id: <sequence>
event: <event>
data: <json>
```

`ProgressEventStreamEmitter::flush()` must flush under FrankenPHP request handling. A measured `dunglas/frankenphp:1-php8.5-bookworm` HTTP request reports `PHP_SAPI === 'frankenphp'`, so tests must cover that SAPI explicitly:

```php
$emitter = new ProgressEventStreamEmitter('frankenphp');

$emitter->event('step', ['key' => 'gateway'], id: 2);

expect($output)->toContain("id: 2\n")
    ->toContain("event: step\n");
```

The implementation may either add `frankenphp` to the flush allowlist or flush for every SAPI except explicitly non-streaming test SAPIs. The test must fail if `frankenphp` returns before calling the flush path.

- [ ] **Step 7: Configure SQLite concurrency for operation events**

The live gateway API and the one-shot runner both read/write `$ORBIT_CONFIG_ROOT/gateway.sqlite`. Set the gateway SQLite connection defaults to WAL and a bounded busy timeout:

```php
'busy_timeout' => env('DB_BUSY_TIMEOUT', 5000),
'journal_mode' => env('DB_JOURNAL_MODE', 'WAL'),
'synchronous' => env('DB_SYNCHRONOUS', 'NORMAL'),
```

Event recorder writes should remain single-statement or tightly scoped transactions so SSE reads do not hold locks longer than needed.

- [ ] **Step 8: Run tests**

```bash
bin/orbit-gateway-pest --compact apps/gateway/tests/Feature/Services/Operations/OperationEventRecorderTest.php apps/gateway/tests/Feature/Services/Operations/OperationEventStreamerTest.php
```

Expected: PASS.

- [ ] **Step 9: Format and commit**

```bash
bin/orbit-gateway-vendor-bin pint --dirty --format agent
git add apps/gateway/database/migrations/2026_06_01_000000_create_operation_events_table.php apps/gateway/app/Models/OperationEvent.php apps/gateway/app/Services/Operations/OperationEventRecorder.php apps/gateway/app/Services/Operations/OperationEventStreamer.php apps/gateway/app/Support/Streaming/ProgressEventStreamEmitter.php apps/gateway/config/database.php apps/gateway/tests/Feature/Services/Operations
git commit -m "Persist operation progress events"
```

---

### Task 7: Add Operation Event Replay API

**Files:**
- Create: `apps/gateway/app/Http/Controllers/Api/OperationEventStreamController.php`
- Modify: `apps/gateway/routes/api.php`
- Test: `apps/gateway/tests/Feature/Http/Api/OperationEventStreamControllerTest.php`

- [ ] **Step 1: Write API tests**

Assertions:

```php
$response = $this
    ->withHeader('Accept', 'text/event-stream')
    ->get('/api/operations/'.$operationId.'/events?since=1');

expect($response->streamedContent())
    ->toContain("id: 2\n")
    ->toContain("event: step\n")
    ->toContain('Updating gateway');
```

Test `Last-Event-ID`:

```php
$response = $this
    ->withHeader('Accept', 'text/event-stream')
    ->withHeader('Last-Event-ID', '2')
    ->get('/api/operations/'.$operationId.'/events');

expect($response->streamedContent())->toContain("id: 3\n");
```

Authorization and middleware tests:

```php
$this->get('/api/operations/'.$operationId.'/events')->assertForbidden();

expect(routeMiddlewareFor('/api/operations/{operation}/events'))
    ->toContain(WireGuardIdentity::class)
    ->toContain(RequireGrantPermission::class)
    ->not->toContain(LogActivity::class);
```

- [ ] **Step 2: Add route**

Register the route inside the authenticated gateway API surface with `WireGuardIdentity` and `RequireGrantPermission`. Do not put the long-lived SSE route behind `LogActivity`; the mutation is logged by the update start endpoint, while this endpoint is a read/follow stream and can stay open for minutes.

```php
Route::get('/operations/{operation}/events', OperationEventStreamController::class);
```

- [ ] **Step 3: Add controller**

The controller must declare the gateway-wide operation-follow permission:

```php
#[RequiresPermission('*', servingNode: ServingNode::Gateway)]
final class OperationEventStreamController
{
    // ...
}
```

Behavior:

```php
$since = max(
    (int) $request->query('since', 0),
    (int) $request->headers->get('Last-Event-ID', 0),
);
```

Loop:

```php
while (true) {
    foreach ($streamer->eventsAfter($operationId, $since) as $event) {
        $emitter->event($event->event, $event->payload, $event->sequence);
        $since = $event->sequence;
    }

    if ($streamer->hasTerminalEvent($operationId)) {
        return;
    }

    $emitter->heartbeat();
    usleep(500_000);
}
```

Use a request/test override such as `?once=1` to avoid infinite feature tests. In `once` mode, replay current events and return immediately.

- [ ] **Step 4: Run tests**

```bash
bin/orbit-gateway-pest --compact apps/gateway/tests/Feature/Http/Api/OperationEventStreamControllerTest.php
```

Expected: PASS.

- [ ] **Step 5: Format and commit**

```bash
bin/orbit-gateway-vendor-bin pint --dirty --format agent
git add apps/gateway/app/Http/Controllers/Api/OperationEventStreamController.php apps/gateway/routes/api.php apps/gateway/tests/Feature/Http/Api/OperationEventStreamControllerTest.php
git commit -m "Stream durable operation events"
```

---

### Task 8: Add Expiring Update Leases

**Files:**
- Create: `apps/gateway/database/migrations/2026_06_01_000100_create_operation_update_leases_table.php`
- Create: `apps/gateway/app/Models/OperationUpdateLease.php`
- Create: `apps/gateway/app/Services/Updates/UpdateLeaseConflict.php`
- Create: `apps/gateway/app/Services/Updates/UpdateLeaseManager.php`
- Create: `apps/gateway/app/Services/Updates/UpdateLeaseHeartbeat.php`
- Test: `apps/gateway/tests/Feature/Services/Updates/UpdateLeaseManagerTest.php`

- [ ] **Step 1: Write lease manager tests**

Create tests that prove a fresh lease blocks the same resource, an expired lease is reclaimable, and releasing a lease clears the active key.

```php
<?php

declare(strict_types=1);

use App\Services\Operations\OperationRunRecorder;
use App\Services\Updates\UpdateLeaseConflict;
use App\Services\Updates\UpdateLeaseManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

afterEach(function (): void {
    Carbon::setTestNow();
});

it('blocks a second fresh lease for the same node', function (): void {
    Carbon::setTestNow('2026-06-01 12:00:00');

    $runs = app(OperationRunRecorder::class);
    $firstRun = $runs->queued((string) Str::uuid(), 'gateway', operationType: 'update:all');
    $secondRun = $runs->queued((string) Str::uuid(), 'gateway', operationType: 'update:all');
    $manager = app(UpdateLeaseManager::class);

    $manager->acquire(
        resourceType: 'node',
        resourceId: 'node-1',
        run: $firstRun,
        ownerToken: 'owner-first',
        ttlSeconds: 1800,
    );

    expect(fn () => $manager->acquire(
        resourceType: 'node',
        resourceId: 'node-1',
        run: $secondRun,
        ownerToken: 'owner-second',
        ttlSeconds: 1800,
    ))->toThrow(UpdateLeaseConflict::class);
});

it('reclaims an expired node lease', function (): void {
    Carbon::setTestNow('2026-06-01 12:00:00');

    $runs = app(OperationRunRecorder::class);
    $firstRun = $runs->queued((string) Str::uuid(), 'gateway', operationType: 'update:all');
    $secondOperationId = (string) Str::uuid();
    $secondRun = $runs->queued($secondOperationId, 'gateway', operationType: 'update:all');
    $manager = app(UpdateLeaseManager::class);

    $expired = $manager->acquire('node', 'node-1', $firstRun, 'owner-first', 60);

    Carbon::setTestNow('2026-06-01 12:02:00');

    $fresh = $manager->acquire('node', 'node-1', $secondRun, 'owner-second', 1800);

    expect($fresh->operation_id)->toBe($secondOperationId)
        ->and($expired->refresh()->release_reason)->toBe('expired')
        ->and($expired->refresh()->active_resource_key)->toBeNull();
});

it('releases a node lease so the node can be updated again', function (): void {
    Carbon::setTestNow('2026-06-01 12:00:00');

    $runs = app(OperationRunRecorder::class);
    $firstRun = $runs->queued((string) Str::uuid(), 'gateway', operationType: 'update:all');
    $secondRun = $runs->queued((string) Str::uuid(), 'gateway', operationType: 'update:all');
    $manager = app(UpdateLeaseManager::class);

    $lease = $manager->acquire('node', 'node-1', $firstRun, 'owner-first', 1800);
    $manager->release($lease, 'completed');

    $fresh = $manager->acquire('node', 'node-1', $secondRun, 'owner-second', 1800);

    expect($lease->refresh()->active_resource_key)->toBeNull()
        ->and($fresh->active_resource_key)->toBe('node:node-1');
});
```

Add a race-mapping test. The exact concurrency harness can use two database connections when the test environment supports it, or a fake repository/manager seam that inserts a row after the select and before create. The assertion is that the losing acquire returns a typed conflict, not a raw `QueryException`:

```php
expect(fn () => $manager->acquire(
    resourceType: 'node',
    resourceId: 'node-1',
    run: $secondRun,
    ownerToken: 'owner-second',
    ttlSeconds: 1800,
))->toThrow(UpdateLeaseConflict::class);
```

- [ ] **Step 2: Add migration**

Create an active-key unique lease table. Released leases keep history by setting `active_resource_key` to `null`; SQLite permits multiple `null` values in a unique index.

```php
Schema::create('operation_update_leases', function (Blueprint $table): void {
    $table->id();
    $table->string('resource_type');
    $table->string('resource_id');
    $table->string('active_resource_key')->nullable()->unique();
    $table->uuid('operation_run_id')->nullable()->index();
    $table->uuid('operation_id')->index();
    $table->string('owner_token', 64)->index();
    $table->timestamp('acquired_at');
    $table->timestamp('heartbeat_at')->nullable();
    $table->timestamp('expires_at')->index();
    $table->timestamp('released_at')->nullable()->index();
    $table->string('release_reason')->nullable();
    $table->timestamps();

    $table->foreign('operation_run_id')->references('id')->on('operation_runs')->nullOnDelete();
    $table->index(['resource_type', 'resource_id']);
});
```

- [ ] **Step 3: Add model**

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OperationUpdateLease extends Model
{
    protected $fillable = [
        'resource_type',
        'resource_id',
        'active_resource_key',
        'operation_run_id',
        'operation_id',
        'owner_token',
        'acquired_at',
        'heartbeat_at',
        'expires_at',
        'released_at',
        'release_reason',
    ];

    protected function casts(): array
    {
        return [
            'acquired_at' => 'immutable_datetime',
            'heartbeat_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'released_at' => 'immutable_datetime',
        ];
    }
}
```

- [ ] **Step 4: Add conflict exception**

```php
<?php

declare(strict_types=1);

namespace App\Services\Updates;

use App\Models\OperationUpdateLease;
use RuntimeException;

class UpdateLeaseConflict extends RuntimeException
{
    public function __construct(public readonly OperationUpdateLease $lease)
    {
        parent::__construct(sprintf(
            '%s %s is already being updated by operation %s until %s.',
            $lease->resource_type,
            $lease->resource_id,
            $lease->operation_id,
            $lease->expires_at?->toIso8601String(),
        ));
    }
}
```

- [ ] **Step 5: Add lease manager**

```php
<?php

declare(strict_types=1);

namespace App\Services\Updates;

use App\Models\OperationRun;
use App\Models\OperationUpdateLease;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UpdateLeaseManager
{
    public function acquire(
        string $resourceType,
        string $resourceId,
        OperationRun $run,
        ?string $ownerToken = null,
        int $ttlSeconds = 1800,
    ): OperationUpdateLease {
        $activeKey = $this->activeKey($resourceType, $resourceId);

        try {
            return DB::transaction(function () use ($resourceType, $resourceId, $run, $ownerToken, $ttlSeconds, $activeKey): OperationUpdateLease {
                $now = now();

                $existing = OperationUpdateLease::query()
                    ->where('active_resource_key', $activeKey)
                    ->first();

                if ($existing instanceof OperationUpdateLease && $existing->expires_at->isFuture()) {
                    throw new UpdateLeaseConflict($existing);
                }

                if ($existing instanceof OperationUpdateLease) {
                    $existing->forceFill([
                        'active_resource_key' => null,
                        'released_at' => $now,
                        'release_reason' => 'expired',
                    ])->save();
                }

                return OperationUpdateLease::query()->create([
                    'resource_type' => $resourceType,
                    'resource_id' => $resourceId,
                    'active_resource_key' => $activeKey,
                    'operation_run_id' => $run->id,
                    'operation_id' => $run->operation_id,
                    'owner_token' => $ownerToken ?? Str::random(40),
                    'acquired_at' => $now,
                    'heartbeat_at' => $now,
                    'expires_at' => $now->copy()->addSeconds($ttlSeconds),
                ]);
            });
        } catch (QueryException $exception) {
            if (! $this->isUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            $existing = OperationUpdateLease::query()
                ->where('active_resource_key', $activeKey)
                ->first();

            if ($existing instanceof OperationUpdateLease) {
                throw new UpdateLeaseConflict($existing);
            }

            throw $exception;
        }
    }

    public function heartbeat(OperationUpdateLease $lease, int $ttlSeconds = 1800): void
    {
        $lease->refresh();

        if ($lease->released_at !== null) {
            throw new UpdateLeaseConflict($lease);
        }

        $lease->forceFill([
            'heartbeat_at' => now(),
            'expires_at' => now()->addSeconds($ttlSeconds),
        ])->save();
    }

    public function release(OperationUpdateLease $lease, string $reason = 'completed'): void
    {
        $lease->refresh();

        if ($lease->released_at !== null) {
            return;
        }

        $lease->forceFill([
            'active_resource_key' => null,
            'released_at' => now(),
            'release_reason' => $reason,
        ])->save();
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $driverCode = (string) ($exception->errorInfo[1] ?? '');
        $message = strtolower($exception->getMessage());

        return $sqlState === '23000'
            || $driverCode === '1062'
            || str_contains($message, 'unique constraint')
            || str_contains($message, 'duplicate entry');
    }

    public function activeKey(string $resourceType, string $resourceId): string
    {
        return $resourceType.':'.$resourceId;
    }
}
```

- [ ] **Step 6: Add heartbeat wrapper for bounded long-running stages**

`UpdateLeaseHeartbeat` refreshes leases before and after bounded Docker and SSH stages without making the lease permanent. Every callback passed to this helper must run commands with a timeout lower than `$ttlSeconds`, so a hung command fails before its lease can expire and be reclaimed by another update.

```php
<?php

declare(strict_types=1);

namespace App\Services\Updates;

use App\Models\OperationUpdateLease;
use Closure;
use Throwable;

final readonly class UpdateLeaseHeartbeat
{
    public function __construct(private UpdateLeaseManager $leases) {}

    /**
     * @template T
     *
     * @param  array<int, OperationUpdateLease>  $leases
     * @param  Closure(): T  $callback
     * @return T
     */
    public function run(array $leases, Closure $callback, int $ttlSeconds = 1800): mixed
    {
        try {
            foreach ($leases as $lease) {
                $this->leases->heartbeat($lease, $ttlSeconds);
            }

            $result = $callback();

            foreach ($leases as $lease) {
                $this->leases->heartbeat($lease, $ttlSeconds);
            }

            return $result;
        } catch (Throwable $throwable) {
            foreach ($leases as $lease) {
                $this->leases->heartbeat($lease, $ttlSeconds);
            }

            throw $throwable;
        }
    }
}
```

- [ ] **Step 7: Run focused tests**

```bash
bin/orbit-gateway-pest --compact apps/gateway/tests/Feature/Services/Updates/UpdateLeaseManagerTest.php
```

Expected: PASS.

- [ ] **Step 8: Format and commit**

```bash
bin/orbit-gateway-vendor-bin pint --dirty --format agent
git add apps/gateway/database/migrations/2026_06_01_000100_create_operation_update_leases_table.php apps/gateway/app/Models/OperationUpdateLease.php apps/gateway/app/Services/Updates/UpdateLeaseConflict.php apps/gateway/app/Services/Updates/UpdateLeaseManager.php apps/gateway/app/Services/Updates/UpdateLeaseHeartbeat.php apps/gateway/tests/Feature/Services/Updates/UpdateLeaseManagerTest.php
git commit -m "Add expiring update leases"
```

---

### Task 9: Start `update:all` As Durable Operation

**Files:**
- Create: `apps/gateway/app/Http/Controllers/Api/UpdateAllStartController.php`
- Create: `apps/gateway/database/migrations/2026_06_01_000200_create_operation_update_plans_table.php`
- Create: `apps/gateway/app/Models/OperationUpdatePlan.php`
- Create: `apps/gateway/app/Services/Updates/UpdatePlanSnapshot.php`
- Create: `apps/gateway/app/Services/Updates/UpdatePlanStore.php`
- Create: `apps/gateway/app/Services/Updates/UpdatePlanBuilder.php`
- Create: `apps/gateway/app/Services/Updates/UpdateRunnerLauncher.php`
- Modify: `apps/gateway/app/Http/Controllers/Api/UpdateAllController.php`
- Modify: `apps/gateway/routes/api.php`
- Test: `apps/gateway/tests/Feature/Http/Api/UpdateAllStartControllerTest.php`

- [ ] **Step 1: Write API start tests**

Assert:

```php
$response = $this->postJson('/api/update/all/start', ['target_version' => 'v1.2.3']);

$response->assertOk()
    ->assertJsonPath('success.data.operation.type', 'update:all')
    ->assertJsonPath('success.data.operation.status', 'queued')
    ->assertJsonPath('success.data.events_url', fn (string $url): bool => str_contains($url, '/api/operations/'));
```

Assert a queued `OperationRun`:

```php
expect(OperationRun::query()->where('operation_type', 'update:all')->exists())->toBeTrue();
```

Assert an immutable update plan is created before the runner launches:

```php
$run = OperationRun::query()->where('operation_type', 'update:all')->firstOrFail();
$plan = OperationUpdatePlan::query()->whereBelongsTo($run)->firstOrFail();

expect($plan->target_version)->toBe('v1.2.3')
    ->and($plan->gateway_image)->not->toBeEmpty()
    ->and($plan->manifest_snapshot)->toHaveKey('version')
    ->and($plan->cli_artifacts)->not->toBeEmpty();
```

Assert the route is inside the authenticated gateway API group and requires fleet update authority:

```php
$this->postJson('/api/update/all/start', ['target_version' => 'v1.2.3'])
    ->assertForbidden()
    ->assertJsonPath('error.code', 'authorization_failed');
```

Production tests must reject raw request image overrides:

```php
config()->set('app.env', 'production');
config()->set('orbit.updates.allow_request_image_override', false);

$this->postJson('/api/update/all/start', [
    'gateway_image' => 'attacker/orbit-gateway:latest',
])->assertUnprocessable()
    ->assertJsonPath('error.code', 'update.image_override_not_allowed');
```

Payload `gateway_image` overrides are local/testing-only. Production overrides must come from the release manifest or an explicit environment/config allowlist such as `ORBIT_GATEWAY_IMAGE`, and production image references must be `ghcr.io/hardimpactdev/orbit-gateway` digest references, not arbitrary request strings.

- [ ] **Step 2: Add immutable update plan storage**

Create `operation_update_plans` as the immutable snapshot the runner consumes:

```php
Schema::create('operation_update_plans', function (Blueprint $table): void {
    $table->id();
    $table->foreignUuid('operation_run_id')->unique()->constrained('operation_runs')->cascadeOnDelete();
    $table->string('target_version')->nullable();
    $table->string('gateway_image');
    $table->string('gateway_image_digest');
    $table->string('manifest_version');
    $table->string('manifest_source');
    $table->json('manifest_snapshot');
    $table->json('cli_artifacts');
    $table->timestamps();
});
```

`UpdatePlanStore` creates exactly one plan for an `OperationRun`. It must fail if a plan already exists for the run, and no implementation path may update a plan row after creation.

`OperationUpdatePlan` belongs to `OperationRun` and casts `manifest_snapshot` and `cli_artifacts` to arrays. It exposes no mutating service method; updates are not part of the public contract for this model.

`UpdatePlanSnapshot` fields:

```php
final readonly class UpdatePlanSnapshot
{
    /**
     * @param  array<string, array{url: string, sha256: string}>  $cliArtifacts
     * @param  array<string, mixed>  $manifestSnapshot
     */
    public function __construct(
        public ?string $targetVersion,
        public GatewayImageReference $gatewayImage,
        public string $gatewayImageDigest,
        public string $manifestVersion,
        public string $manifestSource,
        public array $cliArtifacts,
        public array $manifestSnapshot,
    ) {}
}
```

`UpdatePlanBuilder` is the temporary Task 9 source for `UpdatePlanSnapshot`. It reads `target_version`, local/testing `gateway_image` overrides, and `orbit.updates.allow_request_image_override`. In production it must reject arbitrary request image overrides and may only use an explicit environment/config allowlist such as `ORBIT_GATEWAY_IMAGE` until Task 12 replaces this temporary builder with release-manifest-backed resolution.

- [ ] **Step 3: Add route**

```php
Route::post('/update/all/start', UpdateAllStartController::class);
```

The route must be registered inside the existing `WireGuardIdentity`, `RequireGrantPermission`, and `LogActivity` API group and `UpdateAllStartController` must carry `#[RequiresPermission('*', servingNode: ServingNode::Gateway)]`, matching the existing `UpdateAllController` security boundary.

Keep `/api/update/all` as a compatibility stream that internally calls start then follows events until removed.

- [ ] **Step 4: Add start controller**

Controller sequence:

```php
$operationRun = $operationRuns->queued(
    operationId: (string) Str::uuid(),
    lane: 'gateway',
    internalCommand: 'update:all',
    operationType: 'update:all',
    callerNodeId: $caller?->id,
);

$events->tree($operationRun, 'Updating Orbit Fleet', [
    ['key' => 'gateway', 'label' => 'Update gateway services'],
    ['key' => 'scheduler', 'label' => 'Update scheduler service'],
    ['key' => 'workload-nodes', 'label' => 'Update workload nodes'],
    ['key' => 'verify', 'label' => 'Verify fleet update'],
]);

$plan = $updatePlanBuilder->fromRequest($operationRun, $request);
$updatePlanStore->create($operationRun, $plan);

$launcher->launch($operationRun, $plan);
```

Task 9 deliberately does not use `ReleaseManifestResolver`; that resolver is introduced in Task 12. Until Task 12 lands, tests may pass an explicit `gateway_image` only in local/testing, and production installs use an environment/config-pinned `ORBIT_GATEWAY_IMAGE` if present. Task 12 replaces this temporary plan builder with a manifest-backed builder that persists the GitHub Release manifest snapshot and digest-pinned gateway image before launch.

Return:

```json
{
  "success": {
    "data": {
      "operation": {
        "id": "<operation_run_uuid>",
        "operation_id": "<logical_uuid>",
        "type": "update:all",
        "status": "queued"
      },
      "events_url": "/api/operations/<operation_id>/events"
    },
    "meta": {}
  }
}
```

- [ ] **Step 5: Launch runner as a detached one-shot container**

Add `UpdateRunnerLauncher`:

```php
final readonly class UpdateRunnerLauncher
{
    public function launch(OperationRun $run, UpdatePlanSnapshot $plan): void
    {
        Process::timeout(30)->run([
            'docker', 'run', '-d', '--rm',
            '--name', 'orbit-update-runner-'.$run->operation_id,
            '--env', 'ORBIT_CONFIG_ROOT=/home/orbit/.config/orbit',
            '--mount', 'type=bind,source='.config('orbit.paths.config_root').',target=/home/orbit/.config/orbit',
            '--mount', 'type=bind,source=/var/run/docker.sock,target=/var/run/docker.sock',
            $plan->gatewayImage->image,
            'php', 'artisan', 'orbit:internal:update-runner',
            $run->operation_id,
        ])->throw();
    }
}
```

The launcher must use the same digest-pinned gateway image stored in `operation_update_plans` so the runner code and `GatewayServiceUpdater` operate from the same immutable update plan. It does not pass a mutable `--target-image`; the runner resolves the `OperationRun` and reads the plan row.

- [ ] **Step 6: Run tests**

```bash
bin/orbit-gateway-pest --compact apps/gateway/tests/Feature/Http/Api/UpdateAllStartControllerTest.php
```

Expected: PASS.

- [ ] **Step 7: Format and commit**

```bash
bin/orbit-gateway-vendor-bin pint --dirty --format agent
git add apps/gateway/app/Http/Controllers/Api/UpdateAllStartController.php apps/gateway/database/migrations/2026_06_01_000200_create_operation_update_plans_table.php apps/gateway/app/Models/OperationUpdatePlan.php apps/gateway/app/Services/Updates/UpdatePlanSnapshot.php apps/gateway/app/Services/Updates/UpdatePlanStore.php apps/gateway/app/Services/Updates/UpdateRunnerLauncher.php apps/gateway/app/Http/Controllers/Api/UpdateAllController.php apps/gateway/routes/api.php apps/gateway/tests/Feature/Http/Api/UpdateAllStartControllerTest.php
git commit -m "Start update all as durable operation"
```

---

### Task 10: Implement One-Shot Fleet Update Runner

**Files:**
- Create: `apps/gateway/app/Console/Commands/Internal/UpdateRunnerCommand.php`
- Create: `apps/gateway/app/Services/Updates/FleetUpdateRunner.php`
- Create: `apps/gateway/app/Services/Updates/GatewayServiceUpdater.php`
- Create: `apps/gateway/app/Services/Updates/WorkloadNodeUpdater.php`
- Create: `apps/gateway/app/Services/Updates/FleetUpdateVerifier.php`
- Modify: `apps/gateway/app/Services/OrbitUpdater.php`
- Modify: `apps/gateway/app/Services/Updates/UpdateLeaseManager.php`
- Modify: `apps/gateway/app/Services/Updates/UpdateLeaseHeartbeat.php`
- Test: `apps/gateway/tests/Feature/Commands/Internal/UpdateRunnerCommandTest.php`
- Test: `apps/gateway/tests/Unit/Services/Updates/FleetUpdateRunnerTest.php`

- [ ] **Step 1: Write runner tests**

Fake collaborators and assert order:

```php
expect($events)->sequence(
    fn ($event) => $event->payload['key'] === 'gateway' && $event->payload['status'] === 'running',
    fn ($event) => $event->payload['key'] === 'gateway' && $event->payload['status'] === 'done',
    fn ($event) => $event->payload['key'] === 'scheduler' && $event->payload['status'] === 'running',
    fn ($event) => $event->payload['key'] === 'workload-nodes' && $event->payload['status'] === 'running',
    fn ($event) => $event->event === 'complete',
);
```

Seed an `OperationUpdatePlan` before invoking the runner. The runner must read that row by `operation_run_id`; tests must prove the exact gateway image digest in the plan reaches `GatewayServiceUpdater` and the exact manifest snapshot reaches `WorkloadNodeUpdater`:

```php
$plan = UpdatePlanSnapshot::fake(
    targetVersion: 'v1.2.3',
    gatewayImage: 'ghcr.io/hardimpactdev/orbit-gateway:v1.2.3@sha256:abc123',
    gatewayImageDigest: 'sha256:abc123',
    manifestVersion: 'v1.2.3',
    manifestSource: 'github-release:hardimpactdev/orbit:v1.2.3',
    cliArtifacts: ['linux-x64' => ['url' => 'https://github.com/hardimpactdev/orbit/releases/download/v1.2.3/orbit-linux-x64', 'sha256' => 'cli-sha']],
    manifestSnapshot: ['version' => 'v1.2.3', 'images' => ['orbit-gateway' => 'ghcr.io/hardimpactdev/orbit-gateway:v1.2.3@sha256:abc123']],
);

$planStore->create($run, $plan);
$runner->run($run);

expect($gatewayUpdater->receivedImage()->image)->toBe('ghcr.io/hardimpactdev/orbit-gateway:v1.2.3@sha256:abc123')
    ->and($workloadUpdater->receivedManifest()['version'])->toBe('v1.2.3');
```

Assert failed gateway update stops before workload fan-out:

```php
expect($workloadUpdater->wasCalled())->toBeFalse();
```

Assert a fresh node lease blocks that node from being updated by this operation:

```php
$leases->acquire('node', 'node-1', $otherRun, 'other-owner-token', 1800);

$runner->run($run);

expect($remoteShell->commandsFor('node-1'))->toBeEmpty()
    ->and($events->terminalPayload()['code'])->toBe('update.node_locked')
    ->and($events->terminalPayload()['resource'])->toBe('node:node-1');
```

Assert a conflict during multi-lease acquisition releases leases already acquired by this runner:

```php
$leases->acquire('scheduler', 'orbit-scheduler', $otherRun, 'other-owner-token', 1800);

$runner->run($run);

expect(OperationUpdateLease::query()
    ->where('operation_id', $run->operation_id)
    ->whereNotNull('active_resource_key')
    ->exists())->toBeFalse();
```

- [ ] **Step 2: Add hidden command**

Signature:

```php
#[Signature('orbit:internal:update-runner {operation_id}')]
#[Description('Run a durable Orbit fleet update operation')]
```

The command resolves `OperationRun` by `operation_id`, loads the immutable `OperationUpdatePlan`, marks the run running, invokes `FleetUpdateRunner`, then exits `0`/`1`. It must fail before mutation if the plan row is missing.

- [ ] **Step 3: Add gateway updater**

`FleetUpdateRunner` must acquire the `fleet:update-all` lease before any mutation and keep it active until gateway, workload-node, and verification phases have all finished or failed. Gateway and scheduler leases are scoped only to the gateway service phase. Node leases are scoped per node. Release every lease in a `finally` block so successful and failed attempts both clear active leases.

```php
$ownerToken = (string) Str::uuid();
$fleetLease = $leaseManager->acquire('fleet', 'update-all', $run, $ownerToken, 1800);
$releaseReason = 'runner_failed';

try {
    $heartbeat->run([$fleetLease], function () use ($run, $ownerToken, $plan, $fleetLease, $leaseManager, $heartbeat, $gatewayUpdater, $workloadUpdater, $fleetVerifier): void {
        $gatewayLease = null;
        $schedulerLease = null;

        try {
            $gatewayLease = $leaseManager->acquire('gateway', 'orbit-gateway', $run, $ownerToken, 1800);
            $schedulerLease = $leaseManager->acquire('scheduler', 'orbit-scheduler', $run, $ownerToken, 1800);

            $heartbeat->run(
                [$fleetLease, $gatewayLease, $schedulerLease],
                fn () => $gatewayUpdater->update($plan->gatewayImage),
                1800,
            );
        } finally {
            if ($schedulerLease !== null) {
                $leaseManager->release($schedulerLease, 'gateway_phase_finished');
            }

            if ($gatewayLease !== null) {
                $leaseManager->release($gatewayLease, 'gateway_phase_finished');
            }
        }

        $workloadUpdater->update($run, $plan);
        $fleetVerifier->verify($run, $plan);
    }, 1800);

    $releaseReason = 'runner_finished';
} catch (Throwable $throwable) {
    $releaseReason = 'runner_failed';

    throw $throwable;
} finally {
    $leaseManager->release($fleetLease, $releaseReason);
}
```

Tests must assert the fleet lease remains active while workload nodes are updating and is released only after workload verification completes or the runner fails. Gateway and scheduler leases must be released after the gateway phase, and node leases must still be acquired/released per node.

`GatewayServiceUpdater::update(GatewayImageReference $image): void` keeps the Swarm operations focused:

```php
$previousSchedulerImage = $swarm->serviceImage('orbit_orbit-scheduler');
$schedulerWasStopped = false;

try {
    $swarm->scaleService('orbit_orbit-scheduler', 0);
    $schedulerWasStopped = true;
    $this->runMigrations($image);
    $swarm->updateServiceImage('orbit_orbit-gateway', $image, 'start-first');
    $this->waitForGatewayHealth();
    $swarm->updateServiceImage('orbit_orbit-scheduler', $image, 'stop-first');
    $swarm->scaleService('orbit_orbit-scheduler', 1);
} catch (Throwable $throwable) {
    if ($schedulerWasStopped) {
        if ($previousSchedulerImage !== null) {
            $swarm->updateServiceImage('orbit_orbit-scheduler', GatewayImageReference::fromImage($previousSchedulerImage), 'stop-first');
        }

        $swarm->scaleService('orbit_orbit-scheduler', 1);
    }

    throw $throwable;
}
```

`runMigrations($image)` runs migrations in the current one-shot runner process because the runner is already executing from the target `orbit-gateway` image with `$ORBIT_CONFIG_ROOT` mounted. Do not spawn a sibling `docker run` for gateway-update migrations; use the current container's PHP process, for example `Artisan::call('migrate', ['--force' => true, '--no-interaction' => true])` or an equivalent in-process command wrapper. The initial install path in Task 4 may still use a one-shot container because no runner container exists yet.

The scheduler is explicitly scaled to zero before migrations and restored to one replica after the new gateway service is healthy. On migration failure or gateway-health failure, the updater must restore `orbit-scheduler` to one replica on the previous known-good image when that image can be inspected. If recovery cannot inspect the previous scheduler image or cannot scale the scheduler back to one, the runner must write an explicit terminal event with code `update.scheduler_recovery_failed` and include the manual recovery command in the payload.

Tests must cover:

```php
$swarm->scaleService('orbit_orbit-scheduler', 0);
$migrations->fail();

expect($swarm->serviceImageUpdates())->toContain('orbit_orbit-scheduler='.$previousSchedulerImage)
    ->and($swarm->scaleCalls())->toContain(['orbit_orbit-scheduler', 1]);
```

```php
$gatewayHealth->fail();

expect($swarm->serviceImageUpdates())->toContain('orbit_orbit-scheduler='.$previousSchedulerImage)
    ->and($swarm->scaleCalls())->toContain(['orbit_orbit-scheduler', 1]);
```

The final successful service state must be one scheduler replica on the target image.

- [ ] **Step 4: Add workload updater**

Replace old remote script stages with artifact-aware stages:

```text
download_cli
install_cli
verify_cli
pull_required_images
verify_internal_executor
```

Each active workload node gets updated after gateway API health succeeds. Concurrency remains four. Workload updates must consume the manifest snapshot from the persisted `OperationUpdatePlan`; they must not fetch a fresh release manifest during fan-out.

Before each node mutation, acquire a node lease. Release it in `finally` after that node is verified or failed:

```php
$lease = $leaseManager->acquire('node', (string) $node->id, $run, $ownerToken, 1800);
$releaseReason = 'node_update_finished';

try {
    $heartbeat->run([$fleetLease, $lease], fn () => $this->updateNode($node, $plan->manifestSnapshot), 1800);
} catch (Throwable $throwable) {
    $releaseReason = 'node_update_failed';

    throw $throwable;
} finally {
    $leaseManager->release($lease, $releaseReason);
}
```

If `UpdateLeaseConflict` is thrown, do not run remote commands for that node. Write a terminal failure with code `update.node_locked`.

Every remote command inside `updateNode()` must have a timeout below the 1800 second lease TTL. Use 1500 seconds as the maximum per-command timeout so a hung command fails before another runner can reclaim the node lease.

- [ ] **Step 5: Add event writing**

Every phase writes durable events:

```php
$events->step($run, 'gateway', 'running', 'Updating orbit-gateway service');
$events->step($run, 'gateway', 'done', 'orbit-gateway service updated');
$events->complete($run, 0, ['summary' => $summary]);
```

Failures write:

```php
$events->step($run, $key, 'fail', $message);
$events->error($run, $message, 1, ['code' => 'update.gateway_failed', 'summary' => $summary]);
```

Node lease conflicts write:

```php
$events->step($run, 'workload-nodes', 'fail', $conflict->getMessage());
$events->error($run, $conflict->getMessage(), 1, [
    'code' => 'update.node_locked',
    'operation_id' => $conflict->lease->operation_id,
    'resource' => $conflict->lease->active_resource_key,
    'expires_at' => $conflict->lease->expires_at?->toIso8601String(),
]);
```

- [ ] **Step 6: Run tests**

```bash
bin/orbit-gateway-pest --compact apps/gateway/tests/Feature/Commands/Internal/UpdateRunnerCommandTest.php apps/gateway/tests/Unit/Services/Updates/FleetUpdateRunnerTest.php
```

Expected: PASS.

- [ ] **Step 7: Format and commit**

```bash
bin/orbit-gateway-vendor-bin pint --dirty --format agent
git add apps/gateway/app/Console/Commands/Internal/UpdateRunnerCommand.php apps/gateway/app/Services/Updates apps/gateway/app/Services/OrbitUpdater.php apps/gateway/tests/Feature/Commands/Internal/UpdateRunnerCommandTest.php apps/gateway/tests/Unit/Services/Updates/FleetUpdateRunnerTest.php
git commit -m "Run fleet updates from target image"
```

---

### Task 11: Make CLI Follow Durable Operations With Reconnect

**Files:**
- Modify: `apps/cli/app/Commands/Operation/UpdateAllCommand.php`
- Modify: `apps/cli/app/Services/GatewayStreamClient.php`
- Create: `apps/cli/app/Services/Operations/GatewayOperationFollower.php`
- Test: `apps/cli/tests/Feature/Commands/Operation/UpdateAllCommandTest.php`
- Test: `apps/cli/tests/Unit/Services/Operations/GatewayOperationFollowerTest.php`

- [ ] **Step 1: Write CLI tests**

Flow:

```php
fakeGateway([
    ['method' => 'POST', 'path' => '/api/update/all/start', 'response' => [
        'success' => [
            'data' => [
                'operation' => ['operation_id' => 'op-1', 'status' => 'queued'],
                'events_url' => '/api/operations/op-1/events',
            ],
        ],
    ]],
    ['method' => 'GET', 'path' => '/api/operations/op-1/events', 'stream' => $eventStream],
]);
```

Assert rendered frames include tree, gateway step, workload step, and terminal summary.

The new follower must reuse the existing progress rendering contract in `StreamsGatewayProgress` and `Orbit\Core\Progress\ProgressEventType`. Tests should assert that the durable-event follower produces the same human and `--json` terminal rendering as the current streaming path for equivalent `tree`, `step`, `complete`, and `error` frames.

Reconnect test:

```php
$follower->follow('/api/operations/op-1/events', $onEvent);
expect($requests[1]['headers']['Last-Event-ID'])->toBe('2');
```

- [ ] **Step 2: Add operation follower**

Responsibilities:

```php
public function follow(string $eventsUrl, callable $onEvent): int
```

Behavior:

- opens GET SSE request
- tracks last numeric SSE id
- on connection loss before terminal event, reconnects with `Last-Event-ID`
- stops on `complete` or `error`
- caps reconnect attempts at a config value such as 120 attempts with 1 second sleep
- parses events into `ProgressEventType` before handing them to the existing `StreamsGatewayProgress` rendering path

- [ ] **Step 3: Update `update:all` command**

Command flow:

```php
$this->call('update'); // existing local self-update command, or invoke LocalUpdateWorkflow directly
$start = $gateway->post('/update/all/start', []);
return $follower->follow($start['success']['data']['events_url'], fn (...) => ...);
```

`apps/cli/app/Commands/Operation/UpdateCommand.php` already provides the local `update` command. Add a command test that proves `update:all` calls the local update workflow before the gateway start request, and fails early if the local update fails.

For `--json`, collect terminal payload and print the standard JSON envelope.

- [ ] **Step 4: Run CLI tests**

```bash
bin/orbit-cli-pest --compact apps/cli/tests/Feature/Commands/Operation/UpdateAllCommandTest.php apps/cli/tests/Unit/Services/Operations/GatewayOperationFollowerTest.php
```

Expected: PASS.

- [ ] **Step 5: Format and commit**

```bash
cd apps/cli && vendor/bin/pint --dirty --format agent
git add apps/cli/app/Commands/Operation/UpdateAllCommand.php apps/cli/app/Services/GatewayStreamClient.php apps/cli/app/Services/Operations/GatewayOperationFollower.php apps/cli/tests/Feature/Commands/Operation/UpdateAllCommandTest.php apps/cli/tests/Unit/Services/Operations/GatewayOperationFollowerTest.php
git commit -m "Follow durable update operations"
```

---

### Task 12: Publish `orbit-gateway` Image

**Files:**
- Create or modify: `.github/workflows/orbit-release.yml`
- Modify: `.github/workflows/orbit-cli-binary.yml` if release remains split
- Create: `apps/gateway/app/Services/Updates/ReleaseManifest.php`
- Create: `apps/gateway/app/Services/Updates/ReleaseManifestResolver.php`
- Modify: `apps/gateway/app/Services/Updates/UpdatePlanBuilder.php`
- Test: `.github/workflows` static review through docs/test assertions if existing workflow tests support it

- [ ] **Step 1: Add release workflow**

Required artifacts:

```text
orbit CLI binary assets
ghcr.io/hardimpactdev/orbit-gateway:<version>
ghcr.io/hardimpactdev/orbit-gateway:latest
release manifest JSON attached as a GitHub Release asset
```

Manifest shape:

```json
{
  "version": "v1.2.3",
  "cli": {
    "linux-x64": {"url": "...", "sha256": "..."},
    "macos-arm64": {"url": "...", "sha256": "..."}
  },
  "images": {
    "orbit-gateway": "ghcr.io/hardimpactdev/orbit-gateway:v1.2.3@sha256:..."
  },
  "external_images": {
    "caddy": "caddy:2-alpine@sha256:..."
  }
}
```

- [ ] **Step 2: Add manifest resolver**

`ReleaseManifestResolver` should support:

```php
public function latest(): ReleaseManifest
public function forVersion(string $version): ReleaseManifest
public function fromFile(string $path): ReleaseManifest
public function sourceFor(ReleaseManifest $manifest): string
```

Production resolution reads the GitHub Release asset for the requested version, or the latest release asset when no version is requested. Tests use `file://` or fixture paths so no network is required.

- [ ] **Step 3: Wire update runner to manifest**

Replace Task 9's temporary local/testing `UpdatePlanBuilder` production path. `UpdateAllStartController` resolves the release manifest first, builds an immutable `UpdatePlanSnapshot`, persists it through `UpdatePlanStore`, and launches the one-shot runner from the digest-pinned gateway image in that snapshot.

The production update plan must include:

```php
expect($plan->targetVersion)->toBe('v1.2.3')
    ->and($plan->gatewayImage->image)->toBe('ghcr.io/hardimpactdev/orbit-gateway:v1.2.3@sha256:abc123')
    ->and($plan->gatewayImageDigest)->toBe('sha256:abc123')
    ->and($plan->manifestVersion)->toBe('v1.2.3')
    ->and($plan->manifestSource)->toBe('github-release:hardimpactdev/orbit:v1.2.3')
    ->and($plan->cliArtifacts['linux-x64']['sha256'])->toBe('cli-sha');
```

`ORBIT_GATEWAY_IMAGE` may override the manifest image only when it is explicitly configured as an allowlisted production override and is digest-pinned. Request payload `gateway_image` remains local/testing-only.

Tests must prove the exact digest from the persisted plan reaches `GatewayServiceUpdater` and the same persisted manifest snapshot reaches `WorkloadNodeUpdater`.

- [ ] **Step 4: Run focused tests**

```bash
bin/orbit-gateway-pest --compact apps/gateway/tests/Unit/Services/Updates/ReleaseManifestResolverTest.php apps/gateway/tests/Feature/Http/Api/UpdateAllStartControllerTest.php
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add .github/workflows apps/gateway/app/Services/Updates/ReleaseManifest.php apps/gateway/app/Services/Updates/ReleaseManifestResolver.php apps/gateway/tests/Unit/Services/Updates/ReleaseManifestResolverTest.php
git commit -m "Publish orbit-gateway release artifact"
```

---

### Task 13: Split E2E Into Source-Dev And Artifact-Prod Lanes

**Files:**
- Modify: `apps/docs/content/testing/README.md`
- Modify: `apps/docs/content/testing/e2e/docker.md`
- Modify: `apps/e2e/app/Console/Commands/E2EEnsureArtifactsCommand.php`
- Modify: `apps/e2e/app/Console/Commands/E2EPrepareDockerRuntimeCommand.php`
- Modify: `apps/e2e/app/Services/E2E/DockerImageDistributor.php`
- Modify: `apps/e2e/app/E2E/Support/DockerTopologyProvider.php`
- Modify: `apps/e2e/app/E2E/Support/DockerTopologyBuilder.php`
- Modify: `apps/e2e/app/E2E/Support/IncusTopologyBuilder.php`
- Modify: `apps/e2e/app/E2E/Support/E2ETopologyArtifactNamespace.php`
- Modify: `apps/e2e/app/E2E/Support/OrbitCliBinaryBundle.php`
- Test: `apps/e2e/tests/Feature/**/*Runtime*Test.php`
- Test: `apps/e2e/tests/Feature/**/*Binary*Test.php`

- [ ] **Step 1: Update E2E docs**

Required lane wording:

```markdown
- **Source-dev topology:** fast development lane. VMs receive or mount the current worktree, provide PHP for source CLI execution, link `/usr/local/bin/orbit` to `<source>/apps/cli/orbit`, and hydrate Composer dependencies with host Composer when available or the helper image when host Composer is absent. This lane is for inner-loop CLI and gateway iteration.
- **Artifact-prod topology:** production-grade validation lane. VMs use the native Orbit CLI binary, the `orbit-gateway:<version>` image, the `orbit-scheduler` Swarm service, and role-scoped `orbit-caddy` only on nodes whose roles require router/proxy behavior. Gateway-only nodes use gateway-direct exposure. Gateway+router nodes use router-colocated exposure, where `orbit-caddy` owns host `80/443`, filters gateway API traffic with `remote_ip`, and proxies gateway API traffic to `orbit-gateway` over the attachable overlay `orbit-network`. This lane installs host PHP/Composer only for nodes with `app-dev` or `app-prod`; non-app role nodes must not require host PHP, Composer, Git, or source CLI entrypoints.
- Source-dev tests prove current worktree behavior quickly.
- Artifact-prod tests prove the real production artifacts work together, including update runner behavior.
```

- [ ] **Step 2: Update topology support tests**

Add assertions that source-dev and artifact-prod produce different node bootstrap contracts:

```php
expect($sourceDevEntrypointScript)
    ->toContain('/home/orbit/orbit/apps/cli/orbit')
    ->not->toContain('orbit-binary');

expect($sourceDevDependencyHydrationScript)
    ->toContain('apps/cli')
    ->toContain('composer')
    ->toContain('vendor/autoload.php');

expect($artifactProdGatewayOnlyScript)
    ->toContain('orbit-binary')
    ->toContain('orbit-gateway')
    ->not->toContain('/home/orbit/orbit/apps/cli/orbit')
    ->not->toContain('orbit-caddy')
    ->not->toContain('apt-get install php')
    ->not->toContain('apt install php')
    ->not->toContain('composer install');

expect($artifactProdGatewayRouterScript)
    ->toContain('orbit-binary')
    ->toContain('orbit-gateway')
    ->toContain('orbit-caddy')
    ->toContain('reverse_proxy http://orbit-gateway:8080')
    ->toContain('remote_ip')
    ->not->toContain('client_ip')
    ->not->toContain('published: 443')
    ->not->toContain('/home/orbit/orbit/apps/cli/orbit')
    ->not->toContain('apt-get install php')
    ->not->toContain('apt install php')
    ->not->toContain('composer install');

expect($artifactProdDatabaseScript)
    ->toContain('orbit-binary')
    ->not->toContain('/home/orbit/orbit/apps/cli/orbit')
    ->not->toContain('apt-get install php')
    ->not->toContain('apt install php')
    ->not->toContain('composer install');

expect($artifactProdAppScript)
    ->toContain('orbit-binary')
    ->toContain('apt-get install')
    ->toContain('php')
    ->toContain('composer')
    ->not->toContain('/home/orbit/orbit/apps/cli/orbit');
```

- [ ] **Step 3: Update E2E artifact assertions**

Assert the new production gateway image is present after Tasks 2-4. Do not assert that every `orbit-runtime` reference is gone here; compatibility removal belongs to Task 14.

```php
expect($output)
    ->toContain('orbit-gateway');
```

- [ ] **Step 4: Run E2E support tests**

```bash
cd apps/e2e && vendor/bin/pest --compact tests/Feature/Binary/BinarySelfTest.php tests/Feature/E2ESupport
```

Expected: PASS.

- [ ] **Step 5: Run broader quality gate**

```bash
composer docs-lint
bin/orbit-gateway-pest --compact
bin/orbit-cli-pest --compact
cd apps/e2e && vendor/bin/pest --compact
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add apps/docs/content/testing apps/e2e
git commit -m "Split live topology execution lanes"
```

---

### Task 14: Remove `orbit-runtime` Compatibility Surface

**Files:**
- Remove: `docker/orbit-runtime/Dockerfile`
- Remove: `docker/orbit-runtime/entrypoint.sh`
- Remove or rename: `apps/gateway/app/Services/Runtime/OrbitRuntimeContainer.php`
- Remove or rename: `apps/gateway/app/Services/Runtime/OrbitRuntimeContainerRenderer.php`
- Remove or rename: `apps/gateway/app/Services/Runtime/OrbitRuntimeContainerManager.php`
- Modify: remaining references found by `rg -n "orbit-runtime|OrbitRuntime|RemoteOrbitRuntimeExecutor"`
- Test: all touched tests

- [ ] **Step 1: Search old names**

```bash
rg -n "orbit-runtime|OrbitRuntime|RemoteOrbitRuntimeExecutor|runtime container" apps docs docker bin packages
```

Classify each match as:

```text
historical docs note
source-mounted prerequisite compatibility
must rename now
must delete now
```

- [ ] **Step 2: Rename execution lane**

Rename `RemoteOrbitRuntimeExecutor` to gateway-scoped terminology if it still exists after prerequisite work:

```text
GatewayContainerExecutor
```

It must only target gateway-owned Laravel/artisan/PDO work. Non-gateway node-local helpers use the CLI internal executor from the prerequisite plan.

- [ ] **Step 3: Delete old Docker files**

Delete `docker/orbit-runtime/*` after all build, installer, and E2E references use `docker/orbit-gateway`.

- [ ] **Step 4: Run old-name guard**

```bash
rg -n "orbit-runtime|OrbitRuntime" apps/gateway/app apps/cli/app docker bin
```

Expected: no production-code matches. Docs may retain explicitly historical migration notes only.

- [ ] **Step 5: Run quality check**

```bash
composer quality-check
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "Remove orbit-runtime compatibility surface"
```

---

## Final Verification

After all tasks:

```bash
composer docs-lint
bin/orbit-gateway-pest --compact
bin/orbit-cli-pest --compact
composer quality-check
composer test:e2e
composer test:e2e:provision
```

`composer test:e2e` and `composer test:e2e:provision` are mandatory for this plan because it changes Docker/Swarm, gateway installation, artifact-prod topology, and provisioning behavior. Live-node behavior must be verified against an ephemeral provisioned topology, not a standing production node.

## Risks

- Swarm service updates do not make incompatible gateway DB migrations safe. Keep migrations additive/compatible while old and new gateway API tasks may overlap.
- Swarm ingress listens on the published gateway API port for any IP assigned to the node. This only applies in `gateway-direct` exposure mode. The Docker-aware firewall contract must keep public/provider interfaces from reaching the gateway API while still allowing `start-first` Swarm updates; plain UFW-only rules are not enough.
- Gateway/router co-location can conflict on host port `443` unless the node uses `router-colocated` exposure. In that mode router-owned `orbit-caddy` owns host `80/443`, `orbit-gateway` publishes no host ports, and Caddy route access control must reject non-WireGuard gateway API clients without breaking public app/router hosts.
- The VPN/DNS substrate is deliberately out of scope for this Swarm slice. `orbit-dns` currently shares `wg-easy`'s network namespace; moving one without the other would break that contract.
- SQLite write concurrency matters because the runner and active gateway may both touch operation tables. Task 6 makes this concrete with WAL, `DB_BUSY_TIMEOUT=5000`, `DB_SYNCHRONOUS=NORMAL`, and small transactional event writes.
- Lease TTLs must be longer than any single Docker/SSH command timeout but short enough to recover after a crashed runner. Start with a 30 minute TTL, keep individual command timeouts below 25 minutes, and heartbeat before and after each bounded stage; expired leases are reclaimed on the next acquire attempt.
- A failed runner can leave an operation in `running` even after its leases expire. Add stale-runner operation heartbeat/resume only after the lease-protected durable operation path works.

## Resolved Follow-Up Decisions

- Canonical production gateway image registry: `ghcr.io/hardimpactdev/orbit-gateway`.
- Release manifest hosting: GitHub Release asset for the matching Orbit release.
- Follow-up order after this gateway Swarm/update-runner plan:
  1. `docs/superpowers/plans/2026-06-02-production-swarm-substrate-and-process-runtime.md`
     aligns the shared production runtime substrate, process-runtime backend,
     tool runtime-driver/version-family instance model, runtime platform
     implementation boundary, and service-address contract.
  2. `docs/superpowers/plans/2026-06-02-app-prod-frankenphp-runtime-isolation.md`
     implements the concrete app-prod FrankenPHP Swarm service, runtime
     UID/GID isolation, proxy upstream, and deploy rolling-update path.
- Keep those follow-ups separate. The substrate plan corrects fleet-wide
  runtime/process/addressing assumptions; the app-prod plan applies that
  substrate to app-prod HTTP serving and deployments.
- VPN/DNS Swarm follow-up: move `wg-easy` and `orbit-dns` together. Do not split them because `orbit-dns` currently depends on `wg-easy`'s network namespace, and the follow-up must handle `/dev/net/tun`, `NET_ADMIN`, UDP publishing, and dnsmasq constraints as one substrate.
