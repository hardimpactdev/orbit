---
title: "ADR 0244: Track every action as one task"
description: "Every action the Gateway performs is one task record with a driver. Schedules, Operations and task groups stop being separate tracking systems."
---

# ADR 0244: Track every action as one task

Draft for Nick's review, 2026-11-01. Every action the Gateway performs is a **task**: one record with one status machine, one history, one log read, one cancel and one budget. A task has a **driver** that says what does the work: a `command`, a Swamp `workflow`, or a `pi` agent turn. All three drivers execute as Swamp runs. A recurring job is a **Schedule** that starts tasks from a template. Operations become tasks, the Schedule run path moves onto native Swamp schedules, and the task board keeps only board state.

## Status

In progress.

Principle: [One way, one name](/mission#principles). One tracked action has one record and one term in the API, CLI, MCP, database, web app and documentation. [No exceptions and no legacy](/mission#principles) applies to the renames: old names get no alias.

## Context

Orbit tracks work in three places today. Each has its own status, history, logs and cancel.

| | Schedules | Operations | Tasks extension |
| --- | --- | --- | --- |
| Record | `schedules` row (UUID), Project `schedule_definitions` | `operations` row, keyed by `(kind, idempotency_key)` | `tasks` row: group or subtask; `agent_threads`, `task_vms`, `task_checks`, comments, questions |
| Trigger | systemd timer on the host Node; its oneshot unit curls `POST /schedules/{id}/run` | API admission | Swamp `groups-driver` tick (every minute) and the software factory |
| Execution | `schedule.run` Operation → `operation-schedule-run` over `@swamp/ssh` | `StartSwampRun` → one native workflow per kind | Each stage starts one keyed Operation (`tasks.provision`, `check`, `turn`, `publish`, …); Pi runs the turn in the VM |
| Status | `provisioning/active/failed/removing` plus `last_run_status` | `queued/running/waiting/succeeded/failed/cancelled/interrupted` | group: ten board states; subtask: seven; thread: five; VM: six; check: six |
| History | latest run only | one row per run; steps live in Swamp native history (30 days) | Operations, comments, receipts, threads, and Swamp history |
| Logs | journal through the frozen orbit-agent (`schedule.logs`) | 4 KiB error tail; full logs only in Swamp | transcripts (`tasks:agent:show`), `tasks:swamp-runs` reads `swamp.orbit` directly |
| Cancel | generic `operation-cancel` | native Swamp cancel of the bound run | `tasks:cancel` (itself an Operation), `tasks:subtask:cancel`, and `operation-cancel` |
| Retries | none; a failed curl loses the run | Swamp owns them; the Gateway never re-runs | new cycle key; Durable retries inside a turn |
| Budgets | `timeout_seconds` | per-kind or history-based run budget; expiry fails the record | factory attempt caps; `maxRunSeconds: 7200`; the stuck-VM rule exists only in memory |

Twelve native Swamp schedules (`groups-driver`, `fleet-maintenance`, `automatic-production-deploy`, `observation-collection`, …) run outside any Orbit record. Their results reach the Gateway only through per-family `scheduled-results.php` maps. Task groups also have a second driver inside the Gateway, `TaskMaintenanceContinuation`, which breaks the rule that the Gateway records intent and Swamp executes.

Nick decided on 2026-11-01 that there is one system: Swamp is a first-class part of Orbit, and recurring jobs are Orbit records whose execution is a Swamp workflow.

## Decision

Orbit has one tracked record, the task. Swamp executes every task; the Gateway admits it, binds it to its Swamp run, and projects its result.

### The task

A task is one tracked action. The `operations` table becomes `tasks`, and the record gains a driver, a parent and a schedule.

| Field | Meaning |
| --- | --- |
| `id` | Numeric identity, as today. |
| `kind` | `<family>.<verb>`, declared in the family's `direct-operations.php` registry. |
| `driver` | `command`, `workflow` or `pi`. Fixed by the kind. |
| `target_type`, `target_id`, `node_id` | What the task acts on, as today. |
| `parent_id` | The task that started it, or null. A task group's stage actions are children of the group's root task. |
| `schedule_id` | The Schedule that fired it, or null. |
| `trigger` | `operator`, `automatic`, `schedule` or `parent`. Replaces `triggered_by`. |
| `idempotency_key`, `lock_key` | Unchanged. The generic `lock_key` hold stays the one serialization. |
| `input`, `result`, `error_code`, `error_detail` | Unchanged. `error_detail` replaces `state.native_failure.detail`. |
| `swamp_run_id` | Promoted from `state` to a column. Every task is bound to one Swamp root run. |
| `budget`, `usage` | See Budgets. |
| `waiting_reason` | `capacity`, `human` or `signal` while `waiting`; null otherwise. |

The leftover Kit columns `attempts`, `worker` and `sequence` are dropped.

### Status machine

```text
queued ─▶ running ◀─▶ waiting
   │         │           │
   └────▶ succeeded | failed | cancelled
```

- Only the Gateway writes status: admission, start, terminal projection, cancel and budget expiry.
- `interrupted` is removed. A native interrupted run projects as `failed` (boundary decision 7 of 2026-11-01).
- Recovery is a new task, never a reopened one.
- A task group's board state (`backlog`, `todo`, `reviewing`, `settling`, …) is board state, not task status. The board shows the status of the group's tasks next to it.

### History and logs

- The task row is the history entry. The parent and schedule links give the tree and the run history of a Schedule.
- Step detail stays in Swamp native run history and follows its 30-day retention. Orbit copies no step log.
- `task:logs <id>` is the one log read. The driver picks the reader: Swamp `workflow.history.logs` for `command` and `workflow`, and the Pi transcript for `pi`.
- The Activity log stays the audit of HTTP commands. It links to tasks through `request_id` and is not a second task history.

### Cancel and retries

- `task:cancel <id>` is the one cancel. It records `cancel_requested_at` and calls native Swamp cancel on `swamp_run_id`. Swamp cancels descendant runs. The terminal status still comes from the native run.
- Ending a task group with cleanup (push approved work, remove the workspace) is its own action kind, not a cancel.
- Swamp owns retries. The Gateway never re-runs a task. A retry is a new task with a new key.

### Budgets

`budget` holds the limits that apply to the driver. `usage` holds what the task used.

| Budget | Drivers | Expiry |
| --- | --- | --- |
| `timeout_seconds` | all | Today's run budget: per kind, per Schedule, or 1.5 × the longest success in 14 days. |
| `idle_seconds` | `workflow`, `pi` | No step or transcript progress for this long. |
| `waiting_seconds` | all | Time in `waiting` with reason `human`. |
| `tokens` | `pi` | Token cap for the turn. |

Expiry marks the task `failed` with `task.budget_exceeded` and the budget name, and calls native cancel. The `lock_key` stays held until Swamp confirms the run ended, as today. The stuck task VM rule (no progress for 60 minutes, assistance unanswered for 2 hours) becomes the default `idle_seconds` and `waiting_seconds` of a task group's root task.

### The driver interface

A driver is declarative metadata in the existing kind registry. It adds no Gateway execution machinery.

```php
'schedule.run' => [
    'driver' => 'command',           // command | workflow | pi
    'workflow' => 'task-command-run', // the Swamp workflow that executes it
    'projection' => CommandRunProjection::class,
    'terminal_result' => ['job' => 'run', 'step' => 'result'],
    'budget' => ['timeout_seconds' => 3600],
],
```

Each driver supplies four things:

1. **Inputs**: the full non-secret intent as workflow inputs, secrets as vault key names (the Gateway-to-Swamp run contract).
2. **Projection**: turns the one terminal history read into `result`, `usage` and status.
3. **Log reader**: the reader `task:logs` uses.
4. **Budgets**: the budget fields that apply.

Start, attach, terminal projection, lost-result recovery and cancel stay shared. They are the code that runs Operations today.

### The three drivers

| Driver | What it is | Workflow | Logs | Notes |
| --- | --- | --- | --- | --- |
| `command` | One command on a Node or Instance, as the right account, with a timeout | `task-command-run` (today's `operation-schedule-run`) | command stdout and stderr in the Swamp step | Replaces the journal and the agent `schedule.logs` path. |
| `workflow` | A native Swamp workflow of placed Orbit steps | the kind's own workflow, as today | Swamp step logs | Every Operation kind today except `tasks.turn`. |
| `pi` | One Pi agent turn in a task VM | `operation-task-turn` over `@swamp/ssh` | Pi transcript, by cursor | `usage` holds tokens and cost. Its workflow interrupts Pi when the run is cancelled. |

A task group maps onto the tree. The group keeps its board record: plan, subtasks, deliverables, workspace, PR and comments. Its execution is one root task with driver `workflow`, the group's software factory run. Each stage action is a child task: `tasks.provision`, `check` and `publish` use `workflow`, and `tasks.turn` uses `pi`. The task VM is the group's workspace, not a driver. `TaskMaintenanceContinuation` moves into the group's Swamp run.

### Schedules

A Schedule is a cadence plus a task template: `name`, `target`, `calendar`, `kind`, `input`, `budget` and `enabled`. It holds no run state. Its last run is its newest task.

- The trigger is a native Swamp schedule. The Gateway publishes the full template as inputs with `workflow.trigger.set` (`SwampSchedulePublisher`) when the Schedule is created, changed, enabled or disabled.
- Each fire is a Swamp root run. The Gateway's shared run reader records a task for it when it sees the run start (`trigger: schedule`, `schedule_id`, `swamp_run_id`, idempotency key `schedule:<id>:<run id>`). It projects the result like any other task. Steps never call the Gateway.
- `schedule:run` admits a task from the template through normal admission.
- A user Schedule uses the `command` driver. A Swamp upkeep job (`fleet-maintenance`, `observation-collection`, `automatic-production-deploy`, …) is a Schedule whose kind uses the `workflow` driver. Every native Swamp schedule is then an Orbit Schedule, and none exists outside Orbit.

### What is removed or merged

- The Operation API, CLI, MCP and web panel become `task:*` and `task-*`. No alias remains.
- `operation_*` direct endpoints become `task_*` direct endpoints.
- `tasks:swamp-runs` and `WebSocketSwampRunReader` are replaced by the group's task tree and `task:logs`.
- `tasks:cancel` and `tasks:subtask:cancel` are replaced by `task:cancel` on the running task, plus the group-ending action kind.
- The Schedule path loses its systemd units (`orbit-schedule-*.service` and `.timer`), the curl `/run` call, the unused `/complete` callback, `last_run_at` and `last_run_status`, `RemoteScheduleRuntimeManager`, the agent `schedule.logs` command, and the uncalled `CascadeInstanceSchedulesAction`.
- Per-family `scheduled-results.php` maps fold into the one Schedule-to-task projection.
- `TaskMaintenanceContinuation` leaves the Gateway.
- The Tasks extension's group and subtask stop using the word "task". Proposal: group becomes **work item**, the term the software factory already uses, and subtask becomes **work step**.

### Migration

Each step is one Orbit PR with its Gateway, Swamp, CLI, web, SDK and documentation changes. Each step leaves one working path.

1. **Free the name.** Rename the Tasks extension's group and subtask (table `tasks` becomes `work_items`, `tasks:*` becomes `work:*`). No behaviour changes.
2. **Operations become tasks.** Rename the table, model, API, CLI, MCP and web panel. Add `driver`, `parent_id`, `schedule_id` and the `swamp_run_id` column; backfill `driver` from the kind and `parent_id` from group targets. Drop `interrupted` and the Kit columns. Rewrite the stale `operations.md` as the task reference.
3. **One log read.** Add `task:logs` with the Swamp and Pi readers. Delete `tasks:swamp-runs`.
4. **Budgets.** Add `idle_seconds`, `waiting_seconds` and `tokens` to the shared expiry, with the stuck-VM defaults on group root tasks.
5. **Schedules on native triggers.** Add the template fields and publish each Schedule as a native trigger on `task-command-run`. Record fires as tasks. A one-time workflow removes the systemd units from each host. Delete the timer, curl, callback and agent log paths.
6. **Upkeep jobs become Schedules.** Register each native Swamp schedule as an Orbit Schedule with a `workflow` kind, published by the Gateway. Fold `scheduled-results.php`.
7. **Groups as task trees.** The factory run becomes the group's root task, and stage actions become its children. Move `TaskMaintenanceContinuation` into Swamp. Replace the group cancel paths.

### Open questions for Nick

1. **Trigger.** Are native Swamp schedules the trigger for user Schedules too, replacing the systemd timers? Recommended: yes.
2. **Names.** Is "work item" and "work step" right for today's task group and subtask? Or does the group keep the name "task", and the tracked action get another name?
3. **Tick volume.** Does each fire of a per-minute tick keep 30-day retention, or does Orbit prune no-op fires after one day?
4. **Gateway internals.** Do jobs that the Gateway's own Laravel scheduler runs (for example `ObserveParkedTaskVms`) become tasks as well? Recommended: no. They are Gateway housekeeping, not fleet actions.
5. **Budget defaults.** Which default token cap does a `pi` turn get, and are 60 minutes idle and 2 hours waiting the right group defaults?

## Rejected alternatives

- **A view that joins the three tables.** It hides three status machines behind one page and keeps three cancels and three log paths.
- **Keep systemd timers as the trigger.** They need a unit per Schedule on each host and a curl back to the Gateway, and a lost curl loses the run. Native Swamp schedules already run Orbit's upkeep jobs.
- **Track only fires that do work.** Deciding which fires matter needs per-family rules. Every fire is a task, and lists filter by Schedule.
- **Make the task VM a driver.** A VM is a workspace that lives across many actions. The Pi turn is the action.

## Consequences

- One list answers "what is Orbit doing and what did it do", for every driver.
- Per-minute driver ticks create about 1,440 tasks a day each. Lists filter by Schedule and retention prunes them. A separate decision can replace the ticks with event-driven starts.
- A Schedule fire no longer depends on the Gateway being up, but its task is recorded only when the Gateway sees the run. Lost-start recovery uses the existing one-shot `workflow.run.search`.
- The renames touch every client in steps 1 and 2.

## Affects

- Components: apps/gateway, apps/cli, apps/web, packages/php-sdk
- ADRs: [0209](/decisions/0209-run-long-operations-as-durable-queued-operations), [0218](/decisions/0218-take-long-commands-off-the-request-path), [0229](/decisions/0229-let-an-orchestrator-drive-task-work-through-gateway-operations), [0238](/decisions/0238-trial-two-swamp-slices-before-a-wider-move)
- Detail: [Operations](/reference/operations) becomes the task reference; [Schedules](/reference/schedules); [Tasks](/reference/tasks) becomes the work item reference
- Verify: Swamp workflows under `swamp/` change in the same PRs. Gateway task admission, projection, cancel and budget expiry tests per driver; a Schedule-fire projection test; the Swamp workflow grants check; a proof receipt for the `command` and `pi` drivers
