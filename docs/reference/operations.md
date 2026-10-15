---
title: "Operations"
description: "How the Gateway runs a long operation, such as an Instance deploy, as a queued record with named steps, how a client follows it, and how the Gateway recovers one that a lost worker interrupted."
covers:
  - apps/gateway/app/{Domain/Operations/**,Actions/Operations/**,Data/Operations/**,Http/Requests/Operations/**}
  - apps/gateway/app/{Jobs/RunOperation.php,Console/Commands/SweepOperationsCommand.php,Http/Controllers/Api/OperationsController.php,Models/Operation*.php}
  - apps/gateway/database/migrations/*_create_operations_tables.php
  - packages/php-sdk/src/{Requests,Responses}/Operations/**
  - apps/cli/app/{Commands/Operations/**,Support/Operations/**,Commands/Concerns/FollowsOperations.php}
  - apps/web/src/{api/operations.ts,realtime/operation.ts,pages/OperationPanel.tsx}
---

# Operations

A long operation, such as an [Instance deploy](/reference/deployments#deploy-through-the-api), runs as an operation: a record in the Gateway's database that a job on the [Gateway queue](/reference/gateway-queue) works through as named steps. The request that starts it answers `202 Accepted` with the record and returns at once. A client then follows the record until it ends. Nothing a client does, such as closing its connection, stops the operation. Only [cancel](#cancel-an-operation) does.

Instance deploy is an operation. Rollback, Instance create and remove, and Route convergence still run inside their request ([ADR 0209](/decisions/0209-run-long-operations-as-durable-queued-operations)).

## The record

Every operation has the same fields, whatever its kind.

| Field | Contract |
| --- | --- |
| `id` | The operation ID |
| `kind` | What runs, `<family>.<verb>`, such as `instance.deploy` |
| `target_type`, `target_id` | The record the operation changes, such as `instance` and `15` |
| `input` | The validated request body. `instance.deploy` takes `{}` |
| `status` | `queued`, `running`, `succeeded`, `failed`, or `cancelled`. The last three are terminal and never change again |
| `sequence` | A number that grows with every change of the operation or one of its steps |
| `current_step` | The name of the running step, or null |
| `failed_step`, `error_code` | For a failed operation, the step that ended it and its error code. `failed_step` is null when the operation failed outside a step |
| `result` | What the operation produced, or null until it ends |
| `cancel_requested` | Whether someone asked the operation to stop |
| `attempts` | How many times a worker started the operation |
| `triggered_by` | The name of the Node that started it |
| `created_at`, `started_at`, `finished_at` | ISO 8601 times. `started_at` is the first start |
| `poll_after_seconds` | How long a polling client waits before its next read, or null once terminal. [Follow an operation by polling](#follow-an-operation-by-polling) gives the values |

`operation:show` adds `steps`, in order:

| Field | Contract |
| --- | --- |
| `name` | Unique within the operation, such as `source_preparation` or `before_activation.migrate` |
| `position` | The step's place, from 1 |
| `status` | `pending`, `running`, `succeeded`, `failed`, or `cancelled` |
| `resumable` | Whether the step may run again after a lost worker |
| `sequence` | The operation's `sequence` when this step last changed |
| `started_at`, `finished_at` | ISO 8601 times, or null |
| `error_code` | The error of a failed step, or null |
| `output` | The last 8 KiB of the step's standard output and standard error, as UTF-8 text |
| `output_truncated` | Whether earlier output was dropped from `output` |

The Gateway replaces each byte of output that is not valid UTF-8 with the character U+FFFD. An operation fails outside a step when, for example, it cannot take the Instance's lock.

The steps appear when a worker first starts the operation, all `pending`. A queued operation has none. A best-effort step that fails, such as a development deploy step with `required: false`, ends `failed`, and the operation continues. `failed_step` names only the step that ended the operation.

`result` depends on the kind. For `instance.deploy` it is `{ deployment_id, release, selected_release, commit }`, where `deployment_id` is the [deployment history row](/reference/deployments#history) that holds the full log.

## Start an operation

A start request validates the input and the caller's access, creates the record as `queued`, queues its job, and answers `202` with the record:

```json
{
  "data": { "id": 42, "kind": "instance.deploy", "target_type": "instance", "target_id": 15, "status": "queued", "sequence": 1, "poll_after_seconds": 2, "...": "..." },
  "meta": { "request_id": "..." }
}
```

| Code | HTTP | Meaning |
| --- | --- | --- |
| `operation.target_busy` | 409 | Another operation on the same target has not ended. `details.operation_id` names it |
| `operation.queue_unavailable` | 503 | `ORBIT_GATEWAY_QUEUE_CONNECTION` is empty. The Gateway records nothing |

When the Gateway cannot push the job, it ends the record as `failed` with `operation.dispatch_failed` and answers `503` with that code.

## Read an operation

Four requests read and stop operations.

| Request | Result |
| --- | --- |
| `GET /api/v1/operations/{operation}` | The record with every step |
| `GET /api/v1/operations/{operation}?after_sequence=N` | The record with only the steps whose `sequence` is greater than `N` |
| `GET /api/v1/operations` | The 50 newest records, without steps. Filter with `status`, `kind`, `target_type`, and `target_id` |
| `POST /api/v1/operations/{operation}/cancel` | Asks the operation to stop and returns the record |

Each read is one database query and never waits for the operation. A read needs access to the Node that serves the target, such as the Instance's Node. The list returns only operations on Nodes that the caller can access; the Gateway Node and a Node with an access grant to the Gateway see all of them.

The CLI reads with `operation:show`, `operation:list`, and `operation:cancel` ([operation](/cli/operation)). MCP has the tools `operation-show`, `operation-list`, and `operation-cancel` ([MCP: Operations](/reference/mcp#operations)).

### Follow an operation by polling

A client that has no Reverb connection, such as an MCP agent, polls:

1. Start the operation and keep its `id` and `sequence`.
2. Wait `poll_after_seconds`.
3. Read it with `after_sequence` set to the last `sequence` you have. Apply the steps it returns.
4. Repeat until `status` is terminal. Then read `result`, or `failed_step` and `error_code`.

`poll_after_seconds` is 2 while the operation is queued and in the first 10 seconds of its run. It then grows by one second for each 10 seconds of run time, to at most 15.

Every read returns the whole record and the steps that changed. So a client that missed reads, or starts again from 0, still ends with the current state.

### Follow an operation over Reverb

When the [`websocket` role](/reference/events) is active, the Gateway sends `operation.updated` on the private channel `private-operation.{id}` at each change. A client:

1. Subscribes to `private-operation.{id}`. `POST /api/v1/broadcasting/auth` signs it for any caller with Gateway access.
2. Reads the operation over HTTP.
3. Applies each event whose `sequence` is greater than the one it has: it reads the operation again with `after_sequence`.
4. Unsubscribes when `status` is terminal.

An event never carries output. Reverb can lose an event, for example while the socket reconnects, so a client that has no event for a while reads the operation anyway. The web app and the CLI read every 15 seconds while subscribed. [Realtime events: Operation](/reference/events#operation) lists the fields.

## Cancel an operation

`operation:cancel` asks an operation to stop. A queued operation becomes `cancelled` at once. A running operation finishes its running step, starts no further step, and becomes `cancelled`. The steps it did not reach become `cancelled`. A cancel request for a terminal operation changes nothing and returns the record.

A cancelled deploy records its deployment as `failed` with `deployment.cancelled`. Orbit never undoes the steps that ran.

## How an operation runs

The job `RunOperation` runs on the `operations` queue, which has one worker. One operation runs at a time in the fleet: an operation on another target stays `queued` until the running one ends. The job claims the operation with one conditional update from `queued` to `running`, so a second copy of the job does nothing. It records the worker's host, process ID, and process start time.

The operation's kind plans its steps, then runs them in order. The runner skips a step that already succeeded, so a resumed operation continues where it stopped. It checks for a cancel request before each step. While a step runs, the runner writes its output tail at most once a second. A step that throws ends `failed`, and the operation ends `failed` with the kind's error code.

An operation runs at most 1,600 seconds. Its job may run 1,700 seconds, under the queue's `retry_after` of 1,800, so Redis never gives a running job to a second worker. When the deadline stops a deploy, its error code is `deployment.deadline_exceeded`.

### One operation per target

An operation holds its target from the start request to its terminal status. A second start for that target answers `409` with `operation.target_busy`. The hold lasts across a lost worker and a resume. It does not cover work that is not an operation: while it runs, a deploy also takes the [Instance's operation lock](/reference/deployments#one-operation-at-a-time), as before.

### Recover a lost worker

`orbit:operations-sweep` runs every minute as a [scheduled job](/reference/gateway-queue#scheduled-work). It queues the job again for:

- each `running` operation whose worker process has exited,
- each `running` operation whose process ID now belongs to a newer process, and
- each `queued` operation whose job was queued more than 10 minutes ago and has not started.

The next worker then looks at the step that was running:

| The interrupted step | What happens |
| --- | --- |
| Is resumable | It runs again, and the operation continues |
| Is not resumable | It ends `failed` with `operation.worker_lost`, and the operation fails at that step |
| Belongs to an operation that started 3 times already | The same as not resumable |

The kind finishes its domain record in both failure cases. A lost deploy ends its deployment row as `failed` with the boundary of that step.

A resumed operation plans its steps again. When the plan differs from the first attempt, for example because someone added a deploy step in between, it fails with `operation.plan_changed`.

The sweeper checks processes on the Gateway's own host. A worker that ran on another host counts as lost.

## Errors

These codes belong to every operation.

| Code | Meaning |
| --- | --- |
| `operation.target_busy` | Another operation holds the target |
| `operation.queue_unavailable` | The Gateway has no queue |
| `operation.dispatch_failed` | The Gateway could not queue the job |
| `operation.worker_lost` | A worker stopped during a step that cannot run again |
| `operation.plan_changed` | A resumed operation planned other steps than its first attempt |
| `operation.target_missing` | The target was removed before the operation started |
| `operation.failed` | The kind ended without an error code of its own |

A kind adds its own codes. `instance.deploy` uses the [deployment codes](/reference/deployments#failures), such as `deployment.step_failed`.

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### The database is the truth

An operation outlives every client and every worker, so its state lives in the database. Events and poll replies only tell a client what changed since its last read. A lost event, a closed socket, or a lost MCP context costs one more read, never a wrong state.

### One job, many steps

One job holds the Instance's operation lock for the whole run, so no environment change or Route change slips in between two steps. Each step as its own job would remove the 1,600-second bound but leave the lock free between jobs. Every deploy that ran in a request before, at most 570 seconds, fits in one job.

### Resume only what can run again

Orbit can prepare a release, sync the environment, switch `current`, refresh PHP, and prune again without harm. It cannot know whether a deploy step that migrates a database ran. So the runner repeats only steps that declare themselves resumable and fails the others at a named step. A guess in either direction would hide a broken deploy.

### A process check instead of a heartbeat

A step that waits on a silent remote command writes nothing for minutes. A heartbeat would need a timer inside the worker. Horizon kills a worker that passes its timeout, and a fatal error ends it, so the worker's process ID and start time tell the sweeper what it needs.

### One worker for every operation

The lock per target already keeps two operations off one Instance, and a single operator rarely deploys two Instances at once. Each Horizon worker is a PHP process of about 40 to 50 MB, and the Gateway once ran out of memory with about 18 PHP CLI processes beside its PHP-FPM workers. So the queue has one worker, and a second deploy waits in the queue instead of costing memory while idle.

### Operations need the queue

Running an operation without the queue, for example after the response inside PHP-FPM, keeps the 600-second limit and adds a second path that recovery must cover. Task VMs follow the same rule.

### Names that map onto MCP Tasks

MCP has an experimental Tasks primitive for long tool calls. An operation already has its parts: the ID, the status, the poll interval, and the get, list, and cancel requests. When `laravel/mcp` supports Tasks, `instance-deploy` can return a task without a new record.
