# Docker E2E

Docker is the default provider for Docker-eligible feature tests. Once
`.env.e2e` points at the standing Docker host pool and the runtime/topology
images have been imported onto those hosts, the Docker-only lane is:

```bash
composer test:e2e:docker
```

`composer test:e2e:docker` and the Docker lane of `composer test:e2e` do not
rebuild Docker images. On a fresh host pool, after Dockerfile or system
dependency changes, or when remote images look stale, rebuild on Beast and
refresh each configured Docker host:

```bash
ORBIT_E2E_DOCKER_TEST_RUNNERS=sidecar1:4:28,sidecar2:4:28 \
ORBIT_E2E_DOCKER_IMAGE_BUILD_HOSTS=beast \
composer e2e:prepare-docker-hosts -- --force operator_gateway_app-dev_app-prod
```

For single-host local debugging, the lower-level equivalents are:

```bash
composer e2e:prepare-docker-runtime -- --force
composer e2e:prepare-docker-topology -- --force operator_gateway_app-dev_app-prod
```

Prepare each Docker topology kind that the lane should be able to run. Docker
feature tests use the smallest matching prepared role images for their requested
topology rather than sourcing every gateway-backed test from
`operator_gateway_app-dev_app-prod_agent`.

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

## Docker topology behavior

Docker exercises gateway API, certificate, and registry behavior over isolated
Docker bridge networks from the `10.90.N.0/24` pool. The DNS alias mode keeps
canonical `10.6.0.x` WireGuard identities inside seeded gateway state. Docker
does not exercise real WireGuard interfaces, peer routing, VM boot, or systemd.

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
Gateway source is synchronized to the gateway `orbit-runtime` sibling.

Docker is a valid lane for `process:*`, `schedule:*`, and `workspace:*` runtime
assertions because the Docker topologies that include a gateway provide
`orbit-runtime`, `orbit-caddy`, FrankenPHP app/workspace containers, Docker
process runtime containers, and the gateway scheduler inside `orbit-runtime`.

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
operator `.3`, dev `.4`, prod `.5`, agent `.6`, and ingress `.7`. `control` is
the legacy alias for the operator node address.

Tests must reach Docker topology services through topology handles such as
`$topology->operator()->ssh(...)`, not by calling `https://10.6.0.x` directly
from the Pest process.

If Docker resources accumulate from interrupted runs, prefer the reaper:

```bash
composer e2e:reap-docker
composer e2e:reap-docker -- --force --older-than=0m
```
