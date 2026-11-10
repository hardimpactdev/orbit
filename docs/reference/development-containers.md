---
title: "Instance containers"
description: "ADR 0250, partly built: every Instance runs in an Incus container cloned from the Project's default Instance, with T3 inside. A user drives its T3 in development, Swamp drives it for a task. A Project setting turns on a nested fleet."
covers:
  - apps/gateway/app/{Models/InstanceRuntime,Actions/Nodes/AllocateInstanceBridgeAction,Domain/Nodes/NodeBridges}.php
  - apps/gateway/app/Domain/Instances/{InstanceRuntimePowerState,ContainerNodes,InstanceHostIntent,InstanceImage,InstanceFolderPlan,InstanceNotes,InstanceContainer,InstanceEgress}.php
  - apps/gateway/app/Domain/Instances/Deployment/{ContainerDeployOutcome,DevelopmentDeploySteps}.php
  - apps/gateway/database/migrations/2027_02_04_000000_add_seed_commit_to_instance_runtimes.php
  - swamp/ansible/{instance-container.yml,tasks/instance-container-phase.yml}
  - apps/gateway/{config/instances.php,database/migrations/2027_02_03_000000_create_instance_runtimes.php,resources/instances/notes/**}
  - swamp/ansible/tasks/{instance-host,instance-folder,instance-image,instance-egress}.yml
---

# Instance containers

**Not built, except the [records](#records), the [Node preparation](#prepare-a-node), [egress and DNS](#egress-and-dns) and the [default Instance](#the-default-instance).** This page specifies steps 3, 4, 5 and 8 of [ADR 0250](/decisions/0250-run-instances-and-task-groups-in-incus-containers#build-order). It describes the target, and the production basics of step 10. The parity gate of step 6 lives in the plan. The PRs in [Container runtime](/solutions/development-container-runtime) build it, one part at a time, and move each part into its owning page. Until then, development Instances run from checkouts on their Node, as [Applications](/domains/applications) describes, and task groups run on [task VMs](/reference/compute-drivers#task-vms).

Every development Instance runs in one unprivileged Incus system container on its `app-dev` Node. The container holds the Instance's checkout, its app and Processes, and, except in the default Instance, a T3 server with Claude Code and Codex. It has no WireGuard identity, no roles and no Node record. Orbit reaches it from its Node with `incus`, and users reach it through its Route and its T3 server.

Every new Instance is a ZFS clone of the Project's default Instance on the Node, and its database is a clone of the default Instance's database. The default Instance is the only one built from scratch, and it deploys every merge to the default branch, so clones start at the current default branch with its dependencies installed. The default Instance never runs T3, so each clone starts T3 for the first time and gets its own T3 identity.

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
| Move a checkout Instance into a container | [container/migrate.mmd](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/migrate.mmd) |

## Nodes

Every `app-dev` Node runs its Instances in containers. There is no setting to opt in. A task VM keeps its checkout workspace until step 9 of ADR 0250 removes task VMs.

The `app-dev` role installs the container setup: the Incus project `orbit-instances` on the Node's pool, the bridge `orbitinst0`, the container image, the [shared folders](#shared-folders), the [egress proxy](#egress-and-dns), the forwarder of the [model route](#model-access) and the firewall rules. It stops installing PHP-FPM and checkout folders once the Node has no checkout Instance. Clones need ZFS, so the pool of a real Node is ZFS; only a nested proof Node uses a directory pool, where a copy is a full copy.

### Prepare a Node

This part is built. The `app-dev` role converge of a Node on the [build list](#the-build-list) runs a container branch; a task VM never does. The Gateway sends the branch's intent only for such a Node, so every other Node gets the same intent as before. [Prepare an Instance container host](/reference/compute-drivers#prepare-an-instance-container-host) has the steps.

- **Allocation.** The admission of a role add or converge allocates the Node's bridge. The captured intent only reads it.
- **Pool.** The Node provides the Incus storage pool `orbit-instances`: ZFS, or a directory pool on a nested proof Node. The converge chooses no disk itself.
- **Missing pool.** A Node without the pool is refused with `instance.host_unavailable`.
- **Project.** Containers of `orbit-instances` must set an isolated ID map. They mount only the two shared folders, may have proxy devices, and cannot be VMs.
- **Profile.** The default profile sets the [proxy settings](#egress-and-dns) as `environment.*` keys, so every `incus exec` that keeps its environment has them.
- **Bridge.** `orbitinst0` has NAT and no IPv6. DHCP leaves the last address free for the image build.
- **Bridge ACL.** A container reaches only two ports of the bridge address: the model forwarder at 8319 and the egress proxy at 3128. Each Instance's own paths come with its create flow.
- **Input rules.** The Node admits DHCP from the bridge under `orbit:instances-dhcp`, and the egress proxy under `orbit:instances-egress`. It admits no DNS from the bridge.

The converge then installs what the containers run:

- **Egress proxy.** It runs the [egress proxy](#egress-and-dns) and its nftables table.
- **Image.** The converge builds the [container image](#inside-a-container) once per definition and names it with the stable alias `orbit-instance`.
- **Folders.** It installs both [shared folders](#shared-folders).

The converge creates no Instance.

A task VM host on the build list, such as Beast, gets the container branch beside its task side. The two sides share nothing they change: the Instance side has its own project `orbit-instances`, bridge `orbitinst0`, pool `orbit-instances` and `orbit:instances-*` input rules, and touches no task VM, task container or CI place. The task side keeps the ZFS ARC: on a task VM host the Instance side writes no cap. Only a task VM's own Node gets no branch.

Before it changes anything, the converge refuses a bridge range that another interface of the Node holds, with `instance.host_unavailable`. The Gateway only knows the bridges it records, not one an operator made, such as a CI bridge.

The Gateway gives each Node a bridge network, a `/24` of the fleet range `10.253.0.0/16`, and records it on the Node. It takes the first `/24` that overlaps no recorded bridge and not the VPN subnet. A Node keeps its bridge. When no `/24` is free, the allocation fails with `node.instance_bridge_exhausted` (409). A task VM host's bridges may not overlap an Instance bridge either: `node:update` refuses them with `node.settings_invalid`. T3 servers are published on ports 3800 to 3999 of the Node's WireGuard address.

### The build list

Until every `app-dev` Node switches, only the Nodes on the build list run container Instances. The list is the Gateway setting `instances.container_nodes`, a JSON list of Node ids in `ORBIT_INSTANCES_CONTAINER_NODES`, such as `[12]`. It is empty by default. A malformed list fails with `instance.container_nodes_invalid` (500), so a typo puts no Node on it. The list exists for the build only; the switch removes it ([plan](/solutions/development-container-runtime#rules-for-every-pr)).

The Node must meet the host requirements of ADR 0250: current kernel, SCTP off and no secrets. A Node that still has checkout Instances keeps them until [migration](#migrate-a-checkout-instance) moves them into containers. The `app-prod` role follows the same model in step 10 ([Production Instances](#production-instances)).

## Inside a container

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
| Git | `safe.directory=/srv/workspace` in `/home/orbit/.gitconfig` only |
| Shared folders | `/opt/orbit-runtime` and `/opt/orbit-agent`, the Node's folders, mounted read-only and idmapped |
| T3 unit | `User=agent`, `UMask=0007`, `PATH` from the shared folders, `Requires=nftables.service`, `ConditionPathExists=/etc/orbit/instance`. The unit file is mode 0640 |
| `PATH` | The runtime folder's `bin` first, for units, login shells and `incus exec` sessions, so `composer` is on it |
| `php` | `/usr/local/bin/php` links to the Project's static `php` in the runtime folder. A Project without a PHP version has none. The create and each start set it |
| Instance file | `/etc/orbit/instance`, owned by root and readable by all: the Instance, its Project and its URL. The default Instance has none, so T3 never starts there |
| Model route | `nftables` in the container lets only uid 2750 reach the forwarder, and only uid 0 and 2750 reach T3's port |
| Processes | `/proc` with `hidepid=invisible`, so each user sees only its own processes |
| Kernel surface | Unprivileged, no nesting, `user.max_user_namespaces=0`, an isolated ID map |

A container mounts no host checkout and no Incus socket. Root work, such as units, goes through Orbit from the Node.

The Gateway renders the image: its packages, the setup script, its files and the units it enables. The script turns cloud-init off, so a new container never makes the stock `ubuntu` user and its sudo grant again. The image's alias is `orbit-instance-` with the first 12 hex digits of a digest of that definition, so a changed definition is a new image. A build container from the stock Ubuntu image runs the definition and is published under that alias. The stable alias `orbit-instance` then moves to it, and the older images go; a container keeps what it was made from.

The build container reaches the internet as every container does, through the [egress proxy](#egress-and-dns): the profile gives its `incus exec` the proxy settings, and apt follows them. It takes the bridge's last address, which no Instance gets.

### Who runs what

The app runs as `orbit`: FrankenPHP, queue workers and every Orbit Process. `orbit` cannot run the agent binaries, read `/home/agent`, reach the model route or T3's port, or see agent processes. A public preview URL reaches only an Orbit Process, so an attacker who comes in through it lands as `orbit`.

T3 and the agents run as `agent`, and so do the shell commands of Claude Code and Codex. There is no wrapper shell. Both users read and write the workspace: a file each one writes is `rw-rw----`, group `work`.

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
| Listening ports | The table leaves every port that a program on the Node listens on, on any address: its socket check finds the program |
| Orbit's ports | The table always leaves the Node's own ports, also while their program is down. See the list below |
The Node's own ports are the SSH port, the egress proxy 3128, the published T3 ports 3800 to 3999, the model guard and forwarder 8318 and 8319, the WireGuard port, and the ports of the Node's Database servers and Processes.

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
- **The container ACL.** Only the bridge's own address, the Node's Caddy, reaches ports 1024 to 65535 in a clone.
- **Clones with a Route.** The default runs no agent, and a Project without a Route has no domain for them. `/etc/orbit/instance` names `ORBIT_URL` and `ORBIT_DEV_PORTS`.
- **The Node of the clone.** The Route's private name answers with the clone's Node, or with the Cluster's Router.
- **No other Router.** Only the clone's Node has the table. A clone behind a Router on another Node gets no development ports.

The Node's converge writes the table, and its unit loads it at boot. A Database server or Process added later is left alone by the socket check while it runs; the next converge also adds its port to the ports the Node keeps.

The [environment notes](#environment-notes) tell the agent to bind to `0.0.0.0`, to allow the Instance's host name, and to point hot reload at the URL and port. For Vite, those are `server.host`, `server.allowedHosts` and `server.hmr`.

### PHP

FrankenPHP serves the app, in classic mode, as an Orbit Process running as `orbit`. The runtime folder holds one static build per PHP version that Orbit supports, and each Instance uses the version that Orbit picks from its `composer.json`, as it does today. Its Caddy listens on the container's bridge address; the Node's Caddy terminates TLS for the Route and proxies to it.

The command line runs a real PHP CLI: a static `php` from the same static build as the Instance's FrankenPHP, with the same PHP version and extensions. `frankenphp php-cli` behind a `php` shim does not fit yet. It refuses the `-d` options that Composer and test runners pass, and before PHP 8.6 it cannot run `php -i` or PHP without a script. Orbit runs PHP 8.5. The [static PHP build](/reference/compute-drivers#static-php-build) defines the build and its extension list, and the runtime folder's check compares both binaries with it. The shim can replace the CLI binary once FrankenPHP accepts `-d` and Orbit runs PHP 8.6.

## Shared folders

The software that the app and the agents run lives once per Node, in two versioned read-only folders. Every container mounts both with idmapped mounts, so one update reaches every container. Each folder has a `current` link to the version in use, and the recipe pins every version.

| Path | Content | Access |
| --- | --- | --- |
| `/opt/orbit-runtime/<version>/php/<php-version>/bin` | `frankenphp` and `php` of one PHP version, from its [static PHP build](/reference/compute-drivers#static-php-build) once that is published and pinned | Everyone |
| `/opt/orbit-runtime/<version>/bin` | `composer`, Node, npm, npx, Bun, `vp`, rg | Everyone |
| `/opt/orbit-runtime/vite-plus/<digest>` | The [Vite+ home](/reference/compute-drivers#the-vite-home) that `vp` runs from, linked as `<version>/vite-plus-home` | Everyone |
| `/opt/orbit-agent/<version>/agent/bin` | `t3`, `claude`, `codex` | `root:2750`, mode 0750 |
| `/opt/orbit-agent/<version>/agent/{t3,claude-code,codex}` | The releases | `root:2750`, mode 0750 |
| `/opt/orbit-agent/<version>/notes/<type>.md` | The [environment notes](#environment-notes) | Everyone |

The folders live on the Node's root filesystem, at the same paths as in the containers. The recipe pins each part of a folder, in its `instances` object ([recipe schema](/reference/compute-drivers#recipe-schema)): T3, Claude Code and Codex in the agent folder; Composer, Node, Bun, Vite+, rg and the static PHP builds in the runtime folder. Node, Bun and Vite+ reuse the recipe's own pins. A folder's version is the first 12 hex digits of a digest of everything in it, the notes included, so any change is a new version. Each program in `bin` links to its path in its part.

The idmapped mount keeps gid 2750 the same in every container, whatever its ID map. The spike proved this with one container; PR 3 proves it with two containers whose ID maps differ. `orbit` sees the folder `agent`, but not what is in it.

An update installs the new version beside the old one and flips `current`. It runs with the converge of each Node, so a recipe change reaches the Node with its release refresh.

For the agent folder, Orbit then restarts T3 in each running container, one after another; T3 continues a running turn after its restart. For the runtime folder, Orbit restarts the FrankenPHP Process and the other Processes of each running container: every unit named `orbit-*.service`, so the Process units of a container carry that prefix. A stopped container uses the new versions when it wakes. An old version stays until no process maps a file of it.

The CLI self-updaters are off: `DISABLE_AUTOUPDATER=1` in the T3 unit, which Claude Code inherits, and `check_for_update_on_startup = false` in the image's Codex configuration. The T3 server has no self-updater; it runs from the read-only folder.

The OS packages of the image change rarely. The Node's patch run of ADR 0250 upgrades them in each running container from the Node; a sleeping container gets the upgrade when it next wakes.

## Environment notes

Each Project type has one note in the agent folder, at `notes/<note>.md`. The notes live in the Gateway's `resources/instances/notes`:

| Project type | Note |
| --- | --- |
| `laravel-app`, `symfony-app` | `web-app`: a web app with previews |
| `monorepo` | `monorepo` |
| `laravel-package`, `node-package` | `package` |

When it creates an Instance, Orbit links `/home/agent/.claude/CLAUDE.md` and `/home/agent/.codex/AGENTS.md` to the note of the Project's type. A note states facts about the environment only. It names the two users and the workspace path, and points to `/etc/orbit/instance` for the Instance's URL. It says where PHP, Composer and the other runtime programs are. It says how previews work, and how an agent's own development server becomes reachable: the port range, the address to bind and the host name to allow.

A note gives no instructions about the work. Instructions, skills and MCP servers come from the project's own files.

## Model access

A container holds only a placeholder key, in `/home/agent/.config/orbit/model.env`. Claude Code and Codex send model calls to a forwarder at port 8319 of the bridge's gateway address. The [forwarder](/reference/ai-router#container-model-forwarder) passes them to the model router guard, which admits the agent Node and swaps in the real key. The real key stays off the Node. While the forwarder's table is loaded, only containers on the bridge reach the guard, through the forwarder. The guard accepts the `?beta=true` query that Claude Code sends. On a prepared Node, `ai-router:model-forwarder NODE --bridge=orbitinst0 --address=<bridge address>` places the forwarder.

## Network

A container reaches only what this table lists. Everything else is dropped at its bridge.

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
- **Admission.** Only the bridge reaches the port. The bridge ACL and the Node's rule `orbit:instances-egress` admit it there. The proxy's table resets a connection to the port from anywhere else.
- **Health.** The converge ends by checking that the proxy and its table run. Otherwise it fails with `instance.host_unavailable`.

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

Every lifecycle flow runs from the Node, with `incus` and Ansible phases. Development Instances have no Schedules: Schedules are production only ([ADR 0215](/decisions/0215-start-development-processes-from-project-definitions)).

### The default Instance

The default Instance is the clone source of its Project on the Node. Orbit builds the Project's first default Instance from scratch. It launches a container from the image and pushes in the default branch, fetched on the Node. The database family creates the database on a Database server once the container can reach one; until then the default uses the database in its workspace. Orbit writes `.env` and runs the Project's [development deploy steps](/reference/deployments#development-deploy-steps), which install dependencies and migrate. The [first default chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/create-default.mmd) has the steps.

After that, the default Instance deploys every merge to the default branch, as a checkout default does today ([development deployment](/reference/deployments)). Orbit pushes the new commit in, checks it out, and runs the development deploy steps in the container. After a deployment passes, Orbit stops the default's Processes for a moment and takes the snapshot `seed-<commit>`. Clones copy from that snapshot, so they never see files half written. The [deploy chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/deploy-default.mmd) has the steps. The default Instance runs no T3 and no agent: a thread always starts in a new Instance.

There are no development setup steps. The default Instance has them done already, so a clone needs none.

#### What is built

The first default Instance and its deployments are built for the Nodes of the [build list](#the-build-list). A Project has one `default` Instance in the whole fleet, and every clone of it is made on its Node.

| Part | What Orbit does |
| --- | --- |
| Container | `instance-<id>` in the project `orbit-instances`, from the image `orbit-instance`, at the lowest free guest address of the bridge. It has no T3 port |
| ACL | The NIC carries the bridge ACL and the container's own ACL `instance-<id>`. Only the Node's Caddy, from the bridge's address, reaches port 8080 |
| Git | The Node fetches with the read token into its mirror `/var/lib/orbit/instance-repositories/<id>.git`. A bundle carries the branch into the container, and `orbit` checks it out in `/srv/workspace` |
| Checkout path | The workspace's path on the Node, inside the container's root file system. It keeps the path unique on the Node; Orbit never works in it from the Node |
| `.env` | In the workspace's application directory, for `orbit` with group `work`, mode 0640 |
| Deploy steps | Each runs as `orbit` at `/srv/workspace`, from its own vault copy. Its environment holds only `HOME`, `USER`, `LOGNAME`, `PATH`, the runtime folder's `VP_HOME` and `VP_NO_UPDATE_CHECK=1`, and the [proxy settings](#proxy-settings-in-a-container) |
| `PATH` | The Project's PHP build, then the runtime folder's `bin`, then the system folders |
| FrankenPHP | The unit `orbit-frankenphp.service` runs `frankenphp php-server` as `orbit` on port 8080 of the bridge address. The Route proxies to it; the Node runs no PHP pool for it |
| Seed | After the first build and each deployment that passes, Orbit stops the units, takes `seed-<commit>` and starts them again. It deletes older seeds, and the runtime row records the commit |
| Deployment | The variant `container` starts a stopped container and fetches the commit on the Node. It refuses `deployment.checkout_dirty`, `deployment.commit_not_on_branch` and `deployment.branch_diverged` as a checkout does |

A container default without `--database-server` uses the database its Project names in the workspace, such as SQLite. With `--database-server`, the database family creates its database on that server, and the container reaches it by its own [database rules](#network). A Project with development Process definitions refuses with `operation.kind_unavailable`. Dependency installs in the deploy steps reach the internet through the [egress proxy](#egress-and-dns).

A web-serving Project without a PHP version refuses with `project.source_defaults_incomplete`, and one whose PHP build the runtime folder lacks with `instance.host_unavailable`. Destroy, setup, `env:sync`, `env:import`, transfer, rename and clone refuse a container Instance with `operation.kind_unavailable`: they work on a checkout from the Node. Another Instance of the Project refuses with `instance.default_missing` on any Node but the default's, and on that Node until the default is active with a seed snapshot. Every other Instance is a [clone](#create).

### Create

`orbit instance:create PROJECT NODE NAME` clones the default Instance. It copies the default container with `incus copy`, a ZFS clone, and clones the default database through the database family. Then it starts the container, creates the Instance's branch from the default branch, and writes the `.env`, the agent settings, the note links and `/etc/orbit/instance`. Last, it starts T3 and the Processes, adds the T3 project at `/srv/workspace`, publishes the Route and registers the T3 server. The [create chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/create.mmd) has the steps.

A clone starts at the commit of the default's newest seed snapshot, with its dependencies installed. Its branch is the matching origin branch, fetched on the Node, or a new branch at the seed's commit.

It keeps the default's `APP_KEY`, because the cloned database holds data encrypted with it; the rest of `.env` is the Instance's own. When the Instance's branch changes a lockfile later, the agent or the user installs the change in the workspace with the runtime folder's `composer` or `bun`. A sleeping default Instance is cloned from its snapshot without a start. A seed snapshot stays while a clone depends on it; Orbit deletes older seed snapshots that no clone uses.

#### What is built for clones

Clones are built for the Nodes of the [build list](#the-build-list).

| Part | What Orbit does |
| --- | --- |
| Default | The Project's `default` runs in a container on the same Node, is active and has a seed snapshot. Otherwise `instance.default_missing` |
| Conn and models | Conn is on, else `operation.kind_unavailable`. The model router has its guard, else the router's own code |
| Runtime row | The next free bridge address, the lowest free T3 port from 3800, and the default as clone source |
| Copy | `incus copy` of `seed-<commit>` with the clone's own address and ACL. A stopped default needs no start |
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

`orbit instance:destroy INSTANCE` refuses while a T3 turn runs, and refuses unpushed work as today. Orbit fetches origin on the Node and compares it with the container's branches, so no token enters the container. `--force` overrides both checks. It revokes the pairings, withdraws the Route, deletes the container and the database, and marks the T3 server removed. The thread history goes with the container; an archive comes later.

The default Instance cannot be destroyed while it has clones: they share its blocks. A broken default is repaired by a deployment; replacing a default container that has clones is not part of this runtime yet. The [destroy chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/destroy.mmd) has the steps.

### Sleep and wake

After one hour without activity, Orbit stops the whole container. It counts both HTTP activity and T3 activity: a turn, or a client working in a thread. A running task keeps the container awake. Keep-alive Processes do not: the whole container stops. A stopped container uses no memory; its disk stays.

The next request to its Route, a Conn connection, a task turn or a deployment wakes it. Orbit starts the container, and T3, the app and the Processes start with it in about 3 s.

The [sleep](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/sleep.mmd) and [wake](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/wake.mmd) charts have the steps. A container never prunes its dependencies: a stopped clone shares most of its blocks with the default Instance.

### Migrate a checkout Instance

Orbit moves each existing checkout Instance into a container, with its code and its database, one Instance at a time. A default Instance moves first, and a non-default Instance before its default refuses with `instance.default_not_migrated`.

- **Stop.** Orbit stops the Instance's Processes, and its Route shows the wake page.
- **Repository.** A non-default checkout is a linked worktree of the default's repository. Orbit builds a standalone repository in the container: it fetches the Instance's branches on the Node and pushes them in.
- **Files.** Orbit copies the uncommitted and ignored files of the checkout into the workspace, never its `.git`.
- **Database.** The database family clones the checkout's database into a new one; the old one stays until the move succeeds.
- **Route.** Orbit points the Route at FrankenPHP in the container.
- **Removal.** Orbit removes the checkout and the old database. The default's checkout stays until its last worktree has moved.

A failed move leaves the checkout Instance serving as before. The [migration chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/migrate.mmd) has the steps.

## T3 registration and pairing

The Instance's create flow registers its T3 server with the [Conn layer](/reference/conn#registration), with the same record and the same checks as a server that registers itself:

In the container, as `agent`:

1. A phase in the container issues a session with `t3 auth session issue --label "Orbit Gateway" --token-only`, as `agent`.
2. The same phase issues the `Orbit Swamp` session for [Swamp drives T3](#swamp-drives-t3). PR 8 narrows its scopes.
3. It reads the served environment and asks T3 about both sessions at `/api/auth/session`. The reply waits in the run folder, mode 0600.

On the Swamp worker:

1. The next step, `@nckrtl/instance-t3`, makes the checks of `POST /conn/environments`. T3 must know both sessions, and the Gateway's must carry `access:read` and `access:write`.
2. That step stores both sessions in the vault, as native sensitive fields.
3. It returns the environment id, the server version, the expiry and the two vault keys.

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

Starting a thread in an Orbit Project in Conn creates an Instance, a clone of the default Instance. Conn names it from the first message, pairs with its T3 server and starts the thread there with that message. The [thread chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/thread.mmd) has the steps. A thread started inside an Instance's server stays in that Instance.

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
| `instance.default_not_migrated` | 409 | `instance:migrate` of a non-default Instance whose default Instance is still a checkout |
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

A clone of a container that ran T3 serves the same environment id. It also accepts the same sessions, the Gateway's among them. The default Instance is the one clone source, and it never runs T3, so no clone inherits an identity. Because the default deploys every merge, a clone starts current without setup steps.

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
