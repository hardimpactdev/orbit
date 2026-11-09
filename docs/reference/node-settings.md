---
title: "Node settings"
description: "Typed Node settings for the apps root and operator-owned task VM hosts, with validation and explicit host configuration."
covers:
  - apps/gateway/app/Domain/Nodes/Storage/**
  - apps/gateway/app/{Actions/Nodes/UpdateNodeAction,Domain/Nodes/UpdateNodeResult,Domain/Nodes/NodeSettingsOperationCompletion,Domain/Nodes/NodeSettingsProjection,Providers/TaskVmServiceProvider}.php
  - apps/gateway/app/Infrastructure/Nodes/AgentNodeStorageRootPreparer.php
  - apps/gateway/app/Data/Nodes/{NodeSettingsData,NodeStorageAppsData,NodeTaskVmsData}.php
  - apps/gateway/database/migrations/2026_12_03_000000_allow_cold_recipe_image_rebuilds.php
  - apps/gateway/app/Http/Requests/Nodes/UpdateNodeRequest.php
  - apps/cli/app/{Support/NodeSettingOptions,Commands/Nodes/UpdateNodeCommand}.php
  - packages/php-sdk/src/{Responses/Nodes/NodeSettings,Responses/Nodes/TaskVmsSettings,Requests/Nodes/UpdateNodeRequest}.php
---

# Node settings

A Node has typed settings for its development apps root (`apps.path`) and its task VM host configuration (`task_vms`). Set them with `node:add` or [`node:update`](/cli/node/update). The operator owns task VM host configuration; an `app-dev` role alone never makes a Node a task VM host.

A settings change that touches the apps root records a `node.update` Operation and returns HTTP 202. Adding task-host settings or changing capacity is stored at once. Removing an existing task host or changing its bridge or CIDR records an Operation that withdraws its owned DHCP/DNS input rules before saving the settings. Clearing `apps.path` on a Node without an active `app-dev` role starts no run. The [flow chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/node/update.mmd) shows both paths.

The Gateway starts `operation-node-update` on the `swamp` role's workers. Its inputs are the SSH target, any resolved apps folder with the managed user and group, and the catalog comments of any task-host input rules to withdraw. Every step runs on Node 209 and reaches the Node over SSH with sudo.

The steps are:

`Withdraw task host input` runs when a task host is removed or its bridge changes. The shared Ansible phase deletes rows with the admitted catalog comments and checks that none remain. Reachable Node removal uses the same phase.

When no apps folder needs preparation, the withdrawal step is the terminal check. Its successful result lets Orbit save the settings even though the folder check is skipped.

The apps-folder steps run only when the patch needs folder preparation:
- `Create the apps folder` runs the official `@swamp/cfgmgmt/directory` behavior. It creates missing folders and gives the apps folder the managed user and group with mode `0755`.
- `Check owner and mode` reads the folder with one `stat`. It is the end check.
- The Gateway reads its output from Swamp's run data.
- The folder must be a real folder of the managed user and group, with mode 0755.
- Any other output fails the task with `node.settings_root_failed`.
- The Gateway stores the settings only after that check passed.

The Gateway stores the settings only from a successful run. A failed or cancelled run keeps the previous settings, and folders made before a failure stay. An apps-folder failure reports `node.settings_root_failed`; the failure detail holds the reason. A firewall withdrawal failure keeps the old settings, so its owned rules remain visible for retry.

Gateway admission resolves the root and checks it: path grammar, protected paths, managed checkouts, and the managed home `/home/<user>` that Orbit creates. It refuses `/`, `/home`, any home folder itself (`/root` or a folder directly under `/home`), `/etc` and `/usr`. It performs no account lookup or filesystem action.

Execution requires Linux for apps preparation and for firewall withdrawal. Other platforms refuse before recording an execution intent. Patches that only change stored settings remain available outside the worker rollout.

State-only patches, such as task-host capacity changes, stay synchronous in the Gateway. Node enrollment keeps its synchronous storage preparation until its parent Operation cutover. A settings completion changes only the requested members and preserves other updates made while it was queued.

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

A patch changes only the members it names. Other stored keys stay, and the API never returns them. When a patch needs no run and every value equals the stored one, Orbit writes nothing, and the response adds `meta.skipped: node.unchanged`. Node responses return the stored value only. They return `settings: null` when nothing is set, never the default. `orbit node:show` shows the path, or an em dash when none is set.

## Configure a task VM host

Task VM placement uses Incus hosts only. Task places run on Beast and Shark. CI places also run on Sabre. Set `task_vms` on the host Node, not on the disposable task VM Nodes that it runs. The object replaces `ORBIT_TASK_VMS_INCUS_HOSTS` as the source of Incus host configuration. It has no `node_id`: the Node that stores the setting supplies the identity.

The common converge brings a task VM on such a host to the pins of the release, on every base refresh and every task start. It reads no host setting. See [Converge a running task VM](/reference/compute-drivers#converge-a-running-task-vm).

```bash
orbit node:update beast --task-vms='{"cidr":"10.251.77.0/24","task_places":2,"ci_places":2,"pool":"orbit-tasks","image_alias":"orbit-task-base"}'
```

This is an example, not the live configuration of beast. Preserve a host's current values when changing its configuration. The API patch takes the object directly:

```json
{
  "task_vms": {
    "cidr": "10.251.77.0/24",
    "task_places": 2,
    "ci_places": 2,
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
| `cidr` | Yes | | Private IPv4 bridge network from `/16` to `/28`, not a host address; it must not overlap the VPN subnet, another host's bridge, or any Node's [Instance bridge](/reference/development-containers#nodes) |
| `task_places` | Yes | | Independent task pool, integer from 0 to 64. One live VM holds one place until deletion |
| `ci_places` | Yes | | Independent CI pool, integer from 0 to 64. Authenticated CI proofs and proofs of a refreshed base use it |
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
| `containers` | No | absent | Task container places of the host, which [the plan](/solutions/development-container-runtime#pr-17-remove-task-vms-the-pi-server-and-the-task-container-setting) removes, with their own project, bridge and CIDR. See [Task container fields](#task-container-fields) |

### Task container fields

The optional `containers` object gives a host task container places beside its task VM places. A host without it runs only task VMs.

```json
{
  "task_vms": {
    "cidr": "10.251.77.0/24",
    "task_places": 2,
    "ci_places": 2,
    "containers": { "cidr": "10.251.78.0/24", "task_places": 1, "ci_places": 1 }
  }
}
```

| Field | Required | Default | Meaning and validation |
| --- | --- | --- | --- |
| `task_places` | Yes | | Task places for containers, integer from 0 to 64, apart from the VM `task_places` |
| `ci_places` | Yes | | CI places for containers, integer from 0 to 64, apart from the VM `ci_places` |
| `project` | No | `orbit-task-containers` | Incus project of the containers and their base, with the VM `project` rule. It must differ from the host's VM `project` |
| `network` | No | `orbittaskc0` | Bridge of the containers, with the VM `network` rule. It must differ from the host's VM `network` |
| `cidr` | Yes | | Bridge network, with the VM `cidr` rule. It must not overlap the VPN subnet, the host's VM bridge, any bridge of another host, or any Node's Instance bridge |

The host's `pool`, `cpus`, `memory`, `disk` and `operator_owned` apply to both runtimes. `image_alias` is a VM field only.

Container places are counted apart from VM places, and normal placement skips them until the operator turns on `task_vms.container_placement`; see [Place task VMs](/reference/compute-drivers#place-task-vms). While the host has a task container that is not destroyed, Orbit refuses to remove `containers` or to change its `project`, `network` or `cidr`, with HTTP 409 `task_vm.runtime_in_use`. Its places can change at any time.

Unknown fields, invalid names, wrong types, and out-of-range values are rejected before storage. Integer fields are integers in JSON, not strings. Invalid configuration leaves the stored setting unchanged. The apps-root filesystem checks below apply only to `apps.path`, not to this object.

Task provision, prep, build, fix turns, review rounds and operator proofs use the task pool. Only an active caller Node with an active `ci-runner` role grants access to the CI pool. A full pool records a wait for up to two hours. Failed VMs still hold their place until deletion succeeds. Publishing the PR releases the VM, Node and keys. Later work claims a fresh VM from the base and the task branch.

The approved pool sizes are Beast 2 task / 2 CI, Shark 2 task / 2 CI, and Sabre 0 task / 2 CI. The operator sets these sizes after the pool implementation ships. Existing stored resource-unit settings grant no places under this model.

Placement selects active Nodes with a valid, non-null `settings.task_vms`. Requests and queued jobs take a fresh host-settings snapshot at the start of their lifecycle, so later jobs see changes without a worker restart. Capacity reads their `task_places` and `ci_places`; host preparation and the host sweep read the same configuration. Later package-cache targeting uses this host intent too; setting it does not install a cache. See [Compute drivers](/reference/compute-drivers#configure-task-vms) for runtime behavior. Being `app-dev` grants no host intent: `services` keeps that role for Recall and gets no task VMs.

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
| `node.settings_platform_unsupported` | Apps preparation and firewall withdrawal require a Linux Node. |
| `node.settings_invalid` | The `apps` or `task_vms` value has an unknown member, is not an object or null, or fails a field check. An unknown top-level member or an empty body returns `validation.failed`. |
| `node.settings_path_invalid` | The path is empty or not absolute and normalized. |
| `node.settings_path_protected` | The path overlaps a protected path. |
| `node.settings_path_managed` | The path equals or lies inside an Instance checkout. |
| `node.settings_root_failed` | The path failed the check or the preparation on the Node. |
| `task_vm.runtime_in_use` | HTTP 409. The request removes `task_vms.containers`, or changes its `project`, `network` or `cidr`, while the host has a task container that is not destroyed. |

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
