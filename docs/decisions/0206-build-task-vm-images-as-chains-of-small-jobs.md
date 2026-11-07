---
title: "ADR 0206: Build task VM images as chains of small jobs"
sidebarTitle: "0206 Image build jobs"
description: "Retired. The Incus recipe image build is gone; a host's base is the snapshot of its base VM, made only by the base snapshot refresh."
---

# ADR 0206: Build task VM images as chains of small jobs

**Retired 2026-11-07.** The Incus recipe image build that this ADR describes is removed: no chain, no `task_vm.image_build` kind, no per-minute recipe reconcile and no image reaper. Each host keeps a base VM, and the snapshot that the [base snapshot refresh](/reference/compute-drivers#refresh-the-base-snapshot) proves is the base of every task start. The text below stays as history.

The Gateway builds the task VM base image with a chain of small queued jobs. One `task_vm_image_builds` row records its progress and failure. ADR 0207 replaced the image inputs, build phases, triggers, sync, and workspace plans; the [machine software reference](/reference/compute-drivers#machine-software-recipe) holds them.

## Status

In progress.

[ADR 0247](/decisions/0247-keep-fleet-software-current-through-tested-recipe-changes) replaces this image build: task VMs will boot from the app-dev topology template.

Principle: [Lean](/mission#principles). The build row, not a log search, says where a build stopped. It also serves [Deterministic first](/mission#principles).

## Context

The task VM image build was one long job on the `builds` queue. Three problems remain the reason for this decision:

- **One long job is fragile.** A release during a build waits for it, because [Horizon](/reference/gateway-queue#releases) starts no new job on any queue until the build ends. A job past its timeout loses its work and relies on a `failed()` handler and a host lock with an expiry time. That lock can expire before the build ends.
- **A failure is hard to place.** The log names a stage, but nothing records how far a build got.

## Decision

The Gateway owns the image build. See [the base VM and its snapshot](/reference/compute-drivers#the-base-vm-and-its-snapshot).

### The build is a chain of jobs

A base image build is one Laravel job chain (`Bus::chain`) on `builds`. A failed step stops the chain and records its error. The build row reports the whole build; Horizon shows individual jobs.

### The build row holds the state

The table `task_vm_image_builds` has one row for each build. It holds the host, the source image, the builder VM name, the new image's fingerprint, the current step, the state (`running`, `succeeded`, or `failed`), a pending queue submission, a cleanup claim, and each step's start, seconds, and error. A partial unique index allows one `running` build for each host. It replaces the host's image lock and its expiry time.

- **Failure.** The row becomes `failed` with the step and the error. Its builder, smoke VM, and published image stay for 24 hours, until the next build on that host, or until explicit discard.
- **Resume.** `task-vms:build-image --resume <build id>` continues a failed build from its failed step while cleanup has not claimed it and the step's resources still exist. Cleanup atomically claims a failed row before any remote deletion; resume atomically changes an unclaimed failed row to running. The database decides which wins, with no host cache lock.
- **Submission.** Start and resume write a pending submission marker with the running state before they enqueue. A worker clears it when it claims the build. The host sweep and the next start or resume fail a pending submission older than ten minutes.
- **Pruning and sweeps.** Pruning protects the fingerprints and dated aliases of running and retained failed builds on that host. The [host sweep](/reference/compute-drivers#report-strays) and the base image sweep claim cleanup on a non-running build before they delete its builder or smoke VM.
- **Reads.** `task-vms:build-image` prints steps as they finish, and `task-vms:image-builds` lists recent builds with their steps and errors.

### Enrollment trusts the image

A task VM boots from a base image that the same Gateway code built. Its enrollment no longer runs the `app-dev` software programs and reads no manifest. It still applies everything that is specific to the VM: the host key, WireGuard, the firewall, Caddy, DNS, the agent and its secret, and Pi.

## Rejected alternatives

- **One provisioning script for the whole image.** A failure is a line in a long log, not a named step that can be retried alone.
- **Packer, a Packer plugin of Orbit's own, or distrobuilder.** Each adds a second tool and a second definition of the `app-dev` software. Packer's Incus plugin is a small community fork. Packer also does none of the smoke boot or promote.
- **Ansible for the image.** The image and the enrollment of long-lived Nodes would drift.
- **Keep the manifest and only split the job.** The manifest couples every enrollment program change to the image.

## Consequences

- A release waits at most for one short step instead of a whole build.
- A failed build names its step on its row, and it can be continued from that step.
- The image manifest, the image host lock with its expiry time, the build's signal trap and `failed()` cleanup, and the stage runner are gone.
- **Amended 2026-11-02:** a new claim falls back to its host's last promoted image when that image is at most one day old, unless the group sets `current_recipe`. See [Image fallback](/reference/compute-drivers#image-fallback).

## Affects

- Components: apps/gateway, apps/docs
- ADRs: none in progress. [The base VM and its snapshot](/reference/compute-drivers#the-base-vm-and-its-snapshot) and [Skip what the image holds](/reference/compute-drivers#skip-what-the-image-holds) document the build and enrollment.
- Detail: [Compute drivers: The base VM and its snapshot](/reference/compute-drivers#the-base-vm-and-its-snapshot), [Compute drivers: Jobs](/reference/compute-drivers#jobs), [Gateway queue](/reference/gateway-queue)
- Verify: a step made to fail leaves the row `failed` with that step and keeps the current image, and `--resume` continues from it. A release during a build starts the new Horizon after the running step.
