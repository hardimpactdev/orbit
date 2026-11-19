---
title: "Instance containers"
description: "ADR 0250, partly built: every Instance runs in an Incus container cloned from the Project's default Instance, with T3 inside. A user drives its T3 in development, Swamp drives it for a task. A Project setting turns on a nested fleet."
covers:
  - apps/gateway/app/{Console/Commands/SyncKnownHostsCommand,Infrastructure/Ssh/KnownHostsStore,Infrastructure/Ssh/KnownHostsRepository,Models/ProofSeed,Domain/Proof/ProofSeeds,Models/InstanceRuntime,Actions/Nodes/AllocateInstanceBridgeAction,Domain/Nodes/NodeBridges,Console/Commands/RefreshNestedFleetsCommand,Console/Commands/InstanceIdMapSizeCommand,Console/Commands/InstanceInnerVolumeCommand,Domain/Tasks/TaskTurn,Domain/Tasks/TaskTurnProjection}.php
  - apps/gateway/app/Domain/Instances/{InstanceRuntimePowerState,ContainerNodes,InstanceHostIntent,InstanceImage,InstanceFolderPlan,InstanceNotes,InstanceAgentInstructions,InstanceContainer,InstanceEgress,DevelopmentMail,DevelopmentInstanceCreateProjection,ClonePublication,NestedFleetIntent,NestedFleetRefresh,IsolatedIdRanges,InstanceOperationAlerts,InstanceModelRoute}.php
  - apps/gateway/app/Domain/{InstanceMigration/**,Instances/Environment/DevelopmentCreateEnvironment.php,Instances/Deployment/{ContainerDeployOutcome,DevelopmentDeploySteps}.php}
  - apps/gateway/database/migrations/2027_02_{04_000000_add_seed_commit_to_instance_runtimes,04_000002_add_operation_owner_to_instance_runtimes,05_000001_clear_completed_container_default_projections,05_000002_clear_completed_container_default_projections_by_route_target,25_000000_create_proof_seeds,26_000000_add_ci_bridge_to_nodes,26_000001_release_an_unused_instance_bridge,27_000000_add_seed_sender_id_to_nodes,28_000000_allow_a_proof_copy_clone_on_its_seed_node}.php
  - swamp/{ansible/{instance-container.yml,instance-container-destroy.yml,instance_node_runtime.py,instance_fleet_shrink.py,instance_fleet_runtime.py,tasks/instance-fleet-runtime.yml,instance_migrate_git.py,tasks/instance-node-runtime.yml,tasks/instance-container-phase.yml,tasks/instance-container-run-phase.yml},scripts/instance_clone{,_guest}.py,ansible/instance_{bridge,network,node_services}.py,extensions/behaviors/operations/instance-dev-command/result.ts,ansible/task-copy-turn.yml,workflows/workflow-operation-task-copy-turn.yaml,extensions/behaviors/operations/task-copy-turn/*}
  - apps/gateway/{config/instances.php,database/migrations/{2027_02_03_000000_create_instance_runtimes,2027_02_11_000001_add_workspace_baseline_to_instance_runtimes,2027_02_12_000000_add_idle_watch_started_at_to_instance_runtimes,2027_02_15_000000_add_t3_port_last_to_nodes,2027_02_16_000000_add_idmap_size_to_instance_runtimes,2027_02_16_000001_add_id_ranges_to_nodes}.php,resources/instances/{notes/**,frankenphp.Caddyfile,workspace-git-read.py,id-map-read.py},app/Infrastructure/Instances/{ContainerWorkspaceRead,NodeIdMapRead}.php}
  - swamp/ansible/tasks/{instance-host,instance-folder,instance-image,instance-egress,instance-network-check,instance-network-nic-check,instance-network-move,instance-network-move-nic,instance-bridge-rules,instance-bridge-refresh,instance-mail}.yml
  - swamp/ansible/t3_copy_rpc.mjs
---

# Instance containers

Every new development Instance is a container. Orbit [refuses new host registration](#container-only-admission) and keeps production clone as the sole exception until production containers ship. Existing checkout Instances and their migration remain supported. Task groups and proofs use container copies. [ADR 0250](/decisions/0250-run-instances-and-task-groups-in-incus-containers#remaining-acceptance) owns the remaining transition and acceptance.

| Part | Delivered state |
| --- | --- |
| Records, Node preparation, egress and DNS | Built |
| Default create and deploy, clone, forced non-default destroy, checkout migration | Built for app-dev Nodes; no build list |
| [Nested fleet](#nested-fleet) | Built on switched-on Nodes: placement, host preparation, start guards, the [image](#the-nested-fleet-image), the [refresh](#refresh-after-a-release), and the [clone's](#a-clone-starts-its-fleet) and [stopped clone's](#a-stopped-nested-fleet-clone-starts) fleet start, and a copy's [reset](#reset). Planned: rebuild |
| [Sleep and wake](#sleep-and-wake) | Built: the commands, the wake on a Route visit, and the idle sleep, which is off until the operator turns it on. Planned: the wake from Conn and tasks |
| Task copies and command proofs on their nested fleet | Built; merge-base proof replay is [not ported](/reference/old-paths#the-task-vm-proof-run). No VM runtime remains for a parity comparison |
| Production containers | [Planned](/reference/production-containers) |
| Dedicated workspace volumes and explicit package workspace links | [Draft specification](#workspace-volumes-draft); not implemented or proved |

An Instance on the container path runs in one unprivileged Incus system container on its `app-dev` Node. The container holds the Instance's checkout, its app and Processes, and, except in the default Instance, a T3 server with Claude Code and Codex. It has no WireGuard identity, no roles and no Node record. Orbit reaches it from its Node with `incus`, and users reach it through its Route and its T3 server.

Every non-default Instance on this path is a ZFS clone of the Project's default Instance on the Node, and its database is a clone of the default Instance's database. The default Instance is the only one built from scratch, and it deploys each new commit of the default branch [by itself](/reference/deployments#automatic-development-defaults), so clones start at the current default branch with its dependencies installed. A nested-fleet default deploys after each release instead. The default Instance never runs T3, so each clone starts T3 for the first time and gets its own T3 identity.

A task runs in such a container too. There is no separate task container: the same container has two [drivers](#drivers), a user in Conn and Swamp for a task. A Project may turn on a [nested fleet](#nested-fleet), which Orbit needs for its command proofs.

## Charts

The container flows keep the existing Instance kinds. Their charts sit one folder below the command charts. Only production clone admits a new host checkout; task VM admission is removed.

| Flow | Chart |
| --- | --- |
| Create an Instance: a clone of the default Instance | [container/create.mmd](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/create.mmd) |
| Create a Project's first default Instance | [container/create-default.mmd](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/create-default.mmd) |
| Deploy a merge to the default Instance | [container/deploy-default.mmd](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/deploy-default.mmd) |
| Destroy an Instance | [container/destroy.mmd](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/destroy.mmd) |
| Put an idle Instance to sleep | [instance/sleep.mmd](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/sleep.mmd) |
| Wake an Instance | [instance/wake.mmd](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/wake.mmd) |
| Refresh a nested-fleet default after a release | [container/fleet-refresh.mmd](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/fleet-refresh.mmd) |
| Renew the T3 server's session | [conn/environment-renew.mmd](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/conn/environment-renew.mmd) |
| Start a thread in an Orbit Project | [container/thread.mmd](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/thread.mmd) |
| Move a checkout Instance into a container | [migrate.mmd](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/migrate.mmd) |

## Nodes

Container host preparation applies to every `app-dev` Node with the Incus pool `orbit-instances`. Task VMs are removed.

The Gateway reads no pool, so it counts an `app-dev` Node as an Instance container host when one of these holds:

- The operator set its [Instance host opt-in](/reference/node-settings#instance-host-opt-in), `instance_host`. A new host needs it before its first converge.
- It is the `app-dev` Node of a nested Instance fleet, whose converge creates a btrfs pool.
- It records an Instance runtime.
- Its agent reports the Instance bridge's health, which only a converged container branch writes.

Any other `app-dev` Node, such as a services Node without Incus, is no container host. Its converge runs its other roles and no container branch. The Node gets no Instance bridge and no `orbit:instances-*` firewall rules, so Doctor expects none. Its admission releases an Instance bridge that no container uses. A bridge that a container uses always stays, and so does the release bridge of an `app-prod` Node.

The `app-dev` role installs the container setup: the Incus project `orbit-instances` on the Node's pool, the bridge `orbitinst0`, the container image, the [shared folders](#shared-folders), the [egress proxy](#egress-and-dns), the forwarder of the [model route](#model-access) and the firewall rules. It stops installing PHP-FPM and checkout folders once the Node has no checkout Instance. A copy is a clone, never a file copy: the pool of a real Node is ZFS, and the nested proof Node `nested-app-dev` has a btrfs pool on its btrfs root.

A [CI-only Node](/reference/node-provisioning#proof-copies-on-a-ci-node) gets the same container setup through its `ci-runner` role, with nested-fleet hosting on. It never gets the `app-dev` role. It holds no default Instance: it holds a [seed of the proof default](#the-seed-on-each-ci-node), and it takes only proof copies.

### Prepare a Node

Every `app-dev` role converge of an Instance container host runs the container branch. The `ci-runner` converge of a CI Node that hosts proof copies runs the same branch from the same intent, with its nested part; [ADR 0260](/decisions/0260-share-ci-places-between-runners-and-proof-copies) owns that placement. [Prepare an Instance container host](/reference/compute-drivers#prepare-an-instance-container-host) has the steps.

- **Allocation.** The admission of a role add or converge allocates the Node's bridge. The captured intent only reads it.
- **Pool.** The Node provides the Incus storage pool `orbit-instances`: ZFS, or btrfs on the nested proof Node. The converge chooses no disk itself.
- **Pool of a CI-only Node.** Its `ci-runner` converge creates the pool on a new dataset of the zpool that its ZFS Incus pool uses ([Proof copies on a CI Node](/reference/node-provisioning#proof-copies-on-a-ci-node)).
- **Missing pool.** A Node without the pool is refused with `instance.host_unavailable`.
- **Project.** Containers of `orbit-instances` must set an isolated ID map. They may mount host folders and have proxy devices, and cannot be VMs. The profile mounts the two shared folders.
- **Host paths.** The project sets no `restricted.devices.disk.paths`. Incus refuses `shift` on a disk under that list, and the shared folders need the idmapped mount.
- **Host path guard.** The create flow's device list is the only guard on host paths. A later step can restore the project limit with custom volumes that set `security.shifted`.
The converge sets up the network side next:

- **Profile.** The default profile sets the [proxy settings](#egress-and-dns) as `environment.*` keys, so every `incus exec` that keeps its environment has them.
- **Bridge.** `orbitinst0` has NAT and no IPv6. DHCP leaves the last address free for the image build.
- **Egress ACL.** Bridge and NIC egress defaults stay reject. The bridge admits the Node’s target union. Each NIC admits only its own targets and the proxies. A packet must pass both [ACLs](#network).
- **Input rules.** The Node admits DHCP from the bridge under `orbit:instances-dhcp`, and the egress proxy under `orbit:instances-egress`. It admits no DNS from the bridge.

The converge then installs what the containers run:

- **Egress proxy.** It runs the [egress proxy](#egress-and-dns) and its nftables table.
- **Image.** The converge builds the [container image](#inside-a-container) once per definition and names it with the stable alias `orbit-instance`.
- **Folders.** It installs both [shared folders](#shared-folders).

The bridge keeps default egress reject throughout convergence. Its `orbitinst0-egress` ACL admits the two proxies and one exact host/port per distinct attached target on the Node, with the bridge subnet as source. For a local Docker publication, the bridge rule names its exact live DNAT destination; the NIC still names its published address. Missing or ambiguous DNAT mappings refuse with `instance.container_endpoint_unresolved` before the migration probe or downtime.

The host profile, image builder and Instance NIC writers take the common ACL name from Gateway intent. The profile and NICs use `orbitinst0-proxy`, which admits only the proxies and the existing ingress paths, plus each Instance's own target ACL. Convergence replaces an old common NIC reference in one device update, retaining its reject defaults. It audits every effective NIC on this bridge in every Incus project, including stopped containers. An unsafe NIC fails the role; the bridge stays rejecting.

Create, clone and migrate add their endpoint rules before probing. These lifecycle steps only add rules. Destroy, restore and detach leave them in place, so a concurrent removal cannot revoke another Instance's path. Host convergence removes owned endpoint rules absent from its captured Gateway intent only when their admission predates that capture.

Until then, an unguarded NIC can reach current and old translated endpoint tuples. Reuse of an old Docker address can expose a different service at that exact tuple. This permission lasts until host convergence removes it. Guarded NICs still require their own exact allow. ACL writes reread after an ETag conflict or a transient disappearing host-side veth, with at most five attempts and a one-second delay; exhaustion fails the phase.

The host persists Gateway endpoint records, with immutable names formed from the published tuple and admission time. Admission and refresh stage each endpoint’s last exact translated tuple outside the active inventory. They commit its record only after the ACL allow is confirmed.

All destructive record operations use the fixed state helper, which constructs filenames from state kinds and validated endpoint identities; callers cannot supply a destination filename.

A timed-out GET or ambiguous PUT gets one readback under the same lock; a confirmed rule commits its staged record, and an absent rule does not. An unreadable result reports `instance.bridge_state_unresolved`; refresh preserves that warning until a successful full convergence.

The internal `--withdraw --reconcile` writer mode retains persisted translated tuples without resolving stopped Docker publications. Readers deduplicate each tuple by its latest admission. Host convergence and explicit writer withdrawal compact or remove records, retaining admissions at or after their captured time. A systemd oneshot refreshes resolved endpoint rules at boot after Docker and Incus, then every 15 seconds. It only adds rules. An unresolved or duplicate publication leaves its saved allow and record unchanged while other publications still refresh.

The records are Node-wide: every container on the bridge shares them, so a destroy removes none. A confirmed record removes the older records of its host and port, so the Node keeps one record per endpoint. The newest record always stays, also when an attach admitted earlier runs later. The shared admission itself goes only at host convergence or an explicit writer withdrawal.

Each confirmed endpoint removal also removes its obsolete refresh records under the same writer lock, including confirmations from bounded readback after a timeout. Retained shared endpoints and admissions at or after the reconciliation capture stay eligible. A Docker record without a saved translated tuple refuses reconciliation before any ACL mutation. Partial reconciliation persists `reconcile.pending`: ordinary refresh cannot clear its failure health, and a refresh after a crash reports `instance.bridge_state_unresolved`. A subsequent successful full reconciliation removes that marker and clears the warning.

Gateway admission times are captured before reading targets. Owned ACL rules carry the same time in a strictly parsed description. Reattaching a tuple advances its rule’s stamp. Convergence preserves records and rules admitted at or after its capture, including admissions that arrive while it runs. Conditional ETag writes recheck this fence before deletion. An unknown description is never removed.

Node convergence and the container phase stage the bridge helper beside its installed path, with owner `root:root` and mode `0755`. They compute the expected SHA-256 from that same shipped source file and verify the staged copy before an atomic rename. This detects transfer or staging corruption and truncation; it cannot detect a wrong source. A running refresh reads a complete file.

A checksum mismatch removes the candidate and preserves the installed helper (`instance.bridge_helper_checksum_mismatch`). Symlinks at the installed path or candidate path refuse with `instance.bridge_helper_target_symlink` or `instance.bridge_helper_candidate_symlink`. An unchanged helper needs no write. In Ansible check mode, a missing or old helper reports a planned change without writing; a current helper reports no change. A target symlink still refuses.

The timer recovers missed Docker events and DNAT rules installed after a start event. Admission, refresh, host convergence and withdrawal hold one exclusive lock at `/var/lib/orbit/instance-bridge/writer.lock` before reading records, through the last record and ACL write. A concurrent refresh refuses immediately; a new invocation reads the remaining records, so it cannot restore a removed endpoint.

Lock acquisition makes one nonblocking attempt. An owned lock returns `instance.bridge_busy` immediately, without changing endpoint records, ACL rules or health. Retry the operation after the current writer finishes. Docker and NAT reads use the helper’s remaining budget: 30 seconds for refresh and 300 seconds for lifecycle work. Each Incus curl request keeps its five-second limit, with one request reserved for readback. A read timeout or curl exit 28 returns `instance.bridge_command_timeout` and releases the lock.

Failure recording uses the held lock; a caller that cannot acquire it reports the error without waiting again. An unchanged run leaves active records and health unchanged and sends no Incus write request. A Docker re-address heals after the next successful refresh; a host converge also removes the obsolete resolved tuple.

An empty Docker NAT snapshot changes no ACL or endpoint record. A failed NAT read also preserves them. These snapshots cannot judge publications during a Docker restart. Duplicate matching rows leave that publication unchanged, even when every row names the same target. A restart on the same address has no additional ACL outage: its allow is never withdrawn. Stale records and their rules remain until the separate removal design lands or an authoritative host convergence removes them.

Unresolved publications appear in `health.json` as `warning_count` and at most 20 `warnings` entries, each with `code=instance.container_endpoint_unresolved`, `host` and `port`. The document stays below the agent's 4,096-byte limit. A successful partial refresh exits zero; a write failure still fails. The agent currently reads only `error_code`, so warnings give no doctor issue until the separate agent change.

Refresh errors stay in the journal. A writer that acquired the lock also records its error in `/var/lib/orbit/instance-bridge/health.json`; a busy refusal leaves that file unchanged. The agent reads only its named public error code into its `host` fact, on the existing 60-second observation interval. Doctor reports `node.instance_bridge_unhealthy`, including that error code, and clears it after a healthy observation. The host role checks the enabled, running timer and healthy state file at its end.

The converge creates no Instance. Each existing `app-dev` Node needs one converge of its Instance host role. It reconciles the attached-target union and moves existing NICs to the proxy ACL. Running containers keep working, with no restart.

An app-dev Node gets the Instance project `orbit-instances`, bridge `orbitinst0`, pool `orbit-instances` and `orbit:instances-*` input rules. Its CI project and places stay separate. The Instance host setup applies the Node's ZFS ARC budget.

Before it changes anything, the converge refuses a bridge range that another interface of the Node holds, with `instance.host_unavailable`. The Gateway only knows the bridges it records, not one an operator made, such as a CI bridge.

The Gateway gives each Node a bridge network, a `/24` of the fleet range `10.253.0.0/16`, and records it on the Node. It takes the first `/24` that overlaps no recorded bridge and not the VPN subnet. The recorded bridges include each Node's CI bridge `orbitci0`, whose subnet Incus chose when the `ci-runner` role made it. Orbit never moves a CI bridge.

Each `ci-runner` add and converge reads the subnet of `orbitci0` (`incus network get orbitci0 ipv4.address`), and the Gateway records it on the Node as `ci_bridge`. The node phase reads it twice: before the Instance container branch, so a refused clash is still recorded, and after the CI resources, so a new `orbitci0` is recorded in the same run.

The admission of the first converge allocates before any read. When that bridge overlaps the CI bridge, the branch refuses with `instance.host_unavailable` before it changes anything. The Gateway records the CI bridge from that run and gives back the Node's Instance bridge, when no container uses it yet. The next `ci-runner` converge then allocates a `/24` around the CI bridge. A Node keeps its bridge while a container uses it. When no `/24` is free, the allocation fails with `node.instance_bridge_exhausted` (409). A `task_vms` key in a Node's stored settings reserved a range once; the migration that removed the key ended that ([old paths](/reference/old-paths#task-vm-settings)).

T3 servers are published on ports 3800 to 3999 of the Node's WireGuard address. The Node records the last T3 port it gave. A new runtime takes the first free port after it and wraps from 3999 to 3800, so a port freed by a destroy is given again only after the Node has used every other free port. A client that saved the destroyed server under its address therefore does not meet a new server there at once. When every port is held, the reservation fails with `instance.host_unavailable` (409).

### Container-only admission

Every new development Instance is a container. There is no `instances.container_nodes` setting or `ORBIT_INSTANCES_CONTAINER_NODES` opt-in. Create the Project's container `default` first, then clone it on its Node. A missing default refuses with `instance.default_missing`. If the default is a host checkout, Orbit refuses to create another Instance with `instance.container_required` (409); migrate the default first. An unprepared Node refuses rather than creating a host checkout.

New [`instance:register`](/cli/instance/register) admissions refuse with `instance.container_required` (409) before reading checkout facts or creating records. Retries of existing registered checkouts remain available. Existing checkout Instances keep their operations and [`instance:migrate`](/cli/instance/migrate); create retries do not convert them. Their PHP-FPM pools stay until a live inventory permits retirement.

[`instance:clone`](/cli/instance/clone) into production is the sole new-checkout exception until [production containers](/reference/production-containers) ship. It accepts an existing checkout or the Project’s active, running container default with a recorded seed as the candidate, and requires an app-prod destination. The exception belongs to production clone admission, not a general host-runtime switch.

The Node must meet the host requirements of ADR 0250: current kernel, SCTP off and no secrets. A Node that still has checkout Instances keeps them until [migration](#migrate-a-checkout-instance) moves them into containers. The `app-prod` role follows the same model in step 10 ([Production Instances](#production-instances)).

## Inside a container

Every Instance container, default or clone, has 4 CPUs, 4,096 processes and a 40 GiB root disk on the `orbit-instances` ZFS pool. A nested-fleet default has a [larger root disk](#the-root-disk). It has 8 GiB of memory, or 12 GiB in the [nested-fleet project](#nested-fleet).

Clone phases stage their release's helpers and intent once in a private folder for the run. Later phases run them by path. This keeps scripts off the SSH command and avoids its size limit. The repository credential stays on stdin. Cleanup removes the folder on success or failure, including a failed first launch.

The clone copy supplies a complete root disk device, including its type, path, pool and, except on a [btrfs pool](#the-root-disk), its size. This works when the seed inherits its root disk from a profile or stores it locally.

Every development container has the same layout. The image and the create set it up. Root owns everything below except the homes and the workspace. A container installs as little as possible: the image holds only OS packages, the users, `agent`'s sudo grant and the firewall.

| Part | Setting |
| --- | --- |
| Image | Ubuntu 26.04 with the OS packages the runtime needs, the users, `nftables`, `acl`, `sudo` and `hidepid`. No PHP, no Node, no agent software |
| Egress | The [proxy settings](#egress-and-dns) for every unit, login shell and apt, and no DNS server |
| `www-data` | The stock account, uid and gid 33, home `/var/www` mode 0750, password locked. Runs the app and every Orbit Process. Owns no part of the workspace that Orbit keeps ([ADR 0268](/decisions/0268-one-owner-for-the-workspace)) |
| `agent` | uid 2750, home `/home/agent` mode 0700, password locked, [sudo](#sudo-and-secrets). Runs T3, the agents, Orbit's checkout Git calls and deploy steps. Owns the workspace, T3 state, agent settings and placeholder key |
| Group `www-data` | The stock group, gid 33, with `agent` as a member. [The app user's name](#the-app-users-name) says which old accounts go |
| Sudo | Passwordless for `agent` alone, in every container, from the [shared file](#existing-containers) `/etc/sudoers.d/orbit-agent` (root:root, mode 0440). `www-data` has none. See [Sudo and secrets](#sudo-and-secrets) |
| Workspace | `/srv/workspace` and every entry in it, owned `agent:www-data`. Directories are setgid `www-data`, mode 2770, with the default ACL `g:www-data:rwX` and no access for others, and `core.sharedRepository=group`. Root's [pass](#who-runs-what) gives `agent` what `www-data` made |
| Git | `safe.directory=/srv/workspace` in `/etc/gitconfig`, for every user and only that path; the agent part of the T3 step also writes it to `agent`'s own Git config and preserves its other Git settings |
| Shared folders | `/opt/orbit-runtime` and `/opt/orbit-agent`, the Node's folders, mounted read-only and idmapped; unshifted on an [Instance fleet Node](#shared-folders) |
| T3 unit | `User=agent`, `UMask=0007`, `PATH` from the shared folders, `Requires=nftables.service`, `ConditionPathExists=/etc/orbit/instance`. The unit file is mode 0640. A drop-in names the [container server](#container-server) it starts |
| `PATH` | The runtime folder's `bin` first, for units, login shells and `incus exec` sessions, so `composer` is on it |
| `php` | `/usr/local/bin/php` links to the Project's static `php` in the runtime folder. A Project without a PHP version has none. The create and each start set it |
| Instance file | `/etc/orbit/instance`, root-owned and readable by all: Instance, Project id, slug and quoted one-line name, Node and URL. Absent on the default, keeping T3 stopped |
| Model route | `nftables` in the container lets only uid 2750 reach the forwarder, and only uid 0 and 2750 reach T3's port. `agent` can change the rule through sudo; the container wall stays the boundary |
| Processes | `/proc` with `hidepid=invisible`, so each user sees only its own processes |
| Kernel surface | Unprivileged, no nesting, `user.max_user_namespaces=0`, an isolated ID map |

A container mounts no host checkout and no Incus socket. Root work, such as units, goes through Orbit from the Node.

### Sudo and secrets

`agent` has passwordless sudo in every container, normal and nested. The grant is one line, `agent ALL=(ALL:ALL) NOPASSWD: ALL`. The image removes the stock image's grant and its `ubuntu` user, so this grant is the only sudo path. A container holds only dev-scoped keys, never production-grade secrets. [ADR 0268](/decisions/0268-one-owner-for-the-workspace#amendment-sudo-for-agent-in-every-container) holds the security model.

The Gateway renders the image: its packages, the setup script, its files and the units it enables. The script turns cloud-init off, so a new container never makes the stock `ubuntu` user and its sudo grant again. The image's alias is `orbit-instance-` with the first 12 hex digits of a digest of that definition, so a changed definition is a new image. A build container from the stock Ubuntu image runs the definition and is published under that alias. The stable alias `orbit-instance` then moves to it, and prior images remain for separately authorized operator cleanup; a container keeps what it was made from.

A Node with nested-fleet hosting on also builds the [nested-fleet image](#the-nested-fleet-image); the normal image does not change.

Host preparation publishes the stock Ubuntu container as an independent local image with no update source. It verifies the daemon's identity before checking image archives, promotes the stock alias only after verification, and refuses prior leftovers for operator review. It retains prior stock and prepared images for separately authorized operator cleanup. Prepared-image publication clears the stock-build statement. See [Stock-image publication and explicit adoption](/solutions/incus-shared-image-refresh).

The build container reaches the internet as every container does, through the [egress proxy](#egress-and-dns): the profile gives its `incus exec` the proxy settings, and apt follows them. It takes the bridge's last address, which no Instance gets.

### Who runs what

The app runs as `www-data`: FrankenPHP, queue workers and every Orbit Process. `www-data` cannot run the agent binaries, read `/home/agent`, reach the model route or T3's port, or see agent processes. A public preview URL reaches only an Orbit Process, so an attacker who comes in through it lands as `www-data`.

T3 and the agents run as `agent`, and so do the shell commands of Claude Code and Codex. There is no wrapper shell.

`agent` owns the workspace ([ADR 0268](/decisions/0268-one-owner-for-the-workspace)). Orbit's own work in it runs as `agent` too, with `HOME=/home/agent`. That is the checkout's Git calls, the line that keeps `.env` out of Git and the record of the deploy steps. It is also each development deploy step of a deployment, a create and a proof run. A deploy step runs as uid 2750, so it can reach the model forwarder, as the agent can. `www-data` stays off the model route.

Both users read and write the workspace: a file each one writes is `rw-rw----`, group `www-data`. Some calls only a file's owner may make, such as `chmod`, `utime` with explicit times and `setfacl`. So an `www-data` file in the workspace, such as one that the running app wrote in `storage/` or `bootstrap/cache`, stays `www-data`'s only until root's next pass.

A tool that asks for a narrower mode, such as Bun with its 0755 folders in `node_modules`, caps the ACL mask, and the other user cannot write there. Root's pass gives the group back what the owner has, and gives `agent` each entry that `www-data` owns. It runs before a deployment's steps and after them, before a proof run's steps, and in the `services` part of a clone's create. Between those, a folder that one user creates with a narrow mode stays closed to the other until the next pass.

One program does the pass: `swamp/ansible/instance_workspace_owner.py`. It names each entry it changed, up to 20; above that it prints only the count.

The workspace is live while root does this: Git, the app or an agent may remove an entry between its listing and its change. An entry that is gone needs nothing. Any other error fails the pass and names the path.

The pass never follows a link: it changes the mode and the owner through the entry it opened without following one, and it gives a link or a socket itself to `agent`, never its target. A file it gives to `agent` loses its setuid and setgid bits, so `www-data` cannot make a program that runs as `agent`. A file with a second name, a hard link, keeps its owner, because that name may be outside the workspace; it still gets the group's bits.

Orbit's own Git commands in the workspace start no automatic maintenance (`maintenance.auto=false`, `gc.auto=0`): a fetch would otherwise repack in the background and remove pack files while the pass and the deploy steps run. The Git commands of the people and agents who work in the workspace still repack it.

Each deployment's fetch adds one pack, so a deployment's checkout runs its own repack in the foreground, after the checkout and before the pass: `git repack -d --geometric=2`. It merges the small packs into the next larger one until each pack holds at least twice the objects of the packs after it. A deployment's one new pack merges in milliseconds, and the pack count stays near log2 of the history's size. It keeps every object and the group's mode. It has 30 seconds.

A repack that fails or runs out of time does not fail the deployment: the checkout's output names it in one `orbit-workspace-repack=` line, and the next deployment tries again. A repack that ran out of time leaves its temporary files (`tmp_pack_*`, `tmp_idx_*`, `tmp_rev_*`); the next checkout removes them once they are an hour old. A workspace whose repack never fits in 30 seconds pays those seconds at each deployment and prints the line each time.

There is one exception to "a URL reaches only `www-data`". An agent may start its own development server, such as `npm run dev`, as `agent`, and the Instance's Route reaches it on the server's own port. That server can read what `agent` can read. So only a private Route serves it, which only WireGuard peers reach; a public Route never does. [Agent development servers](#agent-development-servers) has the details.

### Agent development servers

An agent's development server answers at the Instance's Route with its own port, such as `https://feature.tool.test:5173`. Any port from 1024 to 65535 works, and the agent picks it. It opens that URL in the browser panel of its client, T3 or Conn, or checks it headless with [agent-browser](#agent-browser).

The way in has two parts on the Node. A Caddyfile cannot bind a range of ports, and a listener per port would fail the Node's whole Caddy reload whenever another program held one. So Orbit binds nothing in the range:

1. **The redirect.** The nftables table `orbit_instance_dev_ports` takes new TCP connections from WireGuard peers to the Node's WireGuard address on ports 1024 to 65535. It also takes a container's connections to the bridge's gateway address.
2. **The listener.** The table sends them to Caddy's development listener, port 444.
3. **Caddy.** A private Route of a clone has a site on port 444, with the Route's name and certificate.
4. **The port.** The site reads the port from the request's `Host`, which keeps the port the client named. It proxies to that port at the container's bridge address.

| Part | What Orbit does |
| --- | --- |
| Range | 1024 to 65535 on the WireGuard address: every port that `agent`, which is not root, can open. The redirect binds nothing, so the size of the range costs nothing |
| Listening ports | The table leaves every port that a program on the Node listens on, at the WireGuard address or at every address. Its socket check finds the program |
| Other addresses | A program that listens only on another address, such as `127.0.0.1`, is not reached from WireGuard anyway. Its port goes to Caddy |
| Orbit's ports | The table always leaves the Node's own ports, also while their program is down. See the list below |

The Node's own ports are the SSH port, the egress proxy 3128, the published T3 ports 3800 to 3999, the model guard and forwarder 8318 and 8319, the WireGuard port, and the ports of the Node's Database servers and Processes. Overlapping ranges merge into one. `/etc/orbit/instance` names them as `ORBIT_DEV_PORTS_TAKEN`, so the agent picks another port.

| Part | What Orbit does |
| --- | --- |
| Docker | The table runs after Docker's own address mapping, so a port that Docker publishes keeps its mapping |
| Listener | 444, below the range, so no agent port and no client port meets it |
| Many clones | Caddy routes by host name, so every clone on the Node has the whole range |
| Upgrades | WebSocket upgrades, such as hot reload, pass through. Caddy keeps the client's `Host`, which Vite checks |
| Nothing listens | A port where nothing listens in the container answers `502`. A `Host` without a port in the range answers `404` |

Who reaches the servers:

- **WireGuard peers.** The table takes connections that come in on the WireGuard interface to its address. The site refuses LAN neighbours and task VMs, as every WireGuard-only site does.
- **The clone itself.** A clone reaches its own servers on the bridge, as [the own site from inside](#the-own-site-from-inside) shows. No other container reaches them.
- **Private Routes only.** A public Route has no development site. Making a Route public takes it away at the next Caddy build.
- **One rule.** The same rule decides the site, the container's ACL and `/etc/orbit/instance`. The ACL and the file are written at the create.
- **The container ACL.** Only the bridge's own address, the Node's Caddy, reaches ports 1024 to 65535 in a clone.
- **Clones with a Route.** The default runs no agent, and a Project without a Route has no domain for them. `/etc/orbit/instance` names `ORBIT_URL` and `ORBIT_DEV_PORTS`.
Which Node answers:

- **The Node of the clone.** The Route's private name answers with the clone's Node, or with the Cluster's Router.
- **No other Router.** Only the clone's Node has the table. A clone behind a Router on another Node gets no development ports.
- **Cluster members on a LAN.** [Private DNS](/reference/private-dns#cluster-router-addresses) gives a Cluster member with a LAN address the Router's LAN address. The table does not take it, so such a member reaches no development port.
- **WireGuard clients.** A WireGuard client outside the Cluster, such as a Mac, gets the WireGuard address and reaches the ports.

The [`vite` Process preset](/reference/processes-and-schedules#the-vite-preset) uses this way in for a Project's own Vite on port 5173. It adds no rule: the same limits apply. A container default, which has no development ports, gets no `vite` Process.

The Node's converge writes the table, and its unit loads it at boot. A Database server or Process added later is left alone by the socket check while it runs; the next converge also adds its port to the ports the Node keeps.

The [environment notes](#environment-notes) tell the agent to bind to `0.0.0.0`, to allow the Instance's host name, and to point hot reload at the URL and port. For Vite, those are `server.host`, `server.allowedHosts` and `server.hmr`.

### The own site from inside

An agent in a clone opens its own Instance by the Route's name with a headless browser, such as `https://feature.tool.test` or Vite's hot URL `https://feature.tool.test:5173`. The path goes through the Node's Caddy, on the Instance bridge's gateway address, such as `10.253.0.1`.

| Part | What Orbit does |
| --- | --- |
| Sites | A private Route of a clone gets two more sites on the gateway address: the Route's site on 443 and its development site on 444 |
| Same handlers | Each has the same name, certificate and handlers as its twin on the WireGuard address |
| Callers | Each of these sites admits only its own clone's bridge address. Caddy aborts every other connection, so no sibling container reaches it |
| Which clones | The same rule as the development site: a clone with a private Route. A default, a clone without a Route and a public Route get none |
| Band | The development band is ports 5173 to 5199 on the gateway address |
| Redirect | The table sends every new TCP connection from the bridge to the gateway address in the band to 444. Its chain has no exit, and it runs before Docker's address mapping |
| Other ports | The table redirects no other port. Mailpit's 1025, the egress proxy, the model forwarder and an attached Node service keep their own ACL rule and their own path |
| One constant | One constant gives the redirect's ports and the ports of a clone's `Orbit own development ports` rule |
| Host firewall | The bridge ACL `orbitinst0-egress` and UFW (`orbit:instances-own-site` and `orbit:instances-own-dev`) admit TCP 443 and 444 in from the bridge to the gateway address. Neither admits the raw range |
| When | The sites come with the Route's Caddy build, as every site does. The table and the host rules come with the Node's converge. Nothing restarts in a container |

The container side comes from the clone's [access plan](#private-names-of-the-access-plan):

| Part | What Orbit does |
| --- | --- |
| Targets | One target per private Route name of the clone: the gateway address, port 443, the Route's name, and the description `Orbit own site` |
| From each target | The hosts line, the `NO_PROXY` entry and the NIC ACL rule. No second list names the Route names |
| Development ports | One more NIC ACL rule, `Orbit own development ports`, to the gateway address on the band |
| Bridge side | No bridge endpoint and no UFW rule per clone: the Node's own-site rows admit both ports at the bridge |
| `env:sync` | It reconciles both rules by description, as it does the websocket app's rule. A Route that turned public loses them |

The create and the clone write these. A container made before them gets them at its next `env:sync`.

Why the band:

- **The preset port.** The [`vite` preset](/reference/processes-and-schedules#the-vite-preset) and the `vp-dev` preset listen on 5173 in a container, so every clone's development server answers there.
- **Room for Vite.** Vite moves to the next free port while 5173 is in use.
- **More ports.** The band leaves 26 more ports for those moves and for a second server.
- **Nothing else there.** No Node publication on every address or on the gateway address may use the band, so the redirect takes no port that Docker serves there.

**The limit.** A development server outside 5173 to 5199 is not reachable by name from inside the container. That includes a default's old Vite port from before its move into a container. From WireGuard, every port from 1024 still works.

**The refusal.** A Docker publication of a Node in the band, on every address or on the gateway address, is refused with `instance.dev_band_reserved`. Both the Process create and update and a [Node service attach](/cli/instance/node-service-attach) refuse it. A publication on the WireGuard address only is allowed, because it never reaches the gateway address. A Mailpit port configured in the band (`instances.mailpit.port`) is refused with the same code, so mail never hides behind the redirect.

### PHP

FrankenPHP serves the app, in classic mode, as an Orbit Process running as `www-data`. The runtime folder holds one static build per PHP version that Orbit supports, and each Instance uses the version that Orbit picks from its `composer.json`, as it does today. A monorepo uses its root Composer requirement without framework classification. Its Caddy listens on port 8080; the Node's Caddy terminates TLS for the Route and proxies to the container's bridge address.

The container's `/etc/orbit/frankenphp.Caddyfile` trusts only the Node's bridge address (`10.253.0.1/32` on the first Node). When that connection sends `X-Forwarded-Proto: https`, it sets PHP's `HTTPS=on`, `REQUEST_SCHEME=https`, `SERVER_PORT=443` and `REMOTE_ADDR` to Caddy's resolved client IP. Other requests keep their native PHP values, even if they send forwarded headers. Stock Laravel needs no application changes: assets, redirects, signed URLs and session cookies follow the Route's HTTPS scheme. Create, clone and migrate install the same rendered configuration.

The `vp-dev` preset sets its development origin from the HTTPS Route, including `/__orbit/vite`, and HMR uses that origin. PHP's scheme translation preserves these development URLs; it changes no Vite upstream or WebSocket routing.

The command line runs a real PHP CLI: a static `php` from the same static build as the Instance's FrankenPHP, with the same PHP version and extensions. The CLI also loads PCOV, from the same build, so Pest TIA and `pest --coverage` work in the container. The FrankenPHP server does not load PCOV: only the CLI SAPI reads [`php-cli.ini`](/reference/php-runtime#pcov-in-the-cli-only). `frankenphp php-cli` uses the CLI SAPI, so it loads PCOV too.

`frankenphp php-cli` behind a `php` shim does not fit yet. It refuses the `-d` options that Composer and test runners pass, and before PHP 8.6 it cannot run `php -i` or PHP without a script. Orbit runs PHP 8.5. The [static PHP build](/reference/compute-drivers#static-php-build) defines the build and its extension list, and the runtime folder's check compares both binaries with it. The shim can replace the CLI binary once FrankenPHP accepts `-d` and Orbit runs PHP 8.6.

## Shared folders

The software that the app and the agents run lives once per Node, in two versioned read-only folders. Every container mounts both with idmapped mounts, so one update reaches every container. Each folder has a `current` link to the version in use, and the recipe pins every version.

| Path | Content | Access |
| --- | --- | --- |
| `/opt/orbit-runtime/<version>/php/<php-version>/bin` | `frankenphp` and `php` of one PHP version, from its [static PHP build](/reference/compute-drivers#static-php-build) once that is published and pinned | Everyone |
| `/opt/orbit-runtime/<version>/php/<php-version>/modules` | `pcov.so` of the same build, which only the PHP CLI loads ([PCOV in the CLI only](/reference/php-runtime#pcov-in-the-cli-only)) | Everyone |
| `/opt/orbit-runtime/<version>/bin` | `composer`, Node, npm, npx, Bun and `bunx`, Deno, `vp`, rg | Everyone |
| `/opt/orbit-runtime/<version>/{lib,fonts}` | The [browser libraries and fonts](#browser-libraries-and-fonts) | Everyone |
| `/opt/orbit-runtime/<version>/php-data-bridge/bridge` | The files of [PHP Data Bridge](https://github.com/nckrtl/php-data-bridge), which [container PHP loads](/reference/php-runtime#container-php). The part has no program | Everyone |
| `/opt/orbit-runtime/vite-plus/<digest>` | The [Vite+ home](/reference/compute-drivers#the-vite-home) that `vp` runs from, linked as `<version>/vite-plus-home` | Everyone |
| `/opt/orbit-agent/<version>/agent/bin` | `t3`, `claude`, `codex`, `conn-server`, `chrome-headless-shell` ([Container server](#container-server)) and `agent-browser` ([agent-browser](#agent-browser)) | `root:2750`, mode 0750; everyone on an [Instance fleet Node](#instance-host-in-the-fleet) |
| `/opt/orbit-agent/<version>/agent/{t3,conn-server,chrome-headless-shell,agent-browser,claude-code,codex}` | The releases | `root:2750`, mode 0750; everyone on an [Instance fleet Node](#instance-host-in-the-fleet) |
| `/opt/orbit-agent/<version>/notes/<type>.md` | The [environment notes](#environment-notes) | Everyone |

The folders live on the Node's root filesystem, at the same paths as in the containers. The recipe pins each part of a folder, in its `instances` object ([recipe schema](/reference/compute-drivers#recipe-schema)). The agent folder holds T3, the Conn Server, the Chrome headless shell, agent-browser, Claude Code and Codex.

The runtime folder holds Composer, Node, Bun, Deno, Vite+, rg, PHP Data Bridge, the static PHP builds and the same pinned Chrome headless shell for [production containers](/reference/production-containers#host-paths), which have no agent folder. The recipe requires the two Chrome parts to be one pin, so a Node downloads it once. Node, Bun, Deno and Vite+ reuse the recipe's own pins.

A folder's version is the first 12 hex digits of a digest of everything in it, the notes included, so any change is a new version. Each program in `bin` links to its path in its part.

The idmapped mount keeps gid 2750 the same in every container, whatever its ID map. The spike proved this with one container; PR 3 proves it with two containers whose ID maps differ. `www-data` sees the folder `agent`, but not what is in it, except on an Instance fleet Node.

**On an Instance fleet Node.** An idmapped mount fails a third level below the host: on `nested-app-dev` Incus tears the shifted mount of `orbit-agent` down at the start of the first container. So the Instance containers there mount both folders with `shift=false` ([Instance host in the fleet](#instance-host-in-the-fleet)). An isolated container then sees every file of a folder as owned by `nobody` and `nogroup`, and only the mode bits for others count.

The runtime folder is mode 755 and 644 throughout, so everyone still reads it. Gid 2750 does not reach the container, so on that Node the agent's programs are open to everyone as well: `agent/` and its releases are mode 755, as the rest of the folder. Each folder plan of that Node's intent carries `shift: false`, so its folder versions differ from a real Node's and the host installs them fresh. A real `app-dev` or CI Node keeps the idmapped mounts and gid 2750.

An update installs the new version beside the old one and flips `current`. It runs with the converge of each Node, so a recipe change reaches the Node with its release refresh. Both `agent` and `www-data` have `/opt/orbit-runtime/current/bin` on `PATH`, so a running clone gets added programs, such as Deno, without recreation.

For the agent folder, Orbit then restarts T3 in each running container, one after another; T3 continues a running turn after its restart. For the runtime folder, Orbit restarts the FrankenPHP Process and the other Processes of each running container: every unit named `orbit-*.service`, so the Process units of a container carry that prefix. A stopped container uses the new versions when it wakes. An old version stays until no process maps a file of it.

A running process runs only what existed when it started. So a new version that only adds files restarts nothing, such as the [browser libraries and fonts](#browser-libraries-and-fonts) or a new program. The converge compares the version in use with the new one and does not follow links. It restarts only when a file, link or mode of the version in use changed or went.

The CLI self-updaters are off: `DISABLE_AUTOUPDATER=1` in the T3 unit, which Claude Code inherits, and `check_for_update_on_startup = false` in the image's Codex configuration. The T3 server has no self-updater; it runs from the read-only folder.

The OS packages of the image change rarely. The Node's patch run of ADR 0250 upgrades them in each running container from the Node; a sleeping container gets the upgrade when it next wakes.

## Container server

A container with a server runs the T3 server, `t3`, or the [Conn Server](https://github.com/nckrtl/conn), `conn`. Every [task copy](/reference/tasks#task-copy) runs the Conn Server, for every Project. A Project chooses the server of its other containers, its dev clones and [migrated checkouts](#migrate-a-checkout-instance), in its [`container_server`](/reference/projects#container-server) field: `t3`, the default, or `conn`. Dev containers keep the Project's choice because some clients still need methods that only T3 serves. The Conn Server serves the same client contract: `/mcp`, `/.well-known/t3/environment`, the HTTP and WebSocket API, and the `t3` CLI subcommands that Orbit calls. Credentials stay the same: `model.env` and Codex's `config.toml` ([Model access](#model-access)). ADR 0258 records the decision.

| Choice | Program in the agent folder |
| --- | --- |
| `t3` | `/opt/orbit-agent/current/agent/bin/t3` |
| `conn` | `/opt/orbit-agent/current/agent/bin/conn-server` |

When Orbit reserves the runtime of a container with a server, the runtime records its server as its own `container_server`: `conn` for a task copy, and the Project's choice for a dev clone or a [migrated checkout](#migrate-a-checkout-instance). The default's runtime records none: the default runs no server. Every later step reads the runtime's record, never the Project's setting. So a changed setting reaches only dev containers that Orbit reserves after the change, and never a task copy. An existing container keeps its server: the two servers store threads and pairings differently, and nothing converts them.

The record names the program that every call inside the container runs: the create's sessions and `project add`, and a task turn's `thread` commands and its RPC helper. The drop-in `/etc/systemd/system/t3.service.d/20-orbit-server.conf` sets the unit's `ExecStart` to the same program, with the image's arguments: `serve --host=0.0.0.0 --port=3773 --no-browser`. A clone gets it before its first start, with [T3's other files](#t3-starts-with-the-copy). A migrated checkout gets it with the agent's files, and its T3 phase reloads systemd before it starts the unit. The Node's converge keeps it and `10-orbit-boot.conf` current in every existing container: see [T3's unit drop-ins](#t3s-unit-drop-ins).

For Conn, the drop-in also sets `CONN_SERVER_BROWSER_PATH` to `/opt/orbit-agent/current/agent/chrome-headless-shell/chrome-headless-shell-linux64/chrome-headless-shell`. This is the unpacked executable in the current agent folder version, beside its support files, rather than its `bin` link. [agent-browser](#agent-browser) opens the same executable. It also sets `T3CODE_SERVER_BROWSER_SANDBOX=0` because the container has no user namespaces. T3 gets neither override.

The unit keeps its name, `t3.service`, and its `PATH`, which holds the runtime folder's `bin`: the Conn Server's SDK host runs on that folder's `node`, Node 24.10 or later. An agent folder update restarts either server with `systemctl try-restart t3.service`. Orbit's other names for the container's server, such as the T3 port and [T3 registration](#t3-registration-and-pairing), cover both.

The recipe pins each program in the agent folder. A choice whose program the recipe does not pin is refused with `project.container_server_unavailable` (409): `project:update` refuses it, and so does each reservation of a container with a server, a task copy's Conn Server included, before any record. The recipe pins Conn Server 0.2.0 as the part `conn-server`, with `bin` `{"conn-server": "conn-server-<version>-linux-x64/conn-server"}`, so every agent folder holds both programs. Its releases come from `nckrtl/conn`: the tag `conn-server-v<version>` holds the asset `conn-server-<version>-linux-x64.tar.gz` and its `.sha256`. The tarball holds the folder `conn-server-<version>-linux-x64/` with the static `conn-server` and its SDK host.

The part `chrome-headless-shell` pins Chrome 157.0.8103.78 from `nckrtl/conn`'s `apps/conn-server/crates/conn-preview/chrome.json`. Its ZIP unpacks with its `.pak`, `.so` and locale files beside the executable. The runtime folder's [browser libraries](#browser-libraries-and-fonts) supply its system libraries. With this path configured, Conn never downloads Chrome; it refuses preview tools with a typed error if the executable is missing or has the wrong version.

#### agent-browser

Every container, with T3 or Conn, gives its agents one headless browser: [agent-browser](https://github.com/vercel-labs/agent-browser) on the agent folder's `chrome-headless-shell`. The Conn Server's preview uses the same executable. So each Node holds one pinned Chrome for both, and no agent downloads a browser.

- **The program.** The part `agent-browser` pins one release binary, `agent-browser-linux-x64`, with its SHA-256. It comes from the tag `v<version>` of `vercel-labs/agent-browser`.
- **The link.** Its `bin` link is `agent-browser`. It is on the `PATH` of T3 and Conn, and so on the `PATH` of their agents.
- **The browser.** `AGENT_BROWSER_EXECUTABLE_PATH` names the same executable as `CONN_SERVER_BROWSER_PATH`. agent-browser accepts any headless Chrome. So the version that Conn needs serves both.
- **Where it is set.** It is set beside `PLAYWRIGHT_BROWSERS_PATH=0`, in the same places ([Playwright browsers](#playwright-browsers)). Those are the deploy steps' environment and a new clone's two boot files.
- **Existing containers.** An existing container gets it only when Orbit clones it again.
- **Who runs it.** Only `agent` runs it, because only gid 2750 opens the agent folder's programs. The Project's own Playwright keeps its own build.

The browser is in the agent folder because it serves the agents and the Conn Server, not the app. The runtime folder holds what the app and its Processes run, for every user. Both folders are one copy per Node, so the choice changes no disk use.

The [environment notes](#environment-notes) tell agents to use `agent-browser` for a headless check on the server, and the browser panel of their client, T3 or Conn, for a check in a real browser.

## Workspace volumes (draft)

**Agreed direction; not implemented or proved.** The delivered workspace remains inside the container root, as the lifecycle sections below describe. This section owns the draft contract for separate workspace volumes. Accepted policies remain unimplemented. Other details are marked as recommendations.

Incus supports custom filesystem volumes and disk hotplug. Ownership under Orbit's isolated ID maps remains unproven. Neither the existing read-only runtime mounts nor two users sharing one container's workspace prove that a volume can be writable across these maps.

### Agreed boundary

The workspace belongs to its Instance. Only an explicit package link gives another Instance access to it.

- Each Instance owns one filesystem workspace volume separate from its container root. A default Instance owns one too.
- Each clone owns a cloned volume. The flow explicitly copies the workspace volume and rebinds its disk before first start. `incus copy` alone is insufficient.
- An app Instance can explicitly link a package Instance's workspace on the same Node. Both Instances can edit it, with live changes visible in both directions.
- Defaults and unrelated Instances remain independent. There are no implicit package links.
- Container roots, homes, T3 state and caches outside linked workspaces stay private. The [shared software folders](#shared-folders) remain read-only.
- Dependencies inside a linked workspace belong to that explicit sharing. No additional writable state is shared.

The owned workspace stays at `/srv/workspace`, owned `agent:www-data`, with directories mode 2770 and default group ACLs. The [existing account values](#inside-a-container) stay: `agent` uid/gid 2750, `www-data` uid 33 and the group `www-data` gid 33. Tools can narrow modes; [Who runs what](#who-runs-what) describes their correction.

Containers keep distinct isolated ID maps. Sharing cannot require privileged containers, identical host ID ranges, world access, new sudo grants or removal of the user boundary. Refuse a mapping that cannot preserve isolation.

No rename to `~/apps` or `~/packages` is agreed. `/srv/packages/<vendor>/<name>` remains a proposal for package mounts, with no approved alias.

### Proposed ownership and references

**Recommendation, pending review.** Keep the Instance as lifecycle owner. Give its workspace an identity independent of container names, paths and storage location. The Gateway records ownership and consumer references. Swamp applies the captured intent and returns its result. No new runtime manager is needed.

| Record | Required meaning |
| --- | --- |
| Owned workspace | Workspace identity, owning Instance id, Node, Incus project, pool and custom filesystem volume name |
| Package link | Consumer Instance id, selected owner workspace identity, mount target and read-write access |
| Seed or backup manifest | Exact root and workspace snapshots, Instance and workspace identity mapping, links and database recovery material |

Clone provenance records the source workspace and exact snapshot. It grants no ongoing source access. A link references the owner's workspace; it does not resolve a branch, slug or whichever Instance is currently called `default`.

An Instance owns exactly one workspace, and a volume has exactly one owner. Multiple consumers can reference it without becoming co-owners. Reverse references include admitted but unfinished links, so a pending attach cannot race owner removal. A package's own links do not mount transitively in consumers.

Reject self-links, duplicate or overlapping mount targets, targets over `/srv/workspace`, and targets that hide existing files. Resolve names from recorded package identity and reject ambiguity. A path is never the ownership key.

A link exposes the whole workspace according to file permissions, including `.git`, dependencies and readable `.env` files. It filters no source files. It creates no database grants, network access or package-manager configuration. Composer or Node resolves the mounted path through the consuming Project's own files.

### Clone links and proposed seeds

**Agreed policy; not implemented.** Clones NEVER inherit package links automatically. The caller explicitly selects each link. Selecting the same owner deliberately keeps its live files; selecting a package clone uses that clone's independent volume.

Cloning an app neither recursively clones its package graph nor retargets existing consumers. A copied package-manager path authorizes no mount and no fallback to another package.

**Recommended mechanics, pending review.** Prepare root and workspace together:

1. Capture one seed identity with the default's root and owned workspace snapshots. Stop writers for the entire capture interval.
2. Reserve the clone's workspace identity and explicitly copy the admitted volume snapshot. Copy the matching container root without starting it.
3. Remove inherited workspace and package devices, including devices from profiles. Bind `/srv/workspace` to the clone's own volume and add only explicitly admitted links.
4. Verify effective devices and ownership before any app, agent or T3 starts. Recheck on resume, then continue existing clone preparation and identity rules.

Never combine newer workspace data with an older root just because their Git commit matches. SQLite comes with the workspace; server databases still use the [database family](#clone-the-database). Retain the seed pair until both parts are copied or the clone is abandoned.

A failed clone stays stopped with its owned resources recorded. Resume reuses a verified complete destination volume and never overwrites possible edits by cloning again. Unknown binding or completion state requires recovery before start. An inherited source binding must never be used temporarily.

Explicit abandonment deletes only the failed Instance's container and volume after checking references. It never deletes a source or linked owner's data. A lost result cannot make an unverified copy ready. Later source edits leave an independent clone unchanged.

### Writable links and proposed mount path

**Agreed policy; not implemented or proved.** An explicitly linked package workspace is writable from BOTH the owning package Instance and consuming app Instance. Both `agent` and `www-data` can create, edit, delete and rename files through `www-data`. Changes are live and visible in both directions and to every other explicit consumer.

The link permits edits to the selected package, including files outside the app's own checkout. Neither another attachment nor consumer writes may break owner or other explicit consumer access. Preserve numeric guest ownership, default ACLs and group permissions across distinct isolated maps. This support remains UNPROVEN.

Keep `/srv/workspace` owned `agent:www-data`, mode 2770. A file belongs to its creating user under the group policy. Do not relabel every file as `agent` or recursively shift owners for each mount.

Recommend `/srv/packages/<vendor>/<name>` outside the consumer's own workspace. It needs an unambiguous package naming rule. Aliases and renaming home directories stay outside this slice.

Admission requires an explicit owner, authorization to its workspace and the same Node as the consumer. Refuse cross-node links before changing records or devices. Do not substitute a network filesystem, sync process, copy or implicit move.

A link survives owner sleep because the volume supplies its files. Edits appear through every explicit link. A mount does not promise that a language runtime or watcher reloads them, or that an already open file observes a replacement.

**Agreed unlink policy; not implemented.** Unlink explicitly names the consumer and owner workspace. It removes only that link. It never deletes package files, removes another owner or rebinds to another Instance. Removal lists blocking links and refuses until the caller explicitly unlinks them. This draft assigns no command syntax.

**Recommended mechanics, pending review.** Attach succeeds only when the persistent device and running consumer's mount match the admitted owner and read-write access. A stopped consumer receives the same check before start. Detach removes the device and mount before releasing the reference. Repeating the requested state changes nothing.

Successful detach prevents new path lookups. An open descriptor can retain an inode after unmount, so hotplug alone does not promise revocation. Recommend refusing busy detach without lazy unmount. Explicitly stop affected processes or the consumer, then detach again.

A failed attach or detach retains its pending reference and actual-device evidence until resume reconciles both sides. Report failure instead of claiming success. Remount and restart must preserve numeric ownership, default ACLs, group permissions and read-write access. Check mutations in both directions again without breaking other consumers.

### Backup, migration and removal

**Agreed policy; not implemented.** Back up and restore each linked component together offline. Refuse individual linked moves and referenced-owner deletion, including force. Require explicit unlink before removal. Never unlink implicitly or remove another owner. These policies must work before links are enabled.

**Recommended mechanics, pending review.** Reuse lifecycle admission and saved Operation intent, with no separate graph scheduler. Capture the complete connected component of explicit links in both directions. Include consumers of an owner and every owner a consumer depends on. Stabilize that link set against attach, detach, create and removal.

Quiesce all writers, including agents, app Processes, deploys and every explicit consumer. Refuse a consistent backup if a writer or database cannot be quiesced. Within that interval, snapshot each owned workspace once, every participating root, and database recovery points through the database family.

One manifest records topology, ownership, read-write bindings, ACLs, snapshot identities and database material. A container snapshot or export alone is incomplete. Mark a backup complete only after all parts are durable and verified. Partial artifacts are unusable. Resume prior running states after capture or failure when safe; expose failed restarts for recovery.

Restore the whole component offline into isolated destinations. Map saved owners and consumers to restored identities. Restore volumes before binding containers. Validate the manifest, permissions and same-node links before any restored Instance starts or is published.

Refuse partial restore into live shared owners, which would roll back files for consumers outside the restore. A matching name never authorizes rebinding an existing Instance. Failed restore retains its manifest and staged resources without publishing part of the graph. Resume finishes it; explicit abandonment removes only staged destinations.

A move between Nodes refuses any incoming or outgoing reference, including pending or unresolved links. List blockers before downtime. Unlink explicitly first, without substituting a package. Coordinated movement of a component is later work.

An unlinked move keeps root and workspace together as one stopped unit. Preserve logical ownership and check destination maps and bindings before cutover. Keep source data until destination verification and recorded cutover succeed. Never allow both copies to write. Keep the existing rules for placing a Project and its default.

Before cutover, recovery resumes the source. After cutover, recovery finishes destination cleanup without reverting newer data. Unknown cutover keeps both copies stopped until reconciled.

Converting today's workspace from the container root needs separate authorization. Stop writers, stage and verify the dedicated volume, then bind it and record ownership at cutover. Retain source data until cutover is known. Failure before cutover keeps the old binding. This does not reopen checkout migration safety work.

Owner removal refuses incoming references or actual foreign mounts, even with force. Name consumers and require explicit unlink. Never cascade removal, rebind to a default or delete a mounted owner's volume.

Consumer removal refuses outgoing links. After explicit unlink, remove only the consumer's root and workspace through the existing flow. A failure keeps its removal identity and unresolved references. Resume accepts owned resources already absent.

Workspace lifetime follows its owner. Stop, sleep and unlink retain data. Successful explicit destruction deletes owned workspace data and snapshots after references clear; other owners and independent clones remain. Incus must retain backing storage required by independent clones.

Backups retain their manifest and data until explicit backup removal, with no automatic expiry introduced here. Resources from failed clone, move, removal and restore stay recorded with owner, Operation and location until recovery or explicit cleanup. Never silently collect unknown volumes. Existing retained Git and T3 history policies remain in [Destroy](#destroy).

### Proof required before implementation

First authorize a small disposable proof separately. Check distinct isolated maps with `agent` uid/gid 2750, `www-data` uid 33 and the group `www-data` gid 33. Verify `/srv/workspace` ownership `agent:www-data`, mode 2770 and default group ACLs.

As both users in the owner and every explicit consumer, create, edit, delete and rename files in both directions. Verify numeric host and guest ownership, ACL inheritance, setgid and group permissions after each mutation. Neither consumer writes nor subsequent attachments may break owner or other consumer access.

Verify default and unrelated workspace isolation, independent volume clones and explicit rebinding before first start. Prove that app clones never inherit package links. Check hot attach, explicit unlink, busy detach, remount and restart, preserving ownership and access.

Inject clone failures and lost results; prove scoped cleanup without source loss. Prove linked-move and referenced-owner removal refusals, and no implicit unlink or removal. Back up and restore a complete component offline, including partial-failure recovery, then repeat ownership and mutation checks.

Failure to preserve ownership blocks this design. It does not authorize weaker isolation. No live proof has run for this proposal.

### Browser libraries and fonts

A headless browser, such as the Chromium that a Project's own Playwright downloads, starts in every container. The image has none of the libraries it links, and no fonts. So the runtime folder holds them, once per Node.

The recipe pins each library and font as a Debian package of its Ubuntu snapshot. Each is a runtime part named `browser-<package>`. The packages hold what Chromium links and the image lacks, with their own dependencies. GPU drivers stay out: `libgbm` loads them only on demand, and a container has no GPU. DejaVu gives sans, serif and mono. Liberation stands in for Arial, Times New Roman and Courier New. Noto Color Emoji gives the emoji.

- **Folders.** The converge unpacks each package in its part and links its libraries into the version's `lib` and its fonts into `fonts`. Two packages that give the same name fail the build.
- **Linker.** `/etc/ld.so.conf.d/zz-orbit-browser.conf` names `/opt/orbit-runtime/current/lib`. The name sorts after the stock `x86_64-linux-gnu.conf`, so a system library of the same name always wins: the folder only adds what the image lacks.
- **Fonts.** `/etc/fonts/fonts.conf` names `/opt/orbit-runtime/current/fonts` and maps the generic families to them. Fontconfig reads it by default, also the copy inside Chromium. Each user keeps its font cache in its own `~/.cache/fontconfig`.
- **Sandbox.** A container has no user namespaces, so Chromium runs without its own sandbox. Playwright starts it so by default. No setting of the Node changes.
- **The browser.** It is no part of the folder. Each Playwright version pins its own browser build.
- **The download.** The Project's own Playwright downloads that build through the [egress proxy](#egress-and-dns), such as with `bunx playwright install chromium`. No Instance can change a binary that another Instance runs.
- **Where the build goes.** `PLAYWRIGHT_BROWSERS_PATH=0` puts it beside the Project's Playwright, in `node_modules/playwright-core/.local-browsers`, not in a user's `~/.cache/ms-playwright`. See [Playwright browsers](#playwright-browsers).
- **The trust module.** One more package, `browser-p11-kit-modules`, gives only p11-kit's trust module, at `pkcs11/p11-kit-trust.so`. It adds nothing to `lib` or `fonts`. [Trust](#trust) says why.

#### Playwright browsers

The default's deploy steps and a [task check](#a-task-copy-starts-clean) both run as `agent`. A browser in `agent`'s own cache would still be outside the workspace, where the app, as `www-data`, does not see it. The dependency folder is shared by both users through the group `www-data`. When a Project ignores `node_modules` in its `.gitignore`, the folder survives the clean of a task copy. The seed carries it, and a clone gets it with no copy and no download. The build always matches the Project's own Playwright version.

So a Project whose check runs browser tests installs the browser in a [development deploy step](/reference/deployments#development-deploy-steps) of its own, after its JavaScript install, such as `bunx playwright install chromium`. The step downloads only when the Project's Playwright version changed. Otherwise it does nothing.

| Where | What sets `PLAYWRIGHT_BROWSERS_PATH=0` and `AGENT_BROWSER_EXECUTABLE_PATH` ([agent-browser](#agent-browser)) |
| --- | --- |
| Deploy steps of a default Instance | The intent's `environment` list |
| A new clone: its units (T3, its agents and their commands) and login shells (the task check runs `bash -lc`) | Two boot files, `/etc/systemd/system.conf.d/91-orbit-browsers.conf` and `/etc/profile.d/91-orbit-browsers.sh`, written before the copy's first start |

The setting is not a [shared file](#existing-containers), and it is not on the Incus profile or in the image. So the converge changes no existing container: a running clone whose agent installed a browser into `~/.cache/ms-playwright` keeps finding it. A default gets the setting at its next deployment, and only its later clones get the boot files.

A fresh JavaScript install can drop the folder. With the same Playwright version, `bun install`, `npm install` and `pnpm install` keep it. A new Playwright version replaces the package folder, and `npm ci` deletes `node_modules`. The deploy step runs after the install, so the default's seed always holds the build. An agent that reinstalls `node_modules` in a task copy, or changes the Playwright version, runs the Project's own install command again, such as `bunx playwright install chromium`, before it runs browser tests.

### Existing containers

A container keeps the image it was made from. The image definition marks the files that every container needs as `existing`: the linker and font configuration, `/etc/gitconfig` and `agent`'s sudo grant, `/etc/sudoers.d/orbit-agent` (mode 0440). The converge of the Node writes them into each existing container too. It writes them into running, stopped and frozen containers, in the Instance project and in the nested-fleet one. Nothing restarts. Each later process reads the files by itself.

A clone copies its default's seed snapshot, not the default itself, and a seed taken before these files existed lacks them. So every clone gets the same files before its first start, with an empty linker cache that the stock `ldconfig.service` rebuilds at that start.

The sudo grant needs the `sudo` program. The image installs it, and the stock Ubuntu image that every older image came from ships it, so no container needs an install. An existing default and its running copies get the grant at the Node's next converge. A new copy gets it from its clone, also from a seed taken before the grant existed. A deployment, a start or a refresh writes no grant.

The dynamic linker reads its cache, `/etc/ld.so.cache`:

- **Running.** A container whose cache lacks a library of the runtime folder's `lib` runs `ldconfig -X`. It makes no links, because the folder is read-only.
- **Stopped.** A stopped container loses its cache instead. At its next start, the stock `ldconfig.service` builds a new cache.
- **New.** An image removes its cache last, so each new container builds its own at its first start.
- **Digest.** The Node keeps a digest of the library names it last applied, in `/var/lib/orbit/instance-container-libraries`. So a changed folder reaches stopped containers too.
- **Frozen.** A frozen container gets the files through the file API. `incus exec` refuses it, so it gets no exec.
- **Thaw.** A frozen container keeps its cache, because it thaws without a boot. Its wake rebuilds a cache that lacks a runtime library.
- **Failure.** A failed Incus call fails the converge after every other container had its turn.

A removal runs beside the converge, and a removal or an idle sleep stops a container first. So a container that the converge listed as running can be stopped or deleted before its turn. When a call on a container fails, the converge asks Incus again.

When Incus answers `Instance not found`, the container is gone. The converge lists it under `gone` and skips it, and it leaves the T3 restart list.

When Incus now reports `Stopped` or `Frozen`, the container gets the work of that state, as if the listing had shown it so. Its shared files and T3 drop-ins are written through the file API. A stopped container loses its linker cache. Nothing runs inside it. A stopped T3 needs no restart now and stays on the restart list. The runtime folder's Processes need no restart either.

A container that still runs keeps its failure. A failed NIC move always fails, because a device update works on a stopped container too.

A container that needs no restart lets the folder's restart mark clear. A failed restart of a running container keeps the mark for the next converge.

#### T3's unit drop-ins

A container with a T3, a clone or a migrated checkout, holds two root-owned drop-ins of `t3.service`: `10-orbit-boot.conf` and `20-orbit-server.conf` in `/etc/systemd/system/t3.service.d/`. `InstanceContainer::t3DropIns` renders both. The create writes them before the first start, and the Gateway hands the same texts to the Node converge as `t3_files`, one entry for each container with a T3. A default has none.

When a drop-in in a container holds other text, the converge rewrites it through the file API, as root with mode 0644. It does this in its own step, before the shared folders, so a change such as a new Conn environment line reaches existing containers:

| State | What the converge does |
| --- | --- |
| Running | Writes the file and runs `systemctl daemon-reload`. The agent folder's step then restarts T3 once, after the reload. |
| Stopped | Writes the file only. systemd reads it at the next boot |
| Frozen | Writes the file only: `incus exec` refuses a frozen container. The first converge that finds it running sees that systemd has not reloaded the unit (`NeedDaemonReload`), so it reloads and restarts T3 then |
| Same text | No rewrite. A retained restart or `NeedDaemonReload` still gets its catch-up; otherwise a second converge writes nothing and restarts nothing |

The restart is `systemctl try-restart t3.service`. When the agent folder changed in the same converge, its restart covers the container, so T3 does not restart twice. The Node lists each reloaded container in `/var/lib/orbit/instance-t3-restart.json` until its T3 restarted. So a converge that fails between the reload and the restart leaves the list.

Each successful restart removes only its own entry, even if another restart fails. A frozen or stopped container keeps its entry until a converge finds it running and successfully restarts T3; a deleted container leaves the list. A stopped container may already have read the new drop-in at boot, but the entry still requires that explicit restart rather than inferring completion from its power state.

T3 never restarts in the middle of a turn. Right before each T3 restart, whether for a reloaded drop-in or for an agent folder flip, the Node reads that container's open runs as `agent`. A run in `preparing`, `queued`, `starting`, `running` or `waiting` is an open turn. The read covers every thread, with subagent children and side chats.

`thread list` cannot serve here. It lists one project, and it hides subagent children and side chats. A parent's status covers only its own runs, so an idle parent can hide a running child. So the Node reads the server's own store, read-only. The program that the Gateway names in the container's `t3_files` entry says which store:

- T3 keeps its runs in `~/.t3/userdata/statev2.sqlite`.
- The Conn Server keeps them as `runs` entities in `~/.t3/userdata/engine.sqlite`.

Then:

- An open turn defers the restart. The drop-in is written and systemd reloads, but T3 keeps running.
- A deferred container stays on the Node's list. The Node converge Operation lists its identity in `t3_restart_deferred`. A [release rollout](/reference/gateway-recovery#fleet-rollout) keeps that Node pending and retries.
- When `t3.service` is active but the read fails or times out, the container counts as busy too. T3 never restarts blind.
- A missing store, or one whose tables changed, fails the read.
- When `t3.service` is not active, nothing is asked. `try-restart` does nothing there, and the entry leaves the list.
- A container without a `t3_files` entry has no T3. A folder flip restarts it without asking.

The first later converge that finds the container idle restarts T3 once and removes its entry. Every Node converge also runs the drop-in step, so a deferred restart waits only for the next converge after the turn ends. A folder flip clears its restart mark once every container has been restarted or listed.

The converge writes only these root-owned drop-ins. T3's settings, `.claude.json` and Codex's `config.toml` belong to `agent`: T3 and the agents change them, and root never writes through `agent`'s paths. `/etc/orbit/instance` keeps the text of its create.

### The app user's name

The app account of a container is the stock Debian `www-data`, uid and gid 33, with its stock home `/var/www`. FrankenPHP, every Process and Schedule run as it, with `HOME=/var/www`. `agent` (2750) is in the group `www-data`. The workspace is `agent:www-data`, group read and write, with setgid folders and the ACL `g:www-data:rwX` and its default. A normal container has no user `orbit`, no group `work` and no group with gid 1000.

A nested-fleet container is different. Its inner guests are Nodes, and the ID map puts their `orbit` on the outer uid 1000 and gid 2760; the inner fleet writes the shared checkout as them. So a nested-fleet container keeps `orbit` (uid 1000), `work` (gid 2760) and all their files and ACLs, and its workspace stays `agent:work`. `agent` is in `www-data` there too, and `www-data` is in `work`, so FrankenPHP, the Processes and the Node store step open the workspace.

Its firewall drops the traffic of uid 33 and uid 1000 to the inner fleet's networks. The swap file repairs an older nested guard from the image's rule before the app runs, reloading only Orbit's table and preserving the inner Incus tables.

The container intent says which ids the workspace uses (`workspace_ids`): its group for `.env` and the checkout bundle, and the uid whose entries root's [workspace pass](#who-runs-what) gives to `agent`. They are 33 and 33 in a normal container, and 2760 and 1000 in a nested-fleet one.

A container made from an older image has the user `orbit` (uid 1000) with the group `orbit` (gid 1000), and the group `work` (gid 2760). One root shell file moves it: `/usr/local/libexec/orbit-app-user` (`InstanceImage::AppUserSwap`, root-owned, mode 0755). Its source is `swamp/ansible/files/instance-app-user.sh`. It is one of the shared files of the image (`InstanceImage::shared`), so the image, a Node converge and the boot files of every copy carry it. Each container intent carries the same file (`InstanceImage::appAccount`): every Ansible path writes it, root-owned with mode 0755, when the container lacks it or holds another version, and then runs it. No path waits for a converge.

Root runs it before the first command as `www-data` on every path:

- the container phase, right after the container starts, in the `container`, `clone`, `start`, `processes` and `seed` phases. A wake that boots nothing skips it;
- the `node-store` and `node-runtime` phases;
- a Process's `start`, `stop`, `restart` or `destroy` (the `process` tag);
- the `config:clear` of a mail update and of `env:sync` on a container;
- a copy's clone guest, at the start of its services part, before its Node store step runs as `www-data`.

So an existing default moves at its next deployment, start or wake that boots, or at the first Orbit command that writes or changes one of its units. A copy of a seed taken before the move moves in its own completion: no release step is needed. A move to a container (`instance:migrate`) makes a new container of the new image.

The file recovers inactive Orbit units and timers only when their unit-file state is exactly `enabled`. It never starts a static Schedule service between calendar fires. A retry also recovers enabled timers after the account and unit files have already moved.

The file runs these steps:

1. Handle the nested-fleet marker `/etc/orbit/apparmor-off`: repair the firewall guard, preserve inner Incus tables, add the group memberships described above and stop.
2. Read the accounts, groups and old unit files. A moved container recovers its enabled Orbit units and timers before returning `already www-data`.
3. Stop those units and their timers.
4. Refuse any remaining uid-1000 process. Resume enabled Orbit units and timers before exiting 3. Static Schedule services stay idle.
5. Give the files of uid 1000 and of gid 1000 and 2760 on the container's own file system to 33, and the workspace ACL of gid 2760 to 33.
6. Copy the old home's files into `/var/www`, and remove `/home/orbit`.
7. Add `agent` to `www-data`, and remove `orbit`, `work` and the group 1000.
8. Rewrite the unit files for `www-data` and `/var/www`. Reload systemd before recovering inactive enabled Orbit units and timers.

It never touches `agent` beyond its group. Each step reads what is left, so a move that stopped half way finishes at the next run.

### The machine name

A container Instance presents itself as `<instance name>.<project slug>`, such as `task-5003.dlf-leden` or `default.shop`. Its T3 server shows that name. Its hostname is the same text when the slug is a lowercase hostname label and the name fits in 63 characters; otherwise it is the long form below. A person never sees `instance-<id>` as the machine's name.

`instance-<id>` stays the Incus name. It is the machine key: the ACL, the UFW comments, the mail state folder, the Node mirror `<id>.git` and the certificate scopes `app-instance-<id>` keep it. An Operation's `input` keeps it too, as `container.name`; it is the Incus name, not the presented name.

`InstanceMachineName` holds the rule.

**The presented name.** It is `<instance name>.<project slug>`, exactly as stored. It goes into `PRETTY_HOSTNAME` in `/etc/machine-info`. Stock T3 0.1.7 reads that line for its environment label before the kernel hostname. It reads it once, at its start.

**The kernel hostname.** It is the same text when the slug is a lowercase hostname label and the name has at most 63 characters. Otherwise it is exactly 64 characters, the kernel's limit. A valid name of exactly 64 characters takes that form too, so the two forms never meet. The long form holds the start of the Instance name, a hyphen and the Instance ID padded with zeros. A dot and at most 31 characters of the slug's label follow. The label is the slug in lower case, with `_` as `-` and no hyphen at either end.

**Unique on every Node.** An Instance name is unique in its Project, and a slug is unique, so a short name is unique. A 64-character name can equal no short one. It carries the Instance ID, so it can equal no other Instance's.

For a slug such as `My_App`, a person sees `default.My_App` as T3's label, and `hostname` answers with the mapped 64-character form.

**What the Gateway sends.** Every container intent carries `machine_name`, with `hostname` and `pretty`. The host intent of an app-dev Node carries `machine_names`: the project, container and both names of each runtime on the Node. A clone's boot files include `/etc/machine-info`, so a new clone's T3 shows the name from its first start.

#### The files

`instance_machine_name.py` holds the text of the three files, and every writer runs its `render` or `hosts` command on the Node:

- **`/etc/hostname`** holds the hostname alone.
- **`/etc/machine-info`** is Orbit's whole file: `PRETTY_HOSTNAME=<name>`. The clone's boot file writes the same text.
- **`/etc/hosts`** gets its `127.0.1.1` line: the hostname, its first label, the running kernel hostname and the Incus name, each once.

The running kernel hostname and the Incus name stay on the hosts line, so the kernel hostname resolves before and after the next boot.

The converge reads the running kernel hostname of a running container. A container that is frozen at the converge gets no such alias, and neither does a running container whose `hostname` read fails or takes more than 5 seconds, until a later converge reads it. So if a renamed Project's running container is frozen before the next converge, its kernel hostname stops resolving after it thaws, and `sudo` warns until the container's next boot. Orbit's [private names](#private-names-of-the-access-plan) block and every other line of `/etc/hosts` stay. Each writer of the file keeps the other's lines, so their order never matters.

Where each path writes the name:

| Path | When |
|---|---|
| Create of a default, a move | The container phase, right after the first start: the files, then the kernel hostname at once |
| Clone and task copy | `/etc/machine-info` as a boot file before the first start; the rest in the private names pass right after it |
| Start, wake, deployment | Only a stopped container: the start phase writes the files through the file API before it boots it. A running or frozen container gets nothing |
| Node converge | The files, in running, stopped and frozen containers, through the file API. Nothing restarts and the kernel hostname stays |

T3 starts with a clone's copy, at its boot: `/etc/orbit/instance` is the last boot file. Its label is right from its first start, because `/etc/machine-info` is a boot file too. The kernel hostname, `/etc/hostname` and the `127.0.1.1` line come from the private names pass after `incus start` returns, so T3 has already started when they change.

No path above restarts T3 for its name, and only a new container gets its kernel hostname at once. One explicit command restarts an idle running T3: see [Existing Instances](#existing-instances). A clone whose hostname step fails fails its create with `instance.clone_private_names_failed`, as a failed private names write does.

#### Existing Instances

An existing Instance gets its name without a new container. The converge of its Node writes the files. The kernel hostname takes the name at the container's next start, and T3 its label at T3's next start, such as a stopped container's next start or an agent folder update. T3's environment ID, threads, sessions and pairings do not depend on the name.

A container that ran before the name existed keeps showing `instance-<id>` as T3's label until T3 starts again, and a default Instance can run for weeks. [`conn:environment-restart`](/cli/conn/environment-restart) restarts the T3 of one running container whose label is not the name; [`conn:node-environments-restart`](/cli/conn/node-environments-restart) does it for each container of a Node. Run the Node form once on each app-dev Node after the release that brought the name.

Only an idle T3 restarts. The Gateway reads T3's threads with its admin session, the read the idle sleep uses. While a turn works, or any runtime request is pending, it restarts nothing and answers `busy`. A later run takes that container.

Only T3's service restarts. The run first checks that `/etc/machine-info` holds the name; without it, it answers `skipped` with `name_not_written`. Then it runs `systemctl try-restart t3.service` in the container and reads T3's label inside the container until it is the name. A T3 that does not come back is reported `failed`, and nothing retries it. The kernel hostname stays until the next boot.

`t3.service` sets no `KillMode`, so a restart ends every process in the service's cgroup and in any cgroup below it. So right before the restart the run reads, in one call, systemd's `MainPID` of `t3.service`, the cgroups below it, and the exact members of its `cgroup.procs` with each one's parent, session, terminal and name. It restarts only when every member may go. Any other member answers `busy` with `processes` and the counts, and nothing restarts. This table is the rule; the playbook's template only applies it.

| Member of `t3.service` | Answer |
| --- | --- |
| T3 itself: the `MainPID`, and no other process | may go |
| T3's resource monitor (`t3-resource-mon`) or its own `git` call, a direct child of T3 with no terminal | may go |
| The [Conn Server](#container-server)'s SDK host, a direct child of `conn-server` with no terminal, whose name reads `MainThread` (its `node`) | may go |
| A provider, `claude` or `codex` (the binaries the recipe ships), a direct child of T3 or of the SDK host with no terminal | may go |
| A member of the `claude` provider's own session whose parent is in the service: its setup `sh` and stdio MCP servers such as Laravel Boost's `php`, or `npm` and `node` | may go |
| Any member with a terminal (a tty), such as a shell node-pty forked or an `exec claude` typed in it | `busy`: `terminals` when T3 is its parent, else `commands` |
| Any other direct child of T3 | `busy`, counted in `terminals` |
| A `claude` tool tree, in the session of its own that Claude Code starts each tool shell in: a turn's tool, or a server it left running | `busy`, counted in `commands` |
| Any descendant of `codex`: its tools may share its session, so none is allowed | `busy`, counted in `commands` |
| Any other process under the SDK host | `busy`, counted in `commands` |
| A process whose parent left the service, such as a backgrounded `nohup` server that PID 1 adopted | `busy`, counted in `commands` |
| A member line the read cannot parse | `busy`, counted in `commands` |

The `claude` session allowance depends on Claude Code starting each tool shell in a session of its own, as the pinned recipe version does. Measure it again on each Claude Code bump in the recipe.

The Conn Server runs `claude` through its SDK host, not as its own child. The host lives while a Claude session is open, and a session closes after 30 idle minutes, so an idle Conn container often holds it. The rule allows the host as it allows a provider. It matches the name `MainThread` only under `conn-server`: under T3, such a child stays `busy`. Measure the name again on each Conn Server bump in the recipe.

An open idle shell counts because a restart loses its state. After a restart, T3 spawns a new shell only when a client opens the terminal again. It starts in the directory the client names and replays the saved scrollback. The old shell's directory, variables, jobs and history are gone.

An accepted limit: an MCP server that starts a long-running child without detaching keeps that child in the provider's session, and the restart ends it.

The read fails closed. When it cannot be read, `ps` fails for a member that is still alive, `MainPID` is 0 or not a member, or `cgroup.stat` counts a cgroup below the service, the answer is `busy` with `processes_unreadable`, and nothing restarts.

So the restart ends T3, its monitor and `git` calls, the Conn Server's SDK host, and an idle provider with no descendant outside the `claude` session. It also ends a terminal or command that started in the moment (about a second) between the read and the restart. Stock T3 0.1.7 keeps a provider session after its turn and has no idle release, so that session ends here.

A turn with no live tool process outside the provider's session is cut, for example one that runs Grep, Read or Edit, or whose Bash command already ended. T3 continues it after its start. Orbit Processes run in their own `orbit-*` units and do not restart.

A frozen or stopped container is skipped. T3 reads the name at its next start.

#### The restart window

The thread read and the restart are two steps. The read stays in the Gateway so the busy rule has one place. A read inside the run would need the admin session as a vault execution copy, as `conn.session_hold` has, and a second copy of the busy rule outside PHP. So the Gateway admits the Operation right after the read. The Node form reads every label at once and each container's threads right before its own admission.

An estimate, until the proof of the command measures it: about 5 to 15 seconds pass from the Gateway's read to the restart, for the dispatch, the run's input step, the start of its Ansible phase and its reads.

The `conn_operation` check and the admission are two queries in one request, milliseconds apart. A Conn Operation admitted in that gap can overlap the restart; it then fails on its own T3 call and is run again.

The run's process read narrows the window for a turn that runs a tool, and for every terminal, to about a second: the time from that read to `try-restart`. One call of the Node form admits at most four restarts, so the Swamp queue does not widen it. The intent carries `read_at`, the end of the Gateway's read. The run notes `restart_at` just before the restart. A restarted Operation's result keeps both and `window_ms`, the time between them.

What a user sees:

- **A turn in the window** with no live tool process outside the provider's session is cut. T3 0.1.7 marks that run cancelled as it stops.
- **The cut turn continues.** T3 continues it in a new run after its start. The T3 setting `continueThreadsAfterServerUpdate` does this, and it is on by default.
- **A connected client** shows *Reconnecting…* and reconnects by itself. Its backoff starts at one second.
- **An unsent draft stays.** T3's client keeps drafts in the browser (`t3code:composer-drafts:v1`), never on the server.

A container whose T3 nobody restarts keeps its old label until T3's next start, such as the container's next start or an agent folder update. Nothing else changes.

No schedule runs the restart. The label changes once, after one release, and a stock T3 that starts later reads the name by itself. A scheduled pass would add an always-on loop for a one-off change, and it would restart T3 servers at times nobody chose, each restart with its window above.

A rename of the Project (`project:update --slug`) writes no container file itself. The containers take the new name at their next start or at the next converge of their Node.

Three writers change `/etc/hosts` of a container: the converge writes the `127.0.1.1` line; `env:sync` and a start write the private names block; a clone's post-start pass writes both, but only into its new copy. The converge can run while an `env:sync` or a start writes the same file. If both write at once, one can drop the other's change.

This does not block: each writer keeps the other's lines when it runs, so the next writer of the lost part restores it. A lost block comes back at the next `env:sync` or start; a lost `127.0.1.1` line comes back at the next converge, and the kernel takes its name at the next boot anyway.

A presented name that equals one of the container's private names, such as an Instance `reverb` in a Project with the slug `orbit` beside the private name `reverb.orbit`, would shadow that name in `/etc/hosts`. Choose another Instance name in that case.

Incus does not keep the name. A copy's own metadata holds the stock templates `/etc/hostname` and `/etc/hosts` (`when: [create, copy]`), which write the Incus name at the first start after a create or a copy. That is why the hostname and the hosts line come right after that start, and why a start writes nothing into a container that never started. `/etc/machine-info` has no template.

### Trust

Every container trusts the Orbit root CA, so curl, PHP and OpenSSL verify an Orbit-signed site with no extra option. The source is the root that every Node's base converge writes for its agent, `/etc/orbit/agent/ca.pem`. The Gateway never puts the certificate in an intent.

A container holds the root as a local CA file, `/usr/local/share/ca-certificates/orbit-managed-root-ca.crt`. Two links in `/etc/ssl/certs` point to it, as `update-ca-certificates` makes them: `orbit-managed-root-ca.pem`, and the OpenSSL hashed name, such as `912e8a91.0`. The static PHP build's OpenSSL and its curl look a CA up in that folder, as system curl does. So PHP needs no php.ini setting. `update-ca-certificates` also adds the root to `/etc/ssl/certs/ca-certificates.crt`, for tools that read only that bundle.

| Where | What Orbit does |
| --- | --- |
| Image | The build pushes the Node's root; the image definition runs `update-ca-certificates`. A later root reaches its containers at the converge |
| Clone | `boot_files` names the Node file as a source. The clone script reads it before the copy and writes the file and its links |
| Clone bundle before the first start | The clone script adds the root to the copy's bundle when the bundle lacks it |
| Clone source | Only that one Node path may be a source. A missing or unreadable file fails the create with `instance.clone_trust_unavailable` |
| Clone without a bundle | A copy without a regular bundle file fails the create with `instance.clone_trust_bundle_refused`. A bundle of the root alone would drop every public root |
| Running container | The converge writes the file and its links. When the bundle lacks the root, it runs `update-ca-certificates` |
| Stopped or frozen container | The converge writes the file and its links through the file API. The wake rebuilds a bundle that lacks the root |

A new root on the Node reaches every container at the next converge. The converge and the clone script remove the old root's hashed name. A stopped or frozen container's bundle keeps the old root until its wake.

A seed taken before the root reached its bundle gives a copy that trusts the root through the links, but not through the bundle. So the clone script reads the copy's `/etc/ssl/certs/ca-certificates.crt` through the file API before the first start. It appends the root, the same Node file as the trust entry, when no certificate in the bundle is the root. The push keeps the bundle's owner and mode. A bundle that already holds the root stays as it is, so the root is never there twice. An older root in the seed's bundle stays until the next `update-ca-certificates`.

#### Chromium

Chromium on Linux takes local roots only from the user's NSS store, `~/.pki/nssdb`. It never reads `/etc/ssl/certs`. So `agent`'s store names p11-kit's trust module, and that module reads `/etc/ssl/certs/ca-certificates.crt`, the bundle above. Chromium then trusts the Orbit root, still trusts the public roots, and still refuses an untrusted certificate. Only `agent` gets the store, because only `agent` runs the agents and their browsers.

The module:

- The runtime folder holds it once per Node, at `/opt/orbit-runtime/current/pkcs11/p11-kit-trust.so`.
- The recipe pins its Debian package, `p11-kit-modules`, as the part `browser-p11-kit-modules`.
- The folder takes only the module from that package. It sits outside `lib`, so the dynamic linker never caches its name.
- Beside it, `/opt/orbit-runtime/current/pkcs11/requires` names the library the module needs, such as `libp11-kit0 0.26.2-2`.
- A new folder version only adds these files, so nothing restarts.

The store:

- `/home/agent/.pki` and `/home/agent/.pki/nssdb` are `0700`. `pkcs11.txt` is `0600`. `agent` owns all three (uid and gid 2750).
- A new store holds one entry: `library=` with the module's path, `name=Orbit system trust`, and a blank line.
- NSS makes `cert9.db` and `key4.db` itself at first use.
- Orbit never replaces or rewrites an existing store. A store without the entry gets the entry appended after a blank line. A store with the entry stays as it is.

`agent` owns these paths, so root never writes through them.

In a running container, the converge runs `instance-browser-trust.sh` as `agent`, with uid and gid 2750 and umask 077, under `timeout 30`. `agent` writes its own store, so a link it planted gains it nothing. The script refuses a path of another type or a link, and it opens `pkcs11.txt` only as a regular file.

A stopped or frozen container gets no store at that converge, because it can wake at any moment while the step runs. It gets the store at the next converge that finds it running.

A clone's copy has not started yet, so nothing can change its paths. The clone script reads each path's type from the `X-Incus-Type` header of the local Incus API. Each path that exists must be of its type: the home and both folders directories, `pkcs11.txt` a file. A missing folder is created. A copy without a home gets no store. A link anywhere is refused. A refusal fails the create with `instance.clone_browser_trust_refused`.

The module needs the container's `libp11-kit0` at exactly the part's version, `0.26.2-2`. The recipe holds that version once. The image definition carries it too, so a new pin builds and checks a new image.

| Where | A mismatch |
| --- | --- |
| Image build | The build reads the version with `dpkg-query` and fails with `instance.browser_trust_mismatch`. It writes no store and publishes no image |
| Clone | The clone script reads the copy's `/var/lib/dpkg/status` and fails the create with `instance.clone_browser_trust_mismatch` |
| Converge | The converge reads `/var/lib/dpkg/status` of a running container. That container gets no store, and `agent` removes the Orbit entry if its store already has one |

The converge never fails because of one container. A container that is not running, has no `/home/agent`, has a refused path or another library version gets no store. The rest of the converge goes on for it and for every other container. The `node.converge` Operation result lists each such container in `browser_trust`, with its reason:

- `not running`: the container was frozen or stopped at the converge, so its store was not written. The next converge that finds it running writes it.
- `no agent home`
- `path refused: <path> is <type>`
- `version mismatch: <installed> != <expected>`
- `store failed: <cause>`: the store step timed out, exited with another code, or hit an error of the named type

`orbit operation:show <id> --json` shows the list. In such a container, Chromium behaves as before this store: it refuses the Orbit certificate, and nothing else changes. An agent in the container can see the likely cause with one command; the full reason is in that list of the Node's last converge:

```sh
printf 'installed: %s\nneeded: %s\n' "$(dpkg-query -W -f='${Version}' libp11-kit0 2>/dev/null || echo none)" "$(cat /opt/orbit-runtime/current/pkcs11/requires)"; grep -qx 'library=/opt/orbit-runtime/current/pkcs11/p11-kit-trust.so' ~/.pki/nssdb/pkcs11.txt 2>/dev/null && echo 'store: names the module' || echo 'store: lacks the module'; echo 'full reason: browser_trust in the result of the Node'"'"'s last node.converge Operation'
```

A new Orbit root needs nothing here. It flows into the bundle as above, and the module reads the bundle, so no store needs a new seed.

## Environment notes

Each Project type has one note in the agent folder, at `notes/<note>.md`. The notes live in the Gateway's `resources/instances/notes`:

| Project type | Note |
| --- | --- |
| `laravel-app`, `symfony-app` | `web-app`: a web app with previews |
| `monorepo` | `monorepo` |
| `laravel-package`, `node-package` | `package` |

A container that runs T3 without a default to copy, such as a migrated checkout, links `/home/agent/.claude/CLAUDE.md` and `/home/agent/.codex/AGENTS.md` to the note of the Project's type. A clone reads the note through its own [agent instructions](#agent-instructions). A note states facts about the environment only. It names the two users and the workspace path, and points to `/etc/orbit/instance` for the Instance's URL. It says where PHP, Composer and the other runtime programs are.

It says how previews work, and how an agent's own development server becomes reachable: the port range, the address to bind and the host name to allow. It says that `agent` has passwordless sudo and `www-data` has none, that there is no direct internet, how the egress proxy works, and how Git leaves the container.

A note gives no instructions about the work. The Project's own instructions and skills come from its [agent instructions](#agent-instructions); MCP servers come from the project's own files.

## Agent instructions

A Project keeps its own agent instructions in the reserved `agent/` folder of its [Project Documents](/reference/project-documents#agent-instructions) ([ADR 0259](/decisions/0259-deliver-per-project-agent-instructions-from-documents)):

| Document path | In the clone |
| --- | --- |
| `agent/AGENTS.md` | `/home/agent/.config/orbit/agent/AGENTS.md`, after the note of the Project's type, under the heading `# This Project` |
| `agent/.skills/<skill>/SKILL.md` and the files beside it | `/home/agent/.config/orbit/agent/.skills/<skill>/...` |

The folder lives in the copy's own agent home, not in the Node's agent folder that every container mounts, so only the Project's own containers hold its text. It is owned by root; `agent` reads it. Every clone links its agents to it:

| Link | Target | Read by |
| --- | --- | --- |
| `/home/agent/.claude/CLAUDE.md` | `AGENTS.md` | Claude Code |
| `/home/agent/.codex/AGENTS.md` | `AGENTS.md` | Codex |
| `/home/agent/.claude/skills` | `.skills` | Claude Code |
| `/home/agent/.agents/skills` | `.skills` | Codex |

### When a copy gets them

A clone's create reads the Project's `agent/` folder before its admission. It puts the folder's whole content in the clone intent, as `clone.agent_instructions`. The agent part of the create's T3 step makes the folder again with exactly those files. A file that the Project removed is gone from the copy.

A copy keeps the version of its create. A Documents change reaches the next copy. Claude Code and Codex read their instructions when their process starts, and each turn starts a new process.

A Project without an `AGENTS.md` gets the type note alone as `AGENTS.md`. While the `documents` extension is off, no Project has instructions.

### What counts

An archived file or folder is absent. Only `AGENTS.md` and the files below `.skills/<skill>/` are instructions; other files in `agent/` stay out.

The folder holds at most 32 files and 32 KiB in all, of UTF-8 text with no control character but a tab or a line end. The create intent crosses as one command argument, which Linux holds to 128 KiB; JSON escapes text to at most three times its size. Each name after `.skills` holds only letters, digits, `.`, `_` and `-`. When the folder breaks a limit, the create of a clone is refused before any record, with `projects.agent_instructions_too_large` or `projects.agent_instructions_invalid` (422). When the store fails, the create is refused with the store's own error, such as `project_documents.storage_unavailable`.

### Other phases of a clone

Only a clone's create writes the folder. The intent carries the content only for the agent part; the other parts of the create get the intent without it. A clone made before agent instructions existed keeps its links to the type note.

[`project:show`](/cli/project/show) reports what the next copies read, from the records alone; it does not check the limits, which the next create checks.

## Development mail

On a Node with the main Mailpit, Orbit gives every development container two IPv4 loopback listeners: `127.0.0.1:1025` and `127.0.0.1:2525`. Each forwards TCP to the Node's Instance bridge gateway at the configured SMTP port. The single fleet setting `instances.mailpit` names the Node Process and published port. Only an active, desired-running Docker Node Process with that name and a reachable TCP publication supplies an endpoint. Other Nodes, production placements and checkout Instances get no setup.

Create and development clone apply mail inside the container launch, before workspace preparation. There is no separate `mail` or `clone-mail` step or playbook launch. Default create reuses its container play; native `clone-container` calls the same batched converger. It uses the hardened bridge helper additively, admits the translated Docker tuple, and adds the exact gateway TCP port to this Instance's own ACL. No Node converge is required. The existing refresh timer re-resolves Docker addresses from the persisted public endpoint. No Docker address is stored as fleet configuration.

### Listeners and lifecycle

The stock image supplies `/usr/lib/systemd/systemd-socket-proxyd`; setup checks that it is executable before any changes. Mail changes neither the prepared image definition nor its digest. Four root-owned files under `/etc/systemd/system` define `orbit-mail-1025.socket`, `orbit-mail-1025.service`, `orbit-mail-2525.socket` and `orbit-mail-2525.service`. Only the sockets are enabled. Services start on demand and restart on failure. They listen only on IPv4 loopback. Foreign listeners or unit files skip mail setup with `mail_port_in_use` or `mail_unit_conflict`; Orbit does not stop a Project Process to take its port.

### Apply and retry

Create and clone treat mail failures as advisory: the container launch succeeds and workspace, T3 and registration continue. The receipt carries `mail.status=failed` with a bounded reason and `warnings: [{code: "instance.mail_update_failed", step: "mail"}]`. A Node without the main Mailpit adds no warning. `instance:mail:update` retains a real failure when its mail work fails.

Network changes precede unit writes. A failure after admission can leave the exact ACL rule, its confirmed bridge endpoint and some unit files in place; no application Process is restarted. A rerun keeps the admitted path, completes missing or changed units and enables both sockets.

Pending activation lives on the Node at `/var/lib/orbit/instance-mail/<container>/activation.json`, root-only, with unit names only. Orbit saves it before writing units and clears it after reload, restarts and socket checks succeed. A repeat with no drift changes nothing and reports `unchanged`. Refreshing an endpoint’s admission timestamp alone does not count as a changed mail path.

One guest preflight checks the executable, unit ownership and contents, socket states and both ports; only when needed, a second guest command installs and activates the four units together. An unchanged repeat needs only the preflight entry. An active Orbit socket with a missing enable link remains Orbit-owned; the next run restores enablement. Guest failures retain only the fixed public `instance.host_unavailable` and `process.target_inactive` codes; tool output remains private. Network admission completes before any unit write.

Updated endpoint settings rewrite the proxy services and restart active proxies, leaving application Processes running. The runtime folder's existing `try-restart orbit-*.service` also leaves idle proxies inactive; an active proxy restart can close its current SMTP connections while its socket remains listening. Process cleanup matches `orbit-process-*` and does not remove these mail units.

A clone keeps the seed's mail socket links. Its default's mail units are the Node's, the same for every copy, so the container launch finds them in place and reports `unchanged`. A seed taken before mail existed gets them as `applied`. The launch rewrites and restarts them only when the Node's endpoint changed after the seed.

A socket's link in `sockets.target.wants`, absolute or relative, is its enablement. The preflight reads it on disk, because a clone's mail part runs right after `incus start`, often before the copy's systemd answers. A missing link is a change: the install enables and starts the socket. A linked socket that does not listen is started, which is no change; `enable` would reload systemd in the middle of the boot.

The install reads the sockets again after its reload, so a booting copy whose units changed still ends with both sockets listening. A silent systemd means a boot only in a copy that the clone's container phase has just started. That boot starts the linked sockets itself, so a clone of a seed with the right units runs no install and reports `unchanged`. Anywhere else a silent systemd fails the install, as before.

Destroy deletes the container, its private Node activation state and its own ACL, including the SMTP rule. It does not withdraw the shared bridge tuple: that stays for ordinary host reconciliation, like database tuples, and remains needed while other Instances use Mailpit. There is no new helper withdrawal.

The mailer rule edits `/srv/workspace/.env` only when the byte string `MAIL_MAILER` occurs exactly once in the whole file and that occurrence forms exactly `MAIL_MAILER=log`, with or without matching single or double quotes, as a whole line. It replaces only `log` with `smtp`, preserving the quote style. It keeps LF or CRLF, all other bytes, and the file's owner, group, mode, POSIX ACLs and extended attributes.

Before rename, Orbit clears the staged file’s inherited access ACL, copies metadata with GNU coreutils, and explicitly applies the source ACL with the image’s `acl` tools. Node Python verifies owner, group, mode and hashes of the complete access ACL and every xattr name and value before rename. GNU tar, an Ubuntu Essential package, supplies the xattr snapshot because `getfattr` is absent from the image. Snapshot bytes stay in memory. Any mismatch fails with the original file untouched and the staging file removed.

The write uses the one reserved staging file `/srv/workspace/.env.orbit-mail` and an atomic rename, with no backup. Missing files and files without any `MAIL_MAILER` occurrence return `not_present`; one exact `MAIL_MAILER=smtp`, with or without matching single or double quotes, returns `already_smtp`.

Every other form stays as written. A mention in a comment or another value also counts. Multiple occurrences or a symlink stay untouched. Before changing the mail line, Orbit accepts only these whole-line shapes above it, after removing a file-leading BOM for the check:

- Empty lines or spaces and tabs only.
- A comment with optional spaces or tabs before `#`, with no single quote, double quote or backslash in its text.
- `KEY=value`, with no quotes, backslash, `#` or whitespace in the bare value. The value may be empty.
- `KEY="value"`, with no double quote or backslash inside.
- `KEY='value'`, with no single quote or backslash inside, and no `="` marker.

`KEY` matches `[A-Za-z_][A-Za-z0-9_.]*`; no form permits `export`, spaces around `=`, or text after a closing quote. Matching quotes can contain the other quote type, so `APP_NAME="Nick's App"` passes. A bare CR above the mail line, outside CRLF, refuses. Orbit refuses whitespace in bare values and `="` inside single quotes because the installed phpdotenv reader cannot parse those forms as one assignment.

This guard examines only preceding lines: a later quote cannot turn an earlier assignment into value text. Orbit still counts `MAIL_MAILER` across the whole file. The installed phpdotenv tests confirm each admitted shape leaves the mail line as an assignment.

`left_as_written` keeps the 1-based MAIL_MAILER occurrence numbers in `lines`; numbers can repeat for one line. A preceding line outside the whitelist also reports its first 1-based line number as `guard_line`. No line content appears. Only `changed` clears an available Laravel config cache and restarts running FrankenPHP and recognized PHP queue or Horizon workers. An unrelated Process is never restarted.

The Node removes the fixed staging name before inspecting `.env` and again on every exit. It uses `incus file delete --force`, accepting only exit 1 with the exact `Error: file does not exist` diagnostic as an already-absent file. Other deletion errors fail the step. File access works even after a forced container stop: [Incus container file access](https://github.com/lxc/incus/blob/v7.0.1/doc/howto/instances_access_files.md) mounts a stopped container’s root filesystem. A completed or failed step leaves no staging file for a seed or clone. An abrupt Node loss during staging can leave it until the next attempt; a seed or clone taken before that cleanup can inherit it.

For a new development container on a Node with the main Mailpit, the normal environment renderer replaces a parsed `log` mailer with `smtp`, including unquoted, single-quoted and double-quoted inputs. It writes its normal double-quoted `MAIL_MAILER="smtp"` line. Other values remain as written. The Project template and stored values stay unchanged; the new file owns the key through the normal environment mechanism. Create and clone report `mail_keys_set`.

The migration environment preflight uses this same admitted mail endpoint. Selected SMTP on `127.0.0.1` or `localhost` at port 1025 or 2525 passes when the move will apply the mail path. Without an eligible main Mailpit endpoint, it refuses with the reason. Other hosts and ports retain the ordinary endpoint check. Direct `::1` refuses because no IPv6 listener exists. The stock image keeps Ubuntu's localhost entries for IPv4 and IPv6; PHP tries the IPv6 address first and can fall back to IPv4.

Both environment checks read only the application directory’s `.env`, each Process working directory’s `.env` inside the checkout, the environment files named by the Instance’s Process units, and each Schedule’s source working directory `.env`. The application directory uses the same root/subfolder rule as the container. They never walk dependency trees for `.env` fixtures. This selector is not a containment guard: explicitly named absolute paths and symlink targets are followed.

An unattached selected loopback or private endpoint is checked from the Node before downtime. Only explicit TCP connection refusal produces `instance.migrate_env_service_unused` in the generic `warnings` list; an answer, timeout or unreachable result keeps the refusal. An existing selected host socket also refuses before TCP probing. Orbit runs the probe as root. It observes only an explicit connection refusal, not whether the app's user can use the service. Public or mixed-address names are never connected to. Each check allows two seconds per endpoint for resolution and connection attempts, for at most 20 unique endpoints; unexpected probe errors refuse safely.

Both environment checks read the source checkout before the copy. A `log` mailer is not selected there. No selector check runs after the mailer changes. Migration applies the same path and exact-line rule after copying `.env` and before starting application Processes. A mail failure records `instance.mail_update_failed` in `warnings` and does not fail or roll back an otherwise successful move. Run [`instance:mail:update`](/cli/instance/mail-update) to repair it. A listener or unit conflict succeeds with its named skip and never changes the mailer.

## Model access

A clone needs neither Conn enabled nor ai-router configured. Its container run starts T3 and creates its admin session; the Conn contribution records the server even while Conn is off. The contribution must be loaded so completion can verify that record.

Clone create and migration of a non-default Instance record the model route at admission. The Operation result includes `model_route: {"ready": true}` when ready, or `model_route: {"ready": false, "error_code": "ai_router.disabled", "message": "…"}` when unavailable; a missing guard reports `ai_router.guard_missing`. T3 runs without a ready route but cannot reach a model. Human output from `instance:create`, `instance:migrate` and `operation:show` prints one line with the reason. The result is the admission snapshot, not a live routing check.

A container holds only a placeholder key, in `/home/agent/.config/orbit/model.env`. Claude Code and Codex send model calls to a forwarder at port 8319 of the bridge's gateway address. The [forwarder](/reference/ai-router#container-model-forwarder) passes them to the model router guard, which admits the agent Node and swaps in the real key. The real key stays off the Node. While the forwarder's table is loaded, only containers on the bridge reach the guard, through the forwarder. The guard accepts the `?beta=true` query that Claude Code sends. On a prepared Node, `ai-router:model-forwarder NODE --bridge=orbitinst0 --address=<bridge address>` places the forwarder.

### The copy key

The model key does not tell one container from another: every container holds the same placeholder. So a [task copy](/reference/tasks#task-copy) gets one more key of its own, the copy key. It is the only way a copy calls Orbit, and Orbit uses it only for the [reviewer's pull request text](/reference/tasks#the-reviewers-pull-request-text).

The Gateway makes a random key each time it writes a task copy's model environment, at the copy's create. A task copy is never migrated: it already runs in a container. The Gateway stores only the key's SHA-256 hash, on the copy's runtime record. A new key replaces the hash, so the older key stops working.

The key goes into `model.env` as `ORBIT_COPY_KEY`. This is the same `0600` file of `agent` that holds the placeholder. `ORBIT_COPY_API` in the same file holds `http://<bridge address>:8319/orbit/v1/copy`. T3 hands both values to Claude Code and Codex. The app user `www-data` cannot read them.

A clone that is not a task copy gets no copy key and no `ORBIT_COPY_*` line. The copy's destroy deletes its runtime record, so the key ends with the copy. The copy holds no other credential for Orbit and no Git token.

A call reaches the Gateway only through the [forwarder's Orbit routes](/reference/ai-router#orbit-routes-for-task-copies). The Gateway accepts it only when both of these hold:

1. The bearer key's hash matches one runtime record of a task copy. Otherwise it answers 401 `copy.key_invalid`.
2. The request comes from the WireGuard address of that runtime's Node. Otherwise it answers 403 `copy.peer_mismatch`. A key that leaves its Node is useless.

From the runtime, the Gateway finds the copy and then its group. The request never names a group, a check or a commit.

`GET $ORBIT_COPY_API/whoami` answers the copy's Instance, Project and group:

```json
{"data": {"instance_id": 2611, "project_id": 68, "group_id": 5257}}
```

A task copy made before this release has no copy key. It keeps working, but it cannot call Orbit.

## Network

A container reaches only what this table lists. Everything else is dropped by its NIC ACL. The bridge independently rejects everything except the proxies and the Node’s attached-target union. Its ingress policy stays unchanged.

| Path | How |
| --- | --- |
| T3 | An Incus proxy device in NAT mode publishes T3 at a port from 3800 to 3999 on the Node's WireGuard address. A firewall route rule admits WireGuard peers, but not task VMs |
| Other containers | None. Each NIC has `security.port_isolation`, so containers on the bridge cannot reach each other |
| Its own address | Each NIC accepts only its own IPv4 and MAC address: `security.ipv4_filtering` and `security.mac_filtering`. Root in a container cannot send as another bridge address |
| Preview | The Instance's Route on the Node's Caddy proxies to FrankenPHP in the container, at its bridge address |
| Development ports | Any port from 1024 of a private Route, redirected to the Node's Caddy and proxied to the same port in a clone. Only the Node's Caddy reaches them in the container |
| Own site | The clone's own Route on the Node's Caddy at the bridge's gateway address, for that clone alone. See [the own site from inside](#the-own-site-from-inside) |
| Database | The Instance's own database server and port, on the Node or over WireGuard. A rule on the container's NIC, from the create flow. The server admits the Instance's role only |
| Models | The forwarder at the bridge's gateway address |
| Realtime | For an Instance that holds a private [websocket app](/reference/websocket-apps#reach-from-a-container): `reverb.orbit` on port 8443 only, at the address private DNS gives the name, by its [private name](#private-names-of-the-access-plan) |
| Internet | The [egress proxy](#egress-and-dns) at port 3128 of the bridge's gateway address: every public host and port, over HTTP and `CONNECT` |
| DNS | None. The proxy resolves every name |
| Everything else | Dropped, including the Gateway, WireGuard peers and other Nodes' private services |

The NIC filters the published target address before Docker's DNAT. The bridge forwarding hook sees the translated tuple, so its exact allow uses the current DNAT destination for a local Docker target. An unguarded NIC can reach that exact private service tuple, but no other Docker-private service.

For a target on another Node, Orbit also adds the exact route allowance from the container's address through WireGuard; Incus masquerades the bridge source to the Node's address. A profile-only NIC can reach the two proxies, but no attached target. Before starting, the native clone, container phases, image builder and host clone probe use the same check of every effective NIC on the Instance bridge. A missing ACL, a missing default egress reject, a reference to the bridge union ACL, or an unexpected extra NIC refuses with `instance.container_network_unsafe`.

**Address filters.** Create, clone and migrate write `security.ipv4_filtering=true` and `security.mac_filtering=true` on `eth0` in the one call that makes the container. The image build container does the same at its fixed address. The project profile's `eth0` has no address, so it carries no filter.

No Orbit path rewrites the `eth0` of an existing container. Incus applies the two keys to a running or frozen container with no restart, so the containers made before the filters got them by hand.

The NIC check refuses a bridge NIC that holds its own `ipv4.address` but lacks either key, with `instance.nic_filters_missing`. An unguarded NIC keeps its own code. The check runs before the start of a create, a clone, a migrate and the image build container, and at the end of the host converge. The nested guard compares the other keys only, so a nested-fleet container without the filters gets the same code.

A NIC without its own address takes a DHCP lease and carries no filter: the clone probe is a copy of a copy, so a fixed address would be held twice, and no second address is reserved for it. That is the project profile's `eth0`, which the converge's clone probe uses: the check does not ask it for the filters. Every Instance NIC holds its address, because create, clone and migrate write it with the filters.

**Open after the filters.** The egress proxy and the model forwarder admit the whole bridge range without identity. A container that can only send from its own address still reaches both, and neither tells one container from another.

**F10, current ingress limit.** The bridge admits TCP 1024–65535 from its gateway address and TCP 3773 from any source. The NIC combines that common ACL with its own ACL as a union: a live scratch default admitted the Node on 8080, 3773 and 5173, although its own ACL named only 8080.

With only its own NIC ACL, 8080 answered and 3773 and 5173 were refused. With the shipped common-plus-own ACLs and `security.port_isolation=true`, neither scratch container reached the other's 3773 or 8080. A follow-up must separate the common egress rules from broad bridge ingress in the host ACL layout and NIC ACL composition; a default's own ACL does not yet narrow the common ingress.

A container cannot reach the Gateway: its traffic would leave as its Node's WireGuard address, with the Node's Gateway access. The proxy refuses that path too.

### Private names of the access plan

A target of the access plan can carry a fleet private name. Two kinds do: an attached private websocket app, `reverb.orbit` at port 8443, and a clone's [own site](#the-own-site-from-inside), each private Route name at the bridge's gateway address and port 443. The plan lists each one under `private_names`, with the exact address and port it admits; the key is absent for every other Instance, so their plans stay as they were.

- **Hosts file.** The container has no DNS server, and the egress proxy refuses private names. So Orbit writes each name with its address into the container's `/etc/hosts`.
- **Owned lines.** Orbit owns only the lines between `# BEGIN Orbit private names` and `# END Orbit private names`. With no name, it removes them.
- **One renderer.** The command `hosts` of `instance_node_services.py` renders the hosts text from the current file and the names. Every writer below uses it, so all write the same bytes.
- **No proxy.** Each name goes into `NO_PROXY` through the one place that composes the container's proxy settings. The client then connects on the exact path, not through the proxy.
- **Env check.** The migrate's env check admits a name only at the port the plan gives it. Every other private name stays refused.

Who writes the names:

| Writer | When |
| --- | --- |
| Create, migrate and start phases | Deploy and wake run the start phase |
| `env:sync` | In a running container |
| A clone's create | Right after the copy's first start, after its hosts template ran. It replaces any block with its own, or removes it when the clone has no name |

A clone holds no app of its default, so its names are its [own site](#the-own-site-from-inside) names.

## Egress and DNS

This part is built. A container has full outbound access to the public internet, and all of it goes through one forward proxy on its Node. The proxy is the one place where a rule can narrow that access later, with no change to the containers. [ADR 0250](/decisions/0250-run-instances-and-task-groups-in-incus-containers#nested-fleet) records the decision. The `app-dev` converge of a [prepared Node](#prepare-a-node) installs it, in `tasks/instance-egress.yml`.

### The proxy

Each prepared Node runs one proxy for all its containers.

- **Program.** Squid from Ubuntu runs as the system user `proxy`, on port 3128 of the Instance bridge's gateway address. Its unit is `orbit-instance-egress.service`.
- **Configuration.** `/etc/squid/orbit-instance-egress.conf`. Squid's AppArmor profile lets it read only `/etc/squid`.
- **Checked first.** The converge parses a new configuration beside the live one, and only a configuration that parses replaces it.
- **No stock Squid.** The converge masks the package's own `squid.service` before the package installs. So the stock configuration, which listens on every address, never runs.
- **Installed once.** Each converge reads the package's status with `dpkg-query`. Apt reads the package index and installs only for a missing Squid and after a failed read. The host's updates upgrade it ([package sources](/reference/node-provisioning#package-sources)).
What it serves:

- **Scope.** A forward proxy with `CONNECT`, open to every public host and port.
- **Nothing kept.** It keeps no cache and no files. It sends no `Via` or `X-Forwarded-For` header.
- **No cache manager.** It refuses requests for its own cache manager, so a container cannot read what other containers fetch.
- **Who.** Every container on the bridge uses it, `www-data` and `agent` alike, and so does the image build container.
- **Admission.** Only the bridge reaches the port. The common NIC ACL and the Node's rule `orbit:instances-egress` admit it there. The proxy's table resets a connection to the port from anywhere else.
- **Health.** The converge ends by checking that the proxy and its table run. Otherwise it fails with `instance.host_unavailable`.

Readiness inspects the listening socket at the bridge address and port 3128, with 30 retries at a one-second delay, because the proxy's table rejects host TCP probes over `lo`; the image build still uses the proxy from its container on the bridge.

Names and the record:

- **Names.** Containers have no resolver. The proxy resolves each name through the Node's stub resolver, `127.0.0.53`.
- **On an Instance fleet Node.** The proxy sends every request to the fleet's egress proxy and never goes direct ([Instance host in the fleet](#instance-host-in-the-fleet)).
- **Private names.** The proxy refuses a name in the fleet's private routing domains before any lookup. So such a lookup does not reach the VPN's DNS.
- **Private domains.** `orbit`, the VPN domain, the Cluster and Node domains, and the private Route names. Each converge renews the list.
- **A new domain.** A domain added after the last converge is looked up. The table still refuses its address.
- **Record.** Squid writes one journal line per request. It holds the container's bridge address, the request, the status and the server address.
- **Not reported.** The Node's agent does not report these lines yet.

### The proxy's table

The nftables table `orbit_instance_egress` keeps the proxy on the public internet:

- **Refused.** It rejects every new connection of `proxy` to the Node's own addresses and the WireGuard interface `orbit`.
- **Also refused.** Every private or special IPv4 address, and all of IPv6.
- **IPv4 ranges.** `0.0.0.0/8`, `10.0.0.0/8`, `100.64.0.0/10`, `127.0.0.0/8`, `169.254.0.0/16`, `172.16.0.0/12`, `192.0.0.0/24`, `192.168.0.0/16`, `198.18.0.0/15`, `224.0.0.0/4` and `240.0.0.0/4`.
- **No IPv6.** A LAN often uses global IPv6 addresses, which no fixed range tells from the internet. So the proxy uses IPv4 only.
- **Admitted.** Only the stub resolver, on port 53. On an Instance fleet Node also the parent proxy: exactly its address and port.
- **Only `proxy`.** The output chain holds one rule: a packet whose socket belongs to `proxy` jumps to the chain `squid_out`, which holds the rules above. Every other packet leaves the table at once.
- **Kernel sockets.** A packet of a kernel socket, such as a WireGuard handshake, has no socket user. It matches no `meta skuid` rule, so it passes.
- **Why.** The earlier chain began with `meta skuid != "proxy" return`. A packet without a user does not match it either. So on `nested-app-dev` the handshake fell to the private address reject after a restart.
What that means:

- **Effect.** The rule holds whatever a name resolves to. The proxy answers 503.
- **Out of reach.** The Gateway, WireGuard peers, other Nodes' private services and the Node's LAN.
- **Order.** The table's unit, `orbit-instance-egress-table.service`, loads early at boot.
- **Changed table.** The converge loads a changed table in place with `nft --file`. The file replaces the table atomically. Squid keeps running.
- **Bound.** The proxy's unit binds to the table's unit (`BindsTo`). Each start first checks that the table is loaded.

### Proxy settings in a container

Orbit sets the proxy for everything that runs in a container. Tools read the upper-case or the lower-case names, so both are set:

- `HTTP_PROXY`, `HTTPS_PROXY`, `http_proxy` and `https_proxy` are `http://<bridge gateway>:3128`.
- `NO_PROXY` and `no_proxy` are `localhost,127.0.0.1,::1,<bridge gateway>`, so the model forwarder stays off the proxy.
- `NODE_USE_ENV_PROXY=1` makes Node's own `fetch` use the proxy.

| Where | File |
| --- | --- |
| Every unit: T3, FrankenPHP and each Process. Claude Code, Codex and their commands inherit it from T3 | `DefaultEnvironment` in `/etc/systemd/system.conf.d/90-orbit-runtime.conf`, beside the runtime folder's variables |
| `incus exec`: the Node's patch run and the image build | The profile's `environment.*` keys |
| Deploy steps of a default Instance, which run under `env -i` | The intent's `environment` list: `HOME`, `USER`, `LOGNAME`, `PATH`, the container environment `PLAYWRIGHT_BROWSERS_PATH=0` ([Playwright browsers](#playwright-browsers)) and `AGENT_BROWSER_EXECUTABLE_PATH` ([agent-browser](#agent-browser)) |
| Login shells | `/etc/profile.d/90-orbit-runtime.sh` |
| apt | `/etc/apt/apt.conf.d/90orbit-egress` |

**A container's own bypass.** Some containers need more addresses off the proxy than the image gives. `InstanceNodeServiceTargets::proxyEnvironment` builds that one value from three sources after the base above:

- each attached [Node service](/reference/processes-and-schedules#node-service-target-records) host that is not on the bridge;
- for a [nested-fleet](#nested-fleet) container, its inner fleet (`10.45.0.1`, `10.45.0.0/24` and `10.232.2.0/24`): the container's own CLI then reaches its inner Gateway;
- each name of the container intent's [`private_names`](#private-names-of-the-access-plan) list, such as `reverb.orbit` for an attached private websocket app.

A normal container without these keeps the image's value only. Every writer of the value takes it from this builder. When the value is not the image's base, the intent carries it as `proxy_environment`. The create, migrate and start phases then hand it to the one guest writer, and so does `env:sync`. Units read it at their next start. `env:sync` restarts the web unit and the running Process units only when `.env` changed, as for a database attach, and never T3.

| Reader | Source | When it reads a new value |
| --- | --- | --- |
| `incus exec` sessions, such as Orbit's own commands in a copy | The container's `environment.NO_PROXY` and `environment.no_proxy` keys | The next session |
| Units: T3, its agents, each Process | `/etc/systemd/system.conf.d/99-orbit-node-services.conf`, and systemd's manager environment | A unit's next start |
| Login shells | `/etc/profile.d/99-orbit-node-services.sh` | The next login shell |

A create and a clone write the keys. A clone also writes both files before its first start, so its first boot, T3 included, reads the value. The intent's `clone.proxy_environment` carries the value when it is not the base, or when the default's seed holds another bypass.

`instance_node_services.py` renders the two files once, in its function `proxy_files`. Its function `write_proxy_environment` and command `proxy` write them and the manager environment in a running container. The clone script pushes the same text, from the command `proxy-files`, into the stopped copy. The Node service apply calls the writer when a target changes. The [start of a stopped clone](#a-stopped-nested-fleet-clone-starts) also calls it.

A nested-fleet container made before these keys, such as the Orbit default, keeps the image's value until a start phase writes the composed value. Its copies do not wait for that: each copy gets the keys and the files at its create.

A container takes no DNS server from DHCP (`/etc/netplan/90-orbit-no-dns.yaml`), and its resolver has no fallback (`/etc/systemd/resolved.conf.d/90-orbit-no-dns.conf`). So a name lookup in a container fails at once.

So Composer, npm, Bun, Vite+, Git over HTTPS, curl, apt, Guzzle and Node's `fetch` use the proxy without further settings. A tool that ignores the variables fails at its name lookup, such as PHP's `file_get_contents` on a URL or a headless browser. The [private names of the access plan](#private-names-of-the-access-plan), such as `reverb.orbit` for an attached websocket app, are in `NO_PROXY`; other private hosts are not. UDP, so also QUIC, and other protocols get no route out. Git over SSH does not work. The container holds no GitHub credentials, so [Git in and out](#git-in-and-out) still goes through the Node.

## Lifecycle

Lifecycle flows run from the Node with `incus`, usually through Ansible phases. The [clone flow](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/create.mmd) uses official SSH steps running shipped Python host and guest scripts for its four direct phases. Each Ansible phase selects only its own tagged tasks before Ansible evaluates their conditions; it does not walk the tasks of other phases. Migrated development Instances can carry registered legacy Schedules as [container timers](/reference/schedules#container-timers); development Schedule definitions remain unsupported.

### The default Instance

The default Instance is the clone source of its Project on the Node. Orbit builds the Project's first default Instance from scratch. It launches a container from the image and pushes in the default branch, fetched on the Node. The database family creates the database on a Database server once the container can reach one; until then the default uses the database in its workspace. Orbit writes `.env` and runs the Project's [development deploy steps](/reference/deployments#development-deploy-steps), which install dependencies and migrate. The [first default chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/create-default.mmd) has the steps.

A successful create or checkout migration completes the default’s Route projection when it records the seed. Manual deployment is then available without waiting for an automatic-deployment tick. A failed or restored migration keeps its previous projection state.

A stale projection mark is repaired only for an active default on an active app-dev Node, with a container seed. Its own Route is selected from its targets, with authoritative Routes ordered by id and a Route without a web root preferred, as for deploy admission. This includes cluster Routes without a direct Node placement. The selected Route must belong to the same Project and have no web root.

All Routes targeting the Instance must be active and published, with no failure, placement transition, replacement or target-set change. No unfinished Instance or Route publication and no retained publication hold may remain. A non-web Project without a Route counts as complete.

After that, [`instance:deploy`](/cli/instance/deploy) explicitly deploys the default branch. Each new commit of the default branch also deploys it [by itself](/reference/deployments#automatic-development-defaults), unless the Project turned `default_auto_deploy` off. Orbit pushes the new commit in, checks it out, and runs the development deploy steps in the container. A step with [inputs](/reference/deployments#step-inputs) is skipped when they and its command are as at its last passed run in the workspace; its record is in the workspace's `.git/orbit-deploy-steps`, so a clone keeps it with the files it describes. Before that snapshot, a Node/Vite default converts an old shared Node store once as `www-data`, without restarting a Process.

A converted store replaces the same-commit seed too: an immutable old snapshot cannot inherit a later filesystem change. Until this explicit deploy, clones of an old seed still convert their own store correctly.

After a deployment passes, Orbit stops the default's Processes for a moment and takes the snapshot `seed-<commit>`. It retakes the snapshot at the same commit too, so a clone gets the default's current caches and built files. Clones copy from that snapshot, so they never see files half written. The [deploy chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/deploy-default.mmd) has the steps. The default Instance runs no T3 and no agent: a thread always starts in a new Instance.

A default sleeps like any other development container: after one idle window with no traffic, the [idle sleep](#sleep-and-wake) freezes it and its memory goes to swap. A visit to its Route, a deployment, or [`instance:wake`](/cli/instance/wake) wakes it. After an automatic deployment it may sleep again at the next sweep. A clone or task copy does not wake it: the copy comes from the seed snapshot, which needs no running container.

#### A retake keeps the seed

A retake never leaves the default without a seed. It takes the new snapshot first, beside the old one. When `seed-<commit>` exists already, the new snapshot waits under `next-<commit>`. Only when the new snapshot exists does the old `seed-<commit>` go, and `next-<commit>` takes its name. Older seeds go last. The seed of a default with an [inner volume](#the-seed-pair) is a pair, and both halves follow the same order.

The proof Project's default can keep one older pair as the base of the next [seed copy](#the-seed-on-each-ci-node). The Gateway names the default's current seed in the seed step's intent (`seed_base`) when a CI Node that is ready for the seed copy records that seed (`ProofSeeds`). Then, after the new seed is whole, the old pair is not deleted but renamed `base-<commit>`, also at the same commit.

Every older `base-` pair goes first, so the default keeps at most one base. Without `seed_base`, the old pair goes as before. A clone and a restore never read a `base-` pair. A rename keeps the snapshot's creation time, and it needs no free ID range. So a retake still needs only one free range, for a moment.

A copy that meets a retake does not fail. A retake at the admitted commit deletes `seed-<commit>` and renames its `next-` snapshot to that name a moment later. So the step before the copy, `clone-seed-wait`, asks the Node's Incus API for the admitted seed with curl's own retry: every 2 seconds, for at most 120 seconds, the bound of a deployment's `seed` phase. A normal copy finds its seed at the first request.

Then the copy reads the default's snapshots once. It copies the admitted seed when it is there. When a deployment replaced it by a newer seed, the copy takes the newest `seed-` snapshot, and a branch that the Node's mirror lacks starts at that seed's commit. When the admitted seed's `next-` snapshot is still there, the retake did not end within the wait: the create fails with `instance.default_seed_moved`, before any change.

#### The seed hold

The seed of the proof Project's default has one hold, `instance_seed:<default id>`, so its lifecycle is serial. A [seed copy](#the-seed-on-each-ci-node) takes the hold as its Operation lock when it is admitted and keeps it to its end, so no retake deletes or renames a snapshot that it pushes.

A deployment of that default asks the Gateway for the hold before its first `seed` phase, in the step "Hold the seed", and keeps it to its end. While a seed copy is open, the Gateway answers `423 instance.seed_busy` after 5 seconds, and the step asks again for up to 1,800 seconds. One seed copy ends well within that, and the deployment's run budget counts it. A hold that stays busy fails the deployment with `instance.seed_hold_timeout` before it changes the container.

A deployment that got a busy answer waits for the seed. While a deployment waits for the seed or holds it, a seed copy is not admitted (`instance.seed_held`). So a seed copy that ends while a deployment waits admits no next copy, and the deployment gets the hold next. Every end of the deployment frees its claim and admits the seed copy again: passed, failed, cancelled, an unreadable result, and a timeout once Swamp confirms that the run stopped or after its hold of 60 minutes. Every other default takes no hold: no seed copy sends its seed.

Incus maps a new snapshot as it maps a new container: it needs a free [isolated ID range](#isolated-id-ranges) for a moment, but a delete or a rename needs none. So the step never uses `incus snapshot create --reuse`, which deletes the old snapshot before it creates the new one. On 2026-11-14 a retake with `--reuse` on a host with no free range left Instance 2454 without a seed for seven minutes.

A failed snapshot deletes only what this take made, a new name or `next-<commit>`, and fails the step with `instance.container_seed_failed`. The Operation's error detail names the cause: `No free ID range for the new seed`, or the failed command's error output. The old seed stays, and clones keep copying it. A run that stopped after its new seed was whole, but before the rename, leaves the new seed under `next-<commit>`; the next seed step gives it the seed's name. So does a volume half still under `next-` after its container half took the name. Any other `next-` snapshot goes.

There are no development setup steps. The default Instance has them done already, so a clone needs none. A clone prepares its container in four direct command phases: container, workspace and Processes, T3 with the agent's files before it, and sessions.

The workspace phase prepares Node and fetches a changed branch pin before starting any Process, with no separate Node-store playbook. A prepared seed keeps its existing recipe and downloaded versions. Every clone gets a private writable store, including one with no Processes, so a Process added later can install its pin. Without a recognized Node/Vite consumer, only the pin fetch is skipped. Each native step reports its timestamps through the existing Operation progress.

Inside the container the workspace phase has two parts, in order. `checkout` takes the bundle's branch, writes the checkout mark and merges `.env`. `services` writes the PHP settings, file rights, Process files and web config, prepares the Node store, and starts the units. Each part is also a phase of its own for a later workflow; both read the same private vault value, and each passes on only its half.

The `check` phase checks a finished clone: this Instance's checkout mark, the agent's Git identity, PHP, and each running unit, with the same `instance.container_process_inactive` code as the sessions phase. It prints the dead unit's public receipt on its own output; the sessions phase keeps it in the run's private folder. The create calls neither part alone and does not call `check` yet.

Conn supports an [explicit branch selection](/reference/conn#threads-in-an-orbit-project) for a clone. It prepares the Node mirror from the admitted remote source commit and checks out its bundle in the existing workspace phase. Dependencies and build still come from the default's seed; checkout runs no Project setup or deploy command again.

A separate job publishes the Route, certificate and DNS. Swamp runs jobs in levels too, but the container job and the Route job share one level and each keeps its own step levels, so the Route steps cannot delay a container phase. The Route checks the TLS handshake, without waiting for the application to start. The create receipt records T3, session and Route readiness times. Workspace access and environment files stay in the container preparation.

#### What is built

The first default Instance and its deployments run on app-dev Nodes. A Project has one `default` Instance in the whole fleet, and every clone of it is made on its Node. The one placement exception is a proof copy on a CI Node: it clones that Node's [seed](#the-seed-on-each-ci-node). Deployment admission refreshes the Project’s [source facts at the deployed commit](/reference/deployments#container-source-facts).

A proof copy's runtime still names the default as its clone source, so the default keeps its runtime while the copy exists. The database refuses a clone on another Node than its default, unless that Node's `proof_seeds` row holds the default's current seed: the same commit and the same time taken (`seed_commit`, `seed_taken_at`).

| Part | What Orbit does |
| --- | --- |
| Container | `instance-<id>` in the project `orbit-instances`, from the image `orbit-instance`, at the lowest free guest address of the bridge. It has no T3 port |
| ACL | The NIC carries `orbitinst0-proxy` and the container's own ACL `instance-<id>`; `orbitinst0-egress` stays on the bridge only. Only the Node's Caddy, from the bridge's address, reaches port 8080 |
| Git | The Node fetches with the read token into its mirror `/var/lib/orbit/instance-repositories/<id>.git`. A bundle carries the branch into the container, and `agent` checks it out in `/srv/workspace` |
| Checkout path | The workspace's path on the Node, inside the container's root file system. It keeps the path unique on the Node; Orbit never works in it from the Node |
| `.env` | In the workspace's application directory, for `www-data` with group `www-data`, mode 0640. Git never takes it: see [the `.env` stays out of Git](#the-env-stays-out-of-git) |
| Deploy steps | Each runs as `agent` at `/srv/workspace`, from its own vault copy ([ADR 0268](/decisions/0268-one-owner-for-the-workspace)). Its environment holds only `HOME=/home/agent`, `USER=agent`, `LOGNAME=agent`, `PATH`, the runtime folder's `VP_HOME` and `VP_NO_UPDATE_CHECK=1`, the [proxy settings](#proxy-settings-in-a-container), `PLAYWRIGHT_BROWSERS_PATH=0` ([Playwright browsers](#playwright-browsers)) and `AGENT_BROWSER_EXECUTABLE_PATH` ([agent-browser](#agent-browser)) |
| `PATH` | The Project's PHP build, then the runtime folder's `bin`, then the system folders |
| FrankenPHP | The unit `orbit-frankenphp.service` runs `frankenphp run --config /etc/orbit/frankenphp.Caddyfile --adapter caddyfile` as `www-data` on port 8080. The Route proxies to the bridge address; the Node runs no PHP pool for it |
| Seed | The first build and each passed deployment take `seed-<commit>` with the units stopped. Older seeds go, except the proof default's [base](#a-retake-keeps-the-seed). The runtime row records the commit |
| Deployment | The variant `container` starts a stopped container and fetches the commit on the Node. It refuses `deployment.checkout_dirty`, `deployment.commit_not_on_branch` and `deployment.branch_diverged` as a checkout does |
| Processes | The Project's development Process definitions run as `orbit-*` units in the container. [Processes](#processes) has the details |

#### The `.env` stays out of Git

Each step that writes `.env` also names it once in the checkout's own `info/exclude`, as `/<path in the checkout>`: the `workspace` phase of a default's first create, and the clone's `checkout` part, which every clone and task copy runs. So Git never takes it, also when the Project's `.gitignore` does not name it. A rerun or a clone of a clone adds no second line. The file's other lines and the Project's files stay as they are.

#### A task copy starts clean

A [task copy](/reference/tasks#task-copy) starts from its start commit alone. The seed holds the default's tree as it was when the seed was taken, with the default's own work in it: modified files and untracked files. A task copy must not carry that work into its checks and its pull request. So the `checkout` part of a task copy cleans the tree once, at its first checkout:

| Step | What happens |
| --- | --- |
| `.env` | Named in `info/exclude` first, so the clean keeps it and the merge still reads the default's file |
| Index flags | Each skip-worktree and assume-unchanged flag from the default's index is cleared, so no edit stays hidden |
| Modified tracked files | `git reset --hard` puts them back to the start commit, staged files and renames too |
| Untracked files | Then `git clean -d -ff`, without `-x`, with the start commit's `.gitignore`: what Git does not ignore goes, nested repositories too. It repeats until a dry run finds nothing new, at most 64 rounds |
| Check | `git status --porcelain --untracked-files=all` must be empty, else the create fails with `instance.create_workspace_failed`, for example for a changed submodule |

Git runs here with the task check's ignore rules: no global or system configuration, and the agent's own ignore file, `~agent/.config/git/ignore`, which the check reads as `agent`. Like the check, it trusts the checkout by name (`safe.directory`), because another user owns it and the global configuration that trusts it is left out. A file name that is not UTF-8 is cleaned too and named with its bytes escaped.

What Git ignores stays: `vendor`, `node_modules`, built assets, a SQLite file and `.env`. They come from the seed and match the start commit, which is the seed's commit.

A task copy runs no setup step. So a file that the Project does not ignore and that the clean removed does not come back. A Project without a `.gitignore` loses its `node_modules` and its build output in a task copy, and its check and Processes run without them. The fix is in the Project's `.gitignore`; Orbit writes none.

The same goes for a local package kept as its own repository in the checkout, and for a second `.env` file, such as `apps/api/.env`, that `.gitignore` does not name: only the Instance's own `.env` is excluded. A `.env.testing` from the seed that the Project does not ignore goes too: it named the default's test database, and the copy's tests then read `.env`, the copy's own database.

A path that the default excluded in its own `.git/info/exclude`, or under an untracked `.gitignore` that ignores itself, is ignored, so it stays in the copy. It never reaches a commit, but the check sees it, and a new file under such a pattern stays out of the commit too.

The create's result names what went, as `workspace_cleaned`: `reset_count` and `removed_count`, and the first 20 names of each kind (`reset`, `removed`; a folder ends in `/`). Names only. The clean ends before the T3 registration, before the create ends and before the copy is `active`. So no turn and no check, the baseline included, sees the default's work. A retry of the checkout after the mark does not clean again: the work in the copy after its first checkout stays. A retry before the mark cleans again and names only what that run removed.

The Gateway marks a task copy in the clone intent with `container.clone.clean_workspace`. Every other clone, from Conn or `instance:create`, has no mark and keeps the default's tree, its modified and untracked files included.

#### Processes

Container Process units order after `network.target`. They do not request `network-online.target`: the container network is configured before its Processes start. Orbit starts units without blocking and checks each unit within a bounded readiness wait.

A container Instance's Processes come from the Project's development Process definitions, or from an existing checkout during [migration](#migrate-a-checkout-instance). Each one is a Process record and an `orbit-process-<id>-<name>.service` unit in the container, as the [Process lifecycle](/reference/processes-and-schedules) renders it for a Node, with these differences:

| Part | In a container |
| --- | --- |
| User | `www-data`, group `www-data`, `UMask=0007`, `HOME=/var/www` |
| Directory | The workspace's application directory, `/srv/workspace` or the Project root in it |
| `PATH` | The Project's PHP build, then runtime `bin` and system folders. Supported names, such as `php8.5` and `node`, resolve at rendering; proven opaque migration argv stays exact |
| Environment | The workspace's `.env`, then the definition's own values in `/etc/orbit/processes/<id>/<sha256>.env`, root only, mode 0600. The values come only from the run's vault copy |
| Runtime | systemd only. Docker definitions refuse with `process.target_unsupported`. New preset definitions are unsupported; migration captures supported `vp-dev` definitions for clone and deployment rendering |

For a development Instance with a dev-server preset Process, `vp-dev` or `vite`, Orbit sets `INERTIA_SSR_HOT_URL=http://127.0.0.1:<port>` in the PHP web runtime unit, `orbit-frankenphp.service`. For `vp-dev` the port is that Instance's own Vite port, including a migrated default's retained port and a clone's assigned port. For `vite` it is the preset's fixed port, 5173. Orbit owns this value. It is absent without a dev-server preset Process, on production Instances and on host checkouts. It is not an application `.env` value or a stored environment record, and `env:sync` does not see it. It directs Inertia's development SSR requests to Vite inside the container; it does not change `public/hot`.

The setting has four limits:

- inertia-laravel reads `config('inertia.ssr.hot_url')`. Its package default maps the environment key, but Laravel's shallow config merge means an app's own `ssr` block replaces that default. Such an app needs this line in the `ssr` block of `config/inertia.php`:

  ```php
  'hot_url' => env('INERTIA_SSR_HOT_URL'),
  ```

- `config:cache` freezes the value at cache time.
- Command-line `php artisan` processes do not inherit the PHP unit's environment.
- Units render on create, clone, migrate and default deploys. Existing defaults get the key at their next deploy. Existing clones need recreation.

The create run installs and starts the units after the deploy steps. A unit whose desired state is stopped is installed, not started.

A clone takes each Process's desired state from the default's copy of the same definition. A Process that is stopped on the default is stopped in the clone: its unit is installed, not started, and the create's readiness check does not count it. `process:start` starts it. A Process that is failed on the default, or that the default has not, runs. A succeeded create names the stopped Processes in its result as `processes_stopped`.

A clone records its own Processes from the same definitions, with its own ids, units and environment files. Its seed holds the default's units, still enabled. So before the copy first starts, Orbit removes every `orbit-*` link in `multi-user.target.wants`, the default's Process units and their `/etc/orbit/processes/<id>` folders. The `*.target.wants` links go first, in their own delete, so a link whose unit file is already gone, also one that an earlier failed clone left, does not fail the clone.

Before the first start, Orbit also writes the copy's own `/etc/machine-id` from its Incus UUID. The clone boots with none of the default's units; each later phase enables the units it sets up. The clone's `processes` phase checks again: it stops and removes every `orbit-process-*` unit and `/etc/orbit/processes/<id>` folder it does not own before it starts its own.

A deployment first records the Project's new definitions and changes the records of changed ones. Then it writes the changed units and new environment files, removes the former ones, and restarts the changed units. Its new seed stops every running unit and starts it again, so each running Process, such as a queue worker, runs the new commit. A container ignores a Process's restart-on-deploy setting.

When the Project removes a development definition, its Process stays in the container until the next deployment of the default. The deployment marks the Process `removing` at admission and leaves it out of the run's units, so the `processes` step stops and removes its unit and environment folder as one of another owner, and the new seed holds neither. A passed deployment deletes the record and names it in its result as `processes_removed` (`[]` when none). A failed deployment keeps the record `removing`, and the next deployment removes it.

`process:start`, `process:stop` and `process:restart` refuse a `removing` Process with `process.removing`. A definition added again under the same name gets a new Process, which replaces the removed one in the same deployment. A Process recorded without a definition, such as one from before definitions were captured, stays. A clone records only the current definitions, and it removes the units of the default's seed before it first starts, so a removed definition never reaches a clone made after the removal. A clone made before it keeps that Process: a clone has no deployment. There, and for a Process recorded without a definition, [`process:destroy --instance`](/cli/process/destroy#a-process-in-an-instance-container) removes it.

The deployment's start waits only for the units that the container's boot starts: FrankenPHP and the enabled Process units. A Process of a new definition has no unit file yet, and a Process that was stopped and is now set to run has a disabled unit. The `processes` step installs both, enables and starts them, and waits for each running unit. A unit that does not become active fails the deployment with the code of its step (`instance.container_processes_failed`, or `instance.container_start_failed` for a unit that boot starts), and the failure detail names the unit (`Not active: <unit>`).

A changed record keeps its status. An active Process stays `active` while it runs its installed unit: `systemd_activation_hash` names the spec that runs, and `systemd_activation_pending` says the record holds a newer spec. A record that is not active becomes `provisioning`. A passed deployment records every Process `active`, with the hash of its new unit. A failed deployment records a Process `failed` when it was not active or when its unit is one the run saw not become active. Every other active Process stays `active` on its old spec. When the deployment was to change it, `failed_step` (`container`) and `error_code` record the failed change beside it, and its activation stays pending.

`process:start`, `process:stop` and `process:restart` run `operation-process-container` as a child of their own workflow: Ansible on the Node changes the unit through `incus exec` and checks its state. Journal tails and unit states use bounded direct reads through the Node’s existing SSH account. A Process list reads all container Process states on each Node in one call. The Gateway redacts journal lines before returning them and stores no raw lines in history. [Process runtime status](/reference/metrics#process-runtime-status) defines the observation fields. A change to a Process of a stopped container fails; the container’s next deployment starts it.

One-shot log reads keep each complete journal line in time order and drop a final line cut by the byte limit.

Repeating a failed create with the same Project, Node and name reuses its container and Route. Use a new idempotency key and keep the same root, branch and domain inputs.

At admission, the retry records the Project's current development Process definitions in the same transaction. It keeps each surviving definition's Process id, updates changed definitions, and removes Processes whose definitions were removed or exclude development. The create run replaces their units and environment files. Fix or remove a failing definition before retry. It runs the create phases again, including the Project's development deploy steps; activation and the seed commit are recorded only after the final receipt succeeds.

`process:create` and `process:update` refuse a container Instance's Process with `operation.kind_unavailable`: the Project's definitions and a deployment change them. `process:destroy` refuses a Process that a current definition owns the same way; to remove it, remove its definition and deploy the default. It removes a Process that no current definition owns, with its Instance named by `--instance`.

A container default without `--database-server` uses the database its Project names in the workspace, such as SQLite. With `--database-server`, the database family creates its database on that server, and the container reaches it by its own [database rules](#network). Dependency installs in the deploy steps reach the internet through the [egress proxy](#egress-and-dns).

A web-serving Project without a PHP version refuses with `project.source_defaults_incomplete`, and one whose PHP build the runtime folder lacks with `instance.host_unavailable`. Setup, `env:import`, transfer, rename and clone refuse a container Instance with `operation.kind_unavailable`: they work on a checkout from the Node.

On a container Instance, `env:sync` writes only the keys of the attached connections, and the keys that `env:update` stored since the last sync, into the existing `.env`, in place, and adds their network paths; see [env:sync on a container Instance](/cli/env/sync#on-a-container-instance). Another Instance of the Project refuses with `instance.default_missing` on any Node but the default's, and on that Node until the default is active with a seed snapshot. Every other Instance is a [clone](#create).

### Create

`orbit instance:create PROJECT NODE NAME` clones the default Instance. A clone's create ends when the user can start a thread: the checkout, T3 and its registration. Its [completion](#complete-a-clone) does the rest. The create copies the default container with `incus copy`, a ZFS clone. Then it starts the container and creates the Instance's branch from the default branch. A nested-fleet clone gets its Club key right after that start.

Then one step, `clone-t3`, runs until the thread is usable. It runs the `checkout` part of the workspace phase beside T3: the branch, the checkout mark and `.env`. Beside it, the step checks the agent settings and the note links, then waits for T3 and adds the T3 project at `/srv/workspace`. T3 has usually started with the copy ([T3 starts with the copy](#t3-starts-with-the-copy)). The T3 part never writes the workspace, and T3 runs Git with `GIT_OPTIONAL_LOCKS=0`, so it takes no lock that the checkout needs.

When both parts have ended, the step checks the two sessions and the checkout. Before, these were three steps on two Swamp levels, and each step cost about 1 s before it did work.

#### T3 starts with the copy

The copy step writes T3's files into the stopped copy before its first start. First the placeholder model environment, then Codex's `config.toml`, Claude Code's `.claude.json` and T3's `settings.json`, the drop-in `/etc/systemd/system/t3.service.d/10-orbit-boot.conf`, and the drop-in that names the [container server](#container-server). The two JSON files merge into the seed's own, as the agent part merges them. Last comes `/etc/orbit/instance`, the file that `t3.service` waits for. The copy's boot then starts T3, and T3's cold start runs beside private names, Node services, mail and branch. No snapshot holds the model environment: the default never has it, and Orbit takes no snapshot of a clone.

Before the first start, Orbit checks the stopped copy's on-disk ID map. A restricted Incus project strips that map during a local copy. On Nodes without idmapped mounts, the files still hold the source's shifted IDs. When the copy records an empty map and the source records a shift, Orbit unshifts the copied rootfs on the btrfs pool of `nested-app-dev`, where an idmapped mount fails. Incus can then shift it to the copy's own map at start.

This preserves owners, setuid and setgid modes, named POSIX ACL entries and file capability root IDs. IDs outside the source map stay unchanged. If normalization fails, Orbit deletes the incomplete copy and fails the create with its error code. A retry copies the seed again; it never resumes partially restored metadata. Sources with an empty map, including Nodes using idmapped mounts, need no change. Another pool driver in this state refuses before start with `instance.clone_idmap_unshift_unsupported`.

The drop-in turns off T3's default dependencies. T3 then starts after `nftables.service`, the local file systems, the journal socket and `systemd-sysctl.service`, and stops before shutdown. T3 skips the wait for `sysinit.target`, which udev's trigger holds about 0.6 s in a container. `Requires=nftables.service` stays in the unit, so T3 still starts only with its firewall.

The image's `After=network-online.target` stays, because a drop-in cannot remove an ordering. A plain clone's boot does not pull that target in, so it holds nothing there. In a nested-fleet clone the inner Incus wants that target, so T3 there still waits for eth0's route.

On a throwaway clone (2026-11-14, load 12-31), T3's unit started 0.35-0.43 s after systemd's userspace start. Without the drop-in it started 1.14-1.41 s after. The image keeps its unit. The drop-in is one of the agent files, so a migrated checkout with a T3 gets it too; the agent push makes its folder.

The model environment reaches only the copy step, on its one SSH stdin. The T3 step's stdin is the workspace's private JSON; its agent part finds the model environment in place and fails without it. The Gateway writes the run's Git credential line, the line `#orbit-model-environment`, and the model environment into one execution copy, `instance-create-clone-<operation>`. The copy step's `git_credential` reference names it. Git's credential store skips the frame line and the rest. An Instance's destroy deletes the copy with the create's other copies, also after a failed or cancelled create.

The Gateway also writes a clone's launch copy, `instance-create-launch-<operation>`, and sends the launcher `swamp/scripts/instance_clone_launch.py` as the intent's `clone_launcher`. The launch copy is ONE line of JSON. Its `stage` holds the public part: the launcher, the clone script and its helpers from the Gateway release's `swamp/` folder, the run's container intent and the SSH account. Its `private` holds the clone copy's text.

The scripts take about 187 KB, more than one command argument may hold, so they cross the copy step's one SSH stdin, framed in one vault value. Its reference is `clone_launch`. The copy step runs `python3 -c <clone_launcher> <private folder> stage container`: the launcher writes `stage` into the private folder and runs the container part with only `private` on its stdin. A clone runs no `input` and no `clone-input` step.

Why one line: Swamp registers every value that a run reads with `vault.get` for redaction. It turns a value that holds a newline into one regular expression, and V8 refuses that expression when one line is about 32 KB or longer. Release 2080 read an earlier stage copy (the public JSON line, a newline, the private text): every clone create failed at `clone-container` with `Regular expression too large`, and the release was rolled back.

A one-line value is matched as a plain string; a whole create's redaction then costs a few milliseconds. The launch copy may hold at most `CloneStage::MaxBytes` (1 MiB); a larger one refuses the create before its run with `instance.clone_launch_too_large`.

Swamp masks a registered value only as a whole, never a part of it: a token or model key inside the launch copy is not masked when it appears alone, as with the clone copy before it. The clone script's own redaction keeps them out of every failure detail: each line and each `KEY=VALUE` value of its stdin, and credentials by shape (URLs, `Authorization`, GitHub tokens, 64-hex keys). Its stdout carries only its own JSON receipt. Release 2084 also wrote the bare token and model key as copies of their own (`instance-create-redact-token-<operation>`, `instance-create-redact-model-<operation>`); no step read them, and the Gateway writes them no more.

The destroy deletes the launch copy with the clone copy, and the earlier stage and bare copies of releases 2079 and 2084.

Once the workflow reads it, a clone runs the scripts of the Gateway release that admitted it. A release loads Swamp before its Gateway switches, so a create admitted just before a release runs the older scripts on the newer workflow. A change to the clone script's phases, its argument list, or the lines and timings that the result reads must work across that one release: the newer workflow and result reader must accept what the older scripts do and print.

The T3 step does not start a T3 that runs. It starts T3 when T3 does not run, for example on a retry over a copy that an earlier attempt started. Before it starts T3, it reloads systemd, so the start reads the drop-ins the agent part wrote, the [container server's](#container-server) among them.

A failed create stops its copy, and a retry writes the model environment again into a copy that is not running. Only a copy that a release before 2065 started and that still runs holds none: its retry fails in the agent part with `instance.container_agent_failed`; remove it with `instance:destroy` and create it again.

A T3 that failed at boot is started once more, after `systemctl reset-failed`, and the step names it as `restart:t3` in its changes. `timings.container.t3_files` is 1 when the copy step wrote T3's files, and `timings.t3.at_boot` is 1 when T3 ran as the T3 step began.

Swamp starts a level of steps only when the whole level before it has ended ([Swamp shape](https://github.com/hardimpactdev/orbit/blob/main/swamp/docs/swamp-shape.md)). The T3 step is in the level after the copy and the Club key, so it starts when the Club key step ends or skips.

The sessions check waits for both parts, because it checks the branch and the checkout mark. It checks no Process unit: the units start in the completion. A failed part writes its name into the step's private `t3.json`, and the result names the phase: `workspace` for the checkout, `t3` for the agent part or T3, `register` for the sessions check. `clone-session-copy` brings the file to `<run folder>-t3.json` on the Swamp Node. Last, `clone-register` registers the T3 server ([T3 registration](#t3-registration-and-pairing)) and builds the create's result. The [create chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/create.mmd) has the steps.

Each Swamp behavior step costs about 0.9 s of dispatch, also a local one; an assert costs none. A ready clone runs four behavior steps one after the other. The levels and their seconds, from the step times of release 2071:

| Job | Steps by level | Seconds |
| --- | --- | --- |
| (start) | Gateway admission, Swamp start | 1.7 to 2.2 |
| `input` | skipped for a clone | 0 |
| `clone` | `clone-container` (the stage, then the copy) · `clone-club-key` (skipped unless nested) · `clone-t3` (the checkout beside T3, then the sessions check) · `clone-session-copy` | 5.4 · 0 · 4.4 · 1.4 |
| `publication` | `clone-route-input` beside `clone-route-csr-<i>` · `clone-route` (with its TLS probe), beside the `clone` job | 3 to 4 · 5 to 7 |
| `create` | `clone-register` beside `clone-private-cleanup` · `finish` · `stop-input` and `withdraw-route` · `stop-container` · `accepted` and the cleanup | 1.3 · 0 · 0 · 0 · 0 |

On a ready clone, `clone-register` builds the create's result from the same inputs as `finish` (one YAML anchor, `create_inputs`), removes the copied `t3.json` and the run folder, and returns `ready` and `removed`. `finish`, `stop-input`, `stop-container`, `withdraw-route` and the cleanup step then skip at no cost, and the run ends with the `accepted` assert. The Gateway reads the result from `clone-register` when `finish` skipped (the terminal `fallback`, [Swamp command steps](/reference/swamp-command-steps#terminal-outputs)).

After a failure, `finish` builds the failed result, as before. A clone has no input step, so `stop-input` writes the run folder and playbooks that `stop-container` reads. The cleanup step then removes the run folder and the copied `t3.json`. These steps run only after a failure, so they cost the ready create nothing.

A plain clone's create succeeds about 15 seconds after the click by these step times, against 19 to 21 seconds measured with the earlier seven dispatches (release 2071). The baseline read and the admission of the completion add about 0.5 seconds in the Gateway.

When the run succeeds, the Gateway reads the [workspace baseline](#workspace-work-state) over SSH, before its transaction. Then, in one transaction, it records the T3 server, writes the baseline and turns the create `succeeded`. The clone stays `source_resolved` with its `starting_commit`, and its runtime is `running`. In the same transaction, after the create row is terminal, the Gateway admits `instance.complete` with the Instance lock. The create's result names it in `completion_operation_id`. The completion's vault copy and its Swamp start run after the commit.

#### The address during the create

Decided on 2026-11-14 by the owner: the create publishes the clone's address beside the copy, so the address row of a create is not the last to finish. The create's admission captures the Route publication from the current site catalog. A Route that the capture refuses, such as a Cluster Route without a recorded Router (`cluster.router_required`), refuses the create before anything is recorded.

The admission then looks at the Route publication hold of the Route's serving Nodes ([coordinate publication](/reference/routes#coordinate-publication)):

- **Free.** No publisher holds a Node and no older Route write waits for one. The create takes the hold in its admission transaction.
- **Room.** A Caddy-only create needs fewer than eight shared writers on each Node; any other create needs none.
- **Shared or alone.** A publication that is Caddy only takes a shared hold. Any other takes the Nodes alone.
- **Publication.** With the hold, the create's job `publication` runs beside the clone steps: `clone-route-csr-<i>`, then `clone-route`, the Route create child with its TLS probe.
- **Held.** The create never waits. Its intent carries no publication, and the completion publishes the address, as before.

The site step of `clone-route` does not write the Caddyfile that the Gateway rendered. It puts the Route's own Caddy groups into what the Node holds, under the Node lock, and leaves every other site as it is ([publish a development site](/reference/routes#publish-a-development-site)).

Caddy-only creates admitted together share the Nodes, so each publishes its address in its own create. A create that writes more than the Caddyfile, such as a PHP-FPM pool or a private DNS record, takes the Nodes alone. Of two such creates admitted in the same second, exactly one takes them, and the other gets the completion path. No thread ever waits for another create.

The Gateway frees the hold when `clone-route` ends, not at the create's end. When the run's progress event says `clone-route` succeeded, the Gateway turns the Route `active` with its sites published and frees the Nodes in one transaction, so the next Route writer's render keeps the site. No step calls the Gateway. A failed `clone-route`, or a lost event, keeps the Nodes until the create's terminal state frees them. A waiting Route write that publishes a render made at its admission is refused at its start with `route.publication_stale` when the sites of its Nodes changed meanwhile ([coordinate publication](/reference/routes#coordinate-publication)).

Both publication steps allow their failure (`allowFailure`), so a failed publication fails neither the run nor the create. A removal while the create runs is accepted at once: it cancels the create and waits behind it ([remove a clone whose create runs](/reference/conn#remove-a-clone-whose-create-runs)).

No publication step needs the running app. The site step skips the Route access and `APP_URL`, because the copy's root disk does not exist yet; `.env` comes with the checkout. Caddy proxies to the bridge address that the admission reserved. The TLS probe is a handshake with the Node's Caddy and sends no request. So when the create succeeds, the address answers TLS, but the app answers 502 until the completion's `clone-services` starts the units, about 10 to 20 seconds later.

`clone-register` and `finish` wait for both jobs. At the measured times the publication ends at about the same time as the clone steps: 8 to 13 seconds against 9 to 16 seconds from the copy to `clone-session-copy`. A Route clone's site child reads the run folder that `clone-route-input` writes beside the CSR, not the create's input. The create's result says what happened in `route_publication`:

| Value | Meaning |
| --- | --- |
| `published` | The create published the address. The completion publishes nothing. |
| `deferred` | The Route Nodes were held at admission, or a Node refused the merge of the site. The completion publishes the address. |
| `failed` | The publication failed in the create, which still succeeds. The Route records `instance.create_route_failed`, and the completion publishes it again. |

Beside `deferred`, `route_publication_reason` says why:

| Reason | Meaning |
| --- | --- |
| `nodes_held` | At admission, a publisher that writes whole files held a Route Node; for a create that takes its Nodes alone, any writer held one. |
| `share_cap` | At admission, eight shared writers held a Route Node. |
| `waiter_first` | At admission, an older Route write waited for one of the Nodes, and goes first. |
| `node_refused` | A Node refused the merge of the site in `clone-route`. The step's log names the Node's code, such as `caddy.publish_busy`. |

The admission logs one info line, `Clone create deferred its Route publication`, with the reason and the shared writers on each Route Node, after its transaction commits. A Node refusal logs the same line when the create ends. Only the API result carries the reason. Conn and the web views read `route_publication` alone and show nothing new. The CLI prints it only inside the whole result: `--json`, and the `Result` line of `operation:show`. A create that an earlier release admitted has no reason.

A clone without a Route has no `route_publication`. A create that fails after `clone-route` succeeded keeps the address up; a publication that started and did not succeed is withdrawn by the create's `withdraw-route`, under the Nodes it still holds.

The completion captures the Route again only when its sites are not published, because fleet state can change in between: a Cluster's Router, the stored environment, an Ingress firewall. If it refuses then, the projection records a failed `instance.complete` with that code, `failed_step: complete` and the recovery `instance:complete`; any other error at that point is recorded the same way with `operation.admission_failed`. The create still succeeds and names that completion, so Conn, the failure alerts and a task copy see an ordinary failed completion.

A clone is fully set up only when its `status` is `active`. Only a succeeded completion sets it. Every reader of `active` keeps its meaning: deploy, migrate, sleep, renewal and task copies. `orbit instance:create --wait-active` follows both Operations and exits 0 only on `active`.

A clone starts at the commit of the default's newest seed snapshot, with its dependencies installed. It copies no development deploy commands to the execution vault and runs no deploy step. Its branch is the matching origin branch, fetched on the Node, or a new branch at the seed's commit.

A branch that the seed already holds needs no network. A new branch at the seed's commit fetches nothing and fills no Node mirror. The guest checks it out from the seed's own repository, with no bundle.

A proof copy's branch is new, and the origin lacks it, so its clone fetches nothing and makes no Node mirror. No clone intent names a mirror of the default. The proof run then fetches the commit under proof by its SHA from the public repository, through the copy's own egress ([proof:run](/cli/proof/run#the-run)). So a proof copy on a CI Node, which has no mirror of the Project, needs none.

The rule is the same on every Node: one way brings the commit in. A carried bundle or a mirror on each CI Node would add a second way and a second copy of the repository, for a commit that the copy fetches anyway.

A proof copy also takes one CI place on its Node. Its create holds that place first, in the step `clone-ci-hold`, before the copy exists: the role's hold script stops idle runners on the Node down to the places left to runners. Every other clone skips the step. The copy's destroy releases the hold ([Proof places on a CI Node](/reference/node-provisioning#proof-places-on-a-ci-node)).

An [explicit branch choice](/reference/conn#threads-in-an-orbit-project) runs no `ls-remote` in the clone, because its admission read GitHub. Only an existing branch, or a new branch from another commit, is fetched. A create by name only still reads GitHub once, to learn whether the origin has the branch.

Someone may make the branch on GitHub between the admission and the copy. The copy still starts at the admitted commit. When the GitHub branch has commits the copy lacks, a later push of the branch is refused as `tasks.not_fast_forward`. When it has none, the push fast-forwards it, and no commit is lost. A clone's Node mirror exists only after a fetch: its own create's, or a later deployment's.

The container, checkout and T3 phases print the seconds of their parts. The container phase names its ACL, copy, unit cleanup, boot files, T3's files, firewall, network check, ID map, start, private names, Node services, mail and branch. The workspace phase names its bundle and its guest checkout. The T3 phase names its agent part, T3's first answer and its sessions check. Two parts are facts, 1 or 0: `t3_files` and `at_boot` ([T3 starts with the copy](#t3-starts-with-the-copy)). The create's result carries them as `timings`: names and seconds only.

Clone readiness checks PHP only when the Project has a PHP runtime. A Project without PHP can register T3 and its sessions. The Operation keeps its stable error code and shows the failed phase, command and exit code in `error.detail`; command output stays private.

Its `.env` is a copy of the default's, except the keys it must own: see [the environment of a clone](#the-environment-of-a-clone). When the Instance's branch changes a lockfile later, the agent or the user installs the change in the workspace with the runtime folder's `composer` or `bun`. A sleeping default Instance is cloned from its snapshot without a start. A seed snapshot stays while a clone depends on it; Orbit deletes older seed snapshots that no clone uses.

#### The environment of a clone

A clone starts with its default's settings. The seed snapshot holds the default's `.env` as it was at the default's last deployment, so the copied container already has it. The workspace phase keeps that file as written, `${VAR}` references included, and changes only these keys:

- **The clone's own keys.** Orbit writes them from the clone's render.
- **The default's connection keys.** The clone never copies them.
- **Copies of the default's credentials.** The clone drops them.
- **Values that point at the default's own site.** The clone points them at its own.

The clone's own keys are the keys of its own database under `DB` and of its own [Valkey user](#a-clones-valkey-user), with `<PREFIX>_PREFIX` and `HORIZON_PREFIX`. `APP_URL` names the clone's Route, and the mail keys Orbit renders are its own too. A connection key that the clone's connection does not use goes.

The default's connection keys are `<PREFIX>_CONNECTION`, `_HOST`, `_PORT`, `_DATABASE`, `_USERNAME`, `_PASSWORD` and `_URL` of every connection attached to the default. The clone gets its own, or none. A connection that the default's attachment marks [shared](/reference/database-connections#share-a-connection-with-clones) is the exception: the clone gets the same attachment, and its keys are the clone's own keys, rendered from the connection record. When the default has a [Reverb app](/reference/websocket-apps), its keys (`BROADCAST_CONNECTION` and `REVERB_*`) are the default's too; the clone gets the render's.

A copy of a credential is a key whose value equals the default's database name, user name or password under an attached prefix, or its Reverb secret. A Redis database number is no credential. A key whose value contains such a password, for example `DATABASE_URL`, is a copy too, and so is a comment line that holds one. The check runs inside the clone, against the copied file.

A value points at the default's own site when one of its items is the domain of the default's Route without a web root. A Route with a web root serves other content, so its domain stays. An item is the whole value, or one part of a list split at commas, semicolons or white space before any `?` or `#`. It is the host alone, or the host with a scheme or `//`, a leading `.` or `*.`, a port, and a path, query or fragment.

The clone puts its own Route host in place of that host and keeps the rest of the item, so `https://<default host>:5173` becomes `https://<clone host>:5173`. The two hosts can differ in form, for example `site.test` and `feature.site-website.test`. A host inside another name, a path, a query or a user part (`user@host`) is no item and stays, and so does a `${APP_URL}` reference, which already follows the clone's `APP_URL`. A key whose value holds a copy of a credential is dropped before this step.

`VITE_APP_URL` in the file then names the clone's Route, as the `vite` preset sets it on its Process unit. The unit's value wins over the file for that Vite process; PHP and other Processes read the file. `INERTIA_SSR_HOT_URL` lives only on the PHP unit and points at the container's own loopback; a copied loopback value is no host of the default and stays.

Mail needs no rewrite. Every container has its own loopback proxies at `127.0.0.1:1025` and `2525` to the Node's one Mailpit, so the default's `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME` and `MAIL_PASSWORD`, which point at that loopback, reach the same Mailpit from the clone. Orbit renders only `MAIL_MAILER`, when the default's is `log`.

Keys of the render that the default's file lacks are added. `APP_KEY` comes from the default's file, because the cloned database holds data encrypted with it. When the default has no `.env` in its seed, the render is the file, as for any new Instance. A create that an earlier Gateway release admitted carries no key names; its clone also gets the render as the file. A create admitted with key names but without the Route hosts keeps every copied value as written.

Only key names leave the container. The Gateway sends the names of the three groups and the Route host of the default and of the clone with the render. The Operation result names the keys the clone copied, `environment.copied`, the keys it dropped, `environment.dropped`, the copied keys it pointed at its own Route host, `environment.rewritten`, and the keys of the connections it shares with its default, `environment.shared`. No `.env` value enters an argument, a result or a log.

A value set by hand in the default's `.env` after its last deployment reaches clones with the next seed snapshot. A hand-set key that points at the default's other data, such as a storage bucket or a database that Orbit does not attach, is shared with the clone. Its name is in `environment.copied`.

#### What is built for clones

Clones run on their container default's Node, except proof copies on CI seed holders.

| Part | What Orbit does |
| --- | --- |
| Default | The Project's `default` runs in a container on the same Node, is active and has a seed snapshot. Otherwise `instance.default_missing` |
| T3 registration | A contribution is loaded to record T3, even while Conn is off, else `operation.kind_unavailable` |
| Models | A ready route is optional. The create result reports `model_route`; see [Model access](#model-access) |
| Runtime row | The next free bridge address, the next free T3 port after the Node's last one ([rule](#prepare-a-node)), and the default as clone source |
| Copy | `incus copy` of `seed-<commit>` with the clone's own address and ACL. A stopped default needs no start |
| First start | Before it, the copy loses its `orbit-*` boot links and the default's Process units and folders. It gets its own machine-id and the [shared files](#existing-containers) |
| ACL | The Node's Caddy reaches port 8080 and ports 1024 to 65535, any WireGuard peer reaches port 3773, and the container reaches its database server's port |
| T3 port | The proxy device `t3` in NAT mode, from the Node's WireGuard address and the T3 port to port 3773 |
| UFW | Under the comment prefix `orbit:instance-<id>-`: a route deny from the task VM range, then a route allow to port 3773; and the rule to the database server |
| Agent files | `/home/agent/.config/orbit/model.env` with the placeholder key from the vault, Codex's and Claude Code's settings that point at the forwarder, the note links, and `/etc/orbit/instance`. ([T3 starts with the copy](#t3-starts-with-the-copy)) |
| Processes | In the [completion](#complete-a-clone): FrankenPHP's unit, rewritten for the clone's own address, and the Project's Process units |
| T3 | Its first start, a check of `/.well-known/t3/environment` within 30 seconds, and the T3 project `/srv/workspace` |
| Registration | [T3 registration](#t3-registration-and-pairing), from the run's result |

A failed or cancelled create keeps its reservation, runtime, and any container, workspace, owned databases, test databases and role until the owner acts. A refused retry removes none of them. After work starts, Orbit attempts to stop the container. A clone's create creates no database. It withdraws a publication that started and did not succeed; an address it published stays until a retry or `instance:destroy`. Compensation can fail; its step then stays failed. Shared service endpoint records and rules may remain even after destroy until endpoint lifecycle work is live.

A run that succeeded can still fail the create in the Gateway: when the [baseline read](#workspace-work-state) fails, the create fails with `instance.workspace_baseline_unavailable` and `failed_step: register`. The T3 server is not recorded and no completion starts. The run already ended without its container stop, so the container keeps running; a retry runs the create again over it, and every phase of the create accepts a running copy.

Its Operation reports `recovery.state=retained_for_retry` and names `instance:create` with the same Project, Node, name and options, using a new idempotency key. It also names `instance:destroy INSTANCE --yes --force`. A failed or cancelled default create reports the same retry path and marks destroy unavailable; container-default removal is not built. A resume also issues new T3 sessions. When it succeeds, it admits a new completion, also when an earlier completion of the clone failed or never ran. The `Orbit Gateway` session of the failed run stays valid in T3 until the cleanup after the next renewal revokes it; its `Orbit Swamp` session stays valid until it ends.

A resume never moves a branch that this Instance checked out. After its first checkout, the workspace phase checks that the branch and the commit are the fetched ones. Then it writes the mark `/var/lib/orbit/workspace-checkout`, owned by root, with the Instance id and that commit. A default never writes this mark, and a mark that names another Instance does not count. So the first run checks out the fetched commit, even when the copy is already on a branch with the same name.

When the mark names this Instance, a resume skips the fetch and the checkout, and commits on the branch stay. The sessions check needs only the branch name and the mark, not the commit. The result's `commit` is the commit of the mark, so `starting_commit` stays the commit that the workspace started at, not a later commit of the user.

A clone's create checks no Process unit. A dead Process fails the completion's `check` step with `instance.container_process_inactive` and its `process_failure` receipt. When the Process comes from a Project definition, the completion's recovery says `retry.requires=project_process_definition`: change or remove the definition, then run `instance:complete`. Its admission installs the Project's development definitions as they are then.

A deployment of the default can replace the admitted seed before the copy. The copy then [waits for the retake](#a-retake-keeps-the-seed) and copies the new seed. A default with no `seed-` or `next-` snapshot at all refuses with `instance.default_seed_missing`: deploy the default to take a seed. A retake that still runs after 120 seconds refuses with `instance.default_seed_moved`. A clone already copied does not need its seed: on ZFS, Incus keeps a deleted snapshot's blocks while a clone uses them.

#### What a clone has at its first start

The clone script writes these, so a new clone works with no converge or `env:sync`:

| Part | When | What the copy has |
| --- | --- | --- |
| Trust | Before the first start | The Orbit root as a local CA file, its two links, and in `/etc/ssl/certs/ca-certificates.crt`, so curl, PHP and Chromium trust Orbit's sites. See [trust](#trust) |
| `NO_PROXY` | Before the first start | The composed value in the `environment.*` keys and in both drop-ins, with the private names. See [proxy settings](#proxy-settings-in-a-container) |
| Private names | Right after the first start | The plan's `private_names` in `/etc/hosts`, such as the clone's own Route names. See [private names](#private-names-of-the-access-plan) |
| Machine name | Before and right after the first start | `/etc/machine-info` before it; the kernel hostname, `/etc/hostname` and the `127.0.1.1` line right after it. See [the machine name](#the-machine-name) |

The hosts block comes after the start because the copy's own Incus template rewrites `/etc/hosts` at that start. The stock image's metadata holds `templates: /etc/hosts: when: [create, copy]`, which renders `127.0.1.1 <name>` and the localhost lines. A clone copies that metadata from its default, so a write before the start is lost.

Right after the start, the clone script writes `/etc/hostname`, sets the kernel hostname and reads `/etc/hosts` in one `incus exec`, as `env:sync` reads it. It renders the file with the same `hosts` command and pushes it as root with `0644` only when its lines change. This is still before the create reports anything. A failure fails the create with `instance.clone_private_names_failed`, and the create's `stop-container` step stops the started copy. A retry runs the write again.

Orbit does not change the template instead. The template lives in each container's own metadata, copied from its default. A new image would not reach existing defaults, and editing each default's metadata would add hidden state.

To check a new clone, run `getent hosts <its Route name>` inside it right after the create. It answers with the bridge's gateway address.

A task copy is a clone like any other, so it gets the same. So does a clone in the nested-fleet project, and a clone that an inner Gateway makes in its own fleet.

### Complete a clone

[`instance:complete`](/cli/instance/complete) is the second Operation of a clone create, kind `instance.complete` ([why](#a-clone-create-is-two-operations)). It does the part of a create that the user does not wait for: the clone's database, a nested fleet, the Processes, a check, and the Route when the create did not publish it. The `checkout` and `T3` parts stay in the create. A clone is fully set up when its `status` is `active`, and only a succeeded completion makes it so.

The Gateway admits it for a container clone that is `source_resolved`, has its starting commit and has a registered T3 server; it takes the Instance lock. Anything else refuses with `instance.complete_unavailable` and changes nothing.

The input is the create's admitted database plan and Processes. A Route whose sites are not published gets a publication captured again from the current site catalog: its Nodes were held at the create's admission, its publication failed in the create, or an earlier completion failed to publish it. The run has its own private folder and its own vault copy, so it never reads the create's folder.

The Gateway frames that copy, `instance-complete-processes-<operation>`, when the run starts. It is ONE line of JSON, as the create's [launch copy](#create) is, because Swamp turns a vault value with a newline into one regular expression:

- `stage`: the release scripts, the run's container intent and the SSH account, the same public part as the create's launch copy.
- `private`: the Process environment files as `{"processes": ...}`, the text the services part reads.
- `processes`: the same files again, for a workflow that stages the scripts from its `input` step and gives the whole copy to `services`.

A copy over 1 MiB refuses the completion with `instance.complete_launch_too_large` before its run starts. The run's intent carries the launcher program (`clone_launcher`).

The clone script's `complete` phase runs the services part and then the check in one dispatch. A services failure fails the phase. The check's outcome is its output: its own line, a dead unit's receipt, or no line. The phase then ends 0, so the step keeps that output.

The run's levels, from [Swamp shape](https://github.com/hardimpactdev/orbit/blob/main/swamp/docs/swamp-shape.md):

| Job | Steps by level |
| --- | --- |
| `input` | the intent, only for a Route the create did not publish or a nested fleet; a plain clone skips it |
| `complete` | the database starts, the Valkey user, the fleet start · the database check · the fill · `clone-services` |
| `publication` | the CSRs · the Route create child, only when the input captured the Route |
| `result` | `finish` and the private-folder cleanup · `withdraw-route` on failure, and the run-folder cleanup · `accepted` |

`clone-services` is the completion's one Node step. It runs `python3 -c <clone_launcher> <private folder> stage complete`: the launcher stages the copy's `stage` and runs the `complete` phase with only `private` on its stdin.

The phase runs the `services` part of the workspace phase: PHP settings, file rights, Process files, web config, the Node store and the units. It refuses when the checkout mark names another Instance. It writes no tracked file, so the user's work in the checkout stays as it is. Then it runs the `check`. A dead unit gives `instance.container_process_inactive`: the phase prints its `process_failure` receipt as its output and ends 0, and `finish` reads the receipt from it. The step's outer bound is 185 seconds: 115 for the services, 55 for the check, and the stage.

Success turns the Route `active`, then the Instance `active` with `provisioning_step=active` and `first_setup_pending=false`, in one transaction. A failed or cancelled run withdraws a Route that it published. An address the create published stays up and answers 502 until a retry or `instance:destroy`, which withdraws it; a retry that succeeds keeps it as it is. The run keeps the clone `source_resolved` with `failed_step` and `error_code`. The runtime stays `running` and the Conn environment stays registered, so the user keeps the thread. The result's `recovery.retry.command` is `instance:complete`; `instance:destroy` removes the clone as for a failed create.

A completion that publishes the Route holds its Nodes for the whole run; a completion with no publication holds none. A clone's create holds the Nodes only when they are free at its admission, and only until `clone-route` ends, so two creates admitted together never wait on each other. The budget is 1,800 seconds: the 600-second fill and the 900-second fleet start with margin.

The create's terminal projection admits the first completion. A completion that ends moves a [task copy](/reference/tasks#task-copy) on: `active` makes its task run. A failure keeps the copy, and its task stays `reserved` and asks for assistance with the retry.

#### A failed clone phase names its command

Every phase of a clone's create and completion runs `swamp/scripts/instance_clone.py` on the Node. When one of its commands fails, the Operation's `error.detail` holds three things:

1. The code line, such as `instance.container_services_failed: command incus exited 1`.
2. What the command did, in public words, such as `(incus exec instance-7 python3 guest services)`. No option or `-c` program appears.
3. The end of the command's stderr, in whole lines.

The whole detail has at most 4,000 UTF-8 bytes. The native step adds its own words, and the Gateway keeps 4,096 bytes.

Native run history keeps a step's stderr. So the script redacts the text before it prints it:

- Each private value of at least 8 characters becomes `[REDACTED]`.
- The private values are the strings of the framed vault value. Their lines and the values of their `KEY=VALUE` lines count too.
- Credentials become `[REDACTED]` by their shape, as in the Gateway's redaction.
- These shapes are URL user parts, `Authorization` and `Bearer` values, GitHub tokens, PEM blocks and 64-character hex strings.
- A `password=` or `token=` pair loses its value.
- The script drops a line that still holds an 8-character piece of a private value.

The Gateway redacts the detail once more with the Instance's stored values.

The guest phase in the container also hands on the stderr of its own failed `git`, `mkdir` or `systemctl`, on the lines after its diagnostic. It never hands on the stderr of `curl`, `php`, `t3` or `python3`: those commands can see a token that T3 issues in the container or a Project's value, and the Node script cannot know those to redact them. Any other failure of the guest names the error's type, and for a file error its cause and path.

The `workspace` and `services` parts make no Incus file call. Incus serves a container's files through one `forkfile` helper per container, and the helper exits after about 15 seconds without a request. A request that reaches it just before that exit is never served: `incus file push` fails with `Error: error receiving version packet from server: server unexpectedly closed connection`. A completion's first file call comes about 15 seconds after the create's last one, so it could meet that exit.

So the Node script puts the Node runtime helper (`/usr/local/libexec/orbit-instance-node-runtime.py`) in the guest's intent. The guest writes it, owned by root with mode 0755, in the exec it runs anyway. The other phases' file calls come seconds apart within one create. A failed one names its command and Incus's words. Nothing retries it.

### Clone the database

The database family clones every database, whether the server is on the Instance's Node or on another Node. The clone lands on the default's server, unless `instance:create --database-server` names another one.

- **PostgreSQL, same server.** `CREATE DATABASE <new> TEMPLATE <default>`. PostgreSQL copies only from a database without other sessions.
- **The moment of the copy.** Orbit refuses new connections to the default database and ends its sessions. It copies, then allows connections again. The default Instance sees refused connections for that moment.
- **A failed copy.** A cleanup step that always runs allows connections again.
- **The owner.** Every object of the default's owner in the copy passes to the clone's owner. Only the clone's owner may connect.

Other servers and drivers:

- **PostgreSQL, another server.** `pg_dump` of the default database in one snapshot, restored with `pg_restore --no-owner --no-privileges --single-transaction`.
- **Not built yet.** A clone that names another server refuses with `instance.database_clone_unsupported`.
- **MySQL.** A dump and restore, as the database family copies MySQL today.
- **SQLite.** A file in the workspace comes with the ZFS clone.
- **Valkey.** No data is copied. The clone gets its own ACL user on the default's server, attached under the same prefix. See [A clone's Valkey user](#a-clones-valkey-user).

Orbit creates the owner and grants; no path copies roles.

#### A clone's Valkey user

A clone of a default with a Valkey target never uses the default's user, password or keys. Its own user has one key pattern, `orbit_instance_<id>_*`. A database index is no boundary, because Valkey has no ACL rule for it.

The user has the default user's commands and database index, and no channels. It never gets FLUSHDB, even when the default's user has it. FLUSHDB empties a whole index, and the clone shares the default's index. Laravel's `cache:clear` on a Redis store then answers `NOPERM`.

For a Laravel Project, `.env` also gets two keys in the framework's own forms:

- `<PREFIX>_PREFIX=orbit_instance_<id>_database_`
- `HORIZON_PREFIX=orbit_instance_<id>_horizon:`

Another Project gets only the connection keys, and its app must keep its keys under `orbit_instance_<id>_`. Orbit sets no other key, such as `QUEUE_CONNECTION`. Those come from the Project's template.

A clone refuses with `instance.database_clone_unsupported` when the default has more than one Valkey target. The same code refuses a default whose Valkey connection has no Orbit-made user, or whose user grants pub/sub. Destroy deletes the clone's user; the default's user stays.

### Destroy

`orbit instance:destroy INSTANCE --yes --force` removes a non-default container Instance, cloned or migrated. The default is the Instance named `default`. This first slice requires `--force`: it runs no work or turn checks and no Project teardown steps. Guarded removal follows separately. A call without force refuses with `instance.container_destroy_force_required`.

A container default refuses with `operation.kind_unavailable`, except a default whose Project is on the operator list `ORBIT_INSTANCES_DEFAULT_DESTROY_PROJECTS` and that no Instance copies (else `instance.default_has_copies`). The operator lists only the Project of a nested-fleet trial default, made with an [inner volume](#the-inner-volume) or before it; the code does not check the kind of Project.

The same removal deletes its container and its seed snapshots, and its inner volume when it has one; a stopped container needs no start. Only the list tells a trial default from the first nested-fleet default: the operator never lists that Project, and while any copy of it exists the copy check refuses too.

A removal from Conn without acknowledgement reads the [workspace work state](#workspace-work-state) first and removes only a `clean` workspace. The CLI's rules do not change.

The run withdraws the clone's Route, drops its owned server databases, then stops and deletes its Incus container with its Processes, T3 history, snapshots, storage volume and published proxy device. It removes the `instance-<id>` ACL from Incus project `default`, its UFW rules, its Node repository mirror and, for a task copy, the Node scratch of [its sync](/reference/tasks#bring-main-into-a-copy), `/var/tmp/orbit-task-base-<id>`, which a cancelled sync may leave; it holds no credential. Deleting T3 retires every session and unused pairing link. Terminal projection removes execution copies and Conn records, then deletes the runtime row to free its address and T3 port. The default and other clones stay.

An Instance with an [inner volume](#the-inner-volume) loses that volume and its snapshots too, after the container. The run checks that the volume is gone. A part that is already gone counts as removed, and a record whose ZFS dataset is gone still goes ([Retry and ownership](/reference/instance-removal#retry-and-ownership)).

The run deletes each UFW rule by the rule itself, as `ufw show added` lists it under the Instance's comment. It never deletes by number: another removal on the Node can delete a rule at the same time and shift every number above it.

A migrated Instance's retained Git directory survives destroy and is purged only by hand. Its successful destroy Operation result keeps `retained_git_path` and `retained_git_at` after the Instance record is deleted; [instance:destroy](/cli/instance/destroy) explains how to read them.

A failed removal retains its intent and reserved port. A destroy whose native start failed for good (for example `swamp.start_unavailable`) also leaves its removal `failed`, at `source_preparation` or at the step an earlier failed run stopped at, with the Instance still `removing`; that start changed nothing on the Node. A fresh forced destroy accepts resources already gone and finishes cleanup. After withdrawal, the Route stays down until removal completes. The [clone destroy chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/destroy.mmd) has the steps.

### Workspace work state

Before a removal, Conn asks whether the workspace holds work that exists nowhere else ([Remove an Instance](/reference/conn#remove-an-instance)). The Gateway reads it live with one bounded SSH call to the Node. The call runs a fixed reader, `resources/instances/workspace-git-read.py`, as the [container Process read](/reference/node-agent#commands) does.

The reader first asks Incus whether the container runs. A stopped container answers `unknown` with `asleep` when its [asleep marker](#sleep-and-wake) exists, and with `stopped` when it does not. A running container gets its Git calls through `incus exec` as `agent`, with `safe.directory=/srv/workspace` on the command line. The read writes no Git file or setting and fetches nothing. The whole read has 2 seconds on the Node, and the SSH call 4 seconds; past either it answers `timeout`. A missing work tree answers `not_a_repository`, and a failed SSH call or an answer the Gateway cannot read is `unreachable`.

Work is what lies beyond the Instance's baseline:

- `modified`: tracked files that are changed, staged or deleted. They always count.
- `untracked`: files that are not ignored and not in the baseline. Orbit writes `.env` and `.env.testing` at the workspace root, so an untracked copy there is left out. A nested `.env` and a change to a tracked one count.
- `commits`: commits on local branches that no remote-tracking ref, baseline head or published commit reaches.
- **Remote refs.** The read compares with the remote refs as last fetched.
- **Published commits.** A task copy never fetches. Its read names the commits that the task's publishes pushed.
- **Check commits.** A check of a clean head that an earlier check recorded adds no commit. A published head that is checked again still reads `clean`; a commit with a changed tree after it counts.
- **No upstream.** A branch without an upstream gets no special case.
- `stashes`: stash entries that are not in the baseline.

Build output that appears after the create counts too. It stays work until the Project's `.gitignore` covers it.

The read also names the checked-out branch as `head`, its commit as `head_commit`, and how many of the counted commits that branch holds as `commits.head`. A task copy's cancel and complete hold the copy's [content read](/reference/tasks#published-work-of-a-copy) against that commit. Only those commits can count as published by content.

The baseline is what a new Instance held when it was made, so a fresh copy of a default with local branches, stashes or untracked files reads `clean`. It holds the local branch heads, the stash ids and the untracked set, in the Gateway's `instance_runtimes.workspace_baseline`, outside the container.

A clone's create reads it before the transaction that ends the create, and writes it with `workspace_baseline_at` in that transaction. The user can reach T3 only through a pairing, and Conn pairs only after it reads the create `succeeded`, so the baseline holds no work of the user. The completion writes no tracked or unignored file, so the read stays `clean` while it runs.

The read runs in Horizon on an SSH connection of its own, with bounds of its own for a loaded host: the reader's budget is 6 seconds, an outer `timeout 8` ends it, and the SSH command bound is 10 seconds. Other workspace reads keep 2, 3 and 4 seconds.

A failed try, such as a lost connect, is tried once more after 1 second; each failed try writes its SSH exit code and the end of its stderr to the Gateway log. The second failed read fails the create with `instance.workspace_baseline_unavailable`, so a stuck read ends in about 21 seconds. The job that ends the create runs within its 150-second limit. A resumed create keeps a recorded baseline. A task copy's create that fails this way [starts once more](/reference/tasks#a-failed-create).

When the untracked set has more than 2,000 entries, or the answer would pass 900 KB, Orbit drops it, so every untracked file counts. Nothing rewrites a recorded baseline: not a deploy, a rename or a removal restore.

An Instance without a recorded baseline, such as one made before baselines existed or one moved into a container by a migration, compares with the Project default as it is at the time of the read.

A commit counts only when the default does not reach it from its local branches, so a default branch that moved on since the copy still covers what the clone inherited. A stash counts when the default does not hold it. Its answer says `baseline: default_now`, and all its untracked files count. If the default cannot be read, the answer is the plain state, `baseline: none`. A default Instance has no baseline: its answer is always the plain state.

Names from the workspace never become arguments. Git returns them NUL-separated, and they reach the Gateway only as JSON text, cut at 200 characters. Only object ids of 40 or 64 hex characters from the baseline join a `rev-list` call.

### Sleep and wake

A sleeping Instance's container is frozen. Its processes keep their state and memory, but the memory moves to swap: a frozen Laravel and T3 clone holds about 13 MiB of RAM and about 340 MiB of swap. Nothing boots at the wake, so a resume takes a fraction of a second. Its disk, databases, Route, T3 threads and sessions stay. A container never prunes its dependencies.

Incus writes `memory.swap.max = 0` for every Instance container from `limits.memory`, unless `limits.memory.swap` holds a byte size; `true`, `false` and an empty value all give 0. So a running normal container never swaps: a busy one meets its limit instead of filling the swap disk. A nested-fleet container has a size, [equal to its memory](#the-container). A busy nested-fleet container can therefore use swap while it runs, up to that size, and the host's swap disk takes it.

A sleep opens swap (`max`) for every cgroup of the container's subtree that holds 0, freezes the container, records which pages of its processes were resident, and then asks the kernel to reclaim its memory. A wake reads the recorded pages back while the container is still frozen, resumes it, and gives back the limits of exactly the cgroups the sleep opened. Other pages in swap come back when they are used. No Incus setting changes.

One helper on the Node, `/usr/local/libexec/orbit-instance-power`, does this work for every sleep, wake, deployment, start and destroy. [Prepared restore](/reference/prepared-restore) owns its contract: the plan, the bounds, the wake's state table, the fast wake's files and the result fields.

> **Default-off signed engine.** [ADR 0266](/decisions/0266-sleep-and-wake-containers-through-a-signed-order-executor) routes power through a Rust executor on the Node. With [signed power delivery](/reference/executor#signed-power-delivery) enabled, the Gateway signs orders and the observer delivers them. Swamp start, deploy and boot phases hand theirs to the executor through the relay command on the Node. The Python helper and fast wake files below remain the disabled-mode path.

**The swap subtree.** A cgroup whose `memory.swap.max` is 0 keeps its pages in RAM, even when its parent may swap. A nested-fleet Instance runs an inner Incus, which writes 0 for its own containers that have no swap size: `lxc.payload.nested-gateway`, `-app-dev` and `-app-prod` under the outer container's cgroup.

The other way round, a cgroup can swap only as far as each of its ancestors allows. An Instance of the fleet on `nested-app-dev` is three levels below the host: its sleep opens only its own subtree, so it hibernates only when the outer container and `nested-app-dev` both have a swap size. Without them every such sleep left more than 32 MiB in RAM and stopped.

So the sleep walks the container's whole cgroup subtree and writes `max` to each `memory.swap.max` that holds 0. Before it pauses the container, it records what it opened in the [limits record](/reference/prepared-restore#files-on-the-node), `/var/lib/orbit/hibernation/instances/<id>/limits.json`. Its result counts the inner cgroups as `swap_subtree`. Each resume by a wake, a deployment or a start gives the container's own cgroup back what Incus configures for it: the bytes of its `limits.memory.swap` when that is a size, else 0. It writes 0 to each recorded inner path that still exists, then removes the record. A resume reads no other record.

So a nested-fleet container keeps its swap after its own wake, and its fleet can sleep again.

A cgroup that vanishes during the walk, or that refuses the write, is not opened and fails nothing; its pages stay in RAM, and the check of the memory left in RAM below counts them. A sleep that fails while the container still runs closes what it opened. A sleep of a container that is already frozen keeps the earlier record and reports its count as `swap_subtree`. A destroy removes the record with the container.

A short-lived unit, such as `systemd-hostnamed.service`, can end while the container sleeps, and its cgroup goes with it; the resume skips that path and never fails on it. In practice only the container's own cgroup and the three inner payload roots matter.

A cgroup with its own limit, such as polkit's 32 MiB, stays as it is. The Node's fast resume closes the same record, so it serves any `swap_subtree`; it stays off for a nested-fleet Instance.

Measured on a nested-fleet default on 2026-11-14, before this rule:

| Step | Result |
| --- | --- |
| Outer cgroup opened only (the earlier sleep) | 1,531 MiB of anonymous memory stayed in RAM after the reclaim, so every nested sleep would have hit the stop path |
| Every cgroup with 0 in the subtree opened | Anonymous memory in RAM 0; 78 MiB in RAM; 2.1 GiB in swap, from 4.8 GiB in RAM |
| Freeze, in total | 3.6 s: pause 0.02 s, two reclaims of 1.8 s and 1.6 s |
| Resume | 0.03 s |
| Writing the 4 values back to 0 | 1.2 s |
| Inner Doctor healthy, 3 agents online | 4.0 s after the resume |

After the reclaim, the sleep reads the container's memory counters until anonymous and file memory are both 0, at most 20 times 0.1 s apart. The last read is what stays in RAM; the result reports it. When swap cannot take the memory (it is full), more than 32 MiB of anonymous memory stays in RAM. A frozen container would then hold RAM, which a sleep exists to free, so the sleep resumes the container and stops it. Its result says `sleep: stopped`; otherwise `sleep: frozen`.

A nested-fleet Instance never takes this stop: a stop loses the Club key in `/run` and the tunnel `wg-nested`, which only a deployment or a start gives back. The sleep resumes it, closes swap, removes the marker and fails with `instance.container_freeze_failed`.

A stopped container keeps each Process unit's enablement, which holds its desired state, so its later boot brings back what ran before. A sleep that fails to freeze closes the swap it opened and removes the marker, and the container serves as before (`instance.container_freeze_failed`).

The runtime's `power_state` is `stopped` for both a frozen and a stopped sleeping container. Each reader treats them alike.

Incus can lose a freeze: on 17 November 2026 it read Instance 2454 as running while the kernel still held its cgroup frozen, and every `incus exec` into it hung. So a wake or a deployment also reads the kernel's `cgroup.freeze` and `cgroup.events` of a container that Incus calls running, before any exec. The power helper thaws such a container itself within 5 s, or the run fails at once with `instance.container_frozen_unmanaged` ([a freeze that Incus lost](/reference/prepared-restore#a-freeze-that-incus-lost)). A clone needs no wake of a sleeping default: it copies one of the default's seed snapshots and never touches the live container.

By hand, resume a frozen container before you stop it: `incus resume` first, then `incus stop`. A clean `incus stop` of a frozen container times out and leaves it running (Incus 7.0.2). `incus start` of a frozen container only unfreezes it.

| Part | State |
| --- | --- |
| [`instance:sleep`](/cli/instance/sleep) and [`instance:wake`](/cli/instance/wake) | Built for every development container: a clone, a default and a nested-fleet Instance. [Nested-fleet wakes](#a-stopped-nested-fleet-clone-starts) differ when the container is stopped |
| A deployment of a sleeping Instance removes the asleep marker | Built |
| A clone or task copy of an asleep default | Built: the copy needs no running default |
| A Route visit wakes the Instance | Built |
| Sleep after an idle hour | Built; off until the operator turns it on. See below |
| The wake renews the Gateway's T3 session when it ends within 7 days or ended while the Instance slept | Built |
| The Conn thread index reads the server one last time before a sleep is admitted, while its switch is on | Built. See [Thread index](/reference/conn#thread-index) |
| A visit to a frozen container waits while the Instance wakes, then gets the app's own page | Built: [the Node resumes it](#the-node-resumes-a-frozen-container), on a Node whose `app-dev` role converged with the wake script |
| The wake reads the pages that were resident at the freeze back from swap before it resumes | In progress ([ADR 0261](/decisions/0261-restore-a-sleeping-containers-memory-before-it-resumes)); off for nested-fleet Instances. See [Prepared restore](/reference/prepared-restore) |
| A long freeze becomes a stop | Planned |
| A production live sleeps and wakes; Caddy holds its visitor | Planned with production containers ([ADR 0265](/decisions/0265-sleep-idle-production-containers)). See [Production containers](/reference/production-containers#sleep-and-wake) |
| A Conn connection or a task turn wakes the Instance | Planned. Conn calls `POST /api/v1/instances/{id}/wake`, which has the same access rule as the Conn remove |

The Gateway setting `ORBIT_EXECUTOR_POWER_ENABLED`, off by default, selects signed sleep and wake. On either engine, the Gateway holds a visit while the wake runs, up to `ORBIT_VISIT_WAKE_WAIT_SECONDS` (30 s by default); [Wake](/reference/app-dev-runtime-hibernation#wake) lists the answers. The Gateway sends the order only over the Node's WebSocket. With no `accepted` line by the six-second deadline, the order has expired and the Operation fails with `executor.not_accepted`; an accepted order with no result ends `interrupted` with `executor.result_unknown` ([Signed power delivery](/reference/executor#signed-power-delivery)). A frozen Instance keeps the engine that froze it, even after a setting change.

**The idle sleep.** The [runtime hibernator](/reference/app-dev-runtime-hibernation#idle-window-and-sweep) puts a development container to sleep after an idle hour, with the same idle window and sweep as a checkout Instance. A clone, a default and a nested-fleet Instance follow the same rule; no list of names or ids exempts one. `ORBIT_HIBERNATION_CONTAINER_SLEEP` chooses which Instances it acts on:

| Value | The idle sleep acts on |
| --- | --- |
| `false`, the default, or any value not in this table | No Instance. A value not in this table is logged as an error once, and again after a cache clear |
| `without_t3` | Only Instances without a T3 server: the runtime records no T3 port. Today these are the defaults |
| `true` | Every development container. Planned: also each production live ([ADR 0265](/decisions/0265-sleep-idle-production-containers)) |

An Instance sleeps only when no row of this table keeps it awake. The sweep checks them in this order, and the first match is the reason it reports:

| Reason | The Instance stays awake when |
| --- | --- |
| `t3_server` | The value is `without_t3`, and it has a T3 server. |
| `not_development` | It is not a development Instance. Planned: this reason goes, and `not_live` takes its place. |
| `not_live` | Planned: it is a hot or old production release container. |
| `never_sleep` | Its id is in `ORBIT_HIBERNATION_CONTAINER_NEVER_SLEEP`. Planned: the Instance's `never_sleep` field, which `instance:update` sets and `instance:show` shows, replaces the setting. |
| `asleep` | It already sleeps. The sweep does not read it. |
| `busy`, `task` | It is not active, an Operation is open on it or on its Processes, or a task runs in it. |
| `keep_alive` | One of its Processes is desired-running with `keep_alive`. A frozen container runs no Process, so this keeps the whole container awake. |
| `node_service` | It uses a [Node service](/cli/instance/node-service-attach), such as PowerSync on another Node. The service may call back into the app, which a frozen app cannot answer. |
| `swap_full` | Planned: a production live whose last sleep failed within the window because swap could not take its memory. A production sleep never stops live. |
| `http_unreadable` | Its Node does not answer the read of the access logs. |
| `watch_starting`, `watching` | The sweep has not yet watched it for one idle window. |
| `http_active` | Its Route's access log changed within the window. |
| `woke_recently` | A wake of it ended within the window; for a nested-fleet clone, also the [start](#a-stopped-nested-fleet-clone-starts) a wake takes. |
| `deployed_recently` | A deployment of it ended within the window. A release refresh resumes a nested-fleet default; it gets a full window before the next freeze. An [automatic deployment](/reference/deployments#automatic-development-defaults) does not count. |
| `t3_unreadable` | Its T3 server cannot be read. |
| `t3_turn_running` | A turn runs, or a run waits for a delegated thread or a background task. |
| `t3_active` | Its newest user message, turn end, or request that waits for the user is within the window. |
| `next_run` | The run already started five sleeps. |

The three `t3_` rows apply only to an Instance with a T3 server, so never to a production live. An Instance without one, such as a default, has no T3 to read, so the sweep judges it by the other rows alone. A clone whose T3 server cannot be read stays awake, as before. A running turn is preparing, queued, starting or running. Missing data never puts an Instance to sleep.

**The watch.** The first run that acts on a clone records when it began to watch it, in `idle_watch_started_at` of the Instance's runtime. Until one idle window has passed since then, the clone stays awake with `watch_starting` or `watching`. An access log written before the watch, such as the file a migrated Instance kept from its checkout, proves nothing about the time since. A dry run never starts a watch.

A clone without a Route sleeps on the watch, its wakes and T3 alone; an Instance without a Route and without a T3 server sleeps on the watch, its wakes and its deployments. An Instance without a published Route has no Caddy site, so its sleep has no asleep check to verify: it writes the marker and freezes the container. Nothing proxies to it until a Route publishes a site, which renders the check. A Node that does not answer, or a T3 server that cannot be read, keeps the clone awake, and the run says why.

A turn that runs is never cut, whatever its age. A thread that waits for the user keeps the clone awake only while its request is younger than the idle window; the freeze keeps the request, and the user's answer in Conn wakes the clone. A Conn client that is only connected is no activity: Conn keeps a socket open to every server it knows and pings it.

The Gateway reads T3 with the admin session it already holds and renews for each clone: one `GET /api/orchestration/shell` per watched clone, and none for a sleeping clone. It writes nothing in the container. It reads the access logs of all clones on a Node in one call to the Node agent.

Each sleep is an `instance.sleep` Operation with `triggered_by` `hibernation-sweep`, and its result says `t3_turn: idle`, or `t3_turn: no_server` for an Instance without a T3 server. A run starts at most five sleeps; the next run takes the rest.

`orbit:runtime-hibernator --dry-run` on the Gateway host lists every container Instance with the evidence it used: the watch start, the last HTTP request, the last T3 activity or `turn running` (`no T3 server` without one), the last wake and the last deployment. It prints the decision and the reason of each row under each value of the setting, `false`, `without_t3` and `true`, and names the value in force. Under `false` every row reads `off`; under `without_t3` a row with a T3 server reads `t3_server`. It changes nothing.

`--instance=ID`, repeated, limits a run to those Instances and acts as under `true`, whatever the value; its dry run says so. `--idle-seconds=N`, at least 60 and only with `--instance`, gives that run its own idle window. A scoped run applies every rule above.

**Compared with the checkout rule.** The [checkout rule](/reference/app-dev-runtime-hibernation) stops only an Instance's Processes; its site keeps serving. The container rule freezes the whole Instance, site and T3 included, and it wakes on a Route visit, from Conn, or by [`instance:wake`](/cli/instance/wake).

**Terminals.** T3 0.1.7 shows no terminal state in an HTTP read; only its WebSocket API does. So an open terminal, or a process started in one, does not keep a clone awake by itself.

**HTTP activity.** Caddy's site of a container Instance writes each request to `/data/caddy/orbit/hibernation/app-instance-<id>.log`, and the idle sleep reads the file's modification time. Caddy creates the file when it loads the site, so a new site gets a full idle window. The log rolls at 1 MiB and keeps one compressed roll. A destroy removes the log, but not a roll.

A request with `X-Orbit-Probe: 1` and a request that meets the asleep marker write nothing: such a request starts a wake, and the wake counts by itself. A Route's TLS probe makes no request. An open browser tab keeps the clone awake while it sends requests; that is intended. Caddy logs a WebSocket, such as Vite's HMR socket, only when it closes, so an open tab that sends nothing else lets the clone sleep. An uptime check without the probe header keeps it awake too.


**The Route while asleep.** The Node's Caddy site of a container Instance checks for the asleep marker, `/data/caddy/orbit/hibernation/app-instance-<id>.asleep`. Without it, Caddy serves the request as before; the check is a file test on the Node and calls nothing. With it, Caddy calls the [runtime activation endpoint](/reference/app-dev-runtime-hibernation#wake), which holds the request while it starts or joins the Instance's one `instance.wake`, as for a checkout Instance. A request with `X-Orbit-Probe: 1` skips the check. A failed wake keeps the marker, and the visit gets a plain-text `503` that names the failure.

The marker lives on disk, not in `/dev/shm`: a host reboot loses the frozen state, but not the marker. A sleep writes the marker before it freezes the container.

**The Node resumes a frozen container.** <a id="the-node-resumes-a-frozen-container"></a>When the Instance's last sleep froze it, it is not a nested-fleet Instance, and its Node's role installed the fast wake's worker template `orbit-instance-wake@.service`, the wake does not start its run at once. The Gateway records the `instance.wake` Operation under the Instance lock, as always. After the commit, within the call that admitted the wake, it writes a wake request named after the Instance and the Operation, `/dev/shm/orbit/hibernation/app-instance-<id>-op-<opid>.wake`, with the agent's existing `hibernation.marker`. That folder belongs to root and the `caddy` group with mode `0755`, so only root writes in it. The agent stays frozen ([ADR 0253](/decisions/0253-patch-nodes-and-reboot-them-drained)).

The `app-dev` role installs the Node's part: the power helper, a path unit that watches the request folder, and the worker template `orbit-instance-wake@<id>-<opid>.service`.

The worker restores the recorded pages and resumes the container through the helper. Then it checks the web port once, removes the asleep marker, and writes the outcome files under `/var/lib/orbit/hibernation/outcomes`. [The fast wake](/reference/prepared-restore#the-fast-wake) has the files, the worker's steps and the rules for a decline.

The Gateway reads the request and the outcome files with the agent's `hibernation.times`, every 25 ms within 6 seconds of the request. When the outcome shows, the Operation succeeds at once: `wake: resumed`, `trigger_seconds`, `confirm_seconds`, `ready_at` and `prepared_restore.status`.

The `instance:wake` command and a visit both wait for this, up to the bound, and a command or visit that joins the wake waits for it too. A visit gets the app in the same request once the Operation has succeeded.

A request that arrives while the resume runs joins the wake and waits with it. A wake cancelled before the Gateway writes the request gets no request.

Anything else starts the run below, in the same Operation, and a held visit keeps waiting for that run, up to its 30 s deadline. Such a wake takes 14–19 s. That covers a Node whose role has not installed the worker template (the first read asks for it, and such a Node is skipped for 10 minutes), a worker that declined or failed, a failed agent call, and the bound. The Gateway then removes a request still there.

The worker ignores a request older than one second, so a request left behind never wakes the Instance later.

The run takes the helper's lock, so it waits for a worker still at work and then continues from the state the Node reached. A Gateway worker that dies during the resume leaves a queued wake: a delayed start runs its workflow 15 seconds later. A cancel during the resume keeps the Operation cancelled.

A Stopped container whose last sleep froze it, such as after a host reboot, starts its run about a second later, while the Node declines the request.

Measured on a frozen fixture clone before the fast wake: the resume took 0.02 seconds and the web probe 0.4 seconds, but the run's start-up and playbook took 8 to 13 of the 9 to 19 seconds a visitor waited.

**The wake's run.** One Ansible run reads the container's state and the marker:

| Found | The wake |
| --- | --- |
| Frozen, marker | Reads the recorded pages back, still frozen; resumes it, closes the opened swap, and gives FrankenPHP 10 tries one second apart. Nothing boots. `wake: resumed` |
| Frozen, marker; FrankenPHP silent after the resume | Stops it by force, then boots it as below: a visitor would rather wait for a boot than for ever. `wake: restarted_after_resume`. A nested-fleet Instance is never stopped: the wake fails with `instance.web_unready` |
| Stopped, marker (after a host reboot, or a sleep that fell back to a stop) | Boots it. `wake: started`. A nested-fleet Instance is never booted by a wake: it fails with `instance.nested_fleet_stopped`, and the marker stays |
| Running, marker (after a host reboot with `boot.autostart`) | No start and no resume; checks FrankenPHP and removes the marker. `wake: already_running` |
| Running, no marker | Nothing to do. `wake: already_running`, `marker_found: false` |
| Frozen, no marker | A defect: the wake resumes it, and its result says `marker_found: false` |

A resume boots nothing, so a nested-fleet Instance keeps its inner fleet.

The result also gives `resume_seconds` and `web_probe_seconds` for a resume, and `restore_mode`, `ready_at` and `prepared_restore` ([Results](/reference/prepared-restore#results)). The web check goes from the Node to the container's address, not through `incus exec`. A boot starts the container and waits until each running unit is active. Then the run waits until FrankenPHP answers.

A web unit that listens before the app is ready answers 5xx for a moment, so the run waits up to 5 more seconds for an answer below 500; an app that answers 5xx by itself is then served as it is. Then the run removes the marker, and the next request gets the app. A Project without a web unit has no web wait.

A converge cannot run a command in a frozen container, so a container that slept through a converge may wake with a library cache that lacks the shared runtime libraries. After the marker went, each wake runs one check in the container: when `ldconfig -p` does not list a soname of `/opt/orbit-runtime/current/lib` at that path, it runs `ldconfig -X`.

That is the converge's own rule: only names such as `libasound.so.2` count, not the file `libasound.so.2.0.0`, which the cache never lists. The run does it after its marker removal; the Node's wake script does it after every pending request. A failed check fails nothing, and each rebuild is logged ("rebuilt" in the run's output, "library cache rebuilt" in the journal).

A second check follows for the same reason. The converge keeps each container's Orbit root CA file current through the Incus file API, frozen containers included, but only a command in the container rebuilds the bundle. When `/etc/ssl/certs/ca-certificates.crt` lacks the root that `/usr/local/share/ca-certificates/orbit-managed-root-ca.crt` holds, the wake runs `update-ca-certificates`. A container without that file skips the check. It is logged as "refreshed" in the run's output and "CA bundle refreshed" in the journal, and it never fails the wake.

Only then does the run wait up to 30 seconds for T3 in an Instance with a T3 server, such as a clone; a default has none. T3 gates the end of the Operation, so a held visit waits for it too. A wake whose T3 stays silent fails with `instance.t3_unready` while the site already serves, and the visit still gets the app. The runtime records `power_state` `running`, because the container runs. A wake that fails before FrankenPHP answers keeps the marker and the `power_state`, and the visit gets the plain `503` with the failure.

A destroy removes the marker, and the helper's `discard` removes the plan, the limits record and the fast wake's files after any helper call still at work has ended. It also removes the executor's plan, limits record and receipt under `/var/lib/orbit/executor/power`. A sleep refuses with `instance.sleep_route_unready`, and changes nothing, while Caddy's loaded site of the Instance lacks the check: an older site gets it at the next publish of the Node's Caddy sites.

The [agent development servers](#agent-development-servers) site on port 444 has no asleep check: on a sleeping clone it answers 502 until a visit to the Route wakes the Instance.

**What wakes an Instance.** A visit to its Route, a deployment of it, and [`instance:wake`](/cli/instance/wake). A default with no visitor stays asleep until one of them comes. A clone or task copy of an asleep default does not wake it: the copy comes from the seed snapshot. A deployment that starts or resumes the container removes the asleep marker once its `start` part passed (the container runs and its units are active; there is no web probe), so the Route serves the app again; its result records `power_state` `running`.

Planned for a production live ([ADR 0265](/decisions/0265-sleep-idle-production-containers)): a deploy does not wake it, and a due native cron Schedule does. That wake does not count as `woke_recently`. The switch removes the marker. See [Production containers](/reference/production-containers#sleep-and-wake).

The end of a deployment counts as activity, like the end of a wake: the Instance stays awake with `deployed_recently` for one idle window, then sleeps when nothing else keeps it awake. An automatic deployment of a default is the exception: it does not count, so the default sleeps again at the next sweep when nothing else keeps it awake.

**While asleep.** Schedules run only while the container runs (decided 2026-11-12): Schedules in the container do not run, and a scheduler or queue Process in a sleeping container waits until the wake. A Process that must keep running gets `keep_alive`, which keeps the whole container awake. At the resume, systemd timers that came due during the freeze run at once. Other Operations that need the running container refuse it with `process.target_inactive` or `schedule.target_inactive`. Env, mail and Node service updates and the task workspace read `power_state`. Process and Schedule work on the Node refuses a container that is not `Running`, and Incus reports a frozen one as `Frozen`.

Planned for a production live: a due native cron Schedule wakes it and waits for the wake instead of a refusal. A container timer that came due during the sleep fires once at the resume.

A connection held open across a freeze meets the server's idle timeout at the resume, and clients reconnect. A deployment's start resumes a frozen container, or boots a stopped one, before it converges. A destroy deletes a frozen container with `incus delete --force` and needs no wake.

**Conn.** The hourly renewal skips a sleeping Instance, so it never records `conn.environment_stopped` for one. A successful wake admits the renewal at once when the Gateway's session ends within 7 days or has ended: the run issues a new session in the container without the old one. Devices then renew their own pairings through the Gateway, with no new pairing by the user.

The [sleep](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/sleep.mmd) and [wake](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/wake.mmd) charts have the steps.

### Migrate a checkout Instance

Before downtime, migration resolves the [deploy credential](/reference/github-app#instance-source-credentials) and makes one read of the Project's default branch with a 30-second limit. Local branches, commits, stashes and tracked edits are carried; the other retirement guards still apply. Projects using `gh_cli` skip this App preflight. An unreadable source or timeout leaves the checkout serving. The local checkout still supplies the migration's mirror and files. The Git URL rewrite is quoted in `argv` so YAML cannot turn it into a mapping.

[`orbit instance:migrate INSTANCE`](/cli/instance/migrate) moves one existing checkout Instance into a container on its Node, with its code. A linked worktree can move first while its owner remains an active host checkout on the same Node. Migration starts from the stock image and copies its own workspace; it consumes no default seed. The owner moves after its last dependent leaves. The command is temporary: [Old paths](/reference/old-paths) lists it until the last checkout Instance has moved.

- **Stop.** Orbit removes the Instance's awake marker. The Gateway starts no wake during the move, so a visit gets a plain `503` that says the move runs.
- **Repository.** Orbit builds a standalone repository in the container; the checkout's `.git` is never copied.
- **Branch.** The Node's mirror fetches the Instance's branch from the checkout, and Orbit pushes it in.
- **Files.** Orbit copies the changed, untracked and ignored files, never the checkout's `.git`. It then removes the caches built at the old path ([rule](/cli/instance/migrate)).
- **Database.** A database on a Database server stays where it is, and the container gets its own way to it. A SQLite file in the checkout moves with the files.
- **Start.** FrankenPHP starts as `www-data`. A non-default Instance gets its T3 server, registered as in [Create](#create). A default gets its first seed snapshot.
- **Route.** Orbit points the Route at FrankenPHP in the container.
- **Retirement.** Orbit retains all files and Git recovery data. It removes only the linked checkout's registration. An owner's dependents and any registered descendant block retirement.

Registered Schedule timers keep their UUID, calendar, timeout and enabled or paused state. Their timers and commands run inside the container as `www-data`, only while it runs, without catch-up. The container's `/bin/bash` and PATH run each command, whatever the host account's login shell; a host path such as `/usr/bin/php8.5` becomes the container's `php`. [Schedule migration](/reference/schedules#checkout-migration) defines refusals, rollback and retained logs.

A failed move before cutover leaves the checkout Instance serving as before. The [migration chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/migrate.mmd) has the steps.

#### Migration symlinks

Orbit predicts symlinks against the copied layout before downtime, using the same resolver as the final copied-tree check. After copying, it checks and rewrites the exact workspace tree inside the container, before starting any Process. This final check maps absolute targets under the old checkout to the copied workspace; it never reads the host checkout. Links into Git metadata must target paths that the new repository is sure to create; other metadata targets refuse before downtime. A link changed during copying can therefore fail the move with the same error and rollback behavior.

A link must have both its named target and its resolved destination inside the checkout. Broken links, including cycles, and external targets refuse with `instance.migrate_symlink_unsupported`. The error lists every offending checkout path, its original target and the reason, with invalid UTF-8 bytes shown as `\xNN`. Orbit does not choose a different data source or run project setup to repair a link.

Prediction accepts these Git paths: `.git`, `.git/HEAD`, `.git/config`, `.git/index`, `.git/FETCH_HEAD`, `.git/objects`, `.git/objects/info`, `.git/objects/pack`, the checked-out branch at `.git/refs/heads/BRANCH`, the fetched branch at `.git/refs/remotes/origin/BRANCH`, and the directories that contain those refs. The workspace's init, fetch and checkout steps create these paths. Every other `.git/**` target refuses before downtime, including `.git/local-only`. Template files, reflogs and object filenames are not promised.

Directories absent after the Git checkout and file archive are copied too, including empty untracked and ignored trees. Added directories keep their exact modes, including setgid and sticky bits, with the normal `www-data:www-data` ownership mapping.

The Node passes their mode manifest through the protected execution result and stdin; it creates no manifest file in the container. Restoration runs as `www-data` and opens each path component relative to the workspace with directory descriptors that refuse symlinks. Absolute paths and parent traversal refuse before any mode changes. The directory scan excludes `.git` metadata and tracked submodule contents and never follows directory links. Selected nested repositories still enter the file archive as before. Absolute links in ignored directories follow the same rule, including links from `storage/framework/testing` into tracked `database/migrations` files.

A target ending in `/` or `/.` requires a directory at every link hop. A regular file with either suffix is a broken target and refuses, even if removing the suffix would make it work.

In the container workspace, an absolute link into the checkout becomes a relative link to the corresponding resolved destination. For example, `public/storage -> /fast/apps/example/default/storage/app/public` becomes `public/storage -> ../storage/app/public`. A relative link within the checkout keeps its exact target. This applies to tracked, untracked and ignored links, including local-package links; restricting it to `public/storage` would leave other checkout paths pointing at the host.

Orbit skips `.git` and installed dependency contents. A `vendor` directory beside `composer.json`, or `node_modules` beside `package.json`, is a dependency root. Orbit checks package links at its boundary, including Composer namespace and npm scope entries, without following them into package trees. Ordinary directories such as `resources/views/vendor` are checked normally. Rewrites use filesystem paths inside the container, so names and targets with non-UTF-8 bytes do not pass through Incus JSON arguments. The source links are never rewritten.

#### What is built for the move

The move remains available on app-dev Nodes without a build-list opt-in.

| Part | What Orbit does |
| --- | --- |
| Admission | An active checkout Instance on an app-dev Node, with no Route beside its own. Otherwise `instance.migrate_unsupported` |
| Non-default | The Project default is on the same Node; source identity and T3 registration are required. Conn and model routing are optional. No migrated default or seed is needed |
| Deployment | An unfinished deployment refuses the move with `instance.lifecycle_busy` |
| PHP version | A web-serving Project without a recorded version refuses with `project.source_defaults_incomplete`. Set `require.php` in its source, then run `orbit project:update PROJECT --source-access=github_app` to record it |
| Runtime row | Reserved at admission: the next free bridge address and, for a non-default Instance, the next free T3 port after the Node's last one. It names no clone source |
| While it runs | The Instance keeps its checkout path, and the reserved row does not turn its site. The run holds the Route's Node, and the Instance takes no deployment and no wake |
| Retirement check | Checks identity before downtime and retention. Carries local branches, stash history, staging and all copied file bytes. Active clean/smudge filters, intent-to-add, submodule changes, owner dependencies and registered descendants still block |
| Branch | The mirror fetches the recorded branch locally; the files phase imports every local branch tip and complete stash history into independent Git metadata |
| Files | One archive carries changed, untracked and ignored files and missing directories. Added directories keep their modes; ownership maps to `www-data:www-data`. Deleted tracked files are removed |
| `.env` | Copied as it is. The checkout's path in it becomes `/srv/workspace`, and the Gateway rewrites its stored values the same way |
| Database server | The NIC ACL and the Node's firewall rule to every attached target. The container phase checks every port from inside before quiesce, else `instance.database_unreachable`, with zero downtime |
| SQLite | The file moves with the files. The Gateway drops the connection record of a SQLite file inside the checkout |
| Route | The same Route, domain and certificate. One publication replaces the checkout's site and PHP-FPM pool with the container's site |

All untracked files enter the default's seed snapshot and every clone, as ignored build output already does. A branch that later tracks one of those paths can refuse checkout in a clone or deploy. Forgotten credentials files travel into the seed like `.env` does.

Migration reports `untracked_files_copied` as the count of untracked files that Git does not ignore, using `git ls-files --others --exclude-standard -z` at archive time. Selected nested repositories contribute their archived leaf files; directories do not count and symlinks count once. Ignored files are still copied. It exposes no new paths or file contents. Submodule changes still refuse because the parent archive cannot preserve them.

The runtime reservation belongs to its admitting Operation. A late receipt cannot change a newer owner. Settlement reads private material before taking the Instance lock, then checks ownership and records the runtime and receipt atomically. An already-settled stale runtime is not repaired: inspections keep selecting it until a new migrate reuses and owns that reservation.

A migrate admission does not read a previous run to settle it. While settlement is pending, `instance.migrate_settlement_pending` names the previous Operation and the existing hold limit. The reconciler reads late results without a show. See [migrate](/cli/instance/migrate) for the refusal and result-unavailable outcome.

If the finish output is missing, verified native rollback can release the owned reservation after a 60-second grace. The original run deadline caps the grace. Without rollback proof, the deadline records `instance.migrate_result_unavailable` and keeps the Instance runtime. Another migrate names the Operation and missing evidence with `instance.migrate_recovery_required`. No Orbit command repairs that evidence gap.

| Part | What Orbit does |
| --- | --- |
| Seed | A default takes `seed-<commit>` with its units stopped. Its Project's other Instances can be clones from then on |
| T3 | A non-default Instance gets the proxy device `t3`, the agent's files and its T3 server. The Conn layer records the server from the run's result |
| Runtime | A Route that serves the container makes the container the Instance's runtime: the workspace becomes its checkout path |
| Retained Git | The whole old checkout moves under `<checkout>.orbit-retired-git/<sha256 of original path>`. The Instance records `retained_git_path` and `retained_git_at`; files and Git recovery data stay there |
| Linked checkout | Retains all files and independent Git data. Removes only its matching, now-missing owner registration after verification; keeps owner and sibling metadata untouched |
| Last worktree | The default's old checkout retires after the moving worktree, in the same batch. Another live worktree blocks it. [Retirement](/cli/instance/migrate#retained-git-data) names the rules |
| Failed removal | The container keeps serving. Source or retained files stay. An interrupted linked unregister fails explicitly on repeat with `instance.migrate_retire_failed` |
| Failure | Restore puts back a moved Route, then removes the container, its ACL, firewall rules and mirror |
| Reserved row | Dropped after a successful restore or an unstarted run. A failed restore retains the row, address and T3 port across retries to clean up the original rules |
| After a failed restore | The reservation and deployment hold remain. Inspect the site and failed recovery step; deployments refuse with `instance.lifecycle_busy` until the move has run again |

The whole-run budget is 5,700 seconds: 4,830 seconds of declared phase and child timeouts, including Node preparation, restoration and cleanup, rounded up to five minutes, plus 600 seconds for admission and settlement. A lower-bound test checks those declared timeouts and margin. A deadline or confirmed cancel still projects the native end state. A lost worker with no final result reports an unknown state and retains its reservation.

Restore changes host Process states and the awake marker only when quiesce began. A target probe failure leaves both untouched.

The database is not cloned. An Instance's database name, owner and connection come from its Project and its name. So a second database for the same Instance has no name to take, and a clone would end with a drop of the old one. Leaving it in place cannot lose data. A clone of a moved default still copies the default's database with the template copy of [Clone the database](#clone-the-database): the default's connection record is the one it had.

The move keeps each ordinary systemd Process or supported `vp-dev` preset and its desired state. It stops and disables the host units before copying files, installs the same Processes in the container with their private environment files, and removes the host units and environment folders after the Route moves. A failed move removes the container before restoring the host units to their recorded desired states. An unfinished Process operation, removal or previous runtime cleanup blocks migration, and Process changes wait until migration settles. Docker Processes refuse with `process.target_unsupported`.

The [migration command](/cli/instance/migrate#before-you-start) defines supported presets, definition capture and the skip-and-record repair of migrated defaults, including malformed stored data and runtimes with no seed commit, with findings reused on repeat and resolved after a later capture. [Container Vite ports](/reference/assigned-vite-ports#migrated-container-presets) use fixed `5173` for new copies and preserve migrated ports. A migrated `vp-dev` uses the shared runtime, binds its assigned port in the container, and keeps `/__orbit/vite` on the Route. The container admits that port only from the Node, including after a deployment rebuilds its intent.

## T3 registration and pairing

The clone adds `/srv/workspace` to T3 with the Project display name as its title, or its slug when the name is empty. Orbit sets `linkedEnvironmentsEnabled: true` in `/home/agent/.t3/userdata/settings.json` for linking and `hasCompletedOnboarding: true` in `/home/agent/.claude.json`, preserves every other key, and writes only when a managed key differs or the file is missing.

The Instance's create flow registers its T3 server with the [Conn layer](/reference/conn#registration), with the same record and the same checks as a server that registers itself:

In the container, as `agent`:

1. T3 runs: the copy's boot started it ([T3 starts with the copy](#t3-starts-with-the-copy)), else the T3 step starts it.
2. T3's own CLI issues the `Orbit Gateway` session as `agent`: `t3 auth session issue --label "Orbit Gateway" --token-only`.
3. In parallel, the CLI issues the `Orbit Swamp` session for [Swamp drives T3](#swamp-drives-t3). PR 8 narrows its scopes.
4. After T3 answers, `t3 project add` adds the workspace, once. During T3's start that call fails, so it waits.
5. The tokens wait in the run's private folder, mode 0600. They never reach a log or a result.
6. When the checkout and T3's answer have ended, the T3 step checks both sessions at `/api/auth/session`, with the served environment. It issues none.
7. The checked reply replaces the tokens in the private folder.

Stock T3's CLI writes its sessions before and during the server's first start. So the two issues run while T3 starts and add no time of their own.

A create that fails after the T3 step leaves its two sessions valid in T3, as a resume does. Both copies of the tokens stay in private folders, which the create's cleanup removes.

A session issue that fails fails the T3 step: `instance.t3_unready`, with `instance.container_t3_failed: command t3 exited <code>` as its detail.

On the Swamp worker:

1. The next step, `@nckrtl/instance-t3`, makes the checks of `POST /conn/environments`. T3 must know both sessions, and the Gateway's must carry `access:read` and `access:write`.
2. That step stores both sessions in the vault, as native sensitive fields.
3. It returns the environment id, the server version, the expiry and the two vault keys.

The create's grants cover both session keys, scoped by behavior UUID and Instance id.

In the Gateway:

1. The Gateway records the server with the admitted URL: the Node's WireGuard address and the T3 port. It does not call T3 again.
2. It reads the Gateway session from the vault into its encrypted record. The vault copy stays, as a pairing's credential does.
3. The server's Node is the container's Node, and the record names the Instance ([Instance servers](/reference/conn#instance-servers)).

The container cannot call the Gateway, so registration never comes from inside it. Orbit renews the Gateway's session from outside, in an hourly tick ([Renewal of an Instance server](/reference/conn#renewal-of-an-instance-server)). A container started after its session ended gets a new one within the hour, or within six hours when a try found it stopped. The [renewal chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/conn/environment-renew.mmd) has the steps.

A device pairs as with any T3 server: [`conn:pairing:create`](/reference/conn#pairing-and-revocation), with node access to the container's Node. Pairing with a sleeping Instance's server refuses with `conn.environment_asleep`; the device wakes it first. T3 prints a pairing token to its journal at each start; only root and `agent` can read it, and that is accepted.

## Drivers

A container's T3 server has two drivers. They use the same threads and the same history.

| Driver | When | Through |
| --- | --- | --- |
| A user | No task runs in the Instance, or its task is paused | Conn, paired with the T3 server through Orbit |
| Swamp | A task flow runs in the Instance | T3's MCP endpoint and WebSocket API, as [Swamp drives T3](#swamp-drives-t3) specifies |

Both send a turn with `queue`, so neither steers the other's turn. No lease decides who drives.

## Threads

Starting a thread in an Orbit Project in Conn creates an Instance, a clone of the default Instance. Conn names it from the first message, pairs with its T3 server and starts the thread there with that message. The [thread chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/thread.mmd) has the steps, and [Threads in an Orbit Project](/reference/conn#threads-in-an-orbit-project) the endpoints. A thread started inside an Instance's server stays in that Instance.

What is built:

- Conn lists the Projects whose ready container default runs on a Node the device reaches.
- Conn creates the clone through the Conn layer, on the default's Node.
- Conn keeps each thread start on the device until its first message is sent. A reload resumes it.
- Conn pairs once: it follows the pairing's Operation to the end.
- Conn shows a server whose container is stopped as asleep. It does not wake it yet; this remains in [ADR 0250's acceptance](/decisions/0250-run-instances-and-task-groups-in-incus-containers#remaining-acceptance).
- The desktop app starts these threads. The phone does not yet.

The agents use their own subagents only. MCP servers come from the project's own files: `.mcp.json` for Claude Code and the project's Codex configuration.

## Tasks in an Instance container

This section is the target. Built today, for a Project whose default is a container (a nested-fleet Project included): the start, the check ([Task Instance workspace](/reference/task-instance-workspace)), a new implementer turn ([`tasks.copy_turn`](/cli/task/turn#a-task-that-runs-in-a-copy)) and the end by `task:cancel`. A [task copy](/reference/tasks#task-copy) lists the rules. A publish is held per Project until an operator releases it. The copy's agent gets the fixed task model key, as every clone does; it is not per task, and per-task model keys are an open decision.

A task group runs in one Instance container. Everything else about a group, its subtasks, checks, reviews and reads, stays as [Tasks](/reference/tasks) describes.

| Part | In an Instance container |
| --- | --- |
| Start | `task:start` clones the Project's default Instance into the Instance `task-{id}`, branch `task-{id}`, as [Create](#create) does. The task is the clone's [owner](/reference/tasks#workspace-ownership) |
| No default | Without an active default Instance of the Project on an `app-dev` Node, the start fails with `instance.default_missing` |
| Attach | A group may name an existing Instance of its Project instead. The task drives its T3 and works on its branch, but does not own it |
| Turns | Swamp runs T3's own CLI in the container, as [Swamp drives T3](#swamp-drives-t3) specifies |
| Workspace actions | The receipt, the Git evidence, the reviewer's reset and the checks run from the Node, with `incus exec` as `agent` in `/srv/workspace`. A check sees what the agent saw |
| Preview | The Instance's Route |
| Publish | Orbit reads the branch out on the Node and pushes it with a minted token, as [Git in and out](#git-in-and-out) describes |
| Pause | `task:pause` on the group stops Swamp's turns, and the user drives from Conn. `task:resume` gives the turns back to Swamp |
| End | Orbit destroys a clone that the task owns, through [Destroy](#destroy). An attached Instance stays running |

A task keeps its Instance awake: [Sleep and wake](#sleep-and-wake) never stops a container while a task flow runs in it.

## Swamp drives T3

Swamp drives a container's stock T3 through its own CLI and WebSocket RPC, run in the container. The [Conn launch transport](#conn-launch-transport) shares that RPC helper; the rest of this section describes the `t3` task driver. The [`pi` driver](/reference/tasks#drivers) and task VMs are removed.

### Conn launch transport

The placed phase reads or hands off only inside the admitted Instance. Run-folder staging allows the full frozen request to reach T3 on stdin. A cancelled preparation waits for any bounded native call and its local service session to settle before teardown.

The [durable Conn launch](/reference/conn#durable-launches-v1) uses the container's [recorded server](#container-server) through the shared `t3_copy_rpc.mjs` transport.

The Gateway passes the runtime's program, `bin/t3` or `bin/conn-server`, and the worker runs that program for its session. The live descriptor's `serverVersion` must equal the T3 server version that program states with `--version`: `0.1.7` for `t3 v0.1.7`, and `0.1.10` for `conn-server 0.2.0 (T3 server 0.1.10 contract, …)`. Any other version, or a program that states none, answers `conn.launch_server_changed`, so only the pinned program the runtime records passes. 

Swamp uses the admitted Node and exact container runtime; it enters the container with `incus exec`, then runs as `agent` with `runuser` in `/srv/workspace`. The worker reads the local runtime origin, issues a one-minute T3 session with `orchestration:read` and `orchestration:operate`, exchanges it for a WebSocket ticket, and uses `/ws?orchestrationProtocol=2`. Close the socket and revoke the session; if the process dies first, the session expires. Never return sessions, tickets, raw RPC frames or projections as evidence. The prompt is private input, not a shell argument or a Swamp artifact.

Discover the active project for `/srv/workspace` from `orchestration.subscribeShell` and read provider settings with `server.getConfig` in the same scopes. The live `/.well-known/t3/environment` descriptor exposes the ID that stock T3 persists as `userdata/environment-id`.

Check the live identity against the local state. Bind it to the device and inode of the server's persisted store before dispatch: `statev2.sqlite` for T3, `engine.sqlite` for the Conn Server, with the probe session's witness of [the launch's server check](/reference/conn#durable-launches-v1). This uses filesystem metadata. Recheck the binding on every attempt, including a read after a lost response. An ordinary restart with the same state keeps that binding. If state was copied, restored or otherwise lost continuity, a matching descriptor alone does not prove the replay window survived.

Separate the read-only preparation evidence from permission to dispatch. Only native work admitted under the committed launch fence may send. Mark sending as a native write; recovery checks the pinned server and exact thread projection before any possible replay. Stock T3 writes separate command receipts for thread creation and the initial message, and can schedule the run before returning the launch response. A thread with no matching first message is not an acceptance receipt. Keep projection reads bounded and compare the exact message in the container; return only safe facts for the Gateway to project.

### Task-copy RPC (target)

This is the target transport for a copy turn. It is not built. It waits for Conn integration item 4 (task 5868), which supplies the task-scoped authority and the target's exact owner check. Nothing ships to a live fleet until then. The transport reuses Conn's orchestration WebSocket protocol 2 and the task-copy bookkeeping that is already in place. It adds no engine and no admin scope. The [Conn launch transport](#conn-launch-transport) keeps its own callers of `t3_copy_rpc.mjs` and does not depend on this contract.

A local proof ran against Rust Conn and T3 0.1.10 at Conn `ffa3803db91`, on protocol 2. It proved stable message and run replay, exact interrupt and cancel, and replay after a server restart. It did not prove the task credential, the allowlist or the owner check: no target enforces them yet. The first pilot runs on Rust Conn. Pinned stock T3 joins only if it meets [ownership](#ownership-at-dispatch) and the [credential](#task-worker-grant) without changes. Compatibility with the deployed stock 0.1.7 is gated separately. MCP is not a path: `t3_pending_request` cannot cancel one exact stale request.

Where this section says the worker refuses, task 5873 must refuse too. It must not add a workaround.

#### Protocol calls

| Purpose | Protocol 2 call |
| --- | --- |
| Create the role's thread | `orchestration.dispatchCommand` with `thread.create` |
| Send the turn | `orchestration.dispatchCommand` with `message.dispatch` |
| Read the thread, its runs and requests | `orchestration.getThreadProjection` and the existing subscriptions |
| Cancel a stale question | `orchestration.dispatchCommand` with `runtime-request.respond`, decision `cancel` |
| Stop a run | `orchestration.dispatchCommand` with `run.interrupt` |

Reads need `orchestration:read`. Writes need `orchestration:operate`. Neither scope is enough without the grant's allowlist.

#### Admission

`TaskTurn::capture` admits each turn under the group's lock, as it does today. It names the copy, the role's `AgentThread` and the thread's `external_id`:

- **New thread.** Capture sets a UUID as `external_id` and marks it admitted: `external_id_admission` is `admitted`. `thread.create` uses that exact ID. The target must not choose another.
- **Confirmed.** A turn that reports that same ID back makes it `confirmed`. Capture names the ID as `turn.t3_thread_id` and its standing as `turn.admission`.
- **Established thread.** The turn uses an admitted `external_id`. The worker never looks a thread up by its title.
- **Binding.** Before dispatch, the worker confirms the target's environment ID and its one admitted workspace project for `/srv/workspace`. A thread of another environment or project is refused.

Rows made before this contract need one rule. Today `capture` sets a UUID, but `t3_copy_rpc.mjs create` makes its own thread ID. A continued turn finds its thread by title in `thread list`. `TaskTurnProjection::record` then writes the reported ID over the row. So these rows fall in two groups:

| Row | How to tell | Rule |
| --- | --- | --- |
| Provisional | No turn of the thread reported a `t3_thread_id`. The row still holds the UUID that capture set, which T3 never received | The next turn creates the thread with that UUID and marks it admitted |
| Overwritten | A turn reported a `t3_thread_id` | Refused with `tasks.copy_thread_missing`. The turn launches nothing |

`TaskTurn::admission` reads the standing. A row from before the mark has a null `external_id_admission`; it is `provisional` while no copy turn of the thread reported a `t3_thread_id`, and `overwritten` after. A reported ID that differs from the row's ID, or any report on a row without the mark, sets `overwritten`.

The provenance of an overwritten ID is not guaranteed. A first turn reports the ID that the helper made, but a continued turn reports the ID of a title match, and a person's thread with the same title passes that match. The row does not say which happened, so every overwritten row is refused, and the caller starts a new thread with `--thread=new`.

#### Stable IDs

Capture fixes, in the intent, one command ID per step, the thread ID and the message ID (`turn.message_id`). It also freezes the create body: project, title, provider instance, model and options. The message body is frozen too. Before any send of a step, a retry sends the same IDs and the same bodies. Once a step may have been sent, every retry of it only reads.

The worker counts the turn as launched only when `getThreadProjection` shows the admitted message and exactly one run that the target started from that message ID. It records that run ID. A message that may have been sent but has no confirmed run is only possibly sent. The result keeps the two apart:

| Result | `sent` | `launched` | `ended` |
| --- | --- | --- | --- |
| Nothing sent | `false` | `false` | `true`, unless the checkout is detached |
| Nothing sent, over a detached checkout not restored | `false` | `false` | `false` |
| Possibly sent, no confirmed run | `true` | `false` | `false` |
| Confirmed launch | `true` | `true` | As the run went |

`TaskTurnProjection::record` replaces a thread's stored questions only when `launched` is `true`. So a possibly sent answer keeps the questions. `ended: false` keeps the hold through `TaskTurn::unsettled`. A reviewer's turn cut after its detach and before its send holds the group too, as it does today with `tasks.copy_review_unrestored`.

#### Recovery of a lost reply

A lost reply or a dropped socket makes a step ambiguous. The worker then reads `getThreadProjection` for the exact admitted thread ID, with its grant. The target answers only when the grant's environment UUID and registration generation match.

That match does not prove that the server state is the same. The [durable launch](/reference/conn#durable-launches-v1) proves it with a probe session row, which needs a session that the stock 0.1.7 CLI issues locally. The task worker grant has no such witness, and item 4 supplies none. So the worker makes no decision that needs continuity. It accepts only a positive match on exact IDs and frozen bodies, which an older restored state cannot make up. It treats every absence as ambiguous, and it never replays.

| Step | Found | Result |
| --- | --- | --- |
| `thread.create` | The thread, with the frozen project, title, provider instance, model and options, and no message | The create happened. The worker goes on to dispatch |
| `thread.create` | The thread, with any other field or anything in it | The turn launches nothing and fails. The row is not reused |
| `thread.create` | No thread | Not proof that the create failed. The turn launches nothing and fails. A thread that appears later holds no message and stays idle |
| `message.dispatch` | The message, byte for byte, and one run started from its ID | The dispatch happened. The worker follows that run |
| `message.dispatch` | The message with no run, more than one run, or a different body | Ambiguous |
| `message.dispatch` | No message | Ambiguous. A bounded projection does not prove absence, and Rust Conn evicts receipts after 4,096 commands |

A replay would need the target to prove that it did not take the command ID. No target gives that proof, so the worker never replays a step. An ambiguous dispatch sends nothing more and fails with `tasks.copy_turn_failed`. Its result is possibly sent: `sent: true`, `launched: false`, `ended: false`. The turn stays unsettled, the group holds, and stored questions stay.

#### Ownership at dispatch

The worker never marks queued work as started. It never adopts a run that its own admitted message did not start. It never stops a run that someone else started. The group lock and the unsettled-turn check stay.

A person may write to the same thread from Conn at any time, also between `thread.create` and the first message. So the target checks ownership as part of accepting every `message.dispatch`, the first and each continued one. In one step it confirms that the grant names this thread, that no run is active and no message is queued, and then starts the run. Otherwise it refuses the message. It never queues it and never steers another run.

No target makes this check today. Item 4 supplies it. Until it lands, the worker refuses to dispatch, and task 5873 proves only that refusal.

#### Earlier turns

The settle rule in [Hold after a turn that may still run](#hold-after-a-turn-that-may-still-run) stops the thread's current active run. That run may be a person's by then. So under this contract, capture passes, for each unsettled turn, its thread ID, its frozen message ID, the SHA-256 digest of its frozen message body, any run ID it recorded, its `sent` and its `answered` list. A turn with no result counts as possibly sent. Each entry of `turn.settle` holds `t3_thread_id`, `admitted`, `message_id`, `digest`, `run_id`, `sent` and `answered`. A turn admitted before `message_id` has `message_id: null`. A result from before `sent` gives it from `launched`.

The thread ID is the one the turn reported. The worker reports the thread it targeted even when its last read of T3 fails. A turn with no report and an admitted ID passes that ID with `admitted: true`: it may have been sent, so the target can find the thread by it. No T3 was seen to hold that ID, so the CLI driver still finds such a turn's thread by its title.

An earlier turn whose result says `sent: false` has no message and no run to find. Only its marked checkout holds it. The worker restores and verifies that checkout, then names the turn settled.

For any other earlier turn, the earlier worker may have died after the target started the run but before it recorded the ID. So the next turn finds the earlier run by its link to the frozen message, never as the thread's current active run. The message counts only when its body has the passed digest, the same exact-body check as [Recovery of a lost reply](#recovery-of-a-lost-reply):

- Exactly one run started from the message, and it has ended: the run is over.
- Exactly one run started from the message, and it is active: the worker interrupts that exact run and waits for proof of its end.
- Another run is active: the worker leaves it alone. Only the earlier message's own run counts.
- The message is missing: the earlier turn stays unsettled. The worker launches nothing.
- No run or several runs started from the message: the same.
- The body does not match the digest, or cannot be read: the same. The worker interrupts nothing and names nothing spent.

A recorded run ID must equal the run that the link finds. Otherwise the earlier turn stays unsettled.

Settling keeps today's order. First the worker proves that the earlier run ended. Then, for a reviewer's turn whose detach mark is set, it restores the checkout and verifies it, only when the restore is safe as [Hold after a turn that may still run](#hold-after-a-turn-that-may-still-run) says. Only then does it name the turn settled. A reviewer's run that ended over a checkout not yet restored leaves the turn unsettled. A reviewer's turn without the mark is settled once its run is over, as today.

An earlier answer turn may prove launched only at this point: its `answered` list is not empty, its message passes the exact-body check, and the link finds its run. That run used the answer, so its stored questions are spent. The worker names those exact request IDs in its result as `spent`. `TaskTurnProjection` clears a thread's stored questions when their request IDs are exactly the spent ones. A partial overlap or a superset clears nothing. It does not wait for the earlier Operation, which is not recorded a second time.

The current turn froze its prompt at capture, so it may quote those spent questions as the question it answers. When its own `answered` list holds a spent request, it launches nothing and fails with `tasks.copy_question_answered`. The caller sends its prompt again. The next capture composes it without the spent questions.

#### Questions

The flow of [A question ends the turn](#a-question-ends-the-turn) stays, on the new calls:

1. A run started from the turn's message waits. Every pending request must be a complete user-input question, and each request's node must belong to that run.
2. The worker stores the questions, bounded, with each request ID. Each question's `run_id` names its owner run.
3. It reads the projection again. The same request IDs must still be pending, with the same owner run.
4. It interrupts that run and waits for proof that the run ended. Only then is the turn `asking_for_input` and over.

A request whose owner the worker cannot read stores no question and stops no run. An approval, a request without a question, or a request of another run is never a reason to interrupt. The turn then fails as it does today, and the group holds.

The answer is a new turn. Capture admits the stored request IDs and their owner run into its grant. The only change the grant allows to those prior requests is `runtime-request.respond` with decision `cancel`. The target accepts the cancel only when the request is still pending, is a user-input request of the admitted thread, and its owner is the stored run.

The worker first reads the thread. A stored request that this read shows resolved, cancelled or absent needs no action, as in #3980. It cancels each stored request that is still pending, then reads them back.

If the target cannot confirm the owner, or a read fails, or a request stays pending, the turn fails with `tasks.copy_question_unresolved`. The questions stay. There is no checkout and no send, and the grant allows no other change to those requests. Once every stored request is resolved, the answer goes out as the turn's own admitted message, with its own stable IDs, under [ownership at dispatch](#ownership-at-dispatch).

Approvals and refusals are not questions. The worker never answers an approval and never cancels one.

#### Task worker grant

The worker gets a separate task worker grant on item 4's managed binding. It reuses that binding and its revocation fence; the [Conn layer](/reference/conn) page holds the contract once it lands. There is no second mechanism.

- The Gateway issues an opaque bearer and stores only its digest.
- The Rust target checks it by an authenticated `POST` to the Gateway.
- It is bound to the target's environment UUID and registration generation. It names the task, the copy and the admitted turn.
- Its scopes are fixed. A caller cannot set them.
- A cache does not extend its expiry.

The grant holds an allowlist, fixed at capture:

| Allows | On |
| --- | --- |
| `getThreadProjection` and the thread's subscriptions | The admitted thread: its messages, runs and requests, also an empty thread right after create |
| `getThreadProjection` | Each earlier turn's thread named at capture, also another role's thread: only to find the run of its frozen message, its end and its requests |
| `thread.create` | The admitted thread ID, with the frozen body |
| `message.dispatch` | The admitted message ID on the admitted thread, under the owner check |
| `run.interrupt` | The run that the target started from the admitted message |
| `run.interrupt` | The run that the target started from each earlier turn's frozen message, as [Earlier turns](#earlier-turns) says |
| `runtime-request.respond`, decision `cancel` | The stored question requests of their stored owner run |

The grant covers a run that appears after dispatch only through the target's link from that run to an admitted message. It covers no other run. The target refuses any thread, run, request or command outside the allowlist, even when the scopes match. A grant of an ended turn cannot drive a later one. The worker's authority to operate is separate from a user's authority, through Orbit, to watch the thread or answer a question. Item 4's lab reader cannot run task work. No generic bearer and no admin scope is used.

Until task 5870 lands, the target does not check the allowlist or the turn binding. Until then, only a local proof runs, with no live writes.

#### Turn evidence

The Operation's result names:

- the admitted turn and its step IDs;
- the environment, thread, run and message IDs, as the target spells them;
- whether it was sent, whether it launched and whether it ended;
- the earlier turns it settled, with the run each stopped;
- the waiting request IDs, their owner run and their questions;
- one fixed outcome code;
- the reviewer's checkout and restore, as [Calls](#calls) describes.

The result never holds a bearer, a WebSocket ticket, a raw projection, a prompt, a transcript or a private argument. Nor do the logs or Activity: Activity records `task:turn` without its `prompt`.

The result's error holds no text of T3, a provider or the worker, which may quote the prompt. It holds the fixed sentence of its code, and nothing parsed from any text. A turn that has not ended adds a fixed sentence that the group holds. The raw text stays in the run folder. `TaskTurnProjection` enforces this for every result, also an older worker's, and drops any other error field. A code outside the copy turn's codes becomes `tasks.copy_turn_failed`.

One rule names a turn ended, judged on the turn's own run, never on another run of its thread. The first row that applies decides:

| # | Evidence | `ended` |
| --- | --- | --- |
| 1 | A reviewer's checkout is detached and not restored | `false` |
| 2 | Nothing was launched | `true` |
| 3 | The run asks, its question is stored with its owner run, and the interrupt of that run is confirmed | `true` |
| 4 | The run asks, and its question could not be read, a request is not this run's own, or the interrupt is not confirmed | `false` |
| 5 | The start printed a status that ends a run | `true` |
| 6 | The start printed any other status, such as `running` or `queued`, or one that Orbit does not know | `false` |
| 7 | The stop of the run that the bound cut proved its end | `true` |
| 8 | No status of the run: T3 lists no thread of the title, or lists it in a state that runs nothing | `true` |
| 9 | Anything else, such as a thread listed active or in an unknown state, or no list | `false` |

The settle of an earlier turn uses the same states that run nothing. A state it does not know leaves the turn open, and the next turn launches nothing.

The settle finds the earlier turn's thread by its captured ID, and by its title only when no ID is recorded or listed. It stops only the earlier turn's captured run, through `t3_copy_rpc.mjs stop THREAD RUN`, and holds when T3 shows another run or none.

A turn cut by its bound has no known run, so nothing stops it: it holds until T3 lists its thread in a state that runs nothing, or `task:cancel`. A stored question is kept only when this turn's run owns it, and the answer cancels only requests of that owner run. A T3 run status outside T3's known statuses is never named.

A question without its owner run is never stored. The turn records `tasks.copy_question_unread` and `ended: false`, so the next turn settles it. `tasks.copy_review_unrestored` outranks `tasks.copy_question_unread`: an unrestored reviewer turn keeps its code.

The run folder is the target's guarantee, not yet today's. The CLI driver stages the prompt in the run folder's `intent.json` and the thread's messages in `read.json`. The folder is private to the run: the folder is mode `0700`, `intent.json` is mode `0600`, and its cleanup removes the folder. The RPC worker drops that staging with the CLI calls.

The transcript goes only to the thread's archive, as `TaskTurnProjection` does today. The archive keeps assistant text, bounded and redacted. A user message appears only by its ID, author and time, because redaction does not catch an ordinary private prompt. The CLI driver already shapes it so, and the projection drops a user message's text that an older worker still sends. The stock CLI gives no message ID or time: under it, an entry's ID is the run ID and the message's index, and its time is empty. The Gateway already holds the admitted prompt in the Operation's input.

#### Proof and removal

Task 5873 proves this on one isolated copy. Each case passes only on its observable result:

| Case | Passes when |
| --- | --- |
| A user watches the same thread | The user sees the turn. The worker's run and messages are unchanged |
| A person sends between create and first message, and the person's run is still active at dispatch | The target refuses the worker's message. Nothing is queued. The person's run goes on |
| A person's run is active on a continued turn | The target refuses the dispatch. Nothing is queued or steered |
| Two workers at once on one turn | One message and one run exist. The second worker launches nothing |
| A wrong task grant | The target refuses every call. Nothing changes in the thread |
| The create reply is lost | The worker finds the empty thread and dispatches once, or launches nothing when the thread is missing |
| The dispatch reply is lost | The worker finds the message and its one run and follows it |
| A queued message with no run | The turn fails as possibly sent, stays unsettled, keeps any stored questions, and nothing else is sent |
| Recovery after receipt eviction | With the message unreadable, the worker replays nothing and the turn stays unsettled |
| A person's run is active during cleanup | The earlier turn's run is stopped only if it is its own. The person's run goes on. The reviewer checkout is untouched while unsettled |
| The earlier worker died before it recorded its run ID | The next turn finds that run by its message and stops only it |
| A reviewer's run ended, then the worker died before the restore | The turn stays unsettled until the next turn restores and verifies the checkout |
| A reviewer's turn cut after its detach and before its send, with `sent: false` recorded | The next turn restores and verifies the checkout, then settles it without looking for a run |
| A reviewer's turn cut after its detach, with no result | It counts as possibly sent. With no matching message, it stays unsettled and the checkout is untouched |
| An earlier answer turn's message with a different body | The earlier turn stays unsettled. No run is interrupted and no request is named spent |
| A reviewer turn follows an interrupted implementer turn | The reviewer's worker reads the implementer thread, stops only the run of its frozen message, then settles it |
| A possibly sent answer that ran | The next turn finds its run, names its stored requests spent, and fails with `tasks.copy_question_answered` when its own prompt quotes them. The turn after that sends no stale question |
| A stored request already resolved on the answer turn | No cancel is sent, and the answer runs as a new run |
| A live question | The questions are stored with owner run, the run is interrupted, and the turn is `asking_for_input` |
| A stale question on the answer turn | Only the stored request is cancelled, then the answer runs as a new run |
| A cancel with a wrong owner or failed readback | The turn fails with `tasks.copy_question_unresolved`. The questions stay. No checkout and no send |
| An approval and a refusal | The worker neither interrupts nor answers. The turn fails and the group holds |
| A timeout, a cancel and the cleanup | Only the turn's own run is stopped. The next turn settles it |
| A worker restart, then a server restart | Recovery finds the same message and run each time. No second message or run exists |
| A legacy overwritten row | The turn fails with `tasks.copy_thread_missing` and launches nothing |
| Evidence | The result, run folder and logs hold no prompt, bearer, ticket or raw projection |

Once that proof passes, the task driver drops `t3 thread send --wait`, `thread list` and `thread show`, the title lookup, and the CLI session that each helper call issues. The durable launch keeps its caller.

### Reach and authentication

Stock T3 0.1.7 serves its thread tools at `/mcp`, but accepts there only an MCP client session. A session that `t3 auth session issue` makes, as the create's `Orbit Swamp` session, is refused. A session issued with the MCP client subject is held to `approval-required`, because the CLI cannot give it a higher mode. Only T3 itself issues a full-access MCP session, through its OAuth approval or in its own process. So Swamp uses T3's own CLI:

- **Path.** One Ansible phase on the container's Node runs `incus exec` into the container, then `runuser -u agent`. The shell is not a login shell.
- **Lock.** Swamp locks the turn's step by the copy's Instance id for the whole turn. One copy takes one turn at a time; turns in two copies on one Node run side by side ([turn](/cli/task/turn#in-a-task-copy)).
- **Groups.** `runuser` keeps the agent's groups. `incus exec --user` would drop the `www-data` group, which owns the checkout's Git folder.
- **Session.** Each `t3 thread` command issues its own full-access session inside the container.
- **Session end.** The command calls T3 on its loopback address. At its exit, it revokes the session.
- **No credential outside.** No T3 session reaches the Node, Swamp, the vault or the Gateway.
- **Orbit Swamp session.** The create still issues it and keeps it in the vault. The turn does not use it. Destroy revokes it. Orbit does not renew it, so it ends after 30 days.
- **Workspace.** File and Git actions in the container go over SSH to the Node, then `incus exec`. No token enters the container.

### Calls

The phase uses these commands, as `agent` in `/srv/workspace`.

| Purpose | Command | What Orbit sets |
| --- | --- | --- |
| Create a new thread | `node - T3 create TITLE INSTANCE MODEL OPTIONS`, with `t3_copy_rpc.mjs` on stdin | Through T3's own RPC: the title `Orbit task GROUP thread THREAD`, the provider instance of the effort, the [turn model](#turn-model-and-effort) and its model options, in full access, with no message. Bounded at 60 seconds |
| Resolve stored answered requests | `node - T3 answered T3THREAD REQUEST...`, with `t3_copy_rpc.mjs` on stdin | Before reviewer checkout or send: cancel only the stored pending questions, then confirm resolution in one RPC session. Bounded at 60 seconds |
| Run a turn and wait for its run | `t3 thread send --wait --json T3THREAD -` | On the created or the continued thread: the composed prompt on stdin and a 3,600-second `timeout`. The thread keeps its provider instance and options, so its model and effort. |
| Find the thread | `t3 thread list --project /srv/workspace --json` | Matched by its title: before a continued turn, and after every turn, also when the bound cut the wait before T3 printed the thread id. Bounded at 60 seconds |
| Read the thread | `t3 thread show --messages 200 --json THREAD` | The thread's status and its user and assistant messages. Bounded at 60 seconds |
| Put a reviewer on the candidate | `git status --porcelain`, `git symbolic-ref --short HEAD`, `git rev-parse HEAD`, `git checkout --detach CANDIDATE` | Before a reviewer turn: a clean checkout on a branch, its tip, the detach mark at it, then the candidate, detached. Each bounded at 20 seconds |
| Restore the checkout after a reviewer | `git reset --hard`, `git clean -fd`, `git checkout BRANCH`, then the status, branch and tip reads again | After a reviewer turn, also a cut one: the reviewer's changes go and the task branch is back at its earlier tip. A verified restore clears the detach mark |
| Read the question of a run that asks | `node - T3 read T3THREAD`, with `t3_copy_rpc.mjs` on stdin | When the start exits 2. Prints the open runs, the pending requests with their questions, and whether Orbit may stop on them. Bounded at 60 seconds |
| Stop the run that asks | `node - T3 interrupt T3THREAD RUN REQUESTS`, with `t3_copy_rpc.mjs` on stdin | After storing the questions, only for those exact requests whose nodes belong to this run. Waits for its end within 42 seconds, inside a 60-second bound |
| Stop a thread's active run | `node - T3 stop T3THREAD`, with `t3_copy_rpc.mjs` on stdin | After a wait cut by the bound, and before the launch for an earlier turn that T3 shows active. Same interrupt and wait; bounded at 60 seconds |

The start returns the run's status. `completed` is `done`, `waiting` is `asking_for_input` once [the question ends the turn](#a-question-ends-the-turn), and any other status is `failed` with `tasks.turn_failed`. A wait cut by the bound is `failed` with `tasks.turn_timeout`; the phase stops its run, and the turn is over once that stop ended it. A start that T3 refused, for example while its server is down, is `failed` with `tasks.copy_turn_failed`.

A continued turn whose title T3 lists not exactly once launches nothing and is `failed` with `tasks.copy_thread_missing`; a list that fails or is not JSON is `failed` with `tasks.copy_turn_failed`.

A reviewer turn on a checkout with changes or no branch launches nothing and is `failed` with `tasks.copy_tree_dirty`, and one whose checkout is not clean on its branch at its earlier tip afterwards is `failed` with `tasks.copy_review_unrestored`, whatever its run did. The phase marks the detach as soon as it is done, so a reviewer turn cut before the restore is unrestored too.

Each role's turn gets its own fixed prompt text, as [Run an external turn](/reference/tasks#run-an-external-turn) describes. A reviewer's text names the checked commit and holds the [reviewer rule](/reference/tasks#the-reviewer-rule) and the [pull request text](/reference/tasks#the-reviewers-pull-request-text) rule. An implementer's text holds its own rules, including how to ask, and neither of those two.

### A question ends the turn

Stock T3's CLI cannot resolve a pending question request or stop a run. Sending a new message with `t3 thread send` does not resolve that request. Stock T3's server exposes both operations through its own WebSocket RPC, which T3's web client uses:

- `POST /api/auth/websocket-ticket` with a bearer session gives a ticket.
- `GET /ws?orchestrationProtocol=2&wsTicket=TICKET` opens the RPC.
- `orchestration.getThreadProjection {threadId}` gives the thread's runs, its pending requests and each `user_input_request` with its questions.
- `orchestration.dispatchCommand` with `run.interrupt {commandId, threadId, runId, reason}` stops a run; `runtime-request.respond {commandId, threadId, requestId, decision: "cancel"}` resolves a question request without sending a reply message.

`t3 auth session issue` grants the session. Its MCP endpoint `/mcp` refuses that session, so the MCP tools are not a path.

So the phase ends a turn at its question. When `--wait` exits 2, `t3_copy_rpc.mjs read` stores every pending request with its questions: the id, header, text, options and flags, as the agent asked them. A free-text question without options is stored too. Only after that evidence is written does `t3_copy_rpc.mjs interrupt` stop the run.

The read must find questions alone: no approval, no request without a question, and no more text than the result can hold. The run must still wait for exactly the stored requests, and every request's node must belong to the run being stopped. A run that someone answered meanwhile, that now asks something else, or whose pending question belongs to an earlier run goes on.

The helper runs on the runtime's Node.js, `/opt/orbit-runtime/current/bin/node`, from stdin. It takes a session of one minute, read-only for the read, holds it only in its memory and revokes it before it exits. Its `stop` finds the thread's active run, sends the same `run.interrupt` as `interrupt` and waits for the run's end the same way. It answers `{stopped, run, state}`. A thread with no active run answers `stopped: false` and no run. No session reaches an argument, a file, a log or the run folder. T3 stays stock.

The turn is then `asking_for_input` and over, and its Operation succeeds. Its result and its thread keep the questions. A question the helper could not read is `tasks.copy_question_unread`, and a run it could not stop is `tasks.copy_question_unstopped`. Neither is over, so the group holds.

The Gateway passes the stored question request IDs in the continued turn's `answered` list. Before any reviewer checkout or send, `t3_copy_rpc.mjs answered` cancels exactly those pending user-input requests and reads them back in one RPC session. Requests already resolved, cancelled or absent need no action. An older admitted intent without `answered` treats it as an empty list.

If a stored request remains pending, or resolution cannot be confirmed, the turn fails before launch with `tasks.copy_question_unresolved` and keeps the questions for retry. Once resolution is confirmed, the role's next turn quotes the questions with “You asked in your last turn” and sends the caller's answer as a new run with `t3 thread send`.

In T3 0.1.7, interrupting a Claude question with a `live` response capability cancels its request. A Codex question with a `message` response capability can remain pending after interruption. The CLI checks the thread's pending requests after a 15-second wait slice, so a stale request can make a continued run return `waiting` while it is doing work. Resolving the stored requests before sending the answer prevents that stale question from ending the new turn.

### Turn model and effort

A new thread of each role takes the role's provider, model and effort from the task, else the Project's default, else the Gateway's `ORBIT_TASKS_COPY_*` settings, as [copy turn agents](/reference/tasks#copy-turn-agents) describes. The Gateway ships `claude` and `claude-opus-5-5` for both roles, at `medium` effort for the implementer and `high` for the reviewer. A Project can set `codex`, for example for its reviewer with `gpt-6.1-sol` at `high`. Codex reaches the model router through the copy's `/home/agent/.codex/config.toml`, with the same model key as Claude Code: no other credential enters the container.

Stock T3's CLI takes no effort, but T3's settings can hold several provider instances of one driver. The create of a task copy writes `/home/agent/.t3/userdata/settings.json` with one instance per provider and effort:

| Provider | Instances | How the effort reaches the agent |
| --- | --- | --- |
| `claude` | `claudeLow`, `claudeMedium`, `claudeHigh`, `claudeXhigh`, `claudeMax`, driver `claudeAgent` | `CLAUDE_CODE_EFFORT_LEVEL` in the instance's environment |
| `codex` | `codexLow`, `codexMedium`, `codexHigh`, `codexXhigh`, `codexMax`, `codexUltra`, driver `codex` | The thread's `reasoningEffort` model option |

A new thread is created on the instance of its provider and effort, through T3's own RPC, with its model and model options. Its first turn uses `t3 thread send`, as a continued turn does. Orbit refuses a provider and effort with no instance (`tasks.copy_turn_unoffered`) and checks the model against T3's cached model catalog. When that catalog does not confirm a Codex model, Orbit asks the copy's Codex provider to verify the exact slug before starting the thread. A turn's result names the provider instance, the model and the effort the thread started with. See [copy turn agents](/reference/tasks#copy-turn-agents).

Claude Code takes `CLAUDE_CODE_EFFORT_LEVEL` over the `--effort` that T3 passes from its model catalog, so the thread runs at that effort. This was verified with Claude Code 2.1.311 and T3 0.1.7. A Claude thread carries no model options.

Codex takes no effort from its instance. T3 0.1.7 sends Codex, on every turn, the effort of the thread's `reasoningEffort` option, and no effort without one. Codex's own `model_reasoning_effort`, through `-c` launch arguments or a per-instance `CODEX_HOME`, is then dropped, and Codex runs at the model's default: `low` for `gpt-6.1-sol`. So a Codex thread carries its effort as that option, and `t3 thread start`, which takes no options, cannot start it.

This was verified with T3 0.1.7 and Codex 0.167.0: `gpt-6.1-sol` ran at `high`, in full access (approval `never`, sandbox `danger-full-access`), and T3's cached catalog lists `gpt-6.1-sol` for the `codex` driver. Codex leaves variables whose name holds `KEY` out of its shell commands, so the reviewer's pull request text rule sources `model.env` first. A newer version may change any of this.

A continued thread stays on its instance and keeps its options, so it keeps its model and effort. A copy made before these instances existed refuses new threads, and a copy made before the Codex instances cannot run a new Codex thread; cancel it and start the task again on a new copy.
### Gaps of the CLI path

These hold until a T3 server gives Swamp a full-access MCP session:

- **Message text only.** The transcript holds the user and assistant messages, redacted and bounded to 256 KiB. It holds no tool names or states.
- **No token metrics.** The CLI reports no token use. The thread's metrics stay null.
- **No consult and no caller's model.** Both were options of the removed Pi turn; a request with either answers `validation.failed` on that field.

### Hold after a turn that may still run

One rule decides when a turn's run is over.

A thread is named only by its captured T3 thread ID, whatever its title. Only a turn with no ID that T3 holds is found by its title. A new thread's admitted UUID is such an ID, because T3 never received it.

A run is over only when a readback shows that exact run ID in a status that ends it. An accepted interrupt is no proof. A run that T3 does not show is no proof either, and neither is a thread that T3 does not list. The turn then holds. A turn with no recorded run, such as one cut by its bound, is over when T3 lists its thread in a state that runs nothing.

Nothing that needs the run over happens before it ends. That covers the reviewer's restore, the clearing of the detach mark, the settle and `ended: true`.

A turn is over when nothing was launched, or when the start printed an end of its run. A run that asks is over once the helper read it interrupted. A run cut by the turn's bound is not stopped, since the start printed no run ID: its turn is over once T3 lists its thread in no active state. Until then a reviewer's checkout stays detached and its mark set. A reviewer turn whose checkout is not restored is not over. Nor is a turn cancelled before its run step reports.

The same rule settles a turn that is not over. For a reviewer's turn, its detach must also be undone. Only a turn's phase can read that, so the next turn settles it:

- The Gateway puts every turn that is not settled into the next turn's intent, with its title. A reviewer's turn adds the task branch.
- Each of these turns is over in Orbit: it timed out, was cancelled or was lost.
- The result names the settled turns. The Gateway counts them settled from then on.

The phase lists T3's threads first. For each recorded run, `t3_copy_rpc.mjs stop` reads that exact run on the captured thread. It leaves a run that T3 shows over alone, and interrupts a run that still goes on, then waits for its end. It answers `over` only when its last read shows the run in a status that ends it. A turn with no recorded run is judged by its thread's listed state. A turn that sent nothing has no run. The phase writes which turns it found settled, and names each run it stopped.

When a stop fails or proves no end, the earlier turn is not settled. The phase touches nothing and launches nothing, and fails with `tasks.copy_turn_unended`. The work in the copy stays.

A reviewer's detach marks the copy with the ref `refs/orbit/review-detach`, at the tip the branch had. Only a verified restore clears the mark. The phase restores the checkout only when the mark is set, every earlier run is over, and the checkout is still detached. The restore is a reset, a clean and a checkout of the task branch. The checkout must then be clean on that branch at the mark's tip, and the phase clears the mark.

Without the mark, the phase never touches the tree. The reviewer's turn is then settled once its run is over, so an implementer's uncommitted work stays.

A check, a publish or a proof of the copy refuses with `tasks.copy_turn_unended` while a turn is not settled, and waits for the next turn, which stops the earlier run and settles it. `operation:cancel <turn>` ends a running turn's Operation, and the next turn stops its run. When the last subtask is completed, the caller [reopens it](/reference/tasks#reopen-the-last-subtask) with `task:update <subtask> --status=running`, and its next turn settles the copy. When a completed subtask cannot reopen, no turn can run, and `task:cancel` is the only way out: it ends the copy, its work and any run with it.

The threads and roles, thread state, provider errors, token metrics, retries and transcripts below remain the target. Their MCP and WebSocket calls need a T3 server that gives Swamp its own full-access session.

### Threads and roles

Each subtask gets a new implementer thread, and its first review a new reviewer thread. A consult uses the reviewer thread. Titles, the composed prompt, Orbit's fixed instructions, the [turn receipt](/reference/tasks#turn-receipt) and the reviewer's reset after its turn stay as for Pi. The Orbit thread row records the driver `t3`, the T3 thread id as its external id, and the Instance's Node.

A new thread takes any model the model router serves for Codex or Claude Code. Claude runs through Claude Code, Anthropic's own application, so the `t3` driver does not refuse it. The role defaults and effort settings stay.

### Thread state

The state of a thread is the status of its latest run, from `t3_thread_wait` or `orchestration.getThreadProjection`. A T3 restart ends a run and continues it in a new run that points back to it, so the latest run is the end of that chain.

| T3 run status | Orbit state |
| --- | --- |
| No run | `idle` |
| `preparing`, `queued`, `starting`, `running` | `working` |
| `waiting` | `asking_for_input` |
| `completed` | `done` |
| `failed`, `interrupted`, `cancelled`, `rolled_back` | `failed`, with T3's class and message as the error and the [provider error](#provider-errors) that Swamp maps |

`full-access` agents ask for no approval. A `waiting` run comes from a question that the agent asks, or from an MCP server of the project that asks for input.

### Provider errors

Swamp translates a failed run's T3 failure into Orbit's provider error codes, the `providerError` that a Pi thread reports. So retry, assistance and reports work as they do for Pi. Swamp reads the failure from the run's `error` item in `orchestration.getThreadProjection`: its `class`, `code`, `retryable` and `resetAt`. T3's `code` is an adapter string. Claude Code gives `api_error_<status>` or the SDK's error word, and Codex gives the name of its error variant.

| T3 failure code | Orbit `providerError` |
| --- | --- |
| `api_error_529`, `overloaded`, `serverOverloaded` | `overloaded` |
| `api_error_429`, `rate_limit`, `rateLimitExceeded` | `rate_limited` |
| `api_error_5xx`, `server_error`, `internalServerError`, `responseTooManyFailedAttempts`, and Codex codes that start with `http` or `responseStream` | `server_error` |
| `usageLimitExceeded`, and any other code | None |

A rate limit whose `resetAt` lies more than 15 minutes ahead maps to None: that is a spent quota, which Pi does not treat as transient either. A `responseTooManyFailedAttempts` with HTTP status 429 maps to `rate_limited`.

A failure with no mapping leaves `providerError` absent, as Pi does for a failure that is not transient: quota exhaustion, authentication errors and refusals. The thread is `failed`, and its error is T3's class and message, so a report shows the cause. An `interrupted` or `cancelled` run has no failure item and maps to None. A code that T3 adds later has no mapping until Orbit adds it to this table. PR 8 pins the table with fixtures taken from both adapters.

### Token metrics

Swamp reads the `turnTokenUsage` of each provider turn from `orchestration.getThreadProjection`, over every run of the turn's restart chain, and adds them up. It skips a provider turn marked `localCommand`, whose counts describe a local command, not model work.

| Orbit metric | From T3 |
| --- | --- |
| Uncached input | `inputTokens − cachedInputTokens`. T3's input counts cache reads and writes, and Orbit counts a cache write as uncached |
| Cached input | `cachedInputTokens` |
| Output | `outputTokens`, which holds the reasoning tokens |
| Subagents and compaction | `otherUsage.inputTokens` and `otherUsage.outputTokens`, added to uncached input and output |
| Cost | Null. T3 reports no cost per turn |

A provider turn without `turnTokenUsage`, or whose `usageStatus` is `partial` or `unavailable`, leaves the turn's metrics null, because a partial sum would look complete. The thread keeps the sum of its turns, as with Pi.

### Retries and restarts

T3 and its agents own recovery inside a turn. Swamp only waits.

- **Provider errors.** Claude Code and Codex retry a transient provider error inside the turn. T3 records each attempt, and the run stays `running`.
- **No retry by Orbit.** Swamp and the Gateway retry none. An exhausted retry ends the run `failed`, with the [provider error](#provider-errors) that Swamp maps.
- **T3 restart.** A folder update or a crash restarts T3. T3's restart continuation continues the turn in a new run, and the wait follows that chain.
- **Latest run.** Swamp uses the `runId` that each wait returns for its next wait, interrupt and state read.
- **Lost wait.** A step that loses its connection calls `t3_thread_wait` again with the last run id it has. Native resume reuses completed steps, so the keyed send never repeats.
- **Cancel.** A cancelled turn operation interrupts the run, so the turn stops in the container.

### Transcripts and changed files

When the turn ends, Swamp reads the thread's activity since the turn began with `t3_thread_read`, redacts it and records the bounded transcript, as for Pi: message text and each tool's name and status, never tool input or output. The Gateway serves that record, also after a clone is destroyed. While the Instance lives, its user sees the whole thread in Conn.

Swamp keeps its own Git evidence before and after a turn, with a temporary index in the workspace. It does not use T3's diff calls; Conn shows them to a user.

[ADR 0250](/decisions/0250-run-instances-and-task-groups-in-incus-containers) records the choices behind this contract; [remaining acceptance](/decisions/0250-run-instances-and-task-groups-in-incus-containers#remaining-acceptance) distinguishes unfinished work from delivered behavior.

Migration checks executable availability before downtime and requires moved running Processes to settle before cutover. The [`instance:migrate` contract](/cli/instance/migrate) defines the runtime command mapping, readiness window and recovery.

## Nested fleet

A Project setting turns on a nested fleet. Each Instance container of that Project then holds a fleet of Incus containers nested inside it: a Gateway with its own Swamp, an `app-dev` Node and an `app-prod` Node. Orbit uses it to prove its commands before a pull request. Other Projects leave it off and keep the kernel surface of [Inside a container](#inside-a-container).

Built: placement, host preparation, the start guards, the readers below and the [nested-fleet image](#the-nested-fleet-image). The [fleet step](#the-fleet-step) is built. The deployment of the default calls it in the [refresh after a release](#refresh-after-a-release), and the create of a clone calls it to [start its fleet](#a-clone-starts-its-fleet).

Every Instance of a Project with `nested_fleet` on is a container in the nested Incus project, on a Node with nested-fleet hosting on. A proof copy can also be on a [CI-only Node](/reference/node-provisioning#proof-copies-on-a-ci-node). One placement rule in the Gateway refuses every other result before any record, and it also chooses the Incus project. [Nested fleet setting](/reference/projects#nested-fleet-setting) has the rule. The sections from [The fleet](#the-fleet) on describe the target design.

### The container

An Instance container with a nested fleet lives in its own Incus project on the Node, `orbit-instances-nested`, which allows what the fleet needs. `orbit-instances` keeps refusing it. An app-dev Node prepares that project, its profile and two kernel modules when its [nested-fleet switch](/reference/node-settings#nested-fleet-instance-hosting) is on, as does a [CI-only Node](/reference/node-provisioning#proof-copies-on-a-ci-node). It shares the pool, the bridge, the ACLs and the egress proxy with `orbit-instances`. The container is unprivileged and sets these keys:

| Key | Value | Why |
| --- | --- | --- |
| `security.nesting` | `true` | The inner Incus and Docker run in it |
| `security.syscalls.intercept.mknod` | `true` | The inner Incus makes device nodes for its containers |
| `security.syscalls.intercept.setxattr` | `true` | The inner Incus and Docker set trusted extended attributes |
| `security.idmap.isolated` | `true` | The container has its own ID range, as every Instance container |
| `security.idmap.size` | The runtime's [recorded size](#the-id-map-size): `101000000`, or `1165536` after a conversion | The inner Incus takes its range from it: the outer size minus 1,000,000 |
| `limits.memory` | `12GiB` | The inner fleet shares the container's memory |
| `limits.memory.swap` | `12GiB`, the same value as `limits.memory` | Incus gives a container with `limits.memory` no swap unless this key holds a byte size. The fleet's Instances sleep into swap through this container |
| `boot.autostart` | `false` | Incus never starts it at host boot, without the guard below |
| `user.max_user_namespaces` | Not set to zero | The inner Incus needs user namespaces |

Its project allows low-level keys (`restricted.containers.lowlevel=allow`), because `security.idmap.size` is one. So a guard runs before every start of a container in that project: the Ansible start, the native clone and the host converge's clone probe.

The guard reads the effective config and devices, with the profile's. It is an allowlist. It refuses a container whose config or devices differ from what Orbit writes for it. The error is `instance.container_network_unsafe`, and the log names the refused key or device.

The config must be exactly the intent's own `config`, with the same values. That map holds the keys in the table above and the common limits. Create and clone write the same map, so the two cannot drift. A missing key is refused too. These keys pass as well:

- `volatile.*` and `image.*`: Incus writes them itself, and Orbit writes none. The guard does not judge them, as before.
- `environment.*`: they only set the environment of processes in the container.

Any other key is refused, for example a `raw.*` key, `security.privileged`, `linux.kernel_modules`, another syscall intercept or a `user.*` key.

The devices must be exactly the root disk, the NIC, the two read-only shared folders with their own sources, the T3 proxy of a clone, and the disk `inner-pool` of an Instance with an [inner volume](#the-inner-volume). So no other host path, no other volume, and never the host's Incus socket, reaches it.

The guard also reads the storage volumes, whose keys the container's config does not show:

- The inner volume has exactly the keys of [The inner volume](#the-inner-volume), with their values.
- Incus's own `volatile.*` keys pass. Any other key is refused, such as `zfs.delegate`.
- An Instance without an inner volume in its intent gets no `inner-pool` device: the device list above refuses it.
- The root volume must not have `zfs.delegate=true`, which would hand the container the host's ZFS commands on its dataset.

The refusal names the volume and its key, for example `volume inner-pool block.mount_options`.

The clone probe writes only `security.idmap.isolated=true`, so its guard admits only that key. A container of `orbit-instances` keeps its NIC guard, with the same program and arguments as before.

The root disk's `size` is the one device key that may be larger than the intent's, as [The root disk](#the-root-disk) explains. Any other form than `<n>GiB` is refused. The host converge's clone probe inherits the shared profile's root disk, which names no size. For the probe, the guard admits only a root without a size.

After a host reboot, Incus restores the containers that were running, without the guard. A nested-fleet container has `boot.autostart=false`, so it stays stopped. An Orbit start of the Instance, such as its deployment, starts it again through the guard. A container made before the key existed gets it at its next start, before the guard reads it. A start writes two keys this way, `boot.autostart` and `limits.memory.swap`, and Incus applies each without a restart.

Orbit owns both keys: the start writes the intent's value over an absent key or a different value, and does not refuse it. The guard keeps refusing every other key and device that differs.

The outer container is only the operator of its fleet. It runs no fleet service itself. It holds:

- the inner Incus, its bridge and its pool on the [inner volume](#the-inner-volume); the first nested-fleet default, made before the volume, keeps a directory pool;
- the operator end of the fleet's WireGuard network, which the [start phase](#the-fleet-step) brings up with `ip` and `wg` at each fleet start;
- the nested Gateway's root CA and a CLI profile `nested`, so the candidate CLI in `/srv/workspace` talks to the nested Gateway.

The tunnel has no unit: `wg-quick` fails under the outer container's AppArmor profile.

### Capacity

Nested and normal Instances share the Node's address pool, T3 ports and storage pool. A `/24` bridge has 252 Instance addresses, `.2` to `.253`: the bridge takes `.1` and the image build `.254`. Each non-default Instance also takes one of the 200 T3 ports, `3800` to `3999`. Every root disk is on the `orbit-instances` pool. It has 40 GiB, or for a nested-fleet default the size that [The root disk](#the-root-disk) names. A root size is a ZFS quota, not a reservation, and no admission or Doctor check adds root sizes up.

At reservation, the placement rule refuses first, then a full bridge, then a full T3 port range; the last two refuse with `instance.host_unavailable` (409). So one Project with a default and 200 clones runs out of T3 ports first, and many Projects with defaults can run out of addresses first. Free pool space and the [subordinate-ID demand](/reference/node-settings#nested-fleet-instance-hosting), which the converge checks, can limit a Node earlier.

### Readers of the recorded project

Each runtime records its Incus project once. Every step that reaches the container reads that record: create, deploy, clone, destroy, Processes, Schedules, mail, Node services, migration and T3 setup. The shared-folder refresh restarts running containers in every project that the Node's runtimes record, before it clears the folder's restart mark, also after the switch is off. With no nested runtime, it runs the same command as before.

### The root disk

The root disk of a nested-fleet default has the size of the Gateway setting [`ORBIT_INSTANCES_NESTED_DEFAULT_ROOT_SIZE`](/reference/gateway-environment), 80 GiB by default. Every other container, a copy of a default among them, keeps 40 GiB.

On a btrfs pool, such as the pool of the nested proof Node `nested-app-dev`, the root disk has no size. Incus would set it with btrfs qgroups, which the unprivileged nested Node refuses, and the copy's own volume already caps that pool. The Node-side rule `instance_root_disk.py` drops the size there for create, clone, the start's grow and the guard alike, and keeps it on every ZFS pool.

Incus sets the size as the ZFS `quota` of the container's dataset. A quota counts the dataset's snapshots. A default's seeds stay on its dataset while a copy made from them lives, as hidden `deleted-*` snapshots, and each one that a `base` retake replaced holds about 1.7 GiB. A copy's quota counts only its own blocks. The inner Doctor wants 2 GiB and 10% of the disk free, and a [refresh](#refresh-after-a-release) checks that room first. At 40 GiB, six living copies on old seeds left too little room on Instance 2454.

The size reaches a container two ways:

- A create and a clone write the intent's size.
- An existing default grows at its next start, such as a deploy. The start reads a smaller `<n>GiB` and sets the intent's size before the guard.

Incus sets the quota on the running container, without a restart. A failed grow fails the start before the guard, and the container keeps its old size.

A start never shrinks a root disk. On ZFS, a quota below the used space fails, and Incus then leaves the dataset without a quota. So a lowered setting changes no existing default, and the guard admits a root size that is larger than the intent's. That also keeps a default's start working after a rollback to a release that writes a smaller size. A rollback to a release before this rule refuses a grown default at its start (`instance.container_network_unsafe`, device `root`): roll forward instead.

A setting that is not a whole number of GiB of at least 40 GiB, such as `80GB`, refuses every intent of a nested-fleet default with `instance.nested_root_size_invalid` (503).

### The nested-fleet image

A Node with nested-fleet hosting on builds a second image in its nested Incus project, after the normal image. A Node with the switch off builds only the normal image. The normal image's definition, alias and build steps stay the same.

The nested-fleet image has the stable alias `orbit-instance-nested`. Its own alias is `orbit-instance-nested-` with 12 hex digits of a digest of its definition, so a changed definition is a new image. The digest names each inner Incus package by its name and SHA-256, not by its download address.

It is the normal image with these changes:

| Part | Change |
| --- | --- |
| Inner Incus | `incus-base` and `incus-client` from the recipe's pinned artifacts, checked by SHA-256 |
| Packages | `wireguard-tools`, `sqlite3`, `zstd` and `btrfs-progs` too. `zstd` reads the `.tar.zst` archives of the inner fleet's containers; the inner Incus's btrfs driver needs `btrfs-progs` for the pool on the [inner volume](#the-inner-volume) |
| User namespaces | No `/etc/sysctl.d/90-orbit-no-userns.conf` |
| Subordinate IDs | `/etc/subuid` and `/etc/subgid` give root `1000:1` and `1000000:100000000`. The inner Incus does not read them: see [The ID map size](#the-id-map-size) |
| AppArmor marker | `/etc/orbit/apparmor-off` holds `N` |
| `agent` | A member of the inner `incus-admin` group. Its passwordless sudo is the normal image's grant |
| `orbit` (1000) and `www-data` (33) | Neither the group nor sudo; `www-data` is in `work`. The first output rule drops their traffic to the inner fleet's networks, `10.45.0.0/24` and `10.232.2.0/24` |
| Firewall reset | `destroy table inet orbit_agent_guard` instead of `flush ruleset`, also as the `ExecStop` of `nftables.service` |
| Network wait | `systemd-networkd-wait-online.service.d/90-orbit-no-dns.conf`: eth0 routable, at most 30 s, no DNS |

The firewall resets only its own table. So a reload or restart of nftables keeps the inner Incus's own tables, its bridge NAT and ACLs.

At start, the app account script also converts an older nested image's `flush ruleset` to `destroy table inet orbit_agent_guard` before loading the updated guard. It repairs a partially applied guard too. It never loads a global flush into a running nested fleet. A failed start records the task name and the end of its stderr in the public failure detail, up to 4096 characters in all. Private tasks keep their output private.

The inner Incus wants `network-online.target`. Netplan's own wait asks for a DNS server on eth0, which a container never gets, so it held a start for 120 s. The image's wait asks only for a route. It is not masked, so the inner Incus still starts after eth0 has its address.

The Node fetches each Incus package through the artifact cache, checks its SHA-256 and pushes it into the build container. The build container installs both packages with their dependencies through the egress proxy. A `policy-rc.d` file stops the packages from starting the inner Incus during the build; the build removes it before it publishes.

A failed fetch, a wrong digest or a failed install publishes no image. There is no unpinned fallback.

Before it publishes, the build checks the sudo paths of both images:

- no `ubuntu` user and no member of `sudo` or `admin`;
- no grant in `sudoers.d` other than `orbit-agent` with its one line;
- a grant that `visudo` accepts, judged by its exit code, not its output;
- sudo works for `agent` and fails for `www-data`;
- in the nested-fleet image `agent` is alone in `incus-admin`, and the normal image has no `incus-admin` group.

An Instance start fetches and installs nothing. A failed build removes only a build container that it made; a build container from an earlier run stays for the operator.

A failed nested-fleet build fails only the nested-fleet image. The converge goes on: the normal image, the project, the bridge, the folders and the end checks complete, and the role succeeds. The Node records the failure in `/var/lib/orbit/instance-nested-image.json`: `nested image build failed`, the image's alias and the failed task. The converge log names the failed task too.

While that record exists, the create of a nested-fleet default refuses with `instance.nested_fleet_image_unavailable` before any Incus change, because the stable alias names an older image or none. A clone copies the default's seed, not the image, and goes on. The next converge builds the image again; a build that succeeds removes the record. A failed build of the normal image still fails the role.

### The fleet

The fleet runs under the inner Incus:

- three nested containers, `nested-gateway`, `nested-app-dev` and `nested-app-prod`;
- the inner storage pool: the directory pool `topology-seed` of the first nested-fleet default. A new nested-fleet default has an empty btrfs pool `inner` on its [inner volume](#the-inner-volume);
- the inner bridge `oe-nested` and the fleet's WireGuard network, which the nested Gateway leads; the outer container joins it as the operator.

A delegated ZFS dataset is not used: a copy would need one host clone per child dataset, and the inner Incus records the dataset's host name, which a copy changes. [ADR 0250](/decisions/0250-run-instances-and-task-groups-in-incus-containers) has the decision.

### The inner volume

A new nested-fleet default gets its own volume for the inner fleet, as the task VM had its seed disk. Its runtime records the volume's name in `inner_volume`, and the intent carries it as `container.inner_volume`. An existing default gets it in its [conversion](#convert-a-default): the operator makes the volume by hand, and `gateway:instance-inner-volume` records the same name. The [first default's create](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/create-default.mmd) makes it:

| Part | Value |
| --- | --- |
| Volume | `inner-<id>` on the pool `orbit-instances`, in the Incus project `orbit-instances-nested`; a sparse 30 GiB zvol |
| `zfs.block_mode` | `true`: a block volume that Incus formats with a file system |
| `block.filesystem` | `btrfs`: snapshots, restores and clones of the inner containers take about a second |
| `block.mount_options` | `user_subvol_rm_allowed,discard`: the inner Incus deletes its own subvolumes; freed blocks go back to the pool |
| `security.shifted` | `true`: the volume mounts idmapped. Without it Incus shifts every file to a copy's new ID map at attach, 31 to 39 s per copy |
| Device | the disk `inner-pool` at `/srv/inner-pool` |
| Inner pool | `inner`, driver `btrfs`, `source=/srv/inner-pool/pool` |

On the host this is one volume and one device on one container: no pool, profile or project key, no kernel module setting and no host package. The one host change is a ZFS property, set once by the [host branch](/reference/compute-drivers#prepare-an-instance-container-host) of a switched-on Node on a ZFS pool: `volmode=dev` on the pool's `custom` dataset (see the copy below). Incus formats the volume with the host's `mkfs.btrfs`. The container does not see the block device; it reaches the host's btrfs code only through file operations and the subvolume calls of its own file system, and the volume's size caps its space.

A failed create keeps the volume with the container for the retry, which reuses it. [Destroy](#destroy) deletes it with its snapshots.

A copy of such a default copies the volume's seed snapshot with `incus storage volume copy`, a ZFS clone. Incus 7.0.2 makes that clone without a `volmode`, so it inherits the value of the pool's `custom` dataset. Then Incus sets `volmode=dev` to give the copy a new btrfs UUID.

When the clone inherited `default`, the kernel made its device in `geom` mode. The change to `dev` makes ZFS remove the device and make a new one in the background. Incus can find the old `/dev/zdNN` and run `btrfs rescue zero-log` or `btrfstune` on it while it goes away. The copy then fails with `cannot open /dev/zdNN`, or reads another volume's device. Copies at the same time, and any other zvol device change on the Node, make this likely: on Beast on 15 November 2026, 30 of 75 copies failed three at a time.

So the host branch sets `volmode=dev` on the `custom` dataset. The clone inherits `dev`, its device is made once, and Incus changes nothing. Volumes that Incus creates or clones by other paths set their own `volmode`. A zvol that Incus receives, imports or copies in full into this subtree now inherits `dev` too: its device appears in dev mode until its first unmount, and Orbit's inner volumes use none of those paths.

After the volume copy, the clone sets the volume's `size` to the intent's size, `container.inner_volume.size`, before the container copy. A volume copied from a snapshot takes the snapshot's Incus config, and a snapshot that a [seed copy](#how-the-seed-moves) moved to a CI Node records `size: 0`.

Incus 7.0.2 writes the `size` of each snapshot that it sends from the size of the snapshot's block device, and it writes 0 for a volume with a file system. Incus cannot change a snapshot's config, so the seed copy cannot correct it. The ZFS volume keeps the full size, so the set changes only the record. The [start guard](#the-container) still requires that exact size.

On this volume a [reset](#reset) of a copy's fleet, which a proof runs before each batch, takes about 16 seconds.

Every Instance without `inner_volume`, the first nested-fleet default before its conversion and its copies included, keeps today's shape: no step makes, snapshots, copies or checks a volume for it.

`NestedFleetLayout` in `apps/gateway/app/Domain/Instances/NestedFleetLayout.php` holds the fleet's addresses, MACs, default memory limits, subnets and checkout path. This page names none of them twice.

In the outer container, `/etc/subuid` and `/etc/subgid` give root `1000000:100000000`, plus `1000:1` for the [workspace mapping](#fleet-rules). The inner Incus does not read them, because the image has no `newuidmap`. It takes its range from its own map instead: base 1,000,000, size the outer size minus 1,000,000 ([The ID map size](#the-id-map-size)).

### The ID map size

Each nested-fleet runtime records the size of its isolated ID map in `idmap_size`. The create and the clone write it as `security.idmap.size`, and the start guard requires that value.

| Runtime | Size |
| --- | --- |
| A new nested-fleet default, or a migrated nested Instance | 101,000,000 |
| A copy, a plain clone or a task copy | Its default's size at the copy's reservation |
| A converted default | 1,165,536, which gives the inner fleet 165,536 IDs |
| A default raised for an [Instance host in the fleet](#instance-host-in-the-fleet) | 2,000,000, which gives the inner fleet 1,000,000 IDs |
| A normal container | None: Incus's 65,536 |

The inner range is the outer size minus 1,000,000, at base 1,000,000. The nested image has no `newuidmap`, so the inner Incus ignores `/etc/subuid` and takes this range from its own process map. The floor is 1,065,536. Below it the inner base moves to 65,536 and every inner file is remapped; below 131,072 the inner Incus gets no map at all and runs only privileged containers.

The inner fleet's three containers share that range. They use IDs up to 65,534 (`nobody`), and Docker in them runs without user namespaces, so 65,536 IDs are enough; a converted default gives them 165,536. Every nested container mounts its root disk idmapped (`volatile.last_state.idmap` is `[]`), so a changed outer size moves no file on the host.

A copy keeps its size for its whole life. So copies made before a conversion keep 101,000,000 and still match their start guard, beside new copies of 1,165,536 or 2,000,000. The [task start check](#the-check-at-a-task-start) and the converge's [subordinate-ID demand](/reference/node-settings#nested-fleet-instance-hosting) read the recorded sizes.

#### Convert a default

A nested-fleet default made before the inner volume existed, Instance 2454, has the full size and keeps its inner fleet on a directory pool in its root disk. One announced window converts it to the small size and moves the fleet onto its own [inner volume](#the-inner-volume). It runs once, outside any task turn. A full rehearsal on a copy of 2454 on 2026-11-14 timed each step; the seconds below are from it.

Before the window:

- The release that adds `btrfs-progs` to [the pinned shape tools](#the-pinned-shape-tools) is live, and the default's last deployment result has `tools` not `failed`. Without it the inner Incus cannot make the pool (`Required tool "btrfs" is missing`).
- No copy is being created and no task turn runs on the Project. Automatic release is paused.
- No copy of the default has an unfinished create. Destroy such a copy first: a retry after the window would copy the default's own disk, and its guard would refuse it.
- The Node has two free ranges of the full size: one for the snapshot, one for a rollback start. The pool has room for the 30 GiB volume.

Who runs each step: **B** is a hand step on the Node, **G** a command on the Gateway host, **P** a product Operation.

| Step | Who | Action | Time on the copy |
| --- | --- | --- | --- |
| 1 | B | Stop the inner fleet (`incus stop nested-app-prod nested-app-dev nested-gateway` inside the container), then the container | about 20 s |
| 2 | B | `incus snapshot create instance-<id> pre-window`. It needs a free full-size range for a moment; without one, stop here, start the container and deploy it | 0.2 s |
| 3 | B | Set the small size; create `inner-<id>` with the keys of [The inner volume](#the-inner-volume); attach it as the disk `inner-pool` | about 1.5 s |
| 4 | G | `php artisan gateway:instance-idmap-size <id> 1165536` and `php artisan gateway:instance-inner-volume <id> inner-<id>` | about 1 to 2 min with the hand-off |
| 5 | B | Start the container by hand. The inner containers autostart on the old pool, and the inner Incus remaps their files to the new range | 32.8 s for about 250,000 files; allow 1.5 min under load |
| 6 | B | Inside the container: `incus admin waitready`; stop the three; `incus storage create inner btrfs source=/srv/inner-pool/pool`; `incus move <container> --storage inner` for each | moves 82.6, 67.5 and 72.3 s |
| 7 | B | Start the three once and stop them; retake each `base`; check that each root disk has `pool=inner` and that nothing uses `topology-seed`; stop the container | starts under 1 s, `base` 1.2 to 3.5 s each |
| 8 | P | `orbit instance:deploy <id>`: the guard, the Club key, the fleet, Doctor, `base`, the shape tools and the [seed pair](#the-seed-pair) with the flush | about 4.5 min |
| 9 | P | `orbit instance:create <project> <node> window-check --wait-active` must reach `active` with its own `inner-<clone id>`, size 1,165,536 and a healthy fleet; then destroy it. The window is done only then | about 1 min |
| 10 | B | Later: delete the directory pool `topology-seed` after seed rotation | |

Notes on the steps:

Every hand command on the Node takes `--project orbit-instances-nested`. Step 3 in full is three commands:

```
incus config set instance-<id> security.idmap.size=1165536
incus storage volume create orbit-instances inner-<id> zfs.block_mode=true block.filesystem=btrfs block.mount_options=user_subvol_rm_allowed,discard security.shifted=true size=30GiB
incus config device add instance-<id> inner-pool disk pool=orbit-instances source=inner-<id> path=/srv/inner-pool
```

The device takes no other key, because the guard compares it exactly. The size change moves no file on the host.

More notes on the steps:

- Each command of step 4 clears the seed, so new clones refuse with `instance.default_missing` from here. The second prints the device and volume keys that the start guard will require. Compare them with the Node before step 8.
- The inner containers have `boot.autostart=true`, so the remap runs at the outer start of step 5, before the move.
- The fleet that runs after step 5 is whole: the old pool with the new map. The moves copy files that are already remapped, and the starts of step 7 walk no file.
- Step 7 stops the container, so that the product start of step 8 runs the guard and delivers the Club key.
- From the seed of step 8 on, clones are admitted again.

On the copy the fleet was down about 5 minutes; on 2454, with its load and the two Gateway commands and the deployment, expect about 10 to 13 minutes. The container's own workspace and T3 are down only for the two outer restarts, steps 1 to 5 and the deployment's restart. Copies made before the window keep their full size and their directory pool and still start: their runtime records their own shape.

The start guard names each half-converted shape:

| The Node has | The records have | The guard refuses |
| --- | --- | --- |
| The disk `inner-pool` | No inner volume | `inner-pool` |
| No disk `inner-pool` | The inner volume | `inner-pool` |
| The full size | The small size | `config security.idmap.size` |
| A volume key other than above | The inner volume | `volume inner-pool <key>` |

Each refusal fails the start with `instance.container_network_unsafe` and leaves the container stopped. A deployment that fails before its seed cannot restore: the default's only seed has no volume half, so the restore refuses with `instance.fleet_seed_missing` and changes nothing. Then run the rollback.

**Rollback** (rehearsed: 12.6 s by hand and a 27 s product start):

1. Destroy every copy made after step 8.
2. **B** Stop the fleet and the container. Delete any seed newer than `pre-window`: ZFS restores only the newest snapshot, and only one without clones.
3. **B** `incus snapshot restore instance-<id> pre-window`. It brings back the full size, the directory pool and the old inner map, with no walk back, and drops the disk.
4. **B** `incus storage volume delete orbit-instances inner-<id> --project orbit-instances-nested`.
5. **G** `php artisan gateway:instance-idmap-size <id> 101000000` and `php artisan gateway:instance-inner-volume <id> --clear`.
6. **P** `orbit instance:deploy <id>`: the start, the Club key and a new seed.

The rollback needs any free full-size range, not the old one. It loses what the container wrote after step 2.

`gateway:instance-idmap-size` refuses a size other than the two above with `instance.idmap_size_invalid`, and its message names the floor of 1,065,536. It refuses an Instance that is not a nested-fleet default with `instance.idmap_size_not_nested_default`. It refuses a default that an open Operation holds with `operation.target_busy`. It refuses a size below a copy's recorded size with `instance.idmap_size_copies_smaller`, so a rollback waits until the small copies are gone.

`gateway:instance-inner-volume` takes only the name the product reserves for that default, `inner-<id>`. Any other name is `instance.inner_volume_name_invalid`, because a wrong record would point every clone at a volume that is not its own.

It also refuses a container outside the nested project with `instance.inner_volume_not_nested`, a copy with `instance.inner_volume_not_default`, a Project without `nested_fleet` with `instance.inner_volume_nested_flag_off`, an open Operation on the default with `operation.target_busy` and an open create of a copy with `instance.clone_in_flight`. `--clear` refuses while a copy with an inner volume exists (`instance.inner_volume_copies_with_volume`). A run that would change nothing, the same name again or `--clear` without a record, refuses with `instance.inner_volume_unchanged` and keeps the seed.

The command reads nothing on the Node: the start guard reads the volume and the device itself, so a second check would be a second copy of its rule. It prints the record before and after, and the guard's keys, as one JSON line.

Both commands hold the default's row lock, as a task copy's admission does, and clear the seed.

#### The seed pair

The seed of such a default is a pair: the container snapshot `seed-<commit>` and the volume snapshot of the same name. A container snapshot does not hold an attached custom volume, so the name ties the two. The [seed step](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/deploy-default.mmd) takes the volume's half right after the container's, with the Processes still stopped, wherever it takes the seed. It retakes the pair when the volume's half is missing.

Right before the volume's half, the step flushes the volume: `sync -f` on `/srv/inner-pool`, as root in the container. A snapshot of the volume is a snapshot of its block device, so it holds only what btrfs has committed, and btrfs commits every 30 s. Without the flush, a copy lacked an inner pool made 23 s before the seed.

A failed flush takes no volume half and fails with `instance.seed_volume_flush_failed`; the old pair stays. There is no freeze: the two halves are separate snapshots anyway, so a freeze adds nothing over the flush and btrfs's crash consistency; each half is a point in time as after a power cut.

A [retake](#a-retake-keeps-the-seed) takes both new halves under `next-<commit>` when the seed's name is taken. A failed volume snapshot deletes both new halves and fails with `instance.seed_volume_snapshot_failed`, so no reader finds half a pair. The old pair stays whole, also at the same commit. Older pairs go only after both new halves exist. A failed refresh checks that both halves exist before it restores anything; a failed restore of the volume after the container's fails the run.

A deployment retakes the two halves one after the other. So a clone of such a default is not admitted while a deployment of the default is open (`instance.default_seed_in_flight`), as the refresh waits for an open clone (`instance.clone_in_flight`). A default without an inner volume has no such wait.

#### Copies of the volume

A copy of such a default, a plain clone or a task copy, gets its own volume `inner-<copy id>`, which its runtime records. The [clone step](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/create.mmd) checks that the default's volume holds the seed's half, else `instance.default_seed_incomplete`. It copies that snapshot to the new volume. Then it copies the container with its disk `inner-pool` set to the new volume, before the first start. Both copies are ZFS clones. Incus gives the copied volume a new btrfs fsid, so copies never share one. In the 2026-11-14 test the two copies took 3.8 to 4.2 s and the outer start about 1 s.

A proof copy on a CI Node copies the same way from that Node's [seed holder](#the-seed-on-each-ci-node), `proof-seed` and `proof-seed-inner`.

The start guard admits the copy's volume with exactly the keys above. A failed container copy deletes the volume it copied. A volume without its container, left by a failed copy, is deleted and copied again. Destroy of the copy deletes its volume. A copy shares blocks with the default's seed until it goes, as a container copy does.

### Instance host in the fleet

`nested-app-dev` runs real Instance containers, a third level below the host: Node, then the Instance container, then `nested-app-dev`, then an Instance. The nested Gateway leads it as an `app-dev` Node, so `instance:create`, sleep, wake and destroy of a Project clone run through the nested Gateway and its Swamp. Proof cases that need an Instance host in the proof fleet use it ([ADR 0262](/decisions/0262-prove-changed-operations-on-the-pull-request)).

What the host needs, measured by hand on a task copy at 1,165,536 on 2026-11-16:

| Need | Finding |
| --- | --- |
| Nesting keys | `security.nesting=true` and the interception of `mknod` and `setxattr`; user namespaces stay on. Set while the container is stopped |
| Incus | The recipe's pinned 7.0.2 packages install in 32 s; the server runs with the `lxc` driver, no VMs |
| AppArmor | The third-level Incus cannot load a profile (`apparmor_parser: Access denied`), so the bridge's dnsmasq fails. The drop-in `INCUS_SECURITY_APPARMOR=false` for `incus.service` turns its AppArmor off; the Incus one level up still confines `nested-app-dev` |
| ID map | Base 65,536 with 100,000 IDs. See below the table |
| Isolated maps | The first isolated map starts at the base plus 65,536: 131,072. So at 1,165,536 no isolated 65,536 map fits (`Not enough uid/gid available`). One of 34,464 IDs started at 131,072 |
| Pool | btrfs on `nested-app-dev`'s btrfs root: a copy is a subvolume snapshot. See below the table |
| Network | The Node's input rules drop DHCP on `orbitinst0` until the role converge adds `orbit:instances-*` |
| Egress | One isolated container launched in 10 s and ran `apt-get update`, a `git` install and an HTTPS request through the egress proxy in 11 s |
| Shared folders | A read-only disk with `shift=true` fails the start; `shift=false` starts. See below the table |

The btrfs pool was measured on a task copy on 2026-11-18. The root of `nested-app-dev` is mounted with `user_subvol_rm_allowed`, so its unprivileged Incus creates, snapshots and deletes subvolumes. A copy of a 644 MB rootfs took 8.5 to 8.8 s on the former directory pool and 0.65 to 1.0 s on btrfs. Retaking `base` of `nested-app-dev` took 7.1 s with its stop and start, and `base` holds the pool's subvolumes.

The shared folders were measured by hand with Incus 6.0.5 on 2026-11-16. A read-only folder disk with `shift=true` failed the start: `idmapping abilities are required but aren't supported on system`. With `shift=false` an isolated container of 34,464 IDs started at once. Its uid 2750 read a file of mode 644 but not a `root:2750` directory of mode 0750. It read the directory once it was open to others.

`nested-app-dev`'s own map is 0 to 165,535, with the three `raw.idmap` holes below 2,751. Its `/etc/subuid` names root's range at 1,000,000, outside that map, so Incus ignores the file and takes the rest of the map above 65,536. The holes lie below that base and change nothing.

So the host needs the 2,000,000 size of [The ID map size](#the-id-map-size). The inner Incus then hands `nested-app-dev` 1,000,000 IDs, its own Incus keeps base 65,536 with 934,464 IDs, and `(1,000,000 − 131,072) / 65,536` gives 13 isolated Instances. 2,165,536 would be wrong: the outer range crosses the floor again.

The [fleet rules](#fleet-rules) give `nested-app-dev` the nesting keys. A nested Gateway with the [fleet marker](#fleet-rules) recognizes its fleet's app-dev Node by `nested-app-dev`'s address. It sends that Node's `app-dev` intent `instances.fleet_node`, so the [container branch](/reference/compute-drivers#prepare-an-instance-container-host) runs on it in this order:

1. It writes the drop-in `/etc/systemd/system/incus.service.d/50-orbit-nested.conf` with `Environment=INCUS_SECURITY_APPARMOR=false`. The Incus there cannot load AppArmor profiles; the Incus one level up still confines `nested-app-dev`.
2. It installs Incus from the recipe's pinned packages.
3. It installs `btrfs-progs` and creates the btrfs pool `orbit-instances` on the Node's btrfs root.
4. It writes the marker `/etc/orbit/instance-fleet-node`.
5. It runs the rest of the branch as on any `app-dev` Node: project, bridge, folders, egress proxy and the Instance image.

A fleet Node pool is always btrfs; no Incus layer keeps a `dir` pool. The converge (`files/fleet-node-pool.py`) moves the directory pool of a Node made before the btrfs pool to btrfs, with no hand step. It stops each instance on the pool and records the state it had, moves every instance and custom volume to the btrfs pool `<pool>-move`, and points the profiles there.

It then deletes the directory pool, creates it again as btrfs, moves everything back and returns each instance to its state.

Every disk at `/` of an instance or of a profile on the pool loses its `size` before it reaches btrfs. The btrfs pool `<pool>-move` and each stopped instance record the pool they belong to in `user.orbit.pool-move`. A run touches only those, so a converge stopped part way finishes on the next run.

Before it stops anything, the move refuses with `instance.host_unavailable` and names the resource when the pool holds Incus backups, an ephemeral instance, or a volume that something off the pool or a profile uses. A create is killed after 120 s, and the task runs the script once more. Any other driver refuses with `instance.host_unavailable` before anything changes.

The nested proof Node `nested-app-prod` runs the same first part for its release pool `orbit-releases`, which holds its release containers. So that pool is btrfs too, and its release root devices carry no `size`. A production Node keeps `orbit-releases` on ZFS ([Production containers](/reference/production-containers)).

Fleet start appends the managed user `orbit` to `incus-admin` on every nested Node marked as an Instance host by `/etc/orbit/instance-fleet-node`. The Gateway's direct workspace and runtime reads call Incus as that user, without sudo. The account already has passwordless sudo, so this adds no authority. The fleet builds its own Nodes; their account setup belongs to fleet start. Every copy start repairs missing membership, including a copy of an existing seed, with no converge or seed refresh. Other groups remain unchanged, and a current membership reports no change.

Its egress proxy goes out through the fleet's proxy (see below).

Three rules let the operator reach a site on it, also after a restart:

- **Its tunnel.** `nested-app-dev`'s WireGuard handshakes to the hub are packets of a kernel socket. The proxy's table lets them pass, as on every Node ([The proxy's table](#the-proxys-table)).
- **Through the hub.** The operator's tunnel `wg-nested` ends at the nested Gateway, the fleet's hub. Its rule `orbit:vpn-peer-forwarding` forwards traffic between two members ([firewall](/cli/firewall#vpn-peer-forwarding)). So the operator reaches `10.45.0.2` over TCP.
- **By name.** The [fleet start](#the-fleet-step) gives `wg-nested` the hub's resolver for `nested-dev` and `nested-prod`.

The [shared folders](#shared-folders) are the one other difference. An idmapped (`shift=true`) disk fails a third level below the host: the first window at 2,000,000 stopped at the start of the image's build container, and the journal showed the shifted mount of `orbit-agent` torn down at once.

So the folder plans in the fleet Node's intent carry `shift: false`, and from them the profile `orbit-instances` mounts `orbit-runtime` and `orbit-agent` with `shift=false`. The start guard and the native clone of a nested-fleet container take the same two devices from the container intent's `allowed_devices`, so each admits only its Node's form: `shift=false` on the fleet Node and `shift=true` on every other Node. `InstanceFolderPlan::shift` is the one rule; it follows `ContainerNodes::fleetNode`. The Instance image does not change.

A real `app-dev` or CI Node gets no `fleet_node` and no folder `shift`, so its intent, its profile and its containers' devices stay as they were.

The fleet's egress proxy lies in the Instance container's own bridge, in `10.253.0.0/16` one level up. The nested Gateway reads its address from `HTTPS_PROXY` in its `/etc/environment`, which the fleet rules write whole. It gives the fleet Node no Instance bridge in that /24: a bridge that holds the proxy address makes `nested-app-dev` dial itself, and its Incus cannot fetch the stock image.

A nested Gateway in a [release container](/reference/gateway-recovery#move-the-gateway-to-release-containers) reads the host's copy of that file and of the marker from its read-only environment mount, `/opt/orbit-gateway-environment`. The Gateway host refreshes both with the Gateway environment: at preparation, at each release and at `orbit-gateway-environment refresh` or `configure`. A host change between those reaches the container at the next refresh. The same refresh publishes the proxy lines alone as `instance-fleet-proxy.env`, which the container's Gateway units load when they start, in place of the host pool's `zz-orbit-nested-egress.conf`. An outer Gateway's host has no marker, so it publishes none of them.

`nested-app-dev` reaches the internet only through that proxy: it has no route out and no public DNS. So the proxy of its own containers ([Egress and DNS](#egress-and-dns)) takes the fleet's proxy as its only parent. `InstanceFleetGateway::egressProxy` is the one reader of `HTTPS_PROXY`; it gives both the address that the bridge leaves out and this parent. The fleet Node's intent carries the parent in `instances.egress.parent`, and its Squid configuration and table follow from it:

- **Parent.** `cache_peer <address> parent <port> 0 no-query no-digest default` and `never_direct allow all`. Plain requests and `CONNECT` both go to the parent. Squid connects to no other server.
- **Table.** The proxy's table returns new connections of `proxy` to exactly the parent's address and TCP port, before it rejects private addresses. Every other private address stays refused.
- **No lookup.** The fleet Node's Squid has no `to_localhost` rule, because that rule looks up the destination name.
- **Why.** `nested-app-dev` cannot resolve a public name. Squid connects only to the parent, which applies its own rules.
- **Private names.** The proxy still refuses a name in the fleet's private routing domains first, before it forwards anything.
- **Refusal.** A nested Gateway that reads no proxy from `HTTPS_PROXY` fails the fleet Node's intent with `instance.fleet_egress_unavailable` (409).
- **Before any change.** The converge then changes nothing, so Squid never goes direct there by mistake.

A real Node gets no parent, so its Squid configuration, its table and its intent stay as they were.

Measured by hand on a task copy on 2026-11-17, with Squid 7.7 on `nested-app-dev`, its product configuration and its table: without a parent, a `GET` of `http://archive.ubuntu.com/ubuntu/dists/resolute/InRelease` and a `CONNECT` to `example.com:443` each got 503 at once. With the parent, both succeeded.

**Where it runs.** The [refresh](#what-a-refresh-skips) of the default's fleet has a separate `host` digest. It covers three inputs:

- the code that `nested-app-dev`'s converge can load, as the [phase resolver](/reference/node-provisioning#skip-a-phase-whose-input-did-not-change) names it (see below);
- the machine-software recipe under `apps/gateway/resources/machine-software`;
- `nested-app-dev`'s host intent as the nested Gateway renders it (`orbit:node-converge-digest --host app-dev`; the image definition, folders and Incus, without the admission time).

The code holds every `swamp/ansible` file that a `node.converge` tag reaches, and the implicit and unreached files. The tags are the five skippable phases and the `input` and `check` tags that every converge runs. So the role check that `check` runs for `app-dev` counts. It also holds the files the phases name beside the bundle, the staged role siblings, the Tool helper and the Tool folder.

A file that only another Operation's playbook reaches is not in the code. Examples are the outer Instance-container plays, the Swamp role and the Conn launch controller. A change to only those files keeps the host, so the refresh runs no converge and does not retake `base`. The refresh reads the code in the outer container: the runtime's Deno runs the checkout's own `host_code.ts` over the files of its `HEAD` commit. The read takes about a second.

Its first run in a container fetches the resolver's YAML module through the egress proxy; a run that cannot fetch it makes the host due. Measured in a task copy on 2026-11-19: a commit that changed only `instance-container-power.yml` gave a 12 s refresh with the host kept, no converge and no `base` retake. A commit that changed `tasks/instance-host.yml` gave `host_due`, ran only the roles phase and took 83 s.

A missing host record, a changed digest or a failed read of a host input makes the host due. A phase that the resolver cannot bound brings the whole bundle into the code. A failed read leaves no host digest in the record. When the host is due, `nested-app-dev` joins the due converges with its `app-dev` role, not just the base scope. Its converge still skips each phase whose own digest did not change. The default's seed then holds a ready Instance host, and every copy inherits it with no work of its own.

The app-dev role creates its wake marker directory in `/dev/shm`. Its systemd-tmpfiles rule recreates the directory at boot with owner `root:caddy` and mode `0755`, including after a base retake restarts the nested Node.

When only the host part is due, the base digest of `nested-app-dev` did not change, and its converge reason is `host_due`.

A refresh with no change keeps the host and adds no time. The host is built only when the default's recorded ID map size is 2,000,000 or more: the fleet intent's `host` says so. Below that no isolated Instance fits on `nested-app-dev`, so the refresh changes nothing there. The result names `host` as `converged`, `kept` or `skipped_idmap_small`; the Operation's `fleet_refresh` keeps it, and leaves it out when the run names none ([What a refresh skips](#what-a-refresh-skips)).

**Time bounds.** The first host build installs Incus, downloads the shared folders, and builds the stock image and the Instance image. On a task copy at 1,165,536 the converge took 325 s for the base packages and 428 s for the roles phase up to the stock image (2026-11-16). The images could not be built there. So the roles phase of a fleet Node's `app-dev` converge has 1,800 s, from `roles_seconds` in its intent.

The converge workflow accepts only 900 to 1,800 for `roles_seconds` and refuses any other value before the playbook. The refresh waits up to 1,800 s for that converge while the record holds no `host` yet. That converge runs last, after the other Nodes' converges have ended: the hub's reservation refuses a converge with roles beside any other converge. Every other converge keeps 900 s for each phase and for the refresh's wait.

**Memory.** In an Instance fleet the nested containers get 4 GiB (`nested-gateway`), 5 GiB (`nested-app-dev`) and 2 GiB (`nested-app-prod`), 11 GiB of the Instance container's 12 GiB. Each gets the same size as swap: Incus writes `memory.swap.max` 0 for a container with `limits.memory` unless `limits.memory.swap` holds a byte size, and an Instance on `nested-app-dev` swaps only as far as each ancestor cgroup allows. These are owned fleet rules (`limits.memory` and `limits.memory.swap`), so the next refresh writes them with the fleet stopped, before it takes `base`; copies made from the new seed have them. On 2454, `nested-app-prod` used 124 MiB and `nested-gateway` 1.64 GiB. `nested-app-dev` with its Incus and no Instance used 178 MiB.

**The fast wake stays off.** The [power helper](/reference/prepared-restore#the-power-helper) reads `/etc/orbit/instance-fleet-node` and records no plan, with `placement_unproven`, for every Instance on that Node, as for a nested-fleet Instance ([ADR 0261](/decisions/0261-restore-a-sleeping-containers-memory-before-it-resumes)). Sleep and wake use the normal path.

#### Raise a default to 2,000,000

The window raises the converted default 2454 from 1,165,536 to 2,000,000 once, after the release. Deployments before the window are safe: at 1,165,536 the refresh skips the host (`host: skipped_idmap_small`). Without that skip, the stock image build on `nested-app-dev` would fail with `Not enough uid/gid available for the container`. The window's deployment (step 8) is the first that builds the host.

Before the window:

- The release is live, automatic release is paused, and no task turn runs on the Project.
- Every copy of the default is destroyed. A copy keeps its size for life, so a copy at 1,165,536 blocks the raise (`instance.idmap_size_copies_smaller`).
- The Node has a free range of 2,000,000 subordinate IDs.

**B** is a hand step on the Node, **G** a command on the Gateway host, **P** a product Operation. Every **B** command on the Node takes `--project orbit-instances-nested`. The times are from the conversion rehearsal of 2026-11-14 and the task copy of 2026-11-16.

| Step | Who | Action | Time |
| --- | --- | --- | --- |
| 1 | B | `incus exec instance-<id> --project orbit-instances-nested -- incus stop nested-app-prod nested-app-dev nested-gateway`, then `incus stop instance-<id> --project orbit-instances-nested` | about 20 s |
| 2 | B | `incus snapshot create instance-<id> pre-2m --project orbit-instances-nested` and `incus storage volume snapshot create orbit-instances inner-<id> pre-2m --project orbit-instances-nested`: the container snapshot does not hold the inner volume | 0.2 s each |
| 3 | B | `incus config set instance-<id> security.idmap.size=2000000 --project orbit-instances-nested` | under 1 s |
| 4 | G | `php artisan gateway:instance-idmap-size <id> 2000000`. It clears the seed, so clones refuse until step 8 | about 1 min |
| 5 | B | `incus start instance-<id> --project orbit-instances-nested`. Incus remaps the container's files to the new range | 35 s; allow 1.5 min |
| 6 | B | As step 1: stop the inner fleet, then the container | about 20 s |
| 7 | B | `incus list instance-<id> --project orbit-instances-nested -c s` shows `STOPPED` | 1 s |
| 8 | P | `orbit instance:deploy <id>`: the start with the guard, the Club key and `wg-nested`, the fleet rules, the first Instance host on `nested-app-dev`, `base` and the seed pair | about 4.5 min, plus 15 to 25 min for the host |
| 9 | P | `orbit instance:create <project> <node> window-check --wait-active` reaches `active` at 2,000,000; then `orbit instance:destroy <copy> --yes` | about 1 min plus the copy |

Steps 5 to 7 follow [Convert a default](#convert-a-default): the hand start does the remap outside the product start. A hand start gives the container no Club key and no `wg-nested`, so step 6 stops it again and step 8's product start delivers both. The inner containers' root disks are idmapped and the inner base stays at 1,000,000, so no inner file moves.

Step 8 builds the first Instance host. On the task copy, 12.5 min were measured up to the images, and the images could not be built there. Allow 15 to 25 min; the bound is 30 min ([time bounds](#instance-host-in-the-fleet)).

#### Roll back to 1,165,536

The command guards only the raise. It refuses with `instance.idmap_size_copies_smaller` while a copy has a smaller size than the new one, and nothing guards the rollback. So the rollback starts with an operator step: destroy every copy made at 2,000,000 (`orbit instance:destroy <copy> --yes`), because on `nested-app-dev` at 1,165,536 no Instance fits. Two ways follow.

**A. Set the size back (preferred).** It keeps the default's workspace and everything since the window:

| Step | Who | Action | Time |
| --- | --- | --- | --- |
| 1 | B | Stop the inner fleet, then the container, as in the raise | about 20 s |
| 2 | B | `incus config set instance-<id> security.idmap.size=1165536 --project orbit-instances-nested` | under 1 s |
| 3 | G | `php artisan gateway:instance-idmap-size <id> 1165536` | about 1 min |
| 4 | B | `incus start instance-<id> --project orbit-instances-nested`: Incus walks the container's files back to the smaller range | 35 s; allow 1.5 min |
| 5 | B | Stop the inner fleet, then the container, and check `STOPPED` | about 20 s |
| 6 | P | `orbit instance:deploy <id>`: the refresh reports `host: skipped_idmap_small` | about 4.5 min |

The seed keeps the Instance host built at 2,000,000. It is harmless: no isolated Instance fits on `nested-app-dev` any more.

**B. Restore `pre-2m`.** Use it only when the container itself is broken after the raise. It **throws away the default's workspace and every change since the window**: commits, files, deployments, and the inner fleet's state. It needs no new snapshot: both halves of `pre-2m` exist from step 2 of the raise. ZFS restores only the newest snapshot, and only one without clones, as in the rollback of [Convert a default](#convert-a-default):

1. **B** Stop the inner fleet, then the container, as in the raise.
2. **B** List the snapshots: `incus snapshot list instance-<id> --project orbit-instances-nested -f csv` and `incus storage volume snapshot list orbit-instances inner-<id> --project orbit-instances-nested -f csv`.
3. **B** Delete every seed newer than `pre-2m`, both halves: `incus snapshot delete instance-<id> <seed> --project orbit-instances-nested` and `incus storage volume snapshot delete orbit-instances inner-<id> <seed> --project orbit-instances-nested`. A seed with clones blocks this, so destroy those copies first (`orbit instance:destroy <copy> --yes`).
4. **B** `incus snapshot restore instance-<id> pre-2m --project orbit-instances-nested` and `incus storage volume snapshot restore orbit-instances inner-<id> pre-2m --project orbit-instances-nested`. They bring back 1,165,536 with no walk.
5. **G** `php artisan gateway:instance-idmap-size <id> 1165536`.
6. **P** `orbit instance:deploy <id>`: the product start delivers the Club key, and the deployment takes a new seed.

### Fleet rules

Each rule below is a device or config key of a nested container, a file it reads, or a key of the inner bridge `oe-nested`. The container rules are in `base`, because a restore of `base` reverts device and config keys. The bridge is in the inner Incus, not in a container, so a restore of `base` keeps its keys.

A file rule is owned: the refresh writes the whole file where it differs, root-owned with mode 0644. A check or a start only compares: it reads the device and config keys and the bridge, and no file, because a file that differs never refuses. The rules are the shape of the first inner fleet, which was built by hand and runs.

| Rule | Where | Why |
| --- | --- | --- |
| The workspace `/srv/workspace` is the disk device `checkout`, with `shift=false` | `nested-gateway`, `nested-app-dev` | An edit reaches them at once. A shifted mount fails two levels below the host, as the [shared folders](#instance-host-in-the-fleet) of `nested-app-dev`'s Instances do |
| `raw.idmap` `both 1000 1000`, `uid 2750 2750` and `gid 2760 2760` in the inner Incus | All three nested containers | The workspace is `agent:work` (2750:2760) with files of `orbit` (1000). With only the `orbit` line, nested root cannot resolve the state mounts. The outer container still has no `raw.*` key |
| The Club key folder device `club-key`: `/run/orbit-club` at `/home/orbit/.config/swamp/club`, `shift=false`, and the link `/home/orbit/.config/swamp/api-key` to `club/swamp-club-api-key` | `nested-gateway` | The nested Swamp reads the [Club key](#the-club-key-of-the-nested-gateway) that Orbit delivers to the outer container's `/run` |
| A read-only disk device that shows `N` at `/sys/module/apparmor/parameters/enabled`, from `/etc/orbit/apparmor-off` | `nested-app-dev`, `nested-app-prod` | `docker run` fails without it: Docker's own profile cannot load two levels below the host |
| `HTTPS_PROXY`, `HTTP_PROXY` and `NO_PROXY` in the drop-in `50-inner-fleet-proxy.conf` for `orbit-swamp-serve.service`, `orbit-swamp-worker@.service` and `orbit-gateway-horizon.service`, one `Environment=` line | `nested-gateway` | Swamp verifies a new Club key at Swamp Club, Horizon's jobs read Git, and the fleet reaches the internet only through the Node's [egress proxy](#egress-and-dns) |
| The same six proxy settings as `env[...]` lines of the pool `[orbit-gateway]` in `/etc/php/8.5/fpm/pool.d/zz-orbit-nested-egress.conf` | `nested-gateway` | The pool clears its environment, so without them `project:create` cannot reach GitHub (`project.default_branch_unavailable`). php-fpm merges a repeated pool section, and the Gateway role writes only `orbit-gateway.conf`, so a role converge keeps this file |
| The same proxy settings as `environment.*` keys in the inner Incus. `NO_PROXY` is `localhost,127.0.0.1,::1,.orbit,10.45.0.1,10.45.0.0/24,10.232.2.0/24` | All three | A nested Node downloads the agent and packages; fleet names stay off the proxy. PHP's HTTP clients may not match `10.45.0.1` to its range |
| `security.nesting=true`, `security.syscalls.intercept.mknod=true` and `security.syscalls.intercept.setxattr=true` | `nested-app-dev` | Its own Incus runs the [Instance containers of the fleet](#instance-host-in-the-fleet). The fleet must be stopped to set them |
| `limits.memory`: 4 GiB, 5 GiB and 2 GiB | `nested-gateway`, `nested-app-dev`, `nested-app-prod` | `nested-app-dev` hosts the fleet's Instances; see [Instance host in the fleet](#instance-host-in-the-fleet) |
| `limits.memory.swap`: the same size as `limits.memory` | All three | Incus writes `memory.swap.max` 0 unless this key holds a byte size. An Instance on `nested-app-dev` [sleeps into swap](#sleep-and-wake) only when every ancestor cgroup allows it |
| The egress files: the egress proxy for apt in `/etc/apt/apt.conf.d/95orbit-nested-egress`, the same proxy settings, `NO_PROXY` included, in `/etc/environment` after the image's `PATH` line, and the same settings for Docker in `/etc/systemd/system/docker.service.d/50-inner-fleet-proxy.conf` | All three | A Node's base converge runs apt and downloads the agent over SSH with sudo. See below the table |
| The marker `/etc/orbit/instance-fleet` | `nested-gateway` | The nested Gateway names no task VM package cache for its Nodes. See below the table |
| `/etc/gitconfig` with `safe.directory` for the checkout, `/home/orbit/orbit` | All three | `agent` owns the checkout, so Git as the guest's `orbit` stops with "dubious ownership" without it. The outer container's `/etc/gitconfig` does the same for `/srv/workspace` |
| The size files: `APT::Keep-Downloaded-Packages "false";` in `/etc/apt/apt.conf.d/91orbit-nested-package-files`, and `SystemMaxUse=64M` in `/etc/systemd/journald.conf.d/zz-orbit-nested-journal.conf` | All three | Every nested root disk is in every copy. See below the table |
| `security.nesting=true` and a memory limit | All three | Docker runs in them |
| `boot.autostart=true` | All three | They start with the outer container, also after a restore of its seed. With `false`, the start still starts them, and the refresh writes `true` |
| `raw.dnsmasq` `no-resolv` on the inner bridge `oe-nested` | The inner Incus | A name the fleet does not know is refused at once, not after a silent wait of about 15 s. See below the table |

The bridge's dnsmasq answers the fleet's own names. Without `no-resolv` it forwards every other name to the outer container's resolver. That resolver has no DNS server and refuses at once, but dnsmasq drops the refusal and sends no answer. Each lookup then waits for its timeouts: about 15 s in the start of php-fpm, `orbit-websocket` and the Swamp workers. With no upstream, dnsmasq refuses at once. `gateway.orbit` resolves through the nested Node's `orbit` link, which this key does not change.

The cost: while `raw.dnsmasq` is set, the inner Incus runs the inner bridge's dnsmasq without its Incus AppArmor profile. It still runs as the user `incus`, inside the unprivileged outer container.

Incus restarts the bridge's dnsmasq when the key changes, so only the refresh writes it, with the fleet stopped. A start only compares. A copy whose seed lacks the key starts as before. A bridge that cannot be read is refused with `instance.container_fleet_drift`.

The `environment.*` keys reach only `incus exec`. SSH and sudo sessions read `/etc/environment` instead, so the egress files give the converge its proxy. The refresh writes the files before the converge. dockerd reads neither: its drop-in lets a [Database server](/reference/database-servers)'s image pull reach the registry, and dockerd reads it when the nested container starts.

The fleet has the subnet of a task VM's nested fleet, but no Instance runs the task VM's package cache. These rules keep apt on the egress proxy:

- **Its own file.** The fleet's apt file is `95orbit-nested-egress`. The Node plays own only `90orbit-package-cache`, the [task VM package cache](/reference/node-provisioning#role-prerequisites).
- **Its order.** The fleet's file sorts after the cache file. So its proxy wins over a cache file that the image or an older role add left.
- **The marker.** The nested Gateway reads `/etc/orbit/instance-fleet` and gives its Nodes no `apt_proxy`.
- **The cleanup.** A Node play without `apt_proxy` removes `90orbit-package-cache`: the role add, the role converge (`node:role:add --converge`), the enrollment and the exporter.
- **A whole converge.** `node:converge` leaves `90orbit-package-cache` in place. The fleet's file still wins.
- **Tool setup.** It reads the snapshot with the Node's own apt settings.

A fleet seed made before these rules gains both files at the next refresh: the files' digest moved, so the refresh writes them and retakes `base`.

The size files keep the nested root disks small. `apt-get` keeps each package file by default, about 70 to 135 MiB per machine, and the egress proxy serves them again. The journal cap file sorts after `orbit-retention.conf` (256 MiB), which the nested Gateway's role writes, so its cap wins. journald applies it at the start after `base`. A real Node gets neither file and keeps its role's settings.

A unit in the fleet reads its secret from `SetCredential` or a file, never `LoadCredential`: there systemd gives the unit no credential and no error. The fleet check fails on a unit that breaks this rule. The nested Nodes keep `wg-quick` from their own Node converge; the fleet build's cloud-init `bootcmd` turns off the `wg` and `wg-quick` profiles.

### The Club key of the nested Gateway

The nested Gateway's `swamp serve` needs a Swamp Club API key. It is never the proof Club key. Orbit gives it to a nested-fleet container at each start that Orbit runs, and it never lands on the container's disk. No snapshot, seed, clone copy or export of the container can hold it. A [publish](/reference/tasks#task-copy) from a nested-fleet task copy reads the same vault entry, so its content check refuses a commit that holds the key.

| Part | Rule |
| --- | --- |
| Source | The setting `ORBIT_NESTED_FLEET_CLUB_API_KEY` in the shared Gateway env file. `gateway:nested-fleet-club-key:install` writes it from stdin ([Gateway environment](/reference/gateway-environment)) |
| Vault | One Swamp vault entry, `orbit-control-inputs` / `nested-fleet-club-api-key`. The Gateway replaces it when the value changes; no run deletes it |
| Intent | A nested-fleet container's intent has `club_key`: the folder, the owner `1000:1000`, the tmpfiles line, the nested Gateway and its serve unit. A run sets `club_key.available`. Other containers have no `club_key` |
| Starts | The create of the default, the clone and every deploy start, right after the container starts |
| Channel | Stdin only. The value is in no argument, intent, result, log or file on the Node |
| Place | `/run/orbit-club/swamp-club-api-key`, mode `0600`, owner `1000:1000`, in a folder `root:1000` mode `0750`. `/run` is a tmpfs, checked before each write. A tmpfiles line makes the empty folder at boot |
| Nested Gateway | The disk device `club-key` binds the folder `/run/orbit-club` into nested-gateway at `/home/orbit/.config/swamp/club`, `shift=false`. `/home/orbit/.config/swamp/api-key` there is a link to `club/swamp-club-api-key`. A new value restarts its `orbit-swamp-serve` |
| Result | `nested_fleet_club_key`: `written`, `written_serve_not_restarted`, `unchanged`, `refused` or `missing`. See below |
| Without a value | The container starts without the key and the result says `missing`. Serve stays down until a later start delivers the key |
| Other containers | No new task runs and no command changes. Their workflow steps name a key that every run has, which the step never delivers |

The Gateway reads the value once per run, when the run starts, from the shared env file itself. That one read decides both `club_key.available` and the vault copy; admission reads nothing. The queue workers that start runs keep the configuration they loaded at boot, so a read from the configuration cache would miss a key placed after they started. Reading the file means a placed or changed key reaches the next start with no reload.

The Operation's result says what the start did, never what the Gateway held at admission. It comes from the delivery script's own word, which the Ansible phases keep in the run's result file and a clone's `clone-club-key` step prints:

- `written`: the start wrote a new key and restarted the nested Gateway's serve.
- `written_serve_not_restarted`: the start wrote a new key, but the inner Incus was not ready. Serve starts by itself later (`Restart=on-failure`) and reads the key then.
- `unchanged`: the same key was already there.
- `refused`: the start refused before the write, with `instance.nested_club_key_unsafe_transfer` or `instance.nested_club_key_not_tmpfs`.
- `missing`: the start wrote nothing. The run had no value, the write failed, or the run ended before the step.

Right after a boot, the inner Incus can wait behind `network-online.target`, and its client then blocks. So after the write the script touches the inner Incus only when `incus.service` is active and `incus list` answers within 5 seconds; each restart command has 10 seconds. Otherwise it skips the restart and says `changed_serve_pending`. The step never waits on the inner Incus.

The delivery is part of the `start` phase, the first phase of a deployment's step `Prepare the container`, before any check of the inner fleet. That covers the restart after a failed refresh too, which starts the container through the same `start` phase. A clone runs its own step, `clone-club-key`, right after `clone-container`.

In Ansible the value is the module stdin of a pipelined task with `no_log`. Without pipelining, or with remote files kept, Ansible writes the module with its arguments to `~/.ansible/tmp` on the Node. So the task forces pipelining and refuses with `instance.nested_club_key_unsafe_transfer` before it reads the value when pipelining is off. A clone gets the value on the SSH stdin of `instance_clone.py club-key`. Both pass it on as the stdin of `incus exec ... sh -c`.

Root owns the folder, so the key's owner cannot plant a link there that would send the key to the disk. The write refuses with `instance.nested_club_key_not_tmpfs` when `/run` is not a tmpfs.

The Gateway's code does not add the `club-key` device to nested-gateway: the [fleet step](#the-fleet-step) does, in its rules and its start, and an existing fleet gets it by hand. Until then the key reaches the outer container's `/run` only.

A container that Incus starts by itself after a host reboot has no key until Orbit starts or deploys it again. `/etc/orbit/swamp-club-api-key` is the hand-placed file that this replaces; nothing writes it.

### Egress of the nested fleet

The nested Nodes reach the internet through `oe-nested`, the outer container's NAT and the Node's egress proxy, so the proxy's lines carry the Instance's bridge address.

### Users

`agent`, `www-data` and the nested fleet’s `orbit` share the outer container; only `agent` reaches the fleet.

`agent` drives the inner Incus as a member of its admin group. It restores `base`, runs commands in the nested containers and reads their journals. It has root in its own container through `sudo` with no password, as in every container. It is never root on the Node.

`www-data` and `orbit` cannot use the inner Incus. A firewall rule by uid in the outer container drops the traffic of uid 33 and uid 1000 to the fleet's bridge and WireGuard subnets, as the image does for the model route. The rule is needed because the nested Gateway trusts the source address. Without it, any user in the container would be the fleet's operator.

### Club key and snapshots

[The Club key of the nested Gateway](#the-club-key-of-the-nested-gateway) owns the delivery. For the fleet:

- The key is never in `base`, never in a seed snapshot and never in an export: it lives only in `/run`, a tmpfs.
- Swamp verifies a new key once at Swamp Club before serve runs. It goes through the egress proxy, by the proxy rule above.
- Until the key is verified, serve exits with code 75. A verified key needs no network to run.
- An agent with root in a clone can read the key. A task in any other Project never gets it.
- The fleet step and the refresh never read, write or copy the key.

Before the delivery, an operator placed the key by hand in `/etc/orbit/swamp-club-api-key`, on the container's disk. While that file is there, the seed step of a nested-fleet default refuses with `instance.fleet_key_on_disk`. It tests the path only.

### Refresh after a release

After each Gateway release, Orbit deploys each nested-fleet default to the release commit. The deployment is `instance.deploy` with more steps, which only an intent with `fleet` runs. The inner fleet keeps running between releases. The run checks it once, first, then takes the seed of the default as it is, fleet included. Then it refreshes the fleet and takes the seed snapshot again. The [refresh chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/fleet-refresh.mmd) has the steps. An `instance:deploy` of the default runs the same flow.

When the run records the new seed of the proof Project's default, the Gateway copies that seed to each other Node that offers proof places ([The seed on each CI Node](#the-seed-on-each-ci-node)). The copies run after the refresh, and nothing in the release waits for them.

A default gets `fleet` when its runtime is in `orbit-instances-nested`, its Project has `nested_fleet` on and its Node's switch is on (`InstancePlacement::keepsFleet`). Every other deployment runs the same steps and calls as before.

The container phases of a deployment run in two Ansible steps, each one play that runs its phases in order ([deploy chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/deploy.mmd)). The phases of one step share their failure handling:

| Step | Phases of a normal default | Phases of a nested-fleet default | A failure |
| --- | --- | --- | --- |
| Prepare the container | `start`, `fetch` | `start`, `fleet_start`, `seed`, `fetch` | restores nothing: the workspace has not changed |
| Check out in the container | `checkout` | `checkout` | restores the old seed of a nested-fleet default |
| Finish the container | `processes`, `seed`, `check` | `processes`, `fleet_refresh`, `seed`, `check` | restores the old seed of a nested-fleet default |

Each Ansible step costs about 2.5 s to start its play (Python, the address play and SSH), and each Swamp step about 0.8 s more. The two merged steps save six of the nine play starts of a nested-fleet refresh, about 20 s, and three of the six of a normal deployment, about 10 s. The handlers a phase notifies run before the next phase starts, as with separate plays.

Each phase writes the result file as it ends, as its own play did, with the phases that passed so far as `phases_passed`. So a step killed in a later phase, by its timeout or a lost Node, keeps what the passed phases wrote, such as the Club key's word. A failed phase stops the step. The result file names it as `failed_phase`, and the code is the phase's own refusal or `instance.container_<phase>_failed`. The container counts as started once `start` is among the passed phases; otherwise its power state stays as it was.

When nested-fleet refresh is enabled, the Gateway tries to admit one deployment per nested-fleet default when the verified release first becomes [fleet current](/reference/gateway-recovery#fleet-rollout). If fleet rollout is disabled, it tries at verification. The fleet rollout has no time window, so admission follows the release at any hour. A pending or failed rollout postpones admission until the fleet becomes current. The deployment has its own admission rules and can still be refused. The Gateway logs each admission result. A failure for one default leaves the other defaults eligible.

The Gateway setting [`ORBIT_INSTANCES_NESTED_FLEET_REFRESH`](/reference/gateway-environment) enables refresh. It is off by default. Enable it only after the Club key lives off the default's disk and a safety snapshot of the default exists.

The run deploys the release commit itself. A commit missing from the Project's repository refuses the admission with `project.source_unavailable`. A commit off the Project's branch fails the fetch with `deployment.commit_not_on_branch`, and the old seed stays. When the Project's repository is Orbit's own, the release commit is on its `main` already, and no step pushes it. A Project whose repository is a hand-kept copy of `main`, such as a trial repository, needs the release commit pushed there before the release.

| Rule | What happens |
| --- | --- |
| Best effort | The release stays live if admission is refused or a deployment fails. The fleet result tracks the Nodes. Each deployment reports its own result |
| No refresh during a copy | A clone copy reads the seed that a refresh replaces. While a clone's create is open, the admission refuses with `instance.clone_in_flight` |
| One run at a time | An open deployment of the default holds its Instance lock. A second admission refuses with `operation.target_busy` |
| The fleet stays | A step that fails from the checkout on restores the newest seed: the one the run took first, or a newer one. A restore never goes back past the fleet |
| Room before a change | The check reads the room on the nested Nodes' disk first. When a refresh could not end above the inner Doctor's floor, it refuses with `instance.container_fleet_no_room` and the numbers. Nothing changed |
| Check before a change | The check compares the inner fleet with its [rules](#fleet-rules) before the first seed. A refusal, `instance.container_fleet_drift`, changes nothing and restores nothing |
| A failure names its phase | The Operation names the first failed step, and its code names the failed phase, such as `instance.container_fleet_refresh_failed` from "Finish the container". `error.detail` names the failed task and its message. |
| Migrate before Doctor | The check checks only the containers and the tunnel. The refresh migrates the nested Gateway before its Doctor wait |
| The fleet runs after the run | The refresh starts again what it stopped for `base`, before the seed. After a restore, the run starts the restored fleet |

A checkout that fails other than by a refusal also writes its detail: the failed task, its message and the end of the command's stderr, at most 4,096 characters.

A Swamp step that exits nonzero keeps no output. So a failed fleet phase or seed step writes why into the run's result file, and the Gateway shows it as `error.detail`. For the readiness wait, that is every issue of the last Doctor body: its code, Node, summary and observed value, each at most 240 characters, and at most 12 issues. Any other body gives its first 300 characters. For a seed, it is the [cause](#a-retake-keeps-the-seed), such as no free ID range.

The nested Nodes' `/` lies in the inner pool: a directory pool on the outer container's disk, or the [inner volume](#the-inner-volume). So the room the inner Doctor reads for `node.disk_low` is the outer container's `df /`, or `df` of the inner volume's mount. It wants at least 2 GiB and 10% of the size free.

A refresh writes up to 2 GiB more before its readiness wait: on Instance 2454 a seed after a refresh that retook `base` held 1.66 GiB of its own blocks, and each refresh added 0.1 to 0.3 GiB of data. The check refuses when the free space minus 2 GiB is under the floor, before anything changes. On a quota that counts snapshots, the seeds that copies still pin count too.

The fleet admission job ends after it queues the refresh. So a failed refresh that a release admitted also writes the Activity row `instance.refresh_failed`: status failed, command `instances:refresh-nested-fleets`, with the Operation, its code and its detail. `activity:list` and the problem collector show it.

The checkout moves under the running nested Gateway, with its old schema and processes. So the run's one fleet check, before the checkout, starts what is stopped and checks only that the containers run and the tunnel reaches the hub. Nothing in the run stops the fleet before the refresh, so no second start follows. The refresh migrates and restarts the nested Gateway. When the converge is due, it waits for the three agents and converges the Nodes. Only then does it wait for Doctor.

#### What a refresh skips

A refresh always checks fleet readiness. For work it can skip, it compares input digests with its record, `/var/lib/orbit/fleet-refresh.json` in the outer container.

| Part | Digest | When it runs |
| --- | --- | --- |
| Migrate the nested Gateway, restart its services | Git object IDs of `apps/gateway` and `apps/agent/contract`, which it reads | The digest differs |
| Wait for the three agents | | The converges run |
| Default converge of each nested Node | One digest per Node: the `swamp` files a default converge reads, the nested Gateway's digest of that Node's default intent, and the fleet intent | For that Node: its digest cannot be read, the record holds none, its digest differs, or its last converge is more than 7 days old |
| Prepare the Instance host | The separate `host` digest | On an eligible `nested-app-dev`, when the host digest differs, is missing from the record, or cannot be read |
| Agent delivery | `code` | The code digest differs. The delivery writes into the checkout, from `apps/gateway` |
| Reload the grants | Git object IDs of `apps/gateway` and `swamp`, from which the nested Gateway renders them; recorded only with the Club key | With the Club key: the digest differs. The grants live on the nested Gateway's own disk (its state mount `swamp/grants`) |
| Read the fleet files, write those that differ | The fleet files' content | The digest differs, the converge runs (it needs the egress files), or `base` is retaken after no read or after the converge. See [How `base` is taken](#how-base-is-taken) |
| Wait for the state mounts, check the nested Gateway's services (one read), read the fleet's devices and keys, readiness | None | Every run |
| Renew the nested Gateway's Swamp tokens, restart its services | The names and creation times of its six tokens; the record keeps the oldest | With the Club key, when the record's oldest token is missing or older than 20 days. See [The nested Gateway's tokens](#the-nested-gateways-tokens) |
| Install the recipe's Swamp on the nested Gateway, restart Swamp on it | None: every run reads the recipe's release and the binary that serve runs | With the Club key, when serve does not run the recipe's release, or a read fails. See [The pinned Swamp](#the-pinned-swamp) |
| Retake `base`: of the running fleet, or stopped with the rules applied | See below | Only when the fleet's disks changed |
| Build the pinned shape tools in the outer container | The checkout's three pin files, through `bin/swamp-shape-gate`, and four packages | The tools of these pins are not complete, or a package is missing. Every run reads them. See [The pinned shape tools](#the-pinned-shape-tools) |

The migrate follows `apps/gateway`, not only its migrations folder. Packages in its Composer lock ship migrations too, so a new lock can bring a migration.

The refresh converges each nested Node with the default scope, as `node:converge` with required arguments alone does: the base, the software, every recorded role, the Tools and the network. So `base` holds Nodes whose software and role state match the seed's commit, and a proof's converge after a reset finds nothing to change unless the commit under proof or the case changed an input. A base converge left the software, the roles, the Tools and the network to the first default converge after each reset, so every converge case of a proof installed the software and the role state again.

The converge digest follows what a default converge of a nested Node reads, so a release that changes only tests, other workflows or other Gateway code converges nothing. Each Node has its own digest, so a moved base intent converges only that Node. A digest has three inputs:

| Input | What it holds |
| --- | --- |
| The `swamp` files that `swamp/ansible/fleet-converge-inputs.json` lists | The converge workflow, the `ansible-input` behavior and its imports, the playbook with all its includes, its task and copied files, its configuration, and the modules a task calls |
| The nested Gateway's digest of each Node's default intent | The agent pin and its configuration, the recipe, the kernel and update policies, the SSH target, the CLI plan, the recorded roles with their [units](/reference/node-provisioning#role-units-at-a-converge), Tools and network |
| The fleet intent | The fleet's names, layout, checkout and proxy |

A cover test, `swamp/tests/fleet_converge_inputs_test.ts`, walks the playbook and the imports. It fails on a file that the list does not hold.

A module in `swamp/ansible/library/` counts only when a task of the default converge names it. A `module_utils/` file counts only when such a module imports it. Every local module and util is named `orbit_<name>`, so none overrides one of Ansible's own, which the builtin tasks load. So a release that changes only a module of another play, such as a Route module, converges nothing.

The skip never leaves an old module in the fleet. The nested Gateway mounts the container's checkout, so the next inner play loads the new module from it.

The cover test fails, with the file and line, on what it cannot follow:

- a dynamic include;
- a templated module name;
- a short module name that is not in `library/`;
- a role or `include_vars`;
- a copy of a folder or of a templated source onto a Node.

After the migrate, the refresh runs `php artisan orbit:node-converge-digest gateway app-dev app-prod` in the nested Gateway. The command builds each intent as a default `node.converge` does. It admits no Operation, makes no execution copy and writes nothing. When the intent of one Node cannot be built, the command prints that Node's error in place of its digest and still prints the digests of the others.

Each Node has its own reason in the result, `converge_reasons`. The refresh only reports what its comparison found:

| Reason | When | The Node converges |
| --- | --- | --- |
| `digest_unreadable` | Its digest cannot be read. `error` names why. No digest is recorded for it | Yes |
| `no_record` | The record is missing or unreadable, or holds no digest for the Node | Yes |
| `digest_changed` | Its digest differs from the record | Yes |
| `stale` | Its last converge is more than 7 days old | Yes |
| `host_due` | `nested-app-dev` only: its base digest did not change, but the [Instance host](#instance-host-in-the-fleet) part is due | Yes |
| `unchanged` | None of these. The Node does not converge | No |

A digest cannot be read when the Node's intent cannot be built, when the read failed, or when the checkout gives no tree ID of the `swamp` files.

`unchanged` is the only reason of a Node that does not converge. For each Node that converged, `phases` names the skippable phases its converge ran. That converge skips each phase whose phase digest did not change, by the [one skip rule](/reference/node-provisioning#skip-a-phase-whose-input-did-not-change).

Each phase digest holds the `swamp/ansible` files that phase can load, the implicit files and the files that nothing reaches. So a changed converge task file runs only the phases that load it, and a file that only another Operation's playbooks reach runs none. A change to `ansible.cfg`, the converge playbooks, `build_vars.py`, an implicit file or a file that nothing reaches runs every phase ([phase code](/reference/node-provisioning#skip-a-phase-whose-input-did-not-change)).

A change only to the five files excluded from the host digest does not make `nested-app-dev` due. It still counts for any part that reads those files, such as the nested Gateway's grants digest when the Club key is present. If no other work changes a nested disk and no other snapshot rule applies, `base` stays as it was.

A file list cannot follow the Gateway code that builds an intent: through its models, that code reaches much of the Gateway. So the nested Gateway hashes the intent itself.

The intent holds no token or signed URL. Two values are new at each capture. They belong to the Operation and are not inputs, so the hash leaves them out: the version of a role's certificate leaf, which names the leaf's folder, and the admission time of the Instance bridge. Like an admission, the read first gives an app-dev Node its Instance bridge when it has none yet, and then rolls it back. So a healthy Node always has a digest. The Operation id and the agent verifier live in the execution copies, which are not part of it.

The list holds the four include groups of the playbook: roles, software, Tools and the network. A default intent selects each of them that the Node records.

The list also leaves out two imports of the behavior file, `write.ts`. A converge never runs their code:

| Import | Why a converge never runs it |
| --- | --- |
| `fleet_reboot_result.ts`, with `_lib/public_output.ts` | Only the `fleetRebootResult` method uses it, and the converge workflow does not call that method |
| `role_add_plans.ts`, with the route and Tool setup plans it imports | Only the `composeRole` method uses it, and the converge workflow does not call that method |

The cover test fails when the converge workflow calls such a method, or when an imported name is used outside its method. Before this cut, two refreshes on 14 November 2026 converged the three Nodes for nothing (Operations 21867 and 22223, 120 s and 108 s, no task changed): one for a change in `public_output.ts`, one for a line in the clone part of `write.ts`.

The record holds each Node's digest as `converge_<role>` (`converge_gateway`, `converge_app_dev`, `converge_app_prod`). An unreadable digest makes that Node due and is not recorded, so the next refresh tries it again. A read failure that affects inputs common to all three makes all three due. The record also holds `converged_<role>`, the time of each Node's last converge. When that is more than 7 days old, that Node converges whatever the digests say. This guards against an input that no digest covers, such as a package of the inner image.

The due Nodes converge one at a time: the nested Gateway first, which holds the hub, then `nested-app-prod`, then `nested-app-dev`. A default converge has a network, so it holds the hub's reservation, and the nested Gateway refuses any second converge beside it ([node:converge](/cli/node/converge)). The seven Ansible plays still lock a behavior per Node (`node-converge-converge-<phase>-<node_id>`).

The refresh admits the next Node only when the one before it has ended. A failed or refused converge stops the converges: the Nodes after it do not converge, and the error names the Node that failed. The run then restores the old seed.

A due converge still prepares inputs and runs its final checks, even when all its skippable phases match the record.

Below the 2,000,000 [ID map size](#instance-host-in-the-fleet), `nested-app-dev` cannot build its Instance image. There the refresh converges it with the base scope, as before, and its software and role state in `base` stay as they were. A proof needs a default at 2,000,000 when its converge or role-add cases run on `nested-app-dev`.

A reset of a fleet restores `base` of all three containers in seconds. On the directory pool, taking `base` was the slow part: about 32 s for each container, plus its stop and start. So the refresh retakes `base` only for the containers whose disk changed, and the set stays consistent:

| What changed | `base` retaken for |
| --- | --- |
| A step that writes a nested container's own disk reported a change: the grants reload (a recap other than `changed=0`) | That container: today the nested Gateway, whose disk holds the grants |
| The nested Gateway's tokens were reminted | The nested Gateway, whose disk holds the tokens and `.env` |
| A converge reported a changed task on a Node | That Node and the nested Gateway, whose database records the converge |
| A nested container has no `base` | That container |
| The record is missing or unreadable, or a fleet rule or file differed on the running fleet | All three; after a difference, with the rules applied on the stopped fleet |

The refresh lists each step that writes a nested container's own disk once, with that container. A new writer joins that list. So a reset after a refresh that reloaded grants comes up on the new grants.

The digest rules change only which work is due. They do not decide which containers get `base` or change how Orbit takes, distributes or restores the default's seed. When a role task changes `nested-app-dev`, the refresh still retakes `base` there and on the nested Gateway, so the role change survives a reset.

A migration is not such a writer. The migrate that applies one changes the nested Gateway's database, but `base` keeps the schema of the refresh that last took it. A reset restores `base` and then migrates the nested Gateway forward to its checkout, and restarts its services when a migration applied. Laravel's migrations run forward only, and the code is the checkout's.

A migration that was edited after it ran once is not run again, so its new form never reaches a restored `base`; change a schema with a new migration. Retaking `base` for each migrating release cost 95 to 100 s (stop, a full file copy on the directory pool, start and readiness; Operations 21810 and 21978).

The nested Gateway's other two state mounts need no rule. The refresh writes nothing to `swamp/vaults`: on the live default, no file there changed during a refresh.

The nested Gateway's serve loads the workflows of the checkout as they change, with no step of the refresh. It does not register the [schedules of the Swamp Node](/reference/operations#schedules-of-the-swamp-node), `swamp-credential-monitor` and `swamp-datastore-watch`: they have no built-in trigger, and only the production release load sets them.

Swamp serve writes `swamp/.swamp` all the time, with or without a refresh. It holds run records, outputs, an audit log and caches built from the checkout. These are records, not inputs. After a reset, serve builds the caches again.

#### How `base` is taken

A btrfs snapshot of a running container is atomic. So on the btrfs pool `inner` of the [inner volume](#the-inner-volume), the refresh takes `base` of the running fleet. It runs these steps in order:

1. It waits for readiness, the run's one readiness wait, so `base` holds a ready fleet.
2. It deletes the old `base` of each target.
3. It holds the writes of the nested Gateway's Valkey clients with `valkey-cli CLIENT PAUSE 30000 WRITE`, then saves the queues with `valkey-cli SAVE`.
4. It arms a thaw in the Instance container. Only this step runs between the save and the freeze.
5. It checks that the hold has at least 10 s left, then freezes the targets in one `incus pause`. The freeze may take the hold's time left minus 5 s.
6. It checks that at least 5 s of the hold are left, then snapshots each target.
7. It thaws them in one `incus resume`, cancels the armed thaw, and lets the Valkey writes go with `valkey-cli CLIENT UNPAUSE`.

Nothing stops, boots or waits again. The thaw runs also when a snapshot fails; the refresh then fails, and the run restores the old seed.

The armed thaw does not need the controller. It is a transient systemd timer, `orbit-fleet-thaw`, that resumes each target 60 s after it was armed (`fleet_thaw_seconds`). A resume of a running container only fails. So a refresh whose controller is lost while the fleet is frozen leaves it frozen for at most 60 s. In task copy 3717 the controller was killed right after the freeze, and the two containers ran again 60.3 s after the timer was armed.

The snapshot is what a power cut would leave. The nested Gateway's SQLite database recovers from its journal, as after a crash; no other database runs in the three. Valkey keeps the queues in memory, without an append-only file, and writes them only on its save schedule. Without the save, `base` held a `dump.rdb` about 85 s older than the snapshot.

A write acknowledged between the save and the freeze would still be missing from `base`, so the writes are held from before the save until after the thaw. The hold ends by itself after 30 s (`fleet_queue_pause_ms`), so a lost controller cannot hold them longer. A write acknowledged after the hold ended would be missing from `base` too. So right before the freeze, a hold with less than 10 s left is refused: the writes go, the armed thaw is cancelled, and the stopped path takes `base`.

A freeze that fails, runs out of its time, or ends with under 5 s of the hold left is refused the same way, after each target is resumed: no `base` of the running fleet is taken. A hold or a save that fails lets the writes go and takes the stopped path.

In task copy 3717 a writer pushed one list element every 100 ms across a retake of `nested-gateway` and `nested-app-dev`. After a reset from that `base`, all 1,424 elements acknowledged before the freeze were there, and none acknowledged after it. The one write sent during the hold waited 8.7 s, until the writes were let go after the thaw. With the deletion moved before the hold, a second run kept all 1,391 elements acknowledged before the freeze; the freeze came 0.8 s after the hold, and the longest write waited 8.5 s. The median write took 6 ms.

The freeze gives the set one point in time across the containers, as a stop did. It lasts as long as Incus takes to snapshot the targets: about 0.4 s for the freeze and the thaw, plus 1.3 to 2 s for each app container and about 5 s for `nested-gateway`. The btrfs snapshot itself takes 0.04 s. Incus spends the rest of that time in its own snapshot step for each container (measured in task copy 3717 on 2026-11-18).

The refresh stops the targets as before, and takes `base` of the stopped fleet, when:

- an inner pool is not btrfs, such as the directory pool of a default made before the inner volume. There a snapshot of a running container is a file copy.
- the pools cannot be read, or the read gives anything but one driver name per line. The refresh reads them once, with `incus storage list --format csv --columns D`.
- the hold of the nested Gateway's queue writes or the save of its queues fails.
- a fleet rule or file differed on the running fleet. The rules are written with the three stopped.

A due [shrink](#the-inner-machines-stay-small) never stops the fleet: it runs after the refresh.

The result says which way ran: `base_mode` is `running`, `stopped`, or `none` when `base` stays.

A running fleet is not compared again before its snapshot. So a run whose converge ran after it read the fleet files reads them again before `base`, on the running fleet. A file that differs there makes `base` due for all three, with the fleet stopped.

A `base` of the running fleet holds the live pid file of each network of the nested Incus, such as `orbitinst0` on `nested-app-dev`. The [reset](#no-stale-network-pid-file) is the only place that restores `base`. It clears those files after the restore and before the start. Only a `base` of the stopped fleet is cleared before it is taken, so only it holds none.

On the copy that measured it, a refresh that retook `base` of `nested-gateway` and `nested-app-dev` spent 9 to 10 s on `base` against 59 to 66 s with the stop, start and second readiness wait. The whole refresh took 32 s against 89 to 95 s.

With only some containers stopped, the rules only compare. A rule that differs on a running container fails the refresh, so the refresh never writes to a container whose `base` it keeps. Each container to retake stops; the others keep running. While the nested Gateway is stopped, the agents of the other two lose it and connect again after its start, as after the restart of its services.

The refresh waits for readiness once in every run. After a `base` of the stopped fleet, that wait follows the start of what stopped. After a `base` of the running fleet, or when nothing changed, the fleet keeps running, and the wait comes before `base` and the tools.

If that start fails, the refresh fails and the run restores the old seed. The new `base` snapshots are inside the outer container's disk, so the restore takes them back too. The default then runs on the old seed's commit with the old seed's fleet, as before the run. Existing copies are clones of older seeds and stay as they are. New copies clone the restored seed until the next release.

The refresh removes the record before its first write to a nested disk, and writes it again as its last task, before the seed. So:

- A refresh that stops in the middle leaves no record. That covers a failed step, a failed restore of the old seed and a run by hand.
- The next run then does every part again and retakes `base` of all three.
- The seed holds the record of the fleet it holds. A restore of an older seed brings its own record, so the next refresh redoes what changed since then.
- A record that was deleted makes every part run.
- A record that cannot be read or parsed makes every part run too, with a warning in the result. It never fails the run.

The record is owned by root, mode 0644, in a root-owned folder. The workspace user cannot write it without root. The `agent` user has root through `sudo`, so the mode is no guard against it. So a user with root in the default can make a refresh skip a part. That user can change the inner fleet directly too, so the record adds no new risk. A copy is never refreshed, so a record in a copy has no effect.

The write goes to a file beside the record, and a rename puts it in place. So a cut write leaves no record, never a partial one. The write reads stdin as a stream. The container has an isolated id map, so it refuses a command that opens `/dev/stdin` by path. A source check fails when an Ansible task or workflow step behind `incus exec` opens stdin by path. A task that runs in a virtual machine is exempt.

#### The nested Gateway's tokens

The nested Gateway runs its own Swamp. Its six tokens live 30 days: the serve driver, the Gateway's run and read tokens, and the access tokens of workers a, b and c. A fleet lives longer than that, and its copies inherit its tokens. So the refresh keeps them young.

The record keeps `tokens`, the creation time of the oldest of the six, as `YYYYMMDDHHMMSS` in UTC. With the Club key, a refresh whose record has no such time, or one older than `fleet_token_max_age` (20 days), reads their names and creation times with `swamp access token list`, which shows no secret. Any other refresh reads no list: `tokens: young`, `tokens_reason: recorded`. The tokens are due when one is missing, one is older than 20 days, or the list cannot be read. Then the refresh runs the `remint` play of `gateway-bootstrap.yml` on the nested Gateway:

1. It stops the workers and serve, which hold the token store open.
2. It makes the worker set whole (`gateway-swamp-workers.yml`). A worker without token files gets its folder and tokens. A fleet built with workers a and b only gets worker c. Its first refresh remints with `tokens_reason: incomplete`.
3. It renews all six tokens, then enables and starts serve and the three workers.
4. It ends only when serve and the three workers are active.

The refresh then restarts the nested Gateway's services, because they read the new tokens. It checks serve and the three workers before any converge.

The remint takes about 1 to 3 minutes; the record then holds the time of the remint. A refresh that reads the list but does not remint takes about 2 seconds for it. A remint writes the nested Gateway's disk, so its `base` is retaken. A failed remint fails the refresh with `instance.container_fleet_refresh_failed`, its step "Renew the inner Swamp tokens", and the run restores the old seed. No token reaches a log line or the result. Without the Club key the nested Swamp does not run, and the tokens are `skipped`.

#### The pinned Swamp

The fleet build installs the nested Gateway's Swamp once, from the recipe of its commit. A release that moves the Swamp pin in the recipe (`apps/gateway/resources/machine-software/recipe.json`) reaches the nested fleet through the refresh, not through a rebuild.

With the Club key, every refresh reads two things in the nested Gateway: the release that the checkout's recipe pins, with its sha256, and the binary that serve runs. The fleet build installs a release at `/usr/local/lib/orbit-swamp/<sha256>/swamp`, and the part uses the same path. It is due when serve does not run the recipe's release at that path, or when a read fails. A new default built at the recipe's release therefore installs nothing. When the part is due, the `swamp-pin` play of the nested Gateway (`gateway-swamp-pin.yml`) runs:

1. It installs the release beside the old one, by checksum, through the egress proxy.
2. It stops the workers and serve, links `/usr/local/bin/swamp` to the new binary, and starts serve.
3. It waits for `/ready`, starts the workers, and checks that serve and each of the three workers run the new binary.
4. It removes the folders of the other releases, about 290 MB each.

The part takes about 20 s, most of it the download. It writes the nested Gateway's disk, so its `base` is retaken. The result names `swamp` (`installed`, `pinned` or `skipped`), `swamp_reason` (`changed`, `unreadable`, `pinned` or `no_key`) and `swamp_version`.

A play that fails, or a serve or worker that does not come back on the new binary, fails the refresh with `instance.container_fleet_refresh_failed` and its step "Install the recipe's Swamp on the nested Gateway". The play removes the old release only after its check, and the run restores the old seed, and with it the old binary and link: both live on the nested Gateway's own disk.

The seed is retaken after the readiness wait, when the inner Doctor is healthy. A copy made from it starts its inner serve on the new binary, with the Club key mounted from tmpfs as before. Without the Club key the nested Gateway's Swamp does not run, and the part reads nothing: `swamp: skipped`, `swamp_reason: no_key`.

#### The pinned shape tools

The proof runner in a copy needs the pinned Ansible controller of `bin/swamp-shape-gate`: a venv and the Ansible collections, about 160 MB. A copy gets the default's seed, not the image, and the default is never rebuilt from a new image. So every refresh keeps the tools in the default itself, after `base` and before the seed. Each copy then holds the tools of its own commit. No copy builds them, and no copy needs `python3-venv`.

The same step keeps three host programs that Swamp fixture units run: `zip`, `unzip` and `jq`. A [task check](/reference/implementation-loop#the-scoped-task-check) in a copy runs those units, so the copy needs them too.

It also keeps `btrfs-progs`. The inner Incus needs it for its btrfs pool on the [inner volume](#the-inner-volume). The nested image carries it since PR #3706, so a new nested-fleet default has it from its image. A default made from an older image, Instance 2454 among them, keeps that image and lacks it until this step installs it. [Convert a default](#convert-a-default) needs it inside the container before the inner pool can be made.

The step sends `swamp/ansible/instance_shape_tools.py` into the outer container and runs it as root, with the workspace as its argument. The script loads the checkout's own `bin/swamp-shape-gate`. The gate names the tools: `/opt/orbit-shape-gate/venv-<digest>` from `swamp/ansible/shape-gate-requirements.txt`, `swamp/ansible/requirements.txt` and the interpreter that builds the venv, by [the gate's venv rule](/reference/implementation-loop#run-the-swamp-shape-gate-before-you-push), and `/opt/orbit-shape-gate/collections-<digest>` from `swamp/ansible/requirements.yml`. The gate also builds the venv. So the step, the gate and the proof runner always use the same names, and no version or naming rule exists twice.

| State in the default | What the step does | Result |
| --- | --- | --- |
| The venv has `.complete`, the collections folder has `ansible_collections`, and the six packages are installed | Reads only, in about 1 s. Removes the tools of other pins if an earlier cleanup failed | `tools: present`, `tools_reason: current` |
| The tools are ready, but a package is missing | Installs the missing packages with one `apt-get install` | `tools: built`, `tools_reason: missing` |
| Anything else | Builds the tools, as the list below says | `tools: built`, `tools_reason: missing` |

It also keeps `duperemove`, which [the shrink of the nested root disks](#the-inner-machines-stay-small) runs. The image does not carry it, so the first refresh after the shrink was added installs it.

The six packages are `python3-venv`, `zip`, `unzip`, `jq`, `btrfs-progs` and `duperemove`. The step reads each one with `dpkg-query`. The image lacks `zip`, `unzip` and `jq`; an image older than PR #3706 also lacks `btrfs-progs`. So the first refresh that finds a package missing installs it, with `libjq1` and `libonig5` for `jq`, about 0.75 MB. The `apt-get update` and the install add an estimated 10 to 20 s, once. For a default that lacks only `btrfs-progs`, the next refresh reports `tools: built`, `tools_reason: missing` once; the install alone took 1.4 s in a copy of Instance 2454. Later refreshes report `tools: present`. The step changes no image and moves no image alias.

A build does five things:

1. It installs the missing packages with one `apt-get install`.
2. It builds the venv with the gate's own `tool_path`.
3. It installs the collections into a `.partial` folder and renames it. Collections that are complete already stay.
4. It writes the venv's `.complete`, the marker of ready tools.
5. It removes the `venv-*` and `collections-*` folders of other pins or another interpreter.

The build reaches three services: the Ubuntu mirror (apt, for the packages), the Python package index (pip) and Ansible Galaxy (`ansible-galaxy`). All three go through the Node's egress proxy: apt by its own setting in the image, pip and `ansible-galaxy` by `HTTPS_PROXY` from the Instance profile. The first build in a copy took about 30 s. The folders are mode 0755 and the files 0644, so `agent` reads them. The runner's own `ansible-galaxy collection install` then finds every collection and writes nothing.

A failed build or package install never fails the refresh. The refresh goes on and takes the seed. The result says `tools: failed`, and `tools_reason` names the step: `names` (the checkout's gate cannot be read), `package`, `venv`, `collections` or `cleanup`, `timeout` after 600 s, or `unreadable` when the script printed no result line. The run's log then shows the end of the step's error output.

The gate and the runner take the tools as ready only when the venv holds `.complete`. The script writes that marker last, after the collections are in place, so a failed or stopped build leaves no venv that looks ready, and the next refresh builds again. A proof in a copy without ready tools builds them in its own cache, as before, which needs `python3-venv` and the internet. When that build fails, the fleet preparation fails with one line from the runner (`proof_controller` in `swamp/proof/runner/fleet.py`). The line names the cache build that failed and ends with the missing folder, `/opt/orbit-shape-gate/venv-<digest>`.

#### The inner machines stay small

The three nested containers hold their root disks on the inner pool. The shrink keeps them small. The step is `swamp/ansible/instance_fleet_shrink.py`, run as root in the outer container. It has three parts, in this order.

It runs outside the refresh's `base` step, on the running fleet. A due shrink once forced the stopped `base`: in the refresh of Instance 2454 after release 2162 (Operation 29505), `base` took 163 s instead of about 10 s, because `btrfs-progs` had just been installed on `nested-app-dev`. That happens at every release that changes a nested package.

So the refresh checks the shrink and takes `base` of the running fleet on btrfs. A due shrink makes `base` due for all three. A changed package means a nested container's disk changed, and no converge needs to report it: a package installed by hand on `nested-app-dev` is one. The check does not name the container, so `base` of all three holds the new package, and a reset onto it keeps it. Only the shrink waits. After the tools step the refresh starts it as the transient unit `orbit-fleet-shrink` in the outer container. The result says `shrink: started`.

The shrink runs on after the refresh's Operation ends, while the three run. Its record says that `base` does not hold it yet. The next refresh finds the check `shrunk`. It retakes `base` of all three on the running fleet, so `base` holds the small form. Then it records that.

Each refresh first stops an `orbit-fleet-shrink` that still runs, before its first write to a nested disk. So no shrink runs while a refresh holds `base`. A stopped shrink writes no record and starts again after that refresh's `base`. A restore of the old seed stops the outer container, and with it the unit. The unit lives in `/run`, so no seed holds it. It has 900 s (`RuntimeMaxSec`).

The relink and the dedupe are safe on running root disks:

- The relink replaces a file only by a link to an identical file. It swaps the two names in one atomic step. A reader never finds a missing or partial path.
- A package may replace the file after the compare. Then the step swaps it back and skips it.
- The step opens each folder on the way from the one above it. It follows no symlink and holds the folder open. So a swapped folder never leads it outside the root disk.
- The kernel compares the bytes before `duperemove` shares them, so no file changes.
- The step compares the dpkg status before and after the run. A package that changed in between leaves no record, so the shrink runs again.
- Each nested container's own `apt-get clean` empties its archives. A held apt lock leaves them for a later shrink.

apt needs a package it fetched until dpkg unpacked it, and holds the archives' lock over that time. `apt-get clean` takes the same lock without waiting. While an apt run holds it, the clean fails and its error goes to the unit's journal.

The seed stays consistent. A seed is a snapshot of the outer container and its inner volume, taken at one point in time. A seed taken while the shrink runs holds whole files, some relinked or shared and some not. Its `base` snapshots are those of the refresh before, which the shrink never touches. A seed copy (`instance.seed_copy`) pushes such a seed, so it never pushes a half-written `base`. Until the next refresh, `base` and the seed hold the old blocks: larger, but whole.

The first part is the relink. Ubuntu's `rust-coreutils` ships one binary as 116 hardlinks: `/usr/bin/coreutils` and its 115 links in `/usr/lib/cargo/bin/coreutils/`. The step reads the package's dpkg digests. Each file with the digest of `/usr/bin/coreutils` is linked back to that file. It must also have the same bytes, owner and mode. Other files of one digest, such as the package's empty locale files, are separate files in the package, so they stay separate. Each link replaces its path atomically: a new link beside it, then one swap of the two names.

The digests and paths come from the nested container's own dpkg files, which its root controls. So every path stays inside that container's root disk: an absolute path, a `..` part, or a symlink at any level below the root disk is skipped and never followed. A path that cannot be linked, such as a name too long for the temporary link, is skipped too; it never fails the step. This includes `/usr/bin/coreutils` and its folders, the dpkg files themselves and the cache folders.

Each machine's count of skipped paths goes to the step's standard error; the result counts only the relinked paths. A root disk that is itself a symlink fails the run (`shrink: failed`), and a dpkg status that is no regular file inside the root disk makes the check `unreadable`. `duperemove` gets only the three root disk paths, which the outer side names, and its walk follows no symlink.

The second part is the caches. The step runs `apt-get clean` in each nested container, through `incus exec`. It removes the `.deb` files in `/var/cache/apt/archives` and its `partial` folder. apt keeps no new ones (the size files of [Fleet rules](#fleet-rules)), and the egress proxy serves a package again when apt needs it. The clean runs as the nested root, so it reaches no path of the outer side. The journals are trimmed by journald itself, at its start after `base`, so the next `base` retake holds the 64 MiB form.

The third part is the dedupe. `duperemove -dr` over the three root disks makes identical blocks of different files share one extent. The kernel compares the bytes before it shares them, so no file changes. It needs a btrfs pool and `duperemove`. [The pinned shape tools](#the-pinned-shape-tools) step keeps `duperemove` in the container.

The shrink is due when no shrink is recorded, or when a nested container's dpkg status changed since the last one: a package was installed, removed or upgraded. A record of an older rule of the step counts as no record, so a new rule runs once.

The record is `/var/lib/orbit/fleet-shrink.json` in the outer container: the sha256 of each machine's dpkg status, and `base: false` while `base` does not hold the shrink yet. The check reads three files, in under a second; a refresh that changed no package spends nothing more. A due check makes `base` due for all three: `record` and `packages` so that `base` holds the changed packages, `shrunk` so that every `base` holds the shared blocks.

| Case | What the refresh does | Result |
| --- | --- | --- |
| No package changed since the last shrink | Checks only | `shrink: skipped`, `shrink_reason: current` |
| No record, or a dpkg status changed | `base` of all three, of the running fleet; starts the shrink after `base`: it relinks, empties the archives, dedupes and writes the record with `base: false` | `shrink: started`, `shrink_reason: record` or `packages` |
| A shrink ran after the last `base` | `base` of all three, of the running fleet; records that `base` holds the shrink | `shrink: skipped`, `shrink_reason: shrunk` |
| The unit does not start | Nothing more; the next refresh starts it again | `shrink: failed` |
| The check cannot read | Nothing; `base` is decided as before | `shrink: skipped`, `shrink_reason: unreadable` |

What the shrink itself did goes to the unit's journal in the outer container, as its last line (`journalctl -u orbit-fleet-shrink`). Without `duperemove` it relinks and empties the archives and writes no record, so the next refresh starts it again. On a directory pool it does all but the dedupe and writes the record. A run that fails, times out or is stopped writes no record.

The first nested fleet held 116 separate copies of the coreutils binary in each machine, 1.26 GiB each. Its three machines shared no blocks. On a copy of it the shrink took the inner pool from 11.8 GiB to 3.7 GiB in 36 s, with the fleet stopped. The inner volume's own size went from 10.3 GiB to 3.2 GiB. The machines ran again, and `dpkg --verify rust-coreutils` was clean.

In task copy 3777 on 2026-11-19, a package was installed on `nested-app-dev`. The old stopped path took 292 s for `base`: stop 72 s, shrink 199 s. The running `base` took 14 s. The shrink then ran for 166 s as `orbit-fleet-shrink`, with the fleet running and Doctor healthy. The next `base` of the running fleet took 18 s.

The links were lost in that fleet's one-time hand move to the inner volume (14 November 2026). The Incus paths of a new default keep them. Each was checked: an image unpack on the btrfs pool, a start that shifts the IDs, and a move between a directory pool and a btrfs pool, with or without a snapshot. An export and import, and a snapshot restore, keep them too.

A nested container's own package updates count as a change: the next refresh starts the shrink, and the one after it retakes `base` of all three. A shrink that fails at every refresh costs its run after every refresh, outside the Operation, and no `base` retake.

The tools step runs before the shrink starts, so a default whose tools step installs `duperemove` in that refresh runs the whole shrink.

The Operation's result has `fleet_refresh`. It holds these fields:

- `migrate`: `applied`, `nothing` or `skipped`. `restart`: `yes` or `no`.
- `converge`: `ran` or `skipped`. `converge_reasons`: each nested Node's reason, as [above](#what-a-refresh-skips). `converge_nodes`: the Nodes the converge ran for. `changed`: the tasks the converges changed.
- `tokens`: `reminted`, `young` or `skipped`. `tokens_reason`: `age`, `incomplete`, `unreadable`, `young`, `recorded` or `no_key`.
- `tools`: `present`, `built` or `failed`. `tools_reason`: see [The pinned shape tools](#the-pinned-shape-tools).
- `shrink`: `started`, `failed` or `skipped`. `shrink_reason`: `record`, `packages`, `shrunk`, `current` or `unreadable`. See [The inner machines stay small](#the-inner-machines-stay-small).
- `swamp`: `installed`, `pinned` or `skipped`. `swamp_reason`: `changed`, `unreadable`, `pinned` or `no_key`. `swamp_version`: the release that the refresh read in the recipe (null without a read). See [The pinned Swamp](#the-pinned-swamp).
- `base`: `retaken` or `kept`. `base_containers`: the containers whose `base` was retaken.
- `record`: `present`, `missing` or `unreadable`. `seconds`: the seconds of each part.

It may also hold `host`: `converged`, `kept` or `skipped_idmap_small` ([Instance host in the fleet](#instance-host-in-the-fleet)). This field is optional. A run of an older Ansible names no `host`, so the field is absent and the other fields are recorded as before. A word not these is left out the same way.

The seed is taken while the inner fleet runs, also after a retaken `base`. The inner storage pool is a directory pool on the outer container's disk, one ZFS dataset. So the seed is one point in time of all three containers and the inner Incus database, the state a power cut leaves. The nested Gateway's database is SQLite, which recovers from a power cut. A copy boots its nested containers cold, as a copy of a stopped seed does.

The first seed of every run, the `seed` phase of "Prepare the container", was always taken with the fleet running. The nested proof Node's Instance pool is btrfs, so the Instances in a seed are subvolumes, and a copy of one there is a snapshot, not a file copy.

After a restore, the run starts the restored fleet and waits for readiness. If that start fails, the detail says so too.
| An update, not a rebuild | The image variant's tools change only with a new image |
| The next release waits | A release waits up to 600 seconds for open `instance.deploy` Operations before it loads Swamp, then fails with `gateway.release_busy`. Automatic release waits for refresh and seed distribution before admitting the next release |

It replaces the removed task VM refresh and its kind `task_vm.image_refresh`. [Old paths](/reference/old-paths) tracks the remaining fleet residue, not another refresh runtime. A Project has one default Instance in the whole fleet today, so the refresh runs on the Node that holds it.

What stops during a refresh:

- The outer container keeps running. Its Processes stop only for the seed snapshot: under 1 s on ZFS, plus their stop and start.
- The inner fleet runs from its start. A nested container stops only for its own `base`.
- A container stopped for `base` starts again inside the refresh, before the last seed.
- The fleet runs through the seed.
- A clone starts its own copy.
- The run's first seed stops the Processes as briefly as the last one.
- After a failure, the outer container stops for the restore (at most 30 s for the stop) and starts again.

| Step | Time | Source |
| --- | --- | --- |
| The fleet check (running fleet: rules, link, tunnel) | About 6 s for the separate start, measured | Operation 19765 |
| Apply the checkout: delivery, grants, migrate, restart | 9 s, measured | The first build by hand |
| Converge of the three nested Nodes | Not measured in a container | |
| Readiness after the converge | 16 s, measured | The first build by hand |
| Retake `base` (stop and three snapshots) | 94 s, measured | The second experiment |
| Seed snapshot of the outer container | Under 1 s on ZFS, measured | The ADR 0250 spike |
| Start the stopped fleet to a healthy Doctor | 31 to 55 s, measured | The second inner fleet experiment on a Node |
| A whole refresh that converges and retakes `base` | 6 min 32 s to 6 min 38 s, measured | Operations 20079 and 20121 |
| A whole refresh whose converges change nothing (`base` kept) | About 3 min 55 s, estimated | Operation 20121 minus stop, `base` and start |
| A whole refresh that changes neither `apps/gateway` nor `swamp` | About 2 min 20 s, estimated | Operation 20121 minus the converges too |

Each step has a bound, the sum of the bounds of the phases it runs. So a normal default's step ends no later than its phases did as steps of their own, though one phase may now use the time another left:

| Step | Bound | Phases |
| --- | --- | --- |
| Prepare the container | 520 s for a normal default, 1,040 s for a nested-fleet default | `start` 120 s, the check (the run's one fleet start) 400 s, the first seed 120 s, `fetch` 400 s |
| Finish the container | 300 s for a normal default, 4,500 s for a nested-fleet default | `processes` 120 s, the refresh 4,200 s (with the start of what it stopped for `base`), the seed 120 s, `check` 60 s |
| Restore the old seed | 150 s | `fleet_restore` |
| Start on the old seed | 120 s | `start` |
| Start the restored fleet | 600 s | `fleet_resume` |

The run budget grows by the fleet's part of these bounds, 5,590 s: the check 400 s, the first seed 120 s, the refresh 4,200 s and the three restore steps 870 s. A normal default's run budget stays the deploy step timeouts plus 900 s.

Inside the refresh, the agent wait and the Doctor wait are bounded by 300 s each, and each Node converge by 900 s.

#### The seed on each CI Node

This is the target design of ADR 0260, build step 4. Built, not yet proven on the live fleet: the listener of the `ci-runner` role, the `instance.seed_copy` run and its triggers, the record of each Node's seed, and the start of a copy from its own Node's seed. ADR 0260 build step 5, in work, places proof copies on CI Nodes ([proof:run](/cli/proof/run#the-run)).

Each Node that [offers proof places](/reference/node-provisioning#proof-copies-on-a-ci-node), other than the default's own Node, keeps its own seed of the proof Project's default: a copy of the default's [seed pair](#the-seed-pair) on the Node. A proof copy on that Node starts from it. The default's Node holds the seed itself, and a copy there clones the default, as before. In this section a CI Node is such a Node. No copy fetches a seed over the network when it starts.

The holder of the seed on a CI Node is one stopped container, `proof-seed`, and its volume `proof-seed-inner`, in `orbit-instances-nested`. The holder never starts. It keeps the default's `seed-<commit>` snapshots, and a proof copy on the Node clones `proof-seed/seed-<commit>`.

The Gateway copies the seed with one Operation, `instance.seed_copy`. Its targets are the CI Nodes whose seed is not the default's current seed and whose [seed listener](/reference/node-provisioning#the-seed-listener) is ready for the default's Node.

A CI Node is ready when the Gateway records, from its last `ci-runner` converge, that the listener trusts the default's Node. A CI Node without that record is not a target. So no seed copy fails on it and no seed copy marks it tried. Its next `ci-runner` converge that passes makes it a target. When the default moves to another Node, the CI Nodes stay untargeted until the `ci-runner` role of each is converged again. The [seed copy chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/seed-copy.mmd) has the steps. The Gateway admits a seed copy:

- when the default records a new seed: after the [refresh after a release](#refresh-after-a-release), or after an `instance:deploy` of the default;
- when a `ci-runner` add or role converge of a CI Node passes, so a new CI Node does not wait for the next release;
- when the roles phase of a `node:converge` of a CI Node passes, so a Node whose copy failed catches up after any passed converge;
- at the end of a seed copy during which the default's seed moved: for every Node behind the new seed;
- at the end of a seed copy at the same seed: for the Nodes that no seed copy has tried at that seed.

One seed copy takes at most as many targets as its [run budget](/reference/operations) can hold, and it leaves the other Nodes behind the seed untried. So its end admits the next copy for them. A copy that a `ci-runner` add, a role converge or a `node:converge` admits always takes that Node: the Node may have failed a copy at this seed, so the end of another copy would not admit it.

So a seed that moved during a copy reaches every Node. A Node that a seed copy has tried at the current seed is not tried again by the end of any copy. It waits for the next seed or for a passed converge of a CI Node. That converge admits a copy for every Node behind the seed. The Gateway reads the tried Nodes from the targets of the recorded `instance.seed_copy` Operations of that seed. So a lasting failure, such as no free ID range, does not repeat with a new Operation each time.

The automatic release tick advances the recorded fleet, default and seed phases. It resumes the existing Operations after a restart and verifies the default seed commit before it checks CI Node copies. An admission blocked by active work stays pending. A failed refresh Operation or temporary source-read failure retries after ten minutes, up to three attempts for the release commit.

The tick recovers a missing seed-copy admission from the current seed and the recorded attempts. Publication tracks the seed commit and timestamp recorded by refresh. A source that moved fails that publication phase. A Node that failed a copy keeps its recorded seed and follows the existing retry rules above. The release records a terminal failed phase and a newer eligible revision can fix it forward.

A seed is the commit and the time that the default took it, because a retake at the same commit keeps the seed's name. The default's runtime records both (`seed_commit`, `seed_taken_at`). The Gateway records the seed of each CI Node in `proof_seeds`: its holder, its commit and that time (`ProofSeeds`).

##### How the seed moves

Incus moves it, from the default's Node straight to each CI Node over WireGuard. On the default's Node, the run calls, as root, for one target at a time:

- `incus copy <default> proof-node-<id>:proof-seed --refresh --mode=push`, in the project `orbit-instances-nested`;
- `incus storage volume copy` of the default's inner volume to `proof-seed-inner` on the same remote, with `--refresh --mode=push`.

The copies to the targets never overlap. Each push of the inner volume takes a snapshot of it on the default's Node. The volume is mounted in the default, so Incus freezes its file system (`fsfreeze --freeze`) for that snapshot. A second freeze of a frozen file system fails with `Device or resource busy`. So two copies of one source side by side can fail a target.

The certificate reads and the checks run on the CI Nodes, and they still run side by side. Because the copies run one at a time, the run time grows with the number of targets, and the Gateway captures the run budget from it at admission. Each `incus` push stops after 1,200 seconds: a first full copy of the seed pair is a few GB over WireGuard, and a refresh is small.

A retake of the default never runs beside a seed copy: both take [the seed hold](#the-seed-hold). A deployment of the default still runs its other steps beside a copy, because the seed hold is not the default's Instance lock.

An instance copy does not take its attached custom volumes, so the volume has its own copy, first. The holder volume gets the default's `size`, but each snapshot that it receives records `size: 0`; a clone [sets its own size](#the-inner-volume). The container's copy points its `inner-pool` disk at `proof-seed-inner`. Its NIC gets no static address and only the common NIC ACL: the default's address and its own ACL belong to the default's Node, and the holder never starts. A clone sets both for itself.

`--refresh` sends only what changed since the last copy. Incus compares the snapshots on both sides by name and creation time and sends the newer ones as ZFS increments from the newest snapshot that both sides share, so the holder gets the new `seed-<commit>` snapshot natively. The first copy to a Node sends the whole seed pair. The copy reads the default as an instance, not one snapshot of it: a snapshot source carries no snapshots, so `--refresh` could not find a common one and would send the whole pair each time.

So the default keeps the base of the copy: its previous seed pair, renamed `base-<commit>` by the [retake](#a-retake-keeps-the-seed). A plain delete would leave no shared snapshot, and a retake at the same commit gives the new seed the old one's name with a new creation time.

Before the refresh, the run renames the holder's `seed-<commit>` pair to `base-<commit>` when the default holds a `base-` pair of that name. Incus then finds the same name and creation time on both sides and sends only the change since that seed. When the default's base is another seed, or none, the refresh sends the whole pair, as a first copy does. A Node whose seed is older than the base gets the whole pair too: the default keeps only one base.

The base goes when no CI Node needs it. The next retake keeps at most the seed that it replaces. A seed copy can drop the base earlier: when it targeted all the Nodes behind the seed and each target passed, its release step deletes every `base-` pair on the default's Node.

The release step first checks that the default still holds a seed with the name that the copy was admitted for. It compares only the name. A retake to a new commit during the copy gives the seed a new name, so the release step leaves the base of that retake alone. A retake at the same commit keeps the name, so the release step deletes the base that the retake kept. Then the next copy to each target sends the whole pair once, as a first copy does.

A failed release changes no target's result; the next retake deletes the base. The holder mirrors the default, so its next refresh drops the base there too.

Push mode means that the source server sends the data to the target server, and only the target listens on the network (`incus copy --help` of the installed Incus client). So the default's Node gets no Incus listener and no change to its daemon config. Its only change is a client remote of root, `proof-node-<id>`, for each CI Node. The run adds the remote when it is missing. It reads the CI Node's server certificate on that Node first, and fails the target when the certificate that root's client stored for the remote is not that one.

Each CI Node gets an Incus listener on its WireGuard address and one restricted trust entry: root's Incus client certificate of the default's Node, limited to the project `orbit-instances-nested`. The `ci-runner` converge adds both ([Proof copies on a CI Node](/reference/node-provisioning#proof-copies-on-a-ci-node)), and the Gateway records on the Node which Node the listener trusts. So a seed copy can change only that project on a CI Node, and only from the default's Node.

Each target's step may fail alone. It does not stop the other targets. A Node whose copy failed keeps its recorded seed. That seed is not the default's current seed, so the Node offers no proof places. What the failed copy left in the holder does not matter: the next copy refreshes the holder again. Copies made earlier keep running, because ZFS keeps the blocks they share with a changed holder.

A retake of the default during a copy does not wait for it. When the retake deletes a snapshot that the copy reads, that target fails. When the copy ends first, it records the seed it was admitted for; that seed is not the default's new seed, so the end of the copy admits the next one.

Why `incus copy --refresh`, once per seed and Node:

- One refresh makes one fleet. Every CI Node gets the same seed, so the shards of one proof run on the same fleet.
- It reuses the refresh. A seed Instance on each CI Node would refresh its own fleet at every release. That repeats the work on every Node, and the fleets can drift apart.
- A seed Instance on a new CI Node would also need a first build: a [rebuild from stock](#rebuild-from-stock), which is not built.
- Incus does the incremental send, the snapshots and the records of the holder. Orbit adds no ZFS stream, bookmark or pin of its own.
- The data goes from Node to Node. It does not pass the Swamp worker, which is on another host and is short of CPU.
- It moves the seed once per seed and Node, not at each copy's start, which ADR 0260 rejects.

The cost is one trust between Nodes: each CI Node trusts the default's Node for one Incus project. The listener answers only on the WireGuard address, and the firewall admits its port only from the default's Node.

A proof copy on a CI Node is a clone with the same [clone step](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/create.mmd). Its intent names the holder, its snapshot and its volume as the source, in place of the default's container and volume (`TaskStart::seed`).

A copy on a Node whose seed is not the default's current seed is refused with `tasks.copy_source_unavailable`. A task copy still goes to the default's Node and clones the default. A proof copy goes to a free [CI place](/cli/proof/run#the-run) on any Node that offers proof places. The fleet start, the Club key and the [reset](#reset) are the same as for a copy on the default's Node. The copy takes the default's [ID map size](#the-id-map-size). The holder does not need to run, as the default does not need to run for a copy on its own Node.

The copy runs after the release, and nothing in a release or a proof waits for it.

| Rule | What happens |
| --- | --- |
| Never blocks the release | The default's run has ended when the Gateway admits a seed copy. A failed copy changes neither the release nor the default |
| No proof places on an old seed | While a Node's seed is not the default's current seed, the Node offers no proof places. So one proof never mixes the fleets of two seeds. Earlier copies keep running |
| A failed target | The step fails with `instance.seed_copy_failed`, and the result names the failed command and its error output for that Node. The Node keeps its recorded seed |
| An unreachable Node | The phase that could not reach the Node fails that target, and the result holds the SSH error. Later phases do nothing for the target |
| A Node that goes away during the certificate or the copy phase | The next phase fails the target as `The certificate phase ended without a result`, or `The copy phase …`, and does nothing else for it |
| One copy at a time | An open seed copy holds the default's [seed hold](#the-seed-hold). A second admission refuses with `operation.target_busy`; the end of the open copy admits the next one |
| No copy during a retake | While a deployment of the default holds the seed, the admission refuses with `instance.seed_held`; the deployment's end admits the copy again |
| No listener yet | A CI Node whose converge has not added the listener and the trust fails its target. A `ci-runner` converge of the Node adds them, and its end admits the next copy |
| ID ranges on a CI Node | The holder and the snapshot it receives need a free range of the default's size. The [CI Node's demand](/reference/node-settings#a-ci-node) counts them. Without one, that target fails |
| A failure shows | Nothing waits for the copy. So each failed target also writes the Activity row `instance.seed_copy_failed` with the Node, the Operation and the detail, as a failed refresh writes `instance.refresh_failed` |
| A rerun is safe | The next seed, or a passed converge of a CI Node, copies again. `--refresh` makes the holder equal to the default, whatever an earlier run left |
| One base at a time | The default keeps at most one older seed pair, `base-<commit>`. It keeps it only while a ready CI Node needs it |

### A clone starts its fleet

A new Instance or a task of the nested-fleet Project is a ZFS clone of the default's seed, as every [clone](#create) is. The clone then starts the outer container and places the Club key. Its step `clone-fleet` runs the [fleet step](#the-fleet-step)'s check and resume. It waits for the inner Incus and checks the fleet rules. It starts the nested containers and the operator tunnel `wg-nested`. Then it waits for the same Doctor check that a proof uses: the nested Gateway is healthy and its three agents are online.

The step can run for minutes, and Swamp fails a step that waits 60 s for a name another run holds. So the step takes one of 64 names per Node, `ic-clone-fleet-<node>-<instance id % 64>`. Copies started at once get near ids and start their fleets side by side.

Only then do the workspace phase and T3 run. The [create chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/create.mmd) shows the step.

The Gateway gives the create intent its `fleet` only for a clone whose runtime keeps a fleet (`InstancePlacement::keepsFleet`). Every other create has no `fleet` key and skips the step.

The clone's first start drops the copied `orbit-*` boot links, so a tunnel unit in the seed does not start in the clone. The step makes the tunnel.

A failed step fails the create with `instance.container_fleet_drift`, `instance.container_fleet_check_failed` or `instance.container_fleet_resume_failed`. `error.detail` names the failed check, with the Doctor body for a readiness wait. The container is kept, as for every failed clone.

A clone keeps the fleet of the seed it copied. A later refresh does not change it.

The workspace phase runs after the fleet is ready. When it checks out a branch ahead of the seed's commit, the nested Gateway still runs the seed's schema; the clone migrates nothing.

| Step | Time | Source |
| --- | --- | --- |
| ZFS clone of the seed | Under 1 s, measured | The ADR 0250 spike |
| Create to the copy's start | 14 s, measured | Operation 20109 |
| Nested containers running after the start, by autostart alone | 34 to 64 s, measured (stopped at 34 s, running at 64 s) | Operation 20109 |
| Inner Doctor healthy, read inside the nested Gateway | 88 s after admission, measured | Operation 20109, without the fleet step |
| Fleet start to a healthy Doctor | 31 to 55 s, measured; 54 to 90 s cold, before the fleet rules were in `base` | The first and second inner fleet experiments |
| Whole create of a nested-fleet clone with the fleet step | Not measured | |

### A stopped nested-fleet clone starts

A sleep freezes a nested-fleet clone, and a wake resumes it with its fleet ([Sleep and wake](#sleep-and-wake)).

Its container does not start at boot (`boot.autostart=false`). A host reboot, an out-of-memory kill or a stop by hand leaves it stopped. A start by hand with `incus start` gives it no Club key and no tunnel `wg-nested`. So [`instance:wake`](/cli/instance/wake) of such a clone starts an `instance.start` Operation instead of a wake: when its last sleep did not freeze it, or a wake since found it stopped (`instance.nested_fleet_stopped`). A start of a clone that a sleep froze resumes it, closes swap and removes the asleep marker. The [start chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/start.mmd) has the steps:

The Gateway admits the start only for an active development clone whose runtime keeps a fleet (`InstancePlacement::keepsFleet`). The request's Idempotency-Key keys the start, and a second call joins the open start.

The run reads the Club key once, at its start. Without a key the fleet's Swamp cannot start, and Doctor cannot pass. So the run then fails before any change, with `instance.nested_club_key_missing`.

The step `Start the container` runs the `start` part of `instance-container.yml`, as a deployment's `Prepare the container` does. It keeps `boot.autostart=false` and `limits.memory.swap` equal to the memory, and checks the egress gates of every bridge NIC. Then it starts the container and delivers the [Club key](#the-club-key-of-the-nested-gateway) into its `/run`.

The step `Start the inner fleet` runs the [fleet step](#the-fleet-step)'s check and resume, as the clone's create does. It checks the fleet rules and starts the nested containers and the tunnel. Then it waits for Doctor. It records its time as `fleet_seconds`.

A success records `power_state` `running`. The result also gives `nested_fleet_club_key`.

Each step leaves what already runs as it is, so a start of a running copy only checks it and writes its proxy bypass. The start does not wait for T3: T3 starts with the container, as at every boot.

Its fleet step also writes the copy's [proxy bypass](#proxy-settings-in-a-container) for units and login shells. T3 restarts once so that it reads the value, but only when the write changed the drop-ins or the manager environment and this start booted the container. Incus records each start as `last_used_at`. A copy that was already running keeps its T3, because someone may work in it.

A stopped nested-fleet default does not take this path: a wake refuses it with `instance.sleep_unsupported`, and only its deployment starts it. A frozen one wakes by resume, like a clone. The run budget is 1,200 seconds; a clone's fleet start took 91 seconds (Operation 20180).

### Reset

A reset stops the three nested containers in one `incus stop --force` and restores `base` of each stopped container. It starts them and waits for the nested Gateway's state mounts. It migrates the nested Gateway to its checkout, and restarts its services when a migration applied. Then it waits for the same Doctor check. Its waits poll every second; the refresh's poll every 5 seconds.

Its one caller is the [proof of a task copy](/cli/proof/proof#the-run): Swamp runs the phase `fleet_reset` of `instance-container.yml` from the copy's Node, before every batch, the first included, and not after the last. The Gateway admits it only for a clone that keeps its nested fleet. A Project's default Instance is refused with `proof.copy_fleet_missing`, so its fleet, the seed of every copy, is never reset. The play refuses it too, before any command and also when run by hand: an intent whose container is not a clone fails with `instance.fleet_reset_not_clone`.

A restore of a running container stops it and boots it again, so the reset stops the three first. On the [inner volume](#the-inner-volume), measured by hand in a task copy on 2026-11-14:

| Part | Seconds |
| --- | --- |
| Stop the three at once | 1.75 |
| Restore a stopped container | 0.9 each; `nested-gateway` 2.5 |
| Start the three in one call, after the rules check | 0.44 |
| The whole reset, with readiness | 15.9 |
| The whole reset before these cuts: running restores one by one (9.6 s), polls every 5 s | 30.7 |

On a directory pool a restore copies back only the files that changed since `base`, so its time grows with the change: 7 to 8 s for all three, Doctor healthy 33 s after. Each restore has 45 seconds.

#### No stale network pid file

An Incus Node keeps the pid file of each managed bridge's dnsmasq on its disk, at `/var/lib/incus/networks/<network>/dnsmasq.pid`. At its next start, Incus reads the file to stop the old dnsmasq. A file that is not valid YAML stops the network: an empty file fails with `Could not read pid file`. On `nested-app-dev` that stops `orbitinst0`, and every `instance.create` there fails at its `container` step.

Two copies can hold such a file. The first is the **copied seed**. A task copy starts from a copy of the running default Instance container, which holds the live disks of its nested containers. When the pid file's last write had not reached the disk, the copy holds it empty, and the guest's autostart boots with it.

The second is the **inner `base`**. A clean stop keeps the pid file. A `base` taken from a disk with an empty file holds it too, and each reset brings it back. A `base` of the [running fleet](#how-base-is-taken) holds the live file of the running dnsmasq; after a restore no dnsmasq owns it.

**The fleet start heals each nested Instance host.** After it starts the nested containers, the [fleet start](#the-fleet-step) runs `swamp/ansible/files/incus-network-pid-heal.py` in each nested Node marked as an Instance host, whether it ran already or just started. The run places the heal at `/usr/local/sbin/incus-network-pid-heal`, with the drop-in `incus.service.d/60-network-pid-heal.conf` that runs it as `ExecStartPre` before every later start of Incus.

The run heals at once, and it restarts that Incus when it removed a file. So a guest of a copied seed or an older `base` that booted with an empty file and no heal gets its bridge back at the copy's start or resume. The Node converge never places it, so no real Node's release inputs change.

The heal keeps a pid file only when every check holds:

- The file is a regular file, not a link, and reads in full.
- It parses as YAML into the record Incus writes: `name: dnsmasq`, an integer `pid`, an integer `start_time` and a `boot_id`.
- Its `boot_id` is the current boot's.
- The process lives, its `comm` is `dnsmasq`, and its start time equals `start_time`. Incus records field 22 of `/proc/<pid>/stat`, in clock ticks.
- Its command line holds `--interface=<network>` as one whole argument.

So a daemon restart keeps the live dnsmasq's file, and every other pid file goes. The heal follows no link: it leaves a linked network folder alone, and it unlinks a linked pid file itself.

**The host clears stopped disks.** `swamp/ansible/instance_fleet_runtime.py` (`tasks/instance-fleet-runtime.yml`) removes each `dnsmasq.pid` from the root disks of stopped nested containers. It reads each container from the inner Incus and clears only the root disk in the pool of its `root` device. Three steps run it:

- The refresh runs it before it takes a `base` of the stopped fleet, so such a `base` holds no pid file. A `base` of the [running fleet](#how-base-is-taken) is not cleared.
- The reset runs it between the restore of `base` and the start. A `base` taken before the heal existed holds neither the heal nor a clean disk, and this clears it before it boots.
- The reset's rescue runs it, best effort, before it starts the containers again. The reset keeps its own error.

A container that is not `Stopped` fails the step: a frozen container keeps its dnsmasq and its file. So does a container whose disk the helper cannot check without following a link, such as a missing root disk or a linked `networks` folder. Then no `base` is taken and the reset does not start the fleet.

A `base` taken after the start placed the heal holds it, so every boot of that `base` heals itself too.

`orbitinst0` serves DHCP only: its `raw.dnsmasq` is `port=0`, so its dnsmasq answers no names. An Instance there reaches names through its egress proxy at the bridge address.

A reset that fails after its stop starts each nested container that ran before it again, one by one and best effort, then fails with the first reason. The proof's batch reports that reason. A failed restore can leave a container on its old disk or on `base`, so the fleet runs but its state is unknown. To recover, run `orbit proof` again: it resets first. When a container has lost its `base` snapshot, the reset cannot succeed; start a new task copy.

### Rebuild from stock

A rebuild makes the three nested containers again from stock Ubuntu in the default Instance, as the [fleet build of a task VM](/reference/compute-drivers#build-the-nested-fleet-in-the-converge) does, applies the [fleet rules](#fleet-rules) and takes `base`. It is a rare operator action: for a new Node, or a fleet that a refresh cannot repair. The release refresh never rebuilds. How an operator starts it is planned.

### Fleet capacity

The numbers below size the places of a Node for nested-fleet clones.

| Per running nested-fleet clone | Value | Source |
| --- | --- | --- |
| Memory, idle, Swamp down | 1.9 GiB, measured | First experiment |
| Memory, Swamp up, after two proof cases | 2.37 GiB, peak 2.72 GiB, measured | Second experiment |
| Memory under a full proof batch | Not measured | |
| Memory limits | 12 GiB outer; 4, 3 and 3 GiB nested | |
| Disk of the default with its fleet | 12.2 GiB on ZFS, measured | First experiment |
| Disk written by one clone | Not measured | |

A stopped clone uses no memory, only the disk it changed.

Gap: which refusal comes first on a full Node for nested-fleet clones, and its error code. Both come with the task places.

### The fleet step

Six phase task files keep the fleet of a nested-fleet Instance. The deployment of a nested-fleet default calls the check, the start, the refresh and the resume through the tags `fleet_check`, `fleet_start`, `fleet_refresh` and `fleet_resume` of `instance-container.yml`. The create of its clone, and the [start of a stopped clone](#a-stopped-nested-fleet-clone-starts), call the check and the resume ([A clone starts its fleet](#a-clone-starts-its-fleet)). The proof of a task copy calls the reset through the tag `fleet_reset` ([Reset](#reset)). No flow calls the rules alone yet.

A [`proof:run`](/cli/proof/run#the-run) in a copy first runs the phase `proof_checkout` (`tasks/instance-proof-checkout.yml`). As `agent`, the workspace's owner, it fetches the commit under proof by SHA from the public Orbit repository with the deploy commands' environment, 60 s and no retry, and checks it out detached. It then names the development deploy steps whose inputs did not change, as a deployment's checkout does.

Each phase runs on the Node and reaches the inner Incus with `incus exec` into the Instance container. Their input is `intent.container` and `intent.fleet`; the header of `swamp/ansible/tasks/instance-fleet.yml` defines it. The Gateway fills `intent.fleet` (`NestedFleetIntent`) from the fleet constants of task VMs, with the commit of the old seed, for the deployment of the default and the create of its clone.

| Phase | Task file | What it does |
| --- | --- | --- |
| Check | `instance-fleet-check.yml` | Waits for the inner Incus and compares the fleet with its rules. It changes nothing |
| Rules | `instance-fleet-rules.yml` | Applies the fleet rules below to the stopped fleet, then checks them |
| Start | `instance-fleet-start.yml` | Checks the rules, starts the stopped nested containers, brings up `wg-nested` and gives it the fleet's DNS. Then checks the containers and the tunnel (`instance-fleet-reach.yml`), never Doctor. A stopped clone's start adds `instance-fleet-proxy.yml` |
| Refresh | `instance-fleet-refresh.yml` | Migrates and restarts the nested Gateway, converges the three nested Nodes, waits for readiness (`instance-fleet-ready.yml`), stops the fleet and takes `base` again |
| Resume | `instance-fleet-resume.yml` | Runs the start, then waits for readiness: the fleet after a passed refresh, or the fleet of a restored seed |
| Reset | `instance-fleet-reset.yml` | Stops the three nested containers at once and restores `base` on each. Then it runs the start, migrates the nested Gateway forward and waits for readiness |

Fleet start first ensures the managed user's Incus socket access on its Instance hosts ([Instance host in the fleet](#instance-host-in-the-fleet)). It then reads the nested Gateway's Artisan command list as `orbit`. When `gateway:known-hosts:sync` is present, it runs the command as `orbit`. An older checkout records `command_unavailable` and continues: fleet start runs before deployment moves the shared checkout. Refresh repeats the check and synchronization after migration on the new checkout, before readiness and `base`, even when the code digest is unchanged. A command-list read failure or an available sync command that fails still fails the phase.

The internal command restores the Gateway's SSH `known_hosts` from Node records that pin a WireGuard address, host-key type, key and fingerprint. It scans no host, retains unrelated entries and reports `{"changed": true}` only when it repairs the file. A file is current only when its pins match, its mode is `0600` and its directory mode is `0700`. Permission repairs also report a change. This supplies direct Gateway reads in fleets whose Nodes bypassed enrollment.

The fleet rules:

- The disk device `checkout` gives the workspace to all three nested containers with `shift=false`. An ID shift fails two levels below the host.
- The disk device `club-key` gives the folder `/run/orbit-club` of the container to the nested Gateway at `/home/orbit/.config/swamp/club`, with `shift=false` and `required=false`.
- In the nested Gateway, `/home/orbit/.config/swamp/api-key` links to `club/swamp-club-api-key`. `base` keeps the link.
- `raw.idmap` of the inner Incus maps `orbit` (1000), `agent` (2750) and the group `work` (2760) into all three nested containers. The nested `orbit` and the container's `orbit` own the workspace together.
- The AppArmor-off device is on the nested `app-dev` and `app-prod`.
- `boot.autostart=true` is on the three nested containers.
- The egress proxy settings are `environment.*` keys on the three nested containers. The nested Gateway's Swamp, Horizon and PHP-FPM get them from their [fleet rule files](#fleet-rules).
- The egress files are on the three nested containers: the apt proxy file `/etc/apt/apt.conf.d/95orbit-nested-egress`, `/etc/environment` with the same proxy settings, and Docker's drop-in `/etc/systemd/system/docker.service.d/50-inner-fleet-proxy.conf`.

The refresh writes an egress file that differs on the running fleet, before the converge of the nested Nodes. The rules then find it in place.

Orbit owns every rule except `raw.idmap`: the refresh writes an owned rule that differs (a key or device while the fleet is stopped, a file before the converge), and the check and the start let it pass. `raw.idmap` is a build rule, because a new map remaps a container's disk. It is written only where the key is absent.

A fleet whose `raw.idmap` holds another value, or a nested container or the inner bridge that cannot be read, is refused with `instance.container_fleet_drift` before any change; a standalone check, start or proof reset also refuses a fleet that lacks `raw.idmap`. The deployment’s initial start permits an absent build rule because its later refresh writes it with the fleet stopped and retakes `base`. A conflicting value still refuses before any change. This lets an older fleet acquire a newly required map through its normal deployment.

If the deployment fails, the resume of the restored seed permits absent build rules too. It starts the fleet that Prepare admitted, without changing its maps. The next deployment installs the missing rules. Conflicting maps still refuse on rollback.

The Operation's result `fleet_drift` names each difference, as `<container> <kind> <name>`; for the inner bridge the first part is the bridge, as in `oe-nested network raw.dnsmasq`.

The deployment runs the check right after it starts the container, before it takes its first seed. A refusal there changed nothing, so no seed is restored. A failure from the checkout on, such as a start that fails after a change, restores the seed.

A restore of `base` reverts devices and configuration keys. So the rules must be in `base`. The start lets a missing owned rule pass, because the refresh after it writes the rule. The reset ends with the same start and has no refresh after it.

Readiness is the proof's own check, from the container over `wg-nested`: every nested container runs, Doctor of the nested Gateway is healthy within a bounded wait, the three agents are online and the Gateway knows the operator with its key. A failure names the nested container or the check. A failed Doctor wait names what its last poll got: the HTTP status and the first 300 characters of the body, or curl's error when no response came.

The start gives `wg-nested` the nested Gateway's resolver, `10.45.0.1`, and the routing domains `~nested-dev` and `~nested-prod`, the TLDs of the fleet's `app-dev` and `app-prod` Nodes, with `resolvectl dns` and `resolvectl domain`. So the operator in the container reaches a site of the fleet by name, such as `https://webtest.nested-dev`. It never routes `~orbit` there: the container keeps its own `gateway.orbit`. The start reads the link first and sets only what differs, so a second start changes nothing. The settings do not outlive the tunnel; the next start sets them again. `NestedFleetLayout` holds the addresses and TLDs; the intent carries them in `intent.fleet.operator`.

A read right after a start waits for what the nested container sets up at boot, at most 60 s, and accepts only a ready value. The start takes the nested Gateway's hub key only when it is a WireGuard key: `wg-quick` makes the interface before it sets the key, and in between `wg show orbit public-key` prints `(none)` with exit 0. With no key in time, the start fails with "the nested Gateway's WireGuard key was not ready after 60 s" and sets no peer.

The refresh waits in the same way until the state mounts of the nested Gateway and `app-dev` are active, with the nested Gateway's Swamp state mounts when the Club key is there.

Every API call of the fleet step passes `--noproxy '*'`. The Instance container's environment sends every address but the egress proxy's own through that proxy, which cannot reach the tunnel; it answers `CONNECT tunnel failed, response 503`.

The container's app account `www-data` is in `work` too, so it opens the checkout beside them. A command that the fleet step runs as the app user in a nested container, the agent delivery and the migrate, runs with `runuser -u orbit`. That gives `orbit` its groups, among them `work`, which opens the checkout. The guests are Nodes, so their app user keeps the name `orbit`, and the container keeps `orbit` and `work` too. `incus exec --user 1000` gives only the user ID, with group 0.

The fleet step never reads, prints or copies the Club key file. Without the file, the fleet starts and is ready; only Swamp in the nested Gateway stays down.

## Git in and out

A container holds no GitHub credentials. It can read public repositories through the [egress proxy](#egress-and-dns), but it cannot push. Orbit moves the Instance's branches from the Node: it fetches with a minted read token and pushes the branch into the container's repository as a Git bundle.

The bundle holds only what the workspace lacks: its prerequisite is the newest commit the branch shares with the workspace's HEAD, or the tip's parent when the workspace already holds the tip. A workspace whose HEAD the mirror does not hold, or that has none, gets the whole branch. On the Orbit Project the whole branch was 89 MB and took 4 s to bundle; a release's bundle is a few kilobytes. Publishing a branch is an Orbit operation: Swamp reads the branch out of the container and pushes it with a minted write token. The tokens never enter the container.

## Production Instances

Step 10 of ADR 0250 moves production Instances into containers on `app-prod` Nodes, after development containers work. The decided basics:

- The `app-prod` role installs the container setup and the runtime folder, not PHP-FPM. It installs no agent folder.
- A production container has only the user `www-data`: no T3, no agent and no model route.
- A production container gets no `php-cli.ini`, so its PHP never loads PCOV ([ADR 0264](/decisions/0264-build-container-php-on-glibc-with-a-cli-only-pcov)).
- FrankenPHP serves it once it has proved itself in development, by [ADR 0263's production gate](/decisions/0263-run-production-releases-in-hot-live-and-old-containers#production-gate).
- Schedules stay native Swamp schedules that run their command in the container as `www-data`; the per-minute Laravel scheduler runs inside it.
- Planned ([ADR 0265](/decisions/0265-sleep-idle-production-containers)): an idle live sleeps and wakes fully ready by the rule of [Sleep and wake](#sleep-and-wake). [Production containers](/reference/production-containers#sleep-and-wake) has the details.
- Existing production checkouts are migrated, as development checkouts are.

The release flow is planned in [Production containers](/reference/production-containers) ([ADR 0263](/decisions/0263-run-production-releases-in-hot-live-and-old-containers)): hot builds each new commit as a clone of live's release snapshot, live serves, and old stays stopped for rollback.

## Records

The create flow of the [default Instance](#the-default-instance) writes the runtime row; its create and each deployment record the seed commit.

Each Node records its Instance bridge, as [Nodes](#nodes) describes. Each container Instance has one runtime row, `instance_runtimes`. A checkout Instance has none.

| Field | Meaning |
| --- | --- |
| `incus_project`, `container_name` | The container in Incus on the Instance's Node |
| `bridge_address` | The container's address on its Node's bridge. It is a guest address: never the network, broadcast or first usable address, which the bridge keeps |
| `t3_port` | The port of the Node's WireGuard address that publishes T3, from 3800 to 3999. The default Instance has none |
| `container_server` | The [container server](#container-server) the container runs, `t3` or `conn`: `conn` for a task copy, else its Project's setting at the reservation. Null exactly when `t3_port` is null |
| `runtime_version`, `agent_version` | The versions of the shared folders the container started with |
| `operation_id` | The migrate Operation that owns this reservation. A new retry takes ownership before its run starts |
| `clone_source_instance_id` | The default Instance it was cloned from. Null for the default Instance and for a migrated checkout |
| `power_state` | `running` or `stopped`. A sleeping container is stopped. The terminal results of create, deploy, migrate, sleep and wake write it |
| `seed_commit` | A default Instance's commit of its newest `seed-<commit>` snapshot, which clones copy. Null before its first seed |
| `seed_taken_at` | When the default took that seed. With `seed_commit` it names one seed, because a retake at the same commit keeps the name |
| `agent_key_hash` | The SHA-256 hash of a task copy's [copy key](#the-copy-key). Null for every other container, and for a task copy made before the copy key existed |

The database keeps these rules:

- A Node's container, bridge address and T3 port each belong to one runtime.
- A runtime keeps its Node, container, address, port, container server and clone source.
- A runtime changes only its versions, its power state and its copy key hash.
- A runtime records its container server, `t3` or `conn`, exactly when it has a T3 port.
- A clone source is the Instance named `default` of the same Project on the same Node, and runs in a container.
- A default with clones keeps its runtime and its name.
- A container Instance keeps its Node, Project and name.
- An Instance with a runtime, a clone or a Conn environment cannot be deleted. The destroy flow removes those records first.

The T3 server's record in the Conn layer names its Instance. Only the Instance's create flow registers that server; a Node that registers it itself is refused ([Registration](/reference/conn#registration)).

## Isolated ID ranges

Each container takes an isolated ID map from root's one subordinate range in `/etc/subuid` and `/etc/subgid`: 65,536 IDs for an Instance container, and the [recorded size](#the-id-map-size) for a nested-fleet container, 101,000,000 or 1,165,536. A stopped container keeps its range. Incus places a new range in the first gap that fits, from 65,536 IDs past root's base, and a new snapshot needs one for a moment.

On a host with 1,000,000,000 subordinate IDs, nine nested-fleet ranges of 101,000,000 fit, and about 850 of 1,165,536. A small range can sit in a gap a destroyed copy left, so only the bases that Incus chose say which gaps are free.

### The check at a task start

A [task copy](/cli/task/start) start reads them. One bounded SSH call (at most about 4 s) runs a fixed reader on the default's Node. The reader reads root's range and the ID map keys of every container in every Incus project. The Gateway then places ranges as Incus 7.0.2 does (`IsolatedIdRanges`). A container that an open create admitted but has not made yet counts as held.

The copy needs one range of its default's size. With the copy placed, a range must stay free for the next seed of this default and of every other default on the Node, so a small copy never fills the last nested-sized gap. Otherwise the start refuses with `tasks.copy_id_range_full`, before any record. A runtime whose container is not on the host counts only while its create is open. An unreadable host refuses with `tasks.copy_id_ranges_unreadable`: the Gateway cannot show a free range. `ORBIT_TASKS_COPIES_MAX` stays an upper bound for nested-fleet copies that an operator may lower.

However, two starts within the same moment can still both pass. Then the later create or seed fails at Incus. The seed step keeps the old seed and names the cause, so the default stays copyable.

### The Doctor warning

Each read is kept on the Node: how many full-size ranges of 101,000,000 are free (up to 2), how many normal containers still fit before one would land in a full-size gap and split it (up to 10), and when it was read. Doctor reads it without reaching the Node.

While the Node records a nested-fleet default of 101,000,000, it warns with `node.id_ranges_low` when fewer than 2 full-size ranges or fewer than 4 normal slots are free. The warning names the read's time, so an old read shows. Copies keep their size but retake no seed, so a Node whose nested defaults are all converted does not warn, even while old copies of 101,000,000 remain. Only a task copy start refreshes the read, so the warning can stay until the next one.

## Errors

The container runtime adds these codes. The other codes of create and destroy stay as they are.

| Code | HTTP | Cause |
| --- | --- | --- |
| `instance.default_missing` | 409 | `instance:create` of a non-default Instance, or `task:start` in a clone, while the Project has no active default Instance on the Node. Also when the Project's default runs on another Node |
| `instance.default_has_clones` | 409 | `instance:destroy` of a default Instance that other Instances were cloned from |
| `instance.node_ci_only` | 409 | `instance:create` on a [CI-only Node](/reference/node-provisioning#proof-copies-on-a-ci-node): `ci-runner`, CI places and no `app-dev`. Only a proof copy is made there. Nothing is recorded |
| `instance.seed_copy_failed` | 502 | A [seed copy](#the-seed-on-each-ci-node) could not copy the seed to a CI Node. That Node keeps its old seed and offers no proof places |
| `project.container_server_unavailable` | 409 | `instance:create`, `instance:migrate` or `task:start` of a container with a server, while the recipe's agent folder does not pin the program of the [container server](#container-server) it would record. Nothing is recorded |
| `projects.agent_instructions_too_large` | 422 | `instance:create` or `task:start` of a clone while the Project's [agent instructions](#agent-instructions) hold more than 32 files or 32 KiB. Nothing is recorded |
| `projects.agent_instructions_invalid` | 422 | `instance:create` or `task:start` of a clone while an agent instruction file breaks a rule of [What counts](#what-counts). Nothing is recorded |
| `instance.migrate_unsupported` | 409 | `instance:migrate` of an Instance that is not an active development checkout on an app-dev Node |
| `instance.migrate_retirement_blocked` | 409 | Owner dependencies, registered descendants, changed Git identity or submodule changes block retirement. Names 20 paths and total counts |
| `instance.migrate_git_filter_unsupported` | 409 | A tracked path uses a clean/smudge filter; use an unfiltered checkout without changing source data |
| `instance.migrate_git_intent_to_add` | 409 | Stage the intent-to-add contents or remove those index entries before retrying |
| `instance.migrate_git_state_mismatch` | 409 | The copied Git state or any carried file hash differs; restore keeps the source intact |
| `instance.migrate_git_objects_incomplete` | 409 | Git object verification failed; repair the source objects before retrying |
| `instance.migrate_git_source_changed` | 409 | The source changed after export; source data stays intact, and a retirement refusal leaves the container serving |
| `instance.migrate_branch_changed` | 409 | The checkout is not on the branch that Orbit records for the Instance |
| `instance.database_unreachable` | 502 | The new container of a move could not reach the Instance's database server |
| `instance.migrate_process_binary_unavailable` | 409 | A moved Process needs an unavailable PHP version or executable path. The error names the Process and path; the checkout keeps serving |
| `instance.migrate_<step>_failed` | 502 | A step of a move failed, such as `instance.migrate_workspace_failed`. The checkout serves as before |
| `instance.turn_running` | 409 | `instance:destroy` without `--force` while a T3 turn runs in the container |
| `instance.t3_unready` | 502 | T3 did not answer within 30 seconds of a clone's first start or a wake, or its CLI failed in a clone's T3 step. After a wake, the app serves |
| `instance.web_unready` | 502 | FrankenPHP gave no answer within 30 seconds of a wake. The asleep marker stays |
| `instance.clone_in_flight` | 409 | The refresh of a nested-fleet default while the create of a clone of it is open. The next release tries again |
| `instance.fleet_key_on_disk` | 502 | The seed of a nested-fleet default found the Club key as a file on the container's disk. The run's first seed refuses before it changes anything |
| `instance.fleet_seed_missing` | 502 | A failed refresh found no seed to restore, or the [inner volume](#the-inner-volume) lacks the seed's half. Nothing is restored, and the default stays as the run left it |
| `instance.container_fleet_no_room` | 502 | A refresh would end under the inner Doctor's disk floor (see [Refresh after a release](#refresh-after-a-release)). The detail names the numbers. Nothing changed |
| `instance.container_fleet_drift` | 502 | The inner fleet of a nested-fleet default differs from its rules where Orbit does not write, such as `raw.idmap`. The Operation's result `fleet_drift` names each difference. Nothing changed |
| `instance.default_seed_moved` | 409 | A retake of the default's seed still ran when the copy's wait of 120 seconds ended. The step's report names it; run the same command again |
| `instance.default_seed_missing` | 409 | The default has no seed snapshot at all. Deploy the default; its seed step takes one |
| `instance.seed_hold_timeout` | 409 | A deployment of the proof default waited 1,800 seconds for [the seed hold](#the-seed-hold) while a seed copy held it. Nothing changed; deploy again |
| `instance.refresh_failed` | none | An Activity row, not an API error: a refresh that a release admitted failed. It names the Operation, its code and its detail; see [Refresh after a release](#refresh-after-a-release) |
| `instance.container_seed_failed` | 502 | The seed step could not take the new snapshot. The error detail names why, such as `No free ID range for the new seed`. The old seed stays; see [A retake keeps the seed](#a-retake-keeps-the-seed) |
| `instance.seed_volume_snapshot_failed` | 502 | The snapshot of the [inner volume](#the-inner-volume) failed after the container's. Both new halves are deleted and the old pair stays; see [The seed pair](#the-seed-pair) |
| `instance.seed_volume_flush_failed` | 502 | The flush of the [inner volume](#the-seed-pair) before its snapshot failed. The new container half is deleted and the old pair stays |
| `instance.default_seed_incomplete` | 409 | The default's seed has no half on its [inner volume](#the-inner-volume), such as a seed taken before the pair existed. Deploy the default once |
| `instance.default_seed_in_flight` | 409 | A clone of a default with an [inner volume](#the-inner-volume) while a deployment of the default is open. Create the clone when it ends |
| `instance.container_process_inactive` | 502 | A required unit is inactive when a clone records its sessions. The Operation names the unit and records its states, exit status and restart count |
| `instance.create_register_failed` | 502 | A clone's registration step failed: T3 refused a session, or the Gateway's lacks its scopes. The step's report names the `conn.*` code |
| `instance.database_clone_unsupported` | 422 | A clone names another Database server than the default's, or the default has no database on a server to clone |
| `conn.environment_asleep` | 409 | A pairing with a sleeping Instance's T3 server |
| `instance.sleep_unsupported` | 409 | `instance:sleep` or `instance:wake` of an Instance that is not a development container, or `instance:wake` of a nested-fleet default that its last sleep did not freeze |
| `instance.nested_fleet_stopped` | 409 | A wake found a nested-fleet container stopped. Nothing booted; the marker stays. A clone's next wake takes its start; a default starts by its deployment |
| `instance.sleep_route_unready` | 409 | `instance:sleep` while Caddy's loaded site of the Instance lacks the asleep check. Nothing changed |
| `node.instance_bridge_exhausted` | 409 | No `/24` of the fleet range is free for a Node's Instance bridge |
| `instance.container_required` | 409 | New host registration or a new non-default whose default is a checkout; create or migrate the container default first |
| `instance.host_unavailable` | 409 | A Node cannot be prepared: it lacks the pool `orbit-instances` or enough subordinate IDs, or the recipe has no pin for it. |
| `instance.nested_root_size_invalid` | 503 | `ORBIT_INSTANCES_NESTED_DEFAULT_ROOT_SIZE` is not a size in GiB of at least 40 GiB (see [The root disk](#the-root-disk)). Nothing changed |
| `instance.nested_fleet_host_unavailable` | 409 | A new nested-fleet Instance on a Node that hosts no nested fleets: its switch is off, and it is no CI-only Node that its `ci-runner` converge prepared ([Nested fleet setting](/reference/projects#nested-fleet-setting)) |
| `instance.nested_fleet_container_required` | 409 | A nested-fleet create retry would use an existing host checkout |
| `instance.nested_fleet_image_unavailable` | 502 | The create of a nested-fleet default while the Node records a failed build of the [nested-fleet image](#the-nested-fleet-image). Orbit makes no container; converge the Node again |
| `instance.ci_hold_unavailable` | 502 | A proof copy's Node has no [hold script](/reference/node-provisioning#proof-places-on-a-ci-node). Orbit makes no container; the detail names the Node. Converge its `ci-runner` role |
| `instance.host_unavailable` | 409 | A container default cannot be placed: the Node has no bridge, the bridge is full, or the runtime folder lacks the Project's PHP build |
| `instance.browser_trust_mismatch` | 409 | The image build found another `libp11-kit0` than the trust module needs. The image is not published ([Trust](#trust)) |
| `instance.clone_browser_trust_mismatch` | 502 | A clone's copy holds another `libp11-kit0` than the trust module needs. The create stops before the copy starts |
| `instance.clone_browser_trust_refused` | 502 | A path of `agent`'s NSS store in a clone's copy has another type than expected, or is a link. The create stops before the copy starts |
| `instance.clone_trust_bundle_refused` | 502 | A clone's copy has no regular `/etc/ssl/certs/ca-certificates.crt`, or the file API could not read it. The create stops before the copy starts |
| `instance.clone_t3_files_refused` | 502 | A folder or JSON file of [T3's files](#t3-starts-with-the-copy) in a clone's copy is a link or of another type. The create stops before the copy starts |
| `instance.clone_idmap_unshift_unsupported` | 502 | A copied rootfs needs its source's ownership shift removed, but its storage pool does not use the `btrfs` driver. The incomplete copy is deleted |
| `instance.clone_idmap_unshift_failed` | 502 | Orbit could not read or normalize a stopped copy's ownership, permissions, ACLs or capabilities before its first start. The incomplete copy is deleted so a retry copies the seed again |
| `instance.clone_private_names_failed` | 502 | A clone's create could not read, render or write `/etc/hosts` right after the copy's first start. The create's failure step stops the copy, and a retry writes it again |
| `deployment.processes_inactive` | 409 | A container default's units were not active at the end of a deployment |
| `process.target_unsupported` | 409 | A container Instance's Process definition is a Docker Process or uses a preset, or a Process change would go to the Node agent |
| `process.target_unavailable` | 503 | The direct container Process journal read failed |
| `instance.bridge_busy` | 502 | Another shared endpoint writer owns the lock; this invocation applied no intent or health change |
| `instance.bridge_command_timeout` | 502 | A Docker/NAT read exhausted the helper’s remaining budget, or an Incus request timed out |
| `instance.bridge_state_unresolved` | 502 | A bounded ACL readback could not determine the result after a timeout or ambiguous write; retry convergence |
| `instance.container_endpoint_unresolved` | 502 | A local Docker endpoint has no unique exact DNAT mapping; the phase refuses before probing or downtime |
| `instance.nic_filters_missing` | 502 | A bridge NIC with its own address lacks `security.ipv4_filtering` or `security.mac_filtering`. Orbit does not start the container; a host converge fails |
| `instance.container_network_unsafe` | 502 | The NIC has no effective ACL or no default egress reject. In the nested-fleet project, also a `raw.*` key, privilege or an unlisted device. Orbit does not start it |
| `instance.nested_club_key_unsafe_transfer` | 502 | A nested-fleet start would send the Club key with pipelining off or remote files kept. Orbit stops before it reads the value |
| `instance.nested_club_key_not_tmpfs` | 502 | The container's `/run` is not a tmpfs, so a snapshot could hold the Club key. Orbit does not write it |
| `instance.nested_club_key_delivery_failed` | 502 | Writing the Club key into the container failed. The error carries no output |
| `instance.container_<phase>_failed` | 502 | A phase of a container default's create or deployment failed without a refusal, such as `instance.container_start_failed` or, for a nested-fleet default, `instance.container_fleet_refresh_failed` |

## Why it works this way

These reasons shape the runtime. ADR 0250 lists the alternatives that were rejected.

### One container, two drivers

A task needs what a development Instance has: the code with its dependencies, a database, a preview and an agent engine. A second container kind would repeat the image, the users, the folders, the model route and the Git path. With one kind, a user can take over a stuck task in Conn, and a task can continue work that a user began.

### The nested fleet is optional

Only Orbit's command proofs need a fleet inside the container. Nesting adds kernel surface, so only containers that need a fleet turn it on, and every other container keeps nesting off.

### T3 inside, not outside

T3's changed-files view, terminals and browser work on its own disk. From outside, each would need a remote layer through every upstream sync. T3 already has what Swamp needs.

### Two users, no wrapper

The boundary that matters is the preview URL, which reaches only `www-data`. A switch for agent commands adds little. The container holds only its Project's `.env`, and its traffic goes through Orbit's egress proxy. Codex also has no documented setting for its shell.

### Clones of a default that never runs T3

A clone of a container that ran T3 serves the same environment id. It also accepts the same sessions, the Gateway's among them. The default Instance is the one clone source, and it never runs T3, so no clone inherits an identity. A clone starts at the default's last successfully deployed seed without setup steps. A merge alone does not refresh that seed.

### Shared folders per Node

One update reaches every container, and the image keeps only what rarely changes. An idmapped mount keeps one gid valid in every container.

### One proxy for all egress

A container gets full outbound access, but only through one proxy on its Node, so a later rule can narrow it in one place. Without the proxy, a container's packets would leave through the Node, and a packet to a fleet address would leave as the Node's WireGuard address, with the Node's access. The table of the proxy's user stops that whatever a name resolves to. DNS also goes through the proxy: a resolver in the container would be a second way out and would send lookups of fleet names to the VPN's DNS.

### Registration from the result

A step may call the Gateway only to sign or to mint. A container that could call the Gateway would act as its Node.

### A clone create is two Operations

Decided on 2026-11-13 by the owner of the create experience. The user needs only the checkout, `.env` and T3 to start work; the Processes, the database fill and a nested fleet can follow. Since 2026-11-14 the create publishes the address beside the copy when its Route Nodes are free ([the address during the create](#the-address-during-the-create)).

One Operation has one run, one budget and one terminal projection ([How an operation runs](/reference/operations#how-an-operation-runs)). So the part that can follow is a second Operation of its own kind, `instance.complete`. The create's terminal projection admits it in the same transaction, with the Instance lock. One field, `active`, means fully set up for every reader, and one action admits the completion for every caller.

The thread opens about 10 seconds sooner. "Fully set up" comes about 10 seconds later, because two runs start and stage their folders.

A nested-fleet clone keeps its Club key in the create, right after the container's first start, because the copy's inner Swamp serve starts with the container and reads the key at its first start. Its fleet start is in the completion.

Rejected:

- A child workflow of the create: measured 8 seconds slower on a plain clone. The child must wait for the checkout; the Route and database steps started beside the container before.
- A ready mark inside one run: it saves the same seconds for the user, but gives one Operation two meanings of "done" and needs a mid-run Gateway endpoint.
- `instance:setup`: it runs Project setup steps on a checkout, not a clone's services.

## Evidence

Two spikes on Beast on 2026-11-09 ran the user and agent layout in one container, with stock T3 0.1.6, Claude Code 2.1.292 and Codex 0.166.0. FrankenPHP and the template clone of a database were not part of them.

| Check | Result |
| --- | --- |
| Container start | 0.2 s; T3 ready 2–3 s later |
| Copy of a running container | 0.2 s, 1.9 MiB unique; T3 ready 2.9 s after start |
| Memory, T3 idle | About 400 MiB; 590 MiB with warm Claude and Codex sessions; 1,165 MiB peak with both turning |
| Shared agent folder | 1.26 GiB once per Node |
| Claude and Codex turns | Through the router with a placeholder key, about 13 s for a short turn |
| Restart in a turn | Both agents continued after `systemctl restart t3` in a 40 s tool call |
| Changed files | `orchestration.getTurnDiff` answered from a Mac over WireGuard |
| `www-data` user | Could not run `t3`, `claude` or `codex`, read `/home/agent`, reach the model route or T3's port, or see agent processes |
| Shared workspace | Each user edited the other's files; Git was clean for both |
| Egress | Only DNS and the guard; no turn failed |

## Private Node runtimes

Migrate and clone prepare a writable `VP_HOME/js_runtime/node` directory in the new container. The launch phase copies the recipe Node once, with no download. A real copy avoids dangling version links when the Node replaces and retires its shared runtime folder: Vite+ 1.4.0 cannot install over a dangling version symlink. Unpinned Projects initially use the same recipe binary contents.

Vite+ resolves and downloads additional pins as `www-data`, through the Instance proxy, with its own signature and checksum verification. Resolution uses `vp env current node --json`; only a missing resolved executable runs `vp env install VERSION`. Orbit does not implement Node pin precedence. Each other pin on the Process-directory-to-workspace path is also checked in isolation by Vite+, so a malformed child cannot silently fall back to a valid parent.

Migration prepares Node before quiesce. It mounts the serving checkout read-only for this phase and removes the mount even after failure. The phase has a 150-second bound; tool resolution and downloads share 90 seconds. Failure records `instance.node_runtime_prepare_failed` with the pin and a specific malformed-pin, network, HTTP, checksum, disk or unknown-version reason while the checkout keeps serving.

Clone resolution and downloads share 60 seconds instead. Its guest helper allows 75 seconds for store preparation and fetch; all commands in the workspace phase share a 115-second deadline, inside the existing 120-second shell and 130-second Swamp limits. The Operation budget stays unchanged.

Clone converts an old seed's whole-store link as the app user, retains private downloaded versions from new-style seeds, and fetches the selected branch's pin before starting Processes. No privileged command traverses or changes the private store. The launch phase installs a root-owned helper outside the store, but runs all conversion, copy, validation and downloads as `www-data`.

This release needs only the Gateway/Swamp release for migrate and clone. It changes no image or converge tasks and installs no boot unit or Process startup hook. Fresh default creates keep the stock image behavior: their typed result includes `node_pin: {status: "not_prepared", reason: "container_from_stock_image"}` (pin not served: container from the stock image). This advisory does not fail the Operation. An existing container's store is not converted by converge or deploy; deploy fetches only into an already-private store.

After a runtime upgrade, the private recipe copy remains valid. If the shared Vite+ default changes to a version absent from the private store, Vite+ downloads it as the app user on the next start. A pin already cached needs no download. With no network, a start needing an uncached version fails with Vite+'s download error; this release adds no automatic recovery or Process restart.

On Beast, copying Node 24.22.1 from the recipe took 0.87 seconds: 193M apparent (`du -sh --apparent-size`), 130M allocated (`du -sh`), and about 129 MiB added to the pool per Instance. The runtime folder is on ext4 and containers are on ZFS, so the copy shares no blocks with the runtime folder. Clones share the copied blocks through the seed snapshot until written. Budget about 200 MB for each additional Node version. There is no automatic cleanup.

Explicit [Node service targets](/reference/processes-and-schedules#node-service-target-records) on the same Node add an ACL rule to the gateway and published port, plus a root-owned `orbit-node-service-PORT` socket proxy on `127.0.0.1:PORT`. A remote target instead adds only its real private WireGuard IPv4 address and exact TCP port to the NIC ACL, bridge endpoint union and the existing database forward-rule builder. It has no loopback proxy. Create and migrate apply and probe the shared phase before application work. Network admission comes before unit writes.

The remote host also joins managed `NO_PROXY` and `no_proxy` in the container's Incus execution environment, systemd manager environment and login environment. Newly started application commands and Instance Processes receive them; existing processes keep their inherited environment. The shared attached-service phase connects to the exact remote endpoint before application work and migration downtime, and refuses on failure.

A clone copies the default's files `/etc/systemd/system.conf.d/99-orbit-node-services.conf` and `/etc/profile.d/99-orbit-node-services.sh`, which name the default's remote hosts. The clone has no attachment of the default. So when the default has a remote target, the create writes both files with the base value, without service hosts.

The container phase writes them into the stopped copy before its first start, because systemd reads `system.conf.d` at boot. So every unit and Process of the clone starts with the base value. A clone with its own targets then gets its own value from the attached-service phase, which runs after the start. A retry on a running copy does not write the base value again.

Remote withdrawal uses the runtime's captured endpoint and applied bridge and NIC records, so another Instance's changed publication cannot prevent revocation. Detach removes the managed host and owned network path; the NIC denies the port even for an existing process that retains its old environment. Other containers gain no NIC allowance.

The same-Node socket proxy runs with a dynamic unprivileged user; its unit files stay root-owned. Orbit refuses foreign effective vendor/runtime fragments and drop-ins before changing the network. Readiness opens the container's loopback port and checks that the active proxy owns both that accepted connection and a new established connection to the exact upstream. This sends no application bytes.

Orbit waits up to three seconds per target for unit readiness and both connections, polling every 50 ms. The probe’s systemd reads and loopback connect share that deadline. A nonblocking peek at the probe’s client detects EOF or a reset between kernel samples. Refusals retain the [reason and unit states](/reference/processes-and-schedules#node-service-target-records) in the Operation. A missing forwarding connection refuses rather than accepting a listener backlog. Target failures are fatal, unlike advisory development mail.

Pending activation remains after failure, so a later attempt reloads and probes again. Clone attachment records remain independent; inherited target units and enable links are removed before boot. Existing environment files stay unchanged.


Attached-target admission and migration require an active, desired-running Process. Attached targets refuse a stop or publication change until detached. Node intent capture accepts a stopped Process when its recorded publication remains. Detach can withdraw those records. Full host reconciliation still needs live Docker DNAT for every recorded Docker endpoint, as it does for database targets: restart a stopped target before that reconciliation. This feature changes no shared bridge-helper policy.

Migrate accepts only the attachment's exact host-key/port-key pair and published port. A selected nonempty database URL override refuses before downtime; URL credentials are never reported. Target failures carry the public attachment ID, Process ID/name and port. Create preserves that object in its phase receipt; migrate uses its existing error-message field to describe the same public identity. Neither includes environment values.

During clone registration, `instance.container_process_inactive` retains `process_failure`: the unit name, Process id when available, `ActiveState`, `SubState`, `Result`, `ExecMainStatus` and `NRestarts`. A unit that reads active on the detail read does not refuse. Arbitrary Process logs can hold secrets, so the receipt keeps exit facts and omits journal text. A failed native clone copies only its structured refusal through the existing private session file; it never registers that file as credentials.
