# TESTING.md

Environment-specific verification for the clean Orbit rebuild.

## Verification Model

Orbit has three supported test lanes:

1. In-memory Pest tests for deterministic command, service, database, renderer,
   and contract coverage.
2. Docker-backed feature E2E tests for prepared-topology command, registry,
   gateway API, and CA trust coverage.
3. Ephemeral VM E2E tests for real SSH, provisioning, image, WireGuard,
   systemd-backed runtime, and host-mutation
   coverage.

Standing live infrastructure is not a test lane. Do not use persistent gateway,
control, or app nodes as verification targets.

## In-Memory Pest

Use in-memory Pest tests for ordinary development:

```bash
composer test
```

This lane must not require real SSH, hcloud, Incus mutation, or standing
infrastructure. Fake process, gateway, provider, and transport boundaries in
Pest when the behavior is a command or contract concern.

## Ephemeral E2E

Use `composer test:e2e` for the default feature E2E lane. It runs
`e2e-feature` tests against the prepared Docker full topology:

```bash
composer test:e2e
composer test:e2e:topology-contract
```

Use VM-backed E2E only when the behavior depends on real provisioning,
WireGuard, VM networking, OS trust-store mutation, systemd, package
installation, cloud-init, or host-level daemon behavior:

```bash
composer e2e:preflight
php artisan e2e:prepare-incus-images --role=blank --force
composer e2e:prepare-base-image -- --force
composer e2e:prepare-topology -- --force control-gateway-dev-prod
composer test:e2e:provision
```

The VM E2E harness uses Incus VMs on the configured E2E host (`beast` by
default). It builds two reusable images plus per-topology template snapshots:

1. **Blank image** (`orbit-blank-ubuntu-26.04`). Built once via
   `php artisan e2e:prepare-incus-images --role=blank --force`. Ubuntu cloud
   + bootstrap user + sshd. Used by the provisioning lane's blank-VM
   lifecycle test.
2. **Base image** (`orbit-base-ubuntu-26.04`). Built once via
   `composer e2e:prepare-base-image -- --force`. Ubuntu cloud + bootstrap
   user + the `orbit` user + the apt deps that `bin/install-orbit` would
   otherwise install (PHP 8.5, composer, git, sqlite, WireGuard, openssh,
   Supervisor).
   No Orbit source is baked in. Rebuilt only when system deps change. In a
   multi-host pool, the image is built on
   `ORBIT_E2E_INCUS_IMAGE_BUILD_HOST` (default: `ORBIT_E2E_HOST`) and then
   exported/imported onto the configured `ORBIT_E2E_INCUS_HOSTS`.
3. **Per-topology templates** (`orbit-template-<kind>-<role>`). Built per
   `composer e2e:prepare-topology -- --force <kind>` invocation. The
   command tars the current checkout, ships it plus `bin/install-orbit`,
   `bin/e2e-provision-node`, and `bin/_e2e-deps.sh` to the host, then for
   each role: clones from the base image, runs the provisioner inside the
   clone (installing Orbit + role-specific bootstrap), runs the existing
   WireGuard / gateway:add / `node:new` ceremony, stops, and snapshots as
   `clean`. Tests clone these snapshots per run.

Source code lives in the per-run bundle, not in any image. Topology
snapshots get rebuilt each time `e2e:prepare-topology --force` runs. The
base image only needs rebuilding when apt dependencies change.

Latest Beast prepared-topology measurement (May 5, 2026):

- First successful `control-gateway-dev-prod` rebuild completed in roughly
  3m03s after harness blockers were fixed.
- Timed warm rebuild with `/usr/bin/time -p` completed in `real 205.71s`.
  This passes the cold target (≤ 8 min) but misses the warm target (≤ 3 min)
  by about 26s. Follow-up: Solo todo 298
  (`E2E-TOPOLOGY-WARM-OPT-1`).
- `composer test:e2e:topology-contract` passed after the rebuild
  (1 test, 28 assertions).

Use the following overrides to source the per-run bundle from a non-default
location:

- `composer e2e:prepare-topology -- --force <kind> --branch=<ref>` ships
  `git archive` of the named branch instead of tarring the working
  checkout.
- `composer e2e:prepare-topology -- --force <kind> --source-archive=<path>`
  ships an existing tarball.
- `composer e2e:prepare-topology -- --force <kind> --composer-cache=<dir>`
  bundles a composer cache directory; otherwise
  `~/.cache/orbit-e2e/composer` is bundled when present. A warm cache
  cuts `bin/install-orbit` runtime inside each role clone.

