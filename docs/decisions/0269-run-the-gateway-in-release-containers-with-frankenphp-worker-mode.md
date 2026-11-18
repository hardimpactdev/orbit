---
title: "ADR 0269: Run the Gateway in release containers with FrankenPHP worker mode"
sidebarTitle: "0269 Gateway containers"
description: "In progress, proposed, design only. The Gateway runs in an Incus container on its own Node, with a permanent preparation container. A deploy builds there, clones the finished release, and switches traffic to the verified clone. FrankenPHP in worker mode, through Laravel Octane's worker, serves it instead of PHP-FPM. The database, keys, Caddy, WireGuard and Swamp access stay on the host. Each choice states its cost per request."
---

# ADR 0269: Run the Gateway in release containers with FrankenPHP worker mode

The Gateway uses at most three Incus containers on its dedicated Node. Preparation has a writable checkout and sleeps between releases. Live serves HTTP and runs Horizon. The third slot holds the privately checked candidate before cutover or the former live during drain.

FrankenPHP serves live in worker mode: each worker boots Laravel once and then serves many requests. PHP-FPM goes. State that must outlive a release stays on the host: the SQLite database and every key in `ORBIT_HOME`, Caddy with its certificates, WireGuard and the Gateway's address. The host's Caddy switches traffic from live to hot with one graceful reload.

After cutover the Gateway repairs forward. It deletes the former live once HTTP requests and Horizon jobs have drained. A timeout preserves the container and its journal so an operator can resume the drain. The next deployment must respect the same three-container limit.

## Status

In progress.

Gateway and app-prod use the same Swamp build, verification and handover workflow. Gateway runs Laravel Octane in FrankenPHP worker mode. Production adoption remains open. The timing comparison between PHP-FPM and FrankenPHP also remains open.

