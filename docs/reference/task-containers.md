---
title: "Task containers"
description: "Not built. The task container runtime of ADR 0250: a task group runs in one unprivileged Incus container with its nested fleet inside, on hosts that select it with a Node setting, beside task VMs."
---

# Task containers

**Not built.** This page specifies the runtime that step 3 of [ADR 0250](/decisions/0250-run-instances-and-task-groups-in-incus-containers) builds. It describes the target. The build PRs in [Task container runtime](/solutions/task-container-runtime) make it true, one part at a time, and remove this note. Until then, every task group runs on a [task VM](/reference/compute-drivers#task-vms).

A task container is an unprivileged Incus system container on a container host. It takes the place of a task VM for one task group. It is a copy of the host's base container snapshot. The base holds the same software as the base VM: the agent, the Pi server, the tools, Swamp and a warm checkout of main. It also holds the nested fleet of three containers (Gateway, app-dev, app-prod) under an Incus inside the container. A host runs either task VMs or task containers. Task VM hosts do not change.

The container runtime keeps the task VM's records, kinds, workflows and step names. The Gateway puts `runtime: container` in each intent, and the Ansible phases branch on it. So the charts of the container runtime are the task VM charts with other machine work in their steps:

| Flow | Chart |
| --- | --- |
| Task start | [container/start.mmd](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/task/container/start.mmd) |
| Workspace | [container/workspace.mmd](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/task/container/workspace.mmd) |
| Base refresh, with the Pi server and the fleet build | [container/image-refresh.mmd](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/task/container/image-refresh.mmd) |
| Proof, with the fleet restore | [container/proof.mmd](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/task/container/proof.mmd) |
| Start after a stop | [container/resume.mmd](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/task/container/resume.mmd) |
| Release | [container/release.mmd](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/task/container/release.mmd) |

When the runtime switches (ADR 0250 step 5), these charts replace the task VM charts of the same kinds.

## Select the runtime

The host Node's [`settings.task_vms`](/reference/node-settings#configure-a-task-vm-host) gets one more field:

| Field | Required | Default | Meaning |
| --- | --- | --- | --- |
| `runtime` | No | `vm` | `vm` for task VMs, `container` for task containers. Every place of the host, task and CI, uses it |

A container host has its own defaults for two fields, so its resources never share a name with a VM host's:

| Field | Default on a container host |
| --- | --- |
| `project` | `orbit-task-containers` |
| `network` | `orbittaskc0` |

The other fields mean the same for both runtimes. `cpus`, `memory` and `disk` size the container's `limits.cpu`, `limits.memory` and root disk. `image_alias` stays unused, because a container host has no base image, only a base snapshot.

Orbit refuses a change of `runtime` while the host has a task VM or task container that is not `destroyed`: HTTP 409 `task_vm.runtime_in_use`. To switch a host, set its places to zero, wait until its task VMs are destroyed, change `runtime`, and run `task-vms:refresh-image <host>`. The change withdraws the host's recorded base, because a base of the other runtime cannot serve a start. The old project and bridge stay until [step 7](/decisions/0250-run-instances-and-task-groups-in-incus-containers#build-order) removes the task VM runtime.

The Gateway records the runtime on each `task_vms` row and each base build row. A row keeps the runtime it started with, so its release, its sweep and Doctor take the right path after a host switches.

## Prepare a container host

The first phase of every refresh prepares the host, as for a [VM host](/reference/compute-drivers#prepare-an-incus-host). On a container host it sets up these parts:

| Part | On a container host |
| --- | --- |
| Pool | The ZFS pool, as for a VM host. Task containers and the base share it |
| Bridge | `orbittaskc0` with IPv4 NAT, no IPv6 and the egress ACL |
| NIC | `security.port_isolation=true` on the profile's NIC |
| Forwarding and log | The existing forwarding rule and the [egress log](/reference/compute-drivers#log-task-vm-egress). Both cover every bridge whose name starts with `orbittask` |
| Input rules | DHCP and DNS to the bridge address, under the existing catalog names |
| Project | `orbit-task-containers` with `features.networks=false` |
| Restrictions | `restricted=true`, `restricted.networks.access=orbittaskc0`, `restricted.containers.nesting=allow`, `restricted.containers.interception=allow`, `restricted.containers.privilege=isolated` and `restricted.snapshots=allow`. Host-path disks, privileged containers and VMs stay forbidden |
| Stock image | The alias `ubuntu-26.04-ct` from `images:ubuntu/26.04/cloud`, the container variant |
| Kernel modules | The modules that containers cannot load themselves, such as `wireguard` and `overlay`, in `/etc/modules-load.d/orbit-task-containers.conf`. The build PR fixes the list |
| Sysctls | The limits that nested systemd, Docker and file watchers need, such as `fs.inotify.max_user_instances`, in `/etc/sysctl.d/60-orbit-task-containers.conf` |

The phase checks root's subordinate ID range in `/etc/subuid` and `/etc/subgid` on the host. It must hold one [isolated range](#the-outer-container) per place and one for the base: `(task_places + ci_places + 1) × 101,000,000` IDs. The default Incus range of 1,000,000,000 IDs fits nine. A range that is too small fails the phase with the numbers it needs, and changes nothing.

The phase also checks that `incus info` reports the kernel feature `idmapped_mounts`. Without it, Incus shifts the owner of every file when it copies an isolated container, so a start would rewrite the whole root disk instead of making a clone.

The phase requires the host hardening of ADR 0250 step 2 and checks that `sctp` cannot load.

### Egress and DNS

A task container has the egress of a task VM:

- Public addresses on every port. The egress ACL drops private, link-local, CGNAT and multicast ranges.
- No traffic to other containers or VMs on the bridge: port isolation.
- Forwarding through the existing UFW rule `route allow in on orbittask+`.
- No rule per address: the UFW rule matches the interface. This holds on Beast too, which denies forwarding by default.
- Public DNS from the bridge's dnsmasq on the host. The input rules admit it.
- `.orbit` names from the VPN DNS address, over the container's own WireGuard tunnel, as on a task VM.
- The fleet network over that tunnel. The [hub filter](/reference/compute-drivers#limit-fleet-traffic-on-the-hub) limits it.
- The tunnel itself: UDP to the hub's public endpoint, which the ACL allows.

A container on the default `incusbr0` bridge, as in the spike, gets no DNS from its host when the host's input policy denies by default; the spike used `1.1.1.1` for that reason. Task containers never use `incusbr0`.

## The base container and its snapshot

Each container host keeps one stopped base container, `<host>-base`, in the container project. It never gets an identity. A ZFS snapshot of it is the base of every task start. There is no seed volume: the nested fleet lives inside the base's root disk.

### Build the base

A host without a recorded container base builds one in the refresh, from stock:

1. `incus init` from `ubuntu-26.04-ct` with the [outer container settings](#the-outer-container), then a start. Cloud-init makes the `orbit` account with the key of the Swamp Node and installs the SSH server.
2. The common converge, as on a base VM: `software`, `tools`, `boot`, `checkout`, `fleet` and `check`. The software phase installs the Pi server at its pin. The container branches are below.
3. The `base-snapshot` phase: it stops the three nested containers inside the base, stops the base cleanly, and snapshots it under the run's name.
4. The candidate proof: one task start from the new snapshot in a free CI place of the host, then its release.

The container branches of the converge:

| Phase | On a container |
| --- | --- |
| `software` | The same packages and tools at their pins, and the Incus of the recipe for the inner fleet |
| `tools` | The shape-gate linters, the inner Incus setup below, and the nested network `oe-nested` |
| `boot` | WireGuard as `orbit-wireguard.service`, with `ip` and `wg`. No QEMU config, no `orbit-identity` unit and no network file that matches a VM uplink |
| `fleet` | The [nested fleet](#the-nested-fleet), built once, as on a base VM |
| `check` | The same end check, plus the inner Incus, the directory pool and the absence of `LoadCredential` in the fleet's units |

### Ready copies

The ready copies are the three nested containers in the base, stopped, each with its own `.env`, storage and database, and a `base` snapshot each for proofs. They live in the inner directory pool `topology-seed`, which is a folder of the base's root disk. So one ZFS snapshot of the base holds the whole fleet, and `incus copy` of that snapshot gives each task container its own fleet in under a second. This replaces the seed volume and its clone of a task VM.

The base snapshot is taken with the nested containers stopped, so it holds no half-written database pages. A copy of a running container on a directory pool would not be consistent.

### Refresh

The refresh triggers, the queue per host, `--from-stock`, the prune that keeps `-previous`, the leftover cleanup and the rollback on failure are those of a [VM host](/reference/compute-drivers#refresh-the-base-snapshot). A no-op refresh starts the base, converges it, stops and snapshots it. It has no VM boot, so it is shorter; the build PR measures it.

## The outer container

A task container, and the base it copies, has these settings:

| Key | Value | Why |
| --- | --- | --- |
| `security.nesting` | `true` | The inner Incus and Docker run in it |
| `security.syscalls.intercept.mknod` | `true` | The inner Incus makes device nodes for its containers |
| `security.syscalls.intercept.setxattr` | `true` | The inner Incus and Docker set trusted extended attributes |
| `security.idmap.isolated` | `true` | Each task container gets its own host ID range, so two groups share no IDs |
| `security.idmap.size` | `101000000` | The inner range below must fit inside |
| `boot.autostart` | `true` | A host reboot for a kernel patch starts the containers again |
| `limits.cpu`, `limits.memory` | From the host's `cpus` and `memory`, by size | As for a task VM |

Its root disk is a ZFS clone on the host pool, with the size of the place. It has one NIC on the bridge, from the profile, and no other device. The start refuses a copy with any other device, so no host path, and never the host's Incus socket, reaches a task container.

The agent runs as `orbit` with passwordless sudo, as on a task VM. It is root of the container and of its nested fleet. It is not root of the host: its root is an unprivileged ID of its own isolated range. [ADR 0250](/decisions/0250-run-instances-and-task-groups-in-incus-containers#accepted-risk-and-requirements) records the risk of the shared kernel and its requirements.

### Identity

A task container keeps the Node identity of a task VM: the Node `HOST-tvm-N` with its `app-dev` role, a WireGuard address in the reserved range, an SSH host key, the agent and the Pi server. So the hub filter, the Caddy guard, the model router guard, the workspace, Git transfers and every Swamp step reach it as they reach a task VM. [Open question 1](/solutions/task-container-runtime#open-questions) asks whether this holds until step 6 of ADR 0250, which says that containers are never Nodes.

The start writes the identity into the stopped copy with `incus file push` from standard input, with owner and mode. Incus shifts the owners into the container's range. No secret touches the host's disk. The container needs no boot unit to apply it.

WireGuard runs as `orbit-wireguard.service`: `ip link add`, `wg setconf`, the address, the routes and `ip link set up`. Ubuntu 26.04's AppArmor profile for `wg-quick` crashes it in a container, so the outer container does not use `wg-quick`. The readiness check reads the unit name from the intent, `slot.tunnel_unit`.

### Pi

The Pi server is in the base, at the pin of the release. The start writes the claim's Pi token and models file and the Pi unit, which listens on the container's WireGuard address and `ORBIT_PI_PORT`. Everything else is as in [Run Pi on a task VM](/reference/pi-server#run-pi-on-a-task-vm).

## The nested fleet

The nested fleet runs under an Incus inside the task container:

- `/etc/subuid` and `/etc/subgid` give root `1000000:100000000`. The range fits inside the outer container's 101,000,000 IDs.
- The pool `topology-seed` is a directory pool. [Why it works this way](#why-it-works-this-way) explains why it is not a delegated ZFS dataset.
- The network `oe-nested` is an inner bridge. The task container leads it, as a task VM does.
- Each nested container has `security.nesting=true`, a memory limit and `boot.autostart=true`.
- Each nested container has a read-only disk device. It shows the file `/etc/orbit/apparmor-off`, which holds `N`, at `/sys/module/apparmor/parameters/enabled`.
- Docker then skips its own AppArmor profile, which cannot load two levels below the host. The host's profile for the outer container stays in force.
- WireGuard of the nested Nodes comes from the nested Gateway's own Node converge. [Open question 3](/solutions/task-container-runtime#open-questions) decides how it avoids `wg-quick`'s profile.
- No unit of the fleet uses `LoadCredential`, which fails without an error in nested containers. Units that need secrets use `SetCredential` or a file. The fleet check fails on a unit that breaks this rule.

### How the agent reaches its fleet

As on a task VM:

- The task container joins the nested fleet as the `operator` Node with its own WireGuard key, as `10.45.0.10` on `wg-nested`.
- The candidate CLI in the checkout uses the `nested` profile and the nested root CA, and talks to the nested Gateway at `https://10.45.0.1`.
- The agent runs `incus` inside the task container for `exec`, logs and snapshots of its nested containers.
- The Gateway and app-dev get the checkout as the disk device `checkout`, a path inside the task container, so an edit reaches them at once.

### Take and restore `base`

A proof restores each nested container to its `base` snapshot, as on a task VM ([proof chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/task/container/proof.mmd)). On a directory pool a restore copies back the files that changed since `base`, so its time grows with the change. The 45-second limit stays; the build PR measures the restore against it.

Taking `base` stops the three nested containers first, then snapshots them and starts them again. The readiness check follows. This applies where `base` is taken: the fleet build in the refresh, and the token renewal before a proof.

## Places and capacity

A place holds one task container, as it holds one task VM. `task_places`, `ci_places`, the two pools, the queue, the two-hour wait and the sizes are those of [Place task VMs](/reference/compute-drivers#place-task-vms). A container place needs no reserved guest memory, so a host may fit more places than as a VM host. The places stay as they are until the parity gate has measured them ([open question 4](/solutions/task-container-runtime#open-questions)).

The isolated ID ranges set a second limit: one range per place, plus the base. Host preparation checks it.

## Lifecycle

The lifecycle of a task container follows the task VM's, with these differences.

| Event | Task container |
| --- | --- |
| Start | Copy of the base snapshot, identity pushed in, start ([chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/task/container/start.mmd)) |
| Park | Never. Publication releases the container, as it releases a VM |
| Stopped while `ready` | Started again by the resume run in mode `converge` ([chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/task/container/resume.mmd)) |
| Release | `incus delete --force` and the hub peer ([chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/task/container/release.mmd)) |
| Sweep and Doctor | As for task VMs; a container row has no seed clone and no identity volume to report |
| Power | Presence from the host's `incus.instances`, running from the container's own agent heartbeat. The agent's `incus.vm-state` reads only VMs |

`vm_power` reports a container as it reports a VM: `running` while `ready`, `destroyed` once `destroyed`.

## Errors

The container runtime adds one error code and widens one.

| Code | HTTP | Cause |
| --- | --- | --- |
| `task_vm.runtime_in_use` | 409 | `runtime` changes while the host has a task VM or task container that is not `destroyed` |
| `task_vm.invalid_config` | 500 | `runtime` is not `vm` or `container`, or a container host's `network` or `cidr` collides with another host's |

A failed host preparation, base build or start fails its run with the failed step, as for a VM host.

## Why it works this way

These reasons shape the runtime.

- **One kind, two runtimes.** The runtime changes the machine work, not the flow. So the kinds, the workflows and their step names stay.
- **Ansible branches on the runtime.** The switch then needs no new kind. The task VM path keeps its behavior until step 7 removes it.
- **One runtime per host.** One base per host keeps the refresh, the candidate proof and the places simple. A host switches in a quiet window.
- **The identity stays.** The task workspace is an Instance on a Node. The flows that reach it use the Node's WireGuard address.
- **No early Instance runtime.** With the identity, these flows work unchanged until Instances get a runtime of their own.
- **A directory pool inside.** One outer snapshot carries the fleet, and a copy needs no seed volume.
- **No delegated dataset.** `incus copy` does not copy the child datasets of a dataset delegated to a container. A container whose delegated children were in use also could not be deleted until it stopped.
- **Isolated ID ranges.** A container escape into another group's files needs a kernel bug, not a shared ID.
