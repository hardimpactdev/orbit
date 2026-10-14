---
title: "Compute drivers"
description: "Task VMs for every Project and their UpCloud overflow."
covers:
  - apps/gateway/app/{Domain/TaskVms,Infrastructure/TaskVms,Jobs/TaskVms,Actions/TaskVms,Domain/Compute}/**
  - apps/gateway/app/{Models/TaskVm.php,Console/Commands/PrepareTaskVmHostCommand.php,Console/Commands/PrepareTaskVmHubCommand.php,Console/Commands/BuildTaskVmImageCommand.php,Console/Commands/BuildUpCloudTaskVmImageCommand.php,Console/Commands/BuildTaskVmWorkspaceTemplateCommand.php,Console/Commands/SyncTaskVmImageCommand.php,Console/Commands/RetireTaskVmHostCommand.php,Console/Commands/ResolvesTaskVmHostNode.php}
  - apps/gateway/config/task_vms.php
  - apps/gateway/resources/task-vms/**
  - apps/gateway/database/migrations/*{create_task_vms_table,add_model_proxy_origin_to_task_vms,add_warm_pool_to_task_vms,add_upcloud_to_task_vms,add_size_to_task_vms,add_topology_to_task_vms,create_jobs_table,create_failed_jobs_table,create_task_sandboxes_table,add_fleet_enrollment_to_task_sandboxes,add_pi_ready_at_to_task_sandboxes,close_checks_of_destroyed_task_sandboxes,drop_task_sandboxes}.php
---

# Compute drivers

Every task group runs in its own [task VM](#task-vms), which is a normal `app-dev` Node of the dev Cluster. This holds for every Project, `orbit` included. See [Orbit Project](#orbit-project).

Task VMs are off until you [configure them](#configure-task-vms). Until then no task group can start. [ADR 0200](/decisions/0200-run-each-task-group-in-its-own-sandbox-vm) records the design.

## Task VMs

A task VM is an Ubuntu 26.04 VM on an Incus host. It boots from the [base image](#build-the-base-image), which is the same on every host and holds everything that enrollment installs on an `app-dev` Node. The Gateway creates it for one group, enrolls it as an `app-dev` Node, and destroys it when the group ends. After enrollment, the group uses the same code as a shared group, pinned to that Node. Only Incus hosts run task VMs. UpCloud follows in Phase 3 of [ADR 0200](/decisions/0200-run-each-task-group-in-its-own-sandbox-vm).

### From claim to workspace

A claim moves a group through these steps. Steps 2 to 5 are queued [jobs](#jobs). When the host keeps [warm VMs](#keep-warm-vms), the claim takes one and runs step 5 itself, and step 6 follows in the same claim.

1. The scheduler claims a group. `AllocateTaskVmAction` decides the VM's [size](#size-by-declared-topology). It takes a warm VM or records a new `provisioning` row, and queues `ProvisionTaskVm` for a new row.
2. `ProvisionTaskVm` launches the VM from the base image with cloud-init user-data. Its disk is a ZFS clone of the image. On beast, the launch takes under a second. Cloud-init is done about 10 seconds later.
3. `EnrollTaskVm` waits for cloud-init. Then it reads the guest's IPv4 address, its ed25519 host key fingerprint, and the [image manifest](#skip-what-the-image-holds) from the host through `incus`.
4. `EnrollTaskVm` runs the normal [Node provisioning](/reference/node-provisioning#add-a-node) for the Node `tvm-<id>`, through the host as [jump host](/reference/node-provisioning#enroll-through-a-jump-host).
5. `PrepareTaskVmRuntime` creates the group's CLIProxyAPI key and starts Pi as `orbit`. See [Run Pi on a task VM](/reference/pi-server#run-pi-on-a-task-vm). The row becomes `ready`.
6. On the next claim, Orbit creates the [task workspace](/reference/tasks#task-vm-workspace) on the VM's Node. It is a normal Instance `task-<group id>`. A routed workspace gets the private Route `task-<id>.<project>.<dev-tld>`.
7. The claim starts the baseline check. Then the same claim projects the Route.

A workspace starts from the Project's [workspace template](#start-the-workspace-from-a-template) when the host has one.

#### Start the baseline check before the Route

The baseline check needs the checkout, its `.env`, and the PHP packages of the Instance's PHP version. It does not need the Route: Pest's browser tests serve the application from their own server.

So a routed workspace on a task VM stops before its Route. After the source, workspace creation runs these steps, in about 1.5 seconds with the current base image:

1. It classifies the source and records the PHP version.
2. It writes `APP_URL`, and for a new `.env` the application key and name, as it does for every development Instance.
3. It installs the `app-dev` PHP packages of that version. On a task VM they are in the [base image](#build-the-base-image), so the step only checks them.

The workspace stays `source_resolved`, and its Route stays `pending`. The claim attaches it to the group and starts the baseline check. Then, in the same claim, it projects the Route as Instance creation does: the certificates, Caddy, PHP-FPM, the Router, and DNS, under the projection lock. The workspace becomes `active`.

The setup steps of the check can change files while the Route grants Caddy access to the checkout: `vp build` rewrites the build output, and Composer republishes assets. So the access walk ignores an entry that vanishes during the walk, and fails on any other error. It sets each directory's default ACL before its access ACLs, so a file that setup creates during or after the walk inherits the right entry: readable by Caddy in the Web root, denied elsewhere.

A Route that fails at the claim logs `The task workspace Route failed after the baseline check started; Orbit tries again before the implementer starts.` Work that uses the Route waits for it: before the first implementer or the final review starts, Orbit tries the Route again. Each failure there counts as a communication failure. After the fifth, the subtask asks for assistance with `The task workspace Route could not be finished, so no agent started.` and the error. So no agent, reviewer, preview, or pull request ever uses a workspace whose Route failed. Cancelling the group removes the workspace with its pending Route.

`AllocateTaskVmAction` [places](#place-task-vms) the VM on a host. The row gets the name `tvm-<row id>`, the next free WireGuard address in the reserved range, and a random Pi token. It also records `claimed_at`, the moment of the claim.

Cloud-init creates the user `orbit` with passwordless sudo and the Gateway's SSH key, and puts it in the system group `incus-admin`, so a [nested topology](#prepare-the-topology) needs no new login. It does nothing else, because the base image already holds `openssh-server`, Chromium's system libraries, the workspace tools, the software of the `app-dev` role, the stable Rust toolchain, the Node agent, and Pi. Enrollment skips the programs that the image already ran, and finds the rest of that software installed. The Project's setup steps install the rest, for example its Playwright browsers.

`EnrollTaskVm` checks the VM every second, for up to 2 minutes in one run, and then again 15 seconds later. Once the Incus agent in the guest runs, one check waits in the guest for up to 30 seconds with `cloud-init status --wait`, so the job hears at once when cloud-init is done.

The job goes on when cloud-init reports `done` with no errors and the host reports the guest's bridge address. It reads the guest values only then, before any code but cloud-init has run in the VM. A guest reboot shows the VM stopped for about a second, so a stopped VM gets a second reading 5 seconds later before enrollment fails.

Provisioning links the new Node to the row when it creates the Node record, before any convergence. So the Node is a task VM Node from the start: the fleet rollout skips it, and it gets no Orbit CLI. It never hosts a [Metrics exporter](/reference/metrics#exporter-selection), because the [hub filter](#limit-fleet-traffic-on-the-hub) keeps the Metrics Node out. So its enrollment skips the fleet Metrics reconcile, which converges exporters on every Node and took about 80 seconds on the live fleet.

Each step of the jobs logs one line `Task VM step done.` in the Gateway log, with the VM name, the step, and its seconds. The steps are `create`, which counts from the claim, `boot`, `node-converge`, `apt-tool-manager`, `roles`, `router-lan-ingress`, `dns-selection`, `activate`, `agent`, `enrolled`, `model-key`, `pi-current` or `pi-upload`, `pi-files`, and `pi-server`. The last line, `claim-to-ready`, has the seconds from the claim to `ready`. A warm VM logs other first and last lines, as [Keep warm VMs](#keep-warm-vms) describes. The workspace creation then logs `workspace-template`, `workspace-source`, `workspace-prepare` for a routed workspace, and `workspace`, which has the seconds of the whole creation. The baseline check starts after `workspace`. The Route then logs `workspace-route`, with the seconds of its projection, which runs while the check runs.

#### Skip what the image holds

The [image build](#build-the-base-image) runs the programs that enrollment runs on an `app-dev` Node: `bootstrap`, `caddy-package`, `app-dev-prerequisites`, `vp-manager`, and `composer-manager`. It also runs the `app-dev` PHP programs that an Instance's Route runs, as described below. It records the fingerprint of each one, the SHA-256 of its exact arguments and input, in `/var/lib/orbit-task-vm/image.json` in the image. `EnrollTaskVm` reads that manifest through the host before enrollment. Enrollment then skips a program when it renders the same fingerprint under the same name, because the image ran that exact program and each program is idempotent. It logs `Task VM program skipped: its image ran it.` for each one.

Every other program runs, with the warning `Task VM image did not run this program; enrollment runs it.` and its reason. That happens when the Gateway's code or its SSH key changed the program since the build.

An image without a manifest, such as one built before manifests, or with a manifest the Gateway cannot read, makes enrollment run every program, with the warning `Task VM image has no usable manifest; enrollment runs every program.` So an older image is never wrong, only slower.

A skipped program fetches nothing at enrollment. The apt packages, Bun, Vite+ and its Node, and Composer are the versions that the last image build fetched. The nightly build refreshes them. Enrollment still renders and applies everything that is specific to the VM: the host key, WireGuard, the firewall, Caddy, DNS, the agent and its secret, and Pi. Only task VM enrollment reads a manifest, so every other Node runs every program.

The image also runs `app-dev-php-source` and one `app-dev-php-<version>` program for each PHP version an Instance can select, now `8.5` and `8.4`. They add the pinned Sury source and install the `app-dev` PHP packages, with the same code that installs them for an Instance. Enrollment never runs these programs, so the manifest only records them. The Instance's own PHP step on the VM finds the source current and every package installed, and skips the source refresh, as [PHP runtimes](/reference/php-runtime#development-runtime) describes.

Last, the image runs `rust-toolchain`. It installs the stable Rust toolchain for `orbit` with rustup's minimal profile, plus clippy and rustfmt, as CI checks the Node agent. Each build takes the stable release of its day. Enrollment never runs it either.

Enrollment reaches the guest through the host as jump host until WireGuard is up. Those commands share one SSH connection, as commands to a Node over WireGuard do. Its name hashes the jump host, the target address, and the target's pinned host key. So the next VM at a reused address never gets the old VM's connection.

Until the row is `ready`, the claim returns the group to `todo` with a reason, and tries again on the next tick:

| Reason | Cause |
| --- | --- |
| `Task VM: task VMs are not enabled on this Gateway.` | `task_vms.enabled` is false and the group has no task VM |
| `Task VM: no capacity.` | Every host is inactive or full. See [Place task VMs](#place-task-vms) |
| `Task VM: another placement is running.` | SQLite writer contention exhausted the placement transaction's retries. The next tick tries placement again |
| `Task VM: waiting for a warm VM.` | Every host is full, and at least one of its VMs is a [warm VM](#keep-warm-vms) that is still starting. The claim takes it once it is `warm` |
| `Task VM: provisioning.` | The jobs are still running |
| `Task VM: task_vm.invalid_config: <message>` | A `task_vms` value is invalid |
| `Task VM: destroying.` | Cleanup has started |

These reasons are waits: the group asks for no assistance. A `failed` task VM is no wait. The claim returns the group to `todo` without a wait reason, and the group asks for assistance at once with `Task VM failed: tvm-<id>: <code>: <message> Cancel the group to destroy the VM.` The failure sends one assistance event to Coder when the group starts asking. Later claims skip groups with a failed VM and keep the request. The group gets no other VM: cancel it to destroy the VM.

The Node `tvm-<id>` has user `orbit`, role `app-dev`, the dev Cluster, and the reserved WireGuard address.

### Destroy a task VM

When the group ends, Orbit removes its workspace with the VM. The checkout can live on the VM's [workspace volume](#start-the-workspace-from-a-template), which goes with the VM, so Orbit deletes no checkout in the guest. A cancelled group first pushes its stored approval. Pushes, fetches, checks, and agent turns need the VM `ready`, so they finish before the VM is deleted. Workspace removal on a task VM runs these steps:

1. While the VM is `ready`, it runs the Project's teardown steps in the guest. A removal that an earlier attempt started has run them already.
2. It sets the row to `destroying`, revokes the group's model key, and deletes the VM and its workspace clone.
3. It removes the workspace records offline, as for [a VM that died](#a-vm-that-dies-before-its-group-ends).
4. It queues `DestroyTaskVm`, which removes the Node.

A step that fails keeps the Instance and records the error on the row. The group asks for assistance with the removal reason, and the next removal attempt continues. A task VM workspace never holds a host [discovery topology](/reference/incus-topologies): Orbit refuses to acquire one there, so removal skips the topology release.

Each `tasks:tick` also queues `DestroyTaskVm` for every task VM that is not `destroyed` when its group is `completed` or `cancelled`, no [claim](/reference/tasks#claim-and-provision) of the group is in flight, and no Instance is left on its Node. A claim is over once it starts the group, so a group that ends soon after its claim loses its VM on the next tick. Its unique lock has no expiry, so the tick never queues a second copy while one waits or runs. `DestroyTaskVm` then runs these steps:

1. It sets the row to `destroying` and revokes the group's model key.
2. It deletes the VM. Then it deletes the VM's [workspace clone](#start-the-workspace-from-a-template), because Incus keeps that volume. A VM or a clone that is already gone counts as deleted.
3. It removes the Node offline and with force. This sheds its `app-dev` role and removes its Process rows, WireGuard peer, and private DNS records.
4. It sets the row to `destroyed`.

Orbit deleted the VM itself, so the Node removal does not probe the Node's address first. It also runs no Metrics pass, because a task VM Node never hosts a Metrics exporter.

The removal of other Instances moves a checkout within one filesystem only. [Instance removal](/reference/instance-removal) refuses a checkout on a mounted volume with `cross-device:`.

Deletion clears the row's Pi token after the VM is gone. The Node goes before the row becomes `destroyed`, which also clears the token. A row that is not `destroyed` keeps its Node a task VM Node, so Orbit never sends the Gateway's own Pi token to it. When a step fails, the row stays `destroying` with the error, and the job runs again after 1, 5, and then every 15 minutes. A [parked](#park-and-resume) VM is deleted while it is stopped. Only its workspace removal starts it first.

Keep a host in `task_vms.incus.hosts` until it has no task VM that is not `destroyed`, because destroy needs the host's settings. Node removal refuses such a host with `node.has_task_vms`. It also refuses a host that is still configured, with `node.task_vm_host_configured`, because the other hosts still accept its transfer key. [Retire the host](#add-a-task-vm-host) first. Before it changes any other Node, it removes the Node's [transfer key](#let-hosts-copy-the-image) from every configured host, also when `task-vms:retire-host` did not run. When that fails, it fails with `node.task_vm_image_key_cleanup_failed` and changes nothing else. When it removes a host, it also deletes the host's `destroyed` rows.

#### A VM that dies before its group ends

Workspace removal deletes a dead VM like a live one. Only the Project's teardown steps need SSH to the VM. When they cannot run, removal fails and the group asks for assistance with the reason `Workspace removal failed:` or `Merged pull request cleanup failed:`. When the group is `cancelled`, `completed`, `settling`, or `waiting_for_review`, each tick then also queues `DestroyTaskVm` for that task VM. A group in another state, for example one whose cancel failed while the VM ran, keeps its workspace and its assistance request. Cancel it again: cancel then removes the workspace with the VM and queues `DestroyTaskVm`.

The job reads the VM on its host. While the VM runs, it leaves the workspace to the normal removal. When the VM is absent, or still stopped 5 seconds after a stopped reading, it deletes the VM and then removes the workspace offline:

- It records the normal removal journal, with the source steps marked done, because the checkout is gone with the VM.
- It clears the Route through the normal removal projector. The projector skips the VM's Node for a task VM that is `destroying`. The router withdraws the site.
- It deletes the Instance's Process and Schedule records and the Instance row, and clears the group's removal reason.

Then it removes the Node and marks the row `destroyed`, as above. A removal journal that a normal removal left open is finished the same way. A cancelled group's stored approval is lost with the VM.

### Place task VMs

Placement is serialized by the Gateway's SQLite transaction, not a cache lock. The claim checks for an existing VM, counts occupied slots, and either takes a warm VM or records a new VM in the same transaction. The pool refill counts slots and records new pool rows in a transaction too. SQLite serializes these writes, so concurrent claims and refills cannot both take the last slot or assign the same warm VM. Host image reads happen before the transaction; VM provisioning and runtime preparation are dispatched after it commits.

Each host in `task_vms.incus.hosts` has `max_vms` slots. Every task VM on the host that is not `destroyed` or `failed` takes the slots of its [size](#size-by-declared-topology), also a `warm` or `parked` one: one for a normal VM, two for a medium VM, three for a large VM. The free slots of a host are `max_vms` minus those slots. A `failed` VM holds no slot, so it never blocks placement: its group asks for assistance, and the pool refill destroys a failed pool VM before it counts the free slots. A failed VM of a group can still run on its host until the group is cancelled.

A claim first takes a current [warm VM](#keep-warm-vms). It looks at the active hosts that have one, the host with the most free slots first, and takes that host's oldest warm VM. A warm VM already holds its slot, so a claim can take one on a full host.

Without a warm VM, `AllocateTaskVmAction` places a new VM on the active host with the most free slots. Hosts with the same number of free slots keep their order in `task_vms.incus.hosts`. A host that is not an active Node, or that has fewer free slots than the VM takes, gets no VM. When no host has room for a normal VM, the claim returns the group to `todo` with the reason `Task VM: no capacity.` and tries again on the next tick. For a medium VM the reason is `Task VM: no host has 2 free slots for a medium VM.`, and for a large VM `Task VM: no host has 3 free slots for a large VM.` The group waits until VMs are destroyed or you add slots.

A full fleet does not make every group wait. When the group's Project is opted in to the [UpCloud overflow](#upcloud-overflow) and the overflow is on, a new normal VM goes to UpCloud instead, within its own budget. A medium or large VM never goes to UpCloud.

#### Size by declared topology

At the claim, Orbit decides the size of the group's VM once. It takes the union of the `topology` fields of the group's subtasks that are not cancelled, and the Project's place in `task_vms.medium_projects`. The row stores the size in `size`, and it never changes while the VM exists.

| Size | When | VM | Slots | Warm VM |
| --- | --- | --- | --- | --- |
| `normal` | No subtask declares a topology | The host's `cpus`, `memory`, and `disk` | 1 | Taken when one is ready |
| `medium` | The topology has one test node, or no subtask declares a topology and the Project is in `task_vms.medium_projects` | 4 vCPU, 8 GiB of memory, and a 40 GiB disk | 2 | Never: always a new VM, and never at UpCloud |
| `large` | The topology has two test nodes | 6 vCPU, 12 GiB of memory, and a 60 GiB disk | 3 | Never: always a new VM, and never at UpCloud |

A test node is `app-dev`, `app-prod`, or `app-prod-2`, whatever roles its entry adds. The [test Gateway and the operator](/reference/incus-topologies#declared-nodes) run in every topology and do not count. Each size defines how many test nodes it holds, one in a medium VM and two in a large VM, and the group gets the smallest size that holds its topology. The largest size sets the most test nodes a group may declare.

The sizes come from the nested topology spike on beast. There, the test Gateway, an `app-dev` node, and the operator used about 2.7 GiB together. A large VM that ran four test machines used all of its 12 GiB on the host under IO.

The sizes are caps, not reservations. Incus does not pin the vCPUs, and the guest takes host memory only when it first uses it. Medium and large VMs have free page reporting on their memory balloon, so the host gets back the memory that the guest frees, but not the guest's page cache. Plan for a VM that runs a topology to use all of its memory on the host. Warm VMs hold their slots, so a host fits a large VM only when `max_vms` minus its other VMs, warm VMs included, leaves three slots.

A subtask whose topology needs a larger VM than its group got waits before it starts, with the reason `Task VM: this subtask's topology needs a larger task VM than the group got, and Orbit does not resize it.` This happens when a subtask is added or changed after the claim. Orbit never runs it without its nodes, and never moves the group to another VM. To go on, cancel that subtask, or cancel the group and create it again with the topology declared. See [Orbit Project](#orbit-project).

Placement never puts more VMs on a host than its `max_vms`, not counting `failed` ones. The Gateway's SQLite placement transactions serialize claims and pool refills, so two claims cannot both take the last slot or the same warm VM, and a refill cannot race a claim for capacity. Lowering `max_vms` below the number of VMs on a host removes no VM. The host gets no new VM until enough VMs are destroyed.

With one host, placement is the same as before: the host takes VMs until it is full. To add a host, see [Add a task VM host](#add-a-task-vm-host).

### Keep warm VMs

A claim that takes a warm VM gets a ready VM in seconds, instead of about 35 seconds for a new one. A warm VM is a task VM that Orbit created, booted, and enrolled as a Node before any group needed it. Each host keeps `warm_vms` of them. The default is `0`, so a host keeps none until you set it. See [Configure task VMs](#configure-task-vms).

A warm VM is a `tvm-<id>` Node with user `orbit`, role `app-dev`, the dev Cluster, its own WireGuard address in the reserved range, and the Node agent. Its row has no group, no Pi token, no model key, and no `pi-server` Process. A pool row is any task VM row without a group: a warm VM, or one that Orbit is creating, failing, or destroying for the pool.

#### Fill the pool

Every minute, the Gateway scheduler queues the job `RefillTaskVmPool`, while task VMs are enabled and a host sets `warm_vms`, or while a pool row is not `destroyed`. A claim that takes a warm VM queues it too, and so does a VM that becomes `warm`. For each configured host whose Node is active, one run does these steps:

1. It reads the host's `orbit-task-base` image and the `volatile.base_image` of each instance. It records that image on each warm VM.
2. A warm VM from another image becomes `destroying`. So does a warm VM whose instance is gone.
3. In the placement transaction, it sets every `failed` pool row to `destroying`.
4. It counts the warm VMs and the pool VMs that still start. It sets the oldest warm VMs above `warm_vms` to `destroying`.
5. A pool VM that still starts is never stopped. A later run destroys it once it is warm.
6. Below `warm_vms`, it records new pool rows, up to the host's free slots.
7. It queues `ProvisionTaskVm` for each new row, and then `DestroyTaskVm` for every pool row that is `destroying`.

A `destroying` row holds its slot until it is `destroyed`. So a new row exists only within the host's `max_vms`, and the new VM can start before the old one goes. The task VM queue runs one job at a time, so the order matters. After a base image change, the launch of the new warm VM comes first in the queue, and the old VM's destroy follows. A host whose only free slot a `destroying` VM holds records the new row on a later refill, after the destroy.

A new pool row runs the normal `ProvisionTaskVm` and `EnrollTaskVm`. Then `PrepareTaskVmRuntime` sets it `warm` and records `warmed_at`, and runs no group step. The next refill records its image, so a claim can take it.

#### Claim a warm VM

The claim reads the `orbit-task-base` image of each active host that has a warm VM. It takes a warm VM only when the VM launched that image and its Node is active. The claim reads a host's image over SSH at most every 10 seconds. A build or sync that moves the alias makes the next claim read it again. A host whose image list cannot be read keeps its warm VMs for a minute, and the claims place new VMs instead.

In the placement transaction, the claim then makes these changes on the row in one update, and only when the row is still `warm` without a group:

| Field | Value |
| --- | --- |
| `group_id` | The claiming group |
| `state` | `provisioning` |
| `claimed_at` | Now |

Then it gives the row a new random Pi token in the same transaction. After the transaction commits, the claim dispatches one `PrepareTaskVmRuntime` and queues `RefillTaskVmPool`. The claim returns while the row is `provisioning`; it does not prepare the runtime inline in `tasks:tick`. New claims queue no `EnsureWarmClaimReady` guard.

Horizon runs `PrepareTaskVmRuntime` on the `task-vms` queue, as for a new VM. The job creates the group's CLIProxyAPI key, writes the Pi files, and starts the `pi-server` Process. The steps take about 1.5 seconds when Pi is current, apart from the wait for the worker. If the VM has an older Pi, the same job uploads the pinned Pi. Once the row becomes `ready`, a later claim creates the workspace, as for a new VM.

The job uses the normal preparation limits. It waits up to 60 seconds for another model key operation. Each CLIProxyAPI request has 5 seconds. Each SSH step for the Pi check and files has 60 seconds, and a Pi upload has 600 seconds. The `pi-server` commands use the normal Process limits. Its whole run has a timeout of 900 seconds. A failure retries the job twice, 30 seconds apart. The steps are idempotent, so a repeat changes nothing.

SQLite commit and Redis dispatch are not atomic. Every subsequent allocation of an existing claimed warm VM that is still `provisioning` attempts to dispatch `PrepareTaskVmRuntime` again. The row's `warmed_at` distinguishes it from a new VM that still needs provisioning and enrollment; `claimed_at` is set for both new group VMs and claimed warm VMs. Allocation dispatches preparation only when both timestamps are present. A new VM reaches preparation through enrollment, not allocation. Returning the existing row alone is not enough: a crash after commit but before dispatch must leave work that the next claim can recover.

Laravel uses `ShouldBeUnique` to lock preparation for each VM. It suppresses dispatch while a preparation job is queued or running. Preparation acquires Laravel's native unique lock on the same job object that it submits. A confirmed connection-resolution failure before submission releases only that job's lock owner, so the next claim can retry without deleting a replacement owner's lock.

After submission begins, an exception may mean Redis accepted the job but its response was lost, or a post-push event failed. Recovery retains the lock in these ambiguous cases: the accepted job completes or fails normally and releases its own lock; if no job was accepted, recovery waits for the existing one-hour lock expiry.

If a process dies after acquiring the lock but before enqueueing, the lock's existing one-hour expiry allows a subsequent claim to dispatch again. The single `task-vms` worker serializes jobs, and preparation is idempotent. A recovered job either makes the row `ready` or exhausts its retries and marks it `failed` with group assistance. Recovery uses the ordinary preparation job, not a delayed guard.

A warm claim logs `queue` first, which counts from the claim to the worker's start of the group's steps, and `warm-claim-to-ready` last, with the seconds from the claim to `ready`. A VM that becomes warm logs `warm`, with the seconds from its row to `warm`.

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
- A warm VM's Node serves no Instance. Any Instance on it fails with `task_vm.foreign_instance`.
- The fleet rollout skips it, and it has no Metrics exporter, as for every task VM Node.
- Node removal refuses a host with a pool row that is not `destroyed`, with `node.has_task_vms`. `task-vms:retire-host` refuses it with `task_vm.host_in_use`.

#### Security of a warm VM

A warm VM is on the same task bridge as any task VM, behind the same bridge ACL and the same [hub filter](#limit-fleet-traffic-on-the-hub). From enrollment on, it has its Node identity: its SSH host key, its WireGuard key and address, and its Node agent secret. It holds no secret of a group until a claim: no Pi token, no CLIProxyAPI key, no `models.json`, and no workspace. Pi does not run on it.

The Gateway connects to Pi on a task VM only with the row's own token, and a warm VM's row has none, so the Gateway's own `ORBIT_PI_TOKEN` never reaches it either. The claim creates the Pi token, and `PrepareTaskVmRuntime` creates the model key and writes both to the VM. When the group ends, the VM is destroyed as usual. No VM serves a second group.

### Start the workspace from a template

A new task VM has empty caches, so a Project's setup steps download everything again. For mealou on beast they took about 36 seconds: `composer install`, `vp install`, a Playwright download of Chromium, and `vp build`. A workspace template holds their result, so the same steps find little to do.

Each host keeps one template for each Project whose groups run on task VMs, `orbit` included. There is no setting: a Project without a template works as before. A template is an Incus block volume `tvm-template-<project id>-<time>` in the host's project and pool. Its ext4 filesystem holds the Project's apps directory on a task VM, such as `/home/orbit/apps/mealou`:

| Path in the volume | What it holds |
| --- | --- |
| `workspace` | A checkout of the default branch, after the Project's setup steps and one run of its task check: `vendor`, `node_modules`, the build output, and the caches that the check fills |
| `.orbit-cache/ms-playwright` | The Playwright browsers |
| `.orbit-cache/composer` | The Composer cache |
| `.pnpm-store` | The pnpm store. pnpm puts it at the root of the filesystem by itself, because the default store is on another filesystem |

#### Claim with a template

When Orbit creates the workspace on a task VM, it runs these steps before source preparation:

1. It lists the storage volumes of the host's project, and reads the base image that the VM booted (`volatile.base_image`). It takes the newest ready template of the Project from the current `repository_url` and that base image.
2. It clones the template as `tvm-<id>-workspace` with `incus storage volume copy`. On ZFS that is a clone of a snapshot: it took under 0.1 seconds on beast and shares every block with the template.
3. It attaches the clone to the running VM as the disk `workspace`.
4. Over the Node's SSH connection, it mounts the disk at the Project's apps directory and adds it to `/etc/fstab`. It points `~/.cache/ms-playwright` and `~/.cache/composer` at the volume.

5. Source preparation checks the owner and the origin of `workspace`, and takes it in place. Preparation writes its receipt as for a clone.

A task VM workspace's checkout is always `<apps directory>/workspace`, the path where the template was built. A template in place that fails the source checks fails the preparation, because a fresh clone cannot go to its path.

A claim takes only a template with `user.orbit.layout=workspace`. A template from before the fixed checkout path has none. No claim takes it, and the claim queues a new build.

A claim also takes only a template whose files belong to `1000:1000` (`user.orbit.owner`), the `uid:gid` of `orbit` on the base image. For another owner, the claim queues a new build.

The mount never goes over a directory with content. Before it mounts, it checks that `orbit` has the `uid:gid` that the template recorded. After it mounts, it checks that the volume root and `workspace` belong to that `uid:gid`.

After the Project's repository or the base image changes, no template matches until the next build. The fstab line follows a successful mount and owner check only, so a failed mount never comes back at a later boot over a fresh clone.

When step 3 or 4 fails, the claim undoes the attach before the fresh clone: it removes the cache links into the volume, unmounts it, removes its fstab line, detaches the disk, and deletes the clone. So the fresh clone lands on the VM's own disk, never on a volume with another user's files. When the undo fails too, the claim fails with `task_vm.workspace_template_detach_failed`, and the group asks for assistance.

Then the Gateway sends the group's branches from its [mirror](#git-on-a-task-vm), and the workspace checks out the group's branch. The baseline check runs the setup steps on the workspace. Each step finds the dependencies in place and only installs what the branch changed.

A task VM holds one workspace, so its checkout path is fixed. Caches that record absolute paths, such as Rector's, still match at the claim, so the baseline check finds them warm.

Every step is idempotent, so a claim that runs again finds the clone, the disk, and the mount in place. A warm VM gets its clone at the claim, so warm VMs stay the same for every Project. The base image creates `orbit` with uid and gid 1000, so the files of a template belong to `orbit` on every VM from that image. A template is still only taken on the image it was built on and with the owner `1000:1000`, and the mount checks the owner again on the VM.

On beast, mealou's setup steps took about 13 seconds on a new VM with a template, against about 36 seconds without one. `vp install` always runs `npm audit`, which reaches the network.

#### Without a template

The workspace starts from a fresh clone, as before, in these cases:

- The host has no ready template for the Project, or none whose owner is `1000:1000`. Orbit logs the warning `Task VM workspace has no template; it starts from a fresh clone.` and queues a build of the template. See [Template failures](#template-failures) for the wait after a failed build.
- A build of the template runs. An unfinished template is never taken, and the claim queues no second build.
- The Project has no valid default branch or repository URL. Orbit logs `Task VM workspace template cannot be built; workspaces of the Project start from a fresh clone.` with the reason, and queues no build.
- The Project's templates were built on another base image than the VM's, and the VM runs the host's current `orbit-task-base`. Orbit logs `Task VM workspace template was built on another base image; it starts from a fresh clone, and a new build is queued.` and queues a build.
- The VM runs an older base image than the templates. Orbit logs `Task VM runs an older base image than the workspace templates; it starts from a fresh clone.` A build would not help, because it boots the current image.
- A step fails, for example the copy, the mount, or an owner check. Orbit undoes the attach and logs the warning `Task VM workspace template could not be attached; the workspace starts from a fresh clone.` with the error.
- The volume holds no template checkout. Orbit logs `Task VM workspace volume holds no template checkout; it starts from a fresh clone.`
- The template checkout fails a check of source preparation, for example of its origin. Preparation then clones and logs `The workspace template failed its source checks; the checkout is a fresh clone.`

The group never waits because of a template, and fails only when Orbit cannot undo a failed attach. When the volume mounted and passed its checks but holds no template checkout, the fresh clone goes to the volume. Otherwise it goes to the root disk.

#### Build a template

Run `task-vms:build-workspace-template {node} {project}` to build the template of a Project on one host. It prints each stage with its time.

Templates are built on demand. A claim that finds no template for its VM's base image queues `BuildTaskVmWorkspaceTemplate`, and the refresh below builds ahead only for active Projects. Most Projects get few groups, so a build for every Project after each promote would mostly make templates that the next promote replaces unused.

Templates belong to the base image they were built on, so they follow it. Whatever builds or syncs a base image queues `RefreshTaskVmWorkspaceTemplates` once when it ends, after every host it changed, also when it fails: `task-vms:build-image`, the nightly base image refresh at 03:00, and `task-vms:sync-image` unless the host already had the image. The promote itself queues nothing, so no refresh sees one host moved and another not. A refresh that is already queued absorbs another one. A refresh queued while one runs queues one more, which sees the new alias.

The refresh runs on every configured host whose Node is active. It reads the host's current `orbit-task-base` and deletes each ready template that no claim can take:

- the templates of Projects without a valid default branch or repository URL;
- the templates from an earlier repository URL, or built on another base image;
- all but the newest template of each Project.

So after a promote, each host keeps at most one template for each Project, and none from the old image.

Then the refresh queues one build on each host for each Project that had a group created in the last 7 days, unless the host already has a current template of it. A current template is ready, comes from the Project's repository, was built on the host's current base image, and belongs to uid and gid 1000. So a second refresh builds only what the first one did not, and the build job skips a current template too.

Other Projects get their template at their next claim. A Project without a valid default branch or repository URL gets no build. The refresh logs the warning `Task VM workspace template cannot be built; workspaces of the Project start from a fresh clone.` for it each time.

Orbit does not watch the default branch for pushes. A template that is behind the branch is still correct: the fetch and the setup steps bring the workspace up to date. A template is rebuilt when the base image changes, which the nightly build does each night it succeeds. So the template of an active Project is at most a day old while the nightly build succeeds. The template of another Project is as old as its last build.

A change to a Project's setup steps or lockfile does not rebuild its templates. The refresh and the build job skip a current template, so the change reaches a template only at the next base image change. When nightly builds fail, that can be days later. Until then each workspace stays correct, because the setup steps in its baseline check run the change, but it saves less time. To rebuild the template of one Project on one host at once, run `php artisan task-vms:build-workspace-template NODE PROJECT` on the Gateway. The command calls the builder directly, so it builds even when the template is current.

Template jobs run on the `builds` queue, so a build never delays a task VM job. See [Jobs](#jobs).

The build runs these stages on the host over SSH, with `sudo -n incus --project <project> …`:

| Stage | What it does |
| --- | --- |
| `sweep` | Deletes builder VMs and unfinished templates that an interrupted build left |
| `launch`, `cloud-init` | Launches a builder VM `tvm-template-<time>` from `orbit-task-base` with the task VM user-data, and waits for cloud-init |
| `identity` | Reads the base image that the builder booted, and the `uid:gid` of `orbit` |
| `volume` | Creates the block volume with the host's `disk` size, and attaches it |
| `mount` | Formats the volume as ext4, gives its root to `orbit`, and mounts it with the same script as a claim |
| `clone` | Creates `workspace` with `git init` as `orbit`, and checks out the default branch, which the Gateway sends from its [mirror](#git-on-a-task-vm) |
| `php` | Installs the `app-dev` PHP packages of the version that `composer.json` selects, with the Instance's program. With the base image, it only checks them |
| `setup:<step>` | Runs each Project setup step as `orbit` in a login Bash in the checkout, with `VP_HOME` set and stdin closed, as the baseline check does. See below |
| `warm-check` | Runs the Project's task check once in the checkout, as the baseline check would, so the template brings its caches. See below |
| `verify` | Requires a clean tree. The setup steps may only add ignored files |
| `detach` | Stops every process of `orbit` in the builder, which a setup step or the task check may leave running on the volume, then unmounts the volume and detaches it |
| `publish` | Sets `user.orbit.project`, `user.orbit.commit`, `user.orbit.repository`, `user.orbit.image`, `user.orbit.owner`, `user.orbit.layout=workspace`, and `user.orbit.ready=true` on the volume |
| `delete-builder` | Deletes the builder VM |
| `prune` | Deletes the older templates of the Project on the host |

A monorepo whose task workspaces have no Route, such as `orbit`, skips the `php` stage, as its Instance does: the checkout is not one PHP application.

The `warm-check` stage runs the Project's `task_check` like a setup step, within what the build's budget has left less 60 seconds; a Project without one skips it. Its result does not decide the build:

| Outcome | Log | Template |
| --- | --- | --- |
| Passes | Info `Task VM workspace template warmed its caches with the task check.` | Published with warm caches |
| Fails | Warning `Task VM workspace template task check failed; the template keeps the caches it filled.`, with the output tail | Published |
| Runs out of budget | Warning `Task VM workspace template task check stopped at the build budget …` | Published with the caches filled so far |
| No budget left | Warning `Task VM workspace template has no warm caches: …` | Published without warm caches |

Each entry carries the command, the exit code, and the duration. When the check changes tracked files or adds unignored ones, the stage restores the checkout and logs the warning `Task VM workspace template task check changed the checkout; the warm-check stage restored it.` with the paths. Ignored files, such as the caches, stay.

The step travels on stdin to a script file, so a long command fits. A [Gateway-owned step](/reference/instance-setup#gateway-owned-setup-steps), such as `@orbit/tia-baseline` or `@orbit/ci-caches`, becomes the same command as in the check. A builder has no Instance, so no SSR port is set. Each step gets its own timeout, but at most what the build's budget has left.

A build runs within a budget of 1500 seconds. The queued job's timeout of 1700 seconds and the host's lock of 1740 seconds outlast it, and both stay below the queue's `retry_after` of 1800 seconds, so no second worker takes a running build. One template build runs on a host at a time, under that lock.

A second one fails with `task_vm.workspace_template_running`, and the queued job waits a minute and tries again. The template worker takes one job at a time, so one build runs in the whole fleet at a time.

#### Template failures

A build that fails before `publish` deletes its builder VM and its volume, and the Project keeps its current template. After `publish` the new template is ready. A failed `delete-builder` or `prune` then only logs the warning `Task VM workspace template is ready, but a cleanup stage failed; the next build retries it.`

 It fails with `task_vm.workspace_template_failed`, which names the stage. The job logs the error `Task VM workspace template build failed; workspaces of the Project start from a fresh clone.`, and claims queue no new build of that Project on that host for 6 hours. A later claim or the next refresh for an active Project tries again. A build stopped with SIGINT or SIGTERM, or past its job timeout, deletes its builder and frees the host. A build killed with SIGKILL holds the host for at most 25 minutes, and the next build sweeps what it left.

#### Disk use and pruning

A template takes the size of its files on the pool, compressed. The mealou template takes 1.1 GiB, and the orbit template, with the dependencies of all five Composer projects and the web app, 1.3 GiB. The warm-up task check adds about 4 to 5 minutes to the orbit build.

Each host holds at most one template for each Project, plus one clone for each task VM that has a workspace. After a promote, only Projects with a recent group or a new claim get a template again. A clone takes only the blocks that its group changes. The volume is sparse, so its size limit, the host's `disk`, reserves nothing.

A new template replaces the older ones of its Project at once. Incus keeps the data of a deleted template until its last clone is gone, so a running group never loses its workspace. The refresh after each promote deletes the templates that no claim can take, as [Build a template](#build-a-template) lists. Destroy deletes a VM's clone after the VM, and the sweep deletes a clone whose VM has no row. A template holds no secret. No GitHub token reaches the builder: it gets the default branch from the Gateway's [mirror](#git-on-a-task-vm).

#### Park, resume, and security

A parked VM keeps its disk attached. On the next boot, `/etc/fstab` mounts it before `multi-user.target`, so the agent and Pi start after it. In the test on beast, the volume was mounted by the time the VM answered again, 8 seconds after the start.

A template is built by code in a builder VM that no agent and no group ever ran on. Each group gets its own clone, which it can change freely, and the clone goes with its VM. So no state of one group reaches the template or another group. The Project's setup steps run in the builder, as they run in every task VM of the Project.

### Git on a task VM

No GitHub token reaches a task VM or a template builder. The agent in a task VM runs as `orbit` with passwordless sudo, so it could read a token from any process in the VM. So the Gateway runs every Git command that talks to GitHub itself, in its own mirror of the Project. It moves Git objects to and from the VM over the Node's SSH connection, as a pack on standard input or standard output.

#### The mirror

Each Project with task VM work has one bare repository on the Gateway: `storage/app/task-vm-git/<project id>.git`, in the [shared Gateway storage](/reference/gateway-recovery#release-layout). The Gateway fetches into it from the Project repository URL with the Project's [repository access](/reference/github-app#how-orbit-reads-a-repository). The token is in the environment of one `git` process on the Gateway, as for any other read. The fetch asks only for the branches that the operation names, with `--no-tags`, into `refs/heads/<branch>`.

The mirror always uses HTTPS, because the token travels in an HTTPS header. A `git@github.com:` or `ssh://git@github.com/` URL becomes `https://github.com/<owner>/<name>.git`. Any other SSH URL fails with `Task VM Git reaches a repository over HTTPS only`.

A lock lets one operation at a time change the mirror of a Project. The lock covers only work on the Gateway and GitHub: a fetch, the packing of a send, and the move of a verified pack. It never covers a transfer to or from a VM. It lasts 40 minutes, longer than a fetch can run, and another operation waits for it at most 300 seconds. Git in the mirror never collects garbage on its own (`gc.auto=0`). After each publish, the publish job runs `git gc --auto` outside the lock.

#### Send branches to the VM

1. The Gateway fetches the operation's branches into the mirror. Only `task-<id>` may be missing on GitHub.
2. It reads the commit IDs of the refs in the VM's checkout. It keeps those that the mirror holds as commits.
3. It packs the objects of the branches that are not in the history of those commits.
4. It sends the pack on the standard input of one SSH command. The VM indexes the pack and sets `refs/remotes/origin/<branch>` to the mirror's commit. A missing `task-<id>` loses its ref.
5. When the default branch was sent, the VM points `refs/remotes/origin/HEAD` at it, as a clone does. Pest's test selection reads the default branch there.

| Operation | Branches |
| --- | --- |
| Workspace creation | The default branch and `task-<id>` |
| [Fetch before a turn](/reference/tasks#fetch-before-a-turn) | The default branch, `task-<id>`, and a pull request base that is not the default branch |
| Base branch fetch | The pull request base |
| [Template build](#build-a-template) | The default branch |

A workspace without a template starts from `git init`, with the Project repository URL as `origin`, and gets its branches this way. The log messages about a fresh clone mean this new repository. The VM's refs only shrink the pack. A VM that names commits it does not have gets a pack it cannot complete, and its own Git operation fails.

#### Publish from the VM

A VM sends its pack at its own pace, so the task tick does not publish. The push of an approved commit records the commit and `task-<id>` on the task VM row as `queued`, and queues the job `PublishTaskVmCommit` on the [Git queue](#jobs). The approval stays in review, without a failure. Each later tick reads the row:

- `queued`: the job waits or runs. The tick changes nothing.
- `pushed`: GitHub has the commit at the branch. The approval continues, and the pull request opens.
- `failed`: the tick reports the job's error as a [failed push](/reference/tasks#pull-request-and-settle-metrics), once. The next push after its backoff queues the job again.

The job records its start on the row. A job that has not finished 40 minutes after its start counts as failed. A push that waits behind other jobs stays pending, for up to 2 hours. A push whose job has not started after 2 hours counts as failed too, so a lost job or a worker that never starts cannot hold the group. The job pushes only while the row still names its commit and branch as `queued`, so a repeated or late job changes nothing. It does this:

1. The Gateway fetches the default branch and `task-<id>` into the mirror.
2. It asks the VM for a pack of the approved commit. The pack leaves out the history of the mirror's commits of those branches.
3. The VM writes the pack to standard output, and the Gateway streams it to one file. A pack over 2 GiB stops the VM's command and fails the push with `The task VM sent a pack over 2 GiB`.
4. The Gateway indexes the pack into a quarantine object directory with `git index-pack --strict`. That command checks every object. The quarantine reads the mirror's objects but changes nothing in the mirror.
5. It checks that the approved commit is a commit there, and that its history is complete.
6. Only then does the verified pack move into the mirror. A pack that fails a check leaves nothing behind.
7. It pushes `<commit>:refs/heads/task-<id>` from the mirror with the write token, and records the branch in the mirror.

The job and each of its Git commands end within 1600 seconds. Only the commit ID that the Gateway approved names what it pushes. The VM's branch names, refs, and configuration play no part.

#### What the Gateway trusts

From the VM, the Gateway takes only commit IDs and pack data. A commit ID must be 40 or 64 lowercase hex characters. Git in the mirror never reads a file from the VM. It runs with these settings: `core.hooksPath=/dev/null`, `core.fsmonitor=false`, an empty `credential.helper`, `http.followRedirects=false`, `protocol.allow=never`, and `protocol.https.allow=always`. A pack from the VM goes through `git index-pack --strict`, which runs the object checks that `transfer.fsckObjects` runs for a fetch. Fetches from GitHub skip them, because an old commit in a real repository can fail them.

The Gateway refuses to send a command that carries a GitHub credential to a task VM. The command fails with `task_vm.git_credential_refused` before SSH starts. An `incus` command on a task VM host refuses one too. So an Instance or Project operation that would fetch on a task VM with a token fails there and passes no token. Examples are `instance:destroy` of a task VM workspace, `instance:clone` from one, and `project:update` with a new repository URL while one exists. A task VM workspace goes with its group; see [Destroy a task VM](#destroy-a-task-vm).

The guard knows a credential by the mark that `GitReadScript` puts on its input. A test fails when other code builds the credential header. It also fails when a new file sends remote commands with GitHub access in reach, so each such path gets a review.

The checkout keeps the Project repository URL as `origin`. The agent can run Git there, but it holds no credential. So it cannot fetch from or push to a private repository.

### Park and resume

A task VM that has nothing to do stops. Its group keeps the VM, and the next work for the group starts it again. A stopped VM uses no CPU or memory on its host. Its disk stays on the host's ZFS pool, so the workspace, its checkout, and Pi's sessions survive.

Each `tasks:tick` parks the `ready` task VM of a group when all of these are true:

- The group is `waiting_for_review` and has a pull request.
- The group has no `todo`, `reserved`, `running`, or `reviewing` subtask, and it is not `preview`.
- The group's reason does not start with `Workspace removal failed: ` or `Merged pull request cleanup failed: `. Those removals retry on the tick and need the VM.
- The group and all of its subtasks settled more than `task_vms.park_after_minutes` minutes ago. The default is 30 minutes.
- The VM has been `ready` for at least as long.

The last rule keeps a VM that Orbit started again, for example for a removal retry, running that long before it can park again. The row's `ready_at` records when the VM last became `ready`: at its first preparation, and at each resume.

The tick sets the row to `parked` and queues `ParkTaskVm`, which stops the VM with `incus stop`. After three failed attempts, the row keeps its error and stays `parked`, and Orbit logs a warning with the VM and its error. Parking failure does not ask for group assistance or replace an existing request. New subtasks still wake the VM, and a reviewed, green pull request can still merge. A successful resume or park clears the row error. A late failure after the VM wakes changes nothing. A `parked` VM still takes its slot on the host, so a resume never needs a free slot.

Work for the group starts the VM again: a new subtask after a push, a review fix, a reviewer run, a base merge, the workspace removal after a merge, a completion, and a destroy that must first remove the workspace. That work waits while the VM is `parked`. The group shows the reason `Task VM: resuming.`, and Orbit queues `ResumeTaskVm`. The job runs these steps:

1. It starts the VM with `incus start`. A VM that already runs counts as started.
2. It checks once whether the VM answers over WireGuard, `orbit-agent.service` is active, and Pi answers `GET /capabilities` with the group's Pi token.
3. If the VM is not ready, the job calls `release(2)` and returns.
4. Once all checks pass, it sets the row to `ready`.

Horizon retries a released job when it is due. The worker is free for other task VMs between checks. The resume job does not sleep or poll in a long-running job.

The next tick then continues the work, and the reason clears. Orbit never runs a workspace command, an agent turn, a fetch, or a push on a VM that is not `ready`. Those operations fail with `task_vm.parked` while it is `parked`. A merged group's workspace removal and the sweep of ended groups wait without a failure. A manual `tasks:complete` records the reason `Workspace removal failed: ` with that error, and the sweep removes the workspace once the VM is `ready`. Completion removes the workspace under the group's admission lock, so a tick cannot park the VM during that removal.

The queued resume has a fixed 9-minute deadline exposed through Laravel's `retryUntil()`. That deadline stays the same across releases and retries. When Laravel stops retrying at the deadline, `failed()` maps expiry to `task_vm.resume_timeout` and marks the still-parked row `failed`. Readiness releases do not exhaust an attempt count before that deadline. The group asks for assistance with the reason `Task VM failed:`. A `parked` VM that has no workspace left is deleted without a start. Destroy on merge is otherwise unchanged.

### Sweep task VM hosts

Every 10 minutes, while task VMs are enabled or the [UpCloud](#sweep-upcloud) token file is set, the Gateway scheduler queues the job `SweepTaskVmHosts`. While task VMs are enabled, for each configured host that is an active Node, the job checks that the host's Incus project exists with `incus project show`, then lists the names of its instances and compares them with the task VM rows. Incus lists a project that does not exist as empty, so a missing project fails the host, and the job changes nothing there:

- An **orphaned VM** is an instance named `tvm-<number>` in the project that has no task VM row on that host which is not `destroyed`. The job deletes it with `incus delete --force`.
- A **missing VM** is a `warm`, `ready`, or `parked` task VM row on that host whose instance is gone.
- An **orphaned clone** is a storage volume `tvm-<number>-workspace` in the project whose task VM has no row on that host that is not `destroyed`. The job deletes it after the orphaned VMs.

The job fails a missing VM only when two sweeps in a row miss it. The first miss is kept for an hour, and a sweep that finds the VM forgets it. On the second miss, the job sets the row to `failed` with `task_vm.vm_missing`. The group asks for assistance with the reason `Task VM failed:`. A warm VM has no group, and the pool refill destroys it.

Cancel or complete the group of a missing VM to destroy its Node, as for [a VM that dies before its group ends](#a-vm-that-dies-before-its-group-ends).

The job changes only the host's configured Incus project, such as `orbit-tasks`. It reads the other projects only to [report strays](#report-strays). It never changes another project, an instance whose name is not `tvm-<number>`, the base image builder VMs `tvm-image-<time>`, or a workspace template. Rows in `provisioning`, `failed`, or `destroying` are never missing: their own jobs check the VM.

[Doctor](/cli/doctor) reports the VM findings in the `node` family of the host, without changes. It has no finding for an orphaned clone, a leftover template builder `tvm-template-<time>`, or an unfinished template: the sweep deletes the clone, and the next template build deletes the other two. Doctor's codes are `node.task_vm_orphaned` for an orphaned VM and `node.task_vm_missing` for a missing one. Doctor reports a missing VM on its first miss. A host whose project does not exist or cannot be listed reports `node.task_vm_inspection_failed`. Doctor checks a host only while it is in `task_vms.incus.hosts` and reachable over SSH. The fleet rollout ignores these codes.

Doctor does not inspect the Node of a `parked` task VM, because the VM is stopped on purpose. Its `node` family reports one informational `node.task_vm_parked`, and the other families check nothing. So no problem is filed for a parked VM.

### Report strays

The sweep and Doctor also report what Orbit does not own on a task VM host, and the records that should be gone. They never delete or change any of it: the operator decides. The sweep logs one warning per stray on every run, and Doctor reports one finding per stray in the host's `node` family.

A **stray instance** is an Incus instance in any project of the host that is none of these:

- a task VM `tvm-<number>` in the host's project, which the [sweep](#sweep-task-vm-hosts) checks against its row;
- a base image builder or smoke VM `tvm-image-<time>…`, or a workspace template builder `tvm-template-<time>`, in the host's project, for one hour after the `<time>` in its name;
- an entry of the host's `operator_owned` list.

A build holds its lock for less than 30 minutes. So a builder VM older than one hour belongs to no running build. It is a stray until the next build deletes it.

A **stray volume** is an Incus custom volume in any project and pool of the host that is none of these:

- a workspace template `tvm-template-<project id>-<time>` or a claim clone `tvm-<number>-workspace` in the host's project and pool;
- an entry of the host's `operator_owned` list.

The report lists every project with `incus query /1.0/instances?all-projects=true`, and the custom volumes of every storage pool with `incus query /1.0/storage-pools/<pool>/volumes/custom?all-projects=true`. Images, networks, and profiles are not part of it. A host whose list fails reports `node.task_vm_inspection_failed`, and the sweep fails that host.

A **stray record** is a `ready` or `parked` task VM row on the host with no group, or whose group is `completed` or `cancelled`, for more than one hour. The hour counts from the later change of the row and its group. A `warm` row has no group by design and is never a stray record. A group that `failed` is still open: its VM stays for the operator. Normally the [destroy of ended VMs](#destroy-a-task-vm) removes such a VM within minutes, so a stray record means that destroy is stuck.

A **stray Node** is a Node named `tvm-<number>` that has no task VM row that is not `destroyed`, 15 minutes after the Node was created. Enrollment creates the Node and links it to its row in the same job, so 15 minutes covers a slow enrollment. Doctor reports it in the Node's own `node` family, also while the Node is unreachable. The sweep logs it while task VMs are enabled.

| Code | Kind | Meaning |
| --- | --- | --- |
| `node.task_vm_stray_instance` | drift | A stray instance, observed as `<project>/<name>` |
| `node.task_vm_stray_volume` | drift | A stray volume, observed as `<project>/<pool>/<name>` |
| `node.task_vm_stray_record` | drift | A stray record, observed as the task VM name and its state |
| `node.task_vm_stray_node` | drift | A stray Node |

To clear a finding, delete the stray after you check it, or add an operator-owned guest to `operator_owned`. For a stray record, cancel or complete its group, or destroy the VM. For a stray Node, remove the Node with `orbit node:remove`.

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

The task VM jobs run on the [Gateway queue](/reference/gateway-queue), which Laravel Horizon works. Each job loads its row again, does nothing when the row has moved on, and is unique for its task VM. Task VMs need the queue: set `ORBIT_GATEWAY_QUEUE_CONNECTION` before you enable them.

| Queue | Task VM jobs |
| --- | --- |
| `task-vms` | Provision, enroll, prepare, park, resume, and destroy a VM, the host sweep, and the pool refill |
| `tasks` | The [publish](#publish-from-the-vm) of an approved commit (`PublishTaskVmCommit`), beside the scheduled commands |
| `builds` | The workspace template jobs, `RefreshTaskVmWorkspaceTemplates` and `BuildTaskVmWorkspaceTemplate`, and the nightly base image refreshes, [`RefreshTaskVmBaseImages`](#build-the-base-image) and [`RefreshUpCloudTaskVmBaseImage`](#refresh-the-upcloud-template-every-night) |

One worker runs the `task-vms` jobs one at a time and in order, so no two jobs change the same VM at once.

A `task-vms` or `tasks` job starts within about a second while its worker is free, also while a build runs. A delayed or released job waits for its time. A `task-vms` job runs at most 1500 seconds, and any other job at most 1700 seconds. A job that a worker lost runs again after 1800 seconds.

A template build takes about a minute, a base image refresh up to 25 minutes, and a publish seconds. The `builds` queue has one process, so one template build or base image refresh runs in the fleet at a time. The host locks also allow one template build and one base image build or sync per host.

The template queue runs its jobs in the order they were queued. A claim's build waits behind the builds that a refresh queued. After a nightly promote with 10 active Projects on 2 hosts, that can be 20 to 60 minutes.

A builder VM has the size of a task VM and does not count against `max_vms`. It can run beside new task VMs on the same host. At most one template builder runs in the fleet, and none while a base image build runs, because the `builds` queue has one process. The base image builder and smoke VMs do not count against `max_vms` either, and they can run beside new task VMs too.

| Job | Retries |
| --- | --- |
| `ProvisionTaskVm` | Once after 30 seconds |
| `EnrollTaskVm` | Fails at once on a cloud-init error, an absent or stopped VM, or a boot over 10 minutes. Retries other errors twice. Stops after 60 tries, waits included |
| `PrepareTaskVmRuntime` | Twice, 30 seconds apart |
| `DestroyTaskVm` | Until it succeeds |
| `ParkTaskVm` | Twice, 30 seconds apart. A failure stays on the row as its error, and the row stays `parked` |
| `ResumeTaskVm` | `release(2)` between checks until `retryUntil()` expires after 9 minutes; `failed()` maps expiry to `task_vm.resume_timeout`. Readiness waits do not exhaust retries. Start errors fail after three exceptions |
| `SweepTaskVmHosts` | Never. It runs again 10 minutes later |
| `RefillTaskVmPool` | Never. It runs again a minute later. A failed pool VM makes its host wait, as [Failures](#warm-vm-failures) describes |
| `RefreshTaskVmBaseImages` | Never. The next night runs it again |
| `RefreshTaskVmWorkspaceTemplates` | Never. The next base image build, sync, or night queues it again |
| `RefreshUpCloudTaskVmBaseImage` | Never. The next night runs it again |
| `BuildTaskVmWorkspaceTemplate` | Waits a minute while another template build runs on the host, up to 10 times. A failed build or a Project without a default branch is not retried; see [Template failures](#template-failures) |

The jobs log each step with its seconds as `Task VM step done.`. The wait for the worker is a step of its own, apart from the work: `queue` before `create` in `ProvisionTaskVm`, and `enroll-queue` before `boot` in `EnrollTaskVm`. The guest boots during `enroll-queue`. A `boot` after a released try counts from the launch.

When `ProvisionTaskVm`, `EnrollTaskVm`, `PrepareTaskVmRuntime`, or `ResumeTaskVm` has no retries left, or runs past its timeout, the row becomes `failed` with the job's error code, or `task_vm.job_failed` when the error has none. A pool row then makes its host [wait](#warm-vm-failures). Otherwise the group asks for assistance, so `tasks:status` lists it. After `ProvisionTaskVm`, `EnrollTaskVm`, or `PrepareTaskVmRuntime` the reason is `Task VM failed: tvm-<id>: <code>: <message> Cancel the group to destroy the VM.`, the same reason that a [claim](#from-claim-to-workspace) sets; after `ResumeTaskVm` it is `Task VM failed: <code>: <message>`. `EnrollTaskVm` never creates the VM again. The jobs run one at a time, so the 10-minute boot limit counts from the moment `ProvisionTaskVm` launched the VM, not from the row.

#### Claims and resumes across a release

The [Gateway release](/reference/gateway-queue#releases) calls `horizon:terminate`, not a systemd restart. Horizon lets an active claim or resume finish on its original release before the new worker starts. A crashed worker's reserved job becomes available again after the queue's `retry_after`; per-VM uniqueness, state checks, and idempotent preparation keep that retry safe. A claimed warm row committed without its preparation job is recovered by the next scheduler claim as described above.

Already queued payloads must remain readable for one release, as required by [Jobs across releases](/reference/gateway-queue#jobs-across-releases). This includes old delayed `EnsureWarmClaimReady` payloads and old `ResumeTaskVm` payloads without the new deadline field. A compatibility reader for an old guard payload only dispatches the ordinary preparation job when the row belongs to a claimed warm VM and is still `provisioning`. It creates no new guards and is removed after those payloads have drained. This compatibility is not part of the new claim workflow.

An old resume payload uses its original enqueue time for a fixed deadline, which survives its subsequent releases. Its old queue envelope can still exhaust its three-attempt limit before that deadline. Both expiry and that old attempt limit follow the same `task_vm.resume_timeout` failure path.

### Incus provider

`IncusTaskVmProvider` runs `sudo -n incus --project <project> …` on the host Node over SSH, as the host's managed user. It launches the image alias `orbit-task-base` as a VM with the row's `name`, the host's `cpus`, `memory`, and `disk`, and `eth0` on the host's `network` with `security.port_isolation=true`. The user-data goes on stdin. The provider never creates a network.

A [medium VM](#size-by-declared-topology) gets `limits.cpu=4`, `limits.memory=8GiB`, and a 40 GiB root disk instead, and a large VM `limits.cpu=6`, `limits.memory=12GiB`, and a 60 GiB root disk. Their config on stdin also sets `raw.qemu.conf` to turn on free page reporting for the balloon device `qemu_balloon`. Incus applies that key only when the VM starts, so the launch sets it.

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
- The project restrictions `restricted=true`, `restricted.networks.access=<bridge>`, and `restricted.virtual-machines.lowlevel=allow`.
- One host rule: `ufw route allow in on orbittask+ comment 'orbit-task-vms'`.

Task VMs need nothing that the restrictions forbid, except low-level config: medium and large VMs set `raw.qemu.conf` for free page reporting. Only the Gateway sets instance config in the project.

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

No peer reaches a host's Incus API. The task VM project keeps its restrictions anyway: they cost nothing, and they limit what anything in the project can ask Incus for.

### Build the base image

Run `task-vms:build-image {node}` to build the base image on one host. It prints each stage with its time. Then it [syncs](#sync-the-base-image) the new image to every other configured host, so every host launches the same image. It runs these stages on the build host over SSH, with `sudo -n incus --project <project> …`:

| Stage | What it does |
| --- | --- |
| `launch`, `cloud-init` | Launches a builder VM `tvm-image-<time>` from the stock image `ubuntu-26.04-vm`, with the host's VM size and bridge, and waits for cloud-init |
| `orbit-user` | Requires `orbit` to have uid and gid 1000 |
| `bootstrap`, `caddy-package`, `app-dev-prerequisites`, `vp-manager`, `composer-manager` | Runs the Node bootstrap, the Caddy package, the `app-dev` role prerequisites, and the Vite+ and Composer manager programs as `orbit` through `incus exec`, in enrollment's order |
| `app-dev-php-source`, `app-dev-php-8-5`, `app-dev-php-8-4` | Adds the pinned Sury PHP source and installs the `app-dev` PHP packages of each PHP version an Instance can select, with the program that installs them for an Instance |
| `rust-toolchain` | Installs the stable Rust toolchain with clippy and rustfmt for `orbit`, with rustup's minimal profile |
| `agent-binary` | Installs the pinned [Node agent](/reference/node-agent) binary |
| `pi-artifact` | Streams the pinned Pi executable to the VM. The VM checks its digest and installs it for `orbit` as `~/.local/bin/pi-server`. The stage runs only when the Gateway pins a Pi executable |
| `single-boot` | Makes a VM from the image boot once, as described below |
| `lean-boot` | Keeps work off the boot, as described below |
| `manifest` | Writes the fingerprints of the programs it ran to `/var/lib/orbit-task-vm/image.json`. See [Skip what the image holds](#skip-what-the-image-holds) |
| `clean` | Runs `resources/task-vms/base-image-clean.sh` in the VM, which removes the VM's identity. The [E2E role image](/reference/incus-topologies#role-images) build runs the same script. |
| `publish` | Stops the VM and publishes it, without compression, as the image `orbit-task-base-<time>` |
| `smoke` | Boots a smoke VM from the new image with the task VM user-data |
| `promote` | Points the alias `orbit-task-base` at the new image in one step |
| `prune` | Deletes every other `orbit-task-base-<time>` image that no VM in the project uses |

The smoke VM must finish cloud-init without errors. It must have an ed25519 SSH host key and the Gateway's key for `orbit`. Its journal must list one boot only. After a build, `resources/task-vms/image-check.sh --lean-boot` also requires that it kept the lean boot through its first boot, holds the manifest, and has `orbit` with uid and gid 1000. The [UpCloud smoke](#build-the-upcloud-template) runs the same script with `--ufw`. A [sync](#sync-the-base-image) does not check those two, so it can still copy an image built before them.

A VM from the stock image boots twice. Its Incus templates write the NoCloud seed, but cloud-init starts before the Incus agent writes it, so the agent reboots the VM. That costs about 10 seconds. The `single-boot` stage drops the templates under `/var/lib/cloud/seed/` from the image metadata, and keeps the others, such as `/etc/hostname`. It also orders `cloud-init-main.service` after `incus-agent.service`. Cloud-init then reads the user-data from the Incus agent through its `LXD` data source, and each VM gets its own instance ID from Incus.

The `lean-boot` stage runs `resources/task-vms/lean-boot.sh` and masks units, which keeps work off the boot. A VM from the image reaches cloud-init's end about a second sooner:

- The Ethernet link takes DHCPv4 only. The file `/etc/systemd/network/00-orbit-task-vm.network` sorts before the netplan file and otherwise matches it.
- The task bridge has no IPv6. The wait for an IPv6 link-local address cost about a second of each boot.
- `systemd-networkd-wait-online` waits for one routable IPv4 link with DNS, through a drop-in that replaces netplan's command.
- It masks `systemd-firstboot.service`, `apt-daily.timer`, `apt-daily-upgrade.timer`, `motd-news.timer`, and `unattended-upgrades.service`.
- The apt jobs could hold the dpkg lock during a task.
- So a task VM gets no unattended security updates. Its packages are those of the last nightly build. A parked VM can live for days at that state.
- Systemd applies its preset policy on a first boot. The policy enables a disabled unit again, but it keeps a masked unit masked.

Docker stays enabled at boot. Doctor expects it active on an `app-dev` Node. It also starts the containers with a restart policy again after a [resume](#park-and-resume). The smoke check requires Docker active.

The agent binary and the Pi executable are the Gateway's pins at build time. The nightly refresh picks up new pins. Enrollment still checks both digests and installs the pinned build when the image has another one.

The builder's user-data creates `orbit` without the `incus-admin` group, and adds five things to the task VM user-data: `orbit` with uid and gid 1000, a package upgrade, `openssh-server`, Chromium's system libraries, and the workspace tools. The workspace tools are `rsync` and `dig` from `bind9-dnsutils`, which Orbit's own test suites call, and `gcc` with `libc6-dev`, which Rust crates such as `ring` build with. The last program installs the stable Rust toolchain for `orbit` and links each of its tools, such as `cargo` and `rustup`, into `/usr/local/bin`. So shells that are not login shells, such as an agent's commands, find them too.

A `bootcmd` creates the group `orbit` with gid 1000 before cloud-init creates the user, and the user gets uid 1000 with that group. The ids are pinned because workspace templates hold files that `orbit` owns: before, a stock image or package that added a user first moved `orbit` to uid 1001. A uid or gid that is taken makes cloud-init fail, and with it the build.

Enrollment runs the same programs over SSH, rendered by the same code, so the image holds what enrollment installs. The clean script fails while part of the identity remains. The smoke boot also creates the image volume on the pool, so the first task VM is a clone too. New task VMs launch from the new image, and running VMs keep theirs. An image that a task VM still uses stays until a later build finds it unused.

The Chromium libraries are Playwright's `chromium` dependency list for Ubuntu 26.04, which covers `chromium` and `chromium-headless-shell`: `libasound2t64`, `libatk-bridge2.0-0t64`, `libatk1.0-0t64`, `libatspi2.0-0t64`, `libcairo2`, `libcups2t64`, `libdbus-1-3`, `libdrm2`, `libgbm1`, `libglib2.0-0t64`, `libnspr4`, `libnss3`, `libpango-1.0-0`, `libx11-6`, `libxcb1`, `libxcomposite1`, `libxdamage1`, `libxext6`, `libxfixes3`, `libxkbcommon0`, and `libxrandr2`. Playwright's xvfb and font list for headed runs is left out. The `app-dev` role prerequisites install PHP, Composer, Caddy, Docker, Vite+ with Node and pnpm, and Bun.

The clean script removes the SSH host keys, the machine ID, every authorized key, the cloud-init state and its generated configuration, the logs, the APT package cache, temporary files, and shell history.

It also stops Docker, containerd, and Caddy, and removes the IDs they write on their first start: Docker's engine ID, containerd's UUID, and Caddy's instance UUID, storage clean record, and saved config. So every VM from the image gets its own host keys, machine ID, and DHCP address, and cloud-init runs again on its first boot. The package lists stay, so an enrollment that runs a program again fetches only changes. The manifest stays too.

The builder never enrolls, so the image has no WireGuard key, agent secret, Caddy site, firewall rule, or Gateway record. Each VM gets those from its own enrollment.

The command deletes the builder and the smoke VM whether the build passes or fails. A failed build keeps the current base image on every host. It deletes the new image when it already published one, and fails with `task_vm.image_build_failed`, which names the stage. Only one build or sync of a host runs at a time. A second one fails with `task_vm.image_build_running`.

A build or sync that stops early cleans up too. A command stopped with SIGINT or SIGTERM deletes its builder and smoke VMs, frees each host, and queues the template refresh. When the nightly job runs past its timeout, the worker's failure handler does the same. A build killed with SIGKILL holds the host for at most 25 minutes. The next build or sync deletes every builder and smoke VM that an earlier one left.

Every night at 03:00, while task VMs are enabled, the Gateway scheduler queues the job `RefreshTaskVmBaseImages` on the `builds` queue. It builds the base image once, on the first host in `task_vms.incus.hosts`, and syncs it to every other configured host, one after another. While the first host has no base image, the job only reads its image list, so the first build is always `task-vms:build-image`. The job fails with `task_vm.unknown_host` when the first host's Node is not active.

A failed build changes no host. A host whose sync fails keeps its image, and the job fails with `task_vm.image_sync_failed` after the other hosts, so `failed_jobs` records it and the log names each host. The refresh has its own [worker](#jobs), so it delays no other task VM job. So the build runs beside provisioning: while it runs, the first host holds two extra VMs, the builder and then the smoke VM, next to its task VMs.

### Sync the base image

Run `task-vms:sync-image {node}` to copy the current base image to one host. By default it copies from the first configured host. `--from` names another host. It prints each stage with its time. `task-vms:build-image` and the nightly refresh run the same sync on every other host after a build.

The source exports the image, and streams it with ssh and its [transfer key](#let-hosts-copy-the-image) to the receiver of the target, which imports it. The Gateway only starts the commands, and the image never passes through it. The source is only read. The target then gives the image the source's dated alias `orbit-task-base-<time>`.

The export is a file, because `incus image export` writes no stream. The receiver writes the image once more into its staging file, because `incus image import` reads a file. ssh carries it between the two with no other copy. An image of about 3 GiB takes about half a minute on a 1 Gbit/s LAN.

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

`task-vms:retire-host` first sets up the remaining hosts again. That removes the old host's key from each of them. Then it removes the old host's own setup: its peer key lines, `/etc/orbit/task-vms/image-transfer`, the receiver, and `/var/lib/orbit-task-vm-image`. When the Node record is gone, it only cleans the remaining hosts. When SSH cannot reach the old host, the command fails after the remaining hosts are clean, and the old host keeps its own setup. Only then can [Node removal](/reference/node-provisioning#remove-a-node) remove the Node, if you want it gone.

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
| `task_vms.medium_projects` | `ORBIT_TASK_VMS_MEDIUM_PROJECTS` | A JSON list of Project IDs whose groups get a [medium VM](#size-by-declared-topology) when no subtask declares a topology. Default `[]` |
| `task_vms.topology_paths` | `ORBIT_TASK_VMS_TOPOLOGY_PATHS` | A JSON object from Project ID to a list of path patterns that need a [declared topology](#prove-work-on-a-topology). Default `{}` |
| `task_vms.park_after_minutes` | `ORBIT_TASK_VMS_PARK_AFTER_MINUTES` | How many minutes a group waits for review before its VM [parks](#park-and-resume), from 1 to 10080. Unset or empty means `30`. `0` and other values are an error |

Each task VM stores `model_proxy_origin` with its model key, and revokes the key at that origin. So changing the origin never strands a key. A task VM that holds a key at another origin cannot prepare Pi again.

Each host is a JSON object with these snake_case keys. Other keys are an error.

| Key | Required | Default | Meaning |
| --- | --- | --- | --- |
| `node_id` | Yes | | The ID of the host Node. List each host once |
| `cidr` | Yes | | The task bridge network, such as `10.251.77.0/24`: a private network from `/16` to `/28`, not a host address. The bridge gets the first usable address and VMs get the others |
| `max_vms` | Yes | | The slots of the host, from 1 to 64. Each task VM on it that is not `destroyed` takes one slot, two for a medium VM, or three for a [large VM](#size-by-declared-topology) |
| `project` | No | `orbit-tasks` | The Incus project |
| `network` | No | `orbittask0` | The bridge name: `orbittask` and 1 to 6 lowercase letters or digits |
| `pool` | No | `orbit-tasks` | The Incus storage pool for the base image and the VM root disks. It must use the `zfs` driver |
| `zfs_dataset` | No | | The ZFS dataset from which `task-vms:prepare-host` creates `pool` when it is missing, such as `fast/orbit-tasks`. A new dataset below a ZFS pool: not the pool itself, and not a dataset that exists |
| `cpus` | No | `2` | The vCPUs of each normal VM, from 1 to 64 |
| `memory` | No | `4GiB` | The memory of each normal VM, in `MiB` or `GiB` |
| `disk` | No | `20GiB` | The root disk of each normal VM, and the size of each workspace template, in `MiB` or `GiB` |
| `warm_vms` | No | `0` | How many [warm VMs](#keep-warm-vms) the host keeps for claims, from 0 to `max_vms`. They take slots of `max_vms` |
| `operator_owned` | No | `[]` | The Incus instances and custom volumes on the host that the operator owns, such as CI runners. Each entry is `<project>/<name>`, or `<project>/*` for a whole project. The [stray report](#report-strays) skips them |

For example, `ORBIT_TASK_VMS_INCUS_HOSTS='[{"node_id":7,"cidr":"10.251.77.0/24","max_vms":4,"warm_vms":1}]'`.

`TaskVmSettings` validates the configuration once, when it is first used. It checks every value that is set, also while task VMs are off, so you can prepare hosts and the hub before you enable task VMs. An unset or empty value stays absent, and malformed JSON is an error. Enabling task VMs also requires `dev_cluster_id`, `model_proxy_origin`, and at least one host.

The reserved range must always be a private network. As soon as task VMs are enabled, or the Cluster, the origin, or a host is set, the range must also be a smaller network inside the Gateway's VPN subnet, and no host's `cidr` may overlap that subnet. While none of them is set, Orbit does not compare the range with the subnet, so a fleet on another subnet keeps working. Invalid configuration fails with `task_vm.invalid_config` (HTTP 500).

### Errors

`IncusTaskVmProvider` reads the host's output: the instance state, its one IPv4 address inside the bridge range, and the host key fingerprint. `TaskVmGuest` reads the guest's output the same way for every provider: the cloud-init status and the image manifest. Invalid output fails with `task_vm.invalid_host_output`, and a failed guest command with `task_vm.host_command_failed`, also on UpCloud. Every task VM error code starts with `task_vm.`. The setup commands print the code and message, and for `task_vm.setup_failed` the last lines of the script's error output. After that check, Orbit trusts its own records.

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
| `task_vm.invalid_host_output` | 502 | Incus or a task VM guest returned output that fails these checks |
| `task_vm.host_command_failed` | 502 | An `incus` command on the host, or a guest command in a task VM, failed |
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
| `task_vm.resume_timeout` | 504 | A `parked` VM did not become ready before its fixed 9-minute resume deadline |
| `task_vm.vm_missing` | 502 | The sweep found a `warm`, `ready`, or `parked` task VM gone from its host |
| `task_vm.pool_start_timeout` | 504 | A pool VM was still `provisioning` an hour after the refill recorded it |
| `task_vm.workspace_template_failed` | 502 | A workspace template build failed, or the mount of a clone failed. The message names the stage |
| `task_vm.workspace_template_running` | 409 | A workspace template build of the host is already running |
| `task_vm.workspace_template_detach_failed` | 502 | A claim could not attach a workspace template, and could not unmount and detach the volume after it. The claim stops, so no fresh clone lands on the volume |
| `task_vm.git_credential_refused` | 409 | A command that carries a GitHub credential was about to reach a task VM or an `incus` command. See [Git on a task VM](#git-on-a-task-vm) |

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

- A group waits for the next `tasks:tick`, every 10 seconds, before its claim.
- The claim dispatches the preparation job and returns. The job waits for the `task-vms` worker.
- Runtime preparation with current Pi takes about 1.5 seconds, apart from queue time. See [Claim a warm VM](#claim-a-warm-vm).
- The next claim after the row becomes `ready` creates the workspace.
- A warm VM with an older Pi is `ready` only after the queued job uploads Pi, behind the other jobs on the queue.
- A subsequent claim retries a missing preparation dispatch. A unique lock orphaned before enqueueing can delay recovery until its one-hour expiry.

The claim and the refill also have these limits:

- The claim reads each host's image list over SSH inside the scheduler tick, at most every 10 seconds. It waits up to 15 seconds for a host.
- A claim also reads an alias that someone moved by hand. The refill then replaces the warm VMs within a minute.
- The backoff count lives in the Gateway cache. A cleared cache forgets it, and the next refill tries again.

Workspace templates have these limits:

- A template is as fresh as its last build. That is at most a day for an active Project. A claim fetches the rest, and the setup steps install what changed.
- The builder installs the PHP packages of the version that `composer.json` selects. A setup step that needs more than the image and those packages fails the build, and the Project's workspaces start from a fresh clone.
- One template build runs in the fleet at a time.
- After a promote, the first claim of a Project without a recent group starts from a fresh clone.
- That claim's template follows after the builds already queued. After a nightly promote, that can take 20 to 60 minutes.
- A builder VM is not counted in a host's `max_vms`.

An ignored file that no setup step rewrites keeps its state from the template's commit, up to a day old. Examples are a SQLite database from a migration, a config cache, or a `.env` that a step copies only while it is missing. Such a file can make a check pass in the workspace that fails from scratch. Orbit does not remove ignored files, because `vendor` and `node_modules` are ignored files too. A step that creates such a file should rewrite it. No current `vm` Project has one.

Before the baseline check starts:

- The workspace takes the template, the source, and the preparation: about 4 to 5 seconds.
- The Route runs in the claim, so the next claim of the same tick waits for it.
- A VM from an image built before the PHP packages still installs them before the check, in about 30 seconds.

Parking has these limits:

- A `parked` VM keeps its slot. Parking frees CPU and memory on the host, not slots.
- A `parked` VM's private Route does not answer. Set `preview` on a group to keep its VM running during review.
- Doctor does not check a `parked` VM's Node until the VM starts again.

Git on a task VM has these limits:

- The Gateway keeps a mirror of each Project with task VM work, about the size of its repository.
- A task VM checkout has only the branches that [Send branches to the VM](#send-branches-to-the-vm) lists, and no tags.
- The mirror of a Project serves one fetch, pack, or push at a time. A waiting operation fails after 300 seconds.
- A workspace without a template gets the whole history of its branches from the Gateway once. The Gateway packs it, and the pack goes through the Gateway's storage on its way to the VM.
- The operations that [refuse a credential](#what-the-gateway-trusts) on a task VM need the workspace's group to end first, or a Project without task VM workspaces.

The hub filter has these limits:

- The hub filter fails open to keep the fleet VPN up. The tunnel starts even after a failed table load at boot.
- A later restart of `nftables.service` removes the hub table, but its unit still shows active. Run `task-vms:prepare-hub` again to load the table.

### Why task VMs work this way

[ADR 0200](/decisions/0200-run-each-task-group-in-its-own-sandbox-vm) records why the VM edge is the boundary, why egress is open, why one base image serves every host, and the alternatives it rejected. Host output is validated once, and Gateway records are trusted after that, so seven states and no marker timestamps describe the whole lifecycle. `claimed_at` and `warmed_at` only time it. Parking reads the settle times the group already records.

Most of a new VM's 35 seconds go to its boot and its enrollment, and none of that depends on the group. So a warm VM does those steps before the claim, and the claim adds only what belongs to the group: its Pi token, its model key, Pi, and its workspace. A warm VM holds no secret of a group, so it can wait on the task network like any other VM. A VM serves one group only and is destroyed after it, so no state of one group can reach the next.

`warm_vms` is a setting of each host, next to its `max_vms`. Hosts differ in size, and setting a host's `warm_vms` to `0` drains only that host, which retiring a host needs. A claim reads the image itself, so a stale warm VM never serves a group, even before the refill replaces it.

The agent in a task VM has root through sudo, so a token anywhere in the VM, even in another user's process, is the agent's token. The Gateway already holds the tokens and already reaches every task VM over SSH. So Git that talks to GitHub runs on the Gateway, and only objects cross the VM edge.

Packs on the standard input and output of the existing SSH commands need no new transport, reach a template builder through its host as well, and keep the check that a command reaches only its group's workspace. The Gateway checks what comes back the way a fetch checks a remote: only the approved commit ID names what it pushes, and `index-pack --strict` checks every object.

Most groups wait for review far longer than they work, so parking frees the host's CPU and memory for other groups. A stop keeps the disk, so a resume continues the same workspace in seconds instead of a new claim. Placement by free slots spreads VMs across hosts and keeps every host inside its budget. The sweep catches what a crash between two steps leaves, and it acts only on names and the project that Orbit owns.

Phase 3 of [ADR 0200](/decisions/0200-run-each-task-group-in-its-own-sandbox-vm) aligns the UpCloud template with the same contract. The template is built from the stock image with its identity removed. Its cloud-init installs nothing. A smoke boot passes before use, and the previous image stays while a VM uses it.

These alternatives were also rejected:

| Alternative | Why not |
| --- | --- |
| rsync over SSH | Its forced command would have to check `rsync --server` arguments. Its resume and delta transfer do not help, because a new image is a new file |
| `scp` into a staging directory, then a second command that imports | It needs two forced commands, or a key that can write files. One stream into one forced command needs neither |
| Trust on first use for a peer's host key | The Gateway can read the key over SSH, which it already trusts |
| A template only for the caches | It saved almost as much on mealou, but every claim still clones and links `node_modules`. The checkout in the template also replaces the clone |
| A setting that turns templates on for each Project | A Project without a template already falls back to a fresh clone. A template costs about 1 GiB on each host |
| A fallback to the stock image | A VM from it cannot enroll, so the failure would come later and less clearly |
| A snapshot before each park | ZFS keeps the stopped disk, and a resume continues it. A snapshot adds a copy that nothing restores |
| A parked VM frees its slot | A resume could then find its host full, and the group's work would wait behind new groups |
| Placement in host order only | The first host fills before the second takes any VM |
| One `warm_vms` for the whole fleet | Orbit would need a rule to spread it across hosts, and one host could not drain alone |
| Warm VMs outside `max_vms` | A host could then run more VMs than its budget |
| A warm VM with its Pi token and Pi running | A token without a group has no owner. The claim adds the token, the key, and Pi in about 1.5 seconds |
| A claim that takes a pool VM that is still starting | The row would need a group step in the middle of enrollment. The claim waits for it to become `warm` instead |
| A record of the current image on the Gateway | It can drift from the host's alias. The claim reads the alias, which is the fact |
| Stopped warm VMs, saved with a stateful stop | It may follow as a hot pool. A running warm VM uses little and needs no restore |
| A token in the VM for each fetch and push | The agent can read any process in the VM. A read token exposes every private branch, and the write token lets the agent push any commit |
| `git push` and `git fetch` over SSH between the mirror and the VM | A template builder has no address of its own and needs a second transport. It also skips the check that a command reaches only its group's workspace |

## UpCloud overflow

When beast and shark have no free slot, a group of an opted-in Project may get its [task VM](#task-vms) at UpCloud. The overflow is off by default. It needs both the Gateway-wide switch `ORBIT_TASK_VMS_UPCLOUD_ENABLED=true` and the Project's ID in `ORBIT_TASK_VMS_UPCLOUD_PROJECTS`. Without both, the group waits with `Task VM: no capacity.` as before. `shared` stays the default task compute.

An UpCloud task VM follows the same path as an Incus one. It is a `task_vms` row with the provider `upcloud` and no host Node. The same jobs create, enroll, park, resume, and destroy it.

It gets the same task VM user-data, enrolls as the `app-dev` Node `tvm-<id>` of the dev Cluster with an address from the reserved WireGuard range, and starts from a template built with the same recipe as the [base image](#build-the-base-image). So the hub filter, the Caddy guard, the rollout exclusion, and the placement invariant apply unchanged. Each claim starts the workspace from a fresh clone: [workspace templates](#start-the-workspace-from-a-template) are volumes on an Incus host.

### Place a VM at UpCloud

Placement first takes a current warm VM, then a new VM on the Incus host with the most free slots, as in [Place task VMs](#place-task-vms). Only when no host has a free slot does a new VM go to UpCloud, and only while the switch is on, the group's Project is opted in, and fewer than `max_vms` UpCloud VMs are not `destroyed`. Parked and failed UpCloud VMs count. UpCloud keeps no warm VMs. A VM never moves to another provider.

### Create and reach an UpCloud server

The server is named `tvm-<id>` in its title and hostname, and carries the label `orbit-task-vm=<id>`. Orbit records its UUID. A create whose answer was lost is found again by its label, so Orbit never sends a second create. Before each create, Orbit counts the account's servers with the `orbit-task-vm` label and refuses at `max_vms` with `task_vm.upcloud_budget_full`. A server that lost its row counts too.

The server clones the newest verified template onto a disk with the storage tier of its plan, such as `standard` for a Starter plan. The default plan includes 30 GB of that storage, so the default disk costs nothing extra. It has one public IPv4 interface, and no utility or private network, so it reaches no private address of the account. Fleet traffic goes only through WireGuard and the hub filter. Console access is off. The UpCloud firewall stays off: it is stateless, so reply traffic needs source-port rules that open other ports too.

The server boots with ufw on, from the template. ufw denies all incoming traffic except public SSH on port 22, so Caddy and the other services in the image are not public before enrollment. The template holds the exact rule `orbit:public-ssh-recovery` that enrollment opens on a new Node. Enrollment finds it in place and changes nothing, then the `app-dev` role adds WireGuard trust and closes public SSH, as on any public `app-dev` Node.

UpCloud has no trusted host that can read the guest's SSH host key, as Incus does. So the Gateway generates an ed25519 host key for each server, adds it to the user-data, and pins its fingerprint. No connection trusts a key on first use. Root login stays off. The Gateway reads cloud-init and the image manifest over SSH as `orbit` at the public address, and enrollment needs no jump Node.

A park stops the server cleanly, and a hard stop follows after 150 seconds. UpCloud bills a stopped server for its storage only. A destroy stops the server, deletes it with its disk, and waits until it is gone.

### Build the UpCloud template

`php artisan task-vms:build-upcloud-image` builds the template on the Gateway. It creates billed servers. The build prints each stage with its time:

| Stage | What it does |
| --- | --- |
| `sweep` | Deletes the build servers and unverified templates that an interrupted build left |
| `launch` | Boots a builder server from the stock Ubuntu 26.04 template with the base image builder user-data |
| `cloud-init`, `orbit-user` | Waits for cloud-init over SSH, and checks the pinned `orbit` uid and gid |
| One stage for each enrollment program | Runs the same enrollment programs as the Incus base image |
| `agent-binary`, `pi-artifact`, `masks` | Installs the pinned agent binary and Pi executable, and masks the first-boot and apt timers |
| `firewall` | Turns ufw on: incoming traffic denied, except the public SSH rule `orbit:public-ssh-recovery` on port 22 |
| `manifest`, `clean` | Writes the image manifest, and runs `base-image-clean.sh` |
| `templatize`, `delete-builder` | Stops the builder, turns its disk into the template `orbit-task-base-<time>-unverified`, and deletes the builder |
| `smoke` | Boots a server from the template with the task VM user-data, and checks cloud-init, the manifest, ufw, and the key, uid, and gid of `orbit` |
| `promote`, `prune` | Renames the template to `orbit-task-base-<time>`, and deletes all but the two newest verified templates |

A failed build deletes its servers and its unverified template, and the previous template stays in use. Without a verified template, a create fails with `task_vm.base_image_missing`.

One build runs at a time. A build marks its time stamp as running for two hours, and no sweep deletes the servers or the template of a marked build.

### Refresh the UpCloud template every night

Every night at 02:00, while the token file is set, the Gateway scheduler queues the job `RefreshUpCloudTaskVmBaseImage` on the `builds` queue. It runs the same build as `task-vms:build-upcloud-image`, inside UpCloud, and keeps the two newest verified templates. It also runs with the switch off, so the template stays current. While UpCloud has no verified template, the job changes nothing, so the first build is always the command. A failed build keeps the current template.

The build takes about 20 minutes and holds the image worker, so it ends before the 03:00 base image refresh starts on the same worker. When it runs past its 1700 seconds, the worker stops it, and the sweep deletes its servers two hours after its start.

### Sweep UpCloud

While the token file is set, also with the switch or task VMs off, the [host sweep](#sweep-task-vm-hosts) lists the servers with the `orbit-task-vm` label. So no server that Orbit created keeps costing money. The sweep deletes each server named `tvm-<label value>` without a row that is not `destroyed`. A `warm`, `ready`, or `parked` UpCloud row whose server is gone on two sweeps in a row becomes `failed`, and its group asks for assistance. It also deletes build servers and unverified templates older than two hours, which a killed build leaves, except those of a build that is still marked as running.

### Configure UpCloud

Set these values in the Gateway environment. `TaskVmSettings` validates them once, with the other [task VM settings](#configure-task-vms). While the token file is unset, the overflow is not configured, and Orbit sends no UpCloud request.

| Variable | Meaning | Default |
| --- | --- | --- |
| `ORBIT_TASK_VMS_UPCLOUD_ENABLED` | The Gateway-wide switch. It needs task VMs enabled, the token file, and `ORBIT_TASK_VMS_UPCLOUD_MAX_VMS` | `false` |
| `ORBIT_TASK_VMS_UPCLOUD_PROJECTS` | A JSON list of the IDs of the Projects that may overflow | `[]` |
| `ORBIT_TASK_VMS_UPCLOUD_TOKEN_FILE` | The absolute path of the token file: a regular file of the Gateway user with mode `0600` and one `token: ucat_…` line | Unset |
| `ORBIT_TASK_VMS_UPCLOUD_MAX_VMS` | The most UpCloud task VMs, from 1 to 16. Required once the token file is set | Unset |
| `ORBIT_TASK_VMS_UPCLOUD_ZONE` | The UpCloud zone | `nl-ams1` |
| `ORBIT_TASK_VMS_UPCLOUD_PLAN` | The server plan. UpCloud must list it | `STARTER-2xCPU-4GB` |
| `ORBIT_TASK_VMS_UPCLOUD_DISK_GB` | The root disk in GB, from 10 to 200 | `30` |
| `ORBIT_TASK_VMS_UPCLOUD_SOURCE_TEMPLATE` | The UUID of the stock Ubuntu 26.04 template that the build boots | `01000000-0000-4000-8000-000030260200` |

The token goes only to `https://api.upcloud.com/1.3`. The Gateway reads it from the file for each request.

| Error | Meaning |
| --- | --- |
| `task_vm.upcloud_budget_full` (409) | The account already runs `max_vms` task VM servers |
| `task_vm.upcloud_failed` (502) | An UpCloud request failed or did not answer |
| `task_vm.upcloud_invalid` (502) | UpCloud answered with data that does not match what Orbit created |
| `task_vm.upcloud_timeout` (504) | A server or template did not reach the expected state in time |
| `task_vm.upcloud_token_unavailable` (500) | The token file is missing, not private, or has no token |
| `task_vm.upcloud_not_configured` (409) | An UpCloud command ran while the token file is unset |
| `task_vm.host_key_failed` (500) | The Gateway could not generate or read the pinned host key |

### Why the UpCloud overflow works this way

One path for every provider keeps the security boundary and the code the same: only the provider API differs. Two switches keep billed VMs off unless the operator chose them for the fleet and for the Project.

The Project list lives in the Gateway environment next to the switch, so no Project field, API, or CLI change is needed. A count of the account's labelled servers backs the count of rows, so a lost row cannot raise the bill. A pinned host key that the Gateway generates replaces the trusted host that UpCloud lacks. A rename after the smoke boot marks a template as verified, so no VM clones an untested one.

| Alternative | Why not |
| --- | --- |
| A Project field for the opt-in | It needs a migration, API, CLI, SDK, and MCP changes for a switch that only the operator sets |
| The UpCloud firewall | It is stateless. Reply traffic needs source-port rules, which also let anyone reach other ports from those source ports |
| Trust on first use for the host key | The first connection could reach another machine at a reused address |
| The stock template with a full enrollment | Each VM would install the `app-dev` software, and its image would differ from the Incus base image |
| Warm VMs at UpCloud | They cost money while they wait, and overflow is rare |

## Task workspace cleanup

A task workspace is a normal Instance on its task VM. Orbit runs the Project's teardown steps in it and then destroys the VM with it. See [Destroy a task VM](#destroy-a-task-vm).

## Orbit Project

A group of the `orbit` Project runs on a task VM, like the group of any other Project. Its workspace is the Instance `task-<group id>` without a Route, and its template holds a checkout of `main` with the dependencies of all five Composer projects and the web app. Its task check is root `composer check`, which runs `bin/review-check` on the VM as `orbit`. [ADR 0200](/decisions/0200-run-each-task-group-in-its-own-sandbox-vm#orbit-project) records the design.

Configure the Project like this:

- List it in `task_vms.medium_projects`, so its groups get a [medium VM](#size-by-declared-topology) with 4 vCPU. Its check runs the test suites in parallel.
- Add the setup step [`@orbit/ci-caches`](/reference/instance-setup#import-ci-main-caches) after the install steps.

A task VM has no main cache store, so without the `@orbit/ci-caches` step every check runs all tests. With it, the template and a workspace from a fresh clone get the test-impact graphs and quality caches of CI. Then the check runs only the affected tests, as on beast.

The setup and teardown steps run on the VM as `orbit`. The `seed-dependencies` step does nothing there, because a task VM workspace has no `ORBIT_SEED_PATH`: the template brings the dependency trees instead.

### Declare a topology

A subtask declares the test nodes it needs in its `topology` field, for example `["app-dev"]` or `["app-dev", "app-prod+ingress"]`. Each entry names one node, `app-dev`, `app-prod`, or `app-prod-2`, at most once. After a `+` it may add the role `ingress`. `app-prod-2` needs `app-prod` in the same list. A role that conflicts with the node's base role, such as `app-dev+ingress`, is refused, by the [role compatibility](/reference/node-provisioning#role-compatibility) rules. The format takes more roles once one is proven to converge on a test node. Subtask creation, group creation, and stored task definitions keep the declaration. Only a subtask that has not started can change it.

The subtasks of a group declare at most two test nodes together, as many as a large VM holds. In the spike, a 12 GiB VM was full with two, so a third would run it out of memory. A group that declares more is refused with `tasks.invalid_topology`.

The Gateway validates a declaration once, when a request, a task definition, or `tasks:render-prompt` gives it. It trusts a stored declaration. So a node or role that leaves the format later never fails a tick or a response for a subtask that declares it; the harness refuses that node when the topology is prepared.

The group's topology is the union of its subtasks that are not cancelled: each node once, with every role any subtask adds to it. It decides the [VM size](#size-by-declared-topology) at the claim.

### Prepare the topology

Before a subtask that declares a topology runs its baseline check or its implementer, Orbit prepares the group's topology in the VM. It starts the systemd unit `orbit-task-topology-<run>` as `orbit` and polls it on each tick. The subtask waits meanwhile with `Task VM: preparing the topology <nodes>.` The unit runs `resources/task-vms/topology.sh` from the workspace checkout:

| Step | What happens |
| --- | --- |
| `host-setup` | `sudo bin/e2e-topology-host-setup --pool-size=SIZE`: Incus, the btrfs pool, and the [isolation table](/reference/incus-topologies#run-topologies-nested-in-a-task-vm). The pool is 25 GiB in a medium VM and 45 GiB in a large VM. |
| `images` | `bin/e2e-topology-images ensure --nodes=<union>`: the harness builds each [role image](/reference/incus-topologies#role-images) that the declared nodes need and the VM does not have yet. A first build takes about 9 minutes. |
| `acquire` | `bin/e2e-topology acquire TASK-<group> <checkout> --nodes=<union> --replace`, which first releases the topology the VM still holds from an earlier subtask. After a failure it runs once more; the log records the retry. |

Every task VM's user `orbit` is in the `incus-admin` group from its first boot, so agents can run `bin/e2e-topology` once Incus is installed. The row stores the union in `topology`, its state in `topology_state` (`preparing` or `ready`), and the run in `topology_run`.

A ready topology serves every next subtask whose declaration it holds. A subtask that declares more starts the unit again, which releases the topology and acquires the new union; the images stay. A [parked](#park-and-resume) or resumed VM forgets its topology, because its test machines stop with it, and the next subtask prepares it again. A shared group cannot run a topology: its subtask waits with `Declared topology runs only in a task VM group.`

A VM can also restart on its own. So before each turn of a subtask with a ready topology, Orbit reads the run's state. The run records the VM's boot ID, and a run from an earlier boot is `stale`. Orbit then prepares the topology again.

When the unit fails, or stops without a result, the subtask asks for assistance with the step and the end of its log. The row forgets the run, so a resolution prepares again. A unit runs for at most 75 minutes. A start stops the units of earlier runs first, so one preparation runs at a time. When the start call fails, Orbit reads the run's state: a unit that runs or finished is the run, and only a run without a unit fails. Host setup gets the Gateway's VPN subnet, so its input rule covers the VM's own address.

### Prove work on a topology

The implementer and the reviewer use the topology with `bin/e2e-topology`, as the [using-incus-topologies](https://github.com/nckrtl/orbit/blob/main/.agents/skills/using-incus-topologies/SKILL.md) skill describes. Their prompts name the declared nodes. They never acquire or release it.

The reviewer proves each deliverable on the declared nodes with `bin/e2e-topology exec TASK-<group> NODE --argv=JSON --record=LABEL`. The harness writes the node, the command, the exit code, and the output to the [evidence log](/reference/incus-topologies#evidence-log). When the reviewer approves a subtask that declares a topology, Orbit reads the newest MiB of the evidence. The approval passes only when the evidence holds, for each node the subtask declares, a record that counts. Otherwise Orbit reminds the reviewer with the nodes that lack evidence, and the subtask is not approved.

Orbit reads `.e2e/evidence.jsonl` when the workspace has it. Each line is one JSON record: `{"v":1,"start":"…","end":"…","label":"…","node":"…","exit":0,"argv":["…"]}`, with UTC times in milliseconds and the redacted argv as a list. Orbit skips a line that is not such a record, for example one cut at the start of the newest MiB, and ignores keys it does not use. A workspace without that file falls back to the text log `.e2e/evidence.log`, whose `===` header, `$` command line, and `--- stdout` line Orbit parses. A record counts when:

- its command exited 0;
- it started after the implementer's last handoff, and not after the time Orbit reads the evidence;
- its command does work on the node. These commands do not count, also inside `sh -c` or `bash -c`: `true`, `:`, `false`, `exit`, `echo`, `printf`, `sleep`, and `yes`.

The gate is a cooperative check, not a control against forgery. The agent owns the VM and can write the file directly. The gate catches a reviewer that did not run anything on a declared node, and a person audits the records.

Orbit adds the counted records, with their time, node, label, and command, to the approval, and so to its commit, for a person to audit. Appending to `.e2e/evidence.log` is not a workspace change: the file is ignored, and the reviewer's prompt says so.

`task_vms.topology_paths` is the safety net. It maps a Project ID to path patterns in the syntax of [file deliverables](/reference/tasks#deliverables). For Orbit, list its Node, agent, guest, deployment, and routing code, for example:

```json
{"46": ["apps/agent/**", "apps/e2e/resources/guest/**", "apps/gateway/app/{Domain,Infrastructure,Actions}/{Nodes,Deployments,Routes,Caddy,WireGuard}/**"]}
```

Orbit checks this when the handoff check of a group passes. It refuses the handoff when the subtask's diff changes a matching path and no subtask of the group declares a topology. The reminder starts with `This subtask changes paths that need real machines`. The implementer reverts those changes, or ends its turn blocked so the operator can add a subtask with a topology.

## Sandbox power

Task group responses report `sandbox_power` separately from task status. For a [task VM](#task-vms), it is `running` while the VM is `ready`, `stopped` while it is [`parked`](#park-and-resume), and `destroyed` once it is `destroyed`. In the other states it is `null`, and the group's wait reason names the state. The CLI shows this value as **Sandbox power**.

## Request a preview

Set `preview: true` when creating or updating a task group to keep its VM running during review. The default is `false`. Preview intent can change while a group is in backlog, waiting, or active; ended groups refuse it. Title, brief, and status updates keep their existing restrictions. Shared groups store the intent without changing shared-host power. A task VM with `preview` is never [parked](#park-and-resume). Setting `preview` does not start a VM that is already parked. The next work for the group starts it.

Use `orbit tasks:create --preview` or `orbit tasks:update <group> --preview`. Use `--no-preview` on update to release the preview. The two update flags are mutually exclusive. The API accepts JSON booleans; omitted updates preserve the current intent.
