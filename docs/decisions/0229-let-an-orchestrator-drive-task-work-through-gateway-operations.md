---
title: "ADR 0229: Let an orchestrator drive task work through Gateway operations"
sidebarTitle: "0229 Orchestrator operations"
description: "In progress. An external orchestrator owns the task lifecycle; the Gateway performs each task effect as an idempotent operation on the group's task VM; Tasks keeps the board, records, and agent sessions."
---

# ADR 0229: Let an orchestrator drive task work through Gateway operations

An external orchestrator owns the lifecycle of task work: its stages, gates, budgets, approvals, and every state change. The Gateway performs each side effect as an operation that takes an `Idempotency-Key`. Every workspace step runs in the group's task VM, and publish moves the result out through the Gateway's mirror. Tasks keeps the board, the task records, assistance, and the agent sessions. Once every group runs this way, the state machine in `tasks:tick` retires, and Orbit no longer runs a workflow engine of its own.

## Status

In progress.

The shared operation-start idempotency key is built for deploy and rollback. [Operations](/reference/operations#retry-a-start-with-a-key) owns its contract and reasons. The shared waiting status, delayed step reads, and stop hooks are built; [Operations](/reference/operations#a-waiting-step) owns their contract and reasons. The first task operation, `tasks.provision`, uses them to wait for VM capacity and readiness. `tasks.check` is also built: [Check an external group](/reference/tasks#check-an-external-group) owns its contract and reasons. Turn and publish remain to be built.

Nick decided this on 2026-10-22. On the same day he decided that every step of a task runs in the group's task VM, and that Pi stays inside the VM on the Pi Durable harness. He then decided that an external group bypasses the tick, so step 1 does not wait for ADR 0224.

Principle: [Lean](/mission#principles). Orbit stops owning a hand-rolled workflow engine. A general orchestrator holds the lifecycle as data, and Orbit keeps only the effects that it alone can perform. It also serves [Deterministic first](/mission#principles), because stages, gates, and budgets become declared data with typed records. Exceptions to [One way, one name](/mission#principles) and [No exceptions and no legacy](/mission#principles): while this ADR is being built, task work has two modes, the tick and an external orchestrator, and Orbit keeps the tick's engine until its replacement works. Both exceptions end when step 3 removes the state machine from `tasks:tick`.

## Context

Tasks drives its work with an engine of its own, and no outside caller can ask it for a single effect.

### Tasks runs its own engine

`tasks:tick` claims groups, runs baseline and handoff checks, and sends implementer and reviewer turns. It counts reminders, consults, and failed checks, and it raises assistance ([Tasks](/reference/tasks)). The engine keeps its state consistent through restarts and races by hand. In the 30 days before this decision, 361 commits changed `app/Domain/Tasks`, many of them fixes to workspace ownership, recovery, and assistance transitions.

### A spike drove the loop from outside

A spike with the Swamp workflow engine drove the same loop from outside. It ran Orbit dependency upgrades as work items of a Swamp `software-factory` definition. The definition expressed the consult limit, the failed-check budget with its reset on a pass, cycle limits, and operator overrides as stages and gates. The spike ran `packages/php-sdk` and `apps/gateway` to approval. It survived SIGKILL tests without a duplicate commit or dispatch. Its effects ran on a scratch checkout, because the Gateway has no route for them.

### Every effect already exists inside Tasks

Tasks already performs every effect that task work needs, on the group's own [task VM](/reference/compute-drivers#task-vms). The claim provisions the VM and its workspace. The baseline and handoff checks run in the workspace. Implementer and reviewer turns run on the VM's [Pi server](/reference/pi-server). An approval commits the checked tree, and the Gateway pushes that commit from its own mirror. Each effect runs only when the state machine of `tasks:tick` decides it.

Only the WebSocket app routes accept `Idempotency-Key` today. Their key is scoped to the calling Node, and a conflict is `websocket.idempotency_conflict`.

### A task stays in its VM

A task VM holds everything its task needs, so the same VM runs on an Incus host or as [UpCloud overflow](/reference/compute-drivers#upcloud-overflow). A step that ran outside the VM would need a second path for each VM location.

An evaluation of the Pi Durable harness (`@earendil-works/pi-durable`) ran beside the spike. Durable keeps one agent conversation through crashes and deduplicates a submission by its request ID. Nick chose to run it inside the task VM, behind the Pi server's current [API](/reference/pi-server#api).

### Decisions this builds on

[ADR 0209](/decisions/0209-run-long-operations-as-durable-queued-operations) makes long commands durable operations. Today only `instance.deploy` and `instance.rollback` are operations. One job runs a whole operation and holds its target, an operation runs at most 1,600 seconds, and the `operations` queue has one worker ([Operations](/reference/operations#how-an-operation-runs)). [ADR 0218](/decisions/0218-take-long-commands-off-the-request-path) names a kind after its CLI command and keeps only the newest 50 finished operations of each target.

[ADR 0224](/decisions/0224-give-each-task-group-one-holder-and-one-sender) gives each group one workspace holder and makes `tasks:tick` the only place that starts an actor or sends a turn, from one turn-request record. It binds a review to the tree recorded at handoff, and it keeps detached supervision for checks and topologies. [ADR 0225](/decisions/0225-make-node-changes-through-agent-commands) states that this supervision is not a generic command runner. ADR 0224's build has not started. It waits for the remaining steps of [ADR 0223](/decisions/0223-run-one-gate-from-handoff-to-merge), and many of its build steps rework the tick's engine.

### People need to see the work

The board shows which work is in the backlog, ready, in progress, and done. A person can open a task and read its agent sessions. That view stays useful whoever drives the work.

## Decision

The orchestrator decides what happens next, and the Gateway does it on the group's task VM. Three steps build this: externally orchestrated groups with an operation for each effect, then Pi on Durable in the VM, then the retirement of the tick's state machine.

### Who owns what

- The orchestrator owns the lifecycle, every state change, and every time budget.
- The Gateway owns every effect. A key starts each operation once, and a step that cannot run again safely never runs twice.
- Tasks owns the visible record: board states, task records, assistance, and agent sessions.
- The operation that holds an external group is its one holder and its one sender. It starts the group's checks and sends its turns. `tasks:tick` sends nothing for an external group, so ADR 0224's rule of one sender per group still holds. [What the tick keeps](#what-the-tick-keeps) lists its remaining work.
- The operations are orchestrator-neutral. Any caller with Gateway access can use them.
- An orchestrator never runs a command, an agent, or a Git push itself.

### Workspaces

A group's workspace is its [task workspace](/reference/tasks#task-workspace): the Instance `task-{id}` on the group's own task VM. Every workspace step of every subtask runs there: the checks, the agent turns, and the commit that publish pushes. The subtasks share the checkout and run in order, as they do today. A development Instance is never a workspace for task work.

### One idempotency key for every operation start

A caller may send an `Idempotency-Key` header to any route that starts an operation. The header has 1 to 128 characters from `A-Z a-z 0-9 . _ : -`.

- The key is scoped to the operation kind, not to the calling Node, so an orchestrator that moves to another Node keeps its keys.
- The Gateway stores the key on the operation, with a unique index on kind and key. This amends ADR 0218's retention: an operation that carries a key is never pruned, so a key never expires.
- After the access check, the Gateway checks the key before the request validates further or creates a target, so a replay of `tasks.provision` claims no second VM.
- A repeat with the same key, the same target, and the same input answers `202` with the existing operation, whatever its status.
- A repeat with the same key and a different target or input answers `409 operation.idempotency_conflict` and starts nothing.
- The CLI passes the key with `--idempotency-key`, and each MCP tool that starts an operation takes an `Idempotency-Key` argument.
- The WebSocket app routes move to this rule when ADR 0218 makes their changes operations. Until then they keep their own rule.

An orchestrator derives the key from its work item, stage, and cycle, such as `login-form.handoff.c2`.

### Step 1: externally orchestrated groups

A task group gains `orchestration`: `tick`, the default, or `external`. Create sets it, and it never changes. Step 1 does not wait for ADR 0224. An operation takes the workspace lease for its step, as the operator's baseline retry does today. A busy lease keeps the step `waiting`; it is not a failure.

In step 1, create refuses an `external` group with `422 tasks.orchestration_unsupported` in two cases: a subtask declares a [topology](/reference/compute-drivers#size-by-declared-topology), or the Project uses [review and merge](/reference/tasks#review-and-merge). Each case needs its own amendment.

Each effect becomes an operation that wraps the code Tasks runs today:

| CLI command | Kind | Does | Wraps |
| --- | --- | --- | --- |
| `tasks:provision` | `tasks.provision` | Claims the group's task VM and creates its workspace | The [claim](/reference/tasks#claim-and-provision) |
| `tasks:check` | `tasks.check` | Runs the baseline or the handoff check of a subtask | The [Project check](/reference/tasks#project-check) and its supervision |
| `tasks:turn` | `tasks.turn` | Runs one agent turn on a subtask | The [Pi driver](/reference/tasks#drivers) |
| `tasks:publish` | `tasks.publish` | Pushes a checked commit and opens or updates the pull request | The [approval push](/reference/tasks#pull-request-and-settle-metrics) |

#### Rules for every operation

- The target is the group. A check or a turn names its subtask as an input. The Gateway serves the operation, because the VM's Node exists only while the group runs.
- A group has one operation at a time. Another operation on the group answers `409 operation.target_busy`.
- A group whose `orchestration` is `tick` answers `409 tasks.not_orchestrated`.
- An ended group answers `409 tasks.group_closed`.
- A check, a turn, or a publish on a group without a workspace answers `409 tasks.no_workspace`.
- A subtask that is not in the group answers `404`. The current subtask is the open subtask with the lowest position. Any other subtask answers `409 tasks.subtask_not_current`, so at most one subtask runs at a time.
- An operation on a parked VM first queues its resume, as [Park and resume](/reference/compute-drivers#park-and-resume) does for work of the group, and waits for it.
- The Gateway retries no effect for an external group. A retry is the orchestrator's decision.

#### Provision

`tasks.provision` claims a VM and creates the workspace as a claim does today. It sizes the VM from the subtasks, so the group must be in `todo`, which already requires subtasks with deliverables. A group in `backlog` answers `409 tasks.not_todo`, and a claimed group `409 tasks.already_claimed`.

- When no VM is free, the operation waits. It does not fail, and the group stays `reserved`.
- When task VMs are off, misconfigured, or the VM fails, the step fails with the task VM error, such as `task_vm.invalid_config`. Today these cases wait or ask for assistance.
- A failed provision ends the group `failed`, and the existing cleanup removes its VM. To try again, the orchestrator creates a new group, as [Task lifecycle](/reference/tasks#task-lifecycle) already requires.
- The step ends with the group `running`. A routed workspace ends `active` with its Route; an unrouted one ends `source_resolved`. No subtask starts, and no check runs.
- The workspace starts at the default branch, as it does today.
- The result holds the workspace Instance, its Node, and the start commit.

To stop a waiting provision, the orchestrator cancels the group with `tasks:cancel`.

#### Check

`tasks.check` takes a `subtask` and a `phase`: `baseline` or `handoff`. It runs that check as Tasks runs it today: detached in the workspace, with deliverable verification. A baseline after an implementer started in the group answers `409 tasks.baseline_done`.

- A handoff check takes its own check lease after the implementer's turn has released its lease. It does not start inside the turn's receipt scope.
- A handoff check that passes commits the checked tree, as an approval does today, and records the commit.
- The result holds the `outcome`: `passed`, `failed`, or `changed`, as the [Project check](/reference/tasks#project-check) defines them. A failing check is a result, not an operation failure.
- The result also holds each deliverable's result, the check ID, the commit, HEAD and the tree hash, and the redacted [check output](/reference/tasks#check-output).
- A `lost` check keeps the group held, as today. Once ADR 0223's terminal rule for a lost check is built, the operation stays `waiting` until Orbit proves the check ended. Until then, and whenever that proof never comes, the orchestrator cancels the group.

The commit moves the point where Orbit commits. Today an approval commits the reviewed tree. For an external group, the handoff check commits it, so a review and a publish both refer to one recorded commit.

#### Turn

`tasks.turn` takes a `subtask`, a `role` (`implementer`, `reviewer`, or `consult`), a `prompt`, a `thread`, and an optional `model`. The operation sends the turn through the Pi driver.

- `thread` is `new` or a thread of the group with the same role, or a reviewer thread for `consult`. Another thread answers `422 tasks.thread_role_mismatch`. A continued thread keeps its model: a `model` with a thread ID answers `422 tasks.model_fixed`.
- A Claude model answers `422 tasks.model_refused`, as the [Pi driver](/reference/tasks#drivers) refuses it. A model that the VM does not offer fails the step with `model.unavailable`.
- A `reviewer` turn takes the `check` ID of a passed handoff check. The workspace HEAD must be that check's commit, or the start answers `409 tasks.head_moved`. After the turn, Orbit resets the checkout to that commit and removes untracked files, and the result records any difference it removed. A `consult` turn runs read-only on a reviewer thread. Orbit does not reset after it, because the implementer's uncommitted work is in the tree; the result shows any change.
- Orbit keeps its fixed instructions: no fetch or push, a read-only reviewer, the turn command, and the workspace's MCP configuration. The task prompt is the orchestrator's. Orbit adds no rubric, reminder, or nudge.
- The send key of the Pi server message is the `Idempotency-Key`, or `operation-{id}` without one, so a repeat never starts a second turn.
- The turn's agent session attaches to its subtask, and the board shows it.

The [turn receipt](/reference/tasks#turn-receipt) still ends a turn. The result holds the stored receipt: the outcome, the deliverable confirmations, and a question with its cause. It also holds the thread, its final state, HEAD and the tree before and after the turn, and the token metrics. A turn that ends without a receipt has `receipt: null`. The operation reads, clears, and stores the receipt, and does nothing else: a `blocked` receipt starts no consult and creates no question. The orchestrator reads the receipt, not the agent's prose.

In step 1, a Pi server restart that cuts off a turn fails the operation with the restart reason. A transient provider error fails it with its category. The orchestrator decides what comes next.

#### Publish

`tasks.publish` takes the `check` ID of a passed handoff check and an optional `pull_request` with `title` and `body`. It imports that check's commit from the VM into the Gateway mirror and pushes it to `task-{id}`, as an approval does today. A push is never forced. With `pull_request`, it opens the group's pull request against the default branch, or updates an open one for the branch. Without it, the step only pushes. It runs no brief coverage and requests no reviewers. The VM and the agent never hold a GitHub token.

| Case | Result |
| --- | --- |
| The check did not pass, or belongs to another group | `422 tasks.commit_not_checked` |
| The workspace HEAD is no longer the check's commit | `409 tasks.head_moved` |
| `task-{id}` exists and is not an ancestor of the commit | The step fails with `tasks.not_fast_forward` |
| `task-{id}` already points at the commit | Succeeds without a push |
| GitHub refuses the push because the App lacks `Workflows: write` | The step fails with `tasks.workflows_permission` |

Before it opens a pull request, the step looks for an open one for the branch. A resumed step therefore never opens a second one. The result holds the branch, the pushed commit, and the pull request number and URL.

#### State and assistance

The orchestrator sets the board state of an external group with `tasks:update` and `tasks:subtask:update`. Both take a `status`, within these limits:

- A group moves between `backlog` and `todo` as today, and among `running`, `reviewing`, `settling`, and `waiting_for_review`. Only provision moves a group from `todo` to `reserved`, and only `tasks:cancel` and `tasks:complete` end a group.
- A subtask moves from `todo` to `running`, between `running` and `reviewing`, and from either to `completed` or `failed`. `completed` and `failed` are final. Only `tasks:subtask:cancel` cancels a subtask.
- Any other move answers `422 tasks.status_move_refused`. Setting the current status again changes nothing, so the commands need no key.

The orchestrator raises assistance with `tasks:comment:create` and the type `assistance_requested`. A person answers with a [resolution](/reference/tasks#resolve-a-request) comment, which the board shows. The resolution clears the assistance flag and sends nothing to an agent. The orchestrator reads it and continues.

For an external group, `tasks:subtask:cancel` interrupts the subtask's turn or check and marks it `cancelled`. It starts no other subtask and does not settle the group. `tasks:cancel` and `tasks:complete` end the group as today. They cancel the group's open operation first. A cancelled provision clears the claim's reservation, so cleanup removes what it created. When the VM does not answer, the operation ends `cancelled` without the `502`, and the group follows the existing rule for an unreachable Node ([Cancel a stuck task](/reference/tasks#cancel-a-stuck-task)): it ends, keeps the workspace, and asks for removal.

#### What the tick keeps

For an external group, `tasks:tick` does only this:

- It parks an idle VM. Parking skips a group that has an operation that is not finished.
- It reads whether the group's pull request merged or closed, and records that. It does not settle, fast-forward, or start a fixup. The orchestrator reads the state and completes the group.
- It saves transcripts and destroys the VM of an ended group.

It never claims, starts, sends, advances, reviews, consults, reminds, settles, or raises assistance for an external group.

Every other Tasks path that sends a turn, starts a check, or changes a claim skips an external group or refuses it with `409 tasks.orchestrated_externally`:

- The sends of a comment, such as a resolution or a direction answer, and the resume of a deliverable correction.
- The operator's baseline retry.
- The release of stale reservations, so a provision that waits for a VM keeps its claim.

A test lists every entry point that sends a turn, starts a check, or changes a claim, except the four operations, `tasks:cancel`, and `tasks:complete`. It proves that each one skips or refuses an external group.

#### A waiting step

This amends [ADR 0209](/decisions/0209-run-long-operations-as-durable-queued-operations). A claim that waits for a VM, a VM resume, a check, a turn, and a push can each outlast the 1,600-second deadline. None of them may hold the single `operations` worker.

An operation gains the non-terminal status `waiting`, and its current step the same status. A waiting operation has no running job and keeps its target. A delayed job calls the workspace lease's reconcile for the group, as the tick does for a tick group. Reconcile releases a turn's lease when the turn ends, records the check's reading, and updates the thread state and metrics that the board shows. The delayed job first claims the operation with one conditional update from `waiting` to `running`, then calls reconcile. The API, the list filter, and the MCP enums gain the status.

- The wait has no limit of its own, because a Project check and a turn have none. The orchestrator owns time budgets, and it cancels an operation that runs too long.
- A read that finds the work still going counts no attempt.
- The sweep's free-lease rule for `running` operations skips a `waiting` operation. Only one rule applies to it: the sweep queues it again when its next read is more than twice its delay overdue, so a lost delayed job cannot strand it.
- A delayed read does not count as a waiting job in the sweep's check for stale `queued` operations.

This also amends the cancel rule of [Operations](/reference/operations#cancel-an-operation), which finishes the running step. A direct cancel of a waiting operation stops its step at once, and the operation ends `cancelled`:

- A check stops as `tasks:check:cancel` stops it. When the VM does not answer, the cancel answers `502 tasks.check_unreachable`.
- A turn stops as `tasks:subtask:cancel` interrupts it. When the stop fails, the cancel answers `502 tasks.subtask_interrupt_failed`.

Orbit records the cancel request before it answers `502`. The operation stays `waiting`, and the next read tries the stop again.

### Step 2: Pi on Durable in the task VM

Pi on the Durable harness replaces the session internals of the Pi server inside the task VM. The server's HTTP API stays, so `tasks.turn` does not change. The model key stays in the VM, per group, as today. Its own ADR decides the server change. It changes both modes, because one Pi server serves both.

- The send key stays the submission key. It becomes Durable's request ID, so a repeat still starts no turn.
- A Pi server restart no longer fails the turn: the server resumes it. Orbit removes its restart resume ([Recover a Pi server restart](/reference/tasks#recover-a-pi-server-restart)), which would send a second turn. Durable's crash-loop parking after five restarts fails the turn. The orchestrator raises assistance for an external group, and the tick for a tick group.
- Durable retries a transient provider error, with its policy set to Orbit's budget. Orbit removes its own retry after 1, 2, 4, and 8 minutes ([Retry a transient provider error](/reference/tasks#retry-a-transient-provider-error)).
- Durable deduplicates within the VM's storage only. Across a lost VM, the keyed operation is the guard.
- Checks stay operations. An agent does not reliably rerun a check that was cut off.

### Step 3: retire the tick's engine

Step 3 waits for the amendments that cover topology subtasks and review-and-merge Projects. The Orbit Project uses review and merge, so the proof run uses a Project without it. A proof run comes first: one feature group runs through the orchestrator to a merged pull request, with the Gateway worker and the orchestrator killed along the way. Then Orbit removes `orchestration`, and with it the state machine of `tasks:tick`: claims of `todo` groups, subtask advancement, budgets, reminders, the consult limit, review outcomes, and settling decisions. The tick keeps only [what it keeps](#what-the-tick-keeps) for an external group. The board, task records, assistance, and agent sessions stay.

## Rejected alternatives

- **The orchestrator above whole task groups.** One work item would be one task group, and `tasks:tick` would keep the inner loop. That keeps the hand-rolled engine, so Orbit's scope does not shrink.
- **The orchestrator as a second sender.** It would call Pi or the task drivers directly. That breaks the one-sender rule of [ADR 0224](/decisions/0224-give-each-task-group-one-holder-and-one-sender).
- **Step 1 after ADR 0224.** The first external run would wait for ADR 0223's remaining steps and all of ADR 0224, and much of ADR 0224 reworks the tick engine that step 3 removes.
- **A development Instance as the workspace for steps without an agent.** It is a second path outside the VM. A commit would move between the Instance and the VM, and the step would not run unchanged on UpCloud overflow.
- **A generic command operation.** Feature work needs only the checks and the agent. A generic run would need ADR 0225's agent commands, because check supervision is not a generic command runner.
- **Publish of any commit.** With a raw SHA, a caller would skip the check. Publish takes a passed handoff check, so it pushes only the commit that the check recorded. The orchestrator owns the review gate.
- **A long step as one blocking step.** A 15-minute test suite would hold the single `operations` worker and stall every deploy.
- **Per-step jobs for every operation.** The target would be free between steps. Only a waiting step ends its job, and it keeps the target.
- **Endpoints named after one orchestrator.** They would tie Orbit to one tool.
- **A key scoped to the calling Node, or one that expires.** A moved orchestrator would lose its keys, and a replay after expiry would start the work a second time.

## Consequences

- Orbit's task engine can be deleted after step 3, which removes most of the Tasks extension's state logic.
- Each new operation wraps an effect Tasks already performs, so step 1 adds little effect code.
- The board and agent sessions stay, so a person still sees what is happening.
- Retries become one rule for every operation: the key.
- Until step 3, task work has two modes, as the Principle line states.
- Step 1 does not wait for ADR 0224. ADR 0224 continues for tick groups, Orbit's among them, until step 3. Its "One resume" build step loses the Pi-restart resume and the provider retry when step 2 lands.
- Topology subtasks and review-and-merge Projects, Orbit itself among them, stay on the tick until an amendment covers them.
- An update that an agent turn makes is ordinary feature work, so a dependency-update group runs as an external group. Only a deterministic step without an agent waits for a command operation and ADR 0225's agent commands.
- The orchestrator becomes a dependency for task work. Its availability, licensing, and storage are outside Orbit.

## Affects

- Components: apps/gateway, apps/cli, packages/php-sdk, apps/web, apps/docs
- ADRs: [0209](/decisions/0209-run-long-operations-as-durable-queued-operations), which gains the waiting step and its cancel rule; [0218](/decisions/0218-take-long-commands-off-the-request-path), whose retention spares keyed operations; [0224](/decisions/0224-give-each-task-group-one-holder-and-one-sender), whose one sender per group is the tick for a tick group and the holding operation for an external group. When ADR 0224 is built, a `tasks.*` operation can set the group holder, `TaskTurnDispatcher` gains those operations as callers beside the tick, and the holder dispatches the status job. Its "One resume" step shrinks with step 2
- Detail: [Operations](/reference/operations) for the idempotency key and the waiting status; [Tasks](/reference/tasks) for `orchestration`, the four operations, the commit at handoff, assistance on external groups, and the removals of steps 2 and 3; [Pi server](/reference/pi-server) and `apps/pi-server` for step 2
- Verify: Gateway feature tests for each operation, its idempotency conflict, and each refusal; an Incus E2E that runs an external group from provision to a pull request, kills the Gateway worker during a waiting check, and reads the same result after recovery
