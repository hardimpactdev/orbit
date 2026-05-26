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
operator, or app nodes as verification targets.

For the MONO local-executor migration, prepared Docker/Incus E2E is the primary
verification path. Standing live infrastructure is diagnostic only. Prepared
topology images provide Orbit-capable hosts, including the host PHP CLI needed
by the CLI/local-executor artifact, while the current checkout is synced into
hosts during topology preparation or test setup.

## In-Memory Pest

Use in-memory Pest tests for ordinary development:

```bash
composer test
```

This lane must not require real SSH, hcloud, Incus mutation, or standing
infrastructure. Fake process, gateway, provider, and transport boundaries in
Pest when the behavior is a command or contract concern.

## Ephemeral E2E

Use `composer test:e2e` for the prepared-topology feature aggregate. It runs
the selected Docker-backed and Incus-backed feature lanes through
`php artisan e2e:test`. The default lane set is `docker,incus`; override it
with `ORBIT_E2E_LANES=docker`, `ORBIT_E2E_LANES=incus`, or
`ORBIT_E2E_LANES=all`. Provisioning tests are not part of this aggregate:

```bash
composer test:e2e
composer test:e2e:docker
composer test:e2e:incus
composer test:e2e:topology-contract
```

Use VM-backed E2E only when the behavior depends on real provisioning,
WireGuard, VM networking, OS trust-store mutation, systemd, package
installation, cloud-init, or host-level daemon behavior:

```bash
composer e2e:preflight
php artisan e2e:prepare-incus-images --role=blank --force
composer e2e:prepare-topology -- --force operator_gateway_app-dev_app-prod_agent
composer test:e2e:provision
```

Use `composer test:e2e:provision` only when topology, installer, image,
`node:new`, WireGuard provisioning, or other VM setup behavior changes. Ordinary
command ports should add prepared-topology feature tests instead.

The MONO migration uses domain E2E gates 387, 390, and 394 for already-existing
command paths: workspace adapter migration, wg-easy support migration, and the
Docker E2E unblock gate. The SMOKE/E2E gate convention still applies to newly
ported commands.

The VM E2E harness uses Incus VMs on the configured E2E host (`beast` by
default). It builds one reusable blank image plus prepared source snapshots:

1. **Blank image** (`orbit-blank-ubuntu-26.04`). Built once via
   `php artisan e2e:prepare-incus-images --role=blank --force`. Ubuntu cloud
   + bootstrap user + sshd. Used by the provisioning lane's blank-VM
   lifecycle test and as the source for prepared topology roles.
2. **Prepared source templates** (`orbit-template-control`,
   `orbit-template-gateway`, `orbit-template-dev`, `orbit-template-prod`, and
   `orbit-template-agent`). Built by
   `composer e2e:prepare-topology -- --force operator_gateway_app-dev_app-prod_agent`.
   The command tars the current checkout, ships it plus `bin/install-orbit` and
   `bin/e2e-provision-node` to the host, installs Orbit on the operator
   template from the blank image, snapshots `clean-operator`, then starts that
   template and provisions the gateway through real `node:new`. After the
   gateway is seeded, app-dev, app-prod, and agent are provisioned in parallel
   before the five-role source snapshot is taken. App-dev carries database and
   Redis state by default; app-prod carries the ingress role by default.

For Incus, a topology kind is the requested active node set for a test, not a
separate prepared source to build. Gateway-backed role subsets such as
`operator_gateway_agent` and `operator_gateway_app-prod_ingress` use the
`operator_gateway_app-dev_app-prod_agent` source snapshots. At acquisition time
the harness clones and boots only the requested roles from that source, retargets
WireGuard, and prunes gateway registry rows for roles that are not part of the
requested topology. For example, `operator_gateway_agent` boots operator,
gateway, and agent only; app-dev and app-prod stay off.

The Incus template name `orbit-template-control` is a legacy fixture name for
the operator node template. New Docker artifacts use `operator`; Incus keeps
the legacy template name until those prepared templates are rebuilt and migrated
without breaking existing hosts.

Source code lives in the per-run bundle, not in the blank image. Forced
topology preparation resumes from the highest complete canonical prerequisite
snapshot set and rebuilds only the later roles. For example, after a failed
`operator_gateway_app-dev_app-prod_agent` build, a complete
`operator_gateway_app-dev` snapshot lets the next run restore
`orbit-template-{control,gateway,dev}` and continue with prod and agent instead
of rebuilding from blank. Delete the shared `orbit-template-*` instances before
`--force` only when you intentionally need a fully cold rebuild from the
current checkout. Rebuild the blank image only when the bootstrap image shape
changes.

The provisioning bundle also stages host-local `orbit-runtime:current`,
`caddy:2-alpine`, and `4km3/dnsmasq:latest` Docker image archives when those
images exist on the Incus host. `bin/install-orbit` loads those archives before
falling back to Docker Hub and marks archive-seeded installs with
`ORBIT_FORWARD_INSTALL_IMAGE_ARCHIVES=1` so `node:new` can forward the same
local runtime images to freshly provisioned gateway and app nodes. This keeps
provisioning benchmarks independent from Docker Hub rate limits without mutating
the shared blank image or default topology snapshots. Forwarded archives and
source bundles are staged under `/var/tmp` rather than `/tmp`; Ubuntu cloud VMs
often mount `/tmp` as a small tmpfs that cannot hold Docker image archives.

Every machine that runs the Incus lane must be able to reach the configured
Incus host with ordinary non-interactive SSH and SCP. The harness runs Incus
commands over `ssh beast ...` and copies the current checkout archive with
`scp ... beast:/tmp/...`; both paths intentionally use the same SSH host alias
and may use keys from the local SSH agent. If `ssh -o BatchMode=yes beast true`
works but checkout copy fails with `Permission denied (publickey)`, check for
overly strict local SSH options before changing E2E lane selection.

Latest Beast prepared-topology measurement (May 21, 2026):

- Full `operator_gateway_app-dev_app-prod_agent` rebuild completed in
  `real 607.63s`. This is an explicit preparation/provisioning command and is
  not part of `composer test:e2e`.
- The rebuild used `ORBIT_E2E_INCUS_STORAGE_POOL=orbit-e2e`; all
  `orbit-template-*` instances must have root disk `pool: orbit-e2e` on Beast.
  If templates are on `default` while feature clones request `orbit-e2e`, Incus
  falls back to slow cross-pool copies and `batch.copy-start` regresses from
  about 2s to about 100s per worker.
- After the rebuild, `composer test:e2e:incus` passed with 10 tests /
  85 assertions in `real 100.50s`, with two cached five-node workers and
  `batch.copy-start` around 2s per worker.

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

Forced topology preparation prints live phase checkpoints to STDERR with the
`[orbit-e2e]` prefix. Each measured phase emits `started`, then `done <seconds>`
or `failed <seconds> <exception>`. This keeps JSON responses on STDOUT
parseable while still showing whether a long run is in bundle staging,
operator install, `node:new`, gateway API readiness, snapshotting, or cleanup.

Environment overrides:

```bash
ORBIT_E2E_HOST=beast
ORBIT_E2E_INCUS_IMAGE_BUILD_HOST=beast
ORBIT_E2E_SOURCE_IMAGE=images:ubuntu/26.04/cloud
ORBIT_E2E_BLANK_IMAGE=orbit-blank-ubuntu-26.04
ORBIT_E2E_BOOTSTRAP_USER=provisioner
ORBIT_E2E_OPERATOR_USER=orbit
ORBIT_E2E_CONTROL_USER=orbit # Legacy alias for older scripts.
ORBIT_E2E_INSTANCE_PREFIX=orbit-e2e
ORBIT_E2E_TIMEOUT_SECONDS=600
ORBIT_E2E_KEEP=1
```

