---
title: "Preserve work across Gateway releases"
description: "In progress. Drain workers before publishing new code, scope SSH connections to their caller, and keep recipe history separate from attempt state."
---

# ADR 0230: Preserve work across Gateway releases

A Gateway release must transfer ownership of work before publishing new code. It must preserve both active remote commands and the last successful recipe record.

## Status

In progress.

Principle: [Deterministic first and lean](/mission#principles). Use an observed worker drain and explicit ownership of connections and convergence state.

This is a design proposal for the 2026-10-22 rollout incident. Its pull request stays draft. Runtime changes and regression tests require implementation and review before this decision is complete.

## Context

The operator reported image builds 53 and 54 queued during a release handoff and executed by old workers. Sabre's recipe convergence returned `recipe-stage:remote` when release `8cec732b` went live at 10:23:30Z. That attempt removed the recorded recipe. A rerun applied no changes and recorded it again. These observations come from the incident report; this investigation uses repository code and installed dependencies, without live system access.

### Queue shutdown has no publication barrier

`GatewayHorizon::converge` calls `horizon:terminate` after the current release link changes. The command sends SIGTERM and writes the queue restart timestamp. It returns without observing worker exit. `GatewayRuntimeHandoff::schedule` can then queue the new recipe's image builds. Requests can also enqueue jobs from the newly published release.

In Horizon 5.50.1, `MasterSupervisor::terminate` removes the master's Redis record before its supervisors finish. That record's absence does not prove that workers exited. Laravel 13.37.0's worker can pop a job before checking the restart timestamp at the end of its loop. The six queues contain no consumer release fence. A signal request and the restart timestamp therefore cannot prove that old workers stopped reserving new work.

The [queue release reference](/reference/gateway-queue#releases) promises that active jobs finish before restart. [ADR 0206](/decisions/0206-build-task-vm-images-as-chains-of-small-jobs) relies on a build's next step running after that handoff. [ADR 0209](/decisions/0209-run-long-operations-as-durable-queued-operations) also relies on active operations finishing. Moving image dispatch after scheduler handoff alone leaves other producers exposed.

### Shared SSH connections have an unrelated owner

`NativeSshExecutor` uses `ControlMaster=auto` with one socket per target in `ORBIT_HOME/ssh/mux`. [SSH connections](/reference/node-provisioning#ssh-connections) explicitly shares these sockets across requests, scheduler commands, and workers. The process that first opens a connection owns its master. `setsid` changes its process group; it does not move it out of its systemd cgroup.

`GatewayRuntimeHandoff::serve` restarts agent-view. The scheduler phase restarts other application Processes. Stopping one of those units can kill a shared SSH master created in its cgroup, including channels used by another process's recipe convergence. `NodeRecipeProgram` maps an unsuccessful SSH result without a recipe failure envelope to `recipe-stage:remote`.

This is a concrete interruption path consistent with the report. The report does not identify which process owned Sabre's connection. A controlled reproduction must establish the mechanism; the draft does not claim a confirmed owner or host timeline.

### Recipe state destroys history before attempting work

`ConvergeNodeRecipeAction` unsets `settings.machine_recipe` immediately before `NodeRecipeProgram::apply`. A transport interruption, package failure, Doctor failure, or killed Gateway process therefore discards the last successful recipe. The failure test in `ConvergeNodeRecipeTest.php` requires that deletion. Removing the deletion alone would make a partly changed Node appear current to `ConvergeFleetRecipeAction`, which reads `machine_recipe.version` as its success check.

The [recipe reference](/reference/node-provisioning#converge-a-node-to-a-recipe) describes deletion on failure. Changing it requires a separate meaning for historical success and the state of the current attempt.

## Decision

Use one release protocol that drains consumers before promotion, gives each SSH master one caller lifetime, and records recipe attempts separately from successful convergence.

### Drain consumers before publishing code

Add one release-owned consumer hold shared by deploy, rollback, recovery, and manual handoff. Bind it to the release record and the observed source and target SHAs. It covers all six existing queues and adds no queue or alternate worker implementation.

1. Before snapshot, migration, or link promotion, install the hold and suppress Horizon's automatic restart through its managed unit's start gate. The hold must survive death of the releasing process. Only the release owner can advance or recover it.
2. Ask the old Horizon master to terminate gracefully. Observe the original unit invocation and its entire cgroup until every supervisor, worker, and child has exited. A missing Redis master, changed MainPID, or successful signal alone is insufficient. Do not stop the unit or kill an active job to finish this phase.
3. On timeout or incomplete observation, refuse promotion, retain the source link, and record the held drain. Recovery either resumes the source consumers or completes the same drain. It must not start a second worker generation beside the first.
4. After a successful drain, keep consumers held through migration, promotion, and HTTP verification. Producers may enqueue while consumers are held. Once the target is verified, release the hold, start Horizon, and require its running invocation to resolve to the target release before dispatching release recipe builds or declaring handoff complete.
5. Every switch-back must first establish a fresh hold and observe the complete drain of the generation that is now running. This includes a rollback that resumed target workers after HTTP verification and then failed scheduler handoff, web publication, or smoke. Retain the current link if this drain times out or cannot be proved. Switch back only after the drain; confirm the serving release before resuming consumers. Persist each phase before its side effect so recovery can finish a transition without guessing from the current link.

Work reserved before the hold finishes on its source release. Waiting or delayed work is delivered on the verified target as the existing queue retry path; a handoff does not reserve or release it and consumes no attempt. A job created by target code cannot reach a source worker, because target code is published only after source workers exit. Pending work retains its UUID, chain and batch metadata, unique lock, delay, and attempt budget.

This does not make arbitrary serialized jobs compatible with arbitrary releases. Preserve the [requirement to inspect queued payloads under a release hold](/reference/gateway-recovery#stage-a-release-with-consumers-held) for removed job classes or incompatible payload changes. Do not add an automatic payload converter or deserialize an unsupported job. A destructive schema change still needs the full operator-maintained writer hold; this consumer drain does not hold HTTP or external console writers.

The first rollout of this protocol must install and verify the unit start gate before asking an existing master to exit. The narrow privileged helper must accept only the managed Horizon unit and the release-bound hold transition. It must not expose arbitrary unit edits or commands. Recovery and rollback use the same hold protocol. Because the source release lacks both this protocol and caller-scoped SSH sockets, deploy its first implementation under the existing full writer hold, after every source request, command, and shared SSH channel has drained. A normal automatic release cannot safely bootstrap that ownership change. The existing stage operation must preserve its operator hold and must not invoke consumer recovery implicitly.

### Scope SSH masters to one caller lifetime

Keep OpenSSH multiplexing within a Gateway PHP process, with one socket identity per process lifetime and connection identity. Use an unpredictable lifetime token, not a PID alone. Include the pinned identity and jump path in the connection identity. Keep the socket path within the Unix limit and preserve the existing connection options, directory permissions, and direct reachability probes.

A process can reuse its own connection for successive remote commands. Another process must open its own master. Thus restarting agent-view, a scheduler, or a Horizon worker can affect only that process's channels. Idle masters keep the existing 60-second expiry. Do not reuse old global sockets, retry an interrupted mutating command automatically, or disable SSH multiplexing for one recipe call as a special case.

This changes the decision from ADR 0127 to share connections across processes. It adds an initial connection per caller rather than a new connection service. A worker can retain its connection across jobs while it lives; its own active job still requires the graceful consumer drain.

### Preserve last success and record each attempt

Keep `settings.machine_recipe` as the last successful recipe record. Add a separate durable attempt record with a unique attempt ID, requested recipe identity and snapshot, source Gateway SHA, start and completion times, and outcome `running`, `succeeded`, `failed`, or `interrupted`. Record the attempt before the first mutation, under the existing Node locks. Keep only the latest attempt; do not add an unbounded history table.

Hold a local lease only for the attempt, release it on every command outcome, and let process death release it automatically. A live Horizon worker must not retain the lease after its job throws. Reconciliation changes a running attempt to interrupted when its lease is absent. A transport failure means the remote outcome is uncertain even if the local command has exited. Retain the remote update lock and existing manual inspection requirement; do not assume that closing SSH stopped package work.

On failure or interruption, retain the exact last success and record the failed or uncertain attempt separately. On success, after managed Tool and Doctor verification, update last success and attempt outcome atomically. Include partial Incus convergence in the same rule: keep `partial_version` distinct from complete verification and retain Incus hold metadata.

Update every reader that decides readiness, including fleet maintenance, nightly convergence, status output, and recovery. A running, failed, or interrupted attempt makes the Node unverified even when its last successful version equals the requested recipe. Historical recipe identity must never be a current health claim. A Node with no attempt record retains its existing success evidence until it starts its first attempt under this protocol; this introduces attempt tracking without inventing a historical attempt.

### Regression gates

Implementation must add tests that fail against the current behavior:

- Run handoff with an old worker paused immediately before its next reservation, then queue a target image-build step. Assert that the old worker exits before target publication, the target handles the job once, and no reservation or attempt is lost. Include a one-attempt job and a delayed chain step.
- Keep an active job running during the drain. Assert that it completes on the original code, its next step runs on the target, and the handoff never stops its unit. Exercise timeout, missing observations, release-process death, restart-gate installation, target verification failure, and switch-back. After target workers resume during rollback, fail smoke while a target job runs: the target link must stay until that job completes and its whole generation drains. A failed drain must retain that link and its hold. Assert that recovery starts only one worker generation.
- In isolated local processes or a disposable test topology, start a master under one service lifetime and run a long channel from another caller. End the first lifetime and assert that the second caller's channel completes. Unit tests must also prove same-caller reuse and different-caller socket separation, including a reused PID and a jump host. A test that only checks arguments cannot prove that the channel survives.
- Start recipe convergence with an existing success record. Interrupt apply with an unsuccessful SSH result containing no recipe envelope. Assert that the prior record remains byte-for-byte intact, the attempt reports uncertainty, and fleet readiness refuses it. Repeat for package failure, Doctor failure, process death, and an existing current-version success. A successful converge that applies no changes must replace the record and complete the attempt atomically.
- Cover first convergence, partial Incus convergence, preserved unrelated Node settings, and lease reconciliation without automatic remote retry. Exercise the production provider wiring and the API or command that exposes attempt state.

Run only the affected test files, with at most four workers on shared Beast. Never use live Nodes, Incus hosts, or the live Gateway for these tests. Controlled systemd and SSH lifecycle checks belong in disposable infrastructure when authorized. No runtime regression test is claimed by this design-only draft.

## Rejected alternatives

- Delay only the release's image dispatch: API, scheduler, and chain producers can still reach old workers.
- Use `horizon:terminate --wait` as the barrier: in the installed Horizon version it controls fast termination's wait behavior; it does not wait in the command for all workers to exit.
- Restart Horizon with systemd: it can end active jobs and remote commands.
- Check the Redis master list or sleep a fixed interval: neither proves worker exit or target code identity.
- Release a mismatched job in ordinary middleware: reservation already consumes an attempt, so a one-attempt job can fail before its handler on redelivery.
- Restore the old recipe record in a catch block: a killed process never reaches catch, and a partial mutation still needs an explicit unverified state.
- Retry `recipe-stage:remote`: the remote command may still be applying packages. A second mutation is not evidence of recovery.

## Consequences

Releases wait for active jobs before publishing code. Short image-build steps bound most waits; an active operation can delay a release for its remaining job budget. Producers continue to record queued work during that interval. A failed drain needs explicit release recovery and must remain visible.

SSH uses more initial connections. In return, service restarts have a bounded connection lifetime. Recipe status gains an attempt outcome and retains the last known successful inputs for recovery. This proposal amends the handoff assumptions in ADRs 0206 and 0209; implementation must update their owning references and retire this ADR when complete.

## Affects

- Components: apps/gateway, apps/docs, apps/cli, packages/php-sdk, apps/web
- ADRs: [0206](/decisions/0206-build-task-vm-images-as-chains-of-small-jobs), [0209](/decisions/0209-run-long-operations-as-durable-queued-operations), and [0218](/decisions/0218-take-long-commands-off-the-request-path).
- Detail: [Runtime handoff](/reference/gateway-recovery#runtime-handoff), [queue releases](/reference/gateway-queue#releases), [SSH connections](/reference/node-provisioning#ssh-connections), and [Node recipe convergence](/reference/node-provisioning#converge-a-node-to-a-recipe).
- Verify: Gateway release handoff, queue, SSH executor, Node recipe, and fleet maintenance tests, plus the disposable lifecycle checks in Regression gates.
