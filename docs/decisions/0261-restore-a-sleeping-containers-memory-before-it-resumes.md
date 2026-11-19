---
title: "ADR 0261: Restore a sleeping container's memory before it resumes"
sidebarTitle: "0261 Prepared restore"
description: "In progress. At each sleep, while the container is frozen, Orbit records which readable pages of every process in its cgroup subtree were resident, then reclaims all memory to swap as before. At the wake, still frozen, it reads those pages back in parallel within a bound and then unfreezes once. One role-installed helper does it for the Node's fast wake and for the wake's run."
---

# ADR 0261: Restore a sleeping container's memory before it resumes

At each sleep, while a development container is frozen, Orbit records the readable pages that every process in the container's cgroup subtree has resident. Then it reclaims all of the container's memory to swap, as it does today. At the wake, while the container is still frozen, Orbit reads those pages back in parallel within a bound, and then unfreezes the container once. The plan holds process identities, addresses and lengths, never memory contents. One role-installed helper does this work, both for the Node's fast wake and for the wake's run. Every sleep and wake stays an ordinary `instance.sleep` or `instance.wake` Operation.

## Status

In progress.

Principle: [Deterministic first](/mission#principles) and [One way, one name](/mission#principles). The plan comes from what the kernel reports at the freeze, not from training, routes or app hooks, and one helper serves every caller. Exception to [No exceptions and no legacy](/mission#principles): the Node's fast wake keeps its existing narrow exception to Swamp execution. On its success path it records the Operation without a Swamp run, and a role-installed script on the Node does the work. This ADR extends that path; it adds no new one.

## Context

A sleep freezes the container and pushes its memory to swap ([Sleep and wake](/reference/development-containers#sleep-and-wake)). At the resume every page comes back on first use, one fault at a time. So the first request after a wake pays for hundreds of MiB of scattered swap-in reads.

A spike on one isolated fixture measured a generic fix. The fixture had 18 processes: a PHP, Inertia and Vite site, a Node T3 service and system services. These are the results:

| Measure | Result |
| --- | --- |
| Full preparation, 60 local cycles: pause, cgroup scan, metadata save and fsync, address preparation, reclaim, counter settle | median 291 ms, p95 371 ms, max 480 ms |
| Full preparation, 32 remote cycles | median 294 ms, max 600 ms |
| Recorded readable resident mappings | 301–470 MiB; physically shared pages count more than once |
| Prototype JSON metadata | 1.2–2.0 MB |
| Memory left in RAM while asleep, every cycle | outer cgroup anon 0, file 0 |
| Remote HTTPS first response after a wake, median of 4 per route: ordinary vs restore before unfreeze | homepage 449 vs 128 ms; articles 440 vs 168 ms; robots 303 vs 129 ms; sitemap 301 vs 106 ms |
| Local homepage, restore before unfreeze, 8 wakes | median 109 ms, no partial restore |
| Local Node T3 endpoint, restore before unfreeze, 6 wakes | median 82 ms, no partial restore |
| Restore overlapping execution, 30 wakes | local medians 62–103 ms, but 2 partial prefetches when PHP changed its mappings |

All 32 remote requests returned 200. These are spike numbers from one Node, one container and one kernel. They do not cover the full Gateway and loader path, every fleet kernel, nested fleets, overnight idle, concurrent wakes or large-memory apps. No wake of 50 ms was seen, and none is promised.

The boundaries stay as they are:

- The Gateway records intent, admits and projects; it executes nothing.
- Swamp runs one Ansible phase per logical action.
- The Node agent is frozen ([ADR 0253](/decisions/0253-patch-nodes-and-reboot-them-drained)): it gets no new command.
- No host-wide setting changes: no swap, zswap, THP, readahead or sysctl setting, and no kernel module.
- The shape gate allows a new script under `swamp/` no sleep loop, `while true`, `flock` or `lockfile`. `fcntl.flock` passes only on the fixed paths of `HOST_TOOL_LOCKS`, and a policy change would have to land in an earlier pull request.
- A task copy cannot change `.github/workflows/`.

## Decision

**A generic plan, rebuilt at each freeze.** While the container is frozen, the helper enumerates `cgroup.procs` throughout the container's cgroup subtree and deduplicates host process ids. For each process it records the resident pages of its readable mappings from `/proc/<pid>/maps` and `/proc/<pid>/pagemap`. It skips only kernel special mappings such as `[vvar]` and `[vdso]`. Nothing selects by name, executable, language, framework, container server or route. Preparation sends no HTTP request, runs nothing in the container, and reads no app code or environment file. The helper publishes the plan atomically and then reclaims all memory to swap. No working set stays in RAM, and no copy of memory exists anywhere. Each sleep rebuilds the plan, and each resume consumes it.

**Restore completes before the unfreeze.** At the wake, the helper validates the plan against the live container: host boot id, Incus incarnation, cgroup identity, init process and every process's start time. No memory syscall comes before these checks. It then asks the kernel to fault the recorded pages back in parallel, with `process_madvise(MADV_WILLNEED)` and nondestructive `process_vm_readv` reads. It works within a 2-second bound and keeps every target frozen until the attempt completes or is abandoned. Then it unfreezes the container once and closes exactly the swap limits the sleep opened. A mode that overlaps restore with execution is not shipped: the spike showed it races changing mappings. A restore is a hint. The kernel's swap stays the truth, and a failed or partial hint never makes a resumable container unbootable.

**One helper, five commands.** The `app-dev` role installs `/usr/local/libexec/orbit-instance-power` with the commands `capabilities`, `prepare`, `residual`, `restore` and `discard`. Their only dynamic arguments are numeric ids. The sleep's run, the wake's run, deploy and start, destroy, and the Node's fast-wake worker all call this one helper. Nothing else scans memory, validates a plan or closes swap limits; it replaces today's swap-record tasks and the swap write in the wake script. [Prepared restore](/reference/prepared-restore) owns the interface, the plan format, the bounds, the state table and the result fields.

**Deviation from the specification: the opened swap limits have their own record.** The specification lists `opened_swap_limits` in the plan's manifest. But a sleep opens swap limits even when it records no plan: the capability is missing, a bound is hit, the capture fails, or the Instance is nested. So the helper writes the limits it opened to `limits.json`, before it pauses the container. Every resume closes swap from that one record, and the manifest does not repeat it. This replaces today's `/dev/shm` swap record. A resume closes the container's own cgroup, which Incus always holds at 0 while running, and learns inner paths only from `limits.json`. There is no compatibility read of the old record: before the deploy, the release wakes every nested-fleet Instance that is frozen, because only such an Instance can hold an old record with inner cgroups.

**Deviation from the specification: the memory engine is Python 3 with ctypes, not a native binary.** The specification proposes a native memory engine, built in CI and shipped as a versioned release asset. This branch cannot deliver one: a new fleet artifact needs a change under `.github/workflows/`, which a task copy cannot publish. Copying a locally built binary by hand would not count as rollout proof. So the helper is one Python 3 program that calls the kernel directly through ctypes:

- It calls libc `syscall` for `pidfd_open`, `process_madvise` and `process_vm_readv`.
- It builds contiguous iovec batches of `min(512, IOV_MAX)` from the binary plan.
- It uses at most `min(32, processes with work)` worker threads; ctypes releases the GIL during each call.
- The scan works on whole buffers: it takes a strided slice of the pagemap bytes and finds runs with a byte-class match, with no per-page Python loop.

It uses the same kernel primitives and the same batching as the spike's C helper. It needs no compiler on a Node and no new release asset, and the `app-dev` convergence installs it like the existing wake script. After release, the [Proof and release gates](/reference/prepared-restore#proof-and-release-gates) measure the installed helper against the specification's section 14 gates. If a gate misses and the helper's own time causes it, the next step is the native engine. It would ship as a release asset built in CI, behind the same five commands and the same plan format, through a pull request that may change `.github/workflows/`.

**The host lock is one transient systemd unit per Instance.** Every helper command except `capabilities` runs as the transient unit `orbit-instance-power-<id>.service`, started with `systemd-run --unit=… --wait --pipe --collect`. systemd starts at most one unit of a name. A second start fails at once while the first is loaded, so the unit name is the lock. The helper refuses to run outside its unit: it reads its own unit from `/proc/self/cgroup`. There is no lock file and no `flock`. When the helper exits, systemd frees the name. When the helper hangs in uninterruptible I/O, the unit stays active and keeps the lock. No second worker can then touch that memory, which is the required outcome. The lock spans only the helper's work, not its caller: a run whose SSH connection drops cannot release it early. The lock is busy only when `systemd-run` prints nothing on stdout and fails with systemd's `UnitExists` refusal ("was already loaded or has a fragment file"). Every other nonzero end is a helper crash: the phase fails with `instance.power_helper_failed` and never retries it. A caller that finds the lock busy acts by its kind:

- Ansible waits with `until`, `retries` and `delay` within its bound.
- The fast worker declines without a second try, and the Operation continues in its run.
- After the bound, a run fails with `instance.power_helper_busy` and keeps the asleep marker.

**Ansible owns the counter settle.** `prepare` ends with one reclaim of the frozen container, where `EAGAIN` is normal. The sleep phase then calls the helper's `residual` with `until` until anonymous and file memory are both 0. Each call reads the container's `memory.stat` once and stores the values in `sleep.json`, so a repeated sleep of the frozen container reports them again; only the helper reads the counters. It retries 20 times with a `delay` of 0.1 s, so it waits at most 2 seconds, and a miss does not fail the task. The last read is the residual memory that the existing 32 MiB residual-anonymous policy judges and that the result reports. No script loops: the wait belongs to Ansible's own retry, as the shape guide requires.

**One prepared-sleep phase.** The sleep's two Ansible steps, `asleep` and `freeze`, become one phase, `sleep`. It runs the Route check, the marker, `prepare`, the counter settle, the residual policy and the result. `prepare` reads the container's state before it changes anything: a repeated sleep of a frozen container keeps that sleep's plan and returns its result. On the stop path, swap is full, so a prefetch would be pointless: the phase drops the plan with `discard --plan-only` before `restore` resumes the container and closes its limits. Each Ansible start costs about 4 s ([wake chart](/cli/instance/wake#how-it-works)), so one phase saves one start. The proof records the measured comparison of the old and new sleep in the pull request.

**The fast wake speaks per Operation.** The Gateway still writes the wake request with the agent's existing `hibernation.marker` and reads the answer with `hibernation.times`. Requests and outcomes carry the Operation id, so an old wake's result can never satisfy a new one:

- `app-instance-<id>-op-<opid>.wake` and `.taken` under `/dev/shm/orbit/hibernation`.
- `.ready-complete`, `.ready-partial`, `.ready-skipped`, `.ready-timed-out`, `.done` and `.failed` under `/var/lib/orbit/hibernation/outcomes`, where they survive a reboot until the Gateway projects them.

The role installs a path unit. It hands each request to a systemd template worker, `orbit-instance-wake@<id>-<opid>.service`. Slow maintenance after one wake never blocks the intake of the next. The Gateway observes every 25 ms within 6 seconds, without the old first wait of 350 ms. The existing safety net still starts the run 15 seconds after the request. On a timeout the run takes the same helper lock and continues the same Operation from the state the Node reached. The Gateway tries the fast wake only when the Node has the worker template `/etc/systemd/system/orbit-instance-wake@.service`, which only the role's convergence installs, so a Node with the old script takes the run at once. The old unqualified `.wake`, `.taken` and `.resumed` protocol and the script `orbit-instance-wake` are removed in the same pull request.

**Readiness has a time.** A wake's result adds `restore_mode`, `ready_at`, `ready_at_resolution_seconds` and `prepared_restore`. `ready_at` is when readiness passed and the asleep marker went, as a Node UTC time. It is separate from the Operation's `finished_at`, and Operation list and show return it. A sleep's result adds `prepared_restore` with the plan's status and actual measurements. Addresses, PIDs and raw `/proc` data never reach a result, the CLI or a public log.

**Nested-fleet Instances are unchanged.** Their sleep and wake call the same helper, for swap and for resume. For the Incus project `orbit-instances-nested`, `prepare` records no plan: the status is `skipped` with reason `placement_unproven`. The fast path stays off for them. Their admission, the refusal of a stop and of a boot, and their recovery stay as they are. Turning them on needs a real nested-fleet proof and its own change.

**The HTTP activation path is out of scope.** Under the spinner rule, a visit to a sleeping Instance gets the progress page at once, and the page reloads when the Instance runs. The request is never held. Route, Caddy, `RuntimeActivationsController`, `RuntimeActivationPage` and the loader do not change. The proof publishes two timing sets:

- the Node's restore, resume and real app response;
- the full HTTPS path through Caddy, Gateway, Operation, Node and loader, with every request and poll counted.

Removing the visible loader is a separate contract.

2026-11-19: the visit is now held while the Instance wakes, and the progress page is gone; see [ADR 0266](/decisions/0266-sleep-and-wake-containers-through-a-signed-order-executor).

## Rejected alternatives

- Route training, calibration requests or app hooks: they couple preparation to an app and a route list. The generic scan restored every tested route without them.
- Keeping a working set in RAM or in compressed memory: this defeats the reason to sleep, and it would change host-wide memory settings.
- Restore that overlaps execution: in the spike its local medians were 62–103 ms, but it left partial prefetches when a process changed its mappings.
- A native engine in this pull request: it needs a release workflow change that a task copy cannot publish. It stays the measured next step, as stated above.
- `flock` on a per-Instance file: the shape policy allows `fcntl.flock` only on fixed paths, and a per-Instance lock has no fixed path, so this would need a policy change in an earlier pull request. The systemd unit name gives the same exclusion through the host's service manager, and it follows the helper rather than its caller.
- A helper daemon that keeps plans in memory: it would be a new persistent service, and it would hide the cold-metadata path, which must work after any restart.
- A new agent command to report capability or metrics: the agent is frozen. `hibernation.times` observes outcome files, and the full metrics stay in the Node's receipt and journal.
- A Swamp run for every fast wake: measured at 8–13 s of overhead before this path existed.
- A counter-settle loop inside the helper: it is a script wait loop, which the shape gate forbids; Ansible's `until` does it.

## Consequences

- A visitor's app answers after a wake with its pre-freeze pages already in RAM. The spike measured about 3 times faster first responses. The installed gates decide what ships.
- A sleep takes a few hundred milliseconds longer; the budget is 30 seconds. While asleep, it holds no more RAM than before.
- A restore reads the recorded resident set at the wake, so it costs more I/O and RAM at that moment. The bounds limit it; it never raises a container's memory limit.
- The fast wake no longer depends on a sleep's `swap_subtree`: the helper closes every opened limit. It stays off for nested-fleet Instances.
- Python start-up is on the wake's critical path. The proof measures it inside the Node timing set.
- Follow-ups: a native engine if the gates miss on helper time; a nested-fleet proof before its fast path turns on; a separate contract for the visible loader.

## Affects

- Components: apps/gateway, apps/docs
- ADRs: extends the fast-wake exception of [ADR 0250](/decisions/0250-run-instances-and-task-groups-in-incus-containers) step 8; keeps the frozen agent of [ADR 0253](/decisions/0253-patch-nodes-and-reboot-them-drained)
- Swamp: the helper and the role tasks under `swamp/ansible`, the sleep and wake phases, and their workflows
- Detail: [Prepared restore](/reference/prepared-restore) and [Sleep and wake](/reference/development-containers#sleep-and-wake)
- Verify: the helper's Python tests; `swamp/tests/instance_container_wake_test.ts`; `apps/gateway/tests/Feature/Domain/Instances/InstanceSleepWakeTest.php`; `bin/check-flows`; `bin/swamp-shape-gate`; the installed proof of `swamp/proof/instance/sleep.cases.yaml` and `wake.cases.yaml`
