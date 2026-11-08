---
title: "Swamp command steps"
description: "The shared execution-copy and terminal-output contract for native command steps."
covers:
  - apps/gateway/app/Infrastructure/Swamp/{StartSwampRun,SwampCommandStep,SwampVaultExecutionCopy,SwampRunSubscriber,NativeRunResult,SwampConnection,SwampDispatch,SwampTerminalReadUnavailable}.php
  - apps/gateway/app/Jobs/Operations/ReconcileSwampOperations.php
  - apps/gateway/app/Domain/Operations/DirectOperationChildProjection.php
---

# Swamp command steps

Use `App\Infrastructure\Swamp\SwampCommandStep` for execution-copy references and terminal projection. Each family opts in with `workflow_inputs => true`; its projector returns the outer `{intent, execution_secrets}` envelope. The shared `DirectOperation::workflowInputs` builder validates names-only descriptors and writes authoritative Operation, kind and Node IDs last. An empty descriptor map serializes as an object. The separate `terminal_result` declaration fixes the result job and step; it does not opt into full inputs. Native `command/shell` owns execution and output capture. The Gateway records the result through the family's existing projector.

## Secret inputs

Call `executionCopy(string $vault, string $key, string $value): array{vault:string,key:string}` when the source value changes. The value is a sensitive parameter. The helper sends one native `vault.put` request over the authenticated TLS WebSocket and retains only a synchronization digest. The value is in `payload.value`, never process arguments. It accepts only a matching vault/key acknowledgement from a `local_encryption` vault before recording synchronization. Pass the returned descriptor in `execution_secrets`; never pass a secret value in workflow inputs.

Serve rate-limits requests and WebSocket handshakes per client and answers `429` with `Retry-After`. Its source admits at most 50 new WebSocket connections from one IP address in a fixed minute, for every token together, and names the rest of that minute as `Retry-After`; no serve setting changes it. Every Gateway process shares that budget.

The Gateway opens one connection per admission instead of one per request: the copies and run starts that one transaction admits share a single WebSocket, because serve matches each reply to its request by `id`. A request that fails on it closes that connection, and the next request opens a new one.

Every Gateway WebSocket client also tries a refused `429` handshake again after the server's `Retry-After`, or after a doubling backoff from half a second when serve names none. It tries at most four times and only while the caller's connect timeout leaves room, so a copy or start waits up to 15 seconds instead of failing at once. A wait for the rest of serve's minute is longer, so the Gateway keeps its own connection rate low instead.

Reads that only refresh what a caller sees take a connection from one Gateway-wide read budget: at most 20 per minute, and one per subject per interval. An Operation read checks one run's history at most every 30 seconds, however often clients poll it; a task group's Swamp runs are read at most every five seconds. A read the budget refuses answers from recorded state. The rest of serve's minute stays for admissions, execution copies and the shared observers.

Map each named variable with one whole native expression under `task.inputs.env`:

```yaml
inputs:
  run: '/usr/bin/python3 "${{ steps.assets.outputs.probe_script }}"'
  env:
    ORBIT_BOOTSTRAP_IDENTITY: '${{ vault.get(inputs.execution_secrets.bootstrap_identity.vault, inputs.execution_secrets.bootstrap_identity.key) }}'
```

Swamp resolves the value. The script uses stdin or a protected config file when handing it to a tool. It must not expand a secret into `run:`, argv or shell tracing. Environment delivery does not isolate the value from the worker user: `/proc` can expose the environment.

Official e459 accepts this server protocol even though its remote CLI refuses stdin. The request is `{type: "vault.put", id, payload: {vaultName, key, value, force: true}}`; no refresh hook is supplied. The primitive still requires approved vault authority; it adds no permission. Official e459 authorizes `write` on `vault:<name>` with the key in request context. That action also admits delete and annotate; a `methods: [put]` restriction does not narrow it because the handler supplies no method name. A put-only request does not authorize a broader write grant.

Upstream fixes go through the orchestrator to Nick. Only official Swamp versions may be pinned or distributed. The GitHub App key and Orbit CA private key stay only in the Gateway.

## Terminal outputs

