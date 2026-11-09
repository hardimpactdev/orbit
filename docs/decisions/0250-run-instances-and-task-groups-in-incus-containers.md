---
title: "ADR 0250: Run instances and task groups in Incus containers"
sidebarTitle: "0250 Instances and task groups in containers"
description: "In progress. Every Instance runs in an unprivileged Incus container on its Node, with a T3 server inside as its agent engine. A task runs in such an Instance container, and Swamp drives its T3; a user drives the same T3 in development. A nested fleet is an optional feature. A shared kernel is the accepted risk; patching, SCTP off and no secrets on agent hosts are requirements."
---

# ADR 0250: Run instances and task groups in Incus containers

Every Instance runs in an unprivileged Incus container directly on its Node. A development Instance's container holds a T3 server, its agent engine. A task group runs in such an Instance container: a clone of the Project's default Instance, or an existing Instance that the task attaches to. There is one container kind. Only the driver differs: a user drives the T3 threads in development, and Swamp drives the same T3 for a task. Work that needs its own fleet, such as Orbit's command proofs, turns on a nested fleet: Incus containers nested inside an Instance container. There is no task VM, no separate task container, no Project VM and no VM per Node. All containers on a Node share that Node's kernel.

## Status

In progress.

Nick decided the direction on 2026-11-09, after a spike on Beast proved nested Incus with Docker inside an unprivileged container. On the same day he decided that a T3 server is the agent engine in every agent container, and that a development container and a task container are one thing. The build follows [Build order](#build-order) under Decision.

Principle: [Lean](/mission#principles) and [One way, one name](/mission#principles).

This ADR replaces:

- the outer task VM of each task group, and the decision of 2026-11-06 that it stays a VM;
- the Project VM per Project of the development container plan (2026-11-08);
- the separate task container runtime with its base snapshot and its Node identity (2026-11-09, first plan of this ADR);
- "the proof VM is the trust boundary" (2026-11-09), which becomes "the Instance container is the trust boundary".

The nested-topology lesson of 2026-11-06 still applies: start from seed and ready copies, never from stock images.

## Context

A task VM per group isolates each agent with its own kernel, but it is slow to refresh, heavy to keep, and cannot run on UpCloud, whose servers expose no `/dev/kvm` and no `svm` flag. Development Instances need a cheap copy of a prepared environment, including its database, for every feature. Production Instances need release switches without shared PHP-FPM pools on the Node. A VM per Instance is too heavy for that, and a Project VM per Project adds a boot to every wake and a second kind of runtime.

An Incus container starts in about a second, clones with ZFS copy-on-write in under a second, and needs one runtime record per Instance. The cost is one kernel per Node: a kernel bug reachable from a container can reach the host.

A task needs what a development Instance has: the Project's code with its dependencies, a database, a preview, and an agent engine. A second runtime for tasks would repeat the image, the users, the shared folders, the model route and the Git path. So a task runs in an Instance container, and Swamp drives that container's T3 as a user drives it from Conn.

Spikes on 2026-11-09 proved the parts:

- nested Incus with Docker inside an unprivileged container, on Beast and on UpCloud ([report](/solutions/development-container-runtime#upcloud-report));
- a stock T3 server inside an unprivileged container, with Claude Code and Codex turns through the AI router and only a placeholder key inside;
- the same container split into two users: the agent and its shell commands as `agent`, the app as `orbit`.

None needed a change to T3, Claude Code or Codex. [Instance containers](/reference/development-containers#evidence) summarizes the evidence.

## Decision

Orbit has one runtime kind for Instances and agent work: an unprivileged Incus container on the Node. The sections below fix how Instances and tasks use it, which risk is accepted, and in which order the change lands.

### One runtime kind: the Instance container

- **Every Node with Instances.** Every `app-dev` and `app-prod` Node runs its Instances in containers; there is no setting to opt in. The Nodes' Incus pools are ZFS, which clones need. The two roles install the container setup and the [shared folders](#shared-folders) instead of PHP-FPM and checkout folders. Existing checkout Instances are migrated into containers, code and database.
- **Development Instance.** One container per Instance, recorded as the Instance's runtime. The Project's first default Instance on a Node is built from scratch. Every other Instance is a ZFS clone of that default Instance, and its database is a clone of the default's database through the database family. The default Instance deploys every merge to the default branch, so a clone starts current; there are no development setup steps. The default Instance never runs T3.
- **Task.** A task group runs in one Instance container. Its start clones the Project's default Instance, or the task attaches to an existing Instance. Swamp drives the container's T3 for the task's turns. A clone made for the task is destroyed when the group ends; an attached Instance stays.
- **Nested fleet.** An optional feature, set per Project. Instance containers with it run a fleet as Incus containers nested inside them: for Orbit, a Gateway, an `app-dev` and an `app-prod` Node for command proofs.
- **Production Instance.** Containers on its `app-prod` Node, with the user `orbit` only and no agent. Production containers never sleep. Step 10 moves them, after development containers work.
- **Node.** The machine Orbit runs on: a bare-metal host or a VPS. Containers are runtimes of Instances, never Nodes. They get no WireGuard identity, no roles and no exporter.

### Drivers

A container's T3 has two drivers, and they share its threads:

| Driver | When | How |
| --- | --- | --- |
| User | No task, or a paused task | Conn, paired with the container's T3 through Orbit |
| Swamp | A running task flow | T3's own `/mcp` endpoint and WebSocket API, as [Swamp drives T3](/reference/development-containers#swamp-drives-t3) specifies |

Both send with `queue`, so neither steers the other's turn. No lease decides who drives. There is no separate runtime object and no separate mode for work that a user drives.

### Nested fleet

The spikes of 2026-11-09 settled how an Instance container with a nested fleet runs it:

- The container is unprivileged with `security.nesting=true`, intercepted `mknod` and `setxattr`, and an isolated ID map. Its Incus project allows low-level keys (`restricted.containers.lowlevel=allow`), because `security.idmap.size` is one, and the start refuses `raw.*` keys.
- The host's pool is ZFS. A copy of the container is a clone only with idmapped mounts, which a test copy proves with `zfs get written`.
- The inner Incus gets a subordinate ID range that fits inside the outer one (`/etc/subuid` and `/etc/subgid`, for example `root:1000000:100000000`).
- The inner storage pool is a directory pool inside the container's filesystem. A snapshot and a clone of the default Instance then carry the whole fleet: 0.3 s and 0.5 s in the spike. A ZFS dataset delegated to the container is not used, because `incus copy` does not copy its child datasets.
- A nested container that runs Docker gets nesting and a read-only device that presents `N` at `/sys/module/apparmor/parameters/enabled`. Docker then skips its own AppArmor profile, which cannot load two levels below the host. The host's profile for the outer container stays in force.
- Only the Instance container needs `ip` and `wg` for its tunnel to the nested fleet: Ubuntu 26.04's AppArmor profile makes `wg-quick` segfault there. The nested Nodes keep `wg-quick` from the shared Node converge, which works with the AppArmor-off device above and a cloud-init `bootcmd` that turns off the `wg` and `wg-quick` profiles.
- Units that need secrets use `SetCredential` or files, not `LoadCredential`. In nested containers systemd reports no credential error, but the unit gets no credential and fails.

The default Instance holds the fleet's ready copies, with each nested container stopped and snapshotted, so a clone holds no half-written database pages.

### Users and kernel surface

- **Instance containers.** Two unprivileged users, neither with sudo.
  - The app runs as `orbit`: FrankenPHP, queue workers, development servers and every Orbit Process.
  - T3, Claude Code and Codex run as `agent`, and so do their shell commands. There is no wrapper shell and no switch between the users.
  - `orbit` cannot run the agent binaries, read `/home/agent`, see agent processes or reach the model route. A firewall rule by uid inside the container enforces the last.
  - Both users share the workspace through a group with default ACLs. Git's `safe.directory` names the workspace for `orbit` only, because `agent` owns the repository.
  - This is the boundary against an attacker who comes in through a preview URL.
  - Root work (packages, services, runtime setup) goes through Orbit from the host. A project's system packages come from its recipe, not from the agent.
  - Without a nested fleet, `user.max_user_namespaces=0` is set inside the container, and the container has no nesting.
- **With a nested fleet.** The container gets nesting and the keys above. `agent` joins the inner Incus admin group, with no sudo, which makes it root-equivalent inside its own container. `orbit` still gets none of it. This is the accepted risk below.
- **Production containers.** Only `orbit`. No agent, no T3 and no model route.

### Agent engine in containers

A T3 server runs inside each Instance container except a default Instance and production ones, and is its agent engine. It replaces Pi Durable there. Swamp drives it through T3's own `/mcp` endpoint and WebSocket API, so no adapter runs in the container and Orbit forks neither T3 nor Conn. The Pi server stays only on today's task VMs, until step 9 removes them.

- **Previews.** A preview URL reaches only an Orbit Process, which runs as `orbit`. An agent may start its own development servers as `agent` and view them in T3's browser. Orbit does not publish them, and does not restrict them.
- **Model access.** A container holds only a placeholder key. The [forwarder](/reference/ai-router#container-model-forwarder) on its Node passes model calls to the model router guard, which swaps in the real key. The real key stays off the Node.
- **Clones.** A container that is cloned must never have run T3, or every clone would inherit its server identity and sessions. The clone source is the default Instance, which never runs T3. T3 creates its identity on its first start in the clone.
- **History.** A T3 server's threads are deleted with its Instance. Swamp records a redacted transcript of each task turn, so task transcripts outlive a clone. No other archive exists yet.
- **Project files.** MCP servers come from the project's own files. The agents use their own subagents only.

### Shared folders

The software that containers run lives once per Node, in two versioned read-only folders, each with a `current` link. Orbit mounts them into every container, so an update reaches every container from one place. A container installs as little as possible: its image keeps only OS packages, the users and the firewall.

- **Runtime folder**, `/opt/orbit-runtime/<version>`: FrankenPHP, a PHP CLI, Composer, Node, Bun and rg. Development and production containers mount it.
- **Agent folder**, `/opt/orbit-agent/<version>`: T3, Claude Code, Codex and the environment notes. Only development containers mount it.

An update installs a new version and flips the link. Orbit then restarts T3, or the Processes, in each running container; T3 continues a turn after a restart. The CLI self-updaters are off.

### PHP

FrankenPHP serves every container Instance, in classic mode, as an Orbit Process: in development now, and in production once it has proved itself in development, by the criteria of the plan's production gate. The command line runs a static PHP CLI from the same static build, with the same version and extensions. `frankenphp php-cli` behind a `php` shim does not fit yet: it refuses the `-d` options that Composer and test runners pass, and before PHP 8.6 it cannot run without a script.

Each Project type has one environment note: a web app with previews, a monorepo, or a package. In the agent's home, `~/.claude/CLAUDE.md` and `~/.codex/AGENTS.md` link to the note of the Instance's Project type. This is a narrow exception to "the runtime injects no prompts": a note states facts about the environment only, never instructions about the work.

### Threads, Instances and tasks

Starting a thread in an Orbit Project in Conn makes Swamp create an Instance: a container with its own T3 server. Orbit registers that server, and the user pairs with it through Conn. Access follows the node access edges to the Instance's Node. The thread is a session of that Instance's T3 server.

A task runs in an Instance. While its flow runs, Swamp drives the turns; while it is paused, or when there is no task, the user drives from the thread. Both use the same sessions.

### Accepted risk and requirements

All containers on a Node share its kernel. A kernel privilege-escalation bug reachable from inside a container gives root on the Node: every other container, the Node's databases, and the Node's WireGuard identity with its Gateway access. Such bugs appear several times a year; in 2026 at least one had a public container-to-host exploit before Ubuntu shipped a fix. A container with a nested fleet has more kernel surface: nesting, intercepted system calls and user namespaces.

Nick accepts this for development, production and task work. These are requirements, not options:

1. **Patch.** Nodes that run agent containers install kernel updates and reboot into them on a schedule.
2. **SCTP off.** The `sctp` module cannot load on these Nodes. Nothing in Orbit uses it; WebRTC implements its own SCTP over UDP.
3. **No secrets on agent hosts.** A Node that runs agent containers holds nothing beyond what its containers and roles need. Personal agent logins, SSH keys and GitHub tokens stay off it.
4. **Least Gateway access.** Agent Nodes get only the node access edges their roles need.
5. **Placeholder keys.** External API keys reach containers as placeholders that a proxy outside the container replaces, as the model router guard does.

### Other roles

Roles without agents (database, websocket, metrics, router, ingress) keep running on the Node itself. A container reaches its database server and nothing else of the database role: on its Node through the Incus bridge, on another Node over WireGuard through its Node. The server admits the Instance's own role only.

### Metrics

The Node's agent reads per-container metrics from Incus (`/1.0/metrics`) and per-Process CPU and memory from each container's systemd cgroups, from the host. No exporter runs in a container.

### Build order

Task work is the critical path. The Swamp migration waits until step 6 passes, and the CI proof becomes a required check again then.

1. **This ADR.**
2. **Host hardening** on Beast and Shark: current kernels, SCTP off, an explicit container egress model and bridge DNS, and secrets moved off.
3. **Instance containers** on the Nodes of a build list: Node preparation with the shared folders, the default Instance, clones with T3, and destroy. [Instance containers](/reference/development-containers) specifies them, and [Container runtime](/solutions/development-container-runtime) orders the PRs.
4. **Tasks in Instance containers.** A task starts in a clone or attaches to an Instance, and Swamp drives the container's T3.
5. **Nested fleet** as an optional feature, and command proofs in an Orbit Instance container.
6. **Parity gate.** Orbit's command proof cases pass in an Orbit Instance container with the nested fleet on, with the same verdicts as on task VMs, and a real task group runs there end to end. [The plan](/solutions/development-container-runtime#parity-gate) defines it.
7. **Switch task work** on Beast, then Shark, in a window with no proofs or merges in flight. Running task VMs finish first.
8. **Switch development:** sleep and wake, threads from Conn, the migration of checkout Instances, and every `app-dev` Node.
9. **Remove** the task VM runtime, its Pi server, and the task container host settings that the first plan of this ADR added.
10. **Production Instances** in containers on every `app-prod` Node, with release containers, after development containers work. [Container runtime](/solutions/development-container-runtime#production-instances) orders its PRs.

## Rejected alternatives

- **Keep a VM per task group.** Strongest isolation; too slow and heavy, and impossible on UpCloud.
- **One VM per Node with containers inside.** Protects the host, but adds a second layer of Nodes, moves WireGuard and exporters into a VM, and still cannot run on UpCloud. Rejected for simplicity.
- **A separate task container kind, with its own base snapshot, places and a temporary Node identity, running Pi.** The first plan of this ADR. It would repeat the image, users, folders, model route and Git path of Instance containers, keep Pi as a second engine, and need a second switch to T3. Rejected for one container kind with two drivers.
- **Sibling containers per task group instead of nesting.** Less kernel surface, but a group becomes several containers and a bridge that Swamp must snapshot and restore together, and the agent loses control of its fleet. Rejected because nesting proved to work and keeps one unit per Instance.
- **A nested fleet in every container.** Every Instance would carry nesting and its kernel surface for a feature that only Orbit's proofs use. Rejected for an optional feature.
- **A VM per Instance (as DevVM does with Lima).** Needs KVM, which UpCloud does not offer, and costs a kernel and reserved memory per Instance.
- **One T3 outside the containers, driving a Pi server inside each.** Lighter: a Pi server idles at about 90 MB, a T3 server at about 400 MB. But T3's changed-files view, terminals and browser would need a remote git and shell layer through every upstream sync, and Pi Durable has no MCP client. Rejected for a stock T3 inside each container; idle containers stop, which removes the memory cost.
- **A Swamp adapter process inside the container.** T3's MCP endpoint and WebSocket API already cover threads, turns, waits, reads and interrupts. Rejected: nothing runs in the container for Swamp.
- **Agent shell commands as `orbit` through a wrapper shell.** Proven for Claude Code, but Codex has no documented setting for its shell, and the switch adds little: a container holds only its Project's `.env`, and its egress goes through Orbit. Rejected for agent commands as `agent`.
- **Copy a container that ran T3 and delete its identity files.** Works, but a missed file gives a copy the source's sessions, the Gateway's admin session among them. Rejected for clones of a default Instance that never runs T3.
- **A separate golden source container per Project.** Needs its own build and refresh. Rejected: the default Instance already deploys every merge, so it is the clone source.
- **A setting per Node to opt in to containers.** Leaves two runtimes for Instances. Rejected: every `app-dev` and `app-prod` Node runs containers.
- **PHP-FPM and a Caddy inside each container.** Two Processes and the shared FPM setup. Rejected for FrankenPHP, one Process.
- **PHP installed in each container.** Every update would touch every container. Rejected for the shared runtime folder.

## Consequences

- Refresh, snapshot, copy and wake become container operations of about a second.
- Tasks and Instances share one runtime, so a task can attach to a running Instance and hand it back, and a user can take over a stuck task in Conn.
- Claude reaches task agents through Claude Code, Anthropic's own application, in the same T3 as Codex.
- UpCloud Nodes can run tasks and Instances.
- The `app-dev` and `app-prod` roles stop installing PHP-FPM and checkout folders once their Nodes have migrated.
- Isolation between tasks, and between Projects, on one Node relies on the kernel. An escape from one container reaches the others.
- Task agents lose `incus exec` into a host; with a nested fleet they keep full control of their own fleet.
- Docker inside nested containers runs without its own AppArmor profile.
- Each running Instance container costs about 400 MB for its T3 server before any agent runs. Development containers stop after an hour idle, and a wake takes about 3 s.
- A new Instance starts at the default Instance's commit and data. A template copy of the default database briefly refuses the default Instance's connections.
- The default Instance cannot be destroyed while it has clones.
- Containers reach neither the Gateway nor GitHub. Orbit moves Git branches in and out from the host.
- The task container host setting, places and bridge that the first plan built are removed in step 9. Its host preparation stays, as the container host part of the `app-dev` role.

## Affects

- Components: apps/gateway, apps/cli, apps/docs
- ADRs: replaces the outer task VM boundary of task groups, the separate task container runtime, and the Project VM of the development container plan
- Detail: [Instance containers](/reference/development-containers) and its plan [Container runtime](/solutions/development-container-runtime), [Conn layer](/reference/conn), [App-dev runtime hibernation](/reference/app-dev-runtime-hibernation), [Compute drivers](/reference/compute-drivers), [Tasks](/reference/tasks), [Pi server](/reference/pi-server), [Node provisioning](/reference/node-provisioning), [Metrics](/reference/metrics), and the plan in [Container instances and releases](/solutions/container-instances-and-releases). The change also touches `swamp/` (the task provision, turn, check, publish and release workflows, the proof fleet setup and the Instance container workflows) and the Node agent (container and Process metrics).
- Verify: Orbit's command proof cases in an Orbit Instance container with the nested fleet match their task VM results on Beast, and on an UpCloud host before it switches; a real task group in a clone starts, turns through T3 with Codex and Claude, checks, publishes and releases; a clone of the default Instance with its database boots in a container on the Node, its T3 server answers a Claude and a Codex turn, and `orbit` reaches neither the agent binaries nor the model route; a checkout Instance migrates with its code and database; kernel, SCTP and secret checks pass on every agent Node