## Provider Capability Matrix

| Capability | Docker | Incus |
| --- | --- | --- |
| Gateway API and CA trust | yes | yes |
| Registry-backed command behavior | yes | yes |
| Docker process runtime backend | yes | yes |
| Explicit Supervisor residual runtime | conditional | yes |
| Orbit Scheduler daemon | yes | yes |
| Real WireGuard interfaces and peer routing | no | yes |
| VM boot, cloud-init, package install mutation | no | yes |
| Real SSH daemon and sudo behavior | no | yes |
| OS trust-store mutation | no | yes |
| Host init (systemd) on the node itself | no | yes |

### Provider Selection Rules

Use Docker for feature tests whose correctness depends on gateway API, CA
trust, registry state, command forwarding, current-checkout command behavior,
the Docker process runtime backend, the Orbit Scheduler in `orbit-runtime`, or
Orbit-managed process and schedule lifecycle. This is the default for
prepared-topology `e2e-feature` tests.

Use Incus for tests whose correctness depends on real VM behavior:
WireGuard kernel networking, cloud-init, package installation, OS
trust-store mutation, real SSH daemon behavior, sudo prompts, or host
init. Mark these tests with `e2e-provider-incus` so Docker-only runs skip them
without probing an unsuitable provider.

Provisioning, installer, and host-mutation tests stay in the
`e2e-provision` lane on Incus regardless of family.

### Lane Examples

| Test concern | Lane | Why |
| --- | --- | --- |
| CLI validation, JSON envelopes, renderers, DTOs, authorization branches | In-memory Pest | Deterministic contract behavior; fake external boundaries. |
| Gateway API forwarding, CA trust, registry reads/writes, Saloon request/response paths | Docker feature | Prepared topology is enough; fast and parallelizable. |
| Docker-backed tool intent and compose command generation (`redis`, `postgres`, `mailpit`, etc.) | Docker feature | The command contract is gateway intent plus generated Docker command; fake or controlled Docker CLI is acceptable unless real Docker daemon behavior is the subject. |
| Runtime backend, process lifecycle, scheduler tick/heartbeat | Docker feature | Docker topologies run `orbit-runtime`, Docker process runtime containers, and the Orbit Scheduler without host PHP/PHP-FPM fallback. |
| Host-init service control (`systemctl`, journalctl for systemd units, OS service reloads) | Incus VM-feature | Requires real systemd/host init semantics. |
| OS package installs/upgrades, trust-store mutation, sudo behavior, real SSH daemon behavior | Incus VM-feature | Depends on VM/OS behavior Docker does not model. |
| Blank image, installer, topology preparation, `node:new`, WireGuard peer routing | Incus provision | Mutates disposable VMs and proves production-style provisioning. |

When in doubt, start with Docker feature. Move to Incus only when the assertion
would be false confidence in Docker because the kernel, VM boot, host init, or
OS package/trust layer is the behavior under test.

## E2E Lanes

The ephemeral E2E suite is split into two explicit lanes at the Pest group level:

- **`e2e-provision`** — opt-in tests that mutate disposable VMs from blank
  images and exercise setup flows such as blank VM lifecycle, operator node
  readiness, gateway onboarding, and node provisioning. These tests are
  grouped with `pest()->group('e2e-provision')` at the file level and run
  via `composer test:e2e:provision`. Tests that previously launched from
  role-specific ready images now use blank VMs and run the same installer /
  `node:new` paths that production provisioning uses.

- **`e2e-feature`** — tests that start from prepared topology clones and verify
  ported commands, forwarding chains, or read-only behavior. They must not run
  installer or provisioning commands unless the feature under test explicitly
  requires it. These tests are grouped with `pest()->group('e2e-feature')` at the
  file level. Run Docker-eligible feature tests with `composer test:e2e:docker`;
  run Incus-only feature tests with `composer test:e2e:incus`. The aggregate
  `composer test:e2e` runs both prepared-topology feature lanes. Incus-only
  feature tests add `e2e-provider-incus`.

Each prepared topology has its own contract group:

| Topology | Contract group | Feature group |
| --- | --- | --- |
| `operator` | `e2e-topology-contract-operator` | `e2e-feature-operator` |
| `operator_gateway` | `e2e-topology-contract-operator_gateway` | `e2e-feature-operator_gateway` |
| `operator_gateway_app-dev` | `e2e-topology-contract-operator_gateway_app-dev` | `e2e-feature-operator_gateway_app-dev` |
| `operator_gateway_app-dev_app-prod` | `e2e-topology-contract-operator_gateway_app-dev_app-prod` | `e2e-feature-operator_gateway_app-dev_app-prod` |
| `operator_gateway_agent` | `e2e-topology-contract-operator_gateway_agent` | `e2e-feature-operator_gateway_agent` |
| `operator_gateway_app-prod_ingress` | `e2e-topology-contract-operator_gateway_app-prod_ingress` | `e2e-feature-operator_gateway_app-prod_ingress` |

`composer test:e2e:topology-contract` proves the Docker
`operator_gateway_app-dev_app-prod` topology contract. It exists as a quick
topology health check, while `composer test:e2e` excludes topology-contract
tests and runs feature assertions only.

Both lanes still carry the umbrella `e2e` group via `tests/Pest.php`, but
`composer test:e2e` runs only feature assertions. It delegates to
`php artisan e2e:test`, which plans one or both prepared-topology lanes from
`ORBIT_E2E_LANES` and runs selected lanes concurrently by default. Use
`--sequential-lanes` for local debugging when interleaved output is hard to
read. Use `--sequential-tests` when a worktree run should execute the selected
Pest files in one process instead of Pest parallel mode.

`composer test:e2e:docker` selects the Docker lane: all `e2e-feature` tests
except `e2e-provider-incus` and topology-contract groups, using Docker, Pest
parallel mode, and cached requested Docker topologies per worker.

Use `composer test:e2e:docker:canary` to run the representative Docker canary
subset tagged `e2e-feature-canary`.

`composer test:e2e:incus` selects the Incus lane: only `e2e-provider-incus`
feature tests, using Incus prepared topology clones and a process-local topology
cache per Pest worker. If Incus or the required prepared topology is
unavailable, those tests mark themselves skipped.

Provision tests are intentionally on demand because they run real
installer/provisioning paths and are much slower than prepared-topology feature
tests. They run with Pest parallel mode by default, limited by
`ORBIT_E2E_PROVISION_PARALLEL_PROCESSES`, and each worker must acquire an Incus
slot from `ORBIT_E2E_INCUS_HOST_SLOTS` before it creates a disposable VM.

Provision tests clean up on success. On failure they keep tracked VMs/templates
for inspection and print their names plus a reap command. Set
`ORBIT_E2E_KEEP_ON_FAILURE=0` to restore cleanup-on-failure behavior.

Live or standing infrastructure verification lanes are sunset. Do not use
persistent gateway, operator, or app nodes as verification targets.

## Topology Kinds

The `e2e-feature` lane uses prepared topology clones. Choose the smallest topology
that covers the behavior under test:

Legacy `control-*` aliases remain accepted for existing topology kinds. New
topology kinds use the canonical `operator*` spelling.

