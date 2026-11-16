---
title: "ADR 0269: Run the Gateway in release containers with FrankenPHP worker mode"
sidebarTitle: "0269 Gateway containers"
description: "In progress, proposed, design only. The Gateway runs in an Incus container on its own Node, released as hot, live and old containers the way ADR 0263 releases production Instances. FrankenPHP in worker mode, through Laravel Octane's worker, serves it instead of PHP-FPM. The database, keys, Caddy, WireGuard and Swamp access stay on the host. Each choice states its cost per request."
---

# ADR 0269: Run the Gateway in release containers with FrankenPHP worker mode

The Gateway runs in an Incus container on its dedicated Node. Each release is one container with one of three release states, as [ADR 0263](/decisions/0263-run-production-releases-in-hot-live-and-old-containers) defines them for production Instances:

- **Hot** is the next release. It builds, migrates and passes its readiness check before any request reaches it.
- **Live** serves every Gateway request and runs the Gateway's long-running parts.
- **Old** is the release before live. It stays stopped for rollback.

FrankenPHP serves live in worker mode: each worker boots Laravel once and then serves many requests. PHP-FPM goes. State that must outlive a release stays on the host: the SQLite database and every key in `ORBIT_HOME`, Caddy with its certificates, WireGuard and the Gateway's address. The host's Caddy switches traffic from live to hot with one graceful reload.

## Status

In progress.

Proposed: a design for review, with no part built. A measuring spike follows it in the same task group: the same release under PHP-FPM and under worker mode on a task copy's nested Gateway. The spike's numbers replace the guesses below. Number 0269 was the next free number on the workspace's `main` on 2026-11-16, after 0268; the design thread checks open pull requests before it publishes.

