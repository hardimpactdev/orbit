---
title: "Production containers"
description: "Planned, ADR 0263: a production Instance's releases are Incus containers on its app-prod Node. Hot builds and migrates the next commit, live serves, old stays stopped for rollback. One Caddy reload switches the Routes."
---

# Production containers

**Planned.** Nothing on this page is built. It is the target contract of [ADR 0263](/decisions/0263-run-production-releases-in-hot-live-and-old-containers). Until it is built, production Instances deploy as [Instance releases](/reference/deployments) describes.

A production Instance keeps one record: its id, Routes, environment, deploy steps, attachments, Processes and Schedules. Each release of it is an Incus container on its `app-prod` Node, made with the same machinery as [Instance containers](/reference/development-containers): the image, ZFS clones and snapshots, the runtime folder, the bridge and the egress proxy. A production container has only the user `orbit`, no agent and no model route, and it never sleeps.

## Release states

Each release container has one release state.

| State | Container | Runs |
| --- | --- | --- |
| `hot` | A ZFS clone of live's `release` snapshot that builds the next commit. It exists only during a deploy | FrankenPHP, with no traffic, Process, timer or Schedule fire |
| `live` | The release that serves | FrankenPHP for every Route of the Instance, the Processes, the container timers and the Schedule fires |
| `old` | The release before live, stopped | Nothing |

A deploy has at most three containers: hot, live and old. Only hot and live run. When hot is ready, it becomes live, the previous live becomes old, and the earlier old is removed.

## Records

The Gateway keeps the Instance and its release states. The Node keeps the containers, the storage and the two files that say which container is live.

