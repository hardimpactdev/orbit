---
title: "Update and recover a Gateway"
description: "Preserve source, state, and keys through a Gateway update, recover from a failed update, find the request logs, receive release alerts, and follow the fleet rollout."
covers:
  - apps/gateway/app/{Domain,Infrastructure}/Releases/**
  - apps/gateway/config/{app,logging,retention}.php
  - apps/gateway/app/{Infrastructure/Logging/**,Infrastructure/Database/**,Providers/Domains/DatabaseServiceProvider.php}
  - apps/gateway/{app/Domain/Gateway/GatewayCacheStore.php,database/migrations/2026_12_03_000000_allow_cold_recipe_image_rebuilds.php,database/migrations/*add_release_inputs_to_nodes.php,resources/sigstore/trusted_root.json}
  - "{apps/gateway/app/Actions/Fleet/*Rollout*.php,apps/gateway/app/Actions/Fleet/AdmitFleetCliAction.php,apps/gateway/app/Actions/Fleet/ContinueReleaseFleetAction.php}"
  - apps/gateway/app/**/GatewayReleases/**
  - apps/gateway/app/**/*GatewayRelease*.php
  - apps/gateway/{app/Domain/Nodes/NodeUpdat*.php,app/Data/Nodes/NodeUpdatingData.php,app/Domain/Fleet/**,app/Infrastructure/Fleet/**,app/Data/Fleet/**,config/fleet.php}
---

# Update and recover a Gateway

This guide helps an operator keep Gateway state safe during a source update and recover when an update fails. It uses the installation layout from the [Quickstart](/quickstart). Test the procedure on a disposable copy before you rely on it for important data.

Node convergence applies its recorded network part after the other footprint parts. A matching network digest and live state leave WireGuard untouched.

A preflight refusal with `gateway.release_node_capacity` means the container build would exceed the Node’s memory, swap, or disk allowance. Add the required capacity and repeat preflight before attempting the move.

## Gateway request logs

Gateway releases use a [Swamp run](#release-as-a-swamp-run).

The Gateway logs an exception at `ERROR` when its response status is 500 or higher. A lower status is a client refusal. The Gateway does not log it, because [Activity](/cli/activity) already records the failed request.

Every log entry written during an HTTP request carries that request's `request_id`. When the request creates an Activity row, its `activity_log.request_id` has the same value. Search the Gateway logs for the Activity row's request ID to find all related request log entries.

A transaction holds the SQLite write lock from its start, because Gateway transactions begin `IMMEDIATE`. Another writer waits up to the 5-second busy timeout, then fails with `database is locked`. That error names only the writer that waited. The holder is logged as `Long SQLite write transaction` once it ends, with `held_ms` and the `origin` file and line that opened it, when it held the lock 2 seconds or longer.

Measurement ends before post-commit callbacks from both nested and outer transactions, so broadcast and native-dispatch network time is excluded. Nested commits only release savepoints; timing continues until the outer commit releases the write lock. Task maintenance commits its terminal projection before admitting the next operation, so that admission’s GitHub token calls also run outside the projection transaction.

`swamp.vault_sync_unavailable` alone does not identify a database lock. Its `Swamp execution copy unavailable` warning records the transport `phase`, `http_status` and safe remote code. A failure at `phase=connect` with `http_status=429` means the WebSocket upgrade was throttled before a vault frame was sent. Pinned Swamp limits upgrades to 50 per source IP in a 60-second window. Correlate SQLite errors and long-write warnings separately; restarting serve does not fix the request pattern.

Gateway log files rotate daily and keep 14 days. `LOG_DAILY_DAYS` changes the count.

### Disk retention

The [gateway role convergence](/cli/node/role-add) installs the host log-retention files: `journald.conf.d/orbit-retention.conf`, `logrotate.d/orbit-gateway` and the hourly `logrotate.timer` drop-in. Releases leave them unchanged. Laravel's daily logs keep their own rotation policy.

The Gateway scheduler runs Laravel's `queue:prune-failed` daily. `ORBIT_FAILED_JOBS_HOURS` defaults to 168 hours. The daily `queue:prune-batches` uses `ORBIT_JOB_BATCH_HOURS`, which defaults to 24 hours, for finished, unfinished, and cancelled batches. The daily `activitylog:clean` deletes [Activity](/cli/activity) records older than `ORBIT_ACTIVITY_LOG_DAYS`, which defaults to 30 days. Task insights keep their own fields, so they do not depend on old Activity. None of these commands vacuums SQLite: SQLite reuses the freed pages, and only a manual `VACUUM` shrinks the file. [Scheduled work](/reference/gateway-queue#scheduled-work) lists all three.

Local recovery archives need an operator retirement step. Copy a complete state set off the Gateway, verify it there, and remove superseded local copies after the maintenance operation succeeds. Do not accumulate whole-state archives inside `ORBIT_HOME`: the next archive would include them again. Normal release retention owns only `backups/pre-*.sqlite`; it does not own manually named backups, `shared/env-backups`, experiment checkouts, or manually saved recovery copies.

The release build gives Caddy access to the release's public files. The shared Gateway `.env` stays private at mode `0600`.

## Preserve a complete state set

The database alone is not a recoverable Gateway backup. Keep these inputs together and store the backup outside the machine, with access limited to administrators.

| Input | Why it matters |
| --- | --- |
| Exact monorepo commit and lock files | Reinstalls the matching CLI, Gateway, and SDK code. |
| Gateway `.env` | Holds configuration. It can hold the encryption key or a custom database path. |
| Complete `ORBIT_HOME` | Holds `gateway.sqlite` and its WAL files, the SSH identity and known hosts, WireGuard keys, the root CA, generated state, and `gateway.app-key` when the `.env` has no key. |
| Machine configuration or VM snapshot | Keeps service, firewall, DNS, network, ownership, and package state when an update changes them. |
| Application backups | Application databases, uploads, and source are separate from Gateway state. |

The database also holds agent-reported Node facts, including the retained [`node:metrics`](/cli/node/metrics) snapshot and its original observation time. Restoring only the disposable presence cache cannot recover those snapshots. After a restore, metrics reads use the saved observation until the agent reports again.

A nonempty `APP_KEY` in the environment takes precedence over `ORBIT_HOME/gateway.app-key`. Preserve the effective key with its encrypted data. Do not generate a replacement key during an update or restore. A retained public CA certificate cannot replace its private key.

## Back up before an update

Schedule a maintenance window and stop CLI users and automation from changing the fleet. Record the current commit, Gateway status, and the first application's successful HTTPS response. Keep provider-console access available.

On the Gateway, record the source revision as `orbit`:

```bash
cd /home/orbit/orbit
git status --short
git rev-parse HEAD
apps/cli/orbit gateway:status
```

Resolve or preserve local edits before checkout changes. Stop incoming requests and any running Gateway console operations before copying state. For a complete machine recovery point, take a provider or hypervisor snapshot while the machine is shut down. Store the recorded commit and snapshot identifier with the backup.

For a state archive on a quiescent machine, run these commands from a root console. Use a new backup directory for each attempt:

```bash
sudo systemctl stop orbit-runtime-hibernator.timer
sudo systemctl stop orbit-runtime-hibernator.service caddy php8.5-fpm
sudo install -d -m 0700 /root/orbit-backups
test ! -e /root/orbit-backups/gateway-before-update.tar.gz || exit 1
sudo tar --acls --xattrs -czpf /root/orbit-backups/gateway-before-update.tar.gz \
  -C / home/orbit/.orbit home/orbit/orbit/apps/gateway/.env
sudo chmod 0600 /root/orbit-backups/gateway-before-update.tar.gz
sudo tar -tzf /root/orbit-backups/gateway-before-update.tar.gz
```

Stopping `orbit-runtime-hibernator.timer` also stops the idle sleep of development containers, which runs in the same command.

In the release layout, archive `home/orbit/shared/gateway.env` instead of `home/orbit/orbit/apps/gateway/.env`, which is a link there.

Adjust the paths when `ORBIT_HOME` or `DB_DATABASE` differs. Include an external database path and its `-wal` and `-shm` files, as [SQLite WAL default](/solutions/sqlite-wal-default) explains. Pause Gateway timers and external automation too; stopping the web services alone does not stop console writers. Do not copy a live SQLite file without its journal state. Transfer the archive to protected storage and verify that it can be read before changing source.

The archive contains secrets. Do not attach it to a bug report or commit it to Git. A successful archive listing verifies readability, not restore behavior.

## Move the Gateway to release containers

The Gateway Node keeps Caddy, WireGuard, the SQLite database and the Gateway identity. An unprivileged container runs the application with FrankenPHP in Laravel Octane worker mode. The first move runs the same commit and uses the same database on both sides of the switch.

Keep console access and a verified off-host recovery copy. Check space for three Gateway containers: preparation, live and the stopped old. The Gateway never holds more. Stopped containers use only storage. Test queue and cache access from the private bridge. A host-loopback address points into the container.

Before waking preparation, each new build measures the serving workload, free RAM, free swap and free space in the release pool. A capacity refusal reports the resource and the required and available bytes. A later attempt can release the same commit when space is available. See the [production capacity rules](/reference/production-containers#capacity).

The admitted commit pins PHP, FrankenPHP and Composer. The Node installs a changed runtime in its own immutable directory and mounts it read-only in preparation. The candidate inherits this mount. Each release keeps the runtime selected by its commit until it drains.

Preparation updates the monorepo checkout in place. Composer installs the locked production packages in `apps/gateway`. The Gateway loads two PHP SDK log-stream types through its own autoloader. Its install hook reads SDK and CLI source to generate the API contracts. CI builds `apps/web` with Bun. The release installs that commit's verified web artifact at `/home/orbit/web/current` before cloning. At boot, Incus mounts the candidate’s checkout and web tree read-only. Laravel can write its bootstrap cache and shared storage. Preparation keeps its writable checkout. The candidate serves the web app and the Laravel API together.

After preparation is asleep, the candidate runs pending Laravel migrations directly against the production SQLite database before traffic switches. The container release takes no pre-migration database copy.

Every migration is expand-only: a release adds what its code needs, and a following release contracts what neither live nor old still reads. So the stopped old keeps running on the current database, and a rollback never restores data. Reviewers reject destructive migrations.

If another migration holds the lock or a migration fails, the previous container keeps serving and the release records the error. Repair the database with a corrective migration or an operator change.

The Gateway expects its managed Horizon and scheduler Processes to be named `horizon` and `schedule-work`. Preflight reports a custom name before the move. Record the Process configuration and desired state, recreate it under the expected name, then verify a host release to obtain a current Operation receipt.

1. Run `orbit gateway:auto-release:disable`. Wait for any active release to finish.
2. Deploy the container tooling through the host flow. Record its verified Operation ID, full serving commit and published web archive.
3. Run `bin/gateway-container-prepare` in check mode with the Node's storage, network and image settings. Apply the same settings.
4. From the verified host release, run `sudo bin/gateway-container-move <verified-release-operation-id> <published-web.zip>`. This moves the same commit and preserves the database.
5. Check `/up`, `orbit gateway:status` and an authenticated peer request through the public Gateway name. Check the background workers.
6. If the move was interrupted, run `sudo /usr/local/libexec/orbit-gateway-rollback --recover` at the Node console. Inspect the selected commit, public traffic and background services.
7. Release the next forward commit. Check that the former live stops as old after HTTP and Horizon drain, and that preparation returns. Until then the Gateway has no old to roll back to.
8. Converge the Gateway role to retire host PHP-FPM. Restore the previous automation setting.

A failed Operation cleanup check at the end of the move does not fail it. Cleanup Tasks wait for the next verified release.

The installed phase dispatcher accepts commits that carry recovery protocol 4 or later. Protocol 4 keeps a stopped old, journals a rollback before old starts and stops a former live only after the response wait. A dispatcher from before it strands the stopped old and any interrupted handover. Converge the Gateway's container helpers first, then release.

After replacing the shared Gateway environment file, run `sudo /usr/local/libexec/orbit-gateway-environment configure` on the Node. It refreshes the file visible to the container and applies the configuration to the serving release.

The same refresh carries the host's nested-fleet files into the container. On the nested Gateway of an Instance fleet, the fleet rules write `/etc/orbit/instance-fleet` and the egress proxy in `/etc/environment` on the host, not in the container. Each refresh copies both into the read-only environment mount as `instance-fleet-environment`, `instance-fleet-proxy.env` and then `instance-fleet`, and removes the marker first when the host has none.

`instance-fleet-proxy.env` holds only the six proxy lines of `/etc/environment`. Each preparation installs the drop-in `50-orbit-fleet-proxy.conf` for every `orbit-gateway-*` and `orbit-process-*` unit, so web, Horizon, the schedule and the Gateway Processes load that file with an optional `EnvironmentFile=-`. On the host the fleet rules give the php-fpm pool the same proxy. An outer Gateway's host publishes no file, so its units get no proxy setting.

After the fleet rules change either file on the host, run `sudo /usr/local/libexec/orbit-gateway-environment refresh`. The Gateway's own reads of the marker and proxy need no restart, because it reads the copies on each use; a unit reads the proxy file when it starts, so restart the units, or let the next release start them.

Preparation, every candidate and the reactivated old share that mount, so a release or a rollback never loses them. A release container, marked by `ORBIT_GATEWAY_CONTAINER=1`, reads only these copies. See [Instance host in the fleet](/reference/development-containers#instance-host-in-the-fleet).

An established Gateway has three containers: a stopped preparation container, a serving container and a stopped old container with `boot.autostart=false`. A release never adds a fourth.

Orbit finishes an interrupted handover before it builds, and refuses a build while old runs.

A recorded old whose container is gone is forgotten instead of blocking the release. A forward build recreates a missing preparation container first. It updates preparation in place and seals it. Then it stops preparation, renames it to `gateway-<id>`, adds the read-only boot mounts and starts it as the candidate. A candidate that fails before the switch returns to the preparation slot. The candidate migrates the shared database, passes private checks and loads compatible Swamp definitions. Operations already running can continue. New Operations can start throughout the deployment.

The switch journal records the new serving commit before the host publishes the upstream. After that decision, recovery finishes the switch. A drain timeout preserves the former container. Run `sudo /usr/local/libexec/orbit-gateway-rollback --recover` to resume the journal.

Retire is one ordered list of steps. Each checks state, then acts or skips, so a release, a native or console rollback and `--recover` replay it the same way, with at most three containers at each step:

1. It starts Horizon on the new live. It checks the new live through the public name and records the result in the inventory's `verified` list.
2. It moves the scheduler and the background units. It waits for the former live's HTTP requests and Horizon jobs, then disables its Horizon and scheduler units.
3. A separate workflow step records the start of the wait. Ansible then checks every second for queued send bytes on the former live's connected or closing sockets. It records `drained`, or `timeout` after 600 seconds.
4. A failed probe counts as busy. Only a successful Incus lookup that shows the container stopped or absent ends the wait early.
5. Without that outcome, retire probes once per retry, as in console rollback and `--recover`. The bound counts from the recorded start.
6. It stops the former live with `boot.autostart=false`.
7. old becomes the most recent verified former live, else none.
8. If the new live passed its public check, every other stopped release is deleted. Otherwise the other release stays as `earlier`.

Then preparation returns:

1. Only a new live that passed its public check rebuilds preparation, from a snapshot of the release that serves.
2. Preparation stays stopped, without the live address reservation, boot mounts or enabled units.
3. A preparer cloned from another release is replaced.
4. Preparation is a ZFS clone; nothing is copied. Incus forgets the seed snapshot. Then `zfs promote` makes preparation the origin of live.
5. It destroys hidden `deleted-*` release snapshots that no clone needs. A deleted old frees its disk.

The seed refuses a pool without the zfs driver or whose `zfs.clone_copy` is not `true`, because Incus would then copy files. Before any release container is deleted, its dependent clones are promoted off it, so Incus never parks a dataset in `deleted/`. It refuses to promote while a snapshot that Incus still records would move. It refuses a fourth Gateway container. Until its cleanup and promotion finish, the preparer is marked unfinished: no release wakes it, and the next build repeats the seed.

If the new live fails its public check, the release fails after retire. Old and `earlier` fill the slots, three containers with live. Roll back to old, or fix the cause and run `--recover`: once live passes its public check, it deletes `earlier` and rebuilds preparation. The next build does the same first. Retire also checks which closed Operation versions have finished their work. To leave a broken release, [roll back](#roll-back) to old or deploy a corrective commit.

If the selected Horizon unit cannot start, inspect its journal in the selected container. Orbit keeps the outgoing workers and the handover journal. Repair the reported dependency, configuration or service in place and rerun `--recover`. For an emergency code patch, use a temporary systemd override on the affected Horizon unit to mount the corrected file read-only from a writable path in that container. Commit the same patch and deploy the forward release after the journal clears. The correction then lives in the sealed release. The temporary override stays in the stopped old until the next release replaces it.

## Release layout

The following layout describes the host deployment used before the first container move.

A Gateway in the release layout runs from immutable releases instead of an in-place checkout. Each release is one exact commit, built beside the live one, so preparing a release never changes what the Gateway serves.

The Gateway reads the current link and the release inventory itself, as the Gateway account, without the agent. So status, deploy, and rollback answer also when the Gateway's own agent is older than its code, or the release has no agent manifest. The read validates each prepared release's `REVISION`, reports incomplete directories with their modification times, and orders retained releases by preparation time with the release ID as the tie-breaker. It also reports whether the serving application supersedes the caller's running application. It writes nothing, and a layout entry that another account owns refuses the read.

For a container Gateway the read also returns the stopped `old` and `earlier` releases and the `verified` list. [Operation cleanup Tasks](/reference/operations#operation-versions-across-a-gateway-release) use them.

| Path | Content |
| --- | --- |
| `/home/orbit/releases/<id>/` | One release: a linked Git worktree of one commit, with its own `vendor/` for `apps/cli` and `apps/gateway`, and a `REVISION` file. `<id>` is the first 12 hex digits of the commit. |
| `/home/orbit/orbit` | A link to the current release. `PATH`, systemd units, the PHP-FPM `chdir`, and the Caddy root keep using this path. |
| `/home/orbit/shared/orbit.git` | The bare repository every release is a worktree of. Its `origin` is the Orbit repository. |
| `/home/orbit/shared/gateway.env` | The one Gateway env file. `apps/gateway/.env` in every release links to it. |
| `/home/orbit/shared/gateway-storage/` | The Gateway storage directory. `apps/gateway/storage` in every release links to it, so the file cache, its locks, and the logs stay the same across releases. |
| `ORBIT_HOME` | Gateway state, outside every release, as before. |

The paths come from `ORBIT_GATEWAY_CHECKOUT`, which names `apps/gateway` below the current link. Run release commands through that link, as `php /home/orbit/orbit/apps/gateway/artisan`. A shell that changed into the directory before a switch still runs the release it entered. A sealed release keeps its source unchanged. Its source directories are read-only, so an in-place `git checkout` or `composer install` inside a release fails instead of changing it. Only `apps/gateway/bootstrap/cache` and `apps/cli/storage` stay writable. The build makes the Gateway cache directory private (`0700`); PHP-FPM and release commands use the same service account.

The Gateway reports its version in `gateway:status`. When `APP_VERSION` is unset or empty, the version is the full commit in the release's `REVISION` file. Without either, the version is `dev`. Leave `APP_VERSION` out of the shared env file in the release layout; a set value always wins.

### Release as a Swamp run

`orbit gateway:release:deploy <sha>` and every [automatic release](#automatic-releases) run as one Swamp workflow. The [chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/gateway/release-deploy.mmd) shows the flow.

The Gateway admits the run. It needs the full commit and no other queued or running release. It verifies the commit's [agent pin](#build-and-seal-a-release) and selects its [web build](#web-build) from an [accepted CI run](#accepted-ci-runs). It starts a running record and records the `gateway.release` Operation under `phases.run`. At the run's start it puts one GitHub token, with contents and Actions read on the release repository, in the execution vault.

The long steps run script files from `swamp/scripts/`, which `bin/swamp-shape-gate` lints with shellcheck. Every script comes from the commit being released, so a release never runs the scripts of another. The build step pipes its script out of the fetched commit in `shared/orbit.git`. In the host PHP-FPM workflow, the web and snapshot steps run theirs from the sealed release, `/home/orbit/releases/<id>/swamp/scripts/`, and so does the activate child. A Swamp-run release or rollback of a commit from before these scripts fails at the build with `gateway.release_script_missing`: switch to such a release with `bin/gateway-rollback`.

The container workflow is described under [Move the Gateway to release containers](#move-the-gateway-to-release-containers). The host PHP-FPM `operation-gateway-release` workflow runs on the swamp role's workers on Node 209 and reaches the Gateway Node over SSH:

| Step | What it does |
| --- | --- |
| Fetch the commit | Fetches the commit into `shared/orbit.git` when it is missing. The read token reaches git only in its environment. |
| Build the release | Refuses a downgrade with `gateway.release_downgrade`, or a disk below the [floor](#migrations-and-the-snapshot) with `gateway.release_disk_low`. Builds and seals the release, and prints its grants. |
| Install the web build | Downloads the artifact, checks its digest, and extracts it with safe names only into `web/releases/<id>`. |
| Snapshot the database (host PHP-FPM releases) | Writes `backups/pre-<id>-<time>-<hex>.sqlite` with `VACUUM INTO`. Refuses with `gateway.release_disk_low` before the copy when the disk floor would break. The newest two stay, and the one a pause names. |
| Migrate | Runs `artisan migrate --force --isolated=1` from the new release and prints the end of its output with its exit code. |
| Check the migration | Fails the run when the migration's exit code is not 0. The paused record's message ends with the migration's output. |
| Wait for active Operations | Checks the Gateway DB every 5 seconds for up to 600 seconds. Refuses with `gateway.release_busy` and the blocking Operation IDs if queued, running or waiting Operations covered by the [release idle guard](#release-idle-guard) remain. |
| Load Swamp on Node 209 | Runs the [load workflow](#load-swamp) as a child, with the grants from the build. |
| Hand over the release | Runs `gateway-release-activate` as a child, from the definition the load installed: Drain Horizon → Switch the release → Verify the Gateway → Start Horizon. |

The busy guard runs immediately before Swamp load, while the old release and Horizon still serve. It reads current Operation state on each poll and proceeds as soon as none blocks. It does not stop new admissions. A refusal leaves the old release live and pauses automatic releases because migrations already ran. `force` skips only the ancestry check; it never skips this guard.

The Gateway queues the fleet pass when it records verification. Fleet `current` permits the Gateway to advance [the default refresh and seed publication](/reference/development-containers#refresh-after-a-release), when refresh is enabled. Each has its own status and Operation receipts in the release record. With rollout disabled, verification permits the same attempt. The release can stay verified while the fleet is pending or failed. The refresh has its own admission and result.

The refresh can keep a current nested app-dev host when a release changes only the play that launches Conn or the helper that starts a task turn. The refresh result records why each Node converged or skipped and which base snapshots it retook.

The build adds the worktree of the fetched commit, links the shared env and storage, installs the Composer dependencies, writes the agent manifest, and seals the release with `REVISION` and a cached configuration. It reuses a sealed release of the same commit.

The activate child drains Horizon before the switch. The Horizon unit carries the stop policy ([Horizon](/reference/gateway-queue#horizon)), so `systemctl stop` waits until the unit's whole control group is empty, as long as the drain allows. The step refuses a unit without the policy: update the Horizon Process first. A process that a job leaves behind holds the stop; for this reason a command that runs inside Horizon opens its own [SSH connection](/reference/node-provisioning#ssh-connections), and no shared connection master stays in the group.

The child starts Horizon again as a step that runs after every end of the run, so a failed drain, a failed switch or a switch back leaves Horizon running. It then switches with the new release's own [rollback script](#roll-back-without-swamp), which switches both links, reloads PHP-FPM and restarts the running services under the application.

It checks `/up` and the status version at `https://gateway.orbit`, using `/etc/caddy/orbit-cert-current/root-ca.pem`. The status must be `ok` and name the full commit or its 12-digit ID. It then resumes the release cleanups and prunes old releases. When the check fails, the same script from the new release switches back to the previous release, and the cleanups resume on it. The switch-back then fails the run with `gateway.release_switched_back`, so the Gateway reads it. Migrations are expand and contract, so the previous release runs on the new schema.

The scheduler restart pauses each release cleanup, such as Documents cleanup, under a new generation. The switch step reads the generations first with `gateway:release:cleanup`. After verify, `gateway:release:cleanup --before=<generations>` waits up to 30 seconds for the new ones, reconciles and resumes. A cleanup that cannot resume stays paused and fails only its own step; the release stays verified. Resume it with `project-documents:cleanup:reconcile` and `project-documents:cleanup:resume`.

The release switch reloads the Gateway application. The [gateway role convergence](/cli/node/role-add) owns the PHP-FPM pool, the hibernator and agent-view units and the host retention files. The fleet pass applies changed workload role inputs after verification.

The Gateway records the run's terminal result. For a host PHP-FPM release, the record includes the snapshot path. A succeeded run is `verified`, with `phases.verify.version` set, so the [fleet rollout](#fleet-rollout) follows. A step before Migrate fails the record, and the old release stays live.

A run that switched back is `switched_back`: it raises a `release_failed` alert, and the next green commit releases. Any other failure from Migrate on pauses automatic releases with reason `migration_failure` and records the failed step, including a failed switch-back. The record carries the failed step's own code when the step printed one, such as `gateway.release_downgrade`; otherwise it carries the step's code from the [chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/gateway/release-deploy.mmd).

A cancelled or interrupted run may have migrated, so it pauses with `gateway.release_run_failed`. The run budget is 3,600 seconds, including the 600-second busy guard. A run that outlives it pauses with `gateway.release_run_timed_out`, even when Swamp later reports an end. A run that ends without a readable result (`operation.result_unreadable`) pauses with `gateway.release_run_failed`. Read the run before you resume.

While the run is active, every release path refuses with `gateway.release_in_progress`: manual releases, automatic releases and rollbacks use the same admission path. Settling dead records skips the run's record. Admission holds the release lock until the run owns its record.

Read release records with `orbit gateway:release:list` and `orbit gateway:release:show <id>`. The CLI follows an admitted release until it ends. Interrupting the CLI stops following; the Swamp run continues.

After verification, pruning keeps the newest `ORBIT_GATEWAY_RELEASES_KEEP` sealed releases, plus the current and previous releases and the build served by `web/current`. The setting defaults to 3 and has a minimum of 2. Pruning also removes incomplete releases older than one hour and web builds with no retained release. A pruning failure leaves the release live; a later release retries.

### Release idle guard

This guard applies to the host PHP-FPM release path. Container Gateway releases allow Operations to continue across cutover.

A kind needs this guard when its execution input is built or its execution code is read after admission, so switching Gateway code can change the outcome of already admitted work. A whole execution plan captured at admission can continue independently. The guard currently covers these kinds:

| Covered kind | Reason |
| --- | --- |
| `instance.create` | Builds container, environment and Process input at submission; this includes container clones. |
| `instance.deploy*` | Builds deploy steps, environment and runtime input after admission; the prefix covers all deploy variants. |
| `instance.migrate` | Rebuilds container Process input against admitted units; a changed renderer can refuse those units. |
| `instance.clone` | Builds the clone's environment, Process and Schedule input at submission. |
| `instance.destroy` | Reads teardown programs and builds removal input after admission. |
| `instance.unregister` | Builds its run input from the admitted removal at start, through the same projection code as `instance.destroy`. |
| `instance.restore` | Renders its database batches at submission and records the restored Instance after its run. |
| `conn.*` | Preserves the existing guard for checkout and session setup across a switch. |
| `process.*` | Preserves the existing guard for Process unit, definition and control work, excluding read-only journal and state requests. |

Other qualifying kinds remain outside this guard pending a separate change:

| Not covered yet | Reason it also qualifies |
| --- | --- |
| `instance.transfer` | Builds source and destination Process installations, environment and setup input at submission. |
| `instance.register` | Builds setup and Process installation input after admission. |
| `instance.rollback` | Builds release activation input after admission. |
| `instance.sync_environment` | Re-renders files and checks their admitted hashes before submission. |
| `instance.run_steps` | Resolves admitted setup scripts when building native input. |
| `schedule.install_instance_definitions` | Reads admitted Instance Schedule members when building native input. |
| `route.caddy_converge` | Renders Caddy publication input at submission. |

The guard deliberately excludes `proof.*` (long runs pinned to an admitted commit and isolated fleet), `task_vm.*` (long lifecycle runs with a captured VM plan), and `vault.sync` (scheduled work with a captured vault plan). They do not rebuild admitted Instance units from the serving Gateway checkout. Other standalone resource Operations stay outside the guard. In particular:

| Excluded kind | Captured plan or safe read |
| --- | --- |
| `instance.env_import` | Reads an existing environment into the vault without rendering or installing units. |
| `route.create` | Captures the create plan at admission ([NativeRouteAdmission.php:30](https://github.com/hardimpactdev/orbit/blob/main/apps/gateway/app/Domain/Routes/NativeRouteAdmission.php#L30)). |
| `route.update` | Captures the replacement or publication plan ([NativeRouteUpdateInput.php:80](https://github.com/hardimpactdev/orbit/blob/main/apps/gateway/app/Domain/Routes/NativeRouteUpdateInput.php#L80)). |
| `route.target_set` | Captures the target switch plan ([NativeRouteTargetInput.php:133](https://github.com/hardimpactdev/orbit/blob/main/apps/gateway/app/Domain/Routes/NativeRouteTargetInput.php#L133)). |
| `route.destroy` | Captures the withdrawal inventory and removal plan ([NativeRouteRemovalAdmission.php:42](https://github.com/hardimpactdev/orbit/blob/main/apps/gateway/app/Domain/Routes/NativeRouteRemovalAdmission.php#L42)). |
| `schedule.create`, `schedule.destroy` | Captures the timer specification before starting the Operation ([ScheduleOperationAdmission.php:30](https://github.com/hardimpactdev/orbit/blob/main/apps/gateway/app/Domain/Schedules/ScheduleOperationAdmission.php#L30)). Native Schedule changes finish before recording success. |
| `schedule.enable`, `schedule.disable` | Changes the native trigger before recording success; Node timer enable uses its admitted identity ([ScheduleControlProjection.php:32](https://github.com/hardimpactdev/orbit/blob/main/apps/gateway/app/Domain/Schedules/ScheduleControlProjection.php#L32)). |
| `schedule.run` | Uses the captured command and target without re-rendering Instance units ([NativeSchedules.php:213](https://github.com/hardimpactdev/orbit/blob/main/apps/gateway/app/Domain/Schedules/NativeSchedules.php#L213)). |

`gateway.release` cannot wait for itself; release admission already serializes releases. Terminal Operations never hold the guard. `instance:rename` starts no Operation; it records the branch already reported by the Node agent.

Container Process journal and state reads create no Operation, so they do not hold this guard. Process actions still match `process.*`. Already queued `RefreshContainerProcessStatus` jobs fail as a missing class after the switch. They cannot run the removed handler or affect returned state, so the failures are harmless and use normal failed-job cleanup.

Observed successful container migrates took 182–215 seconds, container clones (`instance.create`) 20–40 seconds, and container destroys 23–75 seconds. These are observations, not guarantees; the separate `instance.clone` kind has no recorded timing here.

The wait remains 600 seconds, with a poll every 5 seconds. It is a release limit, not an Operation deadline. Migrate has a 5,700-second budget. Clone can include Project setup. Its history-based budget has a 900-second floor, or 1,800 seconds without successful history. Destroy captures 60 seconds for a checkout or 600 for a container, plus teardown, Routes and owned databases. Any of them can legitimately run longer than the release wait.

On timeout, stderr names every remaining Operation ID with `gateway.release_busy`. The release fails, the old code stays live, and automatic releases pause because migrations already ran. The Operations continue. Wait for them to settle, inspect the failed release, then resume automatic releases. The guard never proceeds through busy work, cancels it, extends its budget or stops new admissions.

### Load Swamp

Swamp serve on Node 209 reads `swamp/` from a Git checkout of the Orbit repository at `/home/orbit/apps/orbit-monorepo-staging/checkout`, and runs with `--hot-reload`. A release loads Swamp in one step, after the migration and before the switch. The build renders both grants files from the target release's `swamp/config/gateway-workflow-grants.json` and `swamp/config/serve-grants.json`, before switching code. It freezes their content and both Nodes' SSH targets in the load intent. This uses the target release, not the running Gateway's older manifests. The release run starts `operation-gateway-swamp-load` as a child with the release commit. That workflow follows its [chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/gateway/swamp-load.mmd) and runs on the `swamp` role on 209:

"Node 209" here is the Swamp Node of the production fleet: the Node that holds the `swamp` role. The Gateway finds it by that role and never by an id, so a Gateway that bootstraps its own machine has the same Swamp Node ([Gateway bootstrap prerequisites](/reference/node-provisioning#gateway-bootstrap-prerequisites)).

1. it checks the target commit and looks for an open switch on the Gateway Node before changing the staging checkout;
2. it fetches the commit and checks it out in the serve checkout; a rollback loads the retained commit;
3. two parallel official `@swamp/cfgmgmt/file` actions write the captured grant content into `swamp/grants/orbit-gateway.yaml` and `swamp/grants/orbit-serve.yaml`, as `orbit:orbit`, mode `0640`, through the admitted SSH identity;
4. beside them, it compares the commit's `swamp/ansible/requirements.yml` with the pin the swamp role last installed collections from, `/opt/orbit/ansible/requirements.yml`, and installs nothing;
5. it asks serve to reload, as hot reload would, and waits for the acknowledgement;
6. it runs `swamp access reload`, so serve creates, revokes or keeps grants to match the file;
7. it sets the [schedules of the Swamp Node](/reference/operations#schedules-of-the-swamp-node) from `swamp/config/swamp-node-schedules.json` as serve trigger overrides, with `swamp workflow trigger set`;
8. it compares the workflows serve lists with the workflows on disk, leaving out the `schedule-<uuid>` workflows that [Schedules](/reference/schedules) write into serve at any time.

The check also runs when a resumed release reuses Build. Host PHP-FPM releases can load without the container protocol when no switch is open.

The release and load definitions ship together. A load refuses an older caller that cannot supply the Gateway SSH target. Retire a checkout script mode only after the served workflow has stopped calling it: a load checks out the target before reloading the workflow.

Required checks invoke the Gateway renderer without deployment credentials and validate both grant files with the pinned serve startup parser before the commit can release.

Grant rendering stays in the Gateway; no Node script renders or publishes grants.

When the two files differ, the `Check the Ansible collections` step fails. The load and the release go on, and the release record gets `phases.swamp` with `warning: swamp.collections_drift` and `fix: orbit node:role:add 209 swamp --converge`. The swamp role converge is the one path that installs collections.

The load reads the JSON replies of `swamp serve reload`, `swamp access reload` and `swamp workflow list` with `jq`. The `swamp` role installs `jq` with its packages, so a Node that only the roles provisioned can run the load.

A schedule that serve does not confirm fails the load. These workflows have no built-in trigger, so this step is the only place that schedules them. A Swamp Node with no overrides runs neither `swamp-credential-monitor` nor `swamp-datastore-watch` until the next release Swamp load. That is a Gateway that bootstraps its own Swamp Node, or a serve that lost `.swamp/serve.yaml`. Nothing reports the gap: a run that never fires raises nothing ([Schedules of the Swamp Node](/reference/operations#schedules-of-the-swamp-node)).

The final `load` step prints the commit, the previous commit, and the workflow count. Any failure, or no terminal within the wait, pauses the release with `gateway.release_swamp_load_failed`, and the old release stays live. The release record names its Operation. A failed load can leave the checkout on the new commit; fix forward and resume.

The load is forward only unless the release is forced, as a rollback is: an unforced load of a commit that does not descend from the serve checkout fails with `gateway.release_downgrade` and leaves the checkout alone. Resume the release itself after Swamp serves the updated release and load definitions. The release starts a fresh load with the admitted inputs and checks the target again.

Serve's reload re-reads each extension manifest's `additionalFiles` ([Lab #4282](https://swamp-club.com/lab/4282), fixed in Swamp `20261103.000636.0-sha.db3f6373`), so a newly shipped file reaches workers without a restart. The load never restarts serve or a worker.

A new workflow needs no grant. The Gateway principal `user:orbit-gateway` has one `run` and `read` grant on every workflow (`workflow:*`), so serve accepts its start as soon as the load step has loaded the workflow.

### Build and seal a release

Admission reads the target commit's pin from `apps/gateway/resources/agent/frozen-manifest.json`, on GitHub from `raw.githubusercontent.com`. It verifies the published manifest, checksums and [provenance](#release-provenance) in PHP. The manifest must match the pin. It names the three agent platforms, and the [executor](/reference/executor#rollout) as `executor-linux-x86_64` from the release that first builds it; each file must match the release's `SHA256SUMS`. Changing agent source does not change the delivered observer until a commit moves the pin. An unavailable delivery returns `gateway.release_agent_delivery_unavailable`; a bad proof returns `gateway.release_provenance_invalid`.

The Swamp build step fetches the full commit through the run's GitHub token. It checks ancestry and refuses a downgrade with `gateway.release_downgrade`, unless the run has `force=true`. It then:

1. checks for free space equal to the database size plus 1 GiB;
2. creates `releases/<id>` as a Git worktree of that commit;
3. links the shared env file and storage directory;
4. runs `composer install --no-dev` and `composer check-platform-reqs --no-dev` in `apps/cli` and `apps/gateway`;
5. writes the admitted agent manifest into `apps/agent/delivery/`;
6. sets source permissions and writes `REVISION`;
7. caches the configuration privately, with mode `0600`.

A sealed release of the same commit is reused. Its configuration is cached again from the shared env file. A partial build is rebuilt on the next run of that commit. Building leaves the serving links and running services unchanged. The next step installs the [web build](#web-build).

#### Release provenance

CI signs each release with the workflow's own identity ([CLI binaries: Provenance](/reference/cli-binaries#provenance)). The Gateway checks that proof in two places. Release admission checks it before it starts the Swamp run. The [desired fleet state](/reference/self-update#how-the-gateway-resolves-it) checks it before it names a CLI digest. Both use one step. It downloads `provenance.sigstore.json` from the release without authentication. It then verifies the bundle offline, with PHP's OpenSSL extension and no call to GitHub or Sigstore.

##### What the Gateway checks

The bundle must be a Sigstore bundle v0.3 with one certificate, one DSSE envelope with one signature, and one transparency log entry. The log entry must pass these checks:

1. It is a Rekor v1 `dsse` 0.0.1 entry.
2. Its log ID names an ECDSA P-256 Rekor key of the trust root. The key is valid at the entry's integrated time.
3. The signed entry timestamp verifies with that key. It covers the entry's body, integrated time, log ID, and log index.
4. The RFC 6962 inclusion proof leads from the body to the proof's root hash.
5. The checkpoint holds the same tree size and root hash. Its signature verifies with the same key.
6. The body records the SHA-256 of the envelope payload, the envelope signature, and the bundle certificate.

The certificate and the statement must pass these checks:

1. A Fulcio CA of the trust root signed the certificate.
2. The integrated time lies inside the CA's validity and inside every certificate of its chain.
3. The integrated time also lies inside the certificate's own validity, which Fulcio sets to 10 minutes.
4. The certificate SAN is exactly `https://github.com/<owner>/<repo>/.github/workflows/orbit-cli-release.yml@refs/heads/main`. The repository is the one in `ORBIT_CLI_RELEASE_REPOSITORY`.
5. Its OIDC issuer extension, OID `1.3.6.1.4.1.57264.1.8`, is exactly `https://token.actions.githubusercontent.com`.
6. The DSSE signature over the payload verifies with the certificate key.
7. The payload is an in-toto statement v1 with SLSA provenance v1.
8. The statement names `SHA256SUMS` with the SHA-256 of the bytes the Gateway downloaded. For release admission, it also names `manifest.json` that way.

The checkpoint must also name the entry's log: its first line is the log host, a space, a hyphen, a space, and the tree ID. Every hash of the proof is 32 bytes. The issuer extension must be a minimal DER UTF8String with no trailing bytes.

The verifier does not check the certificate's SCTs, its code-signing key usage, or that it is not a CA certificate. A pinned Fulcio CA issues only leaf certificates for code signing, valid for 10 minutes. The Rekor signature and inclusion proof then show that the signature was logged inside the certificate's validity. A forged certificate would need the Fulcio CA key, which these checks do not guard against anyway.

The binaries chain through those two files as before.

A bundle that fails any check refuses. So does a Rekor v2 entry, an entry without a signed entry timestamp, or a bundle with only RFC 3161 timestamps. A missing bundle refuses too. A bundle that cannot be downloaded because of a network or server error waits, as a missing release does.

A trust root that cannot be read or parsed, or a release repository that is not on github.com, is a fault of the Gateway, not of the release. Admission then refuses with `gateway.release_provenance_unconfigured`, which waits without spending the commit's attempts. The desired fleet state reports `provenance_unconfigured`.

##### Trust root

The trust root is `apps/gateway/resources/sigstore/trusted_root.json`. It is the Sigstore public-good `trusted_root.json` from Sigstore's TUF repository, `https://tuf-repo-cdn.sigstore.dev`. It was taken on 2026-10-26 as the first line of `gh attestation trusted-root` (GitHub CLI 2.89.0), which verifies it through TUF. The verifier uses only its Fulcio CAs and its ECDSA Rekor v1 log. `ORBIT_CLI_RELEASE_TRUSTED_ROOT` names another file. Leave it unset except during the recovery below.

To refresh the trust root, run this command and merge the result:

```bash
gh attestation trusted-root | head -n 1 | jq . > apps/gateway/resources/sigstore/trusted_root.json
```

##### Failures and recovery

| Situation | What happens | Recovery |
| --- | --- | --- |
| A release has no bundle, or a bundle that does not verify | Admission refuses with `gateway.release_provenance_invalid`. The desired fleet state reports `release_unverified` | None for that commit, because its release never changes. Deploy a later commit |
| A commit whose pinned delivery has no provenance | Release admission refuses | Use [Roll back without Swamp](#roll-back-without-swamp) for a retained release when admission cannot verify its delivery |
| Sigstore adds a new Fulcio CA or Rekor key | Sigstore publishes new keys in its TUF root before it uses them | Refresh the trust root and release it while the old keys still sign |
| A release is signed with a key that the trust root lacks | Admission refuses every new release. The desired fleet state stays unavailable | Override the trust root once, as described below |
| The attest action signs with Rekor v2 or with RFC 3161 timestamps only | Every new release refuses | Revert the action's pin change, as described below |
| Sigstore's Fulcio or Rekor is down while the publish job runs | The attest step fails, so the job fails before the release exists. The Gateway waits for the release | Run the release workflow with `workflow_dispatch` for the commit once Sigstore answers ([Publish a missed commit](/reference/cli-binaries#publish-a-missed-commit)) |
| The repository becomes private | The attest action signs with GitHub's own Sigstore, with RFC 3161 timestamps only. Every new release refuses | Make the repository public again. Release provenance needs the public-good Sigstore |
| The trust root path or the repository setting is wrong | Admission refuses with `gateway.release_provenance_unconfigured` and tries again later. The desired fleet state reports `provenance_unconfigured` | Fix the setting and run [`gateway:release:configure`](#apply-an-env-change) |

To override the trust root once:

1. On a trusted machine, take the new root with the refresh command, and copy the file to `ORBIT_HOME` on the Gateway.
2. Set `ORBIT_CLI_RELEASE_TRUSTED_ROOT` to that file in the shared env file, and run [`gateway:release:configure`](#apply-an-env-change).
3. Deploy the commit that refreshes the committed trust root.
4. Remove the setting, and run `gateway:release:configure` again.

The attest action is pinned by commit SHA, so its bundle format changes only when its pin changes. When a new pin signs in a format the verifier refuses, revert the pin. Teach the verifier the new format first and release that change. Change the pin after.

#### Web build

CI uploads `apps/web` as `web-dist-<sha>` and keeps it for 14 days. Release admission selects the newest unexpired artifact for the exact commit from an [accepted CI run](#accepted-ci-runs). The artifact must have a SHA-256 digest and be no larger than 128 MiB. The main delivery run verifies and republishes the artifact of the green merge queue run on the same commit; see [main delivery](/reference/implementation-loop#deliver-the-queues-commit-on-main). A pull request or queue artifact alone cannot authorize release.

The Swamp step downloads the artifact with the run's GitHub token, checks its digest and extracts it into a private staging directory. It accepts only regular files and directories with safe relative names. It refuses links, special files, absolute paths, `..`, more than 20,000 entries or more than 512 MiB of files. The build must have `index.html` at its root. The step gives Caddy access and moves the build into `web/releases/<id>` with one rename. A complete build is reused.

The activate child switches `web/current` together with the Gateway code, before verification. A failed check switches both back. Open pages read `version.json` and move to the new build on their next navigation or resume ([Updates to open pages](/reference/web-app#updates-to-open-pages)). Pruning keeps the current web build and every retained release's build.

A missing or expired artifact refuses admission with `gateway.release_web_build_missing`. An invalid artifact returns `gateway.release_web_build_invalid`. A deploy or a host rollback needs a valid artifact even when it can reuse a sealed release. A container rollback reuses old's installed web build.

The main `Required checks` and `Gateway release inputs` checks depend on successful promotion of the merge queue run's build. Only the main run reports `Gateway release inputs` on a commit. Swamp-only changes use the same delivery path because the release also [loads Swamp](#load-swamp).

##### Accepted CI runs

The run must be a run of `.github/workflows/ci.yml` in this repository: a `push` or a `workflow_dispatch` on `main`. A red `main` commit is never released; `main` is fixed forward. A run from a fork, a pull request, another workflow, or another branch does not count. An artifact without a SHA-256 digest is refused.

### Migrations and the snapshot

Before it migrates, the host PHP-FPM workflow writes a consistent SQLite copy with `VACUUM INTO` to `ORBIT_HOME/backups/pre-<id>-<time>-<random>.sqlite`, with mode `0600`. Each attempt writes a new file. Retention keeps the newest two snapshots, the current and the previous, and the one a pause names. Snapshots stay on the Gateway; keep a [complete state set](#preserve-a-complete-state-set) elsewhere.

No rollback reads a snapshot: a rollback restores no data. The newest snapshot holds the database from before the live release's migrations, and the one before it the database from before the previous release's. An operator restores one only after a failed migration, and that run's pause names the snapshot it needs.

The run keeps the disk floor of Doctor's `node.disk_low`: at least 2 GiB and 10 % of the root disk stay free after the copy.

When the copy would break the floor, the snapshot step first removes every older snapshot except the newest and the paused one. If the copy still does not fit, the step refuses with `gateway.release_disk_low` before it writes. Nothing has migrated, so the old release stays live. Automatic releases try the commit again, and the third failed attempt raises a `release_failed` alert.

A new build checks the same floor before it writes anything. The 1 GiB for the build must fit as the disk is. The build and the database's size for the snapshot must fit when the older snapshots that the snapshot step would remove are gone. A copy that a stopped run left as `pre-*.sqlite.partial` goes at the next snapshot.

The new release runs `php artisan migrate --force --isolated=1` while the old release still serves. Migrations must use expand and contract: both releases must work with the new schema. Before a schema squash, finish the existing migration chain, as the [contributor guide](/contributor-guide#3-implement-and-verify) requires.

A failed snapshot leaves the live database unchanged. A failed migration may have applied some changes. The run then pauses and names its snapshot. Restoring that snapshot is an operator action; follow [Recover a failed update](#recover-a-failed-update).

### Roll back

A container Gateway returns to its stopped old release. A host PHP-FPM Gateway returns to a retained release.

```bash
orbit gateway:release:rollback <id>
```

`<id>` is the first 12 hex digits of its commit.

On a container Gateway, `<id>` must be old. The Gateway admits a forced `gateway.release` run with trigger `rollback` and the input `retained`. It needs no GitHub token, agent delivery or web build. The run fetches, builds and migrates nothing:

1. The build step checks old's rollback protocol and that old is stopped, then starts it and claims it for this Operation. The address wait of a cold start applies.
2. The candidate check writes old's bind address, caches the configuration from the current env file, restarts FrankenPHP and checks old privately.
3. The load step brings back old's Swamp workflows from the Swamp checkout, with force.
4. The switch, public check, Horizon start and retire run as in a release, through the same journal.
5. An interrupted rollback resumes with `--recover`.
6. Retire stops the rolled-away live as the new old. A second rollback goes forward again.

The candidate fences the Operation versions it deprecates and reopens every version it can still start. A rollback therefore reopens the versions that the rolled-away release closed. Rollback does not yet check for open work pinned to versions that only the rolled-away release defines; that work waits for a release that defines them again. A failure before the switch stops old again and leaves live serving. A rollback uses the current database as it is. It creates no container: preparation stays, and the count stays at three.

On a host PHP-FPM Gateway, the Gateway resolves that release's full commit and admits a forced `gateway.release` run with trigger `rollback`. Force skips the ancestry check. The build reuses the sealed release and refreshes its configuration. The load step brings back that commit's Swamp workflows. The host PHP-FPM workflow snapshots the database, runs migrations, switches both links and verifies the Gateway.

A host rollback needs the target commit's verified agent delivery and a valid web build. Migrations are expand-only so the retained code can use the current database. Rollback does not restore data. If you need to restore a snapshot, follow [Recover a failed update](#recover-a-failed-update). A successful rollback pauses automatic releases with reason `rollback`.

### Roll back without Swamp

On a container Gateway, run this on the Gateway Node when Swamp or the Gateway cannot run the rollback:

```bash
sudo /usr/local/libexec/orbit-gateway-rollback [<old id>]
```

It holds the release lock and runs the same phases as the native rollback, from old's own commit: start and check old, switch through the journal, verify, start Horizon and retire the rolled-away live as the new old. It needs Incus and Caddy, not Swamp and not the Gateway. It reuses the last release's Operation ID and creates no Operation or release record. It loads no Swamp workflows: when the Gateway answers again, reconcile the serving commit with `orbit gateway:release:deploy <full-sha> --reconcile`. A failure before the switch stops old again. After the switch, an interrupted run resumes with `--recover`.

Before it starts old, a rollback records its intent in the switch journal directory. If it stops before the switch is journaled, `--recover`, and any other release phase, stops old again.

For an interrupted container handover or rollback, run `sudo /usr/local/libexec/orbit-gateway-rollback --recover`. It resumes the durable switch and drain.

On a host deployment **before container preparation**, use the host release script:

```bash
sudo /home/orbit/orbit/bin/gateway-rollback <id>
```

It needs only the release directories. It refuses an id that is not 12 hex digits (exit 64) and a release without `REVISION` (exit 1), and then changes nothing. Otherwise it switches `/home/orbit/orbit` to `releases/<id>` with one rename, as the owner of that link. It switches `web/current` the same way when the release keeps its web build, and leaves the web app as it is otherwise.

It reloads PHP-FPM, so every request runs the release and OPcache starts empty. Then it restarts each running service whose working directory is under the application, such as Horizon, the scheduler and agent-view. A stopped Process stays stopped, Horizon included: after a release run that ended before its Horizon start, run `orbit process:start` for it, or `systemctl start orbit-process-<id>-horizon.service` on the Gateway when Orbit does not answer. The release run switches forward with the same script. A restart of Horizon by the script waits for its running jobs, up to the drain.

The host script preserves the shared database. It loads no Swamp definitions and writes no release record. A host deployment uses the regular deploy command to reconcile.

On a container Gateway, `orbit gateway:release:deploy <full-serving-sha> --reconcile` reloads the selected release’s workflows and verifies its public version. Reconciliation holds the serving generation during publication. If the run was interrupted, confirm on Node 209 that it is terminal and publication has stopped. Read the Operation ID and full SHA from `/var/lib/orbit/gateway-release-switch/reconcile`. Run `sudo /usr/local/libexec/orbit-gateway-release-phase reconcile-end <sha> <operation-id>` to clear that exact lease, then retry.

### Apply an env change

Before editing `/home/orbit/shared/gateway.env`, make a private backup with mode `0600`. Keep both the backup and any temporary replacement out of terminal output, logs, and Git. Every release caches its configuration, so an edit changes nothing until you cache it again. For a container deployment, run `sudo /usr/local/libexec/orbit-gateway-environment configure` on the Node. It publishes the private file to the serving release and reloads its workers. For a host deployment, run the following command as `orbit`.

```bash
php /home/orbit/orbit/apps/gateway/artisan gateway:release:configure
```

It runs `config:cache` locally into a temporary file, sets mode `0600`, then renames it over the current release's `bootstrap/cache/config.php`. A request never reads a partial cache. Do not run `php artisan config:cache` in a release: it rewrites the live cache in place. A deploy or rollback caches its target again, so a retained release picks up the edit when it goes current.

## Automatic releases

The Gateway scheduler runs `gateway:release:auto` every five minutes through `GatewaySchedule::command`. PRs merge straight into main, so the merges of those five minutes go out in one release of the newest green commit. With the [Gateway queue](/reference/gateway-queue#scheduled-work) configured, each tick is a queued job. It reconciles the serving release and looks for the newest eligible main commit. It admits the next Swamp release after the preceding cycle settles. Automatic releases are disabled by default, and they are [off in the live fleet](/reference/old-paths#off-in-the-live-fleet). Turn them on after the Gateway runs from the [release layout](#release-layout) and a first manual release succeeds.

A release admits no refresh of the task VM base snapshots: that step, its daily schedule and its artisan commands are removed ([refresh the base snapshot](/reference/compute-drivers#refresh-the-base-snapshot)).

```bash
orbit gateway:auto-release:enable
orbit gateway:auto-release:status
orbit gateway:auto-release:disable
```

A Gateway that ran the release unit still has `orbit-gateway-release.timer` until its [gateway role convergence](/cli/node/role-add) runs. Until then the timer runs the same tick every minute, more often than the scheduler; the release lock and the admission check make the second tick harmless. Its `ExecStopPost` names the removed `gateway:release:settle`, so the unit reports a failed stop step. The convergence stops and disables the timer, then removes it and the release services. Converge only while the current release runs the tick from the scheduler: a rollback to an older release then has no tick until a newer release goes live.

### What a tick does

Each tick of `gateway:release:auto` takes these steps and stops at the first that ends it:

1. It stops when automatic releases are disabled or paused.
2. It stops when a release run is active or the release lock is busy.
3. It settles dead records and stops if this creates a pause.
4. It reads the current release and its `REVISION`. An invalid layout stops the tick.
5. It reads the repository origin locally with `git --git-dir <shared>/orbit.git config --get remote.origin.url`.
6. It asks GitHub for the newest first-parent main commit that descends from the current release and passes both delivery checks.
7. It requeues any pending fleet, default or seed work and waits for the serving cycle to settle.
8. It respects a manual pin and the retry delay, then admits the [Swamp run](#release-as-a-swamp-run) with trigger `auto`.

The [green checks](/reference/github-app#find-the-newest-green-commit) certify a tested landed tree and a promoted web artifact. The tick records the candidate while it waits for the serving cycle. Admission and preflight failures recheck the serving revision, pause and cycle under the release lock.

The fleet phase fails if the CLI delivery is still unavailable after its publication wait and a completed search finds no compatible ancestor. A newer eligible revision can fix a terminal downstream failure forward.

`ORBIT_GATEWAY_RELEASE_BRANCH` and `ORBIT_GATEWAY_RELEASE_CHECK` name the branch and check, by default `main` and `Required checks`. A retryable failure waits 10 minutes between attempts, up to three attempts. Waiting for agent delivery spends no retry budget.

If the agent delivery is unpublished, the tick returns `waiting_for_agent_delivery` and writes no release record. It does not loop or sleep. The next scheduled tick checks again and selects the newest green commit. This wait counts toward the 30-minute stall alert.

Every tick stores its result and prints one JSON object. It exits 1 for a manual-deploy pause or when an admitted run did not start.

| Result | Meaning |
| --- | --- |
| `disabled`, `paused` | Automatic releases are off or paused |
| `busy` | Another release holds the lock or has an active run; this counts toward a stall |
| `not_adopted`, `no_deployed_release` | The Gateway has no valid current release or readable `REVISION`; repair the release layout |
| `up_to_date` | No newer commit qualifies |
| `waiting_for_release_cycle` | The serving release still has fleet, default or seed work to finish |
| `waiting_for_agent_delivery` | The candidate's agent delivery is not available; the next tick checks again |
| `backing_off` | A retryable failure is less than 10 minutes old |
| `source_unavailable` | GitHub, the App, or the shared repository's origin could not be read |
| `started` | A Swamp run was admitted; its terminal result ends the record |
| `failed` | Admission failed; the retry policy decides whether the commit is tried again |

### Pause

A pause is durable state with a reason. It holds until `resume` or until a deploy ends `verified`, whatever other release records come after it. The start of every pause raises one `release_paused` alert that names the reason.

| Reason | Cause |
| --- | --- |
| `migration_failure` | Migrate or a later step failed, without a successful switch-back; inspect the failed step and its output |
| `rollback` | A rollback went live. The runner does not release a newer commit over it |
| `manual_deploy` | A manual deploy went live while a newer green commit already existed: a deliberate pin of an older commit. The runner does not undo it |
| `interrupted` | A release died after a snapshot, a migration, or a switch |
| `marker` | Only `ORBIT_HOME/gateway-release.paused` exists, as an older Gateway wrote it |

A manual deploy or rollback still runs during a pause. A manual or automatic release that ends `verified` ends a pause, so a forward fix hands back to automation. A rollback that goes live starts a `rollback` pause instead.

When a manual deploy goes live, the Gateway asks GitHub whether a newer green commit exists. It stores the answer on the record as the `newest_green` step: `superseded` with that commit, `newest`, or `unknown` when GitHub did not answer. `superseded` and `unknown` make the next tick pause with `manual_deploy`, so a pin is never undone on a guess. After a GitHub outage, a manual deploy may need one `resume`. A manual deploy of the newest green commit hands back to automation, and a newer commit that turns green later is released as usual.

After a failure, inspect the migration output. Apply a forward fix or [roll back the application](#roll-back) if it can serve the current schema. A host PHP-FPM release also records a pre-migration snapshot. Then clear a pause that is left:

```bash
orbit gateway:auto-release:resume
```

Resume removes the pause and the marker. The next tick releases the newest green commit, also over a manual release from before the resume. Resume refuses with `gateway.release_not_paused` when nothing is paused. A commit that failed is not released again automatically.

### Failure and alerts

A release record that ends `paused`, or that ends `switched_back`, `failed`, or `interrupted` with no retry left, raises one [release alert](#release-alerts). An `interrupted` record that touched live state alerts at once, because it pauses. A pause that a rollback or a manual deploy starts alerts too. So does a release that goes live but leaves document cleanup paused. The receipt is stored on the record, so the record never alerts twice. A retryable failure does not alert by itself.

Two stalls alert once with the kind `release_stalled`. The subject is the deployed commit.

- A GitHub error, a retryable failure, or a busy release lock lasted 30 minutes. The next tick that reaches GitHub ends the stall.
- The branch head stayed unreleased for 6 hours. This check also runs during a pause. A deployed head ends it.

While it is up to date, the runner reads the branch head at most every 5 minutes. The second stall covers a deployed commit that left the branch after a force push, because then no newer commit can qualify.

### Read the state

`orbit gateway:auto-release:status` shows the switch, a pause with its release, error code, and any snapshot path, the current release, the last tick, the stalls, and the last run result. `orbit gateway:status` shows the current release and a short summary. A last check older than two minutes needs investigation. Check the Gateway scheduler, its logs and, when configured, the queue workers.

A [Conn launch](/reference/conn#durable-launches-v1) resumes from its recorded Operation. An ambiguous T3 reply is recovered by reading the exact message on the original server. While a launch is Cancelling, owned native work must settle before Orbit can verify teardown.

## Recover a failed update

Keep requests and automation paused. Save redacted failure output and preserve the failed state separately for diagnosis. Do not use a generic migration rollback to guess compatibility with older code.

If a command failed before any state or machine change, restore the previous source commit and its locked dependencies, then verify status and HTTPS before reopening access. Once migrations or machine changes ran, recover the complete pre-update state set. The safest recovery for this walkthrough is the matching stopped-machine snapshot. Do not connect a restored clone to the original fleet at the same time: it contains the same WireGuard, SSH, and CA identities.

For an archive-based restore, stop all Gateway writers and use a root console. Move the failed `ORBIT_HOME` aside rather than extracting over it, restore the archive's original ownership and permissions, restore the matching source commit, and reinstall its locked CLI and Gateway dependencies. Restore any external database and service configuration from the same recovery point. Clear stale Laravel configuration, then start services and repeat status, Node, Doctor, DNS, and HTTPS checks before reopening access.

Do not migrate the restored database forward while attempting to run the older code. If the effective encryption key, CA private key, database, or required machine state is missing, stop: an apparently healthy process cannot prove recovery. This procedure does not roll back changes already made to other Nodes or restore application data; recover those from their own backups and review their consistency with the Gateway.

## Release alerts

A release command raises an alert when a release fails, when it pauses automatic releases, or when a Node of a fleet rollout fails. The [fleet reboot](#fleet-reboot) raises its own three alerts.

The [automatic deployment tick](/reference/deployments#automatic-deployments) also raises one when an automatic deployment of a production Instance fails, stalls, or pauses. Its target is `instance:<id>`.

The alert leaves an Activity entry and a problem for the [outer loop](/reference/tasks#outer-loop), and it posts a signed webhook when one is configured. Each part is recorded on its own. A failed part never stops the others, and the alert never fails the release command that raised it. A release command raises the alert outside a database transaction, so a rollback cannot discard the records after the webhook went out.

| Kind | When |
| --- | --- |
| `release_failed` | A release failed. Nothing went live, or the previous release serves again |
| `release_paused` | A release failed after its migrations ran, or died after it touched the schema or the current link, and automatic releases are paused |
| `rollout_halted` | A fleet rollout stopped at a Node that failed. Once per Node, commit and error code |
| `reboot_halted` | A [fleet reboot](#fleet-reboot) pass stopped at a Node that failed. Once per Node, boot and error code |
| `reboot_unconfirmed` | Node 209 scheduled its own reboot and its agent reported no new boot within 30 minutes of the pass's end. Once per pass |
| `reboot_overdue` | A Node has needed a reboot for more than 3 days when a pass ends. Once per Node and due time |
| `release_stalled` | [Automatic releases](#failure-and-alerts) made no progress for 30 minutes, including a wait for agent delivery, or the branch head stayed unreleased for 6 hours |
| `release_cleanup_paused` | A release went live, but [document cleanup](/reference/project-documents#restore-time-cleanup-gate) stayed paused after the release switch |
| `instance_deploy_failed` | An [automatic deployment](/reference/deployments#automatic-deployments) of a production Instance failed. Once per commit |
| `instance_deploy_stalled` | A commit that the automatic deployment tick would deploy to a production Instance, or a head whose check has not finished, waited 30 minutes. Once per commit |
| `instance_deploy_paused` | A manual deployment or a rollback paused automatic deployment of a production Instance |

Every alert names its subject, a summary, and an optional evidence link. The summary passes through the Gateway log redactor and is cut at 1,000 characters. A field outside these limits is a bug in the calling command, which refuses it before it records anything.

| Field | Content |
| --- | --- |
| `target` | What was released: `gateway`, `fleet`, or another lowercase name of at most 64 characters, such as `instance:12` |
| `repository` | The GitHub repository as `owner/name` |
| `sha` | The full commit SHA: 40 or 64 lowercase hexadecimal characters |
| `release_id` | The release record's id, or null. At most 64 characters |
| `evidence_url` | An `http` or `https` link to the evidence, such as a CI run, or null. At most 2,048 characters |

### Activity entry

Each alert writes one Activity entry with the command `release:alert`. `orbit activity:list --command=release:alert` lists them. The entry starts as `running` and keeps a request ID that the webhook body repeats. Its `properties` hold the kind, the subject, the summary, the evidence link, and the outcome of the problem and the webhook parts. An outcome is `done`, `skipped`, or `failed`, with a reason.

| Status | When | Error code |
| --- | --- | --- |
| `succeeded` | No part failed. A skipped part does not fail the entry | none |
| `failed` | Recording the problem failed | `release.alert_problem_failed` |
| `failed` | The problem part did not fail, but the webhook failed | `release.alert_webhook_failed` |

A failed entry is a server-class Activity row. When the webhook keeps failing, the outer loop counts those rows and files that as its own problem.

### Problem

The alert records `release|{kind}|{resource}|{attempt}|{fault}`, with the source `release`. The failing resource, its attempt, and its fault identify the incident. Source SHAs stay in the evidence and never split an incident. Unlike a recurring signal, it is ready on its first occurrence. The next hourly `problems:file` run files it as a Backlog task in the `orbit` Project, ahead of every other ready fingerprint. [Outer loop](/reference/tasks#outer-loop) describes the sample, the brief, the daily cap, and the mute.

| Outcome | Reason | When |
| --- | --- | --- |
| `done` | none | The fingerprint was recorded |
| `skipped` | `tasks_disabled` | The Tasks extension is disabled, so the outer loop does not run |
| `skipped` | `suppressed` | The fingerprint is on the [suppression list](/reference/tasks#suppression) |
| `failed` | `error` | The database write failed. The Gateway log has the exception |

An open incident keeps one linked group. Further observations update its generated brief while the group and its subtasks remain untouched. Operator edits or a move out of Backlog preserve the group. Confirmed recovery or supersession cancels an untouched generated group. A successful stage is held and does not confirm recovery. Filing clears an occurrence episode; that reset is not recovery evidence.

The recipe image build raised alerts that use `recipe-image:<build id>` as the resource, the build attempt as the attempt, and `build_failed` as the fault. Nothing raises one since the build was removed. Recovery resolves an alert raised before as `resource_gone`, because no build runs to recover it. It reads no build row and no recipe identity.

A migration merges the old buckets based on SHA only when retained alert evidence proves their resource, attempt, and fault. It adopts only groups whose original generated content and lack of operator activity can be confirmed. Ambiguous history stays available for an operator and cannot file a new group automatically.

### Webhook

Set both values in the Gateway's own `.env` to receive alerts:

| Variable | Content |
| --- | --- |
| `ORBIT_RELEASE_ALERT_WEBHOOK_URL` | The receiver's URL |
| `ORBIT_RELEASE_ALERT_WEBHOOK_SECRET` | The HMAC secret |

When either value is unset or empty, the webhook part is `skipped` with the reason `not_configured`, and the Activity entry and the problem are still recorded. The Gateway never returns the secret. It never stores the URL, the secret, or the response body, because a chat webhook URL is itself a credential. Unset both values on a disposable Gateway clone, so its alerts do not reach the operator.

The Gateway posts one JSON object. It signs `{unix timestamp}.{raw body}` with HMAC-SHA256 and sends `X-Orbit-Timestamp`, `X-Orbit-Signature: sha256={hex}`, and `Content-Type: application/json`. The connection timeout is 3 seconds and the whole request has 10 seconds. The Gateway follows no redirect and does not retry.

| Body field | Content |
| --- | --- |
| `event` | `release.alert` |
| `kind` | The kind above |
| `subject` | `target`, `repository`, `sha`, and `release_id` |
| `summary` | The redacted summary |
| `evidence_url` | The evidence link, or null |
| `text` | One line for a chat channel, such as `Release failed for gateway hardimpactdev/orbit@0123456789ab: Verify found an old version.`, followed by the evidence link. It escapes `&`, `<`, and `>` as Slack expects |
| `occurred_at` | When the alert was raised, in ISO 8601 UTC |
| `request_id` | The Activity entry's request ID |
| `activity_id` | The Activity entry's id, or null when it could not be written |
| `problem_fingerprint` | The recorded fingerprint, or null |

| Outcome | Reason | When |
| --- | --- | --- |
| `done` | none | The receiver answered with a 2xx status |
| `skipped` | `not_configured` | The URL or the secret is unset |
| `failed` | `rejected` | The receiver answered with another status. The outcome keeps `http_status` |
| `failed` | `unreachable` | The connection failed or timed out |
| `failed` | `error` | Any other failure |

The body serves two kinds of receivers. A receiver that verifies the signature reads the typed fields, such as an agent's intake webhook. A Slack incoming webhook shows `text` and ignores the signature, because its URL is the credential. The Gateway still needs a secret to send, so set any random value for such a receiver.

## Fleet rollout

A release is **fleet current** when the `phases.fleet.status` field in `gateway:release:show` is `current`. The release first reports `verified` when the Gateway switch passes its health check. The fleet phase can then be `pending`, `failed`, or `disabled`. It lists each Node's status, delivery and converge Operation IDs, and role results. A fleet failure leaves the verified Gateway serving.

The fleet admission job runs as soon as the Gateway records a release `verified`, at any hour. The fleet rollout has no time window: every Node, and then the default refresh, starts right after the release.

The job also runs daily at 03:05 Europe/Amsterdam when either fleet rollout or default refresh is enabled. Pending default and seed work keeps that job active even while automatic Gateway admission is paused. Each daily pass checks the serving release again, including when it already reports fleet current. It discovers membership changes, checks agent pins and reported versions, picks up late CLI publications, and compares rendered role inputs with the applied records. It visits eligible Nodes in order, with the serving Gateway last. A missing role record needs a first apply.

Agent delivery uses `fleet.rollout` and checks the executable of the running service. Any required CLI publication happens through the same Operation. Orbit resolves any required CLI release before reserving the fleet visit and uses that pin for admission. Under the database writer lock, each pass confirms that its release still serves before it records or admits fleet work.

A pass starts when the release is verified, or when the daily pass finds the phase settled, and records `started_at`. It visits each Node once, in order. Once delivery succeeds, `node.converge` applies only the roles with changed inputs. When that converge succeeds, the Node turns `current` and the next Node starts. A `current` Node stays `current` for the rest of the pass, even when Instance work changes its role inputs again. The next pass checks it again.

A converge that succeeded after the pass started counts too, including one an operator ran by hand, for each role assignment it captured. A converge from before the pass, or of an assignment that was since recreated, does not count. Instance work can change a role's inputs while the converge waits, so the pass does not wait for the recorded digest to match.

A failed role converge or a Node refused for its host inputs is marked `failed`, and the pass goes on to the next Node. The pass never retries that Node. The phase ends `failed`, and the next pass retries the Node. Only a failed delivery stops the pass. The Operation results show the checks. An unconfirmed delivery or converge reports `fleet.agent_delivery_failed` or `fleet.role_converge_failed` when the Operation supplied no more specific code. A verified release never creates another scheduler.

The automatic converge can write the selected roles' software, files and services. For example, `app-dev` owns its shared Instance folders and the executor for its action families, and `swamp` owns its worker template and the Deno helper pin. A role needs convergence when its rendered inputs, shipped playbooks or their dependencies change. For an Instance host, this includes the shared folders, images, ID ranges and T3 drop-ins. For a CI runner, it includes the proof seed listener and the Node whose Incus certificate the runner trusts.

Public render inputs determine whether a release needs a role converge. Private material is resolved at admission. Per-Operation values such as the Instance bridge admission timestamp do not affect the release digest.

Automatic role convergence and conflicting Instance, Conn and Process work exclude one another at admission on a shared Node. Task work, such as a copy turn, a check, a publish, a sync or a cancel, runs inside its copy and neither waits for nor holds a role converge, as a hand `node:converge` runs beside it.

When open Instance work refuses the automatic converge, the converge queues: new Instance, Conn and Process work on that Node gets `operation.target_busy` (HTTP 409, `An automatic role converge waits for this Node.`) until the converge is admitted, so it starts as soon as the current holders end. The Node's row lists those holders in `waiting_for`.

A removal, `instance.destroy` or `instance.unregister`, frees the Node and is never refused by the wait, nor by a running automatic converge: it is accepted at once and runs beside the converge. Its Route withdrawal takes the Node's Caddy publication lock and checks the live file before it renames, so the two never overwrite each other's Caddy writes. A container that the removal deletes while the converge walks the Node's containers is skipped as already removed ([existing containers](/reference/development-containers#existing-containers)).

This includes a task cancel's destroy of its copy and the removal reaper's retry of a failed destroy. A holder's continuation goes on besides: an Operation whose parent, or an Operation its input names (`*_operation_id`), leads back to the holder, such as the `instance.complete` that follows an `instance.create`. New work on a holder's target waits like any other new work.

The wait is bounded. A converge that still waits five minutes after its first refusal is admitted beside its holders, and the wait ends. It records them as `admitted_beside`, so each holder can still finish while it runs: the holder's continuation goes on, step by step, such as the `instance.complete` of an `instance.create` and a step that names that completion. Other new work, except a removal, is refused while the converge runs. The wait also ends when the release pass stops renewing it for a minute.

An Instance transfer holds both its source and its destination, together with its other recorded participants. A converge receipt lists the T3 restarts deferred for open turns or activity it could not read. The Node stays pending and retries.

Before a verified release starts fleet work, Orbit preserves the previous host baseline for every eligible Node that has no baseline. It keeps that baseline through a deferral or superseding release.

Host inputs include the common and base-only recipe pins, kernel policy, and the WireGuard network tasks and their included tasks. The Gateway runtime, `gateway-runtime.yml` and `GatewayRuntimeFiles`, is a host input only of a Node with the `gateway` role, because only that Node applies it. A change to a Node's host inputs fails that Node with `fleet.host_inputs_changed` before Orbit starts work on it. The other Nodes go on. The release cannot become fleet current or start its nested-fleet refresh until that Node is converged.

A per-Node host digest starts with `node:`. A bare digest is a baseline recorded before this classification, and it approved one release tree. The pass reads the host inputs of the recently verified releases by their commit from the Git objects of the Gateway checkout, so it needs no release checkout and works in the container release layout too. When the Node's own host inputs are the same in the approved tree and in this release, the pass records the Node's own digest and goes on; otherwise it holds the Node.

A Node with the `gateway` role has the same host inputs the bare digest covered, so the pass compares the two digests directly and holds the Node when they differ. For any other Node, when a release tree cannot be read and no readable tree matches the baseline, nothing can prove a change. The pass then records the Node's current host inputs, goes on, and marks the Node's row `host_baseline: legacy_baseline_unreadable`. The Gateway log names the Node. This upgrade goes after one release.

An approval recorded before the network task dependencies were included needs renewal because it cannot prove which child tasks the Node ran. Apply a reviewed host change or renew its approval with a successful `orbit node:converge <node>` without a base or role filter. The pass retries on the next daily pass or follows a newer verified release. Unreadable desired inputs fail with `fleet.inputs_unavailable`. An invalid assignment scope fails with `fleet.role_scope_invalid`.

`ORBIT_FLEET_ROLLOUT` defaults to `true`. Set it to `false` to pause fleet rollouts. The Gateway reports `disabled` and leaves the release without fleet-current status. `ORBIT_FLEET_ROLLOUT_ORDER` takes a comma-separated list of workload Node names to visit first. The order never adds or removes a Node.

An operator can also request an agent and CLI delivery pass with `orbit fleet:rollout` (`POST /api/v1/fleet/rollout`). Each pass is one `fleet.rollout` Operation. Use [`operation:list --kind=fleet.rollout`](/cli/operation/list) and [`operation:show`](/cli/operation/show) for delivery results. The manual pass follows the rest of this section.

### Rollout set and order

The rollout visits a Node when all of these hold:

- it is not a disposable task sandbox;
- it is `active` and runs Linux;
- the Gateway manages it over SSH: it has a WireGuard address and a pinned SSH host key;
- it holds at least one active role other than `gateway`;
- it is not the Gateway's own machine: it holds no `gateway` role and is not the serving host;

Roleless Nodes, such as operator machines, and macOS Nodes stay out. Their operators run `orbit self-update`. The serving Gateway joins the verified release pass for its agent and its workload roles. The Gateway role and the Gateway’s checkout CLI have their own release and maintenance paths. A [CLI convergence](/cli/fleet#how-it-works) names why it leaves a Node out: `inactive`, `platform`, `unmanaged`, `gateway`, `roleless`, or `cli_not_required`.

No Node is a task sandbox any more, so the rollout has no `sandbox` reason and reads no task VM row ([old paths](/reference/old-paths#the-task-vm-readers)).

The order goes lowest risk first. Each role has a group. A Node with several roles goes in the latest group of its roles, so it waits until every lower-risk Node is done. Inside a group, the Node ID decides.

| Group | Roles |
| --- | --- |
| 1 | `app-dev` |
| 2 | `ci-runner`, `metrics`, `analytics` |
| 3 | `database`, `websocket`, `vpn`, `router` |
| 4 | `app-prod`, `ingress` |

Orbit has no `agent` or `s3` role yet. The order already places `agent` in group 1 and `s3` in group 2.

`fleet.rollout` starts `operation-fleet-rollout`, one native Swamp DAG for the admitted Nodes. Each Node has a placed publication step. Native concurrency is one; each step requires successful prior Node outputs before acting. There are no child Operations, polled claims, target holds or execution receipts. `fleet.cli_converge` uses the same publication behavior in its own native DAG and updates only the CLI.

Control steps run on Node 209. No step asks orbit-agent to act.

Every Node starts at any hour, one at a time, as soon as the Node before it succeeded. Nick decided on 2026-11-18 that the fleet always rolls out right after each release, so no time window holds a Node back. A busy binary-publication lock defers that Node to a later pass.

### Desired state

Admission resolves the desired state of the commit the Gateway serves: the CLI release `cli-v0.N.0` with the SHA-256 of each binary, and the agent pin with the SHA-256 of each binary. It stores the state in the Operation's input.

A Gateway in the release layout rolls out only a commit whose release record is `verified`. A pass during a release, before the record exists, does nothing (`release_unverified`). When the configured layout cannot be read, or its link names no complete release, the pass rolls out nothing (`release_layout_unreadable`). An in-place Gateway, whose current path is a checkout, has no release records, and its running commit is the desired state. A Gateway version that is not a commit, such as `dev`, rolls out nothing.

The Gateway can release before CI publishes that commit's CLI: admission verifies the commit's pinned agent delivery. Only Nodes with CLI intent wait until the CLI release exists. Other Nodes remain eligible for observer rollout.

When the commit's release is still missing after 30 minutes, the desired state [falls back](/reference/self-update#fallback-to-an-ancestor-release) to the newest published release of an ancestor commit with the same CLI code. When the commit's own release appears later, the next pass revisits a Node that requires the CLI if its recorded version differs from the desired one.

### One Node

Each Node runs the `fleet-release-node` child, and every step runs on Node 209 over SSH with the Node's stored host keys. Its `gate` step checks that every earlier Node succeeded and that the task is not cancelled. For a Node with CLI intent, the `cli` step runs the native [`node-cli-install`](/reference/node-provisioning#orbit-cli) child. Other Nodes skip it and publish their observer without a CLI gate. A newer CLI release or a commit build stays in place.

A rollout then publishes the observer with official resources. `@swamp/cfgmgmt/fetch` writes the pinned agent to `/usr/local/bin/orbit-agent` only when its SHA-256 differs. One SSH read prints the running service's binary digest. The agent restarts only when the file changed or the running digest differs from the pin. The same read then checks that the pinned digest runs on an active unit. A failed fetch records `agent.download_failed`, a failed restart `agent.restart_failed`, and a failed check `agent.observer_unhealthy`. The rollout never reads the agent secret or changes observer configuration.

The delivery Operation applies the CLI, its public connection profile and the observer binary. For a verified release, the fleet pass then admits the scoped role converge when it is needed.

For that repair, use [`orbit node:converge NODE`](/cli/node/converge). It admits one active, managed Linux Node against recorded state, reserves that Node and its recorded VPN hub, and runs the [seven scoped phases](/reference/node-provisioning#converge-the-orbit-footprint). Unlike automatic rollout membership, standalone admission allows a roleless Node or the Gateway Node. The default includes the base and all recorded roles; `--base` limits repair to Orbit's own layer, and `--roles=a,b` limits it to the base and those existing assignments. It changes no Instance or role assignment.

A standalone repeat is another full attempt, even when the recorded intent matches. With no drift it must report zero changed tasks on the target and finish within 120 seconds; hub changes do not count as target changes. A phase failure keeps completed and partial changes, with no rollback. Fix the reported cause and start a new attempt from input, rather than expecting a resume at the failed phase. A Tool's own failure is recorded separately and does not alone fail the parent Operation. This is not a fleet rollout pass and does not change rollout order or its stop-on-failure rule.

A lost or unknown command result leaves the Operation unresolved; it is not reported as success.

### A failed Node

At the first failed Node, native dependencies leave later Nodes untouched. The run fails with that Node's error code, and the Operation's result names the Node. The Gateway raises [`rollout_halted`](#release-alerts) for that Node.

Fix the cause. The next pass, at least 5 minutes after the failed run, starts again at that Node, because it is the first Node in risk order that the commit has not converged. That pass comes with the next verified release, the daily pass, or `orbit fleet:rollout`. Until it converges, later Nodes wait. A deferred Node does not hold back later Nodes, just as it does not inside a run. A retry raises no new alert for the same Node, commit and error code, and the alert's problem resolves once a later pass converges the Node.

Native `swamp workflow resume` does not replace the retry. A failed rollout Operation is final at the Gateway, and the pinned Swamp release drops the inline SSH arguments of a resumed run. A new pass is the retry.

### Which Nodes a pass visits

The Gateway's plan reads the newest result of each Node in the terminal `fleet.rollout` Operations of the desired commit. It visits a Node of the rollout set when one of these holds:

| Node | Why the pass visits it |
| --- | --- |
| No result for this commit | It was not visited yet, or it joined the rollout set since |
| `deferred`, `failed`, `cancelled` or `skipped` | Its last visit did not converge it |
| `converged` or `unchanged`, but drifted | Its observer reports another version, or its required CLI is missing or differs from the desired release |

A Node waits at least 5 minutes after its last visit ended. While a failed or cancelled Node waits, the plan names no Node after it. So a Node that was offline is converged by the first pass after it returns.

Doctor reports a lagging Node of the rollout set as `node.release_lag` while the rollout is on. A Node outside the set, such as a task sandbox, never gets it. `observed` says why: `no visit yet`, the Node's outcome, or `drifted`. Doctor reads only the desired state that a pass already resolved; it never asks Git or GitHub.

### Nodes being updated

Each Node in `GET /api/v1/nodes` and `GET /api/v1/nodes/{node}` has an `updating` field. It is null unless a [release record](/cli/gateway/release-show) is `running`; then the Node with the active `gateway` role has `kind` `gateway_release`, `since` the record's `created_at`, and `release`, the record id. A `queued` release record does not count, because nothing changed yet.

The Gateway reads a running release's result from its Swamp run. An interrupted run pauses releases, as [Release as a Swamp run](#release-as-a-swamp-run) describes. The Gateway broadcasts [`node.updated`](/reference/events#node) with the new value when a release record starts running and ends. The [web app](/reference/web-app#live-node-and-process-state) shows the Node as `updating`.

A fleet rollout visit does not set `updating`. Its progress is in the Operation and its Swamp run.

## Fleet reboot

The nightly admission is [off in the live fleet](/reference/old-paths#off-in-the-live-fleet): the live Gateway sets `ORBIT_FLEET_REBOOT=false`.

Every night, Swamp reboots the managed Linux Nodes that need it into their kernel and library fixes, one at a time, after it drained their work ([ADR 0253](/decisions/0253-patch-nodes-and-reboot-them-drained)). The Nodes install the fixes themselves through their [update policy](/reference/node-provisioning#update-policy); the pass only reboots. Each pass is one `fleet.reboot` Operation and its Swamp run. Read them with [`operation:list --kind=fleet.reboot`](/cli/operation/list) and [`operation:show`](/cli/operation/show).

<div className="flow-at" data-chart="fleet/reboot"></div>

### Admission

The Gateway admits a pass every day at 03:06 Europe/Amsterdam, after the fleet rollout admission. An operator starts one at any hour with `POST /api/v1/fleet/reboot`, optionally with `node_ids`. The operator's pass ignores the window.

| Variable | Default | Effect |
| --- | --- | --- |
| `ORBIT_FLEET_REBOOT` | `true` | Admit passes |

A pass, a fleet rollout and a Gateway release never overlap:

- While a rollout or a release runs, a pass refuses with `fleet.reboot_waiting` (409). The nightly admission tries again every minute for two hours.
- While a pass runs, a rollout refuses with `fleet.rollout_busy` and a release with `gateway.release_in_progress`. Both wait for the pass as they wait for one of their own.
- A second pass refuses with `fleet.reboot_busy`.

### Which Nodes and in which order

A pass visits every `active` Linux Node with a WireGuard address and a pinned SSH host key, except task VMs: Nodes without roles, the Gateway and Node 209 included. macOS Nodes stay out.

The order is the rollout's [risk groups](#rollout-set-and-order), then two more. A Node with several roles goes in the latest group of its roles.

| Group | Nodes |
| --- | --- |
| 1 | `app-dev`, and Nodes without roles |
| 2 | `ci-runner`, `metrics`, `analytics` |
| 3 | `database`, `websocket`, `router` |
| 4 | `app-prod`, `ingress` |
| 5 | The control path: the `vpn` hub, the Gateway Node, and the Node of the Route that serves the Swamp address (`swamp.orbit`) |
| 6 | The `swamp` Node |

Set a durable per-Node reboot hold with `orbit node:update NODE --reboot-hold=true`; clear it with `--reboot-hold=false`. The default is false. The existing Node update API and MCP accept the boolean `reboot_hold` field. Every Node read shows it. Admission freezes the hold as the existing `defer: operator_hold` intent value. Changes apply to the next pass. Both nightly passes and operator passes with `node_ids` respect the hold. No new intent key is required, so the Gateway and workflow ship in one release: migrate the database, load Swamp, then activate the Gateway. The `drain_started` marker is run-local state, and rescue skips undo when it is absent.

No Node is a task host any more. Admission reads no stored `task_vms` Node setting, so the intent of a Node has no `task_project`, the pass has no `task_hosts`, and no Node is deferred with `tasks_busy` or drains task guests ([old paths](/reference/old-paths#task-vm-settings)).

### One Node of the pass

The child `fleet-reboot-node` runs three Ansible phases of `swamp/ansible/operation-fleet-reboot.yml` from Node 209. Each phase reads and extends the Node's record in the pass's run folder. Once the record has an outcome, the later phases change nothing. The drain writes an initial record on the controller before its first SSH attempt, so collection can retain evidence of the visit if the run reaches its report step. The run folder is not durable Gateway evidence.

Each phase keeps its Ansible JSON callback report private. Before the child ends, `Record the Node` reads those reports on the controller, including after an unreachable target bypasses Ansible rescue. When collection runs, it records a started phase without a terminal report or Node outcome as `failed` with `fleet.reboot_cut`.

If the root run ends before reporting any Node records, the Gateway cannot tell which Nodes were attempted: every Node records `unknown` with `run_interrupted`. Missing records without an earlier recorded halt also stay unknown. Unknown outcomes make the pass fail and raise `reboot_halted` once. Without a recorded Node failure, its error code is `fleet.reboot_interrupted`. Only missing Nodes after a recorded halt use `skipped` with `not_reached`.

An `unknown` Node reports uncertainty; it does not recover a drain the pass may have started. CI runners stopped before an interrupted reboot can stay stopped. A swamp Node that scheduled its own reboot gets no 30-minute boot confirmation, because that check reads only `scheduled`. After an interrupted pass, an operator reconciles each `unknown` Node by hand: start stopped runners and check the swamp Node's boot.

Only the initial boot-state reads before any Node change retry SSH connection failures (exit 255). They get seven retries after the first attempt, with a 10-second connect timeout and one OpenSSH connection attempt per try. Ansible supplies the pauses: 0, 1, 3, 7, 15, 30 and 30 seconds. Eight timed-out attempts take at most 166 seconds per SSH invocation before process overhead. This covers a two-minute uplink outage, stays within the existing 2,400-second drain phase and leaves room for the 900-second drain.

The phase timeouts remain the hard bounds. The window check runs after the read, so connection retries cannot make a Node start draining after the window's cutoff.

The drain, runner stops, recovery and reboot use zero SSH retries. A disconnect after a command started can therefore fail the phase, but cannot replay that command through the SSH retry plugin.

The `drain` phase decides first whether the Node goes on:

- After an earlier Node of the pass failed, the Node records `skipped` with `halted`. The phase reads that from the run folder.
- Without `/var/run/reboot-required`, the Node records `not_due`.
- A due Node records its boot ID, its kernel, its failed units and its due time.
- A due held Node records `deferred` with `operator_hold` and ends before any drain or reboot. A held Node without a due reboot stays `not_due`. Every Node result includes the admitted `reboot_hold`, including in `operation:show`.
- A Node whose drain and reboot cannot end 45 minutes before 06:00 records `deferred` with `window`.

Then the phase drains the Node:

On a CI runner host, the phase drains the runners every 20 seconds, for at most the drain budget. It freezes each runner container and reads the container's processes from the host. It stops a container that runs no `Runner.Worker` and records the runner with the time of its freeze. A runner with a job runs on, and so does a job on a runner of the host itself. When a job still runs at the end, the stopped runners start again, and the Node records `deferred` with `ci_busy`.

After the hold and window checks, the phase writes `drain_started` to the Node's run-folder record before the first CI or Gateway stop. Rescue runs undo only when this marker exists. If writing it fails, nothing has stopped yet and undo is skipped. Once a drain begins, a failure that the Node's own rescue catches keeps the existing recovery: start the Gateway scheduler and Horizon, and start every runner from the initial running inventory that is now stopped. Recovery does not depend on later record writes or on reading the stopped-runner list.

On the Gateway Node, the phase stops the scheduler. Then it stops Horizon, which drains on stop, within the drain budget. The reboot ends what is left.

The `reboot` phase reboots the Node and waits up to 10 minutes for SSH and a new boot time. The `swamp` Node instead schedules its own reboot and records `scheduled`; see below.

The `check` phase waits up to 5 minutes for the boot to finish, then reads the Node and requires:

- a new boot ID, the newest installed kernel, and no `/var/run/reboot-required`;
- no failed unit that was not failed before the reboot;
- `orbit-agent` active within a minute, and so are the Gateway's scheduler and Horizon on the Gateway Node, and the tunnel and [`orbit-hub-source.service`](/reference/wireguard-endpoints#the-hub-answers-from-its-endpoint-address) on the WireGuard hub.

A reboot of the hub also starts `systemd-networkd` again, so the networkd drop-in of the hub's source unit takes effect.

Then the Node records `rebooted` with its new kernel.

A phase that fails records `failed` with its step and error code: the assert's own code, such as `fleet.reboot_old_kernel`, `fleet.reboot_units_failed` or `fleet.reboot_still_due`, or `fleet.reboot_drain_failed`, `fleet.reboot_lost` (no SSH after the reboot) or `fleet.reboot_check_failed`. The pass visits no Node after it, and the Gateway raises [`reboot_halted`](#release-alerts).

An unreachable Node records `failed` with `fleet.node_unreachable`, its phase and `error_detail`: the SSH diagnostic, redacted by the shared command-output filter and bounded to 4 KiB before recording or printing. This also applies to a held Node: the hold prevents draining and rebooting, but a failed read of its due state still halts the pass. Later Nodes record `skipped` with `halted` without SSH. A Node that never answers SSH needs its provider console. The next pass starts again at that Node, as it is the first due Node.

This change adds no intent key: load the workflow and its result reader together, then activate the Gateway projection in the same release. Runs admitted by the preceding Gateway can use the new workflow.

### CI jobs lost at the freeze

A runner can accept a job in the instant before the drain freezes it. That job is lost when the runner stops. After the `check` phase confirms the reboot of a CI host, the child runs two more steps. Neither fails the Node.

The `mint` step asks the Gateway for the pass's GitHub tokens. Each repository of a Project read through the [GitHub App](/reference/github-app) gets one `actions: write` token, when the App is installed on it. Admission froze that list. The App already holds `Actions: write`; runner administration stays out of it.

The `rerun` phase then looks for lost jobs:

- It lists the runs of each repository created from two hours before the first freeze, one page of 100.
- It keeps the runs that changed after the first freeze and failed or have not ended. It never keeps a cancelled run.
- It gives each kept run up to 4 minutes to end. It records a run that is still running in `rerun_pending`.
- It reads the jobs of each failed run's attempt and keeps the lost jobs.

A lost job ran on a stopped runner and failed. It started at most a minute after that runner's freeze and was still running at the freeze.

For each run with a lost job, the phase reads the run again. It calls `POST /repos/{owner}/{repo}/actions/runs/{id}/rerun-failed-jobs` only while the run is still completed at the attempt that lost the job. That call also repeats any other failed job of the attempt. At the end, in every case, the phase revokes the pass's tokens.

So a run is re-run at most once for one attempt: a second Node of the pass, a later pass, or a person who re-ran it finds the newer attempt and leaves it alone. The Node's result lists the re-run runs in `reran`, each with its repository, run, attempt and lost jobs, and the runs GitHub refused in `rerun_refused`. `rerun_truncated` is true when a list filled its page of 100. Any other error records `rerun_error` and leaves the Node `rebooted`.

### The swamp Node

The pass runs on Node 209, so it cannot wait across 209's own reboot. Its `reboot` phase clears a planned reboot left by an earlier pass, starts a transient path unit, `orbit-planned-reboot`, and returns. The pass's last step, after the report, removes the run folder and releases the unit. The unit then:

1. stops Swamp serve, which finishes its runs for up to 5 minutes;
2. stops the workers, which finish their active steps within the drain budget;
3. reboots. The [shutdown guard](/reference/node-provisioning#shutdown-never-waits) ends what is left at once.

While serve is down, the Gateway's starts of new runs fail as they do for any serve outage. Every five minutes, the Gateway reads the agent's `metrics` fact of each Node a pass `scheduled`: a boot after the pass ended, its observation time minus its uptime, confirms the reboot. Thirty minutes after the pass ended without one, the Gateway raises `reboot_unconfirmed`.

### A cut Operation

A reboot of the control path or of Node 209 can cut a Swamp run after the drain budget. The run ends `interrupted`, and the [reconciler](/reference/operations#read-an-operation) records its Operation `interrupted` with the failed step, as for any lost run. The `fleet.reboot` projection instead records a failed pass when Node evidence is [unknown](#one-node-of-the-pass). A terminal Operation never reopens: its caller starts a new one, such as the next rollout pass, the next automatic deploy, or the task orchestrator's repeat. Every machine phase is an idempotent playbook, so the new Operation repeats the cut phase safely.

### What is reported

The Operation result lists each Node in pass order with its outcome: `rebooted`, `scheduled`, `not_due`, `deferred` with `operator_hold`, `window` or `ci_busy`, `skipped` with `halted` or `not_reached`, or `failed`. A missing Node record without a recorded halt is `unknown` with `run_interrupted`; when the root reports no Nodes, every Node has that outcome. It also holds the kernel before and after, since when the reboot was due, any newly failed units, the stopped CI runners with their freeze times, and the CI runs it re-ran.

Doctor reports `node.reboot_due` when the newest pass that read a Node found it due more than 3 days ago and did not reboot it. A record without a due time, such as `halted`, does not count; Doctor looks at the pass before it. A reboot hold does not suppress this warning or alert: the owner still needs to see overdue fixes. When a pass ends with such a Node, the Gateway raises [`reboot_overdue`](#release-alerts) once.

## Limits

Automatic releases and the fleet rollout have these limits.

### Release limits

A release needs an existing [release layout](#release-layout), the verified agent delivery, a valid CI web build and access to Swamp. There is no in-place update or layout-adoption command.

Migrations must use expand and contract. A failed health check switches back; another failure from Migrate on pauses automation for an operator. The host PHP-FPM workflow keeps its snapshots on the Gateway host. Its build needs room for 1 GiB and a database copy above the disk floor of 2 GiB and 10 % free, and the run keeps the newest two snapshots plus the snapshot a pause names.

The parent workflow runs the definition loaded when it started. The activate child runs the target commit's definition after Load Swamp. Releases reload PHP-FPM and restart running application services. The [gateway role convergence](/cli/node/role-add) owns the static Gateway runtime files, and node converge the Gateway Node's agent.

### Rollout limits

The rollout updates only the Nodes of the [rollout set](#rollout-set-and-order). An operator updates an operator machine or a macOS Node with `orbit self-update`, and the CLI [tells the operator](/reference/self-update#the-newer-release-notice) about a newer release.

The rollout visits one Node at a time, so it takes longer as the fleet grows. A failed Node blocks every later Node until it converges. There is no way to skip it.

The rollout publishes only the CLI, its connection profile and the Node agent. It does not converge the [Orbit footprint](/reference/node-provisioning#converge-the-orbit-footprint), such as the Caddyfile or the `tmpfiles` rule of an `app-dev` Node; `node:converge` does. Caddy, cAdvisor, the FPM exporter, Prometheus, Grafana, Plausible, and Reverb keep their own update paths.

### Not built

Each of these needs its own decision:

- copies of the pre-migration snapshots off the Gateway host;
- `orbit self-update` that applies the rendered footprint on the Node, so the Gateway stops pushing configuration over SSH;
- automatic updates on operator Macs;
- a macOS Node agent;
- a self-update that an agent signal triggers instead of SSH ([Typed commands over SSH](/reference/node-agent#typed-commands-over-ssh)).

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### One small verifier for one bundle shape

The Gateway is the fleet's trust anchor, so it alone proves where a release came from, and it does so before any Node sees the bytes. Asking GitHub's attestation API at runtime was rejected, because it needs an authenticated call. A signing key held by the workflow was rejected too, because it needs a secret and a rotation.

No PHP Sigstore library exists in the locks, and a general one would carry far more code than the one bundle shape that `actions/attest-build-provenance` writes. A pinned external verifier, such as `cosign` or `sigstore-go` offline with a trusted root, or `gh attestation verify --bundle --custom-trusted-root`, was rejected too.

It would be one more binary to pin, deliver, and update on the Gateway, beside the agent. Admission would depend on a process outside PHP before the release starts. Its output would be one more contract for PHP to parse. So the verifier accepts only that shape, a Rekor v1 `dsse` entry with a signed entry timestamp, and refuses the rest. A format change then stops releases instead of weakening the check.

The cost is a trust root to keep current and a verifier to teach a new format before the attest action changes it. Both changes reach the Gateway through a normal release, as long as they ship before the old format or keys stop.

### The Gateway pulls from GitHub

The Gateway is private, so GitHub cannot reach it, and the GitHub App has [no webhooks](/reference/github-app#no-webhooks). A CI job that pushes to a deploy endpoint with a GitHub OIDC identity was rejected: it needs a new trusted path into the Gateway and new credential handling, and an earlier attempt stalled on exactly that.

A CI deploy job that connects to the Gateway over SSH was rejected in favor of pulling through the existing GitHub App. Pulling adds no deployment credential or Node grant.

### Code decides what ships

Whether a commit passed its checks, and whether a release serves, are deterministic questions. `Required checks` and verify answer them, not an agent's judgment. A deploy bot was planned for this duty and rejected for that reason. Judgment is needed only after a pause, and the operator gives it. Each release is one Swamp run whose steps and result an operator can inspect.

### Immutable releases behind one link

An update in place serves a half-changed tree while `git checkout` and `composer install` run, because the Gateway's PHP-FPM pool checks every script for changes on each request. It also needs a maintenance window. A release that is built beside the live one and switched with one rename never serves a mix of two commits. The in-place procedure is gone, not kept beside the release commands, so there is one way to update a Gateway.

### Releases install no development packages

A release runs the Gateway, its artisan commands, and its own CLI. None of them needs a development package. The Gateway and the CLI register Boost only when its classes exist, and the CLI binary already ships from a no-dev install. Leaving those packages out cut the two `vendor/` directories from about 277 MB to 115 MB and the install from about 8 s to 5 s on a warm Composer cache, measured on a build host on 8 Oct 2026. A release cannot run `composer test` or `artisan boost:*`; use a checkout for those.

Copying the current release's `vendor/` into the next one when `composer.lock` is unchanged was measured and rejected. The copy, the write bit it needs back, and the `composer install` that still runs to rebuild the autoloader took 6.6 to 7.2 s against 4.6 to 5.1 s for a fresh no-dev install. Hard links are not an option, because Composer rewrites autoload files in place.

### Reload and verify the release

The switch reloads PHP-FPM so new requests use the new code and OPcache. It restarts running application services so they follow the stable release path. Draining Horizon first lets jobs finish on the release that started them.

### Expand and contract

The old release serves while the new release migrates. Both must work with the resulting schema. This also lets a failed health check switch back without restoring the database.

### Laravel owns migrations

Laravel owns migration discovery, including vendor migrations. The run uses `migrate --force --isolated=1` rather than duplicating that discovery in a release guard. A migration failure or another unresolved failure after it pauses automation and records the error for the operator.

### The Gateway is not an Orbit Instance

The Instance pipeline deploys to Nodes over SSH and runs one PHP-FPM pool per Instance. The Gateway is the control plane on its own host. Its release cannot depend on a healthy Gateway to run it.

### One Node at a time

Converging Nodes in parallel is faster, but a bad release would break many Nodes at once. A failed Node stops the run, because the next Node would most likely fail the same way, and one broken Node is easier to repair than a fleet. A fleet failure never rolls the Gateway back, because the Gateway already verified and serves.

Each pass starts again at the first Node in risk order that the commit has not converged. So a retry visits the failed Node first, and later Nodes stay untouched until it converges. The release record names each Node’s Operations. Swamp keeps the step history.

A busy publication lock defers a Node instead of failing it. A missing CLI release is the normal state for a few minutes after each deploy, so it waits instead of failing.

Development [`instance:mail:update`](/cli/instance/mail-update), [`instance:sleep`](/cli/instance/sleep), [`instance:wake`](/cli/instance/wake), [`instance:complete`](/cli/instance/complete) and [`env:sync` on a container Instance](/cli/env/sync#on-a-container-instance) (`instance.connection_environment`) also hold the release idle guard until their Operation settles.

The release idle guard includes `instance.node_service_detach`: wait for its bounded network/proxy withdrawal before replacing Gateway execution code. Its record remains until the scoped withdrawal succeeds, so a failed Operation can be retried.
