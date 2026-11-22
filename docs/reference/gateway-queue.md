---
title: "Gateway queue"
description: "The Gateway's Redis connection and ACL user, its six Horizon queues, scheduled work, and release drain behavior."
covers:
  - apps/gateway/app/Domain/Gateway/GatewayQueue.php
  - apps/gateway/app/{Infrastructure/Gateway/GatewayQueue*,Infrastructure/Gateway/GatewayHorizon*,Console/Scheduling/**,Jobs/RunScheduledCommand.php,Providers/GatewayQueueServiceProvider.php}
  - apps/gateway/config/{horizon,horizon-new-dawn,queue}.php
---

# Gateway queue

The Gateway scheduler dispatches most recurring work as queued jobs executed by [Laravel Horizon](https://laravel.com/docs/horizon), so slow tasks never block the scheduler loop. Lightweight local checks and cleanup run directly in the scheduler, as listed under [Scheduled work](#scheduled-work).

## Connection

The `gateway` queue connection uses Laravel's Redis driver backed by a registered [Redis Database connection](/reference/database-connections), such as `gateway-queue` on a Valkey [Database server](/reference/database-servers). Configure the Gateway's [`horizon` Process](#horizon) before enabling the queue, set the variables below in `/home/orbit/shared/gateway.env`, and run a release so both the cached configuration and Horizon restart together.

| Key | Default | Effect |
| --- | --- | --- |
| `ORBIT_GATEWAY_QUEUE_CONNECTION` | empty | Slug of the Redis Database connection. Leaving it empty keeps eligible scheduled commands inline |
| `ORBIT_GATEWAY_QUEUE_REDIS_DATABASE` | `1` | Redis database index dedicated to the queue and Horizon |

The Gateway resolves the connection's host, port, username, and password from its database when a process first touches Redis, using Predis so no PHP Redis extension is required. Queue keys use the prefix `orbit-gateway:` and Horizon keys use `orbit-gateway-horizon:`. The connection sets `block_for => 5`, `retry_after => 1800`, and `after_commit => true`.

The Valkey server listens only on its WireGuard address with append-only persistence (`--appendonly yes`) and `maxmemory-policy noeviction` so queued jobs survive restarts and are never evicted.

## Queues

Horizon runs six queues on the `gateway` connection across five supervisors. The `tasks` supervisor polls `tasks` before `problems` with balancing disabled, while `builds`, `operations`, `operation-starts`, and `operations-prompt` each have their own supervisor:

| Queue | Processes | Jobs |
| --- | --- | --- |
| `tasks` | 3 | Scheduled commands, approved commit publishing, and new container [workspace baselines](/reference/development-containers#workspace-work-state) |
| `problems` | shares the 3 `tasks` workers | Observation context publication, Swamp credential renewals, and batch metadata pruning |
| `builds` | 2 | Base snapshot refresh admission and image Operations |
| `operations` | 1 | Serialized Swamp reconciliation, schedule fires, Route promotions, and registry closes |
| `operation-starts` | 4 | Concurrent [direct Swamp starts](/reference/operations#direct-swamp-starts) (timeout 150 s, memory cap 256 MB) |
| `operations-prompt` | 1 | [Ended run projection](/reference/operations#how-an-operation-runs) and [executor wake handoffs](/reference/executor#the-wake-inside-the-request) (timeout 150 s, memory cap 256 MB) |

A slow pass on `operations` never blocks `operations-prompt`, and four `operation-starts` workers let concurrent starts proceed while one waits up to 120 seconds for Swamp confirmation. Status transitions and target holds keep concurrent starts safe.

General jobs run for at most 1,700 seconds before Laravel's 1,800-second `retry_after` window releases an unacknowledged reservation. Because `tries` is 1, a reservation that times out fails instead of running twice. Idle workers block-pop with a 5-second timeout, so new jobs start within a second when a worker is free.

## Scheduled work

When the queue is configured, the scheduler dispatches these commands as unique jobs so a slow tick never overlaps itself:

| Command | Cadence | Queue | Unique for |
| --- | --- | --- | --- |
| `gateway:release:auto` | every 5 minutes | `tasks` | 300 seconds |
| `orbit:vault-sync` | every minute | `tasks` | 900 seconds |
| `orbit:activity-finalize-interrupted` | every 5 minutes | `tasks` | 600 seconds |
| `horizon:snapshot` | every 5 minutes | `tasks` | 300 seconds |
| `operations:repair-fence` | every 5 minutes | `operations` | 300 seconds |
| `conn:threads:read` | every minute | `problems` | 120 seconds |
| `gateway:swamp-credentials` | hourly | `problems` | 900 seconds |
| `queue:prune-failed` | daily | `tasks` | 3,600 seconds |
| `activitylog:clean` | daily | `tasks` | 3,600 seconds |
| `queue:prune-batches` | daily | `problems` | 3,600 seconds |
| `process:artifacts:check-latest` | daily | `tasks` | 3,600 seconds |
| `task:pr-gate`, `task:pr-when-done`, `task:merge-round`, `task:copy-settle`, `task:insights-refresh` | periodic (Tasks enabled) | `tasks` | per command |

Each queued command runs in its own PHP process and logs a non-zero exit as an error. `conn:threads:read` runs only while Conn and `ORBIT_CONN_THREAD_INDEX_POLL` are enabled. `queue:prune-failed`, `activitylog:clean`, and `queue:prune-batches` enforce the [disk retention](/reference/gateway-recovery#disk-retention) windows.

The scheduler also dispatches these background jobs on the queue:

- `ReconcileSwampOperations` (every minute on `operations`, unique 300 s): reads terminal results of open Swamp runs and records pass timestamps for [`gateway:status`](/cli/gateway/status).
- `ReconcileExecutorWakes` (every 5 seconds on `operations-prompt`): relays committed wake notices and leases completion or hook handoffs ([Wake handoff recovery](/reference/executor#wake-handoff-recovery)).
- `CloseWebSocketOperations`, `CheckDevelopmentDeployments`, `PollSwampScheduledResults`, and `InstanceRemovalReaper::Trigger`: periodic lifecycle reconciliation jobs.
- `AdmitFleetRollout` (daily at 03:05 Europe/Amsterdam), `AdmitFleetReboot` (daily at 03:06 Europe/Amsterdam), and `CheckFleetRebootConfirmation` (every 5 minutes): fleet maintenance passes.

These lightweight local commands stay inline in the scheduler even when the queue is enabled:

| Command | Cadence | Overlap lock |
| --- | --- | --- |
| `doctor:collect` | every 5 minutes | overlap-locked |
| `problems:file` | hourly | 30 minutes |
| `project-documents:probes:reconcile` | every minute | 10 minutes |
| `project-documents:cleanup:work` | every 5 minutes | 60 minutes |

`doctor:collect` evaluates stored agent facts locally with no network calls, commits findings and collection timestamps in one transaction, and runs even when Tasks or the queue is disabled. `problems:file` files ready fingerprints hourly under [filing eligibility](/reference/tasks#when-a-fingerprint-is-ready).

### Without a queue

When `ORBIT_GATEWAY_QUEUE_CONNECTION` is empty, eligible scheduled commands run inline with overlap locks and `horizon:snapshot` is skipped. Operations still require the queue: admitting a native Swamp start without it fails with `swamp.start_enqueue_failed`.

## Horizon

Horizon runs as the Gateway Node's systemd Process named `horizon` (or `orbit-gateway-horizon.service` from `gateway` role convergence) with `Restart=always`, executing `php artisan horizon` from `/home/orbit/orbit/apps/gateway`.

Its unit sets `KillMode=mixed`, `KillSignal=SIGTERM`, `SendSIGKILL=no`, and `TimeoutStopSec` equal to the longest supervisor timeout plus 30 seconds so stops drain active jobs cleanly. During a system shutdown or reboot, the unit's `ExecStop` guard checks `systemctl is-system-running` and kills the unit immediately when the host is `stopping` so shutdown never stalls waiting on dead network links. On a bootstrapped Gateway, the unit is ordered after `valkey-server.service`.

The [release run](/reference/gateway-recovery#release-as-a-swamp-run) drains and restarts Horizon across cutover; it never creates the Process. New supervisors added to `config/horizon.php` start automatically on the release that ships them.

### Reservation metadata failures

Laravel pops a Redis job before Horizon records its reservation metadata on a separate connection. `GatewayHorizonReservation` catches failures on that metadata write, logs `Horizon could not record a job reservation.` with the job UUID, queue, worker PID, and exception message, and returns the job to the worker without discarding it or adding an attempt.

## Dashboard

Open `https://gateway.orbit/horizon` from any WireGuard peer with Gateway access to view [Horizon New Dawn](https://github.com/hardimpactdev/horizon-new-dawn). Peers without Gateway access receive HTTP 403.

Because the Gateway uses no browser sessions or CSRF tokens, dashboard requests that change state require `Sec-Fetch-Site: same-origin` (HTTP 403 otherwise). Bulk actions run on the `tasks` queue, and releases publish dashboard assets during `composer install` via `php artisan horizon-new-dawn:assets`.

## Releases

On a container Gateway, the serving container owns Horizon and the scheduler and drains active jobs during handover. On a host PHP-FPM Gateway, the activate child stops Horizon (verifying `KillMode=mixed` and `SendSIGKILL=no`), switches both release symlinks, restarts running application services, and starts Horizon on the new release. Stopped Horizon Processes remain stopped across cutover.

Other requests may keep enqueueing jobs in Redis during a drain; job IDs, attempts, delays, and chains stay intact. Because a job queued by one release can execute on the next, any change to a queued job's class, constructor, or tables must stay backward-compatible for one release before removing the old form.

## When Redis is down

The Gateway continues serving API requests while Redis is unreachable, with bounded degradation:

- The scheduler logs failed dispatches, releases the job's unique lock, and retries on the next tick.
- A running Horizon logs failed reads and resumes automatically once Redis returns.
- Requests that must enqueue a job fail with an error until Redis recovers.

If repeated start failures cause systemd to stop restarting Horizon, start it again with `orbit process:start <id>` once Redis is healthy.

## ACL users

The queue and Horizon authenticate to Valkey as the dedicated ACL user `gateway-queue`, restricting access to queue keys only:

| User | Keys | Commands | Used by |
| --- | --- | --- | --- |
| `default` | All | All | Admin user and the [AI router collector](/reference/ai-router#collection) |
| `gateway-queue` | `orbit-gateway:*`, `orbit-gateway-horizon:*` | Scoped list below | Gateway queue and Horizon |

Create the user with [`database:create`](/reference/database-servers#create-an-acl-user-on-a-valkey-server). Orbit automatically appends `resetchannels`, `-@all`, and `-@admin -@dangerous -function -script` so admin commands, pub/sub, and script flushing stay blocked:

| Flag | Purpose |
| --- | --- |
| `--pattern='orbit-gateway:*'`, `--pattern='orbit-gateway-horizon:*'` | Matches both queue and Horizon key prefixes |
| `--allow=@connection` | Predis `HELLO`, `CLIENT SETINFO`, and `SELECT` |
| `--allow=@transaction` | `MULTI` and `EXEC` for snapshots and the dashboard |
| `--allow=@keyspace` | Key expiry, deletion, and `SCAN` for metrics cleanup |
| `--allow=@string`, `@list`, `@hash`, `@set`, `@sortedset` | Queue lists, sorted sets, `BLPOP`, and Horizon hashes |
| `--allow=eval` | Queue Lua scripts (Valkey still enforces command rules inside scripts) |
| `--allow=time` | Dashboard pending-job scripts |

```bash
orbit database:create gateway-queue --server=services-valkey --database=1 \
  --pattern='orbit-gateway:*' --pattern='orbit-gateway-horizon:*' \
  --allow=@connection --allow=@transaction --allow=@keyspace --allow=@string --allow=@list \
  --allow=@hash --allow=@set --allow=@sortedset --allow=eval --allow=time
```

Set `ORBIT_GATEWAY_QUEUE_CONNECTION=gateway-queue` in `/home/orbit/shared/gateway.env` and deploy the current commit so Horizon restarts as `gateway-queue`. Verify with `orbit doctor --family=database_server` and check the Horizon log for `NOPERM` errors.

## Operations

Run these commands to inspect or control Horizon, running `php artisan` as `orbit` in `/home/orbit/orbit/apps/gateway`:

| Task | Command |
| --- | --- |
| Inspect Horizon | `orbit process:list --node=<gateway>` and `orbit process:logs <id>` |
| Graceful restart | `php artisan horizon:terminate` |
| Pause or resume queues | `php artisan horizon:pause` and `php artisan horizon:continue` |
| Count waiting jobs | `php artisan queue:monitor gateway:tasks,gateway:problems,gateway:builds,gateway:operations,gateway:operation-starts,gateway:operations-prompt` |
| Retry failed jobs | `php artisan queue:retry all` |

A stopped Horizon Process whose desired state is running appears in Doctor as `process.state_mismatch` or `process.crash_loop`.

## Why it works this way

Running slow work in Horizon keeps the scheduler tick fast (milliseconds) and prevents long builds or Swamp starts from blocking unrelated ticks. Sharing the fleet's existing Valkey server avoids operating a second Redis instance on the Gateway host, while a dedicated ACL user isolates queue keys from other Valkey clients.
