---
title: "Instance containers"
description: "ADR 0250, partly built: every Instance runs in an Incus container cloned from the Project's default Instance, with T3 inside. A user drives its T3 in development, Swamp drives it for a task. A Project setting turns on a nested fleet."
covers:
  - apps/gateway/app/{Models/InstanceRuntime,Actions/Nodes/AllocateInstanceBridgeAction,Domain/Nodes/NodeBridges}.php
  - apps/gateway/app/Domain/Instances/{InstanceRuntimePowerState,ContainerNodes,InstanceHostIntent,InstanceImage,InstanceFolderPlan,InstanceNotes,InstanceContainer,InstanceEgress,DevelopmentMail,DevelopmentInstanceCreateProjection}.php
  - apps/gateway/app/Domain/{InstanceMigration/**,Instances/Environment/DevelopmentCreateEnvironment.php,Instances/Deployment/{ContainerDeployOutcome,DevelopmentDeploySteps}.php}
  - apps/gateway/database/migrations/2027_02_{04_000000_add_seed_commit_to_instance_runtimes,04_000002_add_operation_owner_to_instance_runtimes,05_000001_clear_completed_container_default_projections,05_000002_clear_completed_container_default_projections_by_route_target}.php
  - swamp/ansible/{instance-container.yml,instance-container-destroy.yml,instance_node_runtime.py,tasks/instance-node-runtime.yml,tasks/instance-container-phase.yml}
  - swamp/{scripts/instance_clone{,_guest}.py,ansible/instance_{bridge,network,node_services}.py,extensions/behaviors/operations/instance-dev-command/result.ts}
  - apps/gateway/{config/instances.php,database/migrations/2027_02_03_000000_create_instance_runtimes.php,resources/instances/{notes/**,frankenphp.Caddyfile}}
  - swamp/ansible/tasks/{instance-host,instance-folder,instance-image,instance-egress,instance-network-check,instance-network-nic-check,instance-network-move,instance-network-move-nic,instance-bridge-rules,instance-bridge-refresh,instance-mail}.yml
---

# Instance containers

**Partly built, limited to the [build list](#the-build-list).** This page describes the delivered parts and the remaining target of [ADR 0250](/decisions/0250-run-instances-and-task-groups-in-incus-containers#build-order). The [Container runtime plan](/solutions/development-container-runtime) tracks the remaining work. Existing checkout Instances stay until migrated. Task groups still run on [task VMs](/reference/compute-drivers#task-vms).

| Part | Delivered state |
| --- | --- |
| Records, Node preparation, egress and DNS | Built |
| Default create and deploy, clone, forced non-default destroy, checkout migration | Built on the build-list Nodes |
| Task containers, nested-fleet parity, sleep/wake and production containers | Planned |
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
| Put an idle Instance to sleep | [container/sleep.mmd](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/sleep.mmd) |
| Wake an Instance | [container/wake.mmd](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/wake.mmd) |
| Register and renew the T3 server | [container/t3-register.mmd](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/t3-register.mmd) |
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

The internal `--withdraw --reconcile` writer mode retains persisted translated tuples without resolving stopped Docker publications. Readers deduplicate each tuple by its latest admission. Host convergence and explicit writer withdrawal compact or remove records, retaining admissions at or after their captured time. A systemd oneshot refreshes resolved endpoint rules at boot after Docker and Incus, then every 15 seconds. It only adds rules, so a failed resolution never removes a working path.

Each confirmed endpoint removal also removes its obsolete refresh records under the same writer lock, including confirmations from bounded readback after a timeout. Retained shared endpoints and admissions at or after the reconciliation capture stay eligible. A Docker record without a saved translated tuple refuses reconciliation before any ACL mutation. Partial reconciliation persists `reconcile.pending`: ordinary refresh cannot clear its failure health, and a refresh after a crash reports `instance.bridge_state_unresolved`. A subsequent successful full reconciliation removes that marker and clears the warning.

Gateway admission times are captured before reading targets. Owned ACL rules carry the same time in a strictly parsed description. Reattaching a tuple advances its rule’s stamp. Convergence preserves records and rules admitted at or after its capture, including admissions that arrive while it runs. Conditional ETag writes recheck this fence before deletion. An unknown description is never removed.

The timer recovers missed Docker events and DNAT rules installed after a start event. Admission, refresh, host convergence and withdrawal hold one exclusive lock at `/var/lib/orbit/instance-bridge/writer.lock` before reading records, through the last record and ACL write. A concurrent refresh refuses immediately; a new invocation reads the remaining records, so it cannot restore a removed endpoint.

Lock acquisition makes one nonblocking attempt. An owned lock returns `instance.bridge_busy` immediately, without changing endpoint records, ACL rules or health. Retry the operation after the current writer finishes. Docker and NAT reads use the helper’s remaining budget: 30 seconds for refresh and 300 seconds for lifecycle work. Each Incus curl request keeps its five-second limit, with one request reserved for readback. A read timeout or curl exit 28 returns `instance.bridge_command_timeout` and releases the lock.

Failure recording uses the held lock; a caller that cannot acquire it reports the error without waiting again. An unchanged run leaves active records and health unchanged and sends no Incus write request. A Docker re-address heals after the next successful refresh; a host converge also removes the obsolete resolved tuple.

Refresh errors stay in the journal. A writer that acquired the lock also records its error in `/var/lib/orbit/instance-bridge/health.json`; a busy refusal leaves that file unchanged. The agent reads only its named public error code into its `host` fact, on the existing 60-second observation interval. Doctor reports `node.instance_bridge_unhealthy`, including that error code, and clears it after a healthy observation. The host role checks the enabled, running timer and healthy state file at its end.

The converge creates no Instance. Each existing `app-dev` Node needs one converge of its Instance host role. It reconciles the attached-target union and moves existing NICs to the proxy ACL. Running containers keep working, with no restart.

A task VM host on the build list, such as Beast, gets the container branch beside its task side. The two sides share nothing they change: the Instance side has its own project `orbit-instances`, bridge `orbitinst0`, pool `orbit-instances` and `orbit:instances-*` input rules, and touches no task VM, task container or CI place. The task side keeps the ZFS ARC: on a task VM host the Instance side writes no cap. Only a task VM's own Node gets no branch.

Before it changes anything, the converge refuses a bridge range that another interface of the Node holds, with `instance.host_unavailable`. The Gateway only knows the bridges it records, not one an operator made, such as a CI bridge.

The Gateway gives each Node a bridge network, a `/24` of the fleet range `10.253.0.0/16`, and records it on the Node. It takes the first `/24` that overlaps no recorded bridge and not the VPN subnet. A Node keeps its bridge. When no `/24` is free, the allocation fails with `node.instance_bridge_exhausted` (409). A task VM host's bridges may not overlap an Instance bridge either: `node:update` refuses them with `node.settings_invalid`. T3 servers are published on ports 3800 to 3999 of the Node's WireGuard address.

### The build list

Until every `app-dev` Node switches, only the Nodes on the build list run container Instances. The list is the Gateway setting `instances.container_nodes`, a JSON list of Node ids in `ORBIT_INSTANCES_CONTAINER_NODES`, such as `[12]`. It is empty by default. A malformed list fails with `instance.container_nodes_invalid` (500), so a typo puts no Node on it. The list exists for the build only; the switch removes it ([plan](/solutions/development-container-runtime#rules-for-every-pr)).

The Node must meet the host requirements of ADR 0250: current kernel, SCTP off and no secrets. A Node that still has checkout Instances keeps them until [migration](#migrate-a-checkout-instance) moves them into containers. The `app-prod` role follows the same model in step 10 ([Production Instances](#production-instances)).

## Inside a container

Every Instance container, default or clone, has the same limits: 4 CPUs, 8 GiB of memory, 4,096 processes and a 40 GiB root disk on the `orbit-instances` ZFS pool.

The clone copy supplies a complete root disk device, including its type, path, pool and size. This works when the seed inherits its root disk from a profile or stores it locally.

Every development container has the same layout. The image and the create set it up. Root owns everything below except the homes and the workspace. A container installs as little as possible: the image holds only OS packages, the users and the firewall.

| Part | Setting |
| --- | --- |
| Image | Ubuntu 26.04 with the OS packages the runtime needs, the users, `nftables`, `acl` and `hidepid`. No PHP, no Node, no agent software |
| Egress | The [proxy settings](#egress-and-dns) for every unit, login shell and apt, and no DNS server |
| `orbit` | uid 1000, home `/home/orbit` mode 0750, password locked. Runs the app and every Orbit Process |
| `agent` | uid 2750, home `/home/agent` mode 0700, password locked. Runs T3, Claude Code, Codex and their shell commands. Owns the T3 state, the agent settings and the placeholder key |
| `work` | gid 2760, members `agent` and `orbit` |
| Sudo | None for either user. The image's `sudoers.d` grant is removed |
| Workspace | `/srv/workspace`, owned `agent:work`. Directories are setgid `work`, mode 2770, with the default ACL `g:work:rwX` and no access for others. `core.sharedRepository=group` |
| Git | `safe.directory=/srv/workspace` in each user's own Git config; the agent phase preserves its other Git settings |
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

1. **The redirect.** The nftables table `orbit_instance_dev_ports` takes new TCP connections from WireGuard peers to the Node's WireGuard address on ports 1024 to 65535.
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

- **WireGuard peers only.** The table takes only connections that come in on the WireGuard interface to its address. The site refuses LAN neighbours and task VMs, as every WireGuard-only site does.
- **Private Routes only.** A public Route has no development site. Making a Route public takes it away at the next Caddy build.
- **One rule.** The same rule decides the site, the container's ACL and `/etc/orbit/instance`. The ACL and the file are written at the create.
- **The container ACL.** Only the bridge's own address, the Node's Caddy, reaches ports 1024 to 65535 in a clone.
- **Clones with a Route.** The default runs no agent, and a Project without a Route has no domain for them. `/etc/orbit/instance` names `ORBIT_URL` and `ORBIT_DEV_PORTS`.
Which Node answers:

- **The Node of the clone.** The Route's private name answers with the clone's Node, or with the Cluster's Router.
- **No other Router.** Only the clone's Node has the table. A clone behind a Router on another Node gets no development ports.
- **Cluster members on a LAN.** [Private DNS](/reference/private-dns#cluster-router-addresses) gives a Cluster member with a LAN address the Router's LAN address. The table does not take it, so such a member reaches no development port.
- **WireGuard clients.** A WireGuard client outside the Cluster, such as a Mac, gets the WireGuard address and reaches the ports.

The Node's converge writes the table, and its unit loads it at boot. A Database server or Process added later is left alone by the socket check while it runs; the next converge also adds its port to the ports the Node keeps.

The [environment notes](#environment-notes) tell the agent to bind to `0.0.0.0`, to allow the Instance's host name, and to point hot reload at the URL and port. For Vite, those are `server.host`, `server.allowedHosts` and `server.hmr`.

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
| `/opt/orbit-runtime/<version>/bin` | `composer`, Node, npm, npx, Bun, Deno, `vp`, rg | Everyone |
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

## Environment notes

Each Project type has one note in the agent folder, at `notes/<note>.md`. The notes live in the Gateway's `resources/instances/notes`:

| Project type | Note |
| --- | --- |
| `laravel-app`, `symfony-app` | `web-app`: a web app with previews |
| `monorepo` | `monorepo` |
| `laravel-package`, `node-package` | `package` |

When it creates an Instance, Orbit links `/home/agent/.claude/CLAUDE.md` and `/home/agent/.codex/AGENTS.md` to the note of the Project's type. A note states facts about the environment only. It names the two users and the workspace path, and points to `/etc/orbit/instance` for the Instance's URL. It says where PHP, Composer and the other runtime programs are. It says how previews work, and how an agent's own development server becomes reachable: the port range, the address to bind and the host name to allow. It says that there is no sudo and no direct internet, how the egress proxy works, and how Git leaves the container.

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
| Preview | The Instance's Route on the Node's Caddy proxies to FrankenPHP in the container, at its bridge address |
| Development ports | Any port from 1024 of a private Route, redirected to the Node's Caddy and proxied to the same port in a clone. Only the Node's Caddy reaches them in the container |
| Database | The Instance's own database server and port, on the Node or over WireGuard. A rule on the container's NIC, from the create flow. The server admits the Instance's role only |
| Models | The forwarder at the bridge's gateway address |
| Internet | The [egress proxy](#egress-and-dns) at port 3128 of the bridge's gateway address: every public host and port, over HTTP and `CONNECT` |
| DNS | None. The proxy resolves every name |
| Everything else | Dropped, including the Gateway, WireGuard peers and other Nodes' private services |

The NIC filters the published target address before Docker's DNAT. The bridge forwarding hook sees the translated tuple, so its exact allow uses the current DNAT destination for a local Docker target. An unguarded NIC can reach that exact private service tuple, but no other Docker-private service.

For a target on another Node, Orbit also adds the exact route allowance from the container's address through WireGuard; Incus masquerades the bridge source to the Node's address. A profile-only NIC can reach the two proxies, but no attached target. Before starting, the native clone, container phases, image builder and host clone probe use the same check of every effective NIC on the Instance bridge. A missing ACL, a missing default egress reject, a reference to the bridge union ACL, or an unexpected extra NIC refuses with `instance.container_network_unsafe`.

**F10, current ingress limit.** The bridge admits TCP 1024–65535 from its gateway address and TCP 3773 from any source. The NIC combines that common ACL with its own ACL as a union: a live scratch default admitted the Node on 8080, 3773 and 5173, although its own ACL named only 8080.

With only its own NIC ACL, 8080 answered and 3773 and 5173 were refused. With the shipped common-plus-own ACLs and `security.port_isolation=true`, neither scratch container reached the other's 3773 or 8080. A follow-up must separate the common egress rules from broad bridge ingress in the host ACL layout and NIC ACL composition; a default's own ACL does not yet narrow the common ingress.

A container cannot reach the Gateway: its traffic would leave as its Node's WireGuard address, with the Node's Gateway access. The proxy refuses that path too.

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
| Deploy steps of a default Instance, which run under `env -i` | The intent's `environment` list: `HOME`, `USER`, `LOGNAME`, `PATH` and the container environment |
| Login shells | `/etc/profile.d/90-orbit-runtime.sh` |
| apt | `/etc/apt/apt.conf.d/90orbit-egress` |

A container takes no DNS server from DHCP (`/etc/netplan/90-orbit-no-dns.yaml`), and its resolver has no fallback (`/etc/systemd/resolved.conf.d/90-orbit-no-dns.conf`). So a name lookup in a container fails at once.

So Composer, npm, Bun, Vite+, Git over HTTPS, curl, apt, Guzzle and Node's `fetch` use the proxy without further settings. A tool that ignores the variables fails at its name lookup, such as PHP's `file_get_contents` on a URL or a headless browser. An Instance's own HTTP services, such as a private Reverb host, are not in `NO_PROXY` yet. UDP, so also QUIC, and other protocols get no route out. Git over SSH does not work. The container holds no GitHub credentials, so [Git in and out](#git-in-and-out) still goes through the Node.

## Lifecycle

Lifecycle flows run from the Node with `incus`, usually through Ansible phases. The [clone flow](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/create.mmd) uses official SSH steps running shipped Python host and guest scripts for its five direct phases. Each Ansible phase selects only its own tagged tasks before Ansible evaluates their conditions; it does not walk the tasks of other phases. Migrated development Instances can carry registered legacy Schedules as [container timers](/reference/schedules#container-timers); development Schedule definitions remain unsupported.

### The default Instance

The default Instance is the clone source of its Project on the Node. Orbit builds the Project's first default Instance from scratch. It launches a container from the image and pushes in the default branch, fetched on the Node. The database family creates the database on a Database server once the container can reach one; until then the default uses the database in its workspace. Orbit writes `.env` and runs the Project's [development deploy steps](/reference/deployments#development-deploy-steps), which install dependencies and migrate. The [first default chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/create-default.mmd) has the steps.

A successful create or checkout migration completes the default’s Route projection when it records the seed. Manual deployment is then available without waiting for an automatic-deployment tick. A failed or restored migration keeps its previous projection state.

A stale projection mark is repaired only for an active default on an active app-dev Node, with a container seed. Its own Route is selected from its targets, with authoritative Routes ordered by id and a Route without a web root preferred, as for deploy admission. This includes cluster Routes without a direct Node placement. The selected Route must belong to the same Project and have no web root.

All Routes targeting the Instance must be active and published, with no failure, placement transition, replacement or target-set change. No unfinished Instance or Route publication and no retained publication hold may remain. A non-web Project without a Route counts as complete.

After that, [`instance:deploy`](/cli/instance/deploy) explicitly deploys the default branch. Automatic deployment depends on the [shared producer and policy setting](/reference/deployments#automatic-development-defaults). Orbit pushes the new commit in, checks it out, and runs the development deploy steps in the container. Before that snapshot, a Node/Vite default converts an old shared Node store once as `orbit`, without restarting a Process.

A converted store replaces the same-commit seed too: an immutable old snapshot cannot inherit a later filesystem change. Until this explicit deploy, clones of an old seed still convert their own store correctly. After a deployment passes, Orbit stops the default's Processes for a moment and takes the snapshot `seed-<commit>`. Clones copy from that snapshot, so they never see files half written. The [deploy chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/deploy-default.mmd) has the steps. The default Instance runs no T3 and no agent: a thread always starts in a new Instance.

There are no development setup steps. The default Instance has them done already, so a clone needs none. A clone prepares its container in five direct command phases: container, workspace and Processes, agent, T3, and sessions.

The workspace phase prepares Node and fetches a changed branch pin before starting any Process, with no separate Node-store playbook. A prepared seed keeps its existing recipe and downloaded versions. Every clone gets a private writable store, including one with no Processes, so a Process added later can install its pin. Without a recognized Node/Vite consumer, only the pin fetch is skipped. Each native step reports its timestamps through the existing Operation progress.

Conn supports an [explicit branch selection](/reference/conn#threads-in-an-orbit-project) for a clone. It prepares the Node mirror from the admitted remote source commit and checks out its bundle in the existing workspace phase. Dependencies and build still come from the default's seed; checkout runs no Project setup or deploy command again.

A separate job publishes the Route, certificate and DNS, so Route depth cannot delay the container job. The Route checks the TLS handshake, without waiting for the application to start. The create receipt records T3, session and Route readiness times. Workspace access and environment files stay in the container preparation.

#### What is built

The first default Instance and its deployments are built for the Nodes of the [build list](#the-build-list). A Project has one `default` Instance in the whole fleet, and every clone of it is made on its Node. Deployment admission refreshes the Project’s [source facts at the deployed commit](/reference/deployments#container-source-facts).

| Part | What Orbit does |
| --- | --- |
| Container | `instance-<id>` in the project `orbit-instances`, from the image `orbit-instance`, at the lowest free guest address of the bridge. It has no T3 port |
| ACL | The NIC carries `orbitinst0-proxy` and the container's own ACL `instance-<id>`; `orbitinst0-egress` stays on the bridge only. Only the Node's Caddy, from the bridge's address, reaches port 8080 |
| Git | The Node fetches with the read token into its mirror `/var/lib/orbit/instance-repositories/<id>.git`. A bundle carries the branch into the container, and `orbit` checks it out in `/srv/workspace` |
| Checkout path | The workspace's path on the Node, inside the container's root file system. It keeps the path unique on the Node; Orbit never works in it from the Node |
| `.env` | In the workspace's application directory, for `orbit` with group `work`, mode 0640 |
| Deploy steps | Each runs as `orbit` at `/srv/workspace`, from its own vault copy. Its environment holds only `HOME`, `USER`, `LOGNAME`, `PATH`, the runtime folder's `VP_HOME` and `VP_NO_UPDATE_CHECK=1`, and the [proxy settings](#proxy-settings-in-a-container) |
| `PATH` | The Project's PHP build, then the runtime folder's `bin`, then the system folders |
| FrankenPHP | The unit `orbit-frankenphp.service` runs `frankenphp run --config /etc/orbit/frankenphp.Caddyfile --adapter caddyfile` as `orbit` on port 8080. The Route proxies to the bridge address; the Node runs no PHP pool for it |
| Seed | After the first build and each deployment that passes, Orbit stops the units, takes `seed-<commit>` and starts them again. It deletes older seeds, and the runtime row records the commit |
| Deployment | The variant `container` starts a stopped container and fetches the commit on the Node. It refuses `deployment.checkout_dirty`, `deployment.commit_not_on_branch` and `deployment.branch_diverged` as a checkout does |
| Processes | The Project's development Process definitions run as `orbit-*` units in the container. [Processes](#processes) has the details |

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

For a development Instance with a `vp-dev` Process, Orbit sets `INERTIA_SSR_HOT_URL=http://127.0.0.1:<vite_port>` in the PHP web runtime unit, `orbit-frankenphp.service`. It uses that Instance's own Vite port, including a migrated default's retained port and a clone's assigned port. Orbit owns this value. It is absent without a `vp-dev` Process, on production Instances and on host checkouts. It is not an application `.env` value or a stored environment record, and `env:sync` does not see it. It directs Inertia's development SSR requests to Vite inside the container; it does not change `public/hot`.

The setting has four limits:

- inertia-laravel reads `config('inertia.ssr.hot_url')`. Its package default maps the environment key, but Laravel's shallow config merge means an app's own `ssr` block replaces that default. Such an app needs this line in the `ssr` block of `config/inertia.php`:

  ```php
  'hot_url' => env('INERTIA_SSR_HOT_URL'),
  ```

- `config:cache` freezes the value at cache time.
- Command-line `php artisan` processes do not inherit the PHP unit's environment.
- Units render on create, clone, migrate and default deploys. Existing defaults get the key at their next deploy. Existing clones need recreation.

The create run installs and starts the units after the deploy steps. A unit whose desired state is stopped is installed, not started.

A clone records its own Processes from the same definitions, with its own ids, units and environment files. Its seed holds the default's units, still enabled. So before the copy first starts, Orbit removes every `orbit-*` link in `multi-user.target.wants`, the default's Process units and their `/etc/orbit/processes/<id>` folders. It also writes the copy's own `/etc/machine-id` from its Incus UUID. The clone boots with none of the default's units; each later phase enables the units it sets up. The clone's `processes` phase checks again: it stops and removes every `orbit-process-*` unit and `/etc/orbit/processes/<id>` folder it does not own before it starts its own.

A deployment first records the Project's new definitions and changes the records of changed ones. Then it writes the changed units and new environment files, removes the former ones, and restarts the changed units. Its new seed stops every running unit and starts it again, so each running Process, such as a queue worker, runs the new commit. A container ignores a Process's restart-on-deploy setting. A definition the Project removed keeps its record and unit until a later part of the runtime removes them.

`process:start`, `process:stop` and `process:restart` run `operation-process-container` as a child of their own workflow: Ansible on the Node changes the unit through `incus exec` and checks its state. Journal tails and unit states use bounded direct reads through the Node’s existing SSH account. A Process list reads all container Process states on each Node in one call. The Gateway redacts journal lines before returning them and stores no raw lines in history. [Process runtime status](/reference/metrics#process-runtime-status) defines the observation fields. A change to a Process of a stopped container fails; the container’s next deployment starts it.

One-shot log reads keep each complete journal line in time order and drop a final line cut by the byte limit.

Repeating a failed create with the same Project, Node and name reuses its container and Route. Use a new idempotency key and keep the same root, branch and domain inputs. It runs the create phases again, including the Project's development deploy steps; activation and the seed commit are recorded only after the final receipt succeeds.

`process:create`, `process:update` and `process:destroy` refuse a container Instance's Process with `operation.kind_unavailable`: the Project's definitions and a deployment change them.

A container default without `--database-server` uses the database its Project names in the workspace, such as SQLite. With `--database-server`, the database family creates its database on that server, and the container reaches it by its own [database rules](#network). Dependency installs in the deploy steps reach the internet through the [egress proxy](#egress-and-dns).

A web-serving Project without a PHP version refuses with `project.source_defaults_incomplete`, and one whose PHP build the runtime folder lacks with `instance.host_unavailable`. Setup, `env:sync`, `env:import`, transfer, rename and clone refuse a container Instance with `operation.kind_unavailable`: they work on a checkout from the Node. Another Instance of the Project refuses with `instance.default_missing` on any Node but the default's, and on that Node until the default is active with a seed snapshot. Every other Instance is a [clone](#create).

### Create

`orbit instance:create PROJECT NODE NAME` clones the default Instance. It copies the default container with `incus copy`, a ZFS clone, and clones the default database through the database family. Then it starts the container, creates the Instance's branch from the default branch, and writes the `.env`, the agent settings, the note links and `/etc/orbit/instance`. Last, it starts T3 and the Processes, adds the T3 project at `/srv/workspace`, publishes the Route and registers the T3 server. The [create chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/create.mmd) has the steps.

A clone starts at the commit of the default's newest seed snapshot, with its dependencies installed. It copies no development deploy commands to the execution vault and runs no deploy step. Its branch is the matching origin branch, fetched on the Node, or a new branch at the seed's commit.

Clone readiness checks PHP only when the Project has a PHP runtime. A Project without PHP can register T3 and its sessions. The Operation keeps its stable error code and shows the failed phase, command and exit code in `error.detail`; command output stays private.

It keeps the default's `APP_KEY`, because the cloned database holds data encrypted with it; the rest of `.env` is the Instance's own. When the Instance's branch changes a lockfile later, the agent or the user installs the change in the workspace with the runtime folder's `composer` or `bun`. A sleeping default Instance is cloned from its snapshot without a start. A seed snapshot stays while a clone depends on it; Orbit deletes older seed snapshots that no clone uses.

#### What is built for clones

Clones are built for the Nodes of the [build list](#the-build-list).

| Part | What Orbit does |
| --- | --- |
| Default | The Project's `default` runs in a container on the same Node, is active and has a seed snapshot. Otherwise `instance.default_missing` |
| Conn and models | Conn is on, else `operation.kind_unavailable`. The model router has its guard, else the router's own code |
| Runtime row | The next free bridge address, the lowest free T3 port from 3800, and the default as clone source |
| Copy | `incus copy` of `seed-<commit>` with the clone's own address and ACL. A stopped default needs no start |
| First start | Before it, the copy loses its `orbit-*` boot links and the default's Process units and folders, and gets its own machine-id |
| ACL | The Node's Caddy reaches port 8080 and ports 1024 to 65535, any WireGuard peer reaches port 3773, and the container reaches its database server's port |
| T3 port | The proxy device `t3` in NAT mode, from the Node's WireGuard address and the T3 port to port 3773 |
| UFW | Under the comment prefix `orbit:instance-<id>-`: a route deny from the task VM range, then a route allow to port 3773; and the rule to the database server |
| Agent files | `/home/agent/.config/orbit/model.env` with the placeholder key from the vault, Codex's and Claude Code's settings that point at the forwarder, the note links, and `/etc/orbit/instance` |
| Processes | FrankenPHP's unit, rewritten for the clone's own address |
| T3 | Its first start, a check of `/.well-known/t3/environment` within 30 seconds, and the T3 project `/srv/workspace` |
| Registration | [T3 registration](#t3-registration-and-pairing), from the run's result |

A failed clone stops its container; the same command resumes it. The PostgreSQL template copy runs again on a resume, so a resume starts from the default's data again. A resume also issues new T3 sessions; the ones of the failed run stay valid in T3 until a later renewal cleans them.

A deployment of the default can replace the admitted seed before the copy. The copy then refuses with `instance.default_seed_moved`, and the same command takes the new seed. A clone already copied does not need its seed: on ZFS, Incus keeps a deleted snapshot's blocks while a clone uses them.

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
- **Valkey.** No data is copied; the Instance gets its own connection, as `database:create` makes it.

Orbit creates the owner and grants; no path copies roles.

### Destroy

`orbit instance:destroy INSTANCE --yes --force` removes a non-default container Instance, cloned or migrated. The default is the Instance named `default`. This first slice requires `--force`: it runs no work or turn checks and no Project teardown steps. Guarded removal and container default destruction follow separately. A call without force refuses with `instance.container_destroy_force_required`; a container default still refuses with `operation.kind_unavailable`.

The run withdraws the clone's Route, drops its owned server databases, then stops and deletes its Incus container with its Processes, T3 history, snapshots, storage volume and published proxy device. It removes the `instance-<id>` ACL from Incus project `default`, its UFW rules and its Node repository mirror. Deleting T3 retires every session and unused pairing link. Terminal projection removes execution copies and Conn records, then deletes the runtime row to free its address and T3 port. The default and other clones stay.

A migrated Instance's retained Git directory survives destroy and is purged only by hand. Its successful destroy Operation result keeps `retained_git_path` and `retained_git_at` after the Instance record is deleted; [instance:destroy](/cli/instance/destroy) explains how to read them.

A failed removal retains its intent and reserved port. A fresh forced destroy accepts resources already gone and finishes cleanup. After withdrawal, the Route stays down until removal completes. The [clone destroy chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/destroy.mmd) has the steps.

### Sleep and wake

After one hour without activity, Orbit stops the whole container. It counts both HTTP activity and T3 activity: a turn, or a client working in a thread. A running task keeps the container awake. Keep-alive Processes do not: the whole container stops. A stopped container uses no memory; its disk stays.

The next request to its Route, a Conn connection, a task turn or a deployment wakes it. Orbit starts the container, and T3, the app and the Processes start with it in about 3 s.

The [sleep](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/sleep.mmd) and [wake](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/wake.mmd) charts have the steps. A container never prunes its dependencies: a stopped clone shares most of its blocks with the default Instance.

### Migrate a checkout Instance

Before downtime, migration resolves the [deploy credential](/reference/github-app#instance-source-credentials) and makes one read of the Project's default branch with a 30-second limit. The Instance's local branch need not be pushed; its existing retirement checks still apply. Projects using `gh_cli` skip this App preflight. An unreadable source or timeout leaves the checkout serving. The local checkout still supplies the migration's mirror and files. The Git URL rewrite is quoted in `argv` so YAML cannot turn it into a mapping.

[`orbit instance:migrate INSTANCE`](/cli/instance/migrate) moves one existing checkout Instance into a container on its Node, with its code. A linked worktree can move first while its owner remains an active host checkout on the same Node. Migration starts from the stock image and copies its own workspace; it consumes no default seed. The owner moves after its last dependent leaves. The command is temporary: [Old paths](/reference/old-paths) lists it until the last checkout Instance has moved.

- **Stop.** Orbit removes the Instance's awake marker, and its Route shows the wake page. The Gateway starts no wake during the move.
- **Repository.** Orbit builds a standalone repository in the container; the checkout's `.git` is never copied.
- **Branch.** The Node's mirror fetches the Instance's branch from the checkout, and Orbit pushes it in.
- **Files.** Orbit copies the changed, untracked and ignored files into the workspace, never the checkout's `.git`.
- **Database.** A database on a Database server stays where it is, and the container gets its own way to it. A SQLite file in the checkout moves with the files.
- **Start.** FrankenPHP starts as `orbit`. A non-default Instance gets its T3 server, registered as in [Create](#create). A default gets its first seed snapshot.
- **Route.** Orbit points the Route at FrankenPHP in the container.
- **Retirement.** Orbit retains all files and Git recovery data. It removes only the linked checkout's registration. An owner's dependents and any registered descendant block retirement.

Registered Schedule timers keep their UUID, calendar, timeout and enabled or paused state. Their timers and commands run inside the container as `orbit`, only while it runs, without catch-up. [Schedule migration](/reference/schedules#checkout-migration) defines refusals, rollback and retained logs.

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
| Runtime row | Reserved at admission: the next free bridge address and, for a non-default Instance, the lowest free T3 port. It names no clone source |
| While it runs | The Instance keeps its checkout path, and the reserved row does not turn its site. The run holds the Route's Node, and the Instance takes no deployment and no wake |
| Retirement check | Checks identity before downtime and retention. Linked checkouts protect their HEAD; owners protect all branches and stashes. Tracked changes and owner dependencies block; untracked files pass |
| Branch | The mirror fetches the recorded branch locally. A linked checkout refuses its own unpushed HEAD; an owner checks every local branch and detached HEAD |
| Files | One archive carries changed, untracked and ignored files and missing directories. Added directories keep their modes; ownership maps to `orbit`/`work`. Deleted tracked files are removed |
| `.env` | Copied as it is. The checkout's path in it becomes `/srv/workspace`, and the Gateway rewrites its stored values the same way |
| Database server | The NIC ACL and the Node's firewall rule to every attached target. The container phase checks every port from inside before quiesce, else `instance.database_unreachable`, with zero downtime |
| SQLite | The file moves with the files. The Gateway drops the connection record of a SQLite file inside the checkout |
| Route | The same Route, domain and certificate. One publication replaces the checkout's site and PHP-FPM pool with the container's site |

All untracked files enter the default's seed snapshot and every clone, as ignored build output already does. A branch that later tracks one of those paths can refuse checkout in a clone or deploy. Forgotten credentials files travel into the seed like `.env` does.

Migration reports `untracked_files_copied` as the count of untracked files that Git does not ignore, using `git ls-files --others --exclude-standard -z` at archive time. Selected nested repositories contribute their archived leaf files; directories do not count and symlinks count once. Ignored files are still copied. It exposes no new paths or file contents. Submodule changes still refuse because the parent archive cannot preserve them.

The runtime reservation belongs to its admitting Operation. A late receipt cannot change a newer owner. Settlement reads private material before taking the Instance lock, then checks ownership and records the runtime and receipt atomically. An already-settled stale runtime is not repaired: inspections keep selecting it until a new migrate reuses and owns that reservation.

| Part | What Orbit does |
| --- | --- |
| Seed | A default takes `seed-<commit>` with its units stopped. Its Project's other Instances can be clones from then on |
| T3 | A non-default Instance gets the proxy device `t3`, the agent's files and its T3 server. The Conn layer records the server from the run's result |
| Runtime | A Route that serves the container makes the container the Instance's runtime: the workspace becomes its checkout path |
| Retained Git | The whole old checkout moves under `<checkout>.orbit-retired-git/<sha256 of original path>`. The Instance records `retained_git_path` and `retained_git_at`; files and Git recovery data stay there |
| Linked checkout | Retains all files and independent Git data. Removes only its matching, now-missing owner registration after verification; keeps owner and sibling metadata untouched |
| Last worktree | The default's old checkout is a retirement candidate. Any other registered worktree blocks owner retirement, including a path in the same batch |
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

The container cannot call the Gateway, so registration never comes from inside it. Its renewal is not built yet: a session lasts 30 days. PR 13 of the plan adds it: Orbit issues a new session when it wakes a container whose session expires within seven days, and a daily check renews the sessions of running containers. The [registration chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/t3-register.mmd) has the steps.

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

A task group runs in one Instance container. Everything else about a group, its subtasks, checks, reviews and reads, stays as [Tasks](/reference/tasks) describes.

| Part | In an Instance container |
| --- | --- |
| Start | `task:start` clones the Project's default Instance into the Instance `task-{id}`, branch `task-{id}`, as [Create](#create) does. The task is the clone's [owner](/reference/tasks#workspace-ownership) |
| No default | Without an active default Instance of the Project on an `app-dev` Node, the start fails with `instance.default_missing` |
| Attach | A group may name an existing Instance of its Project instead. The task drives its T3 and works on its branch, but does not own it |
| Turns | Swamp drives the container's T3, as [Swamp drives T3](#swamp-drives-t3) specifies |
| Workspace actions | The receipt, the Git evidence, the reviewer's reset and the checks run from the Node, with `incus exec` as `agent` in `/srv/workspace`. A check sees what the agent saw |
| Preview | The Instance's Route |
| Publish | Orbit reads the branch out on the Node and pushes it with a minted token, as [Git in and out](#git-in-and-out) describes |
| Pause | `task:pause` on the group stops Swamp's turns, and the user drives from Conn. `task:resume` gives the turns back to Swamp |
| End | Orbit destroys a clone that the task owns, through [Destroy](#destroy). An attached Instance stays running |

A task keeps its Instance awake: [Sleep and wake](#sleep-and-wake) never stops a container while a task flow runs in it.

## Swamp drives T3

Swamp drives a container's T3 server through T3's own API. Nothing of Orbit runs in the container for it, and Orbit forks neither T3 nor Conn. This section is the contract of the `t3` task driver, which replaces the [`pi` driver](/reference/tasks#drivers) for tasks in Instance containers. The `pi` driver stays for task VMs until they go.

### Reach and authentication

Swamp reaches the container's T3 as a Conn device does, with its own session.

- **Address.** The Instance's published URL: the Node's WireGuard address and the runtime row's `t3_port`. Conn devices use the same path. The firewall admits WireGuard peers and refuses task VMs.
- **Session.** A second T3 session, `Orbit Swamp`, from the create flow.
- **Vault.** Swamp keeps that session in the vault and reads it in each step that calls T3.
- **Session scope.** The session never enters a run or the Gateway's records. The renewal of [registration](#t3-registration-and-pairing) renews it, and destroy revokes it.
- **MCP.** `POST <url>/mcp` with `Authorization: Bearer <session>`.
- **WebSocket.** `POST <url>/api/auth/websocket-ticket` with the same bearer gives a ticket. Swamp then opens `/ws?orchestrationProtocol=2&wsTicket=<ticket>` and sends Effect RPC requests.
- **Workspace.** File and Git actions in the container go over SSH to the Node, then `incus exec` and `incus file pull`. No token enters the container.

### Calls

Swamp uses these T3 calls and no others.

| Purpose | Call | Input that Orbit sets |
| --- | --- | --- |
| Find the workspace project | MCP `t3_project_list` | The project at `/srv/workspace`, which the create flow adds |
| Start a thread with its first turn | MCP `t3_thread_launch` | `projectId`, `title`, `message`, `modelSelection: {instanceId: codex or claudeAgent, model, options: {effort}}`, `runtimeMode: full-access`, `interactionMode: default`, `workspaceStrategy: {type: root}`, `clientRequestId` |
| Send a later turn | MCP `t3_thread_send` | `threadId`, `message`, `mode: queue`, `clientRequestId` |
| Wait for the turn | MCP `t3_thread_wait` | `threadId`, `runId`, `timeoutMs`. It returns when the run ends, with the run's latest `runId` |
| Read a question | MCP `t3_pending_request` | `action: read`, for a `waiting` run |
| Read the transcript | MCP `t3_thread_read` | `threadId`, `view: activity`, paged with `afterPosition` |
| Read state and usage | WebSocket `orchestration.getThreadProjection` | `threadId`. Gives the run's status, failure, retry and provider turns with their token usage |
| Interrupt | MCP `t3_thread_interrupt` | `threadId`, `runId`. If the run has not ended 30 seconds later, WebSocket `orchestration.forceStopThread` with `threadId` and `runId` |

`clientRequestId` is `operation-{id}`. A retried launch or send with the same id and the same session returns the same run and starts no second turn, as a repeated Pi send key does. T3 keeps these ids per session, so Orbit never renews the `Orbit Swamp` session while a turn operation of the Instance runs.

`runtimeMode: full-access` holds only while the session carries no mode limit and the server's managed policy sets no lower maximum. The image sets neither.

A wait that times out reads the run's status. While it is still running, Swamp waits again. A `waiting` run ends the turn as `blocked`: Swamp reads the question with `t3_pending_request` and records it as the receipt's question.

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

A Project setting turns on a nested fleet. Its Instance containers then run a fleet of Incus containers nested inside them. Orbit uses it for its command proofs: a Gateway, an `app-dev` and an `app-prod` Node. Other Projects leave it off and keep the kernel surface of [Inside a container](#inside-a-container).

### The container

An Instance container with a nested fleet lives in its own Incus project on the Node, which allows what the fleet needs. `orbit-instances` keeps refusing it. It changes these settings:

| Key | Value | Why |
| --- | --- | --- |
| `security.nesting` | `true` | The inner Incus and Docker run in it |
| `security.syscalls.intercept.mknod` | `true` | The inner Incus makes device nodes for its containers |
| `security.syscalls.intercept.setxattr` | `true` | The inner Incus and Docker set trusted extended attributes |
| `security.idmap.size` | `101000000` | The inner range below must fit inside |
| `user.max_user_namespaces` | Not set to zero | The inner Incus needs user namespaces |

Its project allows low-level keys (`restricted.containers.lowlevel=allow`), because `security.idmap.size` is one. So the create flow refuses a container with any `raw.*` key, and with any device beyond its root disk, NIC, the shared folders and the T3 proxy. No host path, and never the host's Incus socket, reaches it.

### The fleet

The fleet runs under an Incus inside the container.

- `/etc/subuid` and `/etc/subgid` in the container give root `1000000:100000000`. The range fits inside the container's 101,000,000 IDs.
- The pool `topology-seed` is a directory pool inside the container's root disk. A clone of the container therefore carries the fleet. A delegated ZFS dataset would not work, because `incus copy` does not copy its child datasets.
- The network `oe-nested` is an inner bridge.
- Each nested container has `security.nesting=true`, a memory limit and `boot.autostart=true`.
- Each nested container also has a read-only disk device that shows `N`, from `/etc/orbit/apparmor-off`, at `/sys/module/apparmor/parameters/enabled`.
- Docker then skips its own AppArmor profile, which cannot load two levels below the host.
- A unit that the nested fleet runs reads its secret from `SetCredential` or a file, never `LoadCredential`. There systemd gives the unit no credential and no error.
- The fleet check fails on a unit that breaks this rule.

The nested Nodes keep `wg-quick` from the nested Gateway's own Node converge. The fleet build's cloud-init `bootcmd` turns off the `wg` and `wg-quick` profiles.

The default Instance builds the fleet once and keeps the three nested containers stopped, each with its own `.env`, storage, database and a `base` snapshot. Its deployments keep them current. So a clone gets its own fleet in under a second, with no half-written database pages.

### How the agent reaches its fleet

The agent reaches its fleet as on a task VM, with its own tunnel.

- The container joins the nested fleet as the `operator` Node, `10.45.0.10`, with its own WireGuard key.
- Its tunnel `wg-nested` runs as `orbit-wg-nested.service` with `ip` and `wg`, because `wg-quick` segfaults in the outer container under Ubuntu 26.04's AppArmor profile.
- The candidate CLI in the workspace talks to the nested Gateway at `https://10.45.0.1`, with the `nested` profile and the nested root CA.
- The agent runs `incus` in the container for `exec`, logs and snapshots of its nested containers.
- `agent` is a member of the inner Incus admin group and has no sudo.
- That makes the agent root-equivalent inside its own container, never on the Node.
- `orbit` has no access to the inner Incus.
- The nested Gateway and `app-dev` get `/srv/workspace` as the disk device `checkout`, so an edit reaches them at once.

### Egress of the nested fleet

The nested test Nodes reach the public internet through the Node's [egress proxy](#egress-and-dns), as the container itself does.

| Part | Rule |
| --- | --- |
| Bridge | A nested-fleet container sits on the same bridge `orbitinst0`, with port isolation |
| Path | A nested Node sends through `oe-nested` and the outer container's NAT to the proxy, so the proxy sees the container's bridge address |
| DNS | The nested fleet's own names come from its own DNS. Outside names resolve at the proxy |
| Settings | The fleet build sets `HTTP_PROXY`, `HTTPS_PROXY` and `NO_PROXY` for the nested Nodes, their package managers and their Docker daemons. Fleet names stay off the proxy |
| Record | The proxy's lines carry the container's bridge address, so they belong to the Instance. [PR 10](/solutions/development-container-runtime#pr-10-the-nested-fleet) has the Node's agent report them as egress facts, so `task:egress` shows them |

### Take and restore `base`

A proof restores each nested container to its `base` snapshot. On a directory pool a restore copies back the files that changed since `base`, so its time grows with the change. The limit is 45 seconds; the UpCloud spike measured 3–4 s for a restore and 21 s to take `base` again. Taking `base` stops the three nested containers first, then snapshots and starts them, because a snapshot of a running container on a directory pool is a file copy, not a consistent state.

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
| `power_state` | `running` or `stopped`. A sleeping container is stopped |
| `seed_commit` | A default Instance's commit of its newest `seed-<commit>` snapshot, which clones copy. Null before its first seed |

The database keeps these rules:

- A Node's container, bridge address and T3 port each belong to one runtime.
- A runtime keeps its Node, container, address, port and clone source; only the versions and the power state change.
- A clone source is the Instance named `default` of the same Project on the same Node, and runs in a container.
- A default with clones keeps its runtime and its name.
- A container Instance keeps its Node, Project and name.
- An Instance with a runtime, a clone or a Conn environment cannot be deleted. The destroy flow removes those records first.

The T3 server's record in the Conn layer names its Instance. Only the Instance's create flow registers that server; a Node that registers it itself is refused ([Registration](/reference/conn#registration)).

## Errors

The container runtime adds these codes. The other codes of create and destroy stay as they are.

| Code | HTTP | Cause |
| --- | --- | --- |
| `instance.default_missing` | 409 | `instance:create` of a non-default Instance, or `task:start` in a clone, while the Project has no active default Instance on the Node. Also when the Project's default runs on another Node |
| `instance.default_has_clones` | 409 | `instance:destroy` of a default Instance that other Instances were cloned from |
| `instance.migrate_unsupported` | 409 | `instance:migrate` of an Instance that is not an active development checkout on a Node of the build list |
| `instance.migrate_retirement_blocked` | 409 | Owner dependencies, registered descendants, changed Git identity or tracked changes block retirement. Names 20 paths and total counts |
| `instance.migrate_unpushed_commits` | 409 | A linked checkout's own HEAD, or an owner's branches or detached HEAD, has unpushed commits. Names each protected ref and count |
| `instance.migrate_stashes_present` | 409 | An owner has stashes. Linked checkouts leave shared stashes in the owner's store |
| `instance.migrate_branch_changed` | 409 | The checkout is not on the branch that Orbit records for the Instance |
| `instance.database_unreachable` | 502 | The new container of a move could not reach the Instance's database server |
| `instance.migrate_process_binary_unavailable` | 409 | A moved Process needs an unavailable PHP version or executable path. The error names the Process and path; the checkout keeps serving |
| `instance.migrate_<step>_failed` | 502 | A step of a move failed, such as `instance.migrate_workspace_failed`. The checkout serves as before |
| `instance.turn_running` | 409 | `instance:destroy` without `--force` while a T3 turn runs in the container |
| `instance.t3_unready` | 502 | The T3 server did not answer within 30 seconds of its first start in a clone |
| `instance.default_seed_moved` | 409 | A deployment of the default replaced the seed that a clone's create admitted, before the copy. The step's report names it; run the same command again |
| `instance.create_register_failed` | 502 | A clone's registration step failed: T3 refused a session, or the Gateway's lacks its scopes. The step's report names the `conn.*` code |
| `instance.database_clone_unsupported` | 422 | A clone names another Database server than the default's, or the default has no database on a server to clone |
| `conn.environment_asleep` | 409 | A pairing with a sleeping Instance's T3 server |
| `node.instance_bridge_exhausted` | 409 | No `/24` of the fleet range is free for a Node's Instance bridge |
| `instance.container_nodes_invalid` | 500 | `ORBIT_INSTANCES_CONTAINER_NODES` is not a JSON list of Node ids |
| `instance.host_unavailable` | 409 | A build-list Node cannot be prepared: it lacks the pool `orbit-instances` or enough subordinate IDs, or the recipe has no pin for it |
| `instance.host_unavailable` | 409 | A container default cannot be placed: the Node has no bridge, the bridge is full, or the runtime folder lacks the Project's PHP build |
| `deployment.processes_inactive` | 409 | A container default's units were not active at the end of a deployment |
| `process.target_unsupported` | 409 | A container Instance's Process definition is a Docker Process or uses a preset, or a Process change would go to the Node agent |
| `process.target_unavailable` | 503 | The direct container Process journal read failed |
| `instance.bridge_busy` | 502 | Another shared endpoint writer owns the lock; this invocation applied no intent or health change |
| `instance.bridge_command_timeout` | 502 | A Docker/NAT read exhausted the helper’s remaining budget, or an Incus request timed out |
| `instance.bridge_state_unresolved` | 502 | A bounded ACL readback could not determine the result after a timeout or ambiguous write; retry convergence |
| `instance.container_endpoint_unresolved` | 502 | A local Docker endpoint has no unique exact DNAT mapping; the phase refuses before probing or downtime |
| `instance.container_network_unsafe` | 502 | The container NIC has no effective ACL or lacks its default egress reject. Orbit refuses to start it |
| `instance.container_<phase>_failed` | 502 | A phase of a container default's create or deployment failed without a refusal, such as `instance.container_start_failed` |

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

Remote withdrawal uses the runtime's captured endpoint and applied bridge and NIC records, so another Instance's changed publication cannot prevent revocation. Detach removes the managed host and owned network path; the NIC denies the port even for an existing process that retains its old environment. Other containers gain no NIC allowance.

The same-Node socket proxy runs with a dynamic unprivileged user; its unit files stay root-owned. Orbit refuses foreign effective vendor/runtime fragments and drop-ins before changing the network. Readiness opens the container's loopback port and checks that the active proxy owns both that accepted connection and a new established connection to the exact upstream. This sends no application bytes.

Orbit waits up to three seconds per target for unit readiness and both connections, polling every 50 ms. The probe’s systemd reads and loopback connect share that deadline. Refusals retain the [reason and unit states](/reference/processes-and-schedules#node-service-target-records) in the Operation. A missing forwarding connection refuses rather than accepting a listener backlog. Target failures are fatal, unlike advisory development mail.

Pending activation remains after failure, so a later attempt reloads and probes again. Clone attachment records remain independent; inherited target units and enable links are removed before boot. Existing environment files stay unchanged.


Attached-target admission and migration require an active, desired-running Process. Attached targets refuse a stop or publication change until detached. Node intent capture accepts a stopped Process when its recorded publication remains. Detach can withdraw those records. Full host reconciliation still needs live Docker DNAT for every recorded Docker endpoint, as it does for database targets: restart a stopped target before that reconciliation. This feature changes no shared bridge-helper policy.

Migrate accepts only the attachment's exact host-key/port-key pair and published port. A selected nonempty database URL override refuses before downtime; URL credentials are never reported. Target failures carry the public attachment ID, Process ID/name and port. Create preserves that object in its phase receipt; migrate uses its existing error-message field to describe the same public identity. Neither includes environment values.
