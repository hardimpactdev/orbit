---
title: "ADR 0224: Give each task group one workspace holder and one sender"
sidebarTitle: "0224 One holder, one sender"
description: "In progress; a proposal that Nick has not approved. Each task group has one workspace holder, set in a transaction. Only the scheduler tick starts an actor or sends a turn, from one turn-request record. Only detached checks keep remote supervision. A review is bound to the tree Orbit recorded at handoff. The lease ledger, the remote operation journal, and their recovery go."
---

# ADR 0224: Give each task group one workspace holder and one sender

The workspace lease of #1314 answered a real incident: Orbit merged main and ran checks in a checkout while a final review read it. One admission rule fixes that. #1314 also built a remote proof and recovery system around it, and none of that system has ever fired. This proposal keeps the rule and deletes the rest. Each task group has one workspace holder. Only the scheduler tick starts an actor or sends a turn. One turn-request record replaces the per-kind send keys. Only detached checks keep remote supervision. A review is bound to the tree that Orbit recorded at handoff, and Orbit publishes exactly that tree.

## Status

In progress.

Principle: [Lean](/mission#principles). Three mechanisms replace eleven, and the parts that no real case uses are deleted. It also serves [Security fits the real threat model](/mission#principles): Orbit stops defending against races that do not occur, such as a delayed SSH request after recovery and a Gateway that moves hosts during a lease. It serves [One way, one name](/mission#principles) too: one sender and one turn record. No principle exception is proposed.

This is a proposal for Nick's review, written on 2026-10-18. Nick has not approved it. Build nothing from it until he approves it.

## Context

The data comes from read-only queries of the live Gateway database on 2026-10-18, at main `efab59781`. Leases went live with release 288 on 2026-10-17 at 12:26 UTC, so the lease data covers about 27 hours.

### What stalled groups

50 groups started in the five days to 2026-10-18. They used 308 wall-hours.

| Cause | Hours lost | Groups |
| --- | --- | --- |
| Task VMs have no GitHub write access, so agents asked an operator | about 31 | 1729, 1735, 1641 |
| Orbit merged main into the workspace during a final review | about 19 | 1632, 1692 |
| Topology preparation failed and woke nobody | about 16 | 1733, 1745, 1752 |
| An approved group waited for a manual `tasks:complete` | about 9 | 1645, 1647 |
| Operator holds for infrastructure defects | about 8 | 1791, 1768, 1729 |

The second row is the incident that the lease answers. Orbit itself started a merge and a check from a second code path while a review ran. Fixes #1297, #1303, and #1310 came first, then #1314.

### The lease never needed its recovery

- 1,321 leases ran.
- 3,195 remote operations ran, and all are `finished`. None is pending.
- No lease was ever `remote_uncertain` or `recovery_sealed`.
- No fence, seal, or tombstone ever fired. No operator ever ran `tasks:lease:release`.
- 6 of 1,321 leases waited more than 5 s. Three were publication waits of about 10 s. Three waited 38 to 57 minutes behind checks and verdicts of 40 to 67 minutes. That is correct serialization, and its cost is check time.
- 1,003 of the 1,321 lease rows came from one hot loop. On 2026-10-18 from 12:01 to 15:07, group 1791 took a full lease about every 11 s for `baseline-retry:1793`, with about 2,000 `systemd-run` operations on its VM, all for nothing. `RetryTaskBaselineAction::recover` takes the lease before it checks `requested()`, and the tick calls it whenever `resolution_delivered_comment_id` is set, which stays set after any earlier resolution. Group 1768 shows the same pattern 12 times.
- #1330 was a production bug of the operation wrapper: systemd expanded `$` in arguments.

### Eleven mechanisms guard one checkout

1. A group flock: `TaskExecutionLock`, `NativeTaskExecutionLock`, and `TaskExecutionHold` for the completion fence.
2. An Instance flock: the same lock, keyed by the negative Instance ID.
3. Durable FIFO lease rows: `task_workspace_leases`, with 45 columns from three migrations of 2026-10-16.
4. A remote operation journal: `task_workspace_operations`, with receipts, tombstones, binding seals, and lock files on the VM. Every SSH call in a lease runs through `sudo systemd-run` with cgroup proof, a 0.1 s poll loop, and a second SSH round trip to read the receipt (`RemoteWorkspaceOperationJournal`, `resources/tasks/workspace-operation.py`).
5. A run-ID system service and a start tombstone for detached checks.
6. Gateway process identity, of host, boot, PID, and start ticks, with the remote program `gateway.task-workspace-owner` for an owner "on another Gateway host". The fleet has one Gateway.
7. The workspace owner column and its database trigger (#1318), and `TaskVmWorkspaceGuard`.
8. The ownership checks and send keys in `LeasedAgentDriver`.
9. Scheduler state gates: subtask status, a `working` acting thread, and assistance.
10. The review baseline with its causal lease chain: `ownedChange`, `recoveredCommit`, and `newerImplementerTurn`.
11. IMMEDIATE SQLite transactions, plus about 80 `lockForUpdate()` calls in task code. Laravel compiles `lockForUpdate()` to nothing on SQLite.

`TaskWorkspaceLease.php` alone has 1,011 lines. It mixes mutual exclusion, a FIFO queue, a send ledger (`dispatch_key`, `dispatch_hash`, `dispatch_message`), and a review causality ledger (`head` and `tree` before and after, `acquisition_sequence`, `orbit_mutation`, `causality_uncertain`). The docs admit that receipts, seals, and lock files are never pruned.

`adoptExistingOwner` runs on every `reconcile` while no lease is held. It adopts checks and threads from before leases existed, and it observes every thread of the group through the driver first, with no cache. No group that started before leases is still open.

### Sends start in many places

The `tasks` table has 89 columns. Each kind of send keeps its own durable key:

- `completion_reminder_*`, `completion_handoff_*`, `review_reminder_*`, and `review_notified_*`;
- six `pi_restart_*` columns and the `provider_retry` JSON;
- `direction_answer_*`, `ended_pr_notice_*`, and `deliverable_correction_resume`;
- three `pending_resolution_*` columns;
- the lease's `dispatch_*` columns.

Provider-overload retry (#1228) and Pi-restart resume do one job: resume a failed turn on the same thread, with a key and a budget. They are coupled: `reconcilePiRestart` rewrites `provider_retry`. The retryable categories are listed twice, in `PiDriver.php:309` and `TaskConsultation.php:897`. Task code has about 30 recovery, resume, or "stale" paths. Many exist because a send can start outside the tick:

- `StoreTaskCommentAction.php:227`, `:310`, and `:338`;
- `RequestEndedPullRequestAssistanceAction.php:107`;
- `ResumeDeliverableCorrectionAction.php:97`;
- `TaskSessionActor`, for relays, rubric reminders, and resumes;
- the pending-send replay at `TaskSubtaskRunner.php:78`.

`LeasedAgentDriver` already wraps every send.

### Review drift is inferred

`TaskReviewOutcome::reviewWorkspaceDecision` has five branches: `apply`, `orbit_commit` (a lost-reply commit recovery), a newer implementer turn, the lease causal chain, and assistance. The review records `review_workspace_head`, `review_workspace_tree`, and `review_workspace_lease_id`. It infers after the fact who changed a writable checkout.

### The scheduler is a forwarding hub

`TaskScheduler` has 63 public methods. About 40 are one-line forwarders. The phase services call back through `$scheduler` about 170 times: `TaskSubtaskRunner` 70, `TaskReviewOutcome` 59, and `TaskPublication` 27.

### Group 1758

Backlog group 1758, "Send every agent turn through one entry point", points the right way. Its line references drifted after #1314. It misses the sends of `TaskSessionActor` and the replay in `TaskSubtaskRunner`. It would add a second entry point above `LeasedAgentDriver`. It has no subtasks or deliverables, so it cannot move to Todo.

## Decision

Three mechanisms stay: one workspace holder per group, the detached-check supervisor, and the workspace owner foreign key. Every other guard goes. Each group has its own task VM ([ADR 0200](/decisions/0200-run-each-task-group-in-its-own-sandbox-vm)), so destroying the VM ends every actor in it. No recovery needs remote proof that a process stopped.

### One workspace holder per group

- The group row holds one workspace holder: a kind (`implementer`, `reviewer`, `check`, or `orbit`) and the ID of the thread, the check, or the tick run.
- The tick sets the holder with a conditional update in one IMMEDIATE transaction, only when no holder is set.
- An agent turn holds until the tick observes that it stopped: its receipt, or the driver reports the thread idle. An unreadable runtime keeps the holder.
- A detached check holds until the tick records its result.
- An `orbit` holder covers a synchronous Orbit step that the tick runs: a fetch, a commit, a push, a snapshot, or a teardown. It names the tick run. When the next tick finds a holder whose tick run ended, it clears the holder and runs the step again.
- Each such step is safe to run again. A Git command that a dead tick left running holds Git's own lock, so a retry fails and waits for the next tick.
- There is no queue. One subtask runs at a time, so the tick picks the next actor from the group state.
- There is no expiry and no release command. A holder that never ends is resolved by cancelling the group, which destroys its VM.

### Only the tick starts actors and sends turns

- `tasks:tick` is the only place that starts an actor or sends a turn.
- A request or an action records its intent in one transaction: a turn request, a retry request, or a removal request. The next tick acts on it.
- A baseline retry is a recorded request on the subtask. The tick calls `RetryTaskBaselineAction` only when the request is set, and takes no holder to look.
- `tasks:cancel` and `tasks:complete` keep their checks and their answer. Their workspace removal becomes a recorded request. The tick already removes the workspaces of ended tasks, and it does so after the holder ends.
- An architecture test fails on a call to an agent driver's `send` in Tasks code outside the dispatcher.

### One turn-request record

- One table, `task_turn_requests`, holds every turn that Orbit sends: the subtask, the thread, the kind, the cause, the key, the message, the state (`pending`, `sent`, or `done`), the attempt count, and the earliest send time.
- The key is unique. Pi uses it as the send key and keeps accepted keys across restarts ([Pi server: Restarts](/reference/pi-server#restarts)), so a repeated send after a lost reply opens no second turn.
- `LeasedAgentDriver` becomes the dispatcher, the only caller of `send`. Only the tick calls it.
- A failed turn gets one `resume` request, with the cause `provider_error` or `pi_restart`, and one budget per turn. The retryable categories live only in `PiDriver`.
- This replaces the per-kind key columns, the `pending_resolution_*` columns, `retryPendingResolution`, and the lease `dispatch_*` columns.

### Supervision only for detached checks

- A detached check keeps its run-ID system service and its result by run ID, because it must outlive a Gateway restart.
- Every other remote command is one plain SSH command with the transport timeout. It has no journal row, receipt, tombstone, seal, `systemd-run` wrapper, cgroup proof, or second round trip.

### A review is bound to the handoff tree

- The handoff check already writes the working tree as a Git tree object (`tree_after`). Orbit records that tree on the handoff.
- The review packet diffs the subtask start commit against that tree. The holder rule admits no other writer while the reviewer runs.
- An approval writes the commit with `git commit-tree` from the recorded tree and the current `HEAD`. The author and committer dates come from the approval record, so a retry writes the same commit.
- Orbit moves the branch to that commit and publishes exactly that commit.
- Drift becomes one equality check before the commit. When the checkout differs from the recorded tree, only the reviewer can have written it. Orbit records the differing paths in Activity and resets the checkout to the approved commit.
- A final review is already bound to a commit, its `HEAD`, and publishes that commit.

### What a race must show

Orbit counts a race only when it occurs in testing, deploying, or production. The one race that occurred was Orbit acting from a second code path. One holder and one sender close it. A delayed SSH request after recovery, a second Gateway host, and a lost Pi runtime with a live turn did not occur in 3,195 operations and 1,321 leases. If one occurs, it gets its own decision with its data.

### Group 1758

This ADR supersedes the scope of backlog group 1758. Its one entry point is the dispatcher above. Its first deliverable is the failing architecture test: a call to an agent driver's `send` outside the dispatcher fails. Group 1758 is closed when step 2 below merges.

### Before Tasks moves into its own extension

[ADR 0210](/decisions/0210-give-each-extension-its-own-code-boundary) moves Tasks behind its own boundary only after its scheduler is simplified (its step 5). Before that move starts, these must hold:

1. Steps 1 to 9 below are merged.
2. The architecture test that allows one sender passes.
3. No Tasks service calls back through `TaskScheduler`. The tick is its only entry.
4. The lease and operation tables are dropped, so the move carries no recovery code.
5. The plan of group 1775 decides three open points: whether the `TaskVms` namespace moves too, whether `ModelKeyIssuer` stays in core or Tasks imports the AI router, and how it treats ADR 0215.

### Build order

Each step is one small pull request. Docs change with the code in the same pull request. If ADR 0223, open as a separate proposal, builds first, the main-merge actor is already gone.

1. **Stop the baseline-retry loop.** A resolution records the retry request on the subtask. The tick acts only when it is set.
   - Delete the `resolution_delivered_comment_id` trigger in `TaskSubtaskRunner`.
   - Delete the lease that `RetryTaskBaselineAction::recover` takes before `requested()`.
2. **One dispatcher.** Add the failing architecture test, `task_turn_requests`, and the dispatcher. Move the tick's own sends to it.
   - Delete the send in the pending-send replay of `TaskSubtaskRunner` and `TaskWorkspaceLease::pendingSend`.
   - Delete the `completion_reminder_*`, `completion_handoff_*`, `review_reminder_*`, and `review_notified_*` key columns.
3. **Requests record intent.** Move every send of `StoreTaskCommentAction`, `RequestEndedPullRequestAssistanceAction`, `ResumeDeliverableCorrectionAction`, and `TaskSessionActor` to turn requests. The architecture test passes.
   - Delete `pending_resolution_*` and `retryPendingResolution`.
   - Delete the `direction_answer_*`, `ended_pr_notice_*`, and `deliverable_correction_resume` columns.
4. **One resume.** Merge provider retry and Pi-restart resume.
   - Delete the six `pi_restart_*` columns and `provider_retry`.
   - Delete the rewrite of `provider_retry` in `reconcilePiRestart`, the second category list in `TaskConsultation`, and its dead `driver !== 'pi'` check.
5. **Bind reviews to the handoff tree.**
   - Delete `review_workspace_head`, `review_workspace_tree`, and `review_workspace_lease_id`.
   - Delete the `orbit_commit`, newer-implementer, and causal-chain branches of `reviewWorkspaceDecision`, with `recoveredCommit`, `ownedWorkspaceChange`, `restartReviewOnHead`, and `TaskWorkspaceLease::ownedChange`.
6. **One holder.** Add the holder to the group row. The tick sets and clears it. Removal by cancel and complete becomes a request.
   - Delete `task_workspace_leases` and `TaskWorkspaceLease`, with the FIFO waiters, the `dispatch_*` and causality columns, and `adoptExistingOwner`.
   - Delete `TaskExecutionLock`, `NativeTaskExecutionLock`, and `TaskExecutionHold`.
   - Delete `TaskWorkspaceLeaseRecovery`, `tasks:lease:release` and its `--gateway-node` option, Gateway process identity, and the remote program `gateway.task-workspace-owner`.
   - Replace `workspace_leases` in `tasks:status` and the lease probe of Doctor with the holder.
7. **No remote operation journal.**
   - Delete `RemoteWorkspaceOperationJournal`, `resources/tasks/workspace-operation.py`, and `task_workspace_operations`.
   - Delete the `systemd-run` wrapper of synchronous SSH calls, with its receipts, tombstones, binding seals, and lock files.
8. **No redundant locks.** Delete the `lockForUpdate()` calls in task code.
9. **No forwarders.** Inject the phase services into each other. Delete the one-line forwarders of `TaskScheduler` and the `$scheduler` parameter of the phase services.
10. **Close.** Absorb this ADR into [Tasks](/reference/tasks) and retire it.
    - Replace the [Workspace lease](/reference/tasks#workspace-lease) section with the holder rule.
    - Delete the migration history in present-tense docs: the owner backfill and detach, "Older reservations can lack the ID", "Older Pi servers", and the lines about pre-lease records.

## Rejected alternatives

- **Keep the lease and fix only the hot loop.** It keeps a 45-column ledger and a recovery system that no case in 1,321 leases used.
- **Group 1758 as written.** It adds a second entry point above `LeasedAgentDriver`, misses send sites, and has no deliverables.
- **A FIFO queue of waiters.** One subtask runs at a time, so the tick already knows the next actor. Only 6 of 1,321 leases waited more than 5 s.
- **A lease that expires.** An expired lease admits a second writer while a turn still runs. The holder ends only when the tick observes the actor stop.
- **Remote proof that each SSH command ended.** It defends against a delayed request after recovery, which never occurred in 3,195 operations. It adds a second round trip to every call and caused #1330. Destroying the VM is the final recovery.
- **Infer review drift from a causal chain.** Reviewing a recorded tree and publishing that tree makes drift one equality check.
- **Keep `lockForUpdate()` for another database.** The Gateway runs SQLite with IMMEDIATE transactions. A database change would bring its own locking decision.
- **Keep two resume paths.** They do one job, share a budget, and already rewrite each other's state.

## Consequences

- About 2,000 lines go: `TaskWorkspaceLease` (1,011), `RemoteWorkspaceOperationJournal` (186), `workspace-operation.py` (274), `TaskWorkspaceLeaseRecovery` (128), the release command (52), the execution locks (108), and `task-workspace-owner.py` (32), with their tests, two tables, and about 30 columns on `tasks`.
- Each synchronous SSH call is one round trip again.
- A Gateway restart during an Orbit step leaves an `orbit` holder. The next tick retries the step.
- A comment, a resolution, or a cancel acts at the next tick, not in the request. A tick runs every 10 seconds.
- A holder that never ends needs a cancel, which destroys the VM.
- The VM keeps no receipts, seals, or lock files, so nothing needs pruning.
- The review of a reviewer's stray edit is gone: Orbit discards the edit and records it.

## Affects

- Components: apps/docs, apps/gateway
- ADRs: [0210](/decisions/0210-give-each-extension-its-own-code-boundary): this ADR states what its step 5 needs. [0211](/decisions/0211-ship-node-changes-as-remote-programs): the remote program `gateway.task-workspace-owner` and `workspace-operation.py` go
- Detail: [Tasks](/reference/tasks): Scheduler, Workspace lease, Task workspace, Session routing, Retry a transient provider error, Recover a Pi server restart, Review a subtask, Cancel a stuck task, and Complete and cleanup. [Doctor](/cli/doctor) and [`tasks:status`](/cli/tasks#orbit-tasksstatus)
- Verify: the tests and live reads below.
  - In apps/gateway, the architecture test that forbids an agent `send` outside the dispatcher.
  - In apps/gateway, `php artisan test --compact --filter='TaskScheduler|TaskSubtaskRunner|TaskReviewOutcome|TaskPublication|TaskConsultation|StoreTaskComment|RetryTaskBaseline|CancelTaskGroup'`.
  - On the live Gateway, after the build: one SSH round trip per synchronous step; no group with two actors in its workspace; and no baseline-retry work while no retry is requested.
