---
title: "Operations"
description: "How the Gateway runs a long operation as a queued record with named steps, how a client follows it, and how the Gateway recovers one that a lost worker interrupted."
covers:
  - apps/gateway/config/operation_versions.php
  - apps/gateway/app/{Domain/Operations/**,Actions/Operations/**,Data/Operations/**,Http/Requests/Operations/**,Console/Commands/CheckOperationCleanupCommand.php,Console/Commands/RepairOperationFenceCommand.php}
  - apps/gateway/app/{Http/Controllers/Api/OperationsController.php,Models/Operation*.php,Jobs/Operations/**}
  - apps/gateway/database/migrations/{2026_12_03_000000_allow_cold_recipe_image_rebuilds,2026_12_28_000000_add_operation_idempotency_keys,*route_publication_holds*,*pin_operation_versions,*fence_conn_launch_retries,*create_operation_cleanup_tasks}.php
  - packages/php-sdk/src/{Requests,Responses}/Operations/**
  - apps/cli/app/{Commands/Operations/**,Support/Operations/**,Commands/Concerns/FollowsOperations.php}
  - apps/web/src/{api/operations.ts,realtime/operation.ts,pages/OperationPanel.tsx}
---

# Operations

Swamp is the sole executor of task groups. The Gateway stores task state and runs requested keyed primitives. Operation recovery resumes a primitive without choosing the next task phase.

A long operation, such as an [Instance deploy or rollback](/reference/deployments#deploy-through-the-api), is a record in the Gateway database with named steps. Swamp runs it: the Gateway admits the intent, starts one native workflow, and projects the workflow's result into the record ([How an operation runs](#how-an-operation-runs)). The request that starts it answers `202 Accepted` with the record and returns at once. A client then follows the record until it ends. Nothing a client does, such as closing its connection, stops the operation. Only [cancel](#cancel-an-operation) does.

With [signed power routing](/reference/executor#signed-power-delivery) enabled, container sleep and wake keep their usual Operation kinds and target holds. Admission records the engine choice. The Gateway tries the Node's WebSocket and, if needed, the SSH relay. If the result remains unknown, Swamp takes over the same Operation with a fresh signature. The activation request waits only within its own [short budget](/reference/executor#the-wake-inside-the-request); a result that arrives later can still complete the Operation promptly.

SQLite records the wake's notices and lifecycle handoff with the change. A background pass sends them to `operations-prompt` without holding the writer. [Wake handoff recovery](/reference/executor#wake-handoff-recovery) covers publication, job loss, retries and hook receipts. No queue failure changes an in-bound success.

Container Process journal tails and unit state reads create no Operation. They use the narrow [direct transport exception](/reference/node-agent#commands); Process actions still run as Operations.

Not every recorded result is an operation. The Gateway records an automatic deploy's Activity entries and release alerts from the terminal result of its scheduled run, with no Operation; see [Instance releases](/reference/deployments).

Instance deploy, rollback, [task provision](/reference/tasks#provision-an-external-group), [task checks](/reference/tasks#check-an-external-group), and [task turns](/reference/tasks#run-an-external-turn) are operations. Publication releases the VM; later task work waits for a fresh task place. Every other kind a command starts runs the same way; [Kinds and targets](#kinds-and-targets) lists the task and deploy kinds.

Production deployments include a `process_restart` step after PHP refresh. It restarts active Instance Processes before after-activation commands. See [Deploy](/reference/deployments#deploy) for the opt-out and failure behavior.

Native terminal projection reads Swamp history and any private result material outside the state transaction. Result writes lock and recheck the admitted run, and each family commits its domain state with its result. Remote cleanup runs after that commit.

Removing a [Node service target record](/reference/processes-and-schedules#node-service-target-records) returns an already completed `instance.node_service_detach` Operation. This record-only operation works after a successful migration and performs no network, environment or runtime change. It still refuses an active lifecycle operation or an unfinished failed migration with a retained runtime.

Automatic release role convergence reserves the Node against Instance, Conn and Process Operations that participate on it. Task work (`tasks.*`) neither waits for nor holds it. Admission refuses a conflict with `operation.target_busy`, and `details.operation_ids` names the holders. A refused automatic converge queues ahead of new Instance, Conn and Process work on the Node. A removal (`instance.destroy` or `instance.unregister`) passes the queue. After 300 seconds the converge is admitted beside its holders, and their continuations still finish while it runs. See [Fleet rollout](/reference/gateway-recovery#fleet-rollout).

Containerized AppProd deployments and rollbacks capture their build and activation budgets on the Operation. A failed cutover can still return a verified serving commit. The Gateway records that observed selection before an operator retries.

## Instance failure alerts

Every `instance.create`, `instance.complete` or `instance.deploy` Operation that enters `failed` or `interrupted` sends an immediate notification after the state transaction commits. This includes failures before source resolution and automatic deployments. A terminal replay does not send another notification. Cancelled and successful Operations do not alert.

The alert uses the existing [signed release webhook](/reference/gateway-recovery#webhook), with event `instance.operation_failed`. It includes the Operation, Instance and Node IDs, kind, native root run ID, failed step and error code. It does not include inputs, private material, output or stderr. The Activity command `instance:failure-alert` records the notification and webhook outcome. An unavailable webhook does not change the failed Operation. Both release webhook settings must be configured for delivery; otherwise Activity records `not_configured`.

## Schedules of the Swamp Node

`swamp-datastore-watch` and `swamp-credential-monitor` read the serve checkout at `/home/orbit/apps/orbit-monorepo-staging/checkout`, the `swamp.orbit` certificate under `~/.config/swamp/serve-tls` and the Node 209 token names. Only the production Swamp Node has them.

Every serve loads the same `swamp/workflows`, and Swamp registers each workflow's built-in `trigger` on every serve, a [nested fleet's](/reference/development-containers#nested-fleet) too. These two workflows therefore have no built-in trigger. Only the release sets their schedules, on Node 209:

1. `swamp/config/swamp-node-schedules.json` holds the cron of each workflow.
2. The `load` step of the [release Swamp load](/reference/gateway-recovery#load-swamp) runs `swamp workflow trigger set` for each entry. It runs after `swamp serve reload`.
3. Serve keeps each one as a trigger override in its `.swamp/serve.yaml`.

A schedule that serve does not confirm fails the load.

Only the production release runs that load, so only Node 209 runs these schedules. A nested fleet's serve does not register them. A serve with no overrides, such as a new Swamp Node before its first release, runs neither schedule until a release sets them.

A workflow that keeps a built-in trigger may not read the production checkout or `serve-tls`; `swamp/tests/swamp_node_schedules_test.ts` checks this.

### Changing the file

The load only sets the entries that the file holds. A missing or empty file sets nothing and passes, because a rollback runs the load against an older checkout without the file. A file that jq cannot read fails the load.

When you remove an entry from the file, its override stays in serve.yaml. Remove it on Node 209 by hand, as `orbit` in the serve checkout:

```bash
swamp workflow trigger remove <workflow> --server ws://127.0.0.1:9418 \
  --token-file ~/.config/swamp/serve-driver-token
```

## Swamp datastore watch

The native schedule `swamp-datastore-watch` runs every 15 minutes on the swamp role's workers on Node 209 ([Schedules of the Swamp Node](#schedules-of-the-swamp-node)). It reads only: one `command/shell` step counts the files under serve's datastore, `.swamp/` in the serve checkout, and samples serve's CPU over 5 seconds with `top`. It prints one JSON result with the auto-definitions per type, `outputs`, `workflow_runs`, `data`, `step_leases`, `grants` and `serve_cpu_percent`. The step never calls the Gateway.

"Node 209" here is the Swamp Node of the production fleet: the Node that holds the `swamp` role. The Gateway finds it by that role and never by an id, so a Gateway that bootstraps its own machine has the same Swamp Node ([Gateway bootstrap prerequisites](/reference/node-provisioning#gateway-bootstrap-prerequisites)).

The Gateway reads the terminal `measure` output through the shared scheduled-result reader and raises a [problem](/reference/tasks#fingerprints) with source `swamp` for each crossed bound:

| Key | Raised when |
| --- | --- |
| `datastore\|auto_definitions\|<type>` | One auto-definition type other than `swamp/grant` holds more than 2,000 files |
| `datastore\|outputs` | `outputs` holds more than 50,000 files |
| `datastore\|step_leases` | `data/swamp/step-lease` holds more than 60,000 files; bookkeeping keeps leases 2 hours, so the count should level off near 2 hours of dispatches |
| `datastore\|grants` | Serve holds more than 500 grant records, the auto-definitions of type `swamp/grant` |
| `serve\|cpu` | Serve used more than 50% CPU over the sample |
| `watch\|failed` | A watch run did not succeed, so the datastore is unwatched |

Serve's own garbage collection keeps runs 6 hours, bookkeeping 2 hours and outputs 30 minutes (`garbageCollection` in `swamp/.swamp.yaml`). Nothing reads a method output later, and a resumed run doesn't read it at all: run history, its logs and step outputs come from the run record and `data/`.

Thirty minutes is more than twice the longest run on Node 209 (790 s, a task VM image run) and twice the 900-second step budget. A day on 1 hour, through a serve and worker restart, showed no resume or missing-output failure. Node and Instance Schedules run as systemd timers on their Node, not as Swamp runs. Its retention pass rereads that file every hour, so a change applies at the next pass without a restart. Bookkeeping, such as step leases, is reaped by serve's worker GC, which first runs an hour after serve starts ([Lab #4287](https://swamp-club.com/lab/4287)).

A key is filed once two fires at least 10 minutes apart report it. The first later fire that does not report a key resolves it. A replayed or older run changes nothing. Large auto-definition directories are the known cause of high serve CPU ([Lab #4280](https://swamp-club.com/lab/4280)).

Model data is kept 7 days (`data: 7d`). The same hourly pass deletes every data version created before the cutoff, the latest version included; only Swamp's control-plane records, such as grants, groups, tokens, workers and step leases, are exempt. Every serve stall that follows a catalog rebuild grows with the number of data versions, so this bound keeps it short.

Data a later run reads must be written again within the 7 days. The automatic deploy policy memory is, by its [hourly refresh](/reference/deployments#automatic-deployments). Most other Swamp records that Orbit reads are written in the run or Operation that reads it, or are caches that a later run rebuilds. Two are not. A [dependency index or report](/reference/instance-dependency-contracts) older than 7 days is gone: the Gateway shows its cached copy as stale, then as unavailable. A dependency upgrade plan is gone after 7 days, so an approval later than that fails its run.

A pass deletes in batches of 100 versions, one version at a time, for at most 5 minutes across all retention kinds. Serve's writes wait for at most one batch. A backlog larger than one pass clears over the next hourly passes.

## Swamp credential expiry alerts

The native schedule `swamp-credential-monitor` runs every hour at minute 7 on the swamp role's workers on Node 209 ([Schedules of the Swamp Node](#schedules-of-the-swamp-node)). It is alert only: it reads expiry metadata and changes no credential or grant. Three plain command steps run as `orbit`, with no serve token and no `sudo`, and read these sources:

- `swamp access token list` and `swamp worker token list`, read locally from the serve datastore;
- `openssl x509 -enddate` for the `swamp.orbit` certificate.

The `check` step, the `credential_report` method of `@nckrtl/node-worker-assets`, classifies their output without reading anything itself. Each credential gets one class: `ok` (more than 72 hours left), `within_72h`, `within_6h`, `expired` or `no_expiry`, for a token with no `expiresAt`. Revoked tokens are skipped. The check stops alerting on a credential one week after it expired. The step publishes only the alerts, at most 200, soonest expiry first.

| Result | Alert reason |
| --- | --- |
| A credential is `within_72h`, `within_6h` or `expired` | The class |
| The `orbit-node-209-access` or `orbit-node-209` token is not listed | `missing` |
| A native list fails, times out or changes shape | The run fails |

Club tokens are not checked. Their list needs a personal Swamp Club account, and a Node holds only a service key. Their expiry is watched in the Swamp Club account itself.

The Gateway reads the `alerts` output of the `check` step through the shared scheduled-result reader. It sends event `swamp.credential_expiring` through the [signed release webhook](/reference/gateway-recovery#webhook) with each credential's kind, name, reason, expiry and hours left. Each credential, expiry and reason alerts once after a delivered webhook. While a condition holds, every run reports it again, so an undelivered alert is retried by the next run. A condition that clears and returns alerts again.

If a monitor run does not succeed, the Gateway sends `swamp.credential_monitor_failed` once per failure streak. An undelivered failure alert is sent again by the next failed run. When a run succeeds after a delivered failure alert, the Gateway sends `swamp.credential_monitor_recovered`, again on each run until it is delivered. A webhook that is not configured counts as undelivered. The monitor runs on the worker it watches: when the `orbit-node-209` pair has expired, no worker claims the step, and only the failure alert reports it after `queueTimeout`.

The Gateway keeps the last projected run, the failure streak and the delivered alerts in the Gateway setting `swamp.credential-monitor`. It skips any run that started at or before the last one, so a replay after a reader reset or `optimize:clear` sends nothing. The Activity command `swamp:credential-alert` records each notification and its webhook outcome.

Renewal stays a hand procedure. When an alert names a worker token, follow the [worker enrollment steps](/reference/node-provisioning#per-node-swamp-worker) to issue it again. Then follow the [break-glass recovery steps](/reference/node-provisioning#break-glass-recovery-for-services-and-swamp-209) to restore the worker.

## Native run budgets

Each step in a native Instance workflow has a queue timeout: 10 seconds for create and 30 seconds for setup or destroy. Each child workflow declares its own queue timeout. These queue limits do not change the Operation's run budget.

The Gateway tracks each direct Operation's budget from `started_at`, including native start confirmation. The shared `OperationBudgetTable` reads `instance.create`, `instance.destroy`, `instance.unregister`, `instance.deploy` and `proof.command` from the server-captured immutable `input.run_budget_seconds`. The create admission computes that positive integer from its captured phase plan, including heavy Project setup. The deploy admission sets the frozen deploy step timeouts plus 900 seconds. The Gateway does not re-read the Project.

An explicit kind budget wins and stays fixed until a maintainer changes the table. Long-running kinds use measured or provisional constants; measured rows use the 14-day p95 of successful runs, rounded up to the next five minutes.

For example, `instance.migrate` has 5,700 seconds and `node.converge` 3,900 seconds. A kind without a successful run gets an explicit budget only from the measured work of a real run. For example, `cluster.router_set` and `cluster.router_unset` have 3,900 seconds. That is 1.5 times the 2,417 seconds of work in their only run, rounded up to five minutes. A maintainer must update the fixed row after measuring successful runs. The create fallback is 60 seconds when no captured phase budget exists. Clients cannot choose the captured budget.

`tasks.cancel` and `tasks.maintenance_finish` also have 3,900 seconds, derived from the work they compose: the former VM destroy bound of 2,700 seconds, the 540-second Project teardown cap, and 660 seconds for the workspace Route withdrawal. Withdrawal's only fixed wait is the 31-second DNS grace, so most of that allowance is deliberate margin until real runs are measured. Their history came from cancels with no VM to destroy and was too short for real cleanup.

`tasks.copy_turn` has a fixed 4,680 seconds. Its Ansible phase ends after 4,500 seconds.

That holds the turn's own bound of 3,600 seconds and the 30-second kill grace. It holds eight T3 or helper calls of up to a minute each:

- the settle's list, and its stops of up to two earlier runs, one in the implementer's thread and one in the reviewer's;
- the list of a continued turn;
- the question's read and stop, or a cut run's list and stop;
- the list and show after the turn.

It also holds sixteen Git commands of up to 20 seconds each, for the settle's restore and a reviewer's set-up and restore, and the SSH set-up. No run has finished yet; a maintainer must replace it with the longest run times 1.5 once the history holds turns.

`proof.run` has no execution budget of its own: its children and the watchdog end it. Its execution duration starts at admission; its 7,200-second admission allowance starts at creation. Waiting for the working proof, an earlier parent or a copy place consumes that allowance, and the proof reads `waiting` until admission; expiry fails with `proof.capacity_timeout`. See [proof admission](/cli/proof/run#the-run).

An open Operation of a kind that left the registry, such as the removed `task_vm.image_refresh` and `task_vm.hub_converge`, gets the history budget of its kind: it ends at that budget or on a cancel request ([kinds that left the registry](#kinds-that-left-the-registry)).

`tool.install` and `tool.update` have a provisional 1,500 seconds. A Tool write runs its bounded phases in turn: the [signed repository](/reference/tools#signed-apt-repositories) prepare (180 seconds), the candidate query (120 seconds) and the mutation (900 seconds). Measured brew installs took 471 to 578 seconds. A maintainer must update the provisional row once repository installs have native history.

`fleet.rollout` has a provisional 600 seconds. Its first successes deferred every Node outside the maintenance window in seconds, so a budget derived from them cut off the first real visit at three minutes. One visit of seven Nodes, four of them converged, took 187 seconds; a whole fleet converging at that pace, times 1.5, rounds up to 600. Since 2026-11-18 the rollout visits every Node right after a release, so a maintainer must update the provisional row once those visits have history.

`fleet.reboot` has a provisional 10,800 seconds, the length of its window: a pass never starts a Node it cannot drain and reboot before the window closes. A maintainer must update the provisional row once passes have history.

`gateway.release` has a provisional 3,600 seconds. The legacy runner's last 47 verified releases took 117 seconds at p95 and 125 at most. The Horizon drain waits for running jobs up to the longest supervisor timeout plus 30 seconds, 1,730 seconds. Their sum times 1.5 rounds up to 3,000; the busy guard adds up to 600 seconds. Before loading Swamp, the release checks queued, running and waiting Operations covered by the [release idle guard](/reference/gateway-recovery#release-idle-guard) every 5 seconds. It proceeds when idle or refuses with `gateway.release_busy` and the remaining IDs after 10 minutes. Force does not bypass the guard. An expiry [pauses releases](/reference/gateway-recovery#release-as-a-swamp-run). A maintainer must update the fixed row after measuring successful runs.

A `tasks.check` captures its budget at admission in `input.run_budget_seconds`, and the captured number is the Operation's run budget. Every check captures the budget of its kind's history.

The workflows set the captured number as the native `timeoutSec` of each of their SSH steps, so a step against a VM that is gone ends with the Operation.

The measured parts on a fresh VM are the restore from the ready copies, the rsync sync, and `composer install` where lock files differ from the seed (31 to 35 seconds for five). The plain snapshot `base` takes a fraction of a second. 360 seconds covers them with margin. A maintainer should replace the fixed row after measuring passing runs. When the Gateway times out a script of a proof, the proof goes on and cancels its group.

A `proof.command` captures its budget at admission from the batches it proves. A run in a copy proves its batches through the workflow `operation-proof-run-copy`: the commit is brought in (fetch up to 60 s, then the deploy steps whose inputs changed, each with its own timeout), then each batch runs through `proof-fleet-verify`. The Gateway destroys the copy after the run. The checkout and deploy steps come out of the 600-second shard part.

Its budget is 600 seconds for the shard, 180 seconds for each batch and the measured seconds of its cases, times 1.5, rounded up to five minutes, at most 7,200 seconds. The fixed parts come from the shards of proof 14337, which took 996 to 1,909 seconds for 3 to 8 batches. A fixed 3,600 seconds ended a run of 17 batches on one VM half way (proofs 14341 and 14651). A plan that needs a longer shard [waits for another copy place](/cli/proof/run#the-run). When the Gateway ends a run at its budget, the run keeps the verdicts of the batches that ended.

A `proof.command` that older code admitted has no captured budget, for example one admitted while a release switches. It keeps the fixed 3,600 seconds it had then (`OperationBudgetTable::UncapturedSeconds`), never the 60-second fallback of a captured kind.

`proof.verify` has a provisional 3,000 seconds. It has no run yet. `orbit proof` proves one command in the task copy of the named group, through the workflow `proof-fleet-verify`, with no provision and no destruction.

The basis of that budget dates from when `orbit proof` still ran on task VMs: 14 cases of about 120 seconds each come to 1,680 seconds. Add the restores before the first batch, between batches and after the last, about 16 seconds each. Add a token re-mint with its readiness check and the new `base`, about 150 seconds. The sum is about 1,900 seconds. With 1.5 times margin, rounded up to five minutes, it is 3,000. A maintainer should replace the fixed row after measuring successful runs.

`database.import` has a provisional 3,900 seconds: its step's own bound of 3,600 seconds, a 2 GB dump at 1 MB/s, plus 300 seconds for the vault copy, the run start and the result. A maintainer must update the fixed row after measuring successful runs.

`instance.rollback` has a provisional 900 seconds, the legacy rollback deadline and its [chart](/cli/instance/rollback)'s budget. A maintainer must update the fixed row after measuring successful runs.

Otherwise, the Gateway uses the longest successful run of that kind completed in the last 14 days, multiplied by 1.5 and rounded up to a minute, and at least 15 minutes. It measures `started_at` to `finished_at` on succeeded Operations with a `swamp_run_id`. With no successful history, the budget is 30 minutes.

The 15-minute floor is a stop-gap. A quick first success otherwise sets a budget that cancels a later, larger run, as it did for `instance.migrate` at 120 seconds. A hang of such a kind holds one Swamp dispatch slot and its target lock for up to 15 minutes; the Swamp Node has 12 slots: three workers with one slot per CPU, on 4 CPUs. The floor goes when each kind's budget comes from its workflow's declared step timeouts. The reconciler caches each kind’s history budget for one pass.

Overdue reporting records Gateway expiry only; it does not ship the final soft/hard-limit design. A bound Operation that reaches its existing run-budget timeout records `overdue_since` and queues an alert after commit. The timestamp is the existing deadline, not the time the Gateway noticed it. The existing cancellation and `operation.timed_out` result still happen at that same deadline. This is a timeout audit, not an earlier warning while work continues.

Alert delivery uses the [Gateway queue](/reference/gateway-queue)'s `tasks` workers, separate from the cancellation reconciler's `operations` worker. The job creates an `operation.overdue` Activity entry (`operation:overdue-alert`) and attempts the [signed release webhook](/reference/gateway-recovery#webhook). It has one attempt; webhook failure is recorded as `operation.alert_webhook_failed` and is not retried. The expiry marker prevents repeated reads or reconciliation from scheduling another job. Queue failure or worker loss can prevent delivery; this is not an exactly-once delivery guarantee.

Admission and capacity waits do not raise this execution alert. Native history known before Gateway expiry can settle success without an overdue alert. If history is unavailable at expiry, existing recovery can later restore success while retaining `overdue_since` as the Gateway-expiry audit. Native SSH timeouts and the learned 900-second floor are unchanged.

An unbound admission with attempts `0` has a separate 600-second budget, matching Swamp’s default `queueTimeout`, regardless of its kind’s execution budget. It runs from `started_at`, or `created_at` when no start was recorded. The reconciler includes these rows, even when cancellation was already requested.

The [reconciler](/reference/swamp-command-steps#reconciler) projects an expired budget as `failed` with `error_code: operation.timed_out` and records `cancel_requested`. Operation reads return recorded state without checking or applying deadlines. Expiration keeps the native binding, any captured F1 error detail, the failed or active step, and existing result evidence. An Operation admitted with a generic target hold keeps it until the shared reader verifies the exact bound native root is terminal. That receipt releases the hold and records the native status without reopening the timed-out Operation.

Operation shows return recorded state without remote reads, expiry or projection. The Swamp terminal event and the minute sweep queue the same reconciler. It alone reads and records native terminal results. A stuck operations queue delays terminal state; polling does not settle it. Fleet rollout and CLI converge capture their per-Node outcomes from the native aggregation step, including completed visits before a later Node fails. Cancellation or interruption before aggregation retains the native terminal status without inventing per-Node outcomes.

Instance migration settles [deferred Schedule fires](/reference/schedules#checkout-migration) in the transaction that first marks its Operation terminal. It locks the Instance, then queues each fire against the recorded runtime or explicitly fails it if admission remains closed.

An `instance.create` or `instance.complete` moves the task that owns its Instance, a [task copy](/reference/tasks#task-copy), in the same transaction that first marks it terminal. So a run's report, a start that Swamp refuses and a cancel before the run all count.

An `instance.deploy` deletes its own [execution copies](/reference/deployments#execution-copies) after the transaction that first marks it ended commits. A run's report, a start that Swamp refuses, a cancel before the run, an unreadable result and the settlement of a timed-out run all count. A timed-out deploy keeps them while its run may still read them: until the settlement, or, for a deploy that holds no target, until Swamp confirms its cancel.

Success makes the task run only when the copy is `active`, so a clone's create leaves it `reserved` and its completion starts it. A failed create fails the task. A failed completion keeps the copy and leaves the task `reserved` with an assistance request ([a failed completion](/reference/tasks#a-failed-completion)). An expired budget leaves the task `reserved`, because its run may still be stopping. An Operation on an Instance that no task owns changes no task.

The submission boundary records whether a native start was sent or explicitly refused; proven no-start outcomes, including failed execution-copy preparation, free a new runtime reservation.

A deleted Instance never prevents the Operation from becoming terminal; its waiting Schedule fires fail with `schedule.target_unavailable`, even if their Schedule was also deleted. An unverified runtime keeps its reservation; a projected rollback removes it. Expiry settles fires immediately without changing the migration’s timeout result. Migration-specific late-result recovery can update that result as described below; later receipts, stale hold release and recovery cannot replay a settled fire.

A run can beat its expiry. For example, Swamp's start reply arrives after the 600-second admission budget, so the Operation expires unbound, and the run then ends within its kind's budget. When the exact native terminal finished before the bound deadline, the shared reader reopens the Operation and projects that terminal normally. The `timed_out` mark and its cancel request go, and the records reach their real end state. A terminal at or after the deadline keeps the timeout.

`instance.migrate` has a 5,700-second run budget and its own late settlement. Its declared workflow timeouts total 4,830 seconds, including the 150-second Node preparation phase, restoration and cleanup. Rounding up to five minutes gives 5,100 seconds; admission and settlement add 600 seconds. After expiry or confirmed cancellation, the reconciler reads its late native finish, including after the target hold was released. Shows and admissions make no such read. The native finish decides `moved`, `restored` and `ready`.

A deadline-triggered abort remains `failed` with `operation.timed_out`; a user abort remains `cancelled`. An independent phase failure keeps its own code. Orbit chooses the cause from native history and the locked expiry audit. A late success remains a success. Runtime changes apply only while that migrate Operation still owns the Instance's reservation. Private vault reads precede the write transaction. A new migrate can take ownership of an already-settled stale reservation. A result that names missing rollback or cutover evidence instead requires owner recovery. Other kinds keep the expiry behavior above.

A missing-output migrate keeps its verified root status in `result.native_status`, including cancellation. Known failed-step diagnostics keep their shared redaction. The Operation still uses the named unavailable-result failure.

A migrate with missing finish output gets 60 seconds from the verified native end, capped by its original run deadline. A normal late receipt keeps its normal result. After the grace, proven rollback releases the owned runtime reservation and records `instance.migrate_result_unavailable`.

A completed move or uncertain rollback stays pending until the deadline. Missing output then ends with the same code and keeps the reservation and Instance runtime. The result names missing evidence and owner recovery. Another migrate refuses with `instance.migrate_recovery_required`. No Orbit command repairs that evidence gap. The full 60-minute target hold still applies when the native end itself is unproven.

The shared reader projects a native kind from its declared end-check step. A kind whose action runs as one of several guarded steps names the others as `alternatives`, and the step that ran is the end check; see [Swamp command steps](/reference/swamp-command-steps#read-swamps-run-data-instead-of-a-result-step). A kind with a result-reporting step may name one `fallback` step of the same job, whose stdout is the result only when the reporting step was skipped.

A projection that implements `DirectOperationTimeout` records its documented failure state in the expiry transaction, because the normal terminal projection runs for a timed-out Operation only when its run beat the expiry. It does the same, with the Operation's own error code, when the Operation fails with `operation.result_unreadable`. Node removal, `tasks.cancel` and `tasks.maintenance_finish` use it to mark the departing Node `failed`; the native run may still finish, and a later admission recovers the records.

`tasks.cancel` and `tasks.maintenance_finish` hold the generic Operation target key (`task_group:<id>`). Expiry keeps that key: a new admission returns `operation.target_busy` until the shared reader verifies the exact bound native terminal and releases it. Settlement leaves the timed-out Operation closed. A native `interrupted` cleanup result projects as `failed`; recovery starts a new Operation.

The hold has a limit. When nothing proves the end of the run within 60 minutes of the expiry, the minute reconcile job frees the target and records `native_terminal_status: unproven`. A run that was asked to cancel an hour ago and never reported is lost, and a lost run must not hold a group or a VM for ever.

A Conn launch keeps its owned child hold while native settlement is unproven. The reconciler still looks up the exact run or verifies that no run started. This protects the next dispatch, retry, or teardown from work that could still act.

For an unbound timed-out admission, the existing exact lookup by Operation identity releases the hold only when Serve proves there is no matching run. A matching run binds without reopening the timeout; ambiguity or an unavailable lookup keeps the hold. A valid native terminal with `completedAt` at or before the deadline wins in the shared history read, even when its projection arrives late, so it causes no timeout or timeout alert.

This is the Gateway's budget result, not proof that the native run or its descendants stopped. At expiry the Gateway records `state.native_cancel_requested_at` once. The reconciler asks Swamp to cancel the bound run, as an operator cancel does, and records `state.native_cancel_confirmed_at` when Swamp confirms. Swamp stops the run; the Gateway only forwards the request, and each pass asks again until Swamp confirms. An Operation with no bound run has nothing to cancel.

The request reads the Operation under its row lock and changes only its own keys, so step progress and a failure that land while a caller holds an older copy stay recorded.

The request is a `workflow.cancel` message on the Gateway's run token. Serve authorizes it with the `run` action on the run's workflow, which the Gateway already holds to start the run. Serve's HTTP cancel endpoint needs `admin` on `access:*`, the superuser right, so the Gateway does not use it.

The SSH step limits above end the steps of a run whose cancel did not arrive. Native Serve ceilings, step queue limits and phase command limits remain separate controls. The reconciler checks deadlines on every pass, on the operations queue. The reconciler arbitrates bound native terminal history before expiry; unbound admission expiry needs no native read.

## Native failure detail and live steps

The shared observer projects live step names from native run events without reading history. `current_step_started_at` is the native timestamp when the event supplies one; it stays null when the runtime omits it. `current_step_observed_at` records when the Gateway observed that event. Replayed progress cannot replace a newer event.

A failed or interrupted root retains its first causal step as `failed_step` and a bounded safe diagnostic in `error.detail`. A kind's projection maps that step to its chart step name, a `forEach` iteration such as `Probe the site over TLS-0` included, and records the chart step's error code. Recorded stderr is preferred; otherwise the native step error is retained. `error.stderr_recorded` distinguishes recorded stderr from missing stderr, including worker disconnection or dispatch refusal. Stdout is never failure evidence.

When a kind's workflow has no result-reporting step, the kind declares its terminal result as `native`. Its projector then reads Swamp's own run data keyed by step name ([Swamp command steps](/reference/swamp-command-steps#read-swamps-run-data-instead-of-a-result-step)). `node.converge` uses each native step outcome to record the footprint parts that succeeded, including the network part and its handshake assertion. A `node.converge` phase that its guard skipped because its digest did not change is a `skipped` step in `steps`, and `unchanged` in `result.phases` ([phase digest rule](/reference/node-provisioning#skip-a-phase-whose-input-did-not-change)). An `unchanged` phase counts as passed, so the state that its last run recorded stays.

A terminal result declaration can also name `data`, the data behavior of its terminal step. When Swamp does not inline a large step output in the run history, the projector reads the step's `output` data artifact from that behavior ([Swamp command steps](/reference/swamp-command-steps#scheduled-observations)). A terminal step that succeeded wrote that output, also in a failed run. When the projector cannot read it, it reads the run again on the next pass, until the Operation's run budget ends.

The reconciler uses the full terminal reader for an interrupted `proof.command`, including completed batch reports, before closing the Operation. This preserves completed case failures without a status read; [proof outcomes](/cli/proof/run#verdicts) keep interruption inconclusive.

A failed step with exit 124 and a command beginning with a literal `timeout N` reports `<step> timed out after <N>s`; other failures keep their redacted diagnostic. The shared projector and the existing exact history read use the same diagnostic extractor. A show keeps an older closed failure as recorded; it does not backfill missing detail.

The shared extractor redacts known Instance and Process environment values, configured Swamp credentials and credential-shaped text before retaining the last 4 KiB. It drops a truncated first line whose identifying prefix may be missing, then redacts the retained text again. Budget expiry omits `error.stderr_recorded`, because that result makes no claim about native stderr. Native family scripts still own safe output preservation; this projection does not change their placement or execution.

## Native public participants

A direct Operation may admit immutable `input.participant_node_ids`. A foreign participant may use the existing direct-operation GET with the bound root run ID while the parent is unfinished. A projection implementing `DirectOperationPublicView` asserts its participant ownership and returns fresh public input for that authenticated Node. The response preserves the parent Node identity, omits the parent result and uses `Cache-Control: no-store`. Participation grants neither a result POST nor private-material access; those have their own shared authorization.

## The record

The optional `steps` list uses typed `OperationStep` metadata. Start responses can omit it; show and cancel responses include it. List rows never carry `steps`, `input` or `result`; [Page the list](#page-the-list) says why. The record repeats one result field, `ready_at`, so one history request returns every wake's readiness time.

`operation:show`, `operation:cancel` and `task:cancel` return a summary of `input`: top-level scalars and strings of at most 512 bytes. Nested execution plans, scripts, SSH configuration and vault references stay in the stored Operation. The workflow still receives its full admitted intent. Empty summaries serialize as `[]`. Domain results remain available. `steps` contains only recorded names, jobs, statuses and times, with no nested payload or command output.

The stored column `executor_result` holds the first matching result from the [executor](/reference/executor#the-listener-and-the-hand-off) over WebSocket or the Gateway's SSH relay. The public record does not carry that raw result. For signed power, `executor_accepted_us` stores when the Gateway first saw WebSocket acceptance; it does not prove the action succeeded.

Every operation has the same fields, whatever its kind.

| Field | Contract |
| --- | --- |
| `id` | The operation ID |
| `kind` | What runs, `<family>.<verb>`, such as `instance.deploy` |
| `target_type`, `target_id` | The record the operation changes, such as `instance` and `15` |
| `input` | Validated operation input. Deploys may include `commit`; automatic admission also binds `caller_node_id`. Rollbacks include `release` |
| `status` | `queued`, `running`, `waiting`, `succeeded`, `failed`, `cancelled`, or `interrupted`. The last four are terminal and never change again |
| `sequence` | A number that grows with every change of the operation or one of its steps |
| `current_step` | The name of the running or waiting step, or null |
| `error` | Optional `{code, detail}` with the retained safe native diagnostic or the budget explanation |
| `failed_step`, `error_code` | For a failed operation, the step that ended it and its error code. `failed_step` is null when the operation failed outside a step |
| `result` | What the operation produced, or null until it ends. A terminal failure can have a typed result; non-null does not mean success |
| `cancel_requested` | Whether someone asked the operation to stop |
| `attempts` | How many times a worker started the operation |
| `triggered_by` | The manual caller's Node name, or an [automatic-deploy origin](/reference/deployments#history) |
| `caller_node_id` | The bound automatic caller's Node ID, or null. Also retained in `input.caller_node_id` |
| `caller_node_name` | Current name of the caller Node, or null if there is no caller or the Node has been removed |
| `created_at`, `started_at`, `finished_at` | ISO 8601 times. `started_at` is the first start |
| `poll_after_seconds` | How long a polling client waits before its next read, or null once terminal. [Follow an operation by polling](#follow-an-operation-by-polling) gives the values |
| `hold_wait_reason` | For a Route write or a removal that waits in the Gateway: why, as [One Route write per Node](#one-route-write-per-node) lists. Null otherwise |
| `ready_at` | For an `instance.wake`: its `result.ready_at`, the Node's UTC time when the website became ready ([Results](/reference/prepared-restore#results)). Null otherwise |

A cancelled operation has an `error_code` only when a rollback superseded it: `operation.superseded`.

Cancelling an open [executor diagnostic](/reference/executor#the-proof-operation) settles its Operation. A reply to an order already sent can still be recorded as evidence.

`operation:show` and cancellation replies include observed native `steps`, in event order:

| Field | Contract |
| --- | --- |
| `name`, `job` | Native step and job labels |
| `status` | `running`, `succeeded`, `failed` or `skipped` |
| `started_at`, `finished_at` | Recorded ISO 8601 times, or null |

These summaries come from the Gateway's recorded events, without another native history read. Older Operations or a queued run can have no recorded steps. The summary does not claim that unobserved steps completed.

`result` depends on the kind. For `instance.deploy` and `instance.rollback` it is `{ deployment_id, release, selected_release, commit }`, where `deployment_id` is the [deployment history row](/reference/deployments#history) that holds the full log.

### Operation versions across a Gateway release

Orbit pins an Operation version when it admits the request. Existing records use version 1. Each kind selects its current version in `apps/gateway/config/operation_versions.php`. New admissions use that version. A queued, running or waiting Operation keeps its original workflow, private inputs and result handler.

Swamp records a numeric `version` on a workflow. A child step selects its workflow by name or UUID at execution. A resume looks up the registered definition again. Publish incompatible definitions under separate names and UUIDs, including child workflows they call. Keep their grants, behaviors and Gateway handlers available while either version has work. [Swamp workflow schema](https://github.com/swamp-club/swamp/blob/537e03d18f517965c7a0e49b9f5954365f4da70c/src/domain/workflows/workflow.ts#L98-L126) · [Child lookup](https://github.com/swamp-club/swamp/blob/537e03d18f517965c7a0e49b9f5954365f4da70c/src/domain/workflows/execution_service.ts#L7998-L8005)

First deploy version-aware workers with version 1 active. Publish replacement definitions before selecting them for new requests. Put the previous declaration under `retained` while admitted work can still use it. List its version under `deprecated` to close it to new admissions.

Before publishing traffic, the switch uses `--fence-only` to close admissions durably. After cutover and drain, the release runs `php artisan operations:check-cleanup --json` on the serving Gateway. The hook checks the SQLite ledger and reports the outstanding count for each closed version. Exit 75 means work remains. Run the hook again before removing a definition and its handlers in a cleanup release. Use the individual version’s `eligible` field. Exit 75 can include versions whose tracked work has already settled.

After a container handover is confirmed and the former live drained, the Swamp workflow `gateway-operation-cleanup` runs `operations:check-cleanup --release-operation` on the serving Gateway. It runs under the release lock with the serving release's code, so no switch can interleave. The Operation must be the handover that retire recorded last, so a replayed receipt of an earlier tenure of the same commit is refused. It records each eligible version and its candidate definitions against the release attempt. The record also names the stopped releases that a rollback could start at that moment.

The same step files one backlog Task in the release repository's Project for each recorded, retained version of an earlier release. Filing happens only there; the scheduler never files. A row files only when all of these hold:

- The inventory lists the live commit as verified.
- The row's release verified, and a later verified release replaced it. A release never files its own rows.
- The serving code neither declares the version current nor keeps it retained and open to new work. A rollback that makes the version current again leaves the record waiting.
- Every stopped release named in the record is gone. The next verified release deletes the stopped old that held the earlier code.
- Every stopped release that a rollback can start now has its own record for the version. That record shows it closed the version too.

A stopped release that reverted the deprecation therefore keeps the record waiting. A row's stopped-release list must be a JSON list of full commits; any other value fails that row alone.

Two failed releases in a row keep the earlier releases stopped and file nothing. If Tasks is disabled, the Project is missing, the Project reads its repository through the GitHub CLI or a row fails, the row stays unclaimed and the next verified release's step retries it. A failing row fails only itself. Recorded candidates keep their original release commit across later cutovers. Work that drains after a release's step is recorded by the next release's step.

The switch fences admissions on the release about to serve before it publishes. After that, only the serving release writes the fence from a check. On a release container, which its runtime marks with `ORBIT_GATEWAY_CONTAINER=1`, the host inventory must be readable and name this checkout's commit as `live`. A missing or unreadable inventory gives no write authority. A host Gateway has no marker and always writes. An inventory with `"verified": null` is unreadable; only an inventory without the field trusts live and old.

The switch and `--recover` run `operations:repair-fence` on the new live right after they publish it. On the serving release it rewrites the fence from that release's policy, so a version that an outgoing release closed again between the next release's fence and its publication reopens at once. A failed repair never fails the switch.

As a fallback the scheduler runs the same repair every five minutes, inline and not on the Gateway queue, with or without the Tasks extension. On a release container the scheduled repair first takes the host's release lock without waiting. The lock file is in the state directory that every release container mounts. A switch holds it from before the candidate fences until it has published, so the tick skips while a switch runs.

The switch also writes a handover marker, `gateway-handover`, in that directory before it fences. Only a step that has written the inventory naming the candidate as live removes it: the switch or `--recover`. A switch that created it and fails before routing removes it too; a marker that a routed switch left survives a failed `--recover`. The tick skips while it exists, also after a crash between routing and the inventory write.

A rollback target from before cleanup Tasks has no repair: after an offline rollback to it, run `php artisan operations:check-cleanup` once on that release, or the next release reopens the version.

The Task names the serving commit, retained workflow and Gateway handlers. Before removing code, run the cleanup check again and check current and retained Operations, nested Swamp callers, and schedules for shared definitions. Preserve the admission fence. Closing the Task does not authorize a deletion or cause another Task to be filed. A later verified release records its commit against the Task when the version mapping has been removed.

### Record coordination and native children

`DirectOperation` is the active writer for native work. `OperationKinds::supports()` reports `secret_outputs`, not the old child receipt and completion capabilities. The previous executor's worker callbacks, claims, leases and child polling routes are not an admission or recovery interface.

An Operation that coordinates records need not bind a Swamp run of its own. For example, `ProofRunSettlement` locks the `proof.run` parent, reads its exact child Operations and writes the aggregate through `OperationStore`. A child result can prompt settlement after commit. `ProofRunWatch` uses the existing Operations reconciliation pass to recover a missed signal. The parent does not poll a worker, hold a Swamp slot or perform machine actions. A `DirectOperationChildProjection` is a different seam: it projects evidence from a declared native child workflow inside an open direct parent. It does not admit a separate Instance Operation.

For a [Conn launch](/reference/conn#durable-launches-v1), advance only stored facts, safe receipts and native admissions under the parent row lock. Use the owning admission and projection of each child. In that transaction, record each child's exact key, ID, kind, target and ownership before reading its outcome. Start admitted machine work after commit through the existing native path. A child keeps its own Instance or Node hold, not a second parent hold on the same target. Observe a joined child without claiming it or changing its lifecycle.

Use one repeatable Conn settlement in the existing `ReconcileSwampOperations` pass, beside the proof watcher, to recover missed admissions, results and cleanup. Native results and other terminal paths can request that same settlement after commit. Do not lock the parent synchronously from inside a child's terminal transaction. Terminal writes can come from native projection, start refusal, cancel-before-start or timeout; none proves by itself that a partial machine effect is gone. Read/control can consume the same stored facts without waiting for an RPC. Do not add a queue, a worker callback or another polling loop.

The launch pins the versions of its inspect, dispatch and owned Instance work at admission. A shared wake or an ordinary completion that it joins keeps its own version and owner. A published launch with an application retry already recorded retains its completion handler until the retry settles. Once cleanup marks that version eligible for removal, the published launch refuses a new retry that needs it.

For an ordinary clone, `DevelopmentInstanceCreateProjection` releases create's Instance hold and admits completion in the same transaction. A launch-owned create must not admit a completion that bypasses the launch's cancel fence. Use the same completion admission under the launch lock; keep the ordinary create handoff unchanged. The private command is bound to its native run through declared private material, not the public parent or child input. The launch's read/control rules also apply wherever generic Operations or events would otherwise expose or cancel its parent.

An owning admission can have a preflight outside its record transaction. Run that preflight outside the launch lock. Before any cleanup side effect, record the exact target, ownership and cleanup key. Recheck these facts when committing the child admission. Do not run create or destroy preflight, an Instance wake transport, or a Swamp RPC inside the launch's row transaction.

### Await from a Gateway caller

A retained internal caller can use `AwaitOperation::execute($operation, $timeoutSeconds = 30)` after admission commits. It refreshes only that Operation and sleeps between reads. The bound must be 1–120 seconds. It returns any terminal Operation, including failed or cancelled; the caller must check its outcome before dependent teardown. At the deadline it throws `operation.await_timeout` with `operation_id` and leaves the pending work unchanged. Prefer returning the Operation with HTTP 202 when the caller can follow it. Never wait inside the transaction that admits or holds the Operation.

### Kinds and targets

Use the kind and target type to start or find the work of a command.

| CLI command | Kind | Target type | Serving Node |
| --- | --- | --- | --- |
| `instance:deploy` | `instance.deploy` | `instance` | Instance Node |
| `instance:rollback` | `instance.rollback` | `instance` | Instance Node |
| `orbit:peer-dns-reconcile` | `fleet.peer_dns` | `peer_dns` | Peer Node |
| `task:start` | `tasks.provision` | `task_group` | Gateway |
| `POST tasks/{group}/sync` | `tasks.sync` | `task_group` | Gateway |
| `task:check` | `tasks.check` | `task_group` | Gateway |
| `task:turn` in a [task copy](/reference/tasks#task-copy) | `tasks.copy_turn` | `task_group` | Gateway |
| `task:publish` | `tasks.publish` | `task_group` | Gateway |
| `POST tasks/{group}/pr-ready` | `tasks.pr_ready` | `task_group` | Gateway |
| `POST tasks/{group}/pr-merge` | `tasks.pr_merge` | `task_group` | Gateway |
| `POST projects/{project}/pr-merge` | `project.pr_merge` | `project` | Gateway |

Every task operation holds the group, so different kinds cannot overlap on it. A task VM Node exists only while the group runs, so the Gateway serves the group's operations and their reads. Provision returns `{ instance_id, node_id, starting_commit }` on success. A turn returns its receipt, thread state, workspace evidence, and token metrics; [Run an external turn](/reference/tasks#run-an-external-turn) defines the result and refusals.

A check returns `{ outcome, deliverables, check_id, commit, head, tree, output, ci }` under the [hosted handoff CI contract](/reference/tasks#check-an-external-group). Swamp owns review and merge policy. The input remains `{ subtask, phase }` with the existing optional key.

`head` is the pre-commit check snapshot; `commit` is the candidate HEAD. Baseline/no-policy has `ci: null`. A required-CI handoff adds persisted handoff-push and hosted-CI-read steps after the existing commit step. Before push, state pins the branch and required check name; it retains CI evidence while waiting, so recovery cannot reinterpret a later Project edit. The terminal result carries that evidence. A failed code/workflow check ends `succeeded` with `outcome: failed`, not an operation failure. Consumers need `outcome: passed` and the shared checked-candidate eligibility rule, not just `status: succeeded` or a locally passed check.

### Kinds that left the registry

A kind that leaves the registry keeps its old Operations as history. `operation:list`, `operation:show` and the web views show them with their stored kind, status and result; no projection class is needed to read them.

The reconcile pass reads no Swamp run for them. An open one ends on a cancel request (`cancelled`), or as `failed` with `operation.timed_out` at the history budget of its kind ([Native run budgets](#native-run-budgets)). The release's idle guard waits only for the kind families it names (Instance lifecycle, deploy, Conn and Process kinds). The task VM kinds `task_vm.image_refresh`, `task_vm.hub_converge`, `task_vm.resume` and `task_vm.slot_release` left the registry this way, and so did the [Pi task loop](/reference/old-paths#the-pi-task-loop) kinds `tasks.prep`, `tasks.prep_fix`, `tasks.build`, `tasks.review_round`, `tasks.maintenance`, `tasks.package`, `tasks.script`, `tasks.idle_save` and `tasks.turn`.

## Start an operation

After the access and idempotency checks, a start request validates the input, creates the record as `queued`, and answers `202` with the record. After the record commits, the Gateway submits one native workflow for it ([Direct Swamp starts](#direct-swamp-starts)):

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

When the Gateway cannot confirm the start, it answers `swamp.start_uncertain` and the operation stays running ([Direct Swamp starts](#direct-swamp-starts)).

### Retry a start with a key

Every route that starts an operation accepts an `Idempotency-Key` header. Most routes make it optional. Today these are `POST /api/v1/instances/{instance}/deploy` (`instance.deploy`), `POST /api/v1/instances/{instance}/rollback` (`instance.rollback`), `POST /api/v1/tasks/{task}/start` (`tasks.provision`), `POST /api/v1/tasks/{task}/check` (`tasks.check`), `POST /api/v1/instances/{instance}/deploy/automatic` (`instance.deploy`), and `POST /api/v1/tasks/{task}/turn` (`tasks.copy_turn`; a retry keeps the fingerprint it had when the route's kind was `tasks.turn`). The automatic deploy and worker start APIs require a key. The worker API excludes its enrollment secret from the fingerprint. A key has 1 to 128 characters from `A-Z a-z 0-9 . _ : -`. Keys are case-sensitive and scoped to the operation kind, across all calling Nodes.

One read that may start an operation takes no key: `GET /api/v1/conn/environments/{environment}/session` (`conn.session_hold`, MCP `conn-environment-session`). It ignores the header, because a read must answer the current state, not replay an earlier `202`. Its open Operation dedupes concurrent first reads ([Held sessions](/reference/conn#attach)).

Automatic deploy starts bind caller and command as well as target and input. The [automatic deploy start](/reference/deployments#pinned-commits-and-automatic-callers) requires a key and Gateway Node access. Manual deploys and other kinds retain their key scope across callers.

After checking the caller's access, the Gateway checks the key before further input or domain validation and before creating a target. The same key, target, and input return `202` with the existing operation, even when it has finished or failed to dispatch. A different target or input returns `409 operation.idempotency_conflict` and starts nothing. JSON object member order and whitespace do not change the input; array order and value types do. A malformed key returns `422 validation.failed` in the normal validation envelope, with `Idempotency-Key` in `error.details`.

A caller that starts operations without a request keys each admission itself. `OperationIdempotency::under(kind, key, fingerprint, callback)` runs the callback under that key and puts the request's key back afterwards, also when the callback throws. The pull-request gate, a proof, project maintenance and task maintenance use it, and so does the HTTP boundary for the header. `OperationIdempotency::suspended(callback)` runs a callback with no key and puts the request's key back: an operation that the request did not ask for, such as the resume of a parked task VM, takes none.

Concurrent starts with one new key create one operation and start one workflow. The operation stores the key, and a unique index on kind and key settles competing inserts. A request without a key starts a new operation under the usual target-hold rule.

The CLI commands `instance:deploy`, `instance:rollback`, `task:start`, `task:check`, and `task:turn` pass the header with `--idempotency-key=KEY`. The PHP SDK requests accept `idempotencyKey`. Each MCP tool that starts an operation takes the `Idempotency-Key` argument (required where the API requires it), generated from the OpenAPI header.

Keyed operations are never pruned, so their keys never expire. Operations currently keep every record. When ADR 0218 adds retention for finished operations, it must exclude every operation that carries a key. The [WebSocket app routes](/reference/websocket-apps) keep their own Node-scoped idempotency rule.

## Read an operation

Four requests read and stop operations.

| Request | Result |
| --- | --- |
| `GET /api/v1/operations/{operation}` | The record with every step |
| `GET /api/v1/operations/{operation}?after_sequence=N` | The record with only the steps whose `sequence` is greater than `N` |
| `GET /api/v1/operations` | The newest records, 20 to a page, without `input`, `result` or `steps`; a wake's row carries `ready_at`. [Filter the list](#filter-the-list) with `status`, `kind`, `target_type`, and `target_id`, and [page it](#page-the-list) with `limit` and `cursor` |
| `POST /api/v1/operations/{operation}/cancel` | Asks the operation to stop and returns the record |

The list selects operations and caller Node names in one query. Show uses the bound operation and reads its steps in one query. Neither waits for the operation. A read needs access to the Node that serves the target, such as the Instance's Node. The list returns only operations on Nodes that the caller can access; the Gateway Node and a Node with an access grant to the Gateway see all of them.

The CLI reads with `operation:show`, `operation:list`, and `operation:cancel` ([operation](/cli/operation)). MCP has the tools `operation-show`, `operation-list`, and `operation-cancel` ([MCP: Operations](/reference/mcp#operations)).

Commands that follow an Operation print each string in its terminal `result.warnings` as a warning before the Operation and request IDs. `--json` retains the list in the final Operation.

### Filter the list

`GET /api/v1/operations` takes four optional query filters. A record must match each filter that the request sends.

| Filter | Contract |
| --- | --- |
| `status` | `queued`, `running`, `waiting`, `succeeded`, `failed`, `cancelled`, or `interrupted` |
| `kind` | Two names joined by `.`, `<family>.<verb>`, such as `instance.deploy`. At most 64 characters |
| `target_type` | One name, such as `instance`. At most 32 characters |
| `target_id` | A positive integer |

Each name starts with a lower-case letter and holds only lower-case letters, digits, and `_`.

The Gateway checks the form of `kind` and `target_type`, not whether a kind exists. A well-formed value that no operation has returns an empty list. A value in another form answers `422` with `validation.failed`.

### Page the list

`GET /api/v1/operations` returns at most `limit` records, newest first. `limit` is 1 to 100; the default is 20. `meta.next_cursor` names the next page, or is null on the last page. Send it back as `cursor` with the same filters to read the next page. A cursor is an opaque string; one in another form answers `422` with `validation.failed`. A page is not a snapshot: an Operation created after the first page appears on no later page.

A list row has every field of [the record](#the-record) except `input`, `result` and `steps`. A stored input holds the whole admitted intent and a result can hold up to 256 KiB, so a page of full records can pass what an MCP client accepts in one reply. The list does not read the stored input or result either: it reads only the caller Node from the input. Read one Operation with `operation:show` to get its input summary, result and steps.

### Follow an operation by polling

A client that has no Reverb connection, such as an MCP agent, polls:

1. Start the operation and keep its `id` and `sequence`.
2. Wait `poll_after_seconds`.
3. Read it with `after_sequence` set to the last `sequence` you have. Apply the steps it returns.
4. Repeat until `status` is terminal. Then read `result` according to `kind` and `status`; for failure also read `failed_step` and `error_code`. A failed operation's typed result is evidence, not success.

`poll_after_seconds` is 2 while the operation is queued and in the first 10 seconds of its run. It then grows by one second for each 10 seconds of run time, to at most 15. An Operation that has not started, such as a wait for a place that a release before the [task VM removal](/reference/tasks#task-runtime) recorded, gets 2. The Gateway computes it on every read, from the status and `started_at`.

Every read returns the whole record and the steps that changed. So a client that missed reads, or starts again from 0, still ends with the current state.

The CLI prints the id of each Operation it follows before it follows it, on stderr with `--json`. A caller whose follow ended early reads the Operation again with that id ([a follow that ends early](/cli/operation/show#a-follow-that-ends-early)).

### Follow an operation over Reverb

When the [`websocket` role](/reference/events) is active, the Gateway sends `operation.updated` on the private channel `private-operation.{id}` at each change, and on `orbit` at each status change or cancel request. A client:

1. Subscribes to `private-operation.{id}`. `POST /api/v1/broadcasting/auth` signs it for any caller with Gateway access.
2. Reads the operation over HTTP.
3. Applies each event whose `sequence` is greater than the one it has: it reads the operation again with `after_sequence`.
4. Unsubscribes when `status` is terminal.

An `operation.updated` event never carries output. No component publishes deploy step output on this channel now: the agent command that did was removed.

Reverb can lose an event, for example while the socket reconnects, so a client that has no event for a while reads the operation anyway. The web app and the CLI read every 15 seconds while subscribed. [Realtime events: Operation](/reference/events#operation) lists the fields.

## Cancel an operation

`operation:cancel` asks an operation to stop. The Gateway records the request first. An operation that no workflow has started becomes `cancelled` at once and releases its target. Once a native run is bound, the Gateway records the request and answers; it asks Swamp to cancel that run right after the answer, through the same send path as the reconciler, which stays the retry. So the caller reads the Operation still `running`, with `cancel_requested: true`.

The workflow owns the end: the operation becomes `cancelled` when its terminal result says so, and a workflow may finish its running step first. A cancel request for a terminal operation changes nothing and returns the record. A run that has already ended cannot be cancelled: the next reconciler pass reads it and settles the operation from its end, before it sends the cancel, so Swamp is not asked again.

A cancelled container create with its original Instance still retained for retry reports `recovery.state=retained_for_retry`, even before work starts. Its result names the [retry and destroy paths](/cli/instance/create#on-a-container-node); destroy remains unavailable for a container default.

A cancelled deploy or rollback records its history row as `failed` with `deployment.cancelled`. Orbit never undoes the steps that ran.

A cancel does not cut a database copy that already runs. A database fill (`server-database-fill` of `instance.create` and of `instance.complete`, which fills a clone's database) runs on the database server; Swamp's cancel would end only its SSH client, and the copy would run on. So while that step runs, the Gateway records the request and does not send it. The step's end event (succeeded or failed) asks the reconciler for a pass at once; when a pass already runs or waits, the next pass sends it, within about a minute.

The fill ends by itself within its 600-second limit. A step that still shows `running` 60 seconds past that limit has lost its end event: the Gateway then sends the cancel anyway and logs `operation.cancel_copy_bound_passed`. The window of one hour in which the reconciler forwards a request still counts from the request. An operator's cancel follows the same rule: it cannot cut the copy.

While the cancel waits, the Operation shows `cancel_requested`, keeps its target lock and its Route hold, and other Route writes on those Nodes wait. A cancel that arrives while the run's start is in flight goes on as soon as the run is bound.

A parent that waits for children passes the request to every nonterminal child in the same transaction.

An Operation that the Gateway timed out is terminal, yet its native run may still be going. Cancelling it asks Swamp again to cancel the run. Ending a task group (`task:cancel`) asks Swamp to cancel the runs of the group's timed-out Operations before it destroys the VM, so no run keeps waiting on a VM that is gone.

When a `tasks.publish` has started its push, cancellation keeps the group held until the push records success or failure. See [Publish an external group](/reference/tasks#publish-an-external-group).

Process lifecycle workflows use official cfgmgmt resources and SSH argv. Native assertions enforce the observed runtime and boot state. Docker creation uses one detached run; update removes the existing container before its replacement. Environment files remain root-owned 0600 until destroy. Horizon handoff publishes through systemd apply, chooses graceful termination or start, and checks the enabled unit is active before recording success.

## How an operation runs

Admission records the intent, the idempotency key and the target hold in one transaction. After the commit, the Gateway pushes any execution copies and submits one native workflow for the kind ([Direct Swamp starts](#direct-swamp-starts)). Swamp runs the steps, retries and waits inside that workflow. The Gateway records the observed steps, then projects the workflow's terminal result into the operation and its domain record ([Recover a terminal native result](#recover-a-terminal-native-result)).

When the root run of an operation ends, the event observer queues the projection of that one operation on its own worker, `operations-prompt` ([Gateway queue](/reference/gateway-queue#queues)). It reads the run's history and projects the end at once, with the same code as the minute pass, also while a pass runs.

The minute pass stays the retry: it projects an end that the prompt projection could not read, or that ended while the Gateway was down. The row lock and the terminal check of each projection make sure that an end is projected once, whichever of the two reads it first. The observer still asks for a pass at that end, for the sweeps that only a pass runs. The end of a child run asks for a pass only, which records the child's evidence.

When serve refused a read of the prompt projection with `429`, the job runs once more after serve's `Retry-After` plus one second, so the end does not wait for the next pass. When serve refuses the read of a finished run's step times with `429`, `FillNativeRunStepTimes` reads them once more after the same wait.

A kind listed in `ORBIT_ENGINE_KINDS` runs on the [Gateway engine](/reference/gateway-engine) instead, once every Node it touches supports the kind's primitives. Its steps live in `operation_engine_steps`, its `state.swamp_run_id` is `engine-<id>`, and `operation:show` shows its steps the same way. The engine records its end through the kind's own projection.

Each kind has a run budget ([Native run budgets](#native-run-budgets)). When the budget ends first, the Gateway fails the operation with `operation.timed_out`. The Gateway never reruns a step itself.

A run can succeed while the end check that the kind reads its result from was skipped or failed, for example because a guard skipped it. The run has ended, so no later read can change that. The first read of the run, by the prompt projection or a pass, then fails the operation with `operation.result_unreadable`, frees its target and names the end check as its failed step. It does not wait for the budget.

An end check that is absent or still running reads again in the next pass, because Swamp may not have written it yet. `instance.migrate` keeps its own missing-output recovery instead.

An operation that had already timed out with such a run, within its budget, gets the same end. Whether the work took effect is not known: the kind's records leave their in-progress state as they do at a timeout.

the WebSocket registry tasks (`websocket.sync`, `websocket.app_create`, `websocket.app_destroy` and `websocket.app_rotate`), `proof.run` and the proof-only [`executor.proof`](/reference/executor#the-proof-operation) and `executor.power_proof` are the exception. The Gateway runs or closes them itself, with no Swamp run of their own; `proof.run` closes when its runs have ended, and the executor proofs when their signed-order result reaches their waiter. The power proof targets an Instance and sends sleep or discard; no production command starts it. Each run is an Operation `proof.command` with a Swamp run of its own; its provision, cases and destruction are children of that run.

### One operation per target

An operation holds its target from the start request to its terminal status. A second start for that target answers `409` with `operation.target_busy`. The hold lasts across a lost run and a resume. A rollback is the one exception: it supersedes a deploy of the Instance that no workflow has started, as [One operation at a time](/reference/deployments#one-operation-at-a-time) describes. The hold does not cover work that is not an operation: while it runs, a deploy or rollback also takes the [Instance's operation lock](/reference/deployments#one-operation-at-a-time).

### One Route write per Node

A Route write holds the Nodes that serve its site, from admission to its terminal status ([Coordinate publication](/reference/routes#coordinate-publication)).

A clone's `instance.create` takes its Nodes only when they are all free at admission, and never waits: with one held, it drops its publication and its `instance.complete` publishes and holds them. A clone's create that took them gives them back when its `clone-route` step ends: the run's progress event of that step turns the Route `active` and frees the Nodes in one transaction. An `instance.complete` of a Route that its create published captures no publication and holds no Node.

The kinds are `instance.create`, `instance.complete`, `instance.destroy`, `instance.unregister`, `instance.clone`, `instance.transfer`, `instance.migrate`, `instance.restore`, `route.create`, `route.update`, `route.destroy` and `route.target_set`. A second write for a held Node is admitted with status `waiting` and a `hold_nodes` value in its state. It starts no run and gets no `409`.

`instance.destroy` and `instance.unregister` are removals. A removal shares its Nodes with other removals ([coordinate publication](/reference/routes#coordinate-publication)) and gives them back sooner: when its Route is withdrawn. The step `Release the Route Nodes` runs after `Withdraw the Route` has succeeded and held, and calls `operation:direct:route-release`.

The later steps change only what belongs to the removed Instance: its Process units, teardown steps in its checkout, hibernation files, source, databases, container, ACL, UFW rules by comment, mail state, asleep marker and repository mirror. None writes a Caddyfile, a PHP pool file or a DNS record. The Route is `retiring` with `sites_published` false since admission, so a create or clone that renders after the release leaves out its site and PHP pool.

A removal's container steps take one of 64 names per Node, by its Instance's ID modulo 64 ([Native order](/reference/instance-removal#native-order)). They run after the removal gave its Nodes back, and a removal without a Route holds none. So a removal waits, with `hold_nodes` in its state, while an older removal of the same Instance slot on its Node is still open, or has timed out while Swamp may still run it. The wait ends when that removal ends. While it waits for its slot, it claims no Node: a newer write on its Route Nodes is not held back by it.

A waiting Route write or removal keeps why it waits in `hold_wait_reason`. A read returns it while the write waits:

| Reason | The write waits because |
| --- | --- |
| `nodes_held` | A publisher holds one of its Nodes |
| `waiter_first` | An older write goes first |
| `after_operation` | It starts after the Operation in its `after_operation_id`, such as the clone's completion |
| `removal_cap` | Four removals hold one of its Nodes |
| `removal_slot` | A removal of the same withdrawal slot holds one of its Nodes |
| `instance_slot` | An older removal of the same Instance slot on its Node is open |
| `share_cap` | Eight shared writers hold one of its Nodes |

A refused or failed call does not fail the run; the terminal status frees the Nodes then. The Operation result carries `route_hold_released` and `route_hold_release_status` (the HTTP status, `0` when the Gateway was not reached). A run whose withdrawal did not hold keeps its Nodes to the end. So does a run that an older Gateway release started, whose workflow has no release step.

The release records `route_withdrawn_at` on the removal. A retry of that removal skips `Withdraw the Route` and takes no hold, so it never waits for another Route write.

`instance.register` and the Cluster Router writes hold the same Nodes for one step only. `tasks.provision`, `tasks.cancel` and `tasks.maintenance_finish` hold none: no Operation of the first starts, and the run of the other two publishes no Route. The run calls `operation:direct:route-hold` before its Route-publish step and `operation:direct:route-release` after it. The hold of such a step has a lease of 5 minutes, which the minute pass frees when it ends, and the terminal status of the Operation frees it too.

The hold is the `route_publication_holds` table, with one row per Node. A finished write frees its Nodes and queues `PromoteRouteWrites`, which moves the waiting writes that fit to `queued`, oldest first, and starts them. The minute pass of `ReconcileSwampOperations` frees the Nodes of a write that ended without a release. A waiting write has no deadline; its budget starts when it starts.

A timed-out write keeps its Nodes until Swamp confirms that its run stopped, or for 15 minutes. A timed-out `instance.restore` holds its Instance lock as well, so it keeps its Nodes until its run is proven ended or the 60-minute settlement pass frees the lock.

A Route write whose input names `after_operation_id` waits with status `waiting` until that Operation has ended, then takes its Nodes like any waiting write. A Conn removal of a clone whose completion runs uses it ([Remove a clone whose completion runs](/reference/conn#remove-a-clone-whose-completion-runs)). The end of that Operation starts the promotion, also when it held no Node.

At promotion, a waiting `instance.create`, `instance.complete`, `instance.clone`, `instance.restore` or `route.create` re-captures its Caddy publication from the current site catalog before its run starts; other kinds keep their existing publication behavior.

## Errors

These codes belong to every operation.

| Code | Meaning |
| --- | --- |
| `operation.idempotency_conflict` | The kind already has this key with another target or input; HTTP 409 |
| `validation.failed` | A malformed key or invalid input; HTTP 422 |
| `operation.target_busy` | Another operation holds the target |
| `operation.superseded` | A rollback of the Instance cancelled this deploy before a workflow started it. The status is `cancelled` |
| `operation.timed_out` | The run budget ended before the workflow finished. The status is `failed` |
| `operation.result_unreadable` | The run ended, but its end check did not succeed, so its result cannot be read. The status is `failed`, `failed_step` names the end check, and `error.detail` gives its status |
| `operation.failed` | The kind ended without an error code of its own |

A kind adds its own codes. `instance.deploy` and `instance.rollback` use the [deployment codes](/reference/deployments#failures), such as `deployment.step_failed`.

## Why it works this way

### Publish waits for its existing job

`tasks.publish` uses the approval push job and reads its stored result while waiting. A cancel before the job starts invalidates the queued record; a cancel afterward keeps the group held until the job ends, because stopping a push cannot prove that GitHub did not receive it.

### A task check returns evidence

`tasks.check` holds the group while its detached check runs. A failed check returns a successful operation with a failed outcome so the orchestrator can decide its next step. An unconfirmed stop retains the target and retries, because a cancel request alone does not prove the workspace is free. Group cancellation can end the operation when the VM is unreachable because the group's existing removal rule retains the workspace and requests assistance.

The [hosted-CI prerequisite](/reference/tasks#check-an-external-group) uses bounded reads for pending, missing and unreadable evidence, with no Gateway-owned time budget or next turn. Recovery retains one commit per operation and reconciles the existing VM push record and remote tip. Cancellation can invalidate a queued push, but a started push must reconcile before ownership ends. Once only CI waits, cancel stops observation without cancelling workflows or erasing evidence. The owning operation must succeed with passed CI before either reviewer or publish admission can use its check; cancellation cannot turn a local pass into a completed candidate.

These reasons explain the design. Check them before you propose a change.

### Task operations hold the group and use the Gateway

Provision creates the VM Node, and later cleanup removes it. Serving the operations from the Gateway keeps their access and history independent of that Node's lifetime. Holding the group gives every task effect one shared exclusion rule, including effects on different subtasks.

### The database is the truth

An operation outlives every client and every worker, so its state lives in the database. Events and poll replies only tell a client what changed since its last read. A lost event, a closed socket, or a lost MCP context costs one more read, never a wrong state.

### A key survives the caller and the operation

An orchestrator can lose a response or move to another Node. A key scoped to the kind lets either caller find the same work. The early check lets a replay succeed even when the target's state fails start validation, and prevents a retry from creating another target. A database constraint settles concurrent inserts without a process-local lock. Keeping keyed operations forever prevents a late retry from starting the work again after retention would otherwise remove its key.

### Filters check the form of a kind

The list request accepts any well-formed `kind` and `target_type`. A new kind then changes neither the list request nor its API schema, and a client can filter by a kind before its Gateway runs it.

### Names that map onto MCP Tasks

MCP has an experimental Tasks primitive for long tool calls. An operation already has its parts: the ID, the status, the poll interval, and the get, list, and cancel requests. When `laravel/mcp` supports Tasks, `instance-deploy` and `instance-rollback` can return a task without a new record.

### Tracked task scripts

[Tasks scripts](/reference/tasks#run-a-tracked-repository-script) use `workspace_ready` and `script` steps. The key is required. Replaying the same key precedes admission checks and returns the original receipt and bounded result, including after workspace removal. The existing target hold, lease, delayed reads, cancellation, and uncertain-ownership fences apply. Script completion grants no factory approval, check eligibility, or next-step scheduling.


## Swamp execution

Author native workflows under `swamp/` in this repository. Converted kinds use the shared [direct-start intent and result projection](#direct-swamp-starts), without a generated family artifact or Gateway compiler loader.

A kind declared with `executor: swamp` is admitted through the same Operation store and idempotency boundary. Admission returns `202`. The Gateway queues one PHP starter job after commit to submit the native run. Swamp owns its steps and retries; the Gateway keeps intent, target exclusion, steps, output and the final receipt.

One kind starts differently: the `instance.wake` of a frozen container is recorded without the job, and the Gateway first asks the Instance's Node to resume it ([the Node resumes a frozen container](/reference/development-containers#the-node-resumes-a-frozen-container)). It then ends the Operation itself or queues the starter job; a delayed job 15 seconds later covers a worker lost in between.

### Protected inputs

A run may declare named secret sources for parameter paths. Operation input and Swamp carry only an owning-record reference; the Gateway verifies ownership before hydrating it for the existing transport. Secrets never enter Operation records or responses. Run fingerprints and stored input bind only the unhydrated reference, so a fresh credential mint cannot change replay identity. A null or different owner, or a target type outside the source’s `targetTypes()`, refuses execution. Executor requests preserve empty strings and whitespace for exact input binding.

Each family supplies tagged resolvers for protected values and redaction strings. Script resolvers verify an admitted SHA-256 digest. Registered agent wrappers implement `OperationTransportInput` and delegate native wire conversion before strict schema validation. Optional family execution hooks derive public context, an optional result projection filtered by `result_fields`, and exact inputs, recheck policy before execution, and project redacted receipts in the database. A started invocation without a receipt refuses retries with `operation.execution_uncertain` by default. Only convergent runs explicitly declare `resumable: true`.

## Store secret outputs

A result field declared `{name,secret:true,store:"<family>.<field>"}` is stored through the family's tagged `OperationSecretOutputStore`. The adapter checks the owning record and writes its existing encrypted field. The Gateway checks the target type and owner before dispatch and again when saving the receipt. The encrypted write, family projection and keyed receipt commit together. Retries return the existing receipt without writing the credential again.

Before the family receives a run result, the Gateway replaces each secret output with `{"$secret":"<store>","id":<ownerId>}`. It redacts echoes from other fields. Swamp, Operation state, activity, steps and completion contain only that reference. The family's existing API controls reading the encrypted value. Unknown stores, invalid owners and storage failures return a fixed refusal without credential details. Step and completion requests cannot submit raw values at these paths.

Secret output paths traverse one JSON segment at a time. A literal dotted key cannot replace a nested field. Boolean secret outputs, including booleans inside containers, refuse before storage so public boolean status fields remain usable. A null output stays null in the target receipt: it carries no new secret and does not require recovery of a lost value.

A transport with secret outputs implements `OperationSecretOutputTransport` and keeps its generated values only in memory until the Gateway extracts them. The Gateway transport meets this contract. The agent receives the declared output paths as protected metadata. It stores only a presence marker at each path and redacts echoes before saving its target receipt. The first live reply carries the values for encrypted Gateway storage. If that reply is lost before storage, a presence marker cannot recover the secret: the run stays uncertain and the effect does not repeat. A custom transport must provide the same safe output path before opting in.

`OperationKinds::supports("secret_outputs")` reports support for this enforced contract.

## Direct Swamp starts

Families declare fixed workflows in their own `direct-operations.php`. `DirectOperation` records the per-kind writer choice at admission. `StartSwampRun` submits the committed intent through native `workflow.run` and records its run ID. Every family uses the one `ORBIT_SWAMP_RUN_TOKEN` credential. The immutable Operation ID supplies the stable `orbit_start_key` runtime tag. The connection budget is 15 seconds. Confirmation has a bounded 120-second budget. Serve emits its native `started` event before worker dispatch, so a queued worker does not hold that reply. Measured pre-dispatch setup took 32.7 and 55.3 seconds during the 2026-11-01 canaries; the former 30-second budget expired before either run could be confirmed.

The Gateway keeps admission, record projection and the declared signing primitives.
PHP executor, child-operation and transport adapters that have no loader are
removed with their tests. Native workflow tests cover their replacement.
The Gateway does not load those deleted interfaces.

Synchronous Cluster changes that would move an Analytics tracking Route return
`route.analytics_native_required` before changing records, LAN rules or DNS.
Those changes require the native Route workflow.
Task workspace removal never pushes or wakes a task VM: cancel and complete first
refuse while origin lacks the latest approved commit.

The required CI registry check reads `swamp/config/gateway-workflow-grants.json` for `user:orbit-gateway`. It requires one `run` and `read` grant on every workflow (`*`), and an exact `write` grant only for a declared schedule input producer. Version 2 also requires exact vault key patterns with both `write` and same-key `read`, matched against each kind's public `execution_copies` declaration. CI never applies grants or reads credentials. The swamp role applies this manifest to serve: its convergence and every release Swamp load render it into serve's grants directory and run `swamp access reload` ([ADR 0243](/decisions/0243-serve-swamp-through-a-swamp-role)).

See the [Swamp shape gate](https://github.com/hardimpactdev/orbit/blob/main/swamp/docs/swamp-shape-gate.md#gateway-workflow-grant-intent).

New admission stores `state.swamp_start = {key: 'operation:' + id, workflow: admittedDefinition.workflow}` in the same transaction that creates the Operation, before submission after commit. It preserves unrelated state. A replay or admission fence keeps its recorded row unchanged. This public receipt records the admitted start identity; it does not bind a native run or establish a status. Only native evidence can establish `state.swamp_run_id`.

After the admission commits and its execution copies are acknowledged, `SwampDispatch` queues native starts on the Gateway `operation-starts` queue. Its four workers run starts side by side ([Gateway queue](/reference/gateway-queue#queues)): the status change in `StartSwampRun` admits one submission per Operation, and a target hold keeps a second Operation for the same held target out until the first ends.

Proof children use the spaced sequence below. The API answers `202` with the queued Operation without waiting for native start confirmation. This applies to every admission through the shared dispatcher, including Instance creates and Conn pairings. The job contains only the Operation ID; it reads the committed intent and prepares any run-time execution copies when it runs. Clients follow the same Operation through queued, running and terminal states.

A start whose connection fails is queued again. A start's run-time execution copies and its `workflow.run` share one connection, so this covers both. Nothing was sent on it, and `workflow.run` is the first frame of a start, so no run can exist. An admission copy refused with `429` waits once instead ([Swamp command steps](/reference/swamp-command-steps#secret-inputs)).

A start that serve's admission gate refuses is queued again too. Serve answers `workflow.run` with a `type: error` message whose code is `not_ready` while it boots, or `draining` while it stops. Serve sends it before it saves a run, so no run exists. So a start that meets a serve restart waits instead of failing, unless it is a proof child, its confirmation was cut, or the restart outlasts the budget below.

The Operation returns to `queued` with `state.start_deferrals` counted and `state.start_deferred_since` set at the first deferral. `started_at` stays, so its admission budget keeps counting. Its job runs again after 5, 10, 20 and 40 seconds, then every 65 seconds. A wait is never shorter than serve's `Retry-After` plus one second, and never longer than 65 seconds.

The backoff keeps a long drain from costing a serve handshake every few seconds per waiting start. The new job begins the start under the same lock, so only one job ever submits it.

The deferrals stop 420 seconds after the first one. That covers serve's longest stop (6 minutes) and its boot. Each attempt restarts the 600-second admission clock. While a start waits, its Operation keeps its target hold, so a serve that stays down holds a target for about 7 minutes before the start fails. The next failure then ends the Operation with its own code: `swamp.start_unavailable` or `swamp.vault_sync_unavailable` for a connection, `swamp.start_refused` for a refusal.

Proof children keep their own hold and are not deferred. A failure after the start frame was sent is never queued again: it stays `swamp.start_uncertain`. Any other native refusal fails the Operation with `swamp.start_refused` (see below). A queue submission failure fails it with `swamp.start_enqueue_failed`. A job that fails before submission records `swamp.start_job_failed`. Cancellation or admission expiry before the job runs prevents submission.

The children of one `proof.run` are held until execution copies succeed. Then every start is queued at once: a gap between starts would add to the wall time of the proof. A refusal or definite job failure of one child does not hold its siblings. The stored due time rounds up to a whole second and is the exact timestamp passed to the queue. The parent's row lock keeps two workers from queuing one start twice. Other Operation kinds, including `proof.verify`, keep their normal dispatch.

A child records `state.proof_child_start` with `not_before` and `released_at`. A set `not_before` means its start is queued, so no pass queues it again. A null `released_at` marks a deliberate hold, with no native admission deadline. The delayed job checks the parent before submission: an ended or cancelled parent cancels the child through normal cancellation and group cleanup. Otherwise it records `released_at` and starts the normal 600-second admission budget from that release. The captured execution budget stays unchanged.

A start whose queue write was lost leaves a held child for the proof watchdog. Once the parent exceeds its admission allowance and every child has been quiet for 300 seconds, it fails unstarted children with `proof.admission_interrupted` and cancels their groups.

An uncertain start is never resubmitted. Recovery binds the native run, or the released child's 600-second admission deadline ends it. Its siblings do not wait for it.

The existing reconciler ends an unstarted admission at its 600-second budget with `operation.timed_out`, including a lost job or an unavailable worker. Deliberately held proof children use the proof watchdog until release.

A native refusal is a reply to the start's own message id that arrives before any root event confirms a run. It comes in one of two shapes: a `type: error` message, or a root `type: event` whose `event.kind` is `error` and that names no `runId`. Serve sends the second when it rejects a start whose inputs fail the workflow's schema (`input_validation_failed`) or whose expressions fail to resolve at run start (`workflow_execution_failed`); `type: done` follows it.

The error event is a refusal only when every earlier event of the request was a pre-run one: `validating_inputs`, `superseded_runs` or `evaluating_workflow`. Serve saves a run right before it yields `started`, and drops a `started` event it cannot send. So after any other event, such as a `started` for another workflow or an unknown kind, a run may exist, and the start stays `swamp.start_uncertain`.

Except for the admission gate's `not_ready` and `draining`, which are queued again as above, the Gateway fails the Operation at once with `swamp.start_refused` and releases its target hold. It logs the remote code and message, redacted of the run token. It does not wait out the 120-second confirmation window, which would hold one of the start workers.

If confirmation is lost after submission, the Operation records `swamp.start_uncertain`. The Operation stays running, keeps its target hold and has no invented native binding. Its wait held one start worker only; starts for other targets go on beside it. A timeout does not prove that no run exists. The start path neither searches nor resubmits. A worker failure during submission follows the same uncertain path. Replaying the same idempotent request returns the same Operation and queues no additional work. A repeat without that key still obeys the target hold while the start job waits.

Resolution requires native evidence for the exact workflow, `inputs.operation_id`, `inputs.node_id`, `inputs.kind` and recorded start key. A matching run can supply the missing binding for the existing terminal subscriber. Only authoritative proof that no run started permits a not-started failure and another admission. An unreadable or empty partial response is not that proof. A connection failure before submission fails the Operation once its deferrals have ended; any other explicit native refusal fails it at once.

On every reconciler pass, an unbound running Operation marked `swamp.start_uncertain` receives one native run lookup. The lookup uses its recorded workflow, admitted identity and `orbit_start_key`. Exactly one matching root supplies the binding, then the same pass projects its terminal result. Missing, ambiguous, mismatched or unavailable evidence leaves the Operation and target hold unchanged. The lookup never submits work.

### Execution-copy failures

The Gateway pushes execution secrets through the shared `SwampVaultExecutionCopy` client before queuing a run start. No network call runs inside a database transaction: an admission transaction only records the intent and the Operation. `SwampDispatch` holds the execution copies and run starts until the transaction commits, then pushes every copy and queues the starts. Copies stay in the request because they capture secret values in memory; the queue stores neither those values nor their closures. A rolled-back admission pushes and queues nothing.

A failed push returns `swamp.vault_sync_unavailable` and fails each Operation admitted in that transaction before any start job is queued. Outside a transaction, the push runs at once.

The `Swamp execution copy unavailable` warning records the public vault and key names, native request ID, failure phase, a recognized server refusal code and the HTTP handshake status when available. These fields distinguish connection failures from vault refusals. It records no secret value, token, payload, remote message or original exception message. The client does not retry the write.

### Recover a terminal native result

Operation shows return recorded state. They make no Swamp request. The terminal event and the minute sweep queue the reconciler. It reads each declared terminal result and applies the kind's projection. Fleet rollout and CLI converge have no selector and keep the bounded generic failure probe in that job.

Each completed pass records its end time, duration, and the count and oldest completion time of bound runs proven terminal whose Operations still need settlement. It writes once in the existing collection state, including empty passes and passes with an individual settlement failure. Failed reads, running runs and unbound starts provide no terminal proof. A timeout or a migration settlement wait alone is no proof either.

[`gateway:status`](/cli/gateway/status) exposes these pass facts in `reconcile.finished_at`, `reconcile.last_pass_ms`, and `reconcile.unsettled_count`. A healthy Gateway completes a pass every minute, normally with no unsettled Operations. These facts follow the Gateway Node access rule. Each completed pass also logs `Operation reconcile pass ran.` at info level, with `duration_ms`, `operations` (the number of open Operations read), and `unsettled`. The duration matches the stored value. Empty passes log zero Operations. A logging failure cannot fail the pass. The line contains no inputs, names, or remote text.

[`gateway:status`](/cli/gateway/status) adds two signals on the Gateway Node, under the usual Node access rules. Both use five minutes, allowing five scheduled passes. Before the first recorded pass, the stalled signal requires a registered native Operation in `queued`, `running` or `waiting` state for more than five minutes. With nothing open, a new installation shows no signal:

| Code | Meaning | First action |
| --- | --- | --- |
| `gateway.operation_reconcile_stalled` | The last completed pass is more than five minutes old; `since` is its end, or null before the first pass | Check the scheduler and Horizon Processes, then the `operations` queue and failed jobs. Dispatch alone does not prove that a worker ran the pass |
| `gateway.operation_settlement_stuck` | A pass proved a bound run terminal, but its Operation still needs settlement more than five minutes after that run completed. `since` is the oldest such completion; `count` counts these Operations | Read the Gateway reconciliation warnings and inspect the affected Operation's recorded result and terminal history. Check projection failures before retrying work |

A later completed pass with no ended-but-unsettled runs clears the settlement signal. A deliberate migration settlement window stays separate: only a proven terminal run that remains unsettled is counted. A failed state write logs one warning without failing the pass; its previous timestamp can then raise the stalled signal. No signal records Operation inputs or remote text.

A check waiting for hosted CI is outside this signal: its run ended by design and its Operation stays running. It is counted only when the pass itself failed to project it; a hosted CI result that fails when it is applied later is not seen here. When a pass cannot read whether an Operation settled, it counts that Operation as unsettled and finishes its other work.

The reconciler checks the exact run UUID, workflow name, Operation ID, kind and root Node. Missing or invalid history leaves an in-budget Operation pending. The job enforces its deadline when no terminal result is readable. Already-closed results stay as recorded; there is no backfill. Native terminal failure does not prove that every partial Node effect stopped.

The internal legacy intent GET refuses kinds with full workflow inputs before calling their input builder. Submission already supplies their input through `DirectOperation::workflowInputs`. Only the two Fleet kinds still use the legacy GET; it returns stored Nodes and commit without a Swamp call.

Native runtime tags support submission lookup; they do not provide atomic start deduplication. The recorded run binding remains required before a legacy workflow can read intent or invoke a primitive. The retained shared client makes one read and refuses an unbound run before any action or projection. It has no binding wait, retry, timer or separate deadline. The existing transport owns request timeout and cancellation. New workflows receive the full admitted intent as inputs and use the shared terminal projector; they do not use this client.

A declaration names the workflow, writer configuration path, immutable writer-state field and a typed projection hook. Families add no token, controller or HTTP routes.

A kind whose intents have different shapes may also declare `alternate_workflows`. Its projection then implements `DirectOperationWorkflowChoice` and picks one of the declared workflows for the admitted intent. Admission records the choice once in `state.swamp_start.workflow`. `DirectOperation::workflowFor` returns that recorded name to the start, the lost-start search, the terminal and failure reads, the run subscriber and the reconciler, so an Operation that an older Gateway admitted keeps its workflow.

Swamp resolves every step's inputs when a run starts, so each workflow reads only the keys of its own intent: `tasks.sync` starts `operation-task-sync-copy` for a task copy and `operation-task-sync` for a task VM. Routes retain their existing per-kind settings and `route_publication_writer` field.

Each native kind supplies its workflow and projection in the family's `direct-operations.php` or through a `DirectOperationContribution`. `DirectOperation` resolves the declaration or refuses `operation.kind_unavailable`. It does not fall back to a previous writer.

Cancelling an unstarted queued Operation with no `swamp_run_id` and zero attempts records `cancelled` immediately and releases its target hold, including an existing cancellation request. Once the native start is running, cancellation only records `cancel_requested_at`, even before the run binding arrives: submission may be in flight. The reconciler forwards that request once the run binds. Swamp owns stopping and projecting its terminal result. A terminal Operation stays unchanged. Cancellation does not walk native children.

`firewall.allow`, `firewall.deny` and `firewall.remove` pass the admitted Node rule and shared tuple plan to `operation-firewall-direct`. Every step runs on Node 209. The official firewall model reaches the target over WireGuard after the admitted pin is verified in the published known_hosts snapshot. Doctor's `firewall` family owns the read-only UFW read and comparison. The Gateway projects the native terminal result.

All Tool kinds use direct Swamp execution for root intents on Node 209. Its Gateway command descriptors, completion proofs and package execution policy, manager child admission and receipt adapters are removed. Swamp materializes the admitted manager and applies package policy locally. The Gateway retains typed manager and Tool result projection.

Undeclared or unstamped Operations stay on their previous Swamp path. The helper rejects a PHP writer. Families keep their own authorization, input and result validation; shared projection only records bounded status/results and native run identity. It adds no execution policy.

The optional typed primitive hook is reserved for shared generic Gateway primitives, such as Route/Caddy publication or scoped token minting. New family execution relays are refused; family actions run on their Node worker.

Direct workers authenticate as active WireGuard peers. Direct requests authorize the bound run and immutable participant and field bindings. They require no Gateway access grant. A TaskVM participant receives only its admitted public view and readable private fields, and may store only explicitly writable fields.

Only the root Node may send result, failure or primitive requests. Other Gateway APIs keep their Node access checks. Participation never grants general access to the Gateway or another Node.

Only an authenticated native start response or an exact native history match can bind `swamp_run_id`. Read, result and primitive requests refuse until it is recorded, and cannot bind or replace it. A bound intent returns without another submission. Each family canary proves start-response and first-read ordering. Structured `Swamp direct start` logs retain duration and outcome. Successful starts add [phase timings and admission-to-handler wait](/reference/swamp-command-steps); failures retain their existing phase diagnostics. `Swamp direct start refused` records the workflow and the remote error type, code and bounded message after credential and secret-pattern redaction. Remote diagnostics stay out of Operation results and API errors.

Read input comes only from the projection hook, never automatically from raw intent. Primitive calls accept only the run ID; the hook selects its command and input from admitted state. Existing Route publication URLs reach the same shared controller during the Swamp rollout.

| Shared endpoint | Contract |
| --- | --- |
| `GET /api/v1/direct-operations/{operation}?run_id=UUID` | Safe admitted input, status, result and cancellation request |
| `POST /api/v1/direct-operations/{operation}/result` | Run ID, status, timestamps, bounded result and error code |
| `POST /api/v1/direct-operations/{operation}/primitive` | Optional fixed kind-owned action; run ID only |
| `GET /api/v1/direct-operations/{operation}/private-material?run_id=UUID` | Only the kind-declared private material from existing owned secret sources |
| `POST /api/v1/direct-operations/{operation}/secret-output` | Run ID and declared outputs, written by the existing owned secret stores |
| `POST /api/v1/direct-operations/{operation}/github-token` | Bound run ID and, for a kind with more than one kind of token, the `purpose` (`clone`, `dependencies`, `publish`, `pull_request`, `dependency_merge` or `ci_rerun`); only a declared `github_token_issuer` issues execution copies |
| `POST /api/v1/direct-operations/{operation}/certificates/sign` | Bound run ID and CSR selectors; returns admitted public leaf bundles |

GitHub App tokens are a Gateway primitive: the Gateway keeps the App signing key and mints every token. A kind that declares a `github_token_issuer` gets its token when its step asks. Other kinds get theirs before their run starts. The Gateway mints a token for exactly the repository and permission map the kind names (the `dependencies` token covers the installation, with `contents: read`), puts it in the `orbit-control-inputs` vault, and passes only the vault key name in `execution_secrets`. The workflow declares that vault, reads the token with `vault.get`, and revokes it in a cleanup job.

The Gateway validates the returned permissions and expiry (at most about one hour), then reads `/installation/repositories` with the issued token to prove that it can access exactly the declared repository. A failed scope check, or a failed vault copy, revokes the issued token. Failures log only a fixed phase and HTTP status. They never log tokens or upstream response bodies. The token never enters an Operation, step output, receipt, Activity or shared token cache.

`POST /api/v1/direct-operations/{operation}/github-token` serves only a kind that declares a `github_token_issuer`, such as a [development deploy](/reference/deployments#native-development-deployment), a task provision and its setup steps, a task publish, a pull request step, a dependency merge, and `tasks.check`. A task kind mints when the step that needs the token runs, never at admission: an installation token lives one hour, and a run can take longer to reach its publish step. Any other kind gets `operation.github_token_forbidden` (403). A step that minted a setup token revokes it and reports the outcome in the result as `dependency_token` (`revoked`, `none` or `unrevoked` with a reason), for `tasks.provision`.

### Intent-bound certificate signing

The Gateway alone keeps the Orbit CA private key. A placed Swamp step posts to
`/api/v1/direct-operations/{operation}/certificates/sign` through the existing active
WireGuard peer authentication. The caller must be the Operation's root Node or the
canonical certificate signer, Node 209. The request is:

```json
{
  "run_id": "UUID",
  "requests": [
    {"node_id": 9, "scope": "route-31", "state": "request", "request_base64": "BASE64_CSR_PEM"},
    {"node_id": 12, "scope": "role-websocket", "state": "current"}
  ],
  "restoring": false
}
```

Each selector must match a leaf captured in the original admitted Certificate,
Route, Analytics, Node-role, Node-removal restoration, Cluster-router or Instance
transfer intent. Node, scope, domain and `root_hash` come from that stored intent.
`root_hash` is SHA-256 of the public root PEM bytes, as recorded at admission.
The request cannot supply a domain, root or signing policy. A current leaf returns
no new bundle. Node removal selects its admitted restoration leaves only when
`restoring` is true.

A leaf is signed while its Node is `active`. The Node that a `node.add` adds is also signed while it
is `provisioning`. An old `tasks.provision` signs no leaf: it reads no certificate path, so it is
refused with `certificate.intent_missing`.

For registered `websocket.app_attach` and `websocket.app_detach` parents, signing reads only `websocket_attachment.route_mutations.forward.prepare_certificates` and `websocket_attachment.route_mutations.forward.certificates`. Restoration reads only `websocket_attachment.route_mutations.restore.certificates`. Other containers and the opposite phase grant no authority. Route children carry the actual parent run ID; the Operation and its bound run still scope every request.

The signer verifies the CSR signature, RSA 2048 public key, exact common name and
any requested DNS alternative name. It checks the existing matching RSA 4096 CA
pair and issues a 397-day TLS server leaf with the admitted DNS name, critical
`CA:FALSE` and critical digital-signature/key-encipherment usage. CSR extensions
are not copied. Before returning, it rechecks the live Operation, run binding,
cancellation, admitted leaves and root. Terminal or changed intents refuse.

The private, no-store response contains
`data.certificates: [{node_id, scope, certificate_base64, root_certificate_base64}]`.
Both encoded values are public PEM certificates. Each issued bundle records a
`operation:certificate:sign` Activity containing only Operation/run/caller/leaf
selectors and root hash. Requests and keys are not kept in command Activity input.
Signing adds no Operation, lease, execution step, retry or wait. These two named
root-of-trust endpoints are the only Gateway HTTP primitives for new Swamp steps.

On Swamp, native DAGs, placement, behavior locks, cancellation and recover/resume own execution. Mutating external steps declare `writes: true`. No Gateway claims, heartbeats, child waits, receipts or local retry loop belong in a workflow.

Swamp runs a step that has not written again when its worker is lost, and fails a step that has. A step that changes an external system without writing Swamp data is invisible to the scheduler until it declares `writes: true`. Without the key the second run of a step fails it (`destination path already exists`) or leaves a second token.

A step that cannot run twice must declare it: a `git clone`, a `git worktree add`, a mint, a one-shot API call that is not safe to repeat, an account change, the deletion of a VM. A token revoke stays re-runnable: a second `DELETE installation/token` answers 401, and the revoke steps tolerate it.

Swamp defines the key and what it does. Our rule is the test `swamp/tests/writes_declared_test.ts`. It audits every behavior or model-method step, custom behaviors included, unless its exact type and method appear in `nativeDataMethods` in `swamp/tests/writes-reviewed.json`.

That list holds the methods that only read, or that write only Swamp data, each with its reason. A custom method that writes files, changes a network service or runs a process cannot enter it. Workflow calls, assertions and manual approvals are not audited; child workflows have their own leaf audit.

Every audited step declares `writes: true` or has a step entry in the same file: read-only, idempotent or unreviewed. A check that runs over SSH leaves transport files on the worker, so it is idempotent rather than read-only. The unreviewed count is pinned, so a rise shows in the diff. The audit joins shell continuation lines before checking commands. Commands built from step outputs and effects inside playbooks still need a manual check.

`instance:rename` starts no Operation: the Gateway records the branch from the Node agent's checkout report.

Prove each kind end to end on a dev or canary target. Its takeover PR changes the writer and deletes the replaced Gateway path and execution tests together. Rollback is a revert PR. Admitted Operations retain their writer. Scoped workflow grants and token custody require review before activation; extending one family's run grant to another workflow is not implicit.

### Private material transport

A direct declaration may map `private_material` input paths to registered `OperationSecretSource` names and `secret_outputs` output names to registered `OperationSecretOutputStore` names. The shared endpoints accept no caller-selected source, store, owner or Node. Reads hydrate only those admitted references; writes call the registered store with its existing owner checks and preconditions. They perform no execution and record no receipt.

Declared private fields use the same fixed credential wire serializer as agent commands, including typed values nested inside those fields. A `GitReadEnvironment` becomes `{github_token: string|null}`, and an `AgentGitHubToken` becomes its token string. Arrays, scalar values and null keep their shape. This normalization neither hydrates undeclared input nor changes the caller, owner or native run checks.

Both paths require the recorded native run ID, an unfinished direct Operation, and an active peer with Gateway access whose caller Node equals `Operation.node_id`. The Services peer has no fleet bypass on these paths. Responses use `Cache-Control: no-store`; Activity records no request body, and MCP excludes both endpoints. Private material and output values never enter Operation input, state, results, steps or logs. Swamp consumes them in memory or protected stdin; it must not write them to arguments or resources.

A declared `OperationArtifactSource` may return a bounded existing-file reference: a relative private-read path, SHA-256, byte count and the shared 256 MiB maximum. `GET` on the same private-material path with `artifact=FIELD` serves only that declared artifact field, with the same Node, run and unfinished-Operation checks. It accepts no file path or source from the caller. The Gateway rejects symlinks, oversized files and digest mismatches. The worker bounds the download and verifies the admitted digest before use. No binary is embedded in JSON and no public artifact URL is added.

`DirectOperationResultProjection` supplies the common atomic status/result transaction for extension families. Their fixed `result_hook` validates safe input and domain metadata; the result hook invokes its existing domain completion. Conn’s issue observations update its existing pairing metadata before the registered credential store accepts the output. No family transport or result controller is added.

`instance.connection_environment` is `env:sync` on a container Instance. It holds the Instance lock and has a 180-second run budget around one 150-second phase. Its input and result hold key names only; the values reach the run as the vault copy `instance-environment-<operation>`. See [env:sync on a container Instance](/cli/env/sync#on-a-container-instance).

Development `instance.mail_update` holds the Instance lock and has a 120-second run budget. Its 90-second mail phase leaves room for input, result and cleanup. It returns path and mailer outcomes without environment values; see [`instance:mail:update`](/cli/instance/mail-update).

Development `instance.sleep` and `instance.wake` hold the Instance lock. Their run budgets are 150 and 300 seconds: a 30-second marker phase and a 90-second freeze phase, or a 120-second start phase and a 120-second T3 and marker phase, plus input, result and cleanup. A second wake of the same Instance joins the open one. When the Instance is a clone of a nested-fleet default, the wake admits `instance.start` instead. That kind holds the Instance lock too. Its run budget is 1,200 seconds: a 240-second start phase, a 300-second fleet check and a 600-second fleet start. See [`instance:sleep`](/cli/instance/sleep) and [`instance:wake`](/cli/instance/wake).

Wake admission records a [Conn launch child's shared use](/reference/conn#cancel-retry-and-follow) under the same Operation lock that launch cleanup uses to cancel queued work.

`instance.seed_copy` sends the proof default's seed to each CI Node that is behind it ([The seed on each CI Node](/reference/development-containers#the-seed-on-each-ci-node)). It holds the default's [seed hold](/reference/development-containers#the-seed-hold) `instance_seed:<default id>`, not its Instance lock. A deployment of the default runs beside it, but its seed step waits for the hold, and no copy is admitted while a deployment holds the seed. The copies run one target at a time, so the admission captures the run budget from the number of targets. The budget is 900 seconds for the phases that do not grow with the targets, plus 2,500 seconds for each target's copy step.

The 900 seconds hold the certificate read and the check, which run their targets side by side, the release of the base, the report, and the input, result and cleanup. The copy step allows two pushes of up to 1,200 seconds each, the `timeout` of each `incus` push. A first full copy of the seed pair is a few GB over WireGuard, and a refresh sends only the new snapshot. `ProofSeeds` holds these bounds in one place, and a test checks that the workflow and the playbook use them.

The budget must fit in the Swamp serve run cap, the longest fixed budget. So one run takes at most as many targets as fit beside the fixed phases. The end of the run admits the Nodes it left out.

A [warm proof copy](/cli/proof/run#warm-copies) has no Operation kind of its own. The pool creates it as a task copy: an `instance.create` owned by its own task group. The pool drops it with a discard cancel of that group, which runs `instance.destroy`; a copy whose create still holds it is dropped at a later pass. A proof takes a warm copy in its admission transaction, with no Operation. Each reconcile pass, after it settles the Operations, keeps the pool: it drops the warm copies of an older seed and refills the pool.

Container `instance.node_service_detach` uses one `node-service` Ansible phase with a 90-second execution bound and a 120-second Operation budget. It removes only the detached Instance access, retaining a shared port for other attachments; terminal success removes the record. A checkout detach still completes immediately without Node work.

`instance.complete` finishes the setup of a container clone and makes it `active` ([Complete a clone](/reference/development-containers#complete-a-clone)). The terminal projection of a succeeded clone `instance.create` admits it in its own transaction, after the create row is terminal, and the create's result names it in `completion_operation_id`. It holds the Instance lock and the Route publication Nodes. Its run budget is 1,800 seconds: the 600-second database fill and the 900-second fleet start, with margin; no run has a measured time yet. A failed or cancelled run keeps the clone `source_resolved`; `instance:complete` repeats it.

`instance.unregister` removes Orbit's records of a host checkout Instance and keeps its folder ([Unregister a host checkout](/reference/instance-removal#unregister-a-host-checkout)). Its run withdraws the Route and removes the Processes and hibernation files; on success the Gateway deletes the records as for `instance.destroy`. A failed run keeps the Instance removing; a new `instance:unregister` repeats it.

`instance.restore` returns a host development Instance whose removal failed to `active` ([Restore a failed removal](/reference/instance-removal#restore-a-failed-removal)). A failed run leaves the Instance removing and its failed removal live; a new request repeats the command.
