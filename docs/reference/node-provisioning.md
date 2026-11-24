---
title: "Node provisioning"
description: "How node:add enrolls or converges a Node, how roles share and lock a Node, how the Orbit CLI and footprint reach it, and how node:remove hands it back."
covers:
  - swamp/{ansible/**,extensions/behaviors/operations/ansible-input/**,manifest.yaml,extensions/behaviors/operations/{fleet/{cli_converge_local,cli_install,release,release_local}.ts,node-enrollment-plan/**,node-removal-command/**},workflows/workflow-{operation-node-add,operation-node-remove,operation-node-role-relocate,node-role-remove,node-caddy-package,node-private-dns,node-cli-install,fleet-release-node}.yaml}
  - apps/gateway/app/{Domain/Nodes/{NodeNetworkReservation,NodeRemovalInput,NativeNodeRoleRemovalInput,NativeNodeRoleRelocateInput,NodeRoleRelocateInput,NodeRoleRelocateProjection,NativeNodeEnrollmentInput,NativeNodeEnrollmentProjection,NodeEnrollmentPolicy,NodeRemovalResources,NodeRemovalProjection,NodeRoleRemovalProjection,direct-operations}.php,Actions/{Gateway/BootstrapGatewayAction.php,Nodes/{ProvisionNodeAction,EnrollMacOsNodeAction,RemoveNodeAction,AddNodeRoleAction,AssignRoleAction,AdmitNodeRoleOperationAction,AdmitNodeEnrollmentOperationAction}.php}}
  - apps/gateway/app/Infrastructure/{WireGuard/NativeGatewayPeerProjectionManager.php,Nodes/{Roles/{CiRunnerRoleBaseline,NativeNodeRoleFirewallManager},CiRunnerHostProgram,NativeNodeConverger,MacOsNodeConverger,NodeSshJump,NodeBootstrapCommandFactory,NodeBootstrapPackageCatalog,RemotePhpPackageManager,NodeLocks,NodeLock}.php}
  - apps/gateway/app/{Console/Commands/{ProvisionNodeCommand,BootstrapPlanCommand,BootstrapControlPlanCommand,RoleAddCommand,NodeAddCommand,NodeConvergeCommand},Domain/Gateway/{GatewayBootstrapPlan,GatewayControlPlan}}.php
  - apps/gateway/{database/migrations/{2026_12_03_000000_allow_cold_recipe_image_rebuilds,2027_02_08_000000_add_node_untouched_to_node_roles_table,2027_03_01_000000_add_ci_hold_digest_to_nodes,2027_03_01_000001_add_ci_nested_hosting_to_nodes,2027_03_04_000000_add_converge_digests_to_nodes,2027_03_11_000000_add_converge_digests_full_to_nodes}.php,app/{Infrastructure/MachineSoftware/NodeRecipe*.php,Domain/Nodes/RoleRegistry.php,Models/NodeRole.php}}
  - apps/gateway/app/Infrastructure/{Swamp/SwampSshExecutionInput,Firewall/NodeFirewallRuleCatalog,Ssh/NativeSshExecutor,Ssh/SshConnection,Ssh/SshHostKeyScanner,Ssh/HostKeyScanner}.php
  - apps/gateway/app/{Infrastructure/Nodes/*Cli*.php,Domain/Fleet/NodeFootprint*.php,Domain/Fleet/CliConverge*.php,Infrastructure/Fleet/Footprint/**,Http/Controllers/Api/NodeFootprintsController.php}
  - apps/agent/src/commands/{ci_runner.rs,recipe.rs,recipe/**,php_build.rs,php_build/**}
---

# Node provisioning

`orbit node:add <name> [host]` enrolls a machine into the fleet or re-converges an existing Node after changing its TLD, roles, or settings. `orbit node:remove <node>` removes the Node from Orbit while leaving it reachable over public SSH. See [`node`](/cli/node) for all command flags.

Standalone `node.converge`, `node.ssh_access_converge`, `node.agent_rekey`, `node.add`, `node.remove`, and role workflows execute on the Swamp Node's workers over pinned SSH using the Swamp control key (`/etc/orbit/swamp-role-worker/ssh-identity`). No target Node needs its own Swamp worker.

### Per-Node Swamp worker

Workflows for enrolling or rotating per-Node Swamp workers have been removed. All migrated Node and fleet operations run on the Swamp Node's workers over pinned SSH using `labels: {role: swamp}`.

## Add a Node

Enrolling a new Node requires a public SSH host and an approved host key fingerprint (`--host-key-fingerprint`); omitting it returns `node.ssh_host_fingerprint_required`. An `app-dev` Node requires a TLD or an active Cluster TLD.

For an existing Node that already hosts Instances, `NodeEnrollmentPolicy` refuses re-enrollment with `node.has_instances` (HTTP 409), with one exception: a TLD-only change is permitted when the Node holds an active `app-dev` role.

Admission records a `provisioning` Node, freezes its public resource plan, writes the new observer secret into the execution vault, and starts `operation-node-add` on the Swamp Node:

| Stage | Work |
| --- | --- |
| Trust | Scans the public SSH host key, verifies the approved fingerprint, reads platform and architecture (`uname -sm`), and binds the identity |
| Baseline | Authorizes SSH keys, installs base packages, sets up the managed user and sudo rule, applies [kernel](#kernel-policy) and [update](#update-policy) policies, and enables UFW |
| Network | Generates or verifies the WireGuard key, writes tunnel config, and adds the peer on the VPN hub |
| Observer | Fetches the pinned `orbit-agent` binary, writes config and mode-`0600` secret, and starts `orbit-agent.service` |
| Roles and CLI | Runs frozen role workflows in assignment order and installs the [Orbit CLI](#orbit-cli) on eligible Nodes |
| DNS and check | Configures dnsmasq and private DNS for a VPN hub, then verifies the agent, secret, and WireGuard handshake |

Every Ansible phase is idempotent, so a failed run retains completed work and a rerun skips finished steps. On success, the Gateway records the observed SSH identity, architecture, WireGuard public key, and observer secret hash.

### Bootstrap identity

Authorize the public key from `ORBIT_SWAMP_SSH_PUBLIC_KEY` (matching `/etc/orbit/swamp-role-worker/ssh-identity` on the Swamp Node) for root before running `node:add`. If `ORBIT_SWAMP_SSH_PUBLIC_KEY` is missing or invalid, admission fails with `swamp.ssh_identity_unavailable` (HTTP 503).

New Nodes default to bootstrap user `root` and managed user `orbit` (`--user` and `--orbit-user` override them). Existing Nodes default to their recorded managed user through passwordless sudo.

### Machine architecture

Linux Nodes support `x86_64` only; any other reported or requested Linux architecture refuses with `node.architecture_unsupported` (HTTP 422). Apple silicon macOS Nodes (`arm64`) are supported under [macOS Nodes](#macos-nodes).

### Failure codes

Admission and enrollment return typed error codes naming the failing check or step:

| Code | HTTP | Meaning |
| --- | --- | --- |
| `node.invalid_linux_user` | 422 | Bootstrap or managed user name is invalid |
| `node.user_change_unsupported` | 409 | Attempted to change the managed user on a Node with roles or an enrolled Mac |
| `node.platform_unsupported` | 422 | Platform or operation is unsupported (such as roles on macOS or iPadOS) |
| `node.platform_mismatch` | 409 | Observed platform differs from the requested platform |
| `node.macos_account_required` / `node.macos_account_mismatch` | 422 | macOS enrollment omitted the account or gave different `user` and `orbit_user` |
| `node.account_unavailable` | 502 | SSH as the existing macOS account failed |
| `node.wireguard_required` | 409 | macOS enrollment lacks a WireGuard address or disagrees with the stored address |
| `node.tld_required` / `node.tld_invalid` | 422 | Missing or invalid TLD for an `app-dev` Node |
| `node.tld_taken` | 409 | Requested TLD is already used by another Node or Cluster |
| `cluster.not_found` / `cluster.membership_conflict` | 404 / 409 | Named Cluster does not exist or conflicts with Node membership |
| `node.has_instances` | 409 | Existing Node hosts Instances (except a TLD-only change on an active `app-dev` Node) |
| `node.role_conflict` | 422 | Requested roles conflict with each other or existing assignments |
| `node.ssh_host_fingerprint_required` | 422 | New Node omitted `--host-key-fingerprint` |
| `node.ssh_host_key_scan_failed` | 502 | Host key scan failed |
| `node.ssh_host_key_mismatch` / `node.ssh_host_key_changed` | 409 | Host key differs from the approved or stored fingerprint |
| `swamp.ssh_identity_unavailable` / `swamp.ssh_target_unavailable` | 503 | Swamp SSH control identity or target is missing or invalid |
| `node.bootstrap_failed` / `node.orbit_ssh_failed` | 502 | Bootstrap failed or managed SSH failed afterward |
| `node.architecture_unavailable` / `node.architecture_mismatch` / `node.architecture_unsupported` | 502 / 409 / 422 | Architecture check failed, disagreed, or is unsupported |
| `node.role_convergence_failed` / `node.agent_install_failed` | 502 | Role convergence or agent installation failed |

## CI runner role

`orbit node:role:add <node> ci-runner` prepares a Linux Node to host GitHub Actions runners in unprivileged Incus containers. It is neither a singleton nor relocatable, cannot be passed to `node:add --role`, and conflicts only with `gateway` and `vpn`.

The role installs Incus and configures the restricted `orbit-ci` project, `orbitci0` bridge, `orbit-ci-egress` ACL (public IPv4 egress only; blocks private, CGNAT, loopback, link-local, multicast, reserved IPv4, and all IPv6), and default runner profile (6 CPUs, 12 GiB RAM, 8,192 processes, unprivileged, no nesting, `/opt/orbit-runtime` mounted read-only). Runners use a dedicated `orbit-ci` ZFS or btrfs storage pool marked `user.orbit.ci-runner=true` beside `orbit-instances`.

Each CI Node has a pool of [CI places](/reference/node-settings#ci-places) set via `orbit node:update <node> --ci-places=N` (with optional `--ci-proof-only`). [`tools/ci-runners/setup.sh`](https://github.com/hardimpactdev/orbit/blob/main/tools/ci-runners/setup.sh) creates, registers, moves (`setup.sh move NAME`), and verifies runner containers with the shared labels `self-hosted,Linux,X64,hardimpact,orbit-shard`. The Gateway never stores organization registration tokens.

Before removing `ci-runner`, deregister and delete all runner containers; role removal refuses any non-empty `orbit-ci` project even with `--force`. `--purge-data` also removes the empty project, bridge, and ACL after cached images and custom volumes are removed.

### Runner state for the runner view

The `ci-runner` role installs `/usr/local/libexec/orbit-ci-runner-state`, `orbit-ci-runner-state.service`, and `orbit-ci-runner-state.timer` (every 15 seconds). The script inspects `orbit-ci` containers read-only and atomically writes Prometheus textfile metrics to `/var/lib/prometheus/node-exporter/orbit_ci_runners.prom`:

| Series | Labels | Value |
| --- | --- | --- |
| `orbit_ci_runner_info` | `runner`, `state`, `service`, `version` | `1` |
| `orbit_ci_runner_busy` | `runner` | `1` while `Runner.Worker` runs, else `0` |
| `orbit_ci_runners_observed_timestamp_seconds` | none | Unix observation timestamp |
| `orbit_ci_runners_read_ok` | none | `1` if Incus answered, else `0` |

Role removal stops the timer and deletes the script, unit files, and `.prom` file.

### Proof copies on a CI Node

`ProofCopyHosts` classifies every Node with `ci-runner` into three rules for [proof](/cli/proof/run) copies (each copy consumes one CI place):

| Classification | Requirements | Behavior |
| --- | --- | --- |
| `ciOnly` | `ci-runner`, `ci_places >= 1`, no `app-dev` | `ci-runner` converge prepares the Instance container branch with nested-fleet hosting; refuses ordinary Instances (`instance.node_ci_only` 409) |
| `offersPlaces` | `ci-runner` and `ci_places >= 1` (with or without `app-dev`) | Holds a proof seed and can host proof copies |
| `takesCopies` | `offersPlaces`, current `nodes.ci_hold_digest`, and nested-fleet hosting enabled (`nodes.ci_nested_hosting` or `app-dev` switch) | Eligible for `proof:run` placement |

On a `ciOnly` Node, `ci-runner` converge runs the same Incus container preparation tasks as `app-dev` (creating `orbit-instances` on the host's single ZFS zpool if absent, checking `orbitci0` non-overlap, and recording `nodes.ci_nested_hosting`), without Caddy or Routes.

#### The seed listener

Each Node offering proof places (other than the proof default's own Node) receives its [seed](/reference/development-containers#the-seed-on-each-ci-node) via `incus copy --refresh --mode=push`. Its `ci-runner` converge configures:

| Setting | Value |
| --- | --- |
| Incus listener | `core.https_address` set to `<WireGuard IP>:8444` |
| Client trust | Restricted certificate `orbit-proof-seed` (from root on the proof default's Node) scoped to `orbit-instances-nested` |
| Firewall | Top-priority UFW rules on `orbit`: `orbit:proof-seed` allowing the default Node's WireGuard IP, followed by `orbit:proof-seed-deny` denying all others |

The Gateway records the trusted sender in `nodes.seed_sender_id` after a passed `ci-runner` add or converge, and clears it on failure, removal, or when no proof default exists.

#### Proof places on a CI Node

`ci-runner` converge installs `/usr/local/libexec/orbit-ci-runner-hold` and records its SHA-256 in `nodes.ci_hold_digest`. Proof copy create and destroy call it over SSH under a per-Node lock (`/var/lib/orbit/ci-hold`):

| Call | When | Effect |
| --- | --- | --- |
| `hold <copy> <places>` | Before starting a proof copy's inner fleet | Records the hold and stops idle runner services (no `Runner.Worker`) down to `places - holds` |
| `release <copy>` | After destroying a proof copy | Removes the hold; when no holds remain, restarts the exact runners it stopped |

Busy runners finish their jobs untouched. If the hold script is missing, copy creation fails with `instance.ci_hold_unavailable`.

## macOS Nodes

macOS Nodes are managed for Homebrew formulae, casks, and Vite+ global packages without service roles. Enroll with `orbit node:add <name> <host> --platform=macos --wireguard-ip=<existing-address> --host-key-fingerprint=<fp> --user=<acct> --orbit-user=<acct>` using an existing account, existing WireGuard IP, and authorized SSH key. Omitting `--wireguard-ip` on a new macOS Node (or sending an address that differs from an existing peer's stored IP) returns HTTP `409` `node.wireguard_required`.

Enrollment pins the host key, verifies platform, `uname -m` architecture (`arm64` stays `arm64`), account, and `ifconfig` WireGuard address, and makes no OS, account, tunnel, DNS, or firewall changes. All Linux-only mutations (roles, Processes, Schedules, Metrics, Node agent, firewall, DNS repair) refuse macOS with HTTP 422 before SSH. Removing a macOS Node revokes managed SSH access, removes the hub peer and Gateway row, and retains user data, Homebrew, Vite+, and host WireGuard config (`retained_on_node`).

## Nodes without roles

Running `node:add` without `--role` enrolls a Linux operator or utility Node. It installs base packages, the managed account, UFW with `orbit:public-ssh-recovery` and `orbit:wireguard-members`, a `DNS = <vpn-ip>` line in `/etc/wireguard/orbit.conf` (keeping public SSH open and system DNS untouched), and the [Node agent](/reference/node-agent). Grant API access with [`node:access:add`](/cli/node/access-add). Adding the first role later closes public SSH and switches to managed DNS.

## iPadOS peers

iPadOS devices join WireGuard as unmanaged peers with no SSH host key, no roles, and no agent. Set `--platform=ipados` via [`node:update`](/cli/node/update) on an unmanaged, roleless Node. Operations that rely on SSH refuse `ipados` peers before contacting the device; `node:wireguard-key`, `node:access:add`, and `node:remove` (retaining `host-wireguard`) work normally.

## Orbit CLI

Only Nodes with an active `ci-runner` assignment carry the packaged Orbit CLI. `fleet.cli_converge` (`POST /api/v1/fleet/cli/converge`), `fleet.rollout`, and enrollment run `node-cli-install` from the Swamp Node over SSH:

| Item | Path or value |
| --- | --- |
| Binary | `/usr/local/bin/orbit-<version>` (`root:root`, `0755`), SHA-256 verified |
| Symlink | `/usr/local/bin/orbit` pointing to the active version |
| Profile | `/root/.orbit/config.json` (`root:root`, `0600`) pointing to `https://<gateway-wg-ip>` with `/etc/orbit/agent/ca.pem` |

Existing newer releases or commit builds are kept unless you run `orbit node:converge NODE --base --full`. Nodes without CLI intent have managed CLI files removed during base convergence. Error codes: `cli.release_unavailable`, `cli.architecture_unsupported`, `cli.install_failed`, and `cli.configuration_failed`.

## Converge the Orbit footprint

`orbit node:converge <node> [--base | --roles=a,b] [--full] [--idempotency-key=KEY]` repairs one active managed Linux Node against the current release without re-enrolling it or changing Instances or role assignments. `--base` limits work to Orbit's base layer; `--roles=a,b` limits work to base plus those assigned roles; `--full` bypasses [phase digest skipping](#skip-a-phase-whose-input-did-not-change).

Admission locks the Node (sharing the lock with enrollment, role operations, and agent rekey; competing requests get `operation.target_busy` 409) and freezes intent. Each machine phase runs one tagged Ansible playbook from the Swamp Node:

| Phase | Work |
| --- | --- |
| `input` | Stages private inventory, variables, pinned `known_hosts`, and phase digests |
| `agent` | Reconciles `orbit-agent`, executor (on `app-dev`, `vpn`, `gateway`), CLI state, [role units](#role-units-at-a-converge), [kernel policy](#kernel-policy), and [update policy](#update-policy) without rotating the agent secret |
| `software` | Installs and holds recipe packages from `recipe.json` (plus pinned Deno on the Swamp Node) |
| `roles` | Repairs assigned role configuration (including `/etc/tmpfiles.d/orbit.conf` and container host setup on `app-dev`) |
| `tools` | Runs the Deno Tool helper and applies recorded Tools (individual Tool failures do not fail the parent Operation) |
| `network` | Applies VPN hub and peer WireGuard configuration and verifies the handshake |
| `check` | Updates container presented machine names and reads back software, units, role config, and tunnel state |

Failed phases stop later phases and retain completed changes without rollback; fix the cause and start a new converge.

### Skip a phase whose input did not change

Both `node.converge` and `node:role:add` (with or without `--converge`) compute a digest (SHA-256) for each skippable phase from its normalized variables, secrets, and reachable Ansible and Tool files:

| Operation | Phases that can skip | Phases that always run |
| --- | --- | --- |
| `node.converge` | `agent`, `software`, `roles`, `tools`, `network` | `input`, `check` |
| `node.role_add`, `node.role_converge` | `prerequisites`, `tools`, `node`, `firewall` | input, OS check, Ansible, Analytics, Swamp, certificates, PHP pools, `publication`, private DNS, Metrics, `check` |

A succeeded full `node:converge` or role Operation records its phase digests in `nodes.converge_digests` (with `nodes.converge_digests_full` distinguishing full converges). When a subsequent run's phase digest matches the recorded digest, Swamp skips that phase (`skipped` in steps, `unchanged` in `result.phases`) without connecting to the Node.

Phases run in full whenever the digest changes, no record exists (first run, after failure, or after another locked Node Operation), the Node has a failed agent pin, a T3 restart was deferred, or `--full` is passed. Because `check` always runs, detected drift fails the converge and clears the record so the next run repairs it. Pass `--full` to repair drift outside `check`.

### Check many files in one read

To avoid extra SSH round trips, phases batch read-only checks into a single call on the target host and pass only drifted items to repair tasks:

- `software` reads all package holds in one `dpkg --get-selections` call.
- `network` uses `tasks/managed-files-check.yml` to compare file types, modes, ownership, and SHA-256 digests in one read per file group.
- `check` uses `tasks/check-read.yml` to batch file stats, `systemctl is-active` checks, and version commands (`dpkg-query`, JS, browser, Deno) into one call per section.

### Role units at a converge

Every converge (including `--base`) writes systemd unit files owned by the Node's roles (`orbit-swamp-worker@.service` for `swamp`, `orbit-websocket.service` for `websocket`) during the `agent` phase and reloads systemd if changed, without restarting running services.

### Kernel policy

Both `node:add` (`baseline`) and `node:converge` (`agent`) write two kernel files (`0644`) on every managed Linux Node:

| File | Content | Purpose |
| --- | --- | --- |
| `/etc/modprobe.d/orbit-no-sctp.conf` | `install sctp /bin/false` | Prevents loading the unused SCTP kernel module |
| `/etc/sysctl.d/60-orbit-nonlocal-bind.conf` | `net.ipv4.ip_nonlocal_bind=1` | Lets Docker bind explicit WireGuard/LAN addresses at boot before interfaces come up |

### Update policy

Both `node:add` (`baseline`) and `node:converge` (`agent`) apply the Ubuntu security update policy ([ADR 0253](/decisions/0253-patch-nodes-and-reboot-them-drained)):

| File | Content |
| --- | --- |
| `/etc/apt/apt.conf.d/20auto-upgrades` | Daily package list update and `unattended-upgrades` |
| `/etc/apt/apt.conf.d/52orbit-unattended-upgrades` | Security origin only, `Automatic-Reboot "false"`, unused kernel cleanup, 300 s `DPkg::Lock::Timeout` |
| `/etc/needrestart/conf.d/50-orbit.conf` | Auto-restarts services except protected Orbit/runtime units |
| `/etc/apt/apt.conf.d/53orbit-reboot-due` and `/usr/local/lib/orbit/reboot-due` | Marks `/var/run/reboot-required` when a protected unit runs an outdated library |

Protected units (`orbit-swamp-*`, `orbit-gateway-*`, `orbit-process-*`, `orbit-agent`, `docker`, `containerd`, `incus*`, `wg-quick@*`) wait for a drained [fleet reboot](/reference/gateway-recovery#fleet-reboot). Nested fleet Nodes keep these files with daily apt timers disabled.

### Shutdown never waits

Long-draining units (Gateway Horizon, Swamp workers with 75-minute drain, Swamp serve with 6-minute drain) include a shared `ExecStop` guard (`ShutdownFastStop`). When `systemctl is-system-running` reports `stopping`, the guard kills the unit immediately so host shutdown never stalls on dead WireGuard or SSH links; normal restarts still drain fully.

### Break-glass recovery for Services and Swamp 209

If Swamp serve or its control workers on the Swamp Node are down, restore their systemd units and credential files directly over SSH or the provider console. Never copy or log credential files, and inspect any open Operation before re-admitting work.

## Gateway bootstrap prerequisites

Preparing a fresh Gateway uses the local `gateway-bootstrap` workflow (`swamp/workflows/workflow-gateway-bootstrap.yaml`) and `swamp/ansible/gateway-bootstrap.yml` with plans printed by `php artisan orbit:bootstrap-plan` and `php artisan orbit:bootstrap-control-plan`:

| Job | Work |
| --- | --- |
| `app` | Installs packages (PHP, Composer, Caddy, `jq`), writes `.env`, installs Composer dependencies, creates SQLite and `APP_KEY`, runs migrations, and runs `orbit:bootstrap` (`--operator-ip` registers an `operator` Node) |
| `control` | Installs Swamp serve and three workers (`a`, `b`, `c`), mints local tokens, creates `orbit-control-inputs`, issues `swamp.orbit` and Gateway certificates, starts the WireGuard hub, configures dnsmasq and firewall rules, and starts Horizon and the scheduler |

Internal bootstrap commands `orbit:role-add`, `orbit:node-add`, `orbit:node-converge`, and `orbit:node-converge-digest --base` let a newly bootstrapped Gateway admit its own roles and converges locally.

## Agent role

`orbit node:role:add <node> agent` marks a Linux or macOS Node that hosts a Conn Server entry point: the host-level server where General threads start and orchestrate work across other Orbit Nodes. That server registers itself as the Node's host entry (one with no `instance_id`) in [`GET /api/v1/conn/environments`](/reference/conn#registration). Each entry of the Node shows `agent` in `roles`, and the host entry of the fleet's primary agent Node shows `is_primary_agent: true`. Removing the role drops `agent` from those entries; the registration itself stays.

The role is only a Gateway record. Adding or removing it records the assignment and a succeeded Operation without SSH or a Swamp run, so a Node needs no managed access for it. A converge and a release leave it out, and a converge scoped to it is refused (`validation.failed`). Orbit does not install, start, or check the Conn Server; the Node's operator runs it. Mark the Node that should start General threads with [`node:update --primary-agent=true`](/cli/node/update#record-fields) when the fleet has more than one `agent` Node.


Conflicting roles cannot share a Node (`validation.failed` on `node:role:add`, `node.role_conflict` HTTP 422 on `node:add` and `node:role:relocate`):

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

`router`, `metrics`, `websocket`, `swamp`, and `agent` share a Node with any role. Role admission entry points vary by role:

| Role | `node:add --role` | `node:role:add` |
| --- | --- | --- |
| `app-dev`, `app-prod`, `database`, `metrics`, `websocket` | Yes | Yes |
| `gateway` | Singleton | Singleton |
| `vpn` | Singleton | No |
| `ingress`, `analytics`, `ci-runner` | No | Yes |
| `swamp` | No | Singleton |
| `router` | No | No (use [`cluster:router:set`](/cli/cluster/router-set)) |
| `agent` | No | Yes |

## Package sources

Roles install Ubuntu archive packages (running `apt update` only when `dpkg-query` reports a package missing), except PHP and Caddy:

| Package | Source | Details |
| --- | --- | --- |
| PHP | Pinned Sury `.deb` files from Orbit's [recipe artifact mirror](/reference/compute-drivers#the-recipe-artifact-mirror) (or Sury fallback) | Cached in `/var/cache/orbit/php-packages`; SHA-256 verified; removes legacy apt sources |
| Caddy | Pinned `amd64` `.deb` from GitHub releases (minimum 2.9.0) | SHA-512 verified; skips upgrade when already at or above floor; removes legacy Cloudsmith sources |

### Role prerequisites

`node:role:add` and `node:role:add --converge` run through one Swamp workflow (`node.role_add` / `node.role_converge`) on the Swamp Node. They verify Ubuntu release compatibility, install role packages (retaining healthy Docker CE installations instead of replacing them with `docker.io`), install recipe JavaScript runtimes (Node, Vite+, pnpm, Bun) and Composer homes on app roles, and install Caddy on `gateway`, `router`, `ingress`, `app-dev`, `app-prod`, `websocket`, `analytics`, and `swamp`.

The `swamp` role installs the pinned `/usr/local/bin/swamp` symlink under `/usr/local/lib/orbit-swamp/<version>/` without overwriting a running binary in place. It runs `orbit-swamp-serve.service` and three workers (`orbit-swamp-worker@a`, `@b`, `@c`) using operator-placed tokens under `/etc/orbit/swamp-role-worker/<a|b|c>/`. It also installs `orbit-swamp-worker-renewal.service` to rotate workers before Swamp's 8-hour WebSocket cap, plus hourly `orbit-swamp-housekeeping.timer` for journal, apt, and Git GC maintenance.

On `app-dev` Nodes, role convergence also prepares the [Instance container host](/reference/development-containers#prepare-a-node) (Incus `orbit-instances` ZFS pool, `orbitinst0`, egress proxy, images, `/usr/local/libexec/orbit-instance-power` helper, and optional `orbit-instances-nested` project when nested-fleet hosting is enabled).

### Swamp token renewal

Revoking, rotating or expiring a Swamp access token ends every WebSocket session that uses it, so a live consumer's token is never rotated. On the Swamp Node, `orbit-swamp-token-renewal.timer` runs `orbit-swamp-token-renewal.service` ten minutes after boot and daily after that. The swamp role renders both units in `SwampRoleUnits` and installs the playbook `/usr/local/lib/orbit-swamp/token-renewal.yml` with its module `orbit_swamp_token_renewal`, beside the worker renewal. A Gateway release starts the service too, before it loads Swamp. The renewal renews only a token that expires within ten days:

| Token | Consumer | Renewal |
| --- | --- | --- |
| `serve-driver` (`user:nckrtl`) | `~/.config/swamp/serve-driver-token`: serve, the release load, the worker renewal and the credential monitor | Mint `serve-driver-<YYYYMMDD>` and verify a list with the candidate file. Then rename it over the file atomically as `0600` for `orbit`. Revoke a predecessor once the file is at least 24 hours old. |
| `swamp-role-209-<slot>-access` (`worker:orbit-node-209`) | `/etc/orbit/swamp-role-worker/<slot>/server.token` | Mint `swamp-role-209-<slot>-access-<YYYYMMDD>` with the same principal and verify it as described below. Rename the file atomically as `root:orbit` `0640`. Revoke a predecessor once the worker restarted, as described below. |
| `swamp-role-209-<slot>` enrollment | `/etc/orbit/swamp-role-worker/<slot>/enrollment.token` | `swamp worker token rotate` once through serve, then write the new secret from `swamp worker token reveal` as `root`, `root:orbit` `0640`, since the worker cannot write it back. The old secret stays valid to its own expiry. |

The renewal works from live facts, not from a record of what it did. A token family is `<base>` or `<base>-<suffix>`. The current generation is the name before the first `.` of its consumer file. A predecessor is another active token of the same family and principal that expires earlier. Every run finds the predecessors again from `swamp access token list`, so a crash at any step loses none.

A new generation is named after the UTC renewal day. When that name is already active, a rerun reveals it again and mints nothing, so a lost mint reply never makes a second generation. When that name is revoked or unusable, the next name takes a `-2` suffix. A refused candidate leaves its consumer file untouched. Older `<base>-<UTC stamp>` names belong to their family.

Before the rename of a worker's `server.token`, `swamp access token list` must show the candidate active, with the same principal and an expiry more than 20 days away. A worker principal may not be able to read, so this list is the proof.

A pinned worker reads `server.token` only when `worker connect` starts; a reconnect inside the same process keeps the old authorization. The renewal therefore compares `ExecMainStartTimestampMonotonic` of `orbit-swamp-worker@<slot>` with the file's change time. The worker renewal restarts the oldest worker after three hours, and each worker within eight hours, so the old generation goes within a day. The renewal never restarts serve or a worker. Every mint, reveal and revoke goes through serve with the serve-driver token file.

A role add and a Node converge of the swamp role both start and enable the timer after writing its units, and fail unless `systemctl is-active` and `is-enabled` pass; only a role add starts the worker renewal timer, after all three workers.

The only state is the root-owned `/var/lib/orbit-swamp-token-renewal/state.json` (`0600`). It marks an enrollment rotation before it starts, so a rerun after a crash writes the new secret and never rotates twice, which would retire the secret the connected worker holds. The run reports token names only. A failure quotes the CLI's stderr with anything shaped like a credential redacted, and never its stdout.

On the Swamp Node a `swamp` call from a non-interactive shell (systemd, SSH, a workflow step) fails with `auth_gate_blocked` unless the serve account's Swamp Club credential env, `~/.config/swamp/env.sh`, is sourced first. The swamp role installs `/usr/local/lib/orbit-swamp/swamp-env`, which sources that file with all output discarded and then runs the command. The token renewal, the Gateway token entrypoint and the release's Load step run every `swamp` call through it. When the file is missing or cannot be sourced, it exits 78 with `swamp.credential_env_missing` or `swamp.credential_env_invalid` and the file path, never its content.

Converge the swamp role on the Swamp Node before the first Gateway release that carries the renewal. A release that reaches a Node without `/usr/local/lib/orbit-swamp/swamp-env` still loads: it prints `gateway.release_swamp_env_unconverged` and sources `env.sh` itself. Without `/usr/local/lib/orbit-swamp/gateway-token`, or when it cannot run `swamp-env`, the release, Reconcile and revoke steps print `gateway.swamp_credentials_skipped` and skip the token renewal and the three-day gate. The hourly `gateway:swamp-credentials` still fails with `swamp.gateway_token_entrypoint_missing`, naming the missing file, until the converge.

`orbit-node-*` worker tokens and `orbit-worker-issuer` are out of scope: no placement targets a per-Node worker, and nothing uses the issuer. The Gateway's own tokens renew from the Gateway ([Renew the Swamp read and run tokens](/reference/gateway-environment#renew-the-swamp-read-and-run-tokens)); it starts this service through `/usr/local/lib/orbit-swamp/gateway-token renew-node` over its managed SSH.

### Caddy reloads

Caddy installation writes `/etc/sysctl.d/60-orbit-caddy.conf` (`root:root`, `0644`) with `net.ipv4.tcp_migrate_req = 1` so the Linux kernel migrates queued connections across socket reloads instead of resetting them. The PHP SDK also retries `GET` and `HEAD` requests once after 250 ms on empty replies.

## One lifecycle owner per Node name

`node:add`, `node:remove`, and `node:update` hold a per-Node-name lock (renames lock both names) for the full lifecycle operation. Concurrent requests fail with `node.provisioning_busy` (HTTP 409). Abandoned locks expire after 1 hour.

## Role operations on one Node

`node:role:add`, role convergence, and `node:role:remove` claim the Node's Operation lock (`node:<id>`); competing admissions fail with `operation.target_busy` (HTTP 409) until the run settles. Stale `provisioning` or `removing` claims older than 11 minutes without an active Operation can be taken over.

Each role assignment tracks `node_roles.node_untouched`: if an initial `node:role:add` fails at the OS check before changing the Node, `node:role:remove` deletes the record directly without contacting the Node.

### Role Ansible phases

`node:role:add` and role convergence run one Swamp step per Ansible phase (`prerequisites`, `node`, `publication`, `firewall`, `exporters`, `prometheus`, `check`) from `/opt/orbit/ansible` on the Swamp Node. Tool Manager setup, certificate signing, Analytics initialization, and Swamp bootstrap run as dedicated native steps.

### Native role relocation

`node:role:relocate` moves `gateway`, `websocket`, `metrics`, or a Cluster's `ingress` in one native workflow (`operation-node-role-relocate`, see the [relocate chart](/cli/node/role-relocate#how-it-works)). Relocating `swamp` is refused with `node.role_relocate_swamp_held` (HTTP 422).

Admission enforces destructive-change and Ingress placement guards before mutating state:

- Destructive relocations require `--force` (otherwise refused with `destructive_consent_required`).
- For `ingress`, admission enforces `source_serves_public_route`, `target_is_router`, and `target_serves_public_route`, and holds every Node in the Cluster against Route writes (`route.publication_held`) for the full run.

The workflow prepares the target, probes the Router hop and public HTTPS certificates (for `ingress`), publishes certificates, Caddyfiles, and private DNS, waits the DNS grace period, and runs `node-role-remove` on the source. If an `ingress` move fails before `Publish the role DNS`, the assignment returns to the source (`source_kept: true`). Relocation does not reconcile Metrics; if a `metrics` role exists, run `orbit node:role:add <metrics-node> metrics --converge` afterward.

## SSH connections

The Gateway multiplexes remote commands over one shared OpenSSH connection per Node (`ORBIT_HOME/ssh/mux`, mode `0700`, 60-second idle close, 10-second dead-peer detection via `ServerAliveInterval=5` and `ServerAliveCountMax=2`). Only PHP-FPM workers open shared masters; Horizon jobs, oneshot timers, reachability probes, and `scp` transfers open direct connections so systemd cgroup cleanup never severs shared masters.

## Public SSH

Bootstrap opens `orbit:public-ssh-recovery` and enables UFW, then adds `orbit:wireguard-members` once the tunnel answers. Adding the first active role removes `orbit:public-ssh-recovery` so the Node is reachable only over WireGuard. Removing the last role restores `orbit:public-ssh-recovery` before deleting the assignment (`node.firewall_recovery_failed` if restoration fails).

## WireGuard hub

The `vpn` Node holds the WireGuard hub configuration (`ORBIT_HOME/generated/wireguard/orbit.conf`) and one peer file per Node ([Converge the hub](/reference/wireguard-endpoints#converge-the-hub)). [`node:wireguard-key`](/cli/node/wireguard-key) updates an unmanaged peer's key and re-projects the hub. Hub projection validates the candidate with `wg-quick strip` and live-syncs running interfaces with `wg syncconf` without dropping active tunnels.

## Remove a Node

`orbit node:remove <node> [--offline] [--force]` admits one `node.remove` Operation on the Swamp Node. Remove all Instances, Database servers, Schedules, Routes, and user firewall rules first. The caller, `gateway`, `vpn`, and `swamp` Nodes are protected from removal.

Pass `--force` to shed mutable roles; pass `--offline --force` to remove an unreachable Node's Gateway records and hub peer while leaving the machine untouched (listing retained artifacts in `retained_on_node`). Online removal sheds roles via `node-role-remove`, restores public SSH, removes `orbit-agent` and management SSH keys, withdraws the WireGuard hub peer, and deletes the Gateway record only after native completion confirms `removed: true`.

## Why it works this way

### Removal retains user data

Online removal withdraws Orbit roles, agents, and management keys while preserving user data, package managers, and unrelated SSH keys. Machine destruction remains the hosting provider's responsibility.

### The hub stays on the vpn Node

When `gateway` and `vpn` run on separate machines, writing hub config locally on the Gateway would overwrite its peer tunnel and sever fleet access. Hub writes always target the `vpn` Node explicitly.

### Caddy from its GitHub release, not its apt source

Installing Caddy from a GitHub `.deb` verified by SHA-512 avoids outages in third-party apt repositories that would break every `apt-get update` across the fleet. Skipping upgrades once Caddy meets the version floor prevents unnecessary reloads.

### A Mac needs no service role

macOS Nodes are enrolled for developer tool management (`brew`, `vp`) and Doctor disk/platform checks, not Linux service hosting. Reusing the existing user account, WireGuard tunnel, and package managers avoids duplicating or disrupting local macOS state.

### CI roles own hosts; operators own runner registration

Separating host preparation (`ci-runner` role) from GitHub organization runner registration (`tools/ci-runners/setup.sh`) keeps organization registration tokens off the Gateway while giving CI Nodes standard firewall, metrics, Doctor, and rollout coverage.
