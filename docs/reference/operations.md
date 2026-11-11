---
title: "Operations"
description: "How the Gateway runs a long operation as a queued record with named steps, how a client follows it, and how the Gateway recovers one that a lost worker interrupted."
covers:
  - apps/gateway/app/{Domain/Operations/**,Actions/Operations/**,Data/Operations/**,Http/Requests/Operations/**}
  - apps/gateway/app/{Http/Controllers/Api/OperationsController.php,Models/Operation*.php,Jobs/Operations/**}
  - apps/gateway/database/migrations/2026_12_03_000000_allow_cold_recipe_image_rebuilds.php
  - apps/gateway/database/migrations/2026_12_28_000000_add_operation_idempotency_keys.php
  - apps/gateway/database/migrations/*route_publication_holds*.php
  - packages/php-sdk/src/{Requests,Responses}/Operations/**
  - apps/cli/app/{Commands/Operations/**,Support/Operations/**,Commands/Concerns/FollowsOperations.php}
  - apps/web/src/{api/operations.ts,realtime/operation.ts,pages/OperationPanel.tsx}
---

# Operations

Swamp is the sole executor of task groups. The Gateway stores task state and runs requested keyed primitives. Operation recovery resumes a primitive without choosing the next task phase. Task VM lifecycle operations use Incus hosts only. Review parking reports stopped power only after provider confirmation; an unconfirmed parked row reports unknown power.

A long operation, such as an [Instance deploy or rollback](/reference/deployments#deploy-through-the-api), is a record in the Gateway database with named steps. Swamp runs it: the Gateway admits the intent, starts one native workflow, and projects the workflow's result into the record ([How an operation runs](#how-an-operation-runs)). The request that starts it answers `202 Accepted` with the record and returns at once. A client then follows the record until it ends. Nothing a client does, such as closing its connection, stops the operation. Only [cancel](#cancel-an-operation) does.

Not every recorded result is an operation. The Gateway records an automatic deploy's Activity entries and release alerts from the terminal result of its scheduled run, with no Operation; see [Instance releases](/reference/deployments).

Instance deploy, rollback, [task provision](/reference/tasks#provision-an-external-group), [task checks](/reference/tasks#check-an-external-group), and [task turns](/reference/tasks#run-an-external-turn) are operations. Publication releases the VM; later task work waits for a fresh task place. Every other kind a command starts runs the same way; [Kinds and targets](#kinds-and-targets) lists the task and deploy kinds.

Production deployments include a `process_restart` step after PHP refresh. It restarts active Instance Processes before after-activation commands. See [Deploy](/reference/deployments#deploy) for the opt-out and failure behavior.

Native terminal projection reads Swamp history and any private result material outside the state transaction. Result writes lock and recheck the admitted run, and each family commits its domain state with its result. Remote cleanup runs after that commit.

## Instance failure alerts

Every `instance.create` or `instance.deploy` Operation that enters `failed` or `interrupted` sends an immediate notification after the state transaction commits. This includes failures before source resolution and automatic deployments. A terminal replay does not send another notification. Cancelled and successful Operations do not alert.

The alert uses the existing [signed release webhook](/reference/gateway-recovery#webhook), with event `instance.operation_failed`. It includes the Operation, Instance and Node IDs, kind, native root run ID, failed step and error code. It does not include inputs, private material, output or stderr. The Activity command `instance:failure-alert` records the notification and webhook outcome. An unavailable webhook does not change the failed Operation. Both release webhook settings must be configured for delivery; otherwise Activity records `not_configured`.

## Swamp datastore watch

The native schedule `swamp-datastore-watch` runs every 15 minutes on the swamp role's workers on Node 209. It reads only: one `command/shell` step counts the files under serve's datastore, `.swamp/` in the serve checkout, and samples serve's CPU over 5 seconds with `top`. It prints one JSON result with the auto-definitions per type, `outputs`, `workflow_runs`, `data`, `step_leases`, `grants` and `serve_cpu_percent`. The step never calls the Gateway.

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

## Swamp credential expiry alerts

The native schedule `swamp-credential-monitor` runs every hour at minute 7 on the swamp role's workers. It is alert only: it reads expiry metadata and changes no credential or grant. Four plain command steps run as `orbit`, with no serve token and no `sudo`, and read these sources:

- `swamp access token list` and `swamp worker token list`, read locally from the serve datastore;
- `swamp auth token list --collective nckrtl`, for club tokens; a token with no `expiresAt` is reported as `no_expiry`;
- `openssl x509 -enddate` for the `swamp.orbit` certificate.

The `check` step, the `credential_report` method of `@nckrtl/node-worker-assets`, classifies their output without reading anything itself. Each credential gets one class: `ok` (more than 72 hours left), `within_72h`, `within_6h`, `expired` or `no_expiry`. Revoked and disabled tokens are skipped. The check stops alerting on a credential one week after it expired. The step publishes only the alerts, at most 200, soonest expiry first.

| Result | Alert reason |
| --- | --- |
| A credential is `within_72h`, `within_6h` or `expired` | The class |
| The `orbit-node-209-access` or `orbit-node-209` token is not listed | `missing` |
| A native list fails, times out or changes shape | The run fails |

The Gateway reads the `alerts` output of the `check` step through the shared scheduled-result reader. It sends event `swamp.credential_expiring` through the [signed release webhook](/reference/gateway-recovery#webhook) with each credential's kind, name, reason, expiry and hours left. Each credential, expiry and reason alerts once after a delivered webhook. While a condition holds, every run reports it again, so an undelivered alert is retried by the next run. A condition that clears and returns alerts again.

If a monitor run does not succeed, the Gateway sends `swamp.credential_monitor_failed` once per failure streak. An undelivered failure alert is sent again by the next failed run. When a run succeeds after a delivered failure alert, the Gateway sends `swamp.credential_monitor_recovered`, again on each run until it is delivered. A webhook that is not configured counts as undelivered. The monitor runs on the worker it watches: when the `orbit-node-209` pair has expired, no worker claims the step, and only the failure alert reports it after `queueTimeout`.

The Gateway keeps the last projected run, the failure streak and the delivered alerts in the Gateway setting `swamp.credential-monitor`. It skips any run that started at or before the last one, so a replay after a reader reset or `optimize:clear` sends nothing. The Activity command `swamp:credential-alert` records each notification and its webhook outcome.

Renewal stays a hand procedure. When an alert names a worker token, follow the [worker enrollment steps](/reference/node-provisioning#per-node-swamp-worker) to issue it again. Then follow the [break-glass recovery steps](/reference/node-provisioning#break-glass-recovery-for-services-and-swamp-209) to restore the worker.

## Native run budgets

Each step in a native Instance workflow has a queue timeout: 10 seconds for create and 30 seconds for setup or destroy. Each child workflow declares its own queue timeout. These queue limits do not change the Operation's run budget.

The Gateway tracks each direct Operation's budget from `started_at`, including native start confirmation. The shared `OperationBudgetTable` reads `instance.create`, `instance.destroy`, `instance.deploy` and `proof.command` from the server-captured immutable `input.run_budget_seconds`. The create admission computes that positive integer from its captured phase plan, including heavy Project setup. The deploy admission sets the frozen deploy step timeouts plus 900 seconds. The Gateway does not re-read the Project.

An explicit kind budget wins and stays fixed until a maintainer changes the table. Long-running kinds use measured or provisional constants; measured rows use the 14-day p95 of successful runs, rounded up to the next five minutes.

For example, `task_vm.image_refresh` has 10,800 seconds and `node.converge` 3,900 seconds. A kind without a successful run gets an explicit budget only from the measured work of a real run. For example, `cluster.router_set` and `cluster.router_unset` have 3,900 seconds. That is 1.5 times the 2,417 seconds of work in their only run, rounded up to five minutes. A maintainer must update the fixed row after measuring successful runs. The create fallback is 60 seconds when no captured phase budget exists. Clients cannot choose the captured budget.

`tasks.cancel` and `tasks.maintenance_finish` also have 3,900 seconds, derived from the work they compose: the former VM destroy bound of 2,700 seconds, the 540-second Project teardown cap, and 660 seconds for the workspace Route withdrawal. Withdrawal's only fixed wait is the 31-second DNS grace, so most of that allowance is deliberate margin until real runs are measured. Their history came from cancels with no VM to destroy and was too short for real cleanup.

`tasks.turn` has a fixed 3,600 seconds. Its history held short reviewer and consult turns: the longest was 12 minutes, so the derived budget was 18 minutes and ended an author turn that was still writing code. The `observe` step of the turn workflow ends after 3,300 seconds, so the snapshots and the receipt still run. A maintainer must update the fixed row once the history holds author turns.

`proof.run` has a fixed 14,700 seconds: 7,200 seconds for capacity admission, 7,200 seconds for the longest run of a shard and 300 seconds of margin. Waiting for a CI place consumes the admission allowance; it never skips execution or turns an empty report into a success. Admission expiry fails clearly.

`task_vm.image_refresh` has a provisional 10,800 seconds, the serial children of the run with margin. The provision of a task VM takes 3,900 seconds at its bound. The converge of a base from stock builds the nested fleet when the base has none. That takes 5,400 seconds at its bound and about 25 minutes in practice (the standalone fleet build, since removed, took 1,840 seconds as operation 11816). A VM at its pins converges in about a minute. Stopping and snapshotting the base and proving a fresh task start add time. A maintainer must update the provisional row after measuring successful runs.

`tool.install` and `tool.update` have a provisional 1,500 seconds. A Tool write runs its bounded phases in turn: the [signed repository](/reference/tools#signed-apt-repositories) prepare (180 seconds), the candidate query (120 seconds) and the mutation (900 seconds). Measured brew installs took 471 to 578 seconds. A maintainer must update the provisional row once repository installs have native history.

`fleet.rollout` has a provisional 600 seconds. Its first successes deferred every Node outside the maintenance window in seconds, so a budget derived from them cut off the first real visit at three minutes. One visit of seven Nodes, four of them converged, took 187 seconds; a whole fleet converging at that pace, times 1.5, rounds up to 600. A maintainer must update the provisional row once windowed visits have history.

`fleet.reboot` has a provisional 10,800 seconds, the length of its window: a pass never starts a Node it cannot drain and reboot before the window closes. A maintainer must update the provisional row once passes have history.

`gateway.release` has a provisional 3,600 seconds. The legacy runner's last 47 verified releases took 117 seconds at p95 and 125 at most. The Horizon drain waits for running jobs up to the longest supervisor timeout plus 30 seconds, 1,730 seconds. Their sum times 1.5 rounds up to 3,000; the busy guard adds up to 600 seconds. Before loading Swamp, the release checks queued, running and waiting `instance.create`, `instance.deploy*`, `conn.*` and `process.*` Operations every 5 seconds. It proceeds when idle or refuses with `gateway.release_busy` and the remaining IDs after 10 minutes. Force does not bypass the guard. An expiry [pauses releases](/reference/gateway-recovery#release-as-a-swamp-run). A maintainer must update the fixed row after measuring successful runs.

A `tasks.script` and a `tasks.check` capture their budget at admission in `input.run_budget_seconds`, and the captured number is the Operation's run budget. Every script and every check captures the budget of its kind's history.

The workflows set the captured number as the native `timeoutSec` of each of their SSH steps, so a step against a VM that is gone ends with the Operation.

The other task VM workflows (`task-vm-workspace` and `task-maintenance-path`) limit their SSH steps to 3,900 seconds, the largest fixed budget of the Operations that call them.

The measured parts on a fresh VM are the restore from the ready copies, the rsync sync, and `composer install` where lock files differ from the seed (31 to 35 seconds for five). The plain snapshot `base` takes a fraction of a second. 360 seconds covers them with margin. A maintainer should replace the fixed row after measuring passing runs. When the Gateway times out a script of a proof, the proof goes on and cancels its group.

A `proof.command` captures its budget at admission from the batches it proves. A run proves its batches in its own task VM through the workflow `operation-proof-run`: the task provision, then each batch through `proof-fleet-verify`, then the destruction of the VM.

Its budget is 600 seconds for the shard, 180 seconds for each batch and the measured seconds of its cases, times 1.5, rounded up to five minutes, at most 7,200 seconds. The fixed parts come from the shards of proof 14337, which took 996 to 1,909 seconds for 3 to 8 batches. A fixed 3,600 seconds ended a run of 17 batches on one VM half way (proofs 14341 and 14651). A plan that needs a longer shard [waits for another CI place](/reference/compute-drivers#place-task-vms). When the Gateway ends a run at its budget, the run keeps the verdicts of the batches that ended.

A `proof.command` that older code admitted has no captured budget, for example one admitted while a release switches. It keeps the fixed 3,600 seconds it had then (`OperationBudgetTable::UncapturedSeconds`), never the 60-second fallback of a captured kind.

`proof.verify` has a provisional 3,000 seconds. It has no run yet. `orbit proof` proves one command on the task VM that calls, through the workflow `proof-fleet-verify`, with no provision and no destruction. Basis: 14 cases of about 120 seconds each come to 1,680 seconds. Add the restores before the first batch, between batches and after the last, about 16 seconds each. Add a token re-mint with its readiness check and the new `base`, about 150 seconds. The sum is about 1,900 seconds. With 1.5 times margin, rounded up to five minutes, it is 3,000. A maintainer should replace the fixed row after measuring successful runs.

`instance.rollback` has a provisional 900 seconds, the legacy rollback deadline and its [chart](/cli/instance/rollback)'s budget. A maintainer must update the fixed row after measuring successful runs.

Otherwise, the Gateway uses the longest successful run of that kind completed in the last 14 days, multiplied by 1.5 and rounded up to a minute, and at least 15 minutes. It measures `started_at` to `finished_at` on succeeded Operations with a `swamp_run_id`. With no successful history, the budget is 30 minutes.

The 15-minute floor is a stop-gap. A quick first success otherwise sets a budget that cancels a later, larger run, as it did for `instance.migrate` at 120 seconds. A hang of such a kind holds one Swamp dispatch slot and its target lock for up to 15 minutes; the Swamp Node has 12 slots: three workers with one slot per CPU, on 4 CPUs. The floor goes when each kind's budget comes from its workflow's declared step timeouts. The reconciler caches each kind’s history budget for one pass.

An unbound admission with attempts `0` has a separate 600-second budget, matching Swamp’s default `queueTimeout`, regardless of its kind’s execution budget. It runs from `started_at`, or `created_at` when no start was recorded. The reconciler includes these rows, even when cancellation was already requested.

The [reconciler](/reference/swamp-command-steps#reconciler) records an expired budget as `failed` with `error_code: operation.timed_out` and requests cancellation. This is provisional until Orbit reads the exact native terminal. The final receipt projects the native result and step history, including restoration, even when the deadline passed or cancellation was confirmed. A native phase error replaces `operation.timed_out`. Deadline-caused native cancellation keeps failed status and the timeout code while recording the native end state. User cancellation is cancelled. An unproven native end keeps the provisional timeout. Target holds remain until that terminal is projected or the stale-hold limit expires.

`instance.migrate` has a provisional explicit budget of 5,100 seconds. The 3,900-second basis used by `node.converge` is below the migrate path’s explicitly declared timeouts: 4,270 seconds, rounded up to 4,500, plus 600 seconds for admission and settlement. A test guards this lower bound, including Route children, restore and cleanup. It does not bound undeclared metadata steps or dynamic repetitions. The pinned Swamp has no workflow execution timeout; the native finish settles the Operation even after its Gateway deadline.

The terminal step receipt comes from native history, including nested runs, even when no streamed progress events arrived. This also replaces provisional step states recorded at a deadline.

The shared reader projects a native kind from its declared end-check step. A kind whose action runs as one of several guarded steps names the others as `alternatives`, and the step that ran is the end check; see [Swamp command steps](/reference/swamp-command-steps#read-swamps-run-data-instead-of-a-result-step).

A projection that implements `DirectOperationTimeout` records its documented failure state in the expiry transaction, while the native result is still unknown. The normal terminal projection reconciles the final state when the run ends. Node removal, `tasks.cancel` and `tasks.maintenance_finish` use it to mark the departing Node `failed`; the native run may still finish, and a later admission recovers the records.

`task_vm.slot_release`, `tasks.cancel` and `tasks.maintenance_finish` hold the generic Operation target key (`task_vm:<id>` or `task_group:<id>`). Expiry keeps that key: a new admission returns `operation.target_busy` until the shared reader verifies the exact bound native terminal and releases it. Settlement records the native end state. Native `interrupted` release and Task cleanup results project as `failed`; recovery starts a new Operation. The task VM sweep logs each admission refusal with its details and continues with other rows.

The hold has a limit. When nothing proves the end of the run within 60 minutes of the expiry, a Gateway job (every five minutes) frees the target and records `native_terminal_status: unproven`. A run that was asked to cancel an hour ago and never reported is lost, and a lost run must not hold a group or a VM for ever.

For an unbound timed-out admission, the existing exact lookup by Operation identity releases the hold only when Serve proves there is no matching run. A matching run binds; ambiguity or an unavailable lookup keeps the hold. A valid native terminal wins in the shared history read even when its projection arrives after the deadline.

This is the Gateway's budget result, not proof that the native run or its descendants stopped. At expiry the Gateway records `state.native_cancel_requested_at` once. The reconciler asks Swamp to cancel the bound run, as an operator cancel does, and records `state.native_cancel_confirmed_at` when Swamp confirms. Swamp stops the run; the Gateway only forwards the request, and each pass asks again until Swamp confirms. An Operation with no bound run has nothing to cancel.

The request is a `workflow.cancel` message on the Gateway's run token. Serve authorizes it with the `run` action on the run's workflow, which the Gateway already holds to start the run. Serve's HTTP cancel endpoint needs `admin` on `access:*`, the superuser right, so the Gateway does not use it.

The SSH step limits above end the steps of a run whose cancel did not arrive. Native Serve ceilings, step queue limits and phase command limits remain separate controls. The reconciler checks deadlines on every pass, on the operations queue. Bound Operation reads arbitrate native terminal history before expiry; unbound admission expiry needs no native read.

## Native failure detail and live steps

The shared observer projects live step names from native run events without reading history. `current_step_started_at` is the native timestamp when the event supplies one; it stays null when the runtime omits it. `current_step_observed_at` records when the Gateway observed that event. Replayed progress cannot replace a newer event.

A failed or interrupted root retains its first causal step as `failed_step` and a bounded safe diagnostic in `error.detail`. A kind's projection maps that step to its chart step name, a `forEach` iteration such as `Probe the site over TLS-0` included, and records the chart step's error code. Recorded stderr is preferred; otherwise the native step error is retained. `error.stderr_recorded` distinguishes recorded stderr from missing stderr, including worker disconnection or dispatch refusal. Stdout is never failure evidence.

When a kind's workflow has no result-reporting step, the kind declares its terminal result as `native`. Its projector then reads Swamp's own run data keyed by step name ([Swamp command steps](/reference/swamp-command-steps#read-swamps-run-data-instead-of-a-result-step)). `node.converge` uses each native step outcome to record the footprint parts that succeeded, including the network part and its handshake assertion.

A terminal result declaration can also name `data`, the data behavior of its terminal step. When Swamp does not inline a large step output in the run history, the projector reads the step's `output` data artifact from that behavior ([Swamp command steps](/reference/swamp-command-steps#scheduled-observations)). A terminal step that succeeded wrote that output, also in a failed run. When the projector cannot read it, it reads the run again on the next pass, until the Operation's run budget ends.

A failed step with exit 124 and a command beginning with a literal `timeout N` reports `<step> timed out after <N>s`; other failures keep their redacted diagnostic. The shared projector and the existing exact history read use the same diagnostic extractor. An older native failure can receive missing detail once through its next normal Operation read, without changing its terminal result or sending another failure alert.

The shared extractor redacts known Instance and Process environment values, configured Swamp credentials and credential-shaped text before retaining the last 4 KiB. It drops a truncated first line whose identifying prefix may be missing, then redacts the retained text again. Budget expiry omits `error.stderr_recorded`, because that result makes no claim about native stderr. Native family scripts still own safe output preservation; this projection does not change their placement or execution.

## Native public participants

A direct Operation may admit immutable `input.participant_node_ids`. A foreign participant may use the existing direct-operation GET with the bound root run ID while the parent is unfinished. A projection implementing `DirectOperationPublicView` asserts its participant ownership and returns fresh public input for that authenticated Node. The response preserves the parent Node identity, omits the parent result and uses `Cache-Control: no-store`. Participation grants neither a result POST nor private-material access; those have their own shared authorization.

## The record

The optional `steps` list uses typed `OperationStep` metadata. Start responses can omit it; show and cancel responses include it. List rows never carry `steps`, `input` or `result`; [Page the list](#page-the-list) says why.

`operation:show`, `operation:cancel` and `task:cancel` return a summary of `input`: top-level scalars and strings of at most 512 bytes. An admission that waits for a place keeps the caller's request as `input.capacity_request`; the summary keeps it as an object with the same filter, so a waiting Operation reads the same request on every read. Nested execution plans, scripts, SSH configuration and vault references stay in the stored Operation. The workflow still receives its full admitted intent. Empty summaries serialize as `[]`. Domain results remain available. `steps` contains only recorded names, jobs, statuses and times, with no nested payload or command output.

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
| `capacity_wait` | For an Operation on a task group that waits for a task place: `operation_id`, `position` and `holders`, as [Task places](/reference/tasks#task-places) defines. Null otherwise |

A cancelled operation has an `error_code` only when a rollback superseded it: `operation.superseded`.

`operation:show` and cancellation replies include observed native `steps`, in event order:

| Field | Contract |
| --- | --- |
| `name`, `job` | Native step and job labels |
| `status` | `running`, `succeeded`, `failed` or `skipped` |
| `started_at`, `finished_at` | Recorded ISO 8601 times, or null |

These summaries come from the Gateway's recorded events, without another native history read. Older Operations or a queued run can have no recorded steps. The summary does not claim that unobserved steps completed.

`result` depends on the kind. For `instance.deploy` and `instance.rollback` it is `{ deployment_id, release, selected_release, commit }`, where `deployment_id` is the [deployment history row](/reference/deployments#history) that holds the full log.

### Swamp child Operations

Executor responses encode map fields as JSON objects, including empty Operation inputs, execution contexts, descriptor inputs, step-result maps, run results and receipt results. Descriptor input schemas preserve nested maps as objects and lists as arrays. Empty lists remain `[]`, and absent results remain null. This conversion happens only at the HTTP boundary, after authorization and redaction; stored input, fingerprints and receipts retain their original values. Reading a response does not hydrate secrets or run transports.

A Swamp parent admits children through its owning family's `OperationFamilyChildren::childContext()`. The context returns exact keyed intents `{key,kind,node_id,input}` from admitted input or prepared Gateway state. The parent kind declares allowed child kinds and Node relations. Each child kind registers an `OperationChildAdmission` adapter that calls its normal owning admission action. Swamp posts `{worker,key}` to `operation-executor/{parent}/children`; optional kind, Node and input fields are exact assertions only. Repeating a key returns the same child before repeating family admission.

A child receipt omits executor worker, lease, waiting, step-result and execution-context fields when its kind is direct-only, including an older row without a direct writer stamp. It retains the recorded status and nullable result. This read does not restore an execution contract or admit another child.

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
| `task:start` | `tasks.provision` | `task_group` | Gateway |
| `POST tasks/{group}/prep` | `tasks.prep` | `task_group` | Gateway |
| `POST tasks/{group}/prep-fix` | `tasks.prep_fix` | `task_group` | Gateway |
| `POST tasks/{group}/sync` | `tasks.sync` | `task_group` | Gateway |
| `task:check` | `tasks.check` | `task_group` | Gateway |
| `POST tasks/{group}/script` | `tasks.script` | `task_group` | Gateway |
| `POST tasks/{group}/package` | `tasks.package` | `task_group` | Gateway |
| `task:turn` | `tasks.turn` | `task_group` | Gateway |
| `task:publish` | `tasks.publish` | `task_group` | Gateway |
| `POST tasks/{group}/pr-ready` | `tasks.pr_ready` | `task_group` | Gateway |
| `POST tasks/{group}/pr-merge` | `tasks.pr_merge` | `task_group` | Gateway |
| `POST projects/{project}/pr-merge` | `project.pr_merge` | `project` | Gateway |
| the gate check `task:pr-gate` (no route) | `tasks.review_round` | `task_group` | Gateway |
| the gate check `task:pr-gate` (no route) | `tasks.build` | `task_group` | Gateway |

Every task operation holds the group, so different kinds cannot overlap on it. A task VM Node exists only while the group runs, so the Gateway serves the group's operations and their reads. Provision returns `{ instance_id, node_id, starting_commit }` on success. A turn returns its receipt, thread state, workspace evidence, and token metrics; [Run an external turn](/reference/tasks#run-an-external-turn) defines the result and refusals.

A check returns `{ outcome, deliverables, check_id, commit, head, tree, output, ci }` under the [hosted handoff CI contract](/reference/tasks#check-an-external-group). Swamp owns review and merge policy. The input remains `{ subtask, phase }` with the existing optional key.

`head` is the pre-commit check snapshot; `commit` is the candidate HEAD. Baseline/no-policy has `ci: null`. A required-CI handoff adds persisted handoff-push and hosted-CI-read steps after the existing commit step. Before push, state pins the branch and required check name; it retains CI evidence while waiting, so recovery cannot reinterpret a later Project edit. The terminal result carries that evidence. A failed code/workflow check ends `succeeded` with `outcome: failed`, not an operation failure. Consumers need `outcome: passed` and the shared checked-candidate eligibility rule, not just `status: succeeded` or a locally passed check.

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

Every route that starts an operation accepts an `Idempotency-Key` header. Most routes make it optional. Today these are `POST /api/v1/instances/{instance}/deploy` (`instance.deploy`), `POST /api/v1/instances/{instance}/rollback` (`instance.rollback`), `POST /api/v1/tasks/{task}/start` (`tasks.provision`), `POST /api/v1/tasks/{task}/check` (`tasks.check`), `POST /api/v1/instances/{instance}/deploy/automatic` (`instance.deploy`), and `POST /api/v1/tasks/{task}/turn` (`tasks.turn`). The automatic deploy and worker start APIs require a key. The worker API excludes its enrollment secret from the fingerprint. A key has 1 to 128 characters from `A-Z a-z 0-9 . _ : -`. Keys are case-sensitive and scoped to the operation kind, across all calling Nodes.

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
| `GET /api/v1/operations` | The newest records, 20 to a page, without `input`, `result` or `steps`. [Filter the list](#filter-the-list) with `status`, `kind`, `target_type`, and `target_id`, and [page it](#page-the-list) with `limit` and `cursor` |
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

A list row has every field of [the record](#the-record) except `input`, `result` and `steps`. A stored input holds the whole admitted intent and a result can hold up to 256 KiB, so a page of full records can pass what an MCP client accepts in one reply. Read one Operation with `operation:show` to get its input summary, result and steps.

### Follow an operation by polling

A client that has no Reverb connection, such as an MCP agent, polls:

1. Start the operation and keep its `id` and `sequence`.
2. Wait `poll_after_seconds`.
3. Read it with `after_sequence` set to the last `sequence` you have. Apply the steps it returns.
4. Repeat until `status` is terminal. Then read `result` according to `kind` and `status`; for failure also read `failed_step` and `error_code`. A failed operation's typed result is evidence, not success.

`poll_after_seconds` is 2 while the operation is queued and in the first 10 seconds of its run. It then grows by one second for each 10 seconds of run time, to at most 15. While an admission waits for a place, with `input.capacity_request`, it is 20, the interval at which admission tries again. The Gateway computes it on every read, from the status and `started_at`.

Every read returns the whole record and the steps that changed. So a client that missed reads, or starts again from 0, still ends with the current state.

### Follow an operation over Reverb

When the [`websocket` role](/reference/events) is active, the Gateway sends `operation.updated` on the private channel `private-operation.{id}` at each change. A client:

1. Subscribes to `private-operation.{id}`. `POST /api/v1/broadcasting/auth` signs it for any caller with Gateway access.
2. Reads the operation over HTTP.
3. Applies each event whose `sequence` is greater than the one it has: it reads the operation again with `after_sequence`.
4. Unsubscribes when `status` is terminal.

An `operation.updated` event never carries output. No component publishes deploy step output on this channel now: the agent command that did was removed.

Reverb can lose an event, for example while the socket reconnects, so a client that has no event for a while reads the operation anyway. The web app and the CLI read every 15 seconds while subscribed. [Realtime events: Operation](/reference/events#operation) lists the fields.

## Cancel an operation

`operation:cancel` asks an operation to stop. The Gateway records the request first. An operation that no workflow has started becomes `cancelled` at once and releases its target. Once a native run is bound, the Gateway asks Swamp to cancel that run. The workflow owns the end: the operation becomes `cancelled` when its terminal result says so, and a workflow may finish its running step first. A cancel request for a terminal operation changes nothing and returns the record.

A cancelled deploy or rollback records its history row as `failed` with `deployment.cancelled`. Orbit never undoes the steps that ran.

A parent that waits for children passes the request to every nonterminal child in the same transaction.

An Operation that the Gateway timed out is terminal, yet its native run may still be going. Cancelling it asks Swamp again to cancel the run. Ending a task group (`task:cancel`) asks Swamp to cancel the runs of the group's timed-out Operations before it destroys the VM, so no run keeps waiting on a VM that is gone.

When a `tasks.publish` has started its push, cancellation keeps the group held until the push records success or failure. See [Publish an external group](/reference/tasks#publish-an-external-group).

Process lifecycle workflows use official cfgmgmt resources and SSH argv. Native assertions enforce the observed runtime and boot state. Docker creation uses one detached run; update removes the existing container before its replacement. Environment files remain root-owned 0600 until destroy. Horizon handoff publishes through systemd apply, chooses graceful termination or start, and checks the enabled unit is active before recording success.

## How an operation runs

Admission records the intent, the idempotency key and the target hold in one transaction. After the commit, the Gateway pushes any execution copies and submits one native workflow for the kind ([Direct Swamp starts](#direct-swamp-starts)). Swamp runs the steps, retries and waits inside that workflow. The Gateway records the observed steps, then projects the workflow's terminal result into the operation and its domain record ([Recover a terminal native result](#recover-a-terminal-native-result)).

Each kind has a run budget ([Native run budgets](#native-run-budgets)). When the budget ends first, the Gateway fails the operation with `operation.timed_out`. The Gateway never reruns a step itself.

the WebSocket registry tasks (`websocket.sync`, `websocket.app_create`, `websocket.app_destroy` and `websocket.app_rotate`) and `proof.run` are the exception. The Gateway runs or closes them itself, with no Swamp run of their own; `proof.run` closes when its runs have ended. Each run is an Operation `proof.command` with a Swamp run of its own; its provision, cases and destruction are children of that run.

### One operation per target

An operation holds its target from the start request to its terminal status. A second start for that target answers `409` with `operation.target_busy`. The hold lasts across a lost run and a resume. A rollback is the one exception: it supersedes a deploy of the Instance that no workflow has started, as [One operation at a time](/reference/deployments#one-operation-at-a-time) describes. The hold does not cover work that is not an operation: while it runs, a deploy or rollback also takes the [Instance's operation lock](/reference/deployments#one-operation-at-a-time).

### One Route write per Node

A Route write holds the Nodes that serve its site, from admission to its terminal status ([Coordinate publication](/reference/routes#coordinate-publication)). The kinds are `instance.create`, `instance.destroy`, `instance.clone`, `instance.transfer`, `instance.migrate`, `route.create`, `route.update`, `route.destroy` and `route.target_set`. A second write for a held Node is admitted with status `waiting` and a `hold_nodes` value in its state. It starts no run and gets no `409`.

Task provision and cancel, `instance.register` and the Cluster Router writes hold the same Nodes for one step only. The run calls `operation:direct:route-hold` before its Route-publish step and `operation:direct:route-release` after it. The hold of such a step has a lease of 5 minutes, which the minute pass frees when it ends, and the terminal status of the Operation frees it too.

The hold is the `route_publication_holds` table, with one row per Node. A finished write frees its Nodes and queues `PromoteRouteWrites`, which moves the waiting writes that fit to `queued`, oldest first, and starts them. The minute pass of `ReconcileSwampOperations` frees the Nodes of a write that ended without a release. A waiting write has no deadline; its budget starts when it starts. A timed-out write keeps its Nodes until Swamp confirms that its run stopped, or for 15 minutes.

## Errors

These codes belong to every operation.

| Code | Meaning |
| --- | --- |
| `operation.idempotency_conflict` | The kind already has this key with another target or input; HTTP 409 |
| `validation.failed` | A malformed key or invalid input; HTTP 422 |
| `operation.target_busy` | Another operation holds the target |
| `operation.superseded` | A rollback of the Instance cancelled this deploy before a workflow started it. The status is `cancelled` |
| `operation.timed_out` | The run budget ended before the workflow finished. The status is `failed` |
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

After the admission commits and its execution copies are acknowledged, `SwampDispatch` queues one start job per Operation on the existing Gateway `operations` queue. The API answers `202` with the queued Operation without waiting for native start confirmation. This applies to every admission through the shared dispatcher, including Instance creates and Conn pairings. The job contains only the Operation ID; it reads the committed intent and prepares any run-time execution copies when it runs. Clients follow the same Operation through queued, running and terminal states.

The start job makes one attempt. A connection failure or native refusal fails the Operation with `swamp.start_unavailable` or `swamp.start_refused`. A queue submission failure fails it with `swamp.start_enqueue_failed`. A job that fails before submission records `swamp.start_job_failed`. Cancellation or admission expiry before the job runs prevents submission.

The existing reconciler ends an unstarted admission at its 600-second budget with `operation.timed_out`, including a lost job or an unavailable worker.

If confirmation is lost after submission, the Operation records `swamp.start_uncertain`. The Operation stays running, keeps its target hold and has no invented native binding. A timeout does not prove that no run exists. The start path neither searches nor resubmits. A worker failure during submission follows the same uncertain path. Replaying the same idempotent request returns the same Operation and queues no additional work. A repeat without that key still obeys the target hold while the start job waits.

Resolution requires native evidence for the exact workflow, `inputs.operation_id`, `inputs.node_id`, `inputs.kind` and recorded start key. A matching run can supply the missing binding for the existing terminal subscriber. Only authoritative proof that no run started permits a not-started failure and another admission. An unreadable or empty partial response is not that proof. A connection failure before submission or an explicit native refusal still fails the Operation.

On every reconciler pass, an unbound running Operation marked `swamp.start_uncertain` receives one native run lookup. The lookup uses its recorded workflow, admitted identity and `orbit_start_key`. Exactly one matching root supplies the binding, then the same pass projects its terminal result. Missing, ambiguous, mismatched or unavailable evidence leaves the Operation and target hold unchanged. The lookup never submits work.

### Execution-copy failures

The Gateway pushes execution secrets through the shared `SwampVaultExecutionCopy` client before queuing a run start. No network call runs inside a database transaction: an admission transaction only records the intent and the Operation. `SwampDispatch` holds the execution copies and run starts until the transaction commits, then pushes every copy and queues the starts. Copies stay in the request because they capture secret values in memory; the queue stores neither those values nor their closures. A rolled-back admission pushes and queues nothing.

A failed push returns `swamp.vault_sync_unavailable` and fails each Operation admitted in that transaction before any start job is queued. Outside a transaction, the push runs at once.

The `Swamp execution copy unavailable` warning records the public vault and key names, native request ID, failure phase, a recognized server refusal code and the HTTP handshake status when available. These fields distinguish connection failures from vault refusals. It records no secret value, token, payload, remote message or original exception message. The client does not retry the write.

### Recover a terminal native result

`GET /api/v1/operations/{operation}` makes one bounded native history read for an active direct Operation with a recorded run ID, at most every 30 seconds per Operation and within the Gateway-wide [Swamp read budget](/reference/swamp-command-steps). A read the budget refuses answers the recorded status.

The read queries that exact UUID and checks the workflow name, Operation ID, kind and root Node. Only a native status of failed or interrupted can record `operation.native_failed`, through the same terminal transition as the existing root failure callback. That transition applies the kind's own projection, so a read that sees the failure first still ends the kind's records: a failed `tasks.provision` releases its group reservation. The verified native status is preserved, including interrupted. Native success never invents a missing domain result.

This recovers a lost failure callback after a serve restart or a refused source bundle. Missing history, connection errors, authorization refusals, invalid history and all other native statuses leave recorded status unchanged. The native request runs outside the database transaction; the final write locks and rechecks the original binding and terminal status. Existing terminal results never change. There is no background poller, execution replay, family action or secret hydration. Terminal failure reports the native outcome; it does not prove that every partial Node effect stopped.

This is bounded submission recovery, with no job, timer, execution retry or history poll. Native runtime tags support lookup; they do not provide atomic start deduplication. The recorded run binding remains required before a legacy workflow can read intent or invoke a primitive. The retained shared client makes one read and refuses an unbound run before any action or projection. It has no binding wait, retry, timer or separate deadline. The existing transport owns request timeout and cancellation. New workflows receive the full admitted intent as inputs and use the shared terminal projector; they do not use this client.

A declaration names the workflow, writer configuration path, immutable writer-state field and a typed projection hook. New families use the common `swamp.direct_writers` map (`ORBIT_SWAMP_DIRECT_WRITERS` JSON keyed by exact kind, default `{}`). Families add no token, controller or HTTP routes. Routes retain their existing per-kind settings and `route_publication_writer` field.

After a kind cuts over and removes its previous executor, its `operation-kinds.php` entry contains only `executor: 'swamp'`, `result_fields`, and optional `admission`. Its matching `direct-operations.php` declaration supplies the workflow and projection. The registry resolves that projection without requiring old `runs`, completion handlers or receipt proofs. These direct-only kinds default to the direct writer; an explicit polled setting, a scope mismatch or a missing direct declaration refuses admission rather than falling back to deleted code. The previous executor's claim, execution and completion paths also refuse them. Rollback reverts the kind's cutover PR.

A parent still on the previous executor may admit a direct child through the child's owning admission hook. The child response includes normal Operation, parent and child identity. It omits the old execution context, lease and wait metadata. Replaying that child admission returns the same child without resolving a deleted execution contract.

Cancelling an unstarted queued Operation with no `swamp_run_id` and zero attempts records `cancelled` immediately and releases its target hold, including an existing cancellation request. Once the native start is running, cancellation only records `cancel_requested_at`, even before the run binding arrives: submission may be in flight. The reconciler forwards that request once the run binds. Swamp owns stopping and projecting its terminal result. A terminal Operation stays unchanged. Cancellation does not walk native children.

A declaration may constrain its writer with literal `writer_scope` admitted fields; `node_id` and `parent_operation_id` come from Gateway admission authority. A scope mismatch records the previous polled writer. Tool admission also checks that its prepared input and the stored writer decision agree before it records an Operation.

`firewall.allow`, `firewall.deny` and `firewall.remove` pass the admitted Node rule and shared tuple plan to `operation-firewall-direct`. Every step runs on Node 209. The official firewall model reaches the target over WireGuard after the admitted pin is verified in the published known_hosts snapshot. Doctor's `firewall` family owns the read-only UFW read and comparison. The Gateway projects the native terminal result.

All Tool kinds use direct Swamp execution for root intents on Node 209. Its Gateway command descriptors, completion proofs and package execution policy, manager child admission and receipt adapters are removed. Swamp materializes the admitted manager and applies package policy locally. The Gateway retains typed manager and Tool result projection.

Undeclared or unstamped Operations stay on their previous Swamp path. The helper rejects a PHP writer. Families keep their own authorization, input and result validation; shared projection only records bounded status/results and native run identity. It adds no execution policy.

The optional typed primitive hook is reserved for shared generic Gateway primitives, such as Route/Caddy publication or scoped token minting. New family execution relays are refused; family actions run on their Node worker.

Direct workers authenticate as active WireGuard peers. Direct requests authorize the bound run and immutable participant and field bindings. They require no Gateway access grant. A TaskVM participant receives only its admitted public view and readable private fields, and may store only explicitly writable fields.

Only the root Node may send result, failure or primitive requests. Other Gateway APIs keep their Node access checks. Participation never grants general access to the Gateway or another Node.

Only an authenticated native start response or an exact native history match can bind `swamp_run_id`. Read, result and primitive requests refuse until it is recorded, and cannot bind or replace it. A bound intent returns without another submission. Each family canary proves start-response and first-read ordering. Structured `Swamp direct start` logs record duration, outcome and failure phase. `Swamp direct start refused` records the workflow and the remote error type, code and bounded message after credential and secret-pattern redaction. Remote diagnostics stay out of Operation results and API errors.

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

`POST /api/v1/direct-operations/{operation}/github-token` serves only a kind that declares a `github_token_issuer`, such as a [development deploy](/reference/deployments#native-development-deployment), a task provision and its setup steps, a package install (`tasks.package`), a task publish, a pull request step, a dependency merge, a cancel and a prep run, and `tasks.check`. A task kind mints when the step that needs the token runs, never at admission: an installation token lives one hour, and a prep run can take longer to reach its publish step. Any other kind gets `operation.github_token_forbidden` (403). A step that minted a setup token revokes it and reports the outcome in the result as `dependency_token` (`revoked`, `none` or `unrevoked` with a reason), for `tasks.provision` and `tasks.package`.

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
is `provisioning`. So is the new guest Node of a `tasks.provision` (`guest_node_id`). The Gateway
activates a guest only when it applies the provision result, and that is after the Route step has
asked for the leaf.

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

On Swamp, use the shared `directOperation` template with family-owned read, action and projection functions. The action may be a local Node step or a fixed Gateway primitive while that family migrates. Native DAGs, placement, behavior locks, cancellation and recover/resume own execution. Mutating external steps declare `writes: true`. No Gateway claims, heartbeats, child waits, receipts or local retry loop belong in the template.

Swamp runs a step that has not written again when its worker is lost, and fails a step that has. A step that changes an external system without writing Swamp data is invisible to the scheduler until it declares `writes: true`. Without the key the second run of a step fails it (`destination path already exists`) or leaves a second token.

A step that cannot run twice must declare it: a `git clone`, a `git worktree add`, a mint, a one-shot API call that is not safe to repeat, an account change, the deletion of a VM. A token revoke stays re-runnable: a second `DELETE installation/token` answers 401, and the revoke steps tolerate it.

Swamp defines the key and what it does. Our rule is the test `swamp/tests/writes_declared_test.ts`. It audits every behavior or model-method step, custom behaviors included, unless its exact type and method appear in `nativeDataMethods` in `swamp/tests/writes-reviewed.json`.

That list holds the methods that only read, or that write only Swamp data, each with its reason. A custom method that writes files, changes a network service or runs a process cannot enter it. Workflow calls, assertions and manual approvals are not audited; child workflows have their own leaf audit.

Every audited step declares `writes: true` or has a step entry in the same file: read-only, idempotent or unreviewed. A check that runs over SSH leaves transport files on the worker, so it is idempotent rather than read-only. The unreviewed count is pinned, so a rise shows in the diff. The audit joins shell continuation lines before checking commands. Commands built from step outputs and effects inside playbooks still need a manual check.

`instance.transfer_discard` uses only `operation-instance-lifecycle-direct`; it has no executor claim/run registration or polled discovery entry. `instance:rename` starts no Operation: the Gateway records the branch from the Node agent's checkout report.

Prove each kind end to end on a dev or canary target. Its takeover PR changes the writer and deletes the replaced Gateway path and execution tests together. Rollback is a revert PR. Admitted Operations retain their writer. Scoped workflow grants and token custody require review before activation; extending one family's run grant to another workflow is not implicit.

### Private material transport

A direct declaration may map `private_material` input paths to registered `OperationSecretSource` names and `secret_outputs` output names to registered `OperationSecretOutputStore` names. The shared endpoints accept no caller-selected source, store, owner or Node. Reads hydrate only those admitted references; writes call the registered store with its existing owner checks and preconditions. They perform no execution and record no receipt.

Declared private fields use the same fixed credential wire serializer as agent commands, including typed values nested inside those fields. A `GitReadEnvironment` becomes `{github_token: string|null}`, and an `AgentGitHubToken` becomes its token string. Arrays, scalar values and null keep their shape. This normalization neither hydrates undeclared input nor changes the caller, owner or native run checks.

Both paths require the recorded native run ID, an unfinished direct Operation, and an active peer with Gateway access whose caller Node equals `Operation.node_id`. The Services peer has no fleet bypass on these paths. Responses use `Cache-Control: no-store`; Activity records no request body, and MCP excludes both endpoints. Private material and output values never enter Operation input, state, results, steps or logs. Swamp consumes them in memory or protected stdin; it must not write them to arguments or resources.

A declared `OperationArtifactSource` may return a bounded existing-file reference: a relative private-read path, SHA-256, byte count and the shared 256 MiB maximum. `GET` on the same private-material path with `artifact=FIELD` serves only that declared artifact field, with the same Node, run and unfinished-Operation checks. It accepts no file path or source from the caller. The Gateway rejects symlinks, oversized files and digest mismatches. The worker bounds the download and verifies the admitted digest before use. No binary is embedded in JSON and no public artifact URL is added.

`DirectOperationResultProjection` supplies the common atomic status/result transaction for extension families. Their fixed `result_hook` validates safe input and domain metadata; the result hook invokes its existing domain completion. Conn’s issue observations update its existing pairing metadata before the registered credential store accepts the output. No family transport or result controller is added.
