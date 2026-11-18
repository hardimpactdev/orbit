---
title: "Prepared restore"
description: "How a sleeping development container gets its memory back before it resumes: the power helper on the Node, its plan, its bounds, the wake's state table, the fast wake's files and the result fields. ADR 0261, in progress."
---

# Prepared restore

A sleeping development container keeps its memory in swap ([Sleep and wake](/reference/development-containers#sleep-and-wake)). Without help, each page comes back on first use, one fault at a time, so the first request after a wake is slow. Prepared restore fixes this. At each sleep, while the container is frozen, Orbit records which readable pages of every process were resident. At the wake, while the container is still frozen, Orbit reads those pages back from swap in parallel, and then it unfreezes the container once.

This page is the contract for [ADR 0261](/decisions/0261-restore-a-sleeping-containers-memory-before-it-resumes), which is in progress. The [sleep](/cli/instance/sleep) and [wake](/cli/instance/wake) charts show the steps.

> **Disabled-mode engine.** This page describes the Python path. With [signed power delivery](/reference/executor#signed-power-delivery) enabled, [ADR 0266](/decisions/0266-sleep-and-wake-containers-through-a-signed-order-executor) instead routes power to the Node's Rust executor. The executor uses its own plan and restore pool. Do not transfer frozen plans between engines.

| Rule | Meaning |
| --- | --- |
| Generic | Every process in the container's cgroup subtree counts. Nothing selects by name, runtime, framework, container server or route, and there is no training. |
| Nothing kept in RAM | All memory goes to swap, as before. A plan holds identities, addresses and lengths, never memory contents. |
| Restore before the unfreeze | The container stays frozen until the restore completes or reaches its bound. |
| A hint only | The kernel's swap stays the truth. A missing, stale or failed plan means an ordinary resume, never an unbootable container. |
| Ordinary Operations | Each sleep and wake is an `instance.sleep` or `instance.wake` Operation with its usual access checks, idempotency and Instance lock. |

## Scope

Prepared restore runs for development containers on `app-dev` Nodes with Linux x86_64 and cgroup v2. It covers defaults and clones. Nested-fleet Instances (Incus project `orbit-instances-nested`) use the same helper for swap and resume, but `prepare` records no plan for them (`skipped`, reason `placement_unproven`), and they never take the fast wake. Checkout Instances, VMs and macOS do not sleep this way. Planned: a production Instance's live release container sleeps this way on its `app-prod` Node, whose role installs the helper and the fast wake ([ADR 0265](/decisions/0265-sleep-idle-production-containers)).

A Node gets the feature from the `app-dev` convergence. Orbit changes no host-wide setting: no swap, zswap, THP, readahead or sysctl setting, and no kernel module. Sleep writes only the container's own cgroup files: `memory.swap.max` and `memory.reclaim`.

The plan brings back what was resident before the freeze. It does not warm a route that was already cold, or memory that was already in swap before the sleep.

## The power helper

The `app-dev` role installs the helper at `/usr/local/libexec/orbit-instance-power`. It is one Python 3 program that calls the kernel through ctypes. The sleep and wake phases install the helper alone with the same shared task, so a run never depends on when the role last converged. Only the role's convergence installs the fast wake's units ([Eligibility](#the-fast-wake)).

| Command | Does |
| --- | --- |
| `capabilities` | Probes the kernel and writes the capability manifest. Runs as the unit `orbit-instance-power-probe`, not the Instance's lock. |
| `prepare --instance <id> --operation <opid>` | Sleeps the container: opens swap, pauses, records the plan, reclaims. Reads the Instance's intent on stdin. |
| `restore --instance <id> --operation <opid>` | Wakes the container: validates the plan, restores pages, unfreezes, closes swap. Reads the intent on stdin when a run calls it; the fast worker sends none. |
| `residual --instance <id> --operation <opid>` | Reads the frozen container's `memory.stat` once and stores its anonymous and file bytes in `sleep.json` of the sleep `<opid>`. The sleep phase's counter settle calls it. |
| `discard --instance <id>` | Removes all of the Instance's helper files: plan, sleep result, limits record, target descriptor, receipts, and its request and outcome files. |
| `discard --instance <id> --plan-only` | Removes only the plan. The stop path of a sleep uses it. |

`<id>` and `<opid>` are strictly positive decimal numbers. They are the only arguments that change; `--plan-only` is a fixed flag. The helper takes no PID, cgroup path, memory range, executable or path from a caller. A deadline is a thread join with a timeout and a check before each batch; the helper has no sleep or polling loop.

**Input.** `prepare`, and `restore` when a run calls it, read one JSON object on stdin, at most 16 KiB. The run builds it from the Gateway's intent. It holds no secret.

| Field | Value |
| --- | --- |
| `schema_version` | `1` |
| `instance_id`, `operation_id` | Positive integers; each must equal its argument |
| `incus_project` | `orbit-instances` or `orbit-instances-nested`, from the recorded runtime |
| `incus_name` | `instance-<id>` from the recorded runtime |
| `web` | `{address, port}`: the container's recorded address and web port; `null` without a web unit |

A field that is missing, unknown or out of range makes the helper refuse with `instance.power_helper_failed` before it touches anything.

**Output.** Every command prints one JSON object of at most 64 KiB on stdout and exits 0 for each outcome it handled. Any other end, such as an exception or a signal, is a crash. The helper's top-level handler turns an exception into `outcome: failed` with `error_code: instance.power_helper_failed` and exit code 70. The caller treats a crash as a failure of its phase and never retries it.

| Field | Commands | Value |
| --- | --- | --- |
| `schema_version` | all | `1` |
| `command`, `instance_id`, `operation_id` | all | Echo of the call; `operation_id` is `null` for `discard` and `capabilities` |
| `outcome` | all | One closed value per command; see the list below the table |
| `error_code` | all | `null`, or `instance.container_freeze_failed` (`prepare`), `instance.container_start_failed` (`restore` could not resume), `instance.container_frozen_unmanaged` (see [A freeze that Incus lost](#a-freeze-that-incus-lost)) or `instance.power_helper_failed`. A missing container or another incarnation is `outcome: missing`, which the caller maps to its existing error |
| `state_before`, `state_after` | `prepare`, `restore`, `residual` | `running`, `frozen`, `stopped` or `missing`. Incus's word, except where the helper read the kernel: a container that the kernel holds frozen is `frozen`, whatever Incus says |
| `swap_subtree` | `prepare`, `restore` | Inner cgroups that this sleep opened, or that this resume closed |
| `prepared_restore` | `prepare`, `restore`, `residual` | The [result object](#results). `prepare` returns it without the residual fields when it froze the container, and with them when it found the container frozen; `residual` returns it with them |
| `seconds` | `prepare`, `restore` | `{pause, capture, publish, reclaim}` or `{load, restore, resume, close}`, each in seconds; a step that did not run is absent |
| `capabilities` | `capabilities` | Each primitive and whether it works |

Outcomes per command:

- `prepare`: `frozen`, `already_frozen`, `stopped`, `missing` or `failed`;
- `restore`: `resumed`, `already_running`, `stopped`, `missing` or `failed`;
- `residual`: `recorded` or `not_recorded`; `not_recorded` changes nothing, and means the container is not frozen or `sleep.json` belongs to another sleep;
- `discard`: `discarded`, with `removed`: whether any of its files was there, so a repeated destroy reports no change; `capabilities`: `checked`.

A `failed` outcome also says whether the container is left running or frozen, through `state_after`. A crash leaves no such fact, so the caller keeps the asleep marker.

**The lock.** Every command except `capabilities` runs as the transient unit `orbit-instance-power-<id>.service`:

```sh
systemd-run --unit=orbit-instance-power-<id> --wait --pipe --collect --quiet \
  /usr/local/libexec/orbit-instance-power restore --instance <id> --operation <opid>
```

The caller passes no property. The role writes them once, as the drop-in `/etc/systemd/system/orbit-instance-power-.service.d/orbit.conf`, which systemd applies to every unit whose name starts with `orbit-instance-power-`.

systemd allows one loaded unit of a name, so a second start fails at once: the name is the lock. It serializes the run, the fast worker, a deploy or start, and a destroy for one Instance. Other Instances are not blocked.

The helper reads its own unit from `/proc/self/cgroup` and refuses to run outside it. When the helper exits and systemd collects the unit, the lock is free. A helper stuck in uninterruptible I/O keeps the unit active, so no second worker touches that memory. The lock follows the helper, not its caller: a caller whose SSH connection drops leaves the helper to finish.

**Busy.** The lock is busy only when `systemd-run` exits nonzero, prints nothing on stdout, and its stderr carries systemd's `UnitExists` refusal: `Unit orbit-instance-power-<id>.service was already loaded or has a fragment file.` That covers a helper still at work and a unit that systemd has not yet collected. Any other nonzero end is a crash, as above. A caller that finds the lock busy acts as follows:

| Caller | Busy lock |
| --- | --- |
| An Ansible phase | Waits with `until` and `retries`, 0.25 s apart, at most 40 tries (10 s); then fails with `instance.power_helper_busy`. The asleep marker stays. |
| The fast worker | Declines with `.failed` and makes no second try. The Operation continues in its run. |

The unit runs as root with only the capabilities the kernel requires:

- `CAP_SYS_PTRACE` for `pagemap` and `process_vm_readv`;
- `CAP_SYS_NICE` for `process_madvise`;
- `CAP_DAC_OVERRIDE` for cgroup files that the container's root owns.

It may hold 16,384 open files, two for each of up to 4,096 processes and room for the rest. It also has no new privileges, and a read-only system except its folders and the cgroup tree. Only `/var/lib/orbit/hibernation`, `/dev/shm/orbit/hibernation` and `/sys/fs/cgroup` are writable. Each call opens a new connection to the Incus Unix socket.

**Capabilities.** `capabilities` checks root access to cgroup v2, `/proc/<pid>/maps` and `pagemap`, `pidfd_open`, `process_madvise` and `process_vm_readv`. It runs as the unit `orbit-instance-power-probe`, so it has the same properties and capabilities as every restore. It checks each call the restore makes, the pidfd liveness check included, against a process of another uid: systemd starts `orbit-power-probe-target.service` as `nobody` for it and stops it after, as a container's processes run under another uid. The probe first stops a target that an earlier probe left, and systemd ends any target after 30 s. The manifest so states what the unit can really do.

The probe writes `/var/lib/orbit/hibernation/capabilities.json` (root, `0644`): the helper's version, the plan schema version, the result of each primitive, and the helper's SHA-256. The shared install task runs it when the helper changed or the manifest is missing.

Missing capability means `prepare` records no plan (`unsupported`); swap, pause and resume work as before. The convergence output lists each probe's result. The Gateway learns nothing new from the agent: the fast wake checks only that the role installed its units.

## Files on the Node

The helper keeps its state in fixed folders. Private files belong to root alone; status files that the Gateway observes are readable.

| Path | Owner, mode | Holds |
| --- | --- | --- |
| `/var/lib/orbit/hibernation/instances/<id>/` | `root:root 0700` | One Instance's private state |
| `…/<id>/target.json` | `0600` | The target descriptor |
| `…/<id>/limits.json` | `0600` | The swap limits this sleep opened |
| `…/<id>/sleep.json` | `0600` | The public result of the sleep that froze the container: `sleep_operation_id`, `swap_subtree` and `prepared_restore`, with the residual fields of the last `residual` call |
| `…/<id>/plan/manifest.json`, `…/<id>/plan/ranges.bin` | `0600` | The one committed plan |
| `…/<id>/receipts/op-<opid>.json` | `0600` | Detailed metrics of one Operation; the newest 16 stay |
| `/var/lib/orbit/hibernation/outcomes/` | `root:caddy 0755` | Status files of the fast wake, `0644` |
| `/dev/shm/orbit/hibernation/` | `root:caddy 0755` | Fast wake requests, as today |
| `/var/lib/orbit/hibernation/capabilities.json` | `root 0644` | The capability manifest |
| `/etc/systemd/system/orbit-instance-power-.service.d/orbit.conf` | `root 0644` | The properties of every helper unit |

**Target descriptor.** `prepare` and a run's `restore` write it from the intent on stdin. It holds `schema_version: 1`, `instance_id`, `incus_project` and `incus_name` from the recorded runtime, `incarnation`, and `web`. `incarnation` is the container's Incus `volatile.uuid`. `web` is the container's recorded address and web port, or `null` without a web unit. The fast worker uses only this file to find its target. A missing or invalid descriptor makes it decline. It never guesses a project.

**Limits record.** `prepare` opens swap with `max` on each cgroup of the container's subtree whose `memory.swap.max` holds `0`. Before the pause, it writes what it opened to `limits.json`: `host_boot_id`, the container's `cgroup_inode`, and `opened: [{relative_cgroup_path, previous_value: "0"}]`. A cgroup with its own limit, such as 32 MiB, stays as it is. A cgroup that vanishes or refuses the write is not opened.

Every resume writes back to the container's own cgroup the value Incus configures for it: the bytes of `limits.memory.swap` from the instance's expanded config when it is a byte size, else `0`, which Incus writes for any other value. A nested-fleet container keeps its sized swap that way; any other container gets `0`. The resume writes `0` to each inner path of the record that still exists. Then it removes the record.

A record whose `host_boot_id` or `cgroup_inode` differs from the live one is stale: the resume closes only the container's own cgroup, to the same configured value. A sleep that finds a record no resume closed closes it first, so no inner path stays open. A resume learns inner paths only from `limits.json`; it reads no other record. An Instance frozen before this release, whose sleep opened inner paths (`swap_subtree` above 0), has them only in the old `/dev/shm` record, so the release wakes each such Instance before it deploys.

This record is the only one; the plan does not repeat it.

## The plan

`prepare` writes a new generation in a temporary folder beside `plan/`. It fsyncs both files and the folder, and then renames the folder into place. A half-written generation is never valid. One committed plan exists per Instance, with no history. The manifest and the table are valid only together.

**`manifest.json`, schema 1.** Every field is required.

| Field | Value |
| --- | --- |
| `schema_version` | `1` |
| `generation` | A random UUID |
| `instance_id`, `sleep_operation_id` | Positive integers |
| `incus_project`, `incus_name`, `incarnation` | From the target descriptor and Incus |
| `host_boot_id` | `/proc/sys/kernel/random/boot_id` at the freeze |
| `cgroup_relative_path`, `cgroup_device`, `cgroup_inode` | The container's cgroup |
| `init_host_pid`, `init_start_ticks` | The container's init process |
| `page_size` | `sysconf(_SC_PAGESIZE)` |
| `created_at_utc` | When the plan was committed |
| `processes` | `[{host_pid, start_ticks, first_range, range_count, resident_pages}]`, in table order |
| `range_count`, `resident_mapping_bytes`, `ranges_file_bytes`, `ranges_sha256` | Totals of the table |
| `prepare_metrics` | Durations in seconds and counts; no addresses and no contents |

**`ranges.bin`, version 1.** Little-endian:

| Part | Layout |
| --- | --- |
| Header, 32 bytes | magic `ORBRNG1\0` (8 bytes), `version: u32 = 1`, `record_size: u32 = 24`, `record_count: u64`, `page_size: u32`, `reserved: u32 = 0` |
| Record, 24 bytes each | `process_index: u32`, `reserved: u32 = 0`, `start: u64`, `length: u64` |

The table holds the resident pages of readable mappings, merged into runs. It holds no PFN, no pagemap entry and no page contents. The helper decodes it with checked integer arithmetic, and one failed check rejects the whole generation:

| Part | Rejected when |
| --- | --- |
| Header | The magic, version, record size or page size differs |
| File | Its size is not `32 + 24 × record_count` or `ranges_file_bytes`, or its SHA-256 is not `ranges_sha256` |
| Counts | `record_count` is not `range_count`, or the processes' `first_range` and `range_count` do not tile the table in order |
| Record | `process_index` is outside the process list, or a reserved field is not 0 |
| Range | `start` or `length` is zero or not page aligned, or `start + length` overflows or passes 2^56 |
| Order | A process's ranges are not sorted, or they overlap |
| Totals | `resident_pages` or `resident_mapping_bytes` differ from the sum of the lengths |

The limit 2^56 is the user address limit of 5-level paging. On a 4-level host the limit is 2^47, and a range above it fails at the kernel and counts as `partial`.

**Capture.** While the container is frozen, the helper reads `cgroup.procs` in every cgroup of the subtree, recursively, and deduplicates host PIDs. Threads that share an address space count once.

For each process it records the start time from `/proc/<pid>/stat` and checks that the cgroup still holds the process. It reads `maps` and keeps readable mappings, skipping only kernel special mappings (`[vvar]`, `[vvar_vclock]`, `[vdso]`, `[vsyscall]`). From `pagemap` it takes the pages whose present bit is set.

The scan works on whole buffers: a strided slice of the pagemap bytes and a byte-class match find the runs, with no Python loop per page. A process that exits during the scan is left out. Pages shared between processes count once per process; the result says so.

## Bounds

These are global safety bounds, not settings per app. Hitting one abandons the hint with a reason code. It never changes the container's memory limit and never keeps memory in RAM.

| Bound | Value |
| --- | --- |
| Extra time for `prepare` | 30 s, from the pause to the published plan; checked before each process and each pagemap chunk of 64 Ki pages |
| Restore | 2 s from the start of `restore`, metadata load included |
| Worker threads | `min(32, processes with work)` |
| Processes | 4,096 |
| Ranges | 1,048,576 |
| Plan metadata | 64 MiB |
| Recorded readable residency | The container's memory limit, at most 8 GiB |
| iovec per syscall | `min(512, IOV_MAX)` |
| Counter settle after the reclaim | 20 `residual` calls, 0.1 s apart (Ansible) |
| Fast wake request age | 1 s |
| `incus resume` in `restore` | 1 s |
| The helper's own thaw of a container that the kernel holds frozen | 5 s |
| Each `incus exec` of a wake from the Node | 10 s; 5 s for each T3 try; 30 s to write a nested fleet's Club key |
| Fast worker's readiness request | 1.5 s |
| Gateway observation of the fast wake | every 25 ms, at most 6 s |

The 6 s observation covers the fast wake's whole path: the request may wait up to 1 s, Python start-up and the plan load take a fraction of a second, then the 2 s restore, the 1 s resume and the 1.5 s readiness request.

The 2 s bound limits the work the helper submits and how long it waits. Linux cannot cancel uninterruptible disk I/O. When a worker does not return, the helper keeps the container frozen and the asleep marker in place. It reports `timed_out` only after every worker has stopped. Otherwise the unit stays active and the run fails with `instance.power_helper_busy`. The helper never starts a second worker against the same memory.

## Sleep

`prepare` runs in the sleep's one Ansible phase, after the Route check and the asleep marker. It works in this order:

1. It reads the container's state from Incus, before it changes anything.
2. Frozen: it changes nothing and keeps the plan for the next wake. It returns `already_frozen` with the earlier result from `sleep.json`.
3. Frozen without `sleep.json`: it returns `already_frozen`, `skipped`, reason `no_plan`.
4. Stopped: it discards a plan left from before and returns `stopped`, `not_applicable`.
5. Running, but frozen in the kernel ([a freeze that Incus lost](#a-freeze-that-incus-lost)): it changes nothing and fails `instance.container_frozen_unmanaged`. The marker stays.
6. Running: it removes the earlier plan and `sleep.json`, writes `limits.json`, opens swap and pauses the container.
7. It captures and publishes the plan.
8. It writes `memory.reclaim` once; `EAGAIN` is normal. Then it writes `sleep.json` and returns `frozen`.

Ansible then runs `residual` through the lock until anonymous and file memory are both 0, at most 20 times, 0.1 s apart. Each call reads the counters once and stores them in `sleep.json`, so the last call is the residual memory, and a repeated sleep of the frozen container returns the same values. Only the helper reads the counters; the wait is Ansible's.

The existing policy decides on more than 32 MiB of anonymous memory. Swap is full at that point, so no prefetch would help: the phase runs `discard --plan-only` first, and then `restore` finds no plan, resumes without a memory syscall and closes `limits.json`. When the Instance is a nested-fleet one, the sleep then fails, as before. Any other Instance stops, and its result says `skipped`, reason `stopped`.

A capture that is unsupported, too large or too slow leaves no plan. The sleep continues: freeze and reclaim are the same as without a plan. A failed pause or reclaim takes the existing failure path, and `prepare` closes every limit it opened before it returns. A failure that leaves the container frozen keeps the marker and `limits.json`, so a later `restore` can recover it.

## Wake

`restore` takes the lock and reads the container's state from Incus. It compares the plan with the live container before any memory syscall: the plan's generation with the one `sleep.json` reports, `host_boot_id`, `incarnation`, cgroup device and inode, the init process, and each process's PID and start time.

| Found | Action | `prepared_restore.status` |
| --- | --- | --- |
| Frozen, valid plan | Restores the pages, still frozen; then rechecks the incarnation, unfreezes once and closes the recorded limits | `complete`, `partial` or `timed_out`; `skipped` when no page came back |
| Frozen; plan missing (also after `discard --plan-only`), invalid, stale or unsupported | No memory syscall; unfreezes and closes the limits | `skipped` with a reason |
| Running | No restore and no resume; closes any recorded limits | `not_applicable` |
| Running, but the kernel holds it frozen | The helper thaws the cgroup itself within 5 s, then closes the limits ([a freeze that Incus lost](#a-freeze-that-incus-lost)) | `skipped`, `plan_stale` |
| Stopped | Discards the plan and the limits record; the caller's existing start path and nested-fleet rule decide | `not_applicable` |
| Missing, or a different incarnation | Touches nothing; the existing not-found or lifecycle error, except for a recreated container (below) | none |
| Any other state, such as `error` or `freezing` | Touches nothing; `outcome: failed`, `instance.container_start_failed`, with the state it found | none |

**A recreated container.** A run's `restore` that finds a stopped container of another incarnation drops the old incarnation's plan, limits record, `sleep.json` and descriptor. It writes a new descriptor and goes on as for Stopped, so a recreated container is never blocked. The fast worker sends no intent, so it never rewrites a descriptor.

**Restore.** The helper opens a pidfd for each process and checks the process's identity again. For each process with work, a worker thread does two passes over the process's ranges, in batches of contiguous iovecs:

1. It calls `process_madvise(MADV_WILLNEED)` over all the ranges.
2. It calls `process_vm_readv`, reading one byte per page into a scratch buffer that it reuses and never inspects.

A range longer than 1 GiB is advised in pieces of at most 1 GiB, so every recorded byte is advised.

**Residual risk.** `process_vm_readv` takes a PID, not a pidfd; Linux has no pidfd form of it. The pidfd check runs just before each call. A process that exits in that gap, followed by a full PID wrap-around to a new process, would get one discarded one-byte read per page of that batch: no write, and nothing kept.

A batch holds up to `min(512, IOV_MAX)` pages of one process and may span its ranges. Before each batch, the worker checks the process's identity in two ways:

- its pidfd is not readable, since a pidfd turns readable when its process exits;
- the start time in a `stat` file that the worker opened with the pidfd, which stays bound to that process.

The poll needs no privilege. A signal would: the kernel refuses even signal 0 to a process of another uid without `CAP_KILL`, which the unit does not hold. The worker stops when the identity changed or the deadline passed. A range that is unmapped or unreadable, a short advice or read, or `ENOMEM` makes the result `partial` and abandons that batch; the worker goes on with safe work.

The worker never retries a range and never follows a replacement process. Advice that failed never counts as done. After the restore the plan is consumed. A later wake without a new sleep finds no plan.

**Reason codes.** `skipped` and `timed_out` carry one closed code:

| Code | Meaning |
| --- | --- |
| `no_plan` | No committed plan |
| `plan_invalid` | The manifest or table failed validation |
| `plan_stale` | The boot id, incarnation, cgroup or init process changed |
| `unsupported` | The capability manifest denies a primitive |
| `placement_unproven` | A nested-fleet Instance |
| `bound_processes`, `bound_ranges`, `bound_metadata`, `bound_residency` | A bound of the plan was hit |
| `prepare_timeout` | Capture passed its 30 s |
| `capture_failed` | Capture failed for another reason |
| `stopped` | The sleep took the stop path |
| `deadline` | The restore passed its 2 s |
| `nothing_read` | The restore read no page back, for example because every process exited or refused it: it prepared nothing |

The receipt counts each process's outcome (`complete`, `partial`, `skipped`, `failed`) and the pages read. It does not keep the errno of a short call.

**Readiness.** After `restore`, the caller checks readiness with the existing rule: an answer below 500 from the container's web port. The check is one request from the Node straight to the container's recorded address and web port, bounded to 1.5 s, not `incus exec` and `curl`. The fast worker reads the address and port from the target descriptor; the run reads them from its intent, as the play's `web_probe`.

A container without a web unit gets no HTTP check. The fast worker sends one bounded request. The run keeps its retries and its stop-and-boot fallback, which a nested-fleet Instance never takes. Only after the check passes does the caller remove the asleep marker. `ready_at` is that moment.

### A freeze that Incus lost

Incus reads a container's state only from its liblxc monitor. On 17 November 2026 the monitor of Instance 2454 said running while the kernel still held the container's cgroup frozen (`cgroup.freeze` 1). The wake then skipped the resume, and its first `incus exec` hung until the run's 240 s bound ended it with exit code 124. So the helper and the run also read the kernel's own state:

- Before any exec, the `start` of a wake or a deployment reads `cgroup.freeze` and `cgroup.events` of a container that Incus calls `Running`.
- A frozen one goes to the helper's `restore`, as a `Frozen` one does.
- `restore` writes 0 to `cgroup.freeze` and waits for `frozen 0` in `cgroup.events`, at most 5 s. It makes no memory syscall and no Incus call: Incus refuses to unfreeze a container that it thinks runs.
- The result then has `kernel_frozen: true`, and the plan counts as stale.
- After its own `incus resume`, `restore` checks the kernel too. liblxc marks the container running before it writes the thaw.

A thaw can fail to come. The helper then fails clearly and keeps what the next wake needs:

- A thaw that does not come within 5 s fails `instance.container_frozen_unmanaged`, with `state_after: frozen`.
- A thaw without an Incus call that fails keeps the plan, `sleep.json` and `limits.json` for the next wake.
- After its own resume, the helper has already removed the plan. A failed thaw then keeps only `sleep.json` and `limits.json`.
- `prepare` never freezes such a container again: it fails `instance.container_frozen_unmanaged`.
- Every `incus exec` that a wake runs has a bound from the Node, so a container that does not answer ends that step, not the run.

The read of the boot units, the library-cache check and the CA check have 10 s each. Each read of a running unit and each T3 try have 5 s. A nested fleet's Club key read has 10 s, and its write has 30 s. Every other exec as root in the `start` phase, on each path of a wake (the resume, a container already running, the boot of a `Stopped` one, the stop and boot after a silent resume), has 60 s: twice the slowest of them. That includes the hosts-file read of the private names and the restart after changed PHP settings.

A copy of a sleeping default needs no wake: the clone reads only the default's snapshot list and copies one seed snapshot. It never reads the live container's state, execs into it or changes it.

## The fast wake

The fast wake keeps its narrow exception: on success, no Swamp run executes. The Gateway still writes the request with the agent's `hibernation.marker`, and it reads the answer with `hibernation.times`, which returns modification times in whole seconds. Each name carries the Operation id:

| File | Written by | Meaning |
| --- | --- | --- |
| `/dev/shm/orbit/hibernation/app-instance-<id>-op-<opid>.wake` | Gateway | The request |
| `…/app-instance-<id>-op-<opid>.taken` | Dispatcher | Claimed: the request was renamed |
| `/var/lib/orbit/hibernation/outcomes/app-instance-<id>-op-<opid>.ready-<status>` | Worker | Readiness passed, the marker is gone; `<status>` is `complete`, `partial`, `skipped` or `timed-out` |
| `…/outcomes/app-instance-<id>-op-<opid>.done` | Worker | The facts are committed |
| `…/outcomes/app-instance-<id>-op-<opid>.failed` | Worker | Declined or failed; the run continues the Operation |

A name carries no path, PID, address or shell text. The worker accepts only strictly positive decimal ids and regular files that are not symlinks.

**Dispatcher and worker.** The role's path unit watches the request folder. Its dispatcher renames each `.wake` to `.taken` and starts `orbit-instance-wake@<id>-<opid>.service`, without waiting. systemd runs one worker per Operation, and a slow worker does not hold up the next request. The worker does these steps in order:

1. When this Operation already has a valid `.ready-*` and `.done`, it ends. It never repeats a wake or touches an existing outcome.
2. It declines a request older than 1 s, a missing asleep marker, a missing or invalid target descriptor, or an Instance in `orbit-instances-nested`.
3. It runs `restore` through the lock.
4. It checks readiness once, removes the asleep marker, and writes exactly one `.ready-<status>`. It fsyncs the file and the folder.
5. It writes the receipt and a journal line keyed by `opid`, then `.done`.
6. It runs the existing library-cache and CA-bundle checks, which never hold the marker or the outcome.

On any decline or failure it writes `.failed`, keeps the marker, and never writes a ready or done file it did not earn.

**Gateway.** The Gateway records the Operation under the Instance lock before it writes the request. A visit gets the progress page before any Node call. The Gateway reads the request, `.taken`, the outcome files and the worker template every 25 ms, for at most 6 s, with no first wait. On `.done` with exactly one `.ready-*`, the Operation succeeds from that status, with `ready_at` from that file's time.

On `.failed`, or after 6 s, the Gateway removes a request that is still there, and the same Operation starts its run. The run takes the same lock, so it waits for a worker that is still active. It then finds the state the Node reached and continues from there; it reads this Operation's outcome files and reports their facts. The safety net of 15 s stays. A cancelled or finished Operation is never overwritten by a late outcome.

After the projection commits, the Gateway removes the Operation's request and outcome files with `hibernation.marker`'s remove, off the visitor's path. Until then the files stay, whatever their age. A crash before the cleanup changes nothing, and `ready_at` keeps its first value.

**Eligibility.** The Gateway tries the fast wake when the Instance's last sleep froze it, the Instance is not a nested-fleet Instance, and the Node has `/etc/systemd/system/orbit-instance-wake@.service`. Only the role's convergence writes that worker template, together with the path unit and the dispatcher, so a Node whose role predates this release takes the run at once. The first read also asks for this file; a Node without it skips the fast wake for 10 minutes. Any `swap_subtree` qualifies, because `restore` closes every limit that the sleep opened. Otherwise the wake takes its run at once.

## Results

The Operation keeps its existing keys: `power_state`, `sleep`, `wake`, `marker_found`, `swap_subtree`, `t3_turn`, `trigger_seconds` and `confirm_seconds`. It adds these, in `Operation.result`. Operation list and show return them, so one history request shows every wake's readiness time.

**Sleep.**

| Field | Value |
| --- | --- |
| `prepared_restore.version` | `1` |
| `prepared_restore.status` | `ready`, `unsupported`, `skipped` or `not_applicable` |
| `prepared_restore.reason` | A [reason code](#wake) for `unsupported` and `skipped` |
| `prepared_restore.generation` | The plan's UUID, for `ready` |
| `prepared_restore.processes`, `resident_mapping_bytes`, `metadata_bytes`, `prepare_seconds` | Actual measurements, for `ready` |
| `prepared_restore.residual_anon_bytes`, `residual_file_bytes` | The last `residual` call's read |

`ready` means a committed, complete plan. An incomplete generation is never `ready`.

**Wake.**

| Field | Value |
| --- | --- |
| `restore_mode` | `prepared` for `complete`, `partial` and `timed_out`; `ordinary` otherwise. A restore that read no page back is `skipped` (`nothing_read`, or `deadline` when its 2 s passed first), so `prepared` always means pages came back |
| `ready_at` | Node UTC time when readiness passed and this wake removed the marker; absent when the wake removed no marker |
| `ready_at_resolution_seconds` | `1` for the fast wake (file times); `0.001` for a run |
| `prepared_restore.status` | `complete`, `partial`, `skipped`, `timed_out` or `not_applicable` |
| `prepared_restore.reason` | A closed code, when the transport returned one |
| Durations and counts | Not in the Operation: the Node's receipt and journal keep them; never an invented zero |

The fast wake transports only the status, through the name of its one `.ready-*` file. Its reason and durations stay in the Node's receipt and journal. A run returns the reason in its result. `finished_at` is not the readiness time. Latency is computed on one host's monotonic clock or by the external client, never by subtracting clocks of different hosts. Addresses, PIDs and raw `/proc` data never appear in a result, the CLI or a public log.

## Lifecycle

Other lifecycle work meets the helper's state through the same lock.

| Event | Effect |
| --- | --- |
| Destroy | `discard` waits for a helper still at work, then removes all of the Instance's helper files. A late worker finds no descriptor and declines. |
| Deploy, start, fleet refresh | Their resume of a sleeping container calls `restore`. It consumes the plan before any app change, and it closes the recorded limits. A stopped container's plan is discarded before the boot. |
| Stop path of a sleep | `discard --plan-only`, then `restore` without a plan, then `incus stop`; the sleep's result says `skipped`, reason `stopped`. |
| Host reboot | The boot id differs, so every plan and limits record is stale. The asleep marker and the Incus state decide the wake. |
| Helper restart | The helper keeps nothing in memory. It reloads the committed plan from disk at each call. |
| Two callers | One Operation, one physical restore; the lock makes the other wait or decline. Different Instances wake at the same time within the Node's capacity. |
| Cancellation | No Node work before the Node is called. A started leaf action may finish after the cancel; it never rewinds an unfreeze, and the Operation stays cancelled. |

## Proof and release gates

The proof runs the installed helper on a Node that the `app-dev` role converged. It covers:

- a PHP site with SSR and an independent non-PHP service;
- defaults and clones;
- randomized ordinary and prepared wakes, at least 20 per comparison;
- cold routes, an Instance without a Route, one without a web unit, and one whose `/` returns 404;
- helper restart, 30-minute and overnight idle, and 2 and 4 concurrent wakes;
- missing capability, missing plan and an exhausted bound.

It reports median, p95, min, max, sample count and failures, and it keeps every failure. Before each timed wake it checks that anonymous and file memory are 0. The proof publishes two timing sets: the Node's restore, resume and real app response; and the full HTTPS path through Caddy, Gateway, Operation, Node and the progress page. It names which part of the remaining delay belongs to activation delivery: the progress page, its 250 ms poll and the Gateway's trigger and observation calls.

The release gates apply to a reference fixture like the spike's: 18 processes and 300–500 MiB recorded.

| Gate | Budget |
| --- | --- |
| Full preparation | p95 at most 2 s; every run within 30 s |
| Prepared Node-to-app response, all restore, probe and metadata costs included | median at most 200 ms, p95 at most 300 ms |
| Improvement over an ordinary wake, same route, host and load | median at least 40% |
| Safety | no app request failure from preparation or restore; no unsafe identity access, unclosed swap limit, false `ready` or missing Operation history |

These gates measure the Python engine. If one misses and the helper's own time causes it, the next step is a native engine built in CI and shipped as a release asset, behind the same commands and plan format ([ADR 0261](/decisions/0261-restore-a-sleeping-containers-memory-before-it-resumes)).

## Errors

The helper adds three error codes to the sleep and the wake, and the wake one more of its own.

| Code | Meaning |
| --- | --- |
| `instance.power_helper_busy` | Another helper call of this Instance stayed active past the run's wait, for example a restore stuck in disk I/O. Nothing changed; the asleep marker stays. |
| `instance.power_helper_failed` | The helper refused its input or crashed. The asleep marker stays; the run's output has the helper's error. |
| `instance.container_frozen_unmanaged` | The kernel holds the container frozen while Incus calls it running, and the thaw did not end within 5 s. The marker stays. See [a freeze that Incus lost](#a-freeze-that-incus-lost). |
| `instance.container_exec_timeout` | A nested fleet's start could not read the container's `/run` within 10 s. |

## Why it works this way

[ADR 0261](/decisions/0261-restore-a-sleeping-containers-memory-before-it-resumes) has the measurements and the rejected alternatives.

### The plan comes from the kernel

Training on routes ties a sleep to an app and to a list of routes. The resident set at the freeze is what the app used, and it needs neither.

### Restore before the unfreeze

An app that runs changes its mappings. A frozen app cannot, so the plan stays exact while the helper reads it.

### A systemd unit name is the lock

The shape policy allows `fcntl.flock` only on a fixed list of host lock paths, and a lock per Instance has no fixed path. systemd starts one unit per name and frees the name when the helper exits, so the host's own service manager gives the exclusion. The lock also spans the helper's work, not its caller's connection.

### One helper for every caller

The fast wake, the run, deploy, start and destroy must agree on identity checks and swap limits. One program keeps one rule.

## Executor sleep (ADR 0266 slice 4)

The Rust `power` family implements whole-container sleep through signed orders on durable proof Operations. Production sleep and wake still use the Python helper until slices 7/8. The executor records every non-`max` swap limit across the cgroup subtree before opening it. It freezes through the Incus local API and scans every process in parallel, including T3 and Vite.

The plan records readable pages present or swapped (pagemap bit 63 or bit 62), excluding bit 58 guard markers inside readable mappings, so a partial wake cannot shrink the next plan. Both the limits record and plan publish atomically with fsync. After reclaim settles, or two seconds pass, the executor applies the same 32 MiB residual anonymous rule and writes the asleep marker. Nested-fleet containers resume and fail when swap cannot take their memory; other containers stop. That stop keeps today's clean-stop allowance of 30 s, as `incus stop --timeout 30` does; the executor waits up to 35 s for the Incus operation. Its freeze and unfreeze keep 5 s each.

The Rust plan and limits record live separately under `/var/lib/orbit/executor/power` while the production Python flow remains in place. Crash recovery repeats an open sleep and preserves a valid published plan. Discard removes both records after a stop or a deployment that boots fresh. Destroy removes the Instance's folder there after it deletes the container, with no signed order. The executor refuses a running container whose kernel cgroup is frozen, preserving recovery state for the thaw described above.

The swap-backed nested proof ran 30 Rust sleeps and 30 Python sleeps on the same real clone with T3 and Vite. Rust sleep p50/p95/max was 452.304/543.592/551.332 ms, including reclaim at 398.938/492.065/494.284 ms. Python was 509.563/5,644.503/5,822.532 ms, but all 30 samples skipped plan capture as `placement_unproven`; its timings do not cover the same work and are not a like-for-like comparison. Every sleep used swap. The clone's own swap limit opened from `0` to `max` for sleep and returned to `0` after wake; no clone Incus swap setting was needed. [Executor: Rust sleep and discard](/reference/executor#rust-sleep-and-discard) records timing scopes, RAM and swap for every sleep, raw samples and proof limits.

## Executor wake (ADR 0266 slice 5)

The Rust `power` family also implements the wake, again only through signed orders on durable proof Operations; production wakes keep the Python helper until slices 6 to 8. It keeps three rules of the helper: identity before any memory syscall, restore before the unfreeze, and a restore as a hint.

Before any memory syscall it checks the boot id, the cgroup inode, the Incus incarnation and every process's start time through a pidfd. It reads back the recorded pages that are not resident now, through the Node's one restore pool, with `process_madvise(MADV_WILLNEED)` and `process_vm_readv`, within 2 s. A short memory read skips and counts the failed page, then continues through the item.

Remote `process_madvise` returns `EPERM` in the nested fleet because Linux 7.0 requires `CAP_SYS_NICE` in the initial user namespace; these measurements use memory reads without the WILLNEED head start. Then it unfreezes through the Incus local API, closes exactly the recorded swap limits, checks readiness with the rule above and removes the asleep marker.

Two things differ from the helper. Because the plan also holds swapped pages, a wake restores memory that was in swap before the sleep too. And the executor never thaws a cgroup itself: a freeze that Incus lost fails `instance.container_frozen_unmanaged`, where the helper writes `cgroup.freeze`. [Executor: Rust wake](/reference/executor#rust-wake) has the steps and results.

Thirty Rust wakes returned `done` with zero failed pages and reached the app at p50/p95/max 147.840/494.080/517.545 ms; restore was 122.675/417.062/478.530 ms and the Incus unfreeze API portion 9.471/18.734/19.041 ms. Each wake read 91,932–92,180 nonresident pages. Three simultaneous clone wakes returned `done` in 238.297/402.372/484.466 ms. SIGKILL during restore recovered through the journal with zero failed pages, HTTP 200 after 2,352.641 ms and a signed repeat returning `done`. [Executor: Rust wake](/reference/executor#rust-wake) owns measurement details and remaining CI fixture limits.
