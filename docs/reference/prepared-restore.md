---
title: "Prepared restore"
description: "How the Python power helper saves resident page ranges when a container sleeps, faults them back in parallel before unfreezing, and coordinates with fast wake."
covers:
  - swamp/ansible/files/instance-container-power.py
  - swamp/ansible/instance-container-power.yml
  - swamp/ansible/tasks/instance-container-power.yml
  - swamp/ansible/tasks/instance-power-helper.yml
  - swamp/ansible/tasks/instance-power-call.yml
---

# Prepared restore

A sleeping development container moves its memory into swap ([Sleep and wake](/reference/development-containers#sleep-and-wake)). Without prefetching, pages fault back one by one on first use. Prepared restore records each process's resident readable pages while the container is frozen, reads them back in parallel on wake, and then unfreezes the container once ([ADR 0261](/decisions/0261-restore-a-sleeping-containers-memory-before-it-resumes)).

> **Signed power alternative:** When [signed power delivery](/reference/executor#signed-power-delivery) is enabled, Orbit routes container sleep and wake through the Rust [executor](/reference/executor#rust-sleep-and-discard) instead of the Python helper described here. Do not transfer frozen plans between engines.

## Scope

Prepared restore runs on Linux `x86_64` hosts with cgroup v2 for `orbit-instances` development containers and `orbit-releases` preparation containers. Nested-fleet containers (`orbit-instances-nested`) and any Instance running on a nested fleet host Node (`/etc/orbit/instance-fleet-node`) use the helper for swap and resume, but skip plan capture (`placement_unproven`) and never use fast wake.

Plans store process identities and address ranges only, never page contents. If a plan is missing, stale, or invalid, the helper falls back to an ordinary resume.

## The power helper

`app-dev` and `app-prod` role convergence installs `/usr/local/libexec/orbit-instance-power` (Python 3 using `ctypes`):

| Command | Behavior |
| --- | --- |
| `capabilities` | Probes cgroup v2, `/proc/<pid>/maps`, `pagemap`, `pidfd_open`, `process_madvise`, and `process_vm_readv` as `orbit-instance-power-probe` and writes `capabilities.json` |
| `prepare --instance <id> --operation <opid>` | Opens swap, freezes, captures the plan, and reclaims memory |
| `restore --instance <id> --operation <opid>` | Validates the plan, restores pages in parallel, unfreezes, and closes swap limits |
| `residual --instance <id> --operation <opid>` | Reads `memory.stat` once and updates anonymous/file bytes in `sleep.json` |
| `discard --instance <id> [--plan-only]` | Removes helper files (or only the plan with `--plan-only`) |
| `--release <name>` | Replaces `--instance <id>` for `orbit-releases` preparation and release containers (`prepare`, `restore`, `residual`, `discard`) |

`prepare` and run-invoked `restore` read up to 16 KiB of JSON on stdin (`schema_version: 1`, `instance_id`, `operation_id`, `incus_project` in `orbit-instances`, `orbit-instances-nested`, or `orbit-releases`, `incus_name`, and `web`). Every command prints up to 64 KiB of JSON on stdout and exits `0` for handled outcomes or `70` (`instance.power_helper_failed`) on crash or invalid input.

Every command except `capabilities` runs inside the transient systemd unit `orbit-instance-power-<id>.service` (configured via `/etc/systemd/system/orbit-instance-power-.service.d/orbit.conf` with `CAP_SYS_PTRACE`, `CAP_SYS_NICE`, `CAP_DAC_OVERRIDE`, `LimitNOFILE=16384`, `NoNewPrivileges=yes`, `ProtectSystem=strict`, and write access restricted to `/var/lib/orbit/hibernation`, `/dev/shm/orbit/hibernation`, and `/sys/fs/cgroup`). Because systemd permits only one unit per name, the unit acts as the per-Instance lock. Ansible retries a busy lock 40 times at 0.25 s intervals (10 s) before failing with `instance.power_helper_busy`; the fast wake worker declines immediately.

## Files on the Node

The helper stores private root-owned state under `/var/lib/orbit/hibernation` and public status markers under readable folders:

| Path | Mode | Contents |
| --- | --- | --- |
| `/var/lib/orbit/hibernation/instances/<id>/` | `0700` | Private state for one development Instance |
| `/var/lib/orbit/hibernation/releases/<name>/` | `0700` | Private state for one `orbit-releases` container |
| `…/target.json`, `…/limits.json`, `…/sleep.json` | `0600` | Target descriptor (`incarnation`, `web`), opened cgroup swap limits, and sleep summary |
| `…/plan/manifest.json`, `…/plan/ranges.bin` | `0600` | Atomically committed plan (`ORBRNG1\0` little-endian binary table + SHA-256 manifest) |
| `…/receipts/op-<opid>.json` | `0600` | Detailed metrics for one Operation (newest 16 retained) |
| `/var/lib/orbit/hibernation/outcomes/` | `0755` | Fast wake status files (`0644`) |
| `/dev/shm/orbit/hibernation/` | `0755` | Fast wake request and asleep markers |
| `/var/lib/orbit/hibernation/capabilities.json` | `0644` | Kernel probe manifest |

## Plan validation and bounds

`prepare` writes `manifest.json` and `ranges.bin` into a temporary directory, fsyncs both files and the directory, and renames it into `plan/`. `restore` validates the header magic (`ORBRNG1\0`), exact byte size, SHA-256, process tiling, page alignment, sorted non-overlapping ranges, and `2^56` user-address bound before touching memory.

Global safety bounds prevent slow or oversized plans from delaying power operations:

| Bound | Limit |
| --- | --- |
| `prepare` capture / `restore` prefetch | 30 s / 2 s |
| Worker threads | `min(32, processes with work)` |
| Processes / ranges / metadata / residency | 4,096 / 1,048,576 / 64 MiB / `min(memory.max, 8 GiB)` |
| Syscall batch / `madvise` piece / pagemap chunk | `min(512, IOV_MAX)` iovecs / 1 GiB / 64 Ki pages |
| Residual settle / `incus resume` / kernel thaw | 20 × 0.1 s / 1 s / 5 s |
| Fast wake request age / readiness probe / Gateway poll | 1 s / 1.5 s / 6 s (every 25 ms) |

## Sleep and wake

During sleep, `prepare` records opened `memory.swap.max` limits in `limits.json`, pauses the container, captures readable present pages (skipping `[vvar]`, `[vdso]`, `[vsyscall]`), and writes `memory.reclaim`. Ansible polls `residual` up to 20 times; if more than 32 MiB of anonymous memory remains, non-nested containers stop (`discard --plan-only`, then stop) while nested-fleet containers resume and fail.

During wake, `restore` verifies `host_boot_id`, `incarnation`, cgroup device/inode, init process, and each process's `pidfd` and `/proc/<pid>/stat` start time before each batch. Worker threads call `process_madvise(MADV_WILLNEED)` followed by one-byte `process_vm_readv` reads per page, then unfreeze via Incus and restore `limits.memory.swap` on the container and inner cgroups. A recreated container with a new incarnation discards old state automatically.

### A freeze that Incus lost

Before running any `incus exec`, wake and deploy check `cgroup.freeze` and `cgroup.events` on containers Incus reports as `Running`. If the kernel still holds the cgroup frozen, `restore` writes `0` to `cgroup.freeze` and waits up to 5 seconds for `frozen 0` (setting `kernel_frozen: true` and treating the plan as stale). If the kernel does not thaw within 5 seconds, or if `prepare` encounters a kernel-frozen running container, the helper fails with `instance.container_frozen_unmanaged` while preserving recovery state.

## The fast wake

For eligible frozen non-nested development Instances (where `app-dev` convergence installed `orbit-instance-wake@.service`), the Gateway avoids starting a Swamp run by writing a marker request under `/dev/shm/orbit/hibernation/`:

| File | Written by | Meaning |
| --- | --- | --- |
| `app-instance-<id>-op-<opid>.wake` | Gateway (`hibernation.marker`) | Wake request |
| `app-instance-<id>-op-<opid>.taken` | Dispatcher | Claimed and handed to `orbit-instance-wake@<id>-<opid>.service` |
| `/var/lib/orbit/hibernation/outcomes/…ready-<status>` | Worker | Readiness passed and asleep marker removed (`complete`, `partial`, `skipped`, or `timed-out`) |
| `…outcomes/…done` / `…failed` | Worker | Final outcome committed, or declined so Swamp continues the Operation |

The Gateway polls status files every 25 ms for up to 6 seconds (`hibernation.times`). On `.done` with a single `.ready-*`, the Operation succeeds immediately and cleans up the files asynchronously; on `.failed` or timeout, the same Operation hands off to its Swamp workflow.

## Results

Sleep and wake Operations record `prepared_restore` (schema version `1`) in `Operation.result`:

- **Sleep:** `status` (`ready`, `unsupported`, `skipped`, or `not_applicable`), optional `reason`, `generation` UUID, process/residency/metadata/duration metrics, and `residual_anon_bytes` / `residual_file_bytes`.
- **Wake:** `restore_mode` (`prepared` or `ordinary`), `ready_at`, `ready_at_resolution_seconds` (`1` for fast wake, `0.001` for Swamp runs), and `prepared_restore.status` (`complete`, `partial`, `skipped`, `timed_out`, or `not_applicable`) with closed `reason` codes (`no_plan`, `plan_invalid`, `plan_stale`, `unsupported`, `placement_unproven`, `bound_processes`, `bound_ranges`, `bound_metadata`, `bound_residency`, `prepare_timeout`, `capture_failed`, `stopped`, `deadline`, `nothing_read`).

## Errors

The power helper reports three error codes:

| Code | Meaning |
| --- | --- |
| `instance.power_helper_busy` | Another helper unit for this Instance remained active past the 10-second retry window |
| `instance.power_helper_failed` | Helper rejected invalid stdin or crashed (exit 70) |
| `instance.container_frozen_unmanaged` | Kernel held the cgroup frozen while Incus reported running and thaw timed out after 5 seconds |
