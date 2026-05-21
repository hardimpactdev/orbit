# Docker-First Orbit Runtime Design

## Goal

Move Orbit from host-native PHP/Caddy/PHP-FPM infrastructure to a Docker-first
runtime model.

The target model removes host PHP, Composer, Caddy, and PHP-FPM from the
steady-state Orbit substrate. It also removes host Supervisor as the default
manager for Orbit services and PHP app processes. Hosts provide Git, Docker,
the Orbit launcher, and role-specific non-PHP host tools such as VitePlus.
Orbit itself, fleet Caddy, PHP app runtime, and PHP app command execution run
through long-lived Docker containers.

This is a replacement design, not a second runtime track. The migration either
proves the Docker-first model through tests and E2E and replaces the current
PHP-FPM model, or PHP-FPM remains the clear winner and this branch is not
merged.

## Current Context

Current authority docs describe:

- Orbit installed from source on the host;
- the `orbit` command invoking host PHP;
- gateway API and scheduler running through native PHP;
- Caddy running natively on every relevant node;
- PHP-FPM pools running natively for apps and workspaces;
- Supervisor managing Orbit-defined long-running processes;
- Docker reserved for backing services such as databases and caches.

Those docs match the current implementation and intentionally conflict with
this design. Implementation must therefore start by aligning product docs before
code changes. The current PHP-FPM/Caddy wording must not be silently left as the
product contract while the code moves to Docker.

This design also amends the ingress direction:

- `ingress` remains the role that owns public production traffic;
- `app-production` remains a private backend role unless co-located with
  `ingress`;
- Caddy is no longer a host-native service in either role;
- PHP-FPM is replaced by FrankenPHP app containers for PHP apps.

## Product Decisions

- Use a big-bang migration for the product contract. Temporary compatibility is
  acceptable inside the implementation branch only to keep tests moving.
- Final product state has no PHP-FPM/FrankenPHP dual runtime selection.
- Host PHP and Composer are not supported fallbacks.
- Docker is a baseline host prerequisite for every managed node.
- Git remains a host prerequisite so the Orbit checkout can be updated.
- VitePlus remains a host prerequisite for app-development and app-production
  roles because frontend dev servers, file watching, and HMR are still
  host-side in v1.
- Fleet Caddy moves into Docker on every node that needs Caddy.
- There is at most one standalone Orbit Caddy container per node:
  `orbit-caddy`.
- FrankenPHP app containers may embed Caddy internally, but that embedded Caddy
  is part of the app runtime backend. It is not Orbit's fleet proxy, not a CA
  owner, and not a route authority.
- The gateway remains the owner of Orbit root CA material.
- PHP apps and PHP workspaces run in dedicated long-lived FrankenPHP containers.
- Static/non-PHP apps do not get FrankenPHP containers.
- FrankenPHP classic mode is the default.
- FrankenPHP worker mode is opt-in per app or workspace and uses a dedicated
  worker configuration object.
- Enabling worker mode verifies readiness and refuses when validation fails.
  It does not mutate app source to make the app worker-safe.
- Orbit-defined processes gain an explicit runtime: `docker` or `supervisor`.
- PHP app processes default to Docker sidecar containers, not host Supervisor.
- Static/non-PHP processes may use Supervisor where host-side execution remains
  the correct v1 boundary.
- Docker E2E uses sibling containers through the host Docker socket, not
  Docker-in-Docker.

## Host Contract

The final host contract is deliberately small.

Required on every managed node:

- Git;
- Docker Engine and Docker CLI;
- Orbit checkout;
- Orbit launcher installed in the executable path;
- SSH access for the gateway's `RemoteShell` path;
- WireGuard identity and trust material as defined by the node/vpn model.

Required on app-development and app-production nodes:

- VitePlus for frontend development/build workflow support.

Not required on the host:

- PHP;
- Composer;
- Caddy;
- PHP-FPM;
- Supervisor for PHP app processes;
- per-PHP-version host package repositories or services.

The host `orbit` executable is a launcher, not the PHP application itself. It
checks that the local `orbit-runtime` container exists and then executes the
command inside that container.

Suggested launcher shape:

