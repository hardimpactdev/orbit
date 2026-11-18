---
title: "ADR 0263: Run production releases in hot, live and old containers"
sidebarTitle: "0263 Production containers"
description: "Planned app-prod integration. Each Instance has a permanent off-traffic preparation container. Deploy there, clone the completed build, switch to the ready clone, and keep the drained previous live stopped for rollback."
---

# ADR 0263: Run production releases in hot, live and old containers

A production Instance keeps one stable record: its id, Routes, environment, deploy steps, attachments, Processes and Schedules. Each release of it is an Incus container on its `app-prod` Node. Each container has one of three release states:

- **Hot** is a fresh filesystem clone of the completed build in this Instance’s preparation container. It starts its web server and passes readiness before it receives traffic.
- **Live** serves the Instance's Routes and runs its workers and Schedules.
- **Old** is the release before live. It stays stopped for rollback, and the next deploy's retire removes it.

When hot is ready, Caddy points the Routes at it in one reload, and hot becomes live. The [production containers](/reference/production-containers) page is the contract and holds every number and path.

## Status

In progress.

Gateway and production Instance Operations now enter the same Swamp release workflow. Each Instance on an app-prod Node has its own preparation container, routing state and rollback receipt. See the [production container reference](/reference/production-containers) for the release sequence and the existing checkout transition.

