---
title: "ADR 0250: Run instances and task groups in Incus containers"
sidebarTitle: "0250 Instances and task groups in containers"
description: "In progress. Every development Instance runs in one Incus container on its Node, and every task group runs in one outer container with its topology as nested containers. A shared kernel is the accepted risk; patching, SCTP off and no secrets on agent hosts are requirements."
---

# ADR 0250: Run instances and task groups in Incus containers

A development Instance runs in one unprivileged Incus container directly on its Node. A task group runs in one unprivileged outer container, and its topology runs as Incus containers nested inside it. There is no task VM, no Project VM and no VM per Node. All containers on a Node share that Node's kernel.

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

An Incus container starts in about a second, clones with ZFS copy-on-write in under a second, and maps to the existing `InstanceRuntime`. The cost is one kernel per Node: a kernel bug reachable from a container can reach the host.

## Decision

Orbit has one runtime kind for agent work: an unprivileged Incus container on the Node. The sections below fix how Instances and task groups use it, which risk is accepted, and in which order the change lands.

### One runtime kind: the container

- **Development Instance.** One container per Instance, owned by its `InstanceRuntime`. A feature is a copy of a prepared source container, with its own database and Route.
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

- **Development containers.** One unprivileged user, `orbit`, without sudo, owns the workspace and runs the agent, the app and its development servers. Root work (packages, services, runtime setup) goes through Orbit from the host. A project's system packages come from its recipe, not from the agent. `user.max_user_namespaces=0` is set inside the container, and the container has no nesting.
- **Task containers.** The agent is root inside the outer container, because it drives the nested topology. This is the accepted risk below.

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
6. **Development Instances** directly on the Node, reworking the development container work to drop the Project VM.
7. **Remove** the task VM runtime.

## Rejected alternatives

- **Keep a VM per task group.** Strongest isolation; too slow and heavy, and impossible on UpCloud.
- **One VM per Node with containers inside.** Protects the host, but adds a second layer of Nodes, moves WireGuard and exporters into a VM, and still cannot run on UpCloud. Rejected for simplicity.
- **Sibling containers per task group instead of nesting.** Less kernel surface, but a group becomes several containers and a bridge that Swamp must snapshot and restore together, and the agent loses control of its fleet. Rejected because nesting proved to work and keeps one unit per group.
- **A VM per Instance (as DevVM does with Lima).** Needs KVM, which UpCloud does not offer, and costs a kernel and reserved memory per Instance.

## Consequences

- Refresh, snapshot, copy and wake become container operations of about a second.
- Task groups and development Instances share one runtime model, so a task can attach to a running Instance and hand it back.
- UpCloud Nodes can run task groups and development Instances.
- Isolation between task groups, and between Projects, on one Node relies on the kernel. An escape from one container reaches the others.
- Task agents lose `incus exec` into a host; they keep full control of their own nested fleet.
- Docker inside nested containers runs without its own AppArmor profile.

## Affects

- Components: apps/gateway, apps/cli, apps/docs
- ADRs: replaces the outer task VM boundary of task groups and the Project VM of the development container plan
- Detail: [Task containers](/reference/task-containers) and its plan [Task container runtime](/solutions/task-container-runtime), [Compute drivers](/reference/compute-drivers), [Tasks](/reference/tasks), [Pi server](/reference/pi-server), [Node provisioning](/reference/node-provisioning), [Metrics](/reference/metrics), and the plan in [Container instances and releases](/solutions/container-instances-and-releases). The change also touches `swamp/` (task VM provision, image, snapshot and power workflows, the proof fleet setup and the development container workflows) and the Node agent (container and Process metrics).
- Verify: the command proof cases on task containers match their task VM results on a container host: Beast first, and an UpCloud host before it switches; a feature copy with its database boots in a container on the Node; kernel, SCTP and secret checks pass on every agent Node
