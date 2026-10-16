---
title: "ADR 0222: Give task VMs a cloned topology seed"
sidebarTitle: "0222 Topology seed"
description: "In progress. Each host builds one topology seed per base image: a ZFS volume that holds a btrfs Incus pool with a stopped template Node. A task VM that can run a topology boots with a ZFS clone of it, adopts the clone as its nested pool, and copies its Nodes from the template. The task VM image carries Incus, so preparation installs nothing and copies no image."
---

# ADR 0222: Give task VMs a cloned topology seed

A group's topology still runs nested in its task VM, but the VM no longer installs Incus or receives an image. Each host builds one topology seed per base image: a ZFS block volume that holds a btrfs Incus pool with a stopped template Node. A task VM that can run a topology boots with a ZFS clone of the topology seed as a disk. At preparation, the VM adopts the clone as its nested Incus pool and copies each Node from the template. The task VM image carries the Incus packages. The image copy, the image import, the apt install, and the in-VM pool creation go.

## Status

In progress.

Principle: [Lean](/mission#principles). The host already holds the base image and a ZFS pool, so a 67 ms clone replaces a 4.29 GB copy and import, and the code that did the copy is deleted. It also serves [No exceptions and no legacy](/mission#principles): a VM without a topology seed fails, and no old path serves it.

## Context

[ADR 0200](/decisions/0200-run-each-task-group-in-its-own-sandbox-vm) runs a group's declared topology nested in its task VM. [Prepare the topology](/reference/compute-drivers#prepare-the-topology) runs three steps in the VM:

1. `host-setup`: `sudo bin/e2e-topology-host-setup` installs Incus and QEMU with apt and creates a sparse btrfs loop pool of 25 or 45 GiB on the VM's root disk.
2. `images`: `bin/e2e-topology-images ensure --recipe-images=PATH` imports the base image archive that `TaskVmRoleImages` and `role-images-transfer.sh` copied into the VM when it was prepared.
3. `acquire`: `bin/e2e-topology acquire` composes the Nodes from that image.

A spike on beast, with Incus 6.0.5 in the VM, measured both paths:

| Step | Today | Topology seed |
| --- | --- | --- |
| Packages | About 42 s of apt for `incus`, `qemu-system-x86`, `ovmf`, `qemu-system-modules-spice`, `qemu-utils`, `virtiofsd`, `dnsmasq-base`, and `btrfs-progs` | None: the image holds them |
| Image | A 4.29 GB archive: export 2.9 s, push 11.2 s, import 7.2 s, first unpack 12.0 s, so about 33 s before the first boot with a warm cache | `incus storage volume copy`: a ZFS clone in 67 ms, 10.7K used against 3.29G referenced |
| Pool | A btrfs loop file on the VM's root disk | The clone as a disk; `incus admin recover` adopts it in 58 ms |
| Node | Created from the imported image | `incus copy node-template` is a btrfs snapshot in 45 ms. The Node answers in about 25 s, as today |

The spike's device path, `/dev/disk/by-id/scsi-0QEMU_QEMU_HARDDISK_incus_seed`, is the same in every VM, because the serial comes from the device name. This ADR names the device `topology-seed`, so the path ends in `incus_topology-seed`. Recovery does not restore images, because image records live in the VM's database and in `/var/lib/incus/images`. So the template must be a stopped instance, not an image.

**The live bug.** Since #1240 (15 Oct 2026), the Gateway sends `role-images-transfer.sh` to the host on standard input, with `bash -s`. Its first `incus exec` inherits that input and reads the rest of the script. Bash then exits 0 after it created only the target directory. So every topology preparation failed at `images` with `The claimed recipe image manifest is missing or invalid.` The test of the script runs it as a file, so it did not catch this. Groups 1733, 1745, and 1752 were stuck there and are cancelled.

**A second defect.** Before the group's baseline check, which runs the Project setup, three calls in `TaskSubtaskRunner` prepare the topology. When the first subtask of a group declares a topology, the harness runs before `composer install --working-dir=apps/e2e`. Group 1752 failed with a missing `apps/e2e/vendor/autoload.php`.

## Decision

The host builds the topology seed, the VM boots with a clone, and preparation adopts it. Nothing else about nested topologies changes: the VM edge stays the boundary, and park, resume, sizes, slots, and evidence work as today. Topology seed and clone names derive from the base image fingerprint and the VM, so Orbit stores no new field.

### The image carries Incus

The recipe's `base` tool set gets the eight packages above and `nftables`, which the isolation table needs, with exact pins. Ubuntu 26.04 pins Incus 6.0.5-8, the version that the spike ran in the VM. The `base` profile is amd64 only, because Pi is, so the amd64 QEMU packages suffice. The recipe installs without recommended packages, so QEMU's GUI stack stays out.

After the packages, the image build runs `usermod -aG incus-admin orbit`, so `orbit`, who runs `acquire`, can use Incus. `base-image-clean.sh` stops `incus.socket` and `incus.service`, empties `/var/lib/incus`, and fails when it is not empty. Otherwise every VM and Node from the image would share one Incus server certificate and database.

The recipe has one image profile, `base`, and both the task VM and the topology Nodes start from it ([Build the base image](/reference/compute-drivers#build-the-base-image)). A separate profile for the task VM would bring back a second image build per recipe. So the Nodes carry the packages too, unused.

### One topology seed per base image

The image chain of [ADR 0206](/decisions/0206-build-task-vm-images-as-chains-of-small-jobs) gets one phase, `topology-seed`, after `smoke` and before promotion. `smoke` keeps its VM `<builder>-smoke`, which booted the published base, for this phase:

1. The host creates the block volume `orbit-topology-seed-<12 hex of the base fingerprint>-new` in the host's project and pool, 45 GiB, the size of today's largest loop pool. A task VM runs at most four Nodes, and ZFS volumes are thin, so the size costs nothing until Nodes write.
2. The host attaches it to `<builder>-smoke` and pushes the base image archive into that VM, once per base image and host.
3. In the VM, Incus creates the btrfs pool `topology-seed` on the device, imports the archive, creates the stopped VM `node-template` from it with no profiles and its own root disk on `topology-seed`, and deletes the image. The template never boots, so it holds no machine identity.
4. The host deletes `<builder>-smoke` and renames the volume to `orbit-topology-seed-<12 hex>`.

So an existing topology seed is always a finished one. A retry deletes the `-new` volume and starts again, and relaunches `<builder>-smoke` from the published base when it is gone. Like `smoke` and `publish`, the phase runs even when the build already holds its base image. Promotion requires the finished phase, as it requires `smoke`.

Pruning deletes a topology seed in the same loop that deletes its base image, so a base kept for a VM keeps its topology seed. When clones still exist, Incus moves the seed's dataset to its `deleted` path and destroys it with the last clone, so Orbit tracks no references.

### Each VM that can run a topology boots with a clone

`ProvisionTaskVm` gives every VM whose size holds test Nodes (`testNodes > 0`, today `medium` and `large`) a clone. When `tvm-<id>-topology-seed` is missing, it copies the topology seed of the VM's `image_fingerprint` to that name, so a retry copies nothing twice. `IncusHost::launch` adds the volume as disk device `topology-seed` in the `devices` of the launch YAML, beside its `config`. A `normal` VM gets none, and an UpCloud VM is always `normal`.

The claim gate already requires a promoted base for the current recipe, and promotion now requires its topology seed. So no VM is claimed without one.

Destroy deletes the clone after the VM, as it deletes a retained workspace volume. `TaskVmWorkspaceVolumes` becomes `TaskVmVolumes`, because it now owns live clones too. It and the stray report recognize `tvm-<id>-topology-seed` by name, as they recognize `tvm-<id>-workspace`, and treat the `orbit-topology-seed-*` volumes of retained bases as owned.

### Preparation adopts the clone

`prepareTurn` admits the topology before every turn, the first one included. So the three calls before the baseline check go, and preparation runs after the Project setup has installed `apps/e2e`. The unit `orbit-task-topology-<run>` then runs two steps:

- **`host-setup`.** `sudo bin/e2e-topology-host-setup` adopts the clone when the pool `topology-seed` is missing. It posts the pool (btrfs, source `/dev/disk/by-id/scsi-0QEMU_QEMU_HARDDISK_incus_topology-seed`) to the recovery endpoints `/internal/recover/validate` and `/internal/recover/import`, which `incus admin recover` uses. It also installs the isolation table and the UFW rules for the `oe` topology networks. When the device is missing, it fails, and the subtask asks for assistance.
- **`acquire`.** `bin/e2e-topology acquire` copies `node-template` for each Node. Then it sets what it sets at creation today: the NIC, address, memory, CPU, mount, ownership metadata, and `cloud-init.user-data`. It waits for the first boot and sets public DNS, as `RoleImages::settle()` does today.

The clone is the pool, so it needs no size. Every clone carries the topology seed's btrfs UUID. That is safe, because each VM sees one clone. The host's udev registers each topology seed volume as a btrfs device, and the host never mounts it.

### VM sizes

`medium` and `large` keep their CPU, memory, slots, and free page reporting, because the nested Nodes still use the VM's memory. They keep their 40 and 60 GiB root disks: `medium` is also the size of `task_vms.medium_projects` groups without a topology, whose checks use the space, and a thin disk costs nothing. Only `TaskVmResources::$topologyPool` goes.

### What gets deleted

- `apps/gateway/resources/task-vms/role-images-transfer.sh`, `TaskVmRoleImages` and its test, and its call in `PrepareTaskVmRuntimeAction`.
- In `topology.sh` and `TaskVmTopologyUnit`: the `images` step, the manifest and pool size arguments, `TaskVmRoleImages::Directory`, and the `$pool` parameter of `TaskVmTopologyUnit::start`.
- The three calls of `admitTopology` before the baseline check in `TaskSubtaskRunner`.
- `bin/e2e-topology-images`, `apps/e2e/app/Console/Commands/TopologyImages/`, `RecipeRoleImages`, the image resolution of `RoleImages` (its `settle()` moves into acquisition), and the image import, publish, and alias code of the harness `IncusHost`.
- The image selection of Nodes: the catalog's `image` field, `TopologyRecipe::images()`, `TopologyNode::image`, the image inputs of `TopologyConstructionInputs`, and `user.orbit.e2e.role-image`. Node creation names no image.
- The container branch of guest creation: every guest is a VM.
- `e2e.incus.storage_pool` and `ORBIT_E2E_INCUS_STORAGE_POOL`: the pool is always `topology-seed`. `e2e.incus.root_size`, `ORBIT_E2E_INCUS_ROOT_SIZE`, and `preserve_image_size`: a copy keeps the template's root disk.
- In `bin/e2e-topology-host-setup`: the package, idmap, pool, access, AppArmor, and worktree steps, `--pool-size`, the bridge `incusbr0`, the default profile, and the isolation and UFW rules for `incusbr0`. A copy of the template carries its own root disk and gets its NIC from the harness, so it needs no profile and no default bridge. The idmap and AppArmor steps served containers only.
- In `TaskVmCloudInit`: the `incus-admin` boot command and the `groups` key. The image build adds `orbit` to the group instead, because cloud-init adds groups only to a user it creates.
- `TaskVmResources::$topologyPool`.
- The guard for groups that predate recipe images in `TaskVmTopologyAdmission`; the missing device replaces it.
- Bare-host topologies, by Nick's decision: the `release` of a discovery topology in `bin/worktree-remove`. The harness creates Nodes only from `node-template` in the pool `topology-seed`, which only a task VM has.
- The tests of every deleted class and script.

### Security

The VM edge stays the boundary. The topology seed holds only the public base image, which every task VM already boots, and the VM gets no new access to the host. A clone belongs to one VM and goes with it.

### Migration

There is no legacy path. Groups 1733, 1745, and 1752 are cancelled; the approved commit of 1733 is on branch `task-1733`. Create them again after the switch deploys. Their new VMs get clones. A VM provisioned before the switch has no clone, so host setup fails on the missing device.

### Build order

1. **Prepare after the baseline.** Delete the three calls before the baseline check. This fixes the defect of group 1752 on its own.
2. **Switch.** Docs first, then the image changes, the `topology-seed` phase with its promotion check and pruning, the clone at launch and its deletion, the adoption, Node copies in the harness, and every deletion above, in one pull request. The new recipe identity and the claim gate make sure that no VM is claimed without a clone. Then create groups 1733, 1745, and 1752 again.
3. **Close.** Absorb this ADR into the pages below and retire it.

## Rejected alternatives

- **Topology Nodes as siblings on the host.** The Incus API becomes reachable from every task VM, a new host attack surface. A project-restricted certificate on Incus 6.0.5, which beast and shark run, can copy another project's instance, so the hosts would first need Incus 7.5.1. The Nodes also cannot mount the workspace, so every edit would need a copy to them.
- **Copy the image into the VM (today), fixed.** It keeps about 33 s and 4.29 GB per group, the 42 s apt install, and the loop pool.
- **Bake the template into the image.** An image cannot contain itself, so each image would carry the previous recipe's base. It also adds 4 GB to every task VM image.
- **An image, not an instance, as the template.** Recovery restores pools, instances, and volumes, not images.
- **A separate builder for the topology seed.** The smoke VM already boots the published base, and the builder audit already knows its name.

## Consequences

- Preparation runs no apt, copies no image, and creates no pool. A topology starts in about the time its Nodes take to boot.
- Every task VM, and every topology Node, carries Incus and QEMU without using them unless it runs a topology.
- Each host holds one topology seed per retained base image. Each VM that can run a topology holds one clone, which uses only what its Nodes write, up to 45 GiB.
- The adoption uses Incus's internal recovery API. A bump of the Incus pin must check it again.
- The harness runs topologies only in task VMs.

## Affects

- Components: apps/gateway, apps/e2e, apps/docs
- ADRs: [0200](/decisions/0200-run-each-task-group-in-its-own-sandbox-vm): items 4 (image preparation) and 5 (the inner bridge `incusbr0`) of its Orbit Project section; [0206](/decisions/0206-build-task-vm-images-as-chains-of-small-jobs) gets the `topology-seed` phase
- Detail: [Incus topologies](/reference/incus-topologies): "One recipe base image", "Run topologies nested in a task VM", "After a merge", and "Nested in the task VM". [Compute drivers](/reference/compute-drivers#prepare-the-topology): preparation, [Build the base image](/reference/compute-drivers#build-the-base-image), [Destroy a task VM](/reference/compute-drivers#destroy-a-task-vm), and [strays](/reference/compute-drivers#report-strays). The [using-incus-topologies](https://github.com/nckrtl/orbit/blob/main/.agents/skills/using-incus-topologies/SKILL.md) skill
- Verify: In apps/gateway, `php artisan test --compact --filter='TaskVmTopology|TaskSubtaskRunner|RecipeImage|TaskVmSweep|ProvisionTaskVm|BaseImageClean'`. In apps/e2e, the topology tests. On beast:
  - a build creates `orbit-topology-seed-<12 hex>` and never leaves a `-new` volume after success;
  - a medium VM boots with its clone, and its Incus server certificate differs from that of another VM;
  - a group that declares `app-dev` prepares after its baseline, with no apt and no image import;
  - a VM without the device fails at `host-setup`;
  - pruning a topology seed while a VM uses its clone leaves the clone working, and the dataset goes with the last clone;
  - destroy leaves no clone.
