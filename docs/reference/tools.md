---
title: "Tools"
description: "Where Tools run, the supported Tool Managers, how Orbit discovers, adopts, installs, updates, and removes one package, and why the contract stays closed."
covers:
  - swamp/extensions/behaviors/operations/tools/**
  - swamp/workflows/workflow-operation-tool-{write-209,remove-209,manager-setup}.yaml
  - apps/gateway/app/{Providers/ApplicationServiceProvider.php,{Actions,Domain}/Tools/**,Data/Tools/**,Actions/Doctor/Intent/RuntimeDoctorIntent.php}
  - apps/gateway/app/Infrastructure/{Tools/**,Nodes/Roles/RolePrerequisites.php}
  - apps/gateway/{app/Models/{Tool,ToolManagerRecord,AptRepository}.php,database/migrations/{2026_12_03_000000_allow_cold_recipe_image_rebuilds,2027_01_09_120017_create_apt_repositories_table}.php}
  - apps/agent/src/commands/{vp_store,vp_scope,vp_global,tools_apt,composer_global,homebrew_scope,homebrew,tool_output}{.rs,/**}
  - apps/gateway/app/Http/{Controllers/Api/{ToolsController,ToolManagersController,ToolInventoryController}.php,Requests/Tools/**}
---

# Tools

A Tool is one package that Orbit manages on a Node through a supported Tool Manager, identified by `(node, manager, package)`. Orbit records only packages it installs or explicitly adopts; discovery (`tool:scan`) reports other installed packages without creating Tool records. [`tool`](/cli/tool) lists the CLI commands. Machine recipe software, container runtimes, and CI caches stay separate from Tools.

## Where Tools run and Tool Managers

Tools run on active SSH-managed Nodes with a WireGuard IP and pinned SSH host fingerprint (`tool.node_inactive` or `tool.node_unmanaged`, 409, otherwise). Five managers are fixed in code:

| Manager | Platforms | Package | Scope | Becomes ready |
| --- | --- | --- | --- | --- |
| `apt` | Linux only | Ubuntu package name | Node package database | During Linux `node:add` |
| `composer` | Linux only | `vendor/package` | `COMPOSER_HOME=/opt/orbit/composer` | First Linux use or `app-dev`/`app-prod` |
| `vp` | Linux, macOS | npm package name | Enrolled account Vite+ store (`/opt/orbit/vite-plus`, `~/.vite-plus`, or `~/.local/share/vite-plus`) | Linux: first use or app role; macOS: verified in place |
| `brew` | Linux, macOS | Homebrew Core bottle | `/home/linuxbrew/.linuxbrew` (Linux) or existing prefix (macOS) | Linux: pinned revision on first use; macOS: verified in place |
| `brew-cask` | Linux (with Homebrew), macOS | Official Homebrew cask | Homebrew prefix (shares `brew` lock) | Linux: once agent reports Homebrew; macOS: verified in place |

Unsupported platform/manager pairs return `tool.manager_unsupported` (422); absent or conflicting scopes return `tool.manager_unavailable` (409). `orbit tool:manager:list --node=<id>` reports each supported manager as `uninstalled`, `provisioning`, `active`, or `failed`. Managers stay installed after their last Tool is removed.

Linux `apt` installs and updates wait up to 300 seconds for busy dpkg locks (`-o DPkg::Lock::Timeout=300`); `apt-get update` and `dpkg --remove` fail immediately if locked. Linux `vp` launchers in `/usr/local/bin` export `VP_HOME` for the resolved store.

## Project environments in the Vite+ store

Before JavaScript deploy steps, Orbit reads the release's manifest, lockfile, and Node pin (`.node-version`, `.nvmrc`, or `engines.node`) as data without running Project code, verifying the locked `vite-plus` version against the recipe's `vp` binary and installing missing Node/npm/pnpm runtimes into the enrolled account's immutable store (`js_runtime/node/<version>`, `package_manager/{npm,pnpm}/<version>`). Production builds run as the Instance user via `vp env exec` with `VP_HOME` set, granting ancestor traversal only where needed. Retained releases keep their runtimes so rollbacks work offline; see [Deploy prerequisites](/reference/deployments#vite-store-prerequisite).

## Discover installed packages

`GET /api/v1/tool-inventory?node_id=<id>` (`orbit tool:scan --node=<id>`) reads the [Node agent's](/reference/node-agent#packages) latest `packages` facts without contacting the Node or creating Tool rows. It returns `data.managers` in order (`brew`, `brew-cask`, `vp`, `apt`), each with `scan_state` (`complete`, `unsupported`, or `not_observed`) and sorted `packages` (`manager`, `package`, `package_kind`, `installed_version`, `dependency`, `registered`, `tool_id`, `adoption`, `adoption_block`, `repository`).

Adoption block tokens are `protected`, `dependency`, `unsupported_artifact`, `unsupported_source`, `bottle_unavailable`, `version_unreadable`, and `authorization_required`.

## Protected packages

Orbit protects essential host and manager packages from installation, update, adoption (`adoption_block: protected`), or removal (`tool.package_protected`, 409):

| Manager | Protected package names |
| --- | --- |
| `apt` | All `NodeBootstrapPackageCatalog` packages plus `openssh-server` and `wireguard-tools`: `acl`, `attr`, `bind9-dnsutils`, `ca-certificates`, `caddy`, `composer`, `curl`, `dnsmasq`, `docker.io`, `git`, `git-man`, `gnupg`, `incus`, `incus-base`, `incus-client`, `jq`, `libnss-resolve`, `logrotate`, `openssh-client`, `openssh-server`, `openssl`, `php-curl`, `php-xml`, `python3-venv`, `sudo`, `ufw`, `unzip`, `wireguard`, `wireguard-tools` |
| `brew` | `wireguard-tools`, `wireguard-go` |
| `vp` | `pnpm` |

### Role-owned PHP packages

The `apt` manager also protects every `php<version>-*` package plus `php-common` and `libgd3` (`tool.package_role_owned`, 409, on install/update; `protected` on adopt). Removing an existing Tool row for a PHP package owned by a role deletes only the Gateway record without uninstalling the package.

## Adopt a Tool

`POST /api/v1/tools/adopt` (`orbit tool:adopt <package> --node=<id> --manager=<manager> [--constraint=<range>] [--repository=JSON] --yes`) runs **synchronously** in the Gateway against the agent's latest `packages` fact (creating no Operation and contacting no Node) and returns HTTP `201` with the Tool record and `agent` liveness.

Accepted fields (unknown fields return `422 validation.failed`): `node_id` (int), `manager`, `package`, optional `version_constraint` (SemVer range), and optional `repository` (for `apt`, verifying the matching `/etc/apt/sources.list.d/orbit-<name>.sources` and `/etc/apt/keyrings/orbit-<name>.gpg` fingerprint).

Adoption requires a single installed version, no adoption block, and constraint compliance (`tool.not_observed`, `tool.package_absent`, `tool.installed_version_unparseable`, `tool.installed_version_constraint_violated`, `tool.repository_adoption_mismatch`, `tool.constraint_conflict`, `tool.state_invalid`, or `tool.adoption_unsupported`, all HTTP 409).

## Install and update a Tool

`tool:install` and `tool:update` return HTTP `202` with an [Operation](/reference/operations) serialized under one per-`(node, manager)` lock (`operation.target_busy`, 409, on contention). If install finds an already-installed unmanaged package on the Node, the admitted Operation fails with `tool.package_already_installed`; adopt it with `tool:adopt` first. Optional SemVer `version_constraint` blocks out-of-range candidates (`blocked_by_constraint` / `tool.version_constraint_blocked`, 422) without downgrading.

## Homebrew formulae

`brew` accepts only unqualified lowercase Homebrew Core formulae with a checksummed compatible bottle (never building from source or running `brew ruby`/`brew irb`). Orbit computes the bottle tag from `uname -m` (`x86_64_linux` on Linux; `sw_vers` mapped to `big_sur`..`golden_gate` with `arm64_` prefix on Apple silicon). Every `brew` and `brew-cask` call sets `HOMEBREW_NO_AUTO_UPDATE`, `HOMEBREW_NO_ANALYTICS`, `HOMEBREW_NO_ENV_HINTS`, `HOMEBREW_NO_INSTALLED_DEPENDENTS_CHECK`, and `HOMEBREW_NO_INSTALL_CLEANUP`.

### Homebrew casks

On macOS and on Linux Nodes where the agent reports Homebrew, `brew-cask` accepts unqualified official `homebrew/cask/<token>` casks with `https` URLs, lowercase SHA-256 checksums (`no_check` refused), and user-home or Homebrew-prefix artifacts (fonts, binaries, man pages, completions). Install and update set `HOMEBREW_FORCE_API_AUTO_UPDATE` on both platforms, and cask versions with comma-separated build suffixes (such as `1.5.13,5999302598852608`) compare SemVer before the comma while tracking the full version string. Casks targeting `/Applications`, system paths, package installers, or privileged uninstalls return `authorization_required`; preflight/postflight/arbitrary scripts and disabled casks return `unsupported_artifact`.

## Remove a Tool

`tool:remove` returns HTTP `202` with an Operation (or completes immediately if the package is role-owned or never recorded an installed version). For `apt`, Orbit simulates removal first (`apt-get --simulate remove`) and refuses plans that remove any other package (`tool.removal_plan_unsafe`), then runs `dpkg --remove -- <package>` without purging configs or autoremoving. On success, the Tool row is deleted and the response reports `status: removed`, `outcome: applied`.

## Signed apt repositories

An `apt` Tool install, adopt, or update can include a `repository` JSON object (`name`, `uris`, `suites`, `components`, optional `architectures`, `signing_key.url`, and 40/64-hex `signing_key.fingerprint`; reserved names `caddy` and `php` are refused). Orbit verifies the downloaded key's primary fingerprint before writing `/etc/apt/keyrings/orbit-<name>.gpg`, `/etc/apt/sources.list.d/orbit-<name>.sources`, and `/etc/apt/preferences.d/orbit-<name>.pref` (priority `990` for declared packages, `1` for other packages from that host). Removing the last Tool referencing a repository cleans up its source, preference, and keyring.

Repository errors: `tool.repository_invalid`, `tool.repository_conflict`, `tool.repository_key_mismatch`, `tool.repository_unavailable`, `tool.repository_failed`, and `tool.repository_adoption_mismatch`.

## Why it works this way

### Managers on demand, independent of roles

Keeping Tool Managers independent of Node roles lets any SSH-managed Node install packages without coupling package state to application roles, while installing managers only on first use avoids bloating Nodes with unused toolchains.

### Selected adoption

Separating read-only discovery (`tool:scan`) from explicit `tool:adopt` ensures Orbit never takes ownership of or uninstalls unmanaged host software automatically. Protecting bootstrap and role-owned packages prevents `tool:remove` from breaking SSH, WireGuard, DNS, Docker, Caddy, or PHP.