The action that produces public values prints one JSON object on stdout. Native `command/shell` stores it in `jobs[].steps[].outputs.stdout`, alongside `exitCode`, `executedAt`, `command`, `durationMs` and `stderr`. Keep secret values out of every output. On official e459 a step that exits nonzero keeps no outputs. A refusing action fails with its own exit code; the family projector maps the native failure to its public refusal. Native dependencies skip subsequent actions.

The shared subscriber calls `projectTerminal(Operation $operation, array $terminalRun, array{job:string,step:string} $selector): Operation` with the native event's `run` object and the fixed family declaration. The helper reads that exact root with `workflow.history.get`, authenticated with the existing `swamp.read_token`. It does not require the separate `swamp.run_token` used to start work. It selects the named job and step and decodes stdout. After the history request finishes, it briefly locks and reloads the Operation and checks its run binding. It releases that transaction before calling the family `projection.apply`, so native secret reads and cleanup never inherit a shared write lock.

Each family commits its domain changes and terminal result in its own short transaction; the shared result writer locks and rechecks the original run binding and terminal status. No history request runs under that row lock. It does not repeat host or domain checks, poll status, resume a run or invoke an effect.

A `completed` event can carry a failed run status. Native `interrupted` remains `interrupted`; it is a terminal non-success status. A failed or cancelled run can have no selected output because the step was skipped. A failed or interrupted run with an empty result, or a selected JSON outcome of `succeeded`, projects `{"error_code":"operation.native_failed"}`. A cleanup failure therefore fails the Operation even when the selected action succeeded. Cancellation keeps any selected evidence, or an empty result when none was recorded.

A projection that implements `DirectOperationChildProjection` can also record its declared workflow when it ends inside a parent. The same shared reader finds children through recorded ancestry, bounded to 60 runs and four levels, then reads their fixed terminal selectors. It applies children in completion order under a short parent row lock and records their run IDs to suppress duplicates. Parent identity and held thread IDs constrain task turn evidence. Cancelled children supply no new transcript. No family adds a history reader.

A running parent can record child evidence before its own terminal result; a timed-out parent can retain that evidence while awaiting native settlement. Timeout settlement releases the hold without projecting new root evidence. Native cancellation can skip finalization; a cancelled task turn keeps its previous transcript.

When a capture exceeds inline output limits, the shared reader loads the capture’s declared `artifact` resource by its recorded data ID and version (`result` when omitted). Task settlement captures name `state`, `verdict` or `fix`, matching the producer. The artifact lookup must return exactly one row with the declared ID, name and version. Missing or mismatched evidence leaves the child unprojected. The reader never selects the latest version.

A successful run requires its public JSON object; a kind without a result step declares `native` instead (below).

Missing, mismatched or unreadable history leaves the Operation unchanged and raises `swamp.terminal_result_unavailable`; it never triggers resubmission. When Serve cannot be read at all (the connection fails, Serve rate-limits the request, or no reply comes), the queued projection retries with exponential backoff and equal jitter (30–60 seconds initially, capped at 300 seconds) until the kind's run deadline. A missing, mismatched or still-running history is not retried.

Queued projection explicitly fails on permanent or unexpected errors, including a stale binding or missing Operation; unlimited attempts apply only to the delayed transport retry, never automatic exception retries. Direct invocation still raises the error. Terminal reads reuse the admission connection owner's endpoint-and-authority matching across jobs in each PHP process. A failed request drops the connection; replies still match the exact request ID.

### Read Swamp's run data instead of a result step

Flow-tighten rule 14 removes the step that reports a result. A kind without one declares `terminal_result` with `native => true`; `job` and `step` name its real end check, for example `['job' => 'settings', 'step' => 'Check owner and mode', 'native' => true]`. The same single history read then gives the family projector:

- `steps`: every step by name and status, with `stdout` up to 4 KiB and `exit_code` for the first 256 entries.
- `summary`: the run status, the step counts by status, and `failed_steps`.

Status and summary counts cover the full fan-out. Stderr is excluded. This lets a family record completed effects after a later failure.

When one action runs as one of several guarded steps, such as one per placement, `alternatives` lists the other steps beside `step`. The step that ran is the end check: when the run succeeds, it must have succeeded. Only native declarations take `alternatives`. `database.read` uses it for its SSH read and its read on 209.

The summary holds the facts of Swamp's `@swamp/workflow-summary` report. It comes from the same history, so there is no second read.

