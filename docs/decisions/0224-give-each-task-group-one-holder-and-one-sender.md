---
title: "ADR 0224: Give each task group one workspace holder and one sender"
sidebarTitle: "0224 One holder, one sender"
description: "In progress; approved by Nick on 2026-10-18. One fast tick runs at a time, and a request wakes it. Each task group has one workspace holder, set in a transaction. Only the tick starts an actor or sends a turn, from one turn-request record. Remote work runs in queued jobs. A review is bound to the tree Orbit recorded at handoff. The lease ledger, the remote operation journal, and their recovery go."
---

# ADR 0224: Give each task group one workspace holder and one sender

The workspace lease of #1314 answered a real incident: Orbit merged main and ran checks in a checkout while a final review read it. One admission rule fixes that. #1314 also built a remote proof and recovery system around the rule, and none of that system has ever fired. This decision keeps the rule and deletes the rest. One fast tick runs at a time, and a request wakes it. Each task group has one workspace holder. Only the tick starts an actor or sends a turn. Remote work runs in queued jobs, not in the tick. One turn-request record replaces the per-kind send keys. A review is bound to the tree that Orbit recorded at handoff, and Orbit publishes exactly that tree.

## Status

In progress.

Principle: [Lean](/mission#principles). Seven of the eleven guards of a task checkout go, and one holder per group replaces them. It also serves [One way, one name](/mission#principles): one sender, one turn record, and one holder. No principle exception.

Nick approved this decision on 2026-10-18. It is built after the steps of [ADR 0223](/decisions/0223-run-one-gate-from-handoff-to-merge) land and [#1340](https://github.com/nckrtl/orbit/pull/1340) merges, and before Tasks moves into its own extension.

## Context

The data comes from read-only reads of the live Gateway on 2026-10-18: its database, at main `efab59781`, and Horizon. Leases went live with release 288 on 2026-10-17 at 12:26 UTC, so the lease data covers about 27 hours.

### What stalled groups

50 groups started in the five days to 2026-10-18. They used 308 wall-hours.

| Cause | Hours lost | Groups |
| --- | --- | --- |
| Task VMs have no GitHub write access, so agents asked an operator | about 31 | 1729, 1735, 1641 |
| Orbit merged main into the workspace during a final review | about 19 | 1632, 1692 |
| Topology preparation failed and woke nobody | about 16 | 1733, 1745, 1752 |
| Operator holds for infrastructure defects | about 8 | 1791, 1768, 1729 |

Groups 1645 and 1647 also waited about 9 h in `waiting_for_review`. That was the intended human merge gate: Nick rejected both changes and completed the groups within seconds. It is review time, not lost time.

The second row is the incident that the lease answers. Orbit itself started a merge and a check from a second code path while a review ran. Fixes #1297, #1303, and #1310 came first, then #1314.

### The lease never needed its recovery

- 1,321 leases ran.
- 3,195 remote operations ran, and all are `finished`. None is pending.
- No lease was ever `remote_uncertain` or `recovery_sealed`.
- No fence, seal, or tombstone ever fired.
- No operator ever ran `tasks:lease:release`.
- Gateway releases 289 to 296 ran in that window. No lease became uncertain. A release drains Horizon: `fast_termination` is false, so running jobs finish first.
- 6 of 1,321 leases waited more than 5 s.
  - Three were publication waits of about 10 s.
  - Three waited 38 to 57 minutes behind checks and verdicts of 40 to 67 minutes. That is correct serialization, and its cost is check time.
- 1,003 of the 1,321 leases, and about 2,000 of the 3,195 operations, came from one hot loop. Without it, about 320 leases and about 1,200 operations ran in 27 hours.
- In that loop, group 1791 took a full lease about every 11 s for `baseline-retry:1793`, from 12:01 to 15:07 on 2026-10-18. Group 1768 shows the same pattern 12 times.
- Commit `1f19b0973` fixed the loop on 2026-10-18: `RetryTaskBaselineAction` now checks for a pending retry before it takes the lease.
- #1330 was a production bug of the operation wrapper: systemd expanded `$` in arguments.

### Eleven guards protect one checkout

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

### One tick runs at a time already

- `tasks:tick` runs as the job `RunScheduledCommand` on the `tasks` queue, which has three workers ([ADR 0218](/decisions/0218-take-long-commands-off-the-request-path)).
- The job is `ShouldBeUnique`, with the command as its unique ID. Its unique lock lasts 330 s, and its process stops after 320 s. So no second tick job starts while one runs.
- The command also takes `Cache::lock('orbit:tasks:tick', 300)`. `GatewaySchedulerHandoff` takes the same lock to wait for a running tick before a restart.
- A tick dispatched while one runs is dropped by the unique lock. It waits for the next scheduled tick, at most 10 s.

### How long a tick takes

Horizon keeps each completed job for 60 minutes, and a 10-minute runtime average for each job class for longer.

- From 17:20 to 18:20 UTC no group ran. 322 ticks ran, with a p50 of 0.26 s, a p99 of 0.51 s, and a maximum of 0.77 s.
- From 14:23 to 16:53 UTC group 1791 ran. The 10-minute averages of `RunScheduledCommand` were 0.9 to 4.2 s.
- That class also runs `orbit:auto-deploy` and `orbit:operations-sweep`. Horizon kept no single job of that period, so the maximum of one tick is not known.

A tick of an idle Gateway is fast. Each running group adds remote work:

- 3,415 synchronous SSH operations took a p50 under 1 s, a p99 of 1 s, and a maximum of 6 s, at a resolution of one second.
- A running group costs about four SSH calls in a tick: the turn file, the receipt, a snapshot, and a check status read. A turn adds a fetch and the review packet diff.
- So at the p99 of 1 s per call, five running groups cost about 20 s per tick. One call at the maximum of 6 s adds 5 s.
- Verdict leases took a p50 of 1 s and a p90 of 2 s. Teardown leases took at most 4 s. The one `base-merge` lease took 9 s.
- One approval publication lease (`approval:3778`) was held for 248 s, from 16:33:43 to 16:37:51. That lease runs a fetch, the main merge of a final review, a pull request read, and a synchronous pull request open with its reviewer requests.
- The tick also calls GitHub: the incoming pull requests (up to three pages each minute), the watched pull request, the green-tip retry, and merge on green.
- A Pi call has a 15 s timeout and a 3 s connect timeout (`PiClient`). A tick makes up to two Pi calls for each running group.

### Sends start in many places

The `tasks` table has 89 columns. Each kind of send keeps its own durable key:

- `completion_reminder_*`, `review_reminder_*`, and `review_notified_*`;
- six `pi_restart_*` columns and the `provider_retry` JSON;
- `direction_answer_*`, `ended_pr_notice_*`, and `deliverable_correction_resume`;
- three `pending_resolution_*` columns;
- the lease's `dispatch_*` columns.

Provider-overload retry (#1228) and Pi-restart resume do one job: resume a failed turn on the same thread with a key. They keep separate budgets:

- up to four provider retries for one turn chain, with backoff;
- up to two Pi-restart resumes for one subtask, with no delay.

They are coupled: `reconcilePiRestart` rewrites `provider_retry`. The retryable categories are listed twice, in `PiDriver.php:309` and `TaskConsultation.php:897`.

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

The review records `review_workspace_head`, `review_workspace_tree`, and `review_workspace_lease_id`. It infers after the fact who changed a writable checkout.

- `TaskReviewBase::previousReview` uses `review_workspace_tree` as the base of a delta review.
- A final review writes no commit. Its approval stores the reviewed `review_workspace_head` and publishes it (`TaskReviewOutcome.php:231-241`). The incoming pull request path reads the same field (`:331`).
- Only a subtask approval writes a commit (`TaskReviewOutcome.php:245`).
- The reviewer instruction says: "Leave the workspace tree as you found it: Orbit checks" it (`TaskTurnInstructions.php:42`).
- A reviewer edit survives `changes_requested`. The implementer then continues on that tree, and the next handoff carries the edit.

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

Four guards stay:

- one unique tick at a time;
- one workspace holder per group;
- remote supervision for detached checks and topologies;
- the workspace owner foreign key.

The scheduler state gates, the IMMEDIATE transactions, and `TaskVmWorkspaceGuard` stay as they are. The other guards go. Each group has its own task VM ([ADR 0200](/decisions/0200-run-each-task-group-in-its-own-sandbox-vm)), so destroying the VM ends every actor in it. No recovery needs remote proof that a process stopped.

### The tick stays fast

The tick only decides and sends. It reads and writes the database, reads Pi threads, sends turns, and dispatches jobs. It makes no SSH call and runs no Git command. Its only outbound HTTP calls are its Pi calls, which the pass budget below bounds. So it calls no GitHub API, no model, and no webhook.

- [#1340](https://github.com/nckrtl/orbit/pull/1340) deletes the Coder settle notifier, whose webhook posts the tick makes today (`TaskConsultation.php:858`, `TaskSettling.php:568`). It is a prerequisite of this build. No step here moves those calls.

- **Bound.** A tick pass finishes in under 5 s at the p99, and no pass takes 30 s.
- `TaskTickClock` records the duration of each pass. `tasks:status` reports the p99 and the maximum of the last hour.
- Each pass has a budget of 20 s from its start. A Pi call gets the smaller of its own 15 s timeout and the budget that is left.
- When the budget is spent, the pass stops at the next group. It sets the wake marker, so the next pass starts at once.
- The tick serves groups in the order of their last service, oldest first. So a group that a pass did not reach comes first in the next pass, and every group gets service.
- So no pass takes 30 s, whatever the number of slow or hung Pi servers.
- Slow work runs in a queued job or in a detached unit. A job records its result and wakes the tick.

These tick steps break or risk the bound today. Each one moves:

| Step today | Measured | Moves to |
| --- | --- | --- |
| Turn preparation: the fetch before a turn, the turn file, and the review packet diff | About 1 s per SSH call at the p99, several per turn | A workspace step job, which stores the final message on the turn request |
| Receipt read and clear, workspace snapshots, `hasCommitsSince`, and the settle metrics diff | About 1 s per SSH call at the p99 | A workspace step job |
| The approval commit (in the verdict lease) | Verdict leases: p90 2 s | A workspace step job |
| The `approval:` lease: fetch, final-review main merge, pull request read, pull request open, and reviewer requests | One lease of 248 s | The fetch: a workspace step job. The main merge: deleted by ADR 0223. The GitHub calls and the push: the GitHub job and `PublishTaskVmCommit` |
| Check start and topology start | About 1 s per SSH call | A workspace step job |
| Check and topology status reads | About 1 s per SSH call, each tick | A status job, which needs no holder |
| Baseline-retry fetch and reset, and the resume fast-forward (`TaskSettling.php:1166`) | About 1 s per SSH call | A workspace step job |
| Incoming pull requests, the pull request watch, the green-tip retry, and merge on green | GitHub calls in every tick | A GitHub job per group, and one job for incoming pull requests |
| Brief coverage at the opening approval: `TaskBriefCoverage::missing()` calls Jev, which calls a model (`TaskReviewOutcome.php:204-209`, `Jev.php:35`) | A model call in the tick | The GitHub job, before it opens the pull request |
| Workspace removal: `RemoveTaskWorkspaceAction`, with teardown steps over SSH and the transcript archive | Teardown leases up to 4 s | The `instance.destroy` operation that ADR 0218 plans |
| Claim provisioning: `prepareSource`, the package cache, and the development setup (`TaskClaimAdmission.php:80`) | Remote setup in the tick | A claim job. The tick only reserves the group |

The jobs:

- **Workspace step job** (`RunTaskWorkspaceStep`). It runs one or more workspace steps for one group. It is unique per group, with `tries = 1`, and its `uniqueFor` is at least its timeout. It holds the group's workspace holder as kind `step`, with its job ID.
- **Status job** (`ReadTaskRunStatus`). It reads the status of a check or topology by its run ID, and records a result when it finds one. It reads only. It needs no holder, and it is unique per group. The tick dispatches it while a check or topology holds.
- **GitHub job** (`SyncTaskPullRequest`). It checks brief coverage, reads the pull request, opens it, requests reviewers, and merges on green. It is unique per group. It needs no holder, because it does not touch the checkout.
- **Incoming pull request job**. It runs `WatchIncomingPullRequestsAction` once a minute.
- **Claim job**. It provisions the reserved group, as the claim does today.

The tick stays on the `tasks` queue. The new jobs and `PublishTaskVmCommit`, which can run 1700 s, run on a new `tasks-work` queue with four workers. So a long job never delays a tick. Step 6 checks the worker count against the queue wait.

### Wake the tick

- A request or a job that records work for the tick wakes it: a turn request, a retry request, a removal request, or a job result.
- The tick job becomes `RunTaskTick`, unique until it starts processing (`ShouldBeUniqueUntilProcessing`). So at most one tick job waits in the queue. The schedule and every wake dispatch it.
- Correctness does not depend on the unique lock. It depends on two rules on the cache marker `orbit:tasks:tick:requested` and the tick lock `orbit:tasks:tick`:
  1. A wake, and every tick job when it starts, first sets the marker, then acts. A wake then dispatches `RunTaskTick`. A tick job then tries the tick lock without waiting.
  2. A tick job that gets the lock clears the marker and runs a pass. After each pass it releases the lock, and only then reads the marker. When the marker is set, it tries the lock again and runs another pass. When the lock is taken, it ends, because the tick that holds the lock reads the marker after its own pass.
- A tick job that does not get the lock ends at once, after rule 1 set the marker. No worker waits for a lock.
- So every marker that is set is read by a tick that holds the lock or that releases it afterwards. A recorded request never waits for the next scheduled tick.
- Release-then-read closes the window that the old design left: a wake that lands after a tick's last read finds the lock free, so its tick job gets the lock.
- When no tick runs, the job starts at once. So a send follows its request in about a second, after any step job that prepares the turn.
- A test runs every interleaving of a wake with a tick's last pass, with Laravel's `UniqueLock` and a real cache, and checks that a pass follows each wake.
- The schedule still dispatches the tick every 10 seconds.
- The tick stays the only sender.

### One workspace holder per group

- The group row holds one workspace holder: a kind and an ID.
- The kinds are `implementer` and `reviewer` (a thread), `check` (a check run), `topology` (a topology run), and `step` (a workspace step job).
- The tick sets the holder with a conditional update in one IMMEDIATE transaction, only when no holder is set. The same transaction re-reads the group status, so an ended group gets no new holder. That is the completion fence that `TaskExecutionHold` gives today.
- The tick dispatches a workspace step only while the group has no holder. It sets the `step` holder in the same transaction.
- A step job checks that it is the recorded holder before it acts. When it finishes, it clears the holder and wakes the tick.
- When the holder names a step job whose unique lock is free, the job ended without clearing it. The tick clears the holder and dispatches the step again. Each step is safe to run again.
- At the start of a step, a `.git/index.lock` is stale, because the step holds the workspace. The step removes it and records that in Activity.
- An agent turn holds until the tick observes that the dispatched turn ended. Pi reports `done` after a normal stop and `failed` after an interrupt or a provider failure. `idle` comes before any response, so it ends nothing.
- The rule is bound to the turn ID of the dispatched turn request, as the reconciliation in `TaskWorkspaceLease.php:766-774` does today. A state of another turn does not count.
- A step job then reads and clears the receipt. An unreadable runtime keeps the holder.
- A detached check holds until the status job records its result.
- The check run ID lives on the check row, in a new `task_checks.run_id` column. Today `TaskCheck::process()` reads it from `task_workspace_leases.check_run_id`. The terminal `lost` rule of ADR 0223 (its decision part 8, built in its build step 7) carries over: a run that systemd proves ended releases the holder.
- A topology unit holds until the status job records its result. It keeps its supervision.
- Publication needs no holder. `PublishTaskVmCommit` takes the approved commit out of the VM by its SHA and pushes it from the Gateway mirror. It reads no working tree.
- There is no queue of waiters. One subtask runs at a time, so the tick picks the next actor from the group state.
- There is no expiry and no release command.
- `TaskExecutionHold::active()`, the watched pull request completion gate, stays as a predicate on the group model.
- `ParkIdleTaskVmsAction` parks a VM only in a transaction that sees no holder. That replaces its parking fence.

### Cancel and complete

- `tasks:cancel` and `tasks:complete` keep their push, their interrupt, and their answer, as ADR 0218 decided. An interrupt is not a send.
- `tasks:complete` still answers `tasks.subtask_interrupt_failed` when it cannot stop an agent.
- The tick clears the holder once it observes the stop. A step job ends on its own, within its timeout.
- When a holder cannot be stopped, the tick destroys the VM, which ends it.
- Then the tick starts the workspace removal, the `instance.destroy` operation.

### Only the tick starts actors and sends turns

- `tasks:tick` is the only place that starts an actor or sends a turn.
- A request or an action records its intent in one transaction, as a turn request or a retry request. Then it wakes the tick.
- `tasks:comment:create` records a turn request for the relay or the answer. It no longer calls the agent in the request.
- A baseline retry is a recorded request on the subtask. The tick acts on it only when it is set.
- A send has two phases. A workspace step job prepares the turn: it fetches, writes the turn file, builds the review packet, and stores the final message on the turn request. Then the tick sends the request.
- The dispatcher is a separate class, `TaskTurnDispatcher`. It is the only caller of an agent driver's `send`, `create`, and `respond` in Tasks code. Only the tick calls it.
- `AgentDriverRegistry::get` returns the plain driver. `LeasedAgentDriver` goes.
- An architecture test fails on a call of an agent driver's `send`, `create`, or `respond` in Tasks code outside the dispatcher. It also fails on a caller of the dispatcher outside the tick.
- A second architecture test fails on an SSH call or a Git command in the tick. It also fails on any HTTP client in the tick other than the Pi client, which covers GitHub, model, and webhook calls.

### One turn-request record

- One table, `task_turn_requests`, holds every turn that Orbit sends. Each row holds:
  - the subtask and the thread;
  - the kind and the cause;
  - the key and the message;
  - the state: `pending`, `ready`, `sent`, or `done`;
  - the attempt count and the earliest send time.
- `pending` waits for its step job. `ready` holds the final message, and the tick sends it.
- The key is unique. Pi uses it as the send key and keeps accepted keys across restarts ([Pi server: Restarts](/reference/pi-server#restarts)). So a repeated send after a lost reply opens no second turn.
- A reminder request is keyed by its attempt, so one reminder per attempt holds as today.
- A failed turn gets one `resume` request, with the cause `provider_error` or `pi_restart`.
- Each cause keeps today's budget and delay. A provider error gets four retries with backoff. A Pi restart gets two resumes per subtask, with no delay, counted from the `resume` rows with that cause.
- The retryable categories live only in `PiDriver`.
- The record replaces the per-kind key columns, the `pending_resolution_*` columns, `retryPendingResolution`, and the lease `dispatch_*` columns.
- These columns are not send keys, so they stay: `completion_handoff_turn_id`, `completion_handoff_comment_id`, `completion_handoff_attempt`, and `deliverable_correction_check_id`.

### Supervision only for detached checks and topologies

- A detached check keeps its run-ID system service and its result by run ID, because it must outlive a Gateway restart.
- A topology unit keeps its supervision for the same reason.
- Every other remote command is one plain SSH command with the transport timeout.
- That command has no journal row, receipt, tombstone, seal, `systemd-run` wrapper, cgroup proof, or second round trip.

### A review is bound to the handoff tree

- The handoff check already writes the working tree as a Git tree object (`tree_after`, through `git write-tree` on a copy of the index).
- Orbit records that tree and `head_after` on the handoff, and points `refs/orbit/handoff/<subtask>` at the tree, so Git cannot prune it.
- When the tick starts a review, it records the review base in `review_workspace_head` and `review_workspace_tree`. For a subtask review that is the handoff head and tree. For a final review it is the current `HEAD` and its tree.
- The review packet diffs the subtask start commit against the recorded tree. A delta review diffs against the previous review's recorded tree.
- The holder rule admits no other writer while the reviewer runs.
- `TaskTurnInstructions` tells every reviewer that the checkout is read-only. It also says that Orbit discards any edit and publishes only the tree that the implementer handed off.
- After every reviewer turn, whatever its outcome, a workspace step restores the index and the working tree to the recorded review tree and runs `git clean -fd`. It runs before any other actor starts. It records any difference in Activity.
- So a reviewer edit cannot reach the implementer after `changes_requested`, and it cannot reach a commit after an approval.
- `git clean` runs without `-x`, because `-x` would delete ignored files that no tree holds, such as `vendor`, `node_modules`, and `.env`.
- An approval always commits the recorded tree on the recorded head, with `git -c commit.gpgsign=false commit-tree`. Orbit's fixed author and committer, and the dates of the approval record, make a retry write the same commit.
- When the recorded tree equals the tree of the recorded head, Orbit writes no commit, as `RemoteTaskWorkspaceSigner` skips empty commits today.
- Orbit moves the branch to that commit, resets the checkout to it, and publishes exactly that commit.
- A final review publishes its recorded head.
- So a stray edit can never reach the pull request: the approval commits the recorded tree, not the checkout.

### What a race must show

Orbit counts a race only when it occurs in testing, deploying, or production.

- The race that occurred was Orbit acting from a second code path. One holder and one sender close it.
- Two ticks cannot overlap: a pass needs the tick lock, and a pass ends in under 30 s, well before the lock's 300 s expiry.
- A delayed SSH request after recovery did not occur in 3,195 operations.
- A second Gateway host does not exist.
- A lost Pi runtime with a live turn did not occur in 1,321 leases.

When another race occurs, it gets its own decision with its data.

### Group 1758 and group 1731

- This ADR supersedes the scope of backlog group 1758. Its one entry point is the dispatcher above.
- Its first deliverable is the architecture test: a call of an agent driver's `send` outside the dispatcher fails.
- Group 1758 closes when step 3 below merges, because the test passes only then.
- Group 1731, "One workspace lease", was delivered by #1314. Mark it done by the existing convention for work delivered outside the task flow.

### Before Tasks moves into its own extension

[ADR 0210](/decisions/0210-give-each-extension-its-own-code-boundary) moves Tasks behind its own boundary only after its scheduler is simplified (its step 5). Before that move starts, these must hold:

1. Steps 1 to 9 below are merged. The lease and operation tables are then dropped, so the move carries no recovery code.
2. Both architecture tests pass: the one sender, and no remote call in the tick.
3. No Tasks service calls back through `TaskScheduler`. The tick is its only entry.
4. Step 3 of the AI router move ([ADR 0212](/decisions/0212-turn-proxycli-into-the-ai-router-extension)) is done, because Tasks depends on it.
5. The plan of group 1775 decides three open points:
   - whether the `TaskVms` namespace moves too;
   - whether `ModelKeyIssuer` stays in core, or Tasks imports the AI router;
   - how it treats ADR 0215.

### Build order

The build starts after two prerequisites:

- The steps of [ADR 0223](/decisions/0223-run-one-gate-from-handoff-to-merge) land, so the main-merge actor is already gone.
- [#1340](https://github.com/nckrtl/orbit/pull/1340) merges, so the tick sends no webhook.

 It ends before Tasks moves into its own extension. Each step is one small pull request. Docs change with the code in the same pull request.

1. **Wake and measure the tick.** Add `RunTaskTick`, the wake marker, and the release-then-read rule, with the interleaving test. `TaskTickClock` records each pass. `tasks:status` reports the p99 and the maximum. Doctor fails when the p99 is 5 s or more, or when a pass took 30 s.
2. **One dispatcher.** Add `TaskTurnDispatcher`, `task_turn_requests`, and the first architecture test. The test starts with an allowlist of today's send sites. Move the tick's own sends to the dispatcher. The key columns stay, because the actions of step 3 still write them.
   - `LeasedAgentDriver` stays in this step. The direct sends of step 3 still need its dispatch bookkeeping, and the dispatcher calls drivers through it.
   - Delete the pending-send replays in `TaskSubtaskRunner` (`:78`), `TaskReviewOutcome` (`:77-80`), and `TaskSessionActor` (`:105`), and `TaskWorkspaceLease::pendingSend`.
   - Delete the opening-turn bypass in `TaskAgentSpawner`.
3. **Requests record intent and wake the tick.** Move every send of `StoreTaskCommentAction` and `ResumeDeliverableCorrectionAction` to turn requests. Each request wakes the tick. A baseline retry becomes a recorded request. Empty the allowlist. Then no code writes the key columns, so this step drops them.
   - Delete the `completion_reminder_*`, `review_reminder_*`, and `review_notified_*` key columns, with their writes in `StoreTaskCommentAction` (`:457-459`) and `ResumeDeliverableCorrectionAction` (`:114-119`).
   - Delete `LeasedAgentDriver`, because this step moves its last callers. The turn requests now hold its dispatch bookkeeping, and `AgentDriverRegistry::get` returns the plain driver.
   - Delete `pending_resolution_*` and `retryPendingResolution`.
   - Delete the `direction_answer_*`, `ended_pr_notice_*`, and `deliverable_correction_resume` columns.
   - Delete the `resolution_delivered_comment_id` trigger of the baseline retry in `TaskSubtaskRunner`.
4. **One resume.** Merge provider retry and Pi-restart resume into one request with a cause.
   - Delete the six `pi_restart_*` columns and `provider_retry`.
   - Delete the rewrite of `provider_retry` in `reconcilePiRestart`.
   - Delete the second category list in `TaskConsultation` and its dead `driver !== 'pi'` check.
5. **Bind reviews to the recorded tree.** Record the review base when a review starts. Restore the checkout after every reviewer turn. Change `TaskReviewBase`, `TaskReviewPacket`, the subtask approval commit, and the final-review publication to read the recorded base. Change the reviewer text at `TaskTurnInstructions.php:42` to say that the checkout is read-only.
   - Delete `review_workspace_lease_id`.
   - Delete the `orbit_commit`, newer-implementer, and causal-chain branches of `reviewWorkspaceDecision`.
   - Delete `recoveredCommit`, `ownedWorkspaceChange`, `restartReviewOnHead`, and `TaskWorkspaceLease::ownedChange`.
6. **Move remote work out of the tick.** Add the workspace step job, the status job, the GitHub job, the incoming pull request job, the claim job, and the `tasks-work` queue. Split each send into its two phases. Add the pass budget and the second architecture test.
   - While the lease still exists, a workspace step job takes the workspace lease, as the tick's synchronous steps do today. The status job and the GitHub job take none. So admission and completion keep today's rules in this step.
   - Delete the SSH, Git, GitHub, and model calls of the tick that the table above names.
   - Delete the call of `WatchIncomingPullRequestsAction` in `TickTaskSessionsCommand`.
   - Delete the synchronous workspace removal of the tick. It starts `instance.destroy` instead.
   - `PublishTaskVmCommit` wakes the tick when it ends.
7. **One holder, no journal.** One pull request, because the lease releases a dead owner only through the journal. Add the holder on the group row. The tick sets and clears it. The step job of step 6 now holds the `step` holder instead of a lease. `NativeSshExecutor` sends each SSH call once.
   - Add `task_checks.run_id`. A migration copies each retained `check_run_id` from its lease row. Change `TaskCheck::process()` (`TaskCheck.php:53-58`) to read it.
   - Delete `task_workspace_leases`, `TaskWorkspaceLease`, and its record model, with the FIFO waiters, the `dispatch_*` and causality columns, and `adoptExistingOwner`.
   - Delete `RemoteWorkspaceOperationJournal`, `resources/tasks/workspace-operation.py`, `task_workspace_operations` and its model, and the `TaskWorkspaceOperationRecovery` interface and binding.
   - Delete the `systemd-run` wrapper of synchronous SSH calls, with its receipts, tombstones, binding seals, and lock files.
   - Delete `TaskWorkspaceLeaseRecovery`, `tasks:lease:release` and its `--gateway-node` option, Gateway process identity, and the remote program `gateway.task-workspace-owner`.
   - Delete the group and Instance flocks: `TaskExecutionLock`, `NativeTaskExecutionLock`, and `TaskExecutionHold`, except its `active()` predicate.
   - Change the lease users: `TaskCheck`, `StopTaskSubtaskAction`, `CancelTaskCheckAction`, `CancelTaskGroupAction`, `CompleteTaskGroupAction`, `UpdateTaskGroupAction`, `RequestEndedPullRequestAssistanceAction`, `ResumeDeliverableCorrectionAction`, `RemoveTaskWorkspaceAction`, `ShowTasksStatusAction`, `TaskWorkspaceExecutor`, `TaskSessionObserver`, `TaskConsultation`, `TaskSettling`, `TaskPublication`, `GitHubTaskPullRequestPublisher`, `InstanceDoctorProbe`, and `PublishTaskVmCommit`.
   - Change the parking fence of `ParkIdleTaskVmsAction` to the holder.
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
- **A lease that expires.** An expired lease admits a second writer while a turn still runs. The holder ends only when Orbit observes the actor stop.
- **Run workspace steps inside the tick.** One slow SSH call or GitHub call then delays every group. One approval lease ran for 248 s.
- **Wait for the next scheduled tick.** A comment would wait up to 10 s, and a send that follows a job would wait again.
- **A blocking tick lock.** Each wake during a tick would hold one more of the three `tasks` workers in a lock wait, and Horizon's job time would then measure waits, not ticks.
- **Read the marker before the job's unique lock is released.** A wake between that read and the release finds the lock held and its dispatch dropped, so it waits for the schedule. A review reproduced that window on Beast. Releasing the tick lock before the last read closes it.
- **Let a request send directly.** That is the second code path that caused the incident.
- **Remote proof that each SSH command ended.** It defends against a delayed request after recovery, which never occurred in 3,195 operations. It adds a second round trip to every call, and it caused #1330. Destroying the VM is the final recovery.
- **Infer review drift from a causal chain.** Restoring the recorded tree after each reviewer turn, and committing that tree, make drift one recorded difference.
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
- A tick pass stays under 5 s at the p99, so one slow group no longer delays the others.
- More work runs as queued jobs on the `tasks-work` queue.
- A comment, a resolution, or a removal wakes the tick. A send follows in about a second, after its step job.
- A Gateway release drains Horizon, so a running step job finishes. Only a worker or host death ends a step job early. Then the next tick clears the `step` holder and dispatches the step again.
- A holder that never ends needs a cancel, which destroys the VM.
- The VM keeps no receipts, seals, or lock files, so nothing needs pruning.
- A reviewer's edit is discarded and recorded. It never reaches the implementer or the pull request.
- `tasks:status` shows the holder and the tick durations instead of `workspace_leases`, so its clients change.

## Affects

- Components: apps/cli, apps/docs, apps/gateway, apps/web, packages/php-sdk
- ADRs: [0210](/decisions/0210-give-each-extension-its-own-code-boundary): this ADR states what its step 5 needs. [0211](/decisions/0211-ship-node-changes-as-remote-programs): the remote program `gateway.task-workspace-owner` and `workspace-operation.py` go. [0218](/decisions/0218-take-long-commands-off-the-request-path): `tasks:comment:create` no longer calls an agent in the request; the claim's remote work leaves the tick; the tick starts `instance.destroy` for every workspace removal. [0223](/decisions/0223-run-one-gate-from-handoff-to-merge): its lease changes carry over to the holder in step 7 here. They are the rename in its build step 3, and the terminal `lost` release of its decision part 8, built in its build step 7
- Detail: [Tasks](/reference/tasks): Scheduler, Workspace lease, Task workspace, Session routing, Retry a transient provider error, Recover a Pi server restart, Review a subtask, "The reviewer does not edit", Cancel a stuck task, and Complete and cleanup. [Gateway queue](/reference/gateway-queue). [Doctor](/cli/doctor) and [`tasks:status`](/cli/tasks#orbit-tasksstatus)
- Verify: the tests and live reads below.
  - In apps/gateway, the architecture test that forbids an agent `send`, `create`, or `respond` outside the dispatcher.
  - In apps/gateway, the architecture test that forbids an SSH call, a Git command, and any HTTP client other than the Pi client in the tick.
  - In apps/gateway, `php artisan test --compact --filter='TaskScheduler|TaskSubtaskRunner|TaskReviewOutcome|TaskPublication|TaskConsultation|StoreTaskComment|RetryTaskBaseline|CancelTaskGroup'`.
  - In apps/gateway, a test that a reviewer edit after `changes_requested` is gone before the implementer resumes.
  - On the live Gateway, after the build, the tick's p99 stays under 5 s, and no pass takes 30 s.
  - A send follows its comment within about a second plus its step job.
  - No group has two actors in its workspace.
  - Each synchronous step makes one SSH round trip.