```bash
#!/usr/bin/env bash
set -euo pipefail

exec docker exec \
  --env "ORBIT_HOST_CWD=$PWD" \
  --env "ORBIT_HOST_UID=$(id -u)" \
  --env "ORBIT_HOST_GID=$(id -g)" \
  orbit-runtime \
  orbit "$@"
```

`ORBIT_HOST_CWD` is the canonical local-context value. It lets commands invoked
from inside an app or workspace path pass that path into Orbit even when the
container only mounts the Orbit checkout.

## Runtime Topology

Each node gets long-lived runtime containers based on its roles and configured
apps.

```text
host
  ├─ orbit launcher
  ├─ Docker Engine
  ├─ orbit-runtime
  │    ├─ Orbit CLI target for docker exec
  │    ├─ gateway API when node has gateway role
  │    └─ scheduler when node has gateway role
  ├─ orbit-caddy
  │    ├─ gateway API proxy when node has gateway role
  │    ├─ ingress routes when node has ingress role
  │    ├─ private backend routes when node has app-production role
  │    └─ development routes when node has app-development role
  ├─ orbit-app-<app-or-workspace>
  │    └─ FrankenPHP runtime for one PHP app/workspace
  └─ orbit-process-<process>
       └─ Docker process runtime for one app/workspace process
```

Containers are connected to an Orbit-managed Docker network on the node.
`orbit-caddy` can route to `orbit-runtime` and app containers by Docker network
name. Ingress can still route to remote app-production backends over
WireGuard when the app-production node is separate.

## Orbit Runtime Container

`orbit-runtime` is one long-lived container per node.

It serves two jobs:

- CLI execution target for the host launcher through `docker exec`;
- resident services for roles that need Orbit to listen or loop, such as the
  gateway API and gateway scheduler.

On non-gateway nodes, `orbit-runtime` mostly exists so `orbit` commands can run
without host PHP. On the gateway, the same container also serves the API to
`orbit-caddy` over the node Docker network and runs the scheduler loop.

The gateway API remains reachable only through the Orbit/WireGuard control
plane. Moving the PHP runtime into Docker does not change the trust model:

```text
CLI caller
  -> HTTPS over WireGuard
  -> gateway orbit-caddy
  -> gateway orbit-runtime
  -> gateway database / RemoteShell
```

Streaming progress must be revalidated under the containerized API path. The
old PHP-FPM split between stream and exec sockets cannot be copied directly; the
new API runtime needs its own concurrency contract and tests for long-lived SSE
commands.

## Caddy Runtime

`orbit-caddy` is the standalone fleet proxy container.

One node may have zero or one `orbit-caddy` container. It owns the same route
families Caddy owns today, but from Docker:

- internal Orbit routes such as the gateway API;
- ingress routes;
- private app-production backend routes;
- app-development and workspace routes;
- static app routes;
- tool-owned HTTP routes.

The gateway remains the owner of the Orbit root CA. `orbit-caddy` receives only
the certificates and trust material it needs for the routes on that node.
FrankenPHP app containers must not receive root CA private material or become a
certificate authority.

The Caddy move is fleet-wide. Keeping some nodes on host Caddy and others on
Docker Caddy would create two operational models and awkward exceptions when a
node later needs FrankenPHP-backed PHP routing.

## PHP App Runtime

PHP app execution moves from PHP-FPM pools to FrankenPHP containers.

Runtime units:

- production PHP app: one `orbit-app-<app>` container;
- development PHP workspace: one `orbit-app-<workspace>` container;
- non-PHP/static app or workspace: no FrankenPHP container.

This preserves the isolation Orbit currently gets from PHP-FPM pools while
making app runtime behavior closer between development and production. Idle
containers are expected to consume little CPU; memory overhead must be measured
in the E2E and pilot phases, not assumed away.

The PHP version remains gateway-tracked configuration. Changing an app or
workspace PHP version re-creates the affected container from the corresponding
FrankenPHP image instead of re-rendering a PHP-FPM pool.

Classic mode is the baseline. Request flow for a production app on separate
ingress and app-production nodes is:

```text
browser
  -> ingress orbit-caddy
  -> app-production orbit-caddy over WireGuard HTTP
  -> app FrankenPHP container over Docker network HTTP
```

