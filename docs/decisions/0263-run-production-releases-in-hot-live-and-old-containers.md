---
title: "ADR 0263: Run production releases in hot, live and old containers"
sidebarTitle: "0263 Production containers"
description: "In progress, proposed. A production Instance keeps one record; each release is an Incus container on its app-prod Node. Hot is a ZFS clone of the live release's snapshot that builds and migrates the next commit; when it is ready, Caddy switches the Route to it, it becomes live, the previous live becomes old and stays stopped for rollback, and the earlier old goes."
---

# ADR 0263: Run production releases in hot, live and old containers

A production Instance keeps one stable record: its id, Routes, environment, deploy steps, attachments, Processes and Schedules. Each release of it is an Incus container on its `app-prod` Node. Each container has one of three release states:

- **Hot** builds the next commit. It is a ZFS clone of the live release's snapshot. Orbit fetches the commit into it, runs the deploy steps and migrates the shared database, while live still serves.
- **Live** serves the Instance's Routes and runs its workers and Schedules.
- **Old** is the release before live. It is stopped and kept for rollback.

When hot is ready, Caddy points the Routes at it in one reload. Hot becomes live, the previous live becomes old, and the earlier old is removed. So a deploy has at most three containers, and only two run. The [production containers](/reference/production-containers) page is the contract.

## Status

In progress.

