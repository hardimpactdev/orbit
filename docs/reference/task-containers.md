---
title: "Task containers"
description: "Not built. The task container runtime of ADR 0250: a task group runs in one unprivileged Incus container with its nested fleet inside, on hosts that select it with a Node setting, beside task VMs."
---

# Task containers

**Not built.** This page specifies the runtime that step 3 of [ADR 0250](/decisions/0250-run-instances-and-task-groups-in-incus-containers) builds. It describes the target. The build PRs in [Task container runtime](/solutions/task-container-runtime) make it true, one part at a time, and remove this note. Until then, every task group runs on a [task VM](/reference/compute-drivers#task-vms).

A task container is an unprivileged Incus system container on a container host. It takes the place of a task VM for one task group. It is a copy of the host's base container snapshot. The base holds the same software as the base VM: the agent, the Pi server, the tools, Swamp and a warm checkout of main. It also holds the nested fleet of three containers (Gateway, app-dev, app-prod) under an Incus inside the container. A host runs either task VMs or task containers. Task VM hosts do not change.

The container runtime keeps the task VM's records, kinds, workflows and step names. The Gateway puts `runtime: container` in each intent, and the Ansible phases branch on it. An intent without `runtime` counts as `vm`, so a run admitted before the change keeps working. The charts of the container runtime are the task VM charts with other machine work in their steps:

| Flow | Chart |
| --- | --- |
| Task start, with the workspace and Pi | [container/start.mmd](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/task/container/start.mmd) |
| Base refresh, with the Pi server and the fleet build | [container/image-refresh.mmd](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/task/container/image-refresh.mmd) |
| Proof, with the fleet restore | [container/proof.mmd](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/task/container/proof.mmd) |
| Start after a stop | [container/resume.mmd](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/task/container/resume.mmd) |
| Release | [container/release.mmd](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/task/container/release.mmd) |

`bin/check-flows` reads only `docs/cli/<family>/<name>.mmd`, and each kind keeps its task VM chart there. So these charts sit one folder lower and follow the [chart rules](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/README.md) without the script. When step 7 removes the task VM runtime, they replace the task VM charts of the same kinds.

The workspace has no chart of its own. Its parts reach a task container over WireGuard as they reach a task VM: the warm checkout comes with the base, the task branch with the converge, and the Project setup with the baseline check.

## Select the runtime

The host Node's [`settings.task_vms`](/reference/node-settings#configure-a-task-vm-host) gets one more field:

| Field | Required | Default | Meaning |
| --- | --- | --- | --- |
| `runtime` | No | `vm` | `vm` for task VMs, `container` for task containers. Every place of the host, task and CI, uses it |

A container host needs its own `project`, `network` and `cidr`, so its resources never share a name or a subnet with the VM resources of any host. A new container setting gets the defaults `orbit-task-containers` and `orbittaskc0`. `config/task_vms.php`, the settings data and the agent's egress watch list all take these defaults from the runtime.

The other fields mean the same for both runtimes. `cpus`, `memory` and `disk` size the container's `limits.cpu`, `limits.memory` and root disk. `image_alias` stays unused, because a container host has no base image, only a base snapshot.

### Placement before the switch

Normal placement skips container hosts until the switch. The Gateway setting `task_vms.container_placement` (`ORBIT_TASK_VMS_CONTAINER_PLACEMENT`, default `false`) turns it on. While it is off, only two requests reach a container host: the candidate proof of its own refresh, and an operator `proof:run --host` that names it. So no task group and no CI proof lands on an unproven runtime.

### Switch a host

Orbit refuses a change of `runtime` while the host has a task VM or task container that is not `destroyed`: HTTP 409 `task_vm.runtime_in_use`. A change that keeps the old `project`, `network` or `cidr` fails with `task_vm.invalid_config`, because the old bridge stays on the host.

To switch a host:

1. Set `task_places` and `ci_places` to zero, and wait until its task VMs are destroyed.
2. In one update, set `runtime`, the new `project`, `network` and `cidr`, and the places again. The update withdraws the recorded base, so no start reaches the host yet.
3. Add `<old project>/*` to `operator_owned`, so the stray report skips the old base VM and seed until step 7 removes them.
4. Run `task-vms:refresh-image <host>`. Its candidate proof takes a free CI place.

The Gateway records the runtime on each `task_vms` row and each base build row. A row keeps the runtime it started with, so its release, its sweep and Doctor take the right path after a host switches.

## Prepare a container host

The first phase of every refresh prepares the host, as for a [VM host](/reference/compute-drivers#prepare-an-incus-host). On a container host it sets up these parts:

| Part | On a container host |
| --- | --- |
| Pool | The ZFS pool, as for a VM host. Task containers and the base share it |
| Bridge | `orbittaskc0` with IPv4 NAT, no IPv6 and the egress ACL |
| NIC | `security.port_isolation=true` on the profile's NIC |
| Forwarding | The existing UFW rule `route allow in on orbittask+`, which covers every bridge whose name starts with `orbittask` |
| Egress log | The [egress log](/reference/compute-drivers#log-task-vm-egress) part, run on the bridge that `host.network` names |
| Input rules | DHCP and DNS to the bridge address, under the existing catalog names |
| Project | `orbit-task-containers` with `features.networks=false` |
| Restrictions | `restricted=true`, `restricted.networks.access=orbittaskc0`, `restricted.containers.nesting=allow`, `restricted.containers.interception=allow`, `restricted.containers.privilege=isolated`, `restricted.snapshots=allow` and `limits.virtual-machines=0`. Host-path disks and privileged containers stay forbidden |
| Stock image | The alias `ubuntu-26.04-ct` from `images:ubuntu/26.04/cloud`, the container variant |
| Kernel modules | The modules that containers cannot load themselves, such as `wireguard` and `overlay`, in `/etc/modules-load.d/orbit-task-containers.conf`. The build PR fixes the list |
| Sysctls | The limits that nested systemd, Docker and file watchers need, such as `fs.inotify.max_user_instances`, in `/etc/sysctl.d/60-orbit-task-containers.conf` |

The phase checks root's subordinate ID range in `/etc/subuid` and `/etc/subgid` on the host. It must hold one [isolated range](#the-outer-container) per place and one for the base: `(task_places + ci_places + 1) × 101,000,000` IDs. The default Incus range of 1,000,000,000 IDs fits nine. A range that is too small fails the phase with the numbers it needs, and changes nothing.

The phase also checks that `incus info` reports the kernel feature `idmapped_mounts`. Without it, Incus shifts the owner of every file when it copies an isolated container, so a start would rewrite the whole root disk instead of making a clone.

The phase requires the host hardening of ADR 0250 step 2. It checks that `sctp` cannot load.

### Egress and DNS

A task container has the egress of a task VM:

- Public addresses on every port. The egress ACL drops private, link-local, CGNAT and multicast ranges.
- No traffic to other containers or VMs on the bridge: port isolation.
- Forwarding through the UFW rule `route allow in on orbittask+`.
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
| `boot` | The tunnel unit and the identity conditions [below](#identity). No QEMU config, no `orbit-identity` unit and no network file that matches a VM uplink |
| `fleet` | The [nested fleet](#the-nested-fleet), built once, as on a base VM |
| `check` | The same end check, plus the inner Incus, the directory pool and the [credential rule](#the-nested-fleet) |

### Ready copies

The ready copies are the three nested containers in the base, each with its own `.env`, storage and database, and a `base` snapshot each for proofs. They live in the inner directory pool `topology-seed`, which is a folder of the base's root disk. So one ZFS snapshot of the base holds the whole fleet, and `incus copy` of that snapshot gives each task container its own fleet in under a second. This replaces the seed volume and its clone of a task VM.

The base snapshot is taken with the nested containers stopped, so it holds no half-written database pages.

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
| `boot.autostart` | `false` on the base, `true` on a task container | A host reboot for a kernel patch starts the task containers again, and never the base |
| `limits.cpu`, `limits.memory` | From the host's `cpus` and `memory`, by size | As for a task VM |

Its root disk is a ZFS clone on the host pool, with the size of the place. It has one NIC on the bridge, from the profile, and no other device. The start refuses a copy with any other device, so no host path, and never the host's Incus socket, reaches a task container.

The agent runs as `orbit` with passwordless sudo, as on a task VM. It is root of the container and of its nested fleet. It is not root of the host: its root is an unprivileged ID of its own isolated range. [ADR 0250](/decisions/0250-run-instances-and-task-groups-in-incus-containers#accepted-risk-and-requirements) records the risk of the shared kernel and its requirements.

### Identity

A task container keeps the Node identity of a task VM: the Node `HOST-tvm-N` with its `app-dev` role, a WireGuard address in the reserved range, an SSH host key, the agent and the Pi server. So the hub filter, the Caddy guard, the model router guard, the workspace, Git transfers and every Swamp step reach it as they reach a task VM. [Open question 1](/solutions/task-container-runtime#1-identity-of-a-task-container-in-steps-3-to-5) asks whether this holds until step 6 of ADR 0250, which says that containers are never Nodes.

The start writes the identity into the stopped copy with `incus file push` from standard input, with owner and mode. Incus shifts the owners into the container's range. The secrets go only into the container's own root disk. Incus sets the hostname from the container's name.

The base enables the identity's units: `orbit-wireguard.service`, `orbit-agent.service` and the Pi unit. Each has a `ConditionPathExists=` on its identity file, so it does not start in the base, and starts at the first boot of a copy.

`orbit-wireguard.service` brings up the interface `orbit` with `ip link add`, `wg setconf`, the address, the routes and `ip link set up`. Ubuntu 26.04's AppArmor profile for `wg-quick` crashes `wg-quick` in a container, so the outer container does not use it. The readiness check reads the unit name from the intent, `slot.tunnel_unit`, and the handshake from `wg show orbit` as before.

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
- WireGuard of the nested Nodes comes from the nested Gateway's own Node converge. [Open question 3](/solutions/task-container-runtime#3-wireguard-of-the-nested-nodes) decides how it avoids `wg-quick`'s profile.

`LoadCredential` fails without an error in nested containers. So a unit that the nested fleet runs reads its secret from `SetCredential` or a file, not from `LoadCredential`. The fleet check fails on a unit that breaks this rule. Some shared unit renderers use `LoadCredential` today; [open question 6](/solutions/task-container-runtime#6-loadcredential-in-shared-units) decides how they change.

### How the agent reaches its fleet

The agent reaches its fleet as on a task VM, with one difference in the operator tunnel:

- The task container joins the nested fleet as the `operator` Node with its own WireGuard key, as `10.45.0.10`.
- Its tunnel `wg-nested` runs as `orbit-wg-nested.service`, with `ip` and `wg`, not as `wg-quick@wg-nested`.
- The candidate CLI in the checkout uses the `nested` profile and the nested root CA, and talks to the nested Gateway at `https://10.45.0.1`.
- The agent runs `incus` inside the task container for `exec`, logs and snapshots of its nested containers.
- The Gateway and app-dev get the checkout as the disk device `checkout`, a path inside the task container, so an edit reaches them at once.

### Take and restore `base`

A proof restores each nested container to its `base` snapshot, as on a task VM ([proof chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/task/container/proof.mmd)). On a directory pool a restore copies back the files that changed since `base`, so its time grows with the change. The 45-second limit stays; the build PR measures the restore against it.

Two places take `base`: the task "Take the base snapshots" of the role playbook, in the refresh, and the step "Take base again" of a proof, after a token renewal. On a container, both stop the three nested containers first, then snapshot them and start them again. A snapshot of a running container on a directory pool is a file copy, not a consistent state. The readiness check follows.

## Places and capacity

A place holds one task container, as it holds one task VM. `task_places`, `ci_places`, the two pools, the queue, the two-hour wait and the sizes are those of [Place task VMs](/reference/compute-drivers#place-task-vms). A container place needs no reserved guest memory, so a host may fit more places than as a VM host. The places stay as they are until the parity gate has measured them ([open question 4](/solutions/task-container-runtime#4-places-on-a-container-host)).

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
| Power | Presence from the host's `incus.instances`, running from the container's own agent heartbeat. The agent's `incus.vm-state` reads only VMs, and the agent gets no new command |

`vm_power` reports a container as it reports a VM: `running` while `ready`, `destroyed` once `destroyed`.

## Errors

The container runtime adds one error code and one cause of an existing code.

| Code | HTTP | Cause |
| --- | --- | --- |
| `task_vm.runtime_in_use` | 409 | `runtime` changes while the host has a task VM or task container that is not `destroyed` |
| `task_vm.invalid_config` | 500 | `runtime` is not `vm` or `container`, or a `runtime` change keeps the old `project`, `network` or `cidr` |

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
