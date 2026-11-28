---
title: "Operations"
description: "How the Gateway admits, tracks, cancels, and recovers long-running Operations across Swamp, the Gateway engine, the signed executor, and Gateway-local handlers."
covers:
  - apps/gateway/config/operation_versions.php
  - apps/gateway/app/{Domain/Operations/**,Actions/Operations/**,Data/Operations/**,Http/Requests/Operations/**,Console/Commands/CheckOperationCleanupCommand.php,Console/Commands/RepairOperationFenceCommand.php}
  - apps/gateway/app/{Http/Controllers/Api/OperationsController.php,Models/Operation*.php,Jobs/Operations/**}
  - apps/gateway/database/migrations/{2026_12_03_000000_allow_cold_recipe_image_rebuilds,2026_12_28_000000_add_operation_idempotency_keys,*create_operation_idempotency_aliases_table,*route_publication_holds*,*pin_operation_versions,*fence_conn_launch_retries,*create_operation_cleanup_tasks}.php
  - packages/php-sdk/src/{Requests,Responses}/Operations/**
  - apps/cli/app/{Commands/Operations/**,Support/Operations/**,Commands/Concerns/FollowsOperations.php}
  - apps/web/src/{api/operations.ts,realtime/operation.ts,pages/OperationPanel.tsx}
---

# Operations

Long-running work in Orbit (such as deployments, provisioning, Route changes, and task turns) is recorded as an **Operation** in the Gateway database. Admission returns `202 Accepted` immediately with the Operation record, and clients follow it until it reaches a terminal status. Closing a client connection never cancels an Operation; only [`operation:cancel`](#cancel-an-operation) does.

Operations execute on one of four backends depending on kind and configuration:

- **Swamp workflows:** The default backend for most fleet, Instance, Route, and task Operations ([Swamp execution](#swamp-execution)).
- **Gateway engine:** Event-driven state machine over NATS JetStream for kinds enabled in `ORBIT_ENGINE_KINDS` ([Gateway engine](/reference/gateway-engine)).
- **Signed executor:** Direct WebSocket signed orders for container `instance.sleep` and `instance.wake` ([Executor](/reference/executor#signed-power-delivery)).
- **Gateway-local:** Coordinators and diagnostics that the Gateway settles directly (`websocket.*`, `proof.run`, `executor.proof`, and record-only `instance.node_service_detach`).

When any Operation (including `instance.deploy`, `instance.start`, and Swamp recovery sleep/wake runs) ends `interrupted` with `executor.result_unknown` under a signed-order fence, the reconcile pass re-reads the Node's executor journal (least-recently-read first, up to 50 per pass). A recorded result or late receipt frees the lock once the Swamp run's native terminal is recorded. An absent record frees the lock only when the Gateway recorded signing time and expiry, its clock is 1 minute past expiry, and the journal proves coverage from 1 minute before signing. The Operation keeps `interrupted`, `executor.result_unknown`, and `finished_at`, recording what settled the fence under `executor_fence_settled`.

Signed `instance.destroy` uses its own engine step fence. Its first potentially published order establishes the replay limit. A removal with no terminal receipt remains running and locked, even after the [Gateway stops sending orders](/reference/gateway-engine#instancedestroy). A failed receipt records its redacted diagnostic on the step and in the Operation's error.

Container Process journal tails and unit state reads create no Operation; they use the narrow [direct transport exception](/reference/node-agent#commands). Automatic production deploy Activity entries and alerts also record without an Operation.

The [Conn thread librarian](/reference/conn#the-thread-librarian) also runs outside Swamp: a Gateway background job calls the AI router and updates the thread's Conn metadata directly, without creating an Operation.

## Instance failure alerts

Whenever `instance.create`, `instance.complete`, or `instance.deploy` enters `failed` or `interrupted`, the Gateway sends an immediate `instance.operation_failed` event to the [signed release webhook](/reference/gateway-recovery#webhook) after commit (recorded in Activity as `instance:failure-alert`). It includes IDs, kind, native run ID, failed step, and error code without inputs, secrets, or command output.

## Schedules of the Swamp Node

`swamp-datastore-watch` and `swamp-credential-monitor` have no built-in workflow trigger so they do not run on nested proof fleets. Instead, `swamp/config/swamp-node-schedules.json` declares their cron expressions and the [release Swamp load](/reference/gateway-recovery#load-swamp) applies them on the Swamp Node via `swamp workflow trigger set`.

To remove an old entry from `.swamp/serve.yaml` on the Swamp Node, run as `orbit` in the serve checkout:

```bash
swamp workflow trigger remove <workflow> --server ws://127.0.0.1:9418 \
  --token-file ~/.config/swamp/serve-driver-token
```

## Swamp datastore watch

`swamp-datastore-watch` runs every 15 minutes on the Swamp Node to count `.swamp/` datastore files and sample serve CPU over 5 seconds. The Gateway reads its `measure` output and files a [Problem](/reference/tasks#fingerprints) (source `swamp`, requiring two fires at least 10 minutes apart) when any threshold is exceeded:

| Key | Threshold |
| --- | --- |
| `datastore\|auto_definitions\|<type>` | More than 2,000 files for one auto-definition type (excluding `swamp/grant`) |
| `datastore\|outputs` | More than 50,000 files in `outputs` |
| `datastore\|step_leases` | More than 60,000 files in `data/swamp/step-lease` |
| `datastore\|grants` | More than 500 `swamp/grant` records |
| `serve\|cpu` | More than 50% serve CPU over the 5-second sample |
| `watch\|failed` | The watch run itself failed |

Swamp's own garbage collection (`swamp/.swamp.yaml`) retains runs for 6 hours, bookkeeping for 2 hours, outputs for 30 minutes, and model data for 7 days (deleting in 100-version batches, up to 5 minutes per hourly pass).

## Swamp credential expiry alerts

`swamp-credential-monitor` runs hourly at minute 7 on the Swamp Node across four plain read steps: `swamp access token list`, `swamp worker token list`, the `swamp.orbit` TLS certificate, and the token names held by the Swamp Node's live consumers (serve-driver and each worker slot's `server.token` and `enrollment.token`, never secrets). Superseded token generations are skipped once a consumer holds a newer generation of the same family.

The Gateway reads its `alerts` output and sends `swamp.credential_expiring` (for `within_10d` on `orbit-orchestrator-read` only, `within_72h`, `within_6h`, or `expired`), `swamp.credential_monitor_failed`, and `swamp.credential_monitor_recovered` to the [signed release webhook](/reference/gateway-recovery#webhook), recording delivery in Activity (`swamp:credential-alert`):

- **`serve-driver` and `swamp-role-209-<slot>*`:** Renewed automatically 10 days ahead by [Swamp token renewal](/reference/node-provisioning#swamp-token-renewal). On a `within_72h` alert, inspect `journalctl -u orbit-swamp-token-renewal` and start `orbit-swamp-token-renewal.service`.
- **`orbit-gateway-read*` and `orbit-gateway-run*`:** Renewed by [`gateway:swamp-credentials`](/reference/gateway-environment#renew-the-swamp-read-and-run-tokens); inspect its output and rerun it.
- **`orbit-orchestrator-read`:** Operator-managed alert-only token (`within_10d`); mint a new name, switch the file on mini, then revoke the old name. (`orbit-node-209*` tokens are unused and not renewed.)

Never rotate or revoke a valid live token by hand, because doing so ends active sessions. If `serve-driver` has already expired, follow [break-glass recovery](/reference/node-provisioning#break-glass-recovery-for-services-and-swamp-209).

## Native run budgets

Every direct Operation tracks its execution budget from `started_at`. `OperationBudgetTable` resolves budgets in three tiers:

| Tier | How the budget is chosen |
| --- | --- |
| Captured `input.run_budget_seconds` | Computed at admission for `instance.create`, `instance.destroy`, `instance.unregister`, `instance.deploy`, `instance.rollback` (900 s fallback), `instance.restore`, `instance.seed_copy` (900 s + 2,500 s per target), `schedule.run`, `tasks.check`, and `proof.command` (600 s + 180 s per batch + case time × 1.5, max 7,200 s; 3,600 s fallback) |
| Explicit kind constants | `proof.run` (`14,700` s + `7,200` s admission), `fleet.reboot` (`10,800` s), `instance.migrate` (`5,700` s), `tasks.copy_turn` (`4,680` s), `node.converge` / `cluster.router_*` / `tasks.cancel` / `tasks.maintenance_finish` / `database.import` (`3,900` s), `gateway.release` (`3,600` s), `proof.verify` (`3,000` s), `instance.complete` (`1,800` s), `tool.install` / `tool.update` (`1,500` s), `instance.start` (`1,200` s), `tool.converge` (`1,080` s), `instance.php_index` (`900` s), `fleet.rollout` (`600` s), `instance.wake` (`300` s), `instance.connection_environment` (`180` s), `instance.sleep` (`150` s), and `instance.mail_update` / `instance.node_service_detach` (`120` s) |
| History-derived fallback | 1.5× the longest succeeded run of that kind over the past 14 days (rounded up to a minute, floor 15 minutes; 30 minutes with no history) |

Unbound admissions with zero attempts have a separate 600-second admission budget.

A `tasks.cancel` that waits for its task copy's `instance.destroy` has no deadline: it is recorded `running` without a lock, and it ends when the destroy ends, succeeded or failed with the destroy's error code. The destroy's budget bounds it, except a [signed clone removal](/reference/gateway-engine#instancedestroy), which stays fenced past its budget until its Node returns the result ([`task:cancel`](/cli/task/cancel)).

When a bound Operation reaches its deadline, the reconciler marks it `failed` with `operation.timed_out`, sets `overdue_since` (queuing one `operation:overdue-alert` webhook on `tasks`), and forwards `workflow.cancel` to Swamp. Target holds stay locked until Swamp confirms the bound run is terminal or 60 minutes elapse (`native_terminal_status: unproven`). An Operation that timed out before Swamp bound its start to a run stays in every reconciler pass after its hold is released, until Swamp's lookup by its start key finds the run or verifies that none exists. If a bound run actually completed before its deadline and its terminal history arrives later, the reconciler reopens and projects the true outcome.

A run that ended after its deadline stays timed out, but a projection that declares `DirectOperationLateResult` receives the run's terminal result and its verified history once, as the reconciler records that end, so its records keep what the run proved. It also learns when an Operation never ran: a cancel before its run, or a lost start Swamp has no run for. Tool Operations and Tool and Node convergence use it ([Ownership](/reference/tools#ownership)).

A [signed clone removal](/reference/gateway-engine#instancedestroy) does not release its Instance lock on a deadline. After dispatch only an executor result can settle it; the Gateway keeps asking for the result of the same step.

`instance.migrate` adds a 60-second grace window after verified native end for missing finish output; proven rollback releases the reservation (`instance.migrate_result_unavailable`), while uncertain cutover keeps the reservation fenced (`instance.migrate_recovery_required`).

## The record

Every Operation exposes a consistent structure:

| Field | Contract |
| --- | --- |
| `id`, `kind` | Operation ID and `<family>.<verb>` kind |
| `target_type`, `target_id` | Target resource type and ID |
| `input` | Summary of top-level scalar inputs (max 512 bytes each) on show/cancel; omitted on list |
| `status` | `queued`, `running`, `waiting`, or terminal `succeeded`, `failed`, `cancelled`, `interrupted` |
| `sequence` | Monotonic counter incremented on every change |
| `current_step`, `steps` | Active step name and ordered `OperationStep` list (`name`, `job`, `status`, `started_at`, `finished_at`) |
| `error`, `failed_step`, `error_code` | Redacted diagnostic detail (max 4 KiB stderr tail), causal step, and error code |
| `result` | Kind-specific terminal output (non-null does not guarantee success) |
| `cancel_requested`, `attempts` | Cancellation flag and start attempt count |
| `triggered_by`, `caller_node_id`, `caller_node_name` | Caller metadata |
| `created_at`, `started_at`, `finished_at` | ISO 8601 timestamps |
| `poll_after_seconds` | Recommended polling delay (`2`–`15` s while active, `null` when terminal) |
| `hold_wait_reason`, `ready_at` | Route wait reason (if waiting) and wake readiness timestamp (for `instance.wake`) |

Public list, show, and cancel endpoints apply `Operation::scopePubliclyVisible`, which excludes internal `PrivateConnKinds` Operations from general callers.

### Operation versions across a Gateway release

Admission pins each Operation's version from `apps/gateway/config/operation_versions.php`, keeping in-flight Operations on their original workflow, inputs, and handlers across releases.

To phase out an old version, move it to `retained` while open work drains, then to the `deprecated` list in `operation_versions.php` to fence new admissions. Before traffic cutover, the release fences closed versions (`--fence-only`), runs `operations:repair-fence` right after cutover (and every 5 minutes in the scheduler as a fallback), and runs `php artisan operations:check-cleanup --release-operation` once the former live container drains. When an earlier verified release's closed version has zero open work and no rollback target can reopen it, `gateway-operation-cleanup` files one backlog Task in the release repository's Project.

### Record coordination and native children

Parent Operations that coordinate multiple children (such as `proof.run` and [Conn launches](/reference/conn#durable-launches-v1)) do not bind a Swamp run of their own. They advance stored state and child admissions under the parent row lock, dispatch native work after commit, and use `ReconcileSwampOperations` to recover missed child settlements.

### Kinds that left the registry

Kinds removed from the registry remain readable in `operation:list` and `operation:show` history. The reconciler makes no Swamp calls for them and closes any still-open row on cancel or at its history budget.

## Start an operation

Start endpoints validate access, idempotency, and input, commit a `queued` Operation with its target hold, and return HTTP `202` before dispatching work after commit:

| Code | HTTP | Meaning |
| --- | --- | --- |
| `operation.idempotency_conflict` | 409 | Key already exists for this kind with a different target or input |
| `validation.failed` | 422 | Malformed `Idempotency-Key` or invalid input |
| `operation.target_busy` | 409 | Another open Operation holds the target (`details.operation_id` / `operation_ids`) |

### Retry a start with a key

Start endpoints accept `Idempotency-Key` (`1`–`128` chars of `A-Za-z0-9._:-`, case-sensitive, scoped per kind; required on automatic deploys and worker starts). Replaying the same key, target, and input returns `202` with the first Operation returned for that key even after it ends; a different target or input returns `409 operation.idempotency_conflict`. For a staged operation such as the Conn Server rollout, a replay still returns the original stage, not the next one.

By default, fingerprints are portable across callers with access to the target. Endpoints declared with `bindCaller: true` (such as automatic deploys at `POST /api/v1/instances/{instance}/deploy/automatic`, keyed Conn starts, WebSocket app Operations, task pull-request merges, proof runs, and `node.base_inventory`) also bind `caller_node_id` and `command`, so a different caller Node replaying the same key receives `409 operation.idempotency_conflict`. Durable Conn launches (`POST /api/v1/conn/launches`) are an exception: they scope keys per caller Node (`keyed: false`), so two callers sending the same `Idempotency-Key` for the same target receive `202` with different launch IDs.

Internal callers use `OperationIdempotency::under()` and `OperationIdempotency::suspended()`. Keyed Operations are never pruned. `GET /api/v1/conn/environments/{environment}/session` ignores `Idempotency-Key` and deduplicates via its open `conn.session_hold` Operation instead.

## Read an operation

Use these endpoints (requiring access to the target's serving Node, or Gateway access to list all):

| Request | Result |
| --- | --- |
| `GET /api/v1/operations/{operation}` | Full record with input summary, result, and `steps` |
| `GET /api/v1/operations/{operation}?after_sequence=N` | Record with only steps changed after `sequence` `N` |
| `GET /api/v1/operations` | Filtered, paginated list without `input`, `result`, or `steps` |
| `POST /api/v1/operations/{operation}/cancel` | Requests cancellation and returns the record |

`GET /api/v1/operations` supports optional `status`, `kind`, `target_type`, and `target_id` filters.

### Page the list

`GET /api/v1/operations` returns up to `limit` rows (`1`–`100`, default `20`), newest first, with `meta.next_cursor` for keyset pagination. List rows omit `input`, `result`, and `steps` so large intents or results never exceed client reply limits.

### Follow an operation over Reverb

When the `websocket` role is active, subscribe to `private-operation.{id}` (authorized via `POST /api/v1/broadcasting/auth`), fetch the initial Operation over HTTP, refetch with `after_sequence` whenever `operation.updated` arrives with a higher `sequence`, and poll every 15 seconds as a fallback until terminal ([Realtime events: Operation](/reference/events#operation)). Clients without Reverb poll using `poll_after_seconds` (2 s initially, growing by 1 s per 10 s of runtime up to 15 s).

## Cancel an operation

`POST /api/v1/operations/{operation}/cancel` immediately cancels an unstarted queued Operation and frees its target. Once a native run is bound (or starting), it records `cancel_requested: true` and forwards `workflow.cancel` to Swamp, letting the workflow's terminal result settle the Operation.

Two special rules protect data integrity:

- **Database fill (`server-database-fill`):** Cancellation waits until the fill step finishes (up to its 600-second limit plus 60-second grace) before forwarding cancel so an orphaned dump cannot keep running on the database server.
- **Started `tasks.publish` push:** Cancellation keeps the task group held until the push records success or failure.

Cancelling a parent Operation cascades to all non-terminal children in the same transaction.

## How an operation runs

After commit, the Gateway pushes any required execution copies to Swamp's vault and queues a start job on `operation-starts`. When the root run finishes, the event observer queues immediate projection on `operations-prompt` (retried on HTTP 429 after `Retry-After`), while the minute `ReconcileSwampOperations` job on `operations` acts as a safety net. If a run succeeds while its declared end-check step was skipped or failed, the projector fails the Operation immediately with `operation.result_unreadable`.

### One Route write per Node

Route-writing Operations (`instance.create`, `instance.complete`, `instance.destroy`, `instance.unregister`, `instance.clone`, `instance.transfer`, `instance.migrate`, `instance.restore`, `route.create`, `route.update`, `route.destroy`, `route.target_set`) serialize per serving Node via `route_publication_holds`. An engine-run `route.update` or `route.destroy` and any other Route write never queue behind each other: whichever is second is refused with `route.publication_held` when both write one Node (a serving or edge Node, a Node the old site leaves, or a VPN Node; `RouteWriteNodes`). The engine start checks in its bind transaction; any other Route write checks right after its record exists, before it holds or dispatches. Competing writes enter `waiting` (never `409`) with `hold_wait_reason`:

| Reason | Meaning |
| --- | --- |
| `nodes_held` | A publisher holds one of the target Nodes |
| `waiter_first` | An older waiting write is ahead in the queue |
| `after_operation` | Waiting for `input.after_operation_id` to end |
| `removal_cap` / `removal_slot` | Removal concurrency cap (4) or withdrawal slot collision on a Node |
| `instance_slot` | Older removal of the same Instance slot (`id % 64`) on the Node is open |
| `share_cap` | Eight shared writers hold one of the Nodes |

Removals share Nodes with other removals and release their Route hold early right after `Withdraw the Route` via `operation:direct:route-release`. A removal whose admission rendered an engine plan (`route_engine`) holds its Nodes alone instead, because the engine writes whole files. Waiting creates, clones, restores, and `route.create` Operations re-capture their Caddyfile from the current catalog when promoted by `PromoteRouteWrites`.

## Errors

Common Operation error codes across all kinds:

| Code | Meaning |
| --- | --- |
| `operation.idempotency_conflict` | Duplicate key with mismatched target or input (HTTP 409) |
| `validation.failed` | Invalid input or malformed `Idempotency-Key` (HTTP 422) |
| `operation.target_busy` | Another open Operation holds the target (HTTP 409) |
| `operation.superseded` | A rollback cancelled this queued deploy before it started (`cancelled`) |
| `operation.timed_out` | Run budget expired before completion (`failed`) |
| `operation.result_unreadable` | Run ended without a readable end-check result (`failed`) |
| `operation.failed` | Kind failed without a more specific error code |

## Swamp execution

Native workflows live under `swamp/` and are registered per family in `direct-operations.php`. Mutating external steps must declare `writes: true` (enforced by `swamp/tests/writes_declared_test.ts`) so Swamp retries only safe read-only or idempotent steps after a worker loss.

### Direct Swamp starts

`StartSwampRun` submits `workflow.run` using `ORBIT_SWAMP_RUN_TOKEN` with `orbit_start_key` (`operation:<id>`), a 15-second connect timeout, and a 120-second confirmation budget.

Connection failures and serve `not_ready`/`draining` responses re-queue the start on `operation-starts` with exponential backoff (5, 10, 20, 40, then 65 seconds) for up to 420 seconds before failing with `swamp.start_unavailable`, `swamp.vault_sync_unavailable`, or `swamp.start_refused`. Other rejections before the run starts fail immediately with `swamp.start_refused`. If confirmation is lost after sending `workflow.run`, the Operation stays `running` with `swamp.start_uncertain`, and the reconciler looks up the exact run by workflow, identity, and `orbit_start_key` without ever resubmitting.

Direct Swamp steps may call only these Gateway root-of-trust endpoints (authenticated by WireGuard peer and bound `run_id`):

| Endpoint | Purpose |
| --- | --- |
| `GET /api/v1/direct-operations/{operation}?run_id=UUID` | Legacy public intent read (retained for Fleet kinds) |
| `POST /api/v1/direct-operations/{operation}/result` | Report bounded terminal status and result |
| `POST /api/v1/direct-operations/{operation}/primitive` | Run kind-declared Gateway primitive |
| `GET /api/v1/direct-operations/{operation}/private-material?run_id=UUID` | Hydrate kind-declared secret or artifact references (`Cache-Control: no-store`) |
| `POST /api/v1/direct-operations/{operation}/secret-output` | Store kind-declared encrypted outputs (replaced with `{"$secret":"<store>","id":<id>}`) |
| `POST /api/v1/direct-operations/{operation}/github-token` | Mint short-lived repository-scoped GitHub App token into `orbit-control-inputs` |
| `POST /api/v1/direct-operations/{operation}/certificates/sign` | Sign admitted RSA 2048 CSRs into 397-day TLS leaf certificates with the Orbit root CA |

### Recover a terminal native result

`ReconcileSwampOperations` runs every minute on `operations` to project finished Swamp runs, advance coordinators, and record pass metrics (`reconcile.finished_at`, `reconcile.last_pass_ms`, `reconcile.unsettled_count`) for [`gateway:status`](/cli/gateway/status). If passes stall or a terminal run stays unsettled for more than 5 minutes, `gateway:status` reports `gateway.operation_reconcile_stalled` or `gateway.operation_settlement_stuck`.

### Private material transport

Secret inputs and outputs never enter Operation inputs, state, steps, or logs. Admission records only owning-record references (`OperationSecretSource` / `OperationSecretOutputStore`); at execution, the Gateway hydrates secrets into memory or `orbit-control-inputs` vault keys after verifying the bound `run_id`, unfinished Operation, and matching caller Node, and stores generated secret outputs directly into their encrypted model fields.
