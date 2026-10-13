---
title: "ADR 0206: Build task VM images as chains of small jobs"
sidebarTitle: "0206 Image build jobs"
description: "In progress. The task VM base image is built by a chain of small queued jobs, one for each step, recorded in one image build row. Each build starts from the current image, and task VM enrollment trusts the image instead of a manifest."
---

# ADR 0206: Build task VM images as chains of small jobs

The Gateway builds the task VM base image with a chain of small queued jobs, one job for each step. One `task_vm_image_builds` row records the build, its current step, and each step's time and error. A build starts from the host's current `orbit-task-base` and changes only what changed. A full build from the stock Ubuntu image runs once a week and on demand. Task VM enrollment trusts the image for the `app-dev` software, so the image manifest and its fingerprints go away.

## Status

In progress.

Principle: [Lean](/mission#principles). The image build keeps its result and drops the parts that only made one long job survive: the manifest, the host locks with expiry times, the stage budgets, and the cleanup of a job that was killed halfway. It also serves [Deterministic first](/mission#principles): the build row, not a log search, says where a build stopped.

## Context

[ADR 0200](/decisions/0200-run-each-task-group-in-its-own-sandbox-vm) item 1 builds one base image every night and on demand. Today, one job runs the whole build ([Build the base image](/reference/compute-drivers#build-the-base-image)), and the same job then syncs the image to each other host. On beast the build takes about 3.5 minutes. It may run up to 25 minutes, and a sync up to about 24 more.

An audit of the image code on 13 Oct 2026 found that most of its weight and most of its bugs sit around the build, not in it:

- **One long job is fragile.** The build is one job on the `builds` queue. A release during a build waits for it: [Horizon](/reference/gateway-queue#releases) starts no new job on any queue until the build ends, `tasks:tick` included. A job that runs past its timeout loses its work. A `failed()` handler, a signal trap in the command, and a host lock with an expiry time clean up after it. That lock can expire before a long build ends, and the next build then deletes the running one's builder. The workspace template build has the same flaw.
- **The manifest couples the image to enrollment.** The image runs the enrollment programs of an `app-dev` Node and records a fingerprint for each one. Enrollment renders each program again and skips it only when its fingerprint matches. This keeps an older image correct, but it ties every change to an enrollment program to the image.
- **Every build starts from scratch.** Each night the build boots the stock Ubuntu image and installs everything again: the packages, both PHP versions, Composer, Vite+, Bun, Caddy, Docker, and the Rust toolchain.
- **A failure is hard to place.** The log names the stage of a failed build, but a person must find the log line. Nothing records how far a build got.
- **Two builders.** Incus and UpCloud each have their own builder class, and the two already differ: UpCloud skips the lean boot network setup and never checks it.

Off-the-shelf tools were considered first. See [Rejected alternatives](#rejected-alternatives).

## Decision

The Gateway owns the image build. This decision changes item 1 of [ADR 0200](/decisions/0200-run-each-task-group-in-its-own-sandbox-vm) and the manifest skip in [Skip what the image holds](/reference/compute-drivers#skip-what-the-image-holds).

### The build is a chain of jobs

A base image build is one Laravel job chain (`Bus::chain`) on the `builds` queue, with one job for each step. The chain stops at the first step that fails, and its one `catch` callback queues the cleanup. Horizon shows individual jobs, not the whole chain. The build row and `task-vms:image-builds` report the whole build. The chain for an Incus host is:

| Step | What it does |
| --- | --- |
| `LaunchImageBuilder` | Records the source image on the row and launches the builder VM `tvm-image-<build id>` from it |
| `AwaitImageBuilderBoot` | Waits for cloud-init to finish without errors |
| `UpgradeImagePackages` | Runs `apt-get update` and `apt-get full-upgrade`, and installs the image's apt packages |
| One job for each program | Runs one program of the `app-dev` software: `bootstrap`, `caddy-package`, `app-dev-prerequisites`, `vp-manager`, `composer-manager`, the PHP source and each selectable PHP version, `rust-toolchain`, the agent binary, and Pi |
| `PrepareImageBoot` | Applies the single boot and the lean boot |
| `CleanImageBuilder` | Runs `base-image-clean.sh`, which removes the VM's identity |
| `PublishImage` | Stops the builder, publishes it as `orbit-task-base-<time>`, and deletes the builder |
| `SmokeImage` | Boots a VM from the new image with the task VM user-data and runs `image-check.sh` |
| `PromoteImage` | Points `orbit-task-base` at the new image and deletes images that no VM or running image build owns |

The programs are the ones that enrollment renders today, rendered by the same code. A new tool or package is a new program and one new step in the chain. The build of a host's image never edits one shared script.

Each step is idempotent. A step that runs again finds its work done, so a step can fail, retry, or run after a release without harm. A step that waits, such as `AwaitImageBuilderBoot` or `SmokeImage`, checks once and releases itself back to the queue with a delay instead of holding the worker. Laravel counts each release as an attempt, so a waiting step ends at a deadline (`retryUntil()`) instead of after a number of tries. No job runs longer than its step needs, so a release never waits long for a build.

### The build row holds the state

The table `task_vm_image_builds` has one row for each build. It holds the host, the source image, the builder VM name, the new image's fingerprint, the current step, the state (`running`, `succeeded`, or `failed`), a pending queue submission, a cleanup claim, and each step's start, seconds, and error. A partial unique index allows one `running` build for each host. That index replaces the host's image lock and its expiry time.

When a step fails after its retries, the row becomes `failed` with the step and the error, and a cleanup job deletes the builder and smoke VMs, and the new image when it never became the base image. `task-vms:build-image` prints the steps from the row as they finish. A command `task-vms:image-builds` lists recent builds with their steps and errors. A failed build can be continued from its failed step with `task-vms:build-image --resume <build id>`, while its builder VM still exists and cleanup has not claimed it. Cleanup atomically claims the failed row before any remote deletion; resume atomically changes an unclaimed failed row to running. The database decides which wins, including on SQLite, without a host cache lock.

Start and resume persist a pending submission marker with the running state before enqueueing. The dispatcher clears the marker when enqueueing succeeds; a worker also clears it when it claims a running build. An enqueue exception fails a still-pending submission without changing a build already claimed by a worker. The host sweep and the next start or resume fail pending submissions older than ten minutes. This recovers a crash between the durable write and queue acknowledgement without guessing whether an image step ran or replaying an uncertain submission. The submission deadline does not limit a running build's lifetime.

Pruning protects the fingerprints and dated aliases of running image build rows on that host, even between publish and smoke when no VM uses the new image. Alias protection also covers a publish whose fingerprint has not yet been saved.

The [host sweep](/reference/compute-drivers#report-strays) deletes a `tvm-image-<build id>` VM whose build is not `running`, and its stray report reads the same row. Both the host sweep and the base image sweep atomically claim cleanup on a recorded non-running build before deleting either its builder or smoke VM. They share the cleanup job's durable fence against resume: if resume wins, deletion skips the VM; if deletion wins, resume refuses even while the builder still exists. Neither reads a time from the VM's name, so the 60-minute grace for builders goes away.

### Each build starts from the current image

By default a build launches its builder from the host's current `orbit-task-base`. `UpgradeImagePackages` and the program steps then change only what changed since the last build. The first build of a host, a build with `--from-stock`, and the build each Sunday night launch from the stock image `ubuntu-26.04-vm` instead. So the image never holds leftovers of removed packages or programs for more than a week.

The source image of a build never ran an agent or a Project script: it is a published base image, cleaned by `base-image-clean.sh`. This keeps the reason for the rejected "copy of a finished task VM as the next image" in [ADR 0200](/decisions/0200-run-each-task-group-in-its-own-sandbox-vm) intact.

### Enrollment trusts the image

A task VM boots from a base image that the same Gateway code built. Its enrollment no longer runs the `app-dev` software programs and no longer reads a manifest. It still renders and applies everything that is specific to the VM: the host key, WireGuard, the firewall, Caddy, DNS, the agent and its secret, and Pi. The manifest file, `TaskVmBakedPrograms`, and the build's `manifest` step go away.

A change to one of those programs reaches task VMs with the next nightly image build, or at once with `task-vms:build-image`. Long-lived `app-dev` Nodes still run every program at enrollment, as today.

### Next steps in the same model

Each of these gets its own pull request after the base image chain is built, as its own chain with its own row:

1. **Sync.** The copy to each other host becomes a chain for that host: export, transfer, verify, smoke, and promote. A failed host names its step, and the other hosts go on.
2. **Workspace templates.** A template build becomes a chain: launch, volume, clone, one step for each setup step, warm check, verify, detach, and publish. Its budget, its lock with an expiry time, and its retry count go away with the long job.
3. **UpCloud.** The UpCloud template runs the same steps through its SSH guest shell, so both providers run one list of steps.

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
- A new package or tool is one program and one step, not an edit to a shared script.
- A nightly build that starts from the current image does less work. Its time depends on what changed.
- A build is no longer byte-for-byte reproducible from the stock image each night. The weekly full build limits the drift to a week.
- A task VM can run software that is up to a day older than the Gateway's programs. A tool that the image lacks shows only when a setup step or a check that needs it fails. Run `task-vms:build-image` after a change to a program.
- The `builds` queue runs one job at a time in the fleet, so the steps of a base image build and of a template build take turns. A build takes longer from start to end, but no single job holds the queue.
- This change removes the image manifest, the image host lock with its expiry time, the build's signal trap and `failed()` cleanup, and the stage runner of the base image build.

## Affects

- Components: apps/gateway, apps/docs
- ADRs: [ADR 0200](/decisions/0200-run-each-task-group-in-its-own-sandbox-vm), item 1 (image), the manifest, and the enrollment skip in [Skip what the image holds](/reference/compute-drivers#skip-what-the-image-holds)
- Detail: [Compute drivers: Build the base image](/reference/compute-drivers#build-the-base-image), [Compute drivers: Jobs](/reference/compute-drivers#jobs), [Gateway queue](/reference/gateway-queue)
- Verify: The proofs on beast check these outcomes.
  - A build on beast runs each step as its own job on the `builds` queue, and the row lists each step with its seconds.
  - A build launched from the current `orbit-task-base` and a `--from-stock` build both pass the smoke check.
  - A step made to fail leaves the row `failed` with that step, deletes the builder, and keeps the current image. `--resume` then continues from that step.
  - A release during a build starts the new Horizon after the running step, and the next step runs on the new release.
  - A task VM from the new image enrolls without running the `app-dev` software programs, and a Project's baseline check passes on it.
