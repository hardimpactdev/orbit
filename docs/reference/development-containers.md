---
title: "Development containers"
description: "Not built. Step 6 of ADR 0250: every development Instance runs in one unprivileged Incus container, a clone of the Project's default Instance, with the app as `orbit` on FrankenPHP, a T3 server and its agents as `agent`, and shared runtime and agent folders per Node."
---

# Development containers

**Not built.** This page specifies step 6 of [ADR 0250](/decisions/0250-run-instances-and-task-groups-in-incus-containers#build-order). It describes the target. The PRs in [Development container runtime](/solutions/development-container-runtime) build it, one part at a time, and move each part into its owning page. Until then, development Instances run from checkouts on their Node, as [Applications](/domains/applications) describes.

Every development Instance runs in one unprivileged Incus system container on its `app-dev` Node. The container holds the Instance's checkout, its app and Processes, and, except in the default Instance, a T3 server with Claude Code and Codex. It has no WireGuard identity, no roles and no Node record. Orbit reaches it from its Node with `incus`, and users reach it through its Route and its T3 server.

Every new Instance is a ZFS clone of the Project's default Instance on the Node, and its database is a clone of the default Instance's database. The default Instance is the only one built from scratch, and it deploys every merge to the default branch, so clones start at the current default branch with its dependencies installed. The default Instance never runs T3, so each clone starts T3 for the first time and gets its own T3 identity.

## Charts

The container flows keep the kinds of today's Instance flows. Their charts sit one folder below the command charts, as the [task container charts](/reference/task-containers) do, because each kind keeps its checkout chart until the switch.

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

Every `app-dev` Node runs its Instances in containers. There is no setting to opt in. The `app-dev` role installs the container setup instead of PHP-FPM and checkout folders: the Incus project `orbit-instances`, the bridge `orbitinst0`, the container image, the [shared folders](#shared-folders), the forwarder of the [model route](#model-access) and the firewall rules. The Gateway gives each Node a bridge network from a fleet range that overlaps no other bridge and not the VPN subnet, and records it on the Node. T3 servers are published on ports 3800 to 3999 of the Node's WireGuard address.

The Node must meet the host requirements of ADR 0250: current kernel, SCTP off and no secrets. A Node that still has checkout Instances keeps them until [migration](#migrate-a-checkout-instance) moves them into containers. The `app-prod` role follows the same model in step 8 ([Production Instances](#production-instances)).

## Inside a container

Every development container has the same layout. The image and the create set it up. Root owns everything below except the homes and the workspace. A container installs as little as possible: the image holds only OS packages, the users and the firewall.

| Part | Setting |
| --- | --- |
| Image | Ubuntu 26.04 with the OS packages the runtime needs, the users, `nftables`, `acl` and `hidepid`. No PHP, no Node, no agent software |
| `orbit` | uid 1000, home `/home/orbit` mode 0750, password locked. Runs the app and every Orbit Process |
| `agent` | uid 2750, home `/home/agent` mode 0700, password locked. Runs T3, Claude Code, Codex and their shell commands. Owns the T3 state, the agent settings and the placeholder key |
| `work` | gid 2760, members `agent` and `orbit` |
| Sudo | None for either user. The image's `sudoers.d` grant is removed |
| Workspace | `/srv/workspace`, owned `agent:work`. Directories are setgid `work`, mode 2770, with the default ACL `g:work:rwX` and no access for others. `core.sharedRepository=group` |
| Git | `safe.directory=/srv/workspace` in `/home/orbit/.gitconfig` only |
| Shared folders | `/opt/orbit-runtime` and `/opt/orbit-agent`, the Node's folders, mounted read-only and idmapped |
| T3 unit | `User=agent`, `UMask=0007`, `PATH` from the shared folders, `Requires=nftables.service`, `ConditionPathExists=/etc/orbit/instance`. The unit file is mode 0640 |
| Instance file | `/etc/orbit/instance`, owned by root and readable by all: the Instance, its Project and its URL. The default Instance has none, so T3 never starts there |
| Model route | `nftables` in the container lets only uid 2750 reach the forwarder, and only uid 0 and 2750 reach T3's port |
| Processes | `/proc` with `hidepid=invisible`, so each user sees only its own processes |
| Kernel surface | Unprivileged, no nesting, `user.max_user_namespaces=0`, an isolated ID map |

A container mounts no host checkout and no Incus socket. Root work, such as units, goes through Orbit from the Node.

### Who runs what

The app runs as `orbit`: FrankenPHP, queue workers, development servers and every Orbit Process. `orbit` cannot run the agent binaries, read `/home/agent`, reach the model route or T3's port, or see agent processes. A preview URL reaches only an Orbit Process, so an attacker who comes in through it lands as `orbit`.

T3 and the agents run as `agent`, and so do the shell commands of Claude Code and Codex. There is no wrapper shell. Both users read and write the workspace: a file each one writes is `rw-rw----`, group `work`.

An agent may start its own development server, such as `npm run dev`, as `agent`. It views it in T3's browser through T3's own port preview, which PR 5 must prove; no spike tested it. Orbit does not publish it and does not restrict it. Such a server can read what `agent` can read, so no Route points at it.

### PHP

FrankenPHP serves the app, in classic mode, as an Orbit Process running as `orbit`. Its Caddy listens on the container's bridge address; the Node's Caddy terminates TLS for the Route and proxies to it.

The command line runs a real PHP CLI: a static `php` built by the same static build as FrankenPHP, with the same PHP version and extensions. `frankenphp php-cli` behind a `php` shim does not fit yet. It refuses the `-d` options that Composer and test runners pass, and before PHP 8.6 it cannot run `php -i` or PHP without a script. Orbit runs PHP 8.5. The recipe declares the build's extension list, and the runtime folder's check compares both binaries with it. The shim can replace the CLI binary once FrankenPHP accepts `-d` and Orbit runs PHP 8.6.

## Shared folders

The software that the app and the agents run lives once per Node, in two versioned read-only folders. Every container mounts both with idmapped mounts, so one update reaches every container. Each folder has a `current` link to the version in use, and the recipe pins every version.

| Path | Content | Access |
| --- | --- | --- |
| `/opt/orbit-runtime/<version>/bin` | `frankenphp`, `php`, `composer`, Node, npm, npx, Bun, rg | Everyone |
| `/opt/orbit-agent/<version>/agent/bin` | `t3`, `claude`, `codex` | `root:2750`, mode 0750 |
| `/opt/orbit-agent/<version>/agent/{t3,claude-code,codex}` | The releases | `root:2750`, mode 0750 |
| `/opt/orbit-agent/<version>/notes/<type>.md` | The [environment notes](#environment-notes) | Everyone |

The folders live on the Node's root filesystem. The idmapped mount keeps gid 2750 the same in every container, whatever its ID map. The spike proved this with one container; PR 3 proves it with two containers whose ID maps differ. `orbit` sees the folder `agent`, but not what is in it.

An update installs the new version beside the old one and flips `current`. For the agent folder, Orbit then restarts T3 in each running container, one after another; T3 continues a running turn after its restart. For the runtime folder, Orbit restarts the FrankenPHP Process and the other Processes of each running container. A stopped container uses the new versions when it wakes. An old version stays until no process uses it. The CLI self-updaters are off: `DISABLE_AUTOUPDATER=1` for Claude Code, `check_for_update_on_startup = false` for Codex, and T3's own update check off.

## Environment notes

Each Project type has one note in the agent folder:

| Project type | Note |
| --- | --- |
| `laravel-app`, `symfony-app` | Web app with previews |
| `monorepo` | Monorepo |
| `laravel-package`, `node-package` | Package |

When it creates an Instance, Orbit links `/home/agent/.claude/CLAUDE.md` and `/home/agent/.codex/AGENTS.md` to the note of the Project's type. A note states facts about the environment only. It names the two users and the workspace path, and points to `/etc/orbit/instance` for the Instance's URL. It says how previews work and how an agent views its own server. It says that there is no sudo and no direct internet, and how Git leaves the container. It gives no instructions about the work. Instructions, skills and MCP servers come from the project's own files.

## Model access

A container holds only a placeholder key, in `/home/agent/.config/orbit/model.env`. Claude Code and Codex send model calls to a forwarder on the bridge's gateway address. The forwarder passes them to the [model router guard](/reference/ai-router), which accepts the placeholder from agent Nodes and swaps in the real key. The real key stays off the Node. A separate PR builds the forwarder and the guard change, including the `?beta=true` query that Claude Code sends.

## Network

A container reaches only what this table lists. Everything else is dropped at its bridge.

| Path | How |
| --- | --- |
| T3 | An Incus proxy device in NAT mode publishes T3 at a port from 3800 to 3999 on the Node's WireGuard address. A firewall route rule admits WireGuard peers, but not task VMs |
| Other containers | None. Each NIC has `security.port_isolation`, so containers on the bridge cannot reach each other |
| Preview | The Instance's Route on the Node's Caddy proxies to FrankenPHP in the container, at its bridge address |
| Database | The Instance's database server and port only: at the bridge's gateway address on the Node, or over WireGuard through the Node. The server admits the Instance's own role only |
| Models | The forwarder at the bridge's gateway address |
| DNS | The bridge's DNS |
| Everything else | Dropped, including the Gateway and GitHub |

A container cannot reach the Gateway: its traffic would leave as its Node's WireGuard address, with the Node's Gateway access. Dependency installs go through the egress model of step 2c of ADR 0250.

## Lifecycle

Every lifecycle flow runs from the Node, with `incus` and Ansible phases. Development Instances have no Schedules: Schedules are production only ([ADR 0215](/decisions/0215-start-development-processes-from-project-definitions)).

### The default Instance

The default Instance is the clone source of its Project on the Node. Orbit builds the Project's first default Instance from scratch. It launches a container from the image and pushes in the default branch, fetched on the Node. The database family creates the database. Orbit writes `.env` and runs the Project's [development deploy steps](/reference/deployments#development-deploy-steps), which install dependencies and migrate. The [first default chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/create-default.mmd) has the steps.

After that, the default Instance deploys every merge to the default branch, as a checkout default does today ([development deployment](/reference/deployments)). Orbit pushes the new commit in, checks it out, and runs the development deploy steps in the container. The [deploy chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/deploy-default.mmd) has the steps. The default Instance runs no T3 and no agent: a thread always starts in a new Instance.

There are no development setup steps. The default Instance has them done already, so a clone needs none.

### Create

`orbit instance:create PROJECT NODE NAME` clones the default Instance. It copies the default container with `incus copy`, a ZFS clone, and clones the default database through the database family. Then it starts the container, creates the Instance's branch from the default branch, and writes the `.env`, the agent settings, the note links and `/etc/orbit/instance`. Last, it starts T3 and the Processes, publishes the Route and registers the T3 server. The [create chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/create.mmd) has the steps.

A clone starts at the default Instance's commit, with its dependencies installed. When the Instance's branch changes a lockfile later, the agent or the user installs the change in the workspace with the runtime folder's `composer` or `bun`. A sleeping default Instance is cloned as it is; a clone of a stopped container is the same ZFS clone.

### Clone the database

The database family clones every database, whether the server is on the Instance's Node or on another Node:

- **Same server.** `CREATE DATABASE <new> TEMPLATE <default>`. PostgreSQL copies only from a database without other sessions.
- **The moment of the copy.** Orbit refuses new connections to the default database and ends its sessions. It copies, then allows connections again. The default Instance sees refused connections for that moment.
- **Another server.** `pg_dump` of the default database in one snapshot, restored into the new database with `pg_restore --no-owner --no-privileges --single-transaction`.

Orbit creates the owner and grants; neither path copies roles.

### Destroy

`orbit instance:destroy INSTANCE` refuses while a T3 turn runs, and refuses unpushed work as today. Orbit fetches origin on the Node and compares it with the container's branches, so no token enters the container. `--force` overrides both checks. It revokes the pairings, withdraws the Route, deletes the container and the database, and marks the T3 server removed. The thread history goes with the container; an archive comes later. The default Instance cannot be destroyed while it has clones. The [destroy chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/destroy.mmd) has the steps.

### Sleep and wake

After one hour without activity, Orbit stops the whole container. It counts both HTTP activity and T3 activity: a turn, or a client working in a thread. A running task keeps the container awake. Keep-alive Processes do not: the whole container stops. A stopped container uses no memory; its disk stays.

The next request to its Route, a Conn connection, a task turn or a deployment wakes it. Orbit starts the container, and T3, the app and the Processes start with it in about 3 s. The [sleep](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/sleep.mmd) and [wake](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/wake.mmd) charts have the steps. A container never prunes its dependencies: a stopped clone shares most of its blocks with the default Instance.

### Migrate a checkout Instance

Orbit moves each existing checkout Instance into a container, with its code and its database. It copies the checkout as it is, uncommitted files included, into a new container and clones the database through the database family. Then it moves the Route to the container and removes the checkout. A default Instance moves first, so its Project's other Instances can follow as clones. The [migration chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/migrate.mmd) has the steps.

## T3 registration and pairing

The Instance's create flow registers its T3 server with the [Conn layer](/reference/conn#registration), with the same record and the same checks as a server that registers itself:

1. A step in the container issues a session with `t3 auth session issue --label "Orbit Gateway" --token-only`, as `agent`. Swamp stores it in the vault, never in the run.
2. The same step checks the served environment id and the session's scopes, as `POST /conn/environments` does.
3. The run returns the environment id and the URL: the Node's WireGuard address and the container's port.
4. The Gateway records the server from the run's result and moves the session from the vault into its encrypted record. It does not check the result again.
5. The server's Node is the container's Node, and the record names the Instance.

The container cannot call the Gateway, so registration never comes from inside it. Orbit issues a new session when it wakes a container whose session expires within seven days, and a daily check renews the sessions of running containers. The [registration chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/t3-register.mmd) has the steps.

A device pairs as with any T3 server: [`conn:pairing:create`](/reference/conn#pairing-and-revocation), with node access to the container's Node. Pairing with a sleeping Instance's server refuses with `conn.environment_asleep`; the device wakes it first. T3 prints a pairing token to its journal at each start; only root and `agent` can read it, and that is accepted.

## Threads and tasks

Starting a thread in an Orbit Project in Conn creates an Instance, a clone of the default Instance. Conn names it from the first message, pairs with its T3 server and starts the thread there with that message. The [thread chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/thread.mmd) has the steps. A thread started inside an Instance's server stays in that Instance.

A task can attach to an Instance. While the task flow runs, Swamp drives turns in the Instance's thread through T3's MCP endpoint with the Gateway's session. It sends with `queue`, waits for its turn by run id and reads only that turn's result. While the task is paused, or when there is no task, the user drives from the thread. Conn also sends with `queue` during a turn, so neither driver steers the other's turn. No lease decides who drives.

The agents use their own subagents only. MCP servers come from the project's own files: `.mcp.json` for Claude Code and the project's Codex configuration.

## Git in and out

A container cannot reach GitHub. Orbit moves branches from the Node, as it does for [task VMs](/reference/compute-drivers#git-on-a-task-vm): it fetches with a minted read token and pushes the branch into the container's repository. Publishing a branch is an Orbit operation: Swamp reads the branch out of the container and pushes it with a minted write token. The tokens never enter the container.

## Production Instances

Step 8 of ADR 0250 moves production Instances into containers on `app-prod` Nodes, after development containers work. The decided basics:

- The `app-prod` role installs the container setup and the runtime folder, not PHP-FPM. It installs no agent folder.
- A production container has only the user `orbit`: no T3, no agent and no model route.
- FrankenPHP serves it, once it has proved itself in development.
- Production containers never sleep, and production Schedules run inside them as `orbit`.
- Existing production checkouts are migrated, as development checkouts are.

The release flow, with release containers, gets its own specification in the first production PR of the [plan](/solutions/development-container-runtime#production-instances).

## Records

Orbit records the container as the Instance's runtime: its Incus project and name, bridge address, T3 port, the runtime and agent folder versions it started with, the default Instance it was cloned from, and its power state. The T3 server's record in the Conn layer names the Instance. Each Node records its bridge network.

## Errors

The container runtime adds these codes. The other codes of create and destroy stay as they are.

| Code | HTTP | Cause |
| --- | --- | --- |
| `instance.default_missing` | 409 | `instance:create` of a non-default Instance while the Project has no active default Instance on the Node |
| `instance.default_has_clones` | 409 | `instance:destroy` of a default Instance that other Instances were cloned from |
| `instance.turn_running` | 409 | `instance:destroy` without `--force` while a T3 turn runs in the container |
| `instance.t3_unready` | 502 | The T3 server did not answer after the container started |
| `conn.environment_asleep` | 409 | A pairing with a sleeping Instance's T3 server |

## Why it works this way

These reasons shape the runtime. ADR 0250 lists the alternatives that were rejected.

### T3 inside, not outside

T3's changed-files view, terminals and browser work on its own disk. From outside, each would need a remote layer through every upstream sync. T3 already has what Swamp needs.

### Two users, no wrapper

The boundary that matters is the preview URL, which reaches only `orbit`. A switch for agent commands adds little. The container holds only its Project's `.env`, and its traffic goes through Orbit. Codex also has no documented setting for its shell.

### Clones of a default that never runs T3

A clone of a container that ran T3 serves the same environment id. It also accepts the same sessions, the Gateway's among them. The default Instance is the one clone source, and it never runs T3, so no clone inherits an identity. Because the default deploys every merge, a clone starts current without setup steps.

### Shared folders per Node

One update reaches every container, and the image keeps only what rarely changes. An idmapped mount keeps one gid valid in every container.

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