Principle: [One way, one name](/mission#principles) and [No exceptions and no legacy](/mission#principles). Production uses the container machinery of development: images, ZFS clones, snapshots, the runtime folder, the bridge and the egress proxy. Each old production path below goes when its replacement is built.

### Why old stays stopped

The owner decided that the previous live stays stopped as old, and that the next deploy's retire removes it:

- A rollback starts old, checks it as ready and switches to it, in seconds, with no build.
- A stopped old holds no memory or swap. Retire never depends on the Node's swap, so a release cannot fail after its switch for lack of it.
- Without old, the only way back after the handover is a new build.

## Context

Today a production Instance is a checkout home on an `app-prod` Node ([Instance releases](/reference/deployments#the-production-home)). Each deploy clones a release into `releases/<name>`, links shared paths into the home, runs the steps, swaps the `current` link and reloads a dedicated PHP-FPM pool. The home keeps three releases. A rollback swaps `current` back. Processes run on the Node as the Project account, and per-minute schedulers keep Node timers.

ADR 0250 moves every Instance into an Incus container. [Instance containers](/reference/development-containers#production-instances) holds the decided basics for production: the `app-prod` role installs the container setup and the runtime folder, not PHP-FPM; a production container has only the user `orbit`; FrankenPHP serves it after the [production gate](#production-gate); existing checkouts are migrated. [ADR 0265](/decisions/0265-sleep-idle-production-containers) lets an idle live sleep and wake fully ready.

Development uses a [default Instance seed](/reference/development-containers#the-default-instance) to share warm dependencies. The preparation container brings that pattern to production. It can change without disturbing the serving release.

Production differs from development in four ways that drive this decision:

- One shared database serves every release. It is never cloned per release.
- Traffic must not stop for a deploy, a migration or a rollback. No deploy waits for a window, a soak or an approval.
- Workers, Schedules and long-running Processes must never run twice.
- Uploads and keys must survive every release.

## Decision

The numbered parts below settle the mapping onto today, the source of hot, the database, readiness and rollback, the workers, storage, PowerSync, capacity and PHP. Part 10 lists the next steps.

### 1. One record, release containers under it

The production Instance stays the one record that users and Routes name. Its release containers are runtime rows in `instance_runtimes`, one per container, with a `release_state` of `hot`, `live` or `old`. An Instance has at most one row in each state. The persistent preparation container belongs to the Instance and has no release state. The deployment history keeps its rows; a row's `release` names the release container that the deploy built.

Release containers and the storage volume use the Incus name of the Instance, [`instance-<id>`](/reference/development-containers#the-machine-name), with a suffix.

| Today | Replaced by | Old path that goes |
| --- | --- | --- |
| `releases/<name>/` in the production home | One release container, `instance-<id>-<release>`, with the commit’s 12-character prefix in its name | The release directories and the release layout marker |
| The `current` link | The Node's live link of the Instance and its Caddy upstream file, which the switch replaces | `current`, `current.next` and the swap steps |
| Three retained releases | Live and old, plus a persistent off-traffic preparation container. A hot candidate adds a fourth container briefly | The checkout cleanup step and its process scan |
| `<home>/.env` and its links | The Instance environment is applied to the preparation container before the build. On rollback the old release receives the current environment before readiness | The home environment links |
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

The first deploy creates the preparation container, builds in it, takes the release clone and switches to that clone. A checkout migration uses the same flow at the commit selected by `current`. Copy the home’s shared paths to the persistent volume first. After verification, move the Processes and Schedules and stop the host pool. Keep the unserved home until the next verified deploy completes its final copy and removes it. See [first release and migration](/reference/production-containers#first-release-and-migration).

The planned charts are `docs/cli/instance/container/deploy-production.mmd`, `docs/cli/instance/container/rollback-production.mmd`, `docs/cli/instance/container/create-production.mmd` (a clone, whose first deploy builds the first release container) and `docs/cli/instance/container/migrate-production.mmd` (a checkout), and a change of `docs/cli/instance/auto-deploy.mmd`.

### 2. Clone the finished build

<a id="2-hot-is-a-clone-of-lives-release-snapshot"></a>The permanent preparation container belongs to this Instance alone. It receives no traffic and runs no application workers, Schedules or timers. The first deploy creates it from the pinned production image. Subsequent deploys wake the same container and reuse its dependency cache.

Deploy the admitted revision, render its environment, run its build steps and verify the finished artifact. A failed update leaves live serving. Before a retry, remove incomplete build output. Seal the full commit, artifact digest, Instance and Operation, take a filesystem snapshot, and clone that snapshot as hot. Only the candidate undergoes serving readiness. It starts its own FrankenPHP processes. After a successful clone the preparation container can freeze to swap while hot finishes readiness.

The Gateway uses the same release operations for its own target. Each production Instance has its own preparation, release names, power records, storage and upstream. Both share clone, freeze, thaw and cold-start operations. Each applies its own health check, traffic switch and application-service handover. [Production containers](/reference/production-containers#build-hot) owns the build steps.

### 3. Run migrations against the shared database before the switch

<a id="3-one-shared-database-migrations-run-in-hot-before-the-switch"></a>

Every release uses the Instance’s shared production database. Its `migrate` step runs once in the preparation container before activation while live still serves. Every migration is expand-only: a release adds what its code needs, and a later release contracts what no live or old release still reads. So the stopped old keeps running on the current database, and a rollback never restores data. Reviewers reject a destructive migration.

- The code of live must work on the schema after the migration. A change that live cannot read is split over two deploys: the first expands, and the second contracts once no live or old release uses the old shape.
- A migration changes only the schema, or changes data with raw queries. It never loads application models or domain code, because those belong to the new code and the data still belongs to the old. A migration is tested against the schema of the last released commit.

A failed migration leaves live serving. Discard partial build output before retrying, and record the failed commit under the [automatic deploy rules](/reference/deployments#failures-of-automatic-deployments). Schema changes that already completed remain in place. A rollback selects code only.

### 4. Ready, switch and rollback

Before the switch, check the clone’s full commit and artifact digest. Verify FrankenPHP, the health path through the clone’s private address with the Route domain, and the absence of development extensions. A failure refuses promotion with `deployment.not_ready` and leaves live serving. See [Ready](/reference/production-containers#ready).

**Switch.** The Node's Caddy site of each Route of the Instance imports one upstream file of the Instance. The [switch](/reference/production-containers#switch) replaces that file and the live link together and reloads Caddy once. Caddy's graceful reload lets each request in progress on the previous live finish. A failed reload puts the previous files back, so live serves unchanged. A Cluster Route needs no Router change.

**Handover.** After the new Route is verified, move timers and Processes and run the new live’s after-activation steps. Wait for the previous live’s in-flight work, disable its autostart and stop it as old with `incus stop`. Remove the earlier old only after its replacement is retained. See [Handover](/reference/production-containers#handover).

**Rollback.** Start the stopped old. Apply the current environment, verify its private health and switch traffic. Drain and stop the rolled-away live as old; a second rollback goes forward again. Rollback uses the current database as it is. The preparation container stays asleep. Rollback fetches no code, runs no deployment steps, pauses automatic deploys and blocks the rolled-away commit. Without a retained release it refuses with `rollback.release_not_retained`. A deeper rollback is a deploy of the selected revision.

### 5. Workers, Schedules and Reverb run only in live

Only live has autostart, application workers, Processes and timers enabled. Preparation installs disabled units, so a new clone starts with these disabled. The handover drains and disables them on the former live before enabling them in the new live.

- **Processes.** Each Process record of the Instance has a unit in every release container. Only live runs it. `process:*` commands act on live.
- **Per-minute schedulers.** A Schedule with a systemd calendar, such as the Laravel scheduler, is a [container timer](/reference/schedules#container-timers) in each release container, enabled only in live.
- **Native Schedules.** A cron Schedule stays a native Swamp Schedule. Each fire reads the Instance's live link on the Node and runs its command in that container as `orbit`. Retire waits for a fire that still runs in the previous live. One link names one container, so a fire never runs twice.
- **Reverb.** Orbit runs one shared Reverb outside every Instance. The Route sends only the Pusher `/app` and `/apps` paths to it, so a switch needs no Reverb handover. A Caddy reload can end open WebSocket streams; Pusher clients reconnect by themselves.
- **Queue payloads.** Jobs that the new release queues before the handover can reach a worker of the previous release, and a rollback runs old workers on new jobs. So a job's payload follows the expand and contract rule across one release.

### 6. Persistent storage is one custom volume per Instance

Data that must survive a release never lives on a release container's root disk. Each production Instance has one Incus filesystem custom volume, `instance-<id>-shared`, on the Node's pool. Orbit attaches it in every release container: the preparation container and its release clones share it, as Incus permits for a filesystem volume on one Node. Each [shared path](/reference/deployments#shared-paths) of the Instance, `storage` by default, is a link from the application directory into the volume. Uploads, Passport keys, logs and a SQLite database live there.

`instance:clone` creates the volume, and writes a seeded SQLite database into it. A migration copies each shared path of the home into it before the preparation container’s build steps run, so a step that makes keys only when they are missing finds the existing keys. After the switch it copies the changes again, and never deletes a file in the volume. Instance removal removes the volume with the Instance. A Project may move uploads to object storage such as S3 as its own choice; the volume stays for the rest.

### 7. PowerSync stays outside every deploy

PowerSync runs on the Services Node as Node Processes and replicates from the shared database. A production deploy or rollback:

- never starts, stops or changes a Process on the Services Node;
- never drops or changes a replication slot or a publication. A publication change is a change of the PowerSync configuration, not a migration;
- changes a table that a publication covers only by expand and contract, like every other table;
- keeps the token and upload endpoints of the app answering, because the switch is one Caddy reload with the previous live finishing its requests.

### 8. Capacity: an optional limit and an admission check

Each production Instance can set a memory limit; the default permits a busy release to use the Node’s available memory. Three containers persist between releases: the preparation container, live, and old when one exists. During deployment hot briefly adds a fourth. Reserve disk, RAM and swap across all deploying Instances, taking account of the time between starting hot and reclaiming preparation memory. See [capacity](/reference/production-containers#capacity).

Check capacity before waking preparation. Refuse insufficient RAM, swap or pool space with `deployment.node_capacity`. Automatic deployment retries capacity refusals without counting them against the commit.

### 9. PHP: the static glibc build, pinned per release

FrankenPHP serves every release container, in classic mode, from the runtime folder's static glibc PHP build. Production PHP never loads PCOV or PHP Data Bridge. Each release pins the runtime folder version it was built with: its container mounts that version, and its runtime row records it. A runtime folder update on the Node restarts no production container. It reaches production only through the next deploy. The Node keeps each runtime version that a release container records.

### 10. Next steps

The build follows the production gate below. The planned charts in part 1 are the specification of each command. The [production containers](/reference/production-containers) page links here and holds no copy of this list.

#### Production gate

Before production implementation, confirm these proposed criteria and record four weeks of development evidence: no FrankenPHP-specific failure that PHP-FPM would avoid; working PHP Data Bridge and development Processes; and default-Instance median and 95th-percentile response times no worse than the measured PHP-FPM baseline. If the gate fails, decide the production server again. This cleanup records no such acceptance or live rollout.

Operational acceptance must exercise the managed path, not the spike scripts: changed dependencies, compatible migrations, a long request during switching, bad-candidate refusal, sessions, shared-volume persistence, old hashed assets, code rollback, and one owner for workers and schedules. Test real object storage when configured or claimed supported. Cover Node restart, failed proxy reload, disk/memory pressure and interruption on both sides of the switch. Record cold, warm and dependency-change timings from admission to HTTPS separately from drain completion, along with toolchain, cache and load. Retained releases consume disk; prove retention and cleanup without deleting the serving or rollback release. Repeat provider-specific provisioning and cleanup before claiming support.

#### Build subtasks

Each subtask is one PR of a task group that follows this one, in this order. Each one adds its errors, Pest tests and documentation, and moves its chart from planned to built.

1. **Production Node and image.** The container branch of the `app-prod` role: Incus, the pool, `orbit-releases`, the bridge and the runtime folder with the static glibc PHP. The production image has the user `orbit` only, no agent and no model route.
   - Removes: nothing yet. PHP-FPM stays for checkouts until subtask 10.
   - Proof: converge a fixture `app-prod` Node twice, with no change the second time. Launch the image and check its users, and that PHP loads no PCOV.
2. **Records.** `release_state` with at most one row per state, `runtime_version`, the storage volume of an Instance, and the Caddy site of a production Route that imports the upstream file.
   - Removes: nothing.
   - Proof: Gateway Pest tests of the constraints, the admission reads and the projections.
3. **Clone.** `instance:clone` records the Instance, creates the storage volume with the `503` upstream file, publishes the preview Route and accepts a container candidate.
   - Removes: the home preparation of a clone, the checkout candidate rule and the Node timers that a clone installs.
   - Proof: Pest tests and the cases of `create-production`; a clone of the fixture Project.
4. **Build and clone.** Check capacity, wake preparation, select the exact source and environment, and execute the Instance’s before steps. Verify the build and artifact digest, seal and clone hot, then freeze preparation. Expose `deployment.node_capacity`, `deployment.selection_invalid` and `deployment.step_failed`. Document the [storage rule](/reference/production-containers#storage) for Project steps.
   - Removes: nothing yet. The home branch of the deploy workflow stays for checkouts until subtask 10, listed in [old paths](/reference/old-paths).
   - Proof: The fixture fails preparation before cloning, retries with a clean build, and produces a candidate of the exact target revision and artifact. Live continues serving.
5. **Ready, switch and retire.** Readiness, the switch with its previous files, the after steps, retire and the read-back, with `deployment.not_ready` and `deployment.switch_failed`.
   - Removes: nothing yet.
   - Proof: Pest tests and the switch cases of `deploy-production`; the first and second deploys of a fixture with no Process or Schedule.
6. **Work on live.** Preparation installs disabled units and timers. Only live enables its application units, timers and autostart. Native Schedule fires and Process commands resolve the live link.
   - Removes: production Node timers, and `restart_on_deploy` for container Instances.
   - Proof: Pest tests of the handover, the Schedule workflow and the Process commands; a fixture deploy in which each Schedule fires once a minute and each worker runs in live only.
7. **Automatic tick.** The tick's deployed commit is live's commit, and its deploy is the hot deploy. `deployment.node_capacity` gets a retry time and never counts toward a block.
   - Removes: nothing.
   - Proof: Pest tests of the policy inputs and the retry rule; the capacity case on the fixture.
8. **Rollback.** `instance:rollback` returns to old, with no `--release`.
   - Removes, for container Instances: the `release` argument, the `current` swap and the PHP-FPM refresh of a rollback, and the home scan of `instance:release:list`.
   - Proof: Pest tests and the cases of `rollback-production`; a rollback and a forward rollback on the fixture.
9. **Migrate a production checkout.** `instance:migrate` for a checkout production Instance, and the last copy and home removal in the first deploy after it. Build requirements:
   - Every copy after the first copies only files that the volume lacks or holds in an older version, and deletes none.
   - The last copy runs only after the native Schedules fire in live.
   - Removes: nothing yet.
   - Proof: Pest tests and the cases of `migrate-production`; the migration case of the live proof.
10. **Remove the checkout path.** After the last production Instance has moved: the home branch of the deploy and rollback workflows, PHP-FPM in the `app-prod` role, the production account, the release layout marker and their old-paths entries.
   - Removes: the whole checkout production path.
   - Proof: CI; a converge of the fixture Node shows no PHP-FPM, and no workflow names `current`.

The [live proof](#live-proof-on-a-fixture-production-project) runs after subtask 9 and before any real production Instance moves. A defect it finds is fixed in the subtask that owns it.

#### Live proof on a fixture production Project

The fixture is a small Laravel app, cloned to production on a fixture `app-prod` Node with `instance:clone`. It has:

- a queue worker Process, such as Horizon, and a job that writes its id and release name;
- the Laravel scheduler as a per-minute container timer, and one native cron Schedule; each fire writes its time and release name;
- an upload form that writes into `storage`, and Passport keys made only when missing;
- a PostgreSQL database on a Database server, with one migration per case;
- a page that subscribes to a channel on the shared Reverb, and an event that the worker broadcasts.

During every case except the sleep case, a load loop sends a request to the health path and to one page every 100 ms through the public Route, and records each status. The loop also holds one request that takes 10 seconds across each switch.

| Case | What it does | What it measures | Passes when |
| --- | --- | --- | --- |
| First release | Deploy into a new preparation container and clone the finished build | Admission to first healthy response | The candidate serves the expected commit; Schedules get their triggers |
| Warm deploy | A deploy of a commit with no dependency change | Clone time, time to the first new response, failed requests | No failed request; the 10-second request ends `200` on the previous live; the new commit serves after the switch |
| Dependency change | Change the Composer and npm lock files | Build and clone times; writes into shared storage | Live serves its old dependencies until the switch; the preparation build changes no shared view or cache |
| Expand migration | A commit that adds a nullable column and code that uses it | Failed requests during the migration | Live serves through the migration with no failed request |
| Failed migration | Fail a migration before activation | Serving revision and recorded failure | Live serves through the failure; the tick blocks the commit; partial build output cannot be promoted |
| Failed readiness | A commit whose health path answers `500` | The outcome | `deployment.not_ready`; hot is removed; live serves unchanged |
| Capacity refusal | A memory limit set above the Node's free memory, then removed | The tick's results | `deployment.node_capacity` with nothing made; the commit is not blocked; the tick deploys it after the limit is removed |
| Rollback and forward | `instance:rollback`, then `instance:rollback` again | Time to the first response of each release, failed requests | Each switch has no failed request; the workers and timers run only in the new live; automatic deploys are paused and the commit is blocked |
| Node reboot | Reboot between deploys | First visit, cold rollback and next preparation wake | Only live boots automatically; rollback and the next build work from their retained filesystems |
| One fire | Every case above, read from the fire records | Fires per Schedule per minute | Each Schedule fired once each minute: no minute twice and none skipped, except while the Node rebooted, and for a container timer while live slept ([ADR 0265](/decisions/0265-sleep-idle-production-containers)) |
| Sleep and wake | The sleep-and-wake checks of [ADR 0265 part 11](/decisions/0265-sleep-idle-production-containers#11-rollout), with the load loop paused | Hold time; fire, job and release records | Every check of ADR 0265 part 11 passes |
| Jobs across the switch | Jobs dispatched without pause through a deploy and a rollback | Jobs run, and the drain time | Every job ran once; the drain ended within 120 s |
| Uploads survive | An upload before a deploy, one in hot's time and one after a rollback | The files in the volume | Every upload reads back from live after each deploy and rollback; the Passport keys did not change |
| WebSocket | The page stays open through a deploy | Reconnect time, and the event after the switch | The client reconnects by itself and gets the event |
| Checkout migration | A second fixture Instance runs as a checkout with uploads and both Schedules. `instance:migrate` moves it; then it deploys once | Failed requests, the volume, the fires | No failed request; every upload is in the volume; a file that live changed keeps live's version; one fire a minute; the deploy removes the home |
| Disk | Deploy five revisions using one preparation container | Peak pool use, swap and retained releases | Old is stopped and holds no swap; preparation retains only its measured swap; retired containers and transient snapshot names are pruned |

The proof passes when every case passes in one run, in this order, on one build. A failed case is fixed in its subtask, and the whole run repeats. No case waits for a soak window or an approval.

#### Real production Projects

After the proof passes, the real production Projects move one at a time, each with `instance:migrate`: hauzer first, then mealou.

- **Before the move.** Every migration since the Project's last release follows expand and contract. The move builds the commit that the checkout serves, so the code does not change.
- **Rollback ready.** A failure before the switch leaves the checkout serving. After the move, the first deploy keeps the moved release as old, so `instance:rollback` returns to it in seconds. Until that deploy there is no old, and a fault after the switch is fixed forward with a deploy; the home stays on the Node.
- **One test.** After the move and after its first deploy, the Project's health path and its main page answer through the Route, and its workers and Schedules run in live.

mealou moves after hauzer's first container deploy passed. Neither waits for a window.

## Rejected alternatives

- **Build in a clone of live’s snapshot.** Each Instance instead retains one warm build environment. This keeps the preparation lifecycle independent of application traffic and supports the same build-then-clone path as the Gateway.
- **A clone of live as it runs now.** It would carry every runtime change of live, such as caches and files that requests wrote, into the next release.
- **A cold build from the image for every deploy.** Every deploy would install all dependencies again. Orbit uses it only when there is no warm source or the image changed.
- **Deploy in place in live, as today.** No release would be isolated, a failed build would touch the serving container, and a rollback would need retained directories again.
- **A database copy per release.** One shared database is the production state; a copy per release would split writes.
- **Migrations after the switch.** The new code would serve on the old schema until they end.
- **Undo a migration on rollback.** Older code can need a data recovery that only the application knows. Rollback selects code only.
- **Remove the previous live after the handover.** A rollback after the drain would need a new build, and the disk gain is small: Incus keeps a removed container's dataset while a clone still depends on it.
- **A default memory limit per release container.** The owner wants a busy release to use the Node's memory. An Instance can still set a limit.
- **Keep old running, or frozen to swap.** A running old holds memory for a rollback that a start of a stopped container serves in seconds. A freeze needs swap and a memory reclaim check after the switch, so a Node without swap would fail the release after its traffic already moved.
- **Keep more releases or snapshots.** Each kept release holds a ZFS chain and disk. A pinned deploy of an earlier commit covers the rare deeper rollback.
- **Publish an image of each release for rollback.** Publishing an image copies files.
- **Units enabled in every release container.** A start of old for a rollback would start its workers and timers while live still runs its own.
- **Start workers in hot before the switch, or in both.** Jobs and Schedules would run twice, or run new code before its traffic.
- **Storage on the container's root disk, or a copy per release.** Uploads would go with a removed container, and a copy per release copies files.
- **Bind-mount the home's storage into the containers.** It would keep the home alive as a second production layout.
- **Wait for capacity on the Node.** A waiting run holds the Instance and hides the cause. A refused deploy with a retry is visible and lets a manual action in.
- **Check readiness through the public Route.** The Route still serves live until the switch; only a request to hot's bridge address tests hot.
- **One shared preparation container on an app-prod Node.** Each production Instance owns its builds and rollback state. Two Instances may deploy the same commit with different environment and storage.

## Consequences

- One busy release container can use all of the Node's memory unless its Instance sets a limit. The owner accepts this.
- A deploy never stops traffic: live serves until the switch, and the switch is one Caddy reload. The PHP-FPM restart of today goes.
- A failed build, migration or readiness check never touches live. Hot is removed.
- Every migration must be expand-only, with the contract in a later release, and every queued job payload must stay readable by the release before it. This is a rule for each Project, not something Orbit can check.
- A rollback goes back one release in seconds, as long as old exists. A deeper rollback is a deploy of an earlier commit.
- Between deploys each Instance retains preparation, live and previous live once one exists. Preparation and previous live sleep; a deploy briefly adds the candidate as a fourth root disk.
- The operation removes the transient build snapshot after the independent clone exists. Incus retains underlying storage blocks while any release still references them.
- A Project that caches its configuration in a deploy step serves that cache after a rollback, with the values it cached.

## Affects

- Components: apps/gateway, apps/cli, packages/php-sdk, apps/docs
- ADRs: [0250](/decisions/0250-run-instances-and-task-groups-in-incus-containers), [0253](/decisions/0253-patch-nodes-and-reboot-them-drained), [0265](/decisions/0265-sleep-idle-production-containers)
- Detail: [Production containers](/reference/production-containers), [Instance releases](/reference/deployments), [Instance cloning](/reference/instance-cloning), [Schedules](/reference/schedules)
- Verify: Gateway Pest tests of the records, admission and projections; the cases of the production charts; the live proof on a fixture production Project