Environment overrides:

```bash
ORBIT_E2E_HOST=beast
ORBIT_E2E_INCUS_IMAGE_BUILD_HOST=beast
ORBIT_E2E_SOURCE_IMAGE=images:ubuntu/26.04/cloud
ORBIT_E2E_BLANK_IMAGE=orbit-blank-ubuntu-26.04
ORBIT_E2E_BASE_IMAGE=orbit-base-ubuntu-26.04
ORBIT_E2E_BOOTSTRAP_USER=provisioner
ORBIT_E2E_CONTROL_USER=control
ORBIT_E2E_INSTANCE_PREFIX=orbit-e2e
ORBIT_E2E_TIMEOUT_SECONDS=600
ORBIT_E2E_KEEP=1
```

## Provider Capability Matrix

| Capability | Docker | Incus |
| --- | --- | --- |
| Gateway API and CA trust | yes | yes |
| Registry-backed command behavior | yes | yes |
| Supervisor runtime backend | yes | yes |
| Orbit Scheduler daemon | yes | yes |
| Real WireGuard interfaces and peer routing | no | yes |
| VM boot, cloud-init, package install mutation | no | yes |
| Real SSH daemon and sudo behavior | no | yes |
| OS trust-store mutation | no | yes |
| Host init (systemd) on the node itself | no | yes |

### Provider Selection Rules

Use Docker for feature tests whose correctness depends on gateway API, CA
trust, registry state, the runtime backend, the Orbit Scheduler, or
Orbit-managed process and schedule lifecycle.

Use Incus for tests whose correctness depends on real VM behavior:
WireGuard kernel networking, cloud-init, package installation, OS
trust-store mutation, real SSH daemon behavior, sudo prompts, or host
init.

Provisioning, installer, and host-mutation tests stay in the
`e2e-provision` lane on Incus regardless of family.

## E2E Lanes

The ephemeral E2E suite is split into two explicit lanes at the Pest group level:

- **`e2e-provision`** — opt-in tests that mutate disposable VMs from blank or base
  images and exercise setup flows such as blank VM lifecycle, control node
  readiness, gateway onboarding, and node provisioning. These tests are
  grouped with `pest()->group('e2e-provision')` at the file level and run
  via `composer test:e2e:provision`. Tests that previously launched from
  role-specific ready images now stage a per-run bundle and run
  `bin/e2e-provision-node` against a base-image clone for the role under
  test.

- **`e2e-feature`** — tests that start from prepared topology clones and verify
  ported commands, forwarding chains, or read-only behavior. They must not run
  installer or provisioning commands unless the feature under test explicitly
  requires it. These tests are grouped with `pest()->group('e2e-feature')` at the
  file level and run via `composer test:e2e`. The default provider is Docker.

Each prepared topology has its own contract group:

| Topology | Contract group | Feature group |
| --- | --- | --- |
| `control` | `e2e-topology-contract-control` | `e2e-feature-control` |
| `control-gateway` | `e2e-topology-contract-control-gateway` | `e2e-feature-control-gateway` |
| `control-gateway-dev` | `e2e-topology-contract-control-gateway-dev` | `e2e-feature-control-gateway-dev` |
| `control-gateway-dev-prod` | `e2e-topology-contract-control-gateway-dev-prod` | `e2e-feature-control-gateway-dev-prod` |

`composer test:e2e:topology-contract` proves the Docker
`control-gateway-dev-prod` topology contract. It exists as a quick topology
health check, while `composer test:e2e` excludes topology-contract tests and
runs feature assertions only.

Both lanes still carry the umbrella `e2e` group via `tests/Pest.php`, but
`composer test:e2e` runs only feature assertions with Pest parallel mode. Each
worker process gets one cached Docker superset topology and reuses it for the
tests assigned to that worker. Provision tests are intentionally on demand
because they run real installer/provisioning paths and are much slower than
prepared-topology feature tests. They run with Pest parallel mode by default,
limited by `ORBIT_E2E_PROVISION_PARALLEL_PROCESSES`, and each worker must
acquire an Incus slot from `ORBIT_E2E_INCUS_HOST_SLOTS` before it creates a
disposable VM.

Provision tests clean up on success. On failure they keep tracked VMs/templates
for inspection and print their names plus a reap command. Set
`ORBIT_E2E_KEEP_ON_FAILURE=0` to restore cleanup-on-failure behavior.