Development app/workspace route flow is:

```text
browser
  -> app-development orbit-caddy
  -> workspace FrankenPHP container over Docker network HTTP
```

Static route flow remains direct Caddy file serving where appropriate:

```text
browser
  -> orbit-caddy
  -> static root mounted/readable by orbit-caddy
```

## FrankenPHP Performance Baseline

Classic FrankenPHP must be treated as the PHP-FPM replacement baseline, not as
the performance upside by itself. It should be configured to preserve the same
core PHP performance properties Orbit currently relies on from PHP-FPM:

- OPcache enabled and sized for the app;
- OPcache timestamp behavior suitable for the deploy model;
- optional OPcache preloading per app;
- Composer optimized autoloader generated inside the app container;
- realpath cache sized for the app;
- Laravel production caches generated through `php artisan optimize`;
- Blade views precompiled before production traffic is routed;
- optional HTTP warmup after a new app container becomes healthy.

OPcache is a PHP engine capability, not a PHP-FPM-only feature. FrankenPHP uses
the PHP interpreter and can use OPcache, preloading, Composer autoloader
optimization, and realpath cache tuning. The migration must therefore render
these settings into the FrankenPHP app image/container instead of treating them
as lost PHP-FPM behavior.

Orbit should standardize on fast FrankenPHP image variants for both development
and production. The baseline is official FrankenPHP images built on a
glibc-based distribution such as Debian, not Alpine/musl. Development and
production should use the same image family per PHP version so local behavior
does not drift from production. Production images may use a hardened
Debian/glibc base, but must not switch to Alpine/musl for size alone.

Deployment warmup has two layers:

- PHP/Laravel warmup commands run inside the selected app container:
  `composer install --no-dev --optimize-autoloader`, `php artisan optimize`,
  and any app-specific warmup hooks.
- HTTP warmup requests hit the new container through its actual web path before
  `orbit-caddy` routes production traffic to it. This is the part that warms
  web-server OPcache paths; CLI commands alone are not sufficient proof that
  the web runtime is warm.

Worker mode remains the main performance upside over PHP-FPM/classic mode. The
classic-mode acceptance bar is parity with tuned PHP-FPM; worker mode is the
explicit opt-in path for keeping the Laravel application in memory.

## Worker Mode

Worker mode is an app/workspace runtime option, not the default.

Stored configuration should separate the on/off decision from the worker
settings:

```json
{
  "worker_enabled": false,
  "worker_config": {
    "workers": "auto",
    "max_requests": 500,
    "max_consecutive_failures": 3
  }
}
```

The command surface should be app-oriented:

```bash
orbit app:worker show <app>
orbit app:worker enable <app>
orbit app:worker disable <app>
```

Workspace support can either mirror the app command or be introduced when a
workspace-specific testing workflow needs it. The product rule is the same:
classic by default, worker mode deliberate.

`app:worker enable` must validate readiness and refuse when validation is not
met. For Laravel apps, the first accepted readiness check is Laravel Octane with
FrankenPHP support installed and configured in the app. Orbit should not run
`composer require`, publish config, edit bootstrap files, or otherwise mutate
the app to make it pass validation.

Validation failure should explain the missing condition and leave the runtime
unchanged.

## Process Runtime

The process family remains the owner of app-defined long-running commands such
as queue workers, websocket servers, Horizon, custom daemons, and frontend
watchers.

The process definition gains a runtime:

```json
{
  "runtime": "docker"
}
```

Supported values:

- `docker` - run this process as an Orbit-managed container;
- `supervisor` - run this process under Supervisor on the host or inside the
  relevant runtime boundary where Supervisor remains intentionally supported.

Defaults:

- PHP app/workspace process: `docker`;
- static/non-PHP app process: `supervisor` unless explicitly configured
  otherwise;
- frontend dev process: host/VitePlus path for v1.

A PHP queue worker is therefore not a host process in the Docker-first model.
It becomes a sidecar container using the same app image, mounted source, env,
and PHP version as the app runtime container.

## PHP Command Execution

PHP, Composer, Artisan, and test commands for PHP apps run inside the app or
workspace runtime container. Orbit should expose explicit command surfaces for
this instead of expecting operators to know container names.