For Incus, these names describe the roles that should be booted for a test. They
do not mean each kind has a separate Incus source build. Gateway-backed subsets
use the prepared five-role source and start only the listed nodes.

| Kind | Nodes | Use when |
| --- | --- | --- |
| `operator` | 1 operator node | Fastest. Use for operator-side commands. |
| `operator_gateway` | operator + 1 gateway | Use for gateway trust, onboarding, or node-registry flows. |
| `operator_gateway_app-dev` | operator + gateway + 1 dev app | Use for app or workspace commands that need a development app node. |
| `operator_gateway_app-dev_app-prod` | operator + gateway + dev + 1 prod app | Full app topology. Use for production-app flows or full-stack verification. |
| `operator_gateway_agent` | operator + gateway + 1 agent | Use for agent-node assertions that do not need app-dev or app-prod nodes. |
| `operator_gateway_app-prod_ingress` | operator + gateway + 1 prod app carrying ingress | Use for public production ingress and private app-production backend flows that do not need dev or agent nodes. |

## Feature Checkout Overlay

Prepared topology images and templates are branch-agnostic topology baselines.
They prove OS, users, SSH, Docker, `orbit-runtime`, `orbit-caddy`, service
containers, trust, routes, host PHP CLI for the CLI/local-executor artifact,
and baseline Orbit installation state. Docker-first topology images
intentionally omit host Composer, host Caddy, PHP-FPM, and host Supervisor for
PHP app processes. They must not be rebuilt just to carry feature-branch code
for a command port.

Feature assertions must run the checkout under test inside the disposable clone.
For worktree-based development, this means the worker's current worktree is the
source of truth. The test installs or overlays that checkout into a disposable
path on the clone and invokes commands through the host `orbit` launcher, which
launches the role-appropriate Orbit artifact and passes `ORBIT_HOST_CWD` when
local path context is needed. The clone's installed `orbit` CLI remains a
topology/bootstrap smoke target unless the test is explicitly about installed
CLI behavior.

Use the shared E2E checkout helper when a feature test needs the current
checkout on a clone. Do not mutate template instances, reusable images, or the
steady-state `orbit` symlink to make a feature assertion see new code.

The Pest-facing helpers in `tests/E2E/Support/Pest.php` wrap the lower-level
topology lease API:

```php
$topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdevAppprod)
    ->withCurrentCheckout(roles: ['operator', 'gateway', 'dev', 'prod']);

try {
    $topology->ssh(
        'operator',
        "cd {$topology->checkout('operator')} && orbit node:list --json",
    );
} finally {
    $topology->cleanup();
}
```

Use `roles: ['operator']` when only the operator-side command under test needs
the branch checkout. Add `gateway`, `dev`, or `prod` when the branch changes
code that runs on those nodes. `control` remains accepted by the harness as a
legacy alias for the operator node while older tests are migrated.

When `ORBIT_E2E_TOPOLOGY_CACHE=process`, `e2eTopology()` reuses the same
prepared topology lease for matching requests in the current PHP process and
cleans it up once at process shutdown. `composer test:e2e` combines this with
Pest parallel mode and `ORBIT_E2E_CHECKOUT_CACHE=process`, which installs the
branch checkout once per node/user and gives each test an isolated hardlink copy
with fresh runtime files. The checkout archive itself is cached across Pest
workers under a git-tree hash, so only one worker should pay the local tar/gzip
cost for an unchanged checkout. Because the current aggregate includes
gateway-backed `node:list` tests, it also sets `ORBIT_E2E_GATEWAY_API=1` and
starts the gateway API once per worker.

Tests request the smallest topology kind that covers the behavior under test.
The Docker provider starts the requested gateway-backed roles from the prepared
`operator_gateway_app-dev_app-prod_agent` role images, prunes gateway registry
rows for roles that were not requested, and primes the gateway API for the
active container addresses. Docker does not run Composer for each requested
topology; role images already carry the prepared checkout with vendor
dependencies, and the per-test checkout overlay only falls back to Composer when
the prepared dependency tree is missing or incompatible with the checkout under
test.

The Incus provider also honors the requested smallest topology kind, but it does
not launch blank downstream VMs during feature acquisition. It clones only the
selected roles from the prepared
`operator_gateway_app-dev_app-prod_agent` snapshots, starts those VMs, retargets
WireGuard, and prunes stale gateway registry rows for roles that were not
booted. App-dev carries database/Redis registry state by default, and app-prod
carries the ingress role on the prod node by default. The process topology cache
then reuses the requested topology for later tests that request the same kind.

Lane planning does not sort tests from smallest topology to largest topology.
The generated Pest test directories are ordered by topology weight and
round-robin those weight buckets, so Docker and Incus workers start with a mix
of large, medium, and small topology files. When one topology size is exhausted,
the remaining files continue filling the configured worker pool. Worker counts
remain capacity-safe for the largest topology selected by that lane invocation;
if a canary or filtered run excludes large topology files, the planner sizes the
lane from the smaller selected set.

During Incus preparation, the gateway remains the dependency barrier: after the
operator and gateway are seeded, app-dev, app-prod, and agent are provisioned
with parallel `node:new` commands before the five role snapshots are taken.
Composer lanes set `ORBIT_E2E_TOPOLOGY_CACHE_LIMIT=1` by default so a worker does
not hold one requested topology while blocking on capacity for the next topology
kind. Consecutive same-kind tests still reuse the requested topology; switching
kinds evicts the previous lease first. Set
`ORBIT_E2E_TOPOLOGY_CACHE_LIMIT=<n>` only for diagnostics on hosts with enough
spare capacity for multiple cached topology kinds per worker.

The prepared topology lane supports the current composable role set:
`operator`, `operator_gateway`, `operator_gateway_app-dev`,
`operator_gateway_app-dev_app-prod`, `operator_gateway_agent`,
`operator_gateway_app-dev_app-prod_agent`, and
`operator_gateway_app-prod_ingress`. Legacy app-dev ingress and unimplemented
service scaffold topology kinds are not part of the prepared feature lane;
prepare commands reject them.

Prepared topology artifacts use an explicit namespace by default. Docker
topology images are tagged with `prepared-...`, the Docker topology runtime
images are `orbit-e2e-topology-runtime:prepared-current` and
`orbit-runtime:prepared-current`, and Incus template snapshots use
`clean-prepared-...` on `orbit-template-prepared-*` template instances. Set
`ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE=<slug>` to isolate a branch, benchmark,
or worktree run without colliding with another prepared run. Keep the shared
`prepared` namespace for normal branch work: ordinary Orbit CLI and gateway app
changes are synced or refreshed inside prepared nodes and do not require custom
images. Use a custom namespace only when the worktree changes the prepared
artifact shape itself, such as base system packages, Dockerfiles, blank VM
bootstrap, or a new dedicated role image. After that work is merged, rebuild the
shared `prepared` artifacts and stop using the worktree namespace.

Required prepared sources for the feature lanes:

- `operator` for operator-only tests.
- Docker role images from `operator_gateway_app-dev_app-prod_agent`: operator,
  gateway, app-dev, app-prod, and agent. App-dev includes the database role;
  app-prod includes the ingress role.
- Docker runtime support images: `orbit-runtime:<namespace>-current`,
  `orbit-e2e-topology-runtime:<namespace>-current`, and `caddy:2-alpine`.
- `operator_gateway_app-dev_app-prod_agent` Incus role snapshots for selective
  VM boot.

