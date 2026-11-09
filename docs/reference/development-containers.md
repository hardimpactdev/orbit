---
title: "Development containers"
description: "Not built. Step 6 of ADR 0250: every development Instance on a container Node runs in one unprivileged Incus container, with the app as `orbit`, a T3 server and its agents as `agent`, a shared agent stack, and a thread in Conn as its way in."
---

# Development containers

**Not built.** This page specifies step 6 of [ADR 0250](/decisions/0250-run-instances-and-task-groups-in-incus-containers#build-order). It describes the target. The PRs in [Development container runtime](/solutions/development-container-runtime) build it, one part at a time, and move each part into its owning page. Until then, development Instances run from checkouts on their Node, as [Applications](/domains/applications) describes.

A development Instance on a container Node is one unprivileged Incus system container on that Node. The container holds the Instance's checkout, its app and Processes, and a T3 server with Claude Code and Codex. It has no WireGuard identity, no roles and no Node record. Orbit reaches it from its Node with `incus`, and users reach it through its Route and its T3 server.

Every container is a copy of the Project's source container on the Node, which never ran T3. A copy starts T3 for the first time, so each Instance gets its own T3 identity. A feature copy also gets a copy of the source Instance's database.

## Charts

The container flows keep the kinds of today's Instance flows. Their charts sit one folder below the command charts, as the [task container charts](/reference/task-containers) do, because each kind keeps its checkout chart until the switch.

| Flow | Chart |
| --- | --- |
| Create an Instance | [container/create.mmd](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/create.mmd) |
| Copy a feature with its database (`--from`) | [container/copy.mmd](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/copy.mmd) |
| Destroy an Instance | [container/destroy.mmd](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/destroy.mmd) |
| Put an idle Instance to sleep | [container/sleep.mmd](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/sleep.mmd) |
| Wake an Instance | [container/wake.mmd](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/wake.mmd) |
| Register and renew the T3 server | [container/t3-register.mmd](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/t3-register.mmd) |
| Start a thread in an Orbit Project | [container/thread.mmd](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/thread.mmd) |

## Container Nodes

An `app-dev` Node becomes a container Node with one more member of its [`settings.apps`](/reference/node-settings#set-the-apps-root): `containers`. On a container Node, `instance:create` makes a container. Instances that already run from checkouts keep running until they are destroyed.

| Field | Required | Default | Rule |
| --- | --- | --- | --- |
| `cidr` | Yes | | Private IPv4 bridge network. It must not overlap the VPN subnet or any other bridge of the Node or of another Node |
| `project` | No | `orbit-instances` | Incus project of the Instance containers and source containers |
| `network` | No | `orbitdev0` | Bridge of the containers |
| `t3_ports` | No | `3800-3999` | Ports on the Node's WireGuard address that publish the T3 servers, one per container |

Node preparation adds the Incus project and bridge, the container image, the [agent stack](#shared-agent-stack), the forwarder of the [model route](#model-access), access from the bridge to the Node's database server, and the firewall rules below. The Node must meet the host requirements of ADR 0250: current kernel, SCTP off and no secrets.

## Inside a container

Every container has the same layout. The image and the create set it up; nothing in the container can change it.

| Part | Setting |
| --- | --- |
| Image | Ubuntu 26.04 with OS packages, PHP from the [recipe](/reference/compute-drivers#machine-software-recipe), the users, `nftables`, `acl` and `hidepid`. No agent software |
| `orbit` | uid 1000, home `/home/orbit` mode 0750, password locked. Runs the app and every Orbit Process |
| `agent` | uid 2750, home `/home/agent` mode 0700, password locked. Runs T3, Claude Code, Codex and their shell commands. Owns the T3 state, the agent settings and the placeholder key |
| `work` | gid 2760, members `agent` and `orbit` |
| Sudo | None for either user. The image's `sudoers.d` grant is removed |
| Workspace | `/srv/workspace`, owned `agent:work`. Directories are setgid `work`, mode 2770, with the default ACL `g:work:rwX` and no access for others. `core.sharedRepository=group` |
| Git | `safe.directory=/srv/workspace` in `/home/orbit/.gitconfig` only |
| Agent stack | `/opt/orbit-agent`, the Node's folder, mounted read-only and idmapped |
| T3 unit | `User=agent`, `UMask=0007`, `PATH` from the agent stack, `Requires=nftables.service`. The unit file is mode 0640 |
| Model route | `nftables` in the container lets only uid 2750 reach the forwarder, and only uid 0 and 2750 reach T3's port |
| Processes | `/proc` with `hidepid=invisible`, so each user sees only its own processes |
| Kernel surface | Unprivileged, no nesting, `user.max_user_namespaces=0`, an isolated ID map |

A container mounts no host checkout and no Incus socket. Root work, such as packages and units, goes through Orbit from the Node.

### Who runs what

The app runs as `orbit`: PHP-FPM, queue workers, development servers and every Orbit Process and Schedule. `orbit` cannot run the agent binaries, read `/home/agent`, reach the model route or T3's port, or see agent processes. A preview URL reaches only an Orbit Process, so an attacker who comes in through it lands as `orbit`.

T3 and the agents run as `agent`, and so do the shell commands of Claude Code and Codex. There is no wrapper shell. Both users read and write the workspace: a file each one writes is `rw-rw----`, group `work`.

An agent may start its own development server, such as `npm run dev`, as `agent`. It views it in T3's browser through the T3 server. Orbit does not publish it and does not restrict it. Such a server can read what `agent` can read, so no Route points at it.

## Shared agent stack

The agent software lives once per Node, in `/opt/orbit-agent`:

| Path | Content | Access |
| --- | --- | --- |
| `<version>/bin` | Node, npm, npx, Bun, rg | Everyone |
| `<version>/agent/bin` | `t3`, `claude`, `codex` | `root:2750`, mode 0750 |
| `<version>/agent/{t3,claude-code,codex}` | The releases | `root:2750`, mode 0750 |
| `<version>/notes/<type>.md` | The [environment notes](#environment-notes) | Everyone |
| `current` | Link to the version in use | Everyone |

The recipe pins each version. Every container mounts `/opt/orbit-agent` read-only with an idmapped mount, so gid 2750 is the same inside every container. `orbit` sees the folder `agent`, but not what is in it.

An update installs the new version beside the old one, flips `current`, and restarts T3 in each running container, one after another. T3 continues a running turn after its restart. A stopped container uses the new version when it wakes. The old version stays until no T3 process uses it. The CLI self-updaters are off: `DISABLE_AUTOUPDATER=1` for Claude Code, `check_for_update_on_startup = false` for Codex, and T3's own update check off.

## Environment notes

Each Project type has one note in the agent stack:

| Project type | Note |
| --- | --- |
| `laravel-app`, `symfony-app` | Web app with previews |
| `monorepo` | Monorepo |
| `laravel-package`, `node-package` | Package |

When it creates an Instance, Orbit links `/home/agent/.claude/CLAUDE.md` and `/home/agent/.codex/AGENTS.md` to the note of the Project's type. A note states facts about the environment only. It names the two users, the workspace path and the Instance's URL. It says how previews work and how an agent views its own server. It says that there is no sudo and no direct internet, and how Git leaves the container. It gives no instructions about the work. Instructions, skills and MCP servers come from the project, as before.

## Model access

A container holds only a placeholder key, in `/home/agent/.config/orbit/model.env`. Claude Code and Codex send model calls to a forwarder on the bridge's gateway address. The forwarder passes them to the [model router guard](/reference/ai-router), which accepts the placeholder from agent Nodes and swaps in the real key. The real key stays off the Node. A separate PR builds the forwarder and the guard change, including the `?beta=true` query that Claude Code sends.

## Network

A container reaches only what this table lists. Everything else is dropped at its bridge.

| Path | How |
| --- | --- |
| T3 | An Incus proxy device in NAT mode publishes T3 on the Node's WireGuard address, at a port from `t3_ports`. A firewall route rule admits it |
| Preview | The Instance's Route on the Node's Caddy proxies to the web Process in the container, at its bridge address |
| Database | The container reaches the Node's database server at the bridge's gateway address. The server admits the bridge network for the Instance's own role only |
| Models | The forwarder at the bridge's gateway address |
| DNS | The bridge's DNS |
| Everything else | Dropped, including the Gateway and GitHub |

A container cannot reach the Gateway: its traffic would leave as its Node's WireGuard address, with the Node's Gateway access. Package downloads for setup go through the egress model of step 2c of ADR 0250.

## Source container

Each Project has one source container on each container Node, `<project>-source`. It holds the image, the Project's default branch, and its installed dependencies. It never starts T3: the T3 unit starts only when `/home/agent/.config/orbit/instance` exists, which a copy gets. Orbit builds the source container at the first create for a Project on the Node, and keeps it stopped.

## Lifecycle

### Create

`orbit instance:create PROJECT NODE NAME` copies the source container, brings the branch in, gives the Instance its database, `.env`, agent settings and environment note, starts the container, runs the setup steps as `orbit`, starts the Processes, publishes the Route and registers the T3 server. The [create chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/create.mmd) has the steps.

A new branch starts from the default branch. As today, a non-default Instance gets a copy of the default Instance's database when the default Instance has one.

### Copy a feature

`orbit instance:create PROJECT NODE NAME --from=SOURCE` is a create whose branch and data come from another Instance. The files still come from the source container. The new branch starts at the commit that `HEAD` points to in the source Instance, local commits included. Its database is a `pg_dump` of the source Instance's database, restored into the new one. The source keeps running: `pg_dump` reads one consistent snapshot. Uncommitted files stay in the source. The [copy chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/copy.mmd) has the steps.

### Destroy

`orbit instance:destroy INSTANCE` refuses while a T3 turn runs, and refuses unpushed work as today. `--force` overrides both. It revokes the pairings, withdraws the Route, deletes the container and the database, and marks the T3 server removed. The thread history goes with the container. The [destroy chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/destroy.mmd) has the steps.

### Sleep and wake

After the idle window of [hibernation](/reference/app-dev-runtime-hibernation#idle-window-and-sweep), Orbit stops the whole container if T3 runs no turn and no task drives it. A stopped container uses no memory; its disk stays. The next request to its Route, a Conn connection, or a task turn wakes it: Orbit starts the container, and T3, the app and the Processes start with it in about 3 s. The [sleep](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/sleep.mmd) and [wake](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/wake.mmd) charts have the steps. A container never prunes its dependencies: a stopped copy shares most of its blocks with the source container.

## T3 registration and pairing

Orbit registers each container's T3 server with the [Conn layer](/reference/conn#registration), with the same record and the same checks as a server that registers itself:

1. A step in the container issues a session with `t3 auth session issue --label "Orbit Gateway" --token-only`, as `agent`. Swamp stores it in the vault, never in the run.
2. The run returns the environment id and the URL: the Node's WireGuard address and the container's port.
3. The Gateway records the server from the run's result. It checks the served environment id and the session's scopes. It stores the session encrypted.
4. The server's Node is the container's Node, and the record names the Instance.

The container cannot call the Gateway, so registration never comes from inside it. Orbit issues a new session when it wakes a container whose session expires within seven days, and a daily check renews the sessions of running containers. The [registration chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/t3-register.mmd) has the steps.

A device pairs as with any T3 server: [`conn:pairing:create`](/reference/conn#pairing-and-revocation), with node access to the container's Node. Pairing with a sleeping Instance's server refuses with `conn.environment_asleep`; the device wakes it first.

## Threads and tasks

Starting a thread in an Orbit Project in Conn creates an Instance, pairs with its T3 server and starts the thread there with the first message. The [thread chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/thread.mmd) has the steps. A thread started inside an Instance's server stays in that Instance.

A task can attach to an Instance. While the task flow runs, Swamp drives turns in the Instance's thread through T3's MCP endpoint with the Gateway's session: it sends with `queue`, waits for the turn and reads the result. While the task is paused, or when there is no task, the user drives from the thread. Both use the same session and history, and T3 queues a message that arrives during a turn. No lease decides who drives.

## Git in and out

A container cannot reach GitHub. Orbit moves branches from the Node, as it does for [task VMs](/reference/compute-drivers#git-on-a-task-vm): it fetches with a minted read token and pushes the branch into the container's repository at create. Publishing a branch is an Orbit operation: Swamp reads the branch out of the container and pushes it with a minted write token. The tokens never enter the container.

## Records

Orbit records the container as the Instance's runtime: its Incus project and name, bridge address, T3 port, the agent stack version it started with, the source container it came from, and its power state. The T3 server's record in the Conn layer names the Instance. A source container is recorded per Project and Node, with the commit it holds.

## Errors

The container runtime adds these codes. The other codes of create and destroy stay as they are.

| Code | HTTP | Cause |
| --- | --- | --- |
| `instance.source_not_container` | 409 | `--from` names an Instance that is not a container Instance of the same Project on the same Node, or is not active |
| `instance.database_server_remote` | 422 | The Instance's database server is not on its Node |
| `instance.turn_running` | 409 | `instance:destroy` without `--force` while a T3 turn runs in the container |
| `instance.t3_unready` | 502 | The T3 server did not answer after the container started |
| `conn.environment_asleep` | 409 | A pairing with a sleeping Instance's T3 server |
| `node.settings_invalid` | 422 | `apps.containers` has an invalid field, or its bridge collides with another |

## Why it works this way

These reasons shape the runtime. ADR 0250 lists the alternatives that were rejected.

### T3 inside, not outside

T3's changed-files view, terminals and browser work on its own disk. From outside, each would need a remote layer through every upstream sync. T3 already has what Swamp needs.

### Two users, no wrapper

The boundary that matters is the preview URL, which reaches only `orbit`. A switch for agent commands adds little. The container holds only its Project's `.env`, and its traffic goes through Orbit. Codex also has no documented setting for its shell.

### A source that never ran T3

A copy of a container that ran T3 serves the same environment id. It also accepts the same sessions, the Gateway's among them. Deleting identity files after a copy fails open when one is missed.

### Files from the source, data from a dump

A copy needs no pause of the source. The files never change in the source container, and `pg_dump` reads a consistent snapshot of a live database. The cost is that uncommitted work stays behind.

### One stack per Node

One update reaches every container, and the image keeps only what rarely changes. An idmapped mount keeps one gid valid in every container.

### Registration from the result

A step may call the Gateway only to sign or to mint. A container that could call the Gateway would act as its Node.

## Evidence

Two spikes on Beast on 2026-11-09 ran this layout in one container, with stock T3 0.1.6, Claude Code 2.1.292 and Codex 0.166.0.

| Check | Result |
| --- | --- |
| Container start | 0.2 s; T3 ready 2–3 s later |
| Copy of a running container | 0.2 s, 1.9 MiB unique; T3 ready 2.9 s after start |
| Memory, T3 idle | About 400 MiB; 590 MiB with warm Claude and Codex sessions; 1,165 MiB peak with both turning |
| Shared agent stack | 1.26 GiB once per Node |
| Claude and Codex turns | Through the router with a placeholder key, about 13 s for a short turn |
| Restart in a turn | Both agents continued after `systemctl restart t3` in a 40 s tool call |
| Changed files | `orchestration.getTurnDiff` answered from a Mac over WireGuard |
| `orbit` user | Could not run `t3`, `claude` or `codex`, read `/home/agent`, reach the model route or T3's port, or see agent processes |
| Shared workspace | Each user edited the other's files; Git was clean for both |
| Egress | Only DNS and the guard; no turn failed |
