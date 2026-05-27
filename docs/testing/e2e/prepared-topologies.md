# Prepared topologies

The `e2e-feature` lane uses prepared topology clones. Choose the smallest
topology that covers the behavior under test.

Topology kinds use the canonical `operator*` spelling. `control-*` remains an
accepted alias where the code exposes compatibility shims.

For Incus, topology names describe the roles that should be booted for a test.
They do not mean each kind has a separate Incus source build. Incus uses the
prepared five-role source and starts only the listed nodes.

For Docker, topology names also describe the active roles requested by a test.
Docker prepares composable role images: operator and gateway from
`operator_gateway`, then app-dev, app-prod, and agent from
`operator_gateway_app-dev_app-prod_agent`.

## Topology kinds

Use this table to choose the smallest active node set for a feature test.

| Kind | Nodes | Use when |
| --- | --- | --- |
| `operator` | 1 operator node | Fastest. Use for operator-side commands. |
| `operator_gateway` | operator + 1 gateway | Use for gateway trust, onboarding, or node-registry flows. |
| `operator_gateway_app-dev` | operator + gateway + 1 dev app | Use for app or workspace commands that need a development app node. |
| `operator_gateway_app-dev_app-prod` | operator + gateway + dev + 1 prod app | Full app topology. Use for production-app flows or full-stack verification. |
| `operator_gateway_agent` | operator + gateway + 1 agent | Use for agent-node assertions that do not need app-dev or app-prod nodes. |
| `operator_gateway_app-prod_ingress` | operator + gateway + 1 prod app carrying ingress | Use for public production ingress and private app-production backend flows that do not need dev or agent nodes. |

## Feature checkout overlay

Prepared topology images and templates are branch-agnostic topology baselines.
They prove OS, users, SSH, Docker, `orbit-runtime`, `orbit-caddy`, service
containers, trust, routes, host PHP CLI for the CLI/local-executor artifact, and
baseline Orbit installation state.

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
that runs on those nodes. `control` is accepted as an alias for the operator
node.

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
and gateway come from `operator_gateway`; app-dev, app-prod, and agent come from
`operator_gateway_app-dev_app-prod_agent`.

The Incus provider clones only selected roles from the prepared
`operator_gateway_app-dev_app-prod_agent` snapshots, starts those VMs, retargets
WireGuard, and prunes stale gateway registry rows for roles that were not
booted.

## Prepared sources

Required prepared sources for feature lanes:

- Docker role images for the composable gateway-backed set: operator and
  gateway from `operator_gateway`; app-dev, app-prod, and agent from
  `operator_gateway_app-dev_app-prod_agent`. App-dev carries database and Redis
  registry state by default, and app-prod carries the ingress role. Canonical
  base role images are `orbit-e2e:operator_base`, `orbit-e2e:gateway_base`,
  `orbit-e2e:app-dev_base`, `orbit-e2e:app-prod_base`, and
  `orbit-e2e:agent_base`.
- Docker runner support images: `orbit-runtime:<namespace>-current`,
  `caddy:2-alpine`, and every FrankenPHP image supported by
  `PhpRuntimeCatalog` for app/workspace topologies.
- Docker build-host helpers: `orbit-e2e-topology-runtime:<namespace>-current`
  and `composer:2`, used only to prepare the canonical role images.
- `operator_gateway_app-dev_app-prod_agent` Incus role snapshots for selective
  VM boot, including operator-only and operator-gateway tests.

Prepare the canonical Docker role image set once for the host pool:

```bash
composer e2e:prepare-docker-hosts -- --force operator_gateway_app-dev_app-prod_agent

composer e2e:prepare-topology -- --force operator_gateway_app-dev_app-prod_agent
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
`--roles=operator,gateway,app-dev,app-prod,agent` to build only selected role
images, or `--all-roles` for an explicit full namespaced role set. Incus
acquisition has the same per-role fallback, but forced targeted Incus rebakes
are blocked until the builder can refresh only selected VM templates without
mutating the rest of the artifact set.

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
- host PHP CLI and PDO SQLite are available for the CLI/local-executor artifact;
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
| `operator_gateway_agent` | Adds one agent clone named `agent-1` and skips development and production app clones. |
| `operator_gateway_app-prod_ingress` | Uses one production app clone that also carries the ingress role. Public production HTTP assertions preserve the path `ingress -> router -> backend`. |

## Hosted service expectations

Prepared E2E topologies keep hosted service assertions on the owning app node:

- app-dev carries database and Redis registry state by default;
- app-prod carries the ingress role by default;
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