Prepare the default sources with:

```bash
composer e2e:prepare-docker-hosts -- --force operator_gateway_app-dev_app-prod_agent

composer e2e:prepare-topology -- --force operator_gateway_app-dev_app-prod_agent
```

Docker topology bakes install Composer dependencies inside transient runtime
containers. By default those containers share a stable Docker volume keyed by
the current lockfiles for `/tmp/orbit-composer-cache`, so repeated builds with
the same dependencies reuse package cache state without baking that cache into
the final topology images. Set
`ORBIT_E2E_DOCKER_COMPOSER_CACHE=<path-on-build-host>` to bind an existing
Composer cache from the Docker image build host instead. With
`DOCKER_HOST=ssh://beast`, that path is resolved on `beast`, not on the local
worktree machine. If the build container cannot reach GitHub reliably but the
host cache is already warm, set
`ORBIT_E2E_DOCKER_COMPOSER_CACHE_READ_ONLY=1` so Composer consumes the mounted
cache without trying to update source mirrors from inside the topology network.

The Docker lane sizes its worker pool from the largest topology actually
selected for the lane. Docker runners are configured with
`ORBIT_E2E_DOCKER_TEST_RUNNERS=host:slots:containers,...`. If a runner's
container cap cannot fit every configured slot, the lane lowers the effective
runner slot count for that run instead of mutating the local `.env.e2e` file.
When no explicit process override is set, Pest worker count is derived from the
effective runner slots. With `sidecar1:4:28,sidecar2:4:28`, the Docker canary
keeps eight workers when its largest selected topology reserves five containers
per worker. Adding `sidecar3:4:28` derives twelve workers. An app-production
ingress test starts operator, gateway, and prod only, so it reserves four
containers per worker.

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
- Docker Engine/CLI is available to the host launcher and runtime managers;
- host PHP CLI and PDO SQLite are available for the CLI/local-executor
  artifact;
- host Composer, host Caddy, PHP-FPM, and host Supervisor for PHP app
  processes are absent from Docker-first topology images;
- Orbit runtime containers use sibling containers through the host Docker
  socket. Docker E2E must not use Docker-in-Docker.
- tests may mutate clones, but must never mutate template instances;
- cleanup deletes clones unless `ORBIT_E2E_KEEP=1`;
- reset returns clones to the clean prepared state for the selected reset mode.

`operator`:

- one operator node clone is available through the `operator` topology
  handle;
- the operator user is `ORBIT_E2E_OPERATOR_USER` (`orbit` by default, falling
  back to `ORBIT_E2E_CONTROL_USER` for older environments);
- baseline Orbit is installed at `/home/orbit/orbit` for the default user;
- commands can run from the operator node through the `orbit` CLI;
- `control` remains a legacy topology handle alias for this same operator
  client while older tests and artifacts are migrated.

`operator_gateway`:

- includes everything from `operator`;
- one gateway clone is available through the `gateway` topology handle;
- the gateway steady-state user is `orbit`, with Orbit installed at
  `/home/orbit/orbit`;
- the gateway identity visible to operator-side commands is named `gateway`;
- the operator identity seeded on the gateway uses WireGuard address
  `10.6.0.3`;
- WireGuard test addresses are stable: gateway `10.6.0.2`, operator
  `10.6.0.3`;
- the operator node can reach the gateway API over the synthetic WireGuard
  route;
- `gateway:add 10.6.0.2 --json` has converged on the operator node;
- the operator node stores gateway settings and trusts the gateway CA;
- the gateway API exposes `/api/ca/root` over HTTP and `/api/me` over HTTPS.
- the gateway API is served by gateway `orbit-caddy` forwarding to gateway
  `orbit-runtime` on the node Docker network.

`operator_gateway_app-dev`:

- includes everything from `operator_gateway`;
- one development app clone is available through the `devApp()` topology handle;
- the development app node is named `app-dev-1`;
- the gateway registry stores it as role `app`, environment `development`,
  TLD `test`, user `orbit`, and gateway endpoint `10.6.0.2`;
- the development app receives a WireGuard address in the prepared topology and
  is reachable through the gateway path required by app/workspace commands;
- development TLD state exists for `test` and points at the development app's
  WireGuard address.

`operator_gateway_app-dev_app-prod`:

- includes everything from `operator_gateway_app-dev`;
- one production app clone is available through the `prodApp()` topology handle;
- the production app node is named `app-prod-1`;
- the gateway registry stores it as role `app`, environment `production`, no
  development TLD, user `orbit`, and gateway endpoint `10.6.0.2`;
- production-app assertions can run without re-provisioning the operator,
  gateway, or development app nodes.
- production app runtime assertions use FrankenPHP app containers and Docker
  process runtime units behind the private `orbit-caddy` backend listener.

`operator_gateway_agent`:

- includes everything from `operator_gateway`;
- skips development and production app clones;
- one agent clone is available through the `agent()` topology handle;
- the agent node is named `agent-1`;
- the gateway registry stores it with the active `agent` role, TLD `agent`,
  user `orbit`, and gateway endpoint `10.6.0.2`.

`operator_gateway_app-prod_ingress`:

- includes everything from `operator_gateway`;
- skips development and agent clones;
- one production app clone is available through the `prodApp()` topology handle;
- the `ingress()` handle aliases the prod clone because the app-prod node
  carries the ingress role;
- the production app and ingress node name is `app-prod-1`;
- WireGuard test addresses are stable: the production app and colocated ingress
  use `10.6.0.5`;
- public production HTTP assertions preserve the path
  `ingress -> router -> backend`; the backend is the private `orbit-caddy`
  listener that forwards to the app FrankenPHP container.

### Hosted Service Expectations

Prepared E2E topologies keep hosted service assertions on the owning app node:

- app-dev carries database and Redis registry state by default;
- app-prod carries the ingress role by default;
- hosted service assertions must not add host PHP, host Composer, host Caddy,
  PHP-FPM, or host Supervisor to make those services pass.

## Commands

```bash
# Default in-memory Pest (excludes E2E)
composer test

# Ephemeral E2E lanes (set ORBIT_E2E=1 internally)
composer test:e2e
composer test:e2e:docker
composer test:e2e:incus
composer test:e2e:provision
composer test:e2e:topology-contract

# E2E readiness check
composer e2e:preflight

# Build the reusable Incus blank image (bootstrap user + sshd, no Orbit source).
php artisan e2e:prepare-incus-images --role=blank --force

# Prepare or replace the Incus source templates for the feature lane. Tars the
# current checkout, installs Orbit on the operator template, provisions gateway,
# then provisions app-dev, app-prod, and agent through node:new before taking
# the five-role source snapshots used by smaller requested topologies.
composer e2e:prepare-topology -- --force operator_gateway_app-dev_app-prod_agent

# Prepare Docker feature topology images on the current Docker daemon
composer e2e:prepare-docker-runtime -- --force
composer e2e:prepare-docker-topology -- --force operator_gateway_app-dev_app-prod_agent

# Ensure Docker feature topology images on the build host, then sync to runners
ORBIT_E2E_DOCKER_TEST_RUNNERS=sidecar1:4:28,sidecar2:4:28 \
ORBIT_E2E_DOCKER_IMAGE_BUILD_HOSTS=beast \
composer e2e:prepare-docker-hosts -- --force operator_gateway_app-dev_app-prod_agent

# Reap stale E2E resources
composer e2e:reap-incus
composer e2e:reap-hcloud
composer e2e:reap-docker
composer e2e:reap-docker -- --force --older-than=0m
```

