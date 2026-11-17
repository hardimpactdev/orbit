---
title: "Node settings"
description: "Typed Node settings for the apps root and the CI places of a CI Node, with their validation."
covers:
  - apps/gateway/app/Domain/Nodes/Storage/**
  - apps/gateway/app/{Actions/Nodes/UpdateNodeAction,Domain/Nodes/UpdateNodeResult,Domain/Nodes/NodeSettingsOperationCompletion,Domain/Nodes/NodeSettingsProjection}.php
  - apps/gateway/app/Infrastructure/Nodes/AgentNodeStorageRootPreparer.php
  - apps/gateway/app/Data/Nodes/{NodeSettingsData,NodeStorageAppsData,NodeCiData}.php
  - apps/gateway/database/migrations/{2027_02_05_000000_add_reboot_hold_to_nodes_table,2027_02_24_000000_drop_task_container_ci_places,2027_03_01_000000_drop_vm_era_columns_and_task_vms_setting}.php
  - apps/gateway/app/Http/Requests/Nodes/UpdateNodeRequest.php
  - apps/cli/app/{Support/NodeSettingOptions,Commands/Nodes/UpdateNodeCommand}.php
  - packages/php-sdk/src/{Responses/Nodes/NodeSettings,Responses/Nodes/CiSettings,Requests/Nodes/UpdateNodeRequest,Requests/Nodes/CiPlacesInput}.php
---

# Node settings

A Node has typed settings for its development apps root (`apps.path`) and, on a CI Node, its [CI places](#ci-places) (`ci`). Set them with `node:add` or [`node:update`](/cli/node/update); only `node:update` sets `ci`.

The Node setting `task_vms` and the `image_refresh_hold` field are removed ([old paths](/reference/old-paths#task-vm-settings)). The API refuses either member like any other unknown member, and the CLI has no option for them. A later release removed their stored values: its migration deletes the key `task_vms` from every Node's `settings` and drops the column `image_refresh_hold` ([old paths](/reference/old-paths#task-vm-settings)). Nothing reads either any more.

A settings change that touches the apps root records a `node.update` Operation and returns HTTP 202. Clearing `apps.path` on a Node without an active `app-dev` role starts no run. The [flow chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/node/update.mmd) shows both paths.

The Gateway starts `operation-node-update` on the `swamp` role's workers. Its inputs are the SSH target and the resolved apps folder with the managed user and group. Every step runs on Node 209 and reaches the Node over SSH with sudo.

The steps are:

- `Create the apps folder` runs the official `@swamp/cfgmgmt/directory` behavior. It creates missing folders and gives the apps folder the managed user and group with mode `0755`.
- `Check owner and mode` reads the folder with one `stat`. It is the end check.
- The Gateway reads its output from Swamp's run data.
- The folder must be a real folder of the managed user and group, with mode 0755.
- Any other output fails the task with `node.settings_root_failed`.
- The Gateway stores the settings only after that check passed.

The Gateway stores the settings only from a successful run. A failed or cancelled run keeps the previous settings, and folders made before a failure stay. An apps-folder failure reports `node.settings_root_failed`; the failure detail holds the reason.

Gateway admission resolves the root and checks it: path grammar, protected paths, managed checkouts, and the managed home `/home/<user>` that Orbit creates. It refuses `/`, `/home`, any home folder itself (`/root` or a folder directly under `/home`), `/etc` and `/usr`. It performs no account lookup or filesystem action.

Execution requires Linux for apps preparation. Other platforms refuse before recording an execution intent. Patches that only change stored settings remain available outside the worker rollout.

State-only patches, such as a CI places change, stay synchronous in the Gateway. Node enrollment keeps its synchronous storage preparation until its parent Operation cutover. A settings completion changes only the requested members and preserves other updates made while it was queued.

The same `node:update` path stores the boolean `reboot_hold` column at once, apart from the typed `settings` object. See [fleet reboot holds](/reference/gateway-recovery#fleet-reboot) for their scope, admission time and overdue alerts.

## Set the apps root

`node:add` takes repeatable `--setting=<setting-path>:<value>` options: `apps.path` for the apps root. `node:update` takes `--apps-path`.

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

The [API reference](/api/overview), [MCP](/reference/mcp), and generated web types publish `apps` as an object or null, with `path` as a string or null, and `ci` as an object or null. The settings object and its nested objects reject unknown fields. The request needs at least one member; the `apps.path` member is optional. `"path": null`, `"apps": null`, or `"apps": {}` removes the path.

A patch changes only the members it names. Other stored keys stay, and the API never returns them. When a patch needs no run and every value equals the stored one, Orbit writes nothing, and the response adds `meta.skipped: node.unchanged`. Node responses return the stored value only. They return `settings: null` when nothing is set, never the default. `orbit node:show` shows the path, or an em dash when none is set.

## CI places

A CI Node has one pool of CI places. One place runs one GitHub Actions runner job or one proof copy. Runner jobs and proof copies share the pool, unless the Node keeps its places for proof copies. The top-level `ci` setting holds the pool:

```bash
orbit node:update 14 --ci-places=4
orbit node:update 14 --ci-proof-only
orbit node:update 14 --no-ci
```

The API patch takes the object directly, beside the other members:

```json
{ "ci": { "places": 4, "proof_only": false } }
```

| Field | Required | Default | Meaning and validation |
| --- | --- | --- | --- |
| `places` | Yes, when the Node has no `ci` setting | | The number of places that runner jobs and proof copies share. Integer from 0 to 64 |
| `proof_only` | No | `false` | `true` keeps every place for proof copies: runner jobs never use the Node's places |

Only a Node with an active [`ci-runner` role](/reference/node-provisioning#ci-runner-role) can set `ci`:

- A role that is still provisioning, failed or being removed is not active.
- Orbit refuses a non-null `ci` on any other Node with HTTP 422 `node.settings_ci_runner_required`. Nothing changes.
- The places of a Node count only while its `ci-runner` role is active.
- A Node with no `ci` setting has no CI places.
- A stored setting on a Node without that role grants nothing. Clear it with `"ci": null`.

A patch changes the setting by these rules:

- A patch changes only the members it names. `{"ci": {"proof_only": true}}` keeps the stored `places`.
- The first `ci` patch of a Node must name `places`. Orbit refuses a first patch without it with `node.settings_invalid`.
- `"ci": null` clears the setting on any Node. An absent `ci` member keeps it. `"ci": {}` names no member, and Orbit refuses it with `node.settings_invalid`.
- The object rejects unknown members. `places` is a JSON integer, and `proof_only` is a JSON boolean.

A change is a record. Orbit saves it at once and contacts no Node. `node:show` shows it as `CI places`. Node responses return the stored object and leave out `ci` when it is not set.

Set the count from the CPU pressure of the full Node: all its places busy, together with any other work that the Node runs. Do not derive it from container memory limits. A Node with other work keeps its count small. It can set `proof_only` so that its few places serve proof copies only.

A proof takes a place only when the Node's available memory also holds one copy's peak, the Gateway setting `proof.copy_memory`. While a proof holds places on a Node, the Node's idle runners stop, and they start again when the proof's copies there end ([Proof places on a CI Node](/reference/node-provisioning#proof-places-on-a-ci-node)).

A [warm proof copy](/cli/proof/run#warm-copies) also holds a place while it waits for a proof: one place, one slot of the Node's task copy cap and one copy's memory peak. The Gateway setting `proof.warm_copies` sets how many warm copies the CI Nodes keep together; 0 turns the pool off.

`ci.places` replaced the CI places of the removed task container setting; a database migration removed that member from every stored setting. The [subordinate-ID demand](#nested-fleet-instance-hosting) counts `ci.places` in their place.

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
| `node.setting_invalid` | A `node:add` option has no colon or an empty setting path. |
| `node.option_required` | `node:update` got no option that changes the Node. [`node:update`](/cli/node/update#errors) lists the options. |
| `node.ci_option_conflict` | `node:update` got `--no-ci` with `--ci-places` or a CI proof-only option, or got both `--ci-proof-only` and `--no-ci-proof-only`. |
| `node.ci_places_invalid` | `--ci-places` is not an integer from 0 to 64. |

The Gateway refuses an invalid value without changing the stored setting.

| Code | Condition |
| --- | --- |
| `node.settings_platform_unsupported` | Apps preparation requires a Linux Node. |
| `node.settings_invalid` | The `apps` or `ci` value has an unknown member, is not an object or null, or fails a field check. An unknown top-level member or an empty body returns `validation.failed`. |
| `node.settings_path_invalid` | The path is empty or not absolute and normalized. |
| `node.settings_path_protected` | The path overlaps a protected path. |
| `node.settings_path_managed` | The path equals or lies inside an Instance checkout. |
| `node.settings_root_failed` | The path failed the check or the preparation on the Node. |
| `node.settings_ci_runner_required` | HTTP 422. A non-null `ci` value names a Node without an active `ci-runner` role. |

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

## Nested fleet Instance hosting

The API-only boolean `nested_fleet` records the Node hosting switch and defaults to false. An omitted member preserves its value, including a captured update that predates the member. A combined apps-path and switch update stores the switch only after storage preparation succeeds. An unrelated update preserves it. The update only records the switch: converge the `app-dev` role afterwards.

On a Node of the [build list](/reference/development-containers#the-build-list) with the switch on, the converge also prepares nested-fleet hosting:

- The Incus project `orbit-instances-nested`. It uses the same pool, bridge, ACLs and stock image source as `orbit-instances`.
- Its restrictions: those of `orbit-instances`, with isolated privilege and no virtual machines. It also allows nesting, syscall interception and low-level container keys.
- Its default profile, with the same root disk, NIC and the two read-only shared folders.
- The kernel modules `wireguard` and `overlay`, loaded now and at boot.
- A larger subordinate-ID demand, below.

The converge does not load `br_netfilter`. The module is host-wide: it sends the traffic of every host bridge through the host's `FORWARD` chain, and the nested fleet does not need it. If a later case needs it, root first writes `net.bridge.bridge-nf-call-iptables`, `net.bridge.bridge-nf-call-ip6tables` and `net.bridge.bridge-nf-call-arptables` set to 0 in `/etc/sysctl.d`, and then loads it.

After the normal image, the converge also builds the [nested-fleet image](/reference/development-containers#the-nested-fleet-image) in that project. Only on such a Node does `instance:create` make Instances of a Project with the flag on ([Nested fleet setting](/reference/projects#nested-fleet-setting)); the flag is for the Orbit Project. A CI-only Node needs no switch: its `ci-runner` converge prepares nested hosting, and it takes only proof copies ([Proof copies on a CI Node](/reference/node-provisioning#proof-copies-on-a-ci-node)). A Node with the switch off gets the same host intent and the same commands as before, and builds only the normal image.

A failed nested-fleet build does not fail the converge. The rest of the role completes, the Node records the failed task, and only the create of a nested-fleet default refuses until a later converge builds the image ([The nested-fleet image](/reference/development-containers#the-nested-fleet-image)).

The converge checks root's largest subordinate range in both `/etc/subuid` and `/etc/subgid` before any nesting change on the host. It refuses with `instance.host_unavailable` when either range is smaller than the demand, and then leaves no nesting preparation behind. It never enlarges a range. The demand is:

`13,172,736 + task IDs + S + 2 × 101,000,000`

`13,172,736` is the normal Instance demand. Task IDs come from the [CI places](#ci-places) in `settings.ci`: `(ci.places + 1) × 101,000,000`. Task IDs are 0 when the Node has no `ci` setting. One place gets one full-size map, and the `+ 1` is the map of the base. `S` is the sum of the [ID map sizes](/reference/development-containers#the-id-map-size) that the Node's nested Instance runtimes record. The two spare maps leave room for a new nested default before the next converge.

So a Node with no `ci` setting needs `13,172,736 + S + 2 × 101,000,000` IDs: 215,172,736 with no nested Instance yet, then 101,000,000 more for each full-size runtime, 1,165,536 for each converted one and 2,000,000 for each [raised one](/reference/development-containers#raise-a-default-to-2000000). Incus reads the ranges when its daemon starts; the converge reads the files.

Clearing the switch removes nothing: the nested project, its profile and the module file stay. Runtimes that record `orbit-instances-nested` keep their ID maps in the demand and their project in the shared-folder refresh. Only the two spare maps leave the demand.

### A CI Node

A [CI-only Node](/reference/node-provisioning#proof-copies-on-a-ci-node) has nested-fleet hosting on without the switch: its `ci-runner` converge prepares it. Every rule that needs the switch on, such as `InstancePlacement::keepsFleet`, also holds for such a Node. Its demand is:

`13,172,736 + (P + 2) × D`

| Term | Meaning |
| --- | --- |
| `13,172,736` | The normal Instance demand, as above: the branch is the same |
| `P` | The Node's CI places. Each place holds at most one proof copy |
| `D` | The recorded [ID map size](/reference/development-containers#the-id-map-size) of the proof Project's default, which every copy and every seed holder on the Node takes. 101,000,000 while that Project has no default |
| `2` | The [seed holder](/reference/development-containers#the-seed-on-each-ci-node), and a snapshot that a seed copy adds to it, which Incus maps for a moment as it maps a new container |

Task IDs, `S` and the two spare maps do not apply: a CI Node has no task container places and no nested default. The converge checks and refuses as above, and it never enlarges a range.