Suggested first surfaces:

```bash
orbit app:exec <app> -- composer install
orbit app:exec <app> -- php artisan migrate
orbit workspace:exec <workspace> -- php artisan test
```

Later convenience shims may be added, but the contract should remain explicit:
the command executes in the selected app/workspace runtime boundary.

## Local Context Resolution

Commands invoked from app or workspace directories remain viable with the
launcher model.

The launcher passes `ORBIT_HOST_CWD`. The `orbit-runtime` container does not
need broad access to every app checkout just to resolve context. It can send the
host cwd string to the gateway. The gateway resolves it against registered app
and workspace paths, authorizes the command, and dispatches node-side work over
the normal gateway-to-node SSH path.

Example:

```text
operator runs `orbit workspace:setup` inside /srv/polyscope/workspaces/foo
  -> launcher passes ORBIT_HOST_CWD=/srv/polyscope/workspaces/foo
  -> orbit-runtime calls gateway API
  -> gateway resolves workspace foo from stored path metadata
  -> gateway SSHs to the owning node
  -> owning node performs setup through Docker runtime containers
```

This keeps the Orbit runtime container narrow while preserving cwd-based
operator ergonomics.

## Documentation Alignment First

Implementation must begin by updating product authority docs to the new target
contract. The following docs need explicit alignment before implementation code
starts:

- `docs/architecture.md` - Docker-first component model, CLI launcher, runtime
  topology, ingress to app backend flow, and removal of host PHP-FPM as
  a state-family artifact.
- `docs/tech-stack.md` - host contract, `orbit-runtime`, `orbit-caddy`,
  FrankenPHP app runtime, Docker process runtime, scheduler/API runtime, and
  VitePlus boundary.
- `docs/concepts.md` - add or redirect concepts for Orbit launcher,
  `orbit-runtime`, `orbit-caddy`, app runtime container, worker mode, process
  runtime, and Docker-first host contract.
- `docs/domains/1_node/**` - role baselines, platform prerequisites, Docker
  baseline, ingress/app-production boundaries, and node doctor drift.
- `docs/domains/2_gateway/**` - gateway API and scheduler running inside
  `orbit-runtime`.
- `docs/domains/3_tool/**` - tool catalog changes for Docker, Caddy,
  FrankenPHP/PHP, and VitePlus.
- `docs/domains/4_firewall/**` - Dockerized Caddy listener ownership and
  app-production private backend exposure.
- `docs/domains/5_app/**` - app runtime kind, PHP version, worker mode,
  command execution, and production route flow.
- `docs/domains/6_workspace/**` - workspace runtime containers, cwd resolution
  through `ORBIT_HOST_CWD`, setup/teardown command execution, and worker test
  behavior.
- `docs/domains/7_process/**` - `process.runtime`, Docker sidecars, Supervisor
  residual scope, logs, start/stop/restart/status behavior.
- `docs/domains/8_proxy/**` - `orbit-caddy` route artifacts, backend targets,
  certificate material, and static/PHP route split.
- `docs/domains/9_schedule/**` - scheduler container placement and dispatch
  path.
- `docs/domains/10_deploy/**` - deploy steps that run Composer, PHP, Artisan,
  tests, and process restarts through app containers.
- `docs/domains/11_operation/**` - profile/context handling where cwd affects
  command target selection.
- `docs/domains/14_php/**` - PHP version management becomes container image
  selection rather than host package/FPM pool management.
- `docs/domains/15_agent-ide/**` - cwd-based command invocation and container
  launcher expectations where IDEs call Orbit.
- `docs/domains/16_dns/**` - only if local development URL behavior changes
  while Caddy moves to Docker.
- `TESTING.md` and `docs/porting/testing-infrastructure.md` - Docker-first E2E
  topology contract and sibling-container model.

After these docs are aligned, implementation proceeds with failing tests,
focused code slices, and only then actual E2E execution.

## E2E Strategy

The E2E system is the reason this migration can be attempted as a big bang.
The migration should deliberately strengthen E2E before trusting the runtime
change.

Docker topology images must represent the new host contract:

- no host PHP;
- no host Composer;
- no host Caddy;
- no host PHP-FPM;
- no host Supervisor requirement for PHP app processes;
- Docker CLI available;
- Docker socket mounted for sibling container orchestration;
- Orbit launcher installed;
- `orbit-runtime` and `orbit-caddy` managed as sibling containers.

Docker E2E should not run Docker-in-Docker. The topology node containers use
the host Docker socket to create sibling runtime containers on the same E2E
network.

Incus/provision E2E remains necessary for real host behavior:

- Docker installation and adoption;
- launcher installation;
- firewall behavior;
- WireGuard and SSH paths;
- Caddy certificate trust;
- ingress/private backend reachability;
- live-node conversion rehearsal.

Actual E2E validation starts after docs, focused tests, and product
implementation are in place. Preparing topology images is implementation work,
not proof that the migration works.

## Big-Bang Migration

The migration branch may use internal compatibility seams while being built,
but the shipped product contract is singular:

- one launcher model;
- one gateway API runtime model;
- one fleet Caddy model;
- one PHP app runtime model;
- one process runtime contract with explicit `docker`/`supervisor`;
- no host PHP fallback;
- no host PHP-FPM fallback.

Cutover should be gated by:

1. product docs aligned with the target model;
2. focused tests proving host-native assumptions have been removed;
3. Docker topology E2E passing without host PHP/Caddy/PHP-FPM;
4. Incus/provision E2E passing on clean hosts;
5. a conversion rehearsal on disposable nodes;
6. live-node conversion only after the above passes.

If those gates fail and the failures show FrankenPHP/Docker-first cannot
replace PHP-FPM cleanly, the branch should be abandoned rather than merged as a
permanent dual-runtime system.

## Acceptance Criteria

- A fresh managed node can run `orbit` without host PHP installed.
- Gateway API is reachable through Dockerized Caddy and `orbit-runtime`.
- Gateway scheduler runs without host PHP or host Supervisor.
- `orbit-caddy` is the only standalone Caddy container on a node.
- PHP production apps serve through FrankenPHP app containers.
- PHP workspaces serve through FrankenPHP workspace containers.
- FrankenPHP app/workspace images use the approved glibc-based image family for
  both development and production.
- FrankenPHP classic mode has OPcache, realpath cache, Composer autoload
  optimization, Laravel optimization caches, and optional preloading support.
- Static/non-PHP apps still serve without a FrankenPHP container.
- Changing PHP version recreates the affected runtime container instead of a
  PHP-FPM pool.
- Worker mode is disabled by default and can be enabled only after validation.
- Worker validation failure refuses without changing runtime config.
- PHP queue/process commands run as Docker runtime units.
- Cwd-based commands work through `ORBIT_HOST_CWD`.
- Docker E2E fails if host PHP, host Composer, host Caddy, or host PHP-FPM is
  accidentally required.
- Incus/provision E2E proves the real host bootstrap and conversion path.

## Non-Goals

- Supporting RoadRunner or Swoole in this migration.
- Shipping a permanent PHP-FPM/FrankenPHP runtime selector.
- Dockerizing Vite in v1.
- Automatically installing or configuring Laravel Octane in user apps.
- Giving FrankenPHP app containers Orbit root CA authority.
- Running Docker-in-Docker for topology E2E.
- Replacing the ingress role split.

## Resolved Decisions And Stop Conditions

- Image strategy: v1 builds local development/test images from the Orbit
  checkout. Versioned registry images may be added later, but implementation
  must not block on a registry. Stop and reconcile only if deployment docs
  require registry-published runtime images before Docker-first can merge.
- Container/network naming: use deterministic `orbit-*` names owned by the
  runtime container manager. Stop and reconcile if landed ingress/router code
  already introduced a conflicting naming convention.
- Worker mode: app worker mode ships first. Workspace worker mode waits for a
  separate explicit workflow unless the workspace runtime implementation needs
  the same schema fields for consistency.
- Process runtime: `supervisor` remains an explicit residual runtime for
  supported non-PHP host-side cases. PHP app/workspace processes default to
  Docker.
- Diagnostics: low-level Docker status/log inspection belongs in doctor and
  targeted runtime diagnostics, not every product command output.
