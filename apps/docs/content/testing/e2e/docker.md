# Docker E2E

Docker is the default provider for Docker-eligible feature tests. Once
`.env.e2e` points at the standing Docker host pool and the runtime/topology
images have been imported onto those hosts, the Docker-only lane is:

```bash
composer test:e2e:docker
```

`composer test:e2e:docker` and the Docker lane of `composer test:e2e` do not
rebuild Docker images. Missing support or role images fail the lane before Pest
workers start and include a scoped `composer e2e:ensure-artifacts` command.

Use the Docker provider provision lane when the Docker runtime image, support
images, prepared role images, or Docker host artifact distribution may have
changed:

```bash
composer test:e2e:provision:docker
```

This command is the agent-facing full Docker artifact refresh. It delegates to
the Docker host preparer for
`operator_gateway_app-dev_app-prod_agent_websocket`. It can run in parallel with
`composer test:e2e:provision:incus` because the provider substrates are
separate.

Use the ensure command for targeted refreshes:

```bash
composer e2e:ensure-artifacts -- --lanes=docker --runtime --force operator_gateway_app-dev_app-prod_agent_websocket

ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE=agent-isolation \
composer e2e:ensure-artifacts -- --lanes=docker --roles=agent --force operator_gateway_app-dev_app-prod_agent_websocket

composer e2e:ensure-artifacts -- --lanes=docker --roles=operator,gateway --rebuild --force operator_gateway_app-dev_app-prod_agent_websocket
```

On a fresh host pool, after Dockerfile or system dependency changes, or when the
entire base image set is intentionally being refreshed, rebuild on Beast and
refresh each configured Docker host:

```bash
ORBIT_E2E_DOCKER_TEST_RUNNERS=sidecar1:4:28,sidecar2:4:28 \
ORBIT_E2E_DOCKER_IMAGE_BUILD_HOSTS=beast \
composer e2e:prepare-docker-hosts -- --force operator_gateway_app-dev_app-prod_agent_websocket
```

For single-host local debugging, the lower-level equivalents are:

```bash
composer e2e:prepare-docker-runtime -- --force
composer e2e:prepare-docker-topology -- --force operator_gateway_app-dev_app-prod_agent_websocket
```

Prepare the composable Docker role image set once. The Docker preparation flow
builds `operator_gateway` first, then provisions the downstream app-dev,
app-prod, and agent roles from the full role source. Feature tests request the
smallest active topology they need; websocket-capable topologies add the
websocket role to the app-dev node instead of starting a separate websocket
image.

The canonical reusable role images are:

- `orbit-e2e:operator_base`
- `orbit-e2e:gateway_base`
- `orbit-e2e:app-dev_base`
- `orbit-e2e:app-prod_base`
- `orbit-e2e:agent_base`

Set `ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE=<slug>` only when a branch needs a
role-specific image override. The Docker provider first tries
`orbit-e2e:<role>_<slug>` for each requested role, then falls back to
`orbit-e2e:<role>_base` for that role. Setting the namespace does not make every
role branch-specific; unchanged roles keep reusing the base images.

When preparing artifacts with a custom namespace, pass the changed roles
explicitly. This builds and distributes only those selected role images; absent
roles keep falling back to `base`:

```bash
ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE=agent-isolation \
composer e2e:ensure-artifacts -- --lanes=docker --roles=agent --force operator_gateway_app-dev_app-prod_agent_websocket
```

Use `--all-roles` only when the branch intentionally needs a full namespaced
role-image set. Add `--rebuild` when the selected tags exist but must be
refreshed from the current checkout. A custom namespace without `--roles` or
`--all-roles` is rejected so a worktree cannot accidentally rebuild every Docker
role.

Runner hosts need the canonical role images plus the runtime support images
used by gateway-backed topologies: `orbit-gateway`, `orbit-caddy`, and the
FrankenPHP images listed by `PhpRuntimeCatalog`. `orbit-e2e-topology-runtime`
is a build-host helper for preparing those images. Remote source-dev live runs
may use `composer:2` transiently on the runner to hydrate the synced gateway
and CLI vendor directories, but it is not a topology role image.

## Host transport

On this Mac, OrbStack provides the local Docker CLI and daemon. The active Docker
context should normally be `orbstack`:

```bash
docker context ls
docker info --format '{{.ServerVersion}} {{.Name}}'
```

Remote Docker feature hosts are driven by the local Docker CLI through
`DOCKER_HOST=ssh://<host>`. Verify the same transport the provider uses:

```bash
for host in sidecar1 sidecar2 beast; do
  DOCKER_HOST=ssh://$host docker info --format '{{.ServerVersion}} {{.Name}}'
done
```

Direct SSH can prove the host is reachable, but it does not exercise Docker's
SSH transport:

```bash
ssh -o BatchMode=yes sidecar1 'hostname && command -v docker && docker info --format "{{.ServerVersion}} {{.Name}}"'
```

If direct SSH passes but `DOCKER_HOST=ssh://... docker info` fails, debug Docker
CLI SSH transport, SSH multiplexing, or Docker context/env state. Do not switch
the E2E provider to `ssh host docker ...`; the supported remote Docker transport
is `DOCKER_HOST=ssh://<host>`.

