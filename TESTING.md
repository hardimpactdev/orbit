# TESTING.md

Environment-specific verification for the clean Orbit rebuild.

## Verification Model

Orbit has two supported test lanes:

1. In-memory Pest tests for deterministic command, service, database, renderer,
   and contract coverage.
2. Ephemeral VM E2E tests for real SSH, provisioning, image, and host-mutation
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

## Ephemeral VM E2E

Use ephemeral E2E for full lifecycle, provisioning, destructive, or host
mutation checks against disposable VMs:

```bash
composer test:e2e
php artisan e2e:preflight
php artisan e2e:prepare-incus-images --role=blank --force
php artisan e2e:prepare-incus-images --role=control --force
php artisan e2e:prepare-incus-images --role=gateway --force
composer test:e2e:provisioning --filter='lifecycle'
composer test:e2e:provisioning --filter='control'
composer test:e2e:provisioning --filter='NodeNewGateway'
```

The first ephemeral E2E harness uses Incus VMs on the configured E2E host
(`beast` by default). Run
`php artisan e2e:prepare-incus-images --role=blank --force` to build or replace the reusable
`orbit-blank-ubuntu-26.04` image from the Ubuntu cloud image. The `composer test:e2e` command runs the full ephemeral E2E suite (provisioning
followed by features). The provisioning lane includes a blank VM lifecycle test
that launches one disposable VM from that blank image, injects an ephemeral SSH
key for the non-`orbit` bootstrap user, verifies SSH from the host into the VM,
and deletes the VM. The blank image intentionally does not use `orbit` as the
bootstrap user so gateway and app provisioning tests can prove Orbit creates or
prepares the node-side `orbit` user itself.

Run `php artisan e2e:prepare-incus-images --role=control --force` to build or replace the reusable
`orbit-ready-control` image from the blank image. That lane ships the current
Orbit source and `bin/install-orbit` into a disposable VM, installs the control
node prerequisites and CLI as the non-`orbit` control user, verifies
`orbit --version`, then publishes the ready image. Run `composer test:e2e:provisioning --filter='control'` to
launch from that image and verify the ready control node over SSH.

Run `php artisan e2e:prepare-incus-images --role=gateway --force` to build or replace the reusable
`orbit-ready-gateway` Incus image from the blank image. That lane ships the
current Orbit source and `bin/install-orbit` into a disposable VM, installs the
gateway node prerequisites and CLI as the `orbit` user, bootstraps gateway-local
identity and root CA via `orbit:internal:bootstrap-gateway-local`, verifies
`orbit --version`, then publishes the ready image.

Run `php artisan e2e:prepare-incus-images --role=devapp --force` to build or replace the reusable
`orbit-ready-devapp` Incus image from the blank image. That lane ships the
current Orbit source and `bin/install-orbit` into a disposable VM, installs the
development-app node prerequisites and CLI as the `orbit` user with
`--role=app`, verifies `orbit --version`, then publishes the ready image.

Run `php artisan e2e:prepare-incus-images --role=prodapp --force` to build or replace the reusable
`orbit-ready-prodapp` Incus image from the blank image. That lane ships the
current Orbit source and `bin/install-orbit` into a disposable VM, installs the
production-app node prerequisites and CLI as the `orbit` user with
`--role=app`, verifies `orbit --version`, then publishes the ready image.

Run `composer test:e2e:provisioning --filter='NodeNewGateway'` to exercise the first-gateway bootstrap path.
This lane launches one disposable VM from the ready control image and one from
the blank image, injects an ephemeral SSH key into both, runs
`orbit node:new gateway-1 --role=gateway --host=<gateway-ip> --ssh-user=<bootstrap-user> --control-name=control-1 --json`
from the control VM, and verifies that the control registry contains both nodes,
that the gateway has Orbit installed under the steady-state `orbit` user (not the
bootstrap user), and that `orbit --version` works on the gateway as the `orbit`
user. Verification uses `incus exec` to run `orbit --version` as the `orbit`
user; SSH access from the control VM to the gateway as `orbit` is not yet
verified because runtime-user SSH key distribution is not yet implemented.
The VMs are destroyed at the end unless `ORBIT_E2E_KEEP=1`.

Run `ORBIT_E2E=1 php artisan test --testsuite=E2E --filter='GatewayAdd'` to exercise control-node onboarding against a
prepared gateway VM. This lane launches one disposable VM from the ready control
image and one from the ready gateway image, injects an ephemeral SSH key into
both, configures a dummy network interface on the gateway VM with the expected
WireGuard IP (10.6.0.2), seeds the control node identity into the gateway
database, starts the gateway API server, and runs
`orbit gateway:add 10.6.0.2 --json` from the control VM. It verifies that the
command returns a success response, that `LocalGatewaySettings` is populated in
the control node database, and that the gateway CA is installed in the local OS
trust store. Full HTTPS verification and idempotent rerun convergence depend on
gateway web server/TLS infrastructure in the ephemeral harness; this lane is an
explicit bootstrap-partial gate. The VMs are destroyed at the end unless
`ORBIT_E2E_KEEP=1`.