| Record | Where | Content |
| --- | --- | --- |
| The Instance | Gateway | Id, Routes, environment, deploy steps, attachments, Processes, Schedules, shared paths, memory limit (4 GiB by default) |
| Runtime rows | Gateway, `instance_runtimes` | One per release container: container name, bridge address, `release_state`, release name, commit, image fingerprint, `runtime_version`, `power_state` |
| Deployment history | Gateway | One row per deploy or rollback, as [History](/reference/deployments#history) describes. `release` names the release container that the deploy built |
| Release container | Node | `app-instance-<instance>-<release>` in the Incus project `orbit-instances`. The release name follows from the deploy Operation, as today |
| Release snapshot | Node | The snapshot `release` of each release container, taken after its deploy steps and before any request |
| Storage volume | Node | The filesystem custom volume `app-instance-<instance>-shared`, mounted at `/srv/shared` in every release container |
| Live link | Node | `/var/lib/orbit/production/<instance>/live`: the name of the live container |
| Upstream file | Node | `/var/lib/orbit/production/<instance>/upstream.caddy`: live's bridge address and port 8080. The Caddy site of each Route of the Instance imports it |

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
3. **Prepare hot.** Orbit attaches the storage volume and sets the memory limit. It mounts the runtime folder version that the Node pins now, then starts hot.
4. **Upgrade.** Hot applies the Node's pending package upgrades.
5. **Source.** The Node's mirror fetches the commit: the pinned one, or the branch head for a manual deploy. Orbit pushes it into hot and checks it out. Untracked dependency folders stay.
6. **Links and environment.** Orbit links each shared path into the volume and writes the rendered `.env`.
7. **Before steps.** Each `before_activation` step runs in order as `orbit` at the repository root, after the [Vite+ preparation](/reference/deployments#vite-store-prerequisite) that a step needs. The `migrate` step changes the shared database here. Live still serves.
8. **Install and snapshot.** Orbit installs a disabled unit for each Process and a disabled container timer for each systemd-calendar Schedule. It restarts FrankenPHP and takes hot's `release` snapshot.

### Go live

The second part moves traffic and work to hot.

1. **Readiness.** See [Ready](#ready).
2. **Switch.** See [Switch](#switch).
3. **Handover.** See [Handover](#handover).
4. **After steps.** Each `after_activation` step runs in order, in the new live.
5. **Retire.** The previous live stops and becomes old. The earlier old is removed, with its snapshot.
6. **Check.** Orbit reads back the live link, the upstream file, the commit and the Process states.

The Gateway records the release states, the commit and the Process outcomes from the run's result.

### Cold hot

Hot launches from the image instead of a clone in three cases: the Instance has no live release, the Node's image is newer than live's image, or a [migration](#first-release-and-migration) builds the first release. A cold hot installs every dependency. It also starts a new ZFS clone chain.

## Ready

Hot is ready when all of these hold:

- Every `before_activation` step passed and the `release` snapshot exists.
- `orbit-frankenphp.service` is active in hot.
- The Instance's health path answers `200` three times in a row. It is `/up` for a Laravel app and `/` otherwise.
- Each request has 5 seconds, and all three have 60 seconds.
- Hot's PHP loads neither PCOV nor PHP Data Bridge.

Orbit sends each request from the Node to hot's bridge address on port 8080. The request has a Route's domain as `Host` and `X-Forwarded-Proto: https`. The three requests also warm OPcache.

## Switch

The switch writes the new upstream file and the new live link beside the old ones, validates the Node's Caddy configuration with them, renames both into place and reloads Caddy once. New requests go to the new live. Requests in progress on the previous live finish there. A Cluster Route needs no Router change, because the Router still sends to the same Node.

The shared Reverb is outside the containers, so the switch needs no Reverb handover. A Caddy reload can end open WebSocket streams through the Node; Pusher clients reconnect.

## Handover

After the switch, Orbit moves the work from the previous live to the new live, in this order:

1. It stops the previous live's container timers, lets a running fire finish, and starts the new live's timers.
2. It stops each Process of the previous live gracefully, within 120 seconds: Horizon terminates and queue workers finish their current job. Then systemd ends the rest.
3. It starts each Process of the new live whose desired state is `running`.

A native Schedule fire reads the live link at each fire and runs its command in that container as `orbit`. No Process, timer or fire runs in two release containers.

## Rollback

`instance:rollback <instance>` returns to old. The planned chart is `docs/cli/instance/container/rollback-production.mmd`.

1. Orbit writes the Instance's current `.env` into old and starts it.
2. Orbit checks old as [ready](#ready), without the snapshot condition: old already has its snapshot.
3. The [switch](#switch) and the [handover](#handover) run as in a deploy.
4. The rolled-away live stops and becomes old.

A rollback runs no deploy step, fetches nothing and changes no data. It pauses automatic deploys and blocks the rolled-away commit, as [Pause](/reference/deployments#pause) describes. Without an old release, it refuses with `rollback.release_not_retained`. To go back further, deploy the earlier commit with `instance:deploy --commit`.

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

## Capacity

Each release container has the Instance's memory limit. During a deploy, hot and live run and old is stopped. A clone's disk holds only its changed blocks.

Before the clone, the deploy checks that the Node's available memory covers hot's memory limit plus 1 GiB, and that the pool has 10 GiB free. Otherwise it fails with `deployment.node_capacity` and changes nothing. That failure comes before any step, so the automatic tick retries it with its [backoff](/reference/deployments#failures-of-automatic-deployments).

## PHP runtime

FrankenPHP serves every release container in classic mode, from the runtime folder's static glibc PHP build. Each release pins the runtime folder version it was built with and records it in its runtime row. A runtime folder update on the Node restarts no production container; it reaches production with the next deploy. The Node keeps each version that a release container records.

## First release and migration

The planned chart `docs/cli/instance/container/create-production.mmd` covers both ways to the first release container.

### After a clone

[`instance:clone`](/reference/instance-cloning) records the production Instance, its environment, its Process and Schedule copies, its preview Route and its storage volume. It makes no container. The first deploy builds a cold hot and switches the Routes to it.

### A checkout production Instance

[`instance:migrate`](/cli/instance/migrate) moves a checkout production Instance in these steps:

1. It builds a cold hot at the commit that `current` selects.
2. It copies each shared path of the home into the volume. The checkout still serves.
3. It switches the Routes to the container and stops the host Processes, timers and PHP-FPM pool.
4. It copies the changes again, without deleting a file in the volume.

The home stays unserved until the next successful deploy removes it and the production account.

## Failures

The failed boundary decides what serves.

| Failed boundary | Serves | Hot |
| --- | --- | --- |
| Check, capacity, clone, upgrade, source, links, environment, a `before_activation` step, install, snapshot or readiness | Live, unchanged | Removed |
| Switch | Live, unchanged: Caddy keeps its configuration when validation or reload fails | Removed |
| Handover, an `after_activation` step or retire | The new live | Is live |

A failed migration keeps what it changed. A failure in a deploy step or later blocks the commit for the automatic tick; a failure before the deploy steps is retried with backoff.

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
