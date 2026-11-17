---
title: "Production containers"
description: "Planned, ADR 0263: a production Instance's releases are Incus containers on its app-prod Node. Hot builds and migrates the next commit, live serves, old stays stopped for rollback. One Caddy reload switches the Routes."
---

# Production containers

**Planned.** Nothing on this page is built. It is the target contract of [ADR 0263](/decisions/0263-run-production-releases-in-hot-live-and-old-containers). Until it is built, production Instances deploy as [Instance releases](/reference/deployments) describes.

A production Instance keeps one record: its id, Routes, environment, deploy steps, attachments, Processes and Schedules. Each release of it is an Incus container on its `app-prod` Node, made with the same machinery as [Instance containers](/reference/development-containers): the image, ZFS clones and snapshots, the runtime folder, the bridge and the egress proxy. A production container has only the user `orbit`, no agent and no model route. An idle live [sleeps and wakes](#sleep-and-wake) as a development container does ([ADR 0265](/decisions/0265-sleep-idle-production-containers)).

## Release states

Each release container has one release state.

| State | Container | Runs |
| --- | --- | --- |
| `hot` | A ZFS clone of live's `release` snapshot that builds the next commit. It exists only during a deploy | FrankenPHP, with no traffic, Process, timer or Schedule fire |
| `live` | The release that serves | FrankenPHP for every Route of the Instance, the Processes, the container timers and the Schedule fires |
| `old` | The release before live, stopped | Nothing |

Between deploys, live runs or [sleeps](#sleep-and-wake), and old is stopped. A deploy adds hot, so it has at most three containers, and only hot and live run. When hot is ready, it becomes live, the previous live becomes old, and the earlier old is removed.

## Records

The Gateway keeps the Instance and its release states. The Node keeps the containers, the storage and the two files that say which container is live.

| Record | Where | Content |
| --- | --- | --- |
| The Instance | Gateway | Id, Routes, environment, deploy steps, attachments, Processes, Schedules, shared paths, and an optional memory limit: unset means no limit |
| Runtime rows | Gateway, `instance_runtimes` | One per release container: container name, bridge address, `release_state`, release name, commit, image fingerprint, `runtime_version`, `power_state` |
| Deployment history | Gateway | One row per deploy or rollback, as [History](/reference/deployments#history) describes. `release` names the release container that the deploy built |
| Release container | Node | `instance-<id>-<release>` in the Incus project `orbit-instances`, in the [`instance-<id>` form](/reference/development-containers#the-machine-name). The release name follows from the deploy Operation, as today |
| Release snapshot | Node | The snapshot `release` of each release container, taken after its deploy steps and before any request. It holds that release's code, dependencies and `.env` |
| Storage volume | Node | The filesystem custom volume `instance-<id>-shared`, mounted at `/srv/shared` in every release container |
| Live link | Node | `/var/lib/orbit/production/<id>/live`: the name of the live container |
| Upstream file | Node | `/var/lib/orbit/production/<id>/upstream.caddy`: live's bridge address and port 8080. The Caddy site of each Route of the Instance imports it |

The database keeps these rules:

- An Instance has at most one runtime row in each release state.
- A production runtime row has no T3 port, container server, clone source or seed commit.
- Only a deploy, a rollback, a migration and Instance removal change a release state.

## Deploy

`instance:deploy`, the API and the [automatic tick](/reference/deployments#automatic-deployments) start the same deploy. Admission is the same as today: the deploy holds the Instance, freezes its intent and records a running history row. The intent names live and its snapshot, the image, the runtime folder version, the rendered `.env`, the steps, the Processes and timers, the health path and the domain of each Route. The planned chart is `docs/cli/instance/container/deploy-production.mmd`.

### Build hot

The first part builds hot while live serves.

1. **Check.** The Node's live link must name the live container of the intent. Otherwise the deploy fails with `deployment.selection_invalid`. Then the [capacity check](#capacity) runs.
2. **Make hot.** Orbit clones live's `release` snapshot into a new release container. A [cold hot](#cold-hot) launches from the image instead.
3. **Prepare hot.** Orbit attaches the storage volume and mounts the runtime folder version that the Node pins now. It sets the Instance's optional [memory limit](#capacity) and starts hot.
4. **Upgrade.** Hot applies the Node's pending package upgrades.
5. **Source.** The Node's mirror fetches the commit: the pinned one, or the branch head for a manual deploy. Orbit pushes it into hot and checks it out. Untracked dependency folders stay.
6. **Links and environment.** Orbit links each shared path into the volume and writes the rendered `.env`.
7. **Before steps.** Each `before_activation` step runs in order as `www-data` at the repository root, after the [Vite+ preparation](/reference/deployments#vite-store-prerequisite) that a step needs. The `migrate` step changes the shared database here. Live still serves.
8. **Install and snapshot.** Orbit installs a disabled unit for each Process and a disabled container timer for each systemd-calendar Schedule. It restarts FrankenPHP and takes hot's `release` snapshot.

A warm hot carries live's `.env` from its clone until step 6 writes the new one, because live's snapshot holds it. Secrets never come from the image or the mirror.

### Go live

The second part moves traffic and work to hot.

1. **Readiness.** See [Ready](#ready).
2. **Switch.** See [Switch](#switch).
3. **Handover.** See [Handover](#handover).
4. **After steps.** Each `after_activation` step runs in order, in the new live.
5. **Retire.** See [Retire](#retire).
6. **Check.** Orbit reads back the live link, the upstream file, the commit and the Process states.

The Gateway records the release states, the commit and the Process outcomes from the run's result.

### Cold hot

Hot launches from the image instead of a clone in three cases: the Instance has no live release, the Node's image is newer than live's image, or a [migration](#a-checkout-production-instance) builds the first release. A cold hot installs every dependency. It also starts a new ZFS clone chain.

## Ready

Hot is ready when all of these hold:

- Every `before_activation` step passed and the `release` snapshot exists.
- `orbit-frankenphp.service` is active in hot.
- The Instance's health path answers `200` three times in a row. It is `/up` for a Laravel app and `/` otherwise.
- Each request has 5 seconds, and all three have 60 seconds.
- Hot's PHP loads neither PCOV nor PHP Data Bridge.

Orbit sends each request from the Node to hot's bridge address on port 8080. The request has a Route's domain as `Host` and `X-Forwarded-Proto: https`. The three requests also warm OPcache.

## Switch

The switch writes the new upstream file and the new live link beside the current ones and validates the Node's Caddy configuration with them. Then it renames both into place, keeps the previous files, and reloads Caddy once. New requests go to the new live. Requests in progress on the previous live finish there. A Cluster Route needs no Router change, because the Router still sends to the same Node.

When the validation fails, nothing was renamed. When the reload fails, Caddy keeps its running configuration, and Orbit renames the previous files back. In both cases the files name live again, and live serves unchanged.

The shared Reverb is outside the containers, so the switch needs no Reverb handover. A Caddy reload can end open WebSocket streams through the Node; Pusher clients reconnect.

## Handover

One rule keeps work in one container: a release container's Process units and container timers are enabled only while it is live, and only live has the Incus setting `boot.autostart` on. Hot installs its units and timers disabled. So a Node reboot brings back live with its Processes and timers, and a start of old or hot runs none of them.

After the switch, Orbit moves the work from the previous live to the new live, in this order:

1. It disables and stops the previous live's container timers, and a running timer fire finishes. Then it enables and starts the new live's timers.
2. It disables each Process unit of the previous live and stops it gracefully, within 120 seconds: Horizon terminates and queue workers finish their current job. Then systemd ends the rest.
3. It enables and starts each Process of the new live whose desired state is `running`.
4. It turns `boot.autostart` on for the new live and off for the previous live.

A native Schedule fire reads the live link when it starts and runs its command in that container as `www-data`, as a transient unit named for its Schedule. No Process, timer or fire runs in two release containers.

## Retire

Retire waits until no native Schedule fire runs in the previous live, up to that Schedule's timeout. Then it stops the previous live, which becomes old, and removes the earlier old.

Incus keeps the removed container's dataset while a ZFS clone still depends on it, so the removal frees disk only when no later release needs its blocks.

## Rollback

`instance:rollback <instance>` returns to old. The planned chart is `docs/cli/instance/container/rollback-production.mmd`.

1. Orbit writes the Instance's current `.env` into old and starts it. Its units and timers are disabled, so nothing but FrankenPHP runs.
2. Orbit checks old as [ready](#ready), without the snapshot condition: old already has its snapshot.
3. The [switch](#switch) and the [handover](#handover) run as in a deploy.
4. [Retire](#retire) stops the rolled-away live, which becomes old. No container is removed.

A rollback runs no deploy step, fetches nothing and changes no data. It pauses automatic deploys and blocks the rolled-away commit, as [Pause](/reference/deployments#pause) describes. Without an old release, it refuses with `rollback.release_not_retained`. To go back further, deploy the earlier commit with `instance:deploy --commit`. That deploy builds a warm hot from live's snapshot.

A rollback has no capacity check. No deploy runs beside it, so old runs in the place that hot takes during a deploy.

| Failed boundary | Serves | Old |
| --- | --- | --- |
| Start, readiness or switch | Live, unchanged, with its previous files back in place after a failed switch | Stopped again, and stays old |
| Handover or retire | Old, as the new live | Is live. The Gateway records the release states that the run reached |

## Sleep and wake

**Planned** ([ADR 0265](/decisions/0265-sleep-idle-production-containers)). An idle live sleeps and wakes fully ready, by the rule of [Sleep and wake](/reference/development-containers#sleep-and-wake): the same idle window of one hour, sweep, `instance.sleep` and `instance.wake` Operations, reasons and dry run. The whole container sleeps, with every Process and timer. The fast wake and the [prepared restore](/reference/prepared-restore) apply; the `app-prod` role installs the power helper and the wake worker.

| Release state | Sleeps |
| --- | --- |
| `hot` | Never (`not_live`). It exists only during a deploy, and the open Operation keeps the Instance awake too |
| `live` | After an idle hour, when no reason keeps it awake |
| `old` | Never. It stays stopped and holds no memory and no swap (`not_live`) |

**Swap.** Every release container gets `memory.swap.max` 0 while it runs, also without a memory limit. A sleep opens swap, and a wake closes it again.

**No stop.** A production sleep never stops live. When swap cannot take the memory, the sleep resumes live, closes swap, removes the marker and fails with `instance.container_freeze_failed`. The sweep keeps live awake for one idle window with `swap_full`. Live keeps `boot.autostart`: a Node reboot boots it awake. The boot removes the asleep marker, the Gateway records `power_state` `running`, and a fresh watch starts.

**Traffic.** Each request through any Route of the Instance, a Cluster Route included, writes the Instance's access log on the Node's Caddy. A request with `X-Orbit-Probe: 1`, such as readiness, does not count. An uptime check without that header keeps live awake.

**The visitor.** A production visitor never gets the progress page. When a request meets the asleep marker, Caddy calls the activation endpoint, and the Gateway admits or joins the wake and answers at once. Then the Node's Caddy holds the request: it tries the live upstream again until the marker is gone, and proxies the request to live.

The targets are about 1.5 s for a warm wake with the prepared restore, and 2.4 s for a cold one, in which pages come back from swap one fault at a time. After 10 s, Caddy answers `503` with `Retry-After: 5` and a plain page that names the Instance.

A failed wake is stored as in development. While it is stored, Caddy answers `503` with `Retry-After: 30` at once. The first request 30 s or more after the failure starts one new wake by itself; `instance:wake` retries at once. The hold takes no Gateway worker, but a wake needs the Gateway to admit it.

**Schedules and workers.**

- A due native cron Schedule wakes live, waits for the wake and runs. This wake does not count as activity. A fire whose Swamp run has not ended keeps live awake (`busy`).
- Each container timer pauses with live. A timer that came due during the sleep fires once at the resume: a per-minute scheduler skips the missed minutes, and a daily timer fires late.
- Queue workers pause with live, and queued jobs wait for the next wake.
- A job that ran at the freeze can run twice after the wake. Its queue reservation expires, and another worker takes it.
- A Project whose jobs or scheduler must run without visitors sets the Instance's `never_sleep` field with `instance:update`, or gives a Process `keep_alive`. Either keeps the whole container awake.
- An Instance with an attached Node service, such as PowerSync, stays awake (`node_service`). A sleep never touches a Process on the Services Node.

**Deploy and rollback.** A deploy does not wake a sleeping live: hot clones live's `release` snapshot. A visit during the deploy wakes live as at any other time.

The [switch](#switch) removes the asleep marker in the same step as it replaces the upstream file and the live link, once the Caddy reload succeeded. A failed switch keeps the marker, or puts it back.

The switch, the wake and the handover's resume take the Instance's power lock, and a wake acts only on the container that the live link names. The new live starts its own watch of one idle window. The [handover](#handover) resumes a frozen previous live before it stops its units, because a clean stop of a frozen container times out; then [retire](#retire) stops it as old. A rollback does the same with the rolled-away live.

## Database

Every release container uses the Instance's one shared database. Migrations run in hot before the switch, so live's code must work on the migrated schema:

- Each schema change follows expand and contract. A change that live cannot read is split over two deploys.
- A migration changes the schema, or changes data with raw queries. It never loads application models or domain code. It is tested against the schema of the last released commit.
- Queued job payloads follow the same rule across one release. A worker of the previous release can read a new job, and so can old after a rollback.

Orbit never undoes a migration: not after a failed deploy and not on rollback.

PowerSync replicates from the shared database on the Services Node. A deploy or rollback never changes a Process on the Services Node, never drops or changes a replication slot or a publication, and changes a published table only by expand and contract. The app's PowerSync token and upload endpoints keep answering across the switch.

## Storage

The storage volume holds what must outlive a release: the shared paths, `storage` by default, and a SQLite database when the Instance has one. Each shared path is a link from the application directory into `/srv/shared`. Hot, live and old all mount the volume. Nothing on a release container's root disk survives its removal.

`instance:clone` creates the volume. Instance removal removes it with the Instance.

Hot's `before_activation` steps run while live serves, and both mount the same volume. Laravel names a compiled view after its path inside the app, so a view that hot compiles into `storage/framework/views` replaces the one that live serves. So each Project keeps this rule for its deploy steps: a `before_activation` step writes no compiled view or cache into a shared path; `optimize` and `view:cache` are `after_activation` steps. Orbit cannot check it; the [live proof](/decisions/0263-run-production-releases-in-hot-live-and-old-containers#live-proof-on-a-fixture-production-project) proves it on the fixture.

## Capacity

A release container has no memory limit by default, so one busy release can use all of the Node's memory. The Instance's memory limit is optional. When `instance:update` sets it, every release container of the Instance gets it. During a deploy, hot runs, live runs or sleeps, and old is stopped. A clone's disk holds only its changed blocks.

Before it makes hot, a deploy or a [migration](#a-checkout-production-instance) checks two things. Otherwise it fails with `deployment.node_capacity` and changes nothing.

- The Node's available memory covers hot's need plus 1 GiB.
- The pool has 10 GiB free.

Hot's need is the Instance's memory limit, if one is set. Without a limit, hot's need is live's current memory use, because hot runs the same app. For a sleeping live, it is the use that live's last sleep recorded before the freeze, and the available memory must also cover that use, because a visit or the handover can resume live during the deploy. For a migration, the checkout's PHP-FPM pool and Processes take the place of live.

The automatic tick retries that failure at its [backoff](/reference/deployments#failures-of-automatic-deployments) times, but it does not count toward the three failures that block a commit: the cause is the Node, not the commit. A commit that waits 30 minutes raises the stalled-commit alert.

## PHP runtime

FrankenPHP serves every release container in classic mode, from the runtime folder's static glibc PHP build. Each release pins the runtime folder version it was built with and records it in its runtime row. A runtime folder update on the Node restarts no production container; it reaches production with the next deploy. The Node keeps each version that a release container records.

## First release and migration

Two planned charts cover the two ways to the first release container: `docs/cli/instance/container/create-production.mmd` for a clone and `docs/cli/instance/container/migrate-production.mmd` for a checkout.

### After a clone

[`instance:clone`](/reference/instance-cloning) records the production Instance, its environment, its Process and Schedule copies, its preview Route and its storage volume. It makes no container. It writes the Instance's upstream file with a `503` answer, so the preview Route's site serves before the first release. With `--sqlite-source-path`, the clone writes the seeded database into the volume as `database.sqlite`, and the rendered `.env` names `/srv/shared/database.sqlite`. The first deploy builds a cold hot and switches the Routes to it. The Gateway sets the triggers of the native Schedules when that deploy records a live release.

### A checkout production Instance

[`instance:migrate`](/cli/instance/migrate) moves a checkout production Instance in these steps:

1. It runs the [capacity check](#capacity). A refusal is `deployment.node_capacity`. Nothing changes, and the checkout serves.
2. It launches a cold hot and attaches the volume.
3. It copies each shared path of the home into the volume, and a SQLite database of the home. The checkout still serves.
4. It builds hot at the commit that `current` selects, as a deploy does. The steps see the copied files, such as existing Passport keys.
5. It checks hot as ready and switches the Routes to it.
6. It disables and stops the host Processes, Node timers and PHP-FPM pool. Then it enables and starts the new live's Processes and timers.
7. It copies the changes again: only files that the volume lacks or holds in an older version. It deletes no file in the volume, because live already writes there.

Each native Schedule keeps firing in the home until the run ends. Then the Gateway rewrites its workflow for the live link and sets its trigger again, as an [Instance transfer](/reference/schedules#execution) does. No fire is skipped and none runs twice.

The home stays unserved until the next successful deploy. That deploy runs after the native Schedules fire in live, so nothing writes into the home any more. It copies the shared paths a last time, by the rule of step 7, and then removes the home, its stopped PHP-FPM pool and the production account.

## Failures

The failed boundary decides what serves.

| Failed boundary | Serves | Hot |
| --- | --- | --- |
| Check, capacity, clone, upgrade, source, links, environment, a `before_activation` step, install, snapshot or readiness | Live, unchanged | Removed |
| Switch | Live, unchanged, with its previous files back in place | Removed |
| Handover, an `after_activation` step or retire | The new live | Is live |

A failed migration keeps what it changed. A failure in a deploy step or later blocks the commit for the automatic tick. A failure before the deploy steps is retried with backoff, and only `deployment.node_capacity` never counts toward the block.

## Build and live proof

[ADR 0263 part 10](/decisions/0263-run-production-releases-in-hot-live-and-old-containers#10-next-steps) lists the build subtasks and the live-proof plan on a fixture production Project.

## Errors

These codes are planned for the production container flows.

| Code | When |
| --- | --- |
| `deployment.node_capacity` | The Node lacks the memory or pool space for hot |
| `deployment.not_ready` | Hot did not pass [readiness](#ready) |
| `deployment.selection_invalid` | The Node's live link does not name the recorded live container, or a read-back differs |
| `deployment.step_failed` | A deploy step failed |
| `deployment.switch_failed` | Caddy refused or failed the switch |
| `rollback.release_not_retained` | The Instance has no old release |