Live or standing infrastructure verification lanes are sunset. Do not use
persistent gateway, control, or app nodes as verification targets.

## Topology Kinds

The `e2e-feature` lane uses prepared topology clones. Choose the smallest topology
that covers the behavior under test:

| Kind | Nodes | Use when |
| --- | --- | --- |
| `control` | 1 control | Fastest. Use for control-node-only commands. |
| `control-gateway` | control + 1 gateway | Use for gateway trust, onboarding, or node-registry flows. |
| `control-gateway-dev` | control + gateway + 1 dev app | Use for app or workspace commands that need a development app node. |
| `control-gateway-dev-prod` | control + gateway + dev + 1 prod app | Full topology. Slowest but most realistic. Use for production-app flows or full-stack verification. |

## Feature Checkout Overlay

Prepared topology images and templates are branch-agnostic topology baselines.
They prove OS, users, SSH, PHP, Composer, services, trust, routes, and baseline
Orbit installation state. They must not be rebuilt just to carry feature-branch
code for a command port.

Feature assertions must run the checkout under test inside the disposable clone.
For worktree-based development, this means the worker's current worktree is the
source of truth. The test installs or overlays that checkout into a disposable
path on the clone and invokes commands with `php artisan <command>` from that
checkout. The clone's installed `orbit` CLI remains a topology/bootstrap smoke
target unless the test is explicitly about installed CLI behavior.

Use the shared E2E checkout helper when a feature test needs the current
checkout on a clone. Do not mutate template instances, reusable images, or the
steady-state `orbit` symlink to make a feature assertion see new code.

The Pest-facing helpers in `tests/E2E/Support/Pest.php` wrap the lower-level
topology lease API:

```php
$topology = e2eTopology(E2ETopologyKind::ControlGatewayDevProd)
    ->withCurrentCheckout(roles: ['control', 'gateway', 'dev', 'prod']);

try {
    $topology->ssh(
        'control',
        "cd {$topology->checkout('control')} && php artisan node:list --json",
    );
} finally {
    $topology->cleanup();
}
```

Use `roles: ['control']` when only the control-side command under test needs
the branch checkout. Add `gateway`, `dev`, or `prod` when the branch changes
code that runs on those nodes.

When `ORBIT_E2E_TOPOLOGY_CACHE=process`, `e2eTopology()` reuses the same
prepared topology lease for matching requests in the current PHP process and
cleans it up once at process shutdown. `composer test:e2e` combines this with
Pest parallel mode and `ORBIT_E2E_TOPOLOGY_STRATEGY=superset`, so each worker
pays the Docker container startup cost once for a full topology. It also enables
`ORBIT_E2E_CHECKOUT_CACHE=process`, which installs the branch checkout once per
node/user and gives each test an isolated hardlink copy with fresh runtime
files. Because the current aggregate includes gateway-backed `node:list` tests,
it also sets `ORBIT_E2E_GATEWAY_API=1` and starts the gateway API once per
worker.

Topology state is reused by default inside a worker. Tests that intentionally
mutate shared topology state must either clean up after themselves or call
`$topology->reset()` at the point where they require a clean topology. Reset is
explicit so read-style feature tests can stay fast.

## Prepared Topology Contract

Prepared topology clones are not just booted VMs. When
`E2ETopologyFactory::fromEnvironment()->require(...)` returns, the clone set must
be ready for command assertions that depend on that topology kind.

Common requirements for every prepared topology:

- all nodes are disposable clones of `orbit-template-*` instances;
- each clone has the branch-agnostic baseline Orbit installation expected for
  that topology;
- SSH is authorized for the users needed by the topology handles;
- `orbit --version` works for the steady-state Orbit user on each managed node;
- tests may mutate clones, but must never mutate template instances;
- cleanup deletes clones unless `ORBIT_E2E_KEEP=1`;
- reset returns clones to the clean prepared state for the selected reset
  strategy.

`control`:

- one control clone is available through the `control` topology handle;
- the control user is `ORBIT_E2E_CONTROL_USER` (`control` by default);
- baseline Orbit is installed at `/home/<control-user>/orbit`;
- commands can run from the control node through the `orbit` CLI;
- the control registry contains the local control identity expected by
  control-node commands.

`control-gateway`:

- includes everything from `control`;
- one gateway clone is available through the `gateway` topology handle;
- the gateway steady-state user is `orbit`, with Orbit installed at
  `/home/orbit/orbit`;