Proposed: a design for review, with no part built. It specifies step 10 of [ADR 0250](/decisions/0250-run-instances-and-task-groups-in-incus-containers#build-order) and takes the place of the release design in [Container instances and releases](/solutions/container-instances-and-releases#production-design) and PR 18 of the [container runtime plan](/solutions/development-container-runtime#production-instances).

Principle: [One way, one name](/mission#principles) and [No exceptions and no legacy](/mission#principles). Production uses the container machinery of development: images, ZFS clones, snapshots, the runtime folder, the bridge and the egress proxy. Each old production path below goes when its replacement is built.

### Open questions for the owner

1. **When old goes.** This ADR keeps the previous live stopped as old until the next switch removes it, so a deploy has three containers and a rollback needs no build. The other reading removes old right after the switch, and makes rollback a fresh clone of the previous release snapshot, which then must outlive its container. Recommendation: keep old stopped.
2. **The memory limit.** 4 GiB per release container by default, set per Instance with `instance:update`. Recommendation: take it, and change the default after the fixture proof.

## Context

Today a production Instance is a checkout home on an `app-prod` Node ([Instance releases](/reference/deployments#the-production-home)). Each deploy clones a release into `releases/<name>`, links shared paths into the home, runs the steps, swaps the `current` link and reloads a dedicated PHP-FPM pool. The home keeps three releases. A rollback swaps `current` back. Processes run on the Node as the Project account, and per-minute schedulers keep Node timers.

ADR 0250 moves every Instance into an Incus container. [Instance containers](/reference/development-containers#production-instances) holds the decided basics for production: the `app-prod` role installs the container setup and the runtime folder, not PHP-FPM; a production container has only the user `orbit`; FrankenPHP serves it after its [production gate](/solutions/development-container-runtime#production-gate); production containers never sleep; existing checkouts are migrated.

The development side proved the machinery: a ZFS clone of a snapshot takes about a second and copies no file, and a default Instance's [seed](/reference/development-containers#the-default-instance) carries warm dependencies to its clones. An earlier production spike proved a warm deploy in containers with a clone, a candidate and a Route switch. Its proposal had a separate preparation container that stayed between deploys.

Production differs from development in four ways that drive this decision:

- One shared database serves every release. It is never cloned per release.
- Traffic must not stop for a deploy, a migration or a rollback. No deploy waits for a window, a soak or an approval.
- Workers, Schedules and long-running Processes must never run twice.
- Uploads and keys must survive every release.

## Decision

The numbered parts below settle the mapping onto today, the source of hot, the database, readiness and rollback, the workers, storage, PowerSync, capacity and PHP. Part 10 lists the next steps.

### 1. One record, release containers under it

The production Instance stays the one record that users and Routes name. Its release containers are runtime rows in `instance_runtimes`, one per container, with a `release_state` of `hot`, `live` or `old`. An Instance has at most one row in each state. The deployment history keeps its rows; a row's `release` names the release container that the deploy built.

| Today | Replaced by | Old path that goes |
| --- | --- | --- |
| `releases/<name>/` in the production home | One release container, `app-instance-<instance>-<release>`, with the same release name | The release directories and the release layout marker |
| The `current` link | The Node's live link of the Instance and its Caddy upstream file, which the switch replaces | `current`, `current.next` and the swap steps |
| Three retained releases | Live and old. Hot exists only during a deploy | The cleanup step and its process scan |
| `<home>/.env` and its links | `.env` written into each release container after its clone | The home `.env`, `env/<directory>/.env` and their links |
| `<home>/<shared path>` | The Instance's storage volume, mounted in every release container | Shared directories in the home |
| `<home>/database.sqlite` | The same file in the storage volume | The home file |
| The dedicated PHP-FPM pool | FrankenPHP in the container, from the runtime folder version that the release pins | The pool, its unit and the PHP refresh steps |
| The `orbit-app-<ProjectID>` account and sudo | The user `orbit` in the container | The production account and its home |
| `instance:deploy` on a home | `instance:deploy` builds hot and switches to it | The home branch of the deploy workflow |
| `instance:rollback <release>` | `instance:rollback` returns to old | The `release` argument |
| `instance:release:list` | Lists the release containers and their states | The home scan |
| `instance:clone` to a home | `instance:clone` records the Instance, its storage volume and its preview Route, with no container. A container candidate is accepted | The home preparation and the checkout candidate rule |
| The automatic deploy tick | The same tick. Its deployed commit is live's commit, and its deploy is the hot deploy | Nothing else changes in the tick |
| Node timers of per-minute production schedulers | Container timers in the release containers, active only in live | Production Node timers |
| `restart_on_deploy` on production Processes | Every release container starts its own Processes when it becomes live | The field for production Instances |

The first release of an Instance has no live snapshot, so its hot launches from the image: a cold hot. The same flow moves an existing checkout production Instance: [`instance:migrate`](/cli/instance/migrate) builds a cold hot at the commit that `current` selects, copies each shared path of the home into the storage volume, switches the Routes from PHP-FPM to the container, and stops the host Processes, timers and pool. The home stays, unserved, until the next successful deploy removes it with the production account. There is no other way to move, and a checkout production Instance can no longer deploy after its move.

The planned charts are `docs/cli/instance/container/deploy-production.mmd`, `docs/cli/instance/container/rollback-production.mmd` and `docs/cli/instance/container/create-production.mmd` (the first release container, after a clone or for a migrated checkout), and a change of `docs/cli/instance/auto-deploy.mmd`.

### 2. Hot is a clone of live's release snapshot

Each release container takes one snapshot, `release`, after its deploy steps passed and before any request reaches it. The next hot is a ZFS clone of live's `release` snapshot. It starts with the dependencies, the build output and the Composer and npm caches of the live release, so a deploy without a dependency change installs nothing. The clone copies no file and takes about a second.

After the clone, and before the first step:

1. Orbit attaches the storage volume, sets the container's limits and mounts the runtime folder version that the Node pins now.
2. Hot applies the Node's pending package upgrades, so a [patch run](/decisions/0253-patch-nodes-and-reboot-them-drained) that upgraded live reaches the next release too.
3. The Node's mirror fetches the pinned commit and Orbit pushes it into hot. Hot checks it out with the cleanup rules of a [development deploy](/reference/deployments#development-defaults): tracked files match the commit exactly, and untracked dependency folders stay.
4. Orbit writes the rendered `.env` into hot. Secrets arrive only now, never through the image or the mirror.

Live's changes after its snapshot never reach the next hot: logs, caches and files that requests wrote stay in live. Old keeps its own snapshot, so a rollback can still serve old.

Hot launches from the image instead (a cold hot) when there is no live release, when the Node's image is newer than the image that live came from, or for a migration. A cold hot also starts a new ZFS clone chain.

### 3. One shared database; migrations run in hot before the switch

Every release container uses the Instance's shared production database. The `migrate` deploy step runs in hot, as a `before_activation` step, while live still serves. So every migration follows expand and contract:

- The code of live must work on the schema after the migration. A change that live cannot read is split over two deploys: the first expands, and the second contracts once no live or old release uses the old shape.
- A migration changes only the schema, or changes data with raw queries. It never loads application models or domain code, because those belong to the new code and the data still belongs to the old. A migration is tested against the schema of the last released commit.

A migration that fails before the switch leaves live serving. Orbit removes hot, and the commit counts as a failed commit under the [automatic deploy rules](/reference/deployments#failures-of-automatic-deployments): a failure in a deploy step blocks it. What a failed migration already changed stays; Orbit undoes no data change. A rollback never undoes a migration.

### 4. Ready, switch and rollback

Hot is ready when all four hold:

- Every `before_activation` step passed and the `release` snapshot exists.
- FrankenPHP in hot is active.
- The Instance's health path answers `200` three times in a row. Orbit sends each request from the Node to hot's bridge address, port 8080, with the Route's domain as `Host` and `X-Forwarded-Proto: https`, each within 5 seconds, all within 60 seconds. The health path is `/up` for a Laravel app and `/` otherwise. The three requests also warm OPcache.
- The PHP of hot loads no PCOV.

Otherwise the deploy fails with `deployment.not_ready`, before the switch, with the outcome of a failed migration above.

**Switch.** The Node's Caddy site of each Route of the Instance imports one upstream file of the Instance. The switch writes the new upstream file and the live link beside the old ones, validates Caddy with them, renames both into place and reloads Caddy once. New requests go to the new live. Caddy's graceful reload lets each request in progress on the previous live finish. A Cluster Route needs no Router change: the Router still sends to the same Node.

**Handover.** After the switch, in this order:

1. The previous live's container timers stop, a running fire finishes, and the new live's timers start.
2. Each Process of the previous live stops with a graceful stop, bounded to 120 seconds: Horizon terminates and queue workers finish their current job. Then systemd ends what is left.
3. The new live starts each Process whose desired state is `running`.
4. The `after_activation` steps run in the new live.
5. The previous live stops and becomes old. The earlier old is removed, with its snapshot.

**Rollback.** `instance:rollback` returns to old. Orbit writes the Instance's current `.env` into old, starts it, checks it as ready, switches the Routes to it and hands over in the same order. The rolled-away live stops and becomes old, so a second rollback goes forward again. A rollback runs no deploy step, fetches nothing and changes no data: it selects code only. It pauses automatic deploys and blocks the rolled-away commit, as today. Without an old release, rollback refuses with `rollback.release_not_retained`. To go back further, deploy the earlier commit with `instance:deploy --commit`.

### 5. Workers, Schedules and Reverb run only in live

Hot runs no Process, no container timer and no Schedule fire before the switch. Its Process units and timers are installed disabled, so its `release` snapshot holds them disabled too. Old runs nothing; it is stopped.

- **Processes.** Each Process record of the Instance has a unit in every release container. Only live runs it. `process:*` commands act on live.
- **Per-minute schedulers.** A Schedule with a systemd calendar, such as the Laravel scheduler, is a [container timer](/reference/schedules#container-timers) in each release container, active only in live.
- **Native Schedules.** A cron Schedule stays a native Swamp Schedule. Each fire reads the Instance's live link on the Node and runs its command in that container as `orbit`. One link names one container, so a fire never runs twice.
- **Reverb.** Orbit runs one shared Reverb outside every Instance. The Route sends only the Pusher `/app` and `/apps` paths to it, so a switch needs no Reverb handover. A Caddy reload can end open WebSocket streams; Pusher clients reconnect by themselves.
- **Queue payloads.** Jobs that the new release queues before the handover can reach a worker of the previous release, and a rollback runs old workers on new jobs. So a job's payload follows the expand and contract rule across one release.

### 6. Persistent storage is one custom volume per Instance

Data that must survive a release never lives on a release container's root disk. Each production Instance has one Incus filesystem custom volume, `app-instance-<instance>-shared`, on the Node's pool. Orbit attaches it at `/srv/shared` in every release container: hot, live and old share it, as Incus permits for a filesystem volume on one Node. Each [shared path](/reference/deployments#shared-paths) of the Instance, `storage` by default, is a link from the application directory into the volume. Uploads, Passport keys, logs and a SQLite database live there.

`instance:clone` creates the volume. A migration copies each shared path of the home into it: one copy while the checkout serves, then a final copy of the changes after the switch, which never deletes a file in the volume. Instance removal removes the volume with the Instance. A Project may move uploads to object storage such as S3 as its own choice; the volume stays for the rest.

### 7. PowerSync stays outside every deploy

PowerSync runs on the Services Node as Node Processes and replicates from the shared database. A production deploy or rollback:

- never starts, stops or changes a Process on the Services Node;
- never drops or changes a replication slot or a publication. A publication change is a change of the PowerSync configuration, not a migration;
- changes a table that a publication covers only by expand and contract, like every other table;
- keeps the token and upload endpoints of the app answering, because the switch is one Caddy reload with the previous live finishing its requests.

### 8. Capacity: limits and an admission check

Each release container has the memory limit of its Instance, 4 GiB by default. During a deploy, live and hot run, and old is stopped: two memory limits and three root disks, of which a clone uses only its changed blocks.

The deploy's first step on the Node checks capacity before the hot clone: the Node's available memory must cover hot's memory limit plus 1 GiB, and the pool must have 10 GiB free. Otherwise the deploy fails with `deployment.node_capacity` and changes nothing. That failure comes before any deploy step, so the automatic tick retries it with its backoff. Nothing waits on the Node.

### 9. PHP: the static glibc build, pinned per release

FrankenPHP serves every release container, in classic mode, from the runtime folder's static glibc PHP build. Production PHP never loads PCOV or PHP Data Bridge. Each release pins the runtime folder version it was built with: its container mounts that version, and its runtime row records it. A runtime folder update on the Node restarts no production container. It reaches production only through the next deploy. The Node keeps each runtime version that a release container records.

### 10. Next steps

The build follows the [container runtime plan](/solutions/development-container-runtime#production-instances) after the production gate. A first order, each one PR with its proof:

1. Charts, pages and cases of the planned production charts.
2. A container branch of the `app-prod` role and the production image, with no agent.
3. The records: `release_state`, the storage volume and the live link.
4. `instance:clone` and the first release container on a fixture production Project.
5. The hot deploy with readiness, switch and handover, and the automatic tick on it.
6. Rollback to old.
7. `instance:migrate` for a production checkout, then each production Instance, one at a time, and the removal of PHP-FPM from the role.

The live proof runs on a fixture production Project before the first real production Project moves. Subtask 2 of this task group writes the detailed list and the proof plan.

## Rejected alternatives

- **A separate preparation container, kept between deploys.** It was the earlier proposal. It is a fourth container per Instance with its own drift, and its build is never the build that served. A clone of live's `release` snapshot gives the same warm start, and that snapshot is exactly what passed readiness.
- **A clone of live as it runs now.** It would carry every runtime change of live, such as caches and files that requests wrote, into the next release.
- **A cold build from the image for every deploy.** Every deploy would install all dependencies again. Orbit uses it only when there is no warm source or the image changed.
- **Deploy in place in live, as today.** No release would be isolated, a failed build would touch the serving container, and a rollback would need retained directories again.
- **A database copy per release.** One shared database is the production state; a copy per release would split writes.
- **Migrations after the switch.** The new code would serve on the old schema until they end.
- **Undo a migration on rollback.** Older code can need a data recovery that only the application knows. Rollback selects code only.
- **Keep old running.** It would hold memory for a rollback that a start of a stopped container serves in seconds.
- **Remove old at the switch.** Rollback would become a new build. A stopped old costs only changed blocks.
- **Keep more releases or snapshots.** Each kept snapshot holds a ZFS chain and disk. A pinned deploy of an earlier commit covers the rare deeper rollback.
- **Start workers in hot before the switch, or in both.** Jobs and Schedules would run twice, or run new code before its traffic.
- **Storage on the container's root disk, or a copy per release.** Uploads would go with a removed container, and a copy per release copies files.
- **Bind-mount the home's storage into the containers.** It would keep the home alive as a second production layout.
- **Wait for capacity on the Node.** A waiting run holds the Instance and hides the cause. A refused deploy with a retry is visible and lets a manual action in.
- **Check readiness through the public Route.** The Route still serves live until the switch; only a request to hot's bridge address tests hot.
- **A separate Incus project or bridge for production.** An `app-prod` Node runs no development container, so `orbit-instances` and `orbitinst0` serve it with one setup.

## Consequences

- A deploy never stops traffic: live serves until the switch, and the switch is one Caddy reload. The PHP-FPM restart of today goes.
- A failed build, migration or readiness check never touches live. Hot is removed.
- Every migration must be expand and contract, and every queued job payload must stay readable by the release before it. This is a rule for each Project, not something Orbit can check.
- A rollback goes back one release in seconds, as long as old exists. A deeper rollback is a deploy of an earlier commit.
- Each deploy holds two running containers and three root disks on the Node for its duration.
- ZFS clone chains grow by one release per warm deploy until the next cold hot resets them. The live proof measures whether Incus frees the removed releases' datasets once no clone depends on them.
- A Project that caches its configuration in a deploy step serves that cache after a rollback, with the values it cached.

## Affects

- Components: apps/gateway, apps/cli, packages/php-sdk, apps/docs
- ADRs: [0250](/decisions/0250-run-instances-and-task-groups-in-incus-containers), [0253](/decisions/0253-patch-nodes-and-reboot-them-drained)
- Detail: [Production containers](/reference/production-containers), [Instance releases](/reference/deployments), [Instance cloning](/reference/instance-cloning), [Schedules](/reference/schedules)
- Verify: Gateway Pest tests of the records, admission and projections; the cases of the production charts; the live proof on a fixture production Project
