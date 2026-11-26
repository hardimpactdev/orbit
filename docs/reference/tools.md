---
title: "Tools"
description: "Which packages Orbit owns, and how to manage optional Tools, exact versions, and Gateway policy per Node."
covers:
  - swamp/extensions/behaviors/operations/tools/**
  - swamp/workflows/workflow-operation-tool-{write-209,remove-209,manager-setup,converge}.yaml
  - swamp/ansible/{operation-tool-converge.yml,tasks/node-converge-tool*.yml}
  - apps/gateway/app/{Providers/ApplicationServiceProvider.php,{Actions,Domain}/Tools/**,Data/Tools/**,Actions/Doctor/Intent/RuntimeDoctorIntent.php}
  - apps/gateway/app/Infrastructure/{Tools/**,Nodes/Roles/RolePrerequisites.php}
  - apps/gateway/{app/Models/{Tool,ToolManagerRecord,AptRepository}.php,database/migrations/{2026_12_03_000000_allow_cold_recipe_image_rebuilds,2027_01_09_120017_create_apt_repositories_table,2027_03_26_000000_add_tool_policy}.php,routes/api/tool.php,resources/machine-software/tools.yaml}
  - apps/agent/src/commands/{vp_store,vp_scope,vp_global,tools_apt,composer_global,homebrew_scope,homebrew,tool_output}{.rs,/**}
  - apps/gateway/app/Http/{Controllers/Api/{ToolsController,ToolManagersController,ToolInventoryController,ToolPolicyController}.php,Requests/Tools/**}
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
| An optional host package selected for a Node | A Gateway Tool record. The active Tool mode assigns the version, constraint, and source. |
| A Conn Server running as a host service | Gateway Tool membership pins a release. Only the protected [Conn Server rollout](/reference/development-containers#conn-server-rollout) changes the service. Neither Tool convergence nor container convergence updates it. |

## Tool modes

There is one mode per Gateway, and membership stays per Node in either mode. An existing Gateway enters database mode without adopting, installing, updating, or removing any package. Existing observed versions stay observations, not new pins.

| Mode | Who sets the coordinate |
| --- | --- |
| `database` | `tool:install` and `tool:update` can set an exact desired version for one Node's Tool. With no pin, the manager selects a candidate within the optional constraint. |
| `file` | One `tools.yaml` on the Gateway declares exact versions and optional constraints and repositories. Use the release's default catalog or supply an absolute path that the Gateway can read. |

In file mode, adding a catalog entry does not register or install a Tool on any Node. Use `tool:install` or `tool:adopt` to add membership, and `tool:remove` to remove it. Install and adoption refuse packages outside the catalog (`tool.not_in_catalog`); install, update, and adoption refuse per-command overrides (`tool.file_override`). Adoption writes no package and requires the observed version to match the catalog (`tool.installed_version_mismatch`).

The catalog has schema `1` and a list of packages, each with `manager`, `package`, `version`, and optional `constraint` and `repository`. Quote version strings. Replace the active file atomically and run [`tool:converge`](/cli/tool/converge) on the affected Nodes. Orbit does not watch the file or change packages in the background. Keep an entry until all its memberships have been removed: convergence refuses a missing entry instead of deleting or uninstalling the Tool.

```yaml
schema: 1
packages:
  - manager: apt
    package: ripgrep
    version: '15.1.0-1ubuntu1'
```

### Switch modes without running Node work

Export before switching so you have a copy of the current policy and memberships.

1. Inspect the mode with `orbit tool:mode`. Save `orbit tool:mode --export=snapshot --json` to preserve membership, desired coordinates, observations, and the rollout eligibility of configured host Conn Servers.
2. To enter file mode with existing Tools, use `orbit tool:mode --export=catalog --json`. Write its `document` to `tools.yaml` on the Gateway, then run `orbit tool:mode:set file --catalog=/absolute/path/tools.yaml`. Omit `--catalog` to use the shipped file.
3. To return to database mode, run `orbit tool:mode:set database`. The database takes the current file coordinates and any unapplied signed source definitions for the existing memberships. It keeps observed versions and verified sources separate.

A catalog cannot express different desired coordinates for the same manager and package across Nodes, or a database Tool without an exact version. Export reports `tool.mode_conflict` rather than selecting one version or inferring a pin from observation. Activation also refuses missing or different coordinates, a removed or renamed verified source, and any active or retained Tool execution hold. A successful change stores a snapshot of the prior policy and returns its `snapshot_id`; it starts no Node Operation. The same contracts are available through `GET /api/v1/tool-policy`, `PUT /api/v1/tool-policy`, and the Gateway MCP tools.

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

Linux full [`node:converge`](/cli/node/converge) includes all eligible Tools; role-only and base-only runs do not. Use `--tool-id=ID` on `tool:converge` to select specific memberships without resolving unrelated catalog entries. Packages sharing a signed apt source must agree on that source even if only one is selected. Convergence runs Ansible from the Swamp control Node over pinned SSH. The phase deadline scales with the number of selected Tools, up to the execution limit. If the phase times out, Orbit retains completed Tool observations. Direct package installs, updates, and removals use native SSH with the same admission and package policy.

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

Adoption block tokens are `protected`, `dependency`, `unsupported_artifact`, `unsupported_source`, `bottle_unavailable`, `version_unreadable`, `authorization_required`, and `removal_pending`.

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

`POST /api/v1/tools/adopt` (`orbit tool:adopt <package> --node=<id> --manager=<manager> [--constraint=<range>] [--repository=JSON] --yes`) runs **synchronously** in the Gateway against the agent's latest `packages` fact (creating no Operation and contacting no Node) and returns HTTP `201` with the Tool record and `agent` liveness.

Accepted fields (unknown fields return `422 validation.failed`): `node_id` (int), `manager`, `package`, optional `version_constraint` (SemVer range), and optional `repository` (for `apt`, verifying the matching `/etc/apt/sources.list.d/orbit-<name>.sources` and `/etc/apt/keyrings/orbit-<name>.gpg` fingerprint).

Adoption requires a single installed version, no adoption block, and constraint compliance (`tool.not_observed`, `tool.package_absent`, `tool.installed_version_unparseable`, `tool.installed_version_constraint_violated`, `tool.repository_adoption_mismatch`, `tool.constraint_conflict`, `tool.state_invalid`, or `tool.adoption_unsupported`, all HTTP 409).

## Install and update a Tool

`tool:install` and `tool:update` return HTTP `202` with an [Operation](/reference/operations) serialized under one per-`(node, manager)` lock (`operation.target_busy`, 409, on contention). If install finds an already-installed unmanaged package on the Node, the admitted Operation fails with `tool.package_already_installed`; adopt it with `tool:adopt` first. Optional SemVer `version_constraint` blocks out-of-range candidates (`blocked_by_constraint` / `tool.version_constraint_blocked`).

## Homebrew formulae

`brew` accepts only unqualified lowercase Homebrew Core formulae with a checksummed compatible bottle (never building from source or running `brew ruby`/`brew irb`). Orbit computes the bottle tag from `uname -m` (`x86_64_linux` on Linux; `sw_vers` mapped to `big_sur`..`golden_gate` with `arm64_` prefix on Apple silicon). Every `brew` and `brew-cask` call sets `HOMEBREW_NO_AUTO_UPDATE`, `HOMEBREW_NO_ANALYTICS`, `HOMEBREW_NO_ENV_HINTS`, `HOMEBREW_NO_INSTALLED_DEPENDENTS_CHECK`, and `HOMEBREW_NO_INSTALL_CLEANUP`.

### Homebrew casks

On macOS and on Linux Nodes where the agent reports Homebrew, `brew-cask` accepts unqualified official `homebrew/cask/<token>` casks with `https` URLs, lowercase SHA-256 checksums (`no_check` refused), and user-home or Homebrew-prefix artifacts (fonts, binaries, man pages, completions). Install and update set `HOMEBREW_FORCE_API_AUTO_UPDATE` on both platforms. A comma-separated build suffix is part of the exact coordinate and stays in Tool records and snapshots, while direct install and update receipts display the version before the comma. SemVer constraints apply to that abbreviated version. Casks targeting `/Applications`, system paths, package installers, or privileged uninstalls return `authorization_required`; preflight/postflight/arbitrary scripts and disabled casks return `unsupported_artifact`.

## Remove a Tool

`tool:remove` returns HTTP `202` with an Operation. It removes only the record for a protected or role-owned package.

### Ownership

A Tool owns its package only when Orbit acquired it: its own verified package write, an explicit adoption, or a convergence that installed the package for that Tool. Finding the package already present is an observation, not an acquisition, also when a run succeeds without writing it.

A convergence's installed observation acquires the package only when its plan was admitted under this contract, whose helper refuses a present package Orbit does not own. A preceding-release request or convergence read after the upgrade therefore observes, never acquires. A verified write stays an acquisition even when a later step of the same run fails. Failures, refused or interrupted retries, and another Tool's repair of the shared manager never grant ownership.

A removal that may have taken the package ends ownership. That covers a removal that verified the package gone, one that found it already absent, and one whose package step ran without leaving the package in place. `dpkg --remove` can remove the package and still exit nonzero when another package's trigger fails. After a failed package step the run checks the package again.

Only a package step that never ran, or one the check finds the package still present after, keeps the claim, as when a held dpkg lock refuses before any write. A check that finds it gone, or that could not read it, ends the claim. The check asks only whether the package is there, not whether its version is the one an install would target.

Any remaining cleanup is then the source's alone, and a retry never removes a replacement package installed since. A source-only cleanup never reads or touches the package, so it changes no ownership.

The run's own receipt decides, whatever its end. A failed or cancelled run that recorded a verified acquisition or a confirmed absence keeps it, and so does the Tool row it applies to.

A run that ends after the Gateway timed its Operation out counts the same way. The Operation stays failed with `operation.timed_out`, but when the reconciler reads the run's terminal, its receipt counts for the Tool as it would have on time. A convergence report counts each valid observation it carries, installed or failed, also when its phase was partial or its run was cancelled. A Tools phase that finished proves its sources; one that never ran wrote nothing; a partial one proves nothing.

Receipts can arrive out of order, so each one changes only what no newer receipt changed. The Tool's state, its ownership and its installed version each keep the newest Operation whose receipt changed them, and so does a Tool Manager's state. A version counts only when the receipt observed it: a refused decision that repeats its admitted version observed nothing.

A refusal observed the version only when it read the package, and a read that found the package absent observed its absence. A newer request that changed nothing, because it was refused before its package step or cancelled before its run, never hides an older receipt. An explicit adoption orders after every earlier Operation, so no late receipt sets back what it recorded.

A run is given the uninstall authority its admission recorded, and its receipt is read under that same authority, whatever the Tool holds by then: a timeout that set the claim aside changes how nothing the run did is read. Work the preceding release left open carries the authority the upgrade recorded for it. Missing authority is none: the executor never removes a package without it, and an update refuses a package Orbit does not own.

Desired state belongs to admissions: each direct request and each convergence sets it when admitted, and a mode switch sets it too, so no receipt sets it back. A removed Tool stays removed, and a receipt deletes a source record only while no newer Operation may have published it and no Tool links it.

Until then, uncertainty grants nothing and keeps no right to remove a package. A timed-out removal leaves its Tool waiting for `tool:remove` without uninstall authority, because the package may already be gone and a replacement may follow. That holds even when the start reply that binds the run never arrived: a sent start is no proof that nothing ran. A retry, also after the target hold lapses, therefore cleans only the record and source.

A removal whose start was never sent keeps its authority when it times out. It was never begun, or Swamp's deferral answered its latest begin. The deferral schedule shows that when the latest begin came too soon after the first deferral for a begin after the last one.

Only proof that the removal never reached its package gives the authority back, with the state the Tool had before the timeout set it aside, a preceding-release admission's included. That proof is a receipt showing the package step never ran, including one the reconciler reopens because its cancellation beat the deadline, or Swamp verifying that the lost start never became a run. A start whose reply was merely lost proves nothing.

An install or convergence never takes over a present package for a Tool Orbit does not own. Both refuse with `tool.package_already_installed`, including after a first install that was cancelled, timed out or returned an unreadable result. Such an interrupted first install stays `installing` until you retry or remove it. You cannot update a Tool Orbit does not own.

For a Tool Orbit does not own, removal deletes only the record and needs no SSH target or host key. If an earlier attempt may have written its apt source or key, the removal instead runs on the Node with `package_owned: false`. It skips the manager check and every package read and plan, cleans the source or repins the remaining members, and never removes the package. A refused or cancelled first install that may have written them stays as a failed Tool for that cleanup.

A Tool whose removal is unfinished stays `failed` with `failed_operation: remove`. It is not a membership. Convergence, apt preferences, catalog export, mode changes and Doctor leave it out. `tool:scan` reports its package as unregistered with `adoption_block: removal_pending`, adoption refuses it with `tool.state_invalid`, and only `tool:remove` acts on it.

For an apt package removal, Orbit simulates removal first (`apt-get --simulate remove`) and refuses plans that remove any other package (`tool.removal_plan_unsafe`), then runs `dpkg --remove -- <package>` without purging configs or autoremoving. On success, the Tool row is deleted and the response reports `status: removed`, `outcome: applied`. When the last member's removal cannot confirm that both the source and its preference file are gone, the Tool stays `failed` with `tool.repository_failed`. Run the removal again to finish the cleanup.

Ownership and membership answer different questions. Ownership decides which packages the source's preference prefers. Membership decides whether the source stays: removing the last owned member keeps the source, with every package at priority `1`, while another enrolled member still names it, even one that does not own its package yet. Only the removal of the last member that names the source removes it.

A removal that repins surviving members names the source's origins. Orbit refuses it with `tool.repository_unverified` while the source is unverified, because an uncertain write may have changed which origins are on disk. Update or converge a surviving member first: that write publishes the source again.

## Signed apt repositories

An `apt` Tool definition can include a `repository` object (`name`, `uris`, `suites`, `components`, optional `architectures`, `signing_key.url`, and 40/64-hex `signing_key.fingerprint`; reserved names `caddy` and `php` are refused). In database mode pass it as JSON on install, adoption, or update; in file mode declare it in the catalog. Orbit verifies the downloaded key's primary fingerprint before writing `/etc/apt/keyrings/orbit-<name>.gpg`, `/etc/apt/sources.list.d/orbit-<name>.sources`, and `/etc/apt/preferences.d/orbit-<name>.pref` (priority `990` for owned packages, `1` for other packages from its origin). apt matches `Pin: origin` against the URI's host as written: an IPv6 literal without its brackets and not shortened, so `[0:0:0:0:0:0:0:1]` stays `0:0:0:0:0:0:0:1`. Orbit writes it that way. Removing the last Tool referencing a repository cleans up its source, preference, and keyring.

One URI and suite belongs to one source per Node: apt stops every read when two source files give one entry different `Signed-By` keyrings. Install, update, adoption, convergence and a mode switch refuse with `tool.repository_conflict` a source whose name differs from a source a Tool wants, or that a recorded source may still have on disk, on the same URI and suite. A record keeps every URI and suite an uncertain write may have published under its name, even after an adoption names an older definition again. The refusal comes before any Operation or record. Use one name, or a distinct URI.

Repository errors: `tool.repository_invalid`, `tool.repository_conflict`, `tool.repository_unverified`, `tool.repository_key_mismatch`, `tool.repository_unavailable`, `tool.repository_failed`, and `tool.repository_adoption_mismatch`.

### Source trust

All Tools sharing a repository on one Node must agree on its definition. An unchanged verified source does not need another key download on a direct write.

Every Orbit source keeps every other package of its origins at priority `1`, from the moment it is published. Both executors first write the held preference, before the key and source: priority `990` only for the packages Orbit owns that share the source, and, within one convergence, the Tools of the source the same run already installed. The package being written is not among them until it is acquired, so no interruption, from the key fetch to the decode, can leave an unacquired package preferred.

Candidate selection needs no preference: it reads only Orbit's source list and installs the exact version it chose.

Only after Orbit verifies the package from the source does the executor write the confirmed preference, the package with it. A failure of that write fails the run but keeps the acquisition. A membership that has not acquired its package is never preferred by a sibling's write either. A failed write retains the requested repository separately from the last verified source.

While the source is uncertain, the held preference covers every origin it may have on disk. A change of a source from one origin to another can fail after either is published, so the record collects every URI and suite admitted under its name. Each held preference keeps all their origins at priority `1`. The confirmed preference names only the source's current origins. apt then chooses packages from an origin the source has left, such as an independent repository on that host, without any Orbit preference. Orbit's own successful write resets the record to what it published.

Within one convergence, once a Tool of the source is verified installed, every later preference of that run, a refused sibling's held one included, names the confirmed origins alone. The last preference on disk then agrees with the record the completed phase verifies.

The Node keeps a record of each source Orbit may have on disk. Orbit creates it when it admits the first write that may publish the source, and deletes it only when a removal confirms the source and its preferences are gone.

A write may change the key, source or preference on disk before it fails, is cancelled, times out, or returns an unreadable result. So each admitted direct write or removal that touches the source, and each convergence that includes it, is an open writer of the record until its end settles it, and the record is not verified meanwhile. Each end settles as one of three:

- Proved: Orbit's own successful write that published the key and source.
- Proved too: a finished Tools phase that verified a Tool of the source installed.
- Uncertain: a run that may have written, such as a partial phase.
- Uncertain too: a failure after a source write, or an end without a readable receipt.
- Unwritten: a run whose readable history shows no source write that ran. Each one was skipped, never reached, or absent from the workflow.
- Unwritten too: a successful write admitted with a verified source, which skips publication, and a removal that repinned the survivors. Both rewrite preferences only.
- Unwritten too: a run cancelled before it started, or one Swamp verifies never started.
- Unwritten too: a convergence whose run recorded its Tools step skipped.

The newest settled end decides. An older end, late or not, changes nothing a newer one settled, and an end that wrote nothing changes no trust: it only stops being a writer. The source is verified when the newest settled end proved the definition the record holds and no newer writer is open.

Missing evidence is never a skip. Every write has its record from its admission, or from the upgrade for work the preceding release left open. A record that is gone was withdrawn by a newer confirmed removal: no older end brings it back, and an older write's success neither recreates nor links it. Only an Operation that may write a source settles it, so the end of one that never could changes no trust. A late end settles its source even when its Tool is gone since. 

A first publication that stops at its key fetch leaves a record that is not verified, so the retry fetches the key and publishes the source. While the record is unverified, the next direct write publishes the key and source again.

An older failure does not outlive a later verified write. An adoption reads a Node agent fact that may predate a write, so it never marks a source verified. It settles the record unproven for the definition it names, newer than any Operation admitted before it, so no older end gives that definition trust.

Each convergence plan carries whether its source was verified at admission. A plan with an unproven source also carries the ID of the converge that captured it. So every `node:converge` runs the Tools phase until a write proves the source, however often its trust was lost since the last recorded digest. A Tools phase that does not run wrote nothing.

A removal cleans or repins a source as the record now defines it, so surviving members keep their current origin.

A full convergence, `tool:converge` without Tool IDs or `node:converge`, also removes the key, source and preference of every recorded source that no Tool names. Orbit forgets such a source only after the Tools phase that removed its files succeeded. Each source is its own cleanup, so two sources whose packages share a name are both cleaned.

Remove the membership before dropping or renaming a source that is verified or that an earlier attempt may have published; neither convergence nor a mode switch can bypass that rule. A mode switch also refuses `tool.repository_conflict` while two Tools on one Node define a shared source differently.

#### Upgrade from the preceding release

The upgrade that adds these records replays the preceding release's Tool Operations in order.

Ownership needs an acquisition. A Tool is owned when one of its direct installs or updates succeeded with outcome `applied`. An `unchanged` success is an observation. So is a node converge that found the Tool installed: the preceding release recorded no per-Tool write. A Tool with no Operation keeps ownership only when it is installed.

An adoption keeps ownership. The preceding release recorded none, but every admission captured the Tool's `tool_status` and `installed_version`, and every converge plan its `installed_version`. Only an adoption makes a Tool installed after its latest receipt left it failed. It is also the only thing that changes a Tool's installed version away from the one its history last recorded. Every projected receipt sets that version, an interrupted one included, and so does every converge observation.

So a capture or the final row that shows either names an adopted Tool, and a later converge that changed nothing keeps it owned. A version an interrupted or unchanged receipt explains stays an observation. An admission that found the Tool's link captured it too: a removal's source, a write's that named none, and each converge plan's source are the linked record's definition. A write that named a source shows nothing of the link, because the record it found by that name may be a sibling's.

A link other than the one the Tool's last success left names an adoption from that source, ordered before that admission.

Only the terminal of a bound native run was projected: an Operation the Gateway failed itself, by expiry or a refused or lost start, never touched the row and explains nothing. A removal whose run may have reached the package ends the claim, as the Gateway now reads such an end: one that failed after its decision, was cancelled after its run started, or ended after its expiry, a success included.

Only a removal that failed before its decision, or whose decision refused it, left the package untouched. So did one whose `dpkg` step was `dpkg` refusing to start: its whole diagnostic is the frontend or database lock line and `dpkg`'s note on it. A trigger's nested `dpkg` prints the same line after the removal, followed by the error processing the triggering package, so that end may have taken the package.

Work still open at the upgrade splits by whether its start may have been sent. A start may have reached the Node when the Operation has an attempt or a bound native run, or its start set it running. A queued Operation never started, even one whose start Swamp deferred (`not_ready`, `draining`), and neither did one whose start the Gateway or Swamp refused. An expiry alone proves neither: an Operation the Gateway expired was sent a start only when the deferral schedule shows a begin after its last deferral. One never sent a start ended without a run and changes nothing.

Queued work that never started keeps its Operation and idempotency key, and the current admission admits it again in place before its first dispatch. A direct request gets the scope, protection, membership, ownership, pins and source claim a request admitted now would get. Queued requests do not refuse one another for overlapping only in the preceding release's queue; work that may have run still holds its scope.

A convergence keeps its other phases. A full one, which the preceding release marks by its host release input, stays full even with no Tools planned. The current capture plans its Tools and claims their sources, and the convergence records the Tools scope and its orphan cleanup, as `node:converge` does at admission.

A request the current admission would only record ends as that membership-only removal. One it refuses ends failed with the refusal's code, such as `tool.package_protected` or `tool.repository_conflict`, and runs nothing, cleanup included. A refused first install also drops the `installing` record its own admission made. A replay of the request's idempotency key returns that outcome.

Work whose start may have been sent is settled by the upgraded Gateway when its end is read. It is an open writer of a source only when its run could write it: a write with `repository_given`, a last member's removal, or a convergence.

A removal among it ran under the preceding release, which removed whatever it found, so its end is read under that authority. A removal the preceding release expired with its run unsettled has its authority set aside, as the Gateway does at expiry, unless newer work changed it since. A write that asked for a source sets the Tool's desired source, unless a newer adoption or a confirmed removal of that source followed, or another membership defines the shared source differently.

Each field of a Tool, and each Tool Manager's state, keeps the order of the preceding release's newest receipt that changed it. A receipt that changed nothing orders nothing:
- An `unchanged` success orders no ownership.
- A failure at the step its decision gates read the inventory, so it orders the version.
- So does a failure at the end check after any refusal but a manager scope's or a probe's.
- A failure before that read recorded the admitted version again, and orders nothing.
- The preceding receipt cannot tell a read of the admitted version from a read of absence: after either it shows the admitted version.
- A cancel, or a run that read no manager version, orders no manager state.
- An adoption orders just before the first admission that captured it, by status, version or link.
- An adoption no admission captured orders after every Operation.

A late receipt of older work therefore never sets back newer work, and a newer one that changed nothing hides no older one.

Trust needs a write that ran. A source is verified only when its latest event is a successful write that published it (`repository_given: true`) with the recorded definition, or a converge whose Tools phase ran and installed a Tool from it. A write that skipped publication, and a converge whose Tools phase was skipped, neither prove nor doubt the source.

A run that never reached a source write, by the preceding workflows, neither proves nor doubts the source either:
- Any removal that kept the source: the preceding removal workflow has no repin step.
- A last member's removal refused by its decision (`tool.removal_plan_unsafe`), or failed before its decision.
- A write without `repository_given`.
- A write whose recorded failed step comes before publication, such as the key fetch.

An interruption without a failed step may have written anything, so it doubts the source. An adoption is not a write. A definition that no event or open writer of the history explains is an adoption's: it settles unproven, so no late end of older work gives trust to another definition under its name.

It orders as the newest adoption of a Tool linked to that record: by what that Tool's admissions captured, else before the Tool's open Operations admitted after the row was last written, else after every Operation. The record keeps every URI and suite its history may have published since its last confirmed write until a write proves it.

Cleanup belongs to the source. Every source whose latest event is not a confirmed removal keeps a record, whatever package names collide. The next full convergence removes it once no Tool names it. No membership is created for it. A confirmed removal withdraws the source from every writer admitted before it, so only a newer open writer keeps its record, under that writer's definition, never the removed one.

A failed install that wrote its source without linking it takes back the source of its own latest request while that request is still pending. An earlier possible publication never becomes desired state over a later request without a source, an installed Tool, or a confirmed removal of that source name after the request, even when another Tool later reuses the name.

## Why it works this way

### Managers on demand, independent of roles

Keeping Tool Managers independent of Node roles lets any SSH-managed Node install packages without coupling package state to application roles, while installing managers only on first use avoids bloating Nodes with unused toolchains.

### Selected adoption

Separating read-only discovery (`tool:scan`) from explicit `tool:adopt` ensures Orbit never takes ownership of or uninstalls unmanaged host software automatically. Protecting bootstrap and role-owned packages prevents `tool:remove` from breaking SSH, WireGuard, DNS, Docker, Caddy, or PHP.
