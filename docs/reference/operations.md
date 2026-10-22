---
title: "Operations"
description: "How the Gateway runs a long operation as a queued record with named steps, how a client follows it, and how the Gateway recovers one that a lost worker interrupted."
covers:
  - apps/gateway/app/{Domain/Operations/**,Actions/Operations/**,Data/Operations/**,Http/Requests/Operations/**}
  - apps/gateway/app/{Jobs/RunOperation.php,Console/Commands/SweepOperationsCommand.php,Http/Controllers/Api/OperationsController.php,Models/Operation*.php}
  - apps/gateway/database/migrations/2026_12_03_000000_allow_cold_recipe_image_rebuilds.php
  - apps/gateway/database/migrations/2026_12_28_000000_add_operation_idempotency_keys.php
  - packages/php-sdk/src/{Requests,Responses}/Operations/**
  - apps/cli/app/{Commands/Operations/**,Support/Operations/**,Commands/Concerns/FollowsOperations.php}
  - apps/web/src/{api/operations.ts,realtime/operation.ts,pages/OperationPanel.tsx}
---

# Operations

A long operation, such as an [Instance deploy or rollback](/reference/deployments#deploy-through-the-api), runs as an operation: a record in the Gateway's database that a job on the [Gateway queue](/reference/gateway-queue) works through as named steps. The request that starts it answers `202 Accepted` with the record and returns at once. A client then follows the record until it ends. Nothing a client does, such as closing its connection, stops the operation. Only [cancel](#cancel-an-operation) does.

Instance deploy, rollback, [task provision](/reference/tasks#provision-an-external-group), [task checks](/reference/tasks#check-an-external-group), and [task turns](/reference/tasks#run-an-external-turn) are operations. Instance create and remove, and Route convergence still run inside their request ([ADR 0209](/decisions/0209-run-long-operations-as-durable-queued-operations)). Other long commands, such as `node:add` and `instance:transfer`, also still run inside their request. [ADR 0218](/decisions/0218-take-long-commands-off-the-request-path) plans to move them off the request path and to lower the request limit.

Production deployments include a resumable `process_restart` step after PHP refresh. It restarts active Instance Processes before after-activation commands. A completed restart step is skipped on recovery. An interrupted restart step can restart a Process again. A stored deploy plan from before this step was introduced keeps its original steps after a Gateway upgrade. See [Deploy](/reference/deployments#deploy) for the opt-out and failure behavior.

## The record

Every operation has the same fields, whatever its kind.

| Field | Contract |
| --- | --- |
| `id` | The operation ID |
| `kind` | What runs, `<family>.<verb>`, such as `instance.deploy` |
| `target_type`, `target_id` | The record the operation changes, such as `instance` and `15` |
| `input` | The validated request body. `instance.deploy` takes `{}`, and `instance.rollback` takes `{ release }` |
| `status` | `queued`, `running`, `waiting`, `succeeded`, `failed`, or `cancelled`. The last three are terminal and never change again |
| `sequence` | A number that grows with every change of the operation or one of its steps |
| `current_step` | The name of the running or waiting step, or null |
| `failed_step`, `error_code` | For a failed operation, the step that ended it and its error code. `failed_step` is null when the operation failed outside a step |
| `result` | What the operation produced, or null until it ends |
| `cancel_requested` | Whether someone asked the operation to stop |
| `attempts` | How many times a worker started the operation |
| `triggered_by` | The name of the Node that started it |
| `created_at`, `started_at`, `finished_at` | ISO 8601 times. `started_at` is the first start |
| `poll_after_seconds` | How long a polling client waits before its next read, or null once terminal. [Follow an operation by polling](#follow-an-operation-by-polling) gives the values |

A cancelled operation has an `error_code` only when a rollback superseded it: `operation.superseded`.

`operation:show` adds `steps`, in order:

| Field | Contract |
| --- | --- |
| `name` | Unique within the operation, such as `source_preparation` or `before_activation.migrate` |
| `position` | The step's place, from 1 |
| `status` | `pending`, `running`, `waiting`, `succeeded`, `failed`, or `cancelled` |
| `resumable` | Whether the step may run again after a lost worker |
| `sequence` | The operation's `sequence` when this step last changed |
| `started_at`, `finished_at` | ISO 8601 times, or null |
| `error_code` | The error of a failed step, or null |
| `output` | The last 8 KiB of the step's standard output and standard error, as UTF-8 text |
| `output_truncated` | Whether earlier output was dropped from `output` |

The Gateway replaces each byte of output that is not valid UTF-8 with the character U+FFFD. An operation fails outside a step when, for example, it cannot take the Instance's lock.

The steps appear when a worker first starts the operation, all `pending`. A queued operation has none. A best-effort step that fails, such as a development deploy step with `required: false`, ends `failed`, and the operation continues. `failed_step` names only the step that ended the operation.

`result` depends on the kind. For `instance.deploy` and `instance.rollback` it is `{ deployment_id, release, selected_release, commit }`, where `deployment_id` is the [deployment history row](/reference/deployments#history) that holds the full log.

### Kinds and targets

Use the kind and target type to start or find the work of a command.

| CLI command | Kind | Target type | Serving Node |
| --- | --- | --- | --- |
| `instance:deploy` | `instance.deploy` | `instance` | Instance Node |
| `instance:rollback` | `instance.rollback` | `instance` | Instance Node |
| `tasks:provision` | `tasks.provision` | `task_group` | Gateway |
| `tasks:check` | `tasks.check` | `task_group` | Gateway |
| `tasks:turn` | `tasks.turn` | `task_group` | Gateway |

Every task operation holds the group, so different kinds cannot overlap on it. A task VM Node exists only while the group runs, so the Gateway serves the group's operations and their reads. Provision returns `{ instance_id, node_id, starting_commit }` on success. A turn returns its receipt, thread state, workspace evidence, and token metrics; [Run an external turn](/reference/tasks#run-an-external-turn) defines the result and refusals.

## Start an operation

After the access and idempotency checks, a start request validates the input, creates the record as `queued`, queues its job, and answers `202` with the record:

```json
{
  "data": { "id": 42, "kind": "instance.deploy", "target_type": "instance", "target_id": 15, "status": "queued", "sequence": 1, "poll_after_seconds": 2, "...": "..." },
  "meta": { "request_id": "..." }
}
```

| Code | HTTP | Meaning |
| --- | --- | --- |
| `operation.idempotency_conflict` | 409 | This kind already has the key with another target or input. Nothing starts |
| `validation.failed` | 422 | The `Idempotency-Key` header is malformed, or the input is invalid |
| `operation.target_busy` | 409 | Another operation on the same target has not ended. `details.operation_id` names it |
| `operation.queue_unavailable` | 503 | `ORBIT_GATEWAY_QUEUE_CONNECTION` is empty. The Gateway records nothing |

When the Gateway cannot push the job, it ends the record as `failed` with `operation.dispatch_failed` and answers `503` with that code. A deploy that a rollback superseded stays cancelled.

### Retry a start with a key

Every route that starts an operation accepts an optional `Idempotency-Key` header. Today these are `POST /api/v1/instances/{instance}/deploy` (`instance.deploy`), `POST /api/v1/instances/{instance}/rollback` (`instance.rollback`), `POST /api/v1/task-groups/{group}/provision` (`tasks.provision`), `POST /api/v1/task-groups/{group}/check` (`tasks.check`), and `POST /api/v1/task-groups/{group}/turn` (`tasks.turn`). A key has 1 to 128 characters from `A-Z a-z 0-9 . _ : -`. Keys are case-sensitive and scoped to the operation kind, across all calling Nodes.

After checking the caller's access, the Gateway checks the key before further input or domain validation and before creating a target. The same key, target, and input return `202` with the existing operation, even when it has finished or failed to dispatch. A different target or input returns `409 operation.idempotency_conflict` and starts nothing. JSON object member order and whitespace do not change the input; array order and value types do. A malformed key returns `422 validation.failed` in the normal validation envelope, with `Idempotency-Key` in `error.details`.

Concurrent starts with one new key create one operation and queue one job. The operation stores the key, and a unique index on kind and key settles competing inserts. A request without a key starts a new operation under the usual target-hold rule.

The CLI commands `instance:deploy`, `instance:rollback`, `tasks:provision`, `tasks:check`, and `tasks:turn` pass the header with `--idempotency-key=KEY`. The PHP SDK requests accept `idempotencyKey`. Each MCP tool that starts an operation takes the optional `Idempotency-Key` argument, generated from the OpenAPI header.

Keyed operations are never pruned, so their keys never expire. Operations currently keep every record. When ADR 0218 adds retention for finished operations, it must exclude every operation that carries a key. The [WebSocket app routes](/reference/websocket-apps) keep their own Node-scoped idempotency rule until their changes become operations.

## Read an operation

Four requests read and stop operations.

| Request | Result |
| --- | --- |
| `GET /api/v1/operations/{operation}` | The record with every step |
| `GET /api/v1/operations/{operation}?after_sequence=N` | The record with only the steps whose `sequence` is greater than `N` |
| `GET /api/v1/operations` | The 50 newest records, without steps. [Filter the list](#filter-the-list) with `status`, `kind`, `target_type`, and `target_id` |
| `POST /api/v1/operations/{operation}/cancel` | Asks the operation to stop and returns the record |

Each read is one database query and never waits for the operation. A read needs access to the Node that serves the target, such as the Instance's Node. The list returns only operations on Nodes that the caller can access; the Gateway Node and a Node with an access grant to the Gateway see all of them.

The CLI reads with `operation:show`, `operation:list`, and `operation:cancel` ([operation](/cli/operation)). MCP has the tools `operation-show`, `operation-list`, and `operation-cancel` ([MCP: Operations](/reference/mcp#operations)).

### Filter the list

`GET /api/v1/operations` takes four optional query filters. A record must match each filter that the request sends.

| Filter | Contract |
| --- | --- |
| `status` | `queued`, `running`, `waiting`, `succeeded`, `failed`, or `cancelled` |
| `kind` | Two names joined by `.`, `<family>.<verb>`, such as `instance.deploy`. At most 64 characters |
| `target_type` | One name, such as `instance`. At most 32 characters |
| `target_id` | A positive integer |

Each name starts with a lower-case letter and holds only lower-case letters, digits, and `_`.

The Gateway checks the form of `kind` and `target_type`, not whether a kind exists. A well-formed value that no operation has returns an empty list. A value in another form answers `422` with `validation.failed`.

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

`operation:cancel` asks an operation to stop. A queued operation becomes `cancelled` at once. A waiting operation calls its current step’s stop hook at once and becomes `cancelled` only after the hook confirms the stop. Orbit records the cancel request before calling the hook. When the hook cannot confirm the stop, the request answers with that step’s error, such as HTTP 502. The operation and step stay `waiting`, keep the target, and retry the stop on the next delayed read.

A running operation finishes its running step, starts no further step, and becomes `cancelled`. The steps it did not reach become `cancelled`. A cancel request for a terminal operation changes nothing and returns the record.

A cancelled deploy or rollback records its history row as `failed` with `deployment.cancelled`. Orbit never undoes the steps that ran.

A deploy or rollback ignores a cancel request once its `activation` step succeeded: the selected release is live, so a deploy still refreshes PHP, restarts Processes, runs the `after_activation` steps, and cleans up, and a rollback still refreshes PHP and restarts Processes. It then ends `succeeded`, or `failed` when a later step fails, and `cancel_requested` stays true.

## How an operation runs

The job `RunOperation` runs on the `operations` queue, which has one worker. One operation job runs at a time in the fleet: an operation on another target stays `queued` until the running one ends. The job claims the operation with one conditional update from `queued` to `running`, so a second copy of the job does nothing. It records the worker's host and process ID, for people. While the attempt runs, it holds the operation's attempt lease, an exclusive lock on a file under `ORBIT_HOME/locks/operations`. The lease ends with the attempt's code, also when an error escapes it, and with the worker process.

An error that escapes the attempt fails the operation with `operation.failed`. When the Gateway cannot even record that, for example while its database is locked, the operation stays `running` with a free lease until the sweep recovers it.

The operation's kind plans its steps, then runs them in order. The runner skips a step that already succeeded, so a resumed operation continues where it stopped. It checks for a cancel request before each step. While a step runs, the runner writes its output tail at most once a second. A step that throws ends `failed`, and the operation ends `failed` with the kind's error code.

Each active run has a deadline of 1,600 seconds; time between waiting reads does not count toward it, and its last 20 seconds are a cleanup reserve. A step that runs out of forward time stops there, and the recovery after it uses the reserve, such as reading which release an interrupted activation selected. Its job may run 1,700 seconds, under the queue's `retry_after` of 1,800, so Redis never gives a running job to a second worker. When the deadline stops a deploy or rollback, its error code is `deployment.deadline_exceeded`.

### A waiting step

A step can ask the runner to wait with a positive delay in seconds. The operation and its current step become `waiting`. The job ends and releases its attempt lease, while the operation keeps its target. Another start on that target still answers `409 operation.target_busy`. `tasks.provision` waits for VM capacity and readiness, `tasks.check` waits for VM resume, workspace ownership, and its detached check, and `tasks.turn` waits for a parked VM, a workspace lease, and the Pi turn to end; deploy and rollback keep their current execution.

A delayed job first claims the operation with one conditional update from `waiting` to `running`, then calls the current step’s read hook. It does not start that work again. The read either finishes the step and continues the operation, or asks to wait again. Duplicate delayed jobs cannot read the step together, and an early job cannot read before the next read is due. A read that finds work still running consumes no recovery attempt. A wait has no time limit of its own. The orchestrator owns time budgets and cancels work that takes too long.

The kind supplies the current step’s read and stop hooks. A read returns the step’s result or asks to wait again; a stop returns only when it confirms the work stopped, otherwise it throws the step’s error. Both run under the operation’s attempt lease. The runner stores step results through the same path as a synchronous step and then continues the kind, skipping succeeded steps.

### One operation per target

An operation holds its target from the start request to its terminal status. A second start for that target answers `409` with `operation.target_busy`. The hold lasts across a lost worker and a resume. A rollback is the one exception: it supersedes a deploy of the Instance that no worker has started, as [One operation at a time](/reference/deployments#one-operation-at-a-time) describes. The hold does not cover work that is not an operation: while it runs, a deploy or rollback also takes the [Instance's operation lock](/reference/deployments#one-operation-at-a-time), as before.

### Recover a lost worker

`orbit:operations-sweep` runs every minute as a [scheduled job](/reference/gateway-queue#scheduled-work). It queues the job again for:

- each `running` operation whose attempt lease is free, because its worker died or its attempt ended without recording the outcome, and
- each `queued` operation whose job was queued more than 10 minutes ago and has not started, and
- each `waiting` operation whose next read is more than twice its delay overdue. This is the only sweep rule for a waiting operation; a free attempt lease does not make it a lost running attempt.

The sweep queues a stale `queued` operation again only while the `operations` queue holds no pending start or delayed start job and the operation's attempt lease is free. It refreshes the operation's status and dispatch time under that lease, then releases the lease before publishing the replacement. An idle worker can then run the replacement as soon as it appears. With one worker, an operation that waits behind a long one still has a waiting job, so the sweep adds no second one.

Delayed waiting reads do not count as waiting jobs for queued recovery. Redis reservations do not count as waiting jobs. A reservation proves that a process popped the job, not that its handler runs. In particular, a Horizon metadata error can discard a popped job while the worker stays alive. Such a reservation must not block recovery until the 1,800-second reservation expiry. The sweep can recover a queued operation after 10 minutes, at the next scheduled sweep, while its old reservation still exists. If the old copy later reaches the handler, the attempt lease and conditional claim prevent a second execution.

The next worker then looks at the step that was running:

| The interrupted step | What happens |
| --- | --- |
| Is resumable | It runs again, and the operation continues |
| Is not resumable | It ends `failed` with `operation.worker_lost`, and the operation fails at that step |
| Belongs to an operation that started 3 times already | The same as not resumable |
| Already ended `failed` | It never runs again. The operation fails at that step with `operation.worker_lost` |

The kind finishes its domain record in both failure cases. A lost deploy or rollback ends its history row as `failed` with the boundary of that step.

A lost worker during a waiting read reads the same work again, even when starting that step is not resumable. It never restarts that work. An operation still fails after three lost workers.

A resumed operation plans its steps again. When the plan differs from the first attempt, for example because someone added a deploy step in between, it fails with `operation.plan_changed`.

The attempt lease is local to the Gateway host. All operation workers and the sweeper run on that host; worker process IDs are diagnostic information, not a liveness test.

## Errors

These codes belong to every operation.

| Code | Meaning |
| --- | --- |
| `operation.idempotency_conflict` | The kind already has this key with another target or input; HTTP 409 |
| `validation.failed` | A malformed key or invalid input; HTTP 422 |
| `operation.target_busy` | Another operation holds the target |
| `operation.queue_unavailable` | The Gateway has no queue |
| `operation.dispatch_failed` | The Gateway could not queue the job |
| `operation.worker_lost` | A worker stopped during a step that cannot run again |
| `operation.plan_changed` | A resumed operation planned other steps than its first attempt |
| `operation.target_missing` | The target was removed before the operation started |
| `operation.superseded` | A rollback of the Instance cancelled this deploy before a worker started it. The status is `cancelled` |
| `operation.failed` | The kind ended without an error code of its own |

A kind adds its own codes. `instance.deploy` and `instance.rollback` use the [deployment codes](/reference/deployments#failures), such as `deployment.step_failed`.

## Why it works this way

### A task check returns evidence

`tasks.check` holds the group while its detached check runs, without holding the operations worker. A failed check returns a successful operation with a failed outcome so the orchestrator can decide its next step. An unconfirmed stop retains the target and retries, because a cancel request alone does not prove the workspace is free. Group cancellation can end the operation when the VM is unreachable because the group's existing removal rule retains the workspace and requests assistance.

These reasons explain the design. Check them before you propose a change.

### Task operations hold the group and use the Gateway

Provision creates the VM Node, and later cleanup removes it. Serving the operations from the Gateway keeps their access and history independent of that Node's lifetime. Holding the group gives every task effect one shared exclusion rule, including effects on different subtasks.

### The database is the truth

An operation outlives every client and every worker, so its state lives in the database. Events and poll replies only tell a client what changed since its last read. A lost event, a closed socket, or a lost MCP context costs one more read, never a wrong state.

### A key survives the caller and the operation

An orchestrator can lose a response or move to another Node. A key scoped to the kind lets either caller find the same work. The early check lets a replay succeed even when the target's state fails start validation, and prevents a retry from creating another target. A database constraint settles concurrent inserts without a process-local lock. Keeping keyed operations forever prevents a late retry from starting the work again after retention would otherwise remove its key.

### One job, many steps

One job holds the Instance's operation lock for the whole run, so no environment change or Route change slips in between two steps. Each step as its own job would remove the 1,600-second bound but leave the lock free between jobs. Every deploy that ran in a request before, at most 570 seconds, fits in one job.

### Waiting releases the worker, not the target

Work outside the worker can outlast a job’s 1,600-second deadline. Keeping the worker blocked would stall every other operation. Ending the job at a waiting step lets those operations run while the target hold still prevents conflicting work. Making every step a separate job would release a deploy’s Instance lock between steps, so only an explicit wait ends a job. Reads observe existing work and do not consume the worker-loss attempt budget. The orchestrator sets the overall time budget; a second timeout inside Orbit would compete with it.

A delayed job can be lost. Recovering only a read that is more than twice its delay overdue gives normal delivery time to work and prevents a wait from becoming stranded. Counting these delayed reads as queued starts would prevent the sweep from recovering an unrelated lost start. A waiting cancel must confirm the stop before freeing the target, or a new operation could overlap work that still runs. Saving the cancel request first makes an unconfirmed stop retryable.

### Resume only what can run again

Orbit can prepare a release, sync the environment, switch `current`, refresh PHP, and prune again without harm. It cannot know whether a deploy step that migrates a database ran. So the runner repeats only steps that declare themselves resumable and fails the others at a named step. A guess in either direction would hide a broken deploy.

### An attempt lease instead of a heartbeat

A step that waits on a silent remote command writes nothing for minutes. A heartbeat would need a timer inside the worker. The kernel frees a file lock when its holder's code ends or its process dies, so the lease tells the sweeper whether an attempt still runs without a timer. A check of the worker process is not enough: a Horizon worker lives on after its job throws, so the operation would look alive and keep its target locked.

### A cancel after activation finishes the deploy

Once `current` points at the new release, the release serves traffic. Stopping before the PHP refresh, the `after_activation` steps, and cleanup would leave a half-done deploy. So the cancel arrives too late, and the deploy finishes. A rollback follows the same rule after its `activation` step.

### One worker for every operation

The lock per target already keeps two operations off one Instance, and a single operator rarely deploys two Instances at once. Each Horizon worker is a PHP process of about 40 to 50 MB, and the Gateway once ran out of memory with about 18 PHP CLI processes beside its PHP-FPM workers. So the queue has one worker, and a second deploy or a rollback waits in the queue instead of costing memory while idle.

### Operations need the queue

Running an operation without the queue, for example after the response inside PHP-FPM, keeps the 600-second limit and adds a second path that recovery must cover. Task VMs follow the same rule.

### Filters check the form of a kind

The list request accepts any well-formed `kind` and `target_type`. A new kind then changes neither the list request nor its API schema, and a client can filter by a kind before its Gateway runs it.

### Names that map onto MCP Tasks

MCP has an experimental Tasks primitive for long tool calls. An operation already has its parts: the ID, the status, the poll interval, and the get, list, and cancel requests. When `laravel/mcp` supports Tasks, `instance-deploy` and `instance-rollback` can return a task without a new record.