- the gateway identity visible to control-node commands is named `gateway`;
- the control identity seeded on the gateway is named `control-1`;
- WireGuard test addresses are stable: gateway `10.6.0.2`, control
  `10.6.0.3`;
- the control node can reach the gateway API over the synthetic WireGuard route;
- `gateway:add 10.6.0.2 --json` has converged on the control node;
- the control node stores gateway settings and trusts the gateway CA;
- the gateway API exposes `/api/ca/root` over HTTP and `/api/me` over HTTPS.

`control-gateway-dev`:

- includes everything from `control-gateway`;
- one development app clone is available through the `devApp()` topology handle;
- the development app node is named `app-dev-1`;
- the gateway registry stores it as role `app`, environment `development`,
  TLD `test`, user `orbit`, and gateway endpoint `10.6.0.2`;
- the development app receives a WireGuard address in the prepared topology and
  is reachable through the gateway path required by app/workspace commands;
- development TLD state exists for `test` and points at the development app's
  WireGuard address.

`control-gateway-dev-prod`:

- includes everything from `control-gateway-dev`;
- one production app clone is available through the `prodApp()` topology handle;
- the production app node is named `app-prod-1`;
- the gateway registry stores it as role `app`, environment `production`, no
  development TLD, user `orbit`, and gateway endpoint `10.6.0.2`;
- production-app assertions can run without re-provisioning the control,
  gateway, or development app nodes.

## Commands

```bash
# Default in-memory Pest (excludes E2E)
composer test

# Ephemeral E2E lanes (requires ORBIT_E2E=1)
composer test:e2e
composer test:e2e:provision
composer test:e2e:topology-contract

# E2E readiness check
composer e2e:preflight

# Build the reusable Incus base image (deps + orbit user, no source).
# Multi-host pools build on ORBIT_E2E_INCUS_IMAGE_BUILD_HOST and import to hosts.
# Rebuild only when system deps change.
composer e2e:prepare-base-image -- --force

# Prepare or replace a topology clone for the feature lane. Tars the
# current checkout, ships it via per-run bundle, runs the provisioner per
# role, snapshots clean.
composer e2e:prepare-topology -- --force control-gateway-dev-prod

# Prepare Docker feature topology images
composer e2e:prepare-docker-runtime -- --force
composer e2e:prepare-docker-topology -- --force control-gateway-dev-prod

# Prepare Docker feature topology images on every configured Docker host
ORBIT_E2E_DOCKER_HOST_SLOTS=sidecar1:2,sidecar2:2,beast:3 \
composer e2e:prepare-docker-hosts -- --force control-gateway-dev-prod

# Reap stale E2E resources
composer e2e:reap-incus
composer e2e:reap-hcloud
composer e2e:reap-docker
composer e2e:reap-docker -- --force --older-than=0m
```

`composer test:e2e` is the fast feature regression lane. It runs all
`e2e-feature` tests in parallel against cached Docker full topologies, one
topology per Pest worker process.
Use `composer test:e2e:topology-contract` when you want to prove the prepared
Docker topology itself. Provisioning E2E runs in parallel too, but it leases
Incus slots and remains intentionally on demand because it exercises real
machine setup.

### Docker Feature Topologies

Docker is the default provider for `composer test:e2e`:

```bash
composer e2e:prepare-docker-runtime -- --force
composer e2e:prepare-docker-topology -- --force control-gateway-dev-prod
composer test:e2e
```

On this Mac, OrbStack provides the local Docker CLI and daemon. The active
Docker context should normally be `orbstack`:

```bash
docker context ls
docker info --format '{{.ServerVersion}} {{.Name}}'
```

Remote Docker feature hosts are still driven by the local OrbStack/Docker CLI.
The E2E Docker provider does not run `ssh sidecar1 docker ...`; it runs local
Docker commands with `DOCKER_HOST=ssh://<host>`. If a feature E2E run reports
`docker daemon is not reachable`, verify the same transport the provider uses
before declaring Docker unavailable:

```bash
for host in sidecar1 sidecar2 beast; do
  DOCKER_HOST=ssh://$host docker info --format '{{.ServerVersion}} {{.Name}}'
done
```

For comparison only, direct SSH can prove the host is reachable, but it does not
exercise Docker's SSH transport:

```bash
ssh -o BatchMode=yes sidecar1 'hostname && command -v docker && docker info --format "{{.ServerVersion}} {{.Name}}"'
```

