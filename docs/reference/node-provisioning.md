---
title: "Node provisioning"
description: "How node:add bootstraps or converges a Node, how roles share and lock a Node, how the Orbit CLI and footprint reach it, and how node:remove hands it back."
covers:
  - apps/gateway/app/Actions/{Gateway/BootstrapGatewayAction.php,Nodes/{ProvisionNodeAction,EnrollMacOsNodeAction,RemoveNodeAction,AddNodeRoleAction,RemoveNodeRoleAction,AssignRoleAction}.php}
  - apps/gateway/app/Infrastructure/{WireGuard/{NativeGatewayPeerProjectionManager,NativeGatewayVpnConverger}.php,Gateway/{NativeGatewayCaddyInstaller,NativeGatewaySelfAccessConverger}.php,Nodes/{Roles/{CiRunnerRoleBaseline,NativeNodeRoleFirewallManager},CiRunnerHostProgram,NativeNodeConverger,MacOsNodeConverger,NodeSshJump,NodeBootstrapCommandFactory,NodeBootstrapDnsProgram,NodeBootstrapPackageCatalog,CaddyPackage*,RemotePhpPackageManager,NodeLocks,NodeLock}.php}
  - apps/gateway/app/Console/Commands/{ProvisionNodeCommand,ConvergeNodeRecipeCommand}.php
  - apps/gateway/{app/{Actions/Fleet/ConvergeNodeRecipeAction.php,Data/Fleet/NodeRecipePlan.php,Infrastructure/MachineSoftware/NodeRecipe*.php,Domain/Nodes/RoleRegistry.php}}
  - apps/gateway/app/Infrastructure/{Firewall/NodeFirewallRuleCatalog,Ssh/NativeSshExecutor,Ssh/SshConnection,Ssh/SshHostKeyScanner,Ssh/HostKeyScanner}.php
  - apps/gateway/database/migrations/2026_12_03_000000_allow_cold_recipe_image_rebuilds.php
  - apps/gateway/app/{Infrastructure/Nodes/*Cli*.php,Domain/Nodes/NodeCli*.php,Domain/Fleet/NodeFootprint*.php,Domain/Fleet/NodeCliConvergence.php,Infrastructure/Fleet/Footprint/**,Actions/Fleet/ConvergeNodeFootprintAction.php,Http/Controllers/Api/NodeFootprintsController.php,Actions/Nodes/StartNodePhpPackagesAction.php,Domain/Nodes/NodePhpPackages*.php,Domain/Nodes/operation-kinds.php}
  - apps/agent/src/commands/{ci_runner.rs,recipe.rs,recipe/**,php_build.rs,php_build/**,gateway_identity.rs,gateway_identity/**}
---

# Node provisioning

`orbit node:add <name> [host]` adds a machine to the fleet. For a Node that already exists, the same command converges it again after you change its TLD, roles, or settings. `orbit node:remove <node>` removes the Node from Orbit and leaves the machine reachable over public SSH. [`node`](/cli/node) lists the options.

## Add a Node

A new Node needs a public SSH host and an approved SSH host key fingerprint, `--host-key-fingerprint`. Without the fingerprint, the Gateway refuses with `node.ssh_host_fingerprint_required`. A Node with `app-dev` needs a TLD, unless it joins an active Cluster that has one. Otherwise the Gateway refuses with `node.tld_required`. Platforms are `linux` for Ubuntu 26.04 service Nodes and `macos` for selected tools. [macOS Nodes](#macos-nodes) use a separate enrollment path.

For Ubuntu, the Gateway records the Node as `provisioning` and runs these steps in order:

| Step | Work |
| --- | --- |
| 1 | Check the host key against the fingerprint. |
| 2 | Validate and record the candidate architecture. Install the pinned agent through the bootstrap login, then run `node.bootstrap` for base packages and managed SSH access. |
| 3 | Connect as the managed user and verify the architecture with `agent.status`. |
| 4 | Publish the Node's WireGuard peer and pin SSH over the tunnel. |
| 5 | Install the `apt` [Tool Manager](/reference/tools#tool-managers) and converge each requested role. |
| 6 | Update Router LAN access and private DNS for the Node's Cluster. |
| 7 | Mark the Node `active`. |
| 8 | Store and prepare the [storage settings](/reference/node-settings). |
| 9 | Reconcile the [Metrics exporters](/reference/metrics#exporter-selection). A [task VM](/reference/compute-drivers#task-vms) skips this step. |
| 10 | Configure the [Node agent observer](/reference/node-agent) with its Node identity and secret, at step `agent`. |

`node.bootstrap` creates a missing managed account, adds its SSH key, and validates passwordless sudo before publishing the rule. It preserves existing home and directory modes and refuses linked access paths.

A [task VM's enrollment trusts its base image](/reference/compute-drivers#skip-what-the-image-holds) for the `app-dev` software. It keeps the VM-specific host key, WireGuard, firewall, Caddy, DNS, agent and secret, and Pi steps. Every other Node runs every program at enrollment.

The Gateway role requires `logrotate`, pinned in the recipe's `gateway` profile. Gateway web convergence and release handoff install its [disk retention configuration](/reference/gateway-recovery#disk-retention) beside the release units. They install a missing package only on Gateways not yet converged to the recipe. APT waits up to 300 seconds for dpkg locks. During that installation, temporary systemd masks block logrotate's package hooks and `NEEDRESTART_MODE=l` prevents automatic service restarts. Cleanup attempts to remove every temporary mask, even if one removal fails, and preserves the original installation error.

Existing operator masks remain in place. Convergence skips enabling `logrotate.timer` when `systemctl is-enabled` reports `masked` or `masked-runtime` for either `logrotate.service` or `logrotate.timer`, so an operator mask does not block releases. Otherwise it enables the configured timer afterward; it does not restart journald or force a rotation.

Task checkout operations require systemd 250 or newer, cgroup v2, and the managed user's passwordless sudo. Missing or unreadable supervision refuses operations and keeps uncertain workspace ownership fenced. See [Workspace lease](/reference/tasks#workspace-lease) for completion receipts and recovery.


After the agent, the Gateway installs the [Orbit CLI](#orbit-cli) on a Node of the [fleet rollout set](/reference/gateway-recovery#rollout-set-and-order). A role converge does the same after its agent converge. Both are best effort: an unpublished CLI release or a failed install only logs a warning, and the fleet catch-up installs the CLI later.

The Gateway console command `orbit:node-provision` runs the same steps for the first Node. On failure, `--diagnostics` includes a redacted command-output tail, or the underlying exception messages when no command output exists: at most 40 lines and 4,000 characters. The topology harness uses it to report the prerequisite error. Role firewall intent comes from the shared Node firewall rule catalog. The agent's `firewall.rules` command checks ownership, preserves recovery SSH before enabling UFW, applies desired rules, and removes exact old rules under one firewall lock. Admission calls it over the pinned bootstrap connection; later role work uses the WireGuard connection.

Gateway bootstrap installs the pinned agent before preparing its host identity. `gateway.prepare-identity` creates the managed SSH and WireGuard keys under the Gateway's Orbit home, preserving existing private keys on repeat. `gateway.converge-self-access` authorizes the managed public key for the executing account and returns the host public key and fingerprint. Both commands run as the Gateway account. Invalid paths, foreign ownership, and inconsistent existing keys refuse before publication. The Gateway retains its CA authority and known-host records as control-plane data.

Observer configuration follows once the Gateway is active. `firewall.gateway-vpn` checks all desired and recovery rule ownership before mutation. On an inactive firewall, it proves the stored public SSH recovery rule in both address families before enabling UFW. It applies and verifies the VPN rules before removing recovery SSH. The command holds the firewall lock across these steps. Private DNS publication keeps its separate convergence step.

`wireguard.bootstrap-hub` validates the rendered hub configuration, installs IPv4 forwarding, and restarts `wg-quick@orbit`. It shares configuration publication and recovery with `wireguard.project-hub`, which applies peer changes without restarting an active interface. Both commands hold the same hub target lock. The agent rejects WireGuard shell hooks before writing files. The Gateway retains peer selection, configuration rendering, and the projection lock that protects that selection.

Router LAN reconciliation expands and prunes HTTPS and both DNS transport families together, using the same proposed member eligibility. A failure before membership or Cluster state commits leaves the previous state authoritative. If pruning fails after the commit, the committed state remains authoritative and the command reports cleanup failure; stale rules may remain. Restoring a preceding firewall snapshot does not roll back database state.

After resolving the cause, repeat `orbit cluster:node:add <current-cluster-id> <current-router-node-id>` for the active Router already in that Cluster. This accepts unchanged membership and reconciles Router LAN rules even when the Router hosts Instances or the Cluster is inactive; it does not remove workloads or change Cluster state. Then verify managed/live rules and role Doctor against current authoritative state.

`node:add` with unchanged configuration is an alternative only when its provisioning guards permit it: a Node with Instances is refused with `node.has_instances`. Do not remove workloads or change configuration to bypass that guard. The [Router LAN firewall contract](/cli/firewall#router-lan-member-rules) defines eligibility, cleanup recovery, snapshot-restore failures, and rollout rollback. These rules alone do not install Cluster DNS or change a member's resolver.

### Bootstrap identity

The Gateway picks the bootstrap user from the Node record, unless the request names one.

| Node | Bootstrap user | How the bootstrap runs |
| --- | --- | --- |
| New | `root` | Directly |
| Existing | The recorded managed user | Through passwordless sudo |

Name a bootstrap user with `--user` when root cannot log in, or when the managed user cannot log in. `--orbit-user` names the managed user. A new Node uses `orbit`, and an existing Node keeps its recorded user. The API fields are `user` and `orbit_user`. The API refuses an empty or null `user`.

### Machine architecture

Linux Nodes are x86_64 only. Orbit has no Linux ARM agent, CLI, or recipe software. macOS Nodes on Apple silicon stay supported; see [macOS Nodes](#macos-nodes).

Before staging the first agent binary, the runner selects its platform and architecture with a fixed `uname -sm` probe over the pinned bootstrap connection. A Linux machine that reports anything other than `x86_64`, such as `aarch64`, fails with `node.architecture_unsupported` (HTTP 422) at step `machine-architecture`. The Gateway records no architecture, stages no agent, and converges no role. Adding a role to such a Node fails before any assignment: `node_role.convergence_failed` (HTTP 422) with `error_code` `node.architecture_unsupported` at step `converge:machine-architecture`.

After bootstrap, `agent.status` verifies the same architecture as the managed user. A Node without a recorded architecture stores it. The value stays even when a later step fails. An existing Node keeps its recorded value.

`--architecture` is optional. For a Linux Node, the only accepted value is `x86_64`. Any other value fails with `node.architecture_unsupported` (HTTP 422) before the Gateway connects or records the Node.

### Failure codes

Each failure names the check or step that stopped the request.

| Code | Meaning |
| --- | --- |
| `node.invalid_linux_user` | The bootstrap user or the managed user is not a valid Linux user name. Nothing changes. |
| `node.user_change_unsupported` | Another managed user for a Node with roles, or another account for an already managed Mac. Nothing changes. |
| `node.platform_unsupported` | The platform or operation is unsupported. A macOS role request returns it before SSH. HTTP 422. |
| `node.platform_mismatch` | The observed platform is not the requested one. HTTP 409 at `machine-architecture`. |
| `node.macos_account_required` | macOS enrollment omitted the existing account. HTTP 422. Nothing changes. |
| `node.macos_account_mismatch` | `user` and `orbit_user` name different accounts. HTTP 422. Nothing changes. |
| `node.account_unavailable` | SSH as the existing macOS account failed. HTTP 502 at `account`. |
| `node.wireguard_required` | macOS enrollment has no WireGuard address to verify, or the requested address differs from the Node row. HTTP 409 at `identity`. Nothing changes. |
| `node.agent_unsupported` | A Node agent install or repair targeted macOS. HTTP 422. |
| `node.ssh_host_fingerprint_required` | A new Node has no approved host key fingerprint. |
| `node.ssh_host_key_scan_failed` | The Gateway could not read the host key. |
| `node.ssh_host_key_mismatch` | The host key differs from `--host-key-fingerprint`. |
| `node.ssh_host_key_changed` | The host key differs from the fingerprint stored for the Node. |
| `node.bootstrap_failed` | The bootstrap failed as the bootstrap user. |
| `node.orbit_ssh_failed` | The Gateway could not connect as the managed user after the bootstrap. |
| `node.architecture_unavailable` | The Gateway could not read the architecture. |
| `node.architecture_mismatch` | macOS only: the requested architecture differs from the observed one. HTTP 409 at `machine-architecture`. |
| `node.architecture_unsupported` | A Linux Node is not `x86_64`, or the request names another architecture. HTTP 422. An observed architecture fails at `machine-architecture`, before the agent is staged. |
| `node.role_convergence_failed` | A role failed to converge. The step is `role:<step>`. |
| `node.agent_install_failed` | The Node agent failed to install. |

## CI runner role

`orbit node:role:add <node> ci-runner` prepares a Linux Node to host GitHub Actions runners in unprivileged Incus containers. The role is not a singleton or relocatable. Add it to an enrolled Node through the normal role-add path; `node:add --role` does not accept it. It can share every role except `gateway` and `vpn`, which hold fleet trust anchors. A Node that serves CI alone needs no app role, TLD, or Cluster. Beast can keep its app and database roles. Shark needs only `ci-runner` for CI; removing its existing roles is a separate operator decision.

The role installs Incus, and the agent's `ci-runner.host` prepares the restricted `orbit-ci` project, the `orbitci0` bridge, the `orbit-ci-egress` ACL, and the default runner profile. The profile sets 6 CPUs, 12 GiB of memory, 8192 processes, and autostart, with privilege and nesting disabled. The project permits only that bridge and forbids VMs and host disk devices. The role uses the existing `default` storage pool, or creates a directory pool when it is absent. It never changes an existing pool or bridge IPv4 subnet. Incus chooses a subnet for a new bridge.

The ACL permits public IPv4 egress and rejects private, CGNAT, loopback, link-local, multicast, reserved IPv4, and all IPv6. Incus permits bridge DNS and DHCP before the ACL; this boundary does not claim to block those services. The normal managed firewall catalog owns the routed allowance on `orbitci0`, including adoption of the existing `orbit-ci-runners` comment. Role convergence repairs the project, profile, ACL, bridge settings, and managed firewall rule. Doctor checks their projection, packages, service, and firewall. Task VM stray checks recognize the role's project without an `operator_owned` entry.

The role owns the host boundary. [`tools/ci-runners/setup.sh`](https://github.com/hardimpactdev/orbit/blob/main/tools/ci-runners/setup.sh) creates, updates, registers, and verifies containers. Run its container step only while the runner is idle. Adoption has no container-count or running-state requirement: it preserves clones and stopped containers without starting, deleting, recreating, or registering them. Each active runner carries `hardimpact,orbit-shard`, plus `sabre` on Sabre. The Gateway holds no organization registration token. A token stays with the operator and never enters role input or settings.

A Node with only this role appears in the web app's workload table with CPU, memory, and disk meters. It selects the normal role-default exporter and joins fleet rollout group 2 and the overnight recipe service tier. Explicit exporter preferences still apply. Its role selects the recipe's `ci-runner` tool set; a recorded recipe profile does not grant fleet participation. Host recipe convergence refuses extra PHP packages, as other role hosts do. The container installer alone purges extra PHP packages.

Remove all containers and their GitHub registrations before removing the role. Role removal refuses a project containing any container, including a stopped one after deregistration and with `--force`; it never deregisters or deletes a runner. Normal removal closes the routed allowance and retains the project, bridge, ACL, and pool. Before `--purge-data`, remove cached images, custom volumes, and extra profiles from `orbit-ci`. The option also removes the empty project, bridge, and ACL; Incus refuses resources still in use. The shared pool stays. Offline removal retains host state and reports it for operator cleanup.

After merge and deployment, an operator runs these commands; this feature does not run them. Adding the first role to a roleless Node closes the normal `orbit:public-ssh-recovery` firewall rule. Confirm WireGuard access before adding it:

```bash
orbit node:role:add Sabre ci-runner
orbit node:role:add Shark ci-runner
orbit node:role:add Beast ci-runner
```

Verify each Node with `orbit doctor --node=<numeric-node-id>`. For a failed role add, repair the reported cause and retry with `orbit node:role:add <node> ci-runner --converge`. Existing runners and registrations stay in place.

## Converge a Node to a recipe

When a profile includes Node.js, the recipe is its single runtime owner. Convergence verifies the recipe runtime before removing a duplicate `nodejs` APT package. APT simulation must remove only `nodejs`; dependencies are never removed automatically. Convergence validates NodeSource entries, backs them up outside APT source directories, then removes them. Mixed or unexpected entries stop convergence. Profiles without Node.js keep their APT ownership unchanged.

Convergence also backs up and removes the NodeSource `nodejs` and `nsolid` preferences and its keyring. Unexpected preferences or a keyring still used by another source stop convergence. It removes only dangling `/usr/bin/{yarn,yarnpkg,pnpm,pnpx}` links to Corepack, preserving working or unrelated links. Dry runs report `remove_nodejs_package`, `retire_node_source`, `retire_node_config`, and `remove_corepack_links`. APT removal suppresses needrestart; application owners restart affected processes after convergence.

Vite+ caches and running processes are separate: update project pins, deploy retained releases, and restart affected services before retiring old cached runtimes.

Recipe Node uses the same [immutable store layout](/reference/tools#project-environments-in-the-vite-store) as deploy prerequisites. A matching real runtime stays in place on convergence. A link into the former recipe directory or a runtime without the recipe reference is reported as needing verified installation even when its Node version is current. Convergence publishes the store with the enrolled account as its owner. It migrates all former recipe links to real directories with normalized modes, keeps their old targets for running processes, and records a reference that protects only the current runtime from deployment cleanup.

Older migrated versions remain adopted content. A link is briefly absent between unlink and directory rename; avoid process restarts during migration. Recipe installation refuses unrelated links or differing real content without deleting them.

On the Gateway, run `php artisan node:converge-recipe <node> [--recipe=sha256:<digest>] [--dry-run]`. The target is a Node name or ID. The command uses the repository [machine software recipe](/reference/compute-drivers#machine-software-recipe). An optional full recipe identity guards against running a different version. To select an older version, check out its recipe before running the command; arbitrary Git revisions and remote recipe URLs are not inputs.

The command requires an active managed Ubuntu Node on x86_64. Another architecture fails with `node.recipe_architecture`. It unions the Node's active role profiles, including common tools once. A Node without roles uses `operator`. The service-role profiles match the existing role prerequisite catalog. A prerequisite absent from the recipe, an unsupported artifact, or an incompatible managed Tool constraint stops the operation before package changes. It never installs the base image's development-only tool set on a production Node.

The Gateway sends those pins and the enrolled account to `recipe.inspect`, `recipe.incus-preflight`, and `recipe.apply` through the agent runner. Rust owns package inventory, origin proof, source retirement, verified downloads, runtime installation, and service checks. The applying command holds the package resource lock. It removes its temporary package restart policy on failure or graceful interruption and reserves cleanup time before its command deadline. A command terminated by SIGHUP, SIGINT, or SIGTERM stops the recipe, including an optional command. [Recipe interruption](/reference/node-agent#recipe-interruption) explains how the runner detects the signal.

Docker CE refuses both a dry run and application with `node.recipe_docker_provider` and reason `docker-provider:docker-ce`. Convergence does not stop containers or replace that provider. Follow the [Docker migration runbook](/reference/docker-provider-migration) during a planned window.

The `ci-runner` role selects its recipe tool set through the same role union as other roles. Roleless Nodes use `operator`. Recorded profiles have no effect.

`--dry-run` reads installed versions and managed Tool intent, then prints JSON with the snapshot, recipe identity, selected tools, recipe versions, observed versions, satisfaction status, and planned changes. An `unsupported_newer` tool includes `refusal: origin:<tool>` and plans no downgrade. Ubuntu packages use the [version floor and origin checks](/reference/compute-drivers#recipe-schema); Sury PHP builds stay exact.

A dry run changes no host configuration, installed packages, or system APT indexes. Origin verification can fetch authenticated indexes into a temporary directory, which it removes afterward. It takes no remote lock and restarts nothing. Dependencies are resolved from the snapshot during application; the dry-run lists explicit package and tool changes, not an apt dependency simulation. It reads Vite+ metadata and exact cached runtimes directly. An unknown version is `null` and plans an installation; it never invokes a moving runtime shim or an uninitialized Vite+ binary.

Application shares the role-converge lock with Node convergence and fleet rollout. It holds the existing Tool Manager scope locks while applying and checking the recipe. Each binary installer takes the self-update lock for its own replacement; package work and Doctor do not hold that lock. At preflight it repairs only the exact snapshot source file written by earlier Orbit convergence, restoring stock Ubuntu archive, updates, backports, and security sources. It preserves other source content.

Every applying run refreshes these Ubuntu pockets through a temporary Ubuntu-only source file, whether or not preflight repaired `ubuntu.sources`. An unrelated third-party source cannot block convergence. A failed Ubuntu refresh still stops convergence. Even a Node configured with a regional mirror must reach `archive.ubuntu.com` and `security.ubuntu.com`.

Recipe package resolution uses a temporary snapshot source. It refreshes the snapshot indexes only when an Ubuntu package or Debian artifact needs installation. Index refreshes fetch package metadata without translations, desktop metadata, or command suggestions, and retain APT's cached lists. It downloads and verifies pinned release artifacts, installs missing or older Ubuntu packages at the selected pins, exact artifacts, and their dependencies with package removal disabled, and verifies installed versions. Exact Debian artifacts can downgrade after a dependency simulation; Ubuntu recipe packages and other dependencies cannot. Installation ends with `apt-get check`.

Before installation, Orbit checks the name, complete version (including any epoch), and architecture in each Debian artifact. The architecture must match the Node or be `all`. See the [recipe schema](/reference/compute-drivers#recipe-schema).

Every role that runs PHP, `app-dev`, `app-prod`, `websocket`, and `gateway`, names a [PHP set](/reference/compute-drivers#php-build). So convergence brings PHP on each such Node, the Gateway included, to the recipe build, and replaces a PHP build from the Ubuntu archive.

Package hooks are prevented from restarting services during installation. Only active services belonging to changed packages restart.

The PHP set is the exception: [`php.install-build`](/reference/compute-drivers#install-the-php-build), the one PHP build installer, installs it. Before any change, convergence runs the installer's plan. Another PHP apt source, or a PHP package of a handled version outside the recipe, refuses the convergence with `node.recipe_php_refused` and names it. A dry run refuses the same way. The container installer uses the `ci-runner` profile to purge extras; host convergence always refuses them.

After the other tools, convergence runs the installer with PHP-FPM option `all`. It restarts the shared `php<version>-fpm` service and each active dedicated production master, each after `php-fpm -t`. It checks each pool socket with a FastCGI request. A dedicated production master restarts briefly during this step, so each site of the Node does too.

When a check fails, the installer reinstalls the previous build and restarts the masters again. Convergence then stops with `node.recipe_php_failed`, names the master, and records no convergence. PCOV loads in the CLI on the `app-dev` and `ci-runner` roles. On the Gateway, the restart of `php8.5-fpm` reloads the workers that serve the API, so converge the Gateway last.

Vite+ keeps its existing global store. The pinned CLI receives its initialization marker before it runs, so it cannot launch an unpinned installer. Images and Node convergence use the same installer. The recipe pins the SHA-256 of `vite-plus-package-lock.json`, which fixes the complete runtime dependency tree. The Gateway cross-checks the lock’s Vite+ integrity against the recipe artifact pin; npm verifies that integrity during installation. There is no separate Vite+ package download. The installer runs `npm ci --ignore-scripts` in a separate directory, then switches only Vite+'s runtime package. It never reuses the old runtime's dependencies or replaces the global tool store.

The command then checks service health, existing managed Tool intent, and the Node and role Doctor families. Service health covers services active before convergence from packages whose dpkg version changed and from pinned recipe packages. Convergence restarts only changed-package services. Systemd propagates those restarts to active `Requires=`, `BindsTo=`, and `PartOf=` dependents. Immediately before restarting, convergence reads `RequiredBy`, `BoundBy`, and `ConsistsOf` and takes their transitive closure through units active at that time. These dependents enter the health set, but are never explicit restart arguments. Dependents of unchanged pinned services remain outside the set.

After the restart invocation, convergence waits for an owned transient oneshot ordered after every affected unit. The oneshot does not start or repair other units. The client wait uses the existing 600-second command limit. Convergence cancels only its own fence after a timed-out wait and reports cancellation errors. Systemd collects the unit after completion.

Services from unpinned dependency packages, such as `incus.service` or `wg-quick@`, are covered only when their package changes or they are active dependents of a restarted service. Loading a changed library alone does not put a service in this set. Unrelated services and transient task checks remain outside the set. A transient dependent of a restarted service remains covered when it is active at restart time.

If a unit fails the active check, convergence accepts a loaded unit that completed successfully (`ActiveState=inactive`, `Result=success`). It also accepts a unit when the shared task-unit proof shows it is not loaded, is inactive, has no cgroup, and has no pending job. A failed state read, any other non-loaded state, or an unsuccessful completion stops convergence.

The fleet verifier checks Node and role requirements with no tolerated preexisting issues. Then `recipe.verify-delivery` takes the self-update lock and checks the installed agent digest and CLI version against the delivery selected before application. Only its passing result supplies the recipe identity to record. An unavailable CLI release refuses before application; a mismatch or busy update lock leaves no success marker. The command stops at the first failure and reports the failed stage with a nonzero exit code. It makes no automatic rollback. Package application is not retried; Doctor uses the fleet verifier’s settling checks.

A failed command reports its recipe stage, exit code, and bounded, redacted output in the convergence error. A failure during package installation also reports the attempted packages. APT source validation reports the source or backup path and the reason, including malformed fields, mixed provider entries, and symlinks. These reasons use the same bounded, redacted diagnostic envelope. No automatic retry or rollback runs.

After all checks pass, `settings.machine_recipe` stores the recipe identity in `version`, the snapshot, completion time, and any `newer_than_recipe` packages with installed and recipe versions on the Node record. When fleet convergence holds an Incus change, it stores `partial_version` instead of `version`; the Node has applied only the non-Incus changes. See [Incus holds](/reference/compute-drivers#incus-lts-packages-and-upgrades). `php artisan node:converge-recipe --status` lists the recorded identity for every Node without SSH, including Nodes with no success marker.

A failed attempt clears the previous success marker before mutation: the Node may be partly changed and must not appear converged. It keeps only a recorded profile. Node settings unrelated to the recipe stay intact. To recover manually, pin the previous snapshot date and tool pins in a reviewed recipe, then converge the Node again. Snapshot pinning alone does not undo changed third-party tool pins.

This synchronous operation also runs inside [nightly recipe maintenance](/reference/compute-drivers#nightly-recipe-maintenance). Maintenance selects and orders Nodes; this operation applies and verifies one Node. [Gateway fleet rollout](/reference/gateway-recovery#recipe-convergence) updates Orbit itself separately.

### Sandboxed headless Chrome on production Nodes

The `app-prod` recipe includes the Chromium libraries, AppArmor, and Google's digest-pinned Chrome for Testing headless shell on amd64. Other profiles do not receive this browser. The executable is `/opt/orbit/chrome-headless-shell/<version>/chrome-headless-shell-linux64/chrome-headless-shell`. The versioned runtime, all its resources, and its ancestors belong to root and are not group- or world-writable. Convergence refuses symlinks or unsafe ownership instead of adopting an application account's browser cache.

The recipe publishes `/etc/apparmor.d/orbit-chrome-headless-shell` with one `userns` exception attached to that exact executable. It validates the policy and loads only that profile with `apparmor_parser`; it does not restart AppArmor, change a global sysctl, grant an exception to a writable browser, or disable Chromium's sandbox. Each applying convergence reloads the profile, including when the runtime is already installed. A failed load fails convergence and restores the previous policy. Older root-owned runtimes remain on disk, but only the selected executable receives the current exception.

An application selects this executable through its own browser configuration. For a renderer using `OG_IMAGE_CHROME_PATH`, store the exact versioned path with `env:update`, then apply it with `env:sync`. The recipe does not change Instance environments. Coordinate future pin upgrades with those stored paths: keeping the old runtime does not keep its namespace exception after the one profile moves to the new executable. Verify a real image render before accepting a new browser pin; a successful `--version` alone does not exercise the sandbox. Unsupported architectures refuse before installation.

## macOS Nodes

A macOS Node supports selected Homebrew formulae, casks, and Vite+ global packages. It needs no role. It uses an existing account, a working WireGuard connection, and SSH access authorized for the Gateway. Enrollment pins the approved SSH host identity and verifies the actual platform, architecture, account, and tunnel address before it succeeds. The Gateway resolves the host name. An SSH alias on the caller's machine is not a fleet address.

Use `node:add` with `--platform=macos`, the approved host fingerprint, and `--user` and `--orbit-user` set to the same existing account. The Gateway does not default those fields to `root` or `orbit`. A missing field returns `node.macos_account_required`. Different names return `node.macos_account_mismatch`. A name that fails the existing portable user check returns `node.invalid_linux_user`. All three are HTTP 422 and change nothing.

The request must name a WireGuard address that is already on the machine, or the Node row must already have one. The Gateway does not allocate a new address, install a tunnel, or rewrite the host tunnel, DNS, or firewall. A missing address returns `node.wireguard_required`. A requested address that differs from the address stored on the Node returns `node.wireguard_required` and changes nothing. A role, DNS server override, WireGuard endpoint override, storage settings change, Cluster change, TLD change, or LAN change returns `node.platform_unsupported` and changes nothing.

An eligible existing peer can be enrolled in place. It already has a row, no roles, a WireGuard address, and no pinned SSH fingerprint. After the platform, architecture, account, and tunnel checks pass, the Gateway stores the observed platform and `uname -m` architecture and the named account. It keeps the Node ID, name, access grants, WireGuard keys, address, and endpoint. Apple silicon stays `arm64`. The Gateway does not rewrite `arm64` to `aarch64`.

A requested platform or architecture that disagrees with the machine fails. `node.platform_mismatch` and `node.architecture_mismatch` are HTTP 409 at `machine-architecture`. An already managed Node has a pinned SSH fingerprint. Enrollment does not change its platform, architecture, or user. A disagreement fails and leaves the record as it was. A different account on that Node returns `node.user_change_unsupported` (HTTP 409).

The macOS path creates no account and applies no Ubuntu bootstrap, apt packages, UFW rules, systemd units, or managed DNS. It keeps the existing account, tunnel, Homebrew prefix, and Vite+ global scope. Discovery and adoption report a missing or conflicting manager without installing, replacing, or repinning it. Tool mutations require an active Node with verified WireGuard and pinned SSH identity.

### Enrollment steps

The Gateway does not record a new Node until enrollment succeeds or a remote check fails. A refusal before SSH creates no row. It runs these steps and does not run Ubuntu steps 2 to 10.

| Step | Name | Work |
| --- | --- | --- |
| 1 | `ssh-host-key` | Resolve the host and compare its key with the approved fingerprint. Do not pin the key yet. |
| 2 | `account` | SSH as the existing account. Create no user and change no sudoers file. |
| 3 | `machine-architecture` | Use the fixed staging probe to select the macOS agent artifact. Refuse a different platform before staging. |
| 4 | `agent` | Stage the pinned binary in a unique absolute temporary path and run `agent.install` as the enrolled account. |
| 5 | `identity` | Call `node.inspect-macos-enrollment` to verify the native platform, architecture, and WireGuard address. Do not write the registry yet. |
| 6 | `ssh-pin` | Write the approved host key to the Gateway known_hosts file for the public host and the WireGuard address. |
| 7 | `active` | Store the host key, fingerprint, observed identity, and `active` status. Skip apt, roles, firewall, DNS, exporters, and the observer service. |

`uname -s` must be Darwin. A Linux host requested as macOS fails with `node.platform_mismatch` before any account, package, DNS, or firewall change.

### Enrollment recovery

A failed native check can leave the pinned command binary installed on the Mac. It changes no account, package manager, tunnel, DNS, or firewall state. Retry with the same `node:add`. The retry does not run the Ubuntu bootstrap. The Gateway writes known_hosts before the registry pin. A known_hosts entry with no registry pin is harmless, and the retry completes it. The registry never stores an active pin without those entries.

| Failure | New Node | Existing peer |
| --- | --- | --- |
| Before SSH | No row. | Unchanged. |
| Remote check before `ssh-pin` | `failed` at that step. No host key is stored. | Unchanged. |
| `ssh-pin` | `failed` at `ssh-pin`. No host key is stored in the registry. | Unchanged. |

An already managed Node that fails stays as it was. Storage settings, Metrics reconcile, and the Node agent do not run, so a macOS failure never stops on steps 8 to 10.

### Unsupported operations

These operations refuse macOS before remote mutation and before they write a role or other new assignment.

| Operation | Code | HTTP |
| --- | --- | --- |
| Any role on `node:add` or `node:role:add` | `node.platform_unsupported` | 422 |
| Process mutation | `process.platform_unsupported` | 422 |
| Schedule mutation | `schedule.platform_unsupported` | 422 |
| Metrics exporter or Metrics role | `metrics.platform_unsupported` | 422 |
| Node agent install or repair | `node.agent_unsupported` | 422 |
| Firewall mutation | `firewall.platform_unsupported` | 422 |
| DNS repair | `node.dns_repair_platform_unsupported` | 422 |

Doctor checks lifecycle, SSH reachability, platform, architecture, tunnel identity, tools, and free space on the enrolled account's home volume. It does not expect systemd, a Node agent, an exporter, or a Linux disk path. A missing agent is not `node.agent_missing`. Realtime and the web page treat the Mac as a Node with no agent, not as a failed Linux service.

### Removal

Removing a macOS Node deletes the registry row and the hub peer. It does not delete the user, uninstall Homebrew or Vite+, or edit the host tunnel. It skips the Node agent step and `firewall-recovery`. `retained_on_node` lists `user`, `package-managers`, and `host-wireguard`. A failed hub or registry step uses the normal removal rollback: the Node returns to its previous status, and a removed peer is restored when that rollback succeeds. macOS OS updates, firewall management, application hosting, and a macOS agent are separate features.

JSON and the human `node:remove` output both report that list, so the operator can see the account, package managers, and host tunnel that remain.

## Nodes without roles

`node:add` without `--role` adds a Node that hosts no Orbit service. Use it for an operator machine that runs the Orbit CLI over WireGuard.

On Ubuntu, the Gateway uses this setup when the request names no role and the Node has no role assignment. macOS uses the [macOS enrollment](#macos-nodes) path instead of the steps below. Ubuntu runs the same steps as for any Linux Node, with these differences.

| Area | Node without roles |
| --- | --- |
| Bootstrap | Base packages, managed user, and passwordless sudo, as on any Node. The bootstrap keeps the machine's DNS as it is. |
| WireGuard DNS | A `DNS =` line with the WireGuard address of the `vpn` Node. No `PostUp` hook and no `orbit.dns-link`. |
| `--dns-server` | The Gateway stores the override, but the `DNS =` line does not use it. |
| Firewall | UFW is on, with `orbit:public-ssh-recovery` and `orbit:wireguard-members`. Public SSH stays open. See [Public SSH](#public-ssh). |
| Node agent | The Gateway installs the [Node agent](/reference/node-agent), because the Node has a pinned SSH host key. |
| Metrics | The Node runs no exporter until you run `metrics:exporter:enable`. See [Exporter selection](/reference/metrics#exporter-selection). |

When the package sources do not resolve, the bootstrap fails. Unlike a Node with roles, it does not clear Orbit DNS on the `orbit` link first. See [Add the machine again](#add-the-machine-again).

The `DNS =` line is in `/etc/wireguard/orbit.conf`, and `wg-quick` applies it when the tunnel starts. The Gateway deletes an existing `/etc/wireguard/orbit.dns-link`.

A later `node:add` without `--role` keeps this setup. To call the Gateway API from the Node, grant it access with [`node:access:add`](/cli/node#orbit-nodeaccessadd). The Gateway identifies the caller by its WireGuard address.

On Ubuntu, `node:role:add` accepts the same roles as on any other Linux Node. Adding the first role closes public SSH and moves the Node to managed DNS in the same operation. macOS refuses every role with `node.platform_unsupported` before SSH.

[`orbit:node-dns-repair`](/reference/private-dns#repair-one-peer) still refuses a Node without roles with `node.dns_repair_operator_owned`.

[Node retarget](/reference/node-retarget) keeps a Node without roles in operator DNS mode. The `DNS =` line stays, and the Node does not get an `orbit.dns-link`.

Doctor checks a Node without roles like any other Node when the Gateway has a pinned SSH host key for it. The `role` family reports nothing. The `schedule` family skips its orphan scan unless the Node hosts a Schedule. A Node without roles and without a pinned SSH host key gets only the lifecycle check.

## iPadOS peers

An iPad or iPhone can join WireGuard as an unmanaged peer. The operator owns its tunnel. It has no pinned SSH host key and no role. Orbit runs nothing on it. The `ipados` platform records this, so the Node is not mistaken for a Linux machine.

Set the platform with [`node:platform`](/cli/node#orbit-nodeplatform). The command takes `linux`, `macos`, or `ipados`, and accepts only a Node with no pinned SSH host key and no role. It changes the stored platform and nothing else. `node:add` does not accept `ipados`. Keep the architecture the machine reports, such as `arm64`.

The Gateway treats an `ipados` Node as a peer it never contacts. It reuses the platform gates that macOS and unmanaged Nodes already have.

| Operation | Result |
| --- | --- |
| `node:add`, including `--platform=macos` | `node.platform_unsupported` (HTTP 422) before SSH. The record stays as it was. |
| Any role on `node:role:add` | `node.platform_unsupported` (HTTP 422). |
| `node:converge` | `node.converge_unsupported` (HTTP 422). |
| Process mutation | `process.platform_unsupported` (HTTP 422). |
| Schedule mutation | `schedule.platform_unsupported` (HTTP 422). |
| Metrics exporter or Metrics role | `metrics.platform_unsupported` (HTTP 422). |
| Firewall mutation | `firewall.platform_unsupported` (HTTP 422). |
| DNS repair | `node.dns_repair_operator_owned`, because the Node has no role. |
| Tool operations | `tool.node_unmanaged` (HTTP 409). |
| Fleet rollout | Left out with reason `platform`. |
| Doctor | Lifecycle check only, as for any Node without a pinned SSH host key. |

`node:wireguard-key` still sets the peer's public key, and `node:access:add` still grants it access to call the Gateway. Removing an `ipados` Node deletes the registry row and the hub peer. It skips the Node agent step and `firewall-recovery`. `retained_on_node` lists `host-wireguard`, the tunnel the operator configured on the device.

## Enroll through a jump host

The Gateway can enroll a Node that it reaches only through another Node. [Task VMs](/reference/compute-drivers#task-vms) use this: a task VM has only a private address on its Incus host's bridge. The jump is internal. `node:add` and the API have no field for it.

The Node records the jump Node in `ssh_jump_node_id`. While the Node has no active role, SSH to its public address goes through an `ssh` hop to the jump Node, as `<managed user>@<WireGuard address>:22` of the jump Node. The hop uses the same key, pinned host keys, and strict options as the command. See [An explicit hop, not ProxyJump](#an-explicit-hop-not-proxyjump).

The read-only `node.scan-host-key` agent command scans and fingerprints the target on the jump Node, or on the serving Gateway when no jump is needed. It prefers Ed25519, then ECDSA P-256, then RSA. The target needs no installed agent. The Gateway compares the result with the expected fingerprint before pinning it or installing anything on the target. A task VM's expected fingerprint comes from its host, before any code in the VM runs.

When the Node's host key is pinned, commands through its jump Node share one connection. Nodes behind different jump Nodes can have the same private address, and a new Node can reuse an address. So that connection is named after the jump Node, the target, and the target's pinned host key fingerprint. Before the key is pinned, each command opens its own connection. A jump Node without a WireGuard address fails with `vpn.peer_address_missing` before SSH.

The enrollment steps are the same as for any Node. When the first active role closes public SSH, the Gateway reaches the Node over WireGuard and ignores the jump. A Node without a jump Node connects directly, as [SSH connections](#ssh-connections) describes.

## Converge an existing Node

`node:add` for a recorded Node converges the machine again. It refuses a Node that owns Instances with `node.has_instances`. One exception: it changes only the TLD of a Node with an active `app-dev` role.

What a failure leaves depends on the step. A macOS Node uses [enrollment recovery](#enrollment-recovery) instead of this table.

| Failed step | New Node | Node that was `active` |
| --- | --- | --- |
| Steps 1 to 6 | `failed` at that step | `active` again, with its earlier record and network state restored |
| 8, storage settings | `active`, with no settings stored | `active`, with the earlier settings kept |
| 9, Node exporter or cAdvisor reconcile failure | `active`, with Metrics degraded | `active`, with Metrics degraded |
| 9, Metrics runtime on the Metrics Node fails | `active`, with Metrics degraded | `active`, with Metrics degraded |
| 10, Node agent | `failed` at `agent` | `active` again |

A storage-settings failure returns its `node.settings_*` code, and the request skips steps 9 and 10. Only an unexpected error in step 8 marks the Node `failed` at `node-storage-root`.

Step 9 runs whenever a Metrics role exists, except for a task VM, which never hosts an exporter. A task VM also holds back the reconcile that each role converge runs. A Metrics reconcile failure does not fail provisioning or demote an active Node, including the `gateway` Node. The Node stays `active`; Metrics reports the failure as degraded. This applies to exporter, cAdvisor, runtime, and unexpected failures, both when `node:add` provisions a Node and when it converges an existing Node. See [Metrics role](/reference/metrics#exporter-selection) for how exporter state and degradation are reported.

## PHP packages for production children

`node.prepare_php_packages` prepares PHP packages on one managed Linux Node that is active or marked failed. Its input is `{versions: [version], profile: "app-prod"}`. The Swamp deploy and rollback composition starts it as a child before activation on its serving Node. Standalone admission is refused. The current retained production, rollback and Route callers keep their synchronous package installer until their own cutover.

The Gateway captures the recipe versions, downloads and digests at admission. Swamp runs only `php.install-build` with that captured input. Dispatch refuses a changed recipe revision or an admission older than 120 seconds. It keeps existing modules, uses the recipe PCOV and OpenTelemetry policy, and sets `fpm: none`, so the dedicated runtime owns its restart.

Completion requires a successful keyed installer step with `outcome: current|applied`. Source refusals keep `app-prod.php_package_source_unavailable`; other installer refusals keep `app-prod.php_install_failed`. Results expose the outcome, fixed error code and bounded `stale_masters` and `deferred` lists. The Gateway logs those FPM master warnings. Missing result data or an outcome other than `current` or `applied` fails with `app-prod.php_install_failed`. A lost reply uses the same Operation and target receipt. The child requires Kit C list enforcement; admission is unavailable until it lands.

## Orbit CLI

Every Node of the [fleet rollout set](/reference/gateway-recovery#rollout-set-and-order) runs the Orbit CLI, so the fleet rollout can run [`orbit self-update`](/reference/self-update) there. Provisioning, role converge, and the fleet rollout install it with the same step.

| Item | Path or value |
| --- | --- |
| Binary | `/usr/local/bin/orbit-<version>`, `root:root` mode `0755` |
| Link | `/usr/local/bin/orbit`, a link to the binary. `orbit self-update` switches it with one rename and keeps the previous release |
| Profile | `/root/.orbit/config.json`, `root:root` mode `0600`: the active profile `gateway` with the URL `https://<Gateway WireGuard address>` and the root certificate `/etc/orbit/agent/ca.pem`, which the agent converge writes |
| Download | The desired CLI release's binary for `linux-<arch>`, from `https://github.com/hardimpactdev/orbit/releases/download/cli-v<version>/orbit-<version>-linux-<arch>` |

This is the layout `orbit self-update` keeps, so either one can update what the other installed. The profile holds no secret. The Gateway identifies the Node by its WireGuard address, as for every CLI call, and its certificate covers that address. `sudo orbit self-update` reads root's profile.

The agent's `cli.install` runs the step, and `cli.inspect` reads the link alone. Both read the link path first and run a found binary as the managed user, never as root. A link may name only an `orbit-0.N.0` file beside it, without `/` or `..`. The file must be a `root`-owned ELF executable. Only then does the step run it with `--version`, which must print `Orbit 0.N.0` or, for a pre-release build, `Orbit <40-character commit>`.

| Found | Result |
| --- | --- |
| Nothing | Download the binary to `/usr/local/bin/orbit-<version>.orbit-candidate`, check its SHA-256 from the desired state and that it reports the release version, move it to `orbit-<version>`, and switch the link: `cli_installed` |
| An Orbit CLI that has `self-update` and is the desired release, a newer one, or a pre-release build | Leave it: `cli_present`. `orbit self-update` replaces it |
| An older Orbit CLI release | Install the release as for a missing one. An older CLI's own self-update may refuse the current release, so the step does not rely on it |
| An Orbit CLI without `self-update`, such as a pre-release build | An older CLI. Install the release as for a missing one, and keep the old file as `orbit.orbit-previous` |
| Another link, a script, another program, or a file another user owns | Refuse with `cli.foreign_binary` without running a script. The fleet rollout leaves the Node out as `foreign_cli` until an operator moves the file aside |

The agent takes the Node's update lock, `/run/lock/orbit-self-update.lock`, before it installs, and reads the path again under it, because a self-update may have changed it meanwhile. The download, the move, and the link switch run under that lock, which `orbit self-update` holds too ([Node agent](/reference/node-agent#install-and-upgrade)). A self-update that keeps it longer than 300 seconds fails the step with `node.update_busy`. A checksum mismatch deletes the candidate and fails with `cli.checksum_mismatch`; the link stays as it was. Then the step writes root's profile when it differs.

| Code | Meaning |
| --- | --- |
| `cli.release_unavailable` | The CLI release is not published yet, so a missing CLI cannot be installed |
| `cli.architecture_unsupported` | The release has no binary for the Node's architecture |
| `cli.download_failed` | The download failed |
| `cli.checksum_mismatch` | The download has another SHA-256. Nothing was installed |
| `cli.candidate_invalid` | The download does not run, or reports another version. Nothing was installed |
| `cli.foreign_binary` | `/usr/local/bin/orbit` is a link, a script, or a file Orbit did not install, such as a wrapper around a source checkout |
| `cli.configuration_failed` | The profile could not be written, or the Gateway has no WireGuard address |

## Converge the Orbit footprint

`orbit node:converge <node> [--force]` re-applies what the Gateway renders for a Node, under the Node's role lock. The fleet rollout runs the same step on each Node. It never changes an Instance, a Process unit, or a role, and it never runs `node:add`, so it works on a Node that owns Instances.

| Artifact | On which Node | Re-apply |
| --- | --- | --- |
| `agent` | Every managed Linux Node | The [agent converge](/reference/node-agent#install-and-upgrade). It restarts the agent only when a file changed |
| `caddy-package` | A Node with a Caddy role or Caddy sites | The [Caddy package step](#package-sources). It installs Caddy only when it is missing or below the floor, so a running Caddy is not restarted |
| `caddy` | A Node with a Caddy role or Caddy sites | The [Caddy build](/reference/caddy-configuration). It reloads Caddy gracefully, only when the Caddyfile changed |
| `peer-dns` | Active managed Linux peers with roles, excluding the VPN holder and the Gateway | The [split private DNS policy](/reference/private-dns#resolver-selection), without a tunnel restart. Its digest includes the routing domains and selected DNS server |
| `private-dns` | The `vpn` Node, when it is not the Gateway's | The [private-DNS](/reference/private-dns) listener release, units, records, and catalog. It restarts the listener or dnsmasq only for a change |
| `route-residue` | A Node that an [offline Route removal](/reference/routes#remove-a-route-from-an-unreachable-node) skipped | Caddy and PHP-FPM without the removed Route, then its certificates and firewall rules. A failure is `skipped` and retried later |
| `php-runtime` | Every Linux Node with an active `app-dev` or `app-prod` role | The [managed PHP-FPM module](/reference/php-runtime#request-and-upload-limits) for the profile and active Instances. A change gracefully reloads valid active masters; `local.conf` stays unchanged |
| `tmpfiles` | Every Linux Node with an active `app-dev` role | `/etc/tmpfiles.d/orbit.conf`, through the agent's `node.publish-tmpfiles`. The daily `systemd-tmpfiles-clean` run empties each `/tmp/orbit-*` and `/dev/shm/orbit-*` directory a day after its contents last changed. It removes no other path and restarts nothing |
| `ai-router` | The [AI router](/reference/ai-router) collector's Node | The collector script. A changed script restarts the collector, a Node-owned Process |

Converge applies the artifacts in this order. The artifacts that [extensions contribute](/reference/extensions#plug-in-points), such as `ai-router`, come after core's. Each artifact has its own name.

Each artifact has a digest that the Gateway computes from its own code and pins, without SSH. The footprint combines every artifact digest with the agent pin for that Node. A change to native code therefore re-applies the artifacts even when their PHP sources have not changed; an unchanged pin and artifact digest skip Node work. Manifest locations and other platforms do not change this identity.

The Caddy package digest covers the package program and the `CaddyRelease` floor. The Caddy digest covers every Gateway source file the Caddy build renders from: the build, its site sources, the site sources that [extensions contribute](/reference/extensions#plug-in-points), and the classes they use, such as `DevelopmentSite` and the `CaddyRelease` pin.

The private-DNS digest covers the listener release, the publication code, and each private DNS record source that an extension contributes, with the classes it uses, such as the AI router state that decides the collector record. The other digests cover the inputs the agent unit renders from, the collector script, and the tmpfiles rule. The shared footprint boundary adds the agent pin to all of them.

A user's sites and Routes do not change the code digests. The PHP runtime digest includes the versions selected by active Instances, so a version outside the Node's recipe profile also receives the module. This digest never reads live inventory. The agent skips absent FPM binaries, and recipe convergence publishes the module after installing PHP. The `peer-dns` digest includes the rendered private domains and server, so namespace changes also reach peers through convergence. Their own operations publish them, and Doctor reports their drift.

The `route-residue` digest also covers state: it covers the residues of offline Route removals, which are Orbit's own unfinished work, so the Node drifts until a converge removes them. The Gateway keeps the digests each Node last received, and a converge re-applies only the artifacts whose digest changed. Its native binary installers own their update locks; it starts no separate SSH lock holder.

Removing a registered artifact changes the aggregate footprint digest. Converge invokes only artifacts that are still registered and apply to the Node. Unless forced, it skips each remaining artifact whose own digest is unchanged. After a successful converge, the Gateway drops the removed name from the stored artifact metadata. Removal itself does not delete or rewrite any Node files; files left by the removed artifact stay untouched.

Earlier Gateways published the annotator server in `/opt/orbit/annotator` and kept its stores in `/var/lib/orbit/annotator`. Orbit leaves these paths unmanaged. Convergence forgets the removed artifact but leaves its files in place; the machine recipe does not remove them. After destroying the removed preset Processes through the old Gateway, an operator may remove unused files by hand. Cleanup of both paths remains pending a dependency check for ordinary Processes 168 and 127. Preserve these Processes and their files. Check every ordinary Process dependency and obtain cleanup authorization before deleting retained files.

`--force` re-applies every artifact; each one still leaves a matching live copy alone. A Caddyfile that the render or the validation refuses is `skipped`, not failed; the [fleet rollout](/reference/gateway-recovery#one-node) fails a refused validation on its first Node. A failed artifact fails the command with its own error code and `details.artifact`, and the next converge applies it again. The digest of all artifacts is the Node's footprint digest, which the fleet catch-up compares.

Metrics exporters, cAdvisor, and the FPM exporter are not part of the footprint: their converge always restarts them, and the Metrics fleet reconcile owns them. Reverb and the third-party pins keep their own update paths.

| Code | HTTP | Meaning |
| --- | --- | --- |
| `node.converge_unsupported` | 422 | The Node is not an active, managed Linux Node |
| `node_role.node_busy` | 409 | Another role operation held the Node's lock for 2 minutes |
| `node.footprint_caddy_failed` | 502 | The Caddyfile could not be published |
| `node.footprint_caddy_package_failed` | 502 | The Caddy package step failed, for example because the download does not match the pin. The message names the cause |
| `node.footprint_tmpfiles_failed` | 502 | The tmpfiles rule could not be written. The message names the cause |

## Role compatibility

Some roles never share a Node. The Gateway refuses a conflicting role before it claims or converges anything.

| Role | Never shares a Node with |
| --- | --- |
| `gateway` | `ingress`, `app-dev`, `app-prod`, `database`, `analytics`, `ci-runner` |
| `ingress` | `gateway`, `app-dev`, `database` |
| `vpn` | `database`, `ci-runner` |
| `app-dev` | `gateway`, `ingress`, `app-prod` |
| `app-prod` | `gateway`, `app-dev`, `database` |
| `database` | `gateway`, `vpn`, `ingress`, `app-prod` |
| `analytics` | `gateway` |
| `ci-runner` | `gateway`, `vpn` |

`router`, `metrics`, and `websocket` share a Node with any role. `node:role:add` and `node:role:relocate` answer `validation.failed` with `Role [ROLE] conflicts with assigned role [OTHER].` `node:add` answers `node.role_conflict` (HTTP 422), with `Role [ROLE] conflicts with requested role [OTHER].` when one request names both roles. [Doctor](/cli/doctor) reports each assignment of a conflicting pair as `role.assignment_conflict`.

Not every role can be added the same way.

| Role | `node:add --role` | `node:role:add` |
| --- | --- | --- |
| `app-dev`, `app-prod`, `database`, `metrics`, `websocket` | Yes | Yes |
| `gateway` | Yes, as a singleton | Yes, as a singleton |
| `vpn` | Yes, as a singleton | No |
| `ingress`, `analytics`, `ci-runner` | No | Yes |
| `router` | No | No. Use [`cluster:router:set`](/cli/cluster#orbit-clusterrouterset). |

## Package sources

A role installs its packages from the Ubuntu archive, except PHP and Caddy.

| Package | Source | Files Orbit owns |
| --- | --- | --- |
| PHP | The Sury `.deb` files that the [recipe's PHP build](/reference/compute-drivers#php-build) pins, from Orbit's mirror or Sury | `/var/cache/orbit/php-packages`, every pinned and repacked file, kept for a rollback |
| Caddy | The `.deb` of a pinned [Caddy release](https://github.com/caddyserver/caddy/releases) on GitHub | None |

For PHP, the Gateway sends each pin's URL, version, and SHA-256 digest to the Node with [`php.install-build`](/reference/compute-drivers#install-the-php-build). The Node downloads only over HTTPS, from Orbit's mirror first, and refuses a file whose digest, package name, version, or architecture differs. Orbit adds no PHP apt source. The installer deletes the Sury source that earlier Orbit releases wrote and refuses any other PHP apt source, such as `ppa:ondrej/php`. When every package is at its recipe version, it downloads nothing.

The installer also repairs the [managed PHP settings](/reference/compute-drivers#install-the-php-build) when the packages already match. A settings-only repair validates every active FPM master, including dedicated production masters, and gracefully reloads those that pass. Instance steps defer and log invalid masters while keeping the INI and continuing with the other masters. Recipe convergence instead fails and restores the previous settings. The log also warns when masters are left running without a restart or reload because they may still use the previous build or settings.

For Caddy, the Gateway pins a release and the SHA-512 digest of its `amd64` `.deb`. It downloads the package only when Caddy is missing or below the floor, refuses a download whose digest does not match, and installs it with apt. A Node on another architecture, including `arm64`, fails the step with `caddy.package_failed`. The step adds no apt source. It deletes `/etc/apt/sources.list.d/orbit-caddy.sources` and `/usr/share/keyrings/orbit-caddy.gpg`, which earlier Orbit releases wrote for the Caddy apt source on Cloudsmith.

### Role prerequisites

Each role installs its prerequisites through the agent's `role.install-prerequisites`. The role guard first reads `ID` and `VERSION_CODENAME` with `node.os-release` and refuses a release the role does not support. The command checks the release again and installs the role's packages with `apt-get`, which waits up to 300 seconds for the dpkg locks. A healthy Docker CE installation replaces `docker.io`.

For `app-dev`, it also creates `<home>/apps` for the managed user. For `app-dev` and `app-prod`, it prepares the shared Composer home, `/opt/orbit/composer`. A path that Orbit did not make fails the step with `role.prerequisite_conflict` before any change.

An application role then installs the JavaScript runtimes its [recipe profile](/reference/compute-drivers#role-and-base-tool-sets) pins: Node, Vite+, pnpm, and Bun. It sends `recipe.install-javascript`, the same command and verified archives as [recipe convergence](#converge-a-node-to-a-recipe), and it runs no downloaded installer. Image programs render the same call as `javascript-runtime`. Like recipe convergence, it replaces any root-owned launcher in `/usr/local/bin`. After it verifies the recipe Node, it removes the apt `nodejs` package, retires the NodeSource apt source, and removes Corepack links.

Caddy must be at least 2.9.0. A lower release fails the `caddy-package-source` step and names both releases. Doctor reports it as `role.caddy_version_unsupported`. Converging a role upgrades an archive Caddy in place. A Caddy at or above the floor stays as it is, so a newer pin does not restart it. `/etc/caddy/Caddyfile` is a symlink into Orbit's own versions directory, so the upgrade keeps the live configuration.

The roles `gateway`, `router`, `ingress`, `app-dev`, `app-prod`, `websocket`, and `analytics` install Caddy when they converge. AI router publication does the same on its Node. On the Gateway machine, the bootstrap and `php artisan orbit:gateway-web` install Caddy through the agent command `caddy.install-package`. A failure there stops at step `gateway-caddy-install` with `gateway.caddy_install_failed`, and the live Caddy configuration stays unchanged. [Caddy configuration](/reference/caddy-configuration) describes how the Gateway builds each Node's Caddyfile.

`caddy.install-package` owns package installation for every caller. The Gateway sends the release pins and keeps the operation error mapping. The agent owns the download URL, kernel setting, and obsolete source paths. It checks the pins and host paths before mutation, verifies the downloaded package before apt sees it, and publishes the kernel setting atomically. Package and system commands share the command deadline. Rust tests cover installation, repetition, drift, and refusal; Gateway tests cover intent. `caddy.order-after-vpn` then orders Caddy after WireGuard. Its failure maps to `gateway.caddy_start_failed`.

Gateway VPN convergence calls `wireguard.remove-dns-ordering` to remove the managed dnsmasq ordering drop-in. dnsmasq provides `nss-lookup.target`, which WireGuard starts after; ordering dnsmasq after WireGuard creates a boot cycle. An absent drop-in needs no service call. Removal preserves other drop-ins, reloads systemd, and restarts dnsmasq. Failed activation restores the prior file and service state.

### Caddy reloads

The Caddy step also writes `/etc/sysctl.d/60-orbit-caddy.conf`, owned by `root:root` with mode `0644`:

```text
net.ipv4.tcp_migrate_req = 1
```

On each reload, Caddy opens a new listening socket and closes the old one. With this setting, the kernel moves the connections that wait on the old socket to the new one, instead of resetting them. The step loads a candidate file with `sysctl --load` first, so a kernel that refuses the setting fails the step and leaves no file. It applies the setting on every run, so a changed live value returns to `1`. It refuses a live file that is a symlink, not a regular file, or not `root:root` mode `0644`. Doctor does not check the setting. Converge the role to repair it.

HTTP/1.1 clients can still get an empty reply on a new connection during a reload. The CLI and the PHP SDK use HTTP/1.1. The SDK retries a `GET` or `HEAD` request once after 250 milliseconds when the connection fails without a response. A request that changes state is never retried, so it can fail while the Gateway's Caddy reloads. Run it again. Browsers and curl use HTTP/2 and do not see this.

### Caddy on the Gateway machine

Converging the `gateway` role runs the Caddy step over SSH before its firewall step. A failure returns `node_role.convergence_failed` for step `caddy-package-source`, and the role becomes `failed` with error code `gateway.caddy_install_failed`. Run `orbit node:role:add <node> gateway --converge` to repair it. While the role is `failed`, `php artisan orbit:gateway-web` refuses with `gateway.web_node_missing`, because it needs an active `gateway` role.

Caddy also serves the Gateway API. A convergence that installs a newer Caddy restarts it, so the CLI can report `Could not reach the gateway.` while the Gateway finishes. `orbit activity:list` shows the real result. Run the command again after a minute.

## Role operations on one Node

The Gateway runs one role operation per Node at a time. The role lock covers the role's baseline convergence or removal, the Tool Manager setup for `app-dev` and `app-prod`, and the Node agent converge that follows.

`node:role:add`, `node:role:remove`, and the role steps of `node:add` take the lock before they claim the assignment. A second operation waits up to 2 minutes. Then it fails and leaves the assignment as it was.

| Command | Error |
| --- | --- |
| `node:role:add` | `node_role.convergence_failed`, with `details.step` `converge:node-lock` and `details.error_code` `node_role.node_busy` |
| `node:role:remove` | `node_role.remove_failed`, with `details.step` `node-lock` and `details.error_code` `node_role.node_busy` |

Every failed role operation returns its step's own code in `details.error_code`, such as `node_role.tool_manager_locked`, a `metrics.*` code, or `ingress.caddy_config_failed` for a Caddy removal. `node:add` and `node:remove` do the same when a role step failed.

`node:role:relocate` and Cluster Router changes take the lock only around the baseline step. The Metrics fleet reconcile runs inside the lock for `node:role:add`, `node:role:remove`, and `node:add`. It converges exporters on other Nodes without their locks, so two operations can never wait on each other. Operations on different Nodes run in parallel. An offline role removal runs outside the lock, because it changes nothing on the unreachable machine.

An operation that dies, such as a killed Gateway worker, leaves its role `provisioning` or `removing`. The claim is stale once it is 11 minutes old and no operation holds the lock. That is the role lock's request term plus a 1-minute margin. `node:role:add --converge` and `node:role:remove` then take it over, and Doctor reports `role.claim_stale`.

### Per-Node locks

Four locks guard work on one Node. They live in a file cache store under `ORBIT_HOME`, whatever `CACHE_STORE` says.

| Lock | Guards | Term | When it is busy |
| --- | --- | --- | --- |
| Tool | One package of one Tool Manager | 10 minutes | Fails at once with `tool.operation_locked` |
| Tool Manager | The shared scope of `apt`, `vp`, `composer`, or Homebrew; `brew` and `brew-cask` share the prefix lock | 10 minutes | Fails at once with `tool.operation_locked`, `node.tool_manager_locked` during `node:add`, or `node_role.tool_manager_locked` in a role operation |
| Role | Role operations | 10 minutes | Waits up to 2 minutes, then `node_role.node_busy` |
| Node agent | The [agent converge](/reference/node-agent#install-and-upgrade) | 7 minutes | Waits up to 2 minutes, then `agent.converge_busy` |

A Tool operation takes its Tool lock, then its manager lock. Several manager locks are taken in the order `apt`, `vp`, `composer`, `brew`. `brew-cask` takes the `brew` lock rather than a fifth lock. A role operation on `app-dev` or `app-prod` takes the `vp` and `composer` manager locks first, and then the role lock. The Node agent lock comes last. The locks cannot deadlock: the Tool and manager locks never wait, and no code takes the role lock while it holds the agent lock.

### Lock renewal

The 10-minute term matches the Gateway's PHP-FPM request limit. In an Artisan command, such as `orbit:node-provision`, the Tool, manager, and role locks have a 20-minute term. Each remote command times out after 15 minutes by default.

Before each command that a process runs, on the Node or on the Gateway, the Gateway renews every lock that the process holds for its full term. So a long operation keeps its locks, and a process that dies blocks the Node for at most one term after its last command started. A renewal fails only when the lock has expired. The command then does not run and fails with `node.lock_lost`, and so does every later command of the operation. A role operation that lost its lock stops, reports `details.error_code` `node.lock_lost`, and leaves the role `failed` with that code.

## SSH connections

The Gateway runs every remote command as a channel on one shared OpenSSH connection per Node. The first command opens the connection. Later commands reuse it, and it closes after 60 idle seconds. A command on a shared connection takes about 18 ms, and a command on a new connection about 190 ms.

The sockets live in `ORBIT_HOME/ssh/mux`, and the directory has mode `0700`. Every Gateway process runs as the `orbit` user. So web requests, the scheduler, and commands share the same connections. Each socket name hashes the user, the Node address, and the port. A Node with a new address or port therefore gets a new connection at once.

- A dead Node ends its connection within about 10 seconds, through `ServerAliveInterval=5` and `ServerAliveCountMax=2`.
- A key or host-key change on the Node applies when the connection closes.
- When the socket path is too long for a Unix socket, or the directory cannot be created, each command opens its own connection.
- When a Node refuses another channel, OpenSSH opens a direct connection for that command and writes two warning lines to its stderr.

A reachability check always opens a new connection. Doctor's Node inspection, the `--offline` probe of role and Node removal, and the Node probe of task cancellation use it. File copies between Nodes for Instance transfer and clone use `scp` on their own connections.

A Node that the Gateway reaches through a [jump Node](#enroll-through-a-jump-host) shares a connection named after the jump Node, the Node's address, and its pinned host key.

## Public SSH

The bootstrap adds the UFW rule `orbit:public-ssh-recovery` and enables UFW. An [UpCloud task VM](/reference/compute-drivers#create-and-reach-an-upcloud-server) boots with both from its template, so the bootstrap finds them in place. Once SSH answers over WireGuard, the Gateway adds `orbit:wireguard-members` and keeps public SSH open. The first active role removes the public SSH rule, so the Gateway then reaches the Node only over WireGuard.

- A Node without an active role stays reachable over public SSH.
- A later `node:add` of such a Node connects over public SSH again. It finishes the WireGuard peer over the verified tunnel.
- Removing the last role restores `orbit:public-ssh-recovery` over WireGuard before the Gateway deletes the assignment.
- When that restore fails, the role stays `failed` at step `remove:firewall-recovery` with `node.firewall_recovery_failed`. A retry repeats the removal.
- Removing a role while another role remains keeps public SSH closed.
- [Node retarget](/reference/node-retarget) picks its path from the same boundary.

## WireGuard hub

The Node with the `vpn` role holds the WireGuard hub: its private key, address, listen port, and the list of peers. When a peer changes, the Gateway renders the hub configuration and installs it there. [`node:wireguard-key`](/cli/node#orbit-nodewireguard-key) sets or replaces an unmanaged Node's public key and runs this projection without configuring the peer machine. It refuses managed Nodes, Gateway Nodes, and keys that collide with another Node or the hub, including concurrent assignments. A successful projection preserves non-target peers.

Repeating the same key still projects the hub when it has no collision; after a projection failure, the stored key remains and the operator retries after repairing the hub. A crash can leave either the old or new hub configuration, without guaranteed rollback or automatic reconciliation. [WireGuard key recovery](/cli/node#orbit-nodewireguard-key) explains how to restore an authorized management path and replay the desired key.

The Gateway runs the [Node agent](/reference/node-agent#commands)'s `wireguard.project-hub` on the `vpn` Node, with the configuration and its private key in the protected input. When the `vpn` and `gateway` roles share a machine, the runner starts it as a local process; otherwise it reaches the `vpn` Node over SSH to its WireGuard address. The command writes a root-only candidate, validates it with `wg-quick strip`, keeps the live file as a backup, and swaps the candidate in. Then it live-syncs a running `wg-quick@orbit` with `wg syncconf`, or enables and starts a stopped one. When activation fails, it puts the backup and the previous enabled and active state back.

| Agent refusal | Step | Error code |
| --- | --- | --- |
| `wireguard.hub_install_failed` | `wireguard-server-install` | `vpn.server_config_install_failed` |
| `wireguard.hub_config_invalid` | `wireguard-server-validate` | `vpn.server_config_invalid` |
| `wireguard.hub_start_failed`, or no result | `wireguard-server-restart` | `vpn.server_start_failed` |

The Gateway always keeps the rendered hub configuration, private key included, at `ORBIT_HOME/generated/wireguard/orbit.conf`. Doctor compares it, and the VPN DNS records, with the digests that `wireguard.inspect-hub` reads on the `vpn` Node.

Peer convergence still runs over SSH. It generates or reads the peer key and installs the peer configuration during admission, over the public bootstrap address, before the tunnel that the agent's transport uses exists.

## Remove a Node

`orbit node:remove <node> [--offline] [--force]` removes the Node record and its Gateway configuration. It does not clean the machine. [macOS removal](#removal) also skips the Linux agent and firewall steps and reports the retained user, package managers, and host tunnel.

Task VM host intent is an explicit [Node setting](/reference/node-settings#configure-a-task-vm-host), separate from its roles. Enrollment accepts it inside `settings.task_vms`; it does not infer host approval from `app-dev`. Before removing a host, destroy its task VMs, unset its host configuration,. Removing a role alone does not clear host intent.

The Gateway refuses the removal until the Node is empty. Remove its Instances, Routes, Schedules, roles, Processes, [Database servers](/reference/database-servers#remove-a-server), and Orbit firewall rules first. Orbit never removes the caller's Node, the `gateway` Node, or the `vpn` Node.

| Code | Condition |
| --- | --- |
| `node.has_instances`, `node.has_routes`, `schedule.target_in_use`, `node.has_roles`, `node.has_processes`, `node.has_firewall_rules`, `node.has_database_servers` | The Node still owns that state. |
| `node.has_task_vms` | The Node hosts a [task VM](/reference/compute-drivers#destroy-a-task-vm) that is not `destroyed`. |
| `node.task_vm_host_configured` | The Node has `settings.task_vms`. [Retire the host](/reference/compute-drivers#add-a-task-vm-host) first, so the other hosts stop accepting its key. |
| `route.reconciliation_required` | An active Route depends on the Node. |
| `node.self_removal_forbidden`, `node.gateway_removal_forbidden`, `node.vpn_removal_forbidden` | The Node is protected. |
| `node.provisioning_busy` | Another lifecycle operation holds the Node name. |

Online removal runs these steps in order.

| Step | Result | Code when it fails |
| --- | --- | --- |
| `grafana-access-revocation` | The Gateway revokes the Node's Grafana access. | `node.grafana_access_revocation_failed` |
| `metrics-exporters` | The Gateway retires the Node's exporter state and reconciles the fleet. | `node.metrics_reconcile_failed` |
| Node agent | The Gateway stops and disables `orbit-agent.service` and deletes the unit, `/usr/local/bin/orbit-agent`, and `/etc/orbit/agent`. A failure only logs a warning. | none |
| `firewall-recovery` | The Gateway restores `orbit:public-ssh-recovery` over WireGuard. It skips this step for a Node without a WireGuard peer. | `node.firewall_recovery_failed` |
| `wireguard-projection` | The Gateway removes the WireGuard peer. | `node.wireguard_projection_failed` |
| `router-lan-ingress` | The Gateway removes the Node's Router LAN access in its Cluster. | `router.lan_ingress_failed` |
| `dns-projection` | The Gateway converges private DNS. | `node.dns_projection_failed` |
| `persistence` | The Gateway deletes the Node record. | `node.persistence_failed` |

A failed step returns the Node record to the status it had, restores the Metrics selection, and restores the WireGuard peer when the Gateway removed it. So a `failed` Node stays `failed`. When a rollback itself fails, the Gateway returns `node.removal_rollback_failed` with the step `wireguard-rollback`, `persistence-rollback`, or `metrics-exporters-rollback`.

### Offline removal

Use `--offline` only for a Node that the Gateway cannot reach. The Gateway probes the Node first. A Node that answers keeps every guard above, but the removal still skips `firewall-recovery`. So omit `--offline` for a Node that is up. A reachable Linux removal returns an empty `retained_on_node`. The Gateway still removes the Node agent, and public SSH stays as it was because firewall recovery did not run.

The Node of a task VM is the exception: `DestroyTaskVm` deletes the VM first and removes the Node without a probe, and without a Metrics pass, because a task VM Node never hosts an exporter. See [Destroy a task VM](/reference/compute-drivers#destroy-a-task-vm).

For a Node that does not answer, the API needs `force`, or the Gateway refuses with `node.confirmation_required`. The CLI sends it after `--force` or a yes at the prompt. The guards for Instances, Routes, Schedules, protected Nodes, and firewall rules still apply. The Gateway then removes every role on its own side, deletes the Node's Process records, removes the WireGuard peer, and deletes the record. It changes nothing on the machine. Caddy sites, checkouts, containers, Process units, Orbit UFW rules, the Metrics exporter, and the Node agent stay in place, and public SSH stays closed.

`retained_on_node` lists the Node agent, the Metrics exporter, and each shed role's leftovers. `follow_up` says those leftovers stay until you clear them. macOS does not use that list. [macOS removal](#removal) always reports `user`, `package-managers`, and `host-wireguard`.

### Add the machine again

After an online removal, the machine keeps its WireGuard tunnel, which can still point DNS at Orbit. When a new bootstrap cannot resolve the package sources for that reason, and the request names at least one role and no `--dns-server` override, it clears only the DNS server and route-all domain that match Orbit's ownership record on the `orbit` link.

The native `node.bootstrap` command holds the peer lock while it uses the machine's own DNS for the base packages and restores the earlier link values. It tries APT first, so working proxies and custom transports need no local DNS probe. After a failed update, it inspects source hosts without executing source text. It keeps operator overrides, refuses missing or changed ownership state, and changes no global resolver file or IP route. It attempts to restore both resolver properties on failure or interruption.

Account setup starts only after packages and DNS restoration succeed. A Node added again without a role, or with a DNS server override, keeps its DNS as it is, and the bootstrap fails when the sources do not resolve.

Provisioning then writes a new tunnel configuration and restarts `wg-quick@orbit`. When the Node sends private DNS through WireGuard, the configuration has a `PostUp` hook that sends only the [rendered Orbit private domains](/reference/private-dns#resolver-selection) to Orbit DNS. Public names keep the provider or DHCP resolver. Every split-DNS apply first requires a non-`orbit` per-link or global DNS server; absent one, it refuses with exit 47 (`vpn.peer_dns_uplink_missing`). Existing per-Node DNS overrides and global DNS overrides outside the usable WireGuard subnet are cleared by migrations; endpoint overrides stay unchanged. It never has a `PreDown` hook, because the AppArmor profile that Ubuntu 26.04 ships for wg-quick denies the resolver revert call.

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### Removal does not clean the machine

`node:remove` is a registry and VPN change, plus one firewall rule. Cleaning the machine during removal would change it while the command says it does not, and a failed cleanup would leave a half-removed Node. So you remove Processes, roles, and Instances with their own commands first. `node:add` covers both provisioning and convergence, so the pair is add and remove. Create and destroy stay reserved for machines at a hosting provider.

### One shared SSH connection per Node

Converges and removals run long chains of commands, and a new connection costs about ten times the command. A persistent SSH tunnel is rejected, because WireGuard already gives the private network. A higher `MaxSessions` on every Node is rejected, because OpenSSH already falls back to a direct connection. A reachability check cannot use the shared connection, because that connection outlives a stopped sshd and would report a Node as reachable.

### An explicit hop, not ProxyJump

OpenSSH's `ProxyJump` does not pass the identity file, the known-hosts file, or the strict host-key options to the jump hop. The Gateway keeps its key and pinned host keys in `ORBIT_HOME/ssh`, so that hop would not find them. An explicit `ProxyCommand` hop passes them.

### Public SSH before the peer goes

Role convergence closes public SSH. Without the recovery rule, a removed machine is reachable only through its provider console. So the Gateway reopens public SSH while the tunnel still works, and then removes the peer.

### A jump host for private Nodes

A Node on a private bridge has no public SSH address. SSH through its host needs no proxy device or DNAT rule on the host, and the Node keeps SSH on port 22, as the firewall catalog expects. Reading the host key from the host, not from the new Node, keeps the trust anchor outside the machine being enrolled.

### The hub stays on the vpn Node

When `gateway` runs on another machine, that machine is itself a WireGuard peer. Installing the hub key there would overwrite its peer configuration and cut the Gateway off the network. So the hub configuration always goes to the `vpn` Node, and a missing SSH path fails instead of falling back to a local install. When both roles share a machine, a local install avoids SSH to itself.

### A kernel setting for Caddy reloads

Caddy's `grace_period` and `shutdown_delay`, a reload through the admin API, and a certificate cache that survives reloads leave the reset count unchanged in measurements. Handing Caddy a systemd socket would change every listener for the same effect. `net.ipv4.tcp_migrate_req` cut the resets by about 93%. It needs Linux 5.14 or newer, which every supported Ubuntu release has.

### Caddy from its GitHub release, not its apt source

The Caddy project publishes its apt source on Cloudsmith. That source broke four times: a used-up bandwidth quota in 2024, an expired key in December 2025, an expired signing subkey in September 2026, and `402 Payment Required` on every request from October 2026. A source that fails to fetch fails every `apt-get update` on the Node, so it also broke steps that never install Caddy, such as the PHP step.

The `.deb` on the GitHub release is the Caddy project's own build, and a digest pin needs no third-party signing key. A converge does not upgrade a Caddy that already reaches the floor, because an upgrade restarts Caddy on every Node of the rollout.

### The footprint converge, not node:add

`orbit node:converge <node>` records a `node.converge` Operation and returns its ID for a workload Node. Nodes configured as Services or executor workers keep synchronous convergence through the same command, returning HTTP 200 with the footprint result. They use the same executor-Node classification as Process controls. This recovery path requires no Swamp worker. Fleet children retain the declared Operation contract. Swamp re-applies one managed Linux Node's Gateway-rendered footprint. The input is `{force_artifacts: bool, first_visit: bool}`. The serving Node comes from the Operation. The Gateway records the plan, command payloads and optional child intents before effects. Child admission prepares this snapshot before the shared kit stamps its immutable input.

Only changed artifacts run unless force or owned drift requires repair. A failed agent pin also forces agent repair. The four optional children are `route.caddy_converge`, `route.private_dns_publish`, `route.offline_residue_clean` and `ai_router.collector_converge`. Orphaned residue uses `{removed_node_id: null}`. Every recorded child must settle before successful completion; absent intents need no child. Results contain `artifacts: [{name, status, reason}]` and `skipped: [{child_id, key, kind, reason}]`. Skips contain verified failed children only.

Caddy render or validation refusals and residue cleanup failures may be skipped. If Caddy refuses validation on the first visit and managed code changed, the footprint fails. An owned drift repair keeps the skip. Other child failures stop later artifacts. Completion derives its results from transport receipts and stamped children, then records only proven artifact digests.

The Swamp footprint refuses a required AI-router effect with `operation.kind_unavailable` before recording child intent if `ai_router.collector_converge` is not registered.

On VPN and Gateway Nodes, the Swamp footprint stages the private DNS listener release and units before publication. It restarts an active listener against its existing catalog. A fresh install stays inactive until the publication child atomically writes its first catalog and enables the socket. The footprint does not write DNS records itself. Retained synchronous fleet convergence keeps its existing private DNS scope and digests; Gateway listener staging belongs to Swamp preparation.

Agent configuration stores its returned hash through the encrypted Node owner store. Target receipts retain only a presence marker. A lost live reply leaves the run uncertain and cannot prove storage or completion. Use the [operator re-key command](/reference/node-agent#recover-a-lost-agent-secret-reply) to restore authentication, then cancel the uncertain Operation and start a fresh converge.

### A Mac needs no service role

A Mac is a managed Node for tools, not a service host. It uses the account, WireGuard identity, and SSH access that already exist. Enrollment pins the approved host key and checks the platform, architecture, account, and tunnel before it succeeds. It creates no account, installs no Ubuntu packages, and does not change host DNS or the firewall.

An application role was rejected because tools do not need application services and the role list stays empty. SSH carries named agent commands for enrollment, package operations, and Doctor. The command binary does not run an observer or login-session service on macOS. The Ubuntu bootstrap was rejected because its packages, users, resolver, firewall, and systemd units do not apply to macOS. A second Homebrew or Vite+ install was rejected because the packages already on the machine would stay outside the scope Orbit manages.

Platform support is checked per operation. Tool management and Doctor run on the enrolled Mac. Linux roles, exporters, Processes, Schedules, and the observer service do not. A role assignment fails before any remote change. macOS OS updates, firewall management, application hosting, and a macOS login-session agent are separate features. A roleless Ubuntu Node proves the same empty-role boundary on Linux, including apt adoption without a reinstall. A real Mac proves enrollment and the Homebrew and Vite+ lifecycle. Low free space on that Mac makes the Node drift with `node.disk_low` while informational package findings stay informational.

### CI roles own hosts; operators own runner registration

The CI runner role uses the existing role lifecycle, firewall, Doctor, exporter, agent, and fleet rules. Selecting software through a recorded profile hides workload Nodes and bypasses those rules. The role replaces that exception. Sharing with app and database roles permits Beast's existing workload mix; a CI-only Node such as Shark needs no app role. The project, bridge, ACL, and profile names are reserved for the role, so its first convergence adopts the existing setup-script projection in place.

Container creation and org registration remain together in the operator script. Splitting creation into the role while leaving registration outside it would create unregistered containers and a second partial lifecycle. This host role does not implement Gateway-owned runner records or GitHub App registration: the deployed runners serve the organization, and the Gateway lacks the organization token. Giving the App repository Administration write would not implement that boundary and would expand its permissions. A future managed runner lifecycle needs an explicit credential and ownership decision.

Gateway-owned runner records and their credential flow remain in progress in [ADR 0213](/decisions/0213-run-ci-runners-through-a-ci-runner-role). This host role needs no GitHub permission change.