A successful run must show the named step succeeded; otherwise the result is unavailable. `native` passes each step's outputs, so a projector can record values such as a verifier from the step that produced it.

A projector reads one step's status with `NativeRunResult::stepStatus($result, '<step name>')`, which gives null for an absent step. A failed run projects `error_code` `operation.native_failed` with this result, and the shared failure detail names the failed step. `captures` work with `native` too. A declaration without `native` reads the selected action's public JSON. New ports select the action that produces their values or declare `native` for the end check; neither adds a report step.

An exact typed step error such as `node.architecture_unsupported` survives as `error_code` in the native step entry. Only a complete dotted code, bounded to 128 characters, qualifies. Exception text and command diagnostics are excluded. A family projector can preserve that code without a reporting step or another history read.

### Scheduled observations

A family can declare `scheduled_result` with a fixed native workflow name and UUID, a fixed `terminal_result` job/step, and a fixed list key in that output. The original admission workflow can end while the Operation remains running. Its original `swamp_run_id` stays unchanged. The family validates its immutable check and candidate identity and decides whether an explicit pending observation remains running or ends the Operation.

The existing shared observer keeps its run-authorized connection for bound-root attachments. Scheduled results are not attached or pushed. A Gateway job runs every minute and asks Swamp which root runs finished, with one `workflow.run.search` over the existing read-only `ORBIT_SWAMP_READ_TOKEN`: the terminal statuses, started within the last hour, in pages of 200. The existing shared history helper then reads exact run metadata for each new run. Official e459 requires `run` for active `run.attach`, so scheduled results never attach and require no added run authority.

> Decided by the orchestrator, for Nick's review: polling replaces the commit feed until a v3 datastore is planned. The feed (`commits.subscribe`) needs a v3 commit log. The Swamp node uses the filesystem datastore, so Swamp refused the feed. A v3 datastore needs a new empty path and does not migrate existing data, so it would also drop the schedule pause state.

A listed run is followed only when its workflow UUID is declared and its name matches the declared name, or when it is a `schedule-<uuid>` root of a Schedule the Gateway holds. Any other run is ignored. Each followed run queues one projection job, keyed by run ID. A run whose projector accepted it is remembered for two hours, and a run already queued is not queued again for 15 minutes. So a repeated read is a no-op. A failed projection is tried once more later, then left alone, because Swamp keeps only the latest versions of a result.

The projector reads the exact history and still refuses a run that is not terminal, so the list is only a hint. A failed read or an unavailable Swamp ends the job without moving the cursor; the next minute reads again.

Swamp inlines a step output in the run history only when it is small. A declaration can name the data behavior of its terminal step (`data`); when the history carries no output for that step, the shared history helper reads its single `output` artifact with `data.get`, at the version the history names. A check result with long output needs this. Swamp keeps only the latest versions of that data, so the read follows the native notice at once. A missing artifact refuses the projection like any unreadable history.

Only verified terminal histories reach the family projector. The workflow UUID/name, selector and public rows must match. Each row carries `operation_id`, `node_id`, `kind`, `root_run_id`, `status` and `result`. Row status is `succeeded` or `failed` for the observation; it does not rewrite the original root. Separate provenance prevents older, duplicate or original-admission replay from replacing newer observations or reopening terminal Operations.

A schedule that belongs to no Operation declares its workflow UUID, name and `terminal_result` in its domain's `scheduled-results.php`, with a `projection` that implements `ScheduledResultProjection`. The projection gets the run and the JSON its result step printed. A failed run passes that JSON too when the step printed one, and an empty result when it did not. `automatic-production-deploy` uses this: its result step prints the fire's events before a failures assert can fail the run, so the Gateway records them anyway.

The shared Gateway cache stores the time of the last successful read, keyed by native address. The next read starts one hour before it, so a run that ends later than it started and a short Gateway outage lose nothing. A new cache starts ten minutes back; older runs are not replayed. The projection of a run is idempotent, so the overlap is safe.

A fire of a Schedule's own workflow is recorded as a task when its run ends, not when it starts. The job also tells each projection about its declared workflow every ten minutes, which the feed did when it connected. There is no timer on history reads of one run and no fallback to a family search.

### Reconciler

