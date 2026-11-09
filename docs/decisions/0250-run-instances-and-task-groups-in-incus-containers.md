---
title: "ADR 0250: Run instances and task groups in Incus containers"
sidebarTitle: "0250 Instances and task groups in containers"
description: "In progress. Every development Instance runs in one Incus container on its Node with a T3 server inside, and every task group runs in one outer container with its topology as nested containers. A shared kernel is the accepted risk; patching, SCTP off and no secrets on agent hosts are requirements."
---

# ADR 0250: Run instances and task groups in Incus containers

A development Instance runs in one unprivileged Incus container directly on its Node, with a T3 server inside as its agent engine. A task group runs in one unprivileged outer container, and its topology runs as Incus containers nested inside it. There is no task VM, no Project VM and no VM per Node. All containers on a Node share that Node's kernel.

## Status

In progress.

Nick decided the direction on 2026-11-09, after a spike on Beast proved nested Incus with Docker inside an unprivileged container. The build follows [Build order](#build-order) under Decision.

Principle: [Lean](/mission#principles) and [One way, one name](/mission#principles).

This ADR replaces:

- the outer task VM of each task group, and the decision of 2026-11-06 that it stays a VM;
- the Project VM per Project of the development container plan (2026-11-08);
- "the proof VM is the trust boundary" (2026-11-09), which becomes "the proof container is the trust boundary".

The nested-topology lesson of 2026-11-06 still applies: start from seed and ready copies, never from stock images.

## Context

A task VM per group isolates each agent with its own kernel, but it is slow to refresh, heavy to keep, and cannot run on UpCloud, whose servers expose no `/dev/kvm` and no `svm` flag. Development Instances need a cheap copy of a prepared environment, including its database, for every feature. A VM per Instance is too heavy for that, and a Project VM per Project adds a boot to every wake and a second kind of runtime.

An Incus container starts in about a second, clones with ZFS copy-on-write in under a second, and needs one runtime record per Instance. The cost is one kernel per Node: a kernel bug reachable from a container can reach the host.

A second spike on 2026-11-09 ran a stock T3 server inside an unprivileged container on Beast, with Claude Code and Codex turns through the AI router and only a placeholder key inside. A third split the container into two users: the agent and its shell commands as `agent`, the app as `orbit`. Both worked without changes to T3, Claude Code or Codex. [Development containers](/reference/development-containers#evidence) summarizes the evidence.

## Decision

Orbit has one runtime kind for agent work: an unprivileged Incus container on the Node. The sections below fix how Instances and task groups use it, which risk is accepted, and in which order the change lands.

### One runtime kind: the container

- **Development Instance.** One container per Instance, recorded as the Instance's runtime. Every Instance is a copy of the Project's source container on its Node, which never ran T3. A feature copy also gets its own copy of the source Instance's database, and its own Route.
- **Task group.** One outer container per group, owned by a task group runtime. Its topology (Gateway, app-dev, app-prod, database) runs as nested containers under an Incus inside it.
- **Node.** The machine Orbit runs on: a bare-metal host or a VPS. Containers are runtimes of Instances and task groups, never Nodes. They get no WireGuard identity, no roles and no exporter.

**Temporary exception, until step 6.** A task container keeps the Node identity of a task VM: the Node with its `app-dev` role, a WireGuard address, an SSH host key, the agent and the Pi server. The task workspace is an Instance on a Node, and the agent runner, Git transfers, Pi, the hub filter and the model router guard use that Node's WireGuard address. Step 6 gives Instances their own runtime and removes the exception. Decided on 2026-11-09.

### Nested topology

The spike of 2026-11-09 settled how a task group's outer container runs its fleet:

- The outer container is unprivileged with `security.nesting=true`, intercepted `mknod` and `setxattr`, and an isolated ID map. Its Incus project allows low-level keys (`restricted.containers.lowlevel=allow`), because `security.idmap.size` is one, and the start refuses `raw.*` keys.
- The host's pool is ZFS. A copy of the outer container is a clone only with idmapped mounts, which a test copy proves with `zfs get written`.
- The inner Incus gets a subordinate ID range that fits inside the outer one (`/etc/subuid` and `/etc/subgid`, for example `root:1000000:100000000`).
- The inner storage pool is a directory pool inside the outer container's filesystem. An outer snapshot and copy then carry the whole fleet: 0.3 s and 0.5 s in the spike. A ZFS dataset delegated to the outer container is not used, because `incus copy` does not copy its child datasets.
- A nested container that runs Docker gets nesting and a read-only device that presents `N` at `/sys/module/apparmor/parameters/enabled`. Docker then skips its own AppArmor profile, which cannot load two levels below the host. The host's profile for the outer container stays in force.
- Only the outer container needs `ip` and `wg` for its tunnels: Ubuntu 26.04's AppArmor profile makes `wg-quick` segfault there. The nested Nodes keep `wg-quick` from the shared Node converge, which works with the AppArmor-off device above and a cloud-init `bootcmd` that turns off the `wg` and `wg-quick` profiles.
- Units that need secrets use `SetCredential` or files, not `LoadCredential`. In nested containers systemd reports no credential error, but the unit gets no credential and fails.

A base snapshot is taken after the fleet converges, with its nested containers stopped, so the snapshot holds no half-written database pages.

A second spike on 2026-11-09 ran the same setup on an UpCloud server, which has no nested KVM, with the same results. Its findings are in the [UpCloud report](/solutions/task-container-runtime#upcloud-report).

### Users and kernel surface

- **Development containers.** Two unprivileged users, neither with sudo.
  - The app runs as `orbit`: PHP-FPM, queue workers, development servers and every Orbit Process.
  - T3, Claude Code and Codex run as `agent`, and so do their shell commands. There is no wrapper shell and no switch between the users.
  - `orbit` cannot run the agent binaries, read `/home/agent`, see agent processes or reach the model route. A firewall rule by uid inside the container enforces the last.
  - Both users share the workspace through a group with default ACLs. Git's `safe.directory` names the workspace for `orbit` only, because `agent` owns the repository.
  - This is the boundary against an attacker who comes in through a preview URL.
  - Root work (packages, services, runtime setup) goes through Orbit from the host. A project's system packages come from its recipe, not from the agent.
  - `user.max_user_namespaces=0` is set inside the container, and the container has no nesting.
- **Task containers.** The agent is root inside the outer container, because it drives the nested topology. This is the accepted risk below.

### Agent engine in containers

A T3 server runs inside each development container and is its agent engine. It replaces Pi Durable there. Swamp drives it through T3's own `/mcp` endpoint and WebSocket API, so no adapter runs in the container. The Pi server stays on task VMs, and on task containers until they switch engines.

- **Previews.** A preview URL reaches only an Orbit Process, which runs as `orbit`. An agent may start its own development servers as `agent` and view them in T3's browser. Orbit does not publish them, and does not restrict them.
- **Model access.** A container holds only a placeholder key. A forwarder on its Node passes model calls to the model router guard, which swaps in the real key. The real key stays off the Node.
- **Copies.** A container that is copied must never have run T3, or every copy would inherit its server identity and sessions. Every Instance is therefore a copy of a source container that never ran T3. T3 creates its identity on its first start in the copy.

### Shared agent stack

T3, Claude Code, Codex, Node, Bun and the agent environment notes live once per Node, in a versioned read-only folder such as `/opt/orbit-agent/<version>` with a `current` link. Orbit mounts it into every container. An update installs a new version, flips the link and restarts T3 in each running container; T3 continues a turn after a restart. The CLI self-updaters are off. The container image keeps only OS packages, the users and the firewall.

Each Project type has one environment note: a web app with previews, a monorepo, or a package. In the agent's home, `~/.claude/CLAUDE.md` and `~/.codex/AGENTS.md` link to the note of the Instance's Project type. This is a narrow exception to "the runtime injects no prompts": a note states facts about the environment only, never instructions about the work.

### Threads and Instances

Starting a thread in an Orbit Project in Conn makes Swamp create an Instance: a container with its own T3 server. Orbit registers that server, and the user pairs with it through Conn. Access follows the node access edges to the Instance's Node. The thread is a session of that Instance's T3 server.

A task can attach to an Instance. While its flow runs, Swamp drives the turns; while it is paused, or when there is no task, the user drives from the thread. Both use the same sessions. There is no separate runtime object and no separate mode for work that a user drives.

### Accepted risk and requirements

All containers on a Node share its kernel. A kernel privilege-escalation bug reachable from inside a container gives root on the Node: every other container, the Node's databases, and the Node's WireGuard identity with its Gateway access. Such bugs appear several times a year; in 2026 at least one had a public container-to-host exploit before Ubuntu shipped a fix.

Nick accepts this for development and task work. These are requirements, not options:

1. **Patch.** Nodes that run agent containers install kernel updates and reboot into them on a schedule.
2. **SCTP off.** The `sctp` module cannot load on these Nodes. Nothing in Orbit uses it; WebRTC implements its own SCTP over UDP.
3. **No secrets on agent hosts.** A Node that runs agent containers holds nothing beyond what its containers and roles need. Personal agent logins, SSH keys and GitHub tokens stay off it.
4. **Least Gateway access.** Agent Nodes get only the node access edges their roles need.
5. **Placeholder keys.** External API keys reach containers as placeholders that a proxy outside the container replaces, as the model router guard does for task VMs today.

### Other roles

Roles without agents (database, websocket, metrics, router, ingress) keep running on the Node itself. A container reaches a database on its Node through an Incus proxy device for the database socket, or through the Incus bridge with `pg_hba` limited to that bridge.

### Metrics

The Node's agent reads per-container metrics from Incus (`/1.0/metrics`) and per-Process CPU and memory from each container's systemd cgroups, from the host. No exporter runs in a container.

### Build order

1. **This ADR.**
2. **Host hardening** on Beast and Shark: current kernels, SCTP off, an explicit container egress model and bridge DNS, and secrets moved off.
3. **Task container runtime** next to the task VM runtime, added to a host by a Node setting. Nothing switches. Beast is the test host and keeps its task VMs. [Task containers](/reference/task-containers) specifies it, and [Task container runtime](/solutions/task-container-runtime#decided) records the decisions it rests on.
4. **Parity gate.** The command proof cases run on task containers and must match their task VM results.
5. **Switch** Beast, then Shark, in a window with no proofs or merges in flight. Running task VMs finish first.
6. **Development Instances** directly on the Node, with a T3 server inside each. [Development containers](/reference/development-containers) specifies it, and [Development container runtime](/solutions/development-container-runtime) orders the PRs. It reuses parts of the unpushed development container work and drops its Project VM.
7. **Remove** the task VM runtime.

## Rejected alternatives

- **Keep a VM per task group.** Strongest isolation; too slow and heavy, and impossible on UpCloud.
- **One VM per Node with containers inside.** Protects the host, but adds a second layer of Nodes, moves WireGuard and exporters into a VM, and still cannot run on UpCloud. Rejected for simplicity.
- **Sibling containers per task group instead of nesting.** Less kernel surface, but a group becomes several containers and a bridge that Swamp must snapshot and restore together, and the agent loses control of its fleet. Rejected because nesting proved to work and keeps one unit per group.
- **A VM per Instance (as DevVM does with Lima).** Needs KVM, which UpCloud does not offer, and costs a kernel and reserved memory per Instance.
- **One T3 outside the containers, driving a Pi server inside each.** Lighter: a Pi server idles at about 90 MB, a T3 server at about 400 MB. But T3's changed-files view, terminals and browser would need a remote git and shell layer through every upstream sync, and Pi Durable has no MCP client. Rejected for a stock T3 inside each container; idle containers stop, which removes the memory cost.
- **Agent shell commands as `orbit` through a wrapper shell.** Proven for Claude Code, but Codex has no documented setting for its shell, and the switch adds little: a container holds only its Project's `.env`, and its egress goes through Orbit. Rejected for agent commands as `agent`.
- **Copy a container that ran T3 and delete its identity files.** Works, but a missed file gives a copy the source's sessions, the Gateway's admin session among them. Rejected for copies of a source that never ran T3.

## Consequences

- Refresh, snapshot, copy and wake become container operations of about a second.
- Task groups and development Instances share one runtime model, so a task can attach to a running Instance and hand it back.
- UpCloud Nodes can run task groups and development Instances.
- Isolation between task groups, and between Projects, on one Node relies on the kernel. An escape from one container reaches the others.
- Task agents lose `incus exec` into a host; they keep full control of their own nested fleet.
- Docker inside nested containers runs without its own AppArmor profile.
- Each running development container costs about 400 MB for its T3 server before any agent runs. Idle containers stop, and a wake takes about 3 s.
- A copy of an Instance takes committed work only: its files come from the source container, its branch from the source Instance's commit, and its data from a dump of the source database. Uncommitted files stay in the source.
- Containers reach neither the Gateway nor GitHub. Orbit moves Git branches in and out from the host.

## Affects

- Components: apps/gateway, apps/cli, apps/docs
- ADRs: replaces the outer task VM boundary of task groups and the Project VM of the development container plan
- Detail: [Task containers](/reference/task-containers) and its plan [Task container runtime](/solutions/task-container-runtime), [Development containers](/reference/development-containers) and its plan [Development container runtime](/solutions/development-container-runtime), [Conn layer](/reference/conn), [App-dev runtime hibernation](/reference/app-dev-runtime-hibernation), [Compute drivers](/reference/compute-drivers), [Tasks](/reference/tasks), [Pi server](/reference/pi-server), [Node provisioning](/reference/node-provisioning), [Metrics](/reference/metrics), and the plan in [Container instances and releases](/solutions/container-instances-and-releases). The change also touches `swamp/` (task VM provision, image, snapshot and power workflows, the proof fleet setup and the development container workflows) and the Node agent (container and Process metrics).
- Verify: the command proof cases on task containers match their task VM results on a container host: Beast first, and an UpCloud host before it switches; a feature copy with its database boots in a container on the Node, its T3 server answers a Claude and a Codex turn, and `orbit` reaches neither the agent binaries nor the model route; kernel, SCTP and secret checks pass on every agent Node
