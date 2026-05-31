# Prepared topologies

The `e2e-feature` lane uses prepared topology clones. Choose the smallest
topology that covers the behavior under test.

For Incus, topology names describe the roles that should be booted for a test.
Most kinds reuse the prepared full role source and start only the listed nodes.
The dedicated-ingress kind has its own source snapshot because it includes an
extra ingress VM.

For Docker, topology names also describe the active roles requested by a test.
Docker prepares composable role images: operator and gateway from
`operator_gateway`, then app-dev, app-prod, agent, ingress, and websocket from
their owning source topologies.

## Topology kinds

Use this table to choose the smallest active node set for a feature test.

| Kind | Nodes | Use when |
| --- | --- | --- |
| `operator` | 1 operator node | Fastest. Use for operator-side commands. |
| `operator_gateway` | operator + 1 gateway | Use for gateway trust, onboarding, or node-registry flows. |
| `operator_gateway_app-dev` | operator + gateway + 1 dev app | Use for app or workspace commands that need a development app node. |
| `operator_gateway_app-dev_app-prod` | operator + gateway + dev + 1 prod app | Full app topology. Use for production-app flows or full-stack verification. |
| `operator_gateway_app-dev_app-prod_ingress` | operator + gateway + dev + prod + 1 ingress node | Full app topology with ingress on a dedicated edge node instead of app-prod. |
| `operator_gateway_agent` | operator + gateway + 1 agent | Use for agent-node assertions that do not need app-dev or app-prod nodes. |
| `operator_gateway_app-prod_ingress` | operator + gateway + 1 prod app carrying ingress | Use for public production ingress and private app-prod backend flows that do not need dev or agent nodes. |
| `operator_gateway_app-dev_websocket` | operator + gateway + dev + 1 websocket node | Use for private websocket runtime and publishing flows that only need a development app Redis provider. |
| `operator_gateway_app-dev_app-prod_websocket` | operator + gateway + dev + prod + 1 websocket node | Use for public app WebSocket flows through app-prod's colocated ingress. |
| `operator_gateway_app-dev_app-prod_agent_websocket` | operator + gateway + dev + prod + agent + 1 websocket node | Full websocket-capable source topology. Use for artifact preparation or cross-role assertions that need every current workload role. |

## Feature checkout overlay

Prepared topology images and templates are branch-agnostic topology baselines.
They prove OS, users, SSH, Docker, `orbit-runtime`, `orbit-caddy`, service
containers, trust, routes, the installed Orbit CLI binary, and baseline Orbit
installation state.

Feature assertions must run the checkout under test inside the disposable clone.
For worktree-based development, the worker's current worktree is the source of
truth. The test installs or overlays that checkout into a disposable path on the
clone and invokes commands through the host `orbit` launcher. The clone's
installed `orbit` CLI remains a topology/bootstrap smoke target unless the test
is explicitly about installed CLI behavior.

Use the shared E2E checkout helper when a feature test needs the current
checkout on a clone. Do not mutate template instances, reusable images, or the
steady-state `orbit` symlink to make a feature assertion see new code.

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
the branch checkout. Add `gateway`, `dev`, or `prod` when the branch changes code
that runs on those nodes.

## Retained dev topologies

`composer e2e:incus -- --start` acquires a prepared topology, overlays the
current checkout, and retains it (it is not reaped) so a human can do manual
diagnosis and performance testing against an isolated Incus topology — never
against a live production topology. It reuses the same prepared-topology
substrate, run id, and checkout overlay as the source-checkout E2E lane; it only
differs in that the clone is kept until you release it.

```bash
# Acquire a retained Incus topology with the current checkout overlaid.
composer e2e:incus -- --start --topology=operator_gateway_app-dev_app-prod

# Acquire only the operator + gateway checkout overlay.
composer e2e:incus -- --start --topology=operator_gateway_app-dev \
  --checkout-roles=operator,gateway

# Preview the acquisition plan without provisioning anything.
composer e2e:incus -- --start --dry-run \
  --topology=operator_gateway_app-dev_app-prod_agent
```

