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

Each Node carries closed, typed settings for its development apps root (`apps.path`), container hosting (`instance_host`, `nested_fleet`), and CI capacity (`ci`). Set them via `node:add` (`apps`, `nested_fleet`, `instance_host`) or [`node:update`](/cli/node/update) (`name`, `platform`, `lan_ip`, `reboot_hold`, `apps`, `nested_fleet`, `instance_host`, `ci`).

State-only updates (such as `ci`, `reboot_hold`, `instance_host`, or clearing `apps.path` on a Node without an active `app-dev` role) apply synchronously in the Gateway. Updating `apps` on an `app-dev` Linux Node or setting a non-null `apps.path` returns HTTP `202` with a `node.update` [Operation](/reference/operations). That Operation creates missing directories, sets ownership to the managed user with mode `0755`, verifies `stat` (`node.settings_root_failed` on failure), and saves the setting on success.

## Set the apps root

Configure `apps.path` during enrollment or later with `node:update`:

```bash
orbit node:add app-dev app-dev.example --role=app-dev --setting=apps.path:/srv/orbit/apps
orbit node:update app-dev --apps-path=/mnt/apps
```

Pass an empty value (`--apps-path=` or `"path": null` / `"apps": null` / `"apps": {}`) to clear `apps.path`. Patches update only supplied fields; unchanged synchronous patches return `meta.skipped: node.unchanged`. Node responses return stored settings only (`settings: null` when unset, never the computed default).

## CI places

A Node with an active [`ci-runner` role](/reference/node-provisioning#ci-runner-role) configures a shared pool of CI places (each place runs one GitHub Actions job or one proof copy):

```bash
orbit node:update 14 --ci-places=4
orbit node:update 14 --ci-proof-only
orbit node:update 14 --no-ci
```

| Field | Required | Default | Rule |
| --- | --- | --- | --- |
| `places` | On first `ci` patch | none | Integer `0`–`64`. |
| `proof_only` | No | `false` | `true` reserves all places for proof copies only. |

Non-null `ci` on any Node without an active `ci-runner` role returns `422 node.settings_ci_runner_required`. `"ci": null` clears the setting on any Node; `"ci": {}` returns `node.settings_invalid`. Size `places` from host CPU pressure under full load; proofs also check available memory (`proof.copy_memory`) and pause idle runners while active ([Proof places on a CI Node](/reference/node-provisioning#proof-places-on-a-ci-node)). Warm proof copies (`proof.warm_copies`, default `0`) also hold a place while waiting.

## Derive the effective root

The effective apps root is `apps.path`, or `<managed-user-home>/apps` when unset. New development checkouts land at `<apps-root>/<project-slug>/<instance-name>` and keep their recorded path even if `apps.path` changes later.

## Validation

An `apps.path` must:

- Be absolute and normalized (no trailing/repeated `/`, `.` or `..`, or control characters).
- Not equal `/`, `/home`, `/root`, any direct child of `/home`, or an ancestor of the managed user's home.
- Not overlap `/boot`, `/dev`, `/etc`, `/proc`, `/run`, `/sys`, `/usr`, `/opt/orbit`, `/var/lib/orbit`, `/var/www`, the Gateway checkout, or the Gateway [release layout](/reference/gateway-recovery#release-layout).
- Not sit inside a hidden directory of the managed user's home or inside an existing Instance checkout.

During `node.update`, Orbit creates missing directories and converges the apps folder itself (never its contents) to `<managed-user>:<managed-group>` mode `0755`, refusing symlinks.

## Failure codes

CLI option checks refuse invalid input before sending a request: `node.reference_required`, `node.option_required`, `node.name_required`, `node.platform_required`, `node.reboot_hold_invalid`, `node.setting_unknown`, `node.setting_duplicate`, `node.setting_invalid`, `node.ci_option_conflict`, and `node.ci_places_invalid`.

Gateway validation and admission return:

| Code | Condition |
| --- | --- |
| `node.settings_platform_unsupported` | Apps folder preparation requires Linux. |
| `node.settings_invalid` | Unknown member, wrong type, or invalid `apps`/`ci` value. |
| `node.settings_path_invalid`, `node.settings_path_protected`, `node.settings_path_managed` | Path syntax, protected path, or checkout overlap failure. |
| `node.settings_root_failed` | Folder creation or `stat` verification failed on the Node. |
| `node.settings_ci_runner_required` | Non-null `ci` on a Node without an active `ci-runner` role (422). |
| `node.platform_managed`, `node.provisioning_busy` | Platform change refused on managed Node, or concurrent provisioning in progress (409). |

## Instance host opt-in

The API-only boolean `instance_host` (default `false`) declares that an `app-dev` Node provides the Incus `orbit-instances` pool (`PATCH /api/v1/nodes/{node}` with `{"instance_host": true}`, then converge `app-dev`). Nodes that already run Instance containers or report the Instance bridge are recognized automatically.

## Nested fleet Instance hosting

The API-only boolean `nested_fleet` (default `false`) enables nested-fleet hosting on an `app-dev` Node. Converging `app-dev` prepares Incus project `orbit-instances-nested`, loads `wireguard` and `overlay` modules (never `br_netfilter`), builds the [nested-fleet image](/reference/development-containers#the-nested-fleet-image) (a failed nested-image build is recorded without failing the role), and checks root's `/etc/subuid` and `/etc/subgid` ranges before making changes (`instance.host_unavailable` if too small):

`13,172,736 + (ci.places + 1) × 101,000,000 + S + 2 × 101,000,000`

Here `S` is the sum of recorded nested Instance [ID map sizes](/reference/development-containers#the-id-map-size) (0 task IDs when `ci` is unset). Clearing `nested_fleet` drops only the two spare maps from future demand while keeping existing runtimes intact.

### A CI Node

A [CI-only Node](/reference/node-provisioning#proof-copies-on-a-ci-node) enables nested-fleet hosting automatically during `ci-runner` convergence with subordinate-ID demand `13,172,736 + (P + 2) × D`, where `P` is `ci.places` and `D` is the proof Project default's recorded ID map size (`101,000,000` if none exists).

## Why it works this way

### Closed, typed settings

Using a closed typed schema instead of an arbitrary key-value map keeps validation, OpenAPI, MCP, and SDK contracts explicit. Storing only explicit intent lets defaults adapt if a user's home moves, and leaving existing checkouts in place prevents hidden data migrations. Converging owner and mode `0755` on the apps folder itself (never recursively) repairs drift safely.