`composer test:e2e` is the complete prepared-topology feature regression lane.
It runs `php artisan e2e:test`, which selects lanes from `ORBIT_E2E_LANES`
(`docker,incus` by default) and excludes `e2e-provision`. Docker and Incus
lanes run concurrently by default; pass `--sequential-lanes` when you need
single-lane-at-a-time debugging output.

When `composer test:e2e`, `composer test:e2e:docker`, or
`composer test:e2e:incus` receives `SIGINT` or `SIGTERM`, `php artisan
e2e:test` stops the active lane process, removes generated local test artifacts,
and runs the selected lane reapers with `--force --older-than=0m` before
exiting with the conventional signal code (`130` for Ctrl-C). If a run is killed
with `SIGKILL` or the host dies, run the reaper commands manually.

`composer test:e2e:docker` is the fast feature lane. It runs all Docker-eligible
`e2e-feature` tests in parallel against cached requested Docker topologies.
At live run start, the lane probes each configured Docker test runner with
`docker info` and removes unreachable runners from that run's effective
`ORBIT_E2E_DOCKER_TEST_RUNNERS` value before Pest workers are started. This lets
optional runners such as a laptop on Ethernet be listed in `.env.e2e`; when the
host is offline, Orbit logs that the runner was ignored and uses the remaining
reachable capacity.

`composer test:e2e:incus` runs only `e2e-provider-incus` feature tests against
Incus prepared topology clones. It uses `ORBIT_E2E_TOPOLOGY_CACHE=process`, so
each Pest worker reuses matching topology requests assigned to that
worker. The lane caps Pest workers by the largest selected Incus topology kind
and `ORBIT_E2E_INCUS_HOST_VM_CAPS`, so prepared VM leases cannot exhaust
Beast. Each disposable topology VM is capped at 1 vCPU through
`ORBIT_E2E_TOPOLOGY_CPUS=1`. If wall time moves back toward several minutes,
first check stale `orbit-e2e-*` VMs and the prepared template storage pool before
moving tests into or out of the lane.

Use `composer test:e2e:topology-contract` when you want to prove the prepared
Docker topology itself. Provisioning E2E leases Incus slots and remains
intentionally on demand because it exercises real machine setup.

### Docker Feature Topologies

Docker is the default provider for Docker-eligible feature tests. Once
`.env.e2e` points at the standing Docker host pool and the runtime/topology
images have been imported onto those hosts, the Docker-only lane is:

```bash
composer test:e2e:docker
```

`composer test:e2e:docker` and the Docker lane of `composer test:e2e` do not
rebuild Docker images. On a fresh host pool, after Dockerfile or system
dependency changes, or when remote images look stale, first rebuild on Beast and
refresh each configured Docker host:

```bash
ORBIT_E2E_DOCKER_TEST_RUNNERS=sidecar1:4:28,sidecar2:4:28 \
ORBIT_E2E_DOCKER_IMAGE_BUILD_HOSTS=beast \
composer e2e:prepare-docker-hosts -- --force operator_gateway_app-dev_app-prod_agent
```

For single-host local debugging, the lower-level equivalents are:

```bash
composer e2e:prepare-docker-runtime -- --force
composer e2e:prepare-docker-topology -- --force operator_gateway_app-dev_app-prod_agent
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
`sidecar2`. Incus runs on `beast` only. Beast may also be added to the Docker
runner pool as overflow capacity, as long as it is also listed in
`ORBIT_E2E_EXCLUSIVE_HOSTS`. That setting makes the shared lease pool treat
Beast as cross-backend exclusive: Docker workers may lease Beast only when no
Incus worker currently holds a Beast lease, and Incus workers may lease Beast
only when no Docker worker currently holds a Beast lease. When Beast is used as
Docker overflow, list it after the sidecars, for example
`ORBIT_E2E_DOCKER_TEST_RUNNERS=sidecar1:4:28,sidecar2:4:28,beast:2:56`, so the
Docker lane consumes sidecar capacity before attempting Beast. This is active
lease exclusion, not queue priority; a queued Incus worker that has not yet
acquired a Beast lease does not preempt an already-running Docker worker.
Docker exercises gateway API, certificate, and registry behavior over isolated
Docker bridge networks from the `10.90.N.0/24` pool, while DNS-alias mode
preserves canonical `10.6.0.x` WireGuard identities inside seeded gateway state.
Docker does not exercise real WireGuard interfaces, peer routing, VM boot, or
systemd.

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

The Docker topology build context intentionally includes the local `vendor/`
directory. Client prepared topology dependencies are installed or reused through
transient `composer:2` helper containers and then persisted into the node image;
gateway source is also synchronized to the gateway `orbit-runtime` sibling.
Builds run on isolated `10.90.N.0/24` networks. The
allocator derives `N` from the build/run id or ParaTest worker token and retries
on Docker subnet overlap errors, so build networks do not collide with Beast's
real Orbit WireGuard route. Carrying `vendor/` in the topology image makes the
guarded runtime dependency install a no-op during normal preparation, avoiding
Packagist dependency downloads from inside that network.

Docker is a valid lane for `process:*`, `schedule:*`, and `workspace:*`
runtime assertions because gateway-backed Docker topologies provide the gateway
`orbit-runtime`, `orbit-caddy`, FrankenPHP app/workspace containers, Docker
process runtime containers, and the gateway scheduler inside `orbit-runtime`.
Client topology nodes run the Orbit CLI directly from the node container. Docker
topology nodes may use `tini` for container entrypoint supervision, but host
Supervisor is not the PHP process runtime and the scheduler is not a host
Supervisor program. Incus remains required for tests that depend on real VM
behavior:
cloud-init, package installation, real SSH daemon behavior, sudo prompts,
OS trust-store mutation, real WireGuard interfaces and peer routing, and host
init itself.

`composer test:e2e:docker` runs with Pest parallel mode. Its worker count is
derived from the configured Docker runner slots after per-host container caps
are applied. Each topology role starts one node container, and gateway-backed
topologies start one additional `orbit-runtime` sibling for the gateway. The
largest Docker topology has five active roles plus the gateway runtime sibling,
so it reserves six containers per worker. A runner with four Docker slots
should be configured as `host:4:28`, leaving capacity headroom above the
required 24 containers. When hosts differ, define each daemon in
`ORBIT_E2E_DOCKER_TEST_RUNNERS`, for example
`ORBIT_E2E_DOCKER_TEST_RUNNERS=sidecar1:4:28,sidecar2:4:28,beast:2:56`.

Historical measurement on 2026-05-19 with the tarball-only DNS-alias checkout
path, before the gateway runtime sibling was accounted for, passed the canary
with eight Docker workers across `sidecar1:4,sidecar2:4` and a 16 container cap
in `47.55s` real time. Docker-first runtime now needs a 28 container cap for
that pool.
The full Docker lane passed three consecutive runs with 81 tests / 727
assertions in `113.45s`, `112.49s`, and `114.88s` real time
(`113.61s` mean, `1.20s` sample stdev). A post-regression repair spot-check on
2026-05-21 passed with 94 tests / 779 assertions in `137.24s` real time. The
checkout tarball is built from tracked
and unignored files only, with ignored worktrees, build output, generated E2E
test copies, vendor, node_modules, and runtime state excluded from the archive
and its cache hash.

Earlier measurement at `sidecar1:5,sidecar2:5` with 10 workers regressed, so
the recommended local sidecar pool stays at four slots per sidecar. The Docker
lane derives worker count from those slots and lowers effective slots when a
host's container cap cannot fit
`host slot count * largest selected Docker topology container count`.

For multi-host parallelism, set Docker test runners. Pest worker count follows
the effective total slot count automatically:

```bash
ORBIT_E2E_DOCKER_TEST_RUNNERS=sidecar1:4:28,sidecar2:4:28 \
composer test:e2e:docker
```

The normal local pool is `sidecar1:4,sidecar2:4`, which derives eight Docker
workers. Up to four workers can lease `sidecar1` and four can lease `sidecar2`.
The mapping is a blocking lease pool, not a worker-number map: a worker takes
the first free Docker slot, waits when all slots are busy, and releases its slot
during topology cleanup. Stale lease files are reclaimed after
`ORBIT_E2E_SLOT_STALE_SECONDS`. Lease files are shared across Git worktrees by
default: worktree runs resolve the lease directory to the main checkout's
`storage/framework/e2e/leases`. Set `ORBIT_E2E_LEASE_DIRECTORY` only when a run
should intentionally use an isolated lease pool. Set
`ORBIT_E2E_PARALLEL_PROCESSES=<n>` only as a temporary Docker debugging cap; do
not keep it in `.env.e2e` for normal runs.

To include an optional runner, add it to the same value with its own slots and
container cap. It will contribute workers only when reachable at run start:

```bash
ORBIT_E2E_DOCKER_TEST_RUNNERS=sidecar1:4:28,sidecar2:4:28,macbook:2:16 \
composer test:e2e:docker
```

For worktree debugging of a single file or a related file set, pass the file
paths through the Composer script. Add `--sequential-tests` when the selected
tests should stay in one Pest process:

```bash
composer test:e2e:docker -- --sequential-tests tests/E2E/AppListTest.php

