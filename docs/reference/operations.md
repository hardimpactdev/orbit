---
title: "Operations"
description: "How the Gateway runs a long operation as a queued record with named steps, how a client follows it, and how the Gateway recovers one that a lost worker interrupted."
covers:
  - apps/gateway/app/{Domain/Operations/**,Actions/Operations/**,Data/Operations/**,Http/Requests/Operations/**}
  - apps/gateway/app/{Jobs/RunOperation.php,Http/Controllers/Api/OperationsController.php,Models/Operation*.php}
  - apps/gateway/database/migrations/2026_12_03_000000_allow_cold_recipe_image_rebuilds.php
  - apps/gateway/database/migrations/2026_12_28_000000_add_operation_idempotency_keys.php
  - packages/php-sdk/src/{Requests,Responses}/Operations/**
  - apps/cli/app/{Commands/Operations/**,Support/Operations/**,Commands/Concerns/FollowsOperations.php}
  - apps/web/src/{api/operations.ts,realtime/operation.ts,pages/OperationPanel.tsx}
---

# Operations

Swamp is the sole executor of task groups. The Gateway stores task state and runs requested keyed primitives. Operation recovery resumes a primitive without choosing the next task phase. Review parking reports stopped power only after provider confirmation; an unconfirmed parked row reports unknown power.

A long operation, such as an [Instance deploy or rollback](/reference/deployments#deploy-through-the-api), is a record in the Gateway database with named steps. Its kind declares its executor: PHP uses the [Gateway queue](/reference/gateway-queue); Swamp claims work through the [Services primitives](#swamp-execution). The request that starts it answers `202 Accepted` with the record and returns at once. A client then follows the record until it ends. Nothing a client does, such as closing its connection, stops the operation. Only [cancel](#cancel-an-operation) does.

Instance deploy, rollback, [task provision](/reference/tasks#provision-an-external-group), [task checks](/reference/tasks#check-an-external-group), and [task turns](/reference/tasks#run-an-external-turn) are operations. [External review parking](/reference/tasks#park-an-external-group-for-review) is also a keyed operation. It succeeds only after provider-confirmed stopped power; replay or recovery keeps the same group and VM. Instance create and remove, and Route convergence still run inside their request ([ADR 0209](/decisions/0209-run-long-operations-as-durable-queued-operations)). Other long commands, such as `node:add` and `instance:transfer`, also still run inside their request. [ADR 0218](/decisions/0218-take-long-commands-off-the-request-path) plans to move them off the request path and to lower the request limit.

Production deployments include a resumable `process_restart` step after PHP refresh. It restarts active Instance Processes before after-activation commands. A completed restart step is skipped on recovery. An interrupted restart step can restart a Process again. A stored deploy plan from before this step was introduced keeps its original steps after a Gateway upgrade. See [Deploy](/reference/deployments#deploy) for the opt-out and failure behavior.

## The record

`input` is the stored argument object. Operations with no arguments serialize it as an empty array (`[]`).

Every operation has the same fields, whatever its kind.

| Field | Contract |
| --- | --- |
| `id` | The operation ID |
| `kind` | What runs, `<family>.<verb>`, such as `instance.deploy` |
| `target_type`, `target_id` | The record the operation changes, such as `instance` and `15` |
| `input` | Validated operation input. Deploys may include `commit`; automatic admission also binds `caller_node_id`. Rollbacks include `release` |
| `status` | `queued`, `running`, `waiting`, `succeeded`, `failed`, or `cancelled`. The last three are terminal and never change again |
| `sequence` | A number that grows with every change of the operation or one of its steps |
| `current_step` | The name of the running or waiting step, or null |
| `failed_step`, `error_code` | For a failed operation, the step that ended it and its error code. `failed_step` is null when the operation failed outside a step |
| `result` | What the operation produced, or null until it ends. A terminal failure can have a typed result; non-null does not mean success |
| `cancel_requested` | Whether someone asked the operation to stop |
| `attempts` | How many times a worker started the operation |
| `triggered_by` | The manual caller's Node name, or an [automatic-deploy origin](/reference/deployments#history) |
| `caller_node_id` | The bound automatic caller's Node ID, or null. Also retained in `input.caller_node_id` |
| `caller_node_name` | Current name of the caller Node, or null if there is no caller or the Node has been removed |
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

### Swamp child Operations

Executor responses encode map fields as JSON objects, including empty Operation inputs, execution contexts, descriptor inputs, step-result maps, run results and receipt results. Descriptor input schemas preserve nested maps as objects and lists as arrays. Empty lists remain `[]`, and absent results remain null. This conversion happens only at the HTTP boundary, after authorization and redaction; stored input, fingerprints and receipts retain their original values. Reading a response does not hydrate secrets or run transports.

A Swamp parent admits children through its owning family's `OperationFamilyChildren::childContext()`. The context returns exact keyed intents `{key,kind,node_id,input}` from admitted input or prepared Gateway state. The parent kind declares allowed child kinds and Node relations. Each child kind registers an `OperationChildAdmission` adapter that calls its normal owning admission action. Swamp posts `{worker,key}` to `operation-executor/{parent}/children`; optional kind, Node and input fields are exact assertions only. Repeating a key returns the same child before repeating family admission.

The Gateway stamps `parent_operation_id`, `child_key` and an admission fingerprint. `OperationChildren::requireSucceeded(parent,key)` verifies the child's exact recorded target, input and constraints before returning its result. Parents cannot finish while any admitted child is nonterminal. A `children_succeeded` completion contract requires matching successful children, including each eligible keyed intent. Unsupported guarantees fail when the kind loads; families check `OperationKinds::supports()` before registration.

A same-target child inherits the ancestor's target hold, including chains such as `node.add -> node.role_add -> node.converge`. Child completion releases only its own hold. An unrelated start remains busy until the ancestor ends. A child on another target acquires the normal hold for that target.

`POST operation-executor/{parent}/wait` takes `{worker,keys:[child keys]}`. The Gateway records exact child IDs and fingerprints, releases the parent worker lease and keeps the target hold. The workflow ends. Claim only resumes that parent when those same children are terminal. Claim accepts optional `operation_id` for an exact queued child. `GET operation-executor/{parent}/children` returns bounded receipts for that parent’s children to executor peers. These routes use the same peer and Gateway-access gate as other executor routes.

Owning admission may add a server-only `run_constraints` map. Children retain inherited constraints unchanged. The named `time_window` constraint takes `{timezone,start,end}`, with an IANA timezone and distinct `HH:mm` times; start is inclusive and end is exclusive. Midnight crossing is supported. The Gateway checks constraints before hydration and immediately before dispatch. Families contribute other constraints through tagged `OperationRunConstraint` implementations.

### Bounded results and completion variants

A Swamp kind's `result_fields` accepts scalar paths and bounded lists. Scalar lists declare `{name,type:"list",items:"string"|"integer"|"boolean",max:N}`. Object lists declare `{name,list_of:{field:type},max:N}`. An omitted object-list bound is 4096; explicit bounds are 1–4096. Object fields use `string`, `int`, `bool`, `enum(a,b)` or a nullable form such as `string|null`. Every object field is required, and unknown fields refuse the result. A field inside an object entry may instead use a bounded scalar-list declaration. No other nested containers or keyed maps are allowed.

Lists must use JSON arrays. The step and completion boundaries refuse empty objects and objects with numeric keys. List strings are limited to 1024 bytes each. The complete encoded result is limited to 256 KiB, including scalar paths. A declaration may allow at most 65,536 total list items after expanding nested bounds. List order and scalar types are preserved. Selected values pass through redaction before storage; invalid results return only `operation.result_invalid` and never project a partial receipt.

A completion variant declares `completion_contract: {completion: {by: "input_field_path", map: {variant: proof}}}`. The path is relative to the immutable Operation input, for example `completion_variant` or `configuration.manager`. Each map value is a full named step or child proof. Missing or unlisted variants refuse completion with `operation.completion_unproven`; Swamp cannot choose a variant in a step or completion request. Root `completion` still names the family projection class.

`children_settled:[kinds]` lists allowed child kinds. A listed kind with no recorded intent is optional and does not require a child. Successful completion requires a terminal child matching every recorded intent. Every admitted child must be terminal and have a listed kind. Exact intent and stamped admission proof apply to optional children when present.

On success, `skipped` must acknowledge every failed or cancelled child with its ID and a nonempty reason. The Gateway stamps any declared key and kind from the verified child row. Duplicate, foreign, running or successful skips refuse completion. On failed or cancelled completion the Gateway derives skipped from stamped children, using their terminal status as the reason. No echo is required.

Kind loading requires a bounded skipped object list with `child_id:int`, `reason:string` and optional `key:string`/`kind:string`. Admission checks that its count and encoded byte capacity can retain every child failure. Canonical child metadata appears only in completion results; step and run results keep ordinary redaction. Final step and completion proof compare skipped child metadata after verifying and stamping it from the child rows. All other receipt fields must still match. Executor routes bypass string trimming and empty-string conversion to preserve exact bytes.

### Await from a Gateway caller

A retained internal caller can use `AwaitOperation::execute($operation, $timeoutSeconds = 30)` after admission commits. It refreshes only that Operation and sleeps between reads. The bound must be 1–120 seconds. It returns any terminal Operation, including failed or cancelled; the caller must check its outcome before dependent teardown. At the deadline it throws `operation.await_timeout` with `operation_id` and leaves the pending work unchanged. Prefer returning the Operation with HTTP 202 when the caller can follow it. Never wait inside the transaction that admits or holds the Operation.

### Kinds and targets

Use the kind and target type to start or find the work of a command.

| CLI command | Kind | Target type | Serving Node |
| --- | --- | --- | --- |
| `instance:deploy` | `instance.deploy` | `instance` | Instance Node |
| `instance:rollback` | `instance.rollback` | `instance` | Instance Node |
| `orbit:peer-dns-reconcile` | `fleet.peer_dns` | `peer_dns` | Peer Node |
| `tasks:park` | `tasks.park` | `task_group` | Gateway |
| `tasks:provision` | `tasks.provision` | `task_group` | Gateway |
| `POST /api/v1/task-groups/{group}/worker` | `tasks.worker` | `task_group` | Gateway |
| `tasks:check` | `tasks.check` | `task_group` | Gateway |
| `POST task-groups/{group}/script` | `tasks.script` | `task_group` | Gateway |
| `tasks:analyze` | `tasks.analyze` | `task_group` | Gateway |
| `tasks:turn` | `tasks.turn` | `task_group` | Gateway |
| `tasks:publish` | `tasks.publish` | `task_group` | Gateway |

Every task operation holds the group, so different kinds cannot overlap on it. A task VM Node exists only while the group runs, so the Gateway serves the group's operations and their reads. Provision returns `{ instance_id, node_id, starting_commit, analysis_base }` on success. The nullable [public provision identity](/reference/tasks#public-provision-identity) exposes the immutable recorded analysis pin; do not infer it from `starting_commit` or today's remote branch. A turn returns its receipt, thread state, workspace evidence, and token metrics; [Run an external turn](/reference/tasks#run-an-external-turn) defines the result and refusals.

A check returns `{ outcome, deliverables, check_id, commit, head, tree, output, ci }` under the [hosted handoff CI contract](/reference/tasks#check-an-external-group). Swamp owns review and merge policy. The input remains `{ subtask, phase }` with the existing optional key.

`head` is the pre-commit check snapshot; `commit` is the candidate HEAD. Baseline/no-policy has `ci: null`. A required-CI handoff adds persisted handoff-push and hosted-CI-read steps after the existing commit step. Before push, state pins the branch and required check name; it retains CI evidence while waiting, so recovery cannot reinterpret a later Project edit. The terminal result carries that evidence. A failed code/workflow check ends `succeeded` with `outcome: failed`, not an operation failure. Consumers need `outcome: passed` and the shared checked-candidate eligibility rule, not just `status: succeeded` or a locally passed check.

### Fixed repository analysis evidence

[Tasks analysis](/reference/tasks#analyze-an-external-groups-repository) runs keyed `tasks.analyze` with `workspace_ready`, `analysis`, and `report` steps, using existing task ownership and detached supervision. Its key is required, unlike the optional keys on ordinary operation starts. Same-key replay runs before mutable eligibility checks, never dispatches a second scanner, and keeps the original bounded artifact even after VM destruction. This addition does not change baseline/check inputs, ordinary turns, or closed external review-and-merge/topology admission.

The successful terminal result is `{outcome, artifact, provenance}`. `artifact: {sha256, bytes, content}` preserves exact complete UTF-8 stdout, at most 64 KiB/depth 24, not the step's 8 KiB diagnostic tail. The digest and length apply to decoded content bytes, not reserialized JSON. Size the typed transport for JSON escaping of the entire bounded result; never truncate it.

Gateway-owned provenance binds the complete plan and candidate to repository, branch, owned workspace, run, lease, entrypoint digest, before/after snapshots, duration, exit code, and zero mechanical LLM calls. Tasks owns the detailed field list; repository code owns report semantics and docs mappings.

Operation `succeeded` does not mean docs approval: usable outcomes are `ready` or `action_required`; valid `blocked` and `changed` evidence saved for inspection cannot authorize a turn.

Invalid or missing reports fail only after terminal local proof. Reserve the run before dispatch; missing-start recovery tombstones its identity. Dispatched work with no retained report is not rerun under its key. Cancellation or unknown descendants retain the group and workspace fence until termination is proven, while releasing the worker during waits. Existing operation reads and cancellation apply; add no arbitrary VM file reader or artifact table.

A qualifying terminal analysis failure instead returns the [closed failure result](/reference/tasks#public-analysis-failure-metadata) `{schema: 1, error: {code, required_bytes}}`. Status stays `failed` and the original `error_code` and failed step stay intact. An overflow includes its validated exact byte count when known, otherwise null; unavailable tooling carries null. There is no artifact, free-text exception message, stderr, or private-state projection.

Terminal proof and the existing owned checkpoint precede lease release; recovery and repeated reads preserve the same typed fields through the existing failed-outcome result path. Other kinds do not acquire Tasks metadata. Missing fields on older records remain unavailable rather than being inferred from logs. [Tasks](/reference/tasks#metadata-compatibility-and-generated-surfaces) owns absence handling, examples, validation, and API/CLI/SDK/MCP acceptance requirements.

An optional analysis reference on `tasks.turn` consumes the full plan/report/provenance only after fresh identity comparison under the turn lease before its first send. Persist the complete combined prompt within 100,000 characters before dispatch. Same-key recovery after dispatch uses that original prompt and send key, not a fresh send after revalidating an intentionally edited checkout. See [Analysis-bound turn evidence](/reference/tasks#analysis-bound-turn-evidence). Analysis supplies neither a `TaskCheck` candidate nor semantic approval, and never replaces handoff CI or the actual-file docs gate.

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

Every route that starts an operation accepts an `Idempotency-Key` header. Most routes make it optional. Today these are `POST /api/v1/instances/{instance}/deploy` (`instance.deploy`), `POST /api/v1/instances/{instance}/rollback` (`instance.rollback`), `POST /api/v1/task-groups/{group}/provision` (`tasks.provision`), `POST /api/v1/task-groups/{group}/check` (`tasks.check`), `POST /api/v1/instances/{instance}/deploy/automatic` (`instance.deploy`), and `POST /api/v1/task-groups/{group}/turn` (`tasks.turn`). The automatic deploy and worker start APIs require a key. The worker API excludes its enrollment secret from the fingerprint. A key has 1 to 128 characters from `A-Z a-z 0-9 . _ : -`. Keys are case-sensitive and scoped to the operation kind, across all calling Nodes.

Automatic deploy starts bind caller and command as well as target and input. The [automatic deploy start](/reference/deployments#pinned-commits-and-automatic-callers) requires a key and Gateway Node access. Manual deploys and other kinds retain their key scope across callers.

After checking the caller's access, the Gateway checks the key before further input or domain validation and before creating a target. The same key, target, and input return `202` with the existing operation, even when it has finished or failed to dispatch. A different target or input returns `409 operation.idempotency_conflict` and starts nothing. JSON object member order and whitespace do not change the input; array order and value types do. A malformed key returns `422 validation.failed` in the normal validation envelope, with `Idempotency-Key` in `error.details`.

Concurrent starts with one new key create one operation and queue one job. The operation stores the key, and a unique index on kind and key settles competing inserts. A request without a key starts a new operation under the usual target-hold rule.

The CLI commands `instance:deploy`, `instance:rollback`, `tasks:provision`, `tasks:check`, and `tasks:turn` pass the header with `--idempotency-key=KEY`. The PHP SDK requests accept `idempotencyKey`. Each MCP tool that starts an operation takes the `Idempotency-Key` argument (required where the API requires it), generated from the OpenAPI header.

Keyed operations are never pruned, so their keys never expire. Operations currently keep every record. When ADR 0218 adds retention for finished operations, it must exclude every operation that carries a key. The [WebSocket app routes](/reference/websocket-apps) keep their own Node-scoped idempotency rule until their changes become operations.

## Read an operation

Four requests read and stop operations.

| Request | Result |
| --- | --- |
| `GET /api/v1/operations/{operation}` | The record with every step |
| `GET /api/v1/operations/{operation}?after_sequence=N` | The record with only the steps whose `sequence` is greater than `N` |
| `GET /api/v1/operations` | The 50 newest records, without steps. [Filter the list](#filter-the-list) with `status`, `kind`, `target_type`, and `target_id` |
| `POST /api/v1/operations/{operation}/cancel` | Asks the operation to stop and returns the record |

The list selects operations and caller Node names in one query. Show uses the bound operation and reads its steps in one query. Neither waits for the operation. A read needs access to the Node that serves the target, such as the Instance's Node. The list returns only operations on Nodes that the caller can access; the Gateway Node and a Node with an access grant to the Gateway see all of them.

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
4. Repeat until `status` is terminal. Then read `result` according to `kind` and `status`; for failure also read `failed_step` and `error_code`. A failed operation's typed result is evidence, not success.

`poll_after_seconds` is 2 while the operation is queued and in the first 10 seconds of its run. It then grows by one second for each 10 seconds of run time, to at most 15.

Every read returns the whole record and the steps that changed. So a client that missed reads, or starts again from 0, still ends with the current state.

### Follow an operation over Reverb

When the [`websocket` role](/reference/events) is active, the Gateway sends `operation.updated` on the private channel `private-operation.{id}` at each change. A client:

1. Subscribes to `private-operation.{id}`. `POST /api/v1/broadcasting/auth` signs it for any caller with Gateway access.
2. Reads the operation over HTTP.
3. Applies each event whose `sequence` is greater than the one it has: it reads the operation again with `after_sequence`.
4. Unsubscribes when `status` is terminal.

An `operation.updated` event never carries output. While a production deploy step runs, the Node agent publishes each output line on the same channel as [`client-output`](/reference/events#deploy-step-output). The web app and the CLI show those lines live. Once the step ends, the step's output comes from the record; the CLI compares that tail with the live text it printed and shows the remaining output. If the retained text and record do not overlap, it shows the final tail with an earlier-output-skipped marker.

Reverb can lose an event, for example while the socket reconnects, so a client that has no event for a while reads the operation anyway. The web app and the CLI read every 15 seconds while subscribed. [Realtime events: Operation](/reference/events#operation) lists the fields.

## Cancel an operation

`operation:cancel` asks an operation to stop. A queued operation becomes `cancelled` at once. A waiting operation calls its current step’s stop hook at once and becomes `cancelled` only after the hook confirms the stop. Orbit records the cancel request before calling the hook. When the hook cannot confirm the stop, the request answers with that step’s error, such as HTTP 502. The operation and step stay `waiting`, keep the target, and retry the stop on the next delayed read.

A Swamp kind may declare `cancel_guard: GuardClass`, implementing `OperationCancelGuard::assertCancellable(Operation): void`. The Gateway checks the root and every nonterminal descendant under the parent lock in the cancel transaction, before recording a cancel or changing any child. Guards read Gateway-recorded proof only and perform no effects. A typed refusal, such as `operation.cancel_refused` with HTTP 409, leaves the whole tree unchanged. Terminal Operations remain unchanged without invoking a guard. Kind loading rejects an invalid guard class; `OperationKinds::supports("cancel_guard")` reports this capability.

When a waiting `tasks.publish` has started its push, cancellation is deferred without an error. It returns the waiting record and keeps the group held until the job records success or failure. See [Publish an external group](/reference/tasks#publish-an-external-group).

A running operation finishes its running step, starts no further step, and becomes `cancelled`. The steps it did not reach become `cancelled`. A cancel request for a terminal operation changes nothing and returns the record.

A cancelled deploy or rollback records its history row as `failed` with `deployment.cancelled`. Orbit never undoes the steps that ran.

A deploy or rollback ignores a cancel request once its `activation` step succeeded: the selected release is live, so a deploy still refreshes PHP, restarts Processes, runs the `after_activation` steps, and cleans up, and a rollback still refreshes PHP and restarts Processes. It then ends `succeeded`, or `failed` when a later step fails, and `cancel_requested` stays true.

## How an operation runs

The job `RunOperation` runs on the `operations` queue, which has one worker. One operation job runs at a time in the fleet: an operation on another target stays `queued` until the running one ends. The job claims the operation with one conditional update from `queued` to `running`, so a second copy of the job does nothing. It records the worker's host and process ID, for people. While the attempt runs, it holds the operation's attempt lease, an exclusive lock on a file under `ORBIT_HOME/locks/operations`. The lease ends with the attempt's code, also when an error escapes it, and with the worker process.

An error that escapes the attempt fails the operation with `operation.failed`. When the Gateway cannot even record that, for example while its database is locked, the operation stays `running` with a free lease until the sweep recovers it.

The operation's kind plans its steps, then runs them in order. The runner skips a step that already succeeded, so a resumed operation continues where it stopped. It checks for a cancel request before each step. While a step runs, the runner writes its output tail at most once a second. A step that throws ends `failed`, and the operation ends `failed` with the kind's error code.

Each active run has a deadline of 1,600 seconds; time between waiting reads does not count toward it, and its last 20 seconds are a cleanup reserve. A step that runs out of forward time stops there, and the recovery after it uses the reserve, such as reading which release an interrupted activation selected. Its job may run 1,700 seconds, under the queue's `retry_after` of 1,800, so Redis never gives a running job to a second worker. When the deadline stops a deploy or rollback, its error code is `deployment.deadline_exceeded`.

### A waiting step

A step can ask the runner to wait with a positive delay in seconds. The operation and its current step become `waiting`. The job ends and releases its attempt lease, while the operation keeps its target. Another start on that target still answers `409 operation.target_busy`.

`tasks.provision` waits for VM capacity and readiness. Keyed `tasks.worker` waits for an existing VM to resume, then starts a worker with fresh enrollment under the workspace lease. `tasks.check` waits for VM resume, workspace ownership, and its detached check. Under the [handoff policy](/reference/tasks#check-an-external-group), it also waits for its handoff push and hosted CI. `tasks.turn` waits for a parked VM, a workspace lease, and the Pi turn to end. `tasks.publish` waits for VM resume and its queued push. Deploy and rollback keep their current execution.

A delayed job first claims the operation with one conditional update from `waiting` to `running`, then calls the current step’s read hook. It does not start that work again. The read either finishes the step and continues the operation, or asks to wait again. Duplicate delayed jobs cannot read the step together, and an early job cannot read before the next read is due. A read that finds work still running consumes no recovery attempt. A wait has no time limit of its own. The orchestrator owns time budgets and cancels work that takes too long.

The kind supplies the current step’s read and stop hooks. A read returns the step’s result or asks to wait again; a stop returns only when it confirms the work stopped, otherwise it throws the step’s error. Both run under the operation’s attempt lease. The runner stores step results through the same path as a synchronous step and then continues the kind, skipping succeeded steps.

### One operation per target

An operation holds its target from the start request to its terminal status. A second start for that target answers `409` with `operation.target_busy`. The hold lasts across a lost worker and a resume. A rollback is the one exception: it supersedes a deploy of the Instance that no worker has started, as [One operation at a time](/reference/deployments#one-operation-at-a-time) describes. The hold does not cover work that is not an operation: while it runs, a deploy or rollback also takes the [Instance's operation lock](/reference/deployments#one-operation-at-a-time), as before.

### Recover a lost worker

Swamp requests operation recovery every five minutes through the Services-only [observation API](/reference/gateway-queue#scheduled-work). Gateway state recovery queues the job again for:

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

### Publish waits for its existing job

`tasks.publish` uses the approval push job and reads its stored result while waiting. It holds no operations worker during the VM transfer. A cancel before the job starts invalidates the queued record; a cancel afterward keeps the group held until the job ends, because stopping a push cannot prove that GitHub did not receive it.

### A task check returns evidence

`tasks.check` holds the group while its detached check runs, without holding the operations worker. A failed check returns a successful operation with a failed outcome so the orchestrator can decide its next step. An unconfirmed stop retains the target and retries, because a cancel request alone does not prove the workspace is free. Group cancellation can end the operation when the VM is unreachable because the group's existing removal rule retains the workspace and requests assistance.

The [hosted-CI prerequisite](/reference/tasks#check-an-external-group) uses delayed bounded reads for pending, missing and unreadable evidence, with no worker held and no Gateway-owned time budget or next turn. Recovery retains one commit per operation and reconciles the existing VM push record and remote tip. Cancellation can invalidate a queued push, but a started push must reconcile before ownership ends. Once only CI waits, cancel stops observation without cancelling workflows or erasing evidence. The owning operation must succeed with passed CI before either reviewer or publish admission can use its check; cancellation cannot turn a local pass into a completed candidate.

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

### Tracked task scripts

[Tasks scripts](/reference/tasks#run-a-tracked-repository-script) use `workspace_ready` and `script` steps. The key is required. Replaying the same key precedes admission checks and returns the original receipt and bounded result, including after workspace removal. The existing target hold, lease, delayed reads, cancellation, and uncertain-ownership fences apply. Script completion grants no factory approval, check eligibility, or next-step scheduling.

### Production smoke operations

The [production smoke API](/reference/deployments#dispatch-and-receipt) starts `instance.smoke` with the separate `instance_smoke` target and a required caller-bound key. It never takes the Instance deploy lock. The durable send step records intent before GitHub and is resumable only as a read after that intent. Delayed read steps release the worker. Observation ends 720 seconds after admission (Swamp’s 600-second budget plus 120 seconds for queue delay), after which unresolved evidence is retained as unknown; this transport limit does not replace Swamp's baseline deadline.

Cancellation stops observation and reports unknown; it never resends or cancels the GitHub run. Operation failures describe control-plane errors, while the smoke result independently distinguishes verified failed tests from pending or unknown execution evidence.

## Swamp execution

Author native workflows in `hardimpactdev/orbit-swamp`. Converted kinds use the shared [direct-start intent and result projection](#direct-swamp-starts), without a generated family artifact or Gateway compiler loader. The claim-based API below remains for kinds still being converted.

A kind declared with `executor: swamp` is admitted through the same Operation store and idempotency boundary. Admission returns `202`. The Gateway never dispatches a PHP job for it. Swamp owns its steps and retries; the Gateway keeps intent, target exclusion, steps, output and the final receipt.

The configured Services peer (`dependency_detection.services_node_id`) and explicitly listed executor worker peers can use `/api/v1/operation-executor`. Its primitives claim one queued kind with a renewable lease, record named steps and keyed log lines, invoke a named RemoteProgram or agent command on a Node, and complete the Operation. A worker uses one stable claim key per workflow run.

An expired claim can be reclaimed; every later write must present the current lease from the claiming Node. A matching worker string from another peer does not grant ownership. A lost response is retried with the same key and input. Families must use convergent Node commands or reconcile an uncertain effect before advancing.

### Protected inputs

A run may declare named secret sources for parameter paths. Operation input and Swamp carry only an owning-record reference; the Gateway verifies ownership before hydrating it for the existing transport. Secrets never enter Operation records or responses. Run fingerprints and stored input bind only the unhydrated reference, so a fresh credential mint cannot change replay identity. A null or different owner, or a target type outside the source’s `targetTypes()`, refuses execution. Executor requests preserve empty strings and whitespace for exact input binding.

Each family supplies tagged resolvers for protected values and redaction strings. Script resolvers verify an admitted SHA-256 digest. Registered agent wrappers implement `OperationTransportInput` and delegate native wire conversion before strict schema validation. Optional family execution hooks derive public context, an optional result projection filtered by `result_fields`, and exact inputs, recheck policy before execution, and project redacted receipts in the database. A started invocation without a receipt refuses retries with `operation.execution_uncertain` by default. Only convergent runs explicitly declare `resumable: true`.

### Execution contracts

Each kind declares a closed list of allowed transport/name pairs, typed input bindings to admitted intent, a successful step/result completion contract, and scalar result fields that may be retained. The Gateway validates agent input against the exported schema; RemotePrograms require an explicit input schema. Run targets come only from the Operation, and a supplied target or unlisted command receives `operation.execution_forbidden`. Successful completion requires a recorded successful Node run and matching step, or returns `operation.completion_unproven`.

Immediately before a new Node command, the Gateway rechecks status, worker lease and cancellation. Cancellation returns a keyed `operation.cancelled` refusal without invoking the Node; a saved successful receipt can still be completed. Step names support dotted phase names such as `setup.install`. Step and completion error codes contain only lowercase letters, digits, underscores, dots and hyphens.

Undeclared result fields are dropped. Structured result sensitivity follows the kind’s `result_fields` declarations: secret outputs use their declared stores and return owning-record references.

Kind loading rejects public result paths containing a secret word, including nested and list fields, unless the exact path is `key`, `skipped.key`, `child_phases.key`, `public_key`, `host_key_fingerprint` or `key_count`. Other secret-named results require a declared secret output. Public fields such as `environment` keep their declared structure. Boolean completion proofs keep their native type.

Hydrated secret values and secret text patterns remain masked before projection, storage or return; numeric masks never coerce booleans. Activity inputs, logs and errors retain their existing redaction rules.

Firewall `allow`, `deny` and `remove` use Swamp. Their normal commands validate and record managed intent before returning the Operation. Firewall list, live-list, managed-list and fleet-list remain reads in Orbit. The agent's existing `firewall.rule` command converges or removes the exact owned UFW rule. The conversion recipe is maintained in `hardimpactdev/orbit-swamp`, `docs/conversion-kit.md`.


Protected sources resolve only during invocation hydration. Step, completion and context reads never mint credentials for redaction. Known values are redacted in the run before projection, storage or return; Swamp reuses those redacted results. Later messages retain ordinary secret-pattern filtering. Transport schema and wire failures return a fixed refusal, and a permissive `true` schema is refused.


Kind loading rejects unsupported kind, run and completion-contract keys. A family cannot declare a guarantee before the shared executor enforces it. Child declarations and `children_succeeded` use the checks above. `children_settled` uses the verified terminal and skip checks above.


An uncertain executor response leaves the keyed result unset for a proven recovered receipt. It never projects uncertainty as a completed effect. A successful outcome clears `failed_step` while retaining any failed best-effort step rows. The Gateway requires redaction strings for protected content, including typed credentials and scalars. Boolean flags, null, empty strings, empty arrays, empty plain objects and `GitReadEnvironment::none()` keep their native types without inferred masks. In particular, true does not add a mask for the digit `1`. Unknown opaque values without masks remain refused.

Empty transport schemas are refused. `OperationKinds::supports()` reports optional child, object-list and receipt capabilities; unsupported guarantees remain unavailable at admission. The `children`, `children_settled`, `list_of`, `receipts` and `compensations` capabilities are enabled. `secret_outputs` uses the enforced storage boundary below.
### Target effect receipts

Remote executor runs use `effect_key` equal to `op-<operation id>.<step key>`. The Gateway adds the key after authorizing the original input. Fingerprints and stored inputs always use the unhydrated request. Native commands persist their intent and result in the shared agent receipt store. Existing fixed RemotePrograms use `KeyedRemoteProgramRunner`, which reserves and completes receipts through that same store.

After a lost reply, Swamp reads `GET /operation-executor/{operation}/receipts/{step}`. The route uses the existing executor peer guard and needs no worker query. The authenticated peer must match the Operation's recorded lease peer. The step must have an admitted descriptor or an already recorded run that still matches the kind's allowlist. The Node comes only from the Operation. The response data contains `{effect_key, state, exit_code, result, finished_at}`. State is `succeeded`, `failed`, `running`, or `unknown`. Pending receipts have null exit code, result, and finish time.

The original invocation carries its masks as a protected transient metadata object, outside public input, fingerprints and stored Operation state. Native command envelopes carry those masks separately from typed input. The agent sanitizes results before persistence, stores a sanitization flag, and discards the masks. Missing proof refuses recovery.

A receipt read never resolves payload sources, mints credentials or executes the effect. The hook below lets a bootstrap transport read an existing credential for authentication. Terminal sanitized target results pass through the family transport's normalization, the same `execution.received` hook as `/run`, and the kind's redaction and result filtering. Recording the result and family projection is atomic and happens once. Recovering an unrecorded result requires an active Operation claim; reading an already recorded result needs only the executor peer identity.

On `succeeded` or `failed`, Swamp uses the returned result. On `running`, it reads again later. On `unknown`, it may invoke `/run` with the same step key only when that run explicitly permits replay. An absent key can execute once; an unfinished saved intent refuses another execution. An unknown receipt never proves success.

A family transport can implement `OperationEffectTransport` beside the existing `OperationTransport` methods. Its `effectful(Operation, array $run): bool` declares which runs use target receipts. Its `receipt(Operation, array $run, array $input): array` reads and normalizes the target receipt to the same transport result as `run`. Both methods receive the server-generated key as `$run['effect_key']`. During the original invocation, `$run['effect_metadata']` holds the protected masks. `AgentEffectReceipts::input($run, $input)` forwards that metadata with a native command. Fixed RemotePrograms pass `effect: EffectReceiptMetadata::fromRun($run)` to `KeyedRemoteProgramRunner::run`. Receipt reads receive the original input references; payload references remain unhydrated.

For connection authentication only, `OperationSecrets::connection($operation, $run, $parameter)` requires a declared source implementing `OperationConnectionSecretSource::resolveConnection(Operation,int): OperationSecretValue`. It reuses the exact run allowlist, target-type and owner checks, and reads an existing credential without minting, rotating or creating one. The transport uses that in-memory value only to establish its pinned connection; it never adds it to command payloads, masks, results or records. Other sources cannot use this hook.

Custom readers preserve `effect_nonce`, `effect_created_at` and `sanitized: true` when normalizing proven target results. `AgentEffectReceipts::read(Node, command, effectKey, owner?)` reads native results. `KeyedRemoteProgramRunner::receipt(Node, RemoteProgram, effectKey)` reads fixed program results. Neither accepts a Node override from Swamp.

Pre-start refusals (`agent.target_busy`, `agent.lock_unavailable`, `agent.supervisor_not_ready`) return `{error_code, not_started: true}` without a saved result. The Gateway resets `started` only when no earlier attempt was uncertain, so the same key may run again after the precondition clears. Target and resource locks are acquired before the agent saves an unfinished intent. Contention for the effect key remains uncertain. Invalid input, envelope, version and command refusals before an effect starts retain their real error code as a failed result. Deadlines, supervisor errors after spawning, receipt-store errors and identity conflicts remain `operation.execution_uncertain`. A failed attempt to save the receipt after a remote program runs also remains uncertain.

The Gateway stores a random nonce and the original creation time privately on each Operation. Protected effect metadata binds this identity into target fingerprints and receipts. Receipt recovery checks it before projection. Restoring a Gateway database and reusing a numeric Operation ID therefore cannot adopt an older target result. Public effect keys and receipt response fields stay unchanged.

## Discover work for executor workers

`GET /api/v1/operation-executor/claimable` is a read for the active configured Services peer with Gateway access. Listed worker peers cannot use this endpoint. Its `data.kinds` contains one `{kind, claimable, oldest_at}` entry per registered Swamp kind, including zero counts and null timestamps. It exposes no Operation IDs or inputs and changes no lease or Operation. `oldest_at` is the earliest admission timestamp among the candidates, in ISO 8601 UTC.

The read and claim paths use one shared candidate selector. It counts queued Operations, running Operations whose 120-second lease has expired, and waiting parents whose exact recorded children are terminal and verified. Discovery does not write assistance flags; claim flags an invalid waiting parent and continues to other candidates. A live worker's own claim replay is not new claimable work. Services uses this summary to start each kind's workflow on its placed worker; that same authenticated Node claims, heartbeats, runs and completes the Operation. Discovery never claims work on behalf of another Node.


### Cancel child plans

A kind may declare `compensations: [<child kinds>]` with `cancellation_boundary: <child key>`, gated by `OperationKinds::supports("compensations")`. Each compensation kind must also be a declared child kind. After cancellation, this parent performs no further effect itself. It may admit and run several compensation kinds only after the named boundary is a Gateway-stamped successful child of this parent. Existing input, target and receipt checks still apply.

Cancellation stamps every nonterminal descendant in the same transaction and immediately settles queued children that have not started. Running children observe their own cancel request, so their own proven activation boundary or compensation contract still applies. A cancelled child returns the ordinary `operation.cancelled` result and can settle.

An intermediate transfer can therefore restore after its grandparent is cancelled. New compensation children run their cleanup plan under the existing proof rules.

Child Node relations may use only `state.child_intents`, `state.integration_intent`, `state.route_plan` or `state.role_intent` and their nested fields. Other state prefixes refuse at load. Use admitted `input`, `intent` or `operation.node_id` for other plans. Swamp step data and target receipts never supply Node authority. Same-target siblings serialize their effects under the original ancestor's attempt lease without acquiring or releasing its target hold. Custom run constraints check once at final dispatch; the owning admission adapter validates custom parameters.

An invalid waiting child makes that parent unavailable for claim, without stopping other candidates. Claim records a safe `state.executor_assistance.error_code`; discovery remains read-only. Missing Gateway Swamp kind registration also raises this assistance flag. The Gateway cannot infer whether a deployed Swamp checkout includes a workflow, so deploy each family's registration with its Gateway kind. Re-entry copies verified child states, error codes and redacted results beside their original identities.


### Resolve cancellation and missing targets

Operation show and list expose a safe `assistance` value: null, or `{error_code}` for an invalid waiting child or unavailable child kind. Internal state and arbitrary details stay private. An explicit cancel may end a flagged waiting parent and release its target once no stamped nonterminal child can still be proven. A proven running child keeps the hold until it settles.

The first cancel cascades to nonterminal descendants; queued children that have not started end immediately. A repeated ancestor cancel skips a subtree already marked for cancellation, so newly admitted compensations can finish. A direct cancel of a compensation still cancels that child.

The cascade commits before PHP waiting-step stop hooks run. A started effect without a saved result stays uncertain after cancellation and must be resolved through its target receipt. When explicit cancellation abandons a flagged parent, unprovable descendants end cancelled with `operation.child_unproven` assistance and release their own holds.

Admission records the original Node ID, target type and target ID independently of the live Node foreign key. Child admission proof retains these immutable values after Node deletion. Child receipts and terminal executor context keep the original Node identity. Completion replies, lost-reply replays and cached effect receipt reads do not hydrate sources. New transport effects on a deleted Node fail with `operation.target_missing` before hydration or dispatch. Clearing a live Node foreign key while that admitted Node still exists refuses proof.

`AwaitOperation` refuses with a `LogicException` when called inside a database transaction. Commit admission before awaiting; otherwise the Gateway would hold SQLite's write lock while waiting for Swamp to complete. Claim scans candidates in ID order, stops at the first eligible candidate and locks only that selected row. Discovery uses the same readiness predicate and writes nothing.


## Store secret outputs

A result field declared `{name,secret:true,store:"<family>.<field>"}` is stored through the family's tagged `OperationSecretOutputStore`. The adapter checks the owning record and writes its existing encrypted field. The Gateway checks the target type and owner before dispatch and again when saving the receipt. The encrypted write, family projection and keyed receipt commit together. Retries return the existing receipt without writing the credential again.

Before the family receives a run result, the Gateway replaces each secret output with `{"$secret":"<store>","id":<ownerId>}`. It redacts echoes from other fields. Swamp, Operation state, activity, steps and completion contain only that reference. The family's existing API controls reading the encrypted value. Unknown stores, invalid owners and storage failures return a fixed refusal without credential details. Step and completion requests cannot submit raw values at these paths.

Secret output paths traverse one JSON segment at a time. A literal dotted key cannot replace a nested field. Boolean secret outputs, including booleans inside containers, refuse before storage so public boolean status fields remain usable. A null output stays null in the target receipt: it carries no new secret and does not require recovery of a lost value.

A transport with secret outputs implements `OperationSecretOutputTransport` and keeps its generated values only in memory until the Gateway extracts them. The Gateway transport meets this contract. The agent receives the declared output paths as protected metadata. It stores only a presence marker at each path and redacts echoes before saving its target receipt. The first live reply carries the values for encrypted Gateway storage. If that reply is lost before storage, a presence marker cannot recover the secret: the run stays uncertain and the effect does not repeat. A custom transport must provide the same safe output path before opting in.

`OperationKinds::supports("secret_outputs")` reports support for this enforced contract.

## Relay an archive between child Operations

The `archive_relay` transport delivers an archive from one stamped child to a sibling. The source child first creates a private archive with a named agent command and saves a successful receipt containing `bytes` and `sha256`. The destination descriptor uses its native receive command name and input `{relay:{parent_operation_id,child_key,run_key},input:{...native receive input}}`. The family builds the handle and destination input from recorded intent. Swamp carries references and receipts only; archive bytes stay on the Nodes and in a private temporary Gateway file.

The destination execution class implements `OperationFamilyArchiveRelay::archiveRelay()`. This pure binding returns `source_transport`, `source_name`, `read:{name,input}` and `max_bytes`. The shared transport proves the source child and keyed producer receipt, checks both native schemas, compares the exact byte count and SHA-256, and enforces the minimum of the family bound, the compiled reader and receiver bounds, and 1 TiB. The destination verifies the same metadata before changing its managed target. Unknown handles, foreign children, missing Nodes and invalid metadata refuse before destination dispatch.

Kind loading refuses an archive relay without that family interface. The reader's archive or SQLite path must match the proven producer receipt. Before staging, the Gateway checks free space for the archive plus the larger of 64 MiB or ten percent. Binding, download, integrity or cancellation failures before destination dispatch leave the run unstarted and retryable. A previous uncertain destination attempt stays uncertain; a later preparation failure cannot erase it.

The source producer and destination receiver have separate keyed receipts. A lost destination reply uses the existing target receipt route, with the original effect identity. Recovery never downloads the archive or resolves payload secrets again. Gateway staging is an unlinked private file closed in `finally`. Families keep their source artifact until the destination outcome is proven, then remove only that artifact through a named cleanup child on success or failure. Cancellation uses declared compensation children and their proven boundary; destination discard and source checkout removal remain separate effects.

## Executor health

The Gateway runs `orbit:operations-check` every minute for every registered Swamp kind, independently of Swamp. Queued work without an executor for five minutes reports `operation.executor_unclaimed`; a waiting parent reports the same code while a child has been unclaimed that long. Running or waiting work without an Operation sequence change for fifteen minutes reports `operation.no_progress`. More than three attempts reports `operation.reclaim_churn`.

The primary code appears in `operation:show` assistance, including owner-checked family reads. Existing execution proof assistance takes priority, followed by unclaimed work, no progress and reclaim churn. Activity records each health change once and records recovery once. Health checks never execute, cancel or release an Operation. Terminal work clears its computed assistance.

A Swamp Operation has at most five execution attempts. After the fifth lease expires, the next claim parks it as `waiting` with `operation.reclaim_limit` assistance and keeps its target hold and receipts. Replaying a live claim or resuming a proven child wait spends no additional attempt. Read-only discovery can dispatch one final claim to park exhausted work; it does not change the Operation itself.

### Resume after the reclaim limit

An operator can resume this parked Operation with `orbit operation:resume <id>` (`POST /api/v1/operations/{operation}/resume`, empty JSON object). Under the Operation lock, resume resets attempts to zero, clears reclaim-limit assistance and queues the same Operation for Swamp. It preserves intent, steps, receipts and the target hold. Activity records the caller, Operation and outcome. Resume returns the Operation with its steps and never waits for execution.

A repeated request after the reset, another assistance code, a terminal Operation, a non-Swamp kind or a pending cancellation returns HTTP 409 `operation.not_resumable` without changing the Operation. Each new reclaim-limit stop needs an explicit operator resume; there is no automatic reset. Cancellation still uses the normal proof-aware path.

## Direct Swamp starts

Families declare fixed workflows in their own `direct-operations.php`. `DirectOperation` records the per-kind writer choice at admission. `StartSwampRun` submits the committed intent through native `workflow.run` and records its run ID. Every family uses the one `ORBIT_SWAMP_RUN_TOKEN` credential. The immutable Operation ID supplies the stable `orbit_start_key` runtime tag. Connection and reply budgets follow the native client's 15-second connection and 30-second request defaults.

If the start response is lost, the same call reads native history once for that key and workflow. A matching run with the exact admitted inputs supplies the run ID. Only a confirmed empty result permits one immediate resubmission with the same key and inputs. A failed or cancelled native run, or an unreadable, malformed or ambiguous history response, leaves `swamp.start_uncertain`; it never permits another submission. A native refusal is not resubmitted.

This is bounded submission recovery, with no job, timer, execution retry or history poll. Native runtime tags support lookup; they do not provide atomic start deduplication. The recorded run binding remains required before a workflow can read intent or invoke a primitive. Swamp alone waits for this initial binding: its shared first read retries only `409 operation.run_unbound`, once per second for up to 90 seconds. Cancellation ends that wait. Other errors and all mutations remain single attempts.

A declaration names the workflow, writer configuration path, immutable writer-state field and a typed projection hook. New families use the common `swamp.direct_writers` map (`ORBIT_SWAMP_DIRECT_WRITERS` JSON keyed by exact kind, default `{}`). Families add no token, controller or HTTP routes. Routes retain their existing per-kind settings and `route_publication_writer` field.

After a kind cuts over and removes its previous executor, its `operation-kinds.php` entry contains only `executor: 'swamp'`, `result_fields`, and optional `admission`. Its matching `direct-operations.php` declaration supplies the workflow and projection. The registry resolves that projection without requiring old `runs`, completion handlers or receipt proofs. These direct-only kinds default to the direct writer; an explicit polled setting, a scope mismatch or a missing direct declaration refuses admission rather than falling back to deleted code. The previous executor's claim, execution and completion paths also refuse them. Rollback reverts the kind's cutover PR.

A parent still on the previous executor may admit a direct child through the child's owning admission hook. The child response includes normal Operation, parent and child identity. It omits the old execution context, lease and wait metadata. Replaying that child admission returns the same child without resolving a deleted execution contract.

Cancelling a direct Operation only records `cancel_requested_at`. Swamp owns stopping and projecting the terminal result; the Gateway does not apply old execution policy, walk its native children or settle it as an unstarted queued job. The previous parent's cancellation may request cancellation of a direct child, but cannot settle that child on behalf of its native workflow.

A declaration may constrain its writer with literal `writer_scope` admitted fields; `node_id` and `parent_operation_id` come from Gateway admission authority. A scope mismatch records the previous polled writer. Tool admission also checks that its prepared input and the stored writer decision agree before it records an Operation.

`firewall.allow` and `firewall.deny` start `operation-firewall-direct` directly for root intents on Node 209. Admission refuses other Nodes and a disabled direct writer until their workers are ready. Their Gateway agent-run descriptors and receipt proofs are removed. `firewall.remove` retains its previous Swamp path until its own canary passes. Rollback is a revert PR, which restores both admission and the previous execution contract.

Firewall direct intent records the admitted agent digest. The Node worker checks that digest and the root-owned binary path before running `firewall.rule` through its existing passwordless sudo; the Gateway performs no Node action.

Undeclared or unstamped Operations stay on their previous Swamp path. The helper rejects a PHP writer. Families keep their own authorization, input and result validation; shared projection only records bounded status/results and native run identity. It adds no execution policy.

The optional typed primitive hook is reserved for shared generic Gateway primitives, such as Route/Caddy publication or scoped token minting. New family execution relays are refused; family actions run on their Node worker.

Per-Node workers must match the admitted Operation’s `node_id` on read, result and primitive requests. The configured control-plane peer keeps its existing fleet authority; adding a Node to `worker_node_ids` does not grant that authority. Gateway-scoped intents are refused to per-Node workers.

Only an authenticated native start response or an exact native history match can bind `swamp_run_id`. Read, result and primitive requests refuse until it is recorded, and cannot bind or replace it. A bound intent returns without another submission. Each family canary proves start-response and first-read ordering. Structured `Swamp direct start` logs record duration, outcome and failure phase without remote diagnostics or credentials.

Read input comes only from the projection hook, never automatically from raw intent. Primitive calls accept only the run ID; the hook selects its command and input from admitted state. Existing Route publication URLs reach the same shared controller during the Swamp rollout.

| Shared endpoint | Contract |
| --- | --- |
| `GET /api/v1/direct-operations/{operation}?run_id=UUID` | Safe admitted input, status, result and cancellation request |
| `POST /api/v1/direct-operations/{operation}/result` | Run ID, status, timestamps, bounded result and error code |
| `POST /api/v1/direct-operations/{operation}/primitive` | Optional fixed kind-owned action; run ID only |

On Swamp, use the shared `directOperation` template with family-owned read, action and projection functions. The action may be a local Node step or a fixed Gateway primitive while that family migrates. Native DAGs, placement, behavior locks, cancellation and recover/resume own execution. Mutating external steps declare `writes: true`. No Gateway claims, heartbeats, child waits, receipts or local retry loop belong in the template.

`instance.rename` declares `operation-instance-lifecycle-direct` for branch recording on a development checkout. Its default writer remains `polled`. The binding canary uses existing Node 209 workers (`pool=migration`) until per-Node workers exist; exact `orbit-node-<node_id>` placement follows later. The future exact-Node `rename` method uses `instance.inspect-development-source`, which never changes Git HEAD or files. The canary reads the bound intent, projects running, then projects `instance.node_action_unavailable` without any local agent call. It proves the shared boundary, not checkout inspection or rename success.

The Node action checks repository identity and the requested checked-out branch, then the Gateway projects the recorded branch. Direct mode refuses a domain change before admission until Route's owning workflow can compose it. The previous polled writer retains domain changes. This first slice uses no secrets, Gateway primitive hook or root action.

Prove each kind end to end on a dev or canary target. Its takeover PR changes the writer and deletes the replaced Gateway path and execution tests together. Rollback is a revert PR. Admitted Operations retain their writer. Scoped workflow grants and token custody require review before activation; extending one family's run grant to another workflow is not implicit.