Principle: [One way, one name](/mission#principles). The Gateway uses the release states and traffic switch of ADR 0263. Its clone source is a permanent preparation container. It also serves [No exceptions and no legacy](/mission#principles): the worktree releases, the PHP-FPM pool and `bin/gateway-rollback` go when their replacements are built.

### The owner's decisions

Nick amended the Gateway release decision on 2026-11-18.

- **G1. Fix forward.** Update the permanent preparation container in place. Seal and freeze it, clone its filesystem, and verify the candidate. Commit the forward switch and let old work finish. Remove the drained release.
- **G2. FrankenPHP worker mode.** FrankenPHP in worker mode serves the live Gateway, with Laravel booted once per worker. PHP-FPM goes, for faster requests on the wake and API paths.
- **G3. Every millisecond counts.** As [ADR 0266 D3](/decisions/0266-sleep-and-wake-containers-through-a-signed-order-executor#the-owners-decisions) says for the wake, speed on the wake and API paths is measured in milliseconds. This design states what each choice costs or saves per request. A number marked *guess* is not measured yet; the spike measures it.

## Context

Today the Gateway runs on its Node's host from [immutable releases behind one link](/reference/gateway-recovery#release-layout):

- Each release is a Git worktree in `/home/orbit/releases/<id>/` with its own `vendor/`. `/home/orbit/orbit` links to the current release. `apps/gateway/.env` links to `/home/orbit/shared/gateway.env`, and `storage` links to `/home/orbit/shared/gateway-storage/`.
- The database is SQLite, `ORBIT_HOME/gateway.sqlite`. `ORBIT_HOME` also holds the SSH identity and known hosts, the WireGuard hub keys in `ORBIT_HOME/wireguard/` (`VpnConfigurationRepository` reads `private.key` there to render the hub configuration), the root CA, `gateway.app-key` and the SSH connection sockets ([Preserve a complete state set](/reference/gateway-recovery#preserve-a-complete-state-set)). ADR 0266, not built yet, puts its Ed25519 order key in the Gateway's secret store, readable by `orbit`.
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

### 1. Release and recovery, step by step

The Gateway keeps its one release record and its one Swamp run per release. The run still reaches the Gateway Node over SSH from the Swamp Node, so a release never depends on a healthy Gateway. What each step does changes:

| Step today | Script today | Becomes |
| --- | --- | --- |
| Fetch the commit into `shared/orbit.git` | inline in the workflow | The same, on the host. The host's bare repository stays the Gateway's mirror |
| Build the release: worktree, links, `composer install --no-dev` in `apps/cli` and `apps/gateway`, agent manifest, `REVISION`, `config:cache` | `gateway-release-build.sh` | Build in the preparation container, as [Hot](#hot-and-ready) describes |
| Install the web build into `web/releases/<id>`, with `sudo` for the `caddy` group | `gateway-release-web.sh` | The same checks; the build goes into the preparation container, as [Hot](#hot-and-ready) describes. No `sudo`: the container's own Caddy reads it as `orbit` |
| — | — | **Release snapshot.** New: snapshot the finished preparation container and clone it as hot before starting FrankenPHP |
| Snapshot the database with `VACUUM INTO` | `gateway-release-snapshot.sh` | Container releases skip this step and migrate the shared production database directly |
| Migrate with `artisan migrate --force --isolated=1`, from the new release, while the old serves | inline | The same command, run in hot through `incus exec`, while live serves. See [Migrations](#migrations-across-hot-and-live) |
| Wait for active Operations (the [release idle guard](/reference/gateway-recovery#release-idle-guard)) | `artisan gateway:release:wait-idle` | Existing and new Operations continue during the container release. Their admitted workflow versions outlive the switch |
| — | — | **Ready.** New, before the Swamp load, as [Hot](#hot-and-ready) describes |
| Load Swamp on the Swamp Node | child workflow | Unchanged |
| Drain Horizon | `gateway-release-drain.sh` | Record which workers were running, stop intake on the outgoing release and let its jobs finish |
| Switch: both links, PHP-FPM reload, restart of every service under the application | `gateway-release-switch.sh` → `bin/gateway-rollback` | **Switch.** The host replaces one Caddy upstream file and the live link, and reloads Caddy once. No unit on the host restarts |
| Verify `/up` and the status version through `https://gateway.orbit` | inline | Unchanged, through the public name, right after the switch |
| Failed public verification | `gateway-release-switch-back.sh` on the host PHP-FPM path | Record the failure. Complete the journaled switch and repair forward |
| Start Horizon | inline | Start the selected release’s workers and finish draining the previous generation |
| Admit nested-fleet refresh | Gateway fleet admission | The Gateway admits refresh after the [fleet is current](/reference/gateway-recovery#fleet-rollout), or after verification when fleet rollout is off |
| Resume document cleanup | `artisan gateway:release:cleanup` | Unchanged, run in live |
| Retire | `gateway-release-prune.sh` | Remove the outgoing container after both drains finish. Check deprecated Operation versions separately |

[ADR 0267](/decisions/0267-run-fast-node-actions-in-a-signed-order-executor) keeps the `gateway-release-*.sh` scripts as release steps outside the executor. This design changes what they do, not where they run.

#### Hot and ready

- **Preparation container.** The Gateway Node keeps one between releases. After sealing, freeze preparation and reclaim its memory to swap before taking a filesystem clone. Wake it on the next deployment. Caddy never routes public requests to it. The scheduler, Horizon and the result listener stay disabled there. Only the release run can change its checkout. A failed preparation leaves traffic on live.
- **Build.** The host sends the Git objects missing from the permanent preparation checkout and checks out the exact commit in place. A normal forward release retains the installed Composer dependencies. An interrupted build discards partial dependencies before retrying. `composer install --no-dev` runs in `apps/gateway`. Its autoloader includes two log-stream types from `packages/php-sdk/src`. The install hook reads the SDK source and CLI command definitions to generate OpenAPI and MCP files. The full checkout supplies these files. The release writes the agent delivery manifest and caches the Gateway configuration. It installs the verified CI build of `apps/web` before writing `REVISION` and cloning preparation.
- **Checks.** The downgrade check stays. The disk check becomes the capacity check of [ADR 0263 part 8](/decisions/0263-run-production-releases-in-hot-live-and-old-containers#8-capacity-an-optional-limit-and-an-admission-check) for the pool.
- **Web build.** FrankenPHP's Caddy in the container serves it, so code and web build switch together in one step. Each asset pays the bridge hop, *under 0.2 ms (guess)*; the files are hashed and cached by browsers.
- **Clone.** Validate the commit, agent manifest and web artifact digest after all build steps finish. Only then seal and snapshot the preparation container for this release. A retry of an interrupted build restores a clean checkout and discards partial output before rebuilding. Clone that snapshot as `gateway-<id>`. The snapshot contains files only. No serving process or connection carries into the clone. Check the sealed commit before starting the clone. Only this clone can become live.
- **Ready.** After the migration, and before the Swamp load, hot starts FrankenPHP. The run checks `/up` and `/api/v1/gateway/status` through hot's bridge address, with `Host: gateway.orbit`. A hot that is not ready is removed. Live's code never changed, but its schema did, as after a failed release today. Swamp still holds live's workflows, because the load comes after ready.

**Recovery.** Before the switch, live keeps serving. Repair preparation and retry. After the durable forward decision, recovery completes the switch and drains the outgoing release. A failed public check requires a forward fix. The shared database retains migrations. Console recovery can resume an interrupted switch when the application or Swamp is unavailable.

#### Where the steps run

The host keeps no release worktree, so it needs a rule for where each script comes from and runs:

- **Source.** Every step takes its script from the commit being released, through `git -C /home/orbit/shared/orbit.git show <sha>:swamp/scripts/<script>`, as the build step already does today. So a release never runs another commit's scripts, and the host needs only Git and the bare repository.
- **Host steps.** Fetch, push the commit to the preparation container, clone the finished release, the switch, the handover and retire run on the host as the release run's SSH account, with `sudo` for `incus` and Caddy. They use only shell, Git, `incus` and Caddy.
- **Steps in a container.** Build, web build, migrate, ready, the Horizon drain and the document cleanup run through `incus exec` in the preparation container, hot or live, as `orbit`, with the container's PHP.
- **Offline recovery.** The installed helper resumes the journal recorded by the switch. It may be retried after fixing a failed phase.
- **Host PHP.** None of the steps above needs PHP on the host. Until slice 7 removes the host path, the host keeps `php8.5` and its FPM pool, for the move and its rollback. After slice 7, the `gateway` role installs no PHP. A role that shares the machine and needs PHP, such as `websocket` for Reverb, installs its own.

#### Migrations across hot and live

The rule of today stays: forward only, expand and contract, run before the switch while the old code serves ([Expand and contract](/reference/gateway-recovery#expand-and-contract)). Containers change three things:

- **Hot and live open the same file.** `ORBIT_HOME` is one host directory, mounted into both containers. SQLite's locks and its WAL index are kernel objects of one host file system, so a write in hot is a write that live sees on its next read. No copy and no sync exist.
- **A table rebuild blocks live's writers.** SQLite rebuilds a table for many `ALTER TABLE` changes, and the rebuild holds the write lock. While it runs, every write in live waits on the busy timeout. Cost per request: the rebuild's time for each request that writes during it; *about 1 s per 100 MB of table (guess)*. A migration that rebuilds a large table is split, or runs as a background copy in the expand step.
- **Worker mode keeps connections open.** A live worker keeps its PDO connection across requests. SQLite prepares a statement again when the schema changed, so a migration in hot needs no reload in live. A worker must never hold a read transaction between requests, or the WAL cannot checkpoint while hot migrates; the request end rolls back any open transaction (part 4).

A failed migration stops the deployment before traffic switches and pauses automatic releases. Repair the production database with a corrective migration or an operator change. Rolling back the application preserves the current database.

### 2. What stays on the host, and the switch

The container holds only the release: the code, `vendor/`, the web build, the cached configuration and the container's own `/run`. Everything that a release must not own stays on the host:

| Part | Where | Why |
| --- | --- | --- |
| SQLite database and its WAL | Host `ORBIT_HOME`, mounted into hot, live and old | One database serves every release; a copy per release would split writes. Database backups are independent of container releases |
| `ORBIT_HOME` keys: SSH identity and known hosts, the WireGuard hub keys, root CA, `gateway.app-key`, the Ed25519 order key of ADR 0266 | Host `ORBIT_HOME`, same mount, so the container holds them | A key must survive every release and stay out of release snapshots. [Security](#5-security) says why the container holds the hub key |
| `gateway.env` | Host `/home/orbit/shared/gateway.env`, mounted read-only | One env file. Only `config:cache` in a release reads it |
| `gateway-storage` (file cache, locks, logs, uploads) | Host directory, mounted | File locks and the cache must be the same in hot and live during a deploy |
| Caddy and the TLS certificate of `gateway.orbit` | Host | Caddy is the switch. TLS ends on the host, as on every Node. A release never sees the certificate's private key |
| The WireGuard interface and the Gateway's WireGuard address | Host | Every Node trusts that address. A container would need it moved at each switch, and role convergence owns the interface |
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

**The switch.** The Gateway's Caddy site on the host imports one upstream file, `/etc/caddy/orbit-gateway-upstream.caddy`, which names live's bridge address. The switch writes the new file beside it, renames it over the old one, replaces the live link `/home/orbit/gateway-live`, and runs one `caddy reload`. After the durable selection, recovery republishes that upstream and retries the reload. Caddy's reload is graceful: requests that started on the previous live finish there, and new requests go to the new live. No request is dropped, because the previous live keeps running until it has no request in flight:

- Retire waits for HTTP and Horizon to drain. If the deadline expires or a drain probe fails, preserve the outgoing container and retry recovery.
- Caddy's `grace_period` is set to N, so a reload never cuts a request that the request limit still allows.

Cost per request: the hop from the host's Caddy to FrankenPHP over the bridge replaces today's FastCGI hop over a Unix socket. *Under 0.2 ms either way (guess)*; the spike measures both. The switch itself costs no request any time: the reload is in Caddy, not in PHP.

**An address move was rejected** (see Rejected alternatives): one address cannot serve two containers at once, so the requests in flight on the previous live would break.

### 3. Long-running parts and their switch

ADR 0263's one rule holds, with one exception. A release container's units are enabled only while it is live, and only live starts serving with the Node. After a host reboot the next deploy starts the preparation container from disk, with its Gateway services disabled. Its clones have autostart disabled until promoted. The preparation container installs them disabled. The clone inherits disabled units. The exception: the result listener starts in hot at step 1 below, while the previous live's listener runs until step 6. So it runs in two containers across the switch.

**Units and Process records.** Horizon and the scheduler are Node Processes today: Process records `horizon` and `schedule-work`, with host units that the `gateway` role's convergence writes, and the release intent names the Horizon unit as `horizon_unit`. The preparation container renders these units from the same `GatewayRuntimeFiles` code when it builds, so each release carries its own units. The role no longer writes them. The Process records stay; `process:*` and the journal reads act on live, as [ADR 0263 part 5](/decisions/0263-run-production-releases-in-hot-live-and-old-containers#5-workers-schedules-and-reverb-run-only-in-live) does for Instances. `horizon_unit` names the unit inside the container.

The release remembers which workers were active, fences new work on the outgoing release, commits the switch and starts the selected release’s workers. Public verification and Horizon startup can run concurrently. Recovery continues forward if verification fails. The former live remains available to its in-flight requests and jobs until both drains finish.

- **Horizon and its queues.** Jobs already executing finish on their original workers. The new workers can consume queued jobs admitted by either compatible release. Each Operation retains the workflow version assigned at admission.

- **Scheduler** (`schedule:work`). Stop it in the previous live, then start it in the new live. The handover never runs in the last 2 s or the first 2 s of a minute, so no minute fires twice or is skipped. The release cleanups pause and resume by generation, as today. Cost: none per request. A minute's tick starts up to 2 s late during a release (guess).
- **Runtime hibernator.** A container timer, enabled only in live. Cost: none.
- **ADR 0266 result listener** (`orbit:agent-view`). It starts in the new live **before** the switch and stops in the previous live after it, so one listener always runs.
  - Both listeners may receive one result. Recording a result becomes a conditional write that changes only an Operation without a result, so it is recorded once. Each listener still pushes every accepted result to `executor:op:<operation>`, as ADR 0266 says: a repeat that recovers a lost push changes no row, and must still push. A duplicate push does no harm: `BLPOP` takes one, and the key expires after 60 s.
  - Cost: with a gap, a wake in the gap misses its `accepted` line and pays the SSH relay fallback, *18–190 ms*, or up to the 6 s wait. With the overlap, no wake pays it.
- **Reverb clients.** The web app and the agents talk to Reverb on the `websocket` Node, not to the Gateway, so they do not reconnect. The Gateway's broadcasts are HTTP calls to Reverb from whichever release serves. Cost: none.
- **SSH connections.** The next paragraphs. Cost: one new connection per Node after a switch.

**Gateway Node reboot.** Two readers expect today's host units:

- `FleetReboot::gatewayUnits` names the host units `orbit-gateway-horizon.service` and `orbit-gateway-schedule.service` (or their Process units). The reboot pass stops Horizon on the host and checks those units, with `orbit-agent.service`, after the boot. In containers, the pass drains Horizon in live through `incus exec`. After the boot it checks that live runs, that old and hot stay stopped, and that Horizon and the scheduler run inside live.
- `NodeRoleServiceCatalog` expects `caddy` and `php8.5-fpm` for the `gateway` role, which the agent's watch list and Doctor read. After the move, the role expects `caddy` and `incus` on the host, and Doctor reads the units inside live. The FPM entry goes with the host path in slice 7.

Only live has `boot.autostart`, so a reboot brings back live with its units, as in ADR 0263.

**SSH ControlMaster rule.** `SshControlGroup` keeps its reason and changes its match. In the container, FrankenPHP runs as the unit `orbit-gateway-web.service`, and its control group outlives every request, as PHP-FPM's did. So `Master` also goes to a process in that unit's control group (slice 5). The `php*-fpm.service` match stays until slice 7 removes the host path, so the host Gateway keeps its 18 ms commands during the move and its rollback. No step pays 190 ms per command. Horizon stays `Alone`; every other process rides an open master or opens its own connection.

The connection sockets move from `ORBIT_HOME/ssh/mux`, which every container mounts, to the container's own `/run/orbit/ssh-mux`. Otherwise hot's migration and readiness commands, or the draining release, would ride live's masters across containers, and a stopped container would leave sockets behind that the next live trusts. The cost: after a switch, the first command to each Node from the new live opens a new connection, *about 190 ms instead of 18 ms*, once per Node. A warm-up before the switch would save that cost for the busiest Nodes, but only the FrankenPHP unit opens masters, so it needs a new internal endpoint. That is a new way in, and it stays an open point.

A master started by a FrankenPHP thread is a child of the FrankenPHP process, and a worker restart does not end it. Only the unit's stop ends it, and retire stops the unit only after the drain.

### 4. FrankenPHP worker mode

**The worker.** FrankenPHP from the runtime folder ([ADR 0264](/decisions/0264-build-container-php-on-glibc-with-a-cli-only-pcov)) runs Laravel Octane's FrankenPHP worker script, `vendor/laravel/octane/bin/frankenphp-worker.php`, from an Orbit Caddyfile under the unit `orbit-gateway-web.service`. Orbit owns the unit, the binary and the Caddyfile; `octane:start` does not run. Octane brings the state resets that a Laravel worker needs: a new application sandbox per request, and flushes of the auth, session, request and scoped bindings. A hand-written `frankenphp_handle_request()` loop was rejected: it would copy that list and drift from Laravel. `laravel/octane` is not installed today; it becomes a production dependency of `apps/gateway`.

**Per-request saving.** Worker mode removes the boot, *15–40 ms per request (guess)*, and keeps three connections open across requests:

- the PDO connection to SQLite: *about 0.1 ms per request (guess)*;
- the Predis connection to Valkey with its login (`HELLO`, `CLIENT SETINFO`, `SELECT`): *1–2 ms per request that uses Valkey (guess)*, which includes every wake through ADR 0266's `BLPOP`;
- the HTTP connection to Reverb's API for the signed `executor-order` event: *2–10 ms per wake (guess)* for the TCP and TLS setup. The worker reuses a cURL handler while the endpoint and trusted CA match. The signing driver is rebuilt when the settings change within a request and released after deferred sends and activity finalization, on both normal termination and a worker error. The cURL handler can keep its connection across requests and signing key rotations.

The wake inside the request therefore costs the route, the Ed25519 signature (*about 50 µs, guess*), one Reverb publish and one `BLPOP`, with no boot. Compared with ADR 0266's 500 ms bound, the boot alone was up to 8 % of it.

**`defer()` and after-response work.** Two mechanisms run work after the response. Laravel's `terminating` callbacks: `RecordEventBroadcaster` delivers each record broadcast that way, and `TasksServiceProvider` and `RequestLogContext` register one each. And `defer()`, at four call sites: `ScheduleInstanceRuntimeWakeAction` (the runtime activation), `InstanceWake` (twice: the resume on the Node and the resume cleanup) and `CancelOperationAction` (the forward to Swamp). Octane is expected to send the response first and then run Laravel's deferred callbacks in the same worker, as PHP-FPM does after `fastcgi_finish_request()`; this is not checked against Octane's source yet. So `defer()` and `terminating` stay, and no call site changes for worker mode. The cost is capacity, not latency: a deferred callback holds its worker until it ends, and a worker pool is fixed where `ondemand` forked a new child. The wake's deferred resume can take seconds over SSH. ADR 0266 moves that work into the executor; until it does, the pool counts deferred work (below). ADR 0218 keeps its own precondition: no endpoint runs long work after its response before its limit change. This design does not loosen it. The spike checks that the response reaches the client before the callback runs.

**State that must not leak.** In PHP-FPM every request started empty. In a worker, static properties, singletons and anything resolved at boot live as long as the worker. Each of these must hold no request's data:

- **Node identity per request.** A singleton that holds a Node, an SSH target or a caller would serve the next request with the previous Node. Classes that carry a Node take it as an argument or are bound `scoped`. No such singleton is known today; slice 1 checks every binding. An architecture test lists every `singleton` binding and refuses a binding whose class has a Node, Instance, user or Operation property.
- **Static state.** The known statics: `RequestLogContext::$depth` and `$requestId` (a request id that leaks marks the next request's logs), `RecordEventBroadcaster::$immediate`, `RealtimeConnection::$caFactory` (a factory, safe), `SwampHandshakes::$warned`, `SourceDigest::$cache`, `SourceClosure::$cache` and `$roots`, `OperationRunBudgets::$history` (scoped to one tick, safe), and `ActivityShutdownFinalizer::$armed` (not safe, next item). Slice 1 resets each unsafe one at the request end. A test fails on any mutable static that is not on an allow list with its reason.
- **Closures over the base container.** `TasksServiceProvider` registers a `terminating` callback that captures `$this->app`, the base container, not the copy that Octane gives each request. Its `TaskBroadcasts` flush can then read the wrong copy. Slice 1 resolves the container when the callback runs.
- **`ActivityShutdownFinalizer`.** It registers one `register_shutdown_function` per request. In a worker, shutdown functions run only when the worker exits, so one closure piles up per request until then, and `disarm()` cannot remove it. It changes to disarm and finalize at Octane's request end (`RequestTerminated`), and to keep one shutdown function per worker for a fatal error that ends the worker. Its comment already names `forgetArmed()` for long-lived processes.
- **Configuration.** A worker reads `bootstrap/cache/config.php` once. `gateway:release:configure` therefore restarts live's workers gracefully after it renames the cache. `RealtimeConnection::configureBroadcasting` sets the Reverb connection for broadcasts and channel auth. Octane gives each request its own copy of the configuration and shares Laravel's broadcast manager across requests. The next request starts with an empty Reverb driver cache and no prior signing credentials in its configuration.
- **Connections.** At the request end, any open database transaction rolls back, and a Valkey connection that a client-side timeout cut is closed. A `BLPOP` uses the server-side timeout with a client read timeout above it. Otherwise a late reply would reach the next command on that connection and hand one request another request's result.
- **`/proc/self/cgroup`.** `SshControlGroup` reads it per call; that stays correct in a worker.

**Worker count and memory.** Today's pool allows 8 requests at once. The worker pool starts at 16 workers, with the PHP `memory_limit` of today and `max_requests` of 500, after which Octane restarts the worker to cap a slow leak. A worker holds *60–100 MB (guess)*, so 16 workers hold *1–1.6 GB*. ADR 0218's commands that stay in the request may take up to 180 s, and `doctor` up to the command deadline (570 s today, 270 s after ADR 0218's limit change), so 16 slow commands hold every worker, and a wake that arrives then waits for a free one. If the runtime folder's FrankenPHP supports `match` in a worker block (the spike checks the version), one Caddyfile can give a second worker pool to the wake and the short API paths: `runtime-activations/*`, `/up`, `gateway/status` and the read endpoints. The split is a guess until the spike shows the queue under load; the open points name it.

**The request limit.** Today's pool sets both `request_terminate_timeout` and `max_execution_time` to N, 600 s, and the host's Caddy times out at N. FrankenPHP has no such kill, so `max_execution_time = N` stays the limit in PHP, with the host Caddy's read and write timeouts at N as today. No setting holds N yet: 600 s is written into `GatewayRuntimeFiles` (the pool) and `GatewayCaddyConfigRenderer` (Caddy's timeouts), and `config/orbit.php` only names it in a comment. ADR 0218's limit pull request adds N once, in `config/orbit.php`. Slice 5 adds that one setting at 600 s if the limit pull request has not merged first; the limit pull request then only changes its value. The pool, Caddy's timeouts, the drain and `grace_period` all read it, so the change from 600 s to 300 s reaches them. A request that hits `max_execution_time` ends with a fatal error, and FrankenPHP restarts that worker. The spike checks whether the ZTS execution timer measures wall time or CPU time; if it measures CPU time, a request that waits on SSH is not bounded by it, and ADR 0218's command deadlines remain the bound.

**OPcache.** Each container's OPcache is private and built once per worker boot. `opcache.validate_timestamps` turns off: a release container's code never changes, so the per-file stat of today goes. *A few µs per included file per request (guess)*.

### 5. Security

Unlike an app container, the Gateway's container is the control plane. Anyone who runs code in live already reaches every Node as `orbit` with sudo. The container does not make the Gateway safer against its own code, and this design does not claim it. It isolates releases from each other and from the host.

- **What the container holds.** The release and, through mounts, `ORBIT_HOME` and `gateway.env`. The cached configuration in a release snapshot holds the env's secrets, as ADR 0263's `.env` in a snapshot does. The snapshot stays in the Gateway Node's pool, readable only by root, inside the same boundary as `ORBIT_HOME`.
- **The WireGuard hub key.** The container holds it: all of `ORBIT_HOME` is mounted, and `VpnConfigurationRepository` reads `ORBIT_HOME/wireguard/private.key` to render the hub configuration that the Gateway installs on the `vpn` Node. On a bootstrapped Gateway the `vpn` role shares the machine, so this is the host's own interface key. Mounting `ORBIT_HOME` whole is the simplest sound option. Leaving `wireguard/` out would need a second renderer on the host, outside the Gateway, for one file. And it would protect nothing: the Gateway's SSH key already reaches the `vpn` Node with sudo, where the same key sits in the interface configuration.
- **What it does not hold.** The host's SSH host keys, Caddy's TLS keys, the Incus socket, sudo and the release run's SSH login. From inside, a release cannot start, stop or reconfigure a container.
- **What it does not stop.** The Gateway Node is a fleet Node that the Gateway converges itself. So live reaches its own host over SSH with sudo, like every other Node. The container keeps release steps off the host and releases apart from each other. It is no wall between Gateway code and the host.
- **Privileges.** An unprivileged container (`security.privileged=false`, `security.nesting=false`) with the default idmap. Its only user is `orbit`. The mounts of `ORBIT_HOME`, `gateway-storage` and `gateway.env` use Incus idmapped mounts (`shift=true`), so files stay owned by the host's `orbit` with their modes of today. No `raw.idmap`, no `raw.lxc`.
- **Network.** The bridge `orbitgw0` holds only Gateway containers and accepts inbound traffic only from the host. Outbound traffic leaves through the host's NAT, the same paths the host uses today: WireGuard peers and the egress the build needs (GitHub, Packagist).
- **Host keys and certificates it needs.** It reads `known_hosts` and its SSH identity from `ORBIT_HOME/ssh`, and the root CA from `ORBIT_HOME/ca` for TLS to internal names. It needs no host certificate: TLS ends on the host.

### 6. Rollout from today's Gateway

The first move keeps the same commit live. Deploy the tooling through the host release flow, prepare the container runtime and move the verified release with its published web archive. See [Move the Gateway to release containers](/reference/gateway-recovery#move-the-gateway-to-release-containers).

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
4. **Hot build and ready.** Every script from `git show` of the commit; update the preparation container, run `composer install`, install the web build and units, cache configuration, snapshot the finished build, clone hot, migrate in hot, ready on the bridge address before the Swamp load, removal on failure.
   - Proof: release cases on the nested Gateway; a failed step and a failed readiness leave live unchanged.
5. **Switch, handover and retire.** The upstream file over every Caddy consumer and the live link; the graceful reload with `grace_period`; the busy-worker drain; the listener overlap with its conditional record; the scheduler's minute guard; `process:*` on live; the SSH socket move and the FrankenPHP match in `SshControlGroup`; `FleetReboot`'s drain and boot check in live; the request-limit setting, unless ADR 0218's limit PR added it; `/usr/local/libexec/orbit-gateway-rollback` with the recovery program staged at the switch; forward recovery and retire.
   - Proof: a load loop through a release with one 60-second request across the switch: no failed request, one scheduler tick per minute, no lost wake result.
6. **Recovery.** Reconcile the committed switch and resume any unfinished drain. Verify private failure, public failure, retry and the three-container limit.

7. **Move and remove.** `bin/gateway-container-move`, the bootstrap through a new preparation container, then the removal of the host path: the `php*-fpm.service` match in `SshControlGroup`, `php8.5-fpm` in `NodeRoleServiceCatalog` (it becomes `incus`), PHP in the `gateway` role, and the host units. The [Update and recover a Gateway](/reference/gateway-recovery) page absorbs this ADR.
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
- **Keep the host release beside the containers.** Two ways to release one Gateway would stay. The host path goes in slice 7.

## Consequences

- A Gateway request no longer pays the Laravel boot. The wake path and the API paths gain the most.
- Gateway code must be safe in a long-lived worker. A singleton, a static cache or a shutdown function that holds a request's data is a defect, and tests refuse the known shapes.
- A release changes no PHP, unit or file on the host except the upstream file and the live link. A failed build, migration or readiness check never touches live's code. A migration that ran stays, as today.
- Operational recovery finishes the durable switch. A later corrective deployment uses the same preparation checkout.
- A migration that rebuilds a large SQLite table blocks live's writes while it runs. That was true before; worker mode does not change it.
- During a release, two Gateways run on the Node, and their workers hold memory twice for the drain.
- The first command to each Node after a switch opens a new SSH connection.

## Affects

- Components: apps/gateway, apps/docs
- ADRs: [0218](/decisions/0218-take-long-commands-off-the-request-path), [0250](/decisions/0250-run-instances-and-task-groups-in-incus-containers), [0263](/decisions/0263-run-production-releases-in-hot-live-and-old-containers), [0264](/decisions/0264-build-container-php-on-glibc-with-a-cli-only-pcov), [0266](/decisions/0266-sleep-and-wake-containers-through-a-signed-order-executor), [0267](/decisions/0267-run-fast-node-actions-in-a-signed-order-executor)
- Outside these components: the Swamp release workflows and `swamp/scripts/gateway-release-*.sh`, the `gateway` role and `bin/gateway-rollback`
- Detail: [Update and recover a Gateway](/reference/gateway-recovery), [Gateway queue](/reference/gateway-queue), [Node provisioning: SSH connections](/reference/node-provisioning#ssh-connections), [Node agent](/reference/node-agent)
- Verify: the spike of this task group; Pest tests of slice 1; the release cases of slices 3 to 5 on a nested Gateway
