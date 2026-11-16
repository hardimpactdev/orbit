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
- The database is SQLite, `ORBIT_HOME/gateway.sqlite`. `ORBIT_HOME` also holds the SSH identity and known hosts, the WireGuard keys, the root CA, `gateway.app-key` and the SSH connection sockets ([Preserve a complete state set](/reference/gateway-recovery#preserve-a-complete-state-set)). ADR 0266, not built yet, puts its Ed25519 order key in the Gateway's secret store, readable by `orbit`.
- PHP-FPM serves requests through the pool `orbit-gateway`: `pm = ondemand`, `pm.max_children = 8`, `pm.max_requests = 100`, `request_terminate_timeout = 600s` (`GatewayRuntimeFiles::pool`). [ADR 0218](/decisions/0218-take-long-commands-off-the-request-path#the-new-limit-300-seconds) lowers the limit N to 300 s. Caddy on the host reaches the pool over a Unix socket and serves the web build from `web/current`.
- Long-running parts run as host units from the current link: `schedule:work` and Horizon (both Node Processes of the Gateway), `orbit-agent-view.service` (the [agent view subscriber](/reference/node-agent), which ADR 0266 makes the result listener), and the runtime hibernator as a oneshot.
- Horizon's queues live in Valkey on the `services` Node ([Gateway queue](/reference/gateway-queue#connection)). Reverb runs on the `websocket` Node. Swamp serve runs on the Swamp Node, 209 in the live fleet.

A release is one Swamp run, `operation-gateway-release`, with its child `gateway-release-activate` ([Release as a Swamp run](/reference/gateway-recovery#release-as-a-swamp-run)). Its scripts are `swamp/scripts/gateway-release-*.sh`.

Three facts of today drive this design:

- **Every PHP-FPM request boots Laravel.** With a cached configuration, a boot of the Gateway costs *15–40 ms per request (guess)*, before any route code runs. The wake path pays it twice: once for the activation request and once for each poll of the waiting page.
- **Only PHP-FPM opens shared SSH connections.** `SshControlGroup` gives `Master` only to a process in a `php*-fpm.service` control group, because a master dies with the control group that started it ([SSH connections](/reference/node-provisioning#ssh-connections)). A command on a shared connection takes about 18 ms; one on a new connection about 190 ms (measured, same page).
- **A release changes the host.** The worktree, the link swap, the PHP-FPM reload and the unit restarts all act on the Gateway Node itself. A failed release can leave the host half switched. An operator then repairs it with `bin/gateway-rollback`, which works without Swamp and without the Gateway.

## Decision

The parts below take G1–G3 as given. Part 1 maps each release step of today, part 2 says what stays on the host and how traffic switches, part 3 moves the long-running parts, part 4 sets the worker, part 5 the security and part 6 the move from today's Gateway. Part 7 sums the costs per request; parts 8 and 9 hold the slices and the open points.

### 1. Release and rollback, step by step

The Gateway keeps its one release record and its one Swamp run per release. The run still reaches the Gateway Node over SSH from the Swamp Node, so a release never depends on a healthy Gateway. What each step does changes:

| Step today | Script today | Becomes |
| --- | --- | --- |
| Fetch the commit into `shared/orbit.git` | inline in the workflow | The same, on the host. The host's bare repository stays the Gateway's mirror |
| Build the release: worktree, links, `composer install --no-dev` in `apps/cli` and `apps/gateway`, agent manifest, `REVISION`, `config:cache` | `gateway-release-build.sh` | **Clone hot**, then build in it, as [Hot](#hot-and-ready) describes |
| Install the web build into `web/releases/<id>`, with `sudo` for the `caddy` group | `gateway-release-web.sh` | The same checks; the build goes into hot, as [Hot](#hot-and-ready) describes. No `sudo`: the container's own Caddy reads it as `orbit` |
| — | — | **Release snapshot.** New: hot's Incus snapshot `release`, before FrankenPHP starts |
| Snapshot the database with `VACUUM INTO` | `gateway-release-snapshot.sh` | Unchanged, on the host. The database never moves into a container |
| Migrate with `artisan migrate --force --isolated`, from the new release, while the old serves | inline | The same command, run in hot through `incus exec`, while live serves. See [Migrations](#migrations-across-hot-and-live) |
| Wait for active Operations (the [release idle guard](/reference/gateway-recovery#release-idle-guard)) | `artisan gateway:release:wait-idle` | Unchanged, run in hot |
| — | — | **Ready.** New, before the Swamp load, as [Hot](#hot-and-ready) describes |
| Load Swamp on the Swamp Node | child workflow | Unchanged |
| Drain Horizon | `gateway-release-drain.sh` | The same rule (stop the unit, wait for its whole control group to empty), run in live through `incus exec` |
| Switch: both links, PHP-FPM reload, restart of every service under the application | `gateway-release-switch.sh` → `bin/gateway-rollback` | **Switch.** The host replaces one Caddy upstream file and the live link, and reloads Caddy once. No unit on the host restarts |
| Verify `/up` and the status version through `https://gateway.orbit` | inline | Unchanged, through the public name, right after the switch |
| Switch back after a failed verify | `gateway-release-switch-back.sh` | Put the previous upstream file and live link back and reload Caddy once. The previous live still runs, so this starts nothing |
| Start Horizon, always | inline | Start Horizon in whichever container is live at the end, always. The rest of the [handover](#3-long-running-parts-and-their-switch) follows |
| Admit nested-fleet refresh | inline | Unchanged, run in live after Horizon starts |
| Resume document cleanup | `artisan gateway:release:cleanup` | Unchanged, run in live |
| Prune old releases | `gateway-release-prune.sh` | **Retire.** After the previous live has no request in flight, stop it as old and delete the earlier old container. ZFS keeps a dataset while a clone depends on it |

[ADR 0267](/decisions/0267-run-fast-node-actions-in-a-signed-order-executor) keeps the `gateway-release-*.sh` scripts as release steps outside the executor. This design changes what they do, not where they run.

#### Hot and ready

- **Clone.** Hot is a ZFS clone of live's `release` snapshot, named `gateway-<id>`. The clone copies no file and takes about 1 s, as ADR 0263 measured for Instances.
- **Build.** The host pushes the commit into hot, which checks it out. `composer install --no-dev` runs in `apps/cli` and `apps/gateway` in hot, with live's `vendor/` and Composer cache, so a commit without a lock change installs nothing. The agent manifest, `REVISION`, the web build and `config:cache` follow, inside hot.
- **Checks.** The downgrade check stays. The disk check becomes the capacity check of [ADR 0263 part 8](/decisions/0263-run-production-releases-in-hot-live-and-old-containers#8-capacity-an-optional-limit-and-an-admission-check) for the pool.
- **Web build.** FrankenPHP's Caddy in the container serves it, so code and web build switch together in one step. Each asset pays the bridge hop, *under 0.2 ms (guess)*; the files are hashed and cached by browsers.
- **Release snapshot.** As in ADR 0263, hot takes its Incus snapshot `release` after its build and before any request reaches it. A hot that fails a step after its snapshot is removed with that snapshot, so no next hot clones it.
- **Ready.** After the migration and the idle guard, and before the Swamp load, hot starts FrankenPHP. The run checks `/up` and `/api/v1/gateway/status` through hot's bridge address, with `Host: gateway.orbit`. A hot that is not ready is removed. Live's code never changed, but its schema did, as after a failed release today. Swamp still holds live's workflows, because the load comes after ready.

**Rollback.** `gateway:release:rollback` without an id returns to old, as `instance:rollback` does in ADR 0263:

1. Start old, cache its configuration again from the current env file, start FrankenPHP and check old as ready.
2. Load old's commit into Swamp with `force`, as today's rollback does. Workflow inputs stay compatible only forward, so old's code needs its own workflows.
3. Switch, verify and hand over. The rolled-away live becomes old, so a second rollback goes forward again.
4. Pause automatic releases with reason `rollback`, as today.

Rollback builds nothing and restores no snapshot. Expand and contract makes the old code safe on the new schema, as today. Today's rollback runs `migrate`, which has nothing to do for an older commit; that step goes. A rollback further back is a forced release of the earlier commit. The `<id>` argument goes.

**Rollback without Swamp.** `bin/gateway-rollback` becomes a host script that does only the switch and the handover to old: start old, check `/up` on its bridge address, replace the upstream file and the live link, reload Caddy, move the units. It needs Incus and Caddy, not Swamp and not the Gateway. As today, it loads no Swamp workflows: when the Gateway answers again, the operator admits a release of the serving commit to load them.

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
| Swamp serve, its grants and its vaults (such as `orbit-control-inputs`) | Swamp Node | Unchanged. The Gateway writes execution secrets into the vault from whichever release is live, as [Swamp access](#swamp-access) says |
| The release run's SSH access | Host account `orbit` with its sudo rules | The Swamp worker logs into the host, never into a container. The host runs `incus` for the run |

#### Swamp access

The release run starts on the Swamp Node and reaches the host over SSH. On a bootstrapped Gateway, Swamp runs on the Gateway's own machine: still on the host, never in a release container.

#### Network and the switch

**Network.** The Gateway's containers sit on their own bridge, `orbitgw0`, with no other container on it. The host masquerades their outbound traffic to WireGuard peers behind its own WireGuard address. A hairpin rule does the same for traffic to the host's own WireGuard address, such as SSH to the Gateway Node, `metrics.orbit`, and a nested Gateway's local Valkey or Swamp. Every Node, Valkey, Reverb and Swamp therefore still sees the Gateway's one address, and no firewall rule changes. Cost per request: one NAT lookup per new connection, *under 10 µs (guess)*. FrankenPHP in the container listens with plain HTTP on the bridge only.

**The caller's address.** The Gateway knows a Node by the address that sent the request: `RequireActiveWireGuardPeer::activePeer`, `ApiDispatcher` and `RecordCommandActivity` read `REMOTE_ADDR`. Behind the proxy, `REMOTE_ADDR` is the host's bridge address, so every peer-only route would answer `peer.identity_unknown`. So:

- The host's Caddy replaces `X-Forwarded-For` with the connection's own address, so a client cannot set it.
- The Gateway trusts that header only from the host's bridge address, and the three readers use `$request->ip()`.
- A test sends a request through a trusted proxy and finds its Node; one from an untrusted address with a forged header finds none.

Cost: none measurable.

**What the upstream covers.** Every part of the host's Caddy that reaches the Gateway's PHP or its release files today moves behind the upstream: the Gateway site (today `php_fastcgi` on the pool's socket), the `forward_auth` of `/grafana`, the `metrics.orbit` site's authorization, and the Horizon dashboard assets under `/vendor/horizon-new-dawn/`, which the container's Caddy serves. The host's Caddy keeps `flush_interval` for streamed responses.

**Who writes the site.** Node converge publishes the Gateway site today. The site's form follows one recorded state of the Gateway Node, `host` or `container`, which only the [move](#6-rollout-from-todays-gateway) sets. Converge renders the `import` of the upstream file only in `container` state, and never writes the upstream file. The switch is its only writer. So a converge between two steps of the move cannot publish an import with no container behind it.

**The switch.** The Gateway's Caddy site on the host imports one upstream file, `/etc/caddy/orbit-gateway-upstream.caddy`, which names live's bridge address. The switch writes the new file beside it, renames it over the old one, replaces the live link `/home/orbit/gateway-live`, and runs one `caddy reload`. A failed reload puts both back, and live serves unchanged. Caddy's reload is graceful: requests that started on the previous live finish there, and new requests go to the new live. No request is dropped, because the previous live keeps running until it has no request in flight:

- Retire reads FrankenPHP's `frankenphp_busy_workers` metric on the previous live and stops it only when the metric is 0, or when N has passed since the switch. N is the request limit of [ADR 0218](/decisions/0218-take-long-commands-off-the-request-path#the-new-limit-300-seconds), read from its one setting: 600 s today, 300 s after ADR 0218's limit change.
- Caddy's `grace_period` is set to N, so a reload never cuts a request that the request limit still allows.

Cost per request: the hop from the host's Caddy to FrankenPHP over the bridge replaces today's FastCGI hop over a Unix socket. *Under 0.2 ms either way (guess)*; the spike measures both. The switch itself costs no request any time: the reload is in Caddy, not in PHP.

**An address move was rejected** (see Rejected alternatives): one address cannot serve two containers at once, so the requests in flight on the previous live would break.

### 3. Long-running parts and their switch

ADR 0263's one rule holds, with one exception. A release container's units are enabled only while it is live, and only live starts with the Node. Hot installs them disabled, so its snapshot holds them disabled. The exception: the result listener starts in hot at step 1 below, while the previous live's listener runs until step 6. So it runs in two containers across the switch.

**Units and Process records.** Horizon and the scheduler are Node Processes today: Process records `horizon` and `schedule-work`, with host units that the `gateway` role's convergence writes, and the release intent names the Horizon unit as `horizon_unit`. In containers, hot renders these units from the same `GatewayRuntimeFiles` code when it builds, so each release carries its own units. The role no longer writes them. The Process records stay; `process:*` and the journal reads act on live, as [ADR 0263 part 5](/decisions/0263-run-production-releases-in-hot-live-and-old-containers#5-workers-schedules-and-reverb-run-only-in-live) does for Instances. `horizon_unit` names the unit inside the container.

The order of a release around the switch:

1. Start the listener in hot.
2. Drain Horizon in live.
3. Switch, and verify through the public name. A failed verify switches back.
4. Start Horizon in whichever container is live now.
5. Move the scheduler and the hibernator timer to the live container.
6. Stop the listener in the container that is not live.
7. Resume the release cleanups, then retire.

Each part, with its cost:

- **Horizon and its queues.** As today: drain Horizon in live before the switch, switch, then start Horizon in the new live, always. The drain stops the unit and waits for its control group to empty, up to the drain. Jobs that requests dispatch during the drain wait in Valkey, and a job finishes on the release that started it. Cost: no request waits for the drain, because live serves until the switch. Queued jobs wait up to the drain, as today.
- **Scheduler** (`schedule:work`). Stop it in the previous live, then start it in the new live. The handover never runs in the last 2 s or the first 2 s of a minute, so no minute fires twice or is skipped. The release cleanups pause and resume by generation, as today. Cost: none per request. A minute's tick starts up to 2 s late during a release (guess).
- **Runtime hibernator.** A container timer, enabled only in live. Cost: none.
- **ADR 0266 result listener** (`orbit:agent-view`). It starts in the new live **before** the switch and stops in the previous live after it, so one listener always runs.
  - Both listeners may receive one result. Recording a result becomes a conditional write that changes only an Operation without a result, so it is recorded once. Each listener still pushes every accepted result to `executor:op:<operation>`, as ADR 0266 says: a repeat that recovers a lost push changes no row, and must still push. A duplicate push does no harm: `BLPOP` takes one, and the key expires after 60 s.
  - Cost: with a gap, a wake in the gap misses its `accepted` line and pays the SSH relay fallback, *18–190 ms*, or up to the 6 s wait. With the overlap, no wake pays it.
- **Reverb clients.** The web app and the agents talk to Reverb on the `websocket` Node, not to the Gateway, so they do not reconnect. The Gateway's broadcasts are HTTP calls to Reverb from whichever release serves. Cost: none.
- **SSH connections.** The next paragraphs. Cost: one new connection per Node after a switch.

**SSH ControlMaster rule.** `SshControlGroup` keeps its reason and changes its match. In the container, FrankenPHP runs as the unit `orbit-gateway-web.service`, and its control group outlives every request, as PHP-FPM's did. So `Master` goes to a process in that unit's control group, and `php*-fpm.service` leaves the match. Horizon stays `Alone`; every other process rides an open master or opens its own connection.

The connection sockets move from `ORBIT_HOME/ssh/mux`, which every container mounts, to the container's own `/run/orbit/ssh-mux`. Otherwise hot's migration and readiness commands, or old after a rollback, would ride live's masters across containers, and a stopped container would leave sockets behind that the next live trusts. The cost: after a switch, the first command to each Node from the new live opens a new connection, *about 190 ms instead of 18 ms*, once per Node. A warm-up before the switch would save that cost for the busiest Nodes, but only the FrankenPHP unit opens masters, so it needs a new internal endpoint. That is a new way in, and it stays an open point.

A master started by a FrankenPHP thread is a child of the FrankenPHP process, and a worker restart does not end it. Only the unit's stop ends it, and retire stops the unit only after the drain.

### 4. FrankenPHP worker mode

**The worker.** FrankenPHP from the runtime folder ([ADR 0264](/decisions/0264-build-container-php-on-glibc-with-a-cli-only-pcov)) runs Laravel Octane's FrankenPHP worker script, `vendor/laravel/octane/bin/frankenphp-worker.php`, from an Orbit Caddyfile under the unit `orbit-gateway-web.service`. Orbit owns the unit, the binary and the Caddyfile; `octane:start` does not run. Octane brings the state resets that a Laravel worker needs: a new application sandbox per request, and flushes of the auth, session, request and scoped bindings. A hand-written `frankenphp_handle_request()` loop was rejected: it would copy that list and drift from Laravel. `laravel/octane` is not installed today; it becomes a production dependency of `apps/gateway`.

**Per-request saving.** Worker mode removes the boot, *15–40 ms per request (guess)*, and keeps three connections open across requests:

- the PDO connection to SQLite: *about 0.1 ms per request (guess)*;
- the Predis connection to Valkey with its login (`HELLO`, `CLIENT SETINFO`, `SELECT`): *1–2 ms per request that uses Valkey (guess)*, which includes every wake through ADR 0266's `BLPOP`;
- the HTTP connection to Reverb's API for the signed `executor-order` event: *2–10 ms per wake (guess)* for the TCP and TLS setup. This saving needs a code change. `RealtimeConnection::configureBroadcasting` builds a new `CurlHandler` for every delivery, and channel auth purges the broadcaster, so today no connection survives a request even in a worker. Slice 1 keeps one handler per Reverb connection; until then this saving is 0.

The wake inside the request therefore costs the route, the Ed25519 signature (*about 50 µs, guess*), one Reverb publish and one `BLPOP`, with no boot. Compared with ADR 0266's 500 ms bound, the boot alone was up to 8 % of it.

**`defer()` and after-response work.** Two mechanisms run work after the response. Laravel's `terminating` callbacks: `RecordEventBroadcaster` delivers each record broadcast that way, and `TasksServiceProvider` and `RequestLogContext` register one each. And `defer()`, at four call sites: `ScheduleInstanceRuntimeWakeAction` (the runtime activation), `InstanceWake` (twice: the resume on the Node and the resume cleanup) and `CancelOperationAction` (the forward to Swamp). Octane is expected to send the response first and then run Laravel's deferred callbacks in the same worker, as PHP-FPM does after `fastcgi_finish_request()`; this is not checked against Octane's source yet. So `defer()` and `terminating` stay, and no call site changes for worker mode. The cost is capacity, not latency: a deferred callback holds its worker until it ends, and a worker pool is fixed where `ondemand` forked a new child. The wake's deferred resume can take seconds over SSH. ADR 0266 moves that work into the executor; until it does, the pool counts deferred work (below). ADR 0218 keeps its own precondition: no endpoint runs long work after its response before its limit change. This design does not loosen it. The spike checks that the response reaches the client before the callback runs.

**State that must not leak.** In PHP-FPM every request started empty. In a worker, static properties, singletons and anything resolved at boot live as long as the worker. Each of these must hold no request's data:

- **Node identity per request.** A singleton that holds a Node, an SSH target or a caller would serve the next request with the previous Node. Classes that carry a Node take it as an argument or are bound `scoped`. No such singleton is known today; slice 1 checks every binding. An architecture test lists every `singleton` binding and refuses a binding whose class has a Node, Instance, user or Operation property.
- **Static state.** The known statics: `RequestLogContext::$depth` and `$requestId` (a request id that leaks marks the next request's logs), `RecordEventBroadcaster::$immediate`, `RealtimeConnection::$caFactory` (a factory, safe), `SwampHandshakes::$warned`, `SourceDigest::$cache`, `SourceClosure::$cache` and `$roots`, `AgentSchemas::$commands` (code facts of the release, safe to keep if they read only release files; slice 1 confirms), `OperationRunBudgets::$history` (scoped to one tick, safe), and `ActivityShutdownFinalizer::$armed` (not safe, next item). Slice 1 resets each unsafe one at the request end. A test fails on any mutable static that is not on an allow list with its reason.
- **Closures over the base container.** `TasksServiceProvider` registers a `terminating` callback that captures `$this->app`, the base container, not the copy that Octane gives each request. Its `TaskBroadcasts` flush can then read the wrong copy. Slice 1 resolves the container when the callback runs.
- **`ActivityShutdownFinalizer`.** It registers one `register_shutdown_function` per request. In a worker, shutdown functions run only when the worker exits, so one closure piles up per request until then, and `disarm()` cannot remove it. It changes to disarm and finalize at Octane's request end (`RequestTerminated`), and to keep one shutdown function per worker for a fatal error that ends the worker. Its comment already names `forgetArmed()` for long-lived processes.
- **Configuration.** A worker reads `bootstrap/cache/config.php` once. `gateway:release:configure` therefore restarts live's workers gracefully after it renames the cache. Request code writes configuration today: `RealtimeConnection::configureBroadcasting` sets the Reverb connection on each broadcast and channel auth. Octane gives each request its own copy of the configuration, so such a write ends with its request. This comes from Octane's documented sandbox, not from its source, which is not installed here; slice 1 confirms it with a test of two requests.
- **Connections.** At the request end, any open database transaction rolls back, and a Valkey connection that a client-side timeout cut is closed. A `BLPOP` uses the server-side timeout with a client read timeout above it. Otherwise a late reply would reach the next command on that connection and hand one request another request's result.
- **`/proc/self/cgroup`.** `SshControlGroup` reads it per call; that stays correct in a worker.

**Worker count and memory.** Today's pool allows 8 requests at once. The worker pool starts at 16 workers, with the PHP `memory_limit` of today and `max_requests` of 500, after which Octane restarts the worker to cap a slow leak. A worker holds *60–100 MB (guess)*, so 16 workers hold *1–1.6 GB*. ADR 0218's commands that stay in the request may take up to 180 s, and `doctor` up to the command deadline (570 s today, 270 s after ADR 0218's limit change), so 16 slow commands hold every worker, and a wake that arrives then waits for a free one. If the runtime folder's FrankenPHP supports `match` in a worker block (the spike checks the version), one Caddyfile can give a second worker pool to the wake and the short API paths: `runtime-activations/*`, `/up`, `gateway/status` and the read endpoints. The split is a guess until the spike shows the queue under load; the open points name it.

**The request limit.** Today's pool sets both `request_terminate_timeout` and `max_execution_time` to N, 600 s, and the host's Caddy times out at N. FrankenPHP has no such kill, so `max_execution_time = N` stays the limit in PHP, with the host Caddy's read and write timeouts at N as today. Every number that this design derives from N (the drain, `grace_period`) reads ADR 0218's one setting, so its change from 600 s to 300 s reaches them. A request that hits `max_execution_time` ends with a fatal error, and FrankenPHP restarts that worker. The spike checks whether the ZTS execution timer measures wall time or CPU time; if it measures CPU time, a request that waits on SSH is not bounded by it, and ADR 0218's command deadlines remain the bound.

**OPcache.** Each container's OPcache is private and built once per worker boot. `opcache.validate_timestamps` turns off: a release container's code never changes, so the per-file stat of today goes. *A few µs per included file per request (guess)*.

### 5. Security

Unlike an app container, the Gateway's container is the control plane. Anyone who runs code in live already reaches every Node as `orbit` with sudo. The container does not make the Gateway safer against its own code, and this design does not claim it. It isolates releases from each other and from the host.

- **What the container holds.** The release and, through mounts, `ORBIT_HOME` and `gateway.env`. The cached configuration in a release snapshot holds the env's secrets, as ADR 0263's `.env` in a snapshot does. The snapshot stays in the Gateway Node's pool, readable only by root, inside the same boundary as `ORBIT_HOME`.
- **What it does not hold.** The host's SSH host keys, Caddy's TLS keys, the WireGuard private key, the Incus socket, sudo and the release run's SSH login. From inside, a release cannot start, stop or reconfigure a container.
- **What it does not stop.** The Gateway Node is a fleet Node that the Gateway converges itself. So live reaches its own host over SSH with sudo, like every other Node. The container keeps release steps off the host and releases apart from each other. It is no wall between Gateway code and the host.
- **Privileges.** An unprivileged container (`security.privileged=false`, `security.nesting=false`) with the default idmap. Its only user is `orbit`. The mounts of `ORBIT_HOME`, `gateway-storage` and `gateway.env` use Incus idmapped mounts (`shift=true`), so files stay owned by the host's `orbit` with their modes of today. No `raw.idmap`, no `raw.lxc`.
- **Network.** The bridge `orbitgw0` holds only Gateway containers and accepts inbound traffic only from the host. Outbound traffic leaves through the host's NAT, the same paths the host uses today: WireGuard peers and the egress the build needs (GitHub, Packagist).
- **Host keys and certificates it needs.** It reads `known_hosts` and its SSH identity from `ORBIT_HOME/ssh`, and the root CA from `ORBIT_HOME/ca` for TLS to internal names. It needs no host certificate: TLS ends on the host.

### 6. Rollout from today's Gateway

The move keeps the same commit live and changes only where it runs. The orchestrator owns releases, windows and host changes, so it runs these steps:

1. **Turn automatic releases off** with `gateway:auto-release:disable`, so no release starts during the move.
2. **Converge the `gateway` role with the container setup**: Incus, a ZFS pool for the Gateway's containers, the bridge `orbitgw0`, the NAT rule and the runtime folder. No traffic changes. A Node without a spare disk gets a pool on a file; the open points name this.
3. **Release the commit that holds the container workflows, through today's flow.** The release that adds this design's workflows still builds a worktree and reloads PHP-FPM. Its Swamp load installs the new workflows, unused. The Gateway Node's recorded site state stays `host`, so a converge keeps publishing `php_fastcgi`.
4. **Run `gateway:release:migrate`** (a new one-time run, `operation-gateway-container-migrate`) at the same commit. It refuses while a release runs. It builds a cold hot from the image, mounts `ORBIT_HOME`, runs no migration (the schema is current), checks readiness on the bridge address, and switches: it sets the site state to `container`, and the Caddy site goes from `php_fastcgi` on the pool's socket to the upstream file, in one reload. PHP-FPM finishes its requests in flight. It hands over in the order of [part 3](#3-long-running-parts-and-their-switch): the container's listener starts and the host's Horizon drains before the switch. After the switch and its verify, Horizon starts in the container, and the scheduler, hibernator timer and agent view move from the host to the container. The host units stay installed and disabled.
5. **Check** `/up`, `gateway:status` and one wake of a sleeping clone through the public name.
6. **Rollback during the move** is the inverse: set the site state back to `host`, restore the Caddy site with `php_fastcgi`, reload, and enable the host units again. The host release stays until step 8.
7. **Release one commit through the container flow**, with automatic releases still off. It builds a warm hot from the moved release's snapshot, so the moved release becomes old.
8. **Remove the host path** after that release is verified: the worktree releases, the PHP-FPM pool and its unit, the host units, `web/releases`, and the old-paths entries. Turn automatic releases on.

No step needs a maintenance window. The orchestrator owns the host change of step 2 and picks its time. It picks a quiet time for step 4 too, because the drain of the host's Horizon runs as long as its longest job. The nested Gateways of task copies and proofs bootstrap the same way: `workflow-gateway-bootstrap` builds a cold hot instead of a worktree, so there is one path. Whether a nested Gateway's machine allows a container inside it is an open point.

### 7. Cost per request, in one table

| Choice | Wake and API request cost | Status |
| --- | --- | --- |
| Worker mode instead of PHP-FPM | Saves the Laravel boot, 15–40 ms | guess |
| Open Valkey connection | Saves 1–2 ms per request that uses Valkey | guess |
| Open Reverb API connection | Saves 2–10 ms per wake, after slice 1 keeps the handler | guess |
| `validate_timestamps` off | Saves a few µs per included file | guess |
| Web build served from the container | Under 0.2 ms more per asset | guess |
| Host Caddy to container over the bridge, instead of FastCGI on a socket | ± 0.2 ms | guess |
| NAT to WireGuard peers | Under 10 µs per new connection | guess |
| SSH sockets per container | 190 ms instead of 18 ms, once per Node after a switch | measured per command |
| Listener overlap during a switch | Saves 18–190 ms or up to 6 s for a wake that lands in the switch | from ADR 0266's fallback rules and the SSH figures |
| A table rebuild in a migration | Blocks writes for its duration | guess |
| The switch itself | 0: one Caddy reload, requests in flight finish | design |

### 8. Slices

Each slice is one pull request. Slices 3 to 7 depend on ADR 0263's build subtasks 1, 2, 4 and 5 for the clone, snapshot, ready and switch parts, which this design reuses for the Gateway's own record.

1. **Worker-safe Gateway.** `ActivityShutdownFinalizer` per request; resets of the unsafe statics; the `TasksServiceProvider` closure; scoped bindings for every class that holds a Node; the static allow list and the singleton test; the Valkey and transaction resets; one Reverb handler per connection; `laravel/octane` with Orbit's Caddyfile. PHP-FPM still serves production.
   - Proof: Pest tests that serve two requests for two Nodes in one worker and find no carried state; the spike's numbers.
2. **Caller address behind a proxy.** `X-Forwarded-For` from the host's bridge address only; the three `REMOTE_ADDR` readers use `$request->ip()`.
   - Proof: Pest tests of a peer-only route through a trusted proxy, and of a forged header from an untrusted address.
3. **Gateway role container setup.** Incus, the pool, `orbitgw0`, the NAT and hairpin rules, the image with only `orbit`, the idmapped mounts, and the site state `host` or `container` that picks the Caddy site's form.
   - Proof: a fixture `gateway` Node converged twice, with no change the second time.
4. **Hot build and ready.** Clone, push, `composer install`, web build, `config:cache`, the units that hot renders, the `release` snapshot, migrate in hot, ready on the bridge address before the Swamp load, removal on failure.
   - Proof: release cases on the nested Gateway; a failed step and a failed readiness leave live unchanged.
5. **Switch, handover and retire.** The upstream file over every Caddy consumer and the live link; the graceful reload with `grace_period`; the busy-worker drain; the listener overlap with its conditional record; the scheduler's minute guard; `process:*` on live; the SSH socket move and the `SshControlGroup` match; switch-back and retire.
   - Proof: a load loop through a release with one 60-second request across the switch: no failed request, one scheduler tick per minute, no lost wake result.
6. **Rollback.** `gateway:release:rollback` to old with its forced Swamp load and pause, and the host's `bin/gateway-rollback`.
   - Proof: rollback and forward again on the nested Gateway, under the load loop.
7. **Move and remove.** `gateway:release:migrate`, the bootstrap through a cold hot, then the removal of the host path. The [Update and recover a Gateway](/reference/gateway-recovery) page absorbs this ADR.
   - Proof: the move on the nested Gateway, then on the live Gateway by the orchestrator.

### 9. Open points

- **The pool on the Gateway Node.** The live Gateway Node may have no spare disk for a ZFS pool. A pool on a file works, but its write speed under SQLite's `fsync` is not measured. The database stays on the host's file system either way.
- **A nested Gateway inside a container.** If a task copy's nested Gateway machine is itself an unprivileged container, the Gateway's own container needs nesting there. The spike shows which machine the nested Gateway runs on.
- **The worker split.** Whether the wake and short API paths need their own worker pool, and how many workers each pool gets, waits for the spike's queue numbers under a slow `doctor`.
- **`max_execution_time` under ZTS.** Whether it bounds wall time. If not, ADR 0218's command deadlines are the only bound in a request.
- **Deferred work in the wake.** The deferred resume over SSH holds a worker for seconds until ADR 0266's executor replaces it. Moving it to a queued job earlier would free the worker but add the queue's pickup, about 1 s ([Gateway queue](/reference/gateway-queue#queues)), to the wake.
- **Memory limit.** ADR 0263 sets no memory limit by default. The Gateway may want one so that a leaking worker cannot starve SQLite's page cache on the host. The owner decides.
- **Octane's after-response order.** Whether Octane's FrankenPHP worker sends the response before it runs deferred callbacks. If not, each `defer()` call site adds its work to the response time, and the wake's deferred work moves to a queued job.
- **An SSH warm-up.** Whether to open masters to the busiest Nodes from hot before the switch. It needs an internal endpoint, which is a new way in, and a rule for which Nodes count.

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
- A release changes no PHP, unit or file on the host except the upstream file and the live link. A failed build, migration or readiness check never touches live's code. A migration that ran stays, as today.
- A rollback to old takes seconds and needs no build. A deeper rollback is a forced release.
- A migration that rebuilds a large SQLite table blocks live's writes while it runs. That was true before; worker mode does not change it.
- During a release, two Gateways run on the Node, and their workers hold memory twice for the drain.
- The first command to each Node after a switch opens a new SSH connection.

## Affects

- Components: apps/gateway, apps/docs
- ADRs: [0218](/decisions/0218-take-long-commands-off-the-request-path), [0250](/decisions/0250-run-instances-and-task-groups-in-incus-containers), [0263](/decisions/0263-run-production-releases-in-hot-live-and-old-containers), [0264](/decisions/0264-build-container-php-on-glibc-with-a-cli-only-pcov), [0266](/decisions/0266-sleep-and-wake-containers-through-a-signed-order-executor), [0267](/decisions/0267-run-fast-node-actions-in-a-signed-order-executor)
- Outside these components: the Swamp release workflows and `swamp/scripts/gateway-release-*.sh`, the `gateway` role and `bin/gateway-rollback`
- Detail: [Update and recover a Gateway](/reference/gateway-recovery), [Gateway queue](/reference/gateway-queue), [Node provisioning: SSH connections](/reference/node-provisioning#ssh-connections), [Node agent](/reference/node-agent)
- Verify: the spike of this task group; Pest tests of slice 1; the release cases of slices 3 to 5 on a nested Gateway