composer test:e2e:docker -- --sequential-tests \
  tests/E2E/AppListTest.php \
  tests/E2E/NodeListTopologyTest.php

composer test:e2e:docker -- --sequential-tests \
  --filter='lists apps' \
  tests/E2E/AppListTest.php
```

Each Pest worker gets a non-overlapping Docker subnet from the `10.90.N.0/24`
pool. Run-scoped topologies derive `N` from the run id and, in parallel mode,
from ParaTest's `TEST_TOKEN`; if Docker reports a pool overlap, Orbit retries
the next run-scoped subnet. Role host endings stay consistent within the worker
subnet: gateway `.2`, operator `.3`, dev `.4`, prod `.5`, agent `.6`, and
ingress `.7`. `control` is the legacy alias for the operator node address.

Tests must reach Docker topology services through topology handles such as
`$topology->operator()->ssh(...)`, not by calling `https://10.6.0.x` directly
from the Pest process. The older `$topology->control()` handle is still
accepted as an alias during migration. On macOS, Docker Desktop runs containers
inside a Linux VM, so the bridge subnet is reachable from containers but not
necessarily from the developer host.

Docker bridge addresses are transport addresses only. DNS-alias topology state
continues to use canonical `10.6.0.x` WireGuard identities, and the gateway API
maps Docker bridge peer addresses back to those canonical identities for test
traffic.

To target a single remote Docker daemon for ad hoc debugging, keep Pest running
locally and override the Docker runner list:

```bash
ORBIT_E2E_DOCKER_TEST_RUNNERS=beast:1:28 \
composer test:e2e
```

The local machine only needs the Docker CLI and SSH access; the prepared Docker
images must exist on every configured Docker host. The aggregate preparation
command builds them once on Beast and imports them into the sidecars. The Docker
provider checks `docker image inspect` against the selected daemon because
`DOCKER_HOST=ssh://...` forwards every CLI call.

Use the aggregate host preparation command to build fresh runtime and topology
images on `ORBIT_E2E_DOCKER_IMAGE_BUILD_HOSTS` and import them into the
configured Docker host pool:

```bash
ORBIT_E2E_DOCKER_TEST_RUNNERS=sidecar1:4:28,sidecar2:4:28 \
ORBIT_E2E_DOCKER_IMAGE_BUILD_HOSTS=beast \
composer e2e:prepare-docker-hosts -- --force operator_gateway_app-dev_app-prod_agent
```

The command checks the build host first. If the required runtime or topology
images are missing, it builds them on the single configured build host; either
way it then imports the full required image set into every Docker test runner.
If `ORBIT_E2E_DOCKER_IMAGE_BUILD_HOSTS` is unset, remote Docker pools default to
the Incus image build host, which is normally `beast`. Configure exactly one
Docker image build host, normally `ORBIT_E2E_DOCKER_IMAGE_BUILD_HOSTS=beast`;
sidecars belong only in `ORBIT_E2E_DOCKER_TEST_RUNNERS`. This keeps Docker layer
sharing intact: runtime and prepared topology images are built on one daemon and
imported as one combined image set. Use `--runtime-only` or `--topology-only`
when only one image layer needs refreshing.

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
`composer test:e2e:docker` with
`ORBIT_E2E_DOCKER_TEST_RUNNERS=root@<server-ip>:<processes>:<container-cap>`,
then deletes the server and temporary SSH key unless `--keep` is set. This is intentionally
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

### Incus VM-Feature Tests

Incus VM-feature tests are still `e2e-feature` tests, but they carry the
`e2e-provider-incus` group and call
`E2ETopologyFactory::fromEnvironment()->requireCapabilities(E2ETopologyCapabilities::vm())`.
This keeps Docker-only runs focused while allowing the aggregate
`composer test:e2e` command to include VM-feature coverage when the Incus lane
is selected and available.

Use this lane for prepared-topology tests that need real VM semantics but do
not rebuild topology images and do not run provisioning:

```bash
composer test:e2e:incus
```

When Incus is unavailable or the required prepared topology is missing, the test
should catch `E2ETopologyUnavailable` and call `markTestSkipped()`. That makes
`composer test:e2e` usable on Docker-only hosts and on temporary external
Docker capacity such as Hetzner.

Do not put provisioning tests in `e2e-provider-incus`. Provisioning tests stay
in `e2e-provision` and run only through `composer test:e2e:provision`.

## Environment

The Composer E2E scripts source `.env.e2e` when that file exists, then apply
the lane-specific defaults shown in `composer.json`. Copy `.env.e2e.example` to
`.env.e2e` in the main checkout for local machine pool settings. Worktrees
should symlink their `.env.e2e` back to the main checkout so every worktree uses
the same slot configuration.