Composer requires the separator before command options; keep the `--` between
`e2e:incus` and `--start`/`--stop`. Acquisition requires a configured Incus host
(`ORBIT_E2E_HOST` or `ORBIT_E2E_INCUS_HOSTS`). Retained Docker topologies are not
supported. The cloned instances use a distinct, identifiable dev run id
(`orbit-e2e-dev-<hex>-<role>`) so they never collide with ephemeral test clones
and stay easy to reap.

The command prints, in human or `--json` form, an `id`, the gateway API IP, and a
per-role handle: the instance name plus a ready-to-run SSH example, e.g.

```text
[operator] orbit-e2e-dev-1a2b3c-operator
  ssh: ssh beast incus exec orbit-e2e-dev-1a2b3c-operator -- sudo -u orbit bash -lc 'cd /home/orbit/orbit-current && orbit node:list --json'
[dev] orbit-e2e-dev-1a2b3c-dev
  ssh: ssh beast incus exec orbit-e2e-dev-1a2b3c-dev -- sudo -u orbit bash -lc 'cd /home/orbit/orbit-current && orbit node:list --json'
  endpoint: 10.6.0.4 (dev node WireGuard address; FrankenPHP app runtime — no app served until you deploy one)
  note: Deploy an app from the operator, then curl the app domain through the gateway router with -w "%{time_total}s".
```

For the gateway role, the handle includes an immediate `/api/ca/root` latency
probe. For the `app-dev` and `app-prod` roles, the handle surfaces the
FrankenPHP app node's WireGuard address (dev `10.6.0.4`, prod `10.6.0.5`) and
reminds the human that a fresh retained topology does not serve app traffic until
an app is deployed.

Retained state is recorded under `apps/e2e/var/dev-topology/<id>.json` (gitignored)
with the id, kind, provider, host, run id, ssh key path, gateway IP, per-role
instance names, per-role checkout paths, and creation timestamp.

Release a retained topology when you are done. Releasing reaps the recorded
instances on the host, removes the dedicated per-run SSH key directory, and
deletes the state file:

```bash
# Release a specific retained topology.
composer e2e:incus -- --stop --id=dev-1a2b3c

# Release every recorded retained topology.
composer e2e:incus -- --stop --all
```

Retained topologies are manual diagnosis and performance-testing tools only.
Durable behavior assertions still live in prepared-topology Pest E2E tests, not in
a kept-alive topology.

Legacy `composer e2e:dev-topology` and `composer e2e:dev-topology:release`
aliases still exist for compatibility, but new documentation and command output
use `composer e2e:incus`.

## Cache behavior

When `ORBIT_E2E_TOPOLOGY_CACHE=process`, `e2eTopology()` reuses the same prepared
topology lease for matching requests in the current PHP process and cleans it up
once at process shutdown. `composer test:e2e` combines this with Pest parallel
mode and `ORBIT_E2E_CHECKOUT_CACHE=process`, which installs the branch checkout
once per node/user and gives each test an isolated hardlink copy with fresh
runtime files.

Tests request the smallest topology kind that covers the behavior under test.
The Docker provider starts only the requested roles from the canonical
composable role images, prunes gateway registry rows for roles that were not
requested, and primes the gateway API for active container addresses. Operator
and gateway come from `operator_gateway`; app-dev, app-prod, agent, ingress,
and websocket come from their owning source topologies.

The Incus provider clones only selected roles from the prepared
`operator_gateway_app-dev_app-prod_agent` or
`operator_gateway_app-dev_app-prod_agent_websocket` snapshots for ordinary
current-role and websocket topologies. The dedicated-ingress topology clones
from `operator_gateway_app-dev_app-prod_ingress`. Incus starts those VMs,
retargets WireGuard, and prunes stale gateway registry rows for roles that were
not booted.

## Prepared sources

Required prepared sources for feature lanes:

- Docker role images for the composable gateway-backed set: operator and
  gateway from `operator_gateway`; app-dev, app-prod, and agent from
  `operator_gateway_app-dev_app-prod_agent_websocket`; dedicated ingress from
  `operator_gateway_app-dev_app-prod_ingress`. App-dev carries database, Redis,
  and the websocket role registry state by default; app-prod carries the ingress
  role unless the dedicated-ingress topology is requested. Canonical base role
  images are `orbit-e2e:operator_base`, `orbit-e2e:gateway_base`,
  `orbit-e2e:app-dev_base`, `orbit-e2e:app-prod_base`,
  `orbit-e2e:ingress_base`, and `orbit-e2e:agent_base`.