If the direct SSH check passes but `DOCKER_HOST=ssh://... docker info` fails,
debug Docker CLI SSH transport, SSH multiplexing, or Docker context/env state.
Do not switch the E2E provider to `ssh host docker ...`; the supported remote
Docker transport is `DOCKER_HOST=ssh://<host>`.

The recommended local topology is to run Docker containers on `sidecar1` and
`sidecar2` first, with `beast` as overflow capacity only when it is not running
Incus provisioning E2E. Incus provisioning runs on `beast` only. Set
`ORBIT_E2E_EXCLUSIVE_HOSTS=beast` so Docker leases on Beast and Incus leases on
Beast block each other while still allowing multiple Docker slots on Beast when
provisioning is idle. Docker exercises gateway API, certificate, and registry
behavior over a WireGuard-shaped `10.6.0.0/16` Docker bridge, but it does not
exercise real WireGuard interfaces, peer routing, VM boot, or systemd.

Docker topologies are disposable containers seeded from per-role prepared
images. They are useful for fast command, registry, gateway API, CA trust,
HTTPS-verification, and forwarding assertions where the command behavior is the
thing under test. They are not valid for features whose correctness depends on
the node runtime itself: real SSH daemon behavior, sudo prompts, OS trust-store
mutation, systemd units or timers, package installation, cloud-init, WireGuard
interfaces, WireGuard peer routing, or VM networking behavior. Tests that need
those capabilities must call
`E2ETopologyFactory::fromEnvironment()->requireCapabilities(E2ETopologyCapabilities::vm())->require(...)`
so the provider pool refuses Docker for them.

Docker is a valid lane for `process:*`, `schedule:*`, and `workspace:*`
runtime assertions because the runtime backend (Supervisor) and the Orbit
Scheduler run inside Docker containers. Docker topology containers boot through
`tini` into `supervisord -n`; Supervisor manages `sshd` and the
`orbit_scheduler` program for runtime-backend feature tests. Incus remains required
for tests that depend on real VM behavior: cloud-init, package
installation, real SSH daemon behavior, sudo prompts, OS trust-store
mutation, real WireGuard interfaces and peer routing, and host init
itself.

`composer test:e2e` runs with Pest parallel mode. The script fallback process
count is `2`; the shared local `.env.e2e` uses
`ORBIT_E2E_PARALLEL_PROCESSES=7` to match the sidecar1, sidecar2, and Beast
overflow slot pool. Keep the value within Docker host capacity: a full topology
uses four containers, so a host with
`ORBIT_E2E_DOCKER_MAX_CONTAINERS_PER_HOST=8` can safely run two full-topology
workers. Add Docker hosts with
`ORBIT_E2E_DOCKER_HOSTS=sidecar1,sidecar2,beast` or raise the per-host
container limit when the runner can handle more.

For multi-host parallelism, use host slots and set the Pest worker count to the
total slot count:

```bash
ORBIT_E2E_DOCKER_HOST_SLOTS=sidecar1:2,sidecar2:2,beast:3 \
ORBIT_E2E_EXCLUSIVE_HOSTS=beast \
ORBIT_E2E_PARALLEL_PROCESSES=7 \
composer test:e2e
```

With that example, up to two workers can lease `sidecar1`, two can lease
`sidecar2`, and three can lease `beast` when no Incus provisioning lease is
active on Beast. The mapping is a blocking lease pool, not a worker-number map:
a worker takes the first free Docker slot, waits when all slots are busy or when
Beast is reserved by provisioning, and releases its slot during topology
cleanup. Stale lease files are reclaimed after `ORBIT_E2E_SLOT_STALE_SECONDS`.
Lease files are shared across Git worktrees by default: worktree runs resolve
the lease directory to the main checkout's `storage/framework/e2e/leases`. Set
`ORBIT_E2E_LEASE_DIRECTORY` only when a run should intentionally use an isolated
lease pool.

Each Pest worker gets a non-overlapping Docker subnet. Non-parallel runs keep
the canonical `10.6.0.0/16` topology. Parallel workers use `10.61.0.0/16`,
`10.62.0.0/16`, and so on, derived from ParaTest's `TEST_TOKEN`. Role addresses
stay consistent within the worker subnet: gateway `.2`, control `.3`, dev `.4`,
prod `.5`.

Tests must reach Docker topology services through topology handles such as
`$topology->control()->ssh(...)`, not by calling `https://10.6.0.x` directly
from the Pest process. On macOS, Docker Desktop runs containers inside a Linux
VM, so the bridge subnet is reachable from containers but not necessarily from
the developer host.