```bash
ORBIT_E2E=1                           # Enable ephemeral E2E tests
ORBIT_E2E_LANES=docker,incus          # composer test:e2e lane set: docker, incus, docker,incus, or all
ORBIT_E2E_PROVIDER=incus              # Backend provider (incus is current)
ORBIT_E2E_PROVIDERS=incus             # Ordered provisioning provider pool
ORBIT_E2E_TOPOLOGY_PROVIDER=docker    # Prepared topology provider for direct artisan/Pest runs
ORBIT_E2E_TOPOLOGY_PROVIDERS=docker   # Ordered prepared topology provider pool
ORBIT_E2E_GATEWAY_API=1               # Start gateway API/10.6 routes for tests that need it
ORBIT_E2E_DOCKER_TEST_RUNNERS=sidecar1:4:28,sidecar2:4:28  # Docker host:slots:container-cap pool
ORBIT_E2E_DOCKER_IMAGE_BUILD_HOSTS=beast # Build Docker images here, then import to Docker hosts
ORBIT_E2E_INCUS_HOSTS=beast           # Incus provisioning host pool
ORBIT_E2E_INCUS_HOST_SLOTS=beast:1    # Incus provisioning-test lease pool; not prepared-topology feature parallelism
ORBIT_E2E_INCUS_HOST_VM_CAPS=beast:12 # Required per-Incus-host VM caps for prepared topology clones
ORBIT_E2E_INCUS_PARALLEL_PROCESSES=3  # Requested Pest workers for composer test:e2e:incus; lane caps by selected topology VM count
ORBIT_E2E_HCLOUD_LOCATION_SLOTS=nbg1:2,fsn1:1  # Hetzner provisioning-test lease pool
ORBIT_E2E_HCLOUD_RESOURCE_SLOTS=nbg1/cx23/ubuntu-24.04:2,fsn1/cpx31/ubuntu-24.04:1  # Hetzner location/type/image pool
ORBIT_E2E_PROVISION_PARALLEL_PROCESSES=1  # Pest workers for composer test:e2e:provision
ORBIT_E2E_EXCLUSIVE_HOSTS=beast       # Prevent Docker/Incus overlap if Beast is used for both
ORBIT_E2E_SLOT_WAIT_SECONDS=900       # How long Docker/Incus workers wait for a free slot
ORBIT_E2E_SLOT_STALE_SECONDS=7200     # Reclaim abandoned local lease files after this TTL
ORBIT_E2E_LEASE_DIRECTORY=            # Optional override; default is shared across repo worktrees
ORBIT_E2E_INCUS_IMAGE_BUILD_HOST=beast # Build Incus images once here, then import to Incus hosts
ORBIT_E2E_INCUS_STORAGE_POOL=orbit-e2e  # Optional Incus storage pool for launch/copy operations
ORBIT_E2E_TOPOLOGY_CACHE=process      # Reuse acquired topologies for the current PHP process
ORBIT_E2E_CHECKOUT_CACHE=process      # Reuse branch checkout installs within one PHP process
ORBIT_E2E_CHECKOUT_ARCHIVE_CACHE_DIR= # Optional shared checkout archive cache directory
ORBIT_E2E_DOCKER_PARALLEL_STARTS=0    # Optional; requires SSH ControlMaster capacity on sidecars
ORBIT_E2E_TOPOLOGY_RESET=fresh-clone  # Reset mode for topology clones
ORBIT_E2E_TIMINGS=1                   # Print phase timings to STDERR (acquire / reset)
ORBIT_E2E_CPUS=2                      # vCPUs for image-prep / provisioning VMs
ORBIT_E2E_MEMORY=2GiB                 # Memory for image-prep / provisioning VMs
ORBIT_E2E_TOPOLOGY_CPUS=1             # vCPUs for disposable topology clones
ORBIT_E2E_TOPOLOGY_MEMORY=2GiB        # Memory for disposable topology clones
ORBIT_E2E_TOPOLOGY_ROOT_SIZE=16GiB    # Root disk size for Incus topology templates and feature clones
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

`composer test:e2e` runs `php artisan e2e:test`, which reads
`ORBIT_E2E_LANES` and starts the selected prepared-topology lanes concurrently.
The lane aliases set `ORBIT_E2E_LANES` before invoking the same orchestrator:
`composer test:e2e:docker` selects `docker`, and `composer test:e2e:incus`
selects `incus`. The Docker lane sets
`ORBIT_E2E_TOPOLOGY_PROVIDER=docker`; the Incus lane sets
`ORBIT_E2E_TOPOLOGY_PROVIDER=incus`. `ORBIT_E2E_TOPOLOGY_PROVIDER=auto` still
expands to `incus` for direct artisan or Pest invocations unless the provider
selection code is changed.

`composer test:e2e:provision` runs serially when
`ORBIT_E2E_PROVISION_PARALLEL_PROCESSES=1`. Set the value above `1` to enable
Pest parallel mode for provision tests; every worker still acquires a
provisioning lease before creating Incus or Hetzner resources.

`composer test:e2e:docker` and `composer test:e2e:provision` use separate
lease namespaces in the same shared lease directory. Docker feature tests read
`ORBIT_E2E_DOCKER_TEST_RUNNERS`; Incus provisioning tests read
`ORBIT_E2E_INCUS_HOST_SLOTS`; Hetzner Cloud provisioning tests read
`ORBIT_E2E_HCLOUD_RESOURCE_SLOTS` first and fall back to
`ORBIT_E2E_HCLOUD_LOCATION_SLOTS`. By default those namespaces do not block each
other. Add a host to `ORBIT_E2E_EXCLUSIVE_HOSTS` when the same machine appears
in more than one backend pool and the backend families must not overlap. The
local setup keeps Beast in `ORBIT_E2E_EXCLUSIVE_HOSTS` for the opt-in overflow
case: Beast Docker overflow waits while Beast is running Incus E2E, and Incus
waits while Docker is using Beast. Same-backend slots still run concurrently, so
`beast:2:14` can host two Docker feature workers when no Incus lease is active.
This is active lease exclusion, not queue priority: if an Incus run should have
first claim on Beast, do not include Beast in the Docker runner pool for that
run.

`ORBIT_E2E_HCLOUD_RESOURCE_SLOTS` treats each key as
`location/server-type/image` and applies all three values before creating
servers. `ORBIT_E2E_HCLOUD_LOCATION_SLOTS` remains available when every leased
location should use the same `ORBIT_E2E_HCLOUD_SERVER_TYPE` and
`ORBIT_E2E_HCLOUD_BLANK_IMAGE`.

## E2E Docker lane - benchmark protocol

Use the timing parser to summarize repeated Docker lane runs by `label` and
`event`:

```bash
ORBIT_E2E_TIMINGS=1 \
  composer test:e2e:docker:canary \
  2>&1 | tee /tmp/e2e-canary.log | awk -f bin/e2e-timings.awk

ORBIT_E2E_TIMINGS=1 \
  composer test:e2e:docker \
  2>&1 | tee /tmp/e2e-full.log | awk -f bin/e2e-timings.awk
```

To record a Docker lane baseline, run **3 consecutive** full-lane passes under
identical conditions with unique `/tmp` log names and a wall-clock timer:

```bash
/usr/bin/time -p -o /tmp/e2e-full-run1.time \
  env ORBIT_E2E_TIMINGS=1 \
  composer test:e2e:docker \
  2>&1 | tee /tmp/e2e-full-run1.log | awk -f bin/e2e-timings.awk

/usr/bin/time -p -o /tmp/e2e-full-run2.time \
  env ORBIT_E2E_TIMINGS=1 \
  composer test:e2e:docker \
  2>&1 | tee /tmp/e2e-full-run2.log | awk -f bin/e2e-timings.awk

/usr/bin/time -p -o /tmp/e2e-full-run3.time \
  env ORBIT_E2E_TIMINGS=1 \
  composer test:e2e:docker \
  2>&1 | tee /tmp/e2e-full-run3.log | awk -f bin/e2e-timings.awk
```

Commit a `## Docker lane baseline (YYYY-MM-DD)` section only when all three
runs pass with unchanged exit status, test count, and assertion count. Record
the three wall times, the wall mean plus sample standard deviation, and per-run
`n / p50 / p95` summaries for `docker.start`, `docker.prune`,
`docker.primeGatewayApi`, `reset.delete.*`, `reset.start`, `reset.prune`,
`reset.primeGatewayApi`, and `checkout.*` when
those event groups are present. Downstream phases must beat the recorded wall
baseline by more than `2 x stdev`.

