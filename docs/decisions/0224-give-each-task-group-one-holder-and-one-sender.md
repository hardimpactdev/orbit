---
title: "ADR 0224: Give each task group one workspace holder and one sender"
sidebarTitle: "0224 One holder, one sender"
description: "In progress; a proposal that Nick has not approved. One tick runs at a time. Each task group has one workspace holder, set in a transaction. Only the tick starts an actor or sends a turn, from one turn-request record. Only detached checks and topologies keep remote supervision. A review is bound to the tree Orbit recorded at handoff. The lease ledger, the remote operation journal, and their recovery go."
---

# ADR 0224: Give each task group one workspace holder and one sender

The workspace lease of #1314 answered a real incident: Orbit merged main and ran checks in a checkout while a final review read it. One admission rule fixes that. #1314 also built a remote proof and recovery system around the rule, and none of that system has ever fired. This proposal keeps the rule and deletes the rest. One tick runs at a time. Each task group has one workspace holder. Only the tick starts an actor or sends a turn. One turn-request record replaces the per-kind send keys. Only detached checks and topologies keep remote supervision. A review is bound to the tree that Orbit recorded at handoff, and Orbit publishes exactly that tree.

## Status

In progress.

Principle: [Lean](/mission#principles). Four mechanisms replace eleven, and the parts that no real case uses are deleted. It also serves [One way, one name](/mission#principles): one sender, one turn record, and one holder. No principle exception is proposed.

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
| The human merge gate: Nick closed both pull requests without merging, then completed the groups | about 9 | 1645, 1647 |
| Operator holds for infrastructure defects | about 8 | 1791, 1768, 1729 |

The second row is the incident that the lease answers. Orbit itself started a merge and a check from a second code path while a review ran. Fixes #1297, #1303, and #1310 came first, then #1314.

### The lease never needed its recovery

- 1,321 leases ran.
- 3,195 remote operations ran, and all are `finished`. None is pending.
- No lease was ever `remote_uncertain` or `recovery_sealed`.
- No fence, seal, or tombstone ever fired.
- No operator ever ran `tasks:lease:release`.
- Gateway releases 289 to 296 restarted the Gateway eight times in that window. No lease became uncertain.
- 6 of 1,321 leases waited more than 5 s.
  - Three were publication waits of about 10 s.
  - Three waited 38 to 57 minutes behind checks and verdicts of 40 to 67 minutes. That is correct serialization, and its cost is check time.
- 1,003 of the 1,321 leases, and about 2,000 of the 3,195 operations, came from one hot loop. Without it, about 320 leases and about 1,200 operations ran in 27 hours.
- The hot loop: on 2026-10-18 from 12:01 to 15:07, group 1791 took a full lease about every 11 s for `baseline-retry:1793`, all for nothing.
  - `RetryTaskBaselineAction::recover` takes the lease before it checks `requested()`.
  - The tick calls it whenever `resolution_delivered_comment_id` is set, which stays set after any earlier resolution.
  - Group 1768 shows the same pattern 12 times.
- #1330 was a production bug of the operation wrapper: systemd expanded `$` in arguments.

### Eleven mechanisms guard one checkout

1. A group flock: `TaskExecutionLock`, `NativeTaskExecutionLock`, and `TaskExecutionHold` for the completion fence.
2. An Instance flock: the same lock, keyed by the negative Instance ID.
3. Durable FIFO lease rows: `task_workspace_leases`, with 45 columns from three migrations of 2026-10-16.
4. A remote operation journal: `task_workspace_operations`, with receipts, tombstones, binding seals, and lock files on the VM.
   - `NativeSshExecutor` sends every SSH call in a lease through it.
   - Each call runs through `sudo systemd-run`, with cgroup proof and a 0.1 s poll loop.
   - Each call makes a second SSH round trip to read its receipt.
5. A run-ID system service and a start tombstone for detached checks.
6. Gateway process identity, of host, boot, PID, and start ticks, with the remote program `gateway.task-workspace-owner` for an owner "on another Gateway host". The fleet has one Gateway.
7. The workspace owner column and its database trigger (#1318), and `TaskVmWorkspaceGuard`.
8. The ownership checks and send keys in `LeasedAgentDriver`.
9. Scheduler state gates: subtask status, a `working` acting thread, and assistance.
10. The review baseline with its causal lease chain: `ownedChange`, `recoveredCommit`, and `newerImplementerTurn`.
11. IMMEDIATE SQLite transactions, plus about 80 `lockForUpdate()` calls in task code. Laravel's SQLite grammar compiles `lockForUpdate()` to nothing.

`TaskWorkspaceLease.php` alone has 1,011 lines. It mixes four jobs:

- mutual exclusion;
- a FIFO queue;
- a send ledger: `dispatch_key`, `dispatch_hash`, and `dispatch_message`;
- a review causality ledger: `head` and `tree` before and after, `acquisition_sequence`, `orbit_mutation`, and `causality_uncertain`.

The docs admit that receipts, seals, and lock files are never pruned.

`adoptExistingOwner` runs on every `reconcile` while no lease is held. It adopts checks and threads from before leases existed. It first observes every thread of the group through the driver, with no cache. No group that started before leases is still open.

### Two ticks can overlap

- `tasks:tick` runs as a unique job on the `tasks` queue, which has three workers ([ADR 0218](/decisions/0218-take-long-commands-off-the-request-path)).
- Its lock, `Cache::lock('orbit:tasks:tick', 300)`, expires after 300 s. A longer tick lets a second tick start.
- Today only the group flock keeps two ticks off one group: `TaskExecutionHold::run` with `wait: false` in `TaskScheduler::tick`.

### Sends start in many places

The `tasks` table has 89 columns. Each kind of send keeps its own durable key:

- `completion_reminder_*`, `review_reminder_*`, and `review_notified_*`;
- six `pi_restart_*` columns and the `provider_retry` JSON;
- `direction_answer_*`, `ended_pr_notice_*`, and `deliverable_correction_resume`;
- three `pending_resolution_*` columns;
- the lease's `dispatch_*` columns.

Provider-overload retry (#1228) and Pi-restart resume do one job: resume a failed turn on the same thread with a key. They keep separate budgets: up to four provider retries for one turn chain, with backoff, and up to two Pi-restart resumes for one subtask, with no delay. They are coupled: `reconcilePiRestart` rewrites `provider_retry`. The retryable categories are listed twice, in `PiDriver.php:309` and `TaskConsultation.php:897`.

Task code has about 30 recovery, resume, or "stale" paths. Two kinds of send site exist:

- **Outside the tick:** `StoreTaskCommentAction.php:227`, `:310`, and `:338`, and `ResumeDeliverableCorrectionAction.php:97`, which `StoreTaskCommentAction.php:137` calls. A comment request sends directly.
- **Inside the tick, from many places:** `TaskSessionActor` (`:68` and its relays, reminders, and resumes), the pending-send replays at `TaskSubtaskRunner.php:78` and `TaskReviewOutcome.php:77-80`, `RequestEndedPullRequestAssistanceAction.php:107`, and the opening turns in `TaskAgentSpawner.php:68`, `:165`, and `:397`.

`LeasedAgentDriver` is the decorator that `AgentDriverRegistry::get` returns to every caller. It wraps `send`, `create`, and `respond`.

### Review drift is inferred

`TaskReviewOutcome::reviewWorkspaceDecision` has five branches:

- `apply`;
- `orbit_commit`, a lost-reply commit recovery;
- a newer implementer turn;
- the lease causal chain;
- assistance.

The review records `review_workspace_head`, `review_workspace_tree`, and `review_workspace_lease_id`. It infers after the fact who changed a writable checkout. `TaskReviewBase::previousReview` uses `review_workspace_tree` as the base of a delta review.

### The scheduler is a forwarding hub

- `TaskScheduler` has 63 public methods. About 40 are one-line forwarders.
- The phase services call back through `$scheduler` about 170 times: `TaskSubtaskRunner` 70, `TaskReviewOutcome` 59, and `TaskPublication` 27.

### Group 1758

Backlog group 1758, "Send every agent turn through one entry point", points the right way.

- Its line references drifted after #1314.
- It misses the sends of `TaskSessionActor`, `TaskAgentSpawner`, and the replays.
- It would add a second entry point above `LeasedAgentDriver`.
- It has no subtasks or deliverables, so it cannot move to Todo.

## Decision

Four mechanisms guard the checkout:

- one tick at a time;
- one workspace holder per group;
- remote supervision for detached checks and topologies only;
- the workspace owner foreign key.

The scheduler state gates, the IMMEDIATE transactions, and `TaskVmWorkspaceGuard` stay as they are. Every other guard goes. Each group has its own task VM ([ADR 0200](/decisions/0200-run-each-task-group-in-its-own-sandbox-vm)), so destroying the VM ends every actor in it. No recovery needs remote proof that a process stopped.

### One tick at a time

- `tasks:tick` takes one non-expiring flock for its whole run. The kernel releases it when the process ends, also on a crash. `NativeTaskExecutionLock` already works this way.
- A second tick job that finds the flock held ends at once.
- So a synchronous Orbit step, such as a fetch, a commit, a snapshot, or a merge, runs only inside the one tick. It needs no durable holder.
- `.git/index.lock` in a workspace with no holder and no tick step is stale, because no actor runs. The step removes it and records that in Activity.

### One workspace holder per group

- The group row holds one workspace holder: a kind and an ID.
- The kinds are `implementer` and `reviewer` (a thread), `check` (a check run), and `topology` (a topology run).
- The tick sets the holder with a conditional update in one IMMEDIATE transaction, only when no holder is set. The same transaction re-reads the group status, so an ended group gets no new holder. That is the completion fence that `TaskExecutionHold` gives today.
- The tick runs a synchronous step only while the group has no holder.
- An agent turn holds until the tick observes that it stopped: its receipt, or the driver reports the thread idle. An unreadable runtime keeps the holder.
- A detached check holds until the tick records its result.
- A topology unit holds until the tick records its result. It keeps its supervision.
- Publication needs no holder. `PublishTaskVmCommit` takes the approved commit out of the VM by its SHA and pushes it from the Gateway mirror. It reads no working tree.
- There is no queue. One subtask runs at a time, so the tick picks the next actor from the group state.
- There is no expiry and no release command.
- When a group is cancelled or completed, the tick interrupts the holder: it aborts the turn or cancels the check. When the holder cannot be stopped, the tick destroys the VM, which ends it. Then the tick clears the holder and removes the workspace with the `instance.destroy` operation of ADR 0218.
- `TaskExecutionHold::active()`, the watched pull request completion gate, stays as a predicate on the group model.

### Only the tick starts actors and sends turns

- `tasks:tick` is the only place that starts an actor or sends a turn.
- A request or an action records its intent in one transaction, as a turn request or a retry request. The next tick acts on it. A tick starts every 10 seconds unless one still runs.
- `tasks:comment:create` records a turn request for the relay or the answer. It no longer calls the agent in the request.
- A baseline retry is a recorded request on the subtask. The tick calls `RetryTaskBaselineAction` only when the request is set, and takes no holder to look.
- `tasks:cancel` and `tasks:complete` keep their push and their answer, as ADR 0218 decided.
- The dispatcher is a separate class, `TaskTurnDispatcher`. It is the only caller of an agent driver's `send`, `create`, and `respond` in Tasks code. Only the tick calls it.
- `AgentDriverRegistry::get` returns the plain driver. `LeasedAgentDriver` goes.
- An architecture test fails on a call of an agent driver's `send`, `create`, or `respond` in Tasks code outside the dispatcher. It also fails on a caller of the dispatcher outside the tick.

### One turn-request record

- One table, `task_turn_requests`, holds every turn that Orbit sends. Each row holds:
  - the subtask and the thread;
  - the kind and the cause;
  - the key and the message;
  - the state: `pending`, `sent`, or `done`;
  - the attempt count and the earliest send time.
- The key is unique. Pi uses it as the send key and keeps accepted keys across restarts ([Pi server: Restarts](/reference/pi-server#restarts)). So a repeated send after a lost reply opens no second turn.
- A failed turn gets one `resume` request, with the cause `provider_error` or `pi_restart`. Each cause keeps today's budget and delay: four retries with backoff for a provider error, and two resumes per subtask with no delay for a Pi restart.
- The retryable categories live only in `PiDriver`.
- This replaces the per-kind key columns, the `pending_resolution_*` columns, `retryPendingResolution`, and the lease `dispatch_*` columns.
- `completion_handoff_turn_id` and `completion_handoff_comment_id` record which turn handed off. They are not send keys, so they stay.

### Supervision only for detached checks and topologies

- A detached check keeps its run-ID system service and its result by run ID, because it must outlive a Gateway restart.
- A topology unit keeps its supervision for the same reason.
- Every other remote command is one plain SSH command with the transport timeout.
- That command has no journal row, receipt, tombstone, seal, `systemd-run` wrapper, cgroup proof, or second round trip.

### A review is bound to the handoff tree

- The handoff check already writes the working tree as a Git tree object (`tree_after`, through `git write-tree` on a copy of the index).
- Orbit records that tree and `head_after` on the handoff, and points `refs/orbit/handoff/<subtask>` at the tree, so Git cannot prune it.
- The review packet diffs the subtask start commit against that tree. A delta review diffs against the previous review's recorded tree.
- The holder rule admits no other writer while the reviewer runs.
- An approval checks that `HEAD` still equals the recorded `head_after`.
- When the tree equals the tree of `HEAD`, Orbit writes no commit, as `RemoteTaskWorkspaceSigner` skips empty commits today.
- Otherwise Orbit writes the commit with `git -c commit.gpgsign=false commit-tree`, from the recorded tree, with the recorded `head_after` as its parent. Orbit's fixed author and committer, and the dates of the approval record, make a retry write the same commit.
- Orbit moves the branch to that commit, then resets the checkout to it and runs `git clean -fd`. The recorded tree holds untracked files, so `-x` is not used.
- Orbit publishes exactly that commit.
- Drift becomes one equality check before the commit. When `HEAD` or the checkout differs from the record, only the reviewer can have written it. Orbit records the differing paths in Activity, and the reset discards them.
- A final review is already bound to a commit, its `HEAD`, and publishes that commit.

### What a race must show

Orbit counts a race only when it occurs in testing, deploying, or production.

- The race that occurred was Orbit acting from a second code path. One holder and one sender close it.
- Two overlapping ticks can occur: a tick longer than 300 s outlives its cache lock. The tick flock closes it.
- A delayed SSH request after recovery did not occur in 3,195 operations.
- A second Gateway host does not exist.
- A lost Pi runtime with a live turn did not occur in 1,321 leases.

A race that later occurs gets its own decision with its data.

### Group 1758 and group 1731

- This ADR supersedes the scope of backlog group 1758. Its one entry point is the dispatcher above.
- Its first deliverable is the architecture test: a call of an agent driver's `send` outside the dispatcher fails.
- Group 1758 closes when step 3 below merges, because the test passes only then.
- Group 1731, "One workspace lease", was delivered by #1314. Mark it done by the existing convention for work delivered outside the task flow.

### Before Tasks moves into its own extension

[ADR 0210](/decisions/0210-give-each-extension-its-own-code-boundary) moves Tasks behind its own boundary only after its scheduler is simplified (its step 5). Before that move starts, these must hold:

1. Steps 1 to 9 below are merged. The lease and operation tables are then dropped, so the move carries no recovery code.
2. The architecture test of the dispatcher passes.
3. No Tasks service calls back through `TaskScheduler`. The tick is its only entry.
4. Step 3 of the AI router move ([ADR 0212](/decisions/0212-turn-proxycli-into-the-ai-router-extension)) is done, because Tasks depends on it.
5. The plan of group 1775 decides three open points:
   - whether the `TaskVms` namespace moves too;
   - whether `ModelKeyIssuer` stays in core, or Tasks imports the AI router;
   - how it treats ADR 0215.

### Build order

Each step is one small pull request. Docs change with the code in the same pull request. ADR 0223, a separate proposal, deletes the main-merge gate. If it builds first, the main-merge actor is already gone.

1. **Stop the baseline-retry loop.** A resolution records the retry request on the subtask. The tick acts only when it is set.
   - Delete the `resolution_delivered_comment_id` trigger in `TaskSubtaskRunner`.
   - Delete the lease that `RetryTaskBaselineAction::recover` takes before `requested()`.
2. **One dispatcher.** Add `TaskTurnDispatcher`, `task_turn_requests`, and the architecture test. The test starts with an allowlist of today's send sites. Move the tick's own sends to the dispatcher.
   - Delete the pending-send replays in `TaskSubtaskRunner` (`:78`), `TaskReviewOutcome` (`:77-80`), and `TaskSessionActor` (`:105`), and `TaskWorkspaceLease::pendingSend`.
   - Delete the `completion_reminder_*`, `review_reminder_*`, and `review_notified_*` key columns.
   - Delete the opening-turn bypass in `TaskAgentSpawner`.
3. **Requests record intent.** Move every send of `StoreTaskCommentAction` and `ResumeDeliverableCorrectionAction` to turn requests. Empty the allowlist.
   - Delete `pending_resolution_*` and `retryPendingResolution`.
   - Delete the `direction_answer_*`, `ended_pr_notice_*`, and `deliverable_correction_resume` columns.
4. **One resume.** Merge provider retry and Pi-restart resume into one request with a cause.
   - Delete the six `pi_restart_*` columns and `provider_retry`.
   - Delete the rewrite of `provider_retry` in `reconcilePiRestart`.
   - Delete the second category list in `TaskConsultation` and its dead `driver !== 'pi'` check.
5. **Bind reviews to the handoff tree.** Change `TaskReviewBase` and `TaskReviewPacket` to read the recorded handoff trees.
   - Delete `review_workspace_head`, `review_workspace_tree`, and `review_workspace_lease_id`.
   - Delete the `orbit_commit`, newer-implementer, and causal-chain branches of `reviewWorkspaceDecision`.
   - Delete `recoveredCommit`, `ownedWorkspaceChange`, `restartReviewOnHead`, and `TaskWorkspaceLease::ownedChange`.
6. **No remote operation journal.** `NativeSshExecutor` sends each SSH call once.
   - Delete `RemoteWorkspaceOperationJournal`, `resources/tasks/workspace-operation.py`, `task_workspace_operations`, and the `TaskWorkspaceOperationRecovery` binding in `TasksServiceProvider`.
   - Delete the `systemd-run` wrapper of synchronous SSH calls, with its receipts, tombstones, binding seals, and lock files.
   - Delete `TaskWorkspaceLeaseRecovery`, `tasks:lease:release` and its `--gateway-node` option, Gateway process identity, and the remote program `gateway.task-workspace-owner`. Without the journal, they have no evidence to read.
7. **One tick and one holder.** Add the tick flock and the holder on the group row. The tick sets and clears the holder.
   - Delete `task_workspace_leases` and `TaskWorkspaceLease`, with the FIFO waiters, the `dispatch_*` and causality columns, and `adoptExistingOwner`.
   - Delete the group and Instance flocks: `TaskExecutionLock`, `NativeTaskExecutionLock` as a group lock, and `TaskExecutionHold`, except its `active()` predicate.
   - Change the lease callers: `StopTaskSubtaskAction` (`:70`), `CancelTaskCheckAction` (`:54`), `TaskWorkspaceExecutor` (`:33-34`), `TaskSessionObserver` (`:152`), `InstanceDoctorProbe` (`:228`), and `PublishTaskVmCommit`.
   - Replace `workspace_leases` in `tasks:status` with the holder, in the API, `docs/openapi.json`, the MCP description, the PHP SDK (`TaskWorkspaceLeaseResponse`), the CLI, and the web app types.
8. **No redundant locks.** Delete the `lockForUpdate()` calls in task code.
9. **No forwarders.** Inject the phase services into each other.
   - Delete the one-line forwarders of `TaskScheduler`.
   - Delete the `$scheduler` parameter of the phase services.
10. **Close.** Absorb this ADR into [Tasks](/reference/tasks) and retire it.
    - Replace the [Workspace lease](/reference/tasks#workspace-lease) section with the tick and holder rules.
    - Delete the migration history in present-tense docs: the owner backfill and detach, "Older reservations can lack the ID", "Older Pi servers", and the lines about pre-lease records.

## Rejected alternatives

- **Keep the lease and fix only the hot loop.** It keeps a 45-column ledger and a recovery system that no case in 1,321 leases used.
- **Group 1758 as written.** It adds a second entry point above `LeasedAgentDriver`, misses send sites, and has no deliverables.
- **A FIFO queue of waiters.** One subtask runs at a time, so the tick already knows the next actor. Only 6 of 1,321 leases waited more than 5 s.
- **A lease that expires.** An expired lease admits a second writer while a turn still runs. The holder ends only when the tick observes the actor stop.
- **A durable holder for synchronous Orbit steps.** With one tick at a time, only the tick runs those steps, and a crash releases its flock.
- **Remote proof that each SSH command ended.** It defends against a delayed request after recovery, which never occurred in 3,195 operations. It adds a second round trip to every call, and it caused #1330. Destroying the VM is the final recovery.
- **Infer review drift from a causal chain.** Reviewing a recorded tree and publishing that tree makes drift one equality check.
- **Keep `lockForUpdate()` for another database.** The Gateway runs SQLite with IMMEDIATE transactions. A database change would bring its own locking decision.
- **Keep two resume paths.** They do one job, and they already rewrite each other's state. One request with a cause keeps both budgets.

## Consequences

- About 1,800 lines go, with their tests:
  - `TaskWorkspaceLease`: 1,011;
  - `workspace-operation.py`: 274;
  - `RemoteWorkspaceOperationJournal`: 186;
  - `TaskWorkspaceLeaseRecovery`: 128;
  - the execution locks: 108;
  - the release command: 52;
  - `task-workspace-owner.py`: 32.
- Two tables and about 25 columns on `tasks` go.
- Each synchronous SSH call is one round trip again.
- A Gateway restart during a synchronous step ends the tick. The next tick runs the step again.
- A comment, a resolution, or a removal acts at the next tick, not in the request.
- A holder that never ends needs a cancel, which destroys the VM.
- The VM keeps no receipts, seals, or lock files, so nothing needs pruning.
- A reviewer's stray edit is discarded and recorded, not reviewed.
- `tasks:status` shows the holder instead of `workspace_leases`, so its clients change.

## Affects

- Components: apps/cli, apps/docs, apps/gateway, apps/web, packages/php-sdk
- ADRs: [0210](/decisions/0210-give-each-extension-its-own-code-boundary): this ADR states what its step 5 needs. [0211](/decisions/0211-ship-node-changes-as-remote-programs): the remote program `gateway.task-workspace-owner` and `workspace-operation.py` go. [0218](/decisions/0218-take-long-commands-off-the-request-path): `tasks:comment:create` no longer calls an agent in the request
- Detail: [Tasks](/reference/tasks): Scheduler, Workspace lease, Task workspace, Session routing, Retry a transient provider error, Recover a Pi server restart, Review a subtask, Cancel a stuck task, and Complete and cleanup. [Doctor](/cli/doctor) and [`tasks:status`](/cli/tasks#orbit-tasksstatus)
- Verify: the tests and live reads below.
  - In apps/gateway, the architecture test that forbids an agent `send`, `create`, or `respond` outside the dispatcher.
  - In apps/gateway, `php artisan test --compact --filter='TaskScheduler|TaskSubtaskRunner|TaskReviewOutcome|TaskPublication|TaskConsultation|StoreTaskComment|RetryTaskBaseline|CancelTaskGroup'`.
  - On the live Gateway, after the build: one SSH round trip per synchronous step; no group with two actors in its workspace; one tick at a time; and no baseline-retry work while no retry is requested.
