---
title: "Gateway queue"
description: "The Gateway's Redis connection, six queues, Laravel Horizon, and how a release moves them."
covers:
  - apps/gateway/app/Domain/Gateway/GatewayQueue.php
  - apps/gateway/app/{Infrastructure/Gateway/GatewayQueue*,Infrastructure/Gateway/GatewayHorizon.php,Console/Scheduling/**,Jobs/RunScheduledCommand.php,Providers/GatewayQueueServiceProvider.php}
  - apps/gateway/config/{horizon,horizon-new-dawn,queue}.php
---

# Gateway queue

The Gateway scheduler dispatches most of its work. Each scheduled command that does work, such as `tasks:tick`, becomes a queued job, and [Laravel Horizon](https://laravel.com/docs/horizon) runs the jobs. A long build therefore never delays a tick, and a slow tick never delays the next minute of the schedule. The narrow Horizon observation, problem filing, and document cleanup run in the scheduler itself, as [Scheduled work](#scheduled-work) lists.

## Connection

The queue connection `gateway` uses the Redis driver. Its Redis server is a [Redis Database connection](/reference/database-connections) that Orbit already has, such as the Valkey on the `services` Node (`services-valkey`). Set these keys in `/home/orbit/shared/gateway.env`, then run a release. The release caches them and creates the [`horizon` Process](#horizon). Do not only [apply the env change](/reference/gateway-recovery#apply-an-env-change): the scheduler would then dispatch to the queue before Horizon runs.

| Key | Default | Effect |
| --- | --- | --- |
| `ORBIT_GATEWAY_QUEUE_CONNECTION` | empty | The slug of the Redis Database connection. Empty keeps the [inline schedule](#without-a-queue) |
| `ORBIT_GATEWAY_QUEUE_REDIS_DATABASE` | `1` | The Redis database index of the queue and Horizon. Use an index that no other user of that server uses |

The Gateway reads the host, port, and password of that connection from its records when a process first uses Redis. When the key names no Redis connection, the Gateway logs an error and every dispatch to the queue fails. Every key starts with `orbit-gateway:`, and Horizon's own keys with `orbit-gateway-horizon:`. The Redis client is Predis, so the Gateway needs no PHP Redis extension.

`services-valkey` is the `valkey` Docker Process on `services`. It listens on the WireGuard address of `services`, so only WireGuard peers reach it. It requires a password (`requirepass`), which its Database connection stores, and it writes an append-only file (`--appendonly yes`) to the `services-valkey-data` volume, so queued jobs survive a restart. A separate Valkey ACL user for the Gateway is a follow-up.

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

A `task-vms` job runs at most 1500 seconds, and any other job at most 1700 seconds. An operation therefore has a deadline of 1600 seconds. A job that a worker lost, for example when its process was killed, runs again after 1800 seconds. An idle worker waits on the queue with a blocking read, so a job starts within about a second when a process is free.

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

Normal systemd checks took 2.2–2.5 seconds, so at most three Processes share a job. Instance, Route, Project and database checks took under 0.5 seconds each, so at most 20 share a job. Tool environment checks keep one batch read per Node; inventory is separate. Each failed job records unverifiable results in Laravel's `failed()` hook; there is no completion batch.

Laravel batches keep all Node observations available to related-node checks. Observations survive release switches in shared cache. Resource groups commit separately, so a release waits only for the running group, never the full fleet scan. Collection needs the queue; without it, dispatch fails with an error.

The separate scheduler callback inspects only the Gateway's Horizon Process through Doctor's process checks. It records the same Doctor fingerprint without scanning the fleet or depending on a queue worker. Filing also stays independent of Horizon. The [document cleanup](/reference/project-documents) commands stay because their permit belongs to the scheduler's start.

Laravel batch metadata is pruned daily after 24 hours with `queue:prune-batches`. The pruning job runs on `problems` with the collection worker timeout.

### Without a queue

While `ORBIT_GATEWAY_QUEUE_CONNECTION` is empty, the other scheduled commands run inline with their overlap locks, and the Gateway creates no `horizon` Process. Fleet problem collection and task VMs need the queue. While task VMs are enabled, or the UpCloud token file is set, each due run of the host sweep, image reconciliation, or recipe maintenance fails with an error that names the key, instead of being skipped. A task VM claim fails the same way.

Operations need the queue too. An Instance deploy or rollback request answers `503` with `operation.queue_unavailable` and records nothing ([Operations](/reference/operations#start-an-operation)).

## Horizon

Horizon runs as the Gateway Node's [Process](/reference/processes-and-schedules) named `horizon`, beside the scheduler Process. It is a systemd Process with restart policy `always`. It runs `php artisan horizon` with the scheduler's PHP binary, as `orbit`, in the scheduler's directory, the stable path `/home/orbit/orbit/apps/gateway`. Read its output with `orbit process:logs <id>`.

The serving phase of a release's [runtime handoff](/reference/gateway-recovery#runtime-handoff) creates the Process and starts it, when `ORBIT_GATEWAY_QUEUE_CONNECTION` is set and no Process of the Gateway Node runs `artisan horizon`. So the first release after you set the key starts Horizon. When the handoff cannot create or start the Process, it fails with `gateway.release_units_failed`. The Gateway never removes the Process. After you empty the key, destroy it with `orbit process:destroy <id>`.

## Dashboard

Open `https://gateway.orbit/horizon` in a browser on a machine that is a WireGuard peer with Gateway access, the same access that the Orbit CLI needs for Gateway commands. The dashboard is [Horizon New Dawn](https://github.com/nckrtl/horizon-new-dawn), a React interface for Horizon. Any other address gets 403.

The dashboard can retry, cancel, and clear jobs, and pause queues, so anyone with Gateway access can change the queue. The Gateway keeps no browser session, so the dashboard has no CSRF token. Laravel's request forgery check runs in origin-only mode instead: a request that changes something passes only when the browser sends `Sec-Fetch-Site: same-origin`. Any other such request gets 403. Bulk actions run as jobs on the `tasks` queue.

Each release publishes the dashboard's compiled assets during `composer install` in [prepare](/reference/gateway-recovery#prepare-a-release), with `php artisan horizon-new-dawn:assets`. The first release with the dashboard runs its migration, which adds the table `horizon_new_dawn_batch_metadata`. The scheduler runs `horizon:snapshot` every five minutes for the dashboard's metrics.

The Redis server needs `maxmemory-policy noeviction`, so that it never drops a queued job to free memory. `services-valkey` has it. It has no `maxmemory`, so it can use the memory of `services`; a limit for the Gateway's use is a follow-up.

## Releases

A release moves Horizon in the serving phase, right after the switch, and the old database jobs in the scheduler phase, after the scheduler's drain. The handoff records the Horizon step as `horizon`:

| Outcome | When | What the handoff does |
| --- | --- | --- |
| `created` | No Process runs Horizon | Creates the `horizon` Process and starts it |
| `terminated` | The Process's unit is active | Runs `php artisan horizon:terminate` and leaves the unit alone |
| `started` | The unit is not active, for example after a failed start, a crash loop, or a stop after a switch back | Installs the unit again when it is missing, and starts it |
| `none` | `ORBIT_GATEWAY_QUEUE_CONNECTION` is empty | Nothing |

`horizon:terminate` reaches only a running master, so a stopped Horizon is started instead. The handoff never restarts an active unit, because a restart ends its running jobs.

`horizon:terminate` sends SIGTERM to the Horizon master. Horizon does not use fast termination: the master waits until each worker has finished its running job, on the release that job started on, and then exits. The Process's restart policy then starts Horizon again, on the new release. A new `task-vms` worker therefore never starts while an old one runs, and task VM jobs stay in order.

The cost is the wait. A release during a base image build waits for one short step, not the whole build. The build is a [chain of jobs](/reference/compute-drivers#build-the-base-image), and the next step runs on the new release. Until the running jobs end, no new job starts on any queue, and `tasks:tick` waits too. The master waits at most the longest supervisor timeout, 1700 seconds, and then exits.

The release does not wait for the new master. A failure of the step fails the handoff with `gateway.release_units_failed`, and the release switches back.

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

A job queued by one release can run on the next one, and during a release, on the one before. A change to a queued job's class, its constructor properties, or a table that a job reads, must work with both releases for one release. Remove the old form in a later release.

## When Redis is down

The queue depends on its Redis server, but the Gateway keeps running without it:

- The scheduler logs each failed dispatch as an error and frees the job's unique lock, so the next run dispatches it again. The scheduler keeps running.
- A running Horizon reports each failed read and keeps running. It works the queued jobs again as soon as Redis answers.
- A Horizon start fails while Redis is down.
- A request that queues a job, such as a task VM claim, fails with an error.

systemd starts a failed Horizon again after 2 seconds. After several failed starts in a row, systemd stops trying, and the Process shows the failure. Start it again with `orbit process:start <id>`; the next release also starts it.

A job that Redis lost runs again only when its owner queues it again. For example, the tick queues a publish again after its backoff.

## Operations

These commands inspect and control Horizon on the Gateway.

| Task | Command |
| --- | --- |
| See Horizon | `orbit process:list --node=<gateway>` and `orbit process:logs <id>` |
| Restart on the current release | `php artisan horizon:terminate` |
| Pause and continue every queue | `php artisan horizon:pause` and `php artisan horizon:continue` |
| Count waiting jobs | `php artisan queue:monitor gateway:tasks,gateway:task-vms,gateway:builds,gateway:builds-optional,gateway:operations` |
| Retry failed jobs | `php artisan queue:retry all` |

Run each `php artisan` command as `orbit` in `/home/orbit/orbit/apps/gateway`.

## Not built

Doctor does not report an old `last_tick_at` yet. Until it does, check [`orbit tasks:status`](/cli/tasks#orbit-tasksstatus) when task groups stop moving.

A Horizon that is down while its Process should run is ordinary Process drift. Doctor reports it as `process.state_mismatch`, or `process.crash_loop` when systemd keeps restarting it. The separate Horizon observation callback runs in the scheduler, so it records that issue within one collection cycle, and `problems:file` files it once two collections 10 minutes apart saw it. A Horizon stopped with `orbit process:stop` is not drift and is not reported.

## Why it works this way

The scheduler used to run its work, and the task VM workers, as foreground commands. A foreground command holds the scheduler's run for that minute, so a slow tick or a VM job could hold a publish for minutes. With dispatch, the scheduler's run takes milliseconds, and each queue's workers decide when a job starts.

Fleet problem collection runs as short queued steps, so it cannot hold the [scheduler drain](/reference/gateway-recovery#runtime-handoff), and an in-flight collection step holds Horizon termination for at most its 13-second worker timeout. Only the narrow Horizon observation stays outside its workers, so a stopped Horizon still gets recorded.

Horizon needs Redis. The fleet already runs Valkey on the `database` role, so the Gateway uses that registered connection instead of a second server on its own host. Orbit's records keep its address, as for every other Database connection.
