---
title: "Compute drivers"
description: "Task VMs for web Projects, the UpCloud driver, and the Incus sandboxes of the Orbit lane."
covers:
  - apps/gateway/app/{Domain/TaskVms,Infrastructure/TaskVms,Jobs/TaskVms,Actions/TaskVms,Domain/Compute,Infrastructure/Compute,Actions/Compute}/**
  - apps/gateway/app/{Models/TaskVm.php,Models/TaskSandbox.php,Console/Commands/PrepareTaskVmHostCommand.php,Console/Commands/PrepareTaskVmHubCommand.php,Console/Commands/BuildTaskVmImageCommand.php,Console/Commands/BuildTaskVmWorkspaceTemplateCommand.php,Console/Commands/SyncTaskVmImageCommand.php,Console/Commands/RetireTaskVmHostCommand.php,Console/Commands/ResolvesTaskVmHostNode.php,Domain/Tasks/SandboxHostOperation.php,Infrastructure/Tasks/IncusSandboxHost.php}
  - apps/gateway/config/{task_vms,compute,queue}.php
  - apps/gateway/resources/{task-vms,compute}/**
  - apps/gateway/database/migrations/*{create_task_vms_table,add_model_proxy_origin_to_task_vms,add_warm_pool_to_task_vms,create_jobs_table,create_failed_jobs_table,create_task_sandboxes_table,add_fleet_enrollment_to_task_sandboxes,add_pi_ready_at_to_task_sandboxes}.php
---

# Compute drivers

A task group of a Project with `task_compute: vm` runs in its own VM. There are two lanes:

- The **web lane** serves every Project except `orbit`. Each group gets one [task VM](#task-vms), which is a normal `app-dev` Node of the dev Cluster.
- The **Orbit lane** serves the `orbit` Project. Each group gets an isolated pair of Incus VMs that never joins the live fleet. See [Local Incus control](#local-incus-control).

Both lanes are off by default. [ADR 0200](/decisions/0200-run-each-task-group-in-its-own-sandbox-vm) records the design.

## Task VMs

A task VM is an Ubuntu 26.04 VM on an Incus host. It boots from the [base image](#build-the-base-image), which is the same on every host and holds everything that enrollment installs on an `app-dev` Node. The Gateway creates it for one group, enrolls it as an `app-dev` Node, and destroys it when the group ends. After enrollment, the group uses the same code as a shared group, pinned to that Node. Only Incus hosts run task VMs. UpCloud follows in Phase 3 of [ADR 0200](/decisions/0200-run-each-task-group-in-its-own-sandbox-vm).

### From claim to workspace

A claim moves a group through these steps. Steps 2 to 5 are queued [jobs](#jobs). When the host keeps [warm VMs](#keep-warm-vms), the claim takes one and runs step 5 itself, and step 6 follows in the same claim.

1. The scheduler claims a `vm` group of a Project other than `orbit`. `AllocateTaskVmAction` takes a warm VM, or records a `provisioning` row and queues `ProvisionTaskVm`.
2. `ProvisionTaskVm` launches the VM from the base image with cloud-init user-data. Its disk is a ZFS clone of the image. On beast, the launch takes under a second. Cloud-init is done about 10 seconds later.
3. `EnrollTaskVm` waits for cloud-init. Then it reads the guest's IPv4 address, its ed25519 host key fingerprint, and the [image manifest](#skip-what-the-image-holds) from the host through `incus`.
4. `EnrollTaskVm` runs the normal [Node provisioning](/reference/node-provisioning#add-a-node) for the Node `tvm-<id>`, through the host as [jump host](/reference/node-provisioning#enroll-through-a-jump-host).
5. `PrepareTaskVmRuntime` creates the group's CLIProxyAPI key and starts Pi as `orbit`. See [Run Pi on a task VM](/reference/pi-server#run-pi-on-a-task-vm). The row becomes `ready`.
6. On the next claim, Orbit creates the [task workspace](/reference/tasks#task-vm-workspace) on the VM's Node. It is a normal Instance `task-<group id>` with the private Route `task-<id>.<project>.<dev-tld>`.

A workspace starts from the Project's [workspace template](#start-the-workspace-from-a-template) when the host has one.

`AllocateTaskVmAction` [places](#place-task-vms) the VM on a host. The row gets the name `tvm-<row id>`, the next free WireGuard address in the reserved range, and a random Pi token. It also records `claimed_at`, the moment of the claim.

Cloud-init creates the user `orbit` with passwordless sudo and the Gateway's SSH key. It does nothing else, because the base image already holds `openssh-server`, Chromium's system libraries, the software of the `app-dev` role, the Node agent, and Pi. Enrollment skips the programs that the image already ran, and finds the rest of that software installed. The Project's setup steps install the rest, for example its Playwright browsers.

`EnrollTaskVm` checks the VM every second, for up to 2 minutes in one run, and then again 15 seconds later. Once the Incus agent in the guest runs, one check waits in the guest for up to 30 seconds with `cloud-init status --wait`, so the job hears at once when cloud-init is done.

The job goes on when cloud-init reports `done` with no errors and the host reports the guest's bridge address. It reads the guest values only then, before any code but cloud-init has run in the VM. A guest reboot shows the VM stopped for about a second, so a stopped VM gets a second reading 5 seconds later before enrollment fails.

Provisioning links the new Node to the row when it creates the Node record, before any convergence. So the Node is a task VM Node from the start: the fleet rollout skips it, and it gets no Orbit CLI. It never hosts a [Metrics exporter](/reference/metrics#exporter-selection), because the [hub filter](#limit-fleet-traffic-on-the-hub) keeps the Metrics Node out. So its enrollment skips the fleet Metrics reconcile, which converges exporters on every Node and took about 80 seconds on the live fleet.

Each step of the jobs logs one line `Task VM step done.` in the Gateway log, with the VM name, the step, and its seconds. The steps are `create`, which counts from the claim, `boot`, `node-converge`, `apt-tool-manager`, `roles`, `router-lan-ingress`, `dns-selection`, `activate`, `agent`, `enrolled`, `model-key`, `pi-current` or `pi-upload`, `pi-files`, and `pi-server`. The last line, `claim-to-ready`, has the seconds from the claim to `ready`. A warm VM logs other first and last lines, as [Keep warm VMs](#keep-warm-vms) describes. The workspace creation then logs `workspace-template`, `workspace-source`, `workspace-route` for a routed workspace, and `workspace`, which has the seconds of the whole creation.

#### Skip what the image holds

The [image build](#build-the-base-image) runs the programs that enrollment runs on an `app-dev` Node: `bootstrap`, `caddy-package`, `app-dev-prerequisites`, `vp-manager`, and `composer-manager`. It records the fingerprint of each one, the SHA-256 of its exact arguments and input, in `/var/lib/orbit-task-vm/image.json` in the image. `EnrollTaskVm` reads that manifest through the host before enrollment. Enrollment then skips a program when it renders the same fingerprint under the same name, because the image ran that exact program and each program is idempotent. It logs `Task VM program skipped: its image ran it.` for each one.

Every other program runs, with the warning `Task VM image did not run this program; enrollment runs it.` and its reason. That happens when the Gateway's code or its SSH key changed the program since the build.

An image without a manifest, such as one built before manifests, or with a manifest the Gateway cannot read, makes enrollment run every program, with the warning `Task VM image has no usable manifest; enrollment runs every program.` So an older image is never wrong, only slower.

A skipped program fetches nothing at enrollment. The apt packages, Bun, Vite+ and its Node, and Composer are the versions that the last image build fetched. The nightly build refreshes them. Enrollment still renders and applies everything that is specific to the VM: the host key, WireGuard, the firewall, Caddy, DNS, the agent and its secret, and Pi. Only task VM enrollment reads a manifest, so every other Node runs every program.

Enrollment reaches the guest through the host as jump host until WireGuard is up. Those commands share one SSH connection, as commands to a Node over WireGuard do. Its name hashes the jump host, the target address, and the target's pinned host key. So the next VM at a reused address never gets the old VM's connection.

Measured on beast in a throwaway project, from the same code (11 October 2026), with the Gateway on the same host:

| Step | Before (seconds) | After (seconds) | What changed |
| --- | --- | --- | --- |
| `boot` | 11.2 | 10.3 to 10.5 | IPv4-only network online, cloud-init wait in the guest |
| `node-converge` | 6.9 | 1.7 to 2.2 | bootstrap skipped, one shared SSH connection through the jump host |
| `roles` | 5.8 | 1.0 to 1.2 | prerequisites, Vite+ and Composer programs skipped |

On the live Gateway each SSH command crosses WireGuard to the host, so the live steps take longer than these.

Until the row is `ready`, the claim returns the group to `todo` with a reason, and tries again on the next tick:

| Reason | Cause |
| --- | --- |
| `Task VM: task VMs are not enabled on this Gateway.` | `task_vms.enabled` is false and the group has no task VM |
| `Task VM: no capacity.` | Every host is inactive or full. See [Place task VMs](#place-task-vms) |
| `Task VM: waiting for a warm VM.` | Every host is full, and at least one of its VMs is a [warm VM](#keep-warm-vms) that is still starting. The claim takes it once it is `warm` |
| `Task VM: another placement is running.` | Another claim held the placement lock for 15 seconds |
| `Task VM: provisioning.` | The jobs are still running |
| `Task VM: failed: <code>: <message>` | A job failed. The group also asks for assistance. Cancel the group to destroy the VM |
| `Task VM: task_vm.invalid_config: <message>` | A `task_vms` value is invalid. Shared groups keep working, because only `vm` claims read these settings |
| `Task VM: destroying.` | Cleanup has started |

The Node `tvm-<id>` has user `orbit`, role `app-dev`, the dev Cluster, and the reserved WireGuard address. Shared groups never get a workspace on a task VM Node: their Node selection leaves out every Node of a task VM that is not `destroyed`.

### Destroy a task VM

When the group ends, Orbit removes its workspace Instance with force, as for a shared group. This withdraws the Route. A cancelled group first pushes its stored approval. A task VM workspace never holds a host [discovery topology](/reference/incus-topologies): Orbit refuses to acquire one there, so removal skips the topology release. Pushes, fetches, checks, and agent turns need the VM `ready`, so they finish before the VM is destroyed.

Each `tasks:tick` queues `DestroyTaskVm` for every task VM that is not `destroyed` when its group is `completed` or `cancelled`, no [claim](/reference/tasks#claim-and-provision) of the group is in flight, and no Instance is left on its Node. A claim is over once it starts the group, so a group that ends soon after its claim loses its VM on the next tick. Its unique lock has no expiry, so the tick never queues a second copy while one waits or runs. `DestroyTaskVm` then runs these steps:

1. It sets the row to `destroying` and revokes the group's model key.
2. It deletes the VM. Then it deletes the VM's [workspace clone](#start-the-workspace-from-a-template), because Incus keeps that volume. A VM or a clone that is already gone counts as deleted.
3. It removes the Node offline and with force. This sheds its `app-dev` role and removes its Process rows, WireGuard peer, and private DNS records.
4. It sets the row to `destroyed`.

The Node goes before the row becomes `destroyed`. A row that is not `destroyed` keeps its Node a task VM Node, so Orbit never sends the Gateway's own Pi token to it. When a step fails, the row stays `destroying` with the error, and the job runs again after 1, 5, and then every 15 minutes. A [parked](#park-and-resume) VM is deleted while it is stopped. Only its workspace removal starts it first.

Keep a host in `task_vms.incus.hosts` until it has no task VM that is not `destroyed`, because destroy needs the host's settings. Node removal refuses such a host with `node.has_task_vms`. It also refuses a host that is still configured, with `node.task_vm_host_configured`, because the other hosts still accept its transfer key. [Retire the host](#add-a-task-vm-host) first. Before it changes any other Node, it removes the Node's [transfer key](#let-hosts-copy-the-image) from every configured host, also when `task-vms:retire-host` did not run. When that fails, it fails with `node.task_vm_image_key_cleanup_failed` and changes nothing else. When it removes a host, it also deletes the host's `destroyed` rows.

#### A VM that dies before its group ends

Workspace removal needs SSH to the VM. When the VM is gone, removal fails and the group asks for assistance with the reason `Workspace removal failed:` or `Merged pull request cleanup failed:`. When the group is `cancelled`, `completed`, `settling`, or `waiting_for_review`, each tick then also queues `DestroyTaskVm` for that task VM. A group in another state, for example one whose cancel failed while the VM ran, keeps its workspace and its assistance request. Cancel it again: while the VM runs, cancel removes the workspace normally, and the next tick queues `DestroyTaskVm`.

The job reads the VM on its host. While the VM runs, it leaves the workspace to the normal removal. When the VM is absent, or still stopped 5 seconds after a stopped reading, it deletes the VM and then removes the workspace offline:

- It records the normal removal journal, with the source steps marked done, because the checkout is gone with the VM.
- It clears the Route through the normal removal projector. The projector skips the VM's Node for a task VM that is `destroying`. The router withdraws the site.
- It deletes the Instance's Process and Schedule records and the Instance row, and clears the group's removal reason.

Then it removes the Node and marks the row `destroyed`, as above. A removal journal that a normal removal left open is finished the same way. A cancelled group's stored approval is lost with the VM.

### Place task VMs

Each host in `task_vms.incus.hosts` has `max_vms` slots. Every task VM on the host that is not `destroyed` takes one slot, also a `warm`, `parked`, or `failed` one. The free slots of a host are `max_vms` minus those VMs.

A claim first takes a current [warm VM](#keep-warm-vms). It looks at the active hosts that have one, the host with the most free slots first, and takes that host's oldest warm VM. A warm VM already holds its slot, so a claim can take one on a full host.

Without a warm VM, `AllocateTaskVmAction` places a new VM on the active host with the most free slots. Hosts with the same number of free slots keep their order in `task_vms.incus.hosts`. A host that is not an active Node, or that has no free slot, gets no VM. When no host has a free slot, the claim returns the group to `todo` with the reason `Task VM: no capacity.` and tries again on the next tick. The group waits until a VM is destroyed or you add a slot.

Placement never puts more VMs on a host than its `max_vms`. One placement runs at a time on the Gateway, under a cache lock, so two claims cannot both take the last slot or the same warm VM. The pool refill takes the same lock. Lowering `max_vms` below the number of VMs on a host removes no VM. The host gets no new VM until enough VMs are destroyed.

With one host, placement is the same as before: the host takes VMs until it is full. To add a host, see [Add a task VM host](#add-a-task-vm-host).

### Keep warm VMs

A claim that takes a warm VM gets a ready VM in seconds, instead of about 35 seconds for a new one. A warm VM is a task VM that Orbit created, booted, and enrolled as a Node before any group needed it. Each host keeps `warm_vms` of them. The default is `0`, so a host keeps none until you set it. See [Configure task VMs](#configure-task-vms).

A warm VM is a `tvm-<id>` Node with user `orbit`, role `app-dev`, the dev Cluster, its own WireGuard address in the reserved range, and the Node agent. Its row has no group, no Pi token, no model key, and no `pi-server` Process. A pool row is any task VM row without a group: a warm VM, or one that Orbit is creating, failing, or destroying for the pool.

#### Fill the pool

Every minute, the Gateway scheduler queues the job `RefillTaskVmPool`, while task VMs are enabled and a host sets `warm_vms`, or while a pool row is not `destroyed`. A claim that takes a warm VM queues it too, and so does a VM that becomes `warm`. For each configured host whose Node is active, one run does these steps:

1. It reads the host's `orbit-task-base` image and the `volatile.base_image` of each instance. It records that image on each warm VM.
2. A warm VM from another image becomes `destroying`. So does a warm VM whose instance is gone.
3. Under the placement lock, it sets every `failed` pool row to `destroying`.
4. It counts the warm VMs and the pool VMs that still start. It sets the oldest warm VMs above `warm_vms` to `destroying`.
5. A pool VM that still starts is never stopped. A later run destroys it once it is warm.
6. Below `warm_vms`, it records new pool rows, up to the host's free slots.
7. It queues `DestroyTaskVm` for every pool row that is `destroying`, and `ProvisionTaskVm` for each new row.

A new pool row runs the normal `ProvisionTaskVm` and `EnrollTaskVm`. Then `PrepareTaskVmRuntime` sets it `warm` and records `warmed_at`, and runs no group step. The next refill records its image, so a claim can take it.

#### Claim a warm VM

The claim reads the `orbit-task-base` image of each active host that has a warm VM. It takes a warm VM only when the VM launched that image and its Node is active. The claim reads a host's image over SSH at most every 10 seconds. A build or sync that moves the alias makes the next claim read it again. A host whose image list cannot be read keeps its warm VMs for a minute, and the claims place new VMs instead.

Under the placement lock, the claim then makes these changes on the row in one update, and only when the row is still `warm` without a group:

| Field | Value |
| --- | --- |
| `group_id` | The claiming group |
| `state` | `provisioning` |
| `claimed_at` | Now |

Then it gives the row a new random Pi token. It queues `RefillTaskVmPool`, and the guard job `EnsureWarmClaimReady`, delayed by 330 seconds. The claim itself then runs the steps of `PrepareTaskVmRuntime`, which are only the group's steps: the CLIProxyAPI key, the Pi files, and the `pi-server` Process. They take about 1.5 seconds. The row becomes `ready`, and the same claim creates the workspace, as for a new VM.

The steps run in the claim, not on the `task-vms` queue, because its one worker runs one job at a time. Behind an enrollment, a sync, or the nightly build, a queued job would wait minutes. The claim runs in `tasks:tick` after the placement lock is released, and holds no transaction.

The claim keeps each step short, and the whole run ends after 60 seconds, well inside the tick lock of 300 seconds. At that deadline every SSH command and CLIProxyAPI request of the run stops, and a step that would start after it fails. So a stalled VM or a stalled CLIProxyAPI cannot hold `tasks:tick` past its lock, and no second tick starts the steps beside it.

| Step | Limit in the claim | Limit in the queued job |
| --- | --- | --- |
| Wait for another model key operation | 10 seconds | 60 seconds |
| Each CLIProxyAPI request | 5 seconds, and the claim deadline | 5 seconds |
| Each SSH step for the Pi check, `models.json`, and the token | 15 seconds | 60 seconds |
| Pi upload, when the VM has no current Pi | Not in the claim | 600 seconds |
| Each SSH command of the `pi-server` Process | The claim deadline | The normal Process limits |
| The whole run | 60 seconds | The job timeout of 900 seconds |

A VM from a current base image has the pinned Pi, so the claim never uploads it. Between a change of the Pi pin and the next image build, a warm VM has an older Pi. Then the claim stops at the Pi check, and the queued job uploads Pi.

When a step fails, the claim logs a warning and queues `PrepareTaskVmRuntime`, which retries the steps as for a new VM. When a release or a crash stops the tick during the steps, `EnsureWarmClaimReady` runs 330 seconds after the claim. It finds the row still `provisioning`, logs the error `A warm task VM claim is not ready after its claim; the task VM worker runs its group steps.`, and queues `PrepareTaskVmRuntime`. The steps are idempotent, so a repeat changes nothing. A row that is `ready` makes the guard do nothing.

The guard also does nothing when a `PrepareTaskVmRuntime` for the row is still queued or running. One example is the job that a failed claim queued behind a busy queue. The guard reads the job's unique lock, which the job holds until it ends. It logs only `A warm task VM claim waits for its queued group steps.` at info level, and that job ends the claim.

A warm claim logs `queue` first, which counts from the claim to the start of the group's steps, near 0 in the claim, and `warm-claim-to-ready` last, with the seconds from the claim to `ready`. A VM that becomes warm logs `warm`, with the seconds from its row to `warm`.

#### Replace warm VMs

A warm VM is replaced in two ways when a new base image is promoted, by a build or a sync:

- The claim reads the host's image at the claim, so it never takes a warm VM from an older image.
- The next refill, at most a minute later, destroys the warm VMs from the older image and creates new ones from the new image.

#### Drain the pool

While `task_vms.enabled` is false, the target of every host is `0`, so each refill destroys the warm VMs. Lower a host's `warm_vms` to destroy its surplus warm VMs. A host that is not an active Node keeps its pool, because destroy needs SSH to the host. Destroy also needs the host's settings. So when you remove a host's entry, its pool rows stay, and each refill logs an error for each of them. So set `warm_vms` to `0`, and wait until the pool is gone, before you remove a host's entry.

#### Warm VM failures

When `ProvisionTaskVm`, `EnrollTaskVm`, or `PrepareTaskVmRuntime` fails on a pool row, the row becomes `failed` with its error, as for any task VM. No group asks for assistance, because the row has none. The Gateway log gets the error `A warm task VM failed; its host creates no pool VM until the wait ends.`, with the error code, the count of failures in a row, and the time of the next try. The next refill destroys the failed VM.

The host then creates no pool VM for 1 minute. Each further failure doubles the wait, up to 1 hour. Each refill that waits logs a warning. A pool VM that becomes `warm` ends the wait. The failure count lives in the Gateway cache. A claim in the meantime places a new VM, so a broken pool never stops claims.

A pool row that is still `provisioning` an hour after the refill recorded it has lost its job, for example when a release stopped the worker. Every refill checks for such rows. It sets each one to `failed` with `task_vm.pool_start_timeout` and logs the same error, so the host waits as above. The same run destroys the row, and its slot comes back.

#### Other parts of Orbit

- A warm VM is never parked. Parking, resume, and the wake gate apply only to a VM with a group.
- The tick destroys only the VMs of ended groups. The refill destroys warm VMs.
- The [sweep](#sweep-task-vm-hosts) and Doctor know warm VMs, so a warm VM is never an orphan. A warm VM whose instance is gone is a missing VM.
- A warm VM's Node serves no Instance. Any Instance on it fails with `task_vm.foreign_instance`. Shared groups never get a workspace on it.
- The fleet rollout skips it, and it has no Metrics exporter, as for every task VM Node.
- Node removal refuses a host with a pool row that is not `destroyed`, with `node.has_task_vms`. `task-vms:retire-host` refuses it with `task_vm.host_in_use`.

#### Security of a warm VM

A warm VM is on the same task bridge as any task VM, behind the same bridge ACL and the same [hub filter](#limit-fleet-traffic-on-the-hub). From enrollment on, it has its Node identity: its SSH host key, its WireGuard key and address, and its Node agent secret. It holds no secret of a group until a claim: no Pi token, no CLIProxyAPI key, no `models.json`, and no workspace. Pi does not run on it.

The Gateway connects to Pi on a task VM only with the row's own token, and a warm VM's row has none, so the Gateway's own `ORBIT_PI_TOKEN` never reaches it either. The claim creates the Pi token, and `PrepareTaskVmRuntime` creates the model key and writes both to the VM. When the group ends, the VM is destroyed as usual. No VM serves a second group.

### Start the workspace from a template

A new task VM has empty caches, so a Project's setup steps download everything again. For mealou on beast they took about 36 seconds: `composer install`, `vp install`, a Playwright download of Chromium, and `vp build`. A workspace template holds their result, so the same steps find little to do.

Each host keeps one template for each Project whose groups run on task VMs. Orbit uses it for every such Project other than `orbit`. There is no setting: a Project without a template works as before. A template is an Incus block volume `tvm-template-<project id>-<time>` in the host's project and pool. Its ext4 filesystem holds the Project's apps directory on a task VM, such as `/home/orbit/apps/mealou`:

| Path in the volume | What it holds |
| --- | --- |
| `.orbit-workspace-template` | A checkout of the default branch, after the Project's setup steps: `vendor`, `node_modules`, and the build output |
| `.orbit-cache/ms-playwright` | The Playwright browsers |
| `.orbit-cache/composer` | The Composer cache |
| `.pnpm-store` | The pnpm store. pnpm puts it at the root of the filesystem by itself, because the default store is on another filesystem |

#### Claim with a template

When Orbit creates the workspace on a task VM, it runs these steps before source preparation:

1. It lists the storage volumes of the host's project. It takes the newest ready template of the Project that was cloned from the Project's current `repository_url`.
2. It clones the template as `tvm-<id>-workspace` with `incus storage volume copy`. On ZFS that is a clone of a snapshot: it took under 0.1 seconds on beast and shares every block with the template.
3. It attaches the clone to the running VM as the disk `workspace`.
4. Over the Node's SSH connection, it mounts the disk at the Project's apps directory. It never mounts over a directory with content. Then it adds the mount to `/etc/fstab`. It points `~/.cache/ms-playwright` and `~/.cache/composer` at the volume.
5. Source preparation checks the owner and the origin of `.orbit-workspace-template`. Then it renames that directory to the checkout `task-<id>`. The rename keeps the files and their inode. Preparation writes its receipt as for a clone.

After the Project's repository changes, no template matches until the next build. The fstab line follows a successful mount only, so a failed mount never comes back at a later boot over a fresh clone.

Then the workspace fetches and checks out the group's branch, as usual. The baseline check runs the setup steps on the workspace. Each step finds the dependencies in place and only installs what the branch changed.

Every step is idempotent, so a claim that runs again finds the clone, the disk, and the mount in place. A warm VM gets its clone at the claim, so warm VMs stay the same for every Project. The `orbit` user has the same UID in the builder and in every task VM, because they boot the same image with the same user-data, and the mount step checks the owner.

Measured on beast in a throwaway project (11 October 2026), mealou on its default branch:

| Step | Without a template | With a template |
| --- | --- | --- |
| Clone and checkout, in the guest | 1.2 seconds | 0.6 seconds for the fetch and checkout, after 0.5 seconds for steps 1 to 4 |
| `composer install` | 9.8 seconds | 4.1 seconds |
| `vp install` and the Playwright install | 21.9 seconds | 6.3 seconds |
| `vp build` | 2.5 seconds | 2.9 seconds |

The first run on a new VM reads the volume with a cold page cache. The setup steps take about 10 seconds once the cache is warm. `vp install` always runs `npm audit`, which reaches the network.

#### Without a template

The workspace starts from a fresh clone, as before, in these cases:

- The host has no ready template for the Project. Orbit logs the warning `Task VM workspace has no template; it starts from a fresh clone.` and queues a build of the template. See [Template failures](#template-failures) for the wait after a failed build.
- A step fails, for example the copy or the mount. Orbit logs the warning `Task VM workspace template could not be attached; the workspace starts from a fresh clone.` with the error.
- The volume holds no template checkout. Orbit logs `Task VM workspace volume holds no template checkout; it starts from a fresh clone.`
- The template checkout fails a check of source preparation, for example of its origin. Preparation then clones and logs `The workspace template failed its source checks; the checkout is a fresh clone.`

The group never fails or waits because of a template. When the mount succeeded, the fresh clone goes to the volume. Otherwise it goes to the root disk.

#### Build a template

Run `task-vms:build-workspace-template {node} {project}` to build the template of a Project on one host. It prints each stage with its time.

Every night at 04:00, after the base image refresh, while task VMs are enabled, the Gateway queues `RefreshTaskVmWorkspaceTemplates`. It runs on every configured host whose Node is active. It deletes the templates of Projects whose `task_compute` is not `vm`. It queues one `BuildTaskVmWorkspaceTemplate` for each Project whose `task_compute` is `vm`. A claim without a template queues the same job.

Orbit does not watch the default branch for pushes. A template that is behind the branch is still correct: the fetch and the setup steps bring the workspace up to date. The nightly build keeps the template at most a day old.

The build runs these stages on the host over SSH, with `sudo -n incus --project <project> …`:

| Stage | What it does |
| --- | --- |
| `sweep` | Deletes builder VMs and unfinished templates that an interrupted build left |
| `launch`, `cloud-init` | Launches a builder VM `tvm-template-<time>` from `orbit-task-base` with the task VM user-data, and waits for cloud-init |
| `volume` | Creates the block volume with the host's `disk` size, and attaches it |
| `mount` | Formats the volume as ext4, gives its root to `orbit`, and mounts it with the same script as a claim |
| `clone` | Clones the default branch into `.orbit-workspace-template`, with the Project's repository access, as `orbit` |
| `php` | Installs the `app-dev` PHP packages of the PHP version that the Project's `composer.json` selects, with the same program that the Instance runs on a task VM |
| `setup:<step>` | Runs each Project setup step as `orbit` in a login Bash in the checkout, with `VP_HOME` set, as the baseline check does. See below |
| `verify` | Requires a clean tree. The setup steps may only add ignored files |
| `detach` | Unmounts the volume and detaches it |
| `publish` | Sets `user.orbit.project`, `user.orbit.commit`, `user.orbit.repository`, and `user.orbit.ready=true` on the volume |
| `delete-builder` | Deletes the builder VM |
| `prune` | Deletes the older templates of the Project on the host |

The `@orbit/tia-baseline` step becomes the same installer as in the check. A builder has no Instance, so no SSR port is set. Each step gets its own timeout, but at most what the build's budget has left.

A build runs within a budget of 1500 seconds. The queued job's timeout of 1700 seconds and the host's lock of 1740 seconds outlast it, and both stay below the queue's `retry_after` of 1800 seconds, so no second worker takes a running build. One template build runs on a host at a time, under that lock.

A second one fails with `task_vm.workspace_template_running`, and the queued job waits a minute and tries again. A build runs on the `task-vms` queue, so it delays other task VM jobs for a few minutes. Warm claims run their steps in the claim and do not wait for it. On beast a build of the mealou template took 92 seconds. The PHP packages took 37 seconds of that, and the setup steps 36 seconds.

#### Template failures

A build that fails before `publish` deletes its builder VM and its volume, and the Project keeps its current template. After `publish` the new template is ready. A failed `delete-builder` or `prune` then only logs the warning `Task VM workspace template is ready, but a cleanup stage failed; the next build retries it.`

 It fails with `task_vm.workspace_template_failed`, which names the stage. The job logs the error `Task VM workspace template build failed; workspaces of the Project start from a fresh clone.`, and claims queue no new build of that Project on that host for 6 hours. The next nightly refresh tries again. A build stopped with SIGINT or SIGTERM, or past its job timeout, deletes its builder and frees the host. A build killed with SIGKILL holds the host for at most 25 minutes, and the next build sweeps what it left.

#### Disk use and pruning

A template takes the size of its files on the pool, compressed. The mealou template takes 1.1 GiB. Each host holds one template for each Project, plus one clone for each task VM that has a workspace. A clone takes only the blocks that its group changes. The volume is sparse, so its size limit, the host's `disk`, reserves nothing.

A new template replaces the older ones of its Project at once. Incus keeps the data of a deleted template until its last clone is gone, so a running group never loses its workspace. The nightly refresh deletes the templates of Projects whose `task_compute` is not `vm`. Destroy deletes a VM's clone after the VM, and the sweep deletes a clone whose VM has no row. A template holds no secret. The GitHub token reaches the builder only on standard input, as for a clone on a task VM.

#### Park, resume, and security

A parked VM keeps its disk attached. On the next boot, `/etc/fstab` mounts it before `multi-user.target`, so the agent and Pi start after it. In the test on beast, the volume was mounted by the time the VM answered again, 8 seconds after the start.

A template is built by code in a builder VM that no agent and no group ever ran on. Each group gets its own clone, which it can change freely, and the clone goes with its VM. So no state of one group reaches the template or another group. The Project's setup steps run in the builder, as they run in every task VM of the Project.

### Park and resume

A task VM that has nothing to do stops. Its group keeps the VM, and the next work for the group starts it again. A stopped VM uses no CPU or memory on its host. Its disk stays on the host's ZFS pool, so the workspace, its checkout, and Pi's sessions survive.

Each `tasks:tick` parks the `ready` task VM of a group when all of these are true:

- The group is `waiting_for_review` and has a pull request.
- The group has no `todo`, `reserved`, `running`, or `reviewing` subtask, and it is not `preview`.
- The group's reason does not start with `Workspace removal failed: ` or `Merged pull request cleanup failed: `. Those removals retry on the tick and need the VM.
- The group and all of its subtasks settled more than `task_vms.park_after_minutes` minutes ago. The default is 30 minutes.
- The VM has been `ready` for at least as long.

The last rule keeps a VM that Orbit started again, for example for a removal retry, running that long before it can park again.

The tick sets the row to `parked` and queues `ParkTaskVm`, which stops the VM with `incus stop`. A `parked` VM still takes its slot on the host, so a resume never needs a free slot.

Work for the group starts the VM again: a new subtask after a push, a review fix, a reviewer run, a base merge, the workspace removal after a merge, a completion, and a destroy that must first remove the workspace. That work waits while the VM is `parked`. The group shows the reason `Task VM: resuming.`, and Orbit queues `ResumeTaskVm`. The job runs these steps:

1. It starts the VM with `incus start`. A VM that already runs counts as started.
2. It checks every 2 seconds until the VM answers over WireGuard, `orbit-agent.service` is active, and Pi answers `GET /capabilities` with the group's Pi token.
3. It sets the row to `ready`.

The next tick then continues the work, and the reason clears. Orbit never runs a workspace command, an agent turn, a fetch, or a push on a VM that is not `ready`. Those operations fail with `task_vm.parked` while it is `parked`. A merged group's workspace removal and the sweep of ended groups wait without a failure. A manual `tasks:complete` records the reason `Workspace removal failed: ` with that error, and the sweep removes the workspace once the VM is `ready`. Completion removes the workspace under the group's admission lock, so a tick cannot park the VM during that removal.

When the VM does not answer within 9 minutes, the row becomes `failed` with `task_vm.resume_timeout`. The group asks for assistance with the reason `Task VM failed:`. A `parked` VM that has no workspace left is deleted without a start. Destroy on merge is otherwise unchanged.

### Sweep task VM hosts

Every 10 minutes, while task VMs are enabled, the Gateway scheduler queues the job `SweepTaskVmHosts`. For each configured host that is an active Node, the job checks that the host's Incus project exists with `incus project show`, then lists the names of its instances and compares them with the task VM rows. Incus lists a project that does not exist as empty, so a missing project fails the host, and the job changes nothing there:

- An **orphaned VM** is an instance named `tvm-<number>` in the project that has no task VM row on that host which is not `destroyed`. The job deletes it with `incus delete --force`.
- A **missing VM** is a `warm`, `ready`, or `parked` task VM row on that host whose instance is gone.
- An **orphaned clone** is a storage volume `tvm-<number>-workspace` in the project whose task VM has no row on that host that is not `destroyed`. The job deletes it after the orphaned VMs.

The job fails a missing VM only when two sweeps in a row miss it. The first miss is kept for an hour, and a sweep that finds the VM forgets it. On the second miss, the job sets the row to `failed` with `task_vm.vm_missing`. The group asks for assistance with the reason `Task VM failed:`. A warm VM has no group, and the pool refill destroys it.

Cancel or complete the group of a missing VM to destroy its Node, as for [a VM that dies before its group ends](#a-vm-that-dies-before-its-group-ends).

The job reads and changes only the host's configured Incus project, such as `orbit-tasks`. It never touches another project, an instance whose name is not `tvm-<number>`, the base image builder VMs `tvm-image-<time>`, or a workspace template. Rows in `provisioning`, `failed`, or `destroying` are never missing: their own jobs check the VM.

[Doctor](/cli/doctor) reports the VM findings in the `node` family of the host, without changes. It has no finding for an orphaned clone, a leftover template builder `tvm-template-<time>`, or an unfinished template: the sweep deletes the clone, and the next template build deletes the other two. Doctor's codes are `node.task_vm_orphaned` for an orphaned VM and `node.task_vm_missing` for a missing one. Doctor reports a missing VM on its first miss. A host whose project does not exist or cannot be listed reports `node.task_vm_inspection_failed`. Doctor checks a host only while it is in `task_vms.incus.hosts` and reachable over SSH. The fleet rollout ignores these codes.

Doctor does not inspect the Node of a `parked` task VM, because the VM is stopped on purpose. Its `node` family reports one informational `node.task_vm_parked`, and the other families check nothing. So no problem is filed for a parked VM.

### States

The `TaskVmState` enum has seven cases.

| State | Meaning |
| --- | --- |
| `provisioning` | Orbit is creating, enrolling, or preparing the VM |
| `warm` | A pool VM is enrolled and waits for a claim. It has no group, Pi token, or model key. See [Keep warm VMs](#keep-warm-vms) |
| `ready` | Pi runs, and Orbit can create the workspace |
| `parked` | Orbit stopped the VM, or is starting it again. The disk stays. See [Park and resume](#park-and-resume) |
| `failed` | A job failed. The row keeps `error_code` and `error_message`. The group shows the error as its reason and asks for assistance |
| `destroying` | Cleanup has started |
| `destroyed` | The VM, the Node, and the model key are gone. The row stays for audit |

Orbit derives progress inside `provisioning` from facts: the VM exists, the row has a Node, the Node is active, and the Pi Process exists. A group has at most one task VM that is not `destroyed`. A claim moves a `warm` row back to `provisioning` for its group's steps. `failed` is final for the row. Cancel the group to destroy the VM.

### Jobs

The task VM jobs run on the `task-vms` database queue, in the Gateway's own database. Each job loads its row again, does nothing when the row has moved on, and is unique for its task VM. While a job on that queue is due, the Gateway scheduler starts this worker every second. A delayed job, such as the guard of a warm claim, starts no worker before its time:

```text
queue:work task-vms --queue=task-vms --stop-when-empty --max-time=120 --timeout=1500
```

The worker stops when the queue is empty, or takes no new job after 120 seconds. So one worker run takes a VM from launch to `ready`. A job runs at most 1500 seconds, and a job that stops halfway runs again after 1800 seconds.

A job is due when its time has come and no worker holds it, or a worker's hold is older than 1800 seconds. A delayed retry or a released job starts no worker before its time. Without a due job, the scheduler only reads the queue table each second. A Gateway release drains the worker like any other scheduled command. Its start does not pause [document cleanup](/reference/project-documents), because it runs only task VM jobs.

| Job | Retries |
| --- | --- |
| `ProvisionTaskVm` | Once after 30 seconds |
| `EnrollTaskVm` | Fails at once on a cloud-init error, an absent or stopped VM, or a boot over 10 minutes. Retries other errors twice. Stops after 60 tries, waits included |
| `PrepareTaskVmRuntime` | Twice, 30 seconds apart |
| `DestroyTaskVm` | Until it succeeds |
| `ParkTaskVm` | Twice, 30 seconds apart. A failure stays on the row as its error, and the row stays `parked` |
| `ResumeTaskVm` | Checks for up to 3 minutes in one run, then again 15 seconds later. After the third run, or on an `incus start` error after three tries, the row becomes `failed` |
| `SweepTaskVmHosts` | Never. It runs again 10 minutes later |
| `RefillTaskVmPool` | Never. It runs again a minute later. A failed pool VM makes its host wait, as [Failures](#warm-vm-failures) describes |
| `EnsureWarmClaimReady` | Never. It queues `PrepareTaskVmRuntime`, which has its own retries |
| `RefreshTaskVmBaseImages` | Never. The next night runs it again |
| `RefreshTaskVmWorkspaceTemplates` | Never. The next night runs it again |
| `BuildTaskVmWorkspaceTemplate` | Waits a minute while another template build runs on the host, up to 10 times. A failed build is not retried; see [Template failures](#template-failures) |

When `ProvisionTaskVm`, `EnrollTaskVm`, `PrepareTaskVmRuntime`, or `ResumeTaskVm` has no retries left, or runs past its timeout, the row becomes `failed` with the job's error code, or `task_vm.job_failed` when the error has none. A pool row then makes its host [wait](#warm-vm-failures). Otherwise the group asks for assistance with the reason `Task VM failed: <code>: <message>`, so `tasks:status` lists it. `EnrollTaskVm` never creates the VM again. The jobs run one at a time, so the 10-minute boot limit counts from the moment `ProvisionTaskVm` launched the VM, not from the row.

### Incus provider

`IncusTaskVmProvider` runs `sudo -n incus --project <project> …` on the host Node over SSH, as the host's managed user. It launches the image alias `orbit-task-base` as a VM with the row's `name`, the host's `cpus`, `memory`, and `disk`, and `eth0` on the host's `network` with `security.port_isolation=true`. The user-data goes on stdin. The provider never creates a network.

When the launch fails and the project has no `orbit-task-base` alias, create fails with `task_vm.base_image_missing`. [Sync the image](#sync-the-base-image) to the host, or [build](#build-the-base-image) it. Orbit never falls back to the stock image: a VM from it has no `openssh-server`, so it cannot enroll.

Create, start, stop, and delete are idempotent by name. A running VM counts as created and started, a stopped or absent VM counts as stopped, and an absent VM counts as deleted. Stop waits up to 120 seconds for the guest to shut down, and then stops the VM with force.

Create starts a VM that exists but is stopped, and fails with the `incus start` error when it cannot. A launch that reports an error counts only when the VM runs afterwards. Otherwise create fails with the launch error. A delete that reports an error counts when the VM is gone. The claim checks `task_vms.enabled` before it allocates a VM. The jobs of an existing row use the provider whatever `enabled` says, so Orbit still destroys task VMs after you turn them off.

### Prepare an Incus host

Run `task-vms:prepare-host {node}` once for each host. It sends `resources/task-vms/incus-host.sh` to the host over SSH and runs it with `sudo -n bash -s --`. The script is idempotent and prints `{"ok":true}`.

First it checks the ZFS storage pool that the host's `pool` names. It creates a missing pool from the host's `zfs_dataset`, such as `fast/orbit-tasks`. That dataset must not exist yet, and Incus creates it. The script refuses an existing dataset and calls no Incus command for it, because Incus would unmount that dataset and destroy it with the pool. Without `zfs_dataset`, a missing pool fails. An existing pool must use the `zfs` driver. With `zfs_dataset` set, the pool's source must be that dataset. Then it sets up these parts:

- The Incus project, such as `orbit-tasks`, with `features.networks=false`, and the stock image alias `ubuntu-26.04-vm` from `images:ubuntu/26.04/cloud`. Only the [base image](#build-the-base-image) build launches it.
- The bridge, such as `orbittask0`, with IPv4 NAT and no IPv6.
- The ACL `<bridge>-egress`, which the bridge gets at creation.
- ACL egress drops private, link-local, CGNAT, and multicast ranges, and allows the rest.
- ACL ingress allows TCP 22 from the bridge address and rejects the rest.
- The project's `default` profile: `eth0` on the bridge with `security.port_isolation=true`, and the root disk on the host's pool.
- The project restrictions `restricted=true` and `restricted.networks.access=<bridge>`. Task VMs need nothing that they forbid, so whatever runs in the project cannot ask for it either.
- One host rule: `ufw route allow in on orbittask+ comment 'orbit-task-vms'`.

The command takes these values from the host's entry in `task_vms.incus.hosts`, which `TaskVmSettings` has validated. See [Configure task VMs](#configure-task-vms). The script checks the form of each argument again before it passes it to Incus as root, and changes nothing when one is invalid. The bridge and the ACL live in the Incus project `default`, and the script names that project in every network and ACL command.

Orbit reserves bridge names that start with `orbittask`. Every such bridge must carry its egress ACL, because the ufw rule accepts forwarded traffic from all of them. Only `incus-host.sh` creates these bridges, and it attaches the ACL at creation. Never create an `orbittask` bridge in another way.

The restrictions forbid privileged and nested containers, disks outside a storage pool, host devices, low-level config such as `raw.qemu`, backups, snapshots, and every network except the task bridge.

The dropped egress ranges are `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`, `169.254.0.0/16`, `100.64.0.0/10`, `224.0.0.0/4`, and `240.0.0.0/4`. Port isolation blocks traffic between VMs on the same bridge. Both are needed. The ufw rule lets bridge traffic pass the host's forward policy, and replies use the existing rule for established connections.

After the script, the command [lets the hosts copy the image](#let-hosts-copy-the-image) to each other. It prints one line for each host that it set up and one for each probe.

### Let hosts copy the image

Every configured host whose Node is active can send the base image to every other one over SSH. The image goes from host to host and never passes through the Gateway. `task-vms:prepare-host` sets this up on every host, not only on the one it prepares. Each run makes the setup exact again, and it changes nothing when the setup is already right.

It sets up these parts on each host, as root over SSH:

| Part | What Orbit sets |
| --- | --- |
| Transfer key | An Ed25519 key pair in `/etc/orbit/task-vms/image-transfer`, which only root reads. The host makes it once. The private key never leaves the host |
| Receiver | `/usr/local/libexec/orbit/task-vm-image-receive`, owned by root, from `resources/task-vms/image-receive.sh` |
| Peer keys | One line for each peer's transfer key in the `authorized_keys` of the host's managed user, marked `orbit-task-image-<peer Node id>` |

A peer key line looks like this:

```text
from="192.168.6.20",restrict,command="sudo -n /usr/local/libexec/orbit/task-vm-image-receive orbit-tasks" ssh-ed25519 AAAA… orbit-task-image-9
```

- `from` accepts the key only from the peer's address.
- `restrict` turns off forwarding and the terminal.
- `command` is the forced command. sshd runs it for any command that the peer asks for. The key never gets a shell.
- The project comes from the line, not from the peer.

The receiver reads one image on stdin into a staging file in `/var/lib/orbit-task-vm-image/receive`, which only root reads. It hashes the image while it arrives, imports it into the project with `incus image import`, removes the staging file, and prints the SHA-256. That is the image's Incus fingerprint. Empty input is a probe: the receiver prints `ready` and changes nothing. It refuses an image larger than 32 GiB. A staging file that a killed receiver left is removed after two hours.

Orbit rewrites only the lines whose last field is `orbit-task-image-<id>`. It keeps every other line, and writes nothing when the file is already right. A host without peers gets no peer key line.

Then each host sends a probe to each peer. A host that cannot reach a peer's receiver fails the command with `task_vm.host_command_failed`, the address, and the ssh error. So a missing firewall rule or a wrong key shows here, not in the next sync.

#### Addresses

Two hosts use their LAN addresses when both are in one Cluster and both have a LAN address. Otherwise they use their WireGuard addresses. The source binds ssh to its own address, so the `from` restriction matches. beast (`192.168.6.20`) and shark (`192.168.6.40`) are both in Cluster 4, so they use the LAN.

The target must admit TCP 22 from the source's address. Orbit adds no firewall rule for it:

- On WireGuard, the member trust rule `orbit:wireguard-members` admits it. That traffic may run through the VPN hub. A copy then takes longer and loads the hub.
- On the LAN, an operator ufw rule must admit it. On beast and shark, the rules for `192.168.6.0/24` do.
- On the LAN, sshd must also listen on the LAN address. beast and shark do.

The probe finds a missing rule or listener.

The source pins the target's Ed25519 host key. The Gateway reads that key from the target over its own SSH connection before each send, so no host trusts a host key on first use.

#### Peer keys

A peer key reaches only the receiver. Root on a host that an attacker controls can therefore import any image into each peer's task VM project, again and again. It cannot move `orbit-task-base`. Only the Gateway moves it, after it checks the fingerprint. So such a host can fill a peer's disk, but cannot change what task VMs boot there.

On the LAN, a host masquerades its Docker containers and NAT Incus bridges behind its LAN address. The `from` restriction admits that address. Those guests still cannot use the key, because only root on the host reads it.

#### Remove the Incus API copy

Hosts once copied the image with `incus image copy`, between their Incus APIs. `task-vms:prepare-host` removes what that left on every host, before it sets up the keys. Each step does nothing when its part is gone:

- `core.https_address` with the host's own LAN or WireGuard address on port 8443, which Orbit set. Orbit leaves any other value alone.
- Every trust entry named `orbit-task-image-*`. Other entries stay.
- The client directory `/etc/orbit/task-vms/incus`.
- Every `orbit:task-vm-image:*` firewall rule. A failed delete restores nothing, and the next run deletes the rest.

The project restrictions stay. No peer reaches Incus now, so they guard against nothing from a peer. They cost nothing, and they limit what anything in the project can ask Incus for.

### Build the base image

Run `task-vms:build-image {node}` to build the base image on one host. It prints each stage with its time. Then it [syncs](#sync-the-base-image) the new image to every other configured host, so every host launches the same image. It runs these stages on the build host over SSH, with `sudo -n incus --project <project> …`:

| Stage | What it does |
| --- | --- |
| `launch`, `cloud-init` | Launches a builder VM `tvm-image-<time>` from the stock image `ubuntu-26.04-vm`, with the host's VM size and bridge, and waits for cloud-init |
| `bootstrap`, `caddy-package`, `app-dev-prerequisites`, `vp-manager`, `composer-manager` | Runs the Node bootstrap, the Caddy package, the `app-dev` role prerequisites, and the Vite+ and Composer manager programs as `orbit` through `incus exec`, in enrollment's order |
| `agent-binary` | Installs the pinned [Node agent](/reference/node-agent) binary |
| `pi-artifact` | Streams the pinned Pi executable to the VM. The VM checks its digest and installs it for `orbit` as `~/.local/bin/pi-server`. The stage runs only when the Gateway pins a Pi executable |
| `single-boot` | Makes a VM from the image boot once, as described below |
| `lean-boot` | Keeps work off the boot, as described below |
| `manifest` | Writes the fingerprints of the programs it ran to `/var/lib/orbit-task-vm/image.json`. See [Skip what the image holds](#skip-what-the-image-holds) |
| `clean` | Runs `resources/task-vms/base-image-clean.sh` in the VM, which removes the VM's identity |
| `publish` | Stops the VM and publishes it, without compression, as the image `orbit-task-base-<time>` |
| `smoke` | Boots a smoke VM from the new image with the task VM user-data |
| `promote` | Points the alias `orbit-task-base` at the new image in one step |
| `prune` | Deletes every other `orbit-task-base-<time>` image that no VM in the project uses |

The smoke VM must finish cloud-init without errors. It must have an ed25519 SSH host key and the Gateway's key for `orbit`. Its journal must list one boot only. After a build, it must also have kept the lean boot through its first boot and hold the manifest. A [sync](#sync-the-base-image) does not check those two, so it can still copy an image built before them.

A VM from the stock image boots twice. Its Incus templates write the NoCloud seed, but cloud-init starts before the Incus agent writes it, so the agent reboots the VM. That costs about 10 seconds. The `single-boot` stage drops the templates under `/var/lib/cloud/seed/` from the image metadata, and keeps the others, such as `/etc/hostname`. It also orders `cloud-init-main.service` after `incus-agent.service`. Cloud-init then reads the user-data from the Incus agent through its `LXD` data source, and each VM gets its own instance ID from Incus.

The `lean-boot` stage keeps work off the boot. A VM from the image reaches cloud-init's end about a second sooner:

- The Ethernet link takes DHCPv4 only. The file `/etc/systemd/network/00-orbit-task-vm.network` sorts before the netplan file and otherwise matches it.
- The task bridge has no IPv6. The wait for an IPv6 link-local address cost about a second of each boot.
- `systemd-networkd-wait-online` waits for one routable IPv4 link with DNS, through a drop-in that replaces netplan's command.
- It masks `systemd-firstboot.service`, `apt-daily.timer`, `apt-daily-upgrade.timer`, `motd-news.timer`, and `unattended-upgrades.service`.
- The apt jobs could hold the dpkg lock during a task.
- So a task VM gets no unattended security updates. Its packages are those of the last nightly build. A parked VM can live for days at that state.
- Systemd applies its preset policy on a first boot. The policy enables a disabled unit again, but it keeps a masked unit masked.

Docker stays enabled at boot. Doctor expects it active on an `app-dev` Node. It also starts the containers with a restart policy again after a [resume](#park-and-resume). The smoke check requires Docker active.

The agent binary and the Pi executable are the Gateway's pins at build time. The nightly refresh picks up new pins. Enrollment still checks both digests and installs the pinned build when the image has another one.

The builder's user-data adds three things to the task VM user-data: a package upgrade, `openssh-server`, and Chromium's system libraries. Enrollment runs the same programs over SSH, rendered by the same code, so the image holds what enrollment installs. The clean script fails while part of the identity remains. The smoke boot also creates the image volume on the pool, so the first task VM is a clone too. New task VMs launch from the new image, and running VMs keep theirs. An image that a task VM still uses stays until a later build finds it unused.

The Chromium libraries are Playwright's `chromium` dependency list for Ubuntu 26.04, which covers `chromium` and `chromium-headless-shell`: `libasound2t64`, `libatk-bridge2.0-0t64`, `libatk1.0-0t64`, `libatspi2.0-0t64`, `libcairo2`, `libcups2t64`, `libdbus-1-3`, `libdrm2`, `libgbm1`, `libglib2.0-0t64`, `libnspr4`, `libnss3`, `libpango-1.0-0`, `libx11-6`, `libxcb1`, `libxcomposite1`, `libxdamage1`, `libxext6`, `libxfixes3`, `libxkbcommon0`, and `libxrandr2`. Playwright's xvfb and font list for headed runs is left out. The `app-dev` role prerequisites install PHP, Composer, Caddy, Docker, Vite+ with Node and pnpm, and Bun.

The clean script removes the SSH host keys, the machine ID, every authorized key, the cloud-init state and its generated configuration, the logs, the APT package cache, temporary files, and shell history.

It also stops Docker, containerd, and Caddy, and removes the IDs they write on their first start: Docker's engine ID, containerd's UUID, and Caddy's instance UUID, storage clean record, and saved config. So every VM from the image gets its own host keys, machine ID, and DHCP address, and cloud-init runs again on its first boot. The package lists stay, so an enrollment that runs a program again fetches only changes. The manifest stays too.

The builder never enrolls, so the image has no WireGuard key, agent secret, Caddy site, firewall rule, or Gateway record. Each VM gets those from its own enrollment.

The command deletes the builder and the smoke VM whether the build passes or fails. A failed build keeps the current base image on every host. It deletes the new image when it already published one, and fails with `task_vm.image_build_failed`, which names the stage. Only one build or sync of a host runs at a time. A second one fails with `task_vm.image_build_running`.

A build or sync that stops early cleans up too. A command stopped with SIGINT or SIGTERM deletes its builder and smoke VMs and frees each host. When the nightly job runs past its timeout, the worker's failure handler does the same. A build killed with SIGKILL holds the host for at most 25 minutes. The next build or sync deletes every builder and smoke VM that an earlier one left.

Every night at 03:00, while task VMs are enabled, the Gateway scheduler queues the job `RefreshTaskVmBaseImages` on the `task-vms` queue. It builds the base image once, on the first host in `task_vms.incus.hosts`, and syncs it to every other configured host, one after another. While the first host has no base image, the job only reads its image list, so the first build is always `task-vms:build-image`. The job fails with `task_vm.unknown_host` when the first host's Node is not active.

A failed build changes no host. A host whose sync fails keeps its image, and the job fails with `task_vm.image_sync_failed` after the other hosts, so `failed_jobs` records it and the log names each host. The worker runs one job at a time, so a refresh delays other task VM jobs while it runs.

### Sync the base image

Run `task-vms:sync-image {node}` to copy the current base image to one host. By default it copies from the first configured host. `--from` names another host. It prints each stage with its time. `task-vms:build-image` and the nightly refresh run the same sync on every other host after a build.

The source exports the image, and streams it with ssh and its [transfer key](#let-hosts-copy-the-image) to the receiver of the target, which imports it. The Gateway only starts the commands, and the image never passes through it. The source is only read. The target then gives the image the source's dated alias `orbit-task-base-<time>`.

The export is a file, because `incus image export` writes no stream. The receiver writes the image once more into its staging file, because `incus image import` reads a file. ssh carries it between the two with no other copy. An image of about 3 GiB takes about half a minute on a 1 Gbit/s LAN. On beast, a 1 GiB image took 0.9 seconds to export and 3.8 seconds to receive and import, without the network.

| Stage | What it does |
| --- | --- |
| `inspect` | Reads the source image's dated alias and the host's images. A host whose `orbit-task-base` already names the image stops here, and the command says so |
| `sweep` | Deletes builder and smoke VMs that an interrupted build or sync left |
| `export` | The source runs `incus image export` into `/var/lib/orbit-task-vm-image/export/node-<target id>`, which only root reads. It empties the directory first, and expects one image file |
| `transfer` | The source streams the file to the target's receiver, which imports it. The fingerprint that the receiver prints must be the source's |
| `verify` | Gives the image the dated alias, and checks that the alias names the source's fingerprint. An equal fingerprint is an equal image |
| `smoke` | Boots a smoke VM from the copy, with the same checks as the build's smoke boot |
| `promote` | Points `orbit-task-base` at the copy in one step |
| `prune` | Deletes every other `orbit-task-base-<time>` image that no VM in the project uses |

A host that has the image already skips `export` and `transfer`. The source removes the export directory after the transfer, also when a stage fails. Incus names an image by the SHA-256 of its content, so `verify` compares content.

The export may take 300 seconds and the transfer 540 seconds. With a smoke boot of up to 600 seconds, a sync ends within the host's 25-minute image lock.

A transfer can fail after the import, for example when the connection drops before the receiver's answer. So when ssh fails, Orbit reads the target's images. When the image is there, it logs the warning `The task VM base image transfer failed, but the target has the image.` with the exit code and the ssh error, and goes on with `verify`. Otherwise the stage fails with the exit code and the end of the ssh error output. When the receiver imports an image with another fingerprint, Orbit deletes it and the stage fails.

A failed sync fails with `task_vm.image_sync_failed`, which names the stage. The host keeps its current base image. Orbit deletes the smoke VM, and the copy when it never became the base image. A sync never changes the source's images or another host. After a build, each host's result is one line with its time and stages, or its error code. One failed host does not stop the others, and the command then exits with a failure. When the command is stopped, the next sync to that host removes the export it left.

Running VMs keep the image they launched from. The prune keeps an image that a VM still uses. A claim that resolved the old alias just before the prune can fail its launch when the prune deletes that image first. `ProvisionTaskVm` then retries once after 30 seconds, from the new image. When the alias move succeeds but its reply is lost, the failed sync keeps the copy, because the alias names it.


### Add a task VM host

A second host needs Incus, ZFS, and ufw, and its managed user needs passwordless `sudo`. Then:

1. Add an entry for the host to `ORBIT_TASK_VMS_INCUS_HOSTS`. Give it its own `cidr` and a new `zfs_dataset`, such as `data/orbit-tasks`. Never name a dataset that exists. Apply the change with [`gateway:release:configure`](/reference/gateway-recovery#apply-an-env-change).
2. Run `task-vms:prepare-host {node}`. It creates the pool from the new dataset and sets up the host. Then it lets all hosts [copy the image](#let-hosts-copy-the-image) to each other, and probes each pair.
3. Run `task-vms:sync-image {node}`. It copies the base image of the first host. On the first host of a fleet, run `task-vms:build-image {node}` instead.

The new host takes VMs on the next claim. Its bridge network must not overlap another host's. The hub filter and the Caddy guard depend only on the reserved WireGuard range, so `task-vms:prepare-hub` and the Caddy builds need no new run. The nightly refresh syncs every configured host, so a host that you prepare and do not sync gets the image the next night.

To retire a host:

1. Set its `warm_vms` to `0`. Set its `max_vms` to the number of VMs it still has, or lower. Apply the change. The pool refill destroys its warm VMs. Wait until it has no task VM that is not `destroyed`.
2. Remove its entry and apply the change with `gateway:release:configure`.
3. Run `task-vms:retire-host {node id}`. It refuses a host that is still configured with `task_vm.host_configured`, and a host that still has a task VM with `task_vm.host_in_use`.

`task-vms:retire-host` first sets up the remaining hosts again. That removes the old host's key from each of them. Then it removes the old host's own setup: its peer key lines, `/etc/orbit/task-vms/image-transfer`, the receiver, `/var/lib/orbit-task-vm-image`, and what the [Incus API copy](#remove-the-incus-api-copy) left. When the Node record is gone, it only cleans the remaining hosts. When SSH cannot reach the old host, the command fails after the remaining hosts are clean, and the old host keeps its own setup. Only then can [Node removal](/reference/node-provisioning#remove-a-node) remove the Node, if you want it gone.

The old host's Incus project, images, [workspace templates](#start-the-workspace-from-a-template) `tvm-template-<project id>-<time>`, bridge, ACL, and pool stay. Remove them by hand. Delete the template volumes before the project, because Incus refuses to delete a project that still holds volumes.

### Limit fleet traffic on the hub

This filter relies on the [Caddy guard](#refuse-task-vms-in-caddy). Reverb shares TCP 443 on the `websocket` Node with every other private Caddy site on that Node, such as `executor.orbit`, so an L4 rule cannot tell the sites apart. Run `task-vms:prepare-hub` only after you configure task VMs and rebuild Caddy on every Node that serves sites.

Run `task-vms:prepare-hub` once, and again when one of the values below changes. It runs `resources/task-vms/hub.sh` on the `vpn` Node. The script installs the nft table `inet orbit_task_vms` and the oneshot unit `orbit-task-vms-hub.service`. The unit loads the table after `nftables.service` and before `wg-quick@orbit` starts. The tunnel pulls the unit in but does not depend on it, so a failed load never stops the fleet VPN. The script checks that the table is loaded, is idempotent, and prints `{"ok":true}`.

The command takes every value from Gateway records and settings:

- the reserved range from `task_vms.wireguard_range`;
- the VPN DNS address: the `vpn.dns_server` setting, or else the WireGuard address of the `vpn` Node;
- the Node with the `gateway` role, and the Pi port `orbit.pi.port` (`ORBIT_PI_PORT`, default `3774`);
- Reverb at `reverb.orbit`, which is TCP 443 on the Node with the `websocket` role;
- the host and port of `task_vms.model_proxy_origin`;
- the router of the dev Cluster.

The table filters only the reserved range. Its first rule passes every packet that has no address in the range, so traffic between other Nodes does not change. The range must be inside the WireGuard subnet, because every Node routes only the subnet through the tunnel. `TaskVmSettings` checks that. The command refuses a range that holds the address of a Node not named `tvm-<id>`. It also refuses a missing dev Cluster or origin, and an origin whose host is not an IPv4 address outside the range.

| Direction | Allowed |
| --- | --- |
| From task VMs | The Gateway API on TCP 443, CLIProxyAPI on TCP 8317, Reverb on TCP 443, and DNS on port 53 of the VPN DNS address |
| To task VMs | The Gateway on TCP 22 and the Pi port, and the dev Cluster router on TCP 80, 443, and 5173 |

The table accepts established traffic and drops all other traffic to or from the range. The WireGuard address allocator skips the range for other Nodes. Only `AllocateTaskVmAction` assigns addresses in it. A task VM Node has no access grants to other Nodes.

### Refuse task VMs in Caddy

TCP 443 on the Gateway Node and on the `websocket` Node is one Caddy listener for every private site on that Node, so the hub table cannot tell `reverb.orbit` from `executor.orbit` or `analytics.orbit`. Caddy closes that gap. On every Node, every site except `gateway.orbit` and `reverb.orbit` aborts a client in `task_vms.wireguard_range`. [Caddy configuration](/reference/caddy-configuration#listener-addresses) shows the guard.

Caddy renders the guard only after you configure task VMs: you enable them, or you set `dev_cluster_id`, `model_proxy_origin`, or a host. Until then, every Caddyfile stays as it was. The guard reads only that condition and `task_vms.wireguard_range`. Another invalid task VM value never changes a Caddyfile or blocks a build.

The next Gateway release rebuilds only the Gateway Node's Caddyfile. After you set these values, run `php artisan orbit:caddy-build NODE` for every other Node that serves sites, before the first task VM enrolls. Until then, Doctor reports `role.caddy_build_drift` on each of those Nodes. Unset these values only after every task VM is destroyed, because the next build of each Node then drops the guard.

While task VMs are configured and the range is invalid, every Node Caddy build refuses at stage `render` and names the problem, so live Caddyfiles do not change. This includes the Gateway release handoff, which fails with `gateway.release_caddy_failed`. So after [`gateway:release:configure`](/reference/gateway-recovery#apply-an-env-change), run `php artisan orbit:caddy-build NODE --dry-run` for the Gateway Node. It prints `Build refused:` and the problem when the range is invalid.

### Placement invariant

A task VM workspace is a normal Instance on a normal Node, so generic Instance operations need no sandbox guard. One rule protects the VM: an Instance on a task VM Node must be its group's `task-<group id>` workspace in the group's Project. The rule runs whenever an Instance is saved, so it covers create, clone, transfer, and register. Any other Instance fails with HTTP 409 `task_vm.foreign_instance`. Orbit also refuses an access grant from a task VM Node with HTTP 409 `task_vm.access_refused`. The fleet rollout skips task VM Nodes with the reason `sandbox`. Shared groups never get a workspace on one.

### Configure task VMs

These keys live in the Gateway's `config/task_vms.php`. Set them in the Gateway's environment.

| Key | Variable | Meaning |
| --- | --- | --- |
| `task_vms.enabled` | `ORBIT_TASK_VMS_ENABLED` | Allows new task VMs. Default `false` |
| `task_vms.dev_cluster_id` | `ORBIT_TASK_VMS_DEV_CLUSTER_ID` | The ID of the Cluster that task VM Nodes join |
| `task_vms.wireguard_range` | `ORBIT_TASK_VMS_WIREGUARD_RANGE` | The reserved WireGuard range. Default `10.44.0.128/25`, the upper half of the default VPN subnet `10.44.0.0/24` |
| `task_vms.model_proxy_origin` | `ORBIT_TASK_VMS_MODEL_PROXY_ORIGIN` | The CLIProxyAPI origin that Pi on the VM uses, such as `http://10.44.0.3:8317`. An `http` or `https` origin with no path, query, or credentials. It must be the CLIProxyAPI URL of the [proxycli extension](/reference/proxycli) |
| `task_vms.pi.artifact_path` | `ORBIT_TASK_VMS_PI_ARTIFACT_PATH` | The absolute path of the pinned Pi executable on the Gateway |
| `task_vms.pi.artifact_sha256` | `ORBIT_TASK_VMS_PI_ARTIFACT_SHA256` | Its lowercase SHA-256 digest. Set both Pi artifact values or neither |
| `task_vms.pi.models` | `ORBIT_TASK_VMS_PI_MODELS` | A JSON list of the models Pi offers. Default `[]` |
| `task_vms.incus.hosts` | `ORBIT_TASK_VMS_INCUS_HOSTS` | A JSON list of hosts. Their order breaks ties in [placement](#place-task-vms). Default `[]` |
| `task_vms.park_after_minutes` | `ORBIT_TASK_VMS_PARK_AFTER_MINUTES` | How many minutes a group waits for review before its VM [parks](#park-and-resume), from 1 to 10080. Unset or empty means `30`. `0` and other values are an error |

Each task VM stores `model_proxy_origin` with its model key, and revokes the key at that origin. So changing the origin never strands a key. A task VM that holds a key at another origin cannot prepare Pi again.

Each host is a JSON object with these snake_case keys. Other keys are an error.

| Key | Required | Default | Meaning |
| --- | --- | --- | --- |
| `node_id` | Yes | | The ID of the host Node. List each host once |
| `cidr` | Yes | | The task bridge network, such as `10.251.77.0/24`: a private network from `/16` to `/28`, not a host address. The bridge gets the first usable address and VMs get the others |
| `max_vms` | Yes | | The slots of the host: the most task VMs on it that are not `destroyed`, from 1 to 64 |
| `project` | No | `orbit-tasks` | The Incus project |
| `network` | No | `orbittask0` | The bridge name: `orbittask` and 1 to 6 lowercase letters or digits |
| `pool` | No | `orbit-tasks` | The Incus storage pool for the base image and the VM root disks. It must use the `zfs` driver |
| `zfs_dataset` | No | | The ZFS dataset from which `task-vms:prepare-host` creates `pool` when it is missing, such as `fast/orbit-tasks`. A new dataset below a ZFS pool: not the pool itself, and not a dataset that exists |
| `cpus` | No | `2` | The vCPUs of each VM, from 1 to 64 |
| `memory` | No | `4GiB` | The memory of each VM, in `MiB` or `GiB` |
| `disk` | No | `20GiB` | The root disk of each VM, in `MiB` or `GiB` |
| `warm_vms` | No | `0` | How many [warm VMs](#keep-warm-vms) the host keeps for claims, from 0 to `max_vms`. They take slots of `max_vms` |

For example, `ORBIT_TASK_VMS_INCUS_HOSTS='[{"node_id":7,"cidr":"10.251.77.0/24","max_vms":4,"warm_vms":1}]'`.

`TaskVmSettings` validates the configuration once, when it is first used. It checks every value that is set, also while task VMs are off, so you can prepare hosts and the hub before you enable task VMs. An unset or empty value stays absent, and malformed JSON is an error. Enabling task VMs also requires `dev_cluster_id`, `model_proxy_origin`, and at least one host.

The reserved range must always be a private network. As soon as task VMs are enabled, or the Cluster, the origin, or a host is set, the range must also be a smaller network inside the Gateway's VPN subnet, and no host's `cidr` may overlap that subnet. While none of them is set, Orbit does not compare the range with the subnet, so a fleet on another subnet keeps working. Invalid configuration fails with `task_vm.invalid_config` (HTTP 500).

### Errors

`IncusTaskVmProvider` is the only place that reads host and guest output. It checks the instance state and its one IPv4 address inside the bridge range, the cloud-init status, and the host key fingerprint. Invalid output fails with `task_vm.invalid_host_output`. Every task VM error code starts with `task_vm.`. The setup commands print the code and message, and for `task_vm.setup_failed` the last lines of the script's error output. After that check, Orbit trusts its own records.

| Code | HTTP | Cause |
| --- | --- | --- |
| `task_vm.invalid_config` | 500 | A `task_vms` value is invalid, or the proxycli CLIProxyAPI URL is not `model_proxy_origin`. See [Configure task VMs](#configure-task-vms) |
| `task_vm.unknown_host` | 409 | The Node is not active or not in `task_vms.incus.hosts` |
| `task_vm.invalid_gateway_key` | 500 | The Gateway's SSH public key is not one OpenSSH public key line |
| `task_vm.workspace_mismatch` | 409 | A group's workspace is not on its ready task VM |
| `task_vm.foreign_instance` | 409 | An Instance on a task VM Node is not its group's workspace |
| `task_vm.access_refused` | 409 | An access grant names a task VM Node as its consumer |
| `task_vm.range_in_use` | 409 | A Node not named `tvm-<id>` holds an address in the reserved range |
| `task_vm.fleet_unavailable` | 409 | The fleet has not exactly one active `vpn`, `gateway`, or `websocket` Node, the dev Cluster has no active router, or one of these Nodes has no WireGuard address |
| `task_vm.setup_unavailable` | 409 | The setup script is missing, or the target Node has no WireGuard address |
| `task_vm.setup_failed` | 409 | The setup script failed or did not print `{"ok":true}` |
| `task_vm.invalid_host_output` | 502 | Incus returned output that fails these checks |
| `task_vm.host_command_failed` | 502 | An `incus` command on the host failed |
| `task_vm.bootstrap_failed` | 502 | Cloud-init in the VM reports `error` |
| `task_vm.not_enrolled` | 409 | The task VM has no Node yet, so Pi cannot be prepared |
| `task_vm.model_key_failed` | 409 or 502 | CLIProxyAPI did not confirm the group's model key, the proxycli extension holds no management key, or another key operation runs |
| `task_vm.runtime_failed` | 502 | A Pi step failed on the VM: the executable, its digest, `models.json`, the token, or the `pi-server` Process |
| `task_vm.vm_not_running` | 502 | During enrollment, the VM is absent, or it stayed stopped |
| `task_vm.bootstrap_timeout` | 504 | Cloud-init was not `done` 10 minutes after the row was created |
| `task_vm.job_failed` | 500 | A task VM job failed with an error that has no code of its own |
| `task_vm.base_image_missing` | 409 | The host's project, or the sync source's, has no `orbit-task-base` image. Run `task-vms:sync-image` or `task-vms:build-image` |
| `task_vm.image_build_failed` | 502 | A base image build failed. The message names the stage |
| `task_vm.image_build_running` | 409 | A base image build or sync of the host is already running |
| `task_vm.image_sync_failed` | 502 | A base image sync failed. The message names the host, the source, and the stage. The nightly refresh uses this code when any host failed |
| `task_vm.host_configured` | 409 | `task-vms:retire-host` names a host that is still in `task_vms.incus.hosts` |
| `task_vm.host_in_use` | 409 | `task-vms:retire-host` names a host that still has a task VM that is not `destroyed`, a warm one included |
| `task_vm.parked` | 409 | The group's task VM is `parked`. Orbit starts it, and the work runs once it is `ready` |
| `task_vm.resume_timeout` | 504 | A `parked` VM did not answer within 9 minutes of the first start |
| `task_vm.vm_missing` | 502 | The sweep found a `warm`, `ready`, or `parked` task VM gone from its host |
| `task_vm.pool_start_timeout` | 504 | A pool VM was still `provisioning` an hour after the refill recorded it |
| `task_vm.workspace_template_failed` | 502 | A workspace template build failed, or the mount of a clone failed. The message names the stage |
| `task_vm.workspace_template_running` | 409 | A workspace template build of the host is already running |

### Limits

Task VMs have these known limits.

- Incus accepts DNS before the ACL, so a task VM can query port 53 on any host address. It can read instance names from the DNS of other bridges. This risk is accepted.
- A base image is only as fresh as its last build. The nightly refresh keeps packages and the `app-dev` software at most a day old. A host without a base image cannot start task VMs.
- Each sync copies the whole image, about 3 GiB. ZFS send and receive can copy only the changes. That is a possible optimisation for slow copies.
- Root on one host can send any image to a peer's task VM project, up to 32 GiB each time. It can fill the peer's disk. See [Peer keys](#peer-keys).
- Copies over WireGuard may run through the VPN hub.
- The worker runs one job at a time, so a long enrollment delays the jobs of other task VMs, including a resume.
- Doctor can report task VM Nodes while they exist.

Warm VMs have these limits:

- A warm VM uses its host's memory and a little CPU while it waits.
- Each warm VM takes a slot of `max_vms`. New VMs can use only the other slots.
- A claim never takes a pool VM that still starts. On a full fleet with such a VM, the claim waits with `Task VM: waiting for a warm VM.` for about 35 seconds.

A warm claim takes this long:

- A group waits for the next `tasks:tick`, every 10 seconds, before its claim. The warm claim then takes about 1.5 seconds to `ready`, and creates the workspace.
- In the worst case, the claim's own steps end after 60 seconds. The queued job does the rest. See [Claim a warm VM](#claim-a-warm-vm).
- A warm VM with an older Pi is `ready` only after the queued job uploads Pi, behind the other jobs on the queue.
- After a tick that dies during the steps, the group waits 330 seconds for the guard, and then for the queued job.

The claim and the refill also have these limits:

- The claim reads each host's image list over SSH inside the scheduler tick, at most every 10 seconds. It waits up to 15 seconds for a host.
- A claim also reads an alias that someone moved by hand. The refill then replaces the warm VMs within a minute.
- The backoff count lives in the Gateway cache. A cleared cache forgets it, and the next refill tries again.

Workspace templates have these limits:

- A template is as fresh as its last build. A claim fetches the rest, and the setup steps install what changed.
- The builder installs the PHP packages of the version that `composer.json` selects. A setup step that needs more than the image and those packages fails the build, and the Project's workspaces start from a fresh clone.
- A template build occupies the `task-vms` worker for a few minutes, so cold enrollments wait behind it.

An ignored file that no setup step rewrites keeps its state from the template's commit, up to a day old. Examples are a SQLite database from a migration, a config cache, or a `.env` that a step copies only while it is missing. Such a file can make a check pass in the workspace that fails from scratch. Orbit does not remove ignored files, because `vendor` and `node_modules` are ignored files too. A step that creates such a file should rewrite it. No current `vm` Project has one.

The template also leaves the step before the baseline check as it is:

- The template shortens the baseline check, not the step before it.
- In group 1428 the step before the baseline check took 44 seconds.
- The Instance's PHP package install takes about 25 seconds on a fresh VM. Source preparation and the route took the rest. The `workspace-*` step lines show the split.

Parking has these limits:

- A `parked` VM keeps its slot. Parking frees CPU and memory on the host, not slots.
- A `parked` VM's private Route does not answer. Set `preview` on a group to keep its VM running during review.
- Doctor does not check a `parked` VM's Node until the VM starts again.

The hub filter has these limits:

- The hub filter fails open to keep the fleet VPN up. The tunnel starts even after a failed table load at boot.
- A later restart of `nftables.service` removes the hub table, but its unit still shows active. Run `task-vms:prepare-hub` again to load the table.

### Why task VMs work this way

The VM edge is the security boundary. Inside it, one user runs the agents, Pi, and the check with sudo, so the check sees what CI sees. Enrolling the VM as a normal Node reuses enrollment, Instances, Routes, checks, and publication instead of a second path. Host output is validated once, and Gateway records are trusted after that, so seven states and no marker timestamps describe the whole lifecycle. `claimed_at` and `warmed_at` only time it. Parking reads the settle times the group already records.

Public egress is open on every port. Limiting it to HTTP(S) adds no protection beyond the VM edge and the hub filter, and it broke clock sync, package sources, and key installs. CI on the pushed commit is the gate.

The base image keeps enrollment as the only path to a Node. The build runs the programs that enrollment renders, so the image cannot drift from what enrollment installs. Enrollment still checks every step on each VM. Code builds the image from the stock image on a known date, and no image carries state from a task. ZFS makes each VM a clone of the image volume, so a launch copies nothing.

One build serves every host, so a group behaves the same on any host. Builds on each host differed: the same commit gave beast and shark different images, because packages and even the stock image changed between the builds. One build also saves about two minutes of build time for each other host. The hosts send the image over SSH to a forced command, so it stays on the LAN when the hosts share one, and no host opens its Incus API to the network.

Most of a new VM's 35 seconds go to its boot and its enrollment, and none of that depends on the group. So a warm VM does those steps before the claim, and the claim adds only what belongs to the group: its Pi token, its model key, Pi, and its workspace. A warm VM holds no secret of a group, so it can wait on the task network like any other VM. A VM serves one group only and is destroyed after it, so no state of one group can reach the next.

`warm_vms` is a setting of each host, next to its `max_vms`. Hosts differ in size, and setting a host's `warm_vms` to `0` drains only that host, which retiring a host needs. A claim reads the image itself, so a stale warm VM never serves a group, even before the refill replaces it.

Most groups wait for review far longer than they work, so parking frees the host's CPU and memory for other groups. A stop keeps the disk, so a resume continues the same workspace in seconds instead of a new claim. Placement by free slots spreads VMs across hosts and keeps every host inside its budget. The sweep catches what a crash between two steps leaves, and it acts only on names and the project that Orbit owns.

Phase 3 of [ADR 0200](/decisions/0200-run-each-task-group-in-its-own-sandbox-vm) aligns the UpCloud template with the same contract. The template is built from the stock image with its identity removed. Its cloud-init installs nothing. A smoke boot passes before use, and the previous image stays while a VM uses it.

These alternatives were rejected:

| Alternative | Why not |
| --- | --- |
| WireGuard in cloud-init | It bypasses normal enrollment |
| An Incus proxy device for SSH | Its port does not match the Node firewall catalog, and it needs forward and DNAT rules on the host |
| The Incus REST API with a restricted certificate, from the Gateway | It needs more host setup, and the Gateway already has root SSH to the host |
| A build on each host | The same commit gave different images, and each host spent about two minutes on its build |
| `incus image export` streamed over SSH through the Gateway | Each copy would cross the Gateway twice. The direct copy keeps the image on the LAN when it can |
| `incus image copy` between the hosts' Incus APIs | Orbit used it first. It needed an Incus listener, certificate trust, and firewall rules. Masqueraded guests reached a peer's API, and a copy once failed after the image arrived |
| rsync over SSH | Its forced command would have to check `rsync --server` arguments. Its resume and delta transfer do not help, because a new image is a new file |
| `scp` into a staging directory, then a second command that imports | It needs two forced commands, or a key that can write files. One stream into one forced command needs neither |
| Trust on first use for a peer's host key | The Gateway can read the key over SSH, which it already trusts |
| A hub table for each VM | One static filter on a reserved range does the same work |
| Installing the `app-dev` software on every VM | It added about 40 seconds of boot and package installs to each VM on beast, and each claim depended on package mirrors |
| A base image for each Project | Each Project would need its own image, built and kept current. A [workspace template](#start-the-workspace-from-a-template) is a volume that a claim attaches to any VM |
| A virtiofs filesystem volume for the workspace | It hot-plugs too, but the setup steps ran 10 to 18 times slower on it than on an ext4 block volume |
| `cp --reflink` inside the VM | The guest root is ext4 on a ZFS volume, so a reflink copies nothing |
| A template only for the caches | It saved almost as much on mealou, but every claim still clones and links `node_modules`. The checkout in the template also replaces the clone |
| A setting that turns templates on for each Project | A Project without a template already falls back to a fresh clone. A template costs about 1 GiB on each host |
| A copy of a finished task VM as the next image | An agent and the Project's install scripts ran on that disk, so no cleanup can prove it clean |
| A fallback to the stock image | A VM from it cannot enroll, so the failure would come later and less clearly |
| A GitHub token inside the VM | The Gateway already fetches and pushes over SSH |
| A snapshot before each park | ZFS keeps the stopped disk, and a resume continues it. A snapshot adds a copy that nothing restores |
| A parked VM frees its slot | A resume could then find its host full, and the group's work would wait behind new groups |
| Placement in host order only | The first host fills before the second takes any VM |
| One `warm_vms` for the whole fleet | Orbit would need a rule to spread it across hosts, and one host could not drain alone |
| Warm VMs outside `max_vms` | A host could then run more VMs than its budget |
| A warm VM with its Pi token and Pi running | A token without a group has no owner. The claim adds the token, the key, and Pi in about 1.5 seconds |
| A claim that takes a pool VM that is still starting | The row would need a group step in the middle of enrollment. The claim waits for it to become `warm` instead |
| A record of the current image on the Gateway | It can drift from the host's alias. The claim reads the alias, which is the fact |
| Stopped warm VMs, saved with a stateful stop | It may follow as a hot pool. A running warm VM uses little and needs no restore |

## UpCloud driver

The UpCloud driver creates, observes, parks, resumes, and destroys VMs at UpCloud through its HTTPS API. It stays in the code for Phase 3 of [ADR 0200](/decisions/0200-run-each-task-group-in-its-own-sandbox-vm), which moves UpCloud to the [task VM](#task-vms) path. No claim, command, or job calls it now, so Orbit creates no UpCloud VM and its settings have no effect. Leave `ORBIT_UPCLOUD_ENABLED` and `ORBIT_UPCLOUD_ENROLLMENT_ENABLED` at `false`.

`ProvisionTaskSandboxAction` reserves one VM with the pinned Ubuntu image and the `starter-small` size. The reservation records its UUID, image, plan, network, and the Gateway's public SSH key before the request. Cloud-init creates the `orbit` user with only the Gateway's key. It carries no provider, GitHub, Pi, or model credential.

The driver sends a create request at most once for each reservation. After a lost response it looks for one exact match by hostname, title, and ownership label, and never sends a second create. It installs a provider firewall before it reports a VM as running: public SSH only from the Gateway, WireGuard only from the hub, and outbound HTTP(S), DNS, and the hub. `destroy` deletes only the recorded server and disk, and confirms both are gone before it releases capacity.

### Gateway configuration

Set these values in the Gateway environment.

| Variable | Meaning | Default |
| --- | --- | --- |
| `ORBIT_UPCLOUD_ENABLED` | Permit new VM provisioning | `false` |
| `ORBIT_UPCLOUD_TOKEN_FILE` | Absolute path to the provider credential file, mode `0600`, owned by the Gateway user | Unset |
| `ORBIT_UPCLOUD_MAX_VMS` | Maximum outstanding reservations | `0` |
| `ORBIT_UPCLOUD_ZONE` | UpCloud zone for new reservations | `nl-ams1` |
| `ORBIT_UPCLOUD_GATEWAY_ADDRESS` | Public IPv4 address allowed to SSH into the VM | Unset |
| `ORBIT_UPCLOUD_WIREGUARD_ADDRESS` | Public IPv4 address of the WireGuard hub | Unset |
| `ORBIT_UPCLOUD_WIREGUARD_PORT` | WireGuard UDP port | `51820` |

The token goes only to `https://api.upcloud.com/1.3`. The sandbox sweep refuses an UpCloud reservation with `compute.unknown_provider`, so a leftover UpCloud VM needs manual cleanup.

### UpCloud enrollment

`EnrollUpCloudSandboxAction` enrolls a running reservation as an `app-dev` Node. It needs `ORBIT_UPCLOUD_ENROLLMENT_ENABLED`, `ORBIT_UPCLOUD_DEV_CLUSTER_ID`, `ORBIT_UPCLOUD_MODEL_ADDRESS`, and `ORBIT_UPCLOUD_MODEL_PORT`. It is off by default, nothing calls it, and it starts no agent. A Node that a reservation owns stays out of the [fleet rollout](/reference/gateway-recovery#rollout-set-and-order) as `sandbox`. Orbit cannot destroy an UpCloud reservation yet: every destroy path refuses it with `compute.unknown_provider`. Phase 3 of [ADR 0200](/decisions/0200-run-each-task-group-in-its-own-sandbox-vm) brings UpCloud back on the task VM path.

### Why the UpCloud driver works this way

A started cloud server does not prove that a task can run, so provider state and task state stay separate. Ownership recorded before each request lets cleanup find the VM after a Gateway restart. Refusing a second create when a response is lost prevents duplicate billed VMs.

## Local Incus control

This section and the sections after it describe the Orbit lane. The local driver uses `orbit-agent sandbox` on a managed Linux host over pinned
SSH. The command accepts only bounded typed requests. It embeds the host
controller, so a guest checkout cannot replace host control code. The host's
managed account must already have Incus access; the command grants no permissions.

### Resources

Each reservation records its host, isolated Incus project, VM image fingerprints,
storage pool, subnet, and blocked networks. The project must be marked
`user.orbit.compute.owner=orbit-task-sandbox` and use `features.networks=false`.
VMs and storage belong to that project. Dedicated bridges and ACLs use the host
network namespace because bridges scoped to a project require OVN.

Only UUID-derived resources with the reservation's ownership markers can be
changed. Existing image identities, devices, profiles, and network policy must
match. Public HTTP(S) and public DNS are permitted; fleet, private, host, metadata,
and other group addresses are excluded. Host forwarding policy must also permit
the dedicated bridge. Host firewall access requires the opt-in policy below.

### Image test baselines

Sandbox images need a test baseline from CI. Each successful project job on `main` publishes a `sandbox-tia-<index>-<commit>` artifact for 14 days. It contains the Pest graph, the Pint and PHPStan caches, and a manifest with the Project path, tested commit, CI run, graph checksum, and test configuration checksums. [Feature delivery](/reference/implementation-loop#ci-artifacts) lists its files. The shared main cache store imports the same artifact.

The graph records the tested commit and a result for every test file it links, whether the job ran the affected tests or the full suite. Image preparation must select a successful CI run and validate that manifest before importing the graph. Pull request runs do not publish these image inputs. A baseline accelerates local feedback; CI on the published task commit remains the merge gate.

Download the artifact outside the sandbox from a successful `main` CI run. Pass its extracted directory to `bin/tia-cache import-ci --project <path> --artifact <directory> --commit <tested-sha>` inside the image checkout. The command validates checksums, configuration, portable graph paths, test results, and commit ancestry. It seeds only an absent private graph and preserves an existing one. It does not fetch credentials or publish the imported graph to a shared cache store.

When Orbit prepares a sandbox checkout, it records the Project's default branch as `origin/HEAD` without contacting GitHub. Pest uses that local reference to select its baseline. The source ownership record fixes the default branch for the checkout; a changed branch or reference is refused before another turn.

### Power and recovery

A lock on the host serializes provisioning and power operations. Starting and
running VMs consume the host VM budget. The operator and test gateway share one volume for the group checkout. Park
stops every VM before snapshotting the volume and guest disks. Resume restores
all snapshots and rechecks capacity before starting either guest. A create interrupted
before start can be retried under the same identity. A parked sandbox requires
resume. Cleanup removes only that sandbox's VMs, worktree volume, snapshots, bridge, and ACL.

The driver retains ownership after an uncertain result. A missing VM is not proof
of complete cleanup. Only successful destruction releases the reservation as
destroyed. These controls provide the local compute boundary; task workspace,
agent, and scheduler integration must be proven before enabling VM task execution.


### Configure local placement

`ORBIT_INCUS_ENABLED` defaults to `false`. `ORBIT_INCUS_HOSTS` is a JSON list of
hosts, in placement order. Each entry contains:

| Field | Meaning |
| --- | --- |
| `node_id` | Enrolled host Node ID |
| `project` | `orbit-task-sandboxes`, or `orbit-sandbox-proof-<suffix>` for an isolated proof |
| `pool` | Storage pool for guest disks and the worktree volume |
| `max_vms` | Running or reserved VM budget, from 1 to 64 |
| `warm_pairs` | Unassigned Orbit pairs to keep ready: 0, 1, or 2; defaults to 0 |
| `orbit_images` | Pinned VM fingerprints keyed by `operator`, `gateway`, and optional workload role |
| `blocked_networks` | Additional host and LAN IPv4 CIDRs to exclude from public egress |

The allocator accepts only Orbit groups already pinned to `vm`. It reserves an Orbit
pair before provisioning. Existing reservations retain their
provider, host, image, network, and identity; a parked reservation resumes there.
Stopped guests release compute capacity while retaining their subnet and storage.
Orbit groups stay local. These settings alone do not start sandbox task execution.
Web-lane hosts use [`task_vms.incus.hosts`](#configure-task-vms).

### Keep an Orbit pair ready

Set a host's `warm_pairs` to 1 or 2 after its pair images and source template pass acceptance. The target must fit inside `max_vms`. Warm pairs use the same VM budget as task claims; each pair reserves two VMs, a subnet, and its private Pi port. Local compute, Orbit claims, and the Tasks extension must be enabled before replenishment starts. A zero target preserves the default behavior.

A warm reservation has its own UUID and records the exact published images and source template before cloning. It has no task group, workspace Instance, GitHub token, Pi token, or model key. Its running power state confirms the physical pair only. Normal source, native readiness, and Pi admission still run when a group claims it.

A new Orbit claim uses a matching warm reservation before allocating another pair, even when the remaining VM budget is zero. Assignment preserves the sandbox UUID, images, subnet, devices, and source-template provenance. An incomplete warm reservation can finish under the claim's normal retry path. An assigned sandbox never returns to the pool, and deleting its task group does not turn it into a warm reservation.

The scheduler attempts resumes and new claims before replenishing the pool. It creates or retries at most one warm pair per claim pass and does not replenish while eligible Orbit work waits. Failed preparation keeps its recorded identity and capacity reservation for retry. The orphan sweep preserves explicit unassigned warm intent. Reconciliation destroys stale or excess unassigned pairs through their recorded driver; it never removes another group's resources. A host removed from configuration retains its outstanding reservations until its recorded configuration is restored for cleanup.

Disabling the pool stops replenishment and drains its unassigned reservations. Running groups keep their recorded placement and compute mode. First-agent timing must be measured after warm assignment; a running pair alone is not evidence that an agent can start.

### Commands inside a guest

The host accepts a separate `guest_command` envelope addressed to an owned VM
role. It runs the command as the guest's managed `orbit` user, with a clean
environment and passwordless sudo inside the VM. Command input travels on
protected stdin. The host never interprets a guest command as a host shell
program. Time and output limits apply on both sides of the VM boundary.

Guest commands share a per-sandbox lock. Park, resume, and destroy take its
exclusive lock, so they wait for active commands. Guest work does not hold the
host capacity lock and cannot stall operations for another group. This transport
is an internal building block; workspace execution remains gated until the
full task path is connected and proven.

### Workspace command routing

A sandbox workspace records its reservation in `task_sandbox_id`. Task checks,
receipts, signatures, and diffs use that reservation to reach the guest. They
verify the group, Project, workspace, provider, host, and running state before
sending a command. A VM group with missing sandbox ownership fails closed.
Shared workspaces retain their managed-host transport and worker account.

Guest checkout paths are scoped to a sandbox, so different VMs on one host can use the same path. Shared Instances retain their unique Node and checkout path. Each sandbox still owns at most one workspace.

Sandbox checks use the guest's runtime and home. They do not borrow the host's
Vite+ installation, dependency seed, or `orbit-worker` account. Workspace
provisioning remains gated while Git publication, agent credentials, and
image preparation are integrated.

### VMs use temporary GitHub App access

The Gateway keeps the App private key and mints installation tokens scoped to the Project repository. Fetch and publication run directly in the owned VM over authenticated HTTPS. Tokens travel as protected SSH input, never in command arguments, stored origins, or Git configuration. Every Gateway operation obtains fresh access, so later turns do not depend on an expired token. Fetch updates remote-tracking refs without moving HEAD or replacing local work. Publication pushes the exact approved commit to `task-<id>` without force.

This applies to Orbit sandboxes only. No GitHub token enters a [task VM](#task-vms).

### Pi sessions stay bound to their sandbox

A sandbox reserves a random Pi token before guest configuration. The Gateway encrypts it at rest and excludes it from model serialization. Retries reuse the same token. Sandbox Pi connections never use the Gateway-wide token or the host Node’s Pi settings.

A thread records the sandbox reservation as its runtime identity. Creation and later requests check the owning group, workspace, placement, and running state. An Orbit connection uses the configured Incus host’s private address and the sandbox’s reserved proxy port. A parked, destroyed, replaced, or unconfigured sandbox refuses requests instead of selecting another server. Proxy provisioning and image setup remain prerequisites for enabling VM claims.

Sandbox MCP files are installed through the guest transport. They name the disposable test Gateway at `https://gateway.orbit/mcp/search`. Shared topology acquisition is refused for sandbox workspaces. VM reviewer requests record workload requirements and use the owned compute driver and private Gateway readiness gate.

### Prepare source inside the guest

`SandboxWorkspaceSource` initializes a blank checkout with a sandbox ownership marker, fetches remote refs directly from GitHub with temporary App access, and creates the task branch from its published branch or the Project default. The guest initializes its checkout and fetches the selected refs. Retrying a prepared checkout preserves local commits and uncommitted files. A foreign directory, changed origin, or changed checkout branch fails without replacing its contents.

An Incus Orbit sandbox can fetch from GitHub before the cloned private Gateway is retargeted. Each template guest already resolves public names through `1.1.1.1` and `9.9.9.9`, as described in [Build a cold candidate](#build-a-cold-candidate).

Before it refreshes dependencies, pair preparation gives the private Gateway's dnsmasq backend fixed public upstreams at `1.1.1.1` and `9.9.9.9`, which the host boundary permits. Private records and peer DNS routes stay in place. Orbit checks the source owner and isolated Gateway address before it writes the upstream file. A foreign file or failed validation refuses readiness; failed activation removes only the new owned drop-in.

This prepares source only; the claim gate still requires the runtime, model proxy, and topology bootstrap.

### Durable firewall policy on an Incus host

Install the fixed `apps/agent/resources/incus-host-network.py` helper as root-owned `/usr/local/libexec/orbit-sandbox-network` with mode `0755`. Grant the trusted compute account passwordless sudo for that exact executable with no arguments. Never grant a caller-supplied Python script or interpreter. The helper accepts only a bounded JSON request with `operation` (`enabled`, `project_enabled`, `verify`, `ensure`, or `remove`), `project`, and `sandbox_id` on standard input.

The `project_enabled` operation, and an optional `project_bootstrap` object in the configuration below, served the first web-lane build, which is deleted. No Gateway request uses them. Leave `project_bootstrap` out.

The root-owned `/etc/orbit/sandbox-network.json` file opts in selected Incus projects. Its required fields are `version: 1`, `projects`, `pi_host`, `gateway_address`, `wireguard_interface`, and `blocked_networks`.

Use canonical IPv4 values for the host and Gateway WireGuard addresses. Set `wireguard_interface` to the host’s WireGuard interface name. The host address must belong only to that interface, and its link kind must be `wireguard`. Include the host's LAN networks in `blocked_networks`. Keep the file at mode `0644` under directories that only root can write. An absent installation preserves the existing behavior. An incomplete or unsafe installation refuses new provisioning. The helper verifies the installed boot unit, its enablement, and the loaded Incus dependency before granting access.

Only new bridges receive `user.orbit.compute.host_network=1`. Existing unmarked bridges retain their current firewall policy. The helper checks the root configuration, project ownership, exact bridge identity, bridge settings, and ACL ownership through local Incus before granting access. It derives the subnet from the bridge and excludes private, metadata, multicast, host, LAN, and other sandbox destinations from public access. The request cannot supply rules, addresses, paths, or commands.

The helper installs dedicated IPv4 filter chains and scoped jumps ahead of the host's existing INPUT, OUTPUT, and FORWARD rules. It permits public HTTP(S), UDP/TCP DNS only to `1.1.1.1` and `9.9.9.9`, replies to those connections, DHCP, the operator's own model relay, and the recorded Gateway's Pi connection through that WireGuard interface. Pi replies must leave through the same interface. A matching source address on another interface is refused. Own topology peers can communicate. Other traffic involving the bridge is dropped. Dedicated IPv6 chains drop all traffic involving the bridge. Incus NIC filtering and ACLs remain active.

Root-owned manifests persist before rules are applied. Each address family changes in one `iptables-restore --noflush` transaction. IPv6 protection precedes IPv4 access. A failed second transaction retains the manifest and the first family's rules for retry. Retries restore a completely missing owned policy, but refuse changed chains, jumps, configuration, or foreign files.

Boot restoration waits up to 60 seconds for the recorded host WireGuard address, then verifies its interface before restoring access. Resume checks the policy before starting guests. Parking retains it. Destruction stops and removes guests, removes only the recorded policy, audits its absence, and then deletes the bridge. Unrelated chains and rules are preserved.

Install `apps/agent/resources/orbit-sandbox-host-network.service` and the supplied Incus service dependency before opting in. The boot service waits for host networking, verifies that every connected host network is still excluded, and restores every recorded policy before Incus can start. A new public host network outside the saved exclusions refuses startup until the operator recovers the policy.

The host needs Python 3, systemd, `iptables`, `ip6tables`, their save/restore tools, and local Incus. Firewall reloads must restore the owned policies before starting or resuming sandbox work. Do not flush the helper's chains as part of another service's policy update. Rule or jump drift requires operator recovery; the helper does not overwrite it. Drain every marked sandbox before removing the configuration, helper, or boot dependency.

The privileged CI test runs real packet checks in a disposable Linux network namespace. This proves packet filtering, retries, drift refusal, and exact cleanup without changing the host namespace. Installing the durable policy on a live host and proving disposable Incus connectivity are separate rollout steps. Keep Orbit VM claims disabled until those steps pass.

### Pi proxy on an Incus host

An Orbit host can set `gateway_address` to the real Gateway’s WireGuard address in `compute.incus.hosts`. New Orbit reservations then record the host’s WireGuard address and a port from `23001` to `23254`. A stopped reservation retains its subnet and port. The operator’s Incus NAT proxy forwards that private port to guest port `3774`; its ACL permits only the recorded Gateway to enter through that port.

The proxy never binds a public or wildcard address. Changing or removing an existing proxy through reprovisioning is refused as device drift. This requires a Pi server in the image and host forwarding that permits the owned bridge; creating the proxy does not weaken other host firewall rules.

Guest preparation admits Pi traffic from the recorded live Gateway to the operator's reserved bridge address on TCP port `3774`. A service owned by root restores this rule and a source-specific return route at boot, before Pi starts. The return route applies only to replies from that bridge address to the live Gateway. Traffic from the operator's isolated WireGuard address keeps its existing routes, even when the live and private Gateway addresses overlap.

Preparation finds the guest interface by its reserved address; an Incus device name does not fix the VM's interface name. It refuses foreign routing-table entries, policy rules, service files, or marked firewall rules. Retries keep the same policy and Pi process. These guest rules do not change the host ACL or permit new outbound fleet access.

### Group model keys

`SandboxModelKeys` reserves a random key in encrypted, hidden sandbox storage before registering it with CLIProxyAPI. Registration retries reuse the key and its recorded endpoint. A changed management endpoint refuses recovery until the original endpoint is restored. Registration stays disabled unless `compute.model_proxy.enabled` is enabled.

Revocation uses a JSON `PATCH` that replaces only the group’s key with an empty entry. It does not send credentials in a URL or replace the complete key list. Before registration or revocation, Orbit ensures that a separate random authentication key is present. This key stays encrypted in Gateway settings and never enters a guest. It remains after the last sandbox is destroyed, because CLIProxyAPI permits unauthenticated requests when no usable keys remain.

Orbit verifies that the revoked key and anonymous requests receive `401` before clearing its stored group key. Empty entries contain no credential or group identity. An uncertain result retains the encrypted key for cleanup retries.

Allocation registers model credentials and reserves the Pi token before compute provisioning. A failed registration leaves the reservation for retry without creating a VM. Lifecycle operations for one reservation use a shared lock. Parking retains both credentials. Destruction records its intent, revokes the model key, then asks the driver to remove compute. Failed revocation prevents resource removal; failed removal retains the Pi token until cleanup succeeds. The raw drivers refuse destruction while a model key remains.

### Guest Pi runtime

`SandboxPiRuntime` configures the image’s `/usr/local/bin/orbit-pi-server` binary as the managed `orbit` user. It writes only the reservation’s Pi token and model key into private guest files. Its model provider is `orbit-sandbox`, with the fixed URL `http://127.0.0.1:8317/v1`. The host relay remains a prerequisite. Set `compute.pi.models` to the supported model descriptors before preparing a workspace. Sandbox sessions use this provider even when a task names another provider.

Runtime preparation checks the reservation and checkout, refuses foreign files or changed credentials, and confirms authenticated Pi health. Repeating preparation keeps a healthy service running, so existing sessions remain available. The image must contain the Pi binary and the managed user with sudo; it must not contain `orbit-worker` or subscription credentials.

### Model relay on an Incus host

An Incus host can set `model_proxy_origin` to a fixed HTTP(S) origin on loopback or `10.44.0.0/16`. It must match the endpoint where the Gateway registers model keys. Each reservation keeps that origin. The agent creates an owned Caddy user service bound to that group’s bridge address on port `8317`. Only the operator address can enter, and only model API paths pass through. Management paths and other HTTP methods return `403`. The relay forwards the group key unchanged and has no shared credential.

This needs the host’s Caddy binary and an existing service manager for the compute account. It does not enable a user service manager or change host firewall rules. The Incus ACL permits only the operator to reach its own bridge on this port. Parking retains the relay and key; resume checks the recorded origin; destruction stops the service and removes its owned files before removing the network. Drift refuses mutation.

Guest preparation uses systemd socket forwarding from `127.0.0.1:8317` to the owned bridge. It verifies model-key authentication through that path before reporting readiness. A disposable connectivity proof remains a prerequisite for enabling this host option.

### Review retention policy

`TaskSandboxLifecycle::review` records the start of each review wait before changing compute. Incus has a five-minute grace period when there is no capacity waiter. A capacity waiter ends that grace immediately. Repeated calls keep the original deadline. A reservation with `preview` enabled stays running. Changing it to a preview after parking resumes it through the same credential checks.

Incus retains its stopped snapshot until resume or destruction. Failures retain the deadline and ownership for retry. Preview retention does not prevent explicit merge cleanup.

Review timing, confirmed parking time, and VM power are stored separately. A confirmed activation clears review timing for the next cycle. A failed activation retains it. Resume intent is recorded before the driver runs and stays until running power is confirmed, so an uncertain resume retries restoration instead of provisioning.

The scheduler reconciles review retention after publication and on later ticks. It restores Incus compute before fetching or starting resumed work. Eligible VM review resumes are attempted before new todo claims. A compute failure remains visible in `capacity_wait_reason`; it cannot start an agent through a shared workspace.


## Task workspace cleanup

Merge and cancellation remove the workspaces of Orbit-lane groups through their sandbox reservations. A [task VM](#destroy-a-task-vm) workspace is a normal Instance, and Orbit removes it as it removes a shared workspace. Under the group admission lock, Orbit checks the group, Project, Instance, reservation, and compute host. It refuses foreign group references and unexpected live Routes, Processes, Schedules, or database connections. Guest checkout paths never reach host source inspection or deletion.

For an Orbit Incus pair, Orbit revokes the model key, destroys owned compute, and confirms destruction before deleting the workspace row and clearing its task references. A failed operation retains ownership for retry. The reservation remains as audit history. A foreign workspace, Node, role, or live resource reference refuses cleanup.

The sweep retries reservations with no Instance when their group has ended, has been deleted, or has already recorded destruction intent. It does not adopt unrecorded host resources or start cleanup of an active group. Failed retries use the workspace sweep's time budget and backoff.

## Declared workload nodes

An Orbit subtask can declare `topology: ["app-dev", "app-prod", "app-prod-2"]`. The list names distinct workload nodes; omit it or use an empty list for the default operator and test Gateway. Subtask creation, group creation, and stored task definitions preserve this declaration. Only a subtask that has not started can change it.

Before dispatch, Orbit reserves the additional VM capacity, starts the pinned workload images on the group's network, and enrolls them with its private test Gateway. Each requested node must pass its native readiness check before the agent starts. A missing image, full budget, or failed enrollment leaves a visible wait. Workload nodes never join the live fleet. Resume repairs incomplete native enrollment before verifying the recorded expanded inventory. Cleanup includes every added node.

A reviewer’s `topology_requested` fallback records `app-dev` and `app-prod` as requirements on the current subtask. It uses the owned sandbox and its private Gateway. Orbit waits for capacity and fresh doctor readiness before resuming the reviewer. Repeated requests keep existing workload nodes. Web-lane groups refuse this Orbit-only request and continue in their workspace.

Native enrollment gives each workload a fresh SSH host key and records its ownership outside the shared checkout. Retries keep that key and the native Node identity. The private Gateway pins the key, provisions the requested role, and grants operator access through Orbit. Each guest must match the recorded source, subnet, and Node inventory before doctor can admit a turn.

Adding a workload node requires host images from the sandbox’s recorded template and the same pinned pair images. A host configuration change cannot replace a running group’s template. An incomplete expansion retries its recorded images; it does not adopt new configured images. Workload commands require the complete source descriptor to match the Project repository and default branch.

## Saved pair identity

The saved Orbit pair needs its network identity updated after cloning. The guest helper accepts either the existing four-Node topology or exactly a Gateway and a roleless operator. For the pair, it refuses additional Nodes or operator roles before changing addresses. It preserves private addresses, keys, DNS policy, and other settings while replacing the stored SSH addresses and Gateway endpoints.

## Sandbox power

Task group responses report `sandbox_power` separately from task status. It is `running`, `stopped`, or `destroyed` only when the owned reservation confirms that state. Shared groups, missing ownership, and transitions return `null`. For a [task VM](#task-vms), it is `running` while the VM is `ready` and `destroyed` once it is `destroyed`. In the other states it is `null`, and the group's wait reason names the state. Destroyed reservations remain visible after workspace cleanup. The CLI shows this value as **Sandbox power** for VM groups.

## Request a preview

Set `preview: true` when creating or updating a task group to keep its VM running during review. The default is `false`. Preview intent can change while a group is in backlog, waiting, or active; ended groups refuse it. Title, brief, and status updates keep their existing restrictions. Shared groups store the intent without changing shared-host power. Phase 1 does not park task VMs, so preview does not change them.

Use `orbit tasks:create --preview` or `orbit tasks:update <group> --preview`. Use `--no-preview` on update to release the preview. The two update flags are mutually exclusive. The API accepts JSON booleans; omitted updates preserve the current intent. A successful update stores intent. The next scheduler reconciliation changes compute, and `sandbox_power` reports the confirmed observation. Capacity and restore failures remain visible.

## Saved source templates

Set `orbit_source_template` on an Incus host configuration to bind its Orbit images to a saved source template. Allocation requires the template repository and default branch to match the Project and pins the descriptor on the reservation. The descriptor contains a template UUID, repository URL, default branch, and source commit. Each image and the dedicated source volume's `ready` snapshot carry that same identity. The source volume must have no attachments. A template is published under a new UUID; it is never updated in place.

The host validates the complete template before creating resources. It copies the snapshot into the group's worktree volume with group ownership in the create request. A retry accepts only that group's volume with the same template identity. It does not overwrite group work or adopt an unrelated populated volume. Requests without a template still create an empty volume for the existing source-initialization path.

This copy protocol is an internal building block. Image sanitation, source adoption, pair retargeting, and runtime readiness must all pass before a scheduler claim can use a saved pair. VM claims remain gated until the full lane is proven.

### Verify cold-build inputs

`bin/sandbox-template-inputs --check` validates an offline input set before candidate construction. It accepts a JSON object on standard input with exactly `root`, `packages`, `tools`, `source`, and `composer`. `root` is an absolute directory. `packages` is a nonempty list; the other three inputs are single objects. Each object contains exactly `file` and `sha256`. File names are relative to `root`; parent traversal, symlinks, duplicate paths, and nonregular files are refused. Package names must end in `.deb` and must encode an epoch separator as `%3a`, rather than a literal colon, because APT can silently ignore local paths containing a colon.

The command verifies every SHA-256 digest and checks both gzip tar archives without extracting them. Source entries must remain inside the source root. Tool entries are limited to the Node, Vite+, and pnpm trees under `opt/orbit-image` and the Bun, Pi server, and Orbit Agent binaries under `usr/local/bin`. Links cannot escape those boundaries. Hard links must refer to an earlier regular file. Duplicate entries, link ancestors, devices, FIFOs, privileged mode bits, and oversized archives are refused. Success returns file hashes and archive counts; errors return a fixed code without input paths or file contents.

Input verification does not install packages, create a candidate, certify upstream provenance, or publish an image. The builder must obtain packages and tool checksums from trusted sources, import the pinned commit's green CI baselines, and verify the same inputs again in the guest before use. Keep package acquisition outside the guests. Install the closed package set with an empty APT index and no external source; keep removals and downgrades disabled. Complete native enrollment and the publisher's guest audit before marking a template ready.

Cold tool inputs include the pinned pnpm package under `opt/orbit-image/pnpm`. The builder seeds one Vite+ home, including its Node and package-manager caches, and publishes the same managed entry points used by native app-role convergence. Bun lives under `/opt/orbit/bun`. Image audit verifies these entry points as the managed user from its home before publication. A blank workload image must support native role convergence without replacing its tool layout.

### Build a cold candidate

`bin/sandbox-template-build --plan` verifies inputs and refuses existing candidate resources. `--prepare` allocates a new isolated pair through the production Incus helper, installs the pinned offline inputs, creates the managed `orbit` account, and verifies the shared source and CI baseline hashes. `--converge` installs public DNS upstreams in each owned guest, bootstraps the private Gateway, and enrolls its roleless operator through native Orbit commands. Public DNS uses `1.1.1.1` and `9.9.9.9` with the systemd resolver default route. More specific fleet DNS routes remain authoritative. Guest DNS never depends on access to the Incus host. A foreign resolver file or failed resolver restart refuses readiness.

Each mode accepts the same JSON object with the required fields `project`, `pool`, `sandbox_id`, `budget`, `subnet`, `blocked_networks`, `base_image`, `inputs`, and `source_manifest`. `workload_roles` is an optional ordered list from `app-dev`, `app-prod`, and `app-prod-2`. Omission builds only the pair. The budget must cover all requested guests.

`project` must be a dedicated, owned `orbit-sandbox-proof-<name>` project. `base_image` is an imported x86_64 VM fingerprint. `inputs` uses the offline verifier's schema. `source_manifest` contains exactly `source_template`, `ci_run`, `projects`, and `sha256`; the final hash must identify the source archive.

The source manifest pins Composer lock hashes for the root and all five PHP projects, CI graph hashes for those five projects, and Bun lock hashes for the annotation package, web app, and Pi server. It records the green CI run used to prepare those graphs. Acquire and verify upstream image, package, tool, and CI artifacts before construction; a supplied hash or run ID alone is not proof of origin. Tool inputs include Node 24.21.0, Vite+ 0.3.0, pnpm 10.33.0, Bun 1.4.2, and compatible pinned Agent and Pi binaries. The fresh base must have no `orbit` or `orbit-worker` account and no UID or GID 1002.

Preparation refuses reused resources and a populated source volume. It retains a partial candidate after failure for inspection and explicit owned cleanup. Convergence accepts only that candidate's completed preparation receipt and matching inputs. Commands serialize with publication for the pair and template.

Before native convergence, the builder changes the standard Ubuntu archive and security source URLs from HTTP to HTTPS in every prepared guest. It preserves custom repositories, suites, components, comments, and file permissions. It refuses symlinked or writable source paths before changing any file. Retrying this step preserves sources already using HTTPS. Native convergence needs permitted public egress for package-source verification; the builder never adds host firewall rules. Complete any required host-policy approval separately.

Convergence confirms the pinned source commit, the isolated operator profile, and active Gateway and roleless operator inventory. Publication repeats that native readiness check. No builder mode promotes an alias, enables claims, or changes a live Gateway.

Before each agent dispatch, admission compares the private Gateway version with the owned branch commit. It refreshes Gateway dependencies, migrations, and services only when the version differs, then refreshes native pair prerequisites and requires fresh doctor readiness. A running baseline is polled before this preparation can modify the checkout.

The builder can prepare blank workload guests alongside the pair. All guests share the pinned source and verified offline inputs, including Docker prerequisites for workload roles. Convergence enrolls only the operator. Publication audits each workload for prerequisites and absence of enrollment state, then publishes every requested role with the same immutable source descriptor. Pass the same `workload_roles` to the publisher. Adding roles requires a new template; published templates cannot be extended in place.

Task runtime preparation uses the sandbox’s recorded image roles as its exact private Node inventory. For an expanded resume, it refreshes the private Gateway branch runtime and retries owned workload enrollment before strict retargeting. It retargets every recorded peer, requires an active workload role on each declared Node, and refuses foreign or extra Nodes. The operator stays roleless. Template publication keeps the stricter default-pair check.

### Prepare a copied source volume

The internal `guest-template-source.py` helper prepares a disposable source copy before publication. Run it as the managed user, with a JSON request on standard input containing `checkout` and `source_template`. The descriptor has exactly `id`, `repository`, `base`, and `commit`. The copy must already contain `.git/orbit-template-candidate.json` with that descriptor. The host builder must verify its own candidate volume and exclusive attachment before writing this marker.

Preparation refuses task-owned checkouts, changed source, mismatched identity, unsafe Git configuration, and external Git metadata. It checks the pinned commit before mutation, normalizes local and remote branches to the default branch, and writes the template marker without replacing it. Repeating the same request verifies the published state. Dependency caches remain intact. Errors return a fixed message without command output or credentials.

This helper prepares source only. It does not certify ignored files, dependency provenance, CI baselines, guest credentials, or image sanitation. The complete image publisher must verify those prerequisites before publishing the source snapshot and private pair images. Do not use the helper on a live workspace or a promoted template.

### Publish a disposable candidate

`bin/sandbox-template-publish --plan` validates a candidate without changing it. `--apply` prepares its source, stops every candidate guest, and publishes a private VM image for each recorded role and a dedicated source volume with a `ready` snapshot. Both modes accept one JSON object on standard input and return one JSON object. The required fields are `project`, `pool`, `sandbox_id`, and `source_template`. The optional `workload_roles` list must match the builder request. The template descriptor is the same as the source helper's descriptor.

The builder must mark every candidate VM and its source volume with `user.orbit.template.candidate=<template UUID>`. The names and compute ownership must match `sandbox_id`. Only the recorded candidate guests may attach the source volume. The candidate must be running, have only its root disk, source disk, and group network, and contain no task-source or Pi/model runtime state. The command refuses existing template volumes or matching image identities. It never changes a promoted alias or accepts an unmarked pair.

The publisher runs independent guest audits together, bounded by the requested inventory of at most five guests. Each guest command has a 60-minute limit for scanning mounted source and offline inputs. It waits for every audit before changing source, stopping guests, or creating publication outputs.

The command checks guest prerequisites and known credential locations before source changes. It refuses GitHub tokens in guest files or process environments, subscription credentials, and the shared worker account. This audit complements a clean image build; it cannot establish provenance for arbitrary candidate files. The cold builder must still supply verified packages, tools, dependencies, and CI baselines.

Publication returns pinned image fingerprints and the source descriptor for host configuration. It leaves the candidate stopped. On failure it removes only output resources carrying this operation's exact template identity, then audits their absence. It retains the candidate for diagnosis. A host crash can leave owned outputs; inspect them before retrying. Existing output identities are refused rather than overwritten. This command does not enable claims or change host firewall policy.

### Adopt a template checkout

A source template includes `.git/orbit-sandbox-template.json` with the same descriptor as its reservation. Before first use, the guest checks that marker, the pinned commit, the default branch, and a clean tracked and untracked tree. Ignored dependency caches can remain. The template uses a real local Git directory, contains only the default local and remote branches, and exposes only its canonical origin URL. Git includes, custom filters, alternate object stores, and replacement history are refused.

After validation, the guest records group ownership without replacing an existing marker. It fetches the task branch directly from GitHub and selects that branch, or the current default branch for new work. The template commit is the seed; the imported branch supplies the task's starting commit. Repeated preparation checks the recorded template identity and preserves local commits, dirty files, and dependencies. A populated checkout without the matching template marker remains refused.

### Prepare the isolated pair

Pair preparation checks group source ownership before changing the test Gateway. It repairs the saved Gateway inventory and the operator's WireGuard endpoint on the group's subnet. The Gateway ships copies of the same repair helpers tested by the E2E harness; its quality check verifies that they match.

The test Gateway validates Composer manifests and lock files. It refreshes dependency autoloaders and installs dependencies when lock files changed or dependencies are missing. It sets the private Gateway version to the verified branch commit, clears branch runtime caches, and runs migrations against its own SQLite database. It repairs Caddy’s checkout access and restarts PHP-FPM. After peer endpoints and workload enrollment are ready, it refreshes the pair’s native prerequisites before checking doctor health.

Prerequisite preparation validates the exact recorded private Node inventory, the Gateway at `10.44.0.1`, and the roleless operator at `10.44.0.3` before mutation. It converges both Nodes through the native Node Agent runtime, rebuilds the Gateway’s Caddyfile, and publishes private DNS from current branch intent. Agent convergence verifies the pinned public release checksum and configures the service and its private per-Node secret. Any failed converge refuses runtime readiness and leaves the reservation available for retry. Preparation also enables the native VPN DNS backend for boot, so it remains available after park and resume.

Pair preparation provides an owned `orbit` launcher in the operator’s `~/.local/bin`. It runs the CLI from the group’s branch checkout, so task commands use that branch’s behavior. A foreign launcher or linked launcher directory refuses preparation.

The operator then uses its isolated Gateway profile to list the active Gateway and roleless operator. Preparation reports success only when both guests confirm the same branch commit and the Gateway API reports that version. Admission also requires fresh node, role, and firewall doctor health for every recorded Node. Cold-image convergence applies the same native Agent convergence and pair doctor checks before marking a candidate ready. It never routes these commands through a shared host or a task VM Node.

### Admit an Orbit sandbox claim

`ORBIT_SANDBOX_ORBIT_CLAIMS_ENABLED` defaults to false. Keep it off until the complete Orbit lane and host connectivity policy are proven. With the switch enabled, an Orbit claim needs local pair images, a pinned source template, the private Pi endpoint, the model relay, and Pi model configuration. `ORBIT_SANDBOX_MODEL_PROXY_ENABLED` turns on sandbox model credentials and defaults to false. `ORBIT_SANDBOX_PI_MODELS` is a JSON array of the models that Pi offers in a sandbox, and defaults to `[]`. A claim fails with `compute.not_ready` unless local compute and the model proxy are enabled and the model list is not empty. Other Projects use [task VMs](#task-vms).

Provisioning reserves and attaches an owned workspace before preparing source, the isolated pair, and Pi in that order. It holds the group's execution lock during preparation. A failed step retains the reservation and workspace for retry; it never adopts an unrelated workspace or falls back to shared compute. Only successful preparation returns the workspace to the scheduler, which runs the Project's baseline setup and check before starting an implementer.