`ReconcileSwampOperations` is the one place that settles an Operation with its Swamp run. The scheduler runs it every minute, and the event observer asks for a pass when it sees a run end. Polling stays: the datastore is format 2, which has no commit feed. The same pass frees the Nodes of a Route write that ended without a release and starts the Route writes that wait for them ([One Route write per Node](/reference/operations#one-route-write-per-node)).

Each pass reads every open Operation of a direct kind against Swamp:

- A bound run gets an exact root history read and any declared child evidence reads. A terminal history is projected through `SwampCommandStep::projectTerminal`.
- A nonterminal parent records completed child evidence and stays open. Serve may announce the root’s end before saving history. An unavailable read waits for the next pass.
- An Operation past its budget is expired. A history that shows the run ended within its budget wins.
- An unbound Operation marked `swamp.start_uncertain` gets the exact lookup of its start. So does a timed-out one that still holds its target. The lookup repeats each pass until it binds the run or the hold ends.
- A recorded cancel that Swamp has not confirmed is forwarded for up to an hour. Cancels go after the reads and stop 150 seconds into the pass.
- A timed-out Operation whose run nothing proves ended frees its target after an hour.

An open Operation whose kind has no registry entry is outside these reads. The same pass settles it.

A recorded cancel request sets it to `cancelled`, and cancel does the same at once. Without a request it fails with `operation.timed_out` at its budget. The budget is 600 seconds for an unbound admission with attempts `0`, and the history budget of the kind otherwise. Either end clears the target hold.

The pass has no retry, backoff or per-Operation job. Only one pass runs at a time.

### Kept read connection

Each PHP process keeps one read connection to serve and reuses it across jobs. Serve can close that connection while it sits idle, and PHP cannot see this before the connection is used. The first read on a kept connection then fails on a dead socket.

When a read fails on a kept connection, the Gateway drops it and sends the same read once on a new connection. Every read in this file is idempotent. A read that fails on a new connection is final. The error keeps the exception that caused it as its previous exception, so the Gateway log names the cause. A reply that does not match its request is never sent again.

### Step times

Swamp's step events carry no start or end time, so the steps an Operation records while its run goes have none. The terminal history has both for each step. When the Gateway projects the end of a run, for every kind, it reads the history of the root run and of the runs it started, which serve lists with their parent. It then fills the times of the recorded steps.

A step is matched by its job and name, or by its position when a job repeats a name and both lists have the same number. A step that cannot be matched with certainty keeps its empty times. A time the events already carried stays. A failed read leaves the times empty and never fails the projection.

### Event connection

The existing `orbit:agent-view` Gateway observer keeps the shared native run connection. It joins the private `orbit` record channel; a committed native binding publishes an Operation notice there. At startup and after subscription acknowledgement, it reads active bound Operations once to cover missed notices. It multiplexes `run.attach` requests on its run-authorized socket; receiving events never waits for a workflow to finish.

Only kinds with `terminal_result` are followed through bound-root attachments and project their fixed result. The scheduled read runs as its own Gateway job. The reconciler and the scheduled read run on the operations queue.

The native event connection and shared history reader accept messages and frames up to 8 MiB. Full run metadata and intent can exceed the Reverb client's 64 KiB message limit; Reverb keeps its existing limit. A run beyond the native bound cannot be projected through this connection and must not be treated as successful delivery.

A matching terminal frame is reduced to the Operation ID, bound run ID and actual native status. Laravel dispatches `ReconcileSwampOperations` for a pass; the pass reloads each Operation and its declaration and calls the same `SwampCommandStep` helper. There is no native execution command.

A terminal child event requests the same shared reconciliation pass and keeps the parent attached. The reader verifies ancestry and the declared child result before recording evidence.

An explicit attach `not_found`, or a `run.interrupted` reply naming the bound root, requests the same pass for late-result recovery. Official e459 uses `run.interrupted` when the previous owner's heartbeat is stale; that reply alone does not prove persisted terminal history. The shared helper still requires the exact root's terminal history before projecting.

If projection fails, the observer keeps the binding and attachment without closing the native event socket, and the next scheduled pass reads history again. An interrupted reply can arrive before terminal history; the Operation stays active until a pass sees terminal history. Other bound runs continue on the same socket. A process restart recovers durable bindings through the normal startup snapshot.

Authorization failures and `run.elsewhere` do not invent a terminal result. Successful duplicate deliveries are suppressed on the connection; the helper's row lock and terminal guard protect duplicate delivery after a process restart.

Native transport failures reconnect with exponential backoff from 1 to 60 seconds; a connection must remain healthy for 60 seconds before that backoff resets. Reconnection reattaches remembered active bindings without a retained terminal notice. It does not start, resume, cancel or retry a workflow. Families add no subscriber, callback, timer or status poller. Native resume of an already terminal Operation remains a separate lifecycle contract; this observer does not reopen it.

The start connection can receive `started` and a terminal event in the same message batch. After binding the confirmed root run, `StartSwampRun` announces the binding on the existing private record channel. The observer attaches, or uses its existing explicit late-attach recovery if the run has already finished. Admission never waits for a history request and never queues terminal projection to Horizon. An unavailable terminal result leaves the accepted run bound without changing the start outcome or resubmitting it.

Official e459 supports `run.attach` with `{runId, afterSeq}` for an active run. It returns `run.attached` and streams events on that request id. Completed runs leave the active registry, so this is not a durable subscription after completion. The shared event-connection owner must preserve the terminal event; families must not invent a polling fallback.

After an explicit late-attach miss, the shared subscriber can pass only the already-bound run ID to the same `projectTerminal` method. It reads history once and requires an actual terminal status and the original run/workflow/Operation/Node/kind identity. A supplied event status must still match history exactly. An active run, unavailable transport, authorization refusal or missing history causes no projection; no status is fabricated. This is one result-recovery read, not polling or resubmission.

A terminal event contains metadata, not step outputs. `workflow.history.get` responds with the full run under `payload.data` and includes outputs.

The synthetic dev-worker runs `2ebfa2a6-bb7e-4ad2-8a5b-867095984e93` and `2491206d-0531-442a-b5a5-60b988e989f6` proved success and failure through a second authenticated attach connection, then one history request per terminal event on official e459. This is a transport spike, not the live bootstrap canary.

## Node execution through official SSH

Node 209 hosts Serve and all Swamp workers. Converted Node effects use official `@swamp/ssh` from those workers; they install no target worker or Swamp binary and mint no worker token. Retained workers remain until their owning workflow placements migrate.

`SwampSshExecutionInput::identity()` returns the public control Node ID, existing 209 identity-file locator and approved public key. Bootstrap can read this before a target has a pinned identity; its parent owns first contact. `forNode(Node)` adds the public `target` fields (`name`, `host`, `user`, `port`, `host_keys`) from the stored target account, WireGuard address and complete host key. It refuses incomplete targets. The capture reads no private key, makes no connection and changes no account or access edge. Families compose the official native `task.globalArgs.name`, `transport` and `hosts` from this one capture.

The descriptor does not prove that a target currently authorizes the existing key. Lane 21 owns that coverage proof and worker retirement. Do not assume fleet-wide SSH `AcceptEnv` or sudo `env_keep`. Public Process control arguments can use official `exec.argv`; secret values must use a documented official delivery method and stay out of argv.

### Native failure observation

Workflows have no Gateway failure-report step. The shared terminal subscriber follows kinds that declare `terminal_result`. Normal operation reads retain their existing native failure check. A failed or interrupted run without selected public output records `operation.native_failed`. A converted family maps the native failure in its Gateway projection; retained result steps may still supply a fixed public error code. Schedule runs map a failed native command to `schedule.run_failed`. The Gateway returns `404` for `POST /api/v1/direct-operations/{operation}/failure`.

Unexpected errors in retained `directOperation` actions fail the native step with a fixed public code. They do not send a failed result callback. The normal shared native failure reader owns their terminal settlement. Explicit no-effect cancellation and returned domain refusals retain their existing contracts until those families switch.

A family with admission records may implement `DirectOperationStartRefusal`. The shared start owner calls it inside the failure transaction only after a definite refusal with no bound native run. It closes those records without claiming Node effects. An uncertain submission keeps its pending record and awaits native terminal evidence.

Task build, prep corrections and review rounds capture an optional fresh claim as `Claim a task place`. The child `operation-task-provision` copies the base at the published head. Publication releases the VM through the existing slot release; there is no task park workflow.
