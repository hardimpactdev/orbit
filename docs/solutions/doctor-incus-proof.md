---
title: "Prove the verify-only Doctor on an Incus topology"
description: "Fixture gotchas when you prove that Doctor reports drift without repairing it, on disposable machines."
---

# Prove the verify-only Doctor on an Incus topology

## Problem

A proof of the verify-only Doctor needs a healthy baseline, exactly one declared drift, exactly one declared unverifiable condition, and evidence that Doctor writes nothing. Each fixture must produce one finding and leave every other inspection untouched. Several things make a fixture produce more than one finding, or make the write check fail for the wrong reason.

## Cause and solution

**One fixture, one inspector.** Doctor reports one finding per inspector that fails, and it reads facts the Node agent reported: `units` every 10 seconds, and `host` and `ufw` every 60 seconds. Wait that long after you change a Node. A check whose fact has not arrived is unverifiable.

- To prove the unverifiable condition, run Doctor with `--family=firewall` against a Node that was just enrolled and has not yet reported `ufw`.
- For the healthy baseline, wait for the next report of every fact first.

**Low disk spoils a baseline.** The Node inspector reads free space and free inodes for root and the managed user's home, including on the Gateway. Remove only fixtures the lease owns, or use a fresh topology. A role converge does not free disk.

**A stable restart count is not drift.** `active` alone does not prove a Process is healthy. Doctor reports `process.crash_loop` when it sees auto-restart or a growing restart count. Restore the fixture and record a clean baseline first; Doctor does not restart it.

**The write check sees files that are not Doctor writes.** SQLite creates and removes `-wal` and `-shm` sidecars for any connection, even a read-only one. The Caddy build check creates a lock file under `locks/caddy-build/`, and the file stays. Exclude both from the Orbit home inventory. Only `activity_log` should grow, by one audit row per Doctor request.

**Planted repository callbacks.** Doctor's Git probes pass `-c core.hooksPath=/dev/null` and `-c core.fsmonitor=false`. A read-only Git verb alone is not evidence that a checkout-selected program cannot write files, so plant a hook or monitor in a disposable checkout the lease owns, save its Git configuration first, and restore it before release.

**Drift: edit generated output, not `local.conf`.** The PHP-FPM check compares each generated file byte for byte. Change `listen.mode = 0660` to `0666` in `/etc/orbit/php-fpm/<user>/generated/pool.conf` of a production Instance. Do not edit `pm.max_children`: it is in `local.conf`, which Doctor checks only for structure, and the running master reads the pool file only at its next reload.

**Converge before the first scan.** A converge rewrites the files Orbit owns, including the pool file. `acquire` enrolls the Nodes with the worktree's Gateway code at that moment, so a later edit that changes an expected projection shows drift in the baseline until you converge the role.

**Informational findings.** Tool discoveries may appear in a healthy baseline. They change neither health nor exit status. A certificate finding on the public Route edge is not the declared drift and must be absent from the baseline.

## Limits

Incus proves Linux behavior only. [macOS enrollment](/reference/node-provisioning#macos-nodes), native platform and home-volume observation, and tool operations need a separate task-owned fixture on a real Mac. Router LAN firewall coverage follows the [member-rule contract](/cli/firewall#router-lan-member-rules). Doctor detects managed drift but does not prove unauthorized LAN denial or authorize fleet changes. A disposable rehearsal is not live acceptance.

## Verification

Lease a topology with `bin/e2e-topology acquire`, run each step with `bin/e2e-topology exec ... --record=LABEL`, and read `<worktree>/.e2e/evidence.log`; the [Incus topology registry](/reference/incus-topologies#commands) lists the commands. The baseline and both restored reports show exit `0` and no findings. The drift report shows exit `1` with exactly one `instance.php_fpm_projection_mismatch`. The unverifiable report shows exit `1` with exactly one `role.inspection_failed`. The scans before and after differ only in the `activity_log` row count.