The non-parallel Docker bridge uses subnet `10.6.0.0/16` to match seeded
WireGuard addresses. If you also run an Orbit VPN client locally on `10.6.0.x`,
stop the tunnel before running non-parallel Docker E2E or Docker network
creation will fail with a subnet overlap error.

To target a single remote Docker daemon for ad hoc debugging, keep Pest running
locally and override the Docker host list:

```bash
ORBIT_E2E_DOCKER_HOSTS=beast \
ORBIT_E2E_DOCKER_HOST_SLOTS=beast:1 \
ORBIT_E2E_PARALLEL_PROCESSES=1 \
composer test:e2e
```

The local machine only needs the Docker CLI and SSH access; the prepared Docker
images must exist on every configured Docker host. The Docker provider checks
`docker image inspect` against the selected daemon because `DOCKER_HOST=ssh://...`
forwards every CLI call.

Use the aggregate host preparation command to build fresh runtime and topology
images across the configured Docker host pool:

```bash
ORBIT_E2E_DOCKER_HOST_SLOTS=sidecar1:2,sidecar2:2,beast:3 \
composer e2e:prepare-docker-hosts -- --force control-gateway-dev-prod
```

When `ORBIT_E2E_DOCKER_HOST_SLOTS` is set, the command prepares each unique slot
host once. Otherwise it uses `ORBIT_E2E_DOCKER_HOSTS`. Use
`--runtime-only` or `--topology-only` when only one image layer needs refreshing.

To run Docker feature E2E on temporary Hetzner capacity, use the hidden wrapper:

```bash
composer test:e2e:hcloud-docker -- --force --location=nbg1 --processes=3
```

For explicit per-location resource shapes, pass or export resource slots:

```bash
ORBIT_E2E_HCLOUD_RESOURCE_SLOTS=nbg1/cx23/ubuntu-24.04:2,fsn1/cpx31/ubuntu-24.04:1 \
composer test:e2e:hcloud-docker -- --force --processes=3
```

The wrapper creates a temporary Hetzner Cloud server with Docker installed,
prepares the Docker runtime and topology images on that server, runs
`composer test:e2e` with `ORBIT_E2E_DOCKER_HOSTS=root@<server-ip>`, then deletes
the server and temporary SSH key unless `--keep` is set. This is intentionally
separate from the always-on Docker host pool: use it when local/standing Docker
capacity is unavailable or when CI needs disposable Docker capacity. When
resource slots are configured, the wrapper leases one
`location/server-type/image` slot and uses that exact shape for the temporary
server.

If Docker resources accumulate from interrupted runs, prefer the reaper:

```bash
composer e2e:reap-docker
composer e2e:reap-docker -- --force --older-than=0m
```

## Environment

The Composer E2E scripts source `.env.e2e` when that file exists, then apply
the lane-specific defaults shown in `composer.json`. Copy `.env.e2e.example` to
`.env.e2e` in the main checkout for local machine pool settings. Worktrees
should symlink their `.env.e2e` back to the main checkout so every worktree uses
the same slot configuration.

