---
title: "Node settings"
description: "Typed Node settings for the apps root and operator-owned task VM hosts, with validation and migration."
covers:
  - apps/gateway/app/Domain/Nodes/Storage/**
  - apps/gateway/app/{Actions/Nodes/UpdateNodeSettingsAction,Providers/TaskVmServiceProvider}.php
  - apps/gateway/app/Infrastructure/Nodes/RemoteNodeStorageRootPreparer.php
  - apps/gateway/app/Data/Nodes/{NodeSettingsData,NodeStorageAppsData,NodeTaskVmsData}.php
  - apps/gateway/database/migrations/*_migrate_task_vm_host_settings.php
  - apps/gateway/app/Http/Requests/Nodes/UpdateNodeSettingsRequest.php
  - apps/cli/app/{Support/NodeSettingOptions,Commands/Nodes/UpdateNodeSettingsCommand}.php
  - packages/php-sdk/src/{Responses/Nodes/NodeSettings,Responses/Nodes/TaskVmsSettings,Requests/Nodes/UpdateNodeSettingsRequest}.php
---

# Node settings

A Node has typed settings for its development apps root (`apps.path`) and its task VM host configuration (`task_vms`). Set them with `node:add` or `node:settings`. [`node`](/cli/node#orbit-nodesettings) lists the commands. The operator owns task VM host configuration; an `app-dev` role alone never makes a Node a task VM host.

## Set the apps root

Both commands take repeatable `--setting=<setting-path>:<value>` options. Use `apps.path` for the apps root and `task_vms` for a JSON host configuration object.

```bash
orbit node:add app-dev app-dev.example --role=app-dev --setting=apps.path:/srv/orbit/apps
orbit node:settings app-dev --setting=apps.path:/mnt/apps
```

The CLI splits each option at its first colon, so a value can contain more colons. An empty value unsets the path: `--setting=apps.path:` sends null. The CLI does not trim a value. `node:settings` needs at least one `--setting` option.

`POST /api/v1/nodes` accepts an optional `settings` member:

```json
{ "settings": { "apps": { "path": "/srv/orbit/apps" } } }
```

`PATCH /api/v1/nodes/{node}/settings` takes the settings object directly, without a `settings` wrapper:

```json
{ "apps": { "path": "/mnt/apps" } }
```

The [API reference](/api/overview), [MCP](/reference/mcp), and generated web types publish `apps` as an object or null, with `path` as a string or null, and `task_vms` as a typed object or null. The settings object and its nested objects reject unknown fields. A patch needs at least one supported member, `apps` or `task_vms`; the `apps.path` member is optional. `"path": null`, `"apps": null`, or `"apps": {}` removes the path.

A patch changes only the members it names. Other stored keys stay, such as the [`t3` connection](/reference/tasks#opsbot-direction-webhook) that annotations use. The API never returns them. Node responses return the stored value only. They return `settings: null` when nothing is set, never the default. `orbit node:show` shows the path, or an em dash when none is set.

## Configure a task VM host

Set `task_vms` on the host Node, not on the disposable task VM Nodes that it runs. The object replaces `ORBIT_TASK_VMS_INCUS_HOSTS` as the source of Incus host configuration. It has no `node_id`: the Node that stores the setting supplies the identity.

```bash
orbit node:settings beast --setting='task_vms:{"cidr":"10.251.77.0/24","max_vms":4,"pool":"orbit-tasks","image_alias":"orbit-task-base"}'
```

This is an example, not the live configuration of beast. Preserve a host's current values when changing its configuration. The API patch takes the object directly:

```json
{
  "task_vms": {
    "cidr": "10.251.77.0/24",
    "max_vms": 4,
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
| `project` | No | `orbit-tasks` | Incus project name |
| `network` | No | `orbittask0` | Bridge name: `orbittask` followed by 1 to 6 lowercase letters or digits |
| `pool` | No | `orbit-tasks` | Incus ZFS storage pool for images, root disks, and workspace volumes |
| `image_alias` | No | `orbit-task-base` | Local base image alias used for task VM launches; a non-empty valid Incus alias |
| `zfs_dataset` | No | `null` | New dataset below a ZFS pool, such as `fast/orbit-tasks`, used when preparing a missing pool; never the whole ZFS pool or an existing dataset |
| `cpus` | No | `2` | vCPUs per normal VM, integer from 1 to 64 |
| `memory` | No | `4GiB` | Positive size in `MiB` or `GiB` for a normal VM |
| `disk` | No | `20GiB` | Positive size in `MiB` or `GiB` for root disks |
| `operator_owned` | No | `[]` | List of Incus instances and custom volumes excluded from stray reports, as `<project>/<name>` or `<project>/*` |

Unknown fields, invalid names, wrong types, and out-of-range values are rejected before storage. Integer fields are integers in JSON, not strings. Invalid configuration leaves the stored setting unchanged. The apps-root filesystem checks below apply only to `apps.path`, not to this object.

Placement selects active Nodes with a valid, non-null `settings.task_vms`. Requests and queued jobs take a fresh host-settings snapshot at the start of their lifecycle, so later jobs see changes without a worker restart. Capacity reads their `max_vms`; host preparation and the host sweep read the same configuration. Later package-cache targeting uses this host intent too; setting it does not install a cache. See [Compute drivers](/reference/compute-drivers#configure-task-vms) for runtime behavior. Being `app-dev` grants no host intent: `services` keeps that role for Recall and gets no task VMs.

### Migrate the environment host list

Deployment copies the current `ORBIT_TASK_VMS_INCUS_HOSTS` entries into the settings of Node 9 (`beast`) and Node 13 (`shark`). It preserves each host's explicit values, including `pool`, `max_vms`, `operator_owned`, and resource and network fields, with the former defaults for omitted fields and `orbit-task-base` for the image alias. `node_id` selects the destination Node and is not stored inside `task_vms`. Other Node settings stay unchanged; the migration does not enroll other `app-dev` Nodes as hosts or replace existing explicit host settings.

After migration, placement and capacity read Node settings only. There is no runtime fallback to the environment list. Verify beast and shark's settings before removing `ORBIT_TASK_VMS_INCUS_HOSTS` from `gateway.env`; use [`gateway:release:configure`](/reference/gateway-recovery#apply-an-env-change) to apply that removal. Keep the live values through deployment so the migration can copy operator intent rather than inventing host configuration.

## Derive the effective root

The apps root is `apps.path`, or `<managed-user-home>/apps` when the path is not set. The Gateway computes the default each time and does not store it.

A new development Instance checkout is `<apps-root>/<project-slug>/<instance-name>`. The Gateway stores that path on the Instance. A later change to `apps.path` never moves, rewrites, or deletes an existing checkout. [Doctor](/cli/doctor#what-each-family-checks) accepts a development checkout under the apps root or under the managed user's home.

## Validation

The Gateway checks the path before it stores it. A path must:

- be absolute and normalized: not `/`, with no trailing or repeated `/`, no `.` or `..` part, and no control character;
- not be the managed user's home or an ancestor of it;
- not overlap `/boot`, `/dev`, `/etc`, `/proc`, `/run`, `/sys`, `/usr`, `/opt/orbit`, `/var/lib/orbit`, `/var/www`, or the Gateway checkout;
- not overlap the Gateway [release layout](/reference/gateway-recovery#release-layout): its current link, `releases`, and `shared` directories;
- not be inside a hidden directory of the managed user's home; and
- not equal, or lie inside, an existing Instance checkout on the Node.

The Gateway then checks the path on the Node. When the Node has an active `app-dev` role, it prepares the path: it creates each missing directory, owned by the managed user and group with mode `0755`. Otherwise the path must already exist. `app-dev` convergence prepares the apps root in the same way. Caddy must still reach each development Web root under the apps root; [Caddy access](/reference/routes#caddy-access) explains how.

An existing directory must be a real directory, not a symlink, owned by the managed user and group. The owner must have read, write, and execute access, and group and others must not have write access. The Gateway never changes the owner or mode of an existing directory. When a check fails, the stored setting stays unchanged. A directory that the Gateway created before the failure can remain.

## Failure codes

The CLI refuses a bad option before it sends a request.

| Code | Condition |
| --- | --- |
| `node.setting_unknown` | The setting path is not supported. |
| `node.setting_duplicate` | The same setting path appears twice. |
| `node.setting_invalid` | An option has no colon or an empty setting path. |
| `node.setting_required` | `node:settings` got no `--setting` option. |

The Gateway refuses an invalid value without changing the stored setting.

| Code | Condition |
| --- | --- |
| `node.settings_invalid` | The object has an unknown member, is not an object, or a patch has no supported member. |
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

### No ownership changes to existing directories

The path can be outside the managed user's home, so a broad `chown` could damage unrelated data. The Gateway only creates missing directories and refuses an existing one that does not already fit.
