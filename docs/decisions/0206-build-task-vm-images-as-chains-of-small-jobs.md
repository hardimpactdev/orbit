---
title: "ADR 0206: Build task VM images as chains of small jobs"
sidebarTitle: "0206 Image build jobs"
description: "In progress. The task VM base image is built by a chain of small queued jobs, one for each step, recorded in one image build row. ADR 0207 supersedes image inputs and scheduling; the UpCloud chain remains unfinished."
---

# ADR 0206: Build task VM images as chains of small jobs

The Gateway builds the task VM base image with a chain of small queued jobs. One `task_vm_image_builds` row records its progress and failure. ADR 0207 supersedes this record's image inputs, build phases, nightly trigger, sync, and workspace plans. The [machine software reference](/reference/compute-drivers#machine-software-recipe) holds that implemented decision. The UpCloud chain remains unfinished.

## Status

In progress.

Principle: [Lean](/mission#principles). The image build keeps its result and drops the parts that only made one long job survive: the manifest, the host locks with expiry times, the stage budgets, and the cleanup of a job that was killed halfway. It also serves [Deterministic first](/mission#principles): the build row, not a log search, says where a build stopped.

## Context

Before this decision, [ADR 0200](/decisions/0200-run-each-task-group-in-its-own-sandbox-vm) used one job for a nightly base build and sync to other hosts. On beast the build takes about 3.5 minutes. It may run up to 25 minutes, and a sync up to about 24 more.

An audit on 13 Oct 2026 found these problems in the former build:

- **One long job is fragile.** The build is one job on the `builds` queue. A release during a build waits for it: [Horizon](/reference/gateway-queue#releases) starts no new job on any queue until the build ends, `tasks:tick` included. A job that runs past its timeout loses its work. A `failed()` handler, a signal trap in the command, and a host lock with an expiry time clean up after it. That lock can expire before a long build ends, and the next build then deletes the running one's builder. The former workspace builder had the same flaw.
- **The manifest couples the image to enrollment.** The image runs the enrollment programs of an `app-dev` Node and records a fingerprint for each one. Enrollment renders each program again and skips it only when its fingerprint matches. This keeps an older image correct, but it ties every change to an enrollment program to the image.
- **Every build starts from scratch.** Each night the build boots the stock Ubuntu image and installs everything again: the packages, both PHP versions, Composer, Vite+, Bun, Caddy, Docker, and the Rust toolchain.
- **A failure is hard to place.** The log names the stage of a failed build, but a person must find the log line. Nothing records how far a build got.
- **Two builders.** Incus and UpCloud each have their own builder class, and the two already differ: UpCloud skips the lean boot network setup and never checks it.

Off-the-shelf tools were considered first. See [Rejected alternatives](#rejected-alternatives).

## Decision

The Gateway owns the image build. This decision changes item 1 of [ADR 0200](/decisions/0200-run-each-task-group-in-its-own-sandbox-vm) and the manifest skip in [Skip what the image holds](/reference/compute-drivers#skip-what-the-image-holds).

### The build is a chain of jobs

A base image build is one Laravel job chain (`Bus::chain`) on `builds`. A failed step stops the chain and records its error. The build row reports the whole build; Horizon shows individual jobs.

**Build phases superseded by ADR 0207.** The old enrollment-program steps are replaced by [recipe phases](/reference/compute-drivers#build-the-base-image). Every host builds the current recipe from stock. Two [build workers](/reference/gateway-queue#queues) can serve different hosts in parallel; each chain preserves its step order.

### The build row holds the state

The table `task_vm_image_builds` has one row for each build. It holds the host, the source image, the builder VM name, the new image's fingerprint, the current step, the state (`running`, `succeeded`, or `failed`), a pending queue submission, a cleanup claim, and each step's start, seconds, and error. A partial unique index allows one `running` build for each host. That index replaces the host's image lock and its expiry time.

When a step fails, the row becomes `failed` with the step and the error. Its builder, smoke VM, and published image are retained for 24 hours after failure, until the next build launches on that host, or until explicit discard. `task-vms:build-image` prints the steps from the row as they finish. A command `task-vms:image-builds` lists recent builds with their steps and errors. A failed build can be continued from its failed step with `task-vms:build-image --resume <build id>`, while cleanup has not claimed it and its step's resources still exist: the builder before publication, either the builder or an already published image for publish, and the published image for smoke and promote. Cleanup atomically claims the failed row before any remote deletion; resume atomically changes an unclaimed failed row to running. The database decides which wins, including on SQLite, without a host cache lock.

Start and resume persist a pending submission marker with the running state before enqueueing. The dispatcher clears the marker when enqueueing succeeds; a worker also clears it when it claims a running build. An enqueue exception fails a still-pending submission without changing a build already claimed by a worker. The host sweep and the next start or resume fail pending submissions older than ten minutes. This recovers a crash between the durable write and queue acknowledgement without guessing whether an image step ran or replaying an uncertain submission. The submission deadline does not limit a running build's lifetime.

Pruning protects the fingerprints and dated aliases of running and retained failed image build rows on that host, even between publish and smoke when no VM uses the new image. Alias protection also covers a publish whose fingerprint has not yet been saved.

The [host sweep](/reference/compute-drivers#report-strays) deletes a `tvm-image-<build id>` VM whose build is neither running nor within failed-build retention, and its stray report reads the same row. Both the host sweep and the base image sweep atomically claim cleanup on a recorded non-running build before deleting either its builder or smoke VM. They share the cleanup job's durable fence against resume: if resume wins, deletion skips the VM; if deletion wins, resume refuses even while the builder still exists. Neither reads a time from the VM's name. Failed-build retention counts from the row's failure update; running builds have no age limit.

**Refresh recovery superseded by ADR 0207.** The host sweep no longer dispatches workspace refreshes. Failed build rows still fence cleanup and resume.

Package update retries lists-lock contention for up to 300 seconds because APT does not apply `DPkg::Lock::Timeout` to that lock. Upgrade and install use that option for their dpkg locks. The package job allows 1500 seconds, with remote calls bounded to 1440 seconds, so lock waits leave time for package work.

### Each build starts from the current image

Superseded by [the implemented ADR 0207 contract](/reference/compute-drivers#machine-software-recipe). Each recipe build starts from stock. Current-image builds and the weekly full build are removed.

### Enrollment trusts the image

A task VM boots from a base image that the same Gateway code built. Its enrollment no longer runs the `app-dev` software programs and no longer reads a manifest. It still renders and applies everything that is specific to the VM: the host key, WireGuard, the firewall, Caddy, DNS, the agent and its secret, and Pi. The manifest file, `TaskVmBakedPrograms`, and the build's `manifest` step go away.

**Nightly trigger superseded by ADR 0207.** [Recipe changes](/reference/compute-drivers#build-the-base-image) trigger host builds. [Nightly maintenance](/reference/compute-drivers#nightly-recipe-maintenance) supplies the snapshot-date PR; new claims require its deployed recipe's image.

### Next steps in the same model

**Sync and workspace plans superseded by ADR 0207.** [Host-local builds](/reference/compute-drivers#build-the-base-image) replace inter-host sync. [Clone and setup](/reference/compute-drivers#clone-and-set-up-every-workspace) replaces workspace snapshots and their refresh callbacks.

The remaining step is to move the [UpCloud builder](/reference/compute-drivers#build-the-upcloud-template) into a queued chain. This unfinished step keeps ADR 0206 in progress.

## Rejected alternatives

- **One provisioning script for the whole image.** It is the smallest change, but any added package edits one shared file. A failure is a line in a long log instead of a named step that can be retried alone.
- **Packer.** Packer core only runs the steps; every builder is a plugin. The official UpCloud plugin is solid. The Incus plugin is a small community fork with one maintainer. It calls the local `incus` binary, waits a fixed time instead of for cloud-init, and cannot publish without compression. It would run as root on every host. Packer also does none of the smoke boot, the promote, or the sync, so Orbit would keep that code beside a second tool.
- **A Packer plugin of Orbit's own.** It would make the same calls to the same three Incus commands as the Gateway does now, in Go, with its own release.
- **Ansible for the image.** The image would then have one definition of an `app-dev` machine, and enrollment of long-lived Nodes another. They would drift. Moving all Node provisioning to Ansible is a much larger change than the image needs.
- **distrobuilder.** It is the Incus project's own image builder and uses a declarative file. It always builds from scratch, and it would be a second definition of the `app-dev` software.
- **Keep the manifest and only split the job.** The manifest exists so that an image older than the Gateway's code is never wrong. With a build that starts from the current image and runs nightly, the window is a day, and an on-demand build closes it. The manifest's cost, the fingerprints and the coupling of every program change to the image, does not pay for that window.

## Consequences

- A release waits at most for one short step instead of a whole build.
- A failed build names its step on its row, and it can be continued from that step.
- **Superseded by ADR 0207:** new tools are pinned recipe inputs, applied by the recipe phases.
- **Superseded by ADR 0207:** current-image incremental builds and weekly full builds. Recipe builds start from stock with pinned inputs.
- **Superseded by ADR 0207:** new claims wait for the deployed recipe; running groups keep their recorded recipe.
- **Superseded by ADR 0207 and the queue update:** two `builds` workers run host-local chains in parallel. A release waits for active steps, not whole chains.
- This change removes the image manifest, the image host lock with its expiry time, the build's signal trap and `failed()` cleanup, and the stage runner of the base image build.

## Affects

- Components: apps/gateway, apps/docs
- ADRs: [ADR 0200](/decisions/0200-run-each-task-group-in-its-own-sandbox-vm), item 1 (image), the manifest, and the enrollment skip in [Skip what the image holds](/reference/compute-drivers#skip-what-the-image-holds)
- Detail: [Compute drivers: Build the base image](/reference/compute-drivers#build-the-base-image), [Compute drivers: Jobs](/reference/compute-drivers#jobs), [Gateway queue](/reference/gateway-queue)
- Verify: The proofs on beast check these outcomes.
  - A build on beast runs each step as its own job on the `builds` queue, and the row lists each step with its seconds.
  - **Superseded by ADR 0207:** current-image and `--from-stock` comparison. Recipe images pass smoke checks on each host.
  - A step made to fail leaves the row `failed` with that step, retains its resources, and keeps the current image. `--resume` then continues from that step, using an already published image for replay of publish, smoke, and promote, without republishing.
  - A release during a build starts the new Horizon after the running step, and the next step runs on the new release.
  - A task VM from the new image enrolls without running the `app-dev` software programs, and a Project's baseline check passes on it.
