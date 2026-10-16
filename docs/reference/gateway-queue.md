---
title: "Gateway queue"
description: "The Gateway's Redis connection and ACL user, four queues, Laravel Horizon, and how a release moves them."
covers:
  - apps/gateway/app/Domain/Gateway/GatewayQueue.php
  - apps/gateway/app/{Infrastructure/Gateway/GatewayQueue*,Infrastructure/Gateway/GatewayHorizon.php,Console/Scheduling/**,Jobs/RunScheduledCommand.php,Providers/GatewayQueueServiceProvider.php}
  - apps/gateway/config/{horizon,horizon-new-dawn,queue}.php
---

# Gateway queue

The Gateway scheduler dispatches most of its work. Each scheduled command that does work, such as `tasks:tick`, becomes a queued job, and [Laravel Horizon](https://laravel.com/docs/horizon) runs the jobs. A long build therefore never delays a tick, and a slow tick never delays the next minute of the schedule. Problem collection and filing, and document cleanup, run in the scheduler itself, as [Scheduled work](#scheduled-work) lists.

## Connection

The queue connection `gateway` uses the Redis driver. Its Redis server is reached through a [Redis Database connection](/reference/database-connections), such as `services-valkey-gateway` for the Valkey on the `services` Node. Set these keys in `/home/orbit/shared/gateway.env`, then run a release. The release caches them and creates the [`horizon` Process](#horizon). Do not only [apply the env change](/reference/gateway-recovery#apply-an-env-change): the scheduler would then dispatch to the queue before Horizon runs.

| Key | Default | Effect |
| --- | --- | --- |
| `ORBIT_GATEWAY_QUEUE_CONNECTION` | empty | The slug of the Redis Database connection. Empty keeps the [inline schedule](#without-a-queue) |
| `ORBIT_GATEWAY_QUEUE_REDIS_DATABASE` | `1` | The Redis database index of the queue and Horizon. Use an index that no other client of that server uses |

The Gateway reads the host, port, username, and password of that connection from its records when a process first uses Redis. When the key names no Redis connection, the Gateway logs an error and every dispatch to the queue fails. Every key starts with `orbit-gateway:`, and Horizon's own keys with `orbit-gateway-horizon:`. The Redis client is Predis, so the Gateway needs no PHP Redis extension.

The `valkey` Docker Process on `services` is the Redis server behind `services-valkey` and `services-valkey-gateway`. It listens on the WireGuard address of `services`, so only WireGuard peers reach it. It writes an append-only file (`--appendonly yes`) to the `services-valkey-data` volume, so queued jobs survive a restart. Every client logs in with a password. The queue and Horizon log in as their own [ACL user](#acl-users), `gateway`, through `services-valkey-gateway`. That user reaches only the queue's keys.

## Queues

Laravel Horizon runs four queues on the connection. Each has its own supervisor, so a long build or deploy never holds up a short job:

| Queue | Processes | Jobs |
| --- | --- | --- |
| `tasks` | 3 | The scheduled commands below and the publish of an approved commit. The Git mirror's lock keeps one push per Project at a time |
| `task-vms` | 1 | Task VM provision, enroll, prepare, park, resume, and destroy, the host sweep |
| `builds` | 2 | The [base image step chains and refreshes](/reference/compute-drivers#build-the-base-image), the [UpCloud template refresh](/reference/compute-drivers#refresh-the-upcloud-template-on-recipe-changes), trusted [package cache fills](/reference/compute-drivers#shared-package-cache), and [recipe maintenance](/reference/compute-drivers#nightly-recipe-maintenance) |
| `operations` | 1 | [Operations](/reference/operations), such as an Instance deploy. One job runs one operation, one at a time |

The `task-vms` jobs run one at a time and in order, so no two jobs change the same VM at once.

A `task-vms` job runs at most 1500 seconds, and any other job at most 1700 seconds. An operation therefore has a deadline of 1600 seconds. A job that a worker lost, for example when its process was killed, runs again after 1800 seconds. An idle worker waits on the queue with a blocking read, so a job starts within about a second when a process is free.

Two `builds` workers let hosts build in parallel; `Bus::chain` orders each build, the database permits one running build per host, and pruning stays on that host and protects running builds.

## Scheduled work

The scheduler dispatches each of these as a unique job. While the job waits or runs, the scheduler dispatches no second one, so a slow tick never stacks up:

| Command | Every | Queue | Unique for |
| --- | --- | --- | --- |
| `tasks:tick` | 10 seconds | `tasks` | 330 seconds |
| `annotations:dispatch` | 10 seconds | `tasks` | 600 seconds |
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
| `problems:collect` | 10 minutes | 15 minutes |
| `problems:file` | hour | 30 minutes |
| `project-documents:probes:reconcile` | minute | 10 minutes |
| `project-documents:cleanup:work` | 5 minutes | 60 minutes |

[Problem collection and filing](/reference/tasks#outer-loop) watch Horizon, so they must not depend on it. A collection usually takes under a minute. While it runs, the rest of that minute's scheduler run waits, so the 10-second dispatches of that minute run late. The [document cleanup](/reference/project-documents) commands stay because their permit belongs to the scheduler's start.

### Without a queue

While `ORBIT_GATEWAY_QUEUE_CONNECTION` is empty, the scheduler runs the commands above itself, with their overlap locks, as before, and the Gateway creates no `horizon` Process. Task VMs need the queue. While task VMs are enabled, or the UpCloud token file is set, each due run of the host sweep, image reconciliation, or recipe maintenance fails with an error that names the key, instead of being skipped. A task VM claim fails the same way.

Operations need the queue too. An Instance deploy request answers `503` with `operation.queue_unavailable` and records nothing ([Operations](/reference/operations#start-an-operation)).

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

## ACL users

The `valkey` Process on `services` has two [Valkey ACL users](https://valkey.io/topics/acl/). The queue and Horizon log in as `gateway`, which can read and change only the queue's keys.

| User | Keys | Commands | Used by |
| --- | --- | --- | --- |
| `default` | All | All | The [ProxyCli collector](/reference/proxycli#collection) and the Gateway's [snapshot reads and account updates](/reference/proxycli#clients), through `services-valkey`. That Database connection has no username, so they log in as `default` |
| `gateway` | `orbit-gateway:*` and `orbit-gateway-horizon:*` | The rules in the table below | The queue and Horizon, through the Database connection `services-valkey-gateway` |

The users are in the ACL file `/data/users.acl` on the `services-valkey-data` volume. Valkey reads the file when it starts. The file holds SHA-256 hashes of the passwords, never a password. The command of the `valkey` Process holds no password either, because Orbit stores that command and shows it in `orbit process:list --json`.

```text
user default on #<sha256 of the default password> ~* &* +@all
user gateway on #<sha256 of the gateway password> resetkeys ~orbit-gateway:* ~orbit-gateway-horizon:* resetchannels -@all +@connection +@transaction +@keyspace +@string +@list +@hash +@set +@sortedset +eval +time -@admin -@dangerous
```

Valkey applies the rules of a line from left to right:

| Rule | Why |
| --- | --- |
| `resetkeys ~orbit-gateway:* ~orbit-gateway-horizon:*` | Allows only the keys of the queue and Horizon. `~orbit-gateway:*` does not match the Horizon prefix `orbit-gateway-horizon:`, so the line names both |
| `resetchannels` | Allows no pub/sub channel. The Gateway publishes and subscribes to nothing |
| `-@all` | Starts with no commands |
| `+@connection` | On each new connection, Predis logs in with `HELLO`, sends `CLIENT SETINFO`, and selects the queue's database with `SELECT` |
| `+@transaction` | `horizon:snapshot` and the dashboard use `MULTI` and `EXEC` |
| `+@keyspace` | Horizon sets expiry times on jobs and deletes them. `horizon:clear-metrics` finds metrics with `SCAN` |
| `+@string`, `+@list`, `+@hash`, `+@set`, `+@sortedset` | The queue keeps jobs in lists and sorted sets, and an idle worker waits with `BLPOP`. Horizon keeps jobs, metrics, and tags in strings, hashes, sets, and sorted sets |
| `+eval` | The queue's Lua scripts push, pop, and move jobs. Valkey checks each `redis.call` in a script against the same rules. `+@scripting` would also allow `SCRIPT FLUSH` and `FUNCTION FLUSH` |
| `+time` | The dashboard's Lua scripts for pending jobs call `TIME`. `TIME` is only in `@fast`, and `+@fast` would allow many more commands |
| `-@admin`, `-@dangerous` | Removes the commands that the categories above add, such as `KEYS`, `SORT`, `FLUSHDB`, `CLIENT LIST`, and `CLIENT KILL`. The Gateway sends none of them |

The key patterns are the boundary, not the database index. Valkey 8 has no ACL rule for a database, so `gateway` can select any database, and its key patterns apply in each one. `SCAN` lists the name of every key in a database, and `DBSIZE` counts them, including keys that the user cannot read. The values stay hidden.

### Set up the ACL users

The steps start from a `valkey` Process that writes `requirepass` from its environment value `VALKEY_PASSWORD` into a config file at each start. They move the users into the ACL file, and then move the queue to `gateway`. `default` keeps its password, so the collector and the Gateway's snapshot reads keep working. Valkey restarts once, in step 3. Its append-only file keeps the queued jobs, and [When Redis is down](#when-redis-is-down) describes the Gateway during the restart.

Run the shell commands on `services`, as `orbit`. In each new shell there, set `ctr` as in step 1. Run each `orbit` command on a machine with Gateway access. `10.44.0.3` is the WireGuard address of `services`, and `10.44.0.2` is the WireGuard address of the Gateway.

#### 1. Create the password files

Find `<id>`, the ID of the `valkey` Process, with `orbit process:list --node=services`. Then, on `services`:

```bash
umask 077
set -o noclobber
ctr=orbit-process-<id>-valkey
openssl rand -hex 32 | tr -d '\n' > ~/valkey-gateway.password
sudo docker exec "$ctr" sh -c 'printf %s "$VALKEY_PASSWORD"' > ~/valkey-default.password
set +o noclobber
sudo docker exec -i "$ctr" sh -c 'REDISCLI_AUTH=$(cat) valkey-cli PING' < ~/valkey-default.password
```

The second file copies the current `default` password out of the running container. No command prints a password. The last command must print `PONG`, which proves that the file holds the live password. If it prints anything else, delete both files and run step 1 again. `noclobber` stops a second run from overwriting a file.

Run step 1 only before step 3. After step 3, the container has no `VALKEY_PASSWORD`, so a new copy would be empty. Keep both files: the checks below and [Change a user](#change-a-user) log in with them.

#### 2. Write the ACL file

In the same shell, write the ACL file to `~/valkey-users.acl`. Then copy it into the volume as the `valkey` user, so that Valkey can read it:

```bash
default_hash=$(sha256sum < ~/valkey-default.password | cut -d' ' -f1)
gateway_hash=$(sha256sum < ~/valkey-gateway.password | cut -d' ' -f1)
cat > ~/valkey-users.acl <<ACL
user default on #$default_hash ~* &* +@all
user gateway on #$gateway_hash resetkeys ~orbit-gateway:* ~orbit-gateway-horizon:* resetchannels -@all +@connection +@transaction +@keyspace +@string +@list +@hash +@set +@sortedset +eval +time -@admin -@dangerous
ACL
sudo docker exec -i -u valkey "$ctr" sh -c 'umask 077 && cat > /data/users.acl' < ~/valkey-users.acl
sudo docker exec "$ctr" cat /data/users.acl
```

The last command shows the file in the volume. It has two lines, and each hash has 64 lowercase hexadecimal characters. The file must keep the `default` line. Without it, Valkey makes `default` a user without a password, and every WireGuard peer can use the server. Valkey ignores `requirepass` while it uses an ACL file. The file cannot hold comments. Keep `~/valkey-users.acl`: you edit that copy when a user changes.

#### 3. Start Valkey with the ACL file

Run this step when the [dashboard](#dashboard) shows no running build or operation. A job that ends during the restart runs again after 1800 seconds. [`process:update`](/cli/process#orbit-processupdate) replaces the whole specification, so the command repeats the image, port, volume, working directory, and restart policy:

```bash
orbit process:update <id> --runtime=docker --image=valkey/valkey:8 \
  --command=valkey-server --command=--appendonly --command=yes \
  --command=--dir --command=/data --command=--aclfile --command=/data/users.acl \
  --working-directory=/data --port=10.44.0.3:6379:6379 \
  --volume=services-valkey-data:/data --restart=unless-stopped
```

The update also removes `VALKEY_PASSWORD`, because an update without `--environment` clears the environment. Orbit replaces the container and starts the new one. The image's entrypoint makes `valkey` the owner of the files in `/data` and runs the server as `valkey`. Valkey reads its append-only file again, so the queued jobs stay.

#### 4. Check Valkey

Read the log of the Process:

```bash
orbit process:logs <id> --lines=50
```

The log shows `Ready to accept connections`. When it shows `Aborting Valkey startup because of ACL errors`, it names the wrong line; see [Repair the ACL file](#repair-the-acl-file). Then, on `services`, check both users:

```bash
sudo docker exec -i "$ctr" sh -c 'REDISCLI_AUTH=$(cat) valkey-cli ACL LIST' < ~/valkey-default.password
sudo docker exec -i "$ctr" sh -c 'REDISCLI_AUTH=$(cat) valkey-cli --user gateway -n 1 LLEN orbit-gateway:queues:tasks' < ~/valkey-gateway.password
```

The first command lists both users. The second prints the number of waiting `tasks` jobs. The shell in the container reads the password from standard input into `REDISCLI_AUTH`, so the password is never on a command line.

The Gateway still logs in as `default`, and Horizon reconnects by itself. Within a few minutes, check that [`orbit proxycli:status`](/cli/proxycli#orbit-proxyclistatus) shows a new `collected_at`. The collector polls every minute, and the lock of a round that the restart interrupted expires after 120 seconds.

#### 5. Register `services-valkey-gateway`

Create a new Redis Database connection with the user `gateway`. Do not change `services-valkey`. A change to that connection [reconverges the collector](/reference/proxycli#follow-the-cache-connection), which would then log in as `gateway` and lose its own keys. The machine that runs this command needs SSH access to `services` as `orbit`:

```bash
pw=$(ssh orbit@10.44.0.3 cat valkey-gateway.password) && [ ${#pw} -eq 64 ] && orbit database:create services-valkey-gateway --driver=redis --node=services --host=10.44.0.3 --port=6379 --database=1 --username=gateway --password="$pw"; unset pw
```

[`database:create`](/cli/database#orbit-databasecreate) takes the password only as `--password`. The password stays out of the shell history, but it is in the arguments of `orbit` while the command runs. The length check stops the command when SSH fails, because the Gateway would store an empty password without an error.

The Gateway stores the password encrypted and never shows it. The queue takes its database index from `ORBIT_GATEWAY_QUEUE_REDIS_DATABASE`, not from `--database`, but `1` keeps the record correct. To correct the stored password, run [`database:update`](/cli/database#orbit-databaseupdate) on `services-valkey-gateway`. That connection moves no other client.

#### 6. Move the queue

Do this step only after steps 1 to 5 work. An [automatic release](/reference/gateway-recovery#automatic-releases) also applies an edit of the env file. As `orbit` on the Gateway, set this key in `/home/orbit/shared/gateway.env`:

```bash
ORBIT_GATEWAY_QUEUE_CONNECTION=services-valkey-gateway
```

Then [run a release](/reference/gateway-recovery#deploy-a-release) of the current commit, as [Connection](#connection) requires. Read the commit as `release_sha` from `orbit gateway:status --json`:

```bash
orbit gateway:release:deploy <SHA>
```

The command follows the release, which must end `verified`. The release caches the env file again. Its handoff runs `horizon:terminate`, so Horizon starts again as `gateway` after its running jobs end ([Releases](#releases)). Both users reach the queue's keys, so no job is lost.

A newer commit can pass CI in the meantime, or GitHub can fail to answer. This manual deploy then [pauses automatic releases](/reference/gateway-recovery#pause) with `manual_deploy`. When `orbit gateway:release:auto:status` shows that pause, run [`orbit gateway:release:auto:resume`](/cli/gateway#orbit-gatewayreleaseautoresume).

#### 7. Check the queue

On `services`, list the connections of `gateway`:

```bash
sudo docker exec -i "$ctr" sh -c 'REDISCLI_AUTH=$(cat) valkey-cli CLIENT LIST USER gateway' < ~/valkey-default.password
```

It lists connections from the Gateway, `10.44.0.2`. The old Horizon master can work a running job for up to 1700 seconds. When it has exited, `CLIENT LIST USER default` shows no connection with `name=predis`. The `tick_confirmation` of `orbit gateway:release:auto:status` shows that ticks run, but not which user Horizon uses.

Open the [dashboard](#dashboard), including its pending jobs. Then read the ACL log:

```bash
sudo docker exec -i "$ctr" sh -c 'REDISCLI_AUTH=$(cat) valkey-cli ACL LOG' < ~/valkey-default.password
```

An empty list means that Valkey denied the Gateway nothing. A denied command or key shows as an entry with `username` `gateway`. Check the log even when the dashboard works. A denied command in a dashboard script leaves the page up, and only the ACL log and the Gateway's application log show it.

### Switch the queue back

Set `ORBIT_GATEWAY_QUEUE_CONNECTION=services-valkey` again and run a release of the current commit, as in step 6. Horizon then logs in as `default` and works the same jobs. Do not use `gateway:release:rollback` for this: it switches code only, and every release reads the same env file. The ACL file can stay, because `default` keeps its password and all its rights.

### Repair the ACL file

Valkey does not start when the ACL file is missing or has a wrong line. Docker then starts the container again and again, so `docker exec` fails. Correct `~/valkey-users.acl` on `services`, and copy it into the volume through a short-lived container:

```bash
sudo docker run --rm -i -v services-valkey-data:/data --entrypoint sh valkey/valkey:8 -c 'umask 077 && cat > /data/users.acl' < ~/valkey-users.acl
```

Then run [`orbit process:restart <id>`](/cli/process#orbit-processrestart). When `~/valkey-users.acl` is lost, write it again as in step 2. Step 2 takes the hashes from the two password files.

### Change a user

To change a user while Valkey runs, edit `~/valkey-users.acl` on `services`. Then copy it into the volume and load it:

```bash
sudo docker exec -i -u valkey "$ctr" sh -c 'umask 077 && cat > /data/users.acl' < ~/valkey-users.acl
sudo docker exec -i "$ctr" sh -c 'REDISCLI_AUTH=$(cat) valkey-cli ACL LOAD' < ~/valkey-default.password
```

`ACL LOAD` is all or nothing. A wrong line changes no user and returns an error. The wrong file then stays in the volume, and Valkey would not start from it. Write the active users back to the file at once, then correct `~/valkey-users.acl`:

```bash
sudo docker exec -i "$ctr" sh -c 'REDISCLI_AUTH=$(cat) valkey-cli ACL SAVE' < ~/valkey-default.password
```

A user that is not in the file loses its connections. A change made only with `ACL SETUSER` is lost at the next `ACL LOAD` or restart.

## Operations

These commands inspect and control Horizon on the Gateway.

| Task | Command |
| --- | --- |
| See Horizon | `orbit process:list --node=<gateway>` and `orbit process:logs <id>` |
| Restart on the current release | `php artisan horizon:terminate` |
| Pause and continue every queue | `php artisan horizon:pause` and `php artisan horizon:continue` |
| Count waiting jobs | `php artisan queue:monitor gateway:tasks,gateway:builds,gateway:operations` |
| Retry failed jobs | `php artisan queue:retry all` |

Run each `php artisan` command as `orbit` in `/home/orbit/orbit/apps/gateway`.

## Not built

Doctor does not report an old `last_tick_at` yet. Until it does, check [`orbit tasks:status`](/cli/tasks#orbit-tasksstatus) when task groups stop moving.

A Horizon that is down while its Process should run is ordinary Process drift. Doctor reports it as `process.state_mismatch`, or `process.crash_loop` when systemd keeps restarting it. Problem collection runs in the scheduler, so it records that issue within one collection cycle, and `problems:file` files it once two collections 10 minutes apart saw it. A Horizon stopped with `orbit process:stop` is not drift and is not reported.

The ProxyCli collector has no ACL user of its own. It logs in as `default` through `services-valkey`, and so do the Gateway's snapshot reads. Step 3 of the [AI router build](/decisions/0212-turn-proxycli-into-the-ai-router-extension#build-order) renames their keys from `orbit:proxycli:*` to `orbit:ai-router:*`. After that step, a user for them needs only the key pattern `~orbit:ai-router:*`. ADR 0212 does not plan that user yet.

## Why it works this way

The scheduler used to run its work, and the task VM workers, as foreground commands. A foreground command holds the scheduler's run for that minute, so a slow tick or a VM job could hold a publish for minutes. With dispatch, the scheduler's run takes milliseconds, and each queue's workers decide when a job starts.

Problem collection is the exception, because it is how a stopped Horizon gets recorded. As a queued job, it would stop with Horizon, and the failure it should report would go unseen. In the scheduler, a collection costs late 10-second dispatches once every 10 minutes, and a release's [scheduler drain](/reference/gateway-recovery#runtime-handoff) waits for a running collection. Both are short and need no extra machinery.

Horizon needs Redis. The fleet already runs Valkey on the `database` role, so the Gateway uses that registered connection instead of a second server on its own host. Orbit's records keep its address, as for every other Database connection.

### An ACL user for the queue

The queue has its own ACL user because Valkey is shared. As `default`, a fault in the queue or Horizon, or a leaked queue password, could read or delete the collector's keys. A second Database connection carries the user, because one connection holds one username and one password for all its clients.

The ACL user `gateway` has its own password. When `HELLO` fails, for example for a wrong username, Predis tries `AUTH` with the password alone, which logs in as `default`. With a shared password, that fallback succeeds, and the queue runs as `default` without an error.
