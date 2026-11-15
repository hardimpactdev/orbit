---
title: "ADR 0263: Run production releases in hot, live and old containers"
sidebarTitle: "0263 Production containers"
description: "In progress, proposed. A production Instance keeps one record; each release is an Incus container on its app-prod Node. Hot is a ZFS clone of the live release's snapshot that builds and migrates the next commit; when it is ready, Caddy switches the Route to it and it becomes live. The ADR proposes, as a deviation open for the owner, that the previous live stays stopped as old for rollback."
---

# ADR 0263: Run production releases in hot, live and old containers

A production Instance keeps one stable record: its id, Routes, environment, deploy steps, attachments, Processes and Schedules. Each release of it is an Incus container on its `app-prod` Node. Each container has one of three release states:

- **Hot** builds the next commit. It is a ZFS clone of the live release's snapshot. Orbit fetches the commit into it, runs the deploy steps and migrates the shared database, while live still serves.
- **Live** serves the Instance's Routes and runs its workers and Schedules.
- **Old** is the release before live. This ADR proposes to keep it stopped for rollback. That is a deviation from the owner's design, open as [question 1](#open-questions-for-the-owner).

When hot is ready, Caddy points the Routes at it in one reload, and hot becomes live. The [production containers](/reference/production-containers) page is the contract and holds every number and path.

## Status

In progress.

