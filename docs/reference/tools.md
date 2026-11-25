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
| A Conn Server running as a host service | Gateway Tool membership can pin a release. Only an explicit [Conn Server rollout](/reference/development-containers#conn-server-rollout) changes the service. Neither Tool convergence nor container convergence updates it. |

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

The manager must still offer the version, and the version must meet any SemVer constraint. Orbit refuses an unavailable coordinate instead of substituting a different version. Package downloads and removals retain the manager's source, verification, scope, and dependency rules.

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

Use the explicit [rollout](/reference/development-containers#conn-server-rollout) to apply the desired release or to retire a withdrawn Linux service. Doctor compares a managed service with the last version verified through protected rollout health checks, not with package inventory. A dry run can refresh that observation without changing the service. Before any verified health observation, Doctor reports the Tool as unverifiable. General Tool convergence does not execute host service intent. A host rollout never changes the required container Conn release.

## Project environments in the Vite+ store

Before JavaScript deploy steps, Orbit reads the release's manifest, lockfile, and Node pin (`.node-version`, `.nvmrc`, or `engines.node`) as data without running Project code, verifying the locked `vite-plus` version against the recipe's `vp` binary and installing missing Node/npm/pnpm runtimes into the enrolled account's immutable store (`js_runtime/node/<version>`, `package_manager/{npm,pnpm}/<version>`). Production builds run as the Instance user via `vp env exec` with `VP_HOME` set, granting ancestor traversal only where needed. Retained releases keep their runtimes so rollbacks work offline; see [Deploy prerequisites](/reference/deployments#vite-store-prerequisite).

## Discover installed packages

`GET /api/v1/tool-inventory?node_id=<id>` (`orbit tool:scan --node=<id>`) reads the [Node agent's](/reference/node-agent#packages) latest `packages` facts without contacting the Node or creating Tool rows. It returns `data.managers` in order (`brew`, `brew-cask`, `vp`, `apt`), each with `scan_state` (`complete`, `unsupported`, or `not_observed`) and sorted `packages` (`manager`, `package`, `package_kind`, `installed_version`, `dependency`, `registered`, `tool_id`, `adoption`, `adoption_block`, `repository`).

Adoption block tokens are `protected`, `dependency`, `unsupported_artifact`, `unsupported_source`, `bottle_unavailable`, `version_unreadable`, and `authorization_required`.

## Protected packages

Orbit protects essential host and manager packages from optional installation, update, adoption (`adoption_block: protected`), and convergence (`tool.package_protected`, 409). This includes host prerequisites such as Caddy, Valkey, and Docker CE. Removing a historical Tool membership for protected software relinquishes the record only; the host package stays installed. `conn-server` is reserved for the dedicated host rollout and container recipe, not a generic package write:

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

`tool:remove` returns HTTP `202` with an Operation. It removes only the record for a protected or role-owned package, or cancels a pending first install that failed during manager setup without installing the package first. For an apt package removal, Orbit simulates removal first (`apt-get --simulate remove`) and refuses plans that remove any other package (`tool.removal_plan_unsafe`), then runs `dpkg --remove -- <package>` without purging configs or autoremoving. On success, the Tool row is deleted and the response reports `status: removed`, `outcome: applied`.

## Signed apt repositories

An `apt` Tool definition can include a `repository` object (`name`, `uris`, `suites`, `components`, optional `architectures`, `signing_key.url`, and 40/64-hex `signing_key.fingerprint`; reserved names `caddy` and `php` are refused). In database mode pass it as JSON on install, adoption, or update; in file mode declare it in the catalog. Orbit verifies the downloaded key's primary fingerprint before writing `/etc/apt/keyrings/orbit-<name>.gpg`, `/etc/apt/sources.list.d/orbit-<name>.sources`, and `/etc/apt/preferences.d/orbit-<name>.pref` (priority `990` for declared packages, `1` for other packages from that host). Removing the last Tool referencing a repository cleans up its source, preference, and keyring.

Repository errors: `tool.repository_invalid`, `tool.repository_conflict`, `tool.repository_key_mismatch`, `tool.repository_unavailable`, `tool.repository_failed`, and `tool.repository_adoption_mismatch`.

All Tools sharing a repository on one Node must agree on its definition. An unchanged verified source does not need another key download on a direct write, but the apt preference still follows membership. A failed write retains the requested repository separately from the last verified source. Remove the membership before dropping or renaming a verified source; neither convergence nor a mode switch can bypass that rule.

## Why it works this way

### Managers on demand, independent of roles

Keeping Tool Managers independent of Node roles lets any SSH-managed Node install packages without coupling package state to application roles, while installing managers only on first use avoids bloating Nodes with unused toolchains.

### Selected adoption

Separating read-only discovery (`tool:scan`) from explicit `tool:adopt` ensures Orbit never takes ownership of or uninstalls unmanaged host software automatically. Protecting bootstrap and role-owned packages prevents `tool:remove` from breaking SSH, WireGuard, DNS, Docker, Caddy, or PHP.
