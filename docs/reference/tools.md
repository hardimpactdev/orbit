---
title: "Tools"
description: "Where Tools run, the supported Tool Managers, how Orbit discovers, adopts, installs, updates, and removes one package, and why the contract stays closed."
covers:
  - swamp/extensions/behaviors/operations/tools/**
  - swamp/workflows/workflow-operation-tool-{write-209,remove-209,manager-setup}.yaml
  - apps/gateway/app/{Providers/ApplicationServiceProvider.php,{Actions,Domain}/Tools/**,Data/Tools/**,Actions/Doctor/Intent/RuntimeDoctorIntent.php}
  - apps/gateway/app/Infrastructure/{Tools/**,Nodes/Roles/RolePrerequisites.php}
  - apps/gateway/{app/Models/{Tool,ToolManagerRecord,AptRepository}.php,database/migrations/{2026_12_03_000000_allow_cold_recipe_image_rebuilds,2027_01_09_120017_create_apt_repositories_table}.php}
  - apps/agent/src/commands/{vp_store,vp_scope,vp_materialize,vp_global,tools_apt,composer_global,homebrew_scope,homebrew,tool_output}{.rs,/**}
  - apps/gateway/app/Http/{Controllers/Api/{ToolsController,ToolManagersController,ToolInventoryController}.php,Requests/Tools/**}
---

# Tools

A Tool is one package that Orbit manages on one Node through one Tool Manager. The Node, the manager, and the package name identify it. Orbit records the Tools it installs or explicitly adopts. Discovery reports other installed packages without creating Tool records. [`tool`](/cli/tool) lists the commands.

The [machine software recipe](/reference/compute-drivers#machine-software-recipe) defines the versioned Ubuntu snapshot, machine tool pins, and role tool sets. Its read-only loader and validator do not run Tool operations or change today's enrollment and image programs. Project packages and their lockfiles remain separate from machine software.

The `vpn` role uses stock dnsmasq for [private DNS](/reference/private-dns); DNS needs no PHP runtime. Cluster Router preparation installs `dnsmasq-base` for its dedicated resolver. The shared DNS publication owns resolver configuration and service changes, separately from user-managed Tool operations.

Recording a development [Instance branch rename](/cli/instance/rename) does not install, update, adopt, or remove packages. The Gateway keeps branch inspection separate from Tool manager operations; rename does not rerun Project setup.

[Importing workspace CI caches](/reference/instance-setup#import-ci-main-caches) restores private test and analysis data, not Tools or packages. It does not call a Tool Manager, fill a package download cache, or install a missing dependency. The Project's setup steps must install dependencies before importing these caches.

## Native execution

Install, update, and remove return HTTP 202 with an [Operation](/reference/operations). Adoption answers at once from the Node agent's facts; see [Adopt a Tool](#adopt-a-tool). The Gateway validates the request, records immutable intent and the target Node, and starts one native Swamp workflow. CLI writes follow the Operation. List, show, scan and manager list keep their read paths.

Every step runs on control Node 209. Install, update and removal use official cfgmgmt resources and one bounded SSH argv per manager command. Official package checks supply the installed version or absence. Typed worker steps apply constraints and decode package observations; their contract test reads its version table from `composer-constraints.json`, one constraint per line. The Gateway reads the native run and fixed public captures; no result-reporting step runs. Package phases send no Python source to the Node.

A signed apt source stages its key, reads it with one `gpg --with-colons --show-keys` command, and uses a native assert to require exactly the admitted primary fingerprint. Only then does it publish the keyring, package preferences and source. Completion cleanup removes the staging file, including after a failed fingerprint check. The shared manager setup uses one version argv for readiness and official apt prerequisites. It installs each manager from pinned downloads with official `@swamp/cfgmgmt` resources and SSH argv; no installer script ships.

| Workflow | Steps |
| --- | --- |
| Install, update | `Prepare package commands`, optional manager and signed-source setup, parallel manager and package reads, `Check the candidate`, `Choose package action`, `Apply the package`, apt check or manager query, `Decode package state`, `Assert package state` |
| Remove | `Prepare package commands`, parallel manager and package reads, `Plan the removal`, `Choose package action`, `Remove the package`, apt check or manager query, optional source and preference removal, `Decode package state`, `Assert package state` |

### Install and update steps

Steps carry the exact labels of the reviewed flow charts ([ADR 0246](/decisions/0246-show-task-progress-through-one-resumable-read)). Notes on the Swamp lane identify typed work on 209.

- `Set up the Tool Manager` installs the manager at its pinned version on a Linux first use. A ready manager skips it.
- `Write the apt source` runs only with `--repository`, after worker-side download, fingerprint inspection and a native assertion. Binary key publication, text files and scoped apt refresh remain separate steps.
- `Read installed package` reads current package state; `Check the candidate` reads the manager's normal candidate. Both are individual commands.
- `Choose package action` applies the refusals and the constraint before the package write. A missing candidate fails the install.
- On an update, the same version is `unchanged` and a version outside the constraint is `blocked_by_constraint`. Neither writes.
- `Apply the package` writes the candidate for every manager. `Check apt package` uses the official apt check; `Check the package` queries other managers. `Decode package state` shapes the observation, and `Assert package state` enforces it.

The Gateway writes the records:

- Install records the Tool as `installing` at admission.
- A failed manager setup drops that record. A package that was already installed drops it too.
- A repository definition is stored only when the run succeeds.
- A failed update keeps the old repository definition.

Package output and credentials stay out of public results. The Gateway projects bounded version, outcome and error fields after native completion. A removal with nothing on the Node starts no run (R1): a role-owned package, or a failed install that never left a version. The Gateway deletes the record and records a succeeded Operation at once.

### Removal steps

Removal runs these steps in order. It stops at the first step that fails, except the best-effort last step.

1. `Plan the removal` reads the package and what removing it would also remove. Apt reads `apt-get --simulate remove`. Brew reads the installed formulae that still use the package. Composer and Vite+ global packages need no plan.
2. `Choose package action` refuses a plan that removes any other package, a Composer non-root, or an unsafe cask.
3. `Remove the package` removes exactly that package: dpkg as root, every other manager as the managed account.
4. `Check apt absence` or `Check it is gone` must observe absence.
5. `Remove the apt source` uses official `apt_repository` to drop both the source and key of a signed repository's last Tool. `Remove repository pins` removes its preference file. Both steps are best-effort.

When the apt source cannot be removed, the removal still succeeds and the Tool is deleted. The apt repository record stays, and the next install from that source reuses it.

There is no separate manager kind. `Set up the Tool Manager` is one shared child workflow, `operation-tool-manager-setup`: `tool:install` runs it on a Linux first use, and a development deploy runs it for Vite+. Native role add uses that child for Composer and Vite+, with convergence enabled so an existing manager receives the recipe's pinned resources. Ordinary first use still skips a ready manager.

During convergence, official APT checks compare every package with its pin before artifact downloads. Matching packages need no download or installation. Missing pins form one APT transaction; Composer's managed directories and configuration still converge when all packages already match. Folder and file resources within one manager run sequentially to bound cfgmgmt dispatch memory. Separate Node work can still run in parallel.

`node:add` needs no apt setup: when `node.bootstrap_host` succeeds, the Gateway records apt as ready. Project runtime environments belong to the Instances family. Input, ownership and platform refusals happen before admission. Scan returns its discovery report directly and creates no Tool intent. A kind without a native declaration returns `operation.kind_unavailable` (HTTP 503).

## Where Tools run

The Gateway manages Tools only on an active Node that it manages over SSH. That Node runs a supported platform, has a verified WireGuard address, and has a stored SSH host fingerprint. Ubuntu supports the existing managers. [macOS Nodes](/reference/node-provisioning#macos-nodes) support Homebrew formulae, casks, and Vite+ global packages in the enrolled account's existing installations. Any other Node gets `tool.node_inactive` or `tool.node_unmanaged` (HTTP 409) before the Gateway changes anything.

Manager setup captures the ordered recipe artifact candidates at admission. Pinned Debian prerequisites, including Git for Composer, download from the [artifact mirror](/reference/compute-drivers#the-recipe-artifact-mirror) at its pinned checksum. The structured curl exit code permits upstream fallback only after a transport failure. Local I/O, size-limit and checksum failures stop setup.

Tool Managers do not belong to roles. A Node with no role can use every manager its platform supports. `apt` and `composer` stay Linux-only. `brew` and `vp` run on Linux and macOS. `brew-cask` runs on macOS only.

## Tool Managers

The Gateway has five managers, fixed in code. There is no plugin registry and no generic script manager. Formulae and casks have separate manager identities, so the same package name cannot select the wrong package kind.

| Manager | Platforms | Package | Scope | Becomes ready |
| --- | --- | --- | --- | --- |
| `apt` | Linux only | An Ubuntu package name | The Node's package database | When `node:add` converges a Linux Node |
| `composer` | Linux only | A `vendor/package` name | Orbit's shared Composer global scope | On first Linux use, or with `app-dev` or `app-prod` |
| `vp` | Linux and macOS | An npm package name | The enrolled account's Vite+ global scope | Linux: first use or an application role. macOS: verified in place |
| `brew` | Linux and macOS | A verified Homebrew Core bottle | `/home/linuxbrew/.linuxbrew` on Linux; the existing prefix on macOS | Linux: pinned revision on first use. macOS: verified in place |
| `brew-cask` | macOS only | An official Homebrew cask | That macOS prefix and its casks; shares the `brew` lock | Verified in place. Orbit does not install it |

`apt` and `composer` are not offered on macOS, and `brew-cask` is not offered on Linux. Install, update, removal, and adoption return `tool.manager_unsupported` (HTTP 422) before a lock or SSH. An absent or conflicting scope still returns `tool.manager_unavailable` (HTTP 409).

On Linux, `composer` uses `COMPOSER_HOME=/opt/orbit/composer`. `vp` scan and Linux materialization skip account-owned directories without `bin/vp`, such as a cache-only `~/.vite-plus/package_manager/npm` directory. They choose the first Vite+ store with `bin/vp` for the enrolled account, in this order: `/opt/orbit/vite-plus`, `~/.vite-plus`, then `~/.local/share/vite-plus`. The Node agent reads its [packages fact](/reference/node-agent#packages) from the same scope, without running `vp`; the Gateway names the home candidates from the managed user, `/home/<user>`, without asking the Node. Install, update, removal, and scan all use that store. A later store does not change the choice. The Gateway may install a missing Linux manager on first use.

A task VM's base image runs the Linux `vp` and `composer` materialization programs at build time. A task start copies the base snapshot and runs none of those programs. Explicit Tool installs and updates do not use this enrollment skip and still run their manager commands. It still reads the manager version as usual. Every other Node runs the programs at enrollment. See [The base VM and its snapshot](/reference/compute-drivers#the-base-vm-and-its-snapshot).

Linux Homebrew first use runs a separate native installer after its OS prerequisites. It stages the pinned official revision and verifies its version before publishing the account-owned prefix. Failure removes the staging directory. Existing conflicting prefixes are refused. Scan keeps its read-only scope inspection.

On macOS, the agent reads the enrolled account from the native account database and returns its home and one verified prefix. It checks `/opt/homebrew`, `/usr/local`, `$HOME/homebrew`, and `$HOME/.homebrew`. A conflicting candidate or more than one valid candidate refuses the whole inspection. The command runs as the enrolled account and changes no files or Homebrew revision. Formula and cask operations use this same scope result.

`tool.homebrew-formula-inventory` returns typed Homebrew observations. The agent verifies the scope before running Homebrew, uses the fixed environment below, and parses bounded output on the Node. Formula inventory preserves ambiguous and unreadable versions for the Gateway to report; it does not turn them into absent packages.

Native cask writes use official SSH queries and separate typed policy checks and mutations. They resolve the Node's variation and check artifact destinations against the enrolled account's home and verified Homebrew prefix. Homebrew retains its package locks. Removal checks uninstall safety without applying install-time version, checksum, or disabled gates. A delisted official cask can be removed by its plain token only after its installed metadata passes those checks.

Native Composer package operations use fixed shared-scope arguments through official SSH. The candidate query parses the normal dry-run and resolves an explicit no-op from installed inventory. A typed check applies the admitted version constraint and root ownership before mutation.

Native Swamp Composer convergence installs the fixed Linux prerequisites, creates the account-owned scope, and verifies Composer as that account. It refuses conflicting paths before setup and preserves an existing owned manifest.

The shared Tool Manager setup installs a missing Linux Vite+ scope from the verified JavaScript recipe pins. It fetches each pinned artifact, checks its digest, extracts it and writes the managed launchers with official resources. It runs no downloaded installer. A failed step has no rollback; run the install again after you fix the cause. A development deploy runs the same shared setup for the Instance's account.

Managed-account recipe commands run from that account's home directory, including JavaScript installation, inventory, launcher checks, and image verification. They change directory after switching accounts and set `VP_HOME` to the resolved store. A caller's working directory cannot select workspace configuration or prevent the account from running a tool.

Orbit's `/usr/local/bin` Vite+ launchers export `VP_HOME` for every resolved store, not just `/opt/orbit/vite-plus`. Instance setup, teardown, and task checks also export it so project-local `vp` uses the same store. Tool materialization, recipe convergence, and application-role convergence publish the same launcher format. Tool materialization accepts an exact, root-owned legacy launcher for that store and upgrades it with rollback on failed runtime verification; changed or foreign launchers remain conflicts. Recipe convergence and application-role convergence both send `recipe.install-javascript`, which replaces any root-owned launcher with the recipe's launcher.

On macOS, `vp` is the enrolled account's existing Vite+ global store. The scope probe also skips account-owned directories without `bin/vp`. `brew` and `brew-cask` use the existing Homebrew prefix. The agent resolves that account's home from the machine and does not assume `/home`, `getent`, or a Linux bottle tag. It does not install, replace, or repin those scopes, and it does not check out a Homebrew revision.

The read-only `tool.inspect-vp-scope` agent command resolves the enrolled account's Vite+ binary with native account and filesystem APIs. It returns the selected path or a typed absent, conflicting, or unreadable result. Scope discovery runs no shell, installs nothing, and changes no launchers.

Vite+ package writes use bounded argv through official SSH from the admitted account scope. Typed native checks apply package constraints and ownership. The Gateway stores Tool records. Mutations run as the enrolled account and never return raw manager output.

A missing scope is scan state `absent`. A scope with the wrong owner, a non-official Homebrew origin, or a dirty Linux Homebrew tree is scan state `conflicting`. On macOS, two Vite+ stores with `bin/vp`, or one store with the wrong owner or shape, is `conflicting`. On Linux, the chosen Vite+ store is `conflicting` when it is a symlink, not a directory, owned by someone else, or its present `bin/vp` is not executable or is owned by someone else. A second Linux store is not a conflict. Adoption, install, update, and removal then return `tool.manager_unavailable` (HTTP 409). Orbit does not repair that scope.

An accepted macOS Homebrew prefix is owned by the enrolled account and has origin `https://github.com/Homebrew/brew`. Apple silicon and an untar-anywhere install are the git repository: `prefix/.git` exists and `prefix/bin/brew` is a regular executable. The Intel `/usr/local` layout keeps the nested repository `prefix/Homebrew/.git` and a `prefix/bin/brew` symlink to `../Homebrew/bin/brew`. Any other shape is conflicting.

The probe prints the prefix and the account home. The script accepts only `/`, ASCII letters, digits, `.`, `_`, and `-` in that home. It stops when the home contains `..` or any other character, including a space, `@`, `+`, or a non-ASCII letter, so the probe fails and no prefix is accepted. A home of `/` is ignored after a successful probe: the prefix stands and the home is unknown. A cask target is user-owned only when the home is known and contains the target. A font under that home, such as `font-fira-code`, is supported. A cask that needs an administrator prompt, such as `stats`, stays `authorization_required`.

`orbit tool:manager:list --node=<id>` shows each manager the Node's platform supports, with one of these states. An unsupported manager is omitted, not listed as `uninstalled`.

| State | Meaning |
| --- | --- |
| `uninstalled` | The Node supports the manager, but Orbit has not installed it yet. It has no ID. |
| `provisioning` | Orbit installs or checks the manager. |
| `active` | The manager is ready. |
| `failed` | Installing or checking the manager failed. The record keeps the failed step and error code. The next install tries again. |

Before each Linux install, Swamp converges the manager: it installs the manager when it is missing and checks its version. A failed Operation records `tool.manager_provision_failed` (HTTP 502), keeps the manager `failed`, and creates no Tool. On macOS, install rechecks the existing scope and returns `tool.manager_unavailable` (HTTP 409) when that scope is absent or conflicting. It creates no Tool. An absent macOS scope stays `failed` at step `manager-absent` with error code `node.tool_manager_absent`. A conflicting owner, origin, or Vite+ store stays `failed` at step `manager-conflict` with error code `node.tool_manager_conflict`. A failed macOS probe returns `tool.manager_provision_failed`.

Update and removal use the same `tool.manager_unavailable` code when the macOS scope is absent or conflicting. The Tool stays `failed` for that operation, with its installed version kept, and the same command retries it. A probe that is not an absent or conflicting scope still returns `tool.version_probe_failed`, `tool.update_failed`, or `tool.remove_failed`. A manager stays installed after its last Tool is removed. No command removes a manager.

### The dpkg lock

Native APT removal first reads `apt-get --simulate remove -- <package>`. It refuses a plan containing another package. The mutation uses `dpkg --remove -- <package>` so dependency changes after the plan cannot select additional removals. A separate check must prove absence before success. The command uses dpkg's own dependency checks and lock, with no lock wait, purge or autoremove: dpkg has no wait option. A failed removal keeps the Tool and its recorded version for an explicit retry.

Tool installs and updates wait for a busy apt or dpkg lock, for example while unattended-upgrades runs. They pass `-o DPkg::Lock::Timeout=300`, the same as Caddy, PHP and the retained shared manager prerequisite. The wait stays inside the phase budget; after it the install fails with `tool.install_failed` or `tool.update_failed`.

| Caller | Command | Lock wait |
| --- | --- | --- |
| First-use prerequisites (`brew`) | `apt-get update` | None. It takes the lists lock, `/var/lib/apt/lists/lock`, which `DPkg::Lock::Timeout` does not cover, and fails at once. |
| First-use prerequisites (`brew`) | `apt-get install --no-remove` of `build-essential`, `procps`, `curl`, `file`, `git`, and `ca-certificates` | 300 s |
| First-use prerequisites (`composer`) | Snapshot-pinned package transaction | 300 s for the install; its `apt-get update` fails at once on a busy lists lock. |
| `apt` install and update | `apt-get install --no-remove` | 300 s |
| `apt` remove | `dpkg --remove` | None. dpkg refuses at once. |

Version probes, `apt-cache policy`, and the removal plan `apt-get --simulate remove` do not wait.

A Node inside the nested subnet of a task VM reads the snapshot indexes for the `composer` transaction through the package cache of that VM (`http://10.232.2.1:3142`, one request at a time) and downloads the packages directly. Any other Node reads everything directly over https. The apt runs of enrollment and role prerequisites on a nested Node also go through that cache; see [Role prerequisites](/reference/node-provisioning#role-prerequisites).

A first-use setup failure on `tool:install` returns `tool.manager_provision_failed` (HTTP 502). The manager stays `failed` at step `materialize` with error code `node.tool_manager_materialization_failed`, and Orbit creates no Tool. Only a failed package install marks the Tool `failed`. Retry the install; a manager or apt source set up before the failure stays, and the retry reuses it.

A `composer` prerequisite failure during `app-dev` or `app-prod` convergence leaves the manager `failed` at the same step and error code. The role or `node:add` reports `node.tool_manager_materialization_failed` in `details.error_code`. [Role operations on one Node](/reference/node-provisioning#role-operations-on-one-node) describes how a failed role reports its step.

An `apt` install, update, or remove returns `tool.install_failed`, `tool.update_failed`, or `tool.remove_failed` (HTTP 502). The Tool stays `failed`. Update and removal keep the recorded version. Retry the same command.

## Project environments in the Vite+ store

Swamp executes Project environment installation and pruning for the Instances family. The Gateway Tools adapter keeps its scan read boundary.

Before a JavaScript deploy step, Orbit reads the release's manifest, lockfile, and Node version files as data without running Project code. The lockfile selects the exact vite-plus release, including catalog, workspace, npm aliases, and prereleases. Node must have an exact pin in `.node-version`, `.nvmrc`, or `engines.node`, in that order. The pin must also satisfy Project and locked vite-plus engine constraints. Ranges and moving tags cannot silently change the deployed runtime.

An npm or pnpm lockfile selects the package-manager family. An exact `packageManager` pin (or exact package-manager engine or lockfile metadata) supplies its version and must agree with that family. Missing or ambiguous locked requirements fail before build. Registry metadata is read only to install missing content, using the Project's `.npmrc` registry and credentials.

The machine recipe owns vp itself. Deploys never upgrade it. A recipe version older than the locked vite-plus version fails the prerequisite and needs a normal recipe update. When all exact runtimes exist, the prerequisite takes a read-only path without a lock. Installation waits for the store-owner lock with a timeout and rechecks content after acquiring it.

The enrolled account is the only store writer and owns every runtime in it. Recipe and deploy installation use one Node layout: a real, immutable directory at `js_runtime/node/<version>`, with no symlink components. The Rust agent shares path validation, locking, adoption and publication between recipe convergence and the `tool.inspect-vp-store` command. Existing real content that matches the archive stays in place, including content installed by a deploy. A differing directory stops recipe installation without deleting it.

Convergence migrates every account-owned link into the exact former content-addressed recipe layout, preserving its old target for running processes. Migrated older versions receive no checksum receipt or recipe reference, so collection retains them as adopted content. Other links are refused. The publisher removes group and other write bits from account-owned real store directories before validation; foreign ownership and symlinks remain refusals. This rule also applies to development stores in the enrolled account's home.

Vite+ manager convergence also removes group and other write bits from existing account-owned `package_manager/npm/<version>` and `package_manager/pnpm/<version>` directories and their store parents. It takes the store lock, refuses symlinks or foreign ownership before changing permissions, and preserves runtime files and execute bits. Runtime inspection remains read-only and refuses writable store paths.

The recipe records its current Node version in `.orbit-recipe-node.json` under the installation lock. Collection retains that version even when no Instance references it, including when a deploy originally installed it. The existing checksum ledger stays unchanged on adoption. A real runtime without this recipe reference needs one verified adoption on convergence. The recipe, deploy installer and collector use an account-owned, regular mode-0644 lock; they normalize older account-owned lock permissions without replacing its inode and refuse symlinks.

The Gateway sends each Project deploy script unchanged, with an optional typed Vite+ environment. Instance preparation and the Tool Manager use the same version check: the observed manager must meet the Project's pinned Vite+ version, including the CLI's `vp v1.4.0` output format. The agent validates the store and exact runtime requirements before writing the private script file. It selects the store's `vp env exec` with fixed arguments, sets `VP_HOME`, and runs the whole script with `bash -eu` as the Instance owner. Flags, quoting, pipelines, and multiple build commands keep their meaning. The enrolled account owns the shared store; production builds run as the separate Instance account.

When that store sits below a private enrolled-account directory, production preparation grants only the Instance account traversal through those ancestors. It grants no directory listing or write access and leaves private files protected by their own permissions. Publicly traversable ancestors and the store contents stay unchanged.

Each Instance records its resolved store path, vp version, Node version, package manager, package-manager version, checkout path, and prerequisite status in `vp_environment`. The preparation plan carries its public version pins under `requirements`; `environment` is reserved for protected environment-variable maps. Preparation retains earlier checkout references, including after installation or a failed build. The selected release and every retained release keep their runtimes, so rollback can select retained code without fetching or rebuilding its prerequisites.

Native environment operations validate their selected store and refuse unreadable or invalid state.

See [Deploy prerequisites](/reference/deployments#vite-store-prerequisite) for build execution and early failures.

## Discover installed packages

`GET /api/v1/tool-inventory?node_id=<id>` is `tool:scan`. The CLI command is `orbit tool:scan --node=<id>`. The only input is `node_id`, a strict integer of an existing Node. Any other query or body field fails with `validation.failed` (HTTP 422). This operation is not a script API.

The scan lists `brew`, `brew-cask`, `vp` and apt packages from the [Node agent's](/reference/node-agent) latest `packages` facts. It reads nothing on the Node. It does not inventory `composer`. It compares packages with Tool rows by Node, manager, and package. It stores nothing and creates no Tool row. It does not take a manager lock and it does not materialize a manager.

The response has no paths, environment values, or credentials. Registered apt packages include their declared repository URLs and pinned public signing fingerprint. Success is HTTP 200.

| Field | Type | Meaning |
| --- | --- | --- |
| `data.node_id` | integer | The Node. |
| `data.observed_at` | string | UTC time of the oldest fact the scan used, such as `2026-04-26T12:00:00+00:00`. The request time when no manager was observed. |
| `data.managers` | array | Exactly four objects, in the order `brew`, `brew-cask`, `vp`, `apt`. |
| `data.managers[].manager` | string | `brew`, `brew-cask`, `vp`, or `apt`. |
| `data.managers[].scan_state` | string | `complete`, `unsupported`, or `not_observed`. |
| `data.managers[].packages` | array | Package facts when `scan_state` is `complete`. Otherwise empty, and not an inventory. |
| `meta.request_id` | string | The request id. |

| Scan state | Meaning |
| --- | --- |
| `complete` | The agent reported this manager. The package array is the inventory and may be empty. |
| `unsupported` | This platform does not offer the manager. `brew-cask` is unsupported on Linux; `apt` is unsupported on macOS. |
| `not_observed` | The agent has not reported this manager. `tool:scan` prints "not observed yet". |

A manager is `not_observed` while the agent is new or silent, when the manager is not installed, and on a Node that runs no observing agent, such as a macOS Node. The agent watches every manager a Linux Node can run, recorded or not. A manager removed after the agent reported it keeps its last fact until the agent reports its absence.

Packages inside a `complete` manager are ordered by package name, ascending. A formula and a cask that share a name stay on different managers.

| Field | Type | Meaning |
| --- | --- | --- |
| `manager` | string | The enclosing manager. |
| `package` | string | Unqualified package name. |
| `package_kind` | string | `formula`, `cask`, `global`, or `deb`. |
| `installed_version` | string or null | Normalized version, at most 255 characters, or null. |
| `dependency` | boolean | True for a Homebrew formula not installed on request. False for a root formula, cask, Vite+ package, or deb. |
| `registered` | boolean | True when a Tool row exists for this Node, manager, and package. |
| `tool_id` | integer or null | That Tool's id, or null. |
| `adoption` | string | `supported` or `unsupported`. |
| `adoption_block` | string or null | Null when adoption is supported. Otherwise a block token. |
| `repository` | object or null | Shared signed repository intent for a registered apt Tool. Null for other packages. |

The block tokens are `protected`, `dependency`, `unsupported_artifact`, `unsupported_source`, `bottle_unavailable`, `version_unreadable`, and `authorization_required`. A dependency or a [protected package](#protected-packages) is never `supported`. The facts give these blocks:

- `protected`: a protected or role-owned name.
- `unsupported_source`: a name Orbit cannot manage, or a formula from a tap other than `homebrew/core`.
- `dependency`: a formula not installed on request.
- `unsupported_artifact`: every cask, because the fact carries no cask artifacts.
- `version_unreadable`: no version, several installed versions, or `latest`.

The scan shows a normalized SemVer version, or null when the version does not normalize, such as a Debian revision. Such a package can still be `supported`; adoption without a constraint records its raw version. `tool:scan` prints a null version as an em dash. The Tools page shows `unreadable`.

Scan errors use the [Tool error envelope](#errors). `details.step` is `scan`, `details.outcome` is `manager_failed`, and there is no Tool `id`. A manager state of `unsupported` or `not_observed` does not fail the HTTP request.

| Code | HTTP | When |
| --- | --- | --- |
| `tool.node_inactive` | 409 | The Node is not `active`. |
| `tool.node_unmanaged` | 409 | The Node has no WireGuard address or no pinned SSH fingerprint. |
| `validation.failed` | 422 | The query or body is not the one `node_id` field. |
| `node_access.required` | 403 | The caller cannot address the Node. |

## Protected packages

Some installed packages keep SSH, the WireGuard tunnel, DNS, Docker, the firewall, sudo, Caddy, or a manager working. Adopting one would let `tool:remove` uninstall it. These names are protected. The set is closed and depends on the manager.

| Manager | Protected package names |
| --- | --- |
| `apt` | `acl`, `attr`, `bind9-dnsutils`, `ca-certificates`, `caddy`, `composer`, `curl`, `dnsmasq`, `docker.io`, `git`, `gnupg`, `libnss-resolve`, `openssh-client`, `openssh-server`, `openssl`, `php-curl`, `php-xml`, `sudo`, `ufw`, `unzip`, `wireguard`, `wireguard-tools` |
| `brew` | `wireguard-tools`, `wireguard-go` |
| `vp` | `pnpm` |

The apt names are every package `NodeBootstrapPackageCatalog` returns from `forNode` and `forRole`, plus `openssh-server` and `wireguard-tools`. The Gateway reads that catalog at the check. It does not keep a second copied list. The table is that union.

`composer` and `brew-cask` have no protected names. The [role-owned PHP packages](#role-owned-php-packages) are protected for `apt` too. The apt package `composer` is protected. The `composer` manager is a different scope and has none. A protected package still appears in scan, with `adoption` `unsupported` and `adoption_block` `protected`. Install, update, and adopt refuse it with no new Tool row. Install and update return `tool.package_protected` (HTTP 409, outcome `manager_failed`). Adopt returns `tool.adoption_unsupported` with that block token. `tool:remove` of an existing row returns `tool.package_protected`, leaves the row, and does not run the uninstaller. `tool.removal_plan_unsafe` does not replace this list. A plan that removes only `docker.io` or only `dnsmasq` is still refused.

### Role-owned PHP packages

The PHP role owns the [recipe's PHP build](/reference/compute-drivers#php-build). The apt manager also protects every `php<version>-*` package, for any version, and the recipe's shared PHP pins, `php-common` and `libgd3`. Adopt returns `tool.adoption_unsupported` with block `protected`. Install and update return `tool.package_role_owned` (HTTP 409, outcome `manager_failed`) before they take a lock or run a command. A Tool that moved a PHP package would break the one PHP build that every machine runs.

A Tool record that an earlier release adopted for such a package stays until you remove it. `tool:remove` of that record deletes only the record. It runs no package manager command, so the package stays installed at its recipe version. The result is the usual `status` `removed` with outcome `applied`. For example, on Shark:

```bash
orbit tool:list --node=<shark-node-id>
orbit tool:remove 23 --yes
orbit tool:remove 24 --yes
```

## Adopt a Tool

`POST /api/v1/tools/adopt` is `tool:adopt`. The CLI command is `orbit tool:adopt <package> --node=<id> --manager=<manager> [--constraint=<range>] --yes`. MCP and the Tools page call this endpoint. They do not get a second adoption API. Adoption installs, updates, removes, and repins nothing.

The JSON object allows only these fields. Any other field fails with `validation.failed` (HTTP 422).

| Field | Required | Type | Meaning |
| --- | --- | --- | --- |
| `node_id` | yes | strict integer | An existing Node id, at least 1. |
| `manager` | yes | string | `apt`, `composer`, `vp`, `brew`, or `brew-cask`, at most 32 characters. |
| `package` | yes | string | That manager's package name, at most 255 characters. |
| `version_constraint` | no | string or null | A SemVer range, such as `^14.0`, at most 255 characters. Omit or null for none. |

The Gateway checks the [Node agent's](/reference/node-agent) latest `packages` fact for that manager and records the Tool in one short transaction. It starts no task and no Swamp run, and it reads nothing on the Node. The [adopt chart](/cli/tool/adopt) shows the flow. A discovery row is not evidence; the fact at request time is.

The fact must show the package installed with exactly one version, no adoption block, and a version that fits `version_constraint`. With a `repository`, the fact must also show the apt source `/etc/apt/sources.list.d/orbit-<name>.sources` that install writes for that definition, and the keyring `/etc/apt/keyrings/orbit-<name>.gpg` with one primary key of the admitted fingerprint. The Gateway then records the repository and links it.

Success is HTTP 201 with the Tool and the agent's liveness in `agent`, with `state` `online`, `offline`, `not_seen`, or `unknown`, and `since` while it is `offline`. The CLI prints the liveness first. While the agent is offline, the answer comes from the facts it reported before it went silent, and the CLI marks it possibly outdated. A Node without an observing agent has `agent: null`.

| Field | Type | Meaning |
| --- | --- | --- |
| `id` | integer | The Tool id. |
| `node_id` | integer | The Node. |
| `manager` | string | The manager name. |
| `package` | string | The package name. |
| `version_constraint` | string or null | The stored SemVer range, or null. |
| `status` | string | `installing`, `installed`, `updating`, `removing`, `failed`, or `removed`. `removed` is response-only and is never stored, so list and show never return it. Adoption success is `installed`. |
| `installed_version` | string or null | The version the fact shows. It may be non-SemVer when unconstrained. |
| `failed_operation` | string or null | `install`, `update`, or `remove` after a failed mutation. Null on adoption success. |
| `error_code` | string or null | The last mutation error, or null. |
| `outcome` | string or null | `applied` or `unchanged` on adoption. |
| `agent` | object or null | Adoption only: the agent's liveness. |

| Result | `outcome` | Record |
| --- | --- | --- |
| No Tool yet for this Node, manager, package, and constraint | `applied` | Created as `installed`. The host package is unchanged. |
| The same constraint exists, status is `installed`, and the package is still accepted | `unchanged` | `installed_version` set to the reported version. |
| The same constraint exists, status is `failed`, and the package is still accepted | `applied` | Repaired to `installed`, with the failure cleared and `installed_version` set. The host package is unchanged. |

A Tool Manager row that does not exist yet is created `active`, with no recorded manager version. A different constraint on an existing Tool is `tool.constraint_conflict`. It does not create a second row. A row left in `installing`, `updating`, or `removing` is not repaired. Adopt returns `tool.state_invalid` and leaves that row. The caller retries the original operation or removes the Tool.

Errors use the Tool envelope. `details.step` is `adopt`. `details.id` is included only after the Gateway has found an existing Tool row. `tool.adoption_unsupported` also includes `details.adoption_block`. `details.agent` carries the agent's liveness, as on success.

| Code | HTTP | `details.outcome` | When |
| --- | --- | --- | --- |
| `tool.manager_unsupported` | 422 | `manager_failed` | The manager is unknown, or the platform does not offer it. |
| `tool.package_invalid` | 422 | `manager_failed` | The name fails that manager's grammar. |
| `tool.constraint_invalid` | 422 | `constraint_invalid` | The constraint is not a SemVer range. |
| `tool.node_inactive` | 409 | `manager_failed` | The Node is not `active`. |
| `tool.node_unmanaged` | 409 | `manager_failed` | No WireGuard address or pinned SSH fingerprint. |
| `tool.manager_unavailable` | 409 | `manager_failed` | The recorded Tool Manager is not `active`. |
| `tool.not_observed` | 409 | `manager_failed` | The agent has not reported this manager yet, or the Node runs no observing agent. |
| `tool.package_absent` | 409 | `manager_failed` | The fact does not show the package. |
| `validation.failed` | 422 | none | The body has an unknown field or a field of the wrong type. No Tool lookup. |
| `node_access.required` | 403 | none | The caller cannot address the Node. No Tool lookup. |
| `tool.installed_version_unparseable` | 409 | `manager_failed` | A constraint is set and the version is not SemVer. |
| `tool.installed_version_constraint_violated` | 409 | `manager_failed` | The installed version is outside the constraint. This is the same code install uses for that condition. |
| `tool.repository_adoption_mismatch` | 409 | `manager_failed` | The apt source or keyring on the Node does not match the `repository`. |
| `tool.constraint_conflict` | 409 | `manager_failed` | The existing Tool has another constraint. |
| `tool.state_invalid` | 409 | `manager_failed` | The existing row is `installing`, `updating`, or `removing`. `details.id` is set. |
| `tool.adoption_unsupported` | 409 | `manager_failed` | The package is protected, a dependency, or otherwise has no adopt path. |

`details.adoption_block` uses the [scan block tokens](#discover-installed-packages). `brew` adoption requires a formula from `homebrew/core` installed on request. The fact carries no bottle metadata, so a formula without a bottle for the Node fails at its first update rather than at adoption. Every cask is `unsupported_artifact`, because the fact carries no cask artifacts. `vp` adoption requires a root package in the enrolled account's global scope. `composer` adoption requires a root package of the shared scope. `apt` and `composer` can be adopted only on Linux. No adoption takes dependencies or any other package.

Install still refuses an unregistered installed package with `tool.already_installed_unmanaged` (HTTP 409). It does not adopt that package. The caller uses `tool:adopt`.

On Linux, unconstrained adoption records the apt version string, such as `5.8.3-1`. A constraint is checked only when that version normalizes to SemVer. `^1.25` accepts `1.25.0-2ubuntu4`. A version that does not normalize returns `tool.installed_version_unparseable` and creates no Tool row.

## Install a Tool

A caller sends the Node, the manager, the package name, an optional version constraint, and an optional [signed apt repository](#signed-apt-repositories). Each manager checks the package name against its own grammar and builds a fixed command with the name in one argument position. A caller cannot send commands, manager options, or environment values.

The Gateway refuses a package that is already on the Node without a Tool record, with `tool.already_installed_unmanaged` (HTTP 409). Use explicit [adoption](#adopt-a-tool) to take ownership.

When a Tool operation checks whether a package is installed, it uses the manager inventory. An individual version check selects its package from that inventory; it has no separate parser. Nonempty Homebrew version lists are validated one value at a time. An unmanageable package name is excluded from this inventory. An unreadable version makes only that package unverifiable. Failed or truncated inventory reads remain unverifiable.

A new install records its Operation, installs the package, and reads the installed version. A success records the Tool as `installed` and reports `applied`. A package-phase failure retains a `failed` Tool, so the same command can retry it or removal can drop it. A manager failure creates no Tool. List Tools to find the retained record. Running the same install again retries a Tool whose install failed. An install of a Tool that is already `installed`, with the same constraint, checks the package again. When the package is present, the result is `unchanged`.

The optional constraint is a SemVer range, such as `^0.150`. It only stops an unsafe version. The Gateway rejects malformed constraints and records the original range string. Swamp's Tool policy validates the candidate and installed version against that range. The Gateway neither compiles version intervals nor evaluates package candidates.

A candidate outside the range records the Tool as `failed` with `tool.version_constraint_blocked` (HTTP 422, outcome `blocked_by_constraint`) and installs nothing. Remove that row, or retry with a constraint the candidate satisfies. The Gateway never searches for another matching version and never downgrades. When the candidate is not SemVer, the same failed row is kept and the code is `tool.candidate_version_unparseable`. A Tool keeps its constraint: installing it again with another constraint fails with `tool.constraint_conflict`.

## Update a Tool

`tool:update` asks the manager for its current candidate and installs it. The result is `applied` when the version changed and `unchanged` when it did not. When the candidate falls outside the stored constraint, the update changes nothing and reports `blocked_by_constraint`. The Tool stays installed. A Homebrew formula that is pinned stays pinned. Update reports `unchanged` and does not clear the pin. If the macOS Homebrew prefix or Vite+ scope is absent or conflicting, update returns `tool.manager_unavailable` (HTTP 409), marks the Tool `failed`, and leaves the recorded version in place.

## Homebrew formulae

The `brew` manager accepts one lowercase formula name from Homebrew Core, without a tap prefix. The name may end in `+`, as in `libsigc++` or `gtk+`. A native query reads the formula's metadata on the Node. Swamp requires the `homebrew/core` tap, a stable version, and a compatible bottle with a SHA-256 checksum. Separate native install and update actions force bottle use. Removal targets only the named formula. These actions refuse protected packages and accept no executable, environment, tap, or manager options. Orbit never builds a formula from source. Inventory uses `info --json=v2 --formula --installed` and `list --formula -1`.

Orbit never runs a Homebrew developer command. That includes `brew ruby` and `brew irb`. A developer command writes `homebrew.devcmdrun` into the user's Homebrew git config and changes how a later `brew update` behaves. Orbit does not set `HOMEBREW_DEVELOPER` or `HOMEBREW_DEV_CMD_RUN`.

The compatible tag is computed by the agent. On macOS it reads the product version with the fixed command `sw_vers -productVersion` and uses the CPU stored from `uname -m`. A code-owned table maps that version to Homebrew's active bottle symbol. The Gateway does not store the OS version on the Node.

| Product version major | Bottle symbol |
| --- | --- |
| `27` | `golden_gate` |
| `26` | `tahoe` |
| `15` | `sequoia` |
| `14` | `sonoma` |
| `13` | `ventura` |
| `12` | `monterey` |
| `11` | `big_sur` |

When the major is 11 or higher, Orbit uses that major. It does not turn the display alias `10.16` into `big_sur`. A version with no row, including major `10`, has no compatible bottle. Apple silicon uses `arm64_` plus the symbol, so macOS 27 is `arm64_golden_gate`. Intel macOS, `x86_64`, uses the symbol alone. Any other macOS CPU has no compatible bottle. Update this table when Homebrew adds a symbol. Until then that OS has no compatible bottle.

Linux does not use the table. `x86_64` maps to `x86_64_linux`. Any other Linux CPU, including `aarch64`, has no compatible bottle.

A bottle file keyed by that tag is compatible. A file keyed `all` is compatible only when the current tag is absent. An older OS tag, such as `arm64_sequoia` on macOS 27, is not compatible. If neither the current tag nor `all` has a checksummed file, the formula is refused before any change. Adopt uses block `bottle_unavailable`.

| Input | Result |
| --- | --- |
| A Homebrew Core formula with a compatible bottle for the Node | Accepted |
| A tap-qualified name, a cask, a URL, a local file, or a Git reference | Refused before any change |
| A formula without a matching bottle | Refused before any change |

On Linux, the Gateway installs Homebrew at a pinned revision. When the prefix already exists, the Gateway accepts it only when the managed user owns it, its origin is the official Homebrew repository, and its working tree is clean. It then checks out the pinned revision. Otherwise it leaves the prefix unchanged and marks the manager `failed`. Tool operations never start or stop a Homebrew service.

On macOS, Orbit verifies and reuses the existing Homebrew installation without checking out another revision and without running `brew update`. Formula operations still force compatible bottles. Updating one root formula may install or upgrade the dependencies that formula requires. It does not upgrade other root packages or dependents that merely depend on it.

Every `brew` and `brew-cask` command sets `HOMEBREW_NO_AUTO_UPDATE`, `HOMEBREW_NO_ANALYTICS`, `HOMEBREW_NO_ENV_HINTS`, `HOMEBREW_NO_INSTALLED_DEPENDENTS_CHECK`, and `HOMEBREW_NO_INSTALL_CLEANUP`. Those last two stop install and upgrade from upgrading unrelated packages or running periodic cleanup. Scan does not set `HOMEBREW_FORCE_API_AUTO_UPDATE`. Install, update, and the adopt bottle check on macOS do set it, so Homebrew can refresh its API data while `HOMEBREW_NO_AUTO_UPDATE` still blocks a git update of the user's Homebrew revision. Linux keeps the pinned revision and does not force that API refresh. Its metadata freshness stays the behavior already shipped with the pinned prefix.

## Homebrew casks

`brew-cask` supports official Homebrew casks on macOS. The caller sends one unqualified token. A token may end in `+`, as in `logi-options+`. The Gateway turns that into the fixed coordinate `homebrew/cask/<token>` and refuses a tap, a URL, a local file, or a caller option before it runs anything. A formula and a cask with the same token stay different Tools, because the manager is part of the identity.

Before install or update, the Gateway reads `brew info --json=v2 --cask` for that coordinate. The cask must come from the `homebrew/cask` tap, use an `https` URL, and name itself with the same unqualified token. The Gateway then applies one refusal, in this order: an unsupported artifact, interactive or administrator authorization, the checksum policy, then an unreadable version.

`brew info --json=v2` returns the current arm64 definition as the base fields. Other Mac definitions are shallow overrides under `variations`, keyed by the same bottle tag formulae use (`sw_vers` plus the code-owned symbol table; arm64 prefixes the symbol, Intel uses the symbol alone). The Gateway applies `variations[<tag>]` before it checks the URL, version, checksum, and artifacts. `sha256` must be one lowercase SHA-256 on that definition. `no_check` fails the checksum policy. Discovery reports that checksum failure as `unsupported_artifact`.

Supported artifacts install into the enrolled user's home or the Homebrew prefix. That includes fonts, binaries, man pages, completions, and user plugins whose target is `/$HOME`, `~/`, or `$HOMEBREW_PREFIX`. `brew info --json=v2` expands a default home target to that account's absolute home, such as `/Users/mini/Library/Fonts/Hack.ttf`. The same expansion applies to uninstall `trash`, `delete`, and `rmdir` paths. An absolute path inside the resolved home is still the user's home.

An app or suite that targets `/Applications`, another account's home, a package installer, a privileged plugin, or an uninstall that uses `pkgutil`, `kext`, `launchctl`, or a system path is `authorization_required`. Preflight, postflight, stage-only, and arbitrary uninstall scripts are `unsupported_artifact`. A disabled cask is too. Zap stanzas are ignored and never run.

Removal does not repeat the disabled, checksum, version, or source gates. It still refuses an arbitrary uninstall script, a package installer, an app in `/Applications`, or any other uninstall that needs interactive or administrator authorization. A disabled, unchecksummed, or `latest` cask can still be removed when that uninstall is safe.

When the official cask is gone, Homebrew says `homebrew/cask/<token>` is unavailable because the tap is not installed. Removal then reads that token from `info --json=v2 --cask --installed`. It uninstalls only when that entry's tap is `homebrew/cask`, and it passes the plain token to `uninstall --cask`. The same bottle-tag override applies.

Install and update use `install --cask` and `upgrade --cask` for the coordinate. Removal of an available cask uses `uninstall --cask` for that coordinate. Inventory uses `info --json=v2 --cask --installed` and `list --cask -1`. A cask that `list` names and `info` omits stays in discovery with `unsupported_source`.

The installed-version read uses `info --json=v2 --cask --installed`. Only an entry from `homebrew/cask` counts as the official Tool. A same-token cask from another tap does not count. Discovery reports it as `unsupported_source`; adoption returns `tool.package_absent` (HTTP 409) and creates no Tool. An unreadable installed version affects only that cask.

The Gateway runs brew over a non-TTY SSH session with `BatchMode=yes`, so sudo cannot prompt. Install, update, and the metadata read that guards them also set `HOMEBREW_FORCE_API_AUTO_UPDATE`. Inventory, removal, and installed-version reads do not. The Gateway never runs `brew uninstall --zap`, `brew autoremove`, `brew upgrade` without the cask, `brew services`, or a Homebrew developer command.

An unsupported installed cask stays in discovery with `adoption` `unsupported` and one block token. Discovery does not create a Tool and does not mean the cask can be adopted. A supported cask can be installed, updated, and removed as a normal Tool. A policy refusal or a failed command returns that operation's own error: `tool.install_failed`, `tool.update_failed`, or `tool.remove_failed` (HTTP 502). The Tool stays `failed` and can be retried. The error never includes raw Homebrew output. Adoption of a cask that needs an interactive or administrator prompt uses `tool.adoption_unsupported` with `adoption_block` `authorization_required`. Formula and cask operations share the Homebrew prefix lock, including when their manager records have different ids.

## Remove a Tool

`tool:remove` removes an `installed` or `failed` Tool. Every success returns the Tool object with `status` `removed` and `outcome` `applied`. This covers an actual removal, a package that was already absent, and a failed Tool with no probed version. `removed` appears only in that response. It is never stored, so list and show never return it.

Removal runs from Node209 through official SSH with the captured account and full host pins. The workflow reads installed facts, checks the selected package, removes it, and verifies absence before terminal projection.

For `apt`, a separate `apt-get --simulate remove` read must name only the selected package. The mutation uses `dpkg --remove` for that exact package. Native dpkg dependency checks refuse a later conflict without removing other packages. Orbit never runs an autoremove. A Tool whose package is gone is deleted.

`apt` removes a package without purging its configuration files. The Gateway treats a package that dpkg lists as removed with only its configuration left as absent.

| Condition | Result | Tool record |
| --- | --- | --- |
| The package is already absent | Success, `status` `removed` and `outcome` `applied`, with no manager command | Deleted |
| The Tool failed with `tool.version_probe_failed` and never recorded a version | Success, `status` `removed` and `outcome` `applied`, with no probe | Deleted |
| The removal succeeds and the package is gone | Success, `status` `removed` and `outcome` `applied` | Deleted |
| The version probe fails on a Tool with a known package | `tool.version_probe_failed` | Kept as `failed` |
| The macOS scope is absent or conflicting | `tool.manager_unavailable` | Kept as `failed` |
| The removal fails or the package stays | `tool.remove_failed` | Kept as `failed` |
| The package is a [role-owned PHP package](#role-owned-php-packages) | Success, `status` `removed` and `outcome` `applied`, with no manager command. The package stays installed | Deleted |

Retry the same command with the Tool ID. The Gateway reads the live package state before it acts again.

## Operation outcomes

Every Tool success body and every Tool error `details.outcome` uses one of these tokens.

| Outcome | Success operations | Meaning |
| --- | --- | --- |
| `applied` | install, update, remove, adopt | A mutation changed the package, adopt created a Tool without changing the host, or remove deleted the Tool. Every remove success uses `applied`. |
| `unchanged` | install, update, adopt | The requested intent already held. |
| `blocked_by_constraint` | update | The candidate is outside the stored constraint. Update leaves the Tool installed. Install records `failed` and installs nothing. |
| `constraint_invalid` | none | The constraint is not a SemVer range. |
| `candidate_version_unavailable` | none | The manager returned no candidate. |
| `candidate_version_unparseable` | none | A constraint is set and the candidate is not SemVer. |
| `manager_failed` | none | The Node or manager rejected the operation. |

Install returns `blocked_by_constraint` only inside `tool.version_constraint_blocked`, and only before it installs a candidate. Adopt does not use that code. An installed version that falls outside the constraint is `tool.installed_version_constraint_violated` for both install and adopt.

## Errors

A Tool error carries a stable `code`, a message, and `details`. The Gateway never stores or returns the raw output of a package manager.

| `details` field | Present |
| --- | --- |
| `step` | Always. Examples are `scan`, `adopt`, `install`, `update`, and `remove`. |
| `outcome` | Always. One token from [Operation outcomes](#operation-outcomes). |
| `id` | When a Tool row exists. An adoption or scan that has not found a row omits it. |
| `adoption_block` | Only on `tool.adoption_unsupported`. One scan block token. |

No other `details` keys are returned.

## Locks

Tool admission holds the existing Operation `lock_key` for the exact Node and manager until normal terminal projection releases it. A competing Tool install, update, remove or adopt on that pair returns `operation.target_busy` (HTTP 409) without starting a run. Idempotent replay returns the original Operation. A different Node or manager can admit work independently. This approved admission boundary covers all Tool writes. Package managers retain their own locks, including the shared Homebrew prefix and [dpkg lock](#the-dpkg-lock). Scan creates no Operation hold.

## Check removal with Doctor

Use the Tool removal result and native Tool scan to inspect installation state. [Doctor](/cli/doctor/doctor#what-each-family-checks) does not repeat package inventory, version constraints or account-scope checks. Its Process checks read the same Process list that names the units and awake markers the Node agent watches.

## Direct Swamp execution

Each Tool write records one Operation and starts its declared native Swamp workflow. The family's `direct-operations.php` is the sole registration. Install, update, adopt and remove run on Node209 through official SSH with the admitted target pins and existing209 identity.

Official cfgmgmt resources manage repository files and check apt package state. Package mutations use one bounded SSH argv because the pinned resources cannot express apt's no-removal policy or Homebrew's prefix, environment and bottle options. Typed checks apply package ownership, eligibility and version constraints; the native assertion enforces the observed end state.

The old standalone Tools runner and its workflows are deleted. Active Node and Instance materialization callers retain only their shared manager prerequisite lower. After an uncertain package reply, run the same command again: the write phase reads the package before and after its write, and an install of a package that is now present records it unchanged.

Swamp owns manager setup, package constraints, execution, retries, cancellation and recovery. The Gateway keeps request validation, managed ownership and bounded result projection. It records native status and Tool/manager state without step receipts or a second runtime/completion proof. Native Tool rows reject intent and result HTTP callbacks. Cancelling an admitted Tool Operation sends one cancellation request to its bound Swamp root; the native terminal subscriber records the outcome. An unconfirmed cancellation remains a request. Read-only scan stays in Orbit.


## Why it works this way

These reasons explain the design. Check them before you propose a change.

### A closed manager registry

Each manager is code with its own grammar, fixed commands, and tests. So the Tool API cannot run an arbitrary command. Package plugins, per-Tool definitions, generic scripts, and caller-supplied options are rejected alternatives. A new manager needs a new adapter in code.

### Explicit package ownership

An installation alone does not prove Orbit ownership. Discovery shows what exists; installation or explicit adoption establishes what Orbit may manage. Automatic adoption would turn a read into permission to update or remove unrelated software. Tool rows therefore hold selected intent, not host inventory. Orbit removes only the exact package it recorded, without autoremove.

### Managers on demand, independent of roles

A manager serves any Node that the Gateway manages. Tying `vp` and `composer` to application roles would make Tools depend on where applications run, and role removal would have to handle unrelated Tools. Installing every manager on every Node is also rejected, because most Nodes need few of them and would carry needless software. `apt` and `composer` stay Linux-only. `vp` uses the enrolled account's global scope on Linux and macOS. macOS reuses that scope and the existing Homebrew prefix instead of installing a second copy.

### Bottled Homebrew Core only

The formula manager accepts only Homebrew Core bottles with verified checksums, on Linux and macOS. The macOS tag comes from `sw_vers` and the code-owned symbol table, never from a Homebrew developer command. Casks use a separate macOS adapter with official metadata and supported artifacts. Taps, source builds, caller options, and arbitrary installers remain outside both contracts. Separate package identities avoid formula and cask collisions; a shared prefix lock prevents concurrent mutations of their common installation.

### Selected adoption

Discovery shows installed packages and creates no Tool rows. Adoption is the separate choice to own one supported package that is already installed. It does not install, update, remove, or repin the manager. The CLI, MCP, and the Node Tools page call that same Gateway operation.

Owning every installed package was rejected because a scan is not a request to own the machine. Silent adoption during install was rejected because installing and taking ownership are different intentions. Storing discoveries as Tools was rejected because a Tool row is selected intent, not an observation. One identity for a formula and a cask was rejected because the names can collide and the operations differ. Separate locks were rejected because both change the same Homebrew prefix. Adopting tunnel and bootstrap packages was rejected because removal would then uninstall SSH, WireGuard, DNS, Docker, the firewall, sudo, Caddy, or a manager.

A readable version that is not SemVer can still be adopted when no constraint is set. A formula revision such as `25.8.1_1` and a cask version such as `3.003` are null in the scan. The Tools page shows `unreadable`, and `tool:scan` shows an em dash. A constraint cannot be checked, so adopt returns `tool.installed_version_unparseable` and install returns `tool.candidate_version_unparseable`. The package stays where it was.

## Recipe convergence

## Signed apt repositories

An apt Tool can carry a `repository` object on install or adoption. Tools on the same Node share a definition by `name`. A normal `tool:update` can replace the definition under that name; omit it to keep the current definition. Other managers reject repository input.

| Field | Value |
| --- | --- |
| `name` | Lowercase slug, up to 63 characters; reserved `caddy` and `php` names are refused |
| `uris` | Nonempty list of HTTPS repository base URLs |
| `suites` | Nonempty list of distribution or channel tokens |
| `components` | Nonempty list of component tokens |
| `architectures` | Optional nonempty list of apt architecture tokens |
| `signing_key.url` | HTTPS public signing-key URL |
| `signing_key.fingerprint` | Full uppercase primary-key fingerprint, 40 or 64 hex digits |

For Rex, use this definition. It comes from the [vendor installer](https://pkgs.superlogical.com/rex/install.sh); Orbit does not execute that installer.

```json
{
  "name": "rex",
  "uris": ["https://pkgs.superlogical.com/rex/apt"],
  "suites": ["preview"],
  "components": ["main"],
  "architectures": ["amd64"],
  "signing_key": {
    "url": "https://pkgs.superlogical.com/rex/keys/rex-archive-keyring.gpg",
    "fingerprint": "E28636AC71905C6DDE40C1EDFAA6B80F641307A7"
  }
}
```

Pass the JSON object with `--repository` on `tool:install`, `tool:adopt`, or `tool:update`. The API and MCP take the same object. Repository data is returned on Tool reads. Change `suites` to `["stable"]` in a normal update when the vendor publishes stable. To rotate the vendor's signing key, send a normal update with the new `signing_key.url` and `fingerprint`; Swamp checks the new key first, then replaces the keyring, so the old key is removed. A missing stable InRelease fails; Orbit does not choose another suite.

Swamp downloads the key on Node 209 and verifies the pinned fingerprint before contacting the target Node. Official cfgmgmt `copy_file` publishes the binary keyring and `file` writes the deb822 source. It writes `/etc/apt/keyrings/orbit-<name>.gpg`, the apt preference `/etc/apt/preferences.d/orbit-<name>.pref`, and `/etc/apt/sources.list.d/orbit-<name>.sources` with a scoped `Signed-By` field. Apt refreshes only this source, keeping the indexes for other repositories, then Orbit selects its candidate, installs that exact version with normal dependency resolution and the repository preference. A failed refresh fails the step; a later run converges the admitted source, preference, and key. No downgrade is allowed. Install refuses an existing unowned package, including the distribution's unrelated `rex`; explicit adoption is still required.

Removal keeps the repository while another Tool references it. The final removal deletes its source, preference, and key after confirming package absence. Adoption changes no files. It requires the Node agent to report the declared source and a keyring with the pinned primary fingerprint; the agent does not report preferences. Scan reports apt packages, registration, and the repository for registered Tools; it does not guess trust definitions for unregistered sources.

| Error | Meaning |
| --- | --- |
| `tool.repository_invalid` | Invalid definition or repository input for another manager |
| `tool.repository_conflict` | The name already has a different definition, or an update changes its name |
| `tool.repository_key_mismatch` | The downloaded key does not match the pinned primary fingerprint |
| `tool.repository_unavailable` | Apt cannot refresh the signed index |
| `tool.repository_failed` | An official repository step cannot fetch, write, or refresh the managed source or keyring |
| `tool.repository_adoption_mismatch` | Adoption found a different source or keyring |

### Repository trust and package identity

One Node repository record holds shared intent so package removal cannot retire another Tool's source. Keys authorize only their named repository. Global apt-key trust and vendor installer execution are rejected because they bypass this ownership boundary. Candidate lookup and package download are scoped to the declared source, so a higher version from a distribution cannot select unrelated software with the same package name. Apt still resolves dependencies from the Node's normal sources.

The preference gives the repository's Tool packages priority 990 for each repository host, so unattended upgrades keep the vendor package. Every other package from that host gets priority 1: the vendor cannot replace or upgrade an installed or distribution package. A shared repository keeps a removed Tool's package in the preference until the next write for that repository. Gateway admission and terminal projection store intent and outcomes; Swamp owns downloads, key checks, source writes, refresh, and cleanup.

A fresh Gateway gets its PHP packages from its [bootstrap plan](/reference/node-provisioning#gateway-bootstrap-prerequisites), not from a Tool Manager: the plan reads the machine software recipe and the role prerequisites install the pinned files.

The pinned Swamp binary of a fresh Gateway comes from its [bootstrap](/reference/node-provisioning#gateway-bootstrap-prerequisites), which also starts its serve and workers; after that the swamp role keeps the pin.

The bootstrap plan also installs `valkey-server` on a fresh Gateway, for the [Gateway queue](/reference/gateway-queue#connection) that records finished runs. A Node that the nested Gateway adds with `orbit:node-add` gets its Tools through the normal Node workflow. The Gateway's own Node gets the same through `orbit:node-converge`.

That bootstrap also makes the WireGuard hub key on the new Gateway's machine and brings up `wg-quick@orbit` there, so Swamp can reach the Gateway by its WireGuard address before any role runs.
