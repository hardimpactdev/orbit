---
title: "Gateway queue"
description: "The Gateway's Redis connection and ACL user, six queues, Laravel Horizon, and how a release moves them."
covers:
  - apps/gateway/app/Domain/Gateway/GatewayQueue.php
  - apps/gateway/app/{Infrastructure/Gateway/GatewayQueue*,Infrastructure/Gateway/GatewayHorizon*,Console/Scheduling/**,Jobs/RunScheduledCommand.php,Providers/GatewayQueueServiceProvider.php}
  - apps/gateway/config/{horizon,horizon-new-dawn,queue}.php
---

# Gateway queue

The Gateway scheduler dispatches most of its work. Each scheduled command that does work, such as `tasks:tick`, becomes a queued job, and [Laravel Horizon](https://laravel.com/docs/horizon) runs the jobs. A long build therefore never delays a tick, and a slow tick never delays the next minute of the schedule. The narrow Horizon observation, problem filing, and document cleanup run in the scheduler itself, as [Scheduled work](#scheduled-work) lists.

## Connection

The queue connection `gateway` uses the Redis driver. Its Redis server is reached through a [Redis Database connection](/reference/database-connections), such as `gateway-queue` for the Valkey on the `services` Node. Set these keys in `/home/orbit/shared/gateway.env`, then run a release. The release caches them and creates the [`horizon` Process](#horizon). Do not only [apply the env change](/reference/gateway-recovery#apply-an-env-change): the scheduler would then dispatch to the queue before Horizon runs.

| Key | Default | Effect |
| --- | --- | --- |
| `ORBIT_GATEWAY_QUEUE_CONNECTION` | empty | The slug of the Redis Database connection. Empty keeps the [inline schedule](#without-a-queue) |
| `ORBIT_GATEWAY_QUEUE_REDIS_DATABASE` | `1` | The Redis database index of the queue and Horizon. Use an index that no other client of that server uses |

The Gateway reads the host, port, username, and password of that connection from its records when a process first uses Redis. When the key names no Redis connection, the Gateway logs an error and every dispatch to the queue fails. Every key starts with `orbit-gateway:`, and Horizon's own keys with `orbit-gateway-horizon:`. The Redis client is Predis, so the Gateway needs no PHP Redis extension.

After [setup](#set-up-the-queue-user), the `valkey` Docker Process on `services` is the Valkey [Database server](/reference/database-servers) `services-valkey`. It listens on the WireGuard address of `services`, so only WireGuard peers reach it. It writes an append-only file (`--appendonly yes`) to the `services-valkey-data` volume, so queued jobs survive a restart. Every client logs in with a password. The queue and Horizon log in as their own [ACL user](#acl-users), `gateway-queue`, through the Database connection of the same name. That user reaches only the queue's keys.

## Queues

Laravel Horizon runs six queues on the connection with four supervisors. The `tasks` supervisor checks `tasks` before `problems`, with balancing disabled. Collection cannot put a tick or publication behind its backlog; a running collection job finishes first. The two build queues share one supervisor. Task VMs and operations keep separate supervisors:

| Queue | Processes | Jobs |
| --- | --- | --- |
| `tasks` | 3 | The scheduled commands below and the publish of an approved commit. The Git mirror's lock keeps one push per Project at a time |
| `problems` | shares the 3 `tasks` workers | Doctor collection and signal collection, after waiting `tasks` jobs |
| `task-vms` | 1 | Task VM provision, enroll, prepare, park, resume, and destroy, the host sweep |
| `builds` | 2 | The [base image step chains and refreshes](/reference/compute-drivers#build-the-base-image), trusted [package cache fills](/reference/compute-drivers#shared-package-cache), and [recipe maintenance](/reference/compute-drivers#nightly-recipe-maintenance) |
| `builds-optional` | Shares the 2 build workers | The [UpCloud template refresh](/reference/compute-drivers#refresh-the-upcloud-template-on-recipe-changes) |
| `operations` | 1 | [Operations](/reference/operations), such as an Instance deploy. One job runs one operation, one at a time |

The `task-vms` jobs run one at a time and in order, so no two jobs change the same VM at once.

A `task-vms` job runs at most 1500 seconds, and any other job at most 1700 seconds. An operation therefore has a deadline of 1600 seconds. Laravel makes a reservation available again after 1800 seconds if its worker did not acknowledge it. Reserving a job consumes an attempt, so with one allowed attempt the next worker fails it before its handler runs. Operations have a separate [recovery path](/reference/operations#recover-a-lost-worker). An idle worker waits on the queue with a blocking read, so a job starts within about a second when a process is free.

Each build worker checks `builds` before `builds-optional`, with Horizon balancing disabled. A waiting Incus chain step therefore runs before an optional UpCloud build. Running jobs are not preempted. Two build workers let hosts build in parallel; `Bus::chain` orders each build, the database permits one running build per host, and pruning stays on that host and protects running builds.

## Scheduled work

The scheduler dispatches each of these as a unique job. While the job waits or runs, the scheduler dispatches no second one, so a slow tick never stacks up:

| Command | Every | Queue | Unique for |
| --- | --- | --- | --- |
| `tasks:tick` | 10 seconds | `tasks` | 330 seconds |
| `orbit:auto-deploy` | minute | `tasks` | 600 seconds |
| `orbit:vault-sync` | minute | `tasks` | 900 seconds |
| `orbit:desired-fleet-state` | 5 minutes | `tasks` | 600 seconds |
| `orbit:activity-finalize-interrupted` | 5 minutes | `tasks` | 600 seconds |
| `orbit:operations-sweep` | minute | `tasks` | 300 seconds |
| `horizon:snapshot` | 5 minutes | `tasks` | 300 seconds |

The job runs the command in its own PHP process, as the scheduler did, so a command keeps no state from an earlier run. A command that exits nonzero is logged as an error. `tasks:tick` keeps its own lock of 300 seconds. The "unique for" time only matters when a worker is lost: the lock then frees itself after that time.

The [task VM jobs](/reference/compute-drivers#jobs) that the scheduler queues, the host sweep, image reconciliation, and [recipe maintenance](/reference/compute-drivers#nightly-recipe-maintenance), go to the same connection.

These commands stay in the scheduler and never go to the queue, also while it is set:

| Command | Every | Overlap lock |
| --- | --- | --- |
| `problems:collect` (dispatch only) | 10 minutes | none |
| Horizon Doctor observation | 10 minutes | none |
| `problems:file` | hour | 30 minutes |
| `project-documents:probes:reconcile` | minute | 10 minutes |
| `project-documents:cleanup:work` | 5 minutes | 60 minutes |

`problems:collect` runs every 10 minutes and only dispatches collection on `problems`. One batch observes each Node; a second checks typed resource groups selected by each Doctor family. The last job collects the other signals. Collection never files problems. Only hourly `problems:file` does that, under its lock.

Each probe group shares one 12-second command deadline. The worker timeout is 13 seconds, a backstop derived from the same budget. Measured package inventory took up to 6.8 seconds, and an unreachable Node took 10.2 seconds. These results must finish inside the probe deadline rather than be replaced by worker failures.

Normal systemd checks took 2.2–2.5 seconds, so at most three Processes share a job. Instance, Route, Project and database connection checks took under 0.5 seconds each, so at most 20 share a job. A Database server job inspects one running Valkey server; connection, authentication and response reads share the probe deadline.

Each Tool rows job owns one manager on one Node. It reads that manager’s installed inventory once, then compares every selected Tool locally. Homebrew on macOS and Vite+ first use the normal scope probe, including its output checks, then read the inventory. Both calls share the job deadline. Tool environment checks keep one batch read per Node; discovery inventory is separate.

Three read-only Mini measurements of the collection jobs took 1.20–2.85 seconds for five Brew Tools, 1.22–1.26 seconds for one cask, and 0.96–0.97 seconds for one Vite+ Tool. Each manager job made two SSH calls: the normal scope probe and one inventory read. The separate discovery job took 4.54–5.19 seconds. Grouping by manager bounds the remote work independently of the number of registered Tools.

Each failed job records unverifiable results in Laravel's `failed()` hook; there is no completion batch.

Laravel batches keep all Node observations available to related-node checks. Observations survive release switches in shared cache. Resource groups commit separately, so a release waits only for the running group, never the full fleet scan. Collection needs the queue; without it, dispatch fails with an error.

The separate scheduler callback inspects only the Gateway's Horizon Process through Doctor's process checks. It records the same Doctor fingerprint without scanning the fleet or depending on a queue worker. Filing also stays independent of Horizon. The [document cleanup](/reference/project-documents) commands stay because their permit belongs to the scheduler's start.

Laravel batch metadata is pruned daily after `ORBIT_JOB_BATCH_HOURS` (24 hours by default) with `queue:prune-batches`. The pruning job runs on `problems` with the collection worker timeout while Tasks is enabled. Failed jobs are pruned daily with `queue:prune-failed` after `ORBIT_FAILED_JOBS_HOURS` (168 hours by default), also while Tasks is disabled. These settings live in `config/retention.php`.

### Without a queue

While `ORBIT_GATEWAY_QUEUE_CONNECTION` is empty, the other scheduled commands run inline with their overlap locks, and the Gateway creates no `horizon` Process. Fleet problem collection and task VMs need the queue. While task VMs are enabled, or the UpCloud token file is set, each due run of the host sweep, image reconciliation, or recipe maintenance fails with an error that names the key, instead of being skipped. A task VM claim fails the same way.

Operations need the queue too. An Instance deploy or rollback request answers `503` with `operation.queue_unavailable` and records nothing ([Operations](/reference/operations#start-an-operation)).

## Horizon

Horizon runs as the Gateway Node's [Process](/reference/processes-and-schedules) named `horizon`, beside the scheduler Process. It is a systemd Process with restart policy `always`. It runs `php artisan horizon` with the scheduler's PHP binary, as `orbit`, in the scheduler's directory, the stable path `/home/orbit/orbit/apps/gateway`. Read its output with `orbit process:logs <id>`.

The serving phase of a release's [runtime handoff](/reference/gateway-recovery#runtime-handoff) creates the Process and starts it, when `ORBIT_GATEWAY_QUEUE_CONNECTION` is set and no Process of the Gateway Node runs `artisan horizon`. So the first release after you set the key starts Horizon. When the handoff cannot create or start the Process, it fails with `gateway.release_units_failed`. The Gateway never removes the Process. After you empty the key, destroy it with `orbit process:destroy <id>`.

### Reservation metadata failures

Laravel reserves a Redis job before Horizon records its reservation in a separate Redis connection. A failed metadata write must not discard the job that Laravel already reserved. The Gateway binds Horizon's reservation listener to `GatewayHorizonReservation`: it tries the metadata write once, logs a failure, and returns the job to the worker for normal processing. It does not release the job, add an attempt, or retry the Redis command. Horizon still starts its job timer.

The error `Horizon could not record a job reservation.` records the job UUID, queue connection, queue, host, worker PID, reservation attempt count, exception class, and exception message (`exception_message`). The message preserves the cause, such as a Redis EOF or ACL error. It never records the job payload. Use these fields with the Horizon Process journal to distinguish a metadata failure from a worker that exited. A live worker PID alone does not prove that it still owns an attempt: Laravel can discard a popped job after a listener throws, while the worker continues polling.

## Dashboard

Open `https://gateway.orbit/horizon` in a browser on a machine that is a WireGuard peer with Gateway access, the same access that the Orbit CLI needs for Gateway commands. The dashboard is [Horizon New Dawn](https://github.com/hardimpactdev/horizon-new-dawn), a React interface for Horizon. Any other address gets 403.

The dashboard can retry, cancel, and clear jobs, and pause queues, so anyone with Gateway access can change the queue. The Gateway keeps no browser session, so the dashboard has no CSRF token. Laravel's request forgery check runs in origin-only mode instead: a request that changes something passes only when the browser sends `Sec-Fetch-Site: same-origin`. Any other such request gets 403. Bulk actions run as jobs on the `tasks` queue.

Each release publishes the dashboard's compiled assets during `composer install` in [prepare](/reference/gateway-recovery#prepare-a-release), with `php artisan horizon-new-dawn:assets`. The first release with the dashboard runs its migration, which adds the table `horizon_new_dawn_batch_metadata`. The scheduler runs `horizon:snapshot` every five minutes for the dashboard's metrics.

The Redis server needs `maxmemory-policy noeviction`, so that it never drops a queued job to free memory. `services-valkey` has it. It has no `maxmemory`, so it can use the memory of `services`; a limit for the Gateway's use is a follow-up.

## Releases

A release drains Horizon before switching the serving link and starts it in the serving phase. It moves the old database jobs in the scheduler phase, after the scheduler's drain. The handoff records the Horizon step as `horizon`:

| Outcome | When | What the handoff does |
| --- | --- | --- |
| `created` | No Process runs Horizon | Creates the `horizon` Process and starts it |
| `terminated` | The Process's unit is active | Runs `php artisan horizon:terminate` and leaves the unit alone |
| `started` | The unit is not active, for example after a failed start, a crash loop, or a stop after a switch back | Installs the unit again when it is missing, and starts it |
| `none` | `ORBIT_GATEWAY_QUEUE_CONNECTION` is empty | Nothing |

`horizon:terminate` reaches only a running master, so a stopped Horizon is started instead. The handoff never restarts an active unit, because a restart ends its running jobs.

Before changing the serving release link, the release runner drains the Gateway's Horizon Process through `gateway.drain-horizon`. This applies to deployment, rollback and switch-back. The agent installs a temporary systemd stop policy: SIGTERM reaches only the master, the stop has no systemd timeout, and systemd never escalates to SIGKILL. It submits a stop and waits for both an inactive unit and an empty control group. Running jobs finish against the source release. The stopped unit cannot restart automatically. A missing Horizon Process needs no drain.

The drain has a deadline of the longest supervisor job timeout plus 30 seconds. A timeout or unreadable observation refuses the release switch. The safe stop policy stays installed while a stop is incomplete. Do not force a restart; inspect the Process journal and let its jobs finish, then repeat the release. A completed drain removes its temporary policy. If the runner exits after draining, Horizon stays stopped; repeat the release or run the serving release's runtime handoff to start it.

After the switch, the serving handoff starts the stopped Horizon Process on the selected release before recipe image reconciliation. Thus an old worker cannot reserve a job produced by the new release or read its recipe through the serving link. A switch failure leaves Horizon stopped on the source; run its runtime handoff before resuming normal work. Other Gateway requests can queue during the drain; their jobs wait in Redis. Job IDs, attempts, delays and chains are unchanged.

For the first rollout of this drain code, prepare the reviewed commit and invoke deployment from that prepared release's Artisan entry point as `orbit`, with the normal shared configuration and stable release-layout paths. A runner already started from older code cannot acquire the new barrier. Later releases enter it from the serving release in the normal way.

The scheduler phase restarts every other Gateway Process in the Gateway application with systemd, but never the `horizon` Process.

Do not restart Horizon with `orbit process:restart`, and do not stop it with `orbit process:stop` while a job runs. Both stop its unit, and systemd kills a job that still runs 90 seconds after the stop, such as a build. To restart Horizon, run `php artisan horizon:terminate`.

### Switch back to an older release

A release from before Horizon has no `horizon` command. After a switch back to it, the older release's handoff restarts the `horizon` Process, which then fails at each start until systemd gives up. The Process shows the failure in `orbit process:list`. Stop it with `orbit process:stop <id>`. The older release's scheduler runs its own workers on the database queues meanwhile, and jobs left in Redis wait. The next release with Horizon starts the Process again, and Horizon works the jobs left in Redis.

A job left in Redis keeps its unique lock in the Gateway cache. The older release then queues no second copy of that job, for example no second destroy of the same VM, until the lock expires; a lock without an expiry stays. To let the older release queue it again, remove the lock as `orbit` in `/home/orbit/orbit/apps/gateway`:

```bash
php artisan tinker --execute='cache()->forget("laravel_unique_job:App\\Jobs\\TaskVms\\DestroyTaskVm:7");'
```

The key is `laravel_unique_job:` followed by the job class and its unique id, the task VM id for task VM jobs. When the newer release goes live again, it works the jobs left in Redis.

### Jobs across releases

A job queued by one release can run on the next one. The release drain prevents old workers from consuming work published after the serving link changes. A change to a queued job's class, its constructor properties, or a table that a job reads, must work with both releases for one release. Remove the old form in a later release.

## When Redis is down

The queue depends on its Redis server, but the Gateway keeps running without it:

- The scheduler logs each failed dispatch as an error and frees the job's unique lock, so the next run dispatches it again. The scheduler keeps running.
- A running Horizon reports each failed read and keeps running. It works the queued jobs again as soon as Redis answers.
- A Horizon start fails while Redis is down.
- A request that queues a job, such as a task VM claim, fails with an error.

systemd starts a failed Horizon again after 2 seconds. After several failed starts in a row, systemd stops trying, and the Process shows the failure. Start it again with `orbit process:start <id>`; the next release also starts it.

A job that Redis lost runs again only when its owner queues it again. For example, the tick queues a publish again after its backoff.

## ACL users

After setup, the queue and Horizon log in to the [Valkey Database server](/reference/database-servers#a-valkey-server) `services-valkey` as the ACL user `gateway-queue`. That user can read and change only the queue's keys. The Gateway holds every password, and [Doctor](/reference/database-servers#check-a-server-with-doctor) checks the users.

| User | Keys | Commands | Used by |
| --- | --- | --- | --- |
| `default` | All | All | The admin user. The [AI router collector](/reference/ai-router#collection) and the Gateway's [snapshot reads](/reference/ai-router#clients) also use it, through the Database connection `services-valkey`, until the collector [gets its own user](#not-built) |
| `gateway-queue` | `orbit-gateway:*` and `orbit-gateway-horizon:*` | The rules in the table below | The queue and Horizon, through the Database connection `gateway-queue` |

[`database:create`](/reference/database-servers#create-an-acl-user-on-a-valkey-server) creates the user from key patterns and allowed commands. Orbit adds `resetchannels`, `-@all`, and the final `-@admin -@dangerous -function -script`, so the user has no pub/sub channel, no admin or dangerous command, and no `FUNCTION` or `SCRIPT` command:

| Input | Why |
| --- | --- |
| `--pattern='orbit-gateway:*'`, `--pattern='orbit-gateway-horizon:*'` | The keys of the queue and of Horizon. `orbit-gateway:*` does not match the Horizon prefix `orbit-gateway-horizon:`, so the user names both |
| `--allow=@connection` | On each new connection, Predis logs in with `HELLO`, sends `CLIENT SETINFO`, and selects the queue's database with `SELECT` |
| `--allow=@transaction` | `horizon:snapshot` and the dashboard use `MULTI` and `EXEC` |
| `--allow=@keyspace` | Horizon sets expiry times on jobs and deletes them. `horizon:clear-metrics` finds metrics with `SCAN` |
| `--allow=@string`, `@list`, `@hash`, `@set`, `@sortedset` | The queue keeps jobs in lists and sorted sets, and an idle worker waits with `BLPOP`. Horizon keeps jobs, metrics, and tags in strings, hashes, sets, and sorted sets |
| `--allow=eval` | The queue's Lua scripts push, pop, and move jobs. Valkey checks each `redis.call` in a script against the same rules. Orbit refuses `@scripting` |
| `--allow=time` | The dashboard's Lua scripts for pending jobs call `TIME`. `TIME` is only in `@fast`, which would allow many more commands |

The final `-@admin -@dangerous -function -script` removes commands that the categories above hold, such as `KEYS`, `SORT`, `FLUSHDB`, `CLIENT LIST`, `CLIENT KILL`, `FUNCTION FLUSH`, and `SCRIPT FLUSH`. The Gateway sends none of them. Key patterns do not limit the database index, and Valkey has no memory limit, so a leaked `gateway-queue` password can still fill the server's memory.

### Set up the queue user

These steps bring the `valkey` Process on `services` under Orbit and move the queue to its own user. Run each command on a machine with Gateway access.

First, adopt the Process as a Valkey Database server. `<id>` is the ID of the `valkey` Process in `orbit process:list --node=services`. Run this when the [dashboard](#dashboard) shows no running build or operation, because Valkey restarts once:

```bash
orbit database:server:create services-valkey --node=services --driver=redis --process=<id> --connection=services-valkey
```

The Gateway takes the password of `services-valkey` as the admin password, writes the ACL file, and restarts Valkey with it. The server runs `valkey/valkey:8.1`, so the restart also moves the Process from `valkey/valkey:8` to 8.1. Valkey reads its append-only file again, so the queued jobs stay. The queue and the collector keep logging in as `default` with the same password. [When Redis is down](#when-redis-is-down) describes the Gateway during the restart.

Then create the queue's user and its connection:

```bash
orbit database:create gateway-queue --server=services-valkey --database=1 \
  --pattern='orbit-gateway:*' --pattern='orbit-gateway-horizon:*' \
  --allow=@connection --allow=@transaction --allow=@keyspace --allow=@string --allow=@list \
  --allow=@hash --allow=@set --allow=@sortedset --allow=eval --allow=time
```

Last, as `orbit` on the Gateway, set this key in `/home/orbit/shared/gateway.env`:

```bash
ORBIT_GATEWAY_QUEUE_CONNECTION=gateway-queue
```

Then [run a release](/reference/gateway-recovery#deploy-a-release) of the current commit, as [Connection](#connection) requires. Read the commit as `release_sha` from `orbit gateway:status --json`:

```bash
orbit gateway:release:deploy <SHA>
```

The command follows the release, which must end `verified`. Its handoff runs `horizon:terminate`, so Horizon starts again as `gateway-queue` after its running jobs end ([Releases](#releases)). Both users reach the queue's keys, so no job is lost. When a newer commit passed CI meanwhile, or GitHub does not answer, the manual deploy [pauses automatic releases](/reference/gateway-recovery#pause) with `manual_deploy`. Then run [`orbit gateway:release:auto:resume`](/cli/gateway#orbit-gatewayreleaseautoresume).

### Check the queue user

Run `orbit doctor --family=database_server --node=services`. It reports no issue when the server runs with its ACL file and the live users match the records. Open the [dashboard](#dashboard), including its pending jobs. Then read the Horizon log with `orbit process:logs <id>`, where `<id>` is the `horizon` Process of the Gateway Node. A command that the user may not run shows there as an error with `NOPERM`.

To switch back, set `ORBIT_GATEWAY_QUEUE_CONNECTION=services-valkey` and run a release of the current commit again. Horizon then logs in as `default` and works the same jobs. Do not use `gateway:release:rollback` for this: it switches code only, and every release reads the same env file.

## Operations

These commands inspect and control Horizon on the Gateway.

| Task | Command |
| --- | --- |
| See Horizon | `orbit process:list --node=<gateway>` and `orbit process:logs <id>` |
| Restart on the current release | `php artisan horizon:terminate` |
| Pause and continue every queue | `php artisan horizon:pause` and `php artisan horizon:continue` |
| Count waiting jobs | `php artisan queue:monitor gateway:tasks,gateway:problems,gateway:task-vms,gateway:builds,gateway:builds-optional,gateway:operations` |
| Retry failed jobs | `php artisan queue:retry all` |

Run each `php artisan` command as `orbit` in `/home/orbit/orbit/apps/gateway`.

## Not built

Doctor does not report an old `last_tick_at` yet. Until it does, check [`orbit tasks:status`](/cli/tasks#orbit-tasksstatus) when task groups stop moving.

A Horizon that is down while its Process should run is ordinary Process drift. Doctor reports it as `process.state_mismatch`, or `process.crash_loop` when systemd keeps restarting it. The separate Horizon observation callback runs in the scheduler, so it records that issue within one collection cycle, and `problems:file` files it once two collections 10 minutes apart saw it. A Horizon stopped with `orbit process:stop` is not drift and is not reported.

The collector rebuilds its quota snapshot in Valkey; quota reads never poll the upstream provider.

## Why it works this way

The scheduler used to run its work, and the task VM workers, as foreground commands. A foreground command holds the scheduler's run for that minute, so a slow tick or a VM job could hold a publish for minutes. With dispatch, the scheduler's run takes milliseconds, and each queue's workers decide when a job starts.

Fleet problem collection runs as short queued steps, so it cannot hold the [scheduler drain](/reference/gateway-recovery#runtime-handoff), and an in-flight collection step holds Horizon termination for at most its 13-second worker timeout. Only the narrow Horizon observation stays outside its workers, so a stopped Horizon still gets recorded.

Horizon needs Redis. The fleet already runs Valkey on the `database` role, so the Gateway uses that registered connection instead of a second server on its own host. Orbit's records keep its address, as for every other Database connection.

### An ACL user for the queue

The queue has its own ACL user because Valkey is shared. As `default`, a fault in the queue or Horizon, or a leaked queue password, could read or delete the collector's keys. A second Database connection carries the user, because one connection holds one username and one password for all its clients. The user lives on a [Database server](/reference/database-servers#valkey-starts-only-from-orbits-acl-file), so the Gateway holds its password and Doctor checks it.
