---
title: "Tools"
description: "Which packages Orbit owns, and how to manage optional Tools, exact versions, and Gateway policy per Node."
covers:
  - swamp/extensions/behaviors/operations/tools/**
  - swamp/workflows/workflow-operation-tool-{write-209,remove-209,manager-setup,converge}.yaml
  - swamp/ansible/{operation-tool-converge.yml,tasks/node-converge-tool*.yml}
  - apps/gateway/app/{Providers/ApplicationServiceProvider.php,{Actions,Domain}/Tools/**,Data/Tools/**,Actions/Doctor/Intent/RuntimeDoctorIntent.php}
  - apps/gateway/app/Infrastructure/{Tools/**,Nodes/Roles/RolePrerequisites.php}
  - apps/gateway/{app/Models/{Tool,ToolManagerRecord,AptRepository}.php,database/migrations/{2026_12_03_000000_allow_cold_recipe_image_rebuilds,2027_01_09_120017_create_apt_repositories_table,2027_03_26_000000_add_tool_policy,2027_03_28_000001_record_tool_ownership_and_apt_repository_status}.php,routes/api/tool.php,resources/machine-software/tools.yaml}
  - apps/agent/src/commands/{vp_store,vp_scope,vp_global,tools_apt,composer_global,homebrew_scope,homebrew,tool_output}{.rs,/**}
  - apps/gateway/app/Http/{Controllers/Api/{ToolsController,ToolManagersController,ToolInventoryController,ToolPolicyController,AptRepositoriesController}.php,Requests/Tools/**}
---

# Tools

A Tool is an optional package that the Gateway manages on one Node, identified by `(node, manager, package)`. Orbit registers only packages you ask it to install or adopt. Discovery (`tool:scan`) reports installed packages without taking ownership. [`tool`](/cli/tool) lists the commands.

## Package ownership

Classify a package by what consumes it and where it runs, not by the name of its executable.

| Consumer and install location | Owner |
| --- | --- |
| Orbit, a Node role, or container-host prerequisites on the host | The release's `core.yaml` recipe. A Tool cannot override protected core or role software. |
| Shared container runtime under `/opt/orbit-runtime` | The runtime section of `core.yaml`; Projects and Instances retain their runtime and driver selection. |
| Shared container agent software under `/opt/orbit-agent` | The agent section of `core.yaml`, including the Linux Conn Server required by task copies. |
| An optional host package selected for a Node | A Gateway Tool record. The active Tool mode assigns the version, constraint, and apt repository. |
| A Conn Server running as a host service | Gateway Tool membership pins a release. Only the protected [Conn Server rollout](/reference/development-containers#conn-server-rollout) changes the service. Neither Tool convergence nor container convergence updates it. |

## Tool modes

There is one mode per Gateway, and membership stays per Node in either mode. An existing Gateway enters database mode without adopting, installing, updating, or removing any package. Existing observed versions stay observations, not new pins.

| Mode | Who sets the coordinate |
| --- | --- |
| `database` | `tool:install` and `tool:update` can set an exact desired version for one Node's Tool. With no pin, the manager selects a candidate within the optional constraint. |
| `file` | One `tools.yaml` on the Gateway declares exact versions, optional constraints, and apt repositories. Use the release's default catalog or an absolute path that the Gateway can read. |

In file mode, adding a catalog entry does not register or install a Tool on any Node. Use `tool:install` or `tool:adopt` to add membership, and `tool:remove` to remove it. Install and adoption refuse packages outside the catalog (`tool.not_in_catalog`); install, update, and adoption refuse per-command overrides (`tool.file_override`). Adoption writes no package and requires the observed version to match the catalog (`tool.installed_version_mismatch`).

The catalog has schema `1`, an optional list of `repositories`, and a list of packages. Each repository is a name with its [definition](#apt-repositories). Each package has `manager`, `package`, `version`, and optional `constraint` and `repository`, the name of a repository the catalog lists. Quote version strings. Replace the active file atomically and run [`tool:converge`](/cli/tool/converge) on the affected Nodes. Orbit does not watch the file or change packages in the background. Keep an entry until all its memberships have been removed: convergence refuses a missing entry instead of deleting or uninstalling the Tool.

```yaml
schema: 1
repositories:
  - name: rex
    uris: ['https://apt.example.com/rex']
    suites: [preview]
    components: [main]
    signing_key: {url: 'https://apt.example.com/rex.asc', fingerprint: 'AAAA…'}
packages:
  - manager: apt
    package: ripgrep
    version: '15.1.0-1ubuntu1'
  - manager: apt
    package: rex
    version: '0.9.0'
    repository: rex
```

### Switch modes without running Node work

Export before switching so you have a copy of the current policy and memberships.

1. Inspect the mode with `orbit tool:mode`. Save `orbit tool:mode --export=snapshot --json`: it preserves membership, desired coordinates, observations, apt repository records, and host Conn Server rollout eligibility.
2. To enter file mode with existing Tools, use `orbit tool:mode --export=catalog --json`. Write its `document` to `tools.yaml` on the Gateway, then run `orbit tool:mode:set file --catalog=/absolute/path/tools.yaml`. Omit `--catalog` to use the shipped file.
3. To return to database mode, run `orbit tool:mode:set database`. The database takes the current file coordinates for the existing memberships and keeps observed versions separate.

Leaving file mode, each membership takes the repository the catalog names for it. Its Node must have that repository exactly as the catalog defines it. Otherwise the switch changes nothing and refuses with `tool.mode_conflict`, with `reason` `repository_missing` or `different_repository` and the Tool and repository named.

A catalog cannot express different desired coordinates for the same manager and package across Nodes, different definitions under one repository name, two repository names on one URI and suite, or a database Tool without an exact version. Export reports `tool.mode_conflict` rather than selecting one version or inferring a pin from observation.

Export includes every repository record, also one no Tool references. It runs the check activation runs, against the current memberships, so an export activates unchanged at a new path. A catalog assignment of a repository not yet converged passes when the Node's record of that name has the catalog's definition. A catalog that drops or renames a membership's repository is refused at export with `tool.repository_conflict`, as activation refuses it.

Activation also refuses missing or different coordinates, a repository whose Node record differs from the catalog's definition, a membership whose repository the catalog drops or renames (`tool.repository_conflict`), and any active or retained Tool execution hold.

A successful change stores a snapshot of the prior policy and returns its `snapshot_id`; it starts no Node Operation. The same contracts are available through `GET /api/v1/tool-policy`, `PUT /api/v1/tool-policy`, and the Gateway MCP tools.

## Desired and installed versions

`tool:list` and `tool:show` report `desired_version` separately from `installed_version`. A failed or not yet applied change can leave the two values different. To select an exact version in database mode, use `--tool-version=VERSION` on install or update, or send `desired_version` through the API or MCP. Omitting the option on update keeps an existing pin.

| Manager | Exact coordinate |
| --- | --- |
| `apt` | The full Debian version, including the epoch or distro suffix where applicable. A pin can select an available older version; a floating update does not downgrade. |
| `vp`, `composer` | An exact package release version, not a range or a tag. |
| `brew`, `brew-cask` | The current supported formula or cask version, including a formula revision (`2.1.0_1`) or cask build suffix (`1.5.13,600`). Orbit does not add taps or fetch older bottles. |
| `conn-server` | An exact checksummed host release from `conn-server-releases.yaml`. It is separate from the container agent pin. |

The manager must still offer the version, and the version must meet any SemVer constraint. For Homebrew formulae and casks, constraints use the version without the revision or build suffix; exact pins include it. Orbit refuses an unavailable coordinate instead of substituting a different version. Package downloads and removals retain the manager's source, verification, scope, and dependency rules.

## Converge registered Tools

Run `orbit tool:converge --node=ID` on Linux or macOS to reconcile only the Node's registered Tools. It applies exact desired versions and restores missing packages without installing catalog-only entries. For a database Tool without a pin, convergence leaves a compatible installed package in place; `tool:update` asks the manager for a new candidate. A repeated convergence reports zero changes once the Node matches.

Linux full [`node:converge`](/cli/node/converge) includes all eligible Tools; role-only and base-only runs do not. Use `--tool-id=ID` on `tool:converge` to select specific memberships without resolving unrelated catalog entries. Convergence first publishes each [apt repository](#apt-repositories) its selected Tools reference, then reconciles the packages. Convergence runs Ansible from the Swamp control Node over pinned SSH. The phase deadline scales with the number of selected Tools, up to the execution limit. If the phase times out, Orbit retains completed Tool observations. Direct package installs, updates, and removals use native SSH with the same admission and package policy.

## Where Tools run and Tool Managers

Tools run on active SSH-managed Nodes with a WireGuard IP and pinned SSH host fingerprint (`tool.node_inactive` or `tool.node_unmanaged`, 409, otherwise). The managers are fixed in code:

| Manager | Platforms | Package | Scope | Becomes ready |
| --- | --- | --- | --- | --- |
| `apt` | Linux only | Ubuntu package name | Node package database | During Linux `node:add` |
| `composer` | Linux only | `vendor/package` | `COMPOSER_HOME=/opt/orbit/composer` | First Linux use or `app-dev`/`app-prod` |
| `vp` | Linux, macOS | npm package name | Enrolled account Vite+ store (`/opt/orbit/vite-plus`, `~/.vite-plus`, or `~/.local/share/vite-plus`) | Linux: first use or app role; macOS: verified in place |
| `brew` | Linux, macOS | Homebrew Core bottle | `/home/linuxbrew/.linuxbrew` (Linux) or existing prefix (macOS) | Linux: pinned revision on first use; macOS: verified in place |
| `brew-cask` | Linux (with Homebrew), macOS | Official Homebrew cask | Homebrew prefix (shares `brew` lock) | Linux: once agent reports Homebrew; macOS: verified in place |
| `conn-server` | Linux x64, macOS arm64 | `conn-server` | Configured host service, not a container shared folder | After the Node has a trusted Conn Server topology record |

Unsupported platform/manager pairs return `tool.manager_unsupported` (422); absent or conflicting scopes return `tool.manager_unavailable` (409). `orbit tool:manager:list --node=<id>` reports each supported manager as `uninstalled`, `provisioning`, `active`, or `failed`. Managers stay installed after their last Tool is removed.

Linux `apt` installs and updates wait up to 300 seconds for busy dpkg locks (`-o DPkg::Lock::Timeout=300`); `apt-get update` and `dpkg --remove` fail immediately if locked. Linux `vp` launchers in `/usr/local/bin` export `VP_HOME` for the resolved store.

### Host Conn Server membership

`orbit tool:install conn-server --node=ID --manager=conn-server --tool-version=VERSION` enrolls a configured service in database mode and records its exact desired release. In file mode omit `--tool-version` and declare `manager: conn-server`, `package: conn-server`, and the quoted release version in the active catalog. `tool:update` records a desired release without restarting anything; `tool:remove` withdraws the membership from rollout selection without stopping the service. Package discovery cannot adopt the host service. The install and update Operations report `execution: membership_only` and return `unchanged` when membership and the desired release already match.

Use the [protected rollout](/reference/development-containers#conn-server-rollout) to apply the desired release or retire a withdrawn Linux service. A Gateway release can run the reviewed canary only if that Node has opted in and desires the exact release. Promoting it requires an explicit request and fresh checks on matching followers. A release does not enroll services, change Node pins, or act on withdrawn members. A host rollout never changes the required container Conn release.

Doctor compares a managed service with the last version verified by rollout health checks, not package inventory. A dry run can refresh that observation without changing the service. Without a verified observation, Doctor reports the Tool as unverifiable.

## Project environments in the Vite+ store

Before JavaScript deploy steps, Orbit reads the release's manifest, lockfile, and Node pin (`.node-version`, `.nvmrc`, or `engines.node`) as data without running Project code, verifying the locked `vite-plus` version against the recipe's `vp` binary and installing missing Node/npm/pnpm runtimes into the enrolled account's immutable store (`js_runtime/node/<version>`, `package_manager/{npm,pnpm}/<version>`). Production builds run as the Instance user via `vp env exec` with `VP_HOME` set, granting ancestor traversal only where needed. Retained releases keep their runtimes so rollbacks work offline; see [Deploy prerequisites](/reference/deployments#vite-store-prerequisite).

## Discover installed packages

`GET /api/v1/tool-inventory?node_id=<id>` (`orbit tool:scan --node=<id>`) reads the [Node agent's](/reference/node-agent#packages) latest `packages` facts without contacting the Node or creating Tool rows. It returns `data.managers` in order (`brew`, `brew-cask`, `vp`, `apt`), each with `scan_state` (`complete`, `unsupported`, or `not_observed`) and sorted `packages` (`manager`, `package`, `package_kind`, `installed_version`, `dependency`, `registered`, `tool_id`, `adoption`, `adoption_block`, `repository`).

Adoption block tokens are `protected`, `dependency`, `unsupported_artifact`, `unsupported_source`, `bottle_unavailable`, `version_unreadable`, `authorization_required`, `removal_pending`, and `held`.

When a Homebrew formula has several Cellar versions, its installed version is the one its `opt` link names, so an old folder that `brew cleanup` has not removed yet changes neither the scan, Doctor nor adoption ([brew fact](/reference/node-agent#packages)). However, several versions and no link leave the version unknown: the row carries `version_unreadable` and Doctor cannot verify the Tool.

An apt package held with `apt-mark hold` (dpkg status `hold ok installed`) is installed: its row keeps its version, and Doctor checks a managed held package like any other. apt will not change a held package, so it carries the `held` block and adoption refuses it with `tool.adoption_unsupported`.

A held apt Tool at its desired coordinate or constraint needs no write: a converge, update or reinstall records it `installed` with that version. At any other version Orbit runs no apt write and fails the Tool with `tool.package_held`, because the hold blocks the change. A removal of a held package fails with `tool.package_held` before `dpkg` runs. Release the hold with `apt-mark unhold` to let Orbit change the package.

## Protected packages

Orbit protects essential host and manager packages from optional installation, update, adoption (`adoption_block: protected`), and convergence (`tool.package_protected`, 409). This includes host prerequisites such as Caddy, Valkey, and Docker CE. If a protected package still has a Tool record, removal deletes that record only; the host package stays installed. `conn-server` is reserved for the dedicated host rollout and container recipe, not a generic package write:

| Manager | Protected package names |
| --- | --- |
| `apt` | Host recipe profiles, Git and its rollback helper, and Node bootstrap packages |
| `brew` | `wireguard-tools`, `wireguard-go` |
| `vp` | `pnpm` |

A package used only inside a container does not reserve the host name.

### Role-owned PHP packages

The `apt` manager also protects every `php<version>-*` package plus `php-common` and `libgd3` (`tool.package_role_owned`, 409, on install/update; `protected` on adopt). Removing an existing Tool row for a PHP package owned by a role deletes only the Gateway record without uninstalling the package.

## Adopt a Tool

`POST /api/v1/tools/adopt` (`orbit tool:adopt <package> --node=<id> --manager=<manager> [--constraint=<range>] [--repository=NAME] --yes`) runs **synchronously** in the Gateway against the agent's latest `packages` fact (creating no Operation and contacting no Node) and returns HTTP `201` with the Tool record and `agent` liveness.

Accepted fields (unknown fields return `422 validation.failed`): `node_id` (int), `manager`, `package`, optional `version_constraint` (SemVer range), and optional `repository`, the name of a ready [apt repository](#apt-repositories) of the Node that the package comes from.

Adoption requires a single installed version, no adoption block, and constraint compliance (`tool.not_observed`, `tool.package_absent`, `tool.installed_version_unparseable`, `tool.installed_version_constraint_violated`, `tool.repository_adoption_mismatch`, `tool.constraint_conflict`, `tool.state_invalid`, or `tool.adoption_unsupported`, all HTTP 409).

## Install and update a Tool

`tool:install` and `tool:update` return HTTP `202` with an [Operation](/reference/operations) serialized under one per-`(node, manager)` lock (`operation.target_busy`, 409, on contention). If install finds an already-installed unmanaged package on the Node, the admitted Operation fails with `tool.package_already_installed`; adopt it with `tool:adopt` first. Optional SemVer `version_constraint` blocks out-of-range candidates (`blocked_by_constraint` / `tool.version_constraint_blocked`).

## Homebrew formulae

`brew` accepts only unqualified lowercase Homebrew Core formulae with a checksummed compatible bottle (never building from source or running `brew ruby`/`brew irb`). Orbit computes the bottle tag from `uname -m` (`x86_64_linux` on Linux; `sw_vers` mapped to `big_sur`..`golden_gate` with `arm64_` prefix on Apple silicon). Every `brew` and `brew-cask` call sets `HOMEBREW_NO_AUTO_UPDATE`, `HOMEBREW_NO_ANALYTICS`, `HOMEBREW_NO_ENV_HINTS`, `HOMEBREW_NO_INSTALLED_DEPENDENTS_CHECK`, and `HOMEBREW_NO_INSTALL_CLEANUP`.

### Readable Homebrew prefix

The [Node agent](/reference/node-agent#packages) observer reads `/home/linuxbrew/.linuxbrew` with no capability, so it sees only what others can read. Orbit applies one rule after each Linux brew install, update or removal, and in every Tools phase of a Node converge. Each entry under the prefix that others cannot read becomes readable for group and others, and each such directory also becomes searchable, as Homebrew sets them up by default.

The rule touches only entries that lack those bits and keeps owner and write bits, so a second run changes nothing. It changes only entries that belong to the prefix: it does not descend into another file system mounted inside it, and it skips a file with more than one hard link.

A direct Linux brew write or removal applies the rule after its package step succeeded or failed, never when that step was skipped. A failed step still fails the run. The Tools phase reports a change only when it changed an entry. macOS prefixes are left as they are.

### Homebrew casks

On macOS and on Linux Nodes where the agent reports Homebrew, `brew-cask` accepts unqualified official `homebrew/cask/<token>` casks with `https` URLs, lowercase SHA-256 checksums (`no_check` refused), and user-home or Homebrew-prefix artifacts (fonts, binaries, man pages, completions). Install and update set `HOMEBREW_FORCE_API_AUTO_UPDATE` on both platforms. A comma-separated build suffix is part of the exact coordinate and stays in Tool records and snapshots, while direct install and update receipts display the version before the comma. SemVer constraints apply to that abbreviated version. Casks targeting `/Applications`, system paths, package installers, or privileged uninstalls return `authorization_required`; preflight/postflight/arbitrary scripts and disabled casks return `unsupported_artifact`.

## Remove a Tool

`tool:remove` returns HTTP `202` with an Operation. It removes only the record for a protected or role-owned package.

### Ownership

A Tool owns its package only when Orbit acquired it: its own verified package write, an explicit adoption, or a convergence that installed the package for that Tool. Finding the package already present is an observation, not an acquisition, also when a run succeeds without writing it.

A convergence's installed observation acquires the package only when its plan was admitted under this contract, whose helper refuses a present package Orbit does not own. A preceding-release request or convergence read after the upgrade therefore observes, never acquires. A verified write stays an acquisition even when a later step of the same run fails. Failures, refused or interrupted retries, and another Tool's repair of the shared manager never grant ownership.

A removal that may have taken the package ends ownership. That covers a removal that verified the package gone, one that found it already absent, and one whose package step ran without leaving the package in place. `dpkg --remove` can remove the package and still exit nonzero when another package's trigger fails. After a failed package step the run checks the package again.

Only a package step that never ran, or one the check finds the package still present after, keeps the claim, as when a held dpkg lock refuses before any write. A check that finds it gone, or that could not read it, ends the claim. The check asks only whether the package is there, not whether its version is the one an install would target.

A retry then repins only and never removes a replacement package installed since. A repin-only removal never reads or touches the package, so it changes no ownership.

The run's own receipt decides, whatever its end. A failed or cancelled run that recorded a verified acquisition or a confirmed absence keeps it, and so does the Tool row it applies to.

A run that ends after the Gateway timed its Operation out counts the same way. The Operation stays failed with `operation.timed_out`, but when the reconciler reads the run's terminal, its receipt counts for the Tool as it would have on time. A convergence report counts each valid observation it carries, installed or failed, also when its phase was partial or its run was cancelled.

Receipts can arrive out of order, so each one changes only what no newer receipt changed. The Tool's state, its ownership and its installed version each keep the newest Operation whose receipt changed them, and so does a Tool Manager's state. A version counts only when the receipt observed it: a refused decision that repeats its admitted version observed nothing.

A refusal observed the version only when it read the package, and a read that found the package absent observed its absence. A newer request that changed nothing, because it was refused before its package step or cancelled before its run, never hides an older receipt. An explicit adoption orders after every earlier Operation, so no late receipt sets back what it recorded.

A run is given the uninstall authority its admission recorded, and its receipt is read under that same authority, whatever the Tool holds by then: a timeout that set the claim aside changes how nothing the run did is read. Work the preceding release left open carries the authority the upgrade recorded for it. Missing authority is none: the executor never removes a package without it, and an update refuses a package Orbit does not own.

Desired state belongs to admissions: each direct request and each convergence sets it when admitted, and a mode switch sets it too, so no receipt sets it back. A removed Tool stays removed.

Until then, uncertainty grants nothing and keeps no right to remove a package. A timed-out removal leaves its Tool waiting for `tool:remove` without uninstall authority, because the package may already be gone and a replacement may follow. That holds even when the start reply that binds the run never arrived: a sent start is no proof that nothing ran. A retry once Swamp has bound that start to a run, also after the target hold lapses, therefore only repins and removes the record.

Until Swamp's lookup by the start key answers whether an unbound start became a run, `tool:remove` refuses with `tool.removal_unsettled`. It neither removes the package nor gives it up. Every reconciler pass asks, also after the target hold lapses, since a released hold proves nothing: no run gives the authority back, and a run found settles by its own end. A newer acquisition, such as an update that wrote the package since, takes the Tool back, and its retry removes the package without waiting for the lookup.

A removal whose start was never sent keeps its authority when it times out. It was never begun, or Swamp's deferral answered its latest begin. The deferral schedule shows that when the latest begin came too soon after the first deferral for a begin after the last one.

Only proof that the removal never reached its package gives the authority back, with the state the Tool had before the timeout set it aside, a preceding-release admission's included. That proof is a receipt showing the package step never ran, including one the reconciler reopens because its cancellation beat the deadline, or Swamp verifying that the lost start never became a run. A start whose reply was merely lost proves nothing.

An install or convergence never takes over a present package for a Tool Orbit does not own. Both refuse with `tool.package_already_installed`, including after a first install that was cancelled, timed out or returned an unreadable result. Such an interrupted first install stays `installing` until you retry or remove it. You cannot update a Tool Orbit does not own.

For a Tool Orbit does not own, removal deletes only the record and needs no SSH target or host key. A Tool of an apt repository instead runs on the Node with `package_owned: false`: it skips the manager check and every package read and plan, repins the repository's remaining owned members, and never removes the package. A refused first install leaves no membership.

A Tool whose removal is unfinished stays `failed` with `failed_operation: remove`. It is not a membership. Convergence, apt preferences, catalog export, mode changes and Doctor leave it out. `tool:scan` reports its package as unregistered with `adoption_block: removal_pending`, adoption refuses it with `tool.state_invalid`, and only `tool:remove` acts on it.

For an apt package removal, Orbit simulates removal first (`apt-get --simulate remove`) and refuses plans that remove any other package (`tool.removal_plan_unsafe`), then runs `dpkg --remove -- <package>` without purging configs or autoremoving. On success, the Tool row is deleted and the response reports `status: removed`, `outcome: applied`. When the repin of the repository's remaining members fails, the Tool stays `failed` with `tool.repository_failed`. Run the removal again to finish it.

Ownership decides which packages a repository's preference prefers. Removing a Tool repins the remaining owned members of its repository, whatever the repository's state, and never removes the repository: removing the last Tool leaves the repository with every package at priority `1`. Only [`tool:apt-repository-remove`](/cli/tool/apt-repository-remove) removes it.

## Apt repositories

A signed apt repository is a record of its own on one Node, with its own lifecycle. [`tool:apt-repository-add`](/cli/tool/apt-repository-add) (`POST /api/v1/apt-repositories`) takes the Node, a name, and the definition: `uris`, `suites`, `components`, optional `architectures`, `signing_key.url`, and the 40/64-hex primary `signing_key.fingerprint`. The reserved names `caddy` and `php` are refused. [`tool:apt-repository-list`](/cli/tool/apt-repository-list), [`tool:apt-repository-show`](/cli/tool/apt-repository-show), and [`tool:apt-repository-remove`](/cli/tool/apt-repository-remove) complete the family, with the matching `GET` and `DELETE` routes and Gateway MCP tools.

The add runs an Operation. It reads the signing key, armored or a binary keyring, into a private temporary file and verifies its primary fingerprint. It then holds the repository's preference to the owned Tools that reference it, writes `/etc/apt/keyrings/orbit-<name>.gpg` and `/etc/apt/sources.list.d/orbit-<name>.sources`, and reads the repository's package index. The preference `/etc/apt/preferences.d/orbit-<name>.pref` gives priority `990` to owned packages and `1` to every other package from its origins. apt matches `Pin: origin` against the URI's host as written: an IPv6 literal without its brackets and not shortened, so `[0:0:0:0:0:0:0:1]` stays `0:0:0:0:0:0:0:1`. Orbit writes it that way.

A repository is `adding`, `ready`, `removing`, or `failed` with its error code. It is usable only after a publication of it succeeded: its own add, or a convergence that published it, which runs the same verified publication. No other event grants or removes it. To recover a failed or unconfirmed repository, run `tool:apt-repository-add` with the same name and definition, or converge the Node. An add or removal that never ran leaves the repository as it was. One that timed out leaves it `failed`.

A repository's definition does not change under its name on a Node. Adding the same name with the same definition publishes it again; adding it with another definition is refused with `tool.repository_conflict` (409), whether or not Tools reference it, and so is a convergence that would publish another definition under the name. To change a definition, remove the repository, then add it. A record keeps its definition through a failed add or a failed removal, so it always names what may be on disk.

One URI and suite belongs to one repository per Node: apt stops every read when two source files give one entry different `Signed-By` keyrings. The add refuses with `tool.repository_conflict` a URI and suite that another repository of the Node has, also one whose add or removal failed: its record reserves them until a removal succeeds. The publication reads and verifies the key before it writes anything, so a key that fails to fetch or verify leaves the preference, keyring and source as they were.

`tool:install`, `tool:adopt`, and `tool:update` take `repository` as the name of a repository of that Node. They refuse `tool.repository_missing` (422) when it does not exist and `tool.repository_not_ready` (409) when it is not ready. A Tool write never publishes or changes a repository. It writes only the per-package preference: the held one before its package write, then the confirmed one that adds the package once Orbit verified it from the repository. A Tool keeps the repository it references; change it by removing and installing the Tool again (`tool.repository_conflict`). Only an admitted install, adoption, update or convergence sets the reference; no package receipt or observation, on time or late, changes it.

`tool:apt-repository-remove` refuses with `tool.repository_in_use` while any Tool references the repository, then removes its source, keyring, and preference.

Only the newest Operation admitted to publish or remove a repository decides its state. An add, a removal, and each convergence that publishes it take that place at admission. A report of older work read later changes nothing.

A convergence publishes each repository its enrolled Tools reference before the packages, in database mode and in file mode, as that repository's own add: the outcome makes it `ready` or `failed`. A Node converge publishes a repository that is not ready whatever digest the Tools phase recorded.

In file mode, the catalog defines each repository. The add takes only the catalog's definition (`tool.not_in_catalog`, `tool.file_override`). A Node without a record of a catalog repository gets one with the catalog's definition, which the convergence publishes. When the catalog defines a name differently from the Node's record, install, update, adoption and convergence refuse with `tool.repository_conflict`: rename the entry in the catalog, or remove the repository and add it again.

Repository errors: `tool.repository_invalid`, `tool.repository_missing`, `tool.repository_not_ready`, `tool.repository_conflict`, `tool.repository_in_use`, `tool.repository_key_mismatch`, `tool.repository_unavailable`, `tool.repository_failed`, `tool.repository_remove_failed`, and `tool.repository_adoption_mismatch`.

### Upgrade from the preceding release

The upgrade keeps every apt repository record with its name and definition, and every Tool's reference to it, and marks each one `failed` with `tool.repository_unconfirmed`. It reads no history to decide whether a repository is on the Node as recorded. Run `tool:apt-repository-add` for each with its definition, or converge the Node, which publishes the repositories its Tools reference; until one of them succeeds no Tool write from the repository is admitted.

The upgrade replays the preceding release's Tool Operations in order for what Tool ownership needs.

Ownership needs an acquisition. A Tool is owned when one of its direct installs or updates succeeded with outcome `applied`. An `unchanged` success is an observation. So is a node converge that found the Tool installed: the preceding release recorded no per-Tool write. A Tool with no Operation keeps ownership only when it is installed.

An adoption keeps ownership. The preceding release recorded none, but every admission captured the Tool's `tool_status`, `installed_version` and repository link, and every converge plan its `installed_version`. Only an adoption makes a Tool installed after its latest receipt left it failed. It is also the only thing that changes a Tool's installed version or link away from the ones its history last recorded. Every projected receipt sets that version, an interrupted one included, and so does every converge observation.

So the final row that shows either names an adopted Tool, and a later converge that changed nothing keeps it owned. A version an interrupted or unchanged receipt explains stays an observation.

An adoption orders before every open Operation of its Tool admitted after the row was last written, and otherwise after every Operation, so no late receipt of older work sets back what it recorded.

Only the terminal of a bound native run was projected: an Operation the Gateway failed itself, by expiry or a refused or lost start, never touched the row and explains nothing. A removal whose run may have reached the package ends the claim, as the Gateway now reads such an end: one that failed after its decision, was cancelled after its run started, or ended after its expiry, a success included.

Only a removal that failed before its decision, or whose decision refused it, left the package untouched. So did one whose `dpkg` step was `dpkg` refusing to start: its whole diagnostic is the frontend or database lock line and `dpkg`'s note on it. A trigger's nested `dpkg` prints the same line after the removal, followed by the error processing the triggering package, so that end may have taken the package.

Work still open at the upgrade splits by whether its start may have been sent. A start may have reached the Node when the Operation has an attempt or a bound native run, or its start set it running. A queued Operation never started, even one whose start Swamp deferred (`not_ready`, `draining`), and neither did one whose start the Gateway or Swamp refused. An expiry alone proves neither: an Operation the Gateway expired was sent a start only when the deferral schedule shows a begin after its last deferral. One never sent a start ended without a run and changes nothing.

Queued work that never started keeps its Operation and idempotency key, and the current admission admits it again in place before its first dispatch. A direct request gets the scope, protection, membership, ownership, repository and pins a request admitted now would get. A write that named a repository therefore waits for that repository's add: it ends with `tool.repository_not_ready`, or `tool.repository_missing` when the preceding release never recorded the repository.

Readmission follows queue order, each request as it was admitted then: work queued earlier, and work that may have run, hold their scope, and work queued later is not there yet. A later request that overlaps earlier work is refused, as its own admission would have been, so no two contradictory plans are dispatched.

A convergence leaves out only the Tool a later queued first install created: an install admitted with no Tool, whose admission made the `installing` row. It keeps every other Tool the current capture plans, an adopted one included, also when that Tool's first direct request is a later reinstall. Its pins name that same set.

A convergence keeps its other phases. A full one, which the preceding release marks by its host release input, stays full even with no Tools planned. The current capture plans its Tools and the repositories they reference, and the convergence records the Tools scope, as `node:converge` does at admission.

A request the current admission would only record ends as that membership-only removal. One it refuses ends failed with the refusal's code, such as `tool.package_protected` or `tool.repository_not_ready`, and runs nothing. A refused first install also drops the `installing` record its own admission made. A replay of the request's idempotency key returns that outcome.

Work whose start may have been sent is settled by the upgraded Gateway when its end is read. Its report changes no repository and no Tool's reference: only a publication this release admitted decides a repository.

A removal among it ran under the preceding release, which removed whatever it found, so its end is read under that authority. A removal the preceding release expired with its run unsettled has its authority set aside, as the Gateway does at expiry, unless newer work changed it since.

Each field of a Tool, and each Tool Manager's state, keeps the order of the preceding release's newest receipt that changed it. A receipt that changed nothing orders nothing:
- An `unchanged` success orders no ownership.
- A cancel, or a run that read no manager version, orders no manager state.

A failure orders the version its decision read, by the preceding decision code, also when that decision is read after the upgrade. A refused decision repeats the admitted version whenever the package was absent, and a ready one records absence as none:
- An update reaches its write, or refuses its candidate, only with the package present, and recorded the version it read.
- A reinstall reaches either only after reading the package absent, and recorded the admitted version: that is an absence.
- Both hold for a reinstall refused by its version constraint, and for a write whose end check then failed to decode.
- Any other refusal after the read found the package present.
- A removal's decision returns no version, so its receipt only repeats the admitted one and orders nothing.
- A failure before the decision, or a manager scope's or a probe's refusal, read nothing.

A Linux cask's refresh refuses right after a successful read, present or absent, and repeats the admitted version for an absent cask. So no version recorded there is absence, since a present cask has one, and only the admitted version itself is ambiguous. A receipt without a version field shows nothing.

A late receipt of older work therefore never sets back newer work, and a newer one that changed nothing hides no older one.

## Why it works this way

### Managers on demand, independent of roles

Keeping Tool Managers independent of Node roles lets any SSH-managed Node install packages without coupling package state to application roles, while installing managers only on first use avoids bloating Nodes with unused toolchains.

### Selected adoption

Separating read-only discovery (`tool:scan`) from explicit `tool:adopt` ensures Orbit never takes ownership of or uninstalls unmanaged host software automatically. Protecting bootstrap and role-owned packages prevents `tool:remove` from breaking SSH, WireGuard, DNS, Docker, Caddy, or PHP.
