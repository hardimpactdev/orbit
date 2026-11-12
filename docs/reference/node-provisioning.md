---
title: "Node provisioning"
description: "How node:add bootstraps or converges a Node, how roles share and lock a Node, how the Orbit CLI and footprint reach it, and how node:remove hands it back."
covers:
  - swamp/{ansible/**,extensions/behaviors/operations/ansible-input/**,manifest.yaml,extensions/behaviors/operations/{fleet/{cli_converge_local,cli_install,release,release_local}.ts,node-enrollment-plan/**,node-removal-command/**},workflows/workflow-{operation-node-add,operation-node-remove,operation-node-role-relocate,node-role-remove,node-caddy-package,node-caddy-sites,node-private-dns,node-php-runtime,node-route-residue,node-cli-install,fleet-release-node}.yaml}
  - apps/gateway/app/{Domain/Nodes/{NodeNetworkReservation,NodeRemovalInput,NativeNodeRoleRemovalInput,NativeNodeRoleRelocateInput,NodeRoleRelocateInput,NodeRoleRelocateProjection,NativeNodeEnrollmentInput,NativeNodeEnrollmentProjection,NodeEnrollmentPolicy,NodeRemovalResources,NodeRemovalProjection,NodeRoleRemovalProjection,direct-operations}.php,Actions/{Gateway/BootstrapGatewayAction.php,Nodes/{ProvisionNodeAction,EnrollMacOsNodeAction,RemoveNodeAction,AddNodeRoleAction,AssignRoleAction,AdmitNodeRoleOperationAction,AdmitNodeEnrollmentOperationAction}.php}}
  - apps/gateway/app/Infrastructure/{WireGuard/NativeGatewayPeerProjectionManager.php,Nodes/{Roles/{CiRunnerRoleBaseline,NativeNodeRoleFirewallManager},CiRunnerHostProgram,NativeNodeConverger,MacOsNodeConverger,NodeSshJump,NodeBootstrapCommandFactory,NodeBootstrapDnsProgram,NodeBootstrapPackageCatalog,CaddyPackage*,RemotePhpPackageManager,NodeLocks,NodeLock}.php}
  - apps/gateway/app/{Console/Commands/{ProvisionNodeCommand,BootstrapPlanCommand,BootstrapControlPlanCommand,RoleAddCommand,NodeAddCommand,NodeConvergeCommand},Domain/Gateway/{GatewayBootstrapPlan,GatewayControlPlan}}.php
  - apps/gateway/{database/migrations/2026_12_03_000000_allow_cold_recipe_image_rebuilds.php,app/{Infrastructure/MachineSoftware/NodeRecipe*.php,Domain/Nodes/RoleRegistry.php}}
  - apps/gateway/app/Infrastructure/{Swamp/SwampSshExecutionInput,Firewall/NodeFirewallRuleCatalog,Ssh/NativeSshExecutor,Ssh/SshConnection,Ssh/SshHostKeyScanner,Ssh/HostKeyScanner}.php
  - apps/gateway/app/{Infrastructure/Nodes/*Cli*.php,Domain/Nodes/NodeCli*.php,Domain/Fleet/NodeFootprint*.php,Domain/Fleet/CliConverge*.php,Infrastructure/Fleet/Footprint/**,Actions/Fleet/ConvergeNodeFootprintAction.php,Http/Controllers/Api/NodeFootprintsController.php,Actions/Nodes/StartNodePhpPackagesAction.php,Domain/Nodes/NodePhpPackages*.php,Domain/Nodes/operation-kinds.php}
  - apps/agent/src/commands/{ci_runner.rs,recipe.rs,recipe/**,php_build.rs,php_build/**}
---

# Node provisioning

An [Instance container](/reference/development-containers#processes) runs FrankenPHP and development workers as `orbit-*` units inside the container. Its web configuration follows the [container proxy trust rules](/reference/development-containers#php). Container units order after `network.target` and use nonblocking starts with bounded readiness checks. Migration reads only [application and unit-named environment files](/cli/instance/migrate#before-you-start) in its service preflight. Migration adds a [Process stability check before cutover](/cli/instance/migrate); other lifecycle and direct Process operations keep their own readiness checks. A shared runtime folder update restarts those units in each running container. The [clone copy](/reference/development-containers#lifecycle) supplies its complete root disk device, including when the seed inherited it from the Node's profile.

Migrate and clone launch copy the recipe Node into a private writable store and fetch pins as the app user. This release changes no image, boot unit, startup hook or converge task. Fresh default creates retain the stock shared store and report that pins are not prepared. Existing stores are unchanged by converge.

Preparing an `app-dev` Node for [Instance containers](/reference/development-containers#prepare-a-node) keeps its existing checkout Instances. Each checkout moves only through [`instance:migrate`](/cli/instance/migrate), after the container host is ready. The role installs the profile's default-reject NIC policy from the admitted common ACL name. Before starting an image builder or clone probe, it uses the same [effective-NIC gate](/reference/development-containers#network) as Instance starts. It also replaces old bridge union ACL references on existing NICs with the common NIC ACL, retaining their reject defaults. Its read-only end check repeats the audit, including stopped containers. One converge applies this to an existing host without restarting its containers.

Migration checks remote source access before quiesce using the Node's existing Git transport. Its temporary fetch credential is mode 0600 and is removed after success, refusal or timeout; no source token enters the container.

Each argument of an Ansible command must parse as a string. One shared test reads every literal `argv` list. It fails on a YAML mapping, a number or a boolean.

Container Process journal and state reads use this Node’s existing SSH account and Incus access, under the [transport exception](/reference/node-agent#commands). They add no account, group membership or sudo rule. The Node already requires `python3` for the container host’s effective-NIC gate and bridge reader.

Bridge endpoint convergence uses the [shared endpoint writer](/reference/development-containers#prepare-a-node). If another writer owns its lock, the phase refuses immediately with `instance.bridge_busy`, leaving endpoint records, ACL rules and health unchanged. Retry after that writer finishes.

`orbit node:add <name> [host]` adds a machine to the fleet. For a Node that already exists, the same command converges it again after you change its TLD, roles, or settings. `orbit node:remove <node>` removes the Node from Orbit and leaves the machine reachable over public SSH. [`node`](/cli/node) lists the options.

Standalone `node.converge`, `node.ssh_access_converge`, `node.agent_rekey` and apps-root `node.update` run on 209 through official SSH with the Node's stored host keys. They pass their complete public intent at admission and need no target worker. Settings, converge, SSH access and rekey use official `@swamp/cfgmgmt` behaviors and ship no script. Converge applies each role part in its own step; it calls no agent command and never rotates the secret. Neither operation installs a per-Node worker or accepts worker enrollment credentials.

The public SSH intent of a Node carries its target `name`, `node_id`, `host`, `user`, `port` and pinned `host_keys`. Steps that reach a task VM name their SSH behaviors by `node_id`: the VM's address returns to the pool and the next VM reuses it, but its Node id is never reused. The local and data steps of the same workflows hold no host key, so they name themselves by the address: one lock per VM and a bounded set of names.

The Gateway projects the terminal result and records settings or completed footprint artifacts.

Native role prerequisites retain a complete Docker CE installation instead of installing `docker.io` over it. One bounded APT command installs missing prerequisites with removal and upgrades disabled. The end check verifies the retained provider packages or the normal Ubuntu packages. Exporter children use bounded fan-out so a single control worker does not exhaust their native queue timeout.

Node and standalone role removal also execute from 209. They remove services, files, containers and authorized keys with official cfgmgmt behaviors. Direct commands use SSH argv. No removal script ships to a worker or a Node. Publication withdrawal finishes before runtime removal. Node removal publishes Metrics membership before stopping exporters, updates the full hub configuration, and removes management keys last. Offline consent skips only an unreachable departing Node; other participants still converge.

Removal takes the Node off the hub by deleting its own peer file and loading the files that remain, also when the Gateway never recorded its key. See [Converge the hub](/reference/wireguard-endpoints#converge-the-hub).

Removal retains packages, accounts, runtime data and unrelated SSH keys. Explicit role purge removes the admitted WebSocket releases, Metrics volumes, or drained CI resources. A regular CI role removal retains its project, image cache and network. Native resource checks and one assert determine completion; the Gateway records the public result from that run.


### Per-Node Swamp worker

The operator selects Linux Nodes through the `ORBIT_SWAMP_WORKER_NODES` JSON array, default `[]`, exposed as `swamp_workers.nodes`. A Node read reports this intent as `swamp_worker_selected`. Update the agent fleet before selecting the first Node: the worker configuration changed from a boolean to a table, and old agents must never receive that table. The standalone converge path does not enroll or configure a worker.

Retained enrollment configuration contains the admitted Node ID and configured `user`. Its worker installation uses the checksum-pinned Swamp binary, public Orbit CA and persistent `orbit-node-worker.service`. The binary download stays in a private directory beside its destination, outside memory-backed command scratch. Verification and atomic publication stream the file, so installation does not allocate memory in proportion to its size. It uses the existing account and passwordless sudo. Task VM hosts are supported; task VM Nodes retain their separate worker lifecycle. No worker account or sudo rule is created.

The rollout list records desired workers and permits protected credential staging for their initial enrollment. Standalone `node.converge`, `node.update` and `node.agent_rekey` use 209 and do not consult this selection. They require managed SSH identity on the target. HTTP input cannot supply worker credentials.

Before configuring a selected worker, the agent requires Linux `MemAvailable` to cover the pinned binary's 403,378,168 bytes plus 256 MiB of headroom. Missing or malformed memory information refuses with `swamp.node_worker_memory_low`. The check precedes agent file changes, downloads, credentials and worker publication, applies when the binary is already installed, and runs again before systemd starts the worker. A small Node must remain outside the worker selection; installation never changes swap or memory limits to make it fit.

Migrated family steps use one selector, `labels: {role: swamp}`, which only the swamp role's workers carry. Official pinned SSH reaches the admitted effect Node with the approved 209 identity at `/etc/orbit/swamp-role-worker/ssh-identity`. An unavailable control worker queues until Swamp’s native timeout. Installed per-Node workers remain under their separate runtime-retirement owner; source publication does not retire them.

Enrollment uses native `swamp worker token create orbit-node-<node_id> --duration 30d`, retaining default single-machine enrollment and `_token-secrets` custody. Nick chose 30 days to match the native access-token lifetime and prevent daily fleet outages. Token-authenticated serve also requires an ungranted `worker:orbit-node-<node_id>` access token with its native 30-day default. No API key is installed on workers.

The obsolete per-Node worker enrollment and scheduled rotation workflows are removed. Migrated families execute on209 and reach their effect Nodes through official pinned SSH. This source cleanup does not stop installed worker units or revoke credentials; runtime retirement remains separately owned. The existing209 runtime pools and approved SSH identity remain.

Standalone agent Operations declare no worker-credential private material or secret-output endpoint. Their native inputs contain only public configuration, pins and SSH descriptors.

Worker delivery and credential recovery remain separately owned. Standalone converge does not mint, stage or install worker credentials. Preserve installed worker custody until separately reviewed retirement.

The retained worker installer publishes root-owned `0600` credential sources under `/etc/orbit/swamp-worker`, with a `0700` parent. Missing or unsafe files fail with `swamp.node_worker_not_ready`. A fixed service runs as root before each start and checks the file custody. systemd loads the sources through credentials, runs the Node user with sudo, logs native worker output to the journal and limits failed starts to three in five minutes. The retained worker installer clears the unit's failed-start limit with `systemctl reset-failed` before queuing start or restart. `restart --no-block` and nonblocking opt-out ensure a Node worker never waits for its own drain.

Credentials and the private machine cache survive reboot. The canary, rather than the queued restart acknowledgement, proves connectivity and readiness.

Doctor reports `node.worker_failed` for a failed unit. The Node exporter exposes its failed state through `node_systemd_unit_state{name="orbit-node-worker.service",state="failed"}`. Inspect `journalctl -u orbit-node-worker.service` for native authentication and restart failures.

After review and fleet agent update, prove each selected Node before announcing it ready. VPN and usexit remain outside the current worker rollout. The canary verifies root-owned agent configuration against the expected Node ID and user, hostname, effective user and `sudo -n`. Record its native run ID and worker instance identity before announcing that Node live. Removing a Node from the rollout and converging disables the unit without waiting on its own worker; credentials and cache remain for recovery. macOS launchd is deferred until implemented and proven.

Unused Gateway-local bootstrap and standalone PHP-package writer scaffolds are removed. `node:add` admits its native enrollment Operation through the public API.

`node:add` inspects the Mac tunnel inside its own enrollment workflow on 209, using the admitted SSH host keys and an execution-copy bootstrap key. It checks no agent, account, package or runtime ownership on the Mac.

## Add a Node

A new Node needs a public SSH host and an approved SSH host key fingerprint, `--host-key-fingerprint`. Without the fingerprint, the Gateway refuses with `node.ssh_host_fingerprint_required`. An `app-dev` Node needs a TLD or an active Cluster TLD. A Node with Instances cannot be enrolled again, including for a TLD change.

Admission records a provisioning Node and freezes its public resource plan. Bootstrap identity and the new observer secret enter the execution vault; the workflow receives only their key names. One `operation-node-add` run executes on the Swamp workers on 209. It creates no child Operations and calls no agent command.

| Stage | Work |
| --- | --- |
| Trust | Native steps collect the public SSH key, assert its approved fingerprint, read the platform and bootstrap account, then bind that identity into the frozen resources. |
| Input | The intent, the private files and the inventory with pinned host keys are written to a run folder on 209. |
| Baseline | Ansible phase `baseline`: pin the Node, authorize the control key, install packages, create the managed account and its sudo rule, open recovery SSH, apply the [kernel](#kernel-policy) and [update](#update-policy) policies. |
| Firewall | The `baseline` phase then enables the firewall. |
| Network | Phase `tunnel` creates and checks the WireGuard key, writes the tunnel files and applies peers. A native step reads the public key. Phase `hub` adds the peer. |
| Observer | Ansible phase `agent`: fetch the pinned binary, write its public configuration and mode-0600 secret, and start its systemd unit. |
| Access | A native step logs in as the managed user and, on Linux, runs a command with passwordless sudo. |
| Roles | Run the frozen native role workflows in assignment order; publish the CLI as best effort. Exporters require explicit enablement. |
| DNS | Ansible phase `dns` writes the dnsmasq backend of a new VPN hub; native child workflows install its listener and publish private DNS. |
| End check | Ansible phase `check` reads the agent, its binary and secret, and the tunnel handshake. The native `Check the Node` assertion determines completion. |

Each role in the intent names its execution copies as a record: `{}` for a role without any.

Generated Ansible vars preserve literal intent values. Only strings containing Jinja syntax receive `!unsafe`; quoted shell arguments such as `!` and scalar strings such as `on` keep their string types.

The development mail phase sends failed-task messages to stderr so Operations retain the fixed error code and recognized tool diagnostic. File-content tasks remain `no_log`; arbitrary tool output is never exposed.

The execution copies of task runs are not role records. Their vault key patterns are declared in `swamp/config/gateway-workflow-grants.json`; the [implementation loop](/reference/implementation-loop) says how.

The `baseline` phase replaces the Node pin on 209, and it validates the sudo rule before the rule replaces the old one. The `tunnel` phase requires the WireGuard public key to be unique, and an existing hub to keep its peer-facing key, before it changes any tunnel file, interface or peer.

Role PHP packages, Composer prerequisites and native Caddy use the [recipe artifact mirror](/reference/compute-drivers#the-recipe-artifact-mirror) for each pinned Debian file. Role prerequisites use one Ansible artifact module; native Caddy and Tool Managers use the shared native fetch workflow. Both consume the admitted candidate list, try upstream only after a mirror transport failure, and verify the checksum before publication. They share the curl transport-code policy. A checksum mismatch stops the run. Local I/O and size-limit failures stop without fallback.


Native Node roots freeze certificate scopes and publication plans at admission. Their explicit resource steps publish the target and withdraw the source after target readiness. Node enrollment itself uses Ansible phases for file, package, account and service changes; a single command uses SSH argv. No script is copied to a Node, and the sudo rule is validated in place, so no candidate file is staged. A failure retains completed work; every phase is an end state, so a rerun skips what is done.

The Gateway records the observed SSH identity, architecture, WireGuard public key and observer-secret hash from that run. A successful first VPN hub also supplies the public key used by later peer admission. Private WireGuard keys stay in Node key files. The `hub` phase composes the existing key and the public peer plan into a protected configuration on that hub, applies it, and checks that the hub public key is unchanged afterward. Certificate signing stays at the existing Gateway signer boundary.

The enrollment workflow records the verified bootstrap host key for the WireGuard address before checking private SSH. This replaces a stale pin when an address is reused. Fingerprint mismatches refuse before either pin changes. The scanner derives the fingerprint from the returned public key bytes.

`node.bootstrap` creates a missing managed account, adds its SSH key, and validates passwordless sudo before publishing the rule. It preserves existing home and directory modes and refuses linked access paths.

A [task VM copies its base snapshot](/reference/compute-drivers#claims-copy-the-base-snapshot) for the `app-dev` software. Its [common converge](/reference/compute-drivers#converge-a-running-task-vm) prepares the SSH user's Incus client config before parallel fleet work. It keeps the VM-specific host key, WireGuard, firewall, Caddy, DNS, agent and secret, and Pi steps. Every other Node runs every program at enrollment.

The Gateway role requires `logrotate`, pinned in the recipe's `gateway` profile. The gateway role convergence installs the [host log-retention rules](/reference/gateway-recovery#disk-retention), the Gateway PHP-FPM pool and the agent-view and hibernator units, and retires the release unit's timer and the Documents PHP-FPM start hook. Releases leave them unchanged.

The Gateway’s local listener uses the image prerequisites and readiness checks described in [Gateway private DNS](/reference/private-dns#the-gateway-machine).

Existing operator masks remain in place. Convergence skips enabling `logrotate.timer` when `systemctl is-enabled` reports `masked` or `masked-runtime` for either `logrotate.service` or `logrotate.timer`, so an operator mask does not block releases. Otherwise it enables the configured timer afterward; it does not restart journald or force a rotation.

Task checkout operations require systemd 250 or newer, cgroup v2, and the managed user's passwordless sudo. Missing or unreadable supervision refuses operations.


The native Node workflow composes the [Orbit CLI publication](#orbit-cli) step on eligible Nodes. Gateway provisioning and role code perform no CLI installation.

The internal Gateway console command `orbit:node-provision` retains the synchronous bootstrap for the first Gateway and task-VM setup. On failure, `--diagnostics` includes a redacted command-output tail, or the underlying exception messages when no command output exists: at most 40 lines and 4,000 characters. The topology harness uses it to report the prerequisite error. Role firewall intent comes from the shared Node firewall rule catalog. The agent's `firewall.rules` command checks ownership, preserves recovery SSH before enabling UFW, applies desired rules, and removes exact old rules under one firewall lock. Admission calls it over the pinned bootstrap connection; later role work uses the WireGuard connection.

`wireguard.project-hub` applies peer changes without restarting an active interface. The agent rejects WireGuard shell hooks before writing files. The Gateway retains peer selection, configuration rendering, and the projection lock that protects that selection.

Router LAN reconciliation expands and prunes HTTPS and both DNS transport families together, using the same proposed member eligibility. A failure before membership or Cluster state commits leaves the previous state authoritative. If pruning fails after the commit, the committed state remains authoritative and the command reports cleanup failure; stale rules may remain. Restoring a preceding firewall snapshot does not roll back database state.

After resolving the cause, repeat `orbit cluster:node:add <current-cluster-id> <current-router-node-id>` for the active Router already in that Cluster. This accepts unchanged membership and reconciles Router LAN rules even when the Router hosts Instances or the Cluster is inactive; it does not remove workloads or change Cluster state. Then verify managed/live rules and role Doctor against current authoritative state.

`node:add` with unchanged configuration is an alternative only when its provisioning guards permit it: a Node with Instances is refused with `node.has_instances`. Do not remove workloads or change configuration to bypass that guard. The [Router LAN firewall contract](/cli/firewall#router-lan-member-rules) defines eligibility, cleanup recovery, snapshot-restore failures, and rollout rollback. These rules alone do not install Cluster DNS or change a member's resolver.

### Bootstrap identity

A new Linux machine needs only root SSH access with the Gateway bootstrap key. Enrollment creates the observer unit's environment group before starting it; a fresh machine does not already have that group. The initial host-key read ignores the worker's `known_hosts`; the next step must match the approved fingerprint before any machine change. Later SSH steps pin that approved key. The `baseline` phase appends the control key to the bootstrap account when it is absent.

The Gateway requires `ORBIT_SWAMP_SSH_PUBLIC_KEY`, the public half of `/etc/orbit/swamp-role-worker/ssh-identity` on the swamp Node. Enrollment authorizes that same captured key for the bootstrap and managed accounts. The swamp role copies the identity from `/etc/orbit/node-proxy/id_ed25519`; after an operator provisions or rotates that key, the operator records its public half in the Gateway environment and applies configuration before enrolling machines. If the public key is missing or invalid, admission fails with `swamp.ssh_identity_unavailable`.

The Gateway records the observer unit's canonical group as `intent.agent.environment_group`. The Gateway release that produces this key must be live before the workflow consumer is released. Swamp loads before the Gateway switches, so a consumer in the producer release would reject enrollment admitted during that window.

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

A Node with only this role appears in the web app's workload table with CPU, memory, and disk meters. It selects the normal role-default exporter and joins fleet rollout group 2. Explicit exporter preferences still apply. Its role selects the recipe's `ci-runner` tool set; a recorded recipe profile does not grant fleet participation. The container installer alone purges extra PHP packages.

Remove all containers and their GitHub registrations before removing the role. Role removal refuses a project containing any container, including a stopped one after deregistration and with `--force`; it never deregisters or deletes a runner. Normal removal closes the routed allowance and retains the project, bridge, ACL, and pool. Before `--purge-data`, remove cached images, custom volumes, and extra profiles from `orbit-ci`. The option also removes the empty project, bridge, and ACL; Incus refuses resources still in use. The shared pool stays. Offline removal retains host state and reports it for operator cleanup.

After merge and deployment, an operator runs these commands; this feature does not run them. Adding the first role to a roleless Node closes the normal `orbit:public-ssh-recovery` firewall rule. Confirm WireGuard access before adding it:

```bash
orbit node:role:add Sabre ci-runner
orbit node:role:add Shark ci-runner
orbit node:role:add Beast ci-runner
```

Verify each Node with `orbit doctor --node=<numeric-node-id>`. For a failed role add, repair the reported cause and retry with `orbit node:role:add <node> ci-runner --converge`. Existing runners and registrations stay in place.

## macOS Nodes

A macOS Node supports selected Homebrew formulae, casks, and Vite+ global packages. It needs no role. It uses an existing account, a working WireGuard connection, and SSH access authorized for the Gateway. Enrollment pins the approved SSH host identity and verifies the actual platform, architecture, account, and tunnel address before it succeeds. The Gateway resolves the host name. An SSH alias on the caller's machine is not a fleet address.

Use `node:add` with `--platform=macos`, the approved host fingerprint, and `--user` and `--orbit-user` set to the same existing account. The Gateway does not default those fields to `root` or `orbit`. A missing field returns `node.macos_account_required`. Different names return `node.macos_account_mismatch`. A name that fails the existing portable user check returns `node.invalid_linux_user`. All three are HTTP 422 and change nothing.

The request must name a WireGuard address that is already on the machine, or the Node row must already have one. The Gateway does not allocate a new address, install a tunnel, or rewrite the host tunnel, DNS, or firewall. A missing address returns `node.wireguard_required`. A requested address that differs from the address stored on the Node returns `node.wireguard_required` and changes nothing. A role, DNS server override, storage settings change, Cluster change, TLD change, or LAN change returns `node.platform_unsupported` and changes nothing.

An eligible existing peer can be enrolled in place. It already has a row, no roles, a WireGuard address, and no pinned SSH fingerprint. After the platform, architecture, account, and tunnel checks pass, the Gateway stores the observed platform and `uname -m` architecture and the named account. It keeps the Node ID, name, access grants, WireGuard keys, address, and endpoint. Apple silicon stays `arm64`. The Gateway does not rewrite `arm64` to `aarch64`.

A requested platform or architecture that disagrees with the machine fails. `node.platform_mismatch` and `node.architecture_mismatch` are HTTP 409 at `machine-architecture`. An already managed Node has a pinned SSH fingerprint. Enrollment does not change its platform, architecture, or user. A disagreement fails and leaves the record as it was. A different account on that Node returns `node.user_change_unsupported` (HTTP 409).

The macOS path creates no account and applies no Ubuntu bootstrap, apt packages, UFW rules, systemd units, or managed DNS. It keeps the existing account, tunnel, Homebrew prefix, and Vite+ global scope. Discovery and adoption report a missing or conflicting manager without installing, replacing, or repinning it. Tool mutations require an active Node with verified WireGuard and pinned SSH identity.

### Enrollment steps

Admission records a new Mac as provisioning and leaves its observed identity unset. The native run checks the approved host key, reads `uname -sm` over pinned SSH, and binds the observed identity. It then verifies managed SSH at the recorded WireGuard address and checks that `ifconfig` shows that address. `Check the Node` is the native terminal assertion. No binary, account, package, tunnel, DNS or firewall change runs on this branch.

### Enrollment recovery

A failed Mac run changes no host resources. A newly admitted Node remains marked failed; an existing peer keeps its earlier identity and status. After fixing the failed check, repeat `node:add` with the same approved fingerprint and account. A successful terminal projection records the observed identity and active status.

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

Doctor checks platform, architecture and free space on the root filesystem. It does not expect systemd, a Node agent, an exporter, or a Linux disk path. A missing agent is not `node.agent_missing`. Realtime and the web page treat the Mac as a Node with no agent, not as a failed Linux service.

### Removal

Removing a macOS Node revokes its managed SSH access and removes the hub peer and Gateway records. It does not delete the user, uninstall Homebrew or Vite+, or edit the host tunnel. Linux service and firewall steps are skipped. `retained_on_node` reports the account, installed packages, user data and host-side WireGuard configuration that remain. A failed native step retains the Node record with failed status; the workflow does not claim a rollback or restore a withdrawn peer. macOS OS updates, firewall management, application hosting, and a macOS agent are separate features.

JSON and the human `node:remove` output both report that list.

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

When the package sources do not resolve, the bootstrap fails. Unlike a Node with roles, it does not clear Orbit DNS on the `orbit` link first. See [Add the machine again](#add-a-node).

The `DNS =` line is in `/etc/wireguard/orbit.conf`, and `wg-quick` applies it when the tunnel starts. The Gateway deletes an existing `/etc/wireguard/orbit.dns-link`.

A later `node:add` without `--role` keeps this setup. To call the Gateway API from the Node, grant it access with [`node:access:add`](/cli/node/access-add). The Gateway identifies the caller by its WireGuard address.

On Ubuntu, `node:role:add` accepts the same roles as on any other Linux Node. Adding the first role closes public SSH and moves the Node to managed DNS in the same operation. macOS refuses every role with `node.platform_unsupported` before SSH.

Doctor checks a Node without roles like any other Node when the Gateway has a pinned SSH host key for it. The `role` family reports nothing. A Node without a pinned SSH host key dispatches no Doctor reads and supplies no positive recovery evidence.

## iPadOS peers

An iPad or iPhone can join WireGuard as an unmanaged peer. The operator owns its tunnel. It has no pinned SSH host key and no role. Orbit runs nothing on it. The `ipados` platform records this, so the Node is not mistaken for a Linux machine.

Set the platform with [`node:update --platform`](/cli/node/update). It takes `linux`, `macos`, or `ipados`, and accepts only a Node with no pinned SSH host key and no role. It changes the stored platform and nothing else. `node:add` does not accept `ipados`. Keep the architecture the machine reports, such as `arm64`.

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
| Tool operations | `tool.node_unmanaged` (HTTP 409). |
| Fleet rollout | Left out with reason `platform`. |
| Doctor | No SSH reads or positive recovery evidence without a pinned SSH host key. |

`node:wireguard-key` still sets the peer's public key, and `node:access:add` still grants it access to call the Gateway. Removing an `ipados` Node deletes the registry row and the hub peer. It skips the Node agent step and `firewall-recovery`. `retained_on_node` lists `host-wireguard`, the tunnel the operator configured on the device.

## Enroll through a jump host

The Gateway can enroll a Node that it reaches only through another Node. [Task VMs](/reference/compute-drivers#task-vms) use this: a task VM has only a private address on its Incus host's bridge. The jump is internal. `node:add` and the API have no field for it.

The Node records the jump Node in `ssh_jump_node_id`. While the Node has no active role, SSH to its public address goes through an `ssh` hop to the jump Node, as `<managed user>@<WireGuard address>:22` of the jump Node. The hop uses the same key, pinned host keys, and strict options as the command. See [An explicit hop, not ProxyJump](#an-explicit-hop-not-proxyjump).

The read-only `node.scan-host-key` agent command scans and fingerprints the target on the jump Node, or on the serving Gateway when no jump is needed. It prefers Ed25519, then ECDSA P-256, then RSA. The target needs no installed agent. The Gateway compares the result with the expected fingerprint before pinning it or installing anything on the target. A task VM's expected fingerprint comes from its host, before any code in the VM runs.

When the Node's host key is pinned, commands through its jump Node share one connection. Nodes behind different jump Nodes can have the same private address, and a new Node can reuse an address. So that connection is named after the jump Node, the target, and the target's pinned host key fingerprint. Before the key is pinned, each command opens its own connection. A jump Node without a WireGuard address fails with `vpn.peer_address_missing` before SSH.

The enrollment steps are the same as for any Node. When the first active role closes public SSH, the Gateway reaches the Node over WireGuard and ignores the jump. A Node without a jump Node connects directly, as [SSH connections](#ssh-connections) describes.

## Converge an existing Node

`node:add` for a recorded Node applies the same native resource graph. It refuses every Node with Instances with `node.has_instances`. Existing role assignments use native role convergence; new assignments use native role addition. The observer and CLI remain owned by the parent enrollment run.

A failure keeps an existing Node's earlier recorded identity, settings and status. It retains completed Node changes and completed role convergence. Newly created assignments that did not finish remain failed for a rerun. There is no restoration chain. A new Node becomes active only after `Check the Node` passes.

### Instance containers on an app-dev Node

An `app-dev` Node on the Gateway's build list of [development containers](/reference/development-containers#the-build-list) also gets the container setup with each role converge: Incus, the project `orbit-instances` on the Node's pool, the bridge `orbitinst0`, the [egress proxy](/reference/development-containers#egress-and-dns), the container image and the two shared folders. A task VM never does, and every other `app-dev` Node installs what it did before. The Node must provide the Incus storage pool `orbit-instances`. [Prepare an Instance container host](/reference/compute-drivers#prepare-an-instance-container-host) has the steps.

## PHP packages for production children

`node.prepare_php_packages` prepares PHP packages on one managed Linux Node that is active or marked failed. Its input is `{versions: [version], profile: "app-prod"}`. The Swamp deploy and rollback composition starts it as a child before activation on its serving Node. Standalone admission is refused. The current retained production, rollback and Route callers keep their synchronous package installer until their own cutover.

The Gateway captures the recipe versions, downloads and digests at admission. Swamp runs only `php.install-build` with that captured input. Dispatch refuses a changed recipe revision or an admission older than 120 seconds. It keeps existing modules, uses the recipe PCOV and OpenTelemetry policy, and sets `fpm: none`, so the dedicated runtime owns its restart.

Completion requires a successful keyed installer step with `outcome: current|applied`. Source refusals keep `app-prod.php_package_source_unavailable`; other installer refusals keep `app-prod.php_install_failed`. Results expose the outcome, fixed error code and bounded `stale_masters` and `deferred` lists. The Gateway logs those FPM master warnings. Missing result data or an outcome other than `current` or `applied` fails with `app-prod.php_install_failed`. A lost reply uses the same Operation and target receipt. The child requires Kit C list enforcement; admission is unavailable until it lands.

On build-list Nodes, the `app-dev` converge also prepares the [Instance container host](/reference/compute-drivers#prepare-an-instance-container-host), including the image, bridge ingress, DNS shutdown and egress proxy rule; task VM tasks and place counts stay unchanged.

## Orbit CLI

Only Nodes with a recorded `ci-runner` assignment that is not being removed carry the packaged Orbit CLI. This assignment is the Gateway’s single rule for CLI intent, including a base-only converge. Host CI proof runners call `bin/ci-proof-run`, which invokes `orbit proof:run`. Workload Nodes without this assignment, Swamp and the Gateway need no packaged CLI. Instance containers do not install it. Task VMs keep the checkout’s source CLI for proof and profile setup; operator machines manage their own CLI.

`fleet.cli_converge` records one intent for the selected Nodes and starts `operation-fleet-cli-converge`. The Gateway captures the release assets, the public profile and each installing Node's SSH descriptor. For each Node in turn, the `fleet-release-node` child checks on Node 209 that the Node may publish, runs the native `node-cli-install` child from Node 209 over SSH, and records the outcome on Node 209. `fleet.rollout` also publishes the observer binary from Node 209. Nodes without CLI intent roll out their observer without waiting for a CLI release or CLI installation. It never invokes `cli.install` or `cli.self-update` on orbit-agent. [Fleet rollout](/reference/gateway-recovery#fleet-rollout) owns automatic and operator admission.

`POST /api/v1/fleet/cli/converge` selects the fleet, or the optional `node_ids` list. A selected ineligible Node is rejected. An unpublished release is skipped. Enrollment runs the same `node-cli-install` child only for CLI-eligible Nodes.

| Item | Path or value |
| --- | --- |
| Binary | `/usr/local/bin/orbit-<version>`, `root:root` mode `0755` |
| Link | `/usr/local/bin/orbit`, a link to the binary. `orbit self-update` switches it with one rename and keeps the previous release |
| Profile | `/root/.orbit/config.json`, `root:root` mode `0600`: the active profile `gateway` with the URL `https://<Gateway WireGuard address>` and the root certificate `/etc/orbit/agent/ca.pem`, which the agent converge writes |
| Download | The desired CLI release's binary for `linux-<arch>`, from `https://github.com/hardimpactdev/orbit/releases/download/cli-v<version>/orbit-<version>-linux-<arch>` |

This is the layout `orbit self-update` keeps, so either one can update what the other installed. The profile holds no secret. The Gateway identifies the Node by its WireGuard address, as for every CLI call, and its certificate covers that address. `sudo orbit self-update` reads root's profile.

`node-cli-install` runs `/usr/local/bin/orbit --version` and `orbit self-update --help` as the managed user, never as root. It accepts `Orbit 0.N.0` or, for a pre-release build, `Orbit <40-character commit>`. Any other answer is not a valid Orbit version.

| Found | Result |
| --- | --- |
| Nothing, or a version that is not a valid Orbit version | Install the admitted release: `cli_installed` |
| An Orbit CLI that has `self-update` and is the desired release, a newer one, or a pre-release build | Leave it: `cli_present`. `orbit self-update` replaces it |
| An older Orbit CLI release, or one without `self-update` | Install the admitted release |

To install, `@swamp/cfgmgmt/fetch` downloads the binary to `/usr/local/bin/orbit-<version>` with its SHA-256 from the desired state. The child runs that file with `--version` and requires the release version. Only then does `@swamp/cfgmgmt/link` switch `/usr/local/bin/orbit` to it. A failed download, checksum or version check leaves the link as it was. Then `@swamp/cfgmgmt/file` writes root's profile, `root:root` mode `0600`.

| Code | Meaning |
| --- | --- |
| `cli.release_unavailable` | The CLI release is not published yet, so a missing CLI cannot be installed |
| `cli.architecture_unsupported` | The release has no binary for the Node's architecture |
| `cli.install_failed` | The download, its checksum, its version check, the link or the profile failed. The link stays as it was when the switch did not happen |
| `cli.configuration_failed` | The profile could not be written, or the Gateway has no WireGuard address |

A Node of the build list keeps a mirror of each container Instance's repository at `/var/lib/orbit/instance-repositories/<id>.git`, owned by root. The create and deploy flows fetch into it. See [the default Instance](/reference/development-containers#the-default-instance).

[Instance removal](/reference/development-containers#destroy) deletes its container, mirror and owned rules. Its `instance-<id>` network ACL lives in Incus project `default` and is removed after the container.

A clone's create also adds UFW rules under the comment prefix `orbit:instance-<id>-`: the route rules of its published T3 port and the rule to its database server. See [the network](/reference/development-containers#network).

## Converge the Orbit footprint

`orbit node:converge <node> [--base | --roles=a,b] [--idempotency-key=KEY] [--json]` repairs one active, managed Linux Node against the release. It never runs `node:add`, changes an Instance, or adds or removes a role assignment, so a Node that owns Instances can converge. Fleet release rollout remains a separate CLI and observer publication path.

With no scope flag, the attempt includes Orbit's base and every recorded role. `--base` selects only the agent, the Node’s CLI state and Orbit-owned files and units. `--roles=a,b` selects the base plus the named existing assignments, and refuses an empty, unknown or unassigned role. The two flags are mutually exclusive. There is no `--force` or `--full` option.

The Gateway validates recorded state without a live preflight, reserves the target and its first recorded VPN hub until settlement, and captures an immutable intent. Unreachable machines fail during execution, not admission. The CLI validates inputs, calls the SDK and renders the Operation; the Gateway admits, records and projects; Swamp executes.

Node convergence, role addition or convergence, enrollment and agent rekey share the Node's Operation admission lock. A holder causes `operation.target_busy` for another admission on that Node; distinct Nodes remain independent unless their converges share a VPN hub. Enrollment holds the lock for its whole run, including its frozen role work, without admitting a second role Operation. Existing terminal and timeout settlement rules release the hold. Another converge that owns the hub reservation is also refused.

The native Swamp workflow runs these phases in order. Input preparation writes private inventory, variables and pinned SSH known hosts. Each machine phase invokes one tagged Ansible playbook, within the admitted scope.

| Phase | Work |
| --- | --- |
| `input` | Prepare this attempt's recorded roles, Tools, network and release pins, plus vault key references. Require a CLI pin only when the Gateway includes a CLI plan. |
| `agent` | Reconcile the pinned agent, the Node’s CLI state, Orbit-owned files and units, the [kernel policy](#kernel-policy) and the [update policy](#update-policy). Restart only for managed changes. |
| `software` | Read the selected pins from `recipe.json`, install them and hold the selected packages at those pins. On the Swamp Node, install the recipe's pinned Deno, which runs the Tool helper. |
| `roles` | Repair the selected roles' configuration without changing an Instance or assignment. |
| `tools` | Run the Tool helper once to prove that it works. Then apply recorded Tools within scope, recording each Tool's own result and continuing after an individual Tool failure. |
| `network` | Apply the recorded hub before the Node and wait for the handshake. A target hub is applied once; without peers it needs no handshake. |
| `check` | Read back selected software, units, role configuration and recorded tunnel state. |

No recorded network in scope means no network mutation. DNS routing domains stay literal, including a domain such as `~orbit` that also names a local account. Every new attempt starts at input and runs all seven phases again, even when recorded intent matches. Matching tasks report zero target changes and the attempt must complete within 120 seconds; hub changes are excluded from that target count. A repeat is not a digest-based admission skip. Reusing an idempotency key follows the [keyed-start contract](/reference/operations#retry-a-start-with-a-key), rather than starting a second attempt.

A failed phase stops later phases and fails the Operation with its failed step and error code. Completed and partial machine changes remain; there is no rollback. Fix the cause and start a new attempt from input. An individual Tool failure is different: it marks only that Tool failed, lets other Tools continue, and does not alone fail an otherwise successful parent. Temporary private run files are removed after work stops on success or failure. Incomplete cleanup is exposed without replacing the original failure, and interruption proves no cleanup.

The Tool helper is the TypeScript policy that judges each Tool. It runs on the Swamp Node, with the Deno at `/usr/local/bin/deno`; on its first run for a worker, Deno downloads the helper's two pinned npm imports. A helper that cannot run is not a Tool failure: the `tools` phase fails before it judges a Tool, and no Tool is marked failed. Converge the Swamp Node to install its Deno, then converge the Node again.

Without a CLI plan, the base phase removes `/usr/local/bin/orbit` when it is a managed version link, regular `/usr/local/bin/orbit-0.N.0` binaries and the managed root profile `/root/.orbit/config.json`. It preserves unrelated executables, backups and directories. The final check requires these managed files to be absent. A failed phase retains completed changes; a new converge repeats the same desired state.

Agent and CLI replacements and CLI removal share the existing Node-local update lock with self-update. Replacements hold it only for the final atomic rename; removal holds it across its read and deletion. [One update at a time](/reference/self-update#one-update-at-a-time) owns the staging, timeout and failure rules. Converge checks the existing agent secret against the admitted verifier without rotating it or changing the Gateway's hash.

Metrics exporters, cAdvisor and the FPM exporter stay outside this footprint; Metrics fleet reconciliation owns them. Convergence does not publish Instances, Routes or Process units.

Earlier Gateways published the annotator server in `/opt/orbit/annotator` and kept its stores in `/var/lib/orbit/annotator`. Orbit leaves these paths unmanaged; the machine recipe does not remove them. After destroying the removed preset Processes through the old Gateway, an operator may remove unused files by hand. Cleanup of both paths remains pending a dependency check for ordinary Processes 168 and 127. Preserve these Processes and their files. Check every ordinary Process dependency and obtain cleanup authorization before deleting retained files.

### Kernel policy

Every managed Linux Node gets the same kernel policy. The Gateway renders it once, and two paths apply it: the `baseline` phase of [`node:add`](/cli/node/add) and the `agent` phase of `node:converge`. So a new Node and an existing one end in the same state.

| File | Content |
| --- | --- |
| `/etc/modprobe.d/orbit-no-sctp.conf` | `install sctp /bin/false` |
| `/etc/sysctl.d/60-orbit-nonlocal-bind.conf` | `net.ipv4.ip_nonlocal_bind=1` |

Nothing in Orbit uses SCTP, so the kernel never loads its module (decision of 2026-11-09). A module that is already loaded stays loaded until the next boot.

Docker publishes [Database server](/reference/database-servers#private-publication) ports on the explicit WireGuard and LAN addresses. At boot, Docker can start a container before that address exists. Without the non-local bind, the bind fails with `cannot assign requested address`, and Docker does not retry.

The non-local bind has a cost: a service bound to an address the Node does not hold, such as an old LAN address, starts and serves nothing instead of failing at start. Check a Process's publications after you change a Node's addresses.

Both phases write the module file as root, mode `0644`. A file that already holds the same content, such as one an operator wrote by hand, stays unchanged. The phases also set `net.ipv4.ip_nonlocal_bind` on the running kernel, and `systemd-sysctl` sets it at every boot before Docker starts.

### Update policy

Every managed Linux Node gets the same update policy ([ADR 0253](/decisions/0253-patch-nodes-and-reboot-them-drained)). The Gateway renders it once, in `NodeUpdatePolicy`, and the same two phases as the kernel policy apply it. The Node installs Ubuntu security fixes itself; the nightly [fleet reboot](/reference/gateway-recovery#fleet-reboot) reboots it when a fix needs that, unless the operator has set its reboot hold. The hold preserves the due observation and overdue alerts.

| File | Content |
| --- | --- |
| `/etc/apt/apt.conf.d/20auto-upgrades` | Update the package lists and run `unattended-upgrades` every day |
| `/etc/apt/apt.conf.d/52orbit-unattended-upgrades` | Only the `${distro_id}:${distro_codename}-security` origin; `Automatic-Reboot "false"`; remove unused kernels |
| `/etc/needrestart/conf.d/50-orbit.conf` | Restart services automatically, except the protected units |
| `/etc/apt/apt.conf.d/53orbit-reboot-due` | Run the hook after each `dpkg` run |
| `/usr/local/lib/orbit/reboot-due` | The hook: a protected unit on an old library writes `/var/run/reboot-required` and adds the unit to `/var/run/reboot-required.pkgs` |

The phases install `unattended-upgrades` and `needrestart` and start `apt-daily.timer` and `apt-daily-upgrade.timer`. Held recipe packages stay held, because `unattended-upgrades` skips held packages.

The protected units are `orbit-swamp-*`, `orbit-gateway-*`, every Orbit Process unit (`orbit-process-*`), `orbit-agent`, `docker`, `containerd`, `incus*` and `wg-quick@*`. Their restart drains for a long time or cuts the fleet's work, so they wait for the drained reboot. `needrestart` restarts every other service on the same day as the fix. One list in `NodeUpdatePolicy` feeds both the `needrestart` configuration and the hook.

A Node of a task VM's nested fleet gets the same files with the daily timers stopped and disabled: a proof must not change under an unattended upgrade.

### Shutdown never waits

Some Orbit units drain for a long time on stop: Horizon on the Gateway (its longest job plus 30 seconds), the Swamp workers (75 minutes) and Swamp serve (6 minutes). These drains are right for a rolling restart, such as a release or a worker renewal. A system shutdown must not wait for them: WireGuard and SSH stop during a shutdown, so the work they wait for cannot finish.

Each of these units carries the same `ExecStop` guard, from `ShutdownFastStop`. systemd runs `ExecStop` before it sends the kill signal. While `systemctl is-system-running` reports `stopping`, the guard kills the whole unit at once; otherwise it exits and the unit's own drain applies. Each unit keeps its own `KillMode`, `SendSIGKILL` and `TimeoutStopSec`.

Converging a unit file changes no running unit; systemd uses the new `ExecStop` from the next stop. The planned reboot drains before it reboots ([fleet reboot](/reference/gateway-recovery#fleet-reboot)), so the guard cuts only work that outlasted the drain budget.

### Break-glass recovery for Services and Swamp 209

Standalone convergence runs on a `swamp`-role worker and reaches the admitted target and hub through Ansible over pinned SSH. It does not require the target's persistent Node worker to execute its own repair. If Swamp's serve or control workers are down, restore their existing operator-managed units and protected configuration through existing managed SSH access or the provider console before requesting execution. Do not replace them with a Gateway PHP executor.

Preserve the installed credentials, their ownership and mode, the recorded agent secret hash and the configured Node identity. Do not print or copy credential files into commands, logs or an Operation. Missing or damaged protected files require the approved recovery procedure and retained backup with the enrollment or credential owner; convergence does not rotate an agent secret or remint worker credentials.

Inspect the original Operation and native run before admitting another attempt. A queued or uncertain run keeps its reservations until settlement; a second admission does not repair its intent. Reconcile an uncertain machine effect before recovery. After a phase failure, a new attempt starts from input, not from the failed phase. The [command page](/cli/node/converge#errors) lists admission and execution errors.

## Task VM role phases

A task VM is a Node with the `app-dev` role, and its software has its own Ansible playbook, `swamp/ansible/task-vm-role.yml`, in the phases of this page: `software`, `tools` and `check`. The base snapshot refresh of an Incus host and every [`task:start`](/cli/task/start) run it as the common converge ([compute drivers](/reference/compute-drivers#converge-a-running-task-vm)), so a base and a started VM end in the same state. The refresh runs it on the base VM that Node 209 reaches through the host, and installs no Orbit agent: the observer comes when a VM enrols. A fourth phase, `clean`, follows an image build only.

The wait for a nested fleet is not a phase of the role: it is the task file `swamp/ansible/tasks/task-vm-nested-wait.yml`, which stops when the nested Gateway guest is not running. When the Operation it waits for does not succeed, it names that Operation's failed step and error code. The playbook `task-vm-fleet-wait.yml` runs it from Node 209 for the readiness of a fleet, and the `fleet` phase imports it for the waits of its [fleet build](/reference/compute-drivers#build-the-nested-fleet-in-the-converge).

The nested guests are Incus system containers. The role serves them nothing: each gets the checkout of its task as an Incus disk device, and a restore of `base` is a plain restore and a reboot (see [compute drivers](/reference/compute-drivers)).

Before starting a base VM, the host playbook converges its [firmware boot setting](/reference/compute-drivers#incus-provider). Task copies inherit it from the base snapshot, so the OVMF menu adds no delay.

A host without a recorded base snapshot first discards any unpromoted base VM and seed and builds from stock. This also removes the cached cloud-init state of a failed first boot, whose one-shot package installation does not run again on a restart. Recorded bases are preserved unless the refresh requests `--from-stock`.

On a base snapshot refresh or a task start the role first waits until SSH answers and cloud-init has finished. Both callers share the [SSH readiness budget](/reference/compute-drivers#refresh-the-base-snapshot); a task start reaches the VM over WireGuard. The `clean` phase leaves the temporary files of Ansible itself alone. The `template` phase checks the firewall of the template that a template build made. A test of the role fails any condition that YAML does not parse as a string, because Ansible refuses it at run time.

The role reads the package indexes of the Ubuntu snapshot with the `apt` module's own retry (`update_cache_retries`: twelve attempts, backing off to 30 seconds, about four minutes). It installs packages with the task keywords `retries`, `until` and `delay`: four retries, 30 seconds apart. The retries also apply to a permanent failure such as a missing pin, which then fails after two more minutes. The snapshot service answers 503 now and then, for an index and for a package. Rule 6 of the Swamp shape allows this tool-native retry and no shell loop.

The playbook reads the pins of `recipe.json` from the intent and names none. The `software` phase adds `python3-venv` and `jq` from the same Ubuntu snapshot: the shape gate linters need the first, and its Swamp load test needs the second. The Pi server is an internal artifact: the run copies it from the Gateway host to Node 209 as `pi_path`, and the `software` phase installs it on the VM when the VM's digest differs from the pin. [Converge a running task VM](/reference/compute-drivers#converge-a-running-task-vm) describes the phases.

The `tools` phase also initialises Incus once (`tasks/task-vm-incus.yml`): a directory storage pool and the default profile's root disk, so a VM made from the image can launch nested containers. A task VM that names a nested subnet also gets the nested bridge, with the first address of the subnet and NAT on. It reads the pools and networks first and applies a preseed only for what is missing, so a repeat changes nothing.

### The topology seed pool

A base VM makes the pool `topology-seed` (btrfs) once, on its blank seed disk. Its Incus database keeps the pool, and a task VM copied from the base has it from the database it copied. The phase refuses a seed disk with a file system that the Incus of the VM does not know: it imports no seed. The `fleet` phase builds the nested fleet on that pool ([compute drivers](/reference/compute-drivers#build-the-nested-fleet-in-the-converge)).

A task VM with a nested subnet also opens its firewall to the nested bridge (`tasks/task-vm-nested-firewall.yml`): DHCP and DNS in, and routing out, so the nested containers get an address and reach the internet. Nothing else is opened.

## Gateway bootstrap prerequisites

A fresh Gateway cannot plan any role before its own app runs, and the app needs PHP, Composer and a database. So the operator prepares the machine once with the local `gateway-bootstrap` workflow (`swamp/workflows/workflow-gateway-bootstrap.yaml`), whose steps request no worker. `php artisan orbit:bootstrap-plan` on the operator's release checkout prints the prerequisites as JSON, from the machine software recipe alone: the apt packages of the roles a new Gateway holds (Gateway, VPN, WebSocket, Swamp), the pinned Caddy package and the pinned PHP packages.

The workflow writes that plan to the machine and runs `swamp/ansible/gateway-bootstrap.yml` there with Ansible's local connection. The playbook imports `tasks/role-prerequisites.yml`, the same tasks the role add runs, so a Gateway and a Node get their prerequisites the same way.

### The Gateway app

The `app` job sets up the Gateway app on the machine: it writes the `.env` (`orbit:bootstrap-plan --environment`, production values from `.env.example`, with `DB_DATABASE` set to `ORBIT_HOME/gateway.sqlite`), installs the Composer dependencies, creates the database, makes the application key there and runs the migrations. The key never leaves the machine. The end check fails on a pending migration.

Last, `orbit:bootstrap` runs on the machine from the Gateway's own code. It records the Gateway Node with its Gateway and VPN roles and sets the VPN settings. It also makes the root CA and the Gateway SSH key, and records the host key of the machine. It also records the `swamp` role on that Node, as provisioning. The Gateway finds the Swamp Node by that role, never by an id. The role and route workflows read the control Node from each intent (`control_node_id`) and accept any Node id. The VPN role stays provisioning until Swamp applies the hub.

With `--operator-ip`, `orbit:bootstrap` also records the Node `operator`: no roles, that WireGuard address, and access to the Gateway Node, so it holds Gateway authority. Its key comes later, from the machine that operates the Gateway. The [fleet phase of the converge](/reference/compute-drivers#build-the-nested-fleet-in-the-converge) passes 10.45.0.10, the address stage 2 joins with.

### The Swamp control plane

The `control` job gives the Gateway its own Swamp, with the Ansible `swamp` phase of `gateway-bootstrap.yml` and a local connection. `orbit:bootstrap-control-plan` prints the plan: the serve and worker units for the machine's `swamp/` checkout, the release grants, the units of the scheduler and Horizon, and the tmpfiles rule that creates `/run/orbit` for the [Documents cleanup state](/reference/project-documents#local-state-and-startup-ordering). The phase writes them, applies the rule, and starts the scheduler and Horizon after serve and the workers. So the Gateway records its first run, the converge of its own Node, before its role.

The phase also mints the serve driver token, the two worker tokens and the Gateway's run and read tokens with the Swamp CLI, and places each in a file of its owner or in the Gateway `.env`. The play `remint` of the same playbook renews them later, with `swamp access token rotate` and a new worker enrollment token, when the fleet phase of the converge of a task VM renews them.

The `remint` play prints no token either, so a failed renewal shows its command and its error. It rotates the serve driver token in a task of its own, with one short command, so the recorded error shows the CLI message.

No token is in a run input or output. The phase also issues the `swamp.orbit` certificate from the Gateway's CA, puts a Caddy front on port 443, and starts serve and both workers. The Swamp key is already on the machine: the operator pushes it.

The phase creates the control vault `orbit-control-inputs` before serve starts, and the hub step also writes the hub public key to `wireguard/public.key` in the Orbit home, which every Node peer configuration names.

The phase issues the Gateway's own certificate from the same CA and installs it with the root certificate in `/etc/caddy/orbit-cert-current`, the paths the Gateway Caddyfile names. `orbit:bootstrap` records the `swamp.orbit` Route, so the first Caddyfile the Gateway publishes keeps that front. It also records the Redis Database connection `gateway-queue` for the Valkey server on the machine.

The same phase brings up the WireGuard hub of the Gateway's own network, because Swamp reaches every Node, this one too, by its WireGuard address. It makes the hub key on the machine, writes the hub file the Gateway renders, starts `wg-quick@orbit` and records only the public key.

The `app` job also writes the agent delivery manifest from the `agent_delivery` run input: the public pins the admitting Gateway sends, because a new Gateway has no release to carry them.

`orbit:role-add NODE ROLE [--converge]` admits a role from the Gateway itself, the way `node:role:add` does through the API. A new Gateway has no web site until its own `gateway` role is applied, so that role is admitted this way. The role brings the PHP-FPM pool and the Caddy site, and keeps the units of the scheduler and Horizon that the bootstrap started. It adds the `caddy` user to the group of the Gateway account, because Ubuntu keeps a home at 0750 and Caddy must read the checkout in it.

The control plane phase also makes the Gateway's own agent key and records only its SHA-256 verifier, so the first `orbit:node-converge` of the Gateway Node has a verifier to render. It writes the dnsmasq backend file that private DNS needs and restarts dnsmasq on it, and it starts the Gateway with empty private DNS records and an empty catalog, so the first publication of a private name finds both. It puts the Orbit root certificate in the system trust of the machine.

The phase starts the firewall with the recovery SSH rule, the trust of the network members and an operator rule for the hub port; the first role converge of the Gateway retires the recovery rule.

The hub step marks the `vpn` role active. After serve and both workers run, the phase marks the `swamp` role active.

`orbit:node-add NODE HOST [--role=ROLE] [--wireguard-ip=IP] [--host-key-fingerprint=SHA256:...]` admits `node.add` from the Gateway itself, as `node:add` does through the API; Swamp then enrolls the Node.

`orbit:node-converge NODE` admits `node.converge` from the Gateway itself. The Gateway converges its own Node this way, which installs the agent and removes the packaged CLI from its machine.

The Swamp role's own steps are workflow steps bound to worker labels, so a Gateway without a worker cannot run them; once its own Swamp serves, `node:role:add` converges the roles through it.

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

`router`, `metrics`, `websocket`, and `swamp` share a Node with any role. `node:role:add` answers `validation.failed` with `Role [ROLE] conflicts with assigned role [OTHER].` `node:role:relocate` answers `node.role_conflict` (HTTP 422) with the same message. `node:add` answers `node.role_conflict` (HTTP 422), with `Role [ROLE] conflicts with requested role [OTHER].` when one request names both roles.

Not every role can be added the same way.

| Role | `node:add --role` | `node:role:add` |
| --- | --- | --- |
| `app-dev`, `app-prod`, `database`, `metrics`, `websocket` | Yes | Yes |
| `gateway` | Yes, as a singleton | Yes, as a singleton |
| `vpn` | Yes, as a singleton | No |
| `ingress`, `analytics`, `ci-runner` | No | Yes |
| `swamp` | No | Yes, as a singleton |
| `router` | No | No. Use [`cluster:router:set`](/cli/cluster/router-set). |

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

New assignments from `node:role:add` run through one Swamp workflow. A native SSH step checks the Ubuntu release. Ansible phases apply prerequisites, role state, publications, firewall rules and Metrics changes, then check the result. Tool Manager commands and runtime copies run only when the admitted pin or lock differs from the last successful setup. The pin is recorded after its checks pass, so a failed setup retries.

A Node inside the nested subnet of a [task VM](/reference/compute-drivers) (`10.232.2.0/24`) reads its apt packages through the `apt-cacher-ng` of that VM, at `http://10.232.2.1:3142`. The Gateway puts that address in the `apt_proxy` field of the intent of the Node enrollment (`node.add`), of each role add and converge, and of each metrics exporter.

A play with an `apt_proxy` writes `/etc/apt/apt.conf.d/90orbit-package-cache` before its first apt call: `Acquire::http::Proxy` with the cache, no pipelining, and `Acquire::https::Proxy "DIRECT"`, because the cache refuses https tunnels. A play without one removes that file. Every other Node has no `apt_proxy` and reads the archive.

The [Gateway bootstrap](#gateway-bootstrap-prerequisites) of a nested fleet takes the value from its input, and `php artisan orbit:bootstrap-plan --apt-proxy=URL` puts it in the plan.

Application roles fetch the recipe's PHP packages and Bun archive with checksums, install PHP in one APT transaction, and converge the shared Tool Managers. Role add applies role-owned resources even when an earlier Node footprint has the same digest. It leaves the observer and Orbit CLI to `node:converge`.

The native Gateway role installs an enabled `orbit-gateway-dns.service` oneshot. It applies the resolver policy when the Orbit interface exists and no peer-owned DNS policy is present. Role removal disables and removes that unit. Native role failures retain completed resources and the failed assignment; there is no rollback chain.

Retained role paths install prerequisites through `role.install-prerequisites`. The role guard first reads `ID` and `VERSION_CODENAME` with `node.os-release` and refuses a release the role does not support. The command checks the release again and installs the role's packages with `apt-get`, which waits up to 300 seconds for the dpkg locks. A healthy Docker CE installation replaces `docker.io`.

For `app-dev`, it also creates `<home>/apps` for the managed user. For `app-dev` and `app-prod`, it prepares the shared Composer home, `/opt/orbit/composer`. A path that Orbit did not make fails the step with `role.prerequisite_conflict` before any change.

An application role then installs the JavaScript runtimes its [recipe profile](/reference/compute-drivers#role-and-base-tool-sets) pins: Node, Vite+, pnpm, and Bun. It sends `recipe.install-javascript` and runs no downloaded installer. Image programs render the same call as `javascript-runtime`. It replaces any root-owned launcher in `/usr/local/bin`. After it verifies the recipe Node, it removes the apt `nodejs` package, retires the NodeSource apt source, and removes Corepack links.

Caddy must be at least 2.9.0. A lower release fails the `caddy-package-source` step and names both releases. Converging a role upgrades an archive Caddy in place. A Caddy at or above the floor stays as it is, so a newer pin does not restart it. `/etc/caddy/Caddyfile` is a symlink into Orbit's own versions directory, so the upgrade keeps the live configuration.

Analytics role effects run in their native parent workflow. The Node PHP baseline refuses Analytics. An unported Analytics kind returns `operation.kind_unavailable`.

The roles `gateway`, `router`, `ingress`, `app-dev`, `app-prod`, `websocket`, `analytics`, and `swamp` install Caddy when they converge. The `swamp` role takes no part in Metrics until the Metrics port (wave 7), so it adds no exporter or scrape target.

The `swamp` role runs Swamp serve as `orbit-swamp-serve.service`, with at most 4 scheduled runs in flight. The Gateway renders serve's unit and the worker template at admission. Official `@swamp/cfgmgmt/systemd` steps write them over SSH from 209, enable the services and start any that is stopped. Their `check` and a native `assert` prove the end state.

Serve and the workers run `/usr/local/bin/swamp`. The role installs it in this order, so no running serve or worker loses its binary:

1. `@swamp/cfgmgmt/fetch` installs the [machine software recipe](/reference/compute-drivers)'s pinned Swamp release by SHA-256. It writes `/usr/local/lib/orbit-swamp/<version>/swamp`, which no process runs from yet.
2. One SSH command renames a regular file at `/usr/local/bin/swamp` into a new directory under `/usr/local/lib/orbit-swamp`, then links the path to the release. The rename keeps the inode. A running process can still start children from it.
3. `@swamp/cfgmgmt/link` points `/usr/local/bin/swamp` at the release. A process keeps the file it started from.
4. The units are written after the link.

Replacing the file in place would leave serve and the workers on a deleted binary, and every dispatch would fail until they restart.

Convergence never restarts serve or a running worker, so a changed unit or binary takes effect at its next restart.

The `node` phase gives the swamp account the Swamp start cache, `/tmp/deno-compile-swamp.cache`, when the file exists: owner and group the swamp account, mode `0644`. The check phase asserts it. The swamp account cannot replace a file that another owner left in sticky `/tmp`, and an unreadable cache adds about 0.35 s to every dispatch. The repair needs no restart. A Deno test fails when an Ansible task runs `swamp` without naming a user other than root.

Serve and each worker also run with their own `TMPDIR` in their state directory, so the cache of the units sits outside `/tmp`. The repair and the check above cover the shared `/tmp` file, which Gateway, Ansible and other `swamp` runs outside the units still use. Workers adopt the setting at their next scheduled renewal; serve waits for an operator restart in a quiet window.

### Bump the Swamp pin

A new Swamp release reaches serve and its workers through the role, with one restart in a quiet moment.

1. Merge the pin change in the recipe and the other pins. Each task VM host then refreshes its base snapshot.
2. Converge the role: `orbit node:role:add <node> swamp --converge`. Nothing restarts; the running processes keep their release.
3. When `operation:list --status=running` is empty, restart serve, which drains in-flight runs, then all three workers: `systemctl restart orbit-swamp-serve`, then `systemctl restart orbit-swamp-worker@a orbit-swamp-worker@b orbit-swamp-worker@c`. The workers retry until serve listens.
4. Check that each process runs the pinned file (`readlink /proc/<pid>/exe`).

Earlier releases stay in `/usr/local/lib/orbit-swamp`, about 400 MB each. Rollback points the link at one and restarts the same way. Remove an old release or `replaced-*` directory by hand once no process runs from it: no `/proc/*/exe` names it.

Another installer can put a plain file back at `/usr/local/bin/swamp`, such as the Node worker installer. The next converge renames that file into a new directory and links the path again, so no running process loses its binary. Serve's grants follow the checkout: the release's Swamp load renders them and runs `swamp access reload` ([Load Swamp](/reference/gateway-recovery#load-swamp)).

The role runs three permanent Swamp workers, `orbit-swamp-worker@a`, `@b` and `@c`. Each reaches serve on loopback with one dispatch slot per CPU core (`--concurrency auto`) and the labels `role=swamp` and `node=<id>`. On Node 209 this gives twelve configured slots on four cores; two workers retain the previous eight slots while the third drains. It adds admission capacity, not CPU cores. Each worker keeps its own enrollment, server token, state and cache.

Before adding or converging the role, the operator must place distinct, valid credentials for **all three** workers at `/etc/orbit/swamp-role-worker/<a|b|c>/{enrollment,server}.token`. On Node 209 the additional files are:

- `/etc/orbit/swamp-role-worker/c/enrollment.token`: the full enrollment token, `<enrollment-name>.<secret>`, for a distinct single-machine worker identity.
- `/etc/orbit/swamp-role-worker/c/server.token`: the full server access token, `<access-name>.<secret>`, authorized for that worker to connect to serve.

On 209, c uses enrollment token `swamp-role-209-c` and access token `swamp-role-209-c-access`, with principal `worker:orbit-node-209` and a 30-day duration, matching a and b. The role discovers the worker name from the journal; it has no hard-coded token name or principal. New Gateway proof fleets keep their own bootstrap names.

Use `root:orbit` mode `0640` for both files and `root:orbit` mode `0750` for the `c` folder on 209 (use the swamp account's group on another installation). As with a and b, create/reveal these locally with the pinned official Swamp CLI in serve's repository, through the approved operator credential procedure. Do not copy a or b's enrollment: one identity cannot connect twice. Remote `_token-secrets` vault reads are forbidden by Swamp.

The swamp role and its timer never mint, reveal, rotate or write tokens. Token lifetime maintenance remains the same operator responsibility as a and b. Bootstrap of a new Gateway provisions all three locally. Before remint stops any service in a proof fleet, it checks that c's enrollment and server files use full `<name>.<secret>` form, with the expected bootstrap or remint name prefixes. An older two-worker proof fleet must first provision its three-worker control plane through local bootstrap; remint otherwise refuses and leaves it running.

Swamp ends authenticated WebSockets after eight hours, even while workers refresh their separate 15-minute session credentials. The role installs one systemd renewal service for the three permanent workers. Its timer starts one minute after activation and checks five minutes after each round finishes. One round renews only the oldest worker whose successful connection is at least three hours old. There is no temporary worker or retirement drain.

Before a drain, all three workers must be connected, have their full `auto` capacity and more than 90 minutes of socket lifetime left. The two peers stay accepting. Peers with spare slots must pass native live probes; Swamp rejects a probe of a full worker, so full peers are not probed. A fresh pool check must still show the same instances and at least one spare slot across the two survivors. Otherwise the service defers renewal and retries after five minutes. This preserves eight accepting slots during a long drain on 209 without requiring every slot to be free.

Recovery needs only two serving peers with full capacity. If the third unit is failed or inactive, the service resets its systemd failure limit and starts it, then ends the round. Its connection is checked next round; recovery never drains a serving worker.

The service restarts one unit with SIGTERM. `KillMode=mixed` sends it only to the main process; Swamp finishes active dispatches before disconnecting. `TimeoutStopSec=4500` allows 75 minutes, giving headroom over the observed cold refresh step of about 50 minutes. Work beyond this allowance can still be killed.

A system shutdown does not wait for this drain ([Shutdown never waits](#shutdown-never-waits)). The service waits for a different accepting instance, checks all three workers' capacity, then probes the renewed worker. Native readiness retries are limited to 20 retries, two-second delay and three-second CLI calls; probes are capped at 125 seconds. A round has a 90-minute systemd deadline and a slightly shorter outer command timeout.

Oldest-first selection and one drain per round avoid a second drain to remove a replacement. Starting at the three-hour threshold, even three 90-minute rounds separated by five-minute waits finish by age 7h45. This is a scheduling budget under healthy admission and reconnection, not a guarantee under failures or unlimited load. Activate on connections young enough to complete their first renewal before eight hours; the service refuses a drain with insufficient headroom. Repeated deferrals, invalid tokens or a missing worker can still leave the cap risk active and need operator attention.

Each unit's latest `Enrolled as` receipt from the current boot supplies its actual name and connection timestamp, including bootstrap and timestamped remint names. Pinned Swamp logs this receipt on every successful connection (`connect.ts:547`), even when its durable `enrolledAt` remains unchanged for a reconnect of the same process. Renewal uses the journal timestamp for selection and headroom, so a reconnect clears the old-age lockout; once that socket becomes due it can renew normally. The service refreshes these receipts after live probes.

The unit carries only token paths. Missing receipts log that renewal stopped and the eight-hour cap can still abort work. Peer failure during a blocking restart is detected after it returns, logged explicitly, and stops the sequence. No other worker is deliberately drained during that round. Worker crashes retain 30-second backoff and at most five starts in five minutes.

Orbit uses no worker-affinity groups. Later steps can queue on either survivor during a drain. Swamp fails an affinity group when its worker drains, so adding affinity requires a different renewal design.

After the credentials are placed, release the Gateway and load Swamp, then converge with `orbit node:role:add swamp swamp --converge`. The Ansible `node` phase installs the renewal playbook, tasks and Gateway-rendered service/timer. The Ansible `swamp` phase applies the worker template, enables all three permanent workers without restarting live processes, then starts the timer. Swamp load alone does not apply host files. Convergence leaves active workers running and starts c; serve needs no restart.

Watch `orbit-swamp-worker-renewal.service`, workers a/b/c and serve. Require all three connected before a drain, two workers/eight configured slots accepting throughout it, and the renewed instance accepting before another round. Keep long work and queued follow-on operations active across renewals. At the old eight-hour boundary, require no code 4002 or write-then-drop failure. All three workers remain enabled and running between successful rounds.

The workers run as that account and read the files themselves. The role sets the folders to `0750` and the files to `0640`, with `chown` and `chmod` in two steps, before it writes the worker unit. The next converge repairs a Node whose tokens were placed as `root` `0600`. Nothing reads them first: without them, the worker start fails with `swamp.worker_unit_failed`. The other runtime codes are `swamp.ssh_identity_failed`, `swamp.serve_unit_failed` and `swamp.runtime_check_failed`, at the failed step `runtime`. `node:role:remove` refuses the `swamp` role with `validation.failed`, because serve on its Node runs that removal.

The role also installs the Node's `node-proxy` key as the SSH identity `/etc/orbit/swamp-role-worker/ssh-identity` (`0440`, the serve account's group). An operator bootstraps the first swamp Node, because no worker holds the key before that. The role serves no site itself: `swamp.orbit` is a private custom proxy Route on its Node, with upstream `http://127.0.0.1:9418` ([ADR 0243](/decisions/0243-serve-swamp-through-a-swamp-role)). AI router publication does the same on its Node. [Caddy configuration](/reference/caddy-configuration) describes how the Gateway builds each Node's Caddyfile.

`caddy.install-package` owns package installation for every caller. The Gateway sends the release pins and keeps the operation error mapping. The agent owns the download URL, kernel setting, and obsolete source paths. It checks the pins and host paths before mutation, verifies the downloaded package before apt sees it, and publishes the kernel setting atomically. Package and system commands share the command deadline. Rust tests cover installation, repetition, drift, and refusal; Gateway tests cover intent. `caddy.order-after-vpn` then orders Caddy after WireGuard. Its failure maps to `gateway.caddy_start_failed`.

### Caddy reloads

The Caddy step also writes `/etc/sysctl.d/60-orbit-caddy.conf`, owned by `root:root` with mode `0644`:

```text
net.ipv4.tcp_migrate_req = 1
```

On each reload, Caddy opens a new listening socket and closes the old one. With this setting, the kernel moves the connections that wait on the old socket to the new one, instead of resetting them. The step loads a candidate file with `sysctl --load` first, so a kernel that refuses the setting fails the step and leaves no file. It applies the setting on every run, so a changed live value returns to `1`. It refuses a live file that is a symlink, not a regular file, or not `root:root` mode `0644`. Doctor does not check the setting. Converge the role to repair it.

HTTP/1.1 clients can still get an empty reply on a new connection during a reload. The CLI and the PHP SDK use HTTP/1.1. The SDK retries a `GET` or `HEAD` request once after 250 milliseconds when the connection fails without a response. A request that changes state is never retried, so it can fail while the Gateway's Caddy reloads. Run it again. Browsers and curl use HTTP/2 and do not see this.

### Caddy on the Gateway machine

Converging the `gateway` role runs the Caddy step over SSH before its firewall step. A failure returns `node_role.convergence_failed` for step `caddy-package-source`, and the role becomes `failed` with error code `gateway.caddy_install_failed`. Run `orbit node:role:add <node> gateway --converge` to repair it.

Caddy also serves the Gateway API. A convergence that installs a newer Caddy restarts it, so the CLI can report `Could not reach the gateway.` while the Gateway finishes. `orbit activity:list` shows the real result. Run the command again after a minute.

## One lifecycle owner per Node name

`node:add`, `node:remove`, and `node:update` each take one lock for the Node name; a rename also takes the new name's lock. They hold it from the lookup to the last remote step and rollback. Another operation on the same name fails with `node.provisioning_busy` (HTTP 409) before it changes anything. A retry reads the Node again after it gets the lock. Operations on different names run at the same time. The lock does not block role changes. A lock that nobody releases expires after one hour.

## Role operations on one Node

The Gateway holds one Node Operation for `node:role:add` and explicit role convergence. Admission claims `node:<id>` before changing the assignment. A competing role-add or convergence admission refuses with `operation.target_busy` (HTTP 409), without creating an Operation or changing the assignment. The hold lasts until the native run settles. Tool Manager setup runs inside that workflow. `node:converge` owns the observer and Orbit CLI separately.

Retained provisioning and removal paths use the role lock before claiming the assignment. A second operation waits up to 2 minutes, then fails and leaves the assignment as it was.

| Command | Error |
| --- | --- |
| `node:role:remove` | `node_role.remove_failed`, with `details.step` `node-lock` and `details.error_code` `node_role.node_busy` |

Retained provisioning and removal paths return each failed step's own code in `details.error_code`, such as `node_role.tool_manager_locked`, a `metrics.*` code, or `ingress.caddy_config_failed` for a Caddy removal. `node:add` and `node:remove` do the same when a role step failed.

Cluster Router changes take the lock only around the baseline step.

They also hold the Route-serving Nodes around the steps that install and retire Router sites, for the seconds of those steps. The revert of a failed set is not held ([Coordinate publication](/reference/routes#coordinate-publication)).

`node:role:relocate` holds its operation's target lock on the target Node until the run ends. For role addition and convergence, the Node Operation hold covers Metrics reconciliation. The retained removal and provisioning paths reconcile Metrics inside their role lock. Metrics reconciliation converges exporters on other Nodes without their locks, so two operations cannot wait on each other. Operations on different Nodes run in parallel. An offline role removal runs outside the lock, because it changes nothing on the unreachable machine.

A retained provisioning or removal path that dies can leave its role `provisioning` or `removing`. The claim is stale once it is 11 minutes old and no Operation holds the Node. `node:role:add --converge` and `node:role:remove` can then take it over. Swamp runs continue if a Gateway worker exits. Doctor reports the inactive role lifecycle.

### Per-Node locks

Retained provisioning and removal paths, Tool operations and observer convergence use these four locks. They live in a file cache store under `ORBIT_HOME`, whatever `CACHE_STORE` says. Role addition and explicit convergence use the Node Operation hold described above.

| Lock | Guards | Term | When it is busy |
| --- | --- | --- | --- |
| Tool | One package of one Tool Manager | 10 minutes | Fails at once with `tool.operation_locked` |
| Tool Manager | The shared scope of `apt`, `vp`, `composer`, or Homebrew; `brew` and `brew-cask` share the prefix lock | 10 minutes | Fails at once with `tool.operation_locked`, `node.tool_manager_locked` during `node:add`, or `node_role.tool_manager_locked` in a role operation |
| Role | Role operations | 10 minutes | Waits up to 2 minutes, then `node_role.node_busy` |
| Node agent | The [agent converge](/reference/node-agent#install-and-upgrade) | 7 minutes | Waits up to 2 minutes, then `agent.converge_busy` |

A Tool operation takes its Tool lock, then its manager lock. Several manager locks are taken in the order `apt`, `vp`, `composer`, `brew`. `brew-cask` takes the `brew` lock rather than a fifth lock. A retained role operation on `app-dev` or `app-prod` takes the `vp` and `composer` manager locks first, and then the role lock. The Node agent lock comes last. The locks cannot deadlock: the Tool and manager locks never wait, and no code takes the role lock while it holds the agent lock.

### Lock renewal

The 10-minute term matches the Gateway's PHP-FPM request limit. In an Artisan command, such as `orbit:node-provision`, the Tool, manager, and role locks have a 20-minute term. Each remote command times out after 15 minutes by default.

Before each command that a process runs, on the Node or on the Gateway, the Gateway renews every lock that the process holds for its full term. So a long operation keeps its locks, and a process that dies blocks the Node for at most one term after its last command started. A renewal fails only when the lock has expired. The command then does not run and fails with `node.lock_lost`, and so does every later command of the operation. A role operation that lost its lock stops, reports `details.error_code` `node.lock_lost`, and leaves the role `failed` with that code.

## SSH connections

The Gateway runs every remote command as a channel on one shared OpenSSH connection per Node. The first command opens the connection. Later commands reuse it, and it closes after 60 idle seconds. A command on a shared connection takes about 18 ms, and a command on a new connection about 190 ms.

The sockets live in `ORBIT_HOME/ssh/mux`, and the directory has mode `0700`. Every Gateway process runs as the `orbit` user. So web requests, the scheduler, and commands share the same connections. Each socket name hashes the user, the Node address, and the port. A Node with a new address or port therefore gets a new connection at once.

- A dead Node ends its connection within about 10 seconds, through `ServerAliveInterval=5` and `ServerAliveCountMax=2`.
- A key or host-key change on the Node applies when the connection closes.
- When the socket path is too long for a Unix socket, or the directory cannot be created, each command opens its own connection.
- A command that runs inside the Horizon unit opens its own connection. A release waits for that unit's control group to empty, and a shared master would stay in it.
- When a Node refuses another channel, OpenSSH opens a direct connection for that command and writes two warning lines to its stderr.

A reachability check always opens a new connection. The `--offline` probe of role and Node removal, and the Node probe of task cancellation use it. Doctor uses official Swamp SSH. File copies between Nodes for Instance transfer and clone use `scp` on their own connections.

A Node that the Gateway reaches through a [jump Node](#enroll-through-a-jump-host) shares a connection named after the jump Node, the Node's address, and its pinned host key.

## Public SSH

The bootstrap adds the UFW rule `orbit:public-ssh-recovery` and enables UFW. Once SSH answers over WireGuard, the Gateway adds `orbit:wireguard-members` and keeps public SSH open. The first active role removes the public SSH rule, so the Gateway then reaches the Node only over WireGuard.

- A Node without an active role stays reachable over public SSH.
- A later `node:add` of such a Node connects over public SSH again. It finishes the WireGuard peer over the verified tunnel.
- Removing the last role restores `orbit:public-ssh-recovery` over WireGuard before the Gateway deletes the assignment.
- When that restore fails, the role stays `failed` at step `remove:firewall-recovery` with `node.firewall_recovery_failed`. A retry repeats the removal.
- Removing a role while another role remains keeps public SSH closed.

## WireGuard hub

The Node with the `vpn` role holds the WireGuard hub: its private key, address, listen port, and the list of peers. When a peer changes, the Gateway renders the hub configuration and installs it there. [`node:wireguard-key`](/cli/node/wireguard-key) sets or replaces an unmanaged Node's public key and runs this projection without configuring the peer machine. It refuses managed Nodes, Gateway Nodes, and keys that collide with another Node or the hub, including concurrent assignments. A successful projection preserves non-target peers.

The hub keeps one peer file per Node. A hub converge prunes only the files of Nodes that are gone or being removed, and never the file of a Node created after its intent; [Converge the hub](/reference/wireguard-endpoints#converge-the-hub) has the rule.

Repeating the same key still projects the hub when it has no collision; after a projection failure, the stored key remains and the operator retries after repairing the hub. A crash can leave either the old or new hub configuration, without guaranteed rollback or automatic reconciliation. [WireGuard key recovery](/cli/node/wireguard-key) explains how to restore an authorized management path and replay the desired key.

The Gateway runs the [Node agent](/reference/node-agent#commands)'s `wireguard.project-hub` on the `vpn` Node, with the configuration and its private key in the protected input. When the `vpn` and `gateway` roles share a machine, the runner starts it as a local process; otherwise it reaches the `vpn` Node over SSH to its WireGuard address. The command writes a root-only candidate, validates it with `wg-quick strip`, keeps the live file as a backup, and swaps the candidate in. Then it live-syncs a running `wg-quick@orbit` with `wg syncconf`, or enables and starts a stopped one. When activation fails, it puts the backup and the previous enabled and active state back.

| Agent refusal | Step | Error code |
| --- | --- | --- |
| `wireguard.hub_install_failed` | `wireguard-server-install` | `vpn.server_config_install_failed` |
| `wireguard.hub_config_invalid` | `wireguard-server-validate` | `vpn.server_config_invalid` |
| `wireguard.hub_start_failed`, or no result | `wireguard-server-restart` | `vpn.server_start_failed` |

The Gateway always keeps the rendered hub configuration, private key included, at `ORBIT_HOME/generated/wireguard/orbit.conf`. The native VPN convergence validates its configuration and DNS projection.

Peer convergence still runs over SSH. It generates or reads the peer key and installs the peer configuration during admission, over the public bootstrap address, before the tunnel that the agent's transport uses exists.

## Remove a Node

Removal captures its role publication, private DNS, hub, Router firewall and Metrics participants before dispatch. Global peer DNS work runs only when removal changes the canonical routing domains. Every required participant needs its stored WireGuard address and full host pin; no participant needs its own worker.

`orbit node:remove <node> [--offline] [--force] [--idempotency-key=KEY]` records one `node.remove` Operation. The Gateway captures the full public intent, stored WireGuard addresses and full host pins before starting its native Swamp workflow. Every step runs on Node 209. Host effects use official `@swamp/ssh` with the approved 209 identity; removal needs no worker on the departing Node or its participants.

Remove Instances, Database servers, Schedules, Routes and user firewall rules first, including before offline removal. Use `--force` to consent to shedding the Node’s mutable roles. Protected roles stay with their owning lifecycle. The caller, Gateway, VPN and Swamp control Node are protected. Drain a task VM host and clear `settings.task_vms` before removing it. TaskVM guests stay with their owning lifecycle; standalone removal accepts no provider-deletion assertion.

The parent sheds admitted roles through native workflow children, restores public SSH, removes the obsolete worker and observer footprint, revokes the departing worker credentials, and then removes only its admitted management SSH keys. The hub peer withdrawal follows those target-side actions. It retains user data, package managers and unrelated SSH keys. Network and serving publications use their shared native children from Node 209. A child failure prevents later retirement steps and keeps the Node record with failed status. An expired budget (`operation.timed_out`) does the same. Cleanup does not roll back completed effects; the terminal result reports only completed work.

Task VM guest Nodes skip Node removal's Swamp token list and revoke phases. Admission records `task_vm_guest` only for a Node referenced by a TaskVM row with no selected Swamp worker; callers cannot set it. Real Node removals retain token cleanup.

`--offline --force` permits removal of an unreachable Node's registry membership while leaving its machine untouched. The native workflow checks reachability first. A reachable target runs ordinary cleanup. The result names the artifacts retained on an unreachable machine; offline confirmation does not fabricate their removal.

### Native role child

The `node-role-remove` workflow, started directly by `node:role:remove` and as a child by Node removal and role relocation, receives the parent’s `operation_id`, `node_id` and `kind`, a schema-version1 public `intent`, empty `execution_secrets`, and the parent’s observed `offline` boolean. Its intent contains `node_id`, `assignment_id`, `role`, the pinned `ssh` connection, before/after publications, and the admitted action lists. The child returns typed `schema_version`, `node_id`, `assignment_id`, `role`, `role_removed: true` and `offline` fields. It creates no child Operation, performs no Gateway read, and leaves forward role convergence separate. Metrics removal disables its canonical fleet exporters and service publications. Role removal retains CI resources, release data and container volumes; it does not purge them.

The workflow returns one public terminal result. Only successful native completion with `removed: true` deletes the Gateway Node record and its owned records; work that failed, was cancelled, was interrupted or timed out keeps them as `failed` records, with `failed_step` `remove:operation` and the Operation's `error_code`. The release of a task VM (`task_vm.slot_release`) deletes the Node record in its projection once the run succeeded. Steps do not fetch intent, post progress or project state through Gateway callbacks. Node deletion broadcasts after the database transaction commits.


## Why it works this way

These reasons explain the design. Check them before you propose a change.

### Removal retains user data

Remove Instances and role-owned resources before online Node removal. The native removal workflow sheds admitted roles before retiring its Orbit observer, worker, management credentials and Gateway records. It retains user data and package managers. macOS and iPadOS retain their host WireGuard configuration. Create and destroy remain the names for machines at a hosting provider.

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

Enrollment and repair have different contracts. `node:add` refuses an existing Node with Instances; `node:converge` repairs managed state without re-enrollment or assignment changes. Its admission returns HTTP 202 with an Operation and freezes the desired state before any machine effect, so later record edits cannot alter an in-flight attempt.

Admission cannot prove that a matching machine is healthy without contacting it. Every new attempt therefore inspects and reapplies all seven phases rather than trusting a stored digest or a force flag. Ansible's matching tasks supply the no-change result. Ordered phase failures stop dependent work, while independent Tool results stay on their own records. Keeping partial changes and starting the next attempt from input makes repair fix forward, not a rollback or a hidden resume chain.

### A Mac needs no service role

A Mac is a managed Node for tools, not a service host. It uses the account, WireGuard identity, and SSH access that already exist. Enrollment pins the approved host key and checks the platform, architecture, account, and tunnel before it succeeds. It creates no account, installs no Ubuntu packages, and does not change host DNS or the firewall.

An application role was rejected because tools do not need application services and the role list stays empty. Native Doctor reads use official Swamp SSH exec. The command binary does not run an observer or login-session service on macOS. The Ubuntu bootstrap was rejected because its packages, users, resolver, firewall, and systemd units do not apply to macOS. A second Homebrew or Vite+ install was rejected because the packages already on the machine would stay outside the scope Orbit manages.

Platform support is checked per operation. Tool management and Doctor run on the enrolled Mac. Linux roles, exporters, Processes, Schedules, and the observer service do not. A role assignment fails before any remote change. macOS OS updates, firewall management, application hosting, and a macOS login-session agent are separate features. A roleless Ubuntu Node proves the same empty-role boundary on Linux, including apt adoption without a reinstall. A real Mac proves enrollment and the Homebrew and Vite+ lifecycle. Low free space on that Mac makes the Node drift with `node.disk_low`.

### CI roles own hosts; operators own runner registration

The CI runner role uses the existing role lifecycle, firewall, Doctor, exporter, agent, and fleet rules. Selecting software through a recorded profile hides workload Nodes and bypasses those rules. The role replaces that exception. Sharing with app and database roles permits Beast's existing workload mix; a CI-only Node such as Shark needs no app role. The project, bridge, ACL, and profile names are reserved for the role, so its first convergence adopts the existing setup-script projection in place.

Container creation and org registration remain together in the operator script. Splitting creation into the role while leaving registration outside it would create unregistered containers and a second partial lifecycle. This host role does not implement Gateway-owned runner records or GitHub App registration: the deployed runners serve the organization, and the Gateway lacks the organization token. Giving the App repository Administration write would not implement that boundary and would expand its permissions. A future managed runner lifecycle needs an explicit credential and ownership decision.

Gateway-owned runner records and their credential flow remain in progress in [ADR 0213](/decisions/0213-run-ci-runners-through-a-ci-runner-role). This host role needs no GitHub permission change.


### Pinned bootstrap transport

The Node enrollment workflow uses `bootstrap-ssh` before its WireGuard address is available. `node:add` proves the admitted host fingerprint and architecture inside its own enrollment workflow. `node.bootstrap_install` installs the admitted agent pin only after that probe succeeds. Host, port, login and host fingerprint come from the Operation input. The worker cannot select a connection or credential. The Gateway resolves the existing SSH identity through a protected reference and refuses a different observed fingerprint before running a command. Receipt reads reuse that identity without creating or rotating credentials. These child primitives do not change the retained synchronous enrollment callers.


### Node lifecycle children

Internal callers admit `node.add` through `ProvisionNodeAction::start` and `node.remove` through `RemoveNodeAction::start`. Both record normal Operations whose Node effects run in Swamp. Node removal has no synchronous executor. Existing provisioning callers keep their synchronous methods until their own cutover. Services and configured executor workers use synchronous recovery and refuse these parent admissions.

Enrollment records its pinned bootstrap connection, candidate placement and generated Route changes before execution. Generated-domain children can run before candidate placement is committed; private DNS children see only committed placement. Each effect on another Node belongs to a child whose serving Node and input come from the recorded intent. Bootstrap architecture aliases `amd64` and `aarch64` normalize to `x86_64` and `arm64` before validation.

Node removal records immutable participants and Route withdrawal intent before its native run. It preserves the departing Node's Route associations through serving cleanup. Swamp decides completion; removal does not restore completed effects after a failure. The Gateway projects the native result without child receipts or runtime proof checks.

A task start (`tasks.provision`) records the Node of its VM at admission; no enrollment child runs, and the release deletes the Node record. Serialized role names are converted to the Node role enum at admission.

Cross-family enrollment uses the internal `ProvisionNodeData` field names. TaskVM Node cleanup retains its owning planner and custody checks. Standalone Node removal refuses TaskVM guests and caller-supplied provider-deletion claims.

Every hub render excludes Nodes in `Removing` state, including concurrent enrollment and retained synchronous renders. Enrollment rollback restores the prior state before restoring hub membership. Node removal places serving cleanup on every admitted participant, including Services and control workers. Enrollment retains its own placement policy.

Enrollment and Node removal publish private DNS only on an active Node with an active VPN assignment. Gateway-only Nodes receive no fleet DNS publication.

When no separate Node has a VPN role assignment, the Gateway runtime [installs the shared local listener and its baseline catalog](/reference/private-dns#the-gateway-machine) before resolver routing. Any separate VPN assignment, including a failed or removing Node or role, keeps the Gateway runtime, recipe and resolver inputs unchanged, so stock-bootstrap repair has no side effect on a working production Gateway. Colocated Gateway and VPN roles use one listener footprint.

Admission records local ownership before dispatch, so a crash or a later machine-task failure keeps cleanup possible; if a separate VPN joins later, the next Gateway converge routes DNS to it and withdraws the recorded local listener. Fleet publication keeps the VPN owner selection. Saved footprint listener runs refuse a target without an active VPN role.

If the existing worker cache is empty, owned by root and mode `0755`, converge
replaces it with a private `0700` cache for the configured Node user. It refuses
a nonempty cache owned by root or a symlink. Credential ownership stays the same.

## Native role additions

Adding `gateway`, `ingress`, `app-dev`, `app-prod`, `metrics`, `database`, `websocket`, `analytics`, `ci-runner`, or `swamp` records one `node.role_add` Operation and returns HTTP 202. `node:role:add` follows that Operation. `--converge` records `node.role_converge` for an eligible existing assignment and uses its role convergence DAG. Eligibility is recorded state: active, failed at a `converge:` step, or provisioning for at least 11 minutes without an Operation holding the Node. Recent provisioning, removal and unrelated failures are refused before host contact or mutation. Native initial-add failures retain a `converge:` failure marker, so explicit convergence can repair partial work. VPN and Router retain their protected-role policy.

Before a role claims an existing Node Process, Orbit checks its [Node service target dependencies](/reference/processes-and-schedules#node-service-target-records). A dependent Instance refuses the claim before role settings or runtime changes.

The Gateway freezes the role's public packages, files, services, certificate requests, publications and Metrics changes at admission. The control worker on Node 209 applies them over pinned SSH. Neither additions nor role convergence require a worker on the target. Both use the shared Swamp workflow and Ansible phases; Node convergence separately owns the observer and Orbit CLI.

Shared PHP publication and dedicated FPM status pools have separate files. A Metrics role change captures both, so publishing shared pools does not discard the dedicated exporter pool plan. Before it publishes a status pool fragment, Ansible validates a candidate master that includes that fragment and the existing local settings.

Private role files come from execution vault values and use mode `0600`. The Gateway projects the native terminal check, completed certificate and Caddy publication, and role-owned artifact digests. Finished host changes remain after failure. Analytics addition records its managed Process when its runtime succeeded, even if a later publication fails. No step reads intent or sends a result callback to the Gateway.

Exporter work shares the prospective certificate plan. It omits Caddy and shared PHP publication when the parent owns that Node’s complete publication. Dedicated FPM status pools remain in the exporter phase.



### Native role lifecycle

Role convergence uses the same Swamp workflow and Ansible phases as role addition. Removal first records the assignment as removing, then publishes the remaining PHP, Caddy and DNS state and reconciles the complete Metrics fleet. It removes only the withdrawn role's runtime, certificate and firewall rules. The last role restores public SSH before closing role access. Shared packages and Tool managers remain.

### Native role relocation

`node:role:relocate` moves `gateway`, `websocket` or `metrics` in one native run, `operation-node-role-relocate`, on 209. Its [chart](/cli/node/role-relocate#how-it-works) names every step. The Gateway refuses the `swamp` role before it records anything, with `node.role_relocate_swamp_held` (HTTP 422): serve on that Node runs every Swamp run, so a run cannot move it. A live move of the `swamp` role is still open.

Admission moves the assignment, then captures the whole intent with `NativeNodeRoleRelocateInput`: the target's runtime, its certificate leaves, firewall arguments and publications, and the source removal that `node:role:remove` builds for the same role. It renders the publications as the run leaves them, with the target's certificate published and the source's withdrawn. Both Nodes need recorded SSH management; neither needs a worker.

Every target step is one official `@swamp/ssh exec` argument list with the target's recorded host key. No script ships. Each role has its own target steps:

- `metrics` runs `operation-metrics-enable` against the target. That is the workflow `metrics:enable` runs. The Gateway site keeps its certificate, so the move signs none.
- `websocket` builds a new Reverb release as the Node's user. The target needs Git, PHP and Composer.
- Reverb's `.env` comes from the vault copy on stdin. `ln -sfnT` then points the install path at the release.
- `gateway` writes the private DNS route drop-in and applies the route to the live link. A failed `Route the private domain` step reports `follow_up`: `orbit node:role:add <target> gateway --converge`.

Then the run publishes. The `node-role-certificate-publish` child signs each certificate. The Caddy sites and private DNS of every publishing Node but the source follow. A `websocket` move waits the private DNS grace. Then `node-role-remove` removes the role from the source, and its own end check proves the removal.

One native assert, `Confirm the move`, ends the run. The Gateway records the result from it:

- On success the target assignment becomes active. The certificate and serving state follow the move.
- The Gateway forgets the source's transferred settings.
- A failed step marks the assignment `failed` with `converge:STEP`, or `remove:Remove from the source`.
- A role that was already `active` stays `active` when `node:role:add --converge` fails. The failure stays on the Operation.

Relocation does not reconcile Metrics. When a Metrics role exists and `websocket` or `gateway` moved, `follow_up` names `orbit node:role:add <metrics-node> metrics --converge`.

The native removal builder accepts the actual replacement assignment when preparing source cleanup. A Metrics move retains fleet exporter services, their exporter footprints and shared service deny rules. Cleanup still removes the old Metrics containers and source-owned firewall access. Removing Metrics without a replacement withdraws the exporters.

### Reuse role lowers in a native parent

The owning parent admits role snapshots and private bindings. Inside its private material source, `NodeRoleRuntimeSource::resolveRoleMaterial($snapshot, $nodeId)` validates the assignment, Node, pin and runtime reference before rendering existing credential types. It returns an `OperationSecretValue`; aggregate its in-memory value and redaction strings without serializing protected bytes. The standalone source retains its own Operation ownership and field binding checks.

After checking its admitted publication participant, the parent uses `NodeRolePublicationInput::forPlan($plan, $node)` for fresh Caddy, PHP and private DNS input. The lower renders only duties in that immutable plan. Role removal, relocation source, role, Metrics and proxy metadata stay in the owning public view. These internal lower APIs create no Operation and grant no new private endpoint authority.

## Native role convergence

`node:role:add NODE ROLE --converge` requires an existing assignment. It keeps that assignment's identity throughout the run. It uses the same native resource graph as role addition for every mutable role, including Nodes with several roles and fleets with Metrics. It applies role-owned packages, files, runtime and publication from the frozen intent. It installs no observer or CLI and needs no observer secret.

Matching Reverb inputs skip its restart, while an inactive service starts. Matching Metrics containers keep running. Prometheus configuration is validated, copied onto the existing bind-mounted file and reloaded with SIGHUP. Analytics convergence repairs role-owned configuration and checks its existing Process; it creates no Process and runs no database migration.

The role's Ansible end check determines completion. The Gateway marks the same assignment active on success or failed at the native step on failure. Finished changes remain for the next convergence. Cancellation goes to the bound Swamp run, and only its terminal event settles the Operation.

### Role Ansible phases

`node:role:add` and its role-converge run use one Swamp step per Ansible phase: prerequisites, Node resources, publications, firewall, exporters, Prometheus and check. Each phase applies the admitted state and is safe to run from the start. Tool Manager setup, certificate signing, Analytics initialization and Swamp bootstrap keep their native steps.

Firewall convergence compares each owned rule's shape and position. It removes a changed isolation pair by descending rule index, including duplicate rows with the same owned comment, then inserts the specific allow before its deny and broad peer trust. Unrelated rules stay in place.

A readiness phase waits up to 60 seconds for a newly started Analytics Process before publication. The Gateway DNS oneshot runs only when its unit or live resolver policy changed, under the unit’s existing conditions. PHP and DNS delta publications retain their shared native three-way composition, which preserves unrelated changes admitted by other commands; Ansible has no built-in equivalent. Ansible runs only on the Swamp Node; targets need SSH and Python.

The `swamp` role installs a pinned Ansible environment under `/opt/orbit/ansible`. The `@nckrtl/ansible-input` behavior delivers the playbooks and writes the whole intent to a private run folder. The input builder writes inventory, variables and pinned `known_hosts` there. The readiness of a nested fleet uses the same input, as the kind `task_vm.nested_ready`. The behavior also reads the private Ansible callback reports of a [fleet reboot](/reference/gateway-recovery#one-node-of-the-pass) on the controller. That reference defines its safe read retries and the unknown outcomes of an interrupted root.

The development create playbook has no builder: it reads the intent file by lookup, pins the admitted host keys in the run folder and addresses the Node itself, and it takes its secrets from the environment of the phase. Separate guarded steps write vault values to mode `0600` files. The builder consumes and removes those files. A completed-run cleanup removes the folder after success or failure. No credential enters the run inputs or command arguments.

For a web-serving Project, its `checkout` phase also gives Caddy its access to the checkout and sets `APP_URL` in the `.env`, before the setup commands write into the tree. The Route playbook `route-create-site.yml` then skips both on that Node, and removes its own run folder as its last task, so a success needs no cleanup step. It updates the trust store only when it wrote a new Orbit root.

The withdrawal playbook `route-withdraw.yml` keeps its remote tasks few, because each costs about 0.4 s: one command checks every file it will replace, and one removes every certificate folder.

The Route playbooks `route-create-site.yml` and `route-withdraw.yml` work the same way. Route access rows distinguish optional Laravel storage paths from required web roots. The site phase stats optional paths and grants only those present; it never creates checkout folders. The withdrawal has two phases, `read` and `apply`: between them Swamp composes the result and writes `read.json` and `plan.json` into the run folder, because a Caddyfile is too large for a step output or an environment value.

Task VM host preparation belongs to the [base refresh](/reference/compute-drivers#prepare-an-incus-host). Its shared Ansible phase prepares both native-ZFS and loop-backed pools; loop hosts receive a 2 GiB ARC cap to retain memory for their own services. Before the first guest boot, it opens catalog-owned DHCP input on the task bridge and DNS input to the bridge address, as well as forwarding. Settings removal and reachable Node removal withdraw the three input rules through the same Ansible phase. This lets a guest obtain its address and resolve package servers on a host with default-deny input.

Proof preparation installs the tested checkout's frozen observer pin on every nested Node with checksum verification. Every batch and direct case prepares independently, including when an older workflow skips setup. One Ansible run uses the Node convergence replacement task, stops all observers, discards their old presence versions and host receipts, starts them, then requires each expected version with its binary digest and a new host fact. Preparation has its own 330-second bound; batch setup gets a separate 120 seconds within the 480-second transport limit. Each restore repeats preparation; no proof changes the base snapshot.

The `ai-router-model-forwarder.yml` playbook, including its numeric packet-counter checks, is documented in [AI router](/reference/ai-router#container-model-forwarder).

The container playbook preserves agent settings outside Orbit's managed JSON keys and Git workspace trust, and applies the [per-Instance limits](/reference/development-containers#inside-a-container). It writes the shared [container PHP settings](/reference/php-runtime#container-php): the memory limit and PHP Data Bridge.

Development container [mail setup](/reference/development-containers#development-mail) runs in the Instance lifecycle. It calls the shared bridge helper additively and batches the mail preflight and unit installation into at most two guest commands; a Gateway release does not need a Node converge before those Instance Operations can apply mail. The shared NIC allowance remains limited to its existing proxies.

Development mail repair uses the Node’s existing Python prerequisite through fixed Incus argv. The container needs only its stock shell and coreutils for atomic replacement. It needs no Project PHP runtime or new image digest; see [development mail](/reference/development-containers#development-mail). The shared rule preserves matching quotes around `log` and `smtp`; its guard accepts only whole lines on the shared whitelist and reports the first refused line as `guard_line`.

Container migration reuses this [development mail phase](/reference/development-containers#development-mail) after copying the workspace. It requires no Node converge.

Instance migration permits untracked files during retirement and counts copied untracked files that Git does not ignore; ignored files are still copied. Tracked changes, other worktrees, unpushed commits and stashes still block; [migration](/cli/instance/migrate) explains the seed and checkout consequences.

Checkout destroy admission has one [read-only SSH exception](/reference/remote-programs#remote-programs) for live Git dependencies, before Schedule withdrawal. It uses the existing Node account and identity with a short timeout. Containers and unmaterialized sources skip that read. The workflow and its source-removal command do not recheck dependencies, including on a retry. It never traverses checkout contents. A failed read of another checkout falls back to bounded, non-blocking Git pointer reads; unknown unrelated checkouts produce named Operation warnings.

Instance migration checks selected environment services before downtime. Its mail check uses the admitted [development mail endpoint](/reference/development-containers#development-mail), so the preflight and mail setup share one eligibility decision.

Route site publication batches certificate, link, account-copy, trust and Caddy file work in one Node module. It uses the Node’s existing Python and Caddy prerequisites; see [Route publication](/reference/routes). Instance create shares admitted input with its site child, which retains a separate scratch folder and cleanup.

A [migrated Schedule](/reference/schedules#container-timers) uses the same existing SSH account and Incus access to read its container journal and timer state. Its local timer runs only while the container runs, without a Node trigger or a new credential.

Caddy publication validates a candidate before it replaces the live file, including the bootstrap Swamp front. Validation failure keeps the live bytes and skips reload; see [Caddy publication](/reference/caddy-configuration#native-publication).

Explicit Instance Node service targets use the existing additive bridge admission path, without a role converge or helper reconciliation. Their own ACL rules and root-owned loopback proxies are applied in the container launch before application Processes. Shared translated tuples retain the database/mail host-reconciliation policy.