## Required SSH multiplexing for measured Docker baselines

Operator-applied only. Orbit does not configure this automatically. The Docker
lane opens many short SSH-backed Docker CLI connections through
`DOCKER_HOST=ssh://...`; without SSH multiplexing, connection setup dominates
the run and the full Docker lane can regress from the expected 120-150s range
to roughly 300s even when `.env.e2e` is otherwise identical.

```sshconfig
Host sidecar1 sidecar2 beast
    HostName %h
    User nckrtl
    ControlMaster auto
    ControlPath ~/.ssh/cm-%r@%h:%p.sock
    ControlPersist 10m
    ServerAliveInterval 30
```

Check the effective SSH config and connection reuse before recording or
comparing Docker E2E baselines:

```bash
ssh -G sidecar1 | grep -E '^(controlmaster|controlpath|controlpersist)'
time ssh -o BatchMode=yes sidecar1 true
time ssh -o BatchMode=yes sidecar1 true
```

The config should report `controlmaster auto`, a stable `controlpath`, and
`controlpersist` in seconds. The second `ssh true` call should be around
10-20 ms on the local LAN. If it remains in the hundreds of milliseconds,
fix local SSH config, identity selection, DNS/address selection, or network
routing before treating Docker E2E timing as an Orbit regression. Keep
`ORBIT_E2E_DOCKER_PARALLEL_STARTS=0` unless SSH multiplexing is in place and
sidecar sshd capacity has been verified under load.

Provisioning and topology clones use independent resource budgets. Image
preparation and provisioning E2E keep `ORBIT_E2E_CPUS=2` because installer work
is CPU- and package-manager-bound. Topology feature clones default to 1 vCPU
because the work is mostly SSH, SQLite, command execution, small API calls, and
readiness polling — more 1-vCPU clones in parallel beats fewer 2-vCPU clones.

Prepared Incus feature tests do not use `ORBIT_E2E_INCUS_HOST_SLOTS`.
`ORBIT_E2E_INCUS_HOST_SLOTS` is for provisioning/image-prep leases, where a
test mutates Incus host state by creating new blank or base VMs. Prepared
feature tests clone existing topology snapshots and choose a host through
`ORBIT_E2E_INCUS_HOSTS` plus `ORBIT_E2E_INCUS_HOST_VM_CAPS`.
`composer test:e2e:incus` caps the requested Pest worker count from the largest
selected topology kind. Direct Pest/artisan runs that bypass
`php artisan e2e:test` do not get that lane cap automatically. Their concurrency
is bounded by:

- `ORBIT_E2E_INCUS_PARALLEL_PROCESSES`, the requested Pest worker count for the
  Incus lane.
- `ORBIT_E2E_INCUS_HOST_VM_CAPS`, the maximum number of Orbit-owned
  prepared-topology VMs allowed on an Incus host.
- The cached topology size selected by the lane. Each test clones only the roles
  requested by its topology kind from prepared snapshots, so runs fit more
  workers when selected tests do not need all five roles.

For example, `ORBIT_E2E_INCUS_PARALLEL_PROCESSES=3`,
`ORBIT_E2E_INCUS_HOST_VM_CAPS=beast:12`, and
`ORBIT_E2E_TOPOLOGY_CPUS=1` request three Incus workers. If the largest selected
Incus topology has three roles, all three workers can fit within the 12 VM cap;
if the largest selected topology has five roles, the lane caps to two workers.
`composer test:e2e` starts Docker and Incus lanes together; it is normal for
Docker to finish first and for only the Incus lane to remain visible afterward.

`ORBIT_E2E_INCUS_HOST_VM_CAPS` is enforced by the host pool. When a feature
test asks for a topology, the pool walks the configured hosts and picks the
first one that has both the prepared templates *and* enough free Orbit-owned
slots (`max - runningE2EInstanceCount() >= roles required`). User-owned VMs are
ignored — only instances whose name starts with `ORBIT_E2E_INSTANCE_PREFIX` are
counted. Recommended baseline:

```bash
ORBIT_E2E_INCUS_HOSTS=beast
ORBIT_E2E_INCUS_HOST_SLOTS=beast:1    # provisioning only
ORBIT_E2E_INCUS_STORAGE_POOL=orbit-e2e
ORBIT_E2E_INCUS_HOST_VM_CAPS=beast:12
ORBIT_E2E_INCUS_PARALLEL_PROCESSES=3  # requested; lane caps by selected topology size
ORBIT_E2E_TOPOLOGY_CPUS=1
ORBIT_E2E_EXCLUSIVE_HOSTS=beast
```

`ORBIT_E2E_INCUS_STORAGE_POOL` is optional. Leave it empty to use each host's
Incus default pool. Set it when a host has a faster CoW-capable pool, e.g. a
dedicated ZFS-backed `orbit-e2e` pool, so template builds and feature clones use
that pool explicitly. When this value is set, prepared templates must be built
on the same pool. Verify with:

```bash
ssh beast 'for name in orbit-template-control orbit-template-gateway orbit-template-dev orbit-template-prod orbit-template-agent; do echo "--- $name"; incus config show "$name" --expanded | sed -n "/root:/,/^[^ ]/p" | head -n 4; done'
```

Every listed template should show `pool: orbit-e2e`. If the templates show
`pool: default` while `ORBIT_E2E_INCUS_STORAGE_POOL=orbit-e2e`, rebuild the full
prepared topology before trusting Incus feature-lane timings:

```bash
composer e2e:prepare-topology -- --force operator_gateway_app-dev_app-prod_agent
```

The regression signature for a storage-pool mismatch is
`ORBIT_E2E_TIMINGS=1 composer test:e2e:incus` reporting
`batch.copy-start` near 100s per worker. The expected local Beast value after a
healthy rebuild on `orbit-e2e` is about 2s per worker.

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
  `limits.memory`. `ORBIT_E2E_TOPOLOGY_ROOT_SIZE` remains the normal root disk
  capacity for topology templates and feature clones. Use it only on hosts
  where stateful VM migration/snapshot support has been verified.

Unknown values continue to fall back to `fresh-clone`.

For test-to-test isolation, prefer one topology lease per Pest test and call
`$topology->cleanup()` in `finally`. For multiple destructive sub-scenarios in a
single test, call `$topology->reset()` between scenarios; set
`ORBIT_E2E_TOPOLOGY_RESET=snapshot-restore` or
`ORBIT_E2E_TOPOLOGY_RESET=stateful-restore` when restore speed matters and the
scenario does not need a brand-new clone identity.

Set `ORBIT_E2E_TIMINGS=1` to surface per-phase durations from the topology
factory and lease. Forced `e2e:prepare-topology` already streams checkpoints by
default; the environment flag is still useful for topology acquisition, cleanup,
and reset paths. Current event names include `availability`, `batch.copy-start`,
`agent-ready.<role>`, `command-ready.<role>`, `wireguard`, `cleanup.<role>`, and
`reset.*`. Output goes to STDERR with the prefix `[orbit-e2e]` so it interleaves
cleanly with Pest output. The clone/start batch intentionally stays one remote
SSH operation; split copy/start timing should only be added if it can keep that
single remote operation.

All E2E orchestration now runs via Pest groups and `php artisan e2e:*` commands.
