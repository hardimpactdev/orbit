---
title: "ADR 0222: Run topology Nodes as siblings on the task VM host"
sidebarTitle: "0222 Sibling topologies"
description: "In progress. A task group's topology Nodes run on the task VM's host, next to the task VM, in a restricted Incus project of the group with its own isolated bridge. Each Node is a ZFS clone of the promoted base image. The task VM drives them with a client certificate limited to that project. Nested KVM, the in-VM Incus, its pool, and the image copy go."
---

# ADR 0222: Run topology Nodes as siblings on the task VM host

A task group's topology no longer runs nested in its task VM. Its Nodes run on the host's Incus, next to the task VM, in a restricted Incus project of the group with its own isolated bridge. Each Node is a ZFS clone of the promoted base image that the host already has. The task VM drives the Nodes with an Incus client certificate that can reach only that project. Nested KVM, the Incus and QEMU install in the VM, the btrfs pool in the VM, the image copy into the VM, and the image import all go.

## Status

In progress.

Principle: [Lean](/mission#principles). The host already has Incus, a ZFS pool, and the base image, so the nested copy of each is deleted. It also serves [No exceptions and no legacy](/mission#principles): stuck groups move to the new path, and no nested path stays. [Security fits the real threat model](/mission#principles): the boundary becomes the VM edge plus one project-scoped Incus API, and this ADR states what that API exposes.

## Context

[ADR 0200](/decisions/0200-run-each-task-group-in-its-own-sandbox-vm) runs a group's declared topology nested in its task VM. [Prepare the topology](/reference/compute-drivers#prepare-the-topology) runs three steps in the VM:

1. `host-setup`: `sudo bin/e2e-topology-host-setup` installs `incus`, QEMU, OVMF, `virtiofsd`, `btrfs-progs`, and `nftables` with apt, creates a sparse btrfs loop pool, and installs an nftables isolation table.
2. `images`: `bin/e2e-topology-images ensure --recipe-images=PATH` imports the base image archive that `TaskVmRoleImages` and `role-images-transfer.sh` copied into the VM when it was prepared.
3. `acquire`: `bin/e2e-topology acquire` composes the Nodes on the nested Incus.

What this costs:

- **Packages.** On 16 Oct 2026, apt in groups 1733 and 1752 also installed QEMU's GTK, GStreamer, and VTE stack and rebuilt the initramfs before host setup finished. `topology.sh` records no time for each step, so the install time is not measured.
- **Pool.** The btrfs pool takes 25 GiB of a medium VM's 40 GiB disk and 45 GiB of a large VM's 60 GiB disk.
- **Archive.** The base image on beast (`034f99d859c7`) is 4.0 GiB. In the verification of #1240, its export and push took 21.8 seconds and its import 11.2 seconds, for each group.
- **Speed.** In the spike of ADR 0200, an acquire took about 157 seconds nested and 58 seconds on the bare host. The harness's readiness windows are 900 and 600 seconds because nested guests boot about three times slower.
- **Sizes.** The `large` VM size (6 vCPU, 12 GiB, 60 GiB) exists only for a nested topology of two test Nodes. Free page reporting on medium and large VMs, and `restricted.virtual-machines.lowlevel=allow` on the `orbit-tasks` project for it, exist only to return memory that nested guests used.

**The live bug.** Since #1240 (15 Oct 2026), the Gateway sends `role-images-transfer.sh` to the host on standard input, with `bash -s`. Its first `incus exec` inherits that input and reads the rest of the script. Bash then reaches the end of its input and exits 0 after it created only the target directory. So every task VM became `ready` with an empty `/var/lib/orbit/task-vm-role-images`, and every topology preparation failed at `images` with `The claimed recipe image manifest is missing or invalid.` Groups 1733, 1745, and 1752 are stuck there. `incus-host.sh` already prevents this: its `main` function reads the whole script and closes standard input first. The test of the transfer script runs it as a file, so it did not catch this.

**A second defect.** Orbit prepares the topology before the subtask's baseline check, and the baseline check runs the Project setup. When the first subtask of a group declares a topology, the harness runs before `composer install --working-dir=apps/e2e`. Group 1752 failed with a missing `apps/e2e/vendor/autoload.php`.

ADR 0200 rejected Nodes beside the task VM, because each group would need its own host network and rules, and the host would hold four or five VMs for each group. One bridge per group with a shared ACL and two prefix rules is less than the nested stack above, and a ZFS clone of an image the host already has costs no copy.

## Decision

Orbit runs a group's topology Nodes on the task VM's host, in an Incus project of the group, on a bridge of the group. Orbit owns these host resources from the group's first preparation to its end. The task VM only drives the Nodes through the Incus API of that project.

### Nodes run in a restricted project of the group

When a group prepares its first topology, Orbit creates these on the task VM's host, as root over SSH, like `task-vms:prepare-host`:

| Resource | Name | Settings |
| --- | --- | --- |
| Bridge, in project `default` | `orbittopo<group id>` | `10.232.<n>.1/24`, where `n` is the offset of the task VM's WireGuard address in `task_vms.wireguard_range`; IPv4 NAT; no IPv6; no DNS (`raw.dnsmasq=port=0`), as today; ACL `orbittopo-egress`; default ingress and egress actions `reject` |
| Project | `orbit-topology-<group id>` | `restricted=true`; `restricted.networks.access=orbittopo<group id>`; `restricted.images.servers=orbit.invalid`; the limits below; `features.networks=false` |
| Default profile of the project | `default` | Root disk on the host's `pool`, with the size of the base image's root volume (20 GiB today); `eth0` on the bridge, without port isolation |
| Image | alias `orbit-e2e-base` in the project | The VM's claimed `image_fingerprint`, copied from the host's project. Incus reuses the image volume that the pool already holds, so each Node is a ZFS clone |
| Client certificate | `orbit-topology-<group id>` | Restricted to the project. See [The task VM drives its project](#the-task-vm-drives-its-project) |

The task VM's WireGuard address is unique in the fleet, so `n` is unique and needs no allocator. `TaskVmSettings` requires the range to hold at most 256 addresses, which the default `10.44.0.128/25` does.

`restricted=true` keeps every Incus default restriction: disks only from a storage pool, so no host paths; no proxy, Unix, USB, GPU, or PCI devices; no privileged or nested containers; no low-level VM configuration; no snapshots or backups. `restricted.images.servers` names a host that matches no server, so Incus refuses every remote image download and URL import in the project.

The limits come from the group's union of declared Nodes plus the test Gateway and the operator, using the [Node catalog](/reference/incus-topologies#declared-nodes):

| Limit | Value |
| --- | --- |
| `limits.instances` | The number of Nodes |
| `limits.containers` | `0` |
| `limits.cpu` | The number of Nodes, at 1 vCPU each (`e2e.incus.cpu`) |
| `limits.memory` | The sum of the catalog memory of the Nodes |
| `limits.disk` | The profile's root size for each Node |

When another subtask grows the union, Orbit raises the limits before the next preparation. Only Orbit sets project configuration: a restricted certificate cannot change limits or restrictions.

### Isolation

`task-vms:prepare-host` creates the ACL `orbittopo-egress` once. Every `orbittopo` bridge gets it at creation. Like `orbittask`, the `orbittopo` prefix is reserved: only Orbit creates these bridges, always with the ACL. The ACL has the rules of the task bridge's ACL: drop egress to `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`, `169.254.0.0/16`, `100.64.0.0/10`, `224.0.0.0/4`, and `240.0.0.0/4`, allow other egress, and reject all ingress. Incus bridge ACLs do not filter traffic between instances on the same bridge, and Incus allows DHCP to the bridge before the ACL. Two host rules let the bridges work under UFW, whose default denies incoming and routed traffic: `ufw allow in on orbittopo+ to any port 67 proto udp`, and `ufw route allow in on orbittopo+`.

| From | To | Result |
| --- | --- | --- |
| A Node | Another Node of the same topology | Allowed: same bridge |
| A Node | Public addresses | Allowed, through NAT. The harness sets public DNS servers, as today |
| A Node | The host | DHCP only |
| A Node | The fleet's WireGuard network, the host's LANs, the task bridge, other topology bridges, and every other private range | Dropped |
| Anything outside the bridge | A Node | Rejected |
| The task VM | A Node | No network path: the task bridge ACL drops `10.0.0.0/8`. Only through the Incus API of its project |
| The task VM | The host | TCP 8443 on the task bridge address, new; nothing else changes |

This replaces the `orbit-e2e-isolation` table and the harness's per-network forward rules. They kept topology guests away from the WireGuard interface and subnet, and from other `oe` networks. The ACL drops all of these, and more.

### The task VM drives its project

`task-vms:prepare-host` sets `core.https_address` to the task bridge address on port 8443. Incus applies every `drop` rule of an ACL before any `allow` rule, so an allow rule cannot open the bridge address. Instead, the drop rule of the task bridge ACL lists `10.0.0.0/8` without the bridge address, as the CIDRs that cover the rest. UFW then allows only `ufw allow in on orbittask+ to <bridge address> port 8443 proto tcp`. It refuses a host whose Incus is older than 7.5.1, or whose `authorization.client.tls-restricted` is set to a value other than `tls`; see [Security](#security).

At the first preparation, the VM creates its key with `incus remote generate-certificate` as `orbit`. The key never leaves the VM. Orbit reads the certificate over SSH and trusts it on the host as `orbit-topology-<group id>`, restricted to that project. Orbit writes the remote `host` into the VM's Incus client configuration as the default remote, with the host's server certificate pinned and the project preselected. The recipe adds `incus-client` to the `base` tool set, so the VM has the client and no daemon. Only a VM whose group prepared a topology holds a certificate.

### The harness drives a remote

`bin/e2e-topology` reads the remote and project from the Incus client configuration that Orbit writes, and the storage pool and network from the project's default profile. So the preparation unit and an agent's own shell drive the same project. The harness has no other Incus settings, refuses to start without that configuration, and never uses the local socket.

- **Composition.** The harness launches each Node from `orbit-e2e-base` in the project, on the given network, with its catalog address. It creates no network, no firewall rule, and no image, and it takes no host lock and counts no VM budget: the project limits are the budget.
- **Source.** Incus cannot attach a path of the task VM to a Node on the host, and the project allows no host path. So `acquire` and `sync` copy the worktree into each checkout Node with `rsync` over `incus exec`, which the base image has on both sides. Guest-owned paths, such as `node_modules` and `.e2e`, are excluded. The source marker records the copied tree. An edit reaches the Nodes with `sync --quick`.
- **Web session.** `web` runs the dev server in the operator. It no longer publishes it on host loopback, because the project allows no proxy device. Agents drive the page from the operator, as they do today.
- **Release.** `release` deletes the Nodes. The bridge, image, and certificate stay with the group.

### Lifecycle

- **Claim.** Orbit reserves the slots of the declared union on the chosen host. The row's `topology` holds this reservation. Orbit creates nothing on the host for the topology.
- **Preparation.** It runs after the group's baseline check, before the implementer's first turn, so the Project setup has installed `apps/e2e`. The host script `topology-host.sh ensure` creates or updates the bridge, the project and its limits, the image alias, and the certificate. Then the unit `orbit-task-topology-<run>` in the VM runs only `bin/e2e-topology acquire TASK-<group> <checkout> --nodes=<union> --replace`.
- **Reuse.** A ready topology serves every next subtask whose declaration it holds. When another subtask grows the union, Orbit grows `topology` and clears the run in one write, so the next preparation acquires the larger union.
- **VM restart.** Nothing happens. The Nodes run on the host and keep running, so the boot ID check of a ready run goes.
- **Park.** `topology-host.sh empty` deletes the project's instances, and the row forgets the run but keeps `topology`. The slots stay, as the VM's slots do.
- **Resume.** Nothing happens for the topology. The next subtask that declares one prepares it again, as today.
- **Group end.** Before the VM is deleted, `topology-host.sh delete` removes the certificate, deletes the project with `--force`, which removes its instances, image alias, and profiles, and deletes the bridge.
- **Sweep.** It deletes an `orbit-topology-<group>` project, certificate, and `orbittopo<group>` bridge when the host has no row of that group that holds slots, that is, one neither `destroyed` nor `failed`. So the Nodes of a failed VM go too. The stray report counts the instances in the project of a group whose row holds slots as owned.
- **Doctor.** No new code. Until the sweep runs, the instances of such a project show as `node.task_vm_stray_instance`, as other strays do.

`topology-host.sh` reads its whole body before any command runs, as `incus-host.sh` does, so no command can read the rest of the script from standard input.

### Capacity and placement

The Nodes run on the task VM's host and count against its `max_vms`. A group takes the slots of its VM size plus one slot for each declared test Node. The test Gateway and the operator ride with the first. One function gives these slots from the size and the topology, and placement, capacity, and growth all use it. For a normal Project, this gives the same totals as today: 2 slots for one test Node, 3 for two. An Orbit group, which gets a medium VM, takes 3 slots for one test Node and 4 for two, one more than today, because its 8 GiB VM no longer holds the Nodes.

The row's `topology` holds the union reserved at the claim, and capacity counts it. When another subtask grows the union, preparation needs the extra slots free on the host. Otherwise the subtask waits with `Task VM: no capacity.` This replaces `Task VM: this subtask's topology needs a larger task VM than the group got, and Orbit does not resize it.` Only a group without a topology can go to UpCloud, as today.

A group may declare every declarable Node of the catalog. `TaskTopology::maxNodes()` becomes the number of declarable Nodes, not the test Nodes of the largest VM size.

### VM sizes

The `large` size goes. `medium` stays only for `task_vms.medium_projects`: 4 vCPU and 8 GiB for the Orbit check, and the host's `disk`, because 25 GiB of its 40 GiB disk was the nested pool. `TaskVmResources` loses `testNodes`, `topologyPool`, and `qemuConfig`, and VMs lose free page reporting. Once no VM carries `raw.qemu.conf`, `task-vms:prepare-host` sets `restricted.virtual-machines.lowlevel=block` on `orbit-tasks`.

### What gets deleted

- `bin/e2e-topology-host-setup` and `apps/e2e/tests/Unit/E2E/HostSetupScriptTest.php`.
- `bin/e2e-topology-images`, `apps/e2e/app/Console/Commands/TopologyImages/`, `RecipeRoleImages`, `RoleImages`, and the image import, publish, and alias code of the harness `IncusHost`.
- `apps/gateway/resources/task-vms/role-images-transfer.sh`, `TaskVmRoleImages` and its test, and its call in `PrepareTaskVmRuntimeAction`.
- In `topology.sh` and `TaskVmTopologyUnit`: the `host-setup` and `images` steps, the acquire retry for nested readiness, the pool size, the VPN subnet, and the manifest arguments, and the boot ID check of `status`. `TaskVmTopologyRunState::Stale`, the second status read of a ready run, and the guard for groups that predate recipe images in `TaskVmTopologyAdmission`.
- `TaskVmSize::Large`, `TaskVmSize::forTopology()`, `TaskVmSize::holds()`, `TaskVmResources::$testNodes`, `$topologyPool`, `$qemuConfig`, the free page reporting configuration, `raw.qemu.conf` in `IncusHost::launch()`, and `TaskVmTopologyAdmission::TooSmall`, and the `large` wording of `TaskVmPlacementPolicy::noCapacityFor()`.
- `incus-admin` in the task VM cloud-init.
- `IncusNetworkLifecycle`, `OrphanNetworkSweep`, `apps/e2e/resources/host/reconcile-firewall.py`, `HostCapacity`, the `topology-create` host lock, and the `e2e.incus.max_vms` budget.
- The `orbit-source` virtiofs mount and its mount token, the container branch of guest creation, and the web session's proxy device.
- `e2e.incus.remote`, `e2e.incus.project`, `e2e.incus.storage_pool`, `e2e.incus.root_size`, and their `ORBIT_E2E_INCUS_*` variables. A topology runs only in a group project.
- The tests of every deleted class and script.
- On beast and shark, after the switch: the unused `orbit-e2e` storage pools, any `oe-` networks, and the firewall rules for DNS and DHCP that bare-host topologies needed. Task bridges then need their own DHCP rule, as `orbittopo` bridges get.
- The concepts "nested topology", "role image transfer", "topology pool", and "test Nodes a VM size holds", in [Incus topologies](/reference/incus-topologies) and [Compute drivers](/reference/compute-drivers).

### Security

A compromised task VM holds root in the VM, its certificate, and its key. With them it can:

- create, start, stop, delete, and run commands in instances of its project, within the limits;
- import and delete images of its project, within the disk limit;
- change the profiles of its project, within the restrictions;
- read server information, the storage pools, the list of trusted certificates, and the networks, profiles, images, and volumes of project `default`. This shows the names and subnets of other groups' bridges and the fingerprints of their certificates, and no secret.

It cannot:

- read or change another project: other task VMs, CI runners, and their images;
- change the server configuration, a project's limits or restrictions, a network, an ACL, a certificate, or a storage pool. The TLS authorizer allows a restricted certificate only to view objects of project `default`, and a project with `features.networks=false` keeps its networks and ACLs there;
- use a host path, a proxy, Unix, USB, GPU, or PCI device, a privileged or nested container, low-level VM configuration, a snapshot, or a backup;
- attach a NIC to any network but its bridge;
- make the host fetch a URL: `restricted.images.servers` refuses remote images, and Incus refuses pull migrations in a restricted project.

These guarantees need Incus 7.5.1 or newer. Incus 6.0.5-8 on beast and shark lets a restricted certificate copy an instance from another project (fixed upstream on 22 Jun 2026), publish another project's instance (29 Aug 2026), and make the host download images from any server or pull a migration from any address. A copy of another group's task VM holds its checkout, its Pi token, its model key, and its WireGuard key. So `task-vms:prepare-host` refuses an older Incus, and Orbit issues no certificate on such a host.

The new exposure is the Incus API itself: every task VM can reach it on the task bridge, and a flaw in Incus project confinement is an escape. Upstream fixed several such flaws in 2026, so the hosts must take Incus security releases. `authorization.client.tls-restricted` must stay unset or `tls`: with another value, Incus no longer applies a certificate's project list.

### Migration

Groups 1733, 1745, and 1752 wait for assistance on the old path. After the switch deploys, the operator posts a resolution on each, and Orbit prepares their topologies on the new path. Their nested Incus, pool, and isolation table stay unused in their VMs and go when the VMs are destroyed. Host setup already installed `incus` in all three VMs, so they have a client. A migration rewrites the `size` of every `large` row to `medium`; no live VM is `large` today.

### Build order

1. **Prepare after the baseline.** Move topology preparation after the baseline check. It fixes the defect of group 1752 on its own.
2. **Incus upgrade.** Move beast and shark from Ubuntu's Incus 6.0.5 to 7.5.1 or newer, one host at a time, after a ZFS snapshot of the Incus state and with the host drained of new claims. The database upgrade is one-way, so the snapshot is the rollback. Topology groups stay blocked until step 4.
3. **Hosts and image.** Add `incus-client` to the recipe's `base` set. `task-vms:prepare-host` adds the API listener, the task ACL change and the 8443 rule, the `orbittopo-egress` ACL, the two `orbittopo+` rules, and the version check. Nothing uses them yet.
4. **Switch.** Docs first, then `topology-host.sh`, certificates, limits, slots, park, group-end cleanup, and the sweep in the Gateway; the harness's remote-only mode with the copied source; and every deletion above. Then resolve groups 1733, 1745, and 1752.
5. **Close.** Set `restricted.virtual-machines.lowlevel=block` on `orbit-tasks`. Absorb this ADR into the pages below and retire it.

## Rejected alternatives

- **Fix the copy.** Reading the whole script first fixes the bug in one line. It keeps nested KVM, the apt install with QEMU's GUI stack, a 25 to 45 GiB pool in each VM, a 4 GiB copy and import for each group, nested boots about three times slower, and VM sizes for nested memory.
- **Bake Incus and the template into the task VM image.** It removes the copy and the install, and keeps nested KVM, the in-VM pool, and the sizes. The base image would grow by at least the 4 GiB template, and an image cannot hold itself, so each image would carry the previous recipe's base.
- **Mount a ZFS clone of the image into the VM.** It removes the copy only. The nested Incus would still need to import or unpack the image, and nested KVM, the install, and the pool stay.
- **Nodes in the `orbit-tasks` project.** A certificate for that project reaches every task VM.
- **The Gateway runs every Node command for the VM.** The agent then needs a new Gateway API for exec, spawn, and logs. Incus already offers exactly that API, scoped to one project.
- **A live mount through a shared custom volume.** A custom volume belongs to one project, and the workspace lives on the VM's root disk, so the VM and the Nodes cannot share it.

## Consequences

- A topology starts from instant ZFS clones at bare-host speed. Preparation runs no apt, creates no pool, and copies no image.
- A task VM with a topology needs no extra size. The `large` size, free page reporting, and low-level VM configuration on `orbit-tasks` go.
- A VM restart no longer loses its topology.
- The Incus API on the task bridge is new attack surface. The hosts need Incus 7.5.1 or newer and its security releases.
- An edit reaches the Nodes only after `sync --quick`. The live mount is gone. Screenshots in the operator come back with `incus file pull`.
- A human cannot open a topology's web session on host loopback any more.
- Topologies run only in task groups. The harness has no bare-host mode.
- Each topology group holds one bridge, one project, and one certificate on its host until it ends. The sweep removes leftovers.

## Affects

- Components: apps/gateway, apps/e2e, apps/docs
- ADRs: [0200](/decisions/0200-run-each-task-group-in-its-own-sandbox-vm): this changes items 1 to 5 of its Orbit Project section and reverses its rejection of topology VMs on the host
- Detail: [Incus topologies](/reference/incus-topologies): its sections on nested topologies, the recipe base image, capacity, locks, orphan networks, the discovery mount, the web session, and its reasons "Nested in the task VM", "A live mount", and "A quick sync keeps the verified binding". The [using-incus-topologies](https://github.com/nckrtl/orbit/blob/main/.agents/skills/using-incus-topologies/SKILL.md) skill, which relies on the live mount and the host-loopback web session. [Compute drivers](/reference/compute-drivers#prepare-the-topology): preparation, [VM sizes](/reference/compute-drivers#size-by-declared-topology), [park and resume](/reference/compute-drivers#park-and-resume), [Prepare an Incus host](/reference/compute-drivers#prepare-an-incus-host), and [strays](/reference/compute-drivers#report-strays)
- Verify: In apps/gateway, `php artisan test --compact --filter='TaskVmTopology|TaskVmSize|TaskVmCapacity|IncusHostScript|TaskVmSweep'`. In apps/e2e, the topology tests. On beast: a group that declares `app-dev` prepares its topology after its baseline check, in `orbit-topology-<group>`; its Nodes cannot reach the fleet, the LAN, or another topology; its certificate cannot list or copy an instance of `orbit-tasks`; park empties the project; group end leaves no project, bridge, or certificate; and the time of `acquire` and of `sync --quick`, whose copy includes the `vendor` trees, is recorded.