```bash
ORBIT_E2E=1                           # Enable ephemeral E2E tests
ORBIT_E2E_PROVIDER=incus              # Backend provider (incus is current)
ORBIT_E2E_PROVIDERS=incus             # Ordered provisioning provider pool
ORBIT_E2E_TOPOLOGY_PROVIDER=docker    # Prepared topology provider for composer test:e2e
ORBIT_E2E_TOPOLOGY_PROVIDERS=docker   # Ordered prepared topology provider pool
ORBIT_E2E_GATEWAY_API=1               # Start gateway API/10.6 routes for tests that need it
ORBIT_E2E_DOCKER_HOSTS=sidecar1,sidecar2,beast  # Recommended Docker daemon pool
ORBIT_E2E_DOCKER_HOST_SLOTS=sidecar1:2,sidecar2:2,beast:3  # Docker feature-test lease pool
ORBIT_E2E_DOCKER_MAX_CONTAINERS_PER_HOST=8  # Docker topology capacity per daemon
ORBIT_E2E_PARALLEL_PROCESSES=7        # Pest workers for composer test:e2e
ORBIT_E2E_INCUS_HOSTS=beast           # Incus provisioning host pool
ORBIT_E2E_INCUS_HOST_SLOTS=beast:1    # Incus provisioning-test lease pool
ORBIT_E2E_HCLOUD_LOCATION_SLOTS=nbg1:2,fsn1:1  # Hetzner provisioning-test lease pool
ORBIT_E2E_HCLOUD_RESOURCE_SLOTS=nbg1/cx23/ubuntu-24.04:2,fsn1/cpx31/ubuntu-24.04:1  # Hetzner location/type/image pool
ORBIT_E2E_PROVISION_PARALLEL_PROCESSES=1  # Pest workers for composer test:e2e:provision
ORBIT_E2E_EXCLUSIVE_HOSTS=beast       # Prevent Docker/Incus overlap on shared hosts
ORBIT_E2E_SLOT_WAIT_SECONDS=900       # How long Docker/Incus workers wait for a free slot
ORBIT_E2E_SLOT_STALE_SECONDS=7200     # Reclaim abandoned local lease files after this TTL
ORBIT_E2E_LEASE_DIRECTORY=            # Optional override; default is shared across repo worktrees
ORBIT_E2E_INCUS_IMAGE_BUILD_HOST=beast # Build Incus images once here, then import to Incus hosts
ORBIT_E2E_INCUS_STORAGE_POOL=orbit-e2e  # Optional Incus storage pool for launch/copy operations
ORBIT_E2E_INCUS_MAX_VMS_PER_HOST=4    # VM quota per host
ORBIT_E2E_TOPOLOGY_STRATEGY=minimal   # Topology selection strategy
ORBIT_E2E_TOPOLOGY_CACHE=process      # Reuse acquired topologies for the current PHP process
ORBIT_E2E_CHECKOUT_CACHE=process      # Reuse branch checkout installs within one PHP process
ORBIT_E2E_TOPOLOGY_RESET=fresh-clone  # Reset strategy for topology clones
ORBIT_E2E_TIMINGS=1                   # Print phase timings to STDERR (acquire / reset)
ORBIT_E2E_CPUS=2                      # vCPUs for image-prep / provisioning VMs
ORBIT_E2E_MEMORY=2GiB                 # Memory for image-prep / provisioning VMs
ORBIT_E2E_TOPOLOGY_CPUS=1             # vCPUs for disposable topology clones
ORBIT_E2E_TOPOLOGY_MEMORY=2GiB        # Memory for disposable topology clones
ORBIT_E2E_TOPOLOGY_STATE_SIZE=4GiB    # Root disk state size for stateful VM snapshots
```

`ORBIT_E2E_PROVIDER` and `ORBIT_E2E_PROVIDERS` choose provisioning providers.
`ORBIT_E2E_TOPOLOGY_PROVIDER` and `ORBIT_E2E_TOPOLOGY_PROVIDERS` choose
prepared topology providers for `e2e-feature` tests. Keep provisioning provider
selection VM-backed when a test proves machine setup, SSH, sudo, package
installation, trust-store mutation, or system services.

`ORBIT_E2E_PROVIDER=auto` expands to Incus only. Hetzner Cloud is intentionally
opt-in: use `ORBIT_E2E_PROVIDER=hcloud`, `ORBIT_E2E_PROVIDERS=hcloud`, or the
dedicated `composer test:e2e:hcloud-docker -- --force` wrapper when a run should
create paid Hetzner resources.

`composer test:e2e` sets `ORBIT_E2E_TOPOLOGY_PROVIDER=docker` explicitly.
`ORBIT_E2E_TOPOLOGY_PROVIDER=auto` still expands to `incus` for direct artisan
or Pest invocations unless the provider selection code is changed.

`composer test:e2e:provision` runs serially when
`ORBIT_E2E_PROVISION_PARALLEL_PROCESSES=1`. Set the value above `1` to enable
Pest parallel mode for provision tests; every worker still acquires a
provisioning lease before creating Incus or Hetzner resources.

`composer test:e2e` and `composer test:e2e:provision` use separate lease
namespaces in the same shared lease directory. Docker feature tests read
`ORBIT_E2E_DOCKER_HOST_SLOTS`; Incus provisioning tests read
`ORBIT_E2E_INCUS_HOST_SLOTS`; Hetzner Cloud provisioning tests read
`ORBIT_E2E_HCLOUD_RESOURCE_SLOTS` first and fall back to
`ORBIT_E2E_HCLOUD_LOCATION_SLOTS`. By default those namespaces do not block each
other. Add a host to `ORBIT_E2E_EXCLUSIVE_HOSTS` when the same machine appears
in more than one backend pool and the backend families must not overlap. The
local Beast setup uses `ORBIT_E2E_EXCLUSIVE_HOSTS=beast`, so Beast Docker
overflow waits while Beast is running Incus provisioning E2E, and provisioning
waits while Docker is using Beast. Same-backend slots still run concurrently,
so `beast:3` can host three Docker feature workers when no provisioning lease is
active.

