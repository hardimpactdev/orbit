---
title: "ADR 0222: Give task VMs a cloned topology seed"
sidebarTitle: "0222 Topology seed"
description: "In progress. Each host builds one topology seed per recipe: a ZFS volume that holds a btrfs Incus pool with a stopped template Node. A task VM that can run a topology gets a ZFS clone of it as a disk, adopts it as its nested pool, and copies its Nodes from the template. The task VM image carries Incus, so preparation installs nothing and copies no image."
---

# ADR 0222: Give task VMs a cloned topology seed

A group's topology still runs nested in its task VM, but the VM no longer installs Incus or receives an image. Each host builds one topology seed per recipe: a ZFS block volume that holds a btrfs Incus pool with a stopped template Node. A task VM that can run a topology gets a ZFS clone of the seed as a disk. At preparation, the VM adopts the clone as its nested Incus pool and copies each Node from the template. The task VM image carries the Incus packages. The image copy, the image import, the apt install, and the in-VM pool creation go.

## Status

In progress.

Principle: [Lean](/mission#principles). The host already holds the base image and a ZFS pool, so a 67 ms clone replaces a 4.29 GB copy and import, and the code that did the copy is deleted. It also serves [No exceptions and no legacy](/mission#principles): a VM without a seed is refused, not served by the old path.

## Context

[ADR 0200](/decisions/0200-run-each-task-group-in-its-own-sandbox-vm) runs a group's declared topology nested in its task VM. [Prepare the topology](/reference/compute-drivers#prepare-the-topology) runs three steps in the VM:

1. `host-setup`: `sudo bin/e2e-topology-host-setup` installs Incus and QEMU with apt, adds a subordinate ID range for root, and creates a sparse btrfs loop pool of 25 or 45 GiB on the VM's root disk.
2. `images`: `bin/e2e-topology-images ensure --recipe-images=PATH` imports the base image archive that `TaskVmRoleImages` and `role-images-transfer.sh` copied into the VM when it was prepared.
3. `acquire`: `bin/e2e-topology acquire` composes the Nodes from that image.

A spike on beast (Incus 6.0.5) measured both paths:

| Step | Today | Seed |
| --- | --- | --- |
| Packages | About 42 s of apt for `incus`, `qemu-system-x86`, `ovmf`, `qemu-system-modules-spice`, `qemu-utils`, `virtiofsd`, `dnsmasq-base`, and `btrfs-progs` | None: the image holds them |
| Image | 4.29 GB archive: export 2.9 s, push 11.2 s, import 7.2 s, first unpack 12.0 s, so about 33 s before the first boot with a warm cache | `incus storage volume copy` of the seed: a ZFS clone in 67 ms, 10.7K used against 3.29G referenced |
| Pool | A btrfs loop file on the VM's root disk | The clone, hot-plugged as disk `seed` in 377 ms; `incus admin recover` adopts it in 58 ms |
| Node | Created from the imported image | `incus copy node-template` is a btrfs snapshot in 45 ms. The Node answers in about 25 s, as today |

The device path `/dev/disk/by-id/scsi-0QEMU_QEMU_HARDDISK_incus_seed` is the same in every VM, because the serial comes from the device name. Recovery does not restore images, because image records live in the VM's database and in `/var/lib/incus/images`. So the template must be a stopped instance, not an image.

**The live bug.** Since #1240 (15 Oct 2026), the Gateway sends `role-images-transfer.sh` to the host on standard input, with `bash -s`. Its first `incus exec` inherits that input and reads the rest of the script. Bash then exits 0 after it created only the target directory. So every topology preparation failed at `images` with `The claimed recipe image manifest is missing or invalid.` Groups 1733, 1745, and 1752 are stuck there. The test of the script runs it as a file, so it did not catch this.

**A second defect.** Orbit prepares the topology before the group's baseline check, and the baseline check runs the Project setup. When the first subtask of a group declares a topology, the harness runs before `composer install --working-dir=apps/e2e`. Group 1752 failed with a missing `apps/e2e/vendor/autoload.php`.

## Decision

The host builds the seed, the provisioning attaches a clone, and the VM adopts it. Nothing else about nested topologies changes: the VM edge stays the boundary, and park, resume, sizes, slots, and evidence work as today. Seed and clone names derive from the recipe and the VM, so Orbit stores no new field.

### The image carries Incus

The recipe's `base` tool set gets the eight packages above and `nftables`, which the isolation table needs, with exact pins. The `base` profile is amd64 only, because Pi is, so the amd64 QEMU packages suffice. The image build writes `root:1000000:1000000000` to `/etc/subuid` and `/etc/subgid`. The recipe has one image profile, `base`, and both the task VM and the topology Nodes start from it ([ADR 0207](/reference/compute-drivers#build-the-base-image)). A separate profile for the task VM would bring back a second image build per recipe. So the Nodes carry the packages too, unused. The recipe installs without recommended packages, so QEMU's GUI stack stays out. The smoke step checks that `incus` runs.

### One seed per recipe per host

The image chain of [ADR 0206](/decisions/0206-build-task-vm-images-as-chains-of-small-jobs) gets one phase, `seed`, after `smoke` and before promotion:

1. The host creates the block volume `orbit-seed-<12 hex of the recipe>` in the host's project and pool, 45 GiB, the size of today's largest loop pool. A task VM runs at most four Nodes, and ZFS volumes are thin, so the size costs nothing until Nodes write.
2. The host launches the builder `tvm-image-<build id>-seed` from the published base, which has Incus, and attaches the volume as `seed`. The sweep and the audit treat it like the other builders of the build.
3. The host pushes the base image archive into the builder, once per recipe and host. In the builder, Incus creates the btrfs pool `seed` on the device, imports the archive, creates the stopped VM `node-template` from it with no profiles and its own root disk on `seed`, and deletes the image. The template never boots, so it holds no machine identity.
4. The host deletes the builder. The volume stays.

The phase is resumable: a retry deletes a half-built volume and builder and starts again. Like `smoke` and `publish`, it runs even when the build already holds its base image. Promotion requires a finished `seed` step, as it requires `smoke`. Pruning deletes a seed only when it deletes that recipe's base image, so a base kept for a VM keeps its seed. When clones of a seed still exist, Incus moves the seed's dataset to its `deleted` path and destroys it with the last clone, so Orbit tracks no references.

### Each VM that can run a topology gets a clone

`ProvisionTaskVm` gives every VM whose size holds test Nodes (`testNodes > 0`, today `medium` and `large`) a clone. It copies the seed of the VM's claimed recipe to `tvm-<id>-seed` when that volume is missing, so a retry copies nothing twice, and launches the VM with the volume as disk device `seed`. A `normal` VM gets none, and an UpCloud VM is always `normal`. Destroy deletes the clone after the VM, as it deletes a retained workspace volume. The sweep and the stray report recognize clones by name, as they recognize `tvm-<id>-workspace`, and treat `orbit-seed-*` volumes of retained builds as owned.

### Preparation adopts the clone

Preparation runs after the group's baseline check and before the implementer's first turn, so the Project setup has installed `apps/e2e`. The unit `orbit-task-topology-<run>` then runs two steps:

- **`host-setup`.** `sudo bin/e2e-topology-host-setup` adopts the clone when the pool `seed` is missing. It posts the pool `seed` (btrfs, source `/dev/disk/by-id/scsi-0QEMU_QEMU_HARDDISK_incus_seed`) to the recovery endpoints `/internal/recover/validate` and `/internal/recover/import`, which `incus admin recover` uses. It also installs the isolation table and the UFW rules, as today.
- **`acquire`.** `bin/e2e-topology acquire` copies `node-template` for each Node. Then it sets what it sets at creation today: the NIC, address, memory, CPU, mount, ownership metadata, and `cloud-init.user-data`. It waits for the first boot and sets public DNS, as `RoleImages::settle()` does today.

The pool needs no size: the clone is the pool. Every clone carries the seed's btrfs UUID. That is safe, because each VM sees one clone. The host's udev also registers each seed volume as a btrfs device, and the host never mounts it.

### A VM without a seed is refused

The VM itself is the fact: when `/dev/disk/by-id/scsi-0QEMU_QEMU_HARDDISK_incus_seed` is missing, host setup fails, and the subtask asks for assistance with `Task VM: this VM has no topology seed. Cancel the group and create it again.` A resolution does not provision a new VM. A group has one VM for its life, and a path to replace it would be new code for three groups.

### VM sizes

`medium` and `large` keep their CPU, memory, slots, and free page reporting, because the nested Nodes still use the VM's memory. They keep their 40 and 60 GiB root disks: `medium` is also the size of `task_vms.medium_projects` groups without a topology, whose checks use the space, and a thin disk costs nothing. Only `TaskVmResources::$topologyPool` goes.

### What gets deleted

- `apps/gateway/resources/task-vms/role-images-transfer.sh`, `TaskVmRoleImages` and its test, and its call in `PrepareTaskVmRuntimeAction`.
- The `images` step of `topology.sh`, its manifest and pool size arguments, and `TaskVmRoleImages::Directory` in `TaskVmTopologyUnit`.
- `bin/e2e-topology-images`, `apps/e2e/app/Console/Commands/TopologyImages/`, `RecipeRoleImages`, the image resolution of `RoleImages` (its `settle()` moves into acquisition), the image import, publish, and alias code of the harness `IncusHost`, the catalog's `image` field, the image inputs of `TopologyConstructionInputs`, and `user.orbit.e2e.role-image`. Node creation names no image.
- The container branch of guest creation: every guest is a VM.
- In `bin/e2e-topology-host-setup`: the package, idmap, pool, access, AppArmor, and worktree steps, `--pool-size`, the bridge `incusbr0`, the default profile, and the isolation and UFW rules for `incusbr0`. A copy of the template carries its own root disk and gets its NIC from the harness, so no profile or default bridge is needed. The script keeps the adoption, and the isolation table and UFW rules for the `oe` topology networks.
- The `groupadd incus-admin` boot command in the task VM cloud-init: the Incus package creates the group.
- `TaskVmResources::$topologyPool`.
- The guard for groups that predate recipe images in `TaskVmTopologyAdmission`; the missing seed device replaces it.
- Bare-host topologies. The harness creates Nodes only from `node-template` in the pool `seed`, which only a task VM has.
- The tests of every deleted class and script.

### Security

The VM edge stays the boundary. The seed clone holds only the public base image, which every task VM already boots, and the VM gets no new access to the host. A clone belongs to one VM and goes with it.

### Migration

There is no legacy path. A VM provisioned before build step 2 has no clone, so preparation refuses it as described above. Groups 1733, 1745, and 1752 have such VMs. After the switch deploys, cancel each group and create it again with the same subtasks. Their new VMs get clones. Their old VMs, with their nested Incus and loop pools, go with the cancel. Before cancelling 1733, keep the approved work of its subtask 1734.

### Build order

No topology group runs until step 3: the transfer bug stays until the switch deletes the transfer.

1. **Prepare after the baseline.** Move all three calls that admit the topology before the baseline check in `TaskSubtaskRunner`. This fixes the defect of group 1752 on its own.
2. **Image, seed, and clone.** Add the packages and the root ID range to `base`, the `seed` phase with its promotion check, pruning, and stray rules, the clone at provisioning, and its deletion. The next build of every host has a seed, and every VM claimed after this step has a clone. Preparation still uses the old path.
3. **Switch.** Docs first, then the adoption in host setup, Node copies in the harness, and every deletion above. Then cancel and recreate the groups whose VMs have no clone, among them 1733, 1745, and 1752.
4. **Close.** Absorb this ADR into the pages below and retire it.

## Rejected alternatives

- **Topology Nodes as siblings on the host.** The Incus API becomes reachable from every task VM, a new host attack surface. A project-restricted certificate on Incus 6.0.5, which beast and shark run, can copy another project's instance, so the hosts would first need Incus 7.5.1. The Nodes also cannot mount the workspace, so every edit would need a copy to them.
- **Copy the image into the VM (today), fixed.** It keeps about 33 s and 4.29 GB per group, the 42 s apt install, and the loop pool.
- **Bake the template into the image.** An image cannot contain itself, so each image would carry the previous recipe's base. It also adds 4 GB to every task VM image.
- **An image, not an instance, as the template.** Recovery restores pools, instances, and volumes, not images.

## Consequences

- Preparation runs no apt, copies no image, and creates no pool. A topology starts in about the time its Nodes take to boot.
- Every task VM, and every topology Node, carries Incus and QEMU without using them unless it runs a topology.
- Each host holds one seed per retained recipe. Each VM that can run a topology holds one clone, which uses only what its Nodes write, up to 45 GiB.
- Groups whose VM predates the switch must be cancelled and created again to run a topology.
- The harness runs topologies only in task VMs.

## Affects

- Components: apps/gateway, apps/e2e, apps/docs
- ADRs: [0200](/decisions/0200-run-each-task-group-in-its-own-sandbox-vm) item 4 of its Orbit Project section, image preparation; [0206](/decisions/0206-build-task-vm-images-as-chains-of-small-jobs) gets the `seed` phase
- Detail: [Incus topologies](/reference/incus-topologies): "One recipe base image", "Run topologies nested in a task VM", and "Nested in the task VM". [Compute drivers](/reference/compute-drivers#prepare-the-topology): preparation, [Build the base image](/reference/compute-drivers#build-the-base-image), [VM sizes](/reference/compute-drivers#size-by-declared-topology), [Destroy a task VM](/reference/compute-drivers#destroy-a-task-vm), and [strays](/reference/compute-drivers#report-strays). The [using-incus-topologies](https://github.com/nckrtl/orbit/blob/main/.agents/skills/using-incus-topologies/SKILL.md) skill
- Verify: In apps/gateway, `php artisan test --compact --filter='TaskVmTopology|TaskVmSize|RecipeImage|TaskVmSweep|ProvisionTaskVm'`. In apps/e2e, the topology tests. On beast: a build creates a seed; a medium VM boots with its clone; a group that declares `app-dev` prepares after its baseline with no apt and no image import; a VM from before the switch is refused with its reason; and destroy leaves no clone.
