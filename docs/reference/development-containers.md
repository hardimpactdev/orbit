---
title: "Instance containers"
description: "ADR 0250, partly built: every Instance runs in an Incus container cloned from the Project's default Instance, with T3 inside. A user drives its T3 in development, Swamp drives it for a task. A Project setting turns on a nested fleet."
covers:
  - apps/gateway/app/{Models/InstanceRuntime,Actions/Nodes/AllocateInstanceBridgeAction,Domain/Nodes/NodeBridges,Console/Commands/RefreshNestedFleetsCommand,Console/Commands/InstanceIdMapSizeCommand,Domain/Tasks/TaskCopyTurn,Domain/Tasks/TaskCopyTurnProjection}.php
  - apps/gateway/app/Domain/Instances/{InstanceRuntimePowerState,ContainerNodes,InstanceHostIntent,InstanceImage,InstanceFolderPlan,InstanceNotes,InstanceContainer,InstanceEgress,DevelopmentMail,DevelopmentInstanceCreateProjection,NestedFleetIntent,NestedFleetRefresh,IsolatedIdRanges,InstanceOperationAlerts}.php
  - apps/gateway/app/Domain/{InstanceMigration/**,Instances/Environment/DevelopmentCreateEnvironment.php,Instances/Deployment/{ContainerDeployOutcome,DevelopmentDeploySteps}.php}
  - apps/gateway/database/migrations/2027_02_{04_000000_add_seed_commit_to_instance_runtimes,04_000002_add_operation_owner_to_instance_runtimes,05_000001_clear_completed_container_default_projections,05_000002_clear_completed_container_default_projections_by_route_target}.php
  - swamp/ansible/{instance-container.yml,instance-container-destroy.yml,instance_node_runtime.py,instance_migrate_git.py,tasks/instance-node-runtime.yml,tasks/instance-container-phase.yml}
  - swamp/{scripts/instance_clone{,_guest}.py,ansible/instance_{bridge,network,node_services}.py,extensions/behaviors/operations/instance-dev-command/result.ts,ansible/task-copy-turn.yml,workflows/workflow-operation-task-copy-turn.yaml,extensions/behaviors/operations/task-copy-turn/*}
  - apps/gateway/{config/instances.php,database/migrations/{2027_02_03_000000_create_instance_runtimes,2027_02_11_000001_add_workspace_baseline_to_instance_runtimes,2027_02_12_000000_add_idle_watch_started_at_to_instance_runtimes,2027_02_15_000000_add_t3_port_last_to_nodes,2027_02_16_000000_add_idmap_size_to_instance_runtimes,2027_02_16_000001_add_id_ranges_to_nodes}.php,resources/instances/{notes/**,frankenphp.Caddyfile,workspace-git-read.py,id-map-read.py},app/Infrastructure/Instances/{ContainerWorkspaceRead,NodeIdMapRead}.php}
  - swamp/ansible/tasks/{instance-host,instance-folder,instance-image,instance-egress,instance-network-check,instance-network-nic-check,instance-network-move,instance-network-move-nic,instance-bridge-rules,instance-bridge-refresh,instance-mail}.yml
---

# Instance containers

**Partly built, limited to the [build list](#the-build-list).** This page describes the delivered parts and the remaining target of [ADR 0250](/decisions/0250-run-instances-and-task-groups-in-incus-containers#build-order). The [Container runtime plan](/solutions/development-container-runtime) tracks the remaining work. Existing checkout Instances stay until migrated. Task groups still run on [task VMs](/reference/compute-drivers#task-vms).

| Part | Delivered state |
| --- | --- |
| Records, Node preparation, egress and DNS | Built |
| Default create and deploy, clone, forced non-default destroy, checkout migration | Built on the build-list Nodes |
| [Nested fleet](#nested-fleet) | Built on switched-on build-list Nodes: placement, host preparation, start guards, the [image](#the-nested-fleet-image), the [refresh](#refresh-after-a-release), and the [clone's](#a-clone-starts-its-fleet) and [stopped clone's](#a-stopped-nested-fleet-clone-starts) fleet start. Planned: reset, rebuild |
| [Sleep and wake](#sleep-and-wake) | Built: the commands, the wake on a Route visit, and the idle sleep, which is off until the operator turns it on. Planned: the wake from Conn and tasks |
| Task containers, nested-fleet parity and production containers | Planned |
| Dedicated workspace volumes and explicit package workspace links | [Draft specification](#workspace-volumes-draft); not implemented or proved |

Every development Instance runs in one unprivileged Incus system container on its `app-dev` Node. The container holds the Instance's checkout, its app and Processes, and, except in the default Instance, a T3 server with Claude Code and Codex. It has no WireGuard identity, no roles and no Node record. Orbit reaches it from its Node with `incus`, and users reach it through its Route and its T3 server.

Every new Instance is a ZFS clone of the Project's default Instance on the Node, and its database is a clone of the default Instance's database. The default Instance is the only one built from scratch. Clones start from its last successfully deployed seed, with that seed's dependencies installed. See the [delivered automatic-deployment state](/reference/deployments#automatic-development-defaults). The default Instance never runs T3, so each clone starts T3 for the first time and gets its own T3 identity.

A task runs in such a container too. There is no separate task container: the same container has two [drivers](#drivers), a user in Conn and Swamp for a task. A Project may turn on a [nested fleet](#nested-fleet), which Orbit needs for its command proofs.

## Charts

The container flows keep the kinds of today's Instance flows. Their charts sit one folder below the command charts, because each kind keeps its checkout or task VM chart until its switch.

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

Every `app-dev` Node runs its Instances in containers. There is no setting to opt in. A task VM keeps its checkout workspace until step 9 of ADR 0250 removes task VMs.

The `app-dev` role installs the container setup: the Incus project `orbit-instances` on the Node's pool, the bridge `orbitinst0`, the container image, the [shared folders](#shared-folders), the [egress proxy](#egress-and-dns), the forwarder of the [model route](#model-access) and the firewall rules. It stops installing PHP-FPM and checkout folders once the Node has no checkout Instance. Clones need ZFS, so the pool of a real Node is ZFS; only a nested proof Node uses a directory pool, where a copy is a full copy.

### Prepare a Node

This part is built. The `app-dev` role converge of a Node on the [build list](#the-build-list) runs a container branch; a task VM never does. The Gateway sends the branch's intent only for such a Node, so every other Node gets the same intent as before. [Prepare an Instance container host](/reference/compute-drivers#prepare-an-instance-container-host) has the steps.

- **Allocation.** The admission of a role add or converge allocates the Node's bridge. The captured intent only reads it.
- **Pool.** The Node provides the Incus storage pool `orbit-instances`: ZFS, or a directory pool on a nested proof Node. The converge chooses no disk itself.
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

A task VM host on the build list, such as Beast, gets the container branch beside its task side. The two sides share nothing they change: the Instance side has its own project `orbit-instances`, bridge `orbitinst0`, pool `orbit-instances` and `orbit:instances-*` input rules, and touches no task VM, task container or CI place. The task side keeps the ZFS ARC: on a task VM host the Instance side writes no cap. Only a task VM's own Node gets no branch.

Before it changes anything, the converge refuses a bridge range that another interface of the Node holds, with `instance.host_unavailable`. The Gateway only knows the bridges it records, not one an operator made, such as a CI bridge.

The Gateway gives each Node a bridge network, a `/24` of the fleet range `10.253.0.0/16`, and records it on the Node. It takes the first `/24` that overlaps no recorded bridge and not the VPN subnet. A Node keeps its bridge. When no `/24` is free, the allocation fails with `node.instance_bridge_exhausted` (409). A task VM host's bridges may not overlap an Instance bridge either: `node:update` refuses them with `node.settings_invalid`.

T3 servers are published on ports 3800 to 3999 of the Node's WireGuard address. The Node records the last T3 port it gave. A new runtime takes the first free port after it and wraps from 3999 to 3800, so a port freed by a destroy is given again only after the Node has used every other free port. A client that saved the destroyed server under its address therefore does not meet a new server there at once. When every port is held, the reservation fails with `instance.host_unavailable` (409).

### The build list

Until every `app-dev` Node switches, only the Nodes on the build list run container Instances. The list is the Gateway setting `instances.container_nodes`, a JSON list of Node ids in `ORBIT_INSTANCES_CONTAINER_NODES`, such as `[12]`. It is empty by default. A malformed list fails with `instance.container_nodes_invalid` (500), so a typo puts no Node on it. The list exists for the build only; the switch removes it ([plan](/solutions/development-container-runtime#rules-for-every-pr)).

The Node must meet the host requirements of ADR 0250: current kernel, SCTP off and no secrets. A Node that still has checkout Instances keeps them until [migration](#migrate-a-checkout-instance) moves them into containers. The `app-prod` role follows the same model in step 10 ([Production Instances](#production-instances)).

## Inside a container

Every Instance container, default or clone, has 4 CPUs, 4,096 processes and a 40 GiB root disk on the `orbit-instances` ZFS pool. It has 8 GiB of memory, or 12 GiB in the [nested-fleet project](#nested-fleet).

Clone phases stage their release's helpers and intent once in a private folder for the run. Later phases run them by path. This keeps scripts off the SSH command and avoids its size limit. The repository credential stays on stdin. Cleanup removes the folder on success or failure, including a failed first launch.

The clone copy supplies a complete root disk device, including its type, path, pool and size. This works when the seed inherits its root disk from a profile or stores it locally.

Every development container has the same layout. The image and the create set it up. Root owns everything below except the homes and the workspace. A container installs as little as possible: the image holds only OS packages, the users and the firewall.

| Part | Setting |
| --- | --- |
| Image | Ubuntu 26.04 with the OS packages the runtime needs, the users, `nftables`, `acl` and `hidepid`. No PHP, no Node, no agent software |
| Egress | The [proxy settings](#egress-and-dns) for every unit, login shell and apt, and no DNS server |
| `orbit` | uid 1000, home `/home/orbit` mode 0750, password locked. Runs the app and every Orbit Process |
| `agent` | uid 2750, home `/home/agent` mode 0700, password locked. Runs T3, Claude Code, Codex and their shell commands. Owns the T3 state, the agent settings and the placeholder key |
| `work` | gid 2760, members `agent` and `orbit` |
| Sudo | None for either user. The image's `sudoers.d` grant is removed. The [nested-fleet image](#the-nested-fleet-image) gives `agent` alone passwordless sudo, so it is root in its own [nested-fleet](#users) container |
| Workspace | `/srv/workspace`, owned `agent:work`. Directories are setgid `work`, mode 2770, with the default ACL `g:work:rwX` and no access for others. `core.sharedRepository=group` |
| Git | `safe.directory=/srv/workspace` in `/etc/gitconfig`, for every user and only that path; the agent part of the T3 step also writes it to `agent`'s own Git config and preserves its other Git settings |
| Shared folders | `/opt/orbit-runtime` and `/opt/orbit-agent`, the Node's folders, mounted read-only and idmapped |
| T3 unit | `User=agent`, `UMask=0007`, `PATH` from the shared folders, `Requires=nftables.service`, `ConditionPathExists=/etc/orbit/instance`. The unit file is mode 0640 |
| `PATH` | The runtime folder's `bin` first, for units, login shells and `incus exec` sessions, so `composer` is on it |
| `php` | `/usr/local/bin/php` links to the Project's static `php` in the runtime folder. A Project without a PHP version has none. The create and each start set it |
| Instance file | `/etc/orbit/instance`, root-owned and readable by all: Instance, Project id, slug and quoted one-line name, Node and URL. Absent on the default, keeping T3 stopped |
| Model route | `nftables` in the container lets only uid 2750 reach the forwarder, and only uid 0 and 2750 reach T3's port |
| Processes | `/proc` with `hidepid=invisible`, so each user sees only its own processes |
| Kernel surface | Unprivileged, no nesting, `user.max_user_namespaces=0`, an isolated ID map |

A container mounts no host checkout and no Incus socket. Root work, such as units, goes through Orbit from the Node.

The Gateway renders the image: its packages, the setup script, its files and the units it enables. The script turns cloud-init off, so a new container never makes the stock `ubuntu` user and its sudo grant again. The image's alias is `orbit-instance-` with the first 12 hex digits of a digest of that definition, so a changed definition is a new image. A build container from the stock Ubuntu image runs the definition and is published under that alias. The stable alias `orbit-instance` then moves to it, and prior images remain for separately authorized operator cleanup; a container keeps what it was made from.

A Node with nested-fleet hosting on also builds the [nested-fleet image](#the-nested-fleet-image); the normal image does not change.

Host preparation publishes the stock Ubuntu container as an independent local image with no update source. It verifies the daemon's identity before checking image archives, promotes the stock alias only after verification, and refuses prior leftovers for operator review. It retains prior stock and prepared images for separately authorized operator cleanup. Prepared-image publication clears the stock-build statement. See [Stock-image publication and explicit adoption](/solutions/incus-shared-image-refresh).

The build container reaches the internet as every container does, through the [egress proxy](#egress-and-dns): the profile gives its `incus exec` the proxy settings, and apt follows them. It takes the bridge's last address, which no Instance gets.

### Who runs what

The app runs as `orbit`: FrankenPHP, queue workers and every Orbit Process. `orbit` cannot run the agent binaries, read `/home/agent`, reach the model route or T3's port, or see agent processes. A public preview URL reaches only an Orbit Process, so an attacker who comes in through it lands as `orbit`.

T3 and the agents run as `agent`, and so do the shell commands of Claude Code and Codex. There is no wrapper shell. Both users read and write the workspace: a file each one writes is `rw-rw----`, group `work`.

A tool that asks for a narrower mode, such as Bun with its 0755 folders in `node_modules`, caps the ACL mask, and the other user cannot write there. Root gives the group back what the owner has before a deployment's steps and after them, and after a clone's create. Between those, a folder that one user creates with a narrow mode stays closed to the other until the next deployment.

There is one exception to "a URL reaches only `orbit`". An agent may start its own development server, such as `npm run dev`, as `agent`, and the Instance's Route reaches it on the server's own port. That server can read what `agent` can read. So only a private Route serves it, which only WireGuard peers reach; a public Route never does. [Agent development servers](#agent-development-servers) has the details.

### Agent development servers

An agent's development server answers at the Instance's Route with its own port, such as `https://feature.tool.test:5173`. Any port from 1024 to 65535 works, and the agent picks it. It opens that URL in T3's browser. Conn needs no change.

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

FrankenPHP serves the app, in classic mode, as an Orbit Process running as `orbit`. The runtime folder holds one static build per PHP version that Orbit supports, and each Instance uses the version that Orbit picks from its `composer.json`, as it does today. A monorepo uses its root Composer requirement without framework classification. Its Caddy listens on port 8080; the Node's Caddy terminates TLS for the Route and proxies to the container's bridge address.

The container's `/etc/orbit/frankenphp.Caddyfile` trusts only the Node's bridge address (`10.253.0.1/32` on the first Node). When that connection sends `X-Forwarded-Proto: https`, it sets PHP's `HTTPS=on`, `REQUEST_SCHEME=https`, `SERVER_PORT=443` and `REMOTE_ADDR` to Caddy's resolved client IP. Other requests keep their native PHP values, even if they send forwarded headers. Stock Laravel needs no application changes: assets, redirects, signed URLs and session cookies follow the Route's HTTPS scheme. Create, clone and migrate install the same rendered configuration.

The `vp-dev` preset sets its development origin from the HTTPS Route, including `/__orbit/vite`, and HMR uses that origin. PHP's scheme translation preserves these development URLs; it changes no Vite upstream or WebSocket routing.

The command line runs a real PHP CLI: a static `php` from the same static build as the Instance's FrankenPHP, with the same PHP version and extensions. `frankenphp php-cli` behind a `php` shim does not fit yet. It refuses the `-d` options that Composer and test runners pass, and before PHP 8.6 it cannot run `php -i` or PHP without a script. Orbit runs PHP 8.5. The [static PHP build](/reference/compute-drivers#static-php-build) defines the build and its extension list, and the runtime folder's check compares both binaries with it. The shim can replace the CLI binary once FrankenPHP accepts `-d` and Orbit runs PHP 8.6.

## Shared folders

The software that the app and the agents run lives once per Node, in two versioned read-only folders. Every container mounts both with idmapped mounts, so one update reaches every container. Each folder has a `current` link to the version in use, and the recipe pins every version.

| Path | Content | Access |
| --- | --- | --- |
| `/opt/orbit-runtime/<version>/php/<php-version>/bin` | `frankenphp` and `php` of one PHP version, from its [static PHP build](/reference/compute-drivers#static-php-build) once that is published and pinned | Everyone |
| `/opt/orbit-runtime/<version>/bin` | `composer`, Node, npm, npx, Bun and `bunx`, Deno, `vp`, rg | Everyone |
| `/opt/orbit-runtime/<version>/{lib,fonts}` | The [browser libraries and fonts](#browser-libraries-and-fonts) | Everyone |
| `/opt/orbit-runtime/<version>/php-data-bridge/bridge` | The files of [PHP Data Bridge](https://github.com/nckrtl/php-data-bridge), which [container PHP loads](/reference/php-runtime#container-php). The part has no program | Everyone |
| `/opt/orbit-runtime/vite-plus/<digest>` | The [Vite+ home](/reference/compute-drivers#the-vite-home) that `vp` runs from, linked as `<version>/vite-plus-home` | Everyone |
| `/opt/orbit-agent/<version>/agent/bin` | `t3`, `claude`, `codex` | `root:2750`, mode 0750 |
| `/opt/orbit-agent/<version>/agent/{t3,claude-code,codex}` | The releases | `root:2750`, mode 0750 |
| `/opt/orbit-agent/<version>/notes/<type>.md` | The [environment notes](#environment-notes) | Everyone |

The folders live on the Node's root filesystem, at the same paths as in the containers. The recipe pins each part of a folder, in its `instances` object ([recipe schema](/reference/compute-drivers#recipe-schema)): T3, Claude Code and Codex in the agent folder; Composer, Node, Bun, Deno, Vite+, rg, PHP Data Bridge and the static PHP builds in the runtime folder. Node, Bun, Deno and Vite+ reuse the recipe's own pins.

A folder's version is the first 12 hex digits of a digest of everything in it, the notes included, so any change is a new version. Each program in `bin` links to its path in its part.

The idmapped mount keeps gid 2750 the same in every container, whatever its ID map. The spike proved this with one container; PR 3 proves it with two containers whose ID maps differ. `orbit` sees the folder `agent`, but not what is in it.

An update installs the new version beside the old one and flips `current`. It runs with the converge of each Node, so a recipe change reaches the Node with its release refresh. Both `agent` and `orbit` have `/opt/orbit-runtime/current/bin` on `PATH`, so a running clone gets added programs, such as Deno, without recreation.

For the agent folder, Orbit then restarts T3 in each running container, one after another; T3 continues a running turn after its restart. For the runtime folder, Orbit restarts the FrankenPHP Process and the other Processes of each running container: every unit named `orbit-*.service`, so the Process units of a container carry that prefix. A stopped container uses the new versions when it wakes. An old version stays until no process maps a file of it.

A running process runs only what existed when it started. So a new version that only adds files restarts nothing, such as the [browser libraries and fonts](#browser-libraries-and-fonts) or a new program. The converge compares the version in use with the new one and does not follow links. It restarts only when a file, link or mode of the version in use changed or went.

The CLI self-updaters are off: `DISABLE_AUTOUPDATER=1` in the T3 unit, which Claude Code inherits, and `check_for_update_on_startup = false` in the image's Codex configuration. The T3 server has no self-updater; it runs from the read-only folder.

The OS packages of the image change rarely. The Node's patch run of ADR 0250 upgrades them in each running container from the Node; a sleeping container gets the upgrade when it next wakes.

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

The owned workspace stays at `/srv/workspace`, owned `agent:work`, with directories mode 2770 and default group ACLs. The [existing account values](#inside-a-container) stay: `agent` uid/gid 2750, `orbit` uid/gid 1000 and `work` gid 2760. Tools can narrow modes; [Who runs what](#who-runs-what) describes their correction.

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

**Agreed policy; not implemented or proved.** An explicitly linked package workspace is writable from BOTH the owning package Instance and consuming app Instance. Both `agent` and `orbit` can create, edit, delete and rename files through `work`. Changes are live and visible in both directions and to every other explicit consumer.

The link permits edits to the selected package, including files outside the app's own checkout. Neither another attachment nor consumer writes may break owner or other explicit consumer access. Preserve numeric guest ownership, default ACLs and group permissions across distinct isolated maps. This support remains UNPROVEN.

Keep `/srv/workspace` owned `agent:work`, mode 2770. A file belongs to its creating user under the group policy. Do not relabel every file as `agent` or recursively shift owners for each mount.

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

First authorize a small disposable proof separately. Check distinct isolated maps with `agent` uid/gid 2750, `orbit` uid/gid 1000 and `work` gid 2760. Verify `/srv/workspace` ownership `agent:work`, mode 2770 and default group ACLs.

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

The default's deploy steps run as `orbit`, and a [task check](#a-task-copy-starts-clean) runs as `agent`. A browser in one user's cache is not visible to the other. The dependency folder is shared by both users through the group `work`. When a Project ignores `node_modules` in its `.gitignore`, the folder survives the clean of a task copy. The seed carries it, and a clone gets it with no copy and no download. The build always matches the Project's own Playwright version.

So a Project whose check runs browser tests installs the browser in a [development deploy step](/reference/deployments#development-deploy-steps) of its own, after its JavaScript install, such as `bunx playwright install chromium`. The step downloads only when the Project's Playwright version changed. Otherwise it does nothing.

| Where | What sets `PLAYWRIGHT_BROWSERS_PATH=0` |
| --- | --- |
| Deploy steps of a default Instance | The intent's `environment` list |
| A new clone: its units (T3, its agents and their commands) and login shells (the task check runs `bash -lc`) | Two boot files, `/etc/systemd/system.conf.d/91-orbit-browsers.conf` and `/etc/profile.d/91-orbit-browsers.sh`, written before the copy's first start |

The setting is not a [shared file](#existing-containers), and it is not on the Incus profile or in the image. So the converge changes no existing container: a running clone whose agent installed a browser into `~/.cache/ms-playwright` keeps finding it. A default gets the setting at its next deployment, and only its later clones get the boot files.

A fresh JavaScript install can drop the folder. With the same Playwright version, `bun install`, `npm install` and `pnpm install` keep it. A new Playwright version replaces the package folder, and `npm ci` deletes `node_modules`. The deploy step runs after the install, so the default's seed always holds the build. An agent that reinstalls `node_modules` in a task copy, or changes the Playwright version, runs the Project's own install command again, such as `bunx playwright install chromium`, before it runs browser tests.

### Existing containers

A container keeps the image it was made from. The image definition marks the files that every container needs as `existing`: the linker and font configuration and `/etc/gitconfig`. The converge of the Node writes them into each existing container too. It writes them into running, stopped and frozen containers, in the Instance project and in the nested-fleet one. Nothing restarts. Each later process reads the files by itself.

A clone copies its default's seed snapshot, not the default itself, and a seed taken before these files existed lacks them. So every clone gets the same files before its first start, with an empty linker cache that the stock `ldconfig.service` rebuilds at that start.

The dynamic linker reads its cache, `/etc/ld.so.cache`:

- **Running.** A container whose cache lacks a library of the runtime folder's `lib` runs `ldconfig -X`. It makes no links, because the folder is read-only.
- **Stopped.** A stopped container loses its cache instead. At its next start, the stock `ldconfig.service` builds a new cache.
- **New.** An image removes its cache last, so each new container builds its own at its first start.
- **Digest.** The Node keeps a digest of the library names it last applied, in `/var/lib/orbit/instance-container-libraries`. So a changed folder reaches stopped containers too.
- **Frozen.** A frozen container gets the files through the file API. `incus exec` refuses it, so it gets no exec.
- **Thaw.** A frozen container keeps its cache, because it thaws without a boot. Its wake rebuilds a cache that lacks a runtime library.
- **Failure.** A failed Incus call fails the converge after every other container had its turn.

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

When it creates an Instance, Orbit links `/home/agent/.claude/CLAUDE.md` and `/home/agent/.codex/AGENTS.md` to the note of the Project's type. A note states facts about the environment only. It names the two users and the workspace path, and points to `/etc/orbit/instance` for the Instance's URL. It says where PHP, Composer and the other runtime programs are.

It says how previews work, and how an agent's own development server becomes reachable: the port range, the address to bind and the host name to allow. It says that there is no sudo and no direct internet, how the egress proxy works, and how Git leaves the container. The note does not state the nested-fleet exception yet, where `agent` has sudo.

A note gives no instructions about the work. Instructions, skills and MCP servers come from the project's own files.

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

Updated endpoint settings rewrite the proxy services and restart active proxies, leaving application Processes running. The runtime folder's existing `try-restart orbit-*.service` also leaves idle proxies inactive; an active proxy restart can close its current SMTP connections while its socket remains listening. Process cleanup matches `orbit-process-*` and does not remove these mail units. Clone preparation disables inherited mail socket links before first boot, then its container launch enables its own listeners.

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

A container holds only a placeholder key, in `/home/agent/.config/orbit/model.env`. Claude Code and Codex send model calls to a forwarder at port 8319 of the bridge's gateway address. The [forwarder](/reference/ai-router#container-model-forwarder) passes them to the model router guard, which admits the agent Node and swaps in the real key. The real key stays off the Node. While the forwarder's table is loaded, only containers on the bridge reach the guard, through the forwarder. The guard accepts the `?beta=true` query that Claude Code sends. On a prepared Node, `ai-router:model-forwarder NODE --bridge=orbitinst0 --address=<bridge address>` places the forwarder.

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

This part is built. A container has full outbound access to the public internet, and all of it goes through one forward proxy on its Node. The proxy is the one place where a rule can narrow that access later, with no change to the containers. Step 2c of the [ADR 0250 rollout](/solutions/agent-runtime-and-manual-mode) decided it. The `app-dev` converge of a [prepared Node](#prepare-a-node) installs it, in `tasks/instance-egress.yml`.

### The proxy

Each prepared Node runs one proxy for all its containers.

- **Program.** Squid from Ubuntu runs as the system user `proxy`, on port 3128 of the Instance bridge's gateway address. Its unit is `orbit-instance-egress.service`.
- **Configuration.** `/etc/squid/orbit-instance-egress.conf`. Squid's AppArmor profile lets it read only `/etc/squid`.
- **Checked first.** The converge parses a new configuration beside the live one, and only a configuration that parses replaces it.
- **No stock Squid.** The converge masks the package's own `squid.service` before the package installs. So the stock configuration, which listens on every address, never runs.
What it serves:

- **Scope.** A forward proxy with `CONNECT`, open to every public host and port.
- **Nothing kept.** It keeps no cache and no files. It sends no `Via` or `X-Forwarded-For` header.
- **No cache manager.** It refuses requests for its own cache manager, so a container cannot read what other containers fetch.
- **Who.** Every container on the bridge uses it, `orbit` and `agent` alike, and so does the image build container.
- **Admission.** Only the bridge reaches the port. The common NIC ACL and the Node's rule `orbit:instances-egress` admit it there. The proxy's table resets a connection to the port from anywhere else.
- **Health.** The converge ends by checking that the proxy and its table run. Otherwise it fails with `instance.host_unavailable`.

Readiness inspects the listening socket at the bridge address and port 3128, with 30 retries at a one-second delay, because the proxy's table rejects host TCP probes over `lo`; the image build still uses the proxy from its container on the bridge.

Names and the record:

- **Names.** Containers have no resolver. The proxy resolves each name through the Node's stub resolver, `127.0.0.53`.
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
- **Admitted.** Only the stub resolver, on port 53.
What that means:

- **Effect.** The rule holds whatever a name resolves to. The proxy answers 503.
- **Out of reach.** The Gateway, WireGuard peers, other Nodes' private services and the Node's LAN.
- **Order.** The table's unit, `orbit-instance-egress-table.service`, loads early at boot.
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
| Deploy steps of a default Instance, which run under `env -i` | The intent's `environment` list: `HOME`, `USER`, `LOGNAME`, `PATH`, the container environment and `PLAYWRIGHT_BROWSERS_PATH=0` ([Playwright browsers](#playwright-browsers)) |
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

After that, [`instance:deploy`](/cli/instance/deploy) explicitly deploys the default branch. Automatic deployment depends on the [shared producer and policy setting](/reference/deployments#automatic-development-defaults). Orbit pushes the new commit in, checks it out, and runs the development deploy steps in the container. Before that snapshot, a Node/Vite default converts an old shared Node store once as `orbit`, without restarting a Process.

A converted store replaces the same-commit seed too: an immutable old snapshot cannot inherit a later filesystem change. Until this explicit deploy, clones of an old seed still convert their own store correctly.

After a deployment passes, Orbit stops the default's Processes for a moment and takes the snapshot `seed-<commit>`. It retakes the snapshot at the same commit too, so a clone gets the default's current caches and built files. Clones copy from that snapshot, so they never see files half written. The [deploy chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/deploy-default.mmd) has the steps. The default Instance runs no T3 and no agent: a thread always starts in a new Instance.

#### A retake keeps the seed

A retake never leaves the default without a seed. It takes the new snapshot first, beside the old one. When `seed-<commit>` exists already, the new snapshot waits under `next-<commit>`. Only when the new snapshot exists does the old `seed-<commit>` go, and `next-<commit>` takes its name. Older seeds go last. The seed of a default with an [inner volume](#the-seed-pair) is a pair, and both halves follow the same order.

Incus maps a new snapshot as it maps a new container: it needs a free [isolated ID range](#isolated-id-ranges) for a moment, but a delete or a rename needs none. So the step never uses `incus snapshot create --reuse`, which deletes the old snapshot before it creates the new one. On 2026-11-14 a retake with `--reuse` on a host with no free range left Instance 2454 without a seed for seven minutes.

A failed snapshot deletes only what this take made, a new name or `next-<commit>`, and fails the step with `instance.container_seed_failed`. The Operation's error detail names the cause: `No free ID range for the new seed`, or the failed command's error output. The old seed stays, and clones keep copying it. A run that stopped after its new seed was whole, but before the rename, leaves the new seed under `next-<commit>`; the next seed step gives it the seed's name. So does a volume half still under `next-` after its container half took the name. Any other `next-` snapshot goes.

There are no development setup steps. The default Instance has them done already, so a clone needs none. A clone prepares its container in four direct command phases: container, workspace and Processes, T3 with the agent's files before it, and sessions.

The workspace phase prepares Node and fetches a changed branch pin before starting any Process, with no separate Node-store playbook. A prepared seed keeps its existing recipe and downloaded versions. Every clone gets a private writable store, including one with no Processes, so a Process added later can install its pin. Without a recognized Node/Vite consumer, only the pin fetch is skipped. Each native step reports its timestamps through the existing Operation progress.

Inside the container the workspace phase has two parts, in order. `checkout` takes the bundle's branch, writes the checkout mark and merges `.env`. `services` writes the PHP settings, file rights, Process files and web config, prepares the Node store, and starts the units. Each part is also a phase of its own for a later workflow; both read the same private vault value, and each passes on only its half.

The `check` phase checks a finished clone: this Instance's checkout mark, the agent's Git identity, PHP, and each running unit, with the same `instance.container_process_inactive` code as the sessions phase. It prints the dead unit's public receipt on its own output; the sessions phase keeps it in the run's private folder. The create calls neither part alone and does not call `check` yet.

Conn supports an [explicit branch selection](/reference/conn#threads-in-an-orbit-project) for a clone. It prepares the Node mirror from the admitted remote source commit and checks out its bundle in the existing workspace phase. Dependencies and build still come from the default's seed; checkout runs no Project setup or deploy command again.

A separate job publishes the Route, certificate and DNS. Swamp runs jobs in levels too, but the container job and the Route job share one level and each keeps its own step levels, so the Route steps cannot delay a container phase. The Route checks the TLS handshake, without waiting for the application to start. The create receipt records T3, session and Route readiness times. Workspace access and environment files stay in the container preparation.

#### What is built

The first default Instance and its deployments are built for the Nodes of the [build list](#the-build-list). A Project has one `default` Instance in the whole fleet, and every clone of it is made on its Node. Deployment admission refreshes the Project’s [source facts at the deployed commit](/reference/deployments#container-source-facts).

| Part | What Orbit does |
| --- | --- |
| Container | `instance-<id>` in the project `orbit-instances`, from the image `orbit-instance`, at the lowest free guest address of the bridge. It has no T3 port |
| ACL | The NIC carries `orbitinst0-proxy` and the container's own ACL `instance-<id>`; `orbitinst0-egress` stays on the bridge only. Only the Node's Caddy, from the bridge's address, reaches port 8080 |
| Git | The Node fetches with the read token into its mirror `/var/lib/orbit/instance-repositories/<id>.git`. A bundle carries the branch into the container, and `orbit` checks it out in `/srv/workspace` |
| Checkout path | The workspace's path on the Node, inside the container's root file system. It keeps the path unique on the Node; Orbit never works in it from the Node |
| `.env` | In the workspace's application directory, for `orbit` with group `work`, mode 0640. Git never takes it: see [the `.env` stays out of Git](#the-env-stays-out-of-git) |
| Deploy steps | Each runs as `orbit` at `/srv/workspace`, from its own vault copy. Its environment holds only `HOME`, `USER`, `LOGNAME`, `PATH`, the runtime folder's `VP_HOME` and `VP_NO_UPDATE_CHECK=1`, the [proxy settings](#proxy-settings-in-a-container), and `PLAYWRIGHT_BROWSERS_PATH=0` ([Playwright browsers](#playwright-browsers)) |
| `PATH` | The Project's PHP build, then the runtime folder's `bin`, then the system folders |
| FrankenPHP | The unit `orbit-frankenphp.service` runs `frankenphp run --config /etc/orbit/frankenphp.Caddyfile --adapter caddyfile` as `orbit` on port 8080. The Route proxies to the bridge address; the Node runs no PHP pool for it |
| Seed | The first build and each passed deployment, also at the same commit, take `seed-<commit>` with the units stopped. Older seeds go; the runtime row records the commit |
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
| User | `orbit`, group `orbit`, `UMask=0007`, `HOME=/home/orbit` |
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

Two steps then start at the same time. The workspace step runs the `checkout` part of the workspace phase: the branch, the checkout mark and `.env`. The T3 step first writes the agent settings, the note links and `/etc/orbit/instance`, then starts T3 and adds the T3 project at `/srv/workspace`. The T3 step never writes the workspace, and T3 runs Git with `GIT_OPTIONAL_LOCKS=0`, so it takes no lock that the checkout needs. So it does not wait for the workspace.

Swamp starts a level of steps only when the whole level before it has ended ([Swamp shape](https://github.com/hardimpactdev/orbit/blob/main/swamp/docs/swamp-shape.md)). Both steps are in the level after the copy and the Club key, so both start when the Club key step ends or skips.

The sessions check waits for both lines, because it checks the branch and the checkout mark. It checks no Process unit: the units start in the completion. Last, Orbit registers the T3 server ([T3 registration](#t3-registration-and-pairing)). The [create chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/create.mmd) has the steps.

The levels and their measured seconds, with about 1.2 s of dispatch in each step:

| Job | Steps by level | Seconds |
| --- | --- | --- |
| (start) | Gateway admission, Swamp start | 3.8 |
| `input` | the intent | 1.3 |
| `clone` | `clone-input` · `clone-container` · `clone-club-key` (skipped unless nested) · `clone-workspace` beside `clone-t3` · `clone-sessions` · `clone-session-copy` · `clone-register` | 2.0 · 5.3 · 0 · 4.2 · 2.6 · 1.4 · 1.3 |
| `create` | `finish` beside `clone-private-cleanup` · `stop-container` on failure, and the run-folder cleanup · `accepted` | 1.3 · 0.3 · 0 |

A plain clone's create succeeds about 24 seconds after the click. The baseline read and the admission of the completion add about 0.5 seconds in the Gateway.

When the run succeeds, the Gateway reads the [workspace baseline](#workspace-work-state) over SSH, before its transaction. Then, in one transaction, it records the T3 server, writes the baseline and turns the create `succeeded`. The clone stays `source_resolved` with its `starting_commit`, and its runtime is `running`. In the same transaction, after the create row is terminal, the Gateway admits `instance.complete` with the Instance lock. The create's result names it in `completion_operation_id`. The completion's vault copy and its Swamp start run after the commit.

The create's admission captures the clone's Route publication as the completion will, and keeps nothing of it: a Route that the capture refuses, such as a Cluster Route without a recorded Router (`cluster.router_required`), refuses the create before anything is recorded.

The same capture runs again at the completion's admission, because fleet state can change in between: a Cluster's Router, the stored environment, an Ingress firewall. If it refuses then, the projection records a failed `instance.complete` with that code, `failed_step: complete` and the recovery `instance:complete`; any other error at that point is recorded the same way with `operation.admission_failed`. The create still succeeds and names that completion, so Conn, the failure alerts and a task copy see an ordinary failed completion.

A clone is fully set up only when its `status` is `active`. Only a succeeded completion sets it. Every reader of `active` keeps its meaning: deploy, migrate, sleep, renewal and task copies. `orbit instance:create --wait-active` follows both Operations and exits 0 only on `active`.

A clone starts at the commit of the default's newest seed snapshot, with its dependencies installed. It copies no development deploy commands to the execution vault and runs no deploy step. Its branch is the matching origin branch, fetched on the Node, or a new branch at the seed's commit.

A branch that the seed already holds needs no network. A new branch at the seed's commit fetches nothing and fills no Node mirror. The guest checks it out from the seed's own repository, with no bundle.

An [explicit branch choice](/reference/conn#threads-in-an-orbit-project) runs no `ls-remote` in the clone, because its admission read GitHub. Only an existing branch, or a new branch from another commit, is fetched. A create by name only still reads GitHub once, to learn whether the origin has the branch.

Someone may make the branch on GitHub between the admission and the copy. The copy still starts at the admitted commit. When the GitHub branch has commits the copy lacks, a later push of the branch is refused as `tasks.not_fast_forward`. When it has none, the push fast-forwards it, and no commit is lost. A clone's Node mirror exists only after a fetch: its own create's, or a later deployment's.

The container, checkout, T3 and sessions phases print the seconds of their parts. The container phase names its ACL, copy, unit cleanup, boot files, firewall, network check, start, private names, Node services, mail and branch. The workspace phase names its bundle and its guest checkout. The T3 phase names its agent part and T3's first answer. The sessions phase names its guest check. The create's result carries them as `timings`: names and seconds only.

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

Clones are built for the Nodes of the [build list](#the-build-list).

| Part | What Orbit does |
| --- | --- |
| Default | The Project's `default` runs in a container on the same Node, is active and has a seed snapshot. Otherwise `instance.default_missing` |
| Conn and models | Conn is on, else `operation.kind_unavailable`. The model router has its guard, else the router's own code |
| Runtime row | The next free bridge address, the next free T3 port after the Node's last one ([rule](#prepare-a-node)), and the default as clone source |
| Copy | `incus copy` of `seed-<commit>` with the clone's own address and ACL. A stopped default needs no start |
| First start | Before it, the copy loses its `orbit-*` boot links and the default's Process units and folders. It gets its own machine-id and the [shared files](#existing-containers) |
| ACL | The Node's Caddy reaches port 8080 and ports 1024 to 65535, any WireGuard peer reaches port 3773, and the container reaches its database server's port |
| T3 port | The proxy device `t3` in NAT mode, from the Node's WireGuard address and the T3 port to port 3773 |
| UFW | Under the comment prefix `orbit:instance-<id>-`: a route deny from the task VM range, then a route allow to port 3773; and the rule to the database server |
| Agent files | `/home/agent/.config/orbit/model.env` with the placeholder key from the vault, Codex's and Claude Code's settings that point at the forwarder, the note links, and `/etc/orbit/instance` |
| Processes | In the [completion](#complete-a-clone): FrankenPHP's unit, rewritten for the clone's own address, and the Project's Process units |
| T3 | Its first start, a check of `/.well-known/t3/environment` within 30 seconds, and the T3 project `/srv/workspace` |
| Registration | [T3 registration](#t3-registration-and-pairing), from the run's result |

A failed or cancelled create keeps its reservation, runtime, and any container, workspace, owned databases, test databases and role until the owner acts. A refused retry removes none of them. After work starts, Orbit attempts to stop the container. A clone's create publishes no Route and creates no database, so there is nothing to withdraw. Compensation can fail; its step then stays failed. Shared service endpoint records and rules may remain even after destroy until endpoint lifecycle work is live.

A run that succeeded can still fail the create in the Gateway: when the baseline read fails, the create fails with `instance.workspace_baseline_unavailable` and `failed_step: register`. The T3 server is not recorded and no completion starts. The run already ended without its container stop, so the container keeps running; a retry runs the create again over it, and every phase of the create accepts a running copy.

Its Operation reports `recovery.state=retained_for_retry` and names `instance:create` with the same Project, Node, name and options, using a new idempotency key. It also names `instance:destroy INSTANCE --yes --force`. A failed or cancelled default create reports the same retry path and marks destroy unavailable; container-default removal is not built. A resume also issues new T3 sessions. When it succeeds, it admits a new completion, also when an earlier completion of the clone failed or never ran. The `Orbit Gateway` session of the failed run stays valid in T3 until the cleanup after the next renewal revokes it; its `Orbit Swamp` session stays valid until it ends.

A resume never moves a branch that this Instance checked out. After its first checkout, the workspace phase checks that the branch and the commit are the fetched ones. Then it writes the mark `/var/lib/orbit/workspace-checkout`, owned by root, with the Instance id and that commit. A default never writes this mark, and a mark that names another Instance does not count. So the first run checks out the fetched commit, even when the copy is already on a branch with the same name.

When the mark names this Instance, a resume skips the fetch and the checkout, and commits on the branch stay. The sessions check needs only the branch name and the mark, not the commit. The result's `commit` is the commit of the mark, so `starting_commit` stays the commit that the workspace started at, not a later commit of the user.

A clone's create checks no Process unit. A dead Process fails the completion's `check` step with `instance.container_process_inactive` and its `process_failure` receipt. When the Process comes from a Project definition, the completion's recovery says `retry.requires=project_process_definition`: change or remove the definition, then run `instance:complete`. Its admission installs the Project's development definitions as they are then.

A deployment of the default can replace the admitted seed before the copy. The copy then refuses with `instance.default_seed_moved`, and the same command takes the new seed. A default with no `seed-` snapshot at all refuses with `instance.default_seed_missing`: deploy the default to take a seed. A default with only a `next-` snapshot is a retake between its delete and its rename, so it refuses with `instance.default_seed_moved`. A clone already copied does not need its seed: on ZFS, Incus keeps a deleted snapshot's blocks while a clone uses them.

#### What a clone has at its first start

The clone script writes these, so a new clone works with no converge or `env:sync`:

| Part | When | What the copy has |
| --- | --- | --- |
| Trust | Before the first start | The Orbit root as a local CA file, its two links, and in `/etc/ssl/certs/ca-certificates.crt`, so curl, PHP and Chromium trust Orbit's sites. See [trust](#trust) |
| `NO_PROXY` | Before the first start | The composed value in the `environment.*` keys and in both drop-ins, with the private names. See [proxy settings](#proxy-settings-in-a-container) |
| Private names | Right after the first start | The plan's `private_names` in `/etc/hosts`, such as the clone's own Route names. See [private names](#private-names-of-the-access-plan) |

The hosts block comes after the start because the copy's own Incus template rewrites `/etc/hosts` at that start. The stock image's metadata holds `templates: /etc/hosts: when: [create, copy]`, which renders `127.0.1.1 <name>` and the localhost lines. A clone copies that metadata from its default, so a write before the start is lost.

Right after the start, the clone script reads `/etc/hosts` with `incus exec … cat`, as `env:sync` does. It renders the file with the same `hosts` command and pushes it as root with `0644` only when its lines change. This is still before the create reports anything. A failure fails the create with `instance.clone_private_names_failed`, and the create's `stop-container` step stops the started copy. A retry runs the write again.

Orbit does not change the template instead. The template lives in each container's own metadata, copied from its default. A new image would not reach existing defaults, and editing each default's metadata would add hidden state.

To check a new clone, run `getent hosts <its Route name>` inside it right after the create. It answers with the bridge's gateway address.

A task copy is a clone like any other, so it gets the same. So does a clone in the nested-fleet project, and a clone that an inner Gateway makes in its own fleet.

### Complete a clone

[`instance:complete`](/cli/instance/complete) is the second Operation of a clone create, kind `instance.complete` ([why](#a-clone-create-is-two-operations)). It does the part of a create that the user does not wait for: the clone's database, a nested fleet, the Processes, the Route and a check. The `checkout` and `T3` parts stay in the create. A clone is fully set up when its `status` is `active`, and only a succeeded completion makes it so.

The Gateway admits it for a container clone that is `source_resolved`, has its starting commit and has a registered T3 server; it takes the Instance lock. Anything else refuses with `instance.complete_unavailable` and changes nothing. The input is the create's admitted database plan and Processes, and a Route publication captured again from the current site catalog. The run has its own private folder and its own vault copy of the workspace value, so it never reads the create's folder.

The run's levels, from [Swamp shape](https://github.com/hardimpactdev/orbit/blob/main/swamp/docs/swamp-shape.md):

| Job | Steps by level |
| --- | --- |
| `input` | the intent |
| `complete` | stage the folder, and the database starts · the Valkey user, the database check, the fleet start · the fill · `clone-services` · `clone-check` |
| `publication` | the CSRs · the Route create child |
| `result` | `finish` and the private-folder cleanup · `withdraw-route` on failure, and the run-folder cleanup · `accepted` |

`clone-services` runs the `services` part of the workspace phase: PHP settings, file rights, Process files, web config, the Node store and the units. It refuses when the checkout mark names another Instance. It writes no tracked file, so the user's work in the checkout stays as it is. `clone-check` runs the `check` phase. A dead unit gives `instance.container_process_inactive`; the phase prints its `process_failure` receipt as its output, and the step keeps that output, because the step accepts the phase's refusal exit and `finish` reads the receipt from it.

Success turns the Route `active`, then the Instance `active` with `provisioning_step=active` and `first_setup_pending=false`, in one transaction. A failed or cancelled run withdraws a Route that it published, and keeps the clone `source_resolved` with `failed_step` and `error_code`. The runtime stays `running` and the Conn environment stays registered, so the user keeps the thread. The result's `recovery.retry.command` is `instance:complete`; `instance:destroy` removes the clone as for a failed create.

The completion holds the Route publication Nodes for the whole run. A clone's create captures no Route publication, so it holds no Node, and two creates admitted together never wait on each other. The budget is 1,800 seconds: the 600-second fill and the 900-second fleet start with margin.

The create's terminal projection admits the first completion. A completion that ends `succeeded` or `failed` moves a [task copy](/reference/tasks#task-copy) on, as a create's end does: `active` makes its task run, and a failure fails it.

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

The run withdraws the clone's Route, drops its owned server databases, then stops and deletes its Incus container with its Processes, T3 history, snapshots, storage volume and published proxy device. It removes the `instance-<id>` ACL from Incus project `default`, its UFW rules and its Node repository mirror. Deleting T3 retires every session and unused pairing link. Terminal projection removes execution copies and Conn records, then deletes the runtime row to free its address and T3 port. The default and other clones stay.

An Instance with an [inner volume](#the-inner-volume) loses that volume and its snapshots too, after the container. The run checks that the volume is gone.

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
- **No upstream.** A branch without an upstream gets no special case.
- `stashes`: stash entries that are not in the baseline.

Build output that appears after the create counts too. It stays work until the Project's `.gitignore` covers it.

The baseline is what a new Instance held when it was made, so a fresh copy of a default with local branches, stashes or untracked files reads `clean`. It holds the local branch heads, the stash ids and the untracked set, in the Gateway's `instance_runtimes.workspace_baseline`, outside the container.

A clone's create reads it before the transaction that ends the create, and writes it with `workspace_baseline_at` in that transaction. The user can reach T3 only through a pairing, and Conn pairs only after it reads the create `succeeded`, so the baseline holds no work of the user. The completion writes no tracked or unignored file, so the read stays `clean` while it runs. A failed read fails the create with `instance.workspace_baseline_unavailable`. A resumed create keeps a recorded baseline.

When the untracked set has more than 2,000 entries, or the answer would pass 900 KB, Orbit drops it, so every untracked file counts. Nothing rewrites a recorded baseline: not a deploy, a rename or a removal restore.

An Instance without a recorded baseline, such as one made before baselines existed or one moved into a container by a migration, compares with the Project default as it is at the time of the read.

A commit counts only when the default does not reach it from its local branches, so a default branch that moved on since the copy still covers what the clone inherited. A stash counts when the default does not hold it. Its answer says `baseline: default_now`, and all its untracked files count. If the default cannot be read, the answer is the plain state, `baseline: none`. A default Instance has no baseline: its answer is always the plain state.

Names from the workspace never become arguments. Git returns them NUL-separated, and they reach the Gateway only as JSON text, cut at 200 characters. Only object ids of 40 or 64 hex characters from the baseline join a `rev-list` call.

### Sleep and wake

A sleeping Instance's container is frozen. Its processes keep their state and memory, but the memory moves to swap: a frozen Laravel and T3 clone holds about 13 MiB of RAM and about 340 MiB of swap. Nothing boots at the wake, so a resume takes a fraction of a second. Its disk, databases, Route, T3 threads and sessions stay. A container never prunes its dependencies.

Incus writes `memory.swap.max = 0` for every Instance container from `limits.memory`, so a running container never swaps: a busy one meets its limit instead of filling the swap disk. A sleep opens swap for its own cgroup only (`max`), freezes the container, and then asks the kernel to reclaim its memory. A wake writes 0 again. Pages already in swap come back when they are used. No Incus setting changes.

When swap cannot take the memory (it is full), more than 32 MiB of anonymous memory stays in RAM after the reclaim. A frozen container would then hold RAM, which a sleep exists to free, so the sleep resumes the container and stops it. Its result says `sleep: stopped`; otherwise `sleep: frozen`. A stopped container keeps each Process unit's enablement, which holds its desired state, so its later boot brings back what ran before. A sleep that fails to freeze writes 0 back and removes the marker, and the container serves as before (`instance.container_freeze_failed`).

The runtime's `power_state` is `stopped` for both a frozen and a stopped sleeping container. Each reader treats them alike.

By hand, resume a frozen container before you stop it: `incus resume` first, then `incus stop`. A clean `incus stop` of a frozen container times out and leaves it running (Incus 7.0.2). `incus start` of a frozen container only unfreezes it.

| Part | State |
| --- | --- |
| [`instance:sleep`](/cli/instance/sleep) and [`instance:wake`](/cli/instance/wake) | Built for development clones. A default and a nested-fleet Instance do not sleep. A wake of a nested-fleet clone is its [start](#a-stopped-nested-fleet-clone-starts) |
| A Route visit wakes the Instance | Built |
| Sleep after an idle hour | Built; off until the operator turns it on. See below |
| The wake renews the Gateway's T3 session when it ends within 7 days or ended while the Instance slept | Built |
| The Conn thread index reads the server one last time before a sleep is admitted, while its switch is on | Built. See [Thread index](/reference/conn#thread-index) |
| A visit gets the app without the waiting page while the container is frozen | Built: [the Node resumes it](#the-node-resumes-a-frozen-container), on a Node whose `app-dev` role converged with the wake script |
| A long freeze becomes a stop | Planned |
| A Conn connection or a task turn wakes the Instance | Planned. Conn calls `POST /api/v1/instances/{id}/wake`, which has the same access rule as the Conn remove |

**The idle sleep.** The [runtime hibernator](/reference/app-dev-runtime-hibernation#idle-window-and-sweep) puts a development clone to sleep after an idle hour, with the same idle window and sweep as a checkout Instance. It acts only while `ORBIT_HIBERNATION_CONTAINER_SLEEP` is on; it is off by default. A clone sleeps only when no row of this table keeps it awake. The sweep checks them in this order, and the first match is the reason it reports:

| Reason | The clone stays awake when |
| --- | --- |
| `default`, `nested_fleet`, `not_development` | It is a default, a nested-fleet Instance, or not a development Instance. |
| `never_sleep` | Its id is in `ORBIT_HIBERNATION_CONTAINER_NEVER_SLEEP`. |
| `asleep` | It already sleeps. The sweep does not read it. |
| `busy`, `task` | It is not active, an Operation is open on it or on its Processes, or a task runs in it. |
| `keep_alive` | One of its Processes is desired-running with `keep_alive`. A frozen container runs no Process, so this keeps the whole container awake. |
| `node_service` | It uses a [Node service](/cli/instance/node-service-attach), such as PowerSync on another Node. The service may call back into the app, which a frozen app cannot answer. |
| `http_unreadable` | Its Node does not answer the read of the access logs. |
| `watch_starting`, `watching` | The sweep has not yet watched it for one idle window. |
| `http_active` | Its Route's access log changed within the window. |
| `woke_recently` | A wake of it ended within the window. |
| `t3_unreadable` | Its T3 server cannot be read. |
| `t3_turn_running` | A turn runs, or a run waits for a delegated thread or a background task. |
| `t3_active` | Its newest user message, turn end, or request that waits for the user is within the window. |
| `next_run` | The run already started five sleeps. |

A running turn is preparing, queued, starting or running. Missing data never puts a clone to sleep.

**The watch.** The first run that acts on a clone records when it began to watch it, in `idle_watch_started_at` of the Instance's runtime. Until one idle window has passed since then, the clone stays awake with `watch_starting` or `watching`. An access log written before the watch, such as the file a migrated Instance kept from its checkout, proves nothing about the time since. A dry run never starts a watch.

A clone without a Route sleeps on the watch, its wakes and T3 alone. A Node that does not answer, or a T3 server that cannot be read, keeps the clone awake, and the run says why.

A turn that runs is never cut, whatever its age. A thread that waits for the user keeps the clone awake only while its request is younger than the idle window; the freeze keeps the request, and the user's answer in Conn wakes the clone. A Conn client that is only connected is no activity: Conn keeps a socket open to every server it knows and pings it.

The Gateway reads T3 with the admin session it already holds and renews for each clone: one `GET /api/orchestration/shell` per watched clone, and none for a sleeping clone. It writes nothing in the container. It reads the access logs of all clones on a Node in one call to the Node agent.

Each sleep is an `instance.sleep` Operation with `triggered_by` `hibernation-sweep`, and its result says `t3_turn: idle`. A run starts at most five sleeps; the next run takes the rest. `orbit:runtime-hibernator --dry-run` on the Gateway host lists every container Instance with its decision, its reason and the evidence it used: the watch start, the last HTTP request, the last T3 activity or `turn running`, and the last wake. It changes nothing. `--instance=ID`, repeated, limits a run to those Instances and acts while the switch is off. `--idle-seconds=N`, at least 60 and only with `--instance`, gives that run its own idle window. A scoped run applies every rule above.

**Compared with the checkout rule.** The [checkout rule](/reference/app-dev-runtime-hibernation) stops only an Instance's Processes; its site keeps serving. The container rule freezes the whole Instance, site and T3 included, and it wakes on a Route visit, from Conn, or by [`instance:wake`](/cli/instance/wake).

**Terminals.** T3 0.1.7 shows no terminal state in an HTTP read; only its WebSocket API does. So an open terminal, or a process started in one, does not keep a clone awake by itself.

**HTTP activity.** Caddy's site of a container Instance writes each request to `/data/caddy/orbit/hibernation/app-instance-<id>.log`, and the idle sleep reads the file's modification time. Caddy creates the file when it loads the site, so a new site gets a full idle window. The log rolls at 1 MiB and keeps one compressed roll. A destroy removes the log, but not a roll.

A request with `X-Orbit-Probe: 1` and a request that meets the asleep marker write nothing: such a request starts a wake, and the wake counts by itself. A Route's TLS probe makes no request. An open browser tab keeps the clone awake while it sends requests; that is intended. Caddy logs a WebSocket, such as Vite's HMR socket, only when it closes, so an open tab that sends nothing else lets the clone sleep. An uptime check without the probe header keeps it awake too.


**The Route while asleep.** The Node's Caddy site of a container Instance checks for the asleep marker, `/data/caddy/orbit/hibernation/app-instance-<id>.asleep`. Without it, Caddy serves the request as before; the check is a file test on the Node and calls nothing. With it, Caddy calls the [runtime activation endpoint](/reference/app-dev-runtime-hibernation#wake), which shows the same progress page as for a checkout Instance and starts or joins the Instance's one `instance.wake`. A request with `X-Orbit-Probe: 1` skips the check. A failed wake keeps the marker, and the Route shows the failure page with Try again.

The marker lives on disk, not in `/dev/shm`: a host reboot loses the frozen state, but not the marker. A sleep writes the marker before it freezes the container.

**The Node resumes a frozen container.** <a id="the-node-resumes-a-frozen-container"></a>When the Instance's last sleep froze it and its site has a web unit, the wake does not start its run at once. The Gateway records the `instance.wake` Operation under the Instance lock, as always. After the commit it writes a wake request on the Instance's Node, `/dev/shm/orbit/hibernation/app-instance-<id>.wake`, with the agent's existing `hibernation.marker`. That folder belongs to root and the `caddy` group with mode `0755`, so only root writes in it. The agent stays frozen ([ADR 0253](/decisions/0253-patch-nodes-and-reboot-them-drained)).

The `app-dev` role installs the Node's part: the script `/usr/local/libexec/orbit-instance-wake`, the service `orbit-instance-wake.service` and the path unit `orbit-instance-wake.path`, which starts the service while a request exists. The service runs as root with no capabilities, a read-only file system except the two hibernation folders and the cgroup tree, and no new privileges. It needs root because the Incus socket and the container's cgroup file belong to root; membership of `incus-admin` would give the same power.

The script takes the Instance id only from the request's name, as digits, and never reads its content. It renames the request to `.taken`, and acts only when the request is at most one second old, the asleep marker exists, and Incus reports `instance-<id>` of `orbit-instances` as `Frozen`. Then it resumes the container, writes `memory.swap.max = 0`, and asks FrankenPHP once for an answer below 500, within 1.5 seconds. A resumed FrankenPHP still holds its socket, so one request tells. Then it removes the marker and writes `app-instance-<id>.resumed`. Last it removes the `.taken` file.

In any other case it changes nothing, and a failure after the resume keeps the marker, so the site never proxies to a frozen container. Each step writes one line to the journal of `orbit-instance-wake.service`, with the resume's and the probe's milliseconds.

The Gateway reads the request, `.taken`, `.resumed` and the marker with the agent's `hibernation.times`, first after 0.35 seconds and then every 0.1 seconds, within 3 seconds of the request. When the resume shows, the Operation succeeds at once: `wake: resumed`, `trigger_seconds` (the request's call) and `confirm_seconds` (from the request until the Gateway read the resume). Caddy's activation call gets `307` to the visitor's own path, so the browser sends the same request again, with its method and body, and gets the app. The waiting page is not shown. A request that arrives while the resume runs joins the wake and waits for it, up to the bound.

Anything else starts the run below, in the same Operation, and the visitor gets the waiting page as before. That covers a Node without the script, such as one whose role predates it. The first read also asks for `/usr/local/libexec/orbit-instance-wake`. When it is missing, the Gateway leaves that Node out of the fast path for 10 minutes, so its other wakes cost nothing extra.

It also covers a script that declined (another state, FrankenPHP silent), a failed agent call, and the bound. The Gateway then removes a request still there. The script ignores a request older than one second, so a request left behind never wakes the Instance later.

Each Incus call in the script has its own bound, so a resume ends within about 6.5 seconds of the request, before the workflow reaches the container. The script takes every pending request, also one that arrived during a resume, before it checks any library cache. A Gateway worker that dies during the resume leaves a queued wake: a delayed start runs its workflow 15 seconds later. A cancel during the resume keeps the Operation cancelled.

A Stopped container whose last sleep froze it, such as after a host reboot, now waits about a second longer for the waiting page, while the Node declines the request.

Measured on a frozen fixture clone before this path: the resume took 0.02 seconds and the web probe 0.4 seconds, but the run's start-up and playbook took 8 to 13 of the 9 to 19 seconds a visitor waited.

**The wake's run.** One Ansible run reads the container's state and the marker:

| Found | The wake |
| --- | --- |
| Frozen, marker | Resumes it, writes `memory.swap.max = 0`, and gives FrankenPHP 10 tries one second apart (about 20 seconds at most). It boots nothing and converges nothing. `wake: resumed` |
| Frozen, marker; FrankenPHP silent after the resume | Stops it by force, then boots it as below: a visitor would rather wait for a boot than for ever. `wake: restarted_after_resume` |
| Stopped, marker (after a host reboot, or a sleep that fell back to a stop) | Boots it. `wake: started` |
| Running, marker (after a host reboot with `boot.autostart`) | No start and no resume; checks FrankenPHP and removes the marker. `wake: already_running` |
| Running, no marker | Nothing to do. `wake: already_running`, `marker_found: false` |
| Frozen, no marker | A defect: the wake resumes it, and its result says `marker_found: false` |

The result also gives `resume_seconds` and `web_probe_seconds` for a resume. A boot starts the container and waits until each running unit is active. Then the run waits until FrankenPHP answers. A web unit that listens before the app is ready answers 5xx for a moment, so the run waits up to 5 more seconds for an answer below 500; an app that answers 5xx by itself is then served as it is. Then the run removes the marker, and the next request gets the app. A Project without a web unit has no web wait.

A converge cannot run a command in a frozen container, so a container that slept through a converge may wake with a library cache that lacks the shared runtime libraries. After the marker went, so the visitor does not wait, each wake runs one check in the container: when `ldconfig -p` does not list a soname of `/opt/orbit-runtime/current/lib` at that path, it runs `ldconfig -X`.

That is the converge's own rule: only names such as `libasound.so.2` count, not the file `libasound.so.2.0.0`, which the cache never lists. The run does it after its marker removal; the Node's wake script does it after every pending request. A failed check fails nothing, and each rebuild is logged ("rebuilt" in the run's output, "library cache rebuilt" in the journal).

A second check follows for the same reason. The converge keeps each container's Orbit root CA file current through the Incus file API, frozen containers included, but only a command in the container rebuilds the bundle. When `/etc/ssl/certs/ca-certificates.crt` lacks the root that `/usr/local/share/ca-certificates/orbit-managed-root-ca.crt` holds, the wake runs `update-ca-certificates`. A container without that file skips the check. It is logged as "refreshed" in the run's output and "CA bundle refreshed" in the journal, and it never fails the wake.

Only then does the run wait up to 30 seconds for T3 in a clone: T3 gates the end of the Operation, not the visitor's page. A wake whose T3 stays silent fails with `instance.t3_unready` while the site already serves. The runtime records `power_state` `running`, because the container runs. A wake that fails before FrankenPHP answers keeps the marker and the `power_state`, and the Route shows the failure page.

A destroy removes the marker. A sleep refuses with `instance.sleep_route_unready`, and changes nothing, while Caddy's loaded site of the Instance lacks the check: an older site gets it at the next publish of the Node's Caddy sites.

The [agent development servers](#agent-development-servers) site on port 444 has no asleep check: on a sleeping clone it answers 502 until a visit to the Route wakes the Instance.

**While asleep.** Schedules in the container do not run. At the resume, systemd timers that came due during the freeze run at once. Other Operations that need the running container refuse it with `process.target_inactive` or `schedule.target_inactive`. Env, mail and Node service updates and the task workspace read `power_state`. Process and Schedule work on the Node refuses a container that is not `Running`, and Incus reports a frozen one as `Frozen`.

A connection held open across a freeze meets the server's idle timeout at the resume, and clients reconnect. A default does not sleep, so a deployment or a clone of a sleeping default does not occur; a deployment's start resumes a frozen container before it converges. A destroy deletes a frozen container with `incus delete --force` and needs no wake.

**Conn.** The hourly renewal skips a sleeping Instance, so it never records `conn.environment_stopped` for one. A successful wake admits the renewal at once when the Gateway's session ends within 7 days or has ended: the run issues a new session in the container without the old one. Devices then renew their own pairings through the Gateway, with no new pairing by the user.

The [sleep](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/sleep.mmd) and [wake](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/wake.mmd) charts have the steps.

### Migrate a checkout Instance

Before downtime, migration resolves the [deploy credential](/reference/github-app#instance-source-credentials) and makes one read of the Project's default branch with a 30-second limit. Local branches, commits, stashes and tracked edits are carried; the other retirement guards still apply. Projects using `gh_cli` skip this App preflight. An unreadable source or timeout leaves the checkout serving. The local checkout still supplies the migration's mirror and files. The Git URL rewrite is quoted in `argv` so YAML cannot turn it into a mapping.

[`orbit instance:migrate INSTANCE`](/cli/instance/migrate) moves one existing checkout Instance into a container on its Node, with its code. A linked worktree can move first while its owner remains an active host checkout on the same Node. Migration starts from the stock image and copies its own workspace; it consumes no default seed. The owner moves after its last dependent leaves. The command is temporary: [Old paths](/reference/old-paths) lists it until the last checkout Instance has moved.

- **Stop.** Orbit removes the Instance's awake marker, and its Route shows the wake page. The Gateway starts no wake during the move.
- **Repository.** Orbit builds a standalone repository in the container; the checkout's `.git` is never copied.
- **Branch.** The Node's mirror fetches the Instance's branch from the checkout, and Orbit pushes it in.
- **Files.** Orbit copies the changed, untracked and ignored files, never the checkout's `.git`. It then removes the caches built at the old path ([rule](/cli/instance/migrate)).
- **Database.** A database on a Database server stays where it is, and the container gets its own way to it. A SQLite file in the checkout moves with the files.
- **Start.** FrankenPHP starts as `orbit`. A non-default Instance gets its T3 server, registered as in [Create](#create). A default gets its first seed snapshot.
- **Route.** Orbit points the Route at FrankenPHP in the container.
- **Retirement.** Orbit retains all files and Git recovery data. It removes only the linked checkout's registration. An owner's dependents and any registered descendant block retirement.

Registered Schedule timers keep their UUID, calendar, timeout and enabled or paused state. Their timers and commands run inside the container as `orbit`, only while it runs, without catch-up. The container's `/bin/bash` and PATH run each command, whatever the host account's login shell; a host path such as `/usr/bin/php8.5` becomes the container's `php`. [Schedule migration](/reference/schedules#checkout-migration) defines refusals, rollback and retained logs.

A failed move before cutover leaves the checkout Instance serving as before. The [migration chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/migrate.mmd) has the steps.

#### Migration symlinks

Orbit predicts symlinks against the copied layout before downtime, using the same resolver as the final copied-tree check. After copying, it checks and rewrites the exact workspace tree inside the container, before starting any Process. This final check maps absolute targets under the old checkout to the copied workspace; it never reads the host checkout. Links into Git metadata must target paths that the new repository is sure to create; other metadata targets refuse before downtime. A link changed during copying can therefore fail the move with the same error and rollback behavior.

A link must have both its named target and its resolved destination inside the checkout. Broken links, including cycles, and external targets refuse with `instance.migrate_symlink_unsupported`. The error lists every offending checkout path, its original target and the reason, with invalid UTF-8 bytes shown as `\xNN`. Orbit does not choose a different data source or run project setup to repair a link.

Prediction accepts these Git paths: `.git`, `.git/HEAD`, `.git/config`, `.git/index`, `.git/FETCH_HEAD`, `.git/objects`, `.git/objects/info`, `.git/objects/pack`, the checked-out branch at `.git/refs/heads/BRANCH`, the fetched branch at `.git/refs/remotes/origin/BRANCH`, and the directories that contain those refs. The workspace's init, fetch and checkout steps create these paths. Every other `.git/**` target refuses before downtime, including `.git/local-only`. Template files, reflogs and object filenames are not promised.

Directories absent after the Git checkout and file archive are copied too, including empty untracked and ignored trees. Added directories keep their exact modes, including setgid and sticky bits, with the normal `orbit`/`work` ownership mapping.

The Node passes their mode manifest through the protected execution result and stdin; it creates no manifest file in the container. Restoration runs as `orbit` and opens each path component relative to the workspace with directory descriptors that refuse symlinks. Absolute paths and parent traversal refuse before any mode changes. The directory scan excludes `.git` metadata and tracked submodule contents and never follows directory links. Selected nested repositories still enter the file archive as before. Absolute links in ignored directories follow the same rule, including links from `storage/framework/testing` into tracked `database/migrations` files.

A target ending in `/` or `/.` requires a directory at every link hop. A regular file with either suffix is a broken target and refuses, even if removing the suffix would make it work.

In the container workspace, an absolute link into the checkout becomes a relative link to the corresponding resolved destination. For example, `public/storage -> /fast/apps/example/default/storage/app/public` becomes `public/storage -> ../storage/app/public`. A relative link within the checkout keeps its exact target. This applies to tracked, untracked and ignored links, including local-package links; restricting it to `public/storage` would leave other checkout paths pointing at the host.

Orbit skips `.git` and installed dependency contents. A `vendor` directory beside `composer.json`, or `node_modules` beside `package.json`, is a dependency root. Orbit checks package links at its boundary, including Composer namespace and npm scope entries, without following them into package trees. Ordinary directories such as `resources/views/vendor` are checked normally. Rewrites use filesystem paths inside the container, so names and targets with non-UTF-8 bytes do not pass through Incus JSON arguments. The source links are never rewritten.

#### What is built for the move

The move is built for the Nodes of the [build list](#the-build-list).

| Part | What Orbit does |
| --- | --- |
| Admission | An active checkout Instance on a Node of the build list, with no Route beside its own. Otherwise `instance.migrate_unsupported` |
| Non-default | The Project default is on the same Node; source identity, T3 and model-router prerequisites still apply. No migrated default or seed is needed |
| Deployment | An unfinished deployment refuses the move with `instance.lifecycle_busy` |
| PHP version | A web-serving Project without a recorded version refuses with `project.source_defaults_incomplete`. Set `require.php` in its source, then run `orbit project:update PROJECT --source-access=github_app` to record it |
| Runtime row | Reserved at admission: the next free bridge address and, for a non-default Instance, the next free T3 port after the Node's last one. It names no clone source |
| While it runs | The Instance keeps its checkout path, and the reserved row does not turn its site. The run holds the Route's Node, and the Instance takes no deployment and no wake |
| Retirement check | Checks identity before downtime and retention. Carries local branches, stash history, staging and all copied file bytes. Active clean/smudge filters, intent-to-add, submodule changes, owner dependencies and registered descendants still block |
| Branch | The mirror fetches the recorded branch locally; the files phase imports every local branch tip and complete stash history into independent Git metadata |
| Files | One archive carries changed, untracked and ignored files and missing directories. Added directories keep their modes; ownership maps to `orbit`/`work`. Deleted tracked files are removed |
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

1. A phase in the container issues a session with `t3 auth session issue --label "Orbit Gateway" --token-only`, as `agent`.
2. The same phase issues the `Orbit Swamp` session for [Swamp drives T3](#swamp-drives-t3). PR 8 narrows its scopes.
3. It reads the served environment and asks T3 about both sessions at `/api/auth/session`. The reply waits in the run folder, mode 0600.

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
- Conn shows a server whose container is stopped as asleep. It does not wake it yet: the wake is PR 13 of the [build plan](/solutions/development-container-runtime).
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

Swamp drives a container's T3 server through T3's own CLI, run in the container. Nothing of Orbit runs in the container for it, and Orbit forks neither T3 nor Conn. This section is the contract of the `t3` task driver, which replaces the [`pi` driver](/reference/tasks#drivers) for tasks in Instance containers. The `pi` driver stays for task VMs until they go.

### Reach and authentication

Stock T3 0.1.7 serves its thread tools at `/mcp`, but accepts there only an MCP client session. A session that `t3 auth session issue` makes, as the create's `Orbit Swamp` session, is refused. A session issued with the MCP client subject is held to `approval-required`, because the CLI cannot give it a higher mode. Only T3 itself issues a full-access MCP session, through its OAuth approval or in its own process. So Swamp uses T3's own CLI:

- **Path.** One Ansible phase on the container's Node runs `incus exec` into the container, then `runuser -u agent`. The shell is not a login shell.
- **Lock.** Swamp locks the turn's step by the copy's Instance id for the whole turn. One copy takes one turn at a time; turns in two copies on one Node run side by side ([turn](/cli/task/turn#in-a-task-copy)).
- **Groups.** `runuser` keeps the agent's groups. `incus exec --user` would drop the `work` group, which owns the checkout's Git folder.
- **Session.** Each `t3 thread` command issues its own full-access session inside the container.
- **Session end.** The command calls T3 on its loopback address. At its exit, it revokes the session.
- **No credential outside.** No T3 session reaches the Node, Swamp, the vault or the Gateway.
- **Orbit Swamp session.** The create still issues it and keeps it in the vault. The turn does not use it. Destroy revokes it. Orbit does not renew it, so it ends after 30 days.
- **Workspace.** File and Git actions in the container go over SSH to the Node, then `incus exec`. No token enters the container.

### Calls

The phase uses these commands, as `agent` in `/srv/workspace`.

| Purpose | Command | What Orbit sets |
| --- | --- | --- |
| Start a thread with its first turn and wait for its run | `t3 thread start --provider P --model M --title T --wait --json /srv/workspace -` | The composed prompt on stdin, the provider instance of the effort and the [turn model](#turn-model-and-effort), the title `Orbit task GROUP thread THREAD`, and a 3,600-second `timeout` |
| Continue a thread and wait for its run | `t3 thread send --wait --json T3THREAD -` | The composed prompt on stdin and a 3,600-second `timeout`. The thread keeps its provider instance, so its model and effort. |
| Find the thread | `t3 thread list --project /srv/workspace --json` | Matched by its title: before a continued turn, and after every turn, also when the bound cut the wait before T3 printed the thread id. Bounded at 60 seconds |
| Read the thread | `t3 thread show --messages 200 --json THREAD` | The thread's status and its user and assistant messages. Bounded at 60 seconds |
| Put a reviewer on the candidate | `git status --porcelain`, `git symbolic-ref --short HEAD`, `git rev-parse HEAD`, `git checkout --detach CANDIDATE` | Before a reviewer turn: a clean checkout on a branch and its tip, then the checked candidate, detached. Each Git command is bounded at 20 seconds |
| Restore the checkout after a reviewer | `git reset --hard`, `git clean -fd`, `git checkout BRANCH`, then the status, branch and tip reads again | After a reviewer turn, also a failed or cut one: what the reviewer changed is discarded and the task branch is checked out again, at its earlier tip |
| Read the question of a run that asks | `node - T3 read T3THREAD`, with `t3_copy_rpc.mjs` on stdin | When the start exits 2. Prints the open runs, the pending requests with their questions, and whether Orbit may stop on them. Bounded at 60 seconds |
| Stop the run that asks | `node - T3 interrupt T3THREAD RUN REQUESTS`, with `t3_copy_rpc.mjs` on stdin | After the question's evidence is written. Stops the run only while it waits for exactly the stored requests, then waits up to 30 seconds. Bounded at 60 seconds |

The start returns the run's status. `completed` is `done`, `waiting` is `asking_for_input` once [the question ends the turn](#a-question-ends-the-turn), and any other status is `failed` with `tasks.turn_failed`. A wait cut by the bound is `failed` with `tasks.turn_timeout`. A start that T3 refused, for example while its server is down, is `failed` with `tasks.copy_turn_failed`.

A continued turn whose title T3 lists not exactly once launches nothing and is `failed` with `tasks.copy_thread_missing`; a list that fails or is not JSON is `failed` with `tasks.copy_turn_failed`.

A reviewer turn on a checkout with changes or no branch launches nothing and is `failed` with `tasks.copy_tree_dirty`, and one whose checkout is not clean on its branch at its earlier tip afterwards is `failed` with `tasks.copy_review_unrestored`, whatever its run did. The phase marks the detach as soon as it is done, so a reviewer turn cut before the restore is unrestored too.

### A question ends the turn

Stock T3's CLI cannot answer a question or stop a run. `t3 thread send` to a run that waits queues a second run behind it, and the answer never reaches the agent. Stock T3's server can do both through its own WebSocket RPC, which T3's web client uses:

- `POST /api/auth/websocket-ticket` with a bearer session gives a ticket.
- `GET /ws?orchestrationProtocol=2&wsTicket=TICKET` opens the RPC.
- `orchestration.getThreadProjection {threadId}` gives the thread's runs, its pending requests and each `user_input_request` with its questions.
- `orchestration.dispatchCommand` with `run.interrupt {commandId, threadId, runId, reason}` stops a run; `runtime-request.respond` with `answers` would answer one.

`t3 auth session issue` grants the session. Its MCP endpoint `/mcp` refuses that session, so the MCP tools are not a path.

So the phase ends a turn at its question. When `--wait` exits 2, `t3_copy_rpc.mjs read` stores every pending request with its questions: the id, header, text, options and flags, as the agent asked them. A free-text question without options is stored too. Only after that evidence is written does `t3_copy_rpc.mjs interrupt` stop the run.

The stop has two guards. The read must find questions alone: no approval, no request without a question, and no more text than the result can hold. And the run must still wait for exactly the stored requests. A run that someone answered meanwhile, or that now asks something else, goes on, so no new question is lost.

The helper runs on the runtime's Node.js, `/opt/orbit-runtime/current/bin/node`, from stdin. It takes a session of one minute, read-only for the read, holds it only in its memory and revokes it before it exits. No session reaches an argument, a file, a log or the run folder. T3 stays stock.

The turn is then `asking_for_input` and over, and its Operation succeeds. Its result and its thread keep the questions. The role's next turn quotes them before the caller's prompt and continues the thread with `t3 thread send`, as a normal follow-up. A question the helper could not read is `tasks.copy_question_unread`, and a run it could not stop is `tasks.copy_question_unstopped`. Neither is over, so the group holds.

This was tried on a throwaway T3 0.1.7 server on 2026-11-14. A run that asked two questions was read, then stopped: its run became `interrupted` and its request `cancelled`. A `t3 thread send` with the answer then ran at once as the thread's next run. `runtime-request.respond` with `answers` let the same run continue instead; Orbit does not use it, so a turn's end stays the run's end.

### Turn model and effort

The Gateway's `ORBIT_TASKS_COPY_IMPLEMENTER_MODEL` and `ORBIT_TASKS_COPY_REVIEWER_MODEL` name the Claude model of a new thread of each role. Both default to `claude-opus-5-5`: a container reaches only the Claude model forwarder. The effort is the role's [effort setting](/reference/tasks#drivers): `ORBIT_TASKS_IMPLEMENTER_EFFORT`, default `medium`, and `ORBIT_TASKS_REVIEWER_EFFORT`, default `high`.

Stock T3's CLI takes no effort, but T3's settings can hold several provider instances of one driver, each with its own environment. The create of a task copy writes `/home/agent/.t3/userdata/settings.json` with one `claudeAgent` instance per effort level: `claudeLow`, `claudeMedium`, `claudeHigh`, `claudeXhigh` and `claudeMax`, each with `CLAUDE_CODE_EFFORT_LEVEL` set to its level.

A new thread starts with `--provider` set to the instance of its effort. A turn's result names the provider instance and the model that `t3 thread show` gives, and the effort that instance carries. Claude Code takes `CLAUDE_CODE_EFFORT_LEVEL` over the `--effort` that T3 passes from its model catalog, so the thread runs at that effort. This was verified with Claude Code 2.1.311 and T3 0.1.7; a newer version may change it. A continued thread stays on its instance, so it keeps its model and effort. A copy made before these instances existed refuses new threads; cancel it and start the task again.

### Gaps of the CLI path

These hold until a T3 server gives Swamp a full-access MCP session:

- **No stop of a cut turn.** Orbit stops only a run that asks, through T3's own RPC. A turn cut by its bound may go on in the container.
- **Message text only.** The transcript holds the user and assistant messages, redacted and bounded to 256 KiB. It holds no tool names or states.
- **No token metrics.** The CLI reports no token use. The thread's metrics stay null.
- **No consult and no caller's model.** Both are refused with `tasks.copy_turn_unsupported`.

### Hold after a turn that may still run

A turn is over when nothing was launched, or when the start printed an end. It is also over when T3 lists its thread in no active state. A run that asks is over once Orbit stopped it. A reviewer turn whose checkout is not restored is not over. Nor is a turn cancelled before its run step reports.

One rule settles a turn that is not over: T3 lists its thread in no active state, or none of its title. For a reviewer's turn, the checkout must also be clean on the task branch, at the tip it had before the detach. Only a turn's phase can read that, so the next turn settles it:

- The Gateway puts every turn that is not settled into the next turn's intent, with its title. A reviewer's turn adds the task branch and the tip its detach mark held.
- The phase lists T3's threads first.
- While any earlier run goes on, the phase touches nothing.
- Once every earlier run is over, the phase restores a reviewer's checkout as after its turn: reset, clean, and the task branch.
- It then reads the checkout's changes, branch and tip.
- The phase writes which turns it found settled.
- While one is not, the phase launches nothing and fails with `tasks.copy_turn_unended`. The work in the copy stays.
- The result names the settled turns, and the Gateway counts them settled from then on.

A check, a publish or a proof of the copy refuses with `tasks.copy_turn_unended` while a turn is not settled. Only a turn settles it. When no turn can run, because the subtask is completed or a reviewer turn needs a check first, `task:cancel` is the only way out: it ends the copy, its work and any run with it.

The threads and roles, thread state, provider errors, token metrics, retries and transcripts below remain the target. Their MCP and WebSocket calls need a T3 server that gives Swamp its own full-access session.

### Threads and roles

Each subtask gets a new implementer thread, and its first review a new reviewer thread. A consult uses the reviewer thread. Titles, the composed prompt, Orbit's fixed instructions, the [turn receipt](/reference/tasks#turn-receipt) and the reviewer's reset after its turn stay as for Pi. The Orbit thread row records the driver `t3`, the T3 thread id as its external id, and the Instance's Node.

A new thread takes any model the model router serves for Codex or Claude Code. Claude runs through Claude Code, Anthropic's own application, so the `t3` driver does not refuse it; `tasks.model_refused` stays a refusal of the `pi` driver. The role defaults and effort settings stay.

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

The plan's [decisions](/solutions/development-container-runtime#decided) record the choices behind this contract.

Migration checks executable availability before downtime and requires moved running Processes to settle before cutover. The [`instance:migrate` contract](/cli/instance/migrate) defines the runtime command mapping, readiness window and recovery.

## Nested fleet

A Project setting turns on a nested fleet. Each Instance container of that Project then holds a fleet of Incus containers nested inside it: a Gateway with its own Swamp, an `app-dev` Node and an `app-prod` Node. Orbit uses it to prove its commands before a pull request. Other Projects leave it off and keep the kernel surface of [Inside a container](#inside-a-container).

Built: placement, host preparation, the start guards, the readers below and the [nested-fleet image](#the-nested-fleet-image). The [fleet step](#the-fleet-step) is built. The deployment of the default calls it in the [refresh after a release](#refresh-after-a-release), and the create of a clone calls it to [start its fleet](#a-clone-starts-its-fleet).

Every Instance of a Project with `nested_fleet` on is a container in the nested Incus project, on a [build-list](#the-build-list) Node with nested-fleet hosting on. One placement rule in the Gateway refuses every other result before any record, and it also chooses the Incus project. [Nested fleet setting](/reference/projects#nested-fleet-setting) has the rule. The sections from [The fleet](#the-fleet) on describe the target design.

### The container

An Instance container with a nested fleet lives in its own Incus project on the Node, `orbit-instances-nested`, which allows what the fleet needs. `orbit-instances` keeps refusing it. A Node prepares that project, its profile and two kernel modules only when it is on the build list and its [nested-fleet switch](/reference/node-settings#nested-fleet-instance-hosting) is on. It shares the pool, the bridge, the ACLs and the egress proxy with `orbit-instances`. The container is unprivileged and sets these keys:

| Key | Value | Why |
| --- | --- | --- |
| `security.nesting` | `true` | The inner Incus and Docker run in it |
| `security.syscalls.intercept.mknod` | `true` | The inner Incus makes device nodes for its containers |
| `security.syscalls.intercept.setxattr` | `true` | The inner Incus and Docker set trusted extended attributes |
| `security.idmap.isolated` | `true` | The container has its own ID range, as every Instance container |
| `security.idmap.size` | The runtime's [recorded size](#the-id-map-size): `101000000`, or `1165536` after a conversion | The inner range below must fit inside |
| `limits.memory` | `12GiB` | The inner fleet shares the container's memory |
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

After a host reboot, Incus restores the containers that were running, without the guard. A nested-fleet container has `boot.autostart=false`, so it stays stopped. An Orbit start of the Instance, such as its deployment, starts it again through the guard. A container made before the key existed gets it at its next start, before the guard reads it: it is the one key a start writes, and Incus applies it without a restart.

The outer container is only the operator of its fleet. It runs no fleet service itself. It holds:

- the inner Incus, its bridge and its pool on the [inner volume](#the-inner-volume); the first nested-fleet default, made before the volume, keeps a directory pool;
- the operator end of the fleet's WireGuard network, which the [start phase](#the-fleet-step) brings up with `ip` and `wg` at each fleet start;
- the nested Gateway's root CA and a CLI profile `nested`, so the candidate CLI in `/srv/workspace` talks to the nested Gateway.

The tunnel has no unit: `wg-quick` fails under the outer container's AppArmor profile.

### Capacity

Nested and normal Instances share the Node's address pool, T3 ports and storage pool. A `/24` bridge has 252 Instance addresses, `.2` to `.253`: the bridge takes `.1` and the image build `.254`. Each non-default Instance also takes one of the 200 T3 ports, `3800` to `3999`. Every root disk is 40 GiB on the `orbit-instances` pool.

At reservation, the placement rule refuses first, then a full bridge, then a full T3 port range; the last two refuse with `instance.host_unavailable` (409). So one Project with a default and 200 clones runs out of T3 ports first, and many Projects with defaults can run out of addresses first. Free pool space and the [subordinate-ID demand](/reference/node-settings#nested-fleet-instance-hosting), which the converge checks, can limit a Node earlier.

### Readers of the recorded project

Each runtime records its Incus project once. Every step that reaches the container reads that record: create, deploy, clone, destroy, Processes, Schedules, mail, Node services, migration and T3 setup. The shared-folder refresh restarts running containers in every project that the Node's runtimes record, before it clears the folder's restart mark, also after the switch is off. With no nested runtime, it runs the same command as before.

### The nested-fleet image

A Node with nested-fleet hosting on builds a second image in its nested Incus project, after the normal image. A Node with the switch off builds only the normal image. The normal image's definition, alias and build steps stay the same.

The nested-fleet image has the stable alias `orbit-instance-nested`. Its own alias is `orbit-instance-nested-` with 12 hex digits of a digest of its definition, so a changed definition is a new image. The digest names each inner Incus package by its name and SHA-256, not by its download address.

It is the normal image with these changes:

| Part | Change |
| --- | --- |
| Inner Incus | `incus-base` and `incus-client` from the recipe's pinned artifacts, checked by SHA-256 |
| Packages | `wireguard-tools`, `sqlite3`, `sudo`, `zstd` and `btrfs-progs` too. `zstd` reads the `.tar.zst` archives of the inner fleet's containers; the inner Incus's btrfs driver needs `btrfs-progs` for the pool on the [inner volume](#the-inner-volume) |
| User namespaces | No `/etc/sysctl.d/90-orbit-no-userns.conf` |
| Subordinate IDs | `/etc/subuid` and `/etc/subgid` give root `1000:1` and `1000000:65536` |
| AppArmor marker | `/etc/orbit/apparmor-off` holds `N` |
| `agent` | A member of the inner `incus-admin` group, with passwordless sudo from `/etc/sudoers.d/orbit-agent` (root:root, mode 0440) |
| `orbit` | Neither the group nor sudo. The first rule of the firewall's output chain drops its traffic to the inner fleet's networks, `10.45.0.0/24` and `10.232.2.0/24`. The other rules stay the same |
| Firewall reset | `destroy table inet orbit_agent_guard` instead of `flush ruleset`, also as the `ExecStop` of `nftables.service` |
| Network wait | `systemd-networkd-wait-online.service.d/90-orbit-no-dns.conf`: eth0 routable, at most 30 s, no DNS |

The firewall resets only its own table. So a reload or restart of nftables keeps the inner Incus's own tables, its bridge NAT and ACLs.

The inner Incus wants `network-online.target`. Netplan's own wait asks for a DNS server on eth0, which a container never gets, so it held a start for 120 s. The image's wait asks only for a route. It is not masked, so the inner Incus still starts after eth0 has its address.

The Node fetches each Incus package through the artifact cache, checks its SHA-256 and pushes it into the build container. The build container installs both packages with their dependencies through the egress proxy. A `policy-rc.d` file stops the packages from starting the inner Incus during the build; the build removes it before it publishes.

A failed fetch, a wrong digest or a failed install publishes no image. There is no unpinned fallback. Before it publishes, the build checks the sudo paths: no `ubuntu` user, no grant in `sudoers.d` other than `orbit-agent` with its one line, a grant that `visudo` accepts (judged by its exit code, not its output), `agent` alone in `incus-admin`, sudo works for `agent` and fails for `orbit`. An Instance start fetches and installs nothing. A failed build removes only a build container that it made; a build container from an earlier run stays for the operator.

A failed nested-fleet build fails only the nested-fleet image. The converge goes on: the normal image, the project, the bridge, the folders and the end checks complete, and the role succeeds. The Node records the failure in `/var/lib/orbit/instance-nested-image.json`: `nested image build failed`, the image's alias and the failed task. The converge log names the failed task too.

While that record exists, the create of a nested-fleet default refuses with `instance.nested_fleet_image_unavailable` before any Incus change, because the stable alias names an older image or none. A clone copies the default's seed, not the image, and goes on. The next converge builds the image again; a build that succeeds removes the record. A failed build of the normal image still fails the role.

### The fleet

The fleet runs under the inner Incus:

- three nested containers, `nested-gateway`, `nested-app-dev` and `nested-app-prod`;
- the inner storage pool: the directory pool `topology-seed` of the first nested-fleet default. A new nested-fleet default has an empty btrfs pool `inner` on its [inner volume](#the-inner-volume);
- the inner bridge `oe-nested` and the fleet's WireGuard network, which the nested Gateway leads; the outer container joins it as the operator.

A delegated ZFS dataset is not used: a copy would need one host clone per child dataset, and the inner Incus records the dataset's host name, which a copy changes. [ADR 0250](/decisions/0250-run-instances-and-task-groups-in-incus-containers) has the decision.

### The inner volume

A new nested-fleet default gets its own volume for the inner fleet, as the task VM had its seed disk. Its runtime records the volume's name in `inner_volume`, and the intent carries it as `container.inner_volume`. The [first default's create](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/create-default.mmd) makes it:

| Part | Value |
| --- | --- |
| Volume | `inner-<id>` on the pool `orbit-instances`, in the Incus project `orbit-instances-nested`; a sparse 30 GiB zvol |
| `zfs.block_mode` | `true`: a block volume that Incus formats with a file system |
| `block.filesystem` | `btrfs`: snapshots, restores and clones of the inner containers take about a second |
| `block.mount_options` | `user_subvol_rm_allowed,discard`: the inner Incus deletes its own subvolumes; freed blocks go back to the pool |
| `security.shifted` | `true`: the volume mounts idmapped. Without it Incus shifts every file to a copy's new ID map at attach, 31 to 39 s per copy |
| Device | the disk `inner-pool` at `/srv/inner-pool` |
| Inner pool | `inner`, driver `btrfs`, `source=/srv/inner-pool/pool` |

On the host this is one volume and one device on one container: no pool, profile or project key, no kernel module setting and no host package. Incus formats the volume with the host's `mkfs.btrfs`. The container does not see the block device; it reaches the host's btrfs code only through file operations and the subvolume calls of its own file system, and the volume's size caps its space.

A failed create keeps the volume with the container for the retry, which reuses it. [Destroy](#destroy) deletes it with its snapshots.

The first nested-fleet default and every Instance without `inner_volume`, its copies included, keep today's shape: no step makes, snapshots, copies or checks a volume for them.

`TaskVmNestedFleet` in `apps/gateway/app/Domain/TaskVms/TaskVmNestedFleet.php` holds every address, MAC, memory limit, subnet and path of the fleet. This page names none of them twice.

In the outer container, `/etc/subuid` and `/etc/subgid` give root `1000000:65536`, plus `1000:1` for the [workspace mapping](#fleet-rules). The first nested-fleet default, and every default made from a nested-fleet image built before the release that brought the ID map size, gives `1000000:100000000` until its [conversion](#convert-a-default). Either range fits inside the outer map.

### The ID map size

Each nested-fleet runtime records the size of its isolated ID map in `idmap_size`. The create and the clone write it as `security.idmap.size`, and the start guard requires that value.

| Runtime | Size |
| --- | --- |
| A new nested-fleet default, or a migrated nested Instance | 101,000,000 |
| A copy, a plain clone or a task copy | Its default's size at the copy's reservation |
| A converted default | 1,165,536: the inner Incus's base of 1,000,000, its range of 65,536 and 100,000 spare |
| A normal container | None: Incus's 65,536 |

The inner fleet's three containers share one range of the outer container. They use IDs up to 65,534 (`nobody`), and Docker in them runs without user namespaces, so 65,536 IDs are enough. Every nested container mounts its root disk idmapped (`volatile.last_state.idmap` is `[]`), so a changed outer size moves no file on the host.

A copy keeps its size for its whole life. So copies made before a conversion keep 101,000,000 and still match their start guard, beside new copies of 1,165,536. The [task start check](#the-check-at-a-task-start) and the converge's [subordinate-ID demand](/reference/node-settings#nested-fleet-instance-hosting) read the recorded sizes.

#### Convert a default

An operator converts a nested-fleet default once, outside any task turn. The steps:

1. Stop the default. Its copies keep running; their ranges do not change.
2. Take a container snapshot `pre-idmap-<time>`. Incus needs a free range of the old size for it, for a moment.
3. Write `/etc/subuid` and `/etc/subgid` in the container: `root:1000000:65536` and `root:1000:1`.
4. Set `security.idmap.size` to `1165536` on the container.
5. Run `php artisan gateway:instance-idmap-size <id> 1165536` on the Gateway host. It records the size and clears the default's seed, so clones refuse until a new seed exists.
6. Start the outer container and its inner containers once by hand. The inner Incus then rewrites the owners of their files on its pool, once.
7. Deploy the default. Its start passes the guard with the new size, and the deployment takes a new seed. New copies get the small size.

At step 6 the inner containers' stored maps do not fit the new inner range, so the inner Incus maps them again. That rewrite is a walk over their files, so it takes minutes on a large pool.

The command refuses a size other than the two above with `instance.idmap_size_invalid`. It refuses an Instance that is not a nested-fleet default with `instance.idmap_size_not_nested_default`. It refuses a default that an open Operation holds with `operation.target_busy`. It refuses a size below a copy's recorded size with `instance.idmap_size_copies_smaller`, so a rollback waits until the small copies are gone. It holds the default's row lock, as a task copy's admission does.

A rollback destroys the copies made after the conversion, restores `pre-idmap-<time>`, which brings back the old config and the old inner range, and runs the command with `101000000`. The inner Incus rewrites the owners once more.

#### The seed pair

The seed of such a default is a pair: the container snapshot `seed-<commit>` and the volume snapshot of the same name. A container snapshot does not hold an attached custom volume, so the name ties the two. The [seed step](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/deploy-default.mmd) takes the volume's half right after the container's, with the Processes still stopped, wherever it takes the seed. It retakes the pair when the volume's half is missing.

A [retake](#a-retake-keeps-the-seed) takes both new halves under `next-<commit>` when the seed's name is taken. A failed volume snapshot deletes both new halves and fails with `instance.seed_volume_snapshot_failed`, so no reader finds half a pair. The old pair stays whole, also at the same commit. Older pairs go only after both new halves exist. A failed refresh checks that both halves exist before it restores anything; a failed restore of the volume after the container's fails the run.

A deployment retakes the two halves one after the other. So a clone of such a default is not admitted while a deployment of the default is open (`instance.default_seed_in_flight`), as the refresh waits for an open clone (`instance.clone_in_flight`). A default without an inner volume has no such wait.

#### Copies of the volume

A copy of such a default, a plain clone or a task copy, gets its own volume `inner-<copy id>`, which its runtime records. The [clone step](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/create.mmd) checks that the default's volume holds the seed's half, else `instance.default_seed_incomplete`. It copies that snapshot to the new volume. Then it copies the container with its disk `inner-pool` set to the new volume, before the first start. Both copies are ZFS clones. Incus gives the copied volume a new btrfs fsid, so copies never share one. In the 2026-11-14 test the two copies took 3.8 to 4.2 s and the outer start about 1 s.

The start guard admits the copy's volume with exactly the keys above. A failed container copy deletes the volume it copied. A volume without its container, left by a failed copy, is deleted and copied again. Destroy of the copy deletes its volume. A copy shares blocks with the default's seed until it goes, as a container copy does.

### Fleet rules

Each rule below is a device or config key of a nested container, a file it reads, or a key of the inner bridge `oe-nested`. The container rules are in `base`, because a restore of `base` reverts device and config keys. The bridge is in the inner Incus, not in a container, so a restore of `base` keeps its keys. A file rule is owned: the refresh writes the whole file where it differs, root-owned with mode 0644. The rules are the shape of the first inner fleet, which was built by hand and runs.

| Rule | Where | Why |
| --- | --- | --- |
| The workspace `/srv/workspace` is the disk device `checkout`, with `shift=false` | `nested-gateway`, `nested-app-dev` | An edit reaches them at once. A shifted mount fails two levels below the host |
| `raw.idmap` `both 1000 1000`, `uid 2750 2750` and `gid 2760 2760` in the inner Incus | `nested-gateway`, `nested-app-dev` | The workspace is `agent:work` (2750:2760) with files of `orbit` (1000). With only the `orbit` line, nested root cannot resolve the state mounts. The outer container still has no `raw.*` key |
| The Club key folder device `club-key`: `/run/orbit-club` at `/home/orbit/.config/swamp/club`, `shift=false`, and the link `/home/orbit/.config/swamp/api-key` to `club/swamp-club-api-key` | `nested-gateway` | The nested Swamp reads the [Club key](#the-club-key-of-the-nested-gateway) that Orbit delivers to the outer container's `/run` |
| A read-only disk device that shows `N` at `/sys/module/apparmor/parameters/enabled`, from `/etc/orbit/apparmor-off` | `nested-app-dev`, `nested-app-prod` | `docker run` fails without it: Docker's own profile cannot load two levels below the host |
| `HTTPS_PROXY`, `HTTP_PROXY` and `NO_PROXY` in the drop-in `50-inner-fleet-proxy.conf` for `orbit-swamp-serve.service` and `orbit-swamp-worker@.service`, one `Environment=` line | `nested-gateway` | Swamp verifies a new Club key at Swamp Club, and the fleet reaches the internet only through the Node's [egress proxy](#egress-and-dns) |
| The same proxy settings as `environment.*` keys in the inner Incus. `NO_PROXY` is `localhost,127.0.0.1,::1,.orbit,10.45.0.1,10.45.0.0/24,10.232.2.0/24` | All three | A nested Node downloads the agent and packages; fleet names stay off the proxy. PHP's HTTP clients may not match `10.45.0.1` to its range |
| The egress files: the egress proxy for apt in `/etc/apt/apt.conf.d/90orbit-package-cache`, and the same proxy settings, `NO_PROXY` included, in `/etc/environment` after the image's `PATH` line | All three | A Node's base converge runs apt and downloads the agent over SSH with sudo. See below the table |
| `security.nesting=true` and a memory limit | All three | Docker runs in them |
| `boot.autostart=true` | All three | They start with the outer container, also after a restore of its seed. With `false`, the start still starts them, and the refresh writes `true` |
| `raw.dnsmasq` `no-resolv` on the inner bridge `oe-nested` | The inner Incus | A name the fleet does not know is refused at once, not after a silent wait of about 15 s. See below the table |

The bridge's dnsmasq answers the fleet's own names. Without `no-resolv` it forwards every other name to the outer container's resolver. That resolver has no DNS server and refuses at once, but dnsmasq drops the refusal and sends no answer. Each lookup then waits for its timeouts: about 15 s in the start of php-fpm, `orbit-websocket` and the Swamp workers. With no upstream, dnsmasq refuses at once. `gateway.orbit` resolves through the nested Node's `orbit` link, which this key does not change.

The cost: while `raw.dnsmasq` is set, the inner Incus runs the inner bridge's dnsmasq without its Incus AppArmor profile. It still runs as the user `incus`, inside the unprivileged outer container.

Incus restarts the bridge's dnsmasq when the key changes, so only the refresh writes it, with the fleet stopped. A start only compares. A copy whose seed lacks the key starts as before. A bridge that cannot be read is refused with `instance.container_fleet_drift`.

The `environment.*` keys reach only `incus exec`. SSH and sudo sessions read `/etc/environment` instead, so the egress files give the base converge its proxy. The apt file replaces the task VM package cache that the image names. No Instance runs that cache. A role add through the nested Gateway still writes the cache back, and the next refresh replaces it. The refresh writes both files before the converge.

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

The step runs right after `Start the container`, before any check of the inner fleet. That covers the restart after a failed refresh too, which starts the container through the same `start` phase. A clone runs its own step, `clone-club-key`, right after `clone-container`.

In Ansible the value is the module stdin of a pipelined task with `no_log`. Without pipelining, or with remote files kept, Ansible writes the module with its arguments to `~/.ansible/tmp` on the Node. So the task forces pipelining and refuses with `instance.nested_club_key_unsafe_transfer` before it reads the value when pipelining is off. A clone gets the value on the SSH stdin of `instance_clone.py club-key`. Both pass it on as the stdin of `incus exec ... sh -c`.

Root owns the folder, so the key's owner cannot plant a link there that would send the key to the disk. The write refuses with `instance.nested_club_key_not_tmpfs` when `/run` is not a tmpfs.

The Gateway's code does not add the `club-key` device to nested-gateway: the [fleet step](#the-fleet-step) does, in its rules and its start, and an existing fleet gets it by hand. Until then the key reaches the outer container's `/run` only.

A container that Incus starts by itself after a host reboot has no key until Orbit starts or deploys it again. `/etc/orbit/swamp-club-api-key` is the hand-placed file that this replaces; nothing writes it.

### Egress of the nested fleet

The nested Nodes reach the internet through `oe-nested`, the outer container's NAT and the Node's egress proxy, so the proxy's lines carry the Instance's bridge address.

### Users

Two users share the outer container, and only one of them reaches the fleet.

`agent` drives the inner Incus as a member of its admin group. It restores `base`, runs commands in the nested containers and reads their journals. It has root in its own container through `sudo` with no password. It is never root on the Node.

`orbit` cannot use the inner Incus. A firewall rule by uid in the outer container drops its traffic to the fleet's bridge and WireGuard subnets, as the image does for the model route. The rule is needed because the nested Gateway trusts the source address. Without it, any user in the container would be the fleet's operator.

### Club key and snapshots

[The Club key of the nested Gateway](#the-club-key-of-the-nested-gateway) owns the delivery. For the fleet:

- The key is never in `base`, never in a seed snapshot and never in an export: it lives only in `/run`, a tmpfs.
- Swamp verifies a new key once at Swamp Club before serve runs. It goes through the egress proxy, by the proxy rule above.
- Until the key is verified, serve exits with code 75. A verified key needs no network to run.
- An agent with root in a clone can read the key. A task in any other Project never gets it.
- The fleet step and the refresh never read, write or copy the key.

Before the delivery, an operator placed the key by hand in `/etc/orbit/swamp-club-api-key`, on the container's disk. While that file is there, the seed step of a nested-fleet default refuses with `instance.fleet_key_on_disk`. It tests the path only.

### Refresh after a release

After each Gateway release, Orbit deploys each nested-fleet default to the release commit. The deployment is `instance.deploy` with more steps, which only an intent with `fleet` runs. It first takes the seed of the default as it is, fleet included. Then it starts the inner fleet, refreshes it, takes the seed snapshot again and starts the fleet again. The [refresh chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/fleet-refresh.mmd) has the steps. An `instance:deploy` of the default runs the same flow.

A default gets `fleet` when its runtime is in `orbit-instances-nested`, its Project has `nested_fleet` on and its Node's switch is on (`InstancePlacement::keepsFleet`). Every other deployment runs the same steps and calls as before.

The release step "Admit nested-fleet refresh" runs `instances:refresh-nested-fleets --commit <release>` from the new release. It admits one deployment per nested-fleet default. With none, it prints one line and admits nothing. A default whose admission fails with an error that is no refusal gets the line `operation.admission_failed`; the other defaults are still admitted.

The Gateway setting [`ORBIT_INSTANCES_NESTED_FLEET_REFRESH`](/reference/gateway-environment) turns the step on. It is off by default; while it is off, the step prints one line and admits nothing. The live fleet has it on since 13 November 2026. An operator turns it on only when the Club key lives off the default's disk and a safety snapshot of the default exists.

The run deploys the release commit itself. A commit missing from the Project's repository refuses the admission with `project.source_unavailable`. A commit off the Project's branch fails the fetch with `deployment.commit_not_on_branch`, and the old seed stays. The trial Project's repository is a hand-kept copy of `main`, so its release runbook pushes the release commit there before the release. The Orbit Project itself needs no such step.

| Rule | What happens |
| --- | --- |
| Best effort | The release step allows failure, and nothing in the release waits for the run. A refusal or a failed run leaves the release live |
| No refresh during a copy | A clone copy reads the seed that a refresh replaces. While a clone's create is open, the admission refuses with `instance.clone_in_flight` |
| One run at a time | An open deployment of the default holds its Instance lock. A second admission refuses with `operation.target_busy` |
| The fleet stays | A step that fails from the checkout on restores the newest seed: the one the run took first, or a newer one. A restore never goes back past the fleet |
| Check before a change | The check compares the inner fleet with its [rules](#fleet-rules) before the first seed. A refusal, `instance.container_fleet_drift`, changes nothing and restores nothing |
| A failure names its step | The Operation names the first failed step and its code, such as `instance.container_fleet_refresh_failed`. `error.detail` names the failed task and its message |
| Migrate before Doctor | The start checks only the containers and the tunnel. The refresh migrates the nested Gateway before its Doctor wait |
| The fleet runs after the run | A passed run starts the fleet again after the seed. After a restore, the run starts the restored fleet |

A Swamp step that exits nonzero keeps no output. So a failed fleet phase or seed step writes why into the run's result file, and the Gateway shows it as `error.detail`. For the readiness wait, that is the Doctor body. For a seed, it is the [cause](#a-retake-keeps-the-seed), such as no free ID range.

The release step that admits the refresh ends before the refresh does. So a failed refresh that a release admitted also writes the Activity row `instance.refresh_failed`: status failed, command `instances:refresh-nested-fleets`, with the Operation, its code and its detail. `activity:list` and the problem collector show it.

The checkout moves under the running nested Gateway, with its old schema and processes. So the start of the fleet checks only that the containers run and the tunnel reaches the hub. The refresh migrates and restarts the nested Gateway. When the converge is due, it waits for the three agents and converges the Nodes. Only then does it wait for Doctor.

#### What a refresh skips

A refresh does only the parts whose inputs changed, as the task VM refresh did. Each part has a digest. The refresh compares them with its record, `/var/lib/orbit/fleet-refresh.json` in the outer container.

| Part | Digest | When it runs |
| --- | --- | --- |
| Migrate the nested Gateway, restart its services | Git object IDs of `apps/gateway` and `apps/agent/contract`, which it reads | The digest differs |
| Wait for the three agents | | The converges run |
| Base converge of the three nested Nodes | The `swamp` files a base converge reads, the nested Gateway's digest of each Node's base intent, and the fleet intent | The digest differs, a digest cannot be read, or the last converge that ran is more than 7 days old |
| Agent delivery, grants, state mounts, fleet files, readiness | None | Every run. The delivery writes into the checkout. The grants live on the nested Gateway's own disk (its state mount `swamp/grants`) |
| Renew the nested Gateway's Swamp tokens, restart its services | The names and creation times of its six tokens | With the Club key: a token is missing, older than 20 days or unreadable. See [The nested Gateway's tokens](#the-nested-gateways-tokens) |
| Stop the fleet, apply the rules, retake `base` | See below | Only when the fleet's disks changed |
| Build the pinned shape tools in the outer container | The checkout's three pin files, through `bin/swamp-shape-gate`, and four packages | The tools of these pins are not complete, or a package is missing. Every run reads them. See [The pinned shape tools](#the-pinned-shape-tools) |

The migrate follows `apps/gateway`, not only its migrations folder. Packages in its Composer lock ship migrations too, so a new lock can bring a migration.

The converge digest follows what a base converge of a nested Node reads, so a release that changes only tests, other workflows or other Gateway code converges nothing. It has three inputs:

| Input | What it holds |
| --- | --- |
| The `swamp` files that `swamp/ansible/fleet-converge-inputs.json` lists | The converge workflow, the `ansible-input` behavior and its imports, the playbook and the task files it reaches, and the play's configuration and modules |
| The nested Gateway's digest of each Node's base intent | The agent pin and its configuration, the recipe, the kernel and update policies, the SSH target, the CLI plan and the [units of the Node's roles](/reference/node-provisioning#role-units-at-a-converge) |
| The fleet intent | The fleet's names, layout, checkout and proxy |

A cover test, `swamp/tests/fleet_converge_inputs_test.ts`, walks the playbook and the imports. It fails on a file that the list does not hold.

After the migrate, the refresh runs `php artisan orbit:node-converge-digest --base gateway app-dev app-prod` in the nested Gateway. The command builds each intent as `node.converge` with `base: true` does. It admits no Operation, makes no execution copy and writes nothing.

A file list cannot follow the Gateway code that builds an intent: through its models, that code reaches much of the Gateway. So the nested Gateway hashes the intent itself. The intent holds no time, token, signed URL or random id. The Operation id and the agent verifier live in the execution copies, which are not part of it.

The list leaves out four include groups of the playbook: roles, software, Tools and the network. Each runs only when the intent selects roles, Tools or a network, and a base intent never does. The cover test fails when the guard of such an include changes.

A digest that cannot be read makes the converge run and is not recorded, so the next refresh runs it too. The record also holds `converged`, the time of the last converge that ran. When that is more than 7 days old, the converge runs whatever the digests say. This guards against an input that no digest covers, such as a package of the inner image.

When the converge runs, the three Nodes converge one by one. They cannot run side by side: every step of the converge workflow has a fixed behavior name. Swamp locks each behavior, so a second run would wait for the first one's lock and fail after 60 s. A failed converge fails the refresh, and the run restores the old seed, as for any failed step.

A reset of a fleet restores `base` of all three containers in 7 to 8 s. Taking `base` is the slow part: about 32 s for each container on the directory pool, plus its stop and start. So the refresh retakes `base` only for the containers whose disk changed, and the set stays consistent:

| What changed | `base` retaken for |
| --- | --- |
| A step that writes a nested container's own disk reported a change: the migrate (output without "Nothing to migrate" counts as applied), or the grants reload (a recap other than `changed=0`) | That container: today the nested Gateway, whose disk holds the database and the grants |
| The nested Gateway's tokens were reminted | The nested Gateway, whose disk holds the tokens and `.env` |
| A converge reported a changed task on a Node | That Node and the nested Gateway, whose database records the converge |
| A nested container has no `base` | That container |
| The record is missing or unreadable, or a fleet rule or file differed on the running fleet | All three, with the rules applied on the stopped fleet |

The refresh lists each step that writes a nested container's own disk once, with that container. A new writer joins that list. So a reset after a refresh that migrated or reloaded grants comes up on the new schema and grants.

The nested Gateway's other two state mounts need no rule. The refresh writes nothing to `swamp/vaults`: on the live default, no file there changed during a refresh.

The nested Gateway's serve loads the workflows of the checkout as they change, with no step of the refresh. It does not register the [schedules of the Swamp Node](/reference/operations#schedules-of-the-swamp-node), `swamp-credential-monitor` and `swamp-datastore-watch`: they have no built-in trigger, and only the production release load sets them.

Swamp serve writes `swamp/.swamp` all the time, with or without a refresh. It holds run records, outputs, an audit log and caches built from the checkout. These are records, not inputs. After a reset, serve builds the caches again.

With only some containers stopped, the rules only compare. A rule that differs on a running container fails the refresh, so the refresh never writes to a container whose `base` it keeps. Each container to retake stops; the others keep running. While the nested Gateway is stopped, the agents of the other two lose it and connect again after its start, as after the restart of its services. The start of the refreshed fleet starts what stopped and waits for readiness. If nothing changed, the fleet keeps running and `base` stays.

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

With the Club key, every refresh reads their names and creation times with `swamp access token list`, which shows no secret. The tokens are due when one is missing, one is older than `fleet_token_max_age` (20 days), or the list cannot be read. Then the refresh runs the `remint` play of `gateway-bootstrap.yml` on the nested Gateway:

1. It stops the workers and serve, which hold the token store open.
2. It makes the worker set whole (`gateway-swamp-workers.yml`). A worker without token files gets its folder and tokens. A fleet built with workers a and b only gets worker c. Its first refresh remints with `tokens_reason: incomplete`.
3. It renews all six tokens, then enables and starts serve and the three workers.
4. It ends only when serve and the three workers are active.

The refresh then restarts the nested Gateway's services, because they read the new tokens. It checks serve and the three workers before any converge.

The remint takes about 1 to 3 minutes. A refresh that does not remint reads the list once, in about 2 seconds. A remint writes the nested Gateway's disk, so its `base` is retaken. A failed remint fails the refresh with `instance.container_fleet_refresh_failed`, its step "Renew the inner Swamp tokens", and the run restores the old seed. No token reaches a log line or the result. Without the Club key the nested Swamp does not run, and the tokens are `skipped`.

#### The pinned shape tools

The proof runner in a copy needs the pinned Ansible controller of `bin/swamp-shape-gate`: a venv and the Ansible collections, about 160 MB. A copy gets the default's seed, not the image, and the default is never rebuilt from a new image. So every refresh keeps the tools in the default itself, after `base` and before the seed. Each copy then holds the tools of its own commit. No copy builds them, and no copy needs `python3-venv`.

The same step keeps three host programs that Swamp fixture units run: `zip`, `unzip` and `jq`. A [task check](/reference/implementation-loop#the-scoped-task-check) in a copy runs those units, so the copy needs them too.

The step sends `swamp/ansible/instance_shape_tools.py` into the outer container and runs it as root, with the workspace as its argument. The script loads the checkout's own `bin/swamp-shape-gate`. The gate names the tools from its three pin files: `/opt/orbit-shape-gate/venv-<digest>` from `swamp/ansible/shape-gate-requirements.txt` and `swamp/ansible/requirements.txt`, and `/opt/orbit-shape-gate/collections-<digest>` from `swamp/ansible/requirements.yml`. The gate also builds the venv. So the step, the gate and the proof runner always use the same names, and no version or naming rule exists twice.

| State in the default | What the step does | Result |
| --- | --- | --- |
| The venv has `.complete`, the collections folder has `ansible_collections`, and the four packages are installed | Reads only, in about 1 s. Removes the tools of other pins if an earlier cleanup failed | `tools: present`, `tools_reason: current` |
| The tools are ready, but a package is missing | Installs the missing packages with one `apt-get install` | `tools: built`, `tools_reason: missing` |
| Anything else | Builds the tools, as the list below says | `tools: built`, `tools_reason: missing` |

The four packages are `python3-venv`, `zip`, `unzip` and `jq`. The step reads each one with `dpkg-query`. The image lacks `zip`, `unzip` and `jq`. So the first refresh after this change installs five packages, with `libjq1` and `libonig5`, about 0.75 MB. The `apt-get update` and the install add an estimated 10 to 20 s, once. The step changes no image and moves no image alias.

A build does five things:

1. It installs the missing packages with one `apt-get install`.
2. It builds the venv with the gate's own `tool_path`.
3. It installs the collections into a `.partial` folder and renames it. Collections that are complete already stay.
4. It writes the venv's `.complete`, the marker of ready tools.
5. It removes the `venv-*` and `collections-*` folders of other pins.

The build reaches three services: the Ubuntu mirror (apt, for the packages), the Python package index (pip) and Ansible Galaxy (`ansible-galaxy`). All three go through the Node's egress proxy: apt by its own setting in the image, pip and `ansible-galaxy` by `HTTPS_PROXY` from the Instance profile. The first build in a copy took about 30 s. The folders are mode 0755 and the files 0644, so `agent` reads them. The runner's own `ansible-galaxy collection install` then finds every collection and writes nothing.

A failed build or package install never fails the refresh. The refresh goes on and takes the seed. The result says `tools: failed`, and `tools_reason` names the step: `names` (the checkout's gate cannot be read), `package`, `venv`, `collections` or `cleanup`, `timeout` after 600 s, or `unreadable` when the script printed no result line. The run's log then shows the end of the step's error output.

The gate and the runner take the tools as ready only when the venv holds `.complete`. The script writes that marker last, after the collections are in place, so a failed or stopped build leaves no venv that looks ready, and the next refresh builds again. A proof in a copy without ready tools builds them in its own cache, as before, which needs `python3-venv` and the internet. When that build fails, the fleet preparation fails with one line from the runner (`proof_controller` in `swamp/proof/runner/fleet.py`). The line names the cache build that failed and ends with the missing folder, `/opt/orbit-shape-gate/venv-<digest>`.

The Operation's result has `fleet_refresh`. It holds `migrate` (`applied`, `nothing` or `skipped`), `converge` (`ran` or `skipped`), `converge_reason` (`changed`, `record`, `digest_unreadable`, `stale` or `unchanged`), `changed` (the tasks the converges changed), `tokens` (`reminted`, `young` or `skipped`), `tokens_reason` (`age`, `incomplete`, `unreadable`, `young` or `no_key`), `tools` (`present`, `built` or `failed`), `tools_reason` (see [The pinned shape tools](#the-pinned-shape-tools)), `base` (`retaken` or `kept`), `base_containers` (the containers whose `base` was retaken), `restart` (`yes` or `no`), `record` (`present`, `missing` or `unreadable`) and `seconds` per part.

When `base` stays, the seed is taken while the inner fleet runs. The inner storage pool is a directory pool on the outer container's disk, one ZFS dataset. So the seed is one point in time of all three containers and the inner Incus database, the state a power cut leaves. The nested Gateway's database is SQLite, which recovers from a power cut. A copy boots its nested containers cold, as a copy of a stopped seed does.

The first seed of every run, "Keep the current seed", was always taken with the fleet running. On a nested proof Node with a directory pool, the seed is a file copy and not one point in time; that affects only proofs.

A passed run starts the fleet again after the seed and waits for readiness. If only that start fails, the run fails with `instance.container_fleet_resume_failed`. The new seed and `base` stay. After a restore, the run starts the restored fleet. If that start fails, the detail says so too.
| An update, not a rebuild | The image variant's tools change only with a new image |
| The next release waits | A release waits up to 600 seconds for open `instance.deploy` Operations before it loads Swamp, then fails with `gateway.release_busy`. Cancel an open refresh before the next release |

It replaces the release trigger and the daily run of the [task VM refresh](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/task/vm-image-refresh.mmd) for the nested-fleet path, and calls neither; [Old paths](/reference/old-paths) says when those go. A Project has one default Instance in the whole fleet today, so the refresh runs on the Node that holds it.

What stops during a refresh:

- The outer container keeps running. Its Processes stop only for the seed snapshot: under 1 s on ZFS, plus their stop and start.
- The inner fleet runs from its start. A nested container stops only for its own `base`.
- A container stopped for `base` stays stopped for the last seed. It starts again after the seed.
- When `base` stays, the fleet runs through the seed.
- A clone starts its own copy.
- The run's first seed stops the Processes as briefly as the last one.
- After a failure, the outer container stops for the restore (at most 30 s for the stop) and starts again.

| Step | Time | Source |
| --- | --- | --- |
| Start the inner fleet (running fleet: rules, link, tunnel) | About 6 s, measured | Operation 19765 |
| Apply the checkout: delivery, grants, migrate, restart | 9 s, measured | The first build by hand |
| Converge of the three nested Nodes | Not measured in a container | |
| Readiness after the converge | 16 s, measured | The first build by hand |
| Retake `base` (stop and three snapshots) | 94 s, measured | The second experiment |
| Seed snapshot of the outer container | Under 1 s on ZFS, measured | The ADR 0250 spike |
| Start the stopped fleet to a healthy Doctor | 31 to 55 s, measured | The second inner fleet experiment on a Node |
| A whole refresh that converges and retakes `base` | 6 min 32 s to 6 min 38 s, measured | Operations 20079 and 20121 |
| A whole refresh whose converges change nothing (`base` kept) | About 3 min 55 s, estimated | Operation 20121 minus stop, `base` and start |
| A whole refresh that changes neither `apps/gateway` nor `swamp` | About 2 min 20 s, estimated | Operation 20121 minus the converges too |

Each step has a bound. The check has 300 s, the first seed 120 s, the start 300 s and the refresh 3,600 s. The start of the refreshed fleet has 600 s. The restore has 150 s, the start after it 120 s, and the start of the restored fleet 600 s. The run budget grows by their sum, 5,790 s. Inside the refresh, the agent wait and the Doctor wait are bounded by 300 s each, and each Node converge by 900 s.

### A clone starts its fleet

A new Instance or a task of the nested-fleet Project is a ZFS clone of the default's seed, as every [clone](#create) is. The clone then starts the outer container and places the Club key. Its step `clone-fleet` runs the [fleet step](#the-fleet-step)'s check and resume. It waits for the inner Incus and checks the fleet rules. It starts the nested containers and the operator tunnel `wg-nested`. Then it waits for the same Doctor check that a proof uses: the nested Gateway is healthy and its three agents are online.

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

A nested-fleet clone does not sleep, and its container does not start at boot (`boot.autostart=false`). A host reboot, an out-of-memory kill or a stop by hand leaves it stopped. A start by hand with `incus start` gives it no Club key and no tunnel `wg-nested`. So [`instance:wake`](/cli/instance/wake) of such a clone starts an `instance.start` Operation instead of a wake. The [start chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/start.mmd) has the steps:

The Gateway admits the start only for an active development clone whose runtime keeps a fleet (`InstancePlacement::keepsFleet`). The request's Idempotency-Key keys the start, and a second call joins the open start.

The run reads the Club key once, at its start. Without a key the fleet's Swamp cannot start, and Doctor cannot pass. So the run then fails before any change, with `instance.nested_club_key_missing`.

The step `Start the container` runs the `start` part of `instance-container.yml`, as a deployment does. It keeps `boot.autostart=false` and checks the egress gates of every bridge NIC. Then it starts the container and delivers the [Club key](#the-club-key-of-the-nested-gateway) into its `/run`.

The step `Start the inner fleet` runs the [fleet step](#the-fleet-step)'s check and resume, as the clone's create does. It checks the fleet rules and starts the nested containers and the tunnel. Then it waits for Doctor. It records its time as `fleet_seconds`.

A success records `power_state` `running`. The result also gives `nested_fleet_club_key`.

Each step leaves what already runs as it is, so a start of a running copy only checks it and writes its proxy bypass. The start does not wait for T3: T3 starts with the container, as at every boot.

Its fleet step also writes the copy's [proxy bypass](#proxy-settings-in-a-container) for units and login shells. T3 restarts once so that it reads the value, but only when the write changed the drop-ins or the manager environment and this start booted the container. Incus records each start as `last_used_at`. A copy that was already running keeps its T3, because someone may work in it. A nested-fleet default does not take this path: a wake refuses it with `instance.sleep_unsupported`, and only its deployment starts it. The run budget is 1,200 seconds; a clone's fleet start took 91 seconds (Operation 20180).

### Reset

A reset restores `base` of the three nested containers, then waits for the same Doctor check. `agent` runs it. A reset command is planned. Restore of all three: 7 to 8 s, measured; Doctor healthy 33 s after, measured. On a directory pool a restore copies back only the files that changed since `base`, so its time grows with the change. The proof's limit is 45 seconds. On the [inner volume](#the-inner-volume) each restore is a btrfs snapshot restore: 1.9 to 3.1 s per container in the 2026-11-14 test, whatever changed. The reset on that volume follows separately.

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

Six phase task files keep the fleet of a nested-fleet Instance. The deployment of a nested-fleet default calls the check, the start, the refresh and the resume through the tags `fleet_check`, `fleet_start`, `fleet_refresh` and `fleet_resume` of `instance-container.yml`. The create of its clone, and the [start of a stopped clone](#a-stopped-nested-fleet-clone-starts), call the check and the resume ([A clone starts its fleet](#a-clone-starts-its-fleet)). No flow calls the rules or the reset alone yet.

Each phase runs on the Node and reaches the inner Incus with `incus exec` into the Instance container. Their input is `intent.container` and `intent.fleet`; the header of `swamp/ansible/tasks/instance-fleet.yml` defines it. The Gateway fills `intent.fleet` (`NestedFleetIntent`) from the fleet constants of task VMs, with the commit of the old seed, for the deployment of the default and the create of its clone.

| Phase | Task file | What it does |
| --- | --- | --- |
| Check | `instance-fleet-check.yml` | Waits for the inner Incus and compares the fleet with its rules. It changes nothing |
| Rules | `instance-fleet-rules.yml` | Applies the fleet rules below to the stopped fleet, then checks them |
| Start | `instance-fleet-start.yml` | Checks the rules, starts the stopped nested containers, brings up `wg-nested`. Then checks the containers and the tunnel (`instance-fleet-reach.yml`), never Doctor. A stopped clone's start adds `instance-fleet-proxy.yml` |
| Refresh | `instance-fleet-refresh.yml` | Migrates and restarts the nested Gateway, converges the three nested Nodes, waits for readiness (`instance-fleet-ready.yml`), stops the fleet and takes `base` again |
| Resume | `instance-fleet-resume.yml` | Runs the start, then waits for readiness: the fleet after a passed refresh, or the fleet of a restored seed |
| Reset | `instance-fleet-reset.yml` | Restores `base` on the three nested containers, then runs the start and waits for readiness |

The fleet rules:

- The disk device `checkout` gives the workspace to the nested Gateway and `app-dev` with `shift=false`. An ID shift fails two levels below the host.
- The disk device `club-key` gives the folder `/run/orbit-club` of the container to the nested Gateway at `/home/orbit/.config/swamp/club`, with `shift=false` and `required=false`.
- In the nested Gateway, `/home/orbit/.config/swamp/api-key` links to `club/swamp-club-api-key`. `base` keeps the link.
- `raw.idmap` of the inner Incus maps `orbit` (1000), `agent` (2750) and the group `work` (2760) into the nested Gateway and `app-dev`, so the nested `orbit` and the container's `orbit` own the workspace together.
- The AppArmor-off device is on the nested `app-dev` and `app-prod`.
- `boot.autostart=true` is on the three nested containers.
- The egress proxy settings are `environment.*` keys on the three nested containers, and the drop-in `50-inner-fleet-proxy.conf` for `orbit-swamp-serve.service` and `orbit-swamp-worker@.service` in the nested Gateway.
- The egress files are on the three nested containers: the apt proxy file `/etc/apt/apt.conf.d/90orbit-package-cache` and `/etc/environment` with the same proxy settings.

The refresh writes an egress file that differs on the running fleet, before the converge of the nested Nodes. The rules then find it in place.

Orbit owns every rule except `raw.idmap`: the refresh writes an owned rule that differs (a key or device while the fleet is stopped, a file before the converge), and the check and the start let it pass. `raw.idmap` is a build rule, because a new map remaps a container's disk. It is written only where the key is absent. A fleet whose `raw.idmap` holds another value, or a nested container or the inner bridge that cannot be read, is refused with `instance.container_fleet_drift` before any change; the check and the start also refuse a fleet that lacks `raw.idmap`.

The Operation's result `fleet_drift` names each difference, as `<container> <kind> <name>`; for the inner bridge the first part is the bridge, as in `oe-nested network raw.dnsmasq`.

The deployment runs the check right after it starts the container, before it takes its first seed. A refusal there changed nothing, so no seed is restored. A failure from the checkout on, such as a start that fails after a change, restores the seed.

A restore of `base` reverts devices and configuration keys. So the rules must be in `base`. The start lets a missing owned rule pass, because the refresh after it writes the rule. The reset ends with the same start and has no refresh after it; no flow calls it yet.

Readiness is the proof's own check, from the container over `wg-nested`: every nested container runs, Doctor of the nested Gateway is healthy within a bounded wait, the three agents are online and the Gateway knows the operator with its key. A failure names the nested container or the check. A failed Doctor wait names what its last poll got: the HTTP status and the first 300 characters of the body, or curl's error when no response came.

A read right after a start waits for what the nested container sets up at boot, at most 60 s, and accepts only a ready value. The start takes the nested Gateway's hub key only when it is a WireGuard key: `wg-quick` makes the interface before it sets the key, and in between `wg show orbit public-key` prints `(none)` with exit 0. With no key in time, the start fails with "the nested Gateway's WireGuard key was not ready after 60 s" and sets no peer.

The refresh waits in the same way until the state mounts of the nested Gateway and `app-dev` are active, with the nested Gateway's Swamp state mounts when the Club key is there.

Every API call of the fleet step passes `--noproxy '*'`. The Instance container's environment sends every address but the egress proxy's own through that proxy, which cannot reach the tunnel; it answers `CONNECT tunnel failed, response 503`.

A command that the fleet step runs as the app user in a nested container, the agent delivery and the migrate, runs with `runuser -u orbit`. That gives `orbit` its groups, among them `work`, which opens the checkout. `incus exec --user 1000` gives only the user ID, with group 0.

The fleet step never reads, prints or copies the Club key file. Without the file, the fleet starts and is ready; only Swamp in the nested Gateway stays down.

## Git in and out

A container holds no GitHub credentials. It can read public repositories through the [egress proxy](#egress-and-dns), but it cannot push. Orbit moves the Instance's branches from the Node, as it does for [task VMs](/reference/compute-drivers#git-on-a-task-vm): it fetches with a minted read token and pushes the branch into the container's repository. Publishing a branch is an Orbit operation: Swamp reads the branch out of the container and pushes it with a minted write token. The tokens never enter the container.

## Production Instances

Step 10 of ADR 0250 moves production Instances into containers on `app-prod` Nodes, after development containers work. The decided basics:

- The `app-prod` role installs the container setup and the runtime folder, not PHP-FPM. It installs no agent folder.
- A production container has only the user `orbit`: no T3, no agent and no model route.
- FrankenPHP serves it once it has proved itself in development, by the criteria of the plan's [production gate](/solutions/development-container-runtime#production-gate).
- Production containers never sleep. Schedules stay native Swamp schedules that run their command in the container as `orbit`; the per-minute Laravel scheduler runs inside it.
- Existing production checkouts are migrated, as development checkouts are.

The release flow, with release containers, gets its own specification in the first production PR of the [plan](/solutions/development-container-runtime#production-instances).

## Records

The create flow of the [default Instance](#the-default-instance) writes the runtime row; its create and each deployment record the seed commit.

Each Node records its Instance bridge, as [Nodes](#nodes) describes. Each container Instance has one runtime row, `instance_runtimes`. A checkout Instance has none.

| Field | Meaning |
| --- | --- |
| `incus_project`, `container_name` | The container in Incus on the Instance's Node |
| `bridge_address` | The container's address on its Node's bridge. It is a guest address: never the network, broadcast or first usable address, which the bridge keeps |
| `t3_port` | The port of the Node's WireGuard address that publishes T3, from 3800 to 3999. The default Instance has none |
| `runtime_version`, `agent_version` | The versions of the shared folders the container started with |
| `operation_id` | The migrate Operation that owns this reservation. A new retry takes ownership before its run starts |
| `clone_source_instance_id` | The default Instance it was cloned from. Null for the default Instance and for a migrated checkout |
| `power_state` | `running` or `stopped`. A sleeping container is stopped. The terminal results of create, deploy, migrate, sleep and wake write it |
| `seed_commit` | A default Instance's commit of its newest `seed-<commit>` snapshot, which clones copy. Null before its first seed |

The database keeps these rules:

- A Node's container, bridge address and T3 port each belong to one runtime.
- A runtime keeps its Node, container, address, port and clone source; only the versions and the power state change.
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
| `instance.migrate_unsupported` | 409 | `instance:migrate` of an Instance that is not an active development checkout on a Node of the build list |
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
| `instance.t3_unready` | 502 | The T3 server did not answer within 30 seconds of its first start in a clone, or of a wake. After a wake, the app already serves |
| `instance.web_unready` | 502 | FrankenPHP gave no answer within 30 seconds of a wake. The asleep marker stays |
| `instance.clone_in_flight` | 409 | The refresh of a nested-fleet default while the create of a clone of it is open. The next release tries again |
| `instance.fleet_key_on_disk` | 502 | The seed of a nested-fleet default found the Club key as a file on the container's disk. The run's first seed refuses before it changes anything |
| `instance.fleet_seed_missing` | 502 | A failed refresh found no seed to restore, or the [inner volume](#the-inner-volume) lacks the seed's half. Nothing is restored, and the default stays as the run left it |
| `instance.container_fleet_drift` | 502 | The inner fleet of a nested-fleet default differs from its rules where Orbit does not write, such as `raw.idmap`. The Operation's result `fleet_drift` names each difference. Nothing changed |
| `instance.default_seed_moved` | 409 | A deployment of the default replaced the seed that a clone's create admitted, before the copy. The step's report names it; run the same command again |
| `instance.default_seed_missing` | 409 | The default has no seed snapshot at all. Deploy the default; its seed step takes one |
| `instance.refresh_failed` | none | An Activity row, not an API error: a refresh that a release admitted failed. It names the Operation, its code and its detail; see [Refresh after a release](#refresh-after-a-release) |
| `instance.container_seed_failed` | 502 | The seed step could not take the new snapshot. The error detail names why, such as `No free ID range for the new seed`. The old seed stays; see [A retake keeps the seed](#a-retake-keeps-the-seed) |
| `instance.seed_volume_snapshot_failed` | 502 | The snapshot of the [inner volume](#the-inner-volume) failed after the container's. Both new halves are deleted and the old pair stays; see [The seed pair](#the-seed-pair) |
| `instance.default_seed_incomplete` | 409 | The default's seed has no half on its [inner volume](#the-inner-volume), such as a seed taken before the pair existed. Deploy the default once |
| `instance.default_seed_in_flight` | 409 | A clone of a default with an [inner volume](#the-inner-volume) while a deployment of the default is open. Create the clone when it ends |
| `instance.container_process_inactive` | 502 | A required unit is inactive when a clone records its sessions. The Operation names the unit and records its states, exit status and restart count |
| `instance.create_register_failed` | 502 | A clone's registration step failed: T3 refused a session, or the Gateway's lacks its scopes. The step's report names the `conn.*` code |
| `instance.database_clone_unsupported` | 422 | A clone names another Database server than the default's, or the default has no database on a server to clone |
| `conn.environment_asleep` | 409 | A pairing with a sleeping Instance's T3 server |
| `instance.sleep_unsupported` | 409 | `instance:sleep` of an Instance that is not a development container clone, or `instance:wake` of a nested-fleet Instance |
| `instance.sleep_route_unready` | 409 | `instance:sleep` while Caddy's loaded site of the Instance lacks the asleep check. Nothing changed |
| `node.instance_bridge_exhausted` | 409 | No `/24` of the fleet range is free for a Node's Instance bridge |
| `instance.container_nodes_invalid` | 500 | `ORBIT_INSTANCES_CONTAINER_NODES` is not a JSON list of Node ids |
| `instance.host_unavailable` | 409 | A build-list Node cannot be prepared: it lacks the pool `orbit-instances` or enough subordinate IDs, or the recipe has no pin for it. |
| `instance.nested_fleet_host_unavailable` | 409 | A new nested-fleet Instance on a Node whose nested-fleet switch is off. Not reachable yet |
| `instance.nested_fleet_container_required` | 409 | A new nested-fleet Instance would not be a container: the Node is off the build list, or the default is a host checkout. Not reachable yet |
| `instance.nested_fleet_image_unavailable` | 502 | The create of a nested-fleet default while the Node records a failed build of the [nested-fleet image](#the-nested-fleet-image). Orbit makes no container; converge the Node again |
| `instance.host_unavailable` | 409 | A container default cannot be placed: the Node has no bridge, the bridge is full, or the runtime folder lacks the Project's PHP build |
| `instance.browser_trust_mismatch` | 409 | The image build found another `libp11-kit0` than the trust module needs. The image is not published ([Trust](#trust)) |
| `instance.clone_browser_trust_mismatch` | 502 | A clone's copy holds another `libp11-kit0` than the trust module needs. The create stops before the copy starts |
| `instance.clone_browser_trust_refused` | 502 | A path of `agent`'s NSS store in a clone's copy has another type than expected, or is a link. The create stops before the copy starts |
| `instance.clone_trust_bundle_refused` | 502 | A clone's copy has no regular `/etc/ssl/certs/ca-certificates.crt`, or the file API could not read it. The create stops before the copy starts |
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

The boundary that matters is the preview URL, which reaches only `orbit`. A switch for agent commands adds little. The container holds only its Project's `.env`, and its traffic goes through Orbit's egress proxy. Codex also has no documented setting for its shell.

### Clones of a default that never runs T3

A clone of a container that ran T3 serves the same environment id. It also accepts the same sessions, the Gateway's among them. The default Instance is the one clone source, and it never runs T3, so no clone inherits an identity. A clone starts at the default's last successfully deployed seed without setup steps. A merge alone does not refresh that seed.

### Shared folders per Node

One update reaches every container, and the image keeps only what rarely changes. An idmapped mount keeps one gid valid in every container.

### One proxy for all egress

A container gets full outbound access, but only through one proxy on its Node, so a later rule can narrow it in one place. Without the proxy, a container's packets would leave through the Node, and a packet to a fleet address would leave as the Node's WireGuard address, with the Node's access. The table of the proxy's user stops that whatever a name resolves to. DNS also goes through the proxy: a resolver in the container would be a second way out and would send lookups of fleet names to the VPN's DNS.

### Registration from the result

A step may call the Gateway only to sign or to mint. A container that could call the Gateway would act as its Node.

### A clone create is two Operations

Decided on 2026-11-13 by the owner of the create experience. The user needs only the checkout, `.env` and T3 to start work; the Processes, the database fill, a nested fleet and the Route can follow.

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
| `orbit` user | Could not run `t3`, `claude` or `codex`, read `/home/agent`, reach the model route or T3's port, or see agent processes |
| Shared workspace | Each user edited the other's files; Git was clean for both |
| Egress | Only DNS and the guard; no turn failed |

## Private Node runtimes

Migrate and clone prepare a writable `VP_HOME/js_runtime/node` directory in the new container. The launch phase copies the recipe Node once, with no download. A real copy avoids dangling version links when the Node replaces and retires its shared runtime folder: Vite+ 1.4.0 cannot install over a dangling version symlink. Unpinned Projects initially use the same recipe binary contents.

Vite+ resolves and downloads additional pins as `orbit`, through the Instance proxy, with its own signature and checksum verification. Resolution uses `vp env current node --json`; only a missing resolved executable runs `vp env install VERSION`. Orbit does not implement Node pin precedence. Each other pin on the Process-directory-to-workspace path is also checked in isolation by Vite+, so a malformed child cannot silently fall back to a valid parent.

Migration prepares Node before quiesce. It mounts the serving checkout read-only for this phase and removes the mount even after failure. The phase has a 150-second bound; tool resolution and downloads share 90 seconds. Failure records `instance.node_runtime_prepare_failed` with the pin and a specific malformed-pin, network, HTTP, checksum, disk or unknown-version reason while the checkout keeps serving.

Clone resolution and downloads share 60 seconds instead. Its guest helper allows 75 seconds for store preparation and fetch; all commands in the workspace phase share a 115-second deadline, inside the existing 120-second shell and 130-second Swamp limits. The Operation budget stays unchanged.

Clone converts an old seed's whole-store link as the app user, retains private downloaded versions from new-style seeds, and fetches the selected branch's pin before starting Processes. No privileged command traverses or changes the private store. The launch phase installs a root-owned helper outside the store, but runs all conversion, copy, validation and downloads as `orbit`.

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