`ORBIT_E2E_HCLOUD_RESOURCE_SLOTS` treats each key as
`location/server-type/image` and applies all three values before creating
servers. `ORBIT_E2E_HCLOUD_LOCATION_SLOTS` remains available when every leased
location should use the same `ORBIT_E2E_HCLOUD_SERVER_TYPE` and
`ORBIT_E2E_HCLOUD_BLANK_IMAGE`.

Provisioning and topology clones use independent resource budgets. Image
preparation and provisioning E2E keep `ORBIT_E2E_CPUS=2` because installer work
is CPU- and package-manager-bound. Topology feature clones default to 1 vCPU
because the work is mostly SSH, SQLite, command execution, small API calls, and
readiness polling — more 1-vCPU clones in parallel beats fewer 2-vCPU clones.

`ORBIT_E2E_INCUS_MAX_VMS_PER_HOST` is enforced by the host pool. When a feature
test asks for a topology, the pool walks the configured hosts and picks the
first one that has both the prepared templates *and* enough free Orbit-owned
slots (`max - runningE2EInstanceCount() >= roles required`). User-owned VMs are
ignored — only instances whose name starts with `ORBIT_E2E_INSTANCE_PREFIX` are
counted. Recommended baseline:

```bash
ORBIT_E2E_INCUS_HOSTS=beast
ORBIT_E2E_INCUS_HOST_SLOTS=beast:1
ORBIT_E2E_INCUS_STORAGE_POOL=orbit-e2e
ORBIT_E2E_INCUS_MAX_VMS_PER_HOST=4
ORBIT_E2E_EXCLUSIVE_HOSTS=beast
```

`ORBIT_E2E_INCUS_STORAGE_POOL` is optional. Leave it empty to use each host's
Incus default pool. Set it when a host has a faster CoW-capable pool, e.g. a
dedicated ZFS-backed `orbit-e2e` pool, so template builds and feature clones use
that pool explicitly.

`ORBIT_E2E_TOPOLOGY_RESET` controls how `E2ETopologyLease::reset()` returns a
clone to a known-clean state between sub-scenarios in the same test:

- `fresh-clone` (default): delete the existing clones and rebuild via the
  prepared topology templates. Always works but pays the full clone + start
  cost on every reset.
- `snapshot-restore`: stop each clone, restore the per-instance `lease-clean`
  snapshot (created right after SSH authorization during acquire), start each
  clone, then wait for agent and SSH on the same handles. Significantly
  faster than `fresh-clone` for tests that reset multiple times. Falls back to
  `fresh-clone` if a lease was constructed without a snapshot reset closure
  (e.g. unit tests).
- `stateful-restore`: create a running `lease-warm` snapshot with
  `migration.stateful=true` and restore that running state between
  sub-scenarios. This is the experimental fastest reset path; it restores all
  role VMs to the captured warm state, including memory state and running
  services. It also applies `size.state=ORBIT_E2E_TOPOLOGY_STATE_SIZE` to the
  root disk before boot because Incus requires that value to be larger than
  `limits.memory`. Use it only on hosts where stateful VM migration/snapshot
  support has been verified.

Unknown values continue to fall back to `fresh-clone`.

For test-to-test isolation, prefer one topology lease per Pest test and call
`$topology->cleanup()` in `finally`. For multiple destructive sub-scenarios in a
single test, call `$topology->reset()` between scenarios; set
`ORBIT_E2E_TOPOLOGY_RESET=snapshot-restore` or
`ORBIT_E2E_TOPOLOGY_RESET=stateful-restore` when restore speed matters and the
scenario does not need a brand-new clone identity.

Set `ORBIT_E2E_TIMINGS=1` to surface per-phase durations from the topology
factory and lease. Current event names include `availability`,
`batch.copy-start`, `agent-ready.<role>`, `command-ready.<role>`, `wireguard`,
`cleanup.<role>`, and
`reset.*`. Output goes to STDERR with the prefix `[orbit-e2e]` so it interleaves
cleanly with Pest output. The clone/start batch intentionally stays one remote
SSH operation; split copy/start timing should only be added if it can keep that
single remote operation.

All E2E orchestration now runs via Pest groups and `php artisan e2e:*` commands.
