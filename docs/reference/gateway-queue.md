---
title: "Gateway queue"
description: "The Gateway's Redis queue, its two queues, Laravel Horizon, and how a release moves them."
covers:
  - apps/gateway/app/Domain/Gateway/GatewayQueue.php
  - apps/gateway/app/{Infrastructure/Gateway/GatewayQueue*,Infrastructure/Gateway/GatewayHorizon*,Console/Scheduling/**,Jobs/RunScheduledCommand.php,Providers/GatewayQueueServiceProvider.php}
  - apps/gateway/config/{horizon,horizon-new-dawn,queue}.php
---

# Gateway queue

The Gateway scheduler only dispatches. Each scheduled command that does work, such as `tasks:tick`, becomes a queued job, and [Laravel Horizon](https://laravel.com/docs/horizon) runs the jobs. A long build therefore never delays a tick, and a slow tick never delays the next minute of the schedule.

## Connection

The queue connection `gateway` uses the Redis driver. Its Redis server is a [Redis Database connection](/reference/database-connections) that Orbit already has, such as the Valkey on the `services` Node (`services-valkey`). Set these keys in `/home/orbit/shared/gateway.env`, then [apply the env change](/reference/gateway-recovery#apply-an-env-change):

| Key | Default | Effect |
| --- | --- | --- |
| `ORBIT_GATEWAY_QUEUE_CONNECTION` | empty | The slug of the Redis Database connection. Empty keeps the [inline schedule](#without-a-queue) |
| `ORBIT_GATEWAY_QUEUE_REDIS_DATABASE` | `1` | The Redis database index of the queue and Horizon. Use an index that no other user of that server uses |

The Gateway reads the host, port, and password of that connection from its records when a process first uses Redis. When the key names no Redis connection, the Gateway logs an error and every dispatch to the queue fails. Every key starts with `orbit-gateway:`, and Horizon's own keys with `orbit-gateway-horizon:`. The Redis client is Predis, so the Gateway needs no PHP Redis extension.

`services-valkey` listens on the WireGuard address of `services` and has no password. Only WireGuard peers reach it. A Valkey ACL user with a password for the Gateway is a follow-up.

## Queues

Laravel Horizon runs two queues on the connection. Each has its own supervisor, so a long build never holds up a short job:

| Queue | Processes | Jobs |
| --- | --- | --- |
| `tasks` | 3 | The scheduled commands below, task VM provision, enroll, prepare, park, resume, and destroy, the publish of an approved commit, the host sweep, and the pool refill |
| `builds` | 1 | The [base image refreshes](/reference/compute-drivers#build-the-base-image), the [UpCloud template refresh](/reference/compute-drivers#refresh-the-upcloud-template-every-night), and the [workspace template](/reference/compute-drivers#build-a-template) refreshes and builds |

A job runs at most 1700 seconds. A job that a worker lost, for example when its process was killed, runs again after 1800 seconds. An idle worker waits on the queue with a blocking read, so a job starts within about a second when a process is free.

One `builds` process runs one build at a time in the fleet. The host locks also allow one template build and one base image build or sync per host.

## Scheduled work

The scheduler dispatches each of these as a unique job. While the job waits or runs, the scheduler dispatches no second one, so a slow tick never stacks up:

| Command | Every | Queue | Unique for |
| --- | --- | --- | --- |
| `tasks:tick` | 10 seconds | `tasks` | 330 seconds |
| `annotations:dispatch` | 10 seconds | `tasks` | 600 seconds |
| `orbit:deploy-development-defaults` | minute | `tasks` | 1800 seconds |
| `orbit:vault-sync` | minute | `tasks` | 900 seconds |
| `orbit:desired-fleet-state` | 5 minutes | `tasks` | 600 seconds |
| `orbit:activity-finalize-interrupted` | 5 minutes | `tasks` | 600 seconds |
| `problems:collect` | 10 minutes | `tasks` | 900 seconds |
| `problems:file` | hour | `tasks` | 1800 seconds |
| `horizon:snapshot` | 5 minutes | `tasks` | 300 seconds |

The job runs the command in its own PHP process, as the scheduler did, so a command keeps no state from an earlier run. A command that exits nonzero is logged as an error. `tasks:tick` keeps its own lock of 300 seconds. The "unique for" time only matters when a worker is lost: the lock then frees itself after that time.

The [task VM jobs](/reference/compute-drivers#jobs) that the scheduler queues, the host sweep, the pool refill, and the nightly refreshes, go to the same connection.

The [document cleanup](/reference/project-documents) commands stay in the scheduler, because their permit belongs to the scheduler's start.

### Without a queue

While `ORBIT_GATEWAY_QUEUE_CONNECTION` is empty, the scheduler runs the commands above itself, with their overlap locks, as before, and no Horizon unit is installed. Task VMs need the queue: their jobs fail with a clear error until the key is set.

## Horizon

`orbit-horizon.service` runs `php artisan horizon` as `orbit` from the stable path `/home/orbit/orbit/apps/gateway`. It has the scheduler's `PATH` and `NODE_USE_SYSTEM_CA=1`, restarts 5 seconds after any exit, and never gives up restarting.

`php artisan orbit:gateway-web` and the scheduler phase of each release's [runtime handoff](/reference/gateway-recovery#runtime-handoff) install it while `ORBIT_GATEWAY_QUEUE_CONNECTION` is set. While it is empty, they stop and remove the unit if it exists. The handoff records the outcome as `horizon`: `started`, `stopped`, or `none`. A failed step of the unit fails with `gateway.horizon_unit_failed`, and the handoff then fails with `gateway.release_units_failed`. A failed move of database jobs fails with `gateway.queue_move_failed` and fails the handoff the same way.

## Dashboard

Open `https://gateway.orbit/horizon` in a browser on a machine that is a WireGuard peer with Gateway access, the same access that the Orbit CLI needs for Gateway commands. The dashboard is [Horizon New Dawn](https://github.com/nckrtl/horizon-new-dawn), a React interface for Horizon. Any other address gets 403.

The dashboard can retry, cancel, and clear jobs, and pause queues, so anyone with Gateway access can change the queue. It takes no CSRF token, because the Gateway keeps no browser session. Instead, the Gateway refuses a dashboard request that comes from another site: the browser's `Origin` must be the Gateway's own, or `Sec-Fetch-Site` must be `same-origin` or `none`. Bulk actions run as jobs on the `tasks` queue.

Each release publishes the dashboard's compiled assets during `composer install` in [prepare](/reference/gateway-recovery#prepare-a-release), with `php artisan horizon-new-dawn:assets`. The first release with the dashboard runs its migration, which adds the table `horizon_new_dawn_batch_metadata`. The scheduler runs `horizon:snapshot` every five minutes for the dashboard's metrics.

The Redis server needs `maxmemory-policy noeviction`, so that it never drops a queued job to free memory. `services-valkey` has it. It has no `maxmemory`, so it can use the memory of `services`; a limit for the Gateway's use is a follow-up.

## Releases

A release moves the queue in the scheduler phase, after the old scheduler drained:

1. It installs the unit and enables it.
2. It moves every job that is still in the database queues of the previous releases, `task-vms`, `task-vm-git`, `task-vm-templates`, and `task-vm-images`, to `tasks` or `builds`. A delayed job keeps its time, and a job keeps its unique lock. No job is lost.
3. It starts Horizon, or sends SIGTERM to the running Horizon master, which is what `php artisan horizon:terminate` does.

Horizon uses fast termination, and the unit stops only the master. Each running job finishes in its old worker process, on the release it started on. systemd starts the master again at once, on the new release, so new jobs start right away. For the length of a running build, two builds can run: the old one and a new one. The host locks still allow one build per host.

### Switch back to an older release

The unit starts only while the current release has `config/horizon.php`. The Horizon master also compares the current release with its own each second, and terminates itself when they differ. After a switch back to a release from before Horizon, the master therefore stops, and the unit does not start again. The older release's scheduler then runs its own workers on the database queues. Jobs left in Redis wait for a later release.

### Jobs across releases

A job queued by one release can run on the next one, and during a release, on the one before. A change to a queued job's class, its constructor properties, or a table that a job reads, must work with both releases for one release. Remove the old form in a later release.

## When Redis is down

- The scheduler logs each failed dispatch as an error and frees the job's unique lock, so the next run dispatches it again. The scheduler keeps running.
- Horizon exits, and systemd starts it again every 5 seconds until Redis answers.
- A request that queues a job, such as a task VM claim, fails with an error.

When Redis answers again, Horizon starts and works the queued jobs. A job that Redis lost runs again only when its owner queues it again. For example, the tick queues a publish again after its backoff.

## Operations

| Task | Command |
| --- | --- |
| See Horizon | `systemctl status orbit-horizon` and `journalctl -u orbit-horizon` |
| Restart on the current release | `php artisan horizon:terminate` |
| Pause and continue every queue | `php artisan horizon:pause` and `php artisan horizon:continue` |
| Count waiting jobs | `php artisan queue:monitor gateway:tasks,gateway:builds` |
| Retry failed jobs | `php artisan queue:retry all` |

Run each `php artisan` command as `orbit` in `/home/orbit/orbit/apps/gateway`.