Principle: [One way, one name](/mission#principles). The Gateway uses the release states, the snapshot and the switch of ADR 0263, not a second release model. It also serves [No exceptions and no legacy](/mission#principles): the worktree releases, the PHP-FPM pool and `bin/gateway-rollback` go when their replacements are built.

### The owner's decisions

Nick decided these on 2026-11-16. They bind every part below.

- **G1. Hot, live and old containers.** The Gateway runs in an Incus container on its dedicated Node, released the way ADR 0263 releases production Instances: hot is made ready before traffic, live serves, old is kept for rollback.
- **G2. FrankenPHP worker mode.** FrankenPHP in worker mode serves the live Gateway, with Laravel booted once per worker. PHP-FPM goes, for faster requests on the wake and API paths.
- **G3. Every millisecond counts.** As [ADR 0266 D3](/decisions/0266-sleep-and-wake-containers-through-a-signed-order-executor#the-owners-decisions) says for the wake, speed on the wake and API paths is measured in milliseconds. This design states what each choice costs or saves per request. A number marked *guess* is not measured yet; the spike measures it.

## Context

Today the Gateway runs on its Node's host from [immutable releases behind one link](/reference/gateway-recovery#release-layout):

- Each release is a Git worktree in `/home/orbit/releases/<id>/` with its own `vendor/`. `/home/orbit/orbit` links to the current release. `apps/gateway/.env` links to `/home/orbit/shared/gateway.env`, and `storage` links to `/home/orbit/shared/gateway-storage/`.
- The database is SQLite, `ORBIT_HOME/gateway.sqlite`. `ORBIT_HOME` also holds the SSH identity and known hosts, the WireGuard keys, the root CA, `gateway.app-key`, the Ed25519 order key of ADR 0266 and the SSH connection sockets ([Preserve a complete state set](/reference/gateway-recovery#preserve-a-complete-state-set)).
- PHP-FPM serves requests through the pool `orbit-gateway`: `pm = ondemand`, `pm.max_children = 8`, `pm.max_requests = 100`, `request_terminate_timeout = 600s` (`GatewayRuntimeFiles::pool`). [ADR 0218](/decisions/0218-take-long-commands-off-the-request-path#the-new-limit-300-seconds) lowers the limit N to 300 s. Caddy on the host reaches the pool over a Unix socket and serves the web build from `web/current`.
- Long-running parts run as host units from the current link: `schedule:work`, Horizon, `orbit-agent-view.service` (the [agent view subscriber](/reference/node-agent), which ADR 0266 makes the result listener), and the runtime hibernator as a oneshot.
- Horizon's queues live in Valkey on the `services` Node ([Gateway queue](/reference/gateway-queue#connection)). Reverb runs on the `websocket` Node. Swamp serve runs on the Swamp Node, 209 in the live fleet.

A release is one Swamp run, `operation-gateway-release`, with its child `gateway-release-activate` ([Release as a Swamp run](/reference/gateway-recovery#release-as-a-swamp-run)). Its scripts are `swamp/scripts/gateway-release-*.sh`.

Three facts of today drive this design:

- **Every PHP-FPM request boots Laravel.** With a cached configuration, a boot of the Gateway costs *15–40 ms per request (guess)*, before any route code runs. The wake path pays it twice: once for the activation request and once for each poll of the waiting page.
- **Only PHP-FPM opens shared SSH connections.** `SshControlGroup` gives `Master` only to a process in a `php*-fpm.service` control group, because a master dies with the control group that started it ([SSH connections](/reference/node-provisioning#ssh-connections)). A command on a shared connection takes about 18 ms; one on a new connection about 190 ms (measured, same page).
- **A release changes the host.** The worktree, the link swap, the PHP-FPM reload and the unit restarts all act on the Gateway Node itself. A release can therefore leave the host half switched, and `bin/gateway-rollback` exists for that case.

## Decision

The parts below take G1–G3 as given. Part 1 maps each release step of today, part 2 says what stays on the host and how traffic switches, part 3 moves the long-running parts, part 4 sets the worker, part 5 the security and part 6 the move from today's Gateway. Part 7 sums the costs per request; parts 8 and 9 hold the slices and the open points.

### 1. Release and rollback, step by step

The Gateway keeps its one release record and its one Swamp run per release. The run still reaches the Gateway Node over SSH from the Swamp Node, so a release never depends on a healthy Gateway. What each step does changes:

| Step today | Script today | Becomes |
| --- | --- | --- |
| Fetch the commit into `shared/orbit.git` | inline in the workflow | The same, on the host. The host's bare repository stays the Gateway's mirror |
| Build the release: worktree, links, `composer install --no-dev`, agent manifest, `REVISION`, `config:cache` | `gateway-release-build.sh` | **Clone hot**, then build in it, as [Hot](#hot-and-ready) describes |
| Install the web build into `web/releases/<id>` | `gateway-release-web.sh` | The same checks, but the build goes into hot, at `/srv/gateway/web`. FrankenPHP's Caddy in the container serves it, so code and web build switch together in one step |
| Snapshot the database with `VACUUM INTO` | `gateway-release-snapshot.sh` | Unchanged, on the host. The database never moves into a container |
| Migrate with `artisan migrate --force --isolated`, from the new release, while the old serves | inline | The same command, run in hot through `incus exec`, while live serves. See [Migrations](#migrations-across-hot-and-live) |
| Wait for active Operations (the [release idle guard](/reference/gateway-recovery#release-idle-guard)) | `artisan gateway:release:wait-idle` | Unchanged, run in hot |
| Load Swamp on the Swamp Node | child workflow | Unchanged |
| — | — | **Ready.** New, as [Hot](#hot-and-ready) describes |
| Drain Horizon | `gateway-release-drain.sh` | The same rule (stop the unit, wait for its whole control group to empty), run in live through `incus exec` |
| Switch: both links, PHP-FPM reload, restart of every service under the application | `gateway-release-switch.sh` → `bin/gateway-rollback` | **Switch.** The host replaces one Caddy upstream file and the live link, and reloads Caddy once. Then the [handover](#3-long-running-parts-and-their-switch) moves the long-running parts. No unit on the host restarts |
| Verify `/up` and the status version through `https://gateway.orbit` | inline | Unchanged, through the public name, after the switch |
| Switch back after a failed verify | `gateway-release-switch-back.sh` | Put the previous upstream file and live link back and reload Caddy once. The previous live still runs during its drain, so this costs one reload and starts nothing |
| Start Horizon, always | inline | Start Horizon in whichever container is live at the end, always |
| Resume document cleanup | `artisan gateway:release:cleanup` | Unchanged, run in live |
| Prune old releases | `gateway-release-prune.sh` | **Retire.** After the previous live has no request in flight, stop it as old and delete the earlier old container. ZFS keeps a dataset while a clone depends on it |

#### Hot and ready

- **Clone.** Hot is a ZFS clone of live's `release` snapshot, named `gateway-<id>`. The clone takes about 1 s and copies no file.
- **Build.** The host pushes the commit into hot, which checks it out. `composer install --no-dev` runs in hot with live's `vendor/` and Composer cache, so a commit without a lock change installs nothing. The agent manifest, `REVISION` and `config:cache` follow, inside hot.
- **Checks.** The downgrade check stays. The disk check becomes the capacity check of [ADR 0263 part 8](/decisions/0263-run-production-releases-in-hot-live-and-old-containers#8-capacity-an-optional-limit-and-an-admission-check) for the pool.
- **Ready.** Hot starts FrankenPHP. The run checks `/up` and `/api/v1/gateway/status` through hot's bridge address, with `Host: gateway.orbit`. A hot that is not ready is removed, and live never changed.
- **Snapshot.** Hot takes its Incus snapshot `release` only after it is ready, so the next hot clones a release that passed.

**Rollback.** `gateway:release:rollback` without an id returns to old, as `instance:rollback` does in ADR 0263: write the current configuration cache into old, start it, check it as ready, switch, hand over. The rolled-away live becomes old, so a second rollback goes forward again. Rollback runs no migration, fetches nothing and restores no snapshot; expand and contract makes the old code safe on the new schema, as today. A rollback further back is a forced release of the earlier commit. The `<id>` argument goes.

**Rollback without Swamp.** `bin/gateway-rollback` becomes a host script that does only the switch and the handover to old: start old, check `/up` on its bridge address, replace the upstream file and the live link, reload Caddy, move the units. It needs Incus and Caddy, not Swamp and not the Gateway.

#### Migrations across hot and live

The rule of today stays: forward only, expand and contract, run before the switch while the old code serves ([Expand and contract](/reference/gateway-recovery#expand-and-contract)). Containers change three things:

- **Hot and live open the same file.** `ORBIT_HOME` is one host directory, mounted into both containers. SQLite's locks and its WAL index are kernel objects of one host file system, so a write in hot is a write that live sees on its next read. No copy and no sync exist.
- **A table rebuild blocks live's writers.** SQLite rebuilds a table for many `ALTER TABLE` changes, and the rebuild holds the write lock. While it runs, every write in live waits on the busy timeout. Cost per request: the rebuild's time for each request that writes during it; *about 1 s per 100 MB of table (guess)*. A migration that rebuilds a large table is split, or runs as a background copy in the expand step.
- **Worker mode keeps connections open.** A live worker keeps its PDO connection across requests. SQLite prepares a statement again when the schema changed, so a migration in hot needs no reload in live. A worker must never hold a read transaction between requests, or the WAL cannot checkpoint while hot migrates; the request end rolls back any open transaction (part 4).

A failed migration leaves live serving and pauses automatic releases with the snapshot's name, as today. A rollback never undoes a migration.

### 2. What stays on the host, and the switch

The container holds only the release: the code, `vendor/`, the web build, the cached configuration and the container's own `/run`. Everything that a release must not own stays on the host:

| Part | Where | Why |
| --- | --- | --- |
| SQLite database and its WAL | Host `ORBIT_HOME`, mounted into hot, live and old | One database serves every release; a copy per release would split writes. The host's backups and `VACUUM INTO` snapshots keep working unchanged |
| `ORBIT_HOME` keys: SSH identity and known hosts, root CA, `gateway.app-key`, the Ed25519 order key of ADR 0266 | Host `ORBIT_HOME`, same mount | A key must survive every release and must never sit in a release snapshot. A rollback must read the keys of now, not of the release's time |
| `gateway.env` | Host `/home/orbit/shared/gateway.env`, mounted read-only | One env file. Only `config:cache` in a release reads it |
| `gateway-storage` (file cache, locks, logs, uploads) | Host directory, mounted | File locks and the cache must be the same in hot and live during a deploy |
| Caddy and the TLS certificate of `gateway.orbit` | Host | Caddy is the switch. TLS ends on the host, as on every Node. A release never sees the certificate's private key |
| WireGuard and the Gateway's WireGuard address | Host | Every Node trusts that address. Moving it into a container would mean a move at each switch, and the Node's role convergence owns WireGuard |
| Valkey | `services` Node | Unchanged. The queue and the ADR 0266 hand-off must outlive a release |
| Reverb | `websocket` Node | Unchanged. Browsers and agents connect to Reverb, not to the Gateway |
| Swamp serve and its grants | Swamp Node | Unchanged. The release run starts there and reaches the host over SSH |
| The release run's SSH access | Host account `orbit` with its sudo rules | The Swamp worker logs into the host, never into a container. The host runs `incus` for the run |

**Network.** The Gateway's containers sit on their own bridge, `orbitgw0`, with no other container on it. The host masquerades their outbound traffic to WireGuard peers behind its own WireGuard address. Every Node, Valkey, Reverb and Swamp therefore still sees the Gateway's one address, and no firewall rule changes. Cost per request: one NAT lookup per new connection, *under 10 µs (guess)*. FrankenPHP in the container listens with plain HTTP on the bridge only. The Gateway trusts `X-Forwarded-For` only from the host's bridge address, so Activity and rate limits keep the caller's address.

**The switch.** The Gateway's Caddy site on the host imports one upstream file, `/etc/caddy/orbit-gateway-upstream.caddy`, which names live's bridge address. The switch writes the new file beside it, renames it over the old one, replaces the live link `/home/orbit/gateway-live`, and runs one `caddy reload`. A failed reload puts both back, and live serves unchanged. Caddy's reload is graceful: requests that started on the previous live finish there, and new requests go to the new live. No request is dropped, because the previous live keeps running until it has no request in flight:

- Retire reads FrankenPHP's `frankenphp_busy_workers` metric on the previous live and stops it only when the metric is 0, or when N (300 s, ADR 0218) has passed since the switch.
- Caddy's `grace_period` is set to N, so a reload never cuts a request that the request limit still allows.

Cost per request: the hop from the host's Caddy to FrankenPHP over the bridge replaces today's FastCGI hop over a Unix socket. *Under 0.2 ms either way (guess)*; the spike measures both. The switch itself costs no request any time: the reload is in Caddy, not in PHP.

**An address move was rejected** (see Rejected alternatives): one address cannot serve two containers at once, so the requests in flight on the previous live would break.

### 3. Long-running parts and their switch

ADR 0263's one rule holds: a release container's units are enabled only while it is live, and only live starts with the Node. Hot installs them disabled, so its snapshot holds them disabled. The handover runs after the switch, in this order:

- **Horizon and its queues.** As today: drain Horizon in live before the switch, switch, then start Horizon in the new live, always. The drain stops the unit and waits for its control group to empty, up to the drain. Jobs that requests dispatch during the drain wait in Valkey, and a job finishes on the release that started it. Cost: no request waits for the drain, because live serves until the switch. Queued jobs wait up to the drain, as today.
- **Scheduler** (`schedule:work`). Stop it in the previous live, then start it in the new live. The handover never runs in the last 2 s or the first 2 s of a minute, so no minute fires twice or is skipped. The release cleanups pause and resume by generation, as today. Cost: none per request. A minute's tick starts up to 2 s late during a release (guess).
- **Runtime hibernator.** A container timer, enabled only in live. Cost: none.
- **ADR 0266 result listener** (`orbit:agent-view`). It starts in the new live **before** the switch and stops in the previous live after it, so one listener always runs.
  - Both listeners may receive one result. Recording a result becomes a conditional write that changes only an Operation without a result. Only the writer that changed the row pushes `executor:op:<operation>`. So a result is recorded once and pushed once.
  - Cost: with a gap, a wake in the gap misses its `accepted` line and pays the SSH relay fallback, *18–190 ms*, or up to the 6 s wait. With the overlap, no wake pays it.
- **Reverb clients.** The web app and the agents talk to Reverb on the `websocket` Node, not to the Gateway, so they do not reconnect. The Gateway's broadcasts are HTTP calls to Reverb from whichever release serves. Cost: none.
- **SSH connections.** The next paragraphs. Cost: one new connection per Node after a switch.

**SSH ControlMaster rule.** `SshControlGroup` keeps its reason and changes its match. In the container, FrankenPHP runs as the unit `orbit-gateway-web.service`, and its control group outlives every request, as PHP-FPM's did. So `Master` goes to a process in that unit's control group, and `php*-fpm.service` leaves the match. Horizon stays `Alone`; every other process rides an open master or opens its own connection.

The connection sockets move from `ORBIT_HOME/ssh/mux`, which every container mounts, to the container's own `/run/orbit/ssh-mux`. Otherwise hot's migration and readiness commands, or old after a rollback, would ride live's masters across containers, and a stopped container would leave sockets behind that the next live trusts. The cost: after a switch, the first command to each Node from the new live opens a new connection, *about 190 ms instead of 18 ms*, once per Node. The ready step can warm the masters: hot's FrankenPHP runs one no-op command on each Node that live used in the last 10 minutes, so the busiest Nodes pay nothing. Masters close after 60 idle seconds, so the warm-up runs as the last step before the switch.

A master started by a FrankenPHP thread is a child of the FrankenPHP process, and a worker restart does not end it. Only the unit's stop ends it, and retire stops the unit only after the drain.

### 4. FrankenPHP worker mode

**The worker.** FrankenPHP from the runtime folder ([ADR 0264](/decisions/0264-build-container-php-on-glibc-with-a-cli-only-pcov)) runs Laravel Octane's FrankenPHP worker script, `vendor/laravel/octane/bin/frankenphp-worker.php`, from an Orbit Caddyfile under the unit `orbit-gateway-web.service`. Orbit owns the unit, the binary and the Caddyfile; `octane:start` does not run. Octane brings the state resets that a Laravel worker needs: a new application sandbox per request, and flushes of the auth, session, request and scoped bindings. A hand-written `frankenphp_handle_request()` loop was rejected: it would copy that list and drift from Laravel. `laravel/octane` becomes a production dependency of `apps/gateway`.

**Per-request saving.** Worker mode removes the boot, *15–40 ms per request (guess)*, and keeps three connections open across requests:

- the PDO connection to SQLite: *about 0.1 ms per request (guess)*;
- the Predis connection to Valkey with its `AUTH`: *1–2 ms per request that uses Valkey (guess)*, which includes every wake through ADR 0266's `BLPOP`;
- the HTTP connection to Reverb's API for the signed `executor-order` event: *2–10 ms per wake (guess)* for the TCP and TLS setup it no longer pays.

The wake inside the request therefore costs the route, the Ed25519 signature (*about 50 µs, guess*), one Reverb publish and one `BLPOP`, with no boot. Compared with ADR 0266's 500 ms bound, the boot alone was up to 8 % of it.

**`defer()` and after-response work.** Four call sites defer work: `ScheduleInstanceRuntimeWakeAction` (the runtime activation), `InstanceWake` (twice: the resume on the Node and the resume cleanup) and `CancelOperationAction` (the forward to Swamp). Octane sends the response first and then runs Laravel's deferred callbacks in the same worker, as PHP-FPM did after `fastcgi_finish_request()`. So `defer()` stays the one after-response mechanism, and no call site changes. The cost is capacity, not latency: a deferred callback holds its worker until it ends, and a worker pool is fixed where `ondemand` forked a new child. The wake's deferred resume can take seconds over SSH. ADR 0266 moves that work into the executor; until it does, the pool counts deferred work (below). The spike checks that the response reaches the client before the callback runs.

**State that must not leak.** In PHP-FPM every request started empty. In a worker, static properties, singletons and anything resolved at boot live as long as the worker. Each of these must hold no request's data:

- **Node identity per request.** A singleton that holds a Node, an SSH target or a caller would serve the next request with the previous Node. Classes that carry a Node take it as an argument or are bound `scoped`. An architecture test lists every `singleton` binding and refuses a binding whose class has a Node, Instance, user or Operation property.
- **Static caches.** The known ones: `SourceDigest::$cache`, `SourceClosure::$cache` and `$roots`, `AgentSchemas::$commands` (code facts of the release, safe to keep), `OperationRunBudgets::$history` (scoped to one tick, safe), and `ActivityShutdownFinalizer::$armed` (not safe, next item). A test fails on any new mutable static that is not on an allow list with its reason.
- **`ActivityShutdownFinalizer`.** It arms a `register_shutdown_function` per request. In a worker, shutdown functions run only when the worker exits: the closures pile up, and a request's Activity is not finalized when a fatal error ends only the request. It changes to disarm and finalize at Octane's request end (`RequestTerminated`), and to keep one shutdown function per worker for a fatal error that ends the worker. Its comment already names `forgetArmed()` for long-lived processes.
- **Configuration.** A worker reads `bootstrap/cache/config.php` once. `gateway:release:configure` therefore restarts live's workers gracefully after it renames the cache. A `Config::set()` inside a request leaks into the next one; a test refuses it in request code.
- **Connections.** At the request end, any open database transaction rolls back, and a Valkey connection that a client-side timeout cut is closed. A `BLPOP` uses the server-side timeout with a client read timeout above it. Otherwise a late reply would reach the next command on that connection and hand one request another request's result.
- **`/proc/self/cgroup`.** `SshControlGroup` reads it per call; that stays correct in a worker.

**Worker count and memory.** Today's pool allows 8 requests at once. The worker pool starts at 16 workers, with the PHP `memory_limit` of today and `max_requests` of 500, after which Octane restarts the worker to cap a slow leak. A worker holds *60–100 MB (guess)*, so 16 workers hold *1–1.6 GB*. ADR 0218's commands that stay in the request may take up to 180 s, and `doctor` up to 270 s, so 16 slow commands hold every worker, and a wake that arrives then waits for a free one. FrankenPHP's `match` lets one Caddyfile give a second worker pool to the wake and the short API paths: `runtime-activations/*`, `/up`, `gateway/status` and the read endpoints. The split is a guess until the spike shows the queue under load; the open points name it.

**The request limit.** PHP-FPM ended a request after N with `request_terminate_timeout`. FrankenPHP has no such kill. The limit becomes `max_execution_time = N`, with the host Caddy's read and write timeouts at N as today. A request that hits `max_execution_time` ends with a fatal error, and FrankenPHP restarts that worker. The spike checks whether the ZTS execution timer measures wall time or CPU time; if it measures CPU time, a request that waits on SSH is not bounded by it, and ADR 0218's command deadlines remain the bound.

**OPcache.** Each container's OPcache is private and built once per worker boot. `opcache.validate_timestamps` turns off: a release container's code never changes, so the per-file stat of today goes. *A few µs per included file per request (guess)*.

### 5. Security

Unlike an app container, the Gateway's container is the control plane. Anyone who runs code in live already reaches every Node as `orbit` with sudo. The container does not make the Gateway safer against its own code, and this design does not claim it. It isolates releases from each other and from the host.

- **What the container holds.** The release and, through mounts, `ORBIT_HOME` and `gateway.env`. The cached configuration in a release snapshot holds the env's secrets, as ADR 0263's `.env` in a snapshot does. The snapshot stays on the Gateway Node, inside the same boundary as `ORBIT_HOME`, and the Gateway Node runs no other container.
- **What it does not hold.** The host's SSH host keys, Caddy's TLS keys, the WireGuard private key, the Incus socket, sudo and the release run's SSH login. A release cannot start, stop or reconfigure a container, and cannot change the host.
- **Privileges.** An unprivileged container (`security.privileged=false`, `security.nesting=false`) with the default idmap. Its only user is `orbit`. The mounts of `ORBIT_HOME`, `gateway-storage` and `gateway.env` use Incus idmapped mounts (`shift=true`), so files stay owned by the host's `orbit` with their modes of today. No `raw.idmap`, no `raw.lxc`.
- **Network.** The bridge `orbitgw0` holds only Gateway containers and accepts inbound traffic only from the host. Outbound traffic leaves through the host's NAT, the same paths the host uses today: WireGuard peers and the egress the build needs (GitHub, Packagist).
- **Host keys and certificates it needs.** It reads `known_hosts` and its SSH identity from `ORBIT_HOME/ssh`, and the root CA from `ORBIT_HOME/ca` for TLS to internal names. It needs no host certificate: TLS ends on the host.

### 6. Rollout from today's Gateway

The move keeps the same commit live and changes only where it runs. The orchestrator owns releases, windows and host changes, so it runs these steps:

1. **Turn automatic releases off** with `gateway:auto-release:disable`, so no release starts during the move.
2. **Converge the `gateway` role with the container setup**: Incus, a ZFS pool for the Gateway's containers, the bridge `orbitgw0`, the NAT rule and the runtime folder. No traffic changes. A Node without a spare disk gets a pool on a file; the open points name this.
3. **Release the commit that holds the container workflows, through today's flow.** The release that adds this design's workflows still builds a worktree and reloads PHP-FPM. Its Swamp load installs the new workflows, unused.
4. **Run `gateway:release:migrate`** (a new one-time run, `operation-gateway-container-migrate`) at the same commit. It builds a cold hot from the image, mounts `ORBIT_HOME`, runs no migration (the schema is current), checks readiness on the bridge address, and switches: the Caddy site goes from `php_fastcgi` on the pool's socket to the upstream file, in one reload. PHP-FPM finishes its requests in flight. Then it hands over: drain the host's Horizon, stop the host's scheduler, agent view and hibernator timer, and start them in the container. The host units stay installed and disabled.
5. **Check** `/up`, `gateway:status` and one wake of a sleeping clone through the public name.
6. **Rollback during the move** is the inverse: restore the Caddy site with `php_fastcgi`, reload, and enable the host units again. The host release stays until step 8.
7. **Release one commit through the container flow**, with automatic releases still off. It builds a warm hot from the moved release's snapshot, so the moved release becomes old.
8. **Remove the host path** after that release is verified: the worktree releases, the PHP-FPM pool and its unit, the host units, `web/releases`, and the old-paths entries. Turn automatic releases on.

No step needs a maintenance window. The orchestrator picks a quiet time anyway for step 4, because the drain of the host's Horizon runs as long as its longest job. The nested Gateways of task copies and proofs bootstrap the same way: `workflow-gateway-bootstrap` builds a cold hot instead of a worktree, so there is one path. Whether a nested Gateway's machine allows a container inside it is an open point.

### 7. Cost per request, in one table

| Choice | Wake and API request cost | Status |
| --- | --- | --- |
| Worker mode instead of PHP-FPM | Saves the Laravel boot, 15–40 ms | guess |
| Open Valkey connection | Saves 1–2 ms per request that uses Valkey | guess |
| Open Reverb API connection | Saves 2–10 ms per wake | guess |
| `validate_timestamps` off | Saves a few µs per included file | guess |
| Host Caddy to container over the bridge, instead of FastCGI on a socket | ± 0.2 ms | guess |
| NAT to WireGuard peers | Under 10 µs per new connection | guess |
| SSH sockets per container | 190 ms instead of 18 ms, once per Node after a switch; 0 for warmed Nodes | measured per command; count is a guess |
| Listener overlap during a switch | Saves 18–190 ms or up to 6 s for a wake that lands in the switch | from ADR 0266's fallback rules |
| A table rebuild in a migration | Blocks writes for its duration | guess |
| The switch itself | 0: one Caddy reload, requests in flight finish | design |

### 8. Slices

Each slice is one pull request. Slices 2 to 6 depend on ADR 0263's build subtasks 1, 2, 4 and 5 for the clone, snapshot, ready and switch parts, which this design reuses for the Gateway's own record.

1. **Worker-safe Gateway.** `ActivityShutdownFinalizer` per request; scoped bindings for every class that holds a Node; the static allow list and the singleton test; the Valkey and transaction resets; `laravel/octane` with Orbit's Caddyfile. PHP-FPM still serves production.
   - Proof: Pest tests that serve two requests for two Nodes in one worker and find no carried state; the spike's numbers.
2. **Gateway role container setup.** Incus, the pool, `orbitgw0`, the NAT rule, the image with only `orbit`, the idmapped mounts.
   - Proof: a fixture `gateway` Node converged twice, with no change the second time.
3. **Hot build and ready.** Clone, push, `composer install`, web build, `config:cache`, migrate in hot, ready on the bridge address, the `release` snapshot, removal on failure.
   - Proof: release cases on the nested Gateway; a failed step and a failed readiness leave live unchanged.
4. **Switch, handover and retire.** The upstream file and the live link; the graceful reload with `grace_period`; the busy-worker drain; the listener overlap; the scheduler's minute guard; the SSH socket move and the `SshControlGroup` match; switch-back and retire.
   - Proof: a load loop through a release with one 60-second request across the switch: no failed request, one scheduler tick per minute, no lost wake result.
5. **Rollback.** `gateway:release:rollback` to old, and the host's `bin/gateway-rollback`.
   - Proof: rollback and forward again on the nested Gateway, under the load loop.
6. **Move and remove.** `gateway:release:migrate`, the bootstrap through a cold hot, then the removal of the host path. The [Update and recover a Gateway](/reference/gateway-recovery) page absorbs this ADR.
   - Proof: the move on the nested Gateway, then on the live Gateway by the orchestrator.

### 9. Open points

- **The pool on the Gateway Node.** The live Gateway Node may have no spare disk for a ZFS pool. A pool on a file works, but its write speed under SQLite's `fsync` is not measured. The database stays on the host's file system either way.
- **A nested Gateway inside a container.** If a task copy's nested Gateway machine is itself an unprivileged container, the Gateway's own container needs nesting there. The spike shows which machine the nested Gateway runs on.
- **The worker split.** Whether the wake and short API paths need their own worker pool, and how many workers each pool gets, waits for the spike's queue numbers under a slow `doctor`.
- **`max_execution_time` under ZTS.** Whether it bounds wall time. If not, ADR 0218's command deadlines are the only bound in a request.
- **Deferred work in the wake.** The deferred resume over SSH holds a worker for seconds until ADR 0266's executor replaces it. Moving it to a queued job earlier would free the worker but add the queue's pickup, about 1 s, to the wake.
- **Memory limit.** ADR 0263 sets no memory limit by default. The Gateway may want one so that a leaking worker cannot starve SQLite's page cache on the host. The owner decides.
- **The SSH warm-up list.** Which Nodes count as "used in the last 10 minutes", and whether the warm-up delays the switch by more than its saving.

## Rejected alternatives

- **Keep PHP-FPM inside the container.** It keeps the boot on every request, which G2 removes.
- **FrankenPHP's own worker loop without Octane.** It would copy Octane's list of state resets and drift from Laravel with every framework release.
- **FrankenPHP in classic mode, as ADR 0263 part 9 serves Instances.** Classic mode boots Laravel per request; it saves only the FastCGI hop.
- **Move the WireGuard interface or address into live.** A move at each switch cuts the requests in flight, and the role convergence owns WireGuard on the host.
- **Switch by moving one bridge address between containers.** Only one container can hold it, so the previous live's requests in flight would break, and neighbour caches would send packets to the wrong container for a moment.
- **The database inside the container, or a copy per release.** One database must serve hot's migration and live's requests; a copy would split writes.
- **Shared SSH sockets in `ORBIT_HOME` for every container.** Hot and old would ride live's masters, and a stopped container would leave sockets behind.
- **Run the listener only in live, with a gap at the switch.** A wake that lands in the gap pays the fallback.
- **Make the Gateway an Orbit Instance.** Its release would depend on a healthy Gateway ([The Gateway is not an Orbit Instance](/reference/gateway-recovery#the-gateway-is-not-an-orbit-instance)). It reuses ADR 0263's states and parts, not the Instance pipeline.
- **Keep the host release beside the containers.** Two ways to release one Gateway would stay. The host path goes in slice 6.

## Consequences

- A Gateway request no longer pays the Laravel boot. The wake path and the API paths gain the most.
- Gateway code must be safe in a long-lived worker. A singleton, a static cache or a shutdown function that holds a request's data is a defect, and tests refuse the known shapes.
- A release never changes the host's PHP, units or files except the upstream file and the live link. A failed build, migration or readiness check never touches live.
- A rollback to old takes seconds and needs no build. A deeper rollback is a forced release.
- A migration that rebuilds a large SQLite table blocks live's writes while it runs. That was true before; worker mode does not change it.
- During a release, two Gateways run on the Node, and their workers hold memory twice for the drain.
- The first command to each Node after a switch opens a new SSH connection, unless the warm-up opened it.

## Affects

- Components: apps/gateway, apps/docs
- ADRs: [0218](/decisions/0218-take-long-commands-off-the-request-path), [0250](/decisions/0250-run-instances-and-task-groups-in-incus-containers), [0263](/decisions/0263-run-production-releases-in-hot-live-and-old-containers), [0264](/decisions/0264-build-container-php-on-glibc-with-a-cli-only-pcov), [0266](/decisions/0266-sleep-and-wake-containers-through-a-signed-order-executor), [0267](/decisions/0267-run-fast-node-actions-in-a-signed-order-executor)
- Outside these components: the Swamp release workflows and `swamp/scripts/gateway-release-*.sh`, the `gateway` role and `bin/gateway-rollback`
- Detail: [Update and recover a Gateway](/reference/gateway-recovery), [Gateway queue](/reference/gateway-queue), [Node provisioning: SSH connections](/reference/node-provisioning#ssh-connections), [Node agent](/reference/node-agent)
- Verify: the spike of this task group; Pest tests of slice 1; the release cases of slices 3 to 5 on a nested Gateway