These are backend and single-role E2E checks only; they do not yet validate a full
gateway/control/development-app/production-app topology.

Environment overrides:

```bash
ORBIT_E2E_HOST=beast
ORBIT_E2E_SOURCE_IMAGE=images:ubuntu/26.04/cloud
ORBIT_E2E_BLANK_IMAGE=orbit-blank-ubuntu-26.04
ORBIT_E2E_CONTROL_IMAGE=orbit-ready-control
ORBIT_E2E_GATEWAY_IMAGE=orbit-ready-gateway
ORBIT_E2E_BOOTSTRAP_USER=provisioner
ORBIT_E2E_CONTROL_USER=control
ORBIT_E2E_INSTANCE_PREFIX=orbit-e2e
ORBIT_E2E_TIMEOUT_SECONDS=600
ORBIT_E2E_KEEP=1
```

## E2E Lanes

The ephemeral E2E suite is split into two explicit lanes at the Pest group level:

- **`e2e-provisioning`** — tests that mutate disposable VMs from blank or ready
  images and exercise setup flows such as blank VM lifecycle, control node readiness,
  gateway onboarding, and node provisioning. These tests are grouped with
  `pest()->group('e2e-provisioning')` at the file level and run via
  `composer test:e2e:provisioning`.

- **`e2e-feature`** — tests that start from prepared topology clones and verify
  ported commands, forwarding chains, or read-only behavior. They must not run
  installer or provisioning commands unless the feature under test explicitly
  requires it. These tests are grouped with `pest()->group('e2e-feature')` at the
  file level and run via `composer test:e2e:features`.

Each prepared topology has its own contract group:

| Topology | Contract group | Feature group |
| --- | --- | --- |
| `control` | `e2e-topology-contract-control` | `e2e-feature-control` |
| `control-gateway` | `e2e-topology-contract-control-gateway` | `e2e-feature-control-gateway` |
| `control-gateway-dev` | `e2e-topology-contract-control-gateway-dev` | `e2e-feature-control-gateway-dev` |
| `control-gateway-dev-prod` | `e2e-topology-contract-control-gateway-dev-prod` | `e2e-feature-control-gateway-dev-prod` |

A feature lane must run the contract for the smallest topology it needs before
running that topology's feature assertions. If the relevant topology contract
fails, the feature lane stops before command assertions can produce misleading
results. Additional Pest arguments passed to a feature-lane Composer script are
applied only to the post-contract feature assertions. Add a Composer feature
script for a topology when the first feature test for that topology lands.

Both lanes still carry the umbrella `e2e` group via `tests/Pest.php`, so
`composer test:e2e` continues to run all ephemeral tests together through the
ordered lane scripts.

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
composer test:e2e:provisioning
composer test:e2e:topology-contract
composer test:e2e:features
composer test:e2e:features:control-gateway-dev-prod
composer test:e2e:features:parallel
composer test:e2e:features:docker

# Prepare or replace a topology clone for the feature lane
composer e2e:prepare-topology -- --force control-gateway-dev-prod

# Prepare Docker feature topology images
composer e2e:prepare-docker-runtime -- --force
composer e2e:prepare-docker-topology -- --force control-gateway-dev-prod

# Prepare Hcloud feature topology images
composer e2e:prepare-hcloud-images

# Reap stale Incus VMs and images created by E2E tests
composer e2e:reap-incus
composer e2e:reap-hcloud
composer e2e:reap-docker
composer e2e:reap-docker -- --force --older-than=0m
```

`composer test:e2e:features:parallel` is opt-in. It runs topology contracts first,
then runs feature assertions with Pest parallel mode and `--processes=3`. The
recommended Incus pool is `sidecar1,sidecar2`; the host pool chooses the first
host with prepared templates and enough free Orbit-owned VM slots, so multiple
workers can share a sidecar when capacity allows. Provisioning E2E remains
serial by default.

### Docker Feature Topologies

Docker is an optional fast provider for `e2e-feature` tests:

```bash
composer e2e:prepare-docker-runtime -- --force
composer e2e:prepare-docker-topology -- --force control-gateway-dev-prod
ORBIT_E2E_TOPOLOGY_PROVIDER=docker composer test:e2e:features:docker
```

The recommended local topology is to run Docker containers on `beast` and keep
Incus feature VMs on `sidecar1` and `sidecar2`. Docker remains explicit because
it does not exercise WireGuard or VM semantics.

Docker topologies are disposable containers seeded from per-role prepared
images. They are useful for fast command, registry, gateway API, and forwarding
assertions. They do not prove real SSH, sudo, OS trust-store mutation, systemd,
package installation, cloud-init, or VM networking behavior. Tests that need
those capabilities must call
`E2ETopologyFactory::fromEnvironment()->requireCapabilities(E2ETopologyCapabilities::vm())->require(...)`
so the provider pool refuses Docker for them.

`composer test:e2e:features:docker` is serial in the MVP. Parallel Docker E2E
is not supported while each test creates a fixed `10.6.0.0/16` bridge network;
parallel support needs non-overlapping subnets or the per-worker network design
from the Docker topology plan.

Tests must reach Docker topology services through topology handles such as
`$topology->control()->ssh(...)`, not by calling `https://10.6.0.x` directly
from the Pest process. On macOS, Docker Desktop runs containers inside a Linux
VM, so the bridge subnet is reachable from containers but not necessarily from
the developer host.