- Docker runner support images: `orbit-runtime:<namespace>-current`,
  `caddy:2-alpine`, and every FrankenPHP image supported by
  `PhpRuntimeCatalog` for app/workspace topologies.
- Docker build-host helpers: `orbit-e2e-topology-runtime:<namespace>-current`
  and `composer:2`, used only to prepare the canonical role images.
- `operator_gateway_app-dev_app-prod_agent` and
  `operator_gateway_app-dev_app-prod_agent_websocket` Incus role snapshots for
  selective VM boot, including operator-only, operator-gateway, and websocket
  tests.
- `operator_gateway_app-dev_app-prod_ingress` Incus role snapshots for tests
  that need app-dev, app-prod, and a dedicated ingress VM.

`composer test:e2e`, `composer test:e2e:docker`, and
`composer test:e2e:incus` do not prepare artifacts. They fail before Pest
workers start when a selected provider is missing a required image, template, or
snapshot, and print a scoped artifact command for the missing lane.

Use `composer e2e:ensure-artifacts` to plan or run a targeted artifact refresh:

```bash
# Build or pull Docker runtime/support images only when one is missing.
composer e2e:ensure-artifacts -- --lanes=docker --runtime --force operator_gateway_app-dev_app-prod_agent

# Refresh only the agent Docker role image for a branch/worktree override.
ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE=agent-isolation \
composer e2e:ensure-artifacts -- --lanes=docker --roles=agent --force operator_gateway_app-dev_app-prod_agent

# Rebuild selected Docker role images even when matching tags already exist.
composer e2e:ensure-artifacts -- --lanes=docker --roles=operator,gateway --rebuild --force operator_gateway_app-dev_app-prod_agent

# Inspect the Incus templates for an agent role override.
ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE=agent-isolation \
composer e2e:ensure-artifacts -- --lanes=incus --roles=agent operator_gateway_agent
```

Forced Docker ensure delegates to the Docker host preparer, which checks the
requested images on the build host and distributes only the selected role or
runtime artifacts. Add `--rebuild` when the selected Docker tags already exist
but their baked source or runtime state must be refreshed. Forced Incus targeted
role refreshes are guarded; inspect the planned role templates with
`e2e:ensure-artifacts` and use the explicit topology preparer only when
intentionally rebuilding an Incus artifact set.

Prepare the canonical Docker role image set once for the host pool:

```bash
composer e2e:prepare-docker-hosts -- --force operator_gateway_app-dev_app-prod_agent

composer e2e:prepare-topology -- --force operator_gateway_app-dev_app-prod_agent

composer e2e:prepare-docker-hosts -- --force operator_gateway_app-dev_app-prod_agent_websocket

composer e2e:prepare-topology -- --force operator_gateway_app-dev_app-prod_agent_websocket
```

Docker and Incus preparation both use Composer caches during provisioning.
Docker uses a lockfile-keyed volume or the optional
`ORBIT_E2E_DOCKER_COMPOSER_CACHE` bind mount. Incus stages the local
`~/.cache/orbit-e2e/composer` cache into the provisioning bundle when present.

Prepared topology artifacts use an explicit namespace by default. Docker role
images use `orbit-e2e:<role>_<artifact-set>`, where the shared artifact set is
`base`. Set `ORBIT_E2E_TOPOLOGY_ARTIFACT_NAMESPACE=<slug>` only for branch,
benchmark, or worktree role-image overrides. The Docker provider resolves each
role independently by trying `orbit-e2e:<role>_<slug>` first, then
`orbit-e2e:<role>_base`. Incus role templates use
`orbit-template-<role>-<artifact-set>` with snapshots named
`clean-<source-topology>-<artifact-set>` and the same per-role fallback to the
`base` artifact set. Runtime support images continue to use
`<namespace>-current`.

Custom artifact preparation must declare intent. Docker accepts
`--roles=operator,gateway,app-dev,app-prod,ingress,agent,websocket` to build only
selected role images, or `--all-roles` for an explicit full namespaced role set.
Incus acquisition has the same per-role fallback, but forced targeted Incus
rebakes are blocked until the builder can refresh only selected VM templates
without mutating the rest of the artifact set.

## Contract