Proposed: a design for review, with no part built. It specifies step 10 of [ADR 0250](/decisions/0250-run-instances-and-task-groups-in-incus-containers#build-order) and takes the place of the release design in [Container instances and releases](/solutions/container-instances-and-releases#production-design) and PR 18 of the [container runtime plan](/solutions/development-container-runtime#production-instances).

Principle: [One way, one name](/mission#principles) and [No exceptions and no legacy](/mission#principles). Production uses the container machinery of development: images, ZFS clones, snapshots, the runtime folder, the bridge and the egress proxy. Each old production path below goes when its replacement is built.

### Deviation from the owner's design

The owner's design: "when hot is ready Caddy points the Route at it, it becomes live, and the previous live container is removed". This ADR deviates from it in one point. It keeps the previous live stopped as old until the next switch, and removes it only then.

| | Owner's design | This ADR's proposal |
| --- | --- | --- |
| Between deploys | Live only | Live running, old stopped |
| During a deploy | Hot and live | Hot and live running, old stopped |
| Memory | One limit between deploys, two during a deploy | The same: a stopped old holds no memory |
| Disk | Live's root disk | Live's and old's root disks. Live is a ZFS clone of old's snapshot, so they share most blocks |
| Rollback | Only while the previous live still exists, during the handover drain: switch back to it | Start old, check it as ready and switch to it, in seconds, with no build |
| An earlier release after that | `instance:deploy --commit` of the earlier commit: a warm hot from live's snapshot, with every before step | The same, for any release before old |

In the owner's design no copy of the previous release outlives its container. An Incus snapshot is deleted with its container, and an image published from a snapshot copies files. So the only way back after the drain is a new build.

The disk gain of the owner's design is also small. Live is a ZFS clone of the previous live's snapshot, and Incus keeps a removed container's dataset while a clone still depends on it.

### Open questions for the owner

1. **When the previous live goes.** Keep it stopped as old until the next switch (this ADR), or remove it after the handover (the owner's design), as the [deviation](#deviation-from-the-owners-design) compares. Recommendation: keep it stopped. A rollback in seconds, with no build, costs only changed blocks on disk.
2. **The memory limit.** The [contract](/reference/production-containers#records) sets a default per release container, which `instance:update` changes per Instance. Recommendation: take the default, and change it after the fixture proof.

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

Release containers and the storage volume use the Incus name of the Instance, [`instance-<id>`](/reference/development-containers#the-machine-name), with a suffix.

| Today | Replaced by | Old path that goes |
| --- | --- | --- |
| `releases/<name>/` in the production home | One release container, `instance-<id>-<release>`, with the same release name | The release directories and the release layout marker |
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
| Node timers of per-minute production schedulers | Container timers in the release containers, enabled only in live | Production Node timers |
| `restart_on_deploy` on production Processes | Every release container starts its own Processes when it becomes live | The field for production Instances |

The first release of an Instance has no live snapshot, so its hot launches from the image: a cold hot. The same flow moves an existing checkout production Instance with [`instance:migrate`](/cli/instance/migrate). It copies the home's shared paths into the storage volume first, then builds a cold hot at the commit that `current` selects, switches the Routes from PHP-FPM to the container and stops the host Processes, timers and pool. Each native Schedule keeps firing in the home until the migration ends; then the Gateway rewrites its workflow for the live link, as an Instance transfer does. The home stays, unserved, until the next successful deploy removes it with the production account. There is no other way to move, and a checkout production Instance can no longer deploy after its move.

The planned charts are `docs/cli/instance/container/deploy-production.mmd`, `docs/cli/instance/container/rollback-production.mmd` and `docs/cli/instance/container/create-production.mmd` (the first release container, after a clone or for a migrated checkout), and a change of `docs/cli/instance/auto-deploy.mmd`.

### 2. Hot is a clone of live's release snapshot

Each release container takes one snapshot, `release`, after its deploy steps passed and before any request reaches it. The next hot is a ZFS clone of live's `release` snapshot. It starts with the dependencies, the build output and the Composer and npm caches of the live release, so a deploy without a dependency change installs nothing. The clone copies no file and takes about a second.

After the clone, and before the first step, Orbit attaches the storage volume, sets the limits and mounts the runtime folder version that the Node pins now. Hot applies the Node's pending package upgrades, so a [patch run](/decisions/0253-patch-nodes-and-reboot-them-drained) that upgraded live reaches the next release too. The Node's mirror fetches the commit and Orbit pushes it into hot, which checks it out with the cleanup rules of a [development deploy](/reference/deployments#development-defaults). Then Orbit writes the rendered `.env`.

A release snapshot holds that release's `.env`, because the deploy steps need it before the snapshot. So a warm hot carries live's `.env` from its clone until Orbit writes the new one. Secrets never come from the image or the mirror, and the snapshot stays on the Node, inside the same boundary as live.

Live's changes after its snapshot never reach the next hot: logs, caches and files that requests wrote stay in live. Old's snapshot stays because live is a ZFS clone of it; a rollback starts old's container itself, not its snapshot.

Hot launches from the image instead (a cold hot) when there is no live release, when the Node's image is newer than the image that live came from, or for a migration. A cold hot also starts a new ZFS clone chain.

### 3. One shared database; migrations run in hot before the switch

Every release container uses the Instance's shared production database. The `migrate` deploy step runs in hot, as a `before_activation` step, while live still serves. So every migration follows expand and contract:

- The code of live must work on the schema after the migration. A change that live cannot read is split over two deploys: the first expands, and the second contracts once no live or old release uses the old shape.
- A migration changes only the schema, or changes data with raw queries. It never loads application models or domain code, because those belong to the new code and the data still belongs to the old. A migration is tested against the schema of the last released commit.

A migration that fails before the switch leaves live serving. Orbit removes hot, and the commit counts as a failed commit under the [automatic deploy rules](/reference/deployments#failures-of-automatic-deployments): a failure in a deploy step blocks it. What a failed migration already changed stays; Orbit undoes no data change. A rollback never undoes a migration.

### 4. Ready, switch and rollback

Hot must be [ready](/reference/production-containers#ready) before any traffic reaches it: its steps passed, its snapshot exists, FrankenPHP is up, the health path answers through hot's bridge address with a Route's domain as `Host`, and its PHP loads no development extension. Otherwise the deploy fails with `deployment.not_ready`, with the outcome of a failed migration above.

**Switch.** The Node's Caddy site of each Route of the Instance imports one upstream file of the Instance. The [switch](/reference/production-containers#switch) replaces that file and the live link together and reloads Caddy once. Caddy's graceful reload lets each request in progress on the previous live finish. A failed reload puts the previous files back, so live serves unchanged. A Cluster Route needs no Router change.

**Handover.** The [handover](/reference/production-containers#handover) moves timers, then Processes, from the previous live to the new live, with a bounded graceful stop. Then the `after_activation` steps run in the new live, and [retire](/reference/production-containers#retire) stops the previous live as old and removes the earlier old.

**Rollback.** `instance:rollback` returns to old: Orbit writes the Instance's current `.env` into old, starts it, checks it as ready, switches the Routes to it and hands over. The rolled-away live becomes old, so a second rollback goes forward again. A rollback runs no deploy step, fetches nothing and changes no data: it selects code only. It pauses automatic deploys and blocks the rolled-away commit, as today. Without an old release, rollback refuses with `rollback.release_not_retained`. To go back further, deploy the earlier commit with `instance:deploy --commit`.

### 5. Workers, Schedules and Reverb run only in live

One rule decides where work runs: a release container's Process units and container timers are enabled only while it is live, and only live starts with its Node. The handover disables and stops them in the previous live, then enables and starts them in the new live. Hot installs them disabled, so its snapshot holds them disabled too. So a Node reboot restores live with its work, and a start of old or hot runs none of it.

- **Processes.** Each Process record of the Instance has a unit in every release container. Only live runs it. `process:*` commands act on live.
- **Per-minute schedulers.** A Schedule with a systemd calendar, such as the Laravel scheduler, is a [container timer](/reference/schedules#container-timers) in each release container, enabled only in live.
- **Native Schedules.** A cron Schedule stays a native Swamp Schedule. Each fire reads the Instance's live link on the Node and runs its command in that container as `orbit`. Retire waits for a fire that still runs in the previous live. One link names one container, so a fire never runs twice.
- **Reverb.** Orbit runs one shared Reverb outside every Instance. The Route sends only the Pusher `/app` and `/apps` paths to it, so a switch needs no Reverb handover. A Caddy reload can end open WebSocket streams; Pusher clients reconnect by themselves.
- **Queue payloads.** Jobs that the new release queues before the handover can reach a worker of the previous release, and a rollback runs old workers on new jobs. So a job's payload follows the expand and contract rule across one release.

### 6. Persistent storage is one custom volume per Instance

Data that must survive a release never lives on a release container's root disk. Each production Instance has one Incus filesystem custom volume, `instance-<id>-shared`, on the Node's pool. Orbit attaches it in every release container: hot, live and old share it, as Incus permits for a filesystem volume on one Node. Each [shared path](/reference/deployments#shared-paths) of the Instance, `storage` by default, is a link from the application directory into the volume. Uploads, Passport keys, logs and a SQLite database live there.

`instance:clone` creates the volume, and writes a seeded SQLite database into it. A migration copies each shared path of the home into it before hot's steps run, so a step that makes keys only when they are missing finds the existing keys. After the switch it copies the changes again, and never deletes a file in the volume. Instance removal removes the volume with the Instance. A Project may move uploads to object storage such as S3 as its own choice; the volume stays for the rest.

### 7. PowerSync stays outside every deploy

PowerSync runs on the Services Node as Node Processes and replicates from the shared database. A production deploy or rollback:

- never starts, stops or changes a Process on the Services Node;
- never drops or changes a replication slot or a publication. A publication change is a change of the PowerSync configuration, not a migration;
- changes a table that a publication covers only by expand and contract, like every other table;
- keeps the token and upload endpoints of the app answering, because the switch is one Caddy reload with the previous live finishing its requests.

### 8. Capacity: limits and an admission check

Each release container has the memory limit of its Instance. During a deploy, live and hot run, and old is stopped: two memory limits and three root disks, of which a clone uses only its changed blocks.

The deploy's first step on the Node runs the [capacity check](/reference/production-containers#capacity) before the hot clone. When the Node lacks memory or pool space, the deploy fails with `deployment.node_capacity` and changes nothing. Nothing waits on the Node. The automatic tick retries it, and it never counts toward blocking the commit, because the cause is the Node, not the commit.

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
- **Keep more releases or snapshots.** Each kept release holds a ZFS chain and disk. A pinned deploy of an earlier commit covers the rare deeper rollback.
- **Publish an image of each release for rollback.** Publishing an image copies files.
- **Units enabled in every release container.** A start of old for a rollback would start its workers and timers while live still runs its own.
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
- With this ADR's proposal, a rollback goes back one release in seconds, as long as old exists. A deeper rollback is a deploy of an earlier commit.
- Each deploy holds two running containers and three root disks on the Node for its duration.
- ZFS clone chains grow by one release per warm deploy until the next cold hot resets them. The live proof measures whether Incus frees the removed releases' datasets once no clone depends on them.
- A Project that caches its configuration in a deploy step serves that cache after a rollback, with the values it cached.

## Affects

- Components: apps/gateway, apps/cli, packages/php-sdk, apps/docs
- ADRs: [0250](/decisions/0250-run-instances-and-task-groups-in-incus-containers), [0253](/decisions/0253-patch-nodes-and-reboot-them-drained)
- Detail: [Production containers](/reference/production-containers), [Instance releases](/reference/deployments), [Instance cloning](/reference/instance-cloning), [Schedules](/reference/schedules)
- Verify: Gateway Pest tests of the records, admission and projections; the cases of the production charts; the live proof on a fixture production Project