Source-mounted Docker topologies mount the initiating worktree at
`/home/orbit/orbit`. The local daemon uses the current worktree path directly.
Remote Docker runners rsync the current worktree to a stable host path before
acquisition, then bind-mount that synced path. The generated remote path is
`/tmp/orbit-e2e-sources/<worktree>-<hash>`; override it with
`ORBIT_E2E_DOCKER_SOURCE_PATH=/host/visible/orbit` or the host-specific
`ORBIT_E2E_DOCKER_SOURCE_PATH_<HOST>=/host/visible/orbit` only when a host needs
a fixed path.

The source sync excludes dependency directories, build output, env files,
SQLite files, and gateway service state. After rsync, the runner hydrates
`apps/gateway/vendor` and `apps/cli/vendor` on the remote path from the synced
lockfiles. Vendor is refreshed only when the lock marker changes.

## Host pool

The recommended local topology is to run Docker containers on `sidecar1` and
`sidecar2`. Incus runs on `beast` only. Beast may be added to the Docker runner
pool as overflow capacity when it is also listed in `ORBIT_E2E_EXCLUSIVE_HOSTS`.

```bash
ORBIT_E2E_DOCKER_TEST_RUNNERS=sidecar1:4:28,sidecar2:4:28 \
composer test:e2e:docker
```

The normal local pool derives eight Docker workers. Up to four workers can lease
`sidecar1` and four can lease `sidecar2`. The mapping is a blocking lease pool,
not a worker-number map: a worker takes the first free Docker slot, waits when
all slots are busy, and releases its slot during topology cleanup.

To include an optional runner, add it to the same value with its own slots and
container cap. It contributes workers only when reachable at run start:

```bash
ORBIT_E2E_DOCKER_TEST_RUNNERS=sidecar1:4:28,sidecar2:4:28,macbook:2:16 \
composer test:e2e:docker
```

Set `ORBIT_E2E_PARALLEL_PROCESSES=<n>` only as a temporary Docker debugging cap;
do not keep it in `.env.e2e` for normal runs.

Docker subnets that are scoped to a test run support up to 16 parallel workers.
When the configured runner pool has more slots than that, `e2e:test` reduces the
effective host slots before starting Pest so workers do not fail inside topology
acquisition.

## Docker topology behavior

Docker exercises gateway API, certificate, and registry behavior over isolated
Docker bridge networks from the `10.90.N.0/24` pool. The DNS alias mode keeps
canonical `10.6.0.x` WireGuard identities inside seeded gateway state. Docker
does not exercise real WireGuard interfaces, peer routing, VM boot, or systemd.
DNS alias mode is the only supported Docker prepared-topology mode. Parallel
test isolation comes from per-run container names, Docker bridge networks, and
subnet allocation; image tags do not carry topology kind, DNS mode, or run
identity.

### Feature scope

Docker topologies are disposable containers seeded from per-role prepared images.
They are useful for fast command, registry, gateway API, CA trust,
HTTPS-verification, and forwarding assertions where command behavior is the
thing under test. Tests that require real SSH daemon behavior, sudo prompts,
OS trust-store mutation, systemd units, package installation, cloud-init,
WireGuard interfaces, WireGuard peer routing, or VM networking must require VM
capabilities so the provider pool refuses Docker.

The Docker topology build context intentionally includes the local `vendor/`
directory. Client prepared topology dependencies are installed or reused through
transient `composer:2` helper containers and then persisted into the node image.
Gateway source-dev code is synchronized to the gateway container sibling.

Docker provisioning uses a Composer cache during image preparation. By default
the cache is a lockfile-keyed Docker volume; set
`ORBIT_E2E_DOCKER_COMPOSER_CACHE` to bind a build-host cache directory, and set
`ORBIT_E2E_DOCKER_COMPOSER_CACHE_READ_ONLY=1` when the mounted cache should not
be mutated by the helper containers.

Docker is a valid lane for `process:*`, `schedule:*`, and `workspace:*` runtime
assertions because the Docker topologies that include a gateway provide
`orbit-gateway`, `orbit-scheduler`, `orbit-caddy`, FrankenPHP app/workspace
containers, and Supervisor process programs where configured process units are
under test.

## Debugging selected tests

For worktree debugging of a single file or related file set, pass paths through
the Composer script. Add `--sequential-tests` when the selected tests should stay
in one Pest process:

```bash
composer test:e2e:docker -- --sequential-tests apps/gateway/tests/E2E/AppListTest.php

composer test:e2e:docker -- --sequential-tests \
  apps/gateway/tests/E2E/AppListTest.php \
  apps/gateway/tests/E2E/NodeListTopologyTest.php

composer test:e2e:docker -- --sequential-tests \
  --filter='lists apps' \
  apps/gateway/tests/E2E/AppListTest.php
```

Each Pest worker gets a non-overlapping Docker subnet from the `10.90.N.0/24`
pool. Role host endings stay consistent within the worker subnet: gateway `.2`,
operator `.3`, dev `.4`, prod `.5`, agent `.6`, and ingress `.7`.

Tests must reach Docker topology services through topology handles such as
`$topology->operator()->ssh(...)`, not by calling `https://10.6.0.x` directly
from the Pest process.

If Docker resources accumulate from interrupted runs, prefer the reaper:

```bash
composer e2e:reap-docker
composer e2e:reap-docker -- --force --older-than=0m
```
