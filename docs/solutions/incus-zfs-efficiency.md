---
title: "Reduce Incus VM costs on ZFS"
description: "Measured findings on topology disk size, dependency-copy writes, and VM memory, with the limits that failed."
covers:
  - apps/e2e/config/e2e.php
  - apps/e2e/app/E2E/IncusHost.php
---

# Reduce Incus VM costs on ZFS

## Problem

An [Incus topology](/reference/incus-topologies) is a Gateway, an operator, and the declared workload VMs, nested in the group's task VM. Disk copies are cheap, but prepared image contents, new writes in each guest, and each VM's memory allocation still cost resources. These findings say which savings are real and which settings break.

## Cause

Each guest is a copy of the task VM's `node-template`: a btrfs snapshot in the pool `topology-seed`, which is a ZFS clone of the host's [topology seed](/reference/compute-drivers#the-topology-seed). The pool uses LZ4 and sparse 16 GiB root volumes with no reservation. A copy keeps the template's root disk size. The guest root filesystem is ext4, which has no file reflinks, so a [dependency copy](/domains/applications#dependency-copy) writes the data again.

## Solution

**Clean the base image before it is published.** Removing unused Docker images and APT archives, then `fstrim /`, cut Gateway referenced disk data from 6.55 GiB to 1.98 GiB and app-prod from 7.07 GiB to 2.48 GiB. Run `sync -f /` before the trim, or discard cannot reach blocks whose deletions are still pending. Trimming a clone does not free blocks its parent image still references, so measure the ZFS volume, not Docker's reclaimed-space report. The base snapshot must be trimmed before new task VMs copy it.

**Size memory per Node.** Gateway 1.5 GiB, app-dev 2 GiB, app-prod 1 GiB passed the workload and all readiness checks after reboots. That is a 4.5 GiB budget instead of 6 GiB.

| Gateway | app-dev | app-prod | Result |
| --- | --- | --- | --- |
| 2 GiB | 2 GiB | 2 GiB | Passed. |
| 1 GiB | 1.5 GiB | 1 GiB | HTTP and simple commands passed. Readiness failed: the kernel killed PHP on Gateway and MySQL on app-dev. |
| 2 GiB | 2 GiB | 1 GiB | Passed. |
| 1.5 GiB | 2 GiB | 1 GiB | Passed. |

An idle-memory reading did not predict the failure. The verifier starts several commands together, and the database Node needs room for them and its services. Keep app-dev at 2 GiB. A blanket 1 GiB or 1.5 GiB setting is not supported.

**Reflinks help dependency copies, but only on a different filesystem.** Three copies of a real Laravel `vendor` tree (8,850 files, about 46 MiB) used 19.1 MiB on XFS against 222.0 MiB on ext4. Synthetic `vendor` and `node_modules` (about 260 MiB) used 7.1 MiB against 815.4 MiB. Using XFS needs a base image change or a workload disk with its own snapshot and cleanup lifecycle. It is still a candidate; do not ship a loop-device filesystem.

**Do not add a trim scheduler.** Guest block discard works and `fstrim.timer` is on. A trim before any cleanup released only tens of MiB.

## Limits

The numbers come from the sample topology, on 2 October 2026, with Incus 6.0.5, host ZFS on the `fast` pool, and Ubuntu 26.04 guests. They do not set memory needs for larger applications, parallel PHP test suites in guests, or long database load. Configured memory is a limit, not resident memory. The XFS timings ran after the ext4 timings on a shared host, so treat them as indicative; the space results are stronger.

The measurements also found two production-scan bugs. After SSH starts in the private `/home/orbit` directory, `sudo -u` changes the user but not the working directory, and GNU `find` cannot restore it. Source classification failed with `app-prod.source_classification_failed`, and retained-release listing failed with `deployment.receipt_invalid`. Any script that switches user with `sudo -u` must enter `/` first.

## Verification

Use the [Incus proof workflow](/reference/incus-topologies#commands) to repeat a measurement. Record guest filesystem allocation, ZFS `used` and `referenced`, clone `origin`, configured memory, guest memory pressure, kernel out-of-memory events, and the readiness result. Let a ZFS transaction commit before you read its accounting. Keep file-content and write-isolation checks apart from copy timing.
