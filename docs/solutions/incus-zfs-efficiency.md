---
title: "Reduce Incus image and clone costs on ZFS"
description: "Practical ZFS trimming, reflink, and memory sizing lessons for Incus images and nested fleets."
---

# Reduce Incus image and clone costs on ZFS

## Problem

Base images and cloned guests can retain gigabytes of deleted packages, Docker layers, and duplicated dependency trees on ZFS if cleaned or measured in the wrong order.

## Solution

Apply these rules when preparing base images or measuring nested-fleet storage and memory:

- **Sync before trimming:** Clean APT caches and Docker images inside the base image, then run `sync -f /` before `fstrim /`. Without `sync -f /`, discard skips pending deletions.
- **Measure ZFS, not guest tools:** Trimming a clone never frees blocks still referenced by its parent snapshot. Measure ZFS `used` and `referenced` after a transaction commits rather than Docker's reclaimed-space output.
- **Do not add a trim scheduler:** Ubuntu guests already enable `fstrim.timer`.
- **Reflinks need a reflink filesystem:** Dependency copy savings require XFS or btrfs rather than ext4; do not ship loop-device filesystems.
- **Size memory under load:** Idle memory readings do not predict OOM kills during parallel verification commands and database activity.

## Verification

Measure candidate changes inside a [task copy's nested fleet](/reference/tasks#build-a-feature-in-one-copy). Record guest filesystem usage, ZFS `used` and `referenced`, clone `origin`, memory pressure, kernel OOM events, and readiness results.