The Docker bridge uses subnet `10.6.0.0/16` to match seeded WireGuard
addresses. If you also run an Orbit VPN client locally on `10.6.0.x`, stop the
tunnel before running Docker E2E or Docker network creation will fail with a
subnet overlap error.

To offload Docker work to `beast`, keep Pest running locally and target the
remote Docker daemon:

```bash
ORBIT_E2E_TOPOLOGY_PROVIDER=docker ORBIT_E2E_DOCKER_HOSTS=beast composer test:e2e:features:docker
```

The local machine only needs the Docker CLI and SSH access; the prepared Docker
images must exist on `beast`. The Docker provider checks `docker image inspect`
against the remote daemon because `DOCKER_HOST=ssh://...` forwards every CLI
call.

If Docker resources accumulate from interrupted runs, prefer the reaper:

```bash
composer e2e:reap-docker
composer e2e:reap-docker -- --force --older-than=0m
```

## Environment

```bash
ORBIT_E2E=1                           # Enable ephemeral E2E tests
ORBIT_E2E_PROVIDER=incus              # Backend provider (incus is current)
ORBIT_E2E_PROVIDERS=incus             # Ordered provisioning provider pool
ORBIT_E2E_TOPOLOGY_PROVIDER=incus     # Prepared topology provider
ORBIT_E2E_TOPOLOGY_PROVIDERS=incus    # Ordered prepared topology provider pool
ORBIT_E2E_DOCKER_HOSTS=beast          # Recommended Docker daemon pool for Docker topology provider
ORBIT_E2E_DOCKER_MAX_CONTAINERS_PER_HOST=8  # Docker topology capacity per daemon
ORBIT_E2E_INCUS_HOSTS=sidecar1,sidecar2  # Recommended Incus host pool for VM-backed feature E2E
ORBIT_E2E_INCUS_MAX_VMS_PER_HOST=4    # VM quota per host
ORBIT_E2E_TOPOLOGY_STRATEGY=minimal   # Topology selection strategy
ORBIT_E2E_TOPOLOGY_RESET=fresh-clone  # Reset strategy for topology clones
ORBIT_E2E_TIMINGS=1                   # Print phase timings to STDERR (acquire / reset)
ORBIT_E2E_CPUS=2                      # vCPUs for image-prep / provisioning VMs
ORBIT_E2E_MEMORY=2GiB                 # Memory for image-prep / provisioning VMs
ORBIT_E2E_TOPOLOGY_CPUS=1             # vCPUs for disposable topology clones
ORBIT_E2E_TOPOLOGY_MEMORY=2GiB        # Memory for disposable topology clones
```

`ORBIT_E2E_PROVIDER` and `ORBIT_E2E_PROVIDERS` choose provisioning providers.
`ORBIT_E2E_TOPOLOGY_PROVIDER` and `ORBIT_E2E_TOPOLOGY_PROVIDERS` choose
prepared topology providers for `e2e-feature` tests. Keep provisioning provider
selection VM-backed when a test proves machine setup, SSH, sudo, package
installation, trust-store mutation, or system services.

`ORBIT_E2E_TOPOLOGY_PROVIDER=auto` currently expands to `incus`, not Docker.
Docker can be selected explicitly with `ORBIT_E2E_TOPOLOGY_PROVIDER=docker`;
only add Docker to `auto` after measured timing data proves it should become
part of the default feature-lane pool.

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
ORBIT_E2E_INCUS_HOSTS=sidecar1,sidecar2
ORBIT_E2E_INCUS_MAX_VMS_PER_HOST=4
```

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

Unknown values continue to fall back to `fresh-clone`.

Set `ORBIT_E2E_TIMINGS=1` to surface per-phase durations from the topology
factory and lease. Current event names include `availability`,
`batch.copy-start`, `agent-ready.<role>`, `ssh-authorize.<role>`,
`ssh-ready.<role>`, `snapshot.<role>`, `wireguard`, `cleanup.<role>`, and
`reset.*`. Output goes to STDERR with the prefix `[orbit-e2e]` so it interleaves
cleanly with Pest output. The clone/start batch intentionally stays one remote
SSH operation; split copy/start timing should only be added if it can keep that
single remote operation.

All E2E orchestration now runs via Pest groups and `php artisan e2e:*` commands.
