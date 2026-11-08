---
title: "Node settings"
description: "Typed Node settings for the apps root and operator-owned task VM hosts, with validation and explicit host configuration."
covers:
  - apps/gateway/app/Domain/Nodes/Storage/**
  - apps/gateway/app/{Actions/Nodes/UpdateNodeAction,Domain/Nodes/NodeSettingsOperationCompletion,Domain/Nodes/NodeSettingsProjection,Providers/TaskVmServiceProvider}.php
  - apps/gateway/app/Infrastructure/Nodes/AgentNodeStorageRootPreparer.php
  - apps/gateway/app/Data/Nodes/{NodeSettingsData,NodeStorageAppsData,NodeTaskVmsData}.php
  - apps/gateway/database/migrations/2026_12_03_000000_allow_cold_recipe_image_rebuilds.php
  - apps/gateway/app/Http/Requests/Nodes/UpdateNodeRequest.php
  - apps/cli/app/{Support/NodeSettingOptions,Commands/Nodes/UpdateNodeCommand}.php
  - packages/php-sdk/src/{Responses/Nodes/NodeSettings,Responses/Nodes/TaskVmsSettings,Requests/Nodes/UpdateNodeRequest}.php
---

# Node settings

A Node has typed settings for its development apps root (`apps.path`) and its task VM host configuration (`task_vms`). Set them with `node:add` or [`node:update`](/cli/node/update). The operator owns task VM host configuration; an `app-dev` role alone never makes a Node a task VM host.

A settings change that touches the apps root records a `node.update` Operation and returns HTTP 202. A change to `task_vms` alone, or clearing `apps.path` on a Node without an active `app-dev` role, is stored at once and starts no run. The [flow chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/node/update.mmd) shows both paths.

The Gateway starts `operation-node-update` on the `swamp` role's workers. Its inputs are the SSH target and the resolved apps folder with the managed user and group. Every step runs on Node 209 and reaches the Node over SSH with sudo.

The steps are:

- `Create the apps folder` runs the official `@swamp/cfgmgmt/directory` behavior. It creates missing folders and gives the apps folder the managed user and group with mode `0755`.
- `Check owner and mode` reads the folder with one `stat`. It is the end check.
- The Gateway reads its output from Swamp's run data.
- The folder must be a real folder of the managed user and group, with mode 0755.
- Any other output fails the task with `node.settings_root_failed`.
- The Gateway stores the settings only after that check passed.

The Gateway stores the settings only from a successful run. A failed or cancelled run keeps the previous settings, and folders made before a failure stay. A failure in any of these steps reports `node.settings_root_failed`; the failure detail holds the reason.

Gateway admission resolves the root and checks it: path grammar, protected paths, managed checkouts, and the managed home `/home/<user>` that Orbit creates. It refuses `/`, `/home`, any home folder itself (`/root` or a folder directly under `/home`), `/etc` and `/usr`. It performs no account lookup or filesystem action.

An action on an apps root requires Linux. Other platforms refuse before recording an execution intent. Patches that only change stored settings remain available outside the worker rollout.

State-only patches, such as task VM host intent, stay synchronous in the Gateway. Node enrollment keeps its synchronous storage preparation until its parent Operation cutover. A settings completion changes only the requested members and preserves other updates made while it was queued.

## Set the apps root

`node:add` takes repeatable `--setting=<setting-path>:<value>` options: `apps.path` for the apps root and `task_vms` for a JSON host configuration object. `node:update` takes `--apps-path` and `--task-vms`.

```bash
orbit node:add app-dev app-dev.example --role=app-dev --setting=apps.path:/srv/orbit/apps
orbit node:update app-dev --apps-path=/mnt/apps
```

`node:add` splits each option at its first colon, so a value can contain more colons. An empty value unsets the path: `--setting=apps.path:` and `--apps-path=` send null. The CLI does not trim a value.

`POST /api/v1/nodes` accepts an optional `settings` member:

```json
{ "settings": { "apps": { "path": "/srv/orbit/apps" } } }
```

`PATCH /api/v1/nodes/{node}` takes the members directly, without a `settings` wrapper, beside the optional `name` and `platform`:

```json
{ "apps": { "path": "/mnt/apps" } }
```

The [API reference](/api/overview), [MCP](/reference/mcp), and generated web types publish `apps` as an object or null, with `path` as a string or null, and `task_vms` as a typed object or null. The settings object and its nested objects reject unknown fields. The request needs at least one member; the `apps.path` member is optional. `"path": null`, `"apps": null`, or `"apps": {}` removes the path.

A patch changes only the members it names. Other stored keys stay, and the API never returns them. Node responses return the stored value only. They return `settings: null` when nothing is set, never the default. `orbit node:show` shows the path, or an em dash when none is set.

## Configure a task VM host

Task VM placement uses Incus hosts only. The fleet uses Beast and Shark. Set `task_vms` on the host Node, not on the disposable task VM Nodes that it runs. The object replaces `ORBIT_TASK_VMS_INCUS_HOSTS` as the source of Incus host configuration. It has no `node_id`: the Node that stores the setting supplies the identity.

The common converge brings a task VM on such a host to the pins of the release, on every base refresh and every task start. It reads no host setting. See [Converge a running task VM](/reference/compute-drivers#converge-a-running-task-vm).

```bash
orbit node:update beast --task-vms='{"cidr":"10.251.77.0/24","max_vms":12,"task_max_vms":2,"pool":"orbit-tasks","image_alias":"orbit-task-base"}'
```

This is an example, not the live configuration of beast. Preserve a host's current values when changing its configuration. The API patch takes the object directly:

```json
{
  "task_vms": {
    "cidr": "10.251.77.0/24",
    "max_vms": 12,
    "task_max_vms": 2,
    "pool": "orbit-tasks",
    "image_alias": "orbit-task-base"
  }
}
```

`POST /api/v1/nodes` accepts the same object inside `settings`. `"task_vms": null` removes host intent; an absent `task_vms` member leaves it unchanged. A non-null object must be a valid host configuration, not an empty marker. Keep the setting until all task VMs on the host are destroyed, then follow [host retirement](/reference/compute-drivers#add-a-task-vm-host).

### Task VM fields

Use these fields to describe a host's network, storage, resources, and capacity.

| Field | Required | Default | Meaning and validation |
| --- | --- | --- | --- |
| `cidr` | Yes | | Private IPv4 bridge network from `/16` to `/28`, not a host address; it must not overlap the VPN subnet or another host's bridge |
| `max_vms` | Yes | | Slot capacity, integer from 1 to 64; VM sizes consume one, two, or three slots |
| `task_max_vms` | No | `null` | Maximum VMs held by task work and operator proofs on this host, integer from 0 to 64; parked VMs count. Unset or null adds no limit. CI and base refresh proofs are exempt |
| `ci_only` | No | `false` | Boolean. Only authenticated CI proofs and this host’s own base refresh proof may use this host; CI prefers it over shared hosts |
| `project` | No | `orbit-tasks` | Incus project name |
| `network` | No | `orbittask0` | Bridge name: `orbittask` followed by 1 to 6 lowercase letters or digits |
| `pool` | No | `orbit-tasks` | Incus ZFS storage pool for images, root disks, topology seeds, and their clones |
| `image_alias` | No | `orbit-task-base` | Local base image alias used for task VM launches; a non-empty valid Incus alias. A host refresh moves it; the old image becomes `<alias>-previous` |
| `pool_size` | No | `640GiB` | Size in `MiB` or `GiB` of a new loop-backed ZFS pool when no dataset is configured; existing pools are not resized |
| `zfs_dataset` | No | `null` | New dataset below a ZFS pool, such as `fast/orbit-tasks`, used instead of a loop file when preparing a missing pool; never the whole ZFS pool or an existing dataset |
| `cpus` | No | `2` | vCPUs per normal VM, integer from 1 to 64 |
| `memory` | No | `4GiB` | Positive size in `MiB` or `GiB` for a normal VM |
| `disk` | No | `20GiB` | Positive size in `MiB` or `GiB` for root disks |
| `operator_owned` | No | `[]` | List of Incus instances and custom volumes excluded from stray reports, as `<project>/<name>` or `<project>/*` |

Unknown fields, invalid names, wrong types, and out-of-range values are rejected before storage. Integer fields are integers in JSON, not strings. Invalid configuration leaves the stored setting unchanged. The apps-root filesystem checks below apply only to `apps.path`, not to this object.

Each Large VM consumes three `max_vms` units. `task_max_vms` counts VMs, not units. With `max_vms=12` and `task_max_vms=2`, task work may hold two Large VMs and CI or base refresh proofs may use the remaining two places. Prep, build, review, provision and parked task VMs share the limit; only an authenticated CI proof or a base refresh proof is exempt. Lowering the limit removes no claim; new task work is refused with `tasks.capacity_unavailable` until the count falls below it. Shared hosts keep one Large place for authenticated CI proofs and refresh proofs; task work and operator proofs cannot take it. A CI-only host with `max_vms=6` admits up to two Large proof VMs. A task start takes the lowest free public slot number on its host, names the VM `<node>-tvm-<n>`, and mints a new Node and keys for it; the end of its group deletes the VM, the Node and the keys (chart `docs/cli/task/start.mmd`).

Placement selects active Nodes with a valid, non-null `settings.task_vms`. Requests and queued jobs take a fresh host-settings snapshot at the start of their lifecycle, so later jobs see changes without a worker restart. Capacity reads their `max_vms`; host preparation and the host sweep read the same configuration. Later package-cache targeting uses this host intent too; setting it does not install a cache. See [Compute drivers](/reference/compute-drivers#configure-task-vms) for runtime behavior. Being `app-dev` grants no host intent: `services` keeps that role for Recall and gets no task VMs.

### Migrate the environment host list

The Gateway reads Node settings only. It does not copy `ORBIT_TASK_VMS_INCUS_HOSTS`, and there is no runtime fallback to that environment list. Configure each host's `settings.task_vms` with the fields above.

Verify the stored settings before removing `ORBIT_TASK_VMS_INCUS_HOSTS` from `gateway.env`; use [`gateway:release:configure`](/reference/gateway-recovery#apply-an-env-change) to apply that removal.

A configured task VM host builds the nested fleet of its VMs in the fleet phase of the common converge, on a base VM that has none (see [Build the nested fleet in the converge](/reference/compute-drivers#build-the-nested-fleet-in-the-converge)).

## Derive the effective root

The apps root is `apps.path`, or `<managed-user-home>/apps` when the path is not set. The Gateway computes the default each time and does not store it.

A new development Instance checkout is `<apps-root>/<project-slug>/<instance-name>`. The Gateway stores that path on the Instance. A later change to `apps.path` never moves, rewrites, or deletes an existing checkout. [Doctor](/cli/doctor/doctor#what-each-family-checks) accepts a development checkout under the apps root or under the managed user's home.

## Validation

The Gateway checks path grammar and recorded exclusions before admission. Swamp checks the actual account and filesystem before settings are stored. A path must:

- be absolute and normalized: not `/`, with no trailing or repeated `/`, no `.` or `..` part, and no control character;
- not be the managed user's home or an ancestor of it;
- not overlap `/boot`, `/dev`, `/etc`, `/proc`, `/run`, `/sys`, `/usr`, `/opt/orbit`, `/var/lib/orbit`, `/var/www`, or the Gateway checkout;
- not overlap the Gateway [release layout](/reference/gateway-recovery#release-layout): its current link, `releases`, and `shared` directories;
- not be inside a hidden directory of the managed user's home; and
- not equal, or lie inside, an existing Instance checkout on the Node.

Swamp then prepares the path on the Node with `@swamp/cfgmgmt/directory`. When the Node has an active `app-dev` role, it prepares the path: it creates each missing directory, owned by the managed user and group with mode `0755`. Otherwise the path must already exist. `app-dev` convergence prepares the apps root in the same way. Caddy must still reach each development Web root under the apps root; [Caddy access](/reference/routes#caddy-access) explains how.

An existing directory must be a real directory, not a symlink, owned by the managed user and group. The owner must have read, write, and execute access, and group and others must not have write access. The Gateway never changes the owner or mode of an existing directory. When a check fails, the stored setting stays unchanged. A directory that the Gateway created before the failure can remain.

## Failure codes

The CLI refuses a bad option before it sends a request.

| Code | Condition |
| --- | --- |
| `node.setting_unknown` | A `node:add` setting path is not supported. |
| `node.setting_duplicate` | The same `node:add` setting path appears twice. |
| `node.setting_invalid` | A `node:add` option has no colon or an empty setting path, or the task VM host settings are not a typed JSON object or `null`. |
| `node.option_required` | `node:update` got none of `--name`, `--platform`, `--apps-path`, or `--task-vms`. |

The Gateway refuses an invalid value without changing the stored setting.

| Code | Condition |
| --- | --- |
| `node.settings_platform_unsupported` | An apps-root action requires a Linux Node. |
| `node.settings_invalid` | The `apps` or `task_vms` value has an unknown member or is not an object or null. An unknown top-level member or an empty body returns `validation.failed`. |
| `node.settings_path_invalid` | The path is empty or not absolute and normalized. |
| `node.settings_path_protected` | The path overlaps a protected path. |
| `node.settings_path_managed` | The path equals or lies inside an Instance checkout. |
| `node.settings_root_failed` | The path failed the check or the preparation on the Node. |

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### Closed, typed settings

A generic settings map would make any key part of the public contract. A column for each setting would make each new setting a schema change. So `settings` is one closed, typed value that grows only through reviewed code. `instance.path` and `worktree.path` are unknown paths.

### Only intent is stored

Storing the computed default would go stale when the managed user's home changes. So the Gateway stores only the path you set and derives the default when it needs it.

### Settings never move checkouts

Moving source on a settings change would be a hidden, risky migration. So each checkout keeps the path it got at creation. Move or remove old checkouts with explicit commands.

### An existing folder converges

An existing apps folder gets the managed user, group and mode 0755, as a new one does, so a rerun repairs drift. A broad `chown` of the wrong path could damage unrelated data. So the Gateway refuses obviously wrong paths at admission, and `@swamp/cfgmgmt/directory` changes only the folder itself, never its contents.