Prepared topology clones are not just booted VMs. When
`E2ETopologyFactory::fromEnvironment()->require(...)` returns, the clone set must
be ready for command assertions that depend on that topology kind.

Common requirements for every prepared topology:

- all nodes are disposable clones of prepared templates or images;
- each clone has the baseline Orbit installation for that topology, and that
  installation is independent of the current branch;
- SSH is authorized for the users needed by the topology handles;
- `orbit --version` works for the steady-state Orbit user on each managed node;
- Docker Engine/CLI is available to the host launcher and runtime managers;
- the installed Orbit CLI binary is available on each managed node;
- host Composer, host Caddy, PHP-FPM, and host Supervisor for PHP app processes
  are absent from Docker-first topology images;
- Orbit runtime containers use sibling containers through the host Docker socket;
- tests may mutate clones, but must never mutate template instances;
- cleanup deletes clones unless `ORBIT_E2E_KEEP=1`;
- reset returns clones to the clean prepared state for the selected reset mode.

## Role expectations

Each topology kind adds the handles and seeded state that tests can rely on.

| Kind | Expectation |
| --- | --- |
| `operator` | Provides one operator node clone through the `operator` topology handle. The operator user is `ORBIT_E2E_OPERATOR_USER` (`orbit` by default, with `ORBIT_E2E_CONTROL_USER` accepted as an alias). |
| `operator_gateway` | Adds one gateway clone through the `gateway` handle. The operator can reach the gateway API, stores gateway settings, and trusts the gateway CA. |
| `operator_gateway_app-dev` | Adds a development app clone named `app-dev-1`. Development TLD state exists for `test` and points at the development app's WireGuard address. |
| `operator_gateway_app-dev_app-prod` | Adds a production app clone named `app-prod-1`. Production app runtime assertions use FrankenPHP app containers and Docker process runtime units behind the private `orbit-caddy` backend listener. |
| `operator_gateway_app-dev_app-prod_ingress` | Adds a dedicated ingress clone named `edge-1`; app-prod remains `app-prod-1` and points its production ingress setting at `edge-1`. |
| `operator_gateway_agent` | Adds one agent clone named `agent-1` and skips development and production app clones. |
| `operator_gateway_app-prod_ingress` | Uses one production app clone that also carries the ingress role. Public production HTTP assertions preserve the path `ingress -> router -> backend`. |
| `operator_gateway_app-dev_websocket` | Adds the websocket role to `app-dev-1`, alongside `app-dev` and `database`; Redis for websocket points to the same app-dev node. |
| `operator_gateway_app-dev_app-prod_websocket` | Adds a production app clone that carries ingress for public app WebSocket assertions while keeping websocket on `app-dev-1`. |
| `operator_gateway_app-dev_app-prod_agent_websocket` | Adds dev, prod, and agent workload nodes, with websocket colocated on `app-dev-1`. Use as the full websocket-capable artifact source. |

## Hosted service expectations

Prepared E2E topologies keep hosted service assertions on the owning app node:

- app-dev carries database and Redis registry state by default;
- app-prod carries the ingress role by default;
- `operator_gateway_app-dev_app-prod_ingress` moves ingress from app-prod to
  `edge-1`;
- hosted service assertions must not add host PHP, host Composer, host Caddy,
  PHP-FPM, or host Supervisor to make those services pass.

## Reset modes

`ORBIT_E2E_TOPOLOGY_RESET` controls how `E2ETopologyLease::reset()` returns a
clone to a known-clean state between sub-scenarios in the same test:

- `fresh-clone` deletes existing clones and rebuilds from prepared topology
  templates.
- `snapshot-restore` stops each clone, restores the per-instance `lease-clean`
  snapshot, starts each clone, then waits for agent and SSH on the same handles.
- `stateful-restore` restores a running `lease-warm` snapshot with
  `migration.stateful=true`; use it only on hosts where stateful VM snapshot
  support has been verified.

Unknown values fall back to `fresh-clone`.

`ORBIT_E2E_TOPOLOGY_RESET` applies after a test has already acquired a topology.
Initial Incus acquisition can use prepared warm stateful snapshots only when
`ORBIT_E2E_INCUS_WARM_SNAPSHOTS=1`; see
`docs/testing/e2e/incus.md#warm-stateful-snapshots`.
