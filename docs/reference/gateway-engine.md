---
title: "Gateway engine"
description: "How the Gateway runs an Operation kind as an event-driven state machine over NATS JetStream, including routing, steps, deadlines, and authentication."
covers:
  - apps/gateway/app/{Domain/Engine/**,Infrastructure/Engine/**,Domain/Firewall/FirewallEngineFlow.php,Domain/Routes/RouteEngineFlow.php,Domain/Processes/ProcessControlEngineFlow.php,Domain/Schedules/ScheduleEnableEngineFlow.php,Domain/Instances/{InstanceCreateEngineFlow,InstanceDestroyEngineFlow,InstanceFleetEngineFlow,InstanceFleetOperation}.php,Domain/Tasks/TaskSyncEngineFlow.php}
  - apps/gateway/app/Console/Commands/{EngineConsumeCommand.php,EngineSetupCommand.php,EngineKeysCommand.php}
  - apps/gateway/database/migrations/{2027_03_10_000000_create_operation_engine_steps_table,2027_03_21_000000_add_fleet_readiness_to_instance_runtimes}.php
  - apps/agent/contract/fixtures/ufw-ensure-rule-input.json
  - swamp/ansible/tasks/gateway-nats.yml
---

# Gateway engine

The Gateway engine runs an Operation as a state machine inside the Gateway. Each step is a typed primitive that the [executor](/reference/executor) runs on its target Node, with NATS JetStream carrying orders, receipts, and engine events ([ADR 0271](/decisions/0271-run-operations-on-a-gateway-state-machine-over-nats)).

Operation kinds move off Swamp one at a time, per Operation. Each Operation runs entirely on the engine or entirely on Swamp, never split across both.

## Which kinds run on the engine

For Operations started through `StartSwampRun`, `EngineKinds::routes()` chooses the engine when all of these hold:

- The engine defines a flow for the kind (`EngineKinds`).
- The kind appears in `ORBIT_ENGINE_KINDS` (or `instance.create` appears there for its child kinds `instance.fleet`, `instance.complete`, and `instance.finalize`). Listing a child kind alone does not enable it.
- Every Node targeted by its steps has reported the required primitive, and each optional input the step needs in its `features`.
- The flow accepts the Operation's intent without throwing `EngineUnsupported`.

When any condition fails, the entire Operation runs on its Swamp workflow. `instance.fleet` and `instance.finalize` are engine-only flows with no Swamp workflow fallback.

For `instance.fleet`, the parent `instance.create` checks `EngineKinds::routes()` on a draft before admitting the wait. A failed check creates no fleet Operation; a passing check admits it and calls `Engine::start()` directly.

An admitted `instance.finalize` bypasses `EngineKinds::routes()` and calls `Engine::start()` directly. `Engine::start()` commits step rows (finalize always records `names`) before publishing the start event, so `engine.start_failed` applies only when no steps were recorded. On a NATS publish outage, the finalize sweep keeps nudging until recovery or `operation.timed_out`. The table below lists all kinds with engine definitions:

| Kind | Steps | Primitives |
| --- | --- | --- |
| `firewall.allow`, `firewall.deny`, `firewall.remove` | `ensure-0` on the rule's Node | `ufw.ensure_rule` |
| `route.create` | `csr-<i>`, `sign-<i>`, `site-<node>`, `dns-<i>`, `probe-<i>` | `tls.ensure_csr`, `route.publish_site`, `dns.publish_records` |
| `route.destroy` | `dns-<i>`, `grace`, `withdraw-<node>`, `sibling-<node>` | `dns.publish_records` (with `dns.guarded_records`), `route.withdraw_site` |
| `route.update` | `csr-<i>`, `sign-<i>`, `site-<node>`, `probe-<i>`, `dns-<i>`, `cutover`, `grace`, `final-<node>`, `withdraw-<node>`, `sibling-<node>` | `tls.ensure_csr`, `route.publish_site`, `dns.publish_records` (with `dns.guarded_records`), `route.withdraw_site` |
| `instance.create` | `clone`, `fetch`, `copy_volume`, `prepare`, `start`, `club_key`, `checkout`, `spare`, `t3`, `stop` | `instance.clone`, `instance.fetch_branch`, `instance.copy_volume`, `instance.prepare`, `instance.start`, `instance.club_key`, `instance.checkout`, `instance.spare_volume`, `instance.t3`, `instance.stop` |
| `instance.destroy` | `dns-<node>`, `withdraw-<node>`, `sibling-<node>`, `release` (a clone with Routes), `remove` | `dns.publish_records`, `route.withdraw_site`, `instance.destroy` |
| `process.start`, `process.stop` | `converge` on the Process's Node | `process.ensure_state` |
| `process.restart` | `read`, then `restart`, on the Process's Node | `process.read_instance`, `process.restart` |
| `schedule.enable` | `timer` on the Schedule's Node | `schedule.ensure_timer` |
| `instance.fleet` (engine-only; follows `instance.create`) | `fleet_ready` | `instance.fleet_ready` |
| `instance.complete` (follows `instance.create`) | zero steps (when nothing remains on the Node) | none |
| `instance.finalize` (engine-only; follows `instance.create`) | `fetch` (on branch switch), `names` | `instance.fetch_branch`, `instance.names` |
| `tasks.sync` (task copies) | `fetch`, `sync`, `clear`, `clear_after_failure` | `instance.fetch_branch`, `instance.sync_base`, `instance.clear_base` |

### route.create

The engine runs the admitted `route_create` plan while preserving Route holds and waiting-create Caddyfile refreshes. Its steps run in this order:

1. `csr-<i>`: The leaf Node creates its private key once and returns a certificate signing request. The key never leaves the Node.
2. `sign-<i>`: The Gateway signs the request using `operation:direct:certificate-sign`. It refuses any pending cancellation.
3. `site-<node>`: `route.publish_site` configures Caddy access, `APP_URL`, symlinks, PHP-FPM pools, leaf certificates, Orbit root trust, and the Caddyfile.
4. `dns-<i>`: `dns.publish_records` updates changed private DNS records on each VPN Node alongside site publication.
5. `probe-<i>`: The Gateway completes a TLS handshake with each serving Node over its WireGuard address, trusting only the Orbit root.

Probes wait only for site publication because they connect by WireGuard address rather than DNS. A failed step records the chart step name, such as `Install cert, publish site-3`, so the Route receives the same `failed_step` and error code as on Swamp.

Routes that need an Ingress edge, Cluster Router LAN rules, a production `.env`, awake markers, or non-standard access commands or PHP-FPM pools stay on Swamp. Routes targeting Nodes without the executor also stay on Swamp, as do the other Route kinds except `route.destroy` and `route.update`.

### route.destroy

The engine runs the admitted withdrawal plan (`route_lifecycle.plan`, the plan of `operation-route-removal`) with the Route Nodes held from admission to the terminal state. Its steps run in this order:

1. `dns-<i>`: `dns.publish_records` writes each VPN Node's records and catalog without the Route. Each file carries the digest of the bytes the Gateway rendered from at admission. Admission requires `dns.guarded_records` in the VPN Node's `features`.
2. `grace`: The Gateway waits until 31 seconds (TTL 30 s + 1 s) after the last DNS receipt, as Swamp's `route-dns-grace` does. It is a [timed Gateway step](#waits).
3. `withdraw-<node>`: `route.withdraw_site` writes the Node's Caddyfile and pools without the Route and removes its leaf folders. It writes only over the Caddyfile the Gateway rendered from. A repeat reports `applied: 0`.
4. `sibling-<node>`: The Gateway completes a TLS handshake with another private Route on that Node, as `route.create`'s probe does. A Node that serves no other Route needs no probe.

The grace survives a restart of the consumer, the Gateway or Horizon: the run waits only what is left, never from zero and never less. A redelivered DNS receipt does not move that time. A cancel during the grace ends the Operation `cancelled` without withdrawing. The DNS answers stay withdrawn and the Route is kept, so the same command removes it.

A failed step records the Swamp chart's step name, such as `Withdraw site, remove cert-3`, so the Route keeps the same `failed_step` and error code and the existing removal projection deletes the Route only after every step succeeds. `--offline`, a replacement Route, a public Route, a Process proxy, LAN rules, the Ingress edge, Caddy access, link or `APP_URL` writes, a PHP version whose last pool leaves, and orders over the signer's limit stay on Swamp.


### route.update

`RouteUpdateEngineFlow` runs a `--domain` change of a private Route from the admitted `route_update` plan (docs/cli/route/engine/update.mmd). Its steps run in this order:

| Step | Work |
| --- | --- |
| `csr-<i>`, `sign-<i>` | The leaves, as for `route.create` |
| `site-<node>` | `route.publish_site` with the early leaves and a Caddyfile that serves the old and the new name side by side |
| `probe-<i>` | The TLS handshake with each serving Node under the new name |
| `dns-<i>` | The guarded `dns.publish_records` on each VPN Node |
| `cutover` | A Gateway transaction that marks the replacement `active` and the old Route `retiring`; its `done` row is the acknowledgement |
| `grace` | A [wait](#waits) that ends 31 s after the latest stored DNS receipt; a resend or retry never shortens it |
| `final-<node>` | `route.publish_site` with the lasting Caddyfile and the late leaves, validated and read back |
| `withdraw-<node>` | `route.withdraw_site` for the old name and its staging leaves, over the lasting Caddyfile |
| `sibling-<node>` | Another site the Node serves, of any Route kind, still answers over TLS; a Node that serves none passes, one without an address fails |

The lasting Caddyfile is both the rendered-from and the desired file of `withdraw-<node>`, so a sibling change since then refuses `owned_revision_conflict`. The action reloads Caddy and reads back that the old name is gone.

A failure after the committed cutover is labelled `Withdraw the old site` or `Wait the DNS grace`, so `NativeRouteUpdateProjection` keeps the replacement authoritative and the old Route `retiring`; it never demotes it. A retry of the same Route and replacement is forward only: when the chain's earlier engine Operation committed the cutover, the retry runs only `grace`, `final-<node>`, `withdraw-<node>` and `sibling-<node>`, from that Operation's stored plan, signed leaves and DNS receipt times.

One chain never mixes engines: a retry of an update that was bound to Swamp, or whose Swamp start was uncertain or timed out unbound, stays on Swamp, and `StartSwampRun` refuses a Swamp start of an engine-started chain with `route.update_engine_chain`. A retry resumes from the attempt that committed the cutover, past any later attempt. Under the Node hold, it refuses with `route.publication_stale` when the Caddyfile the catalog renders for that Node differs from the stored lasting one, because a sibling Route changed there.

A first attempt captures its plan again under the Node hold, before any step is sent, and refuses the same way when its side-by-side or lasting Caddyfile differs from the stored one.

The update is receipt-bound, as `instance.destroy` is. In one transaction the Gateway binds the steps and takes the Route's Operation lock. Neither a failure nor a cancel ends the run while an order it may have published has no terminal receipt; it sends no new order meanwhile. A Gateway step whose worker was lost after it was marked sent runs again at its next deadline or nudge.

An engine-run `route.update` and any other Route write never queue behind each other: whichever is second is refused with `route.publication_held` when both write one Node (a serving or edge Node, a Node the old site leaves, or a VPN Node; `RouteWriteNodes`). The engine start checks in its bind transaction; any other Route write checks right after its record exists, before it holds or dispatches. Swamp writes among themselves keep their publication holds and queue as before.

A timed-out write whose run is not proven stopped counts as writing until it settles. The Route writers whose Nodes are known only at their step (`node.role_relocate`, `instance.register`, `cluster.router_set`, `cluster.router_unset`) and an engine-run update refuse each other fleet-wide.

The Operation lock names the old Route, so `route:destroy` of the replacement refuses with `operation.target_busy` while the update is open.

A public Route, a publication or web root change, the Ingress edge, a LAN rule and every URL write (`APP_URL` lines and whole `.env` files) stay on Swamp. An update that moves private DNS records the VPN Node's records and catalog digests at admission and needs `dns.guarded_records` there; a VPN Node without that feature, or a Node that does not report `route.withdraw_site`, keeps the whole update on Swamp.

### instance.create

The engine runs container clone creation (including task copies, Conn clones, Processes, and nested-fleet clones) from its admitted `direct_provisioning.container` intent using the executor's [instance family](/reference/executor#the-instance-family). Its steps run in this order:

| Step | After | What it does |
| --- | --- | --- |
| `clone` | | Copies the default's seed snapshot with its ACL, NIC, T3 proxy, and route rules. |
| `fetch` | | Fetches the branch into the Node's mirror using a short-lived read credential blob. |
| `copy_volume` | | Creates the inner volume for a nested-fleet copy, taking a [spare volume](/reference/development-containers#spare-volumes) when available. |
| `prepare` | `clone` | Writes only the files that differ from the [seed manifest](/reference/development-containers#the-seed-manifest), delivered as one blob. |
| `start` | `prepare`, `copy_volume` | Verifies both copies match the seed, attaches the volume, checks the network guard, starts the copy, and sets hostnames. |
| `club_key` | `start` | Writes the Club key for a nested-fleet copy into `/run`. |
| `checkout` | `start`, `fetch` | Checks out the branch, cleans a task copy when needed, and merges `.env`. |
| `spare` | `start` | Prepares the default's next spare inner volume in parallel. |
| `t3` | `checkout` | Starts T3 or the Conn Server and returns two sessions (plus an optional reserved-clone pairing link when `instance.t3_pairing` is in `features`) sealed to the Gateway's key. |
| `stop` | `clone` | Failure step that runs only when an earlier step fails. |

The Gateway unseals the sessions without using a vault and records `route_publication: deferred` (`engine_completion`), leaving Route publication to `instance.complete` on Swamp.

### instance.destroy

The engine accepts `--force` for one container clone in development. The clone cannot be the default and must have a saved Incus identity. The plan cannot require database or Valkey cleanup, Process or Schedule teardown, custom teardown, a CI hold release, or waiting for a create or completion. The Node must advertise `instance.destroy`, independently of `instance.create`. Other removals keep their full Swamp workflow.

A clone that owns private Routes withdraws them first, when the admission rendered each Node's whole state without them (`route_engine`). LAN rules, the Ingress edge, and a PHP version whose shared pool empties stay on Swamp. The withdrawal reuses `route.destroy`'s steps without the DNS grace:

1. `dns-<node>`: The guarded `dns.publish_records` removes the Routes' answers.
2. `withdraw-<node>`, `sibling-<node>`: `route.withdraw_site` removes the sites, pools and leaves, then another Route on the Node must still answer.
3. `release`: The Gateway records `route_withdrawn_at` on the removal and frees the Route Nodes, as the Swamp run's release step does.

Every Route must be served by the clone's own Node. The removal holds every Node whose files it replaces alone, DNS Nodes included, until `release` frees them. A removal promoted after waiting runs on Swamp. A failed withdrawal fails as `route.withdrawal_failed` at `Withdraw the Route` before `remove` runs. A retry of a removal that already withdrew its Routes runs `remove` alone.

The Gateway binds the `remove` step and takes the Instance's Operation lock in the same transaction, before publishing the start. The executor takes the same target lock it uses for sleep and wake, force-stops and removes the saved clone, and discards both engines' freeze artifacts. The existing removal projection deletes the records only after the Node confirms the removal.

The removal can still be cancelled during order preparation, deadline scheduling, and setup of the Node's order consumer. After setup and just before publishing, the Gateway checks cancellation and records the first order's signed expiry under the same Operation lock. From then on, a lost order acknowledgement, cancellation, expiry, or order refusal cannot release the Instance lock: none proves that the Node did not act.

The Gateway resends the same step with a fresh nonce only within the Node journal's guaranteed replay window. A repeat must expire by the first order's expiry minus the Node's 10-second acceptance window plus 24 hours of journal retention. After that, the Operation stops issuing orders and stays fenced pending a matching terminal receipt or authoritative recovery. An absent record after compaction does not settle the removal.

A terminal receipt, including the original result in a nonce replay, must match the Node, Operation step ID, action and target. A terminal failure keeps the removal intent for the existing retry rules and exposes a bounded, redacted diagnostic in `error.detail`. If no complete line fits in the tail, the error records that the diagnostic was omitted rather than exposing part of a line.

### process.start and process.stop

The engine runs a start or stop of a host systemd unit or a host Docker container, of a Node Process or an app-dev Instance Process, on a non-production Node only. One step, `converge`, sends `process.ensure_state` with the desired state and the boot policy Swamp's `Set boot policy` applies: enabled at boot only for a running Process that is not on demand. The executor's [process family](/reference/executor#the-process-family) converges and reads the state back.

Eligibility comes from the Process and its placement, for both verbs, so one Process never splits its lifecycle across engines. These throw `EngineUnsupported` and run their whole Swamp workflow:

- a dev-server Process (`vp-dev`, `vite`), whose start publishes a dev route, checks its listener and marks it awake;
- a Process of an Instance not placed on app-dev, such as a production release layout;
- any intent with a `container`: a unit in an Instance container, whose app-account move is a script, and a Gateway release container;
- a Node's host systemd unit that runs Horizon, whose unit carries the renderer's long-drain stop directives, so its stop waits for its drain;
- any Process on a Node the Gateway's own roles do not place in development (`Node::placementEnvironment`, the rule Instance placement reads): an app-prod Node, a Node with both app roles or with neither, a Node with no row, or a Node Process the intent names on another Node;
- any intent with `production_control`, a production release.

The production limit reads only the Gateway's records, never the agent version or the Node's capability report: an app-prod Node that reports the process actions still runs the Swamp workflow. Admitting app-prod is a separate change.

[`process.restart`](#processrestart) takes the same admission. All three kinds are receipt-bound, as `instance.destroy` and `tasks.sync` are (`Engine::ReceiptBound`). The run, its steps and its lock (`process:<id>`, `Engine::fenceKey`) commit in one transaction before the start event. Binding also refuses `operation.target_busy` while another open Operation, on either engine, acts on the Process (`EngineTargetFence`). While the lock is held, Process admission refuses `operation.target_busy`, and so does every synchronous Process writer under the runtime lease (a direct start or restart, a bootstrap or reconverge, a direct removal), before it changes the row or the runtime.

A collector of the AI router checks the lock before it writes or calls its Node again. A removal that would delete a fenced Process (an Instance, a Node, the analytics role, a database server) is refused `operation.target_busy` at admission, and a Process start or stop does not bind while such a removal is open. If a row is gone when the receipt arrives, the run still ends and frees its lock.

No budget fails a sent step and no expiry ends the Operation. A lost receipt is resent under the same step ID with a fresh nonce, and the Node's journal answers the stored result. The Process stays fenced until a terminal receipt. The step settles only on an `applied` receipt from its own Node, action and target.

The end goes through `ProcessControlProjection`, so the success rows and result (`ready`, `state`) are the ones a Swamp run records. A failure records `operation.native_failed` with the Swamp step its phase stands for. That is the change (`Start the Process`, `Stop the container` …), `Stop the unit`, the check (`Check the unit`, `Check the container`) or the assert (`Verify the state`). A cancel waits for a published order's receipt, then records no error code, as a cancelled Swamp run does.

### process.restart

The engine restarts the shapes it starts and stops, with the same eligibility, lock and fence; everything else runs `operation-process-control`. The Node must report both `process.read_instance` and `process.restart`; a Node on an older agent takes the whole Swamp path. Two steps run on the Process's Node part (`<node>:process-<id>`).

The `read` step sends `process.read_instance`, which answers the run in place without changing anything. The `restart` step waits for it and sends `process.restart` with that run pinned in its input. The input also carries the Process's boot policy, as for a start and disabled on demand, or the container's image. Every send of the step rebuilds the same input from the recorded `read` output.

Each step settles only on an `output` receipt that is its evidence (`ProcessControlEngineFlow::evidence`): `{"instance": …}` for `read`, and `{"restarted": "now"|"already"|"recovered", "instance": <non-empty>}` for `restart`. Any other answer fails the step `engine.receipt_invalid`. A lost receipt is resent under the same step ID, and the Node's journal and the order's attempt file keep it to one restart ([the process family](/reference/executor#the-process-family)). A new Operation reads a new run and restarts once more.

The end records through `ProcessControlProjection`, as a Swamp restart's does: `ready` and `state` `active` or `running`, and the desired state `running`. A failed `read` records the first Swamp step on the Process (`Set boot policy` for a unit, `Restart the container`). A restart's `process.boot_failed` also records `Set boot policy`. A failed `restart` otherwise records the change (`Restart the Process`, `Restart the container`), including its read before the restart, the check (`Check the unit`, `Check the container`) for a read after it, or `Verify the state` for a read-back in another state or on the pinned run. The evidence check takes the members in any order.

### schedule.enable

The engine enables a retained host Node timer (`trigger: node`). One step, `timer`, sends `schedule.ensure_timer` for the Schedule's own `orbit-schedule-<uuid>.timer`; the end goes through `ScheduleControlProjection` (`ready: true`).

The kind is receipt-bound like a Process start. The run holds the Schedule's own lock (`schedule:<uuid>`), so the owner's other Schedules stay free. Its step settles only on an `output` receipt whose read-back shows the timer enabled and active with an answered next elapse (`EngineEvidence`); other output fails it as `engine.receipt_invalid`.

While the run holds its lock, any admission for that Schedule, a create's reservation included, refuses `operation.target_busy` before changing the row. A failure records `operation.native_failed` with failed step `timer`, `check_timer` or `Check the timer`, by the phase that failed. A native cron Schedule keeps its synchronous Swamp trigger and never reaches the engine. A timer in a production release (`production_control`) or one that requests runs inside an Instance container (`container`) throws `EngineUnsupported` and runs `operation-schedule-control` or `production-schedule-control`.

### tasks.sync

The engine runs `task:sync` of a [task copy](/reference/tasks#bring-main-into-a-copy) (an intent with `copy`) as the chart `docs/cli/task/engine/sync.mmd` draws it. The copy's Node must report `instance.fetch_branch`, `instance.sync_base` and `instance.clear_base`, and the feature `instance.fetch_seed`. The intent must name the group's own running copy on that Node, with its runtime, branch, mirrors and published commits in the flow's shape. Any other intent, a Project that reads with the Gateway's GitHub CLI login, or a Node without one of them runs the whole `operation-task-sync-copy` workflow.

| Step | After | What it does |
| --- | --- | --- |
| `fetch` | | `instance.fetch_branch` with the default branch into the copy's mirror, with the read token minted at routing. |
| `sync` | `fetch` | `instance.sync_base` with the head the fetch receipt returned, pinned in the step input. |
| `clear` | `sync` | `instance.clear_base` removes the Node scratch and the copy's bundle folder. |
| `clear_after_failure` | | The same cleanup as a failure step, after a failed `fetch` or `sync`, as the Swamp cleanup job runs after either. |

Routing decides admission once: the Node's features, the copy's saved runtime, the repository, the default's mirror and the credential mode. Then it mints the token and keeps it encrypted in the Gateway cache for the journal's replay window or until the Operation ends. The bound steps and every order, resend and cleanup come from the pinned intent alone, so a resend sends the same blob to the copy's Node only; no copy step names it.

A missing copy mirror is made from the default's mirror, and refused without one, as on Swamp.

The seed checks the default's mirror before it changes anything, replaces only an empty folder in the mirror's place, and leaves a folder that holds anything else untouched.

If the Gateway cache loses the credential before the first `fetch` order is published, the run mints it again with the admitted access mode and uploads it with that order; after publication, a resend names the kept digest. The publish history reaches `sync` as a blob. Because the head is pinned, a resend imports the same commit even when origin moved. A null head fails as `tasks.default_branch_missing`. When the remote ref moved since its read, the import fails as `tasks.base_ref_mismatch` and leaves the ref as it is.

Like `instance.destroy`, the kind is receipt-bound. The sync takes the copy's Instance lock when it binds its steps, so no removal of the copy is admitted beside it; the running Operation fences the group. A sync on Swamp takes no Instance lock, as before.

While the kind is enabled, a sync whose copy's removal was admitted first is refused with `operation.target_busy` before routing, so neither the engine nor the Swamp fallback starts it, so one copy never has two writers. No budget fails a sent step, and no expiry ends the Operation. A lost receipt is resent under the same step ID within the journal's replay window, so the group stays fenced until each step's terminal receipt.

Each step settles only by its own evidence (the mirror head, the base evidence or the cleanup); other output fails it as `engine.receipt_invalid`. An order the signer refuses fails its step as `engine.order_invalid` instead of waiting.

A cancel sends no new step. It waits for each published order's receipt, then runs `clear_after_failure` and ends cancelled with no field and no error, as a cancelled Swamp run does. `TaskSyncProjection` records the `sync` receipt's evidence as the same result a Swamp run records; a failed step names `Bring in the base` or `Discard the base scratch` with `tasks.sync_failed`, or the import's own refusal code.

### instance.fleet

`instance.fleet` waits for the inner fleet of a nested-fleet clone (`instance.fleet_ready`) in the background alongside `instance.complete`, updating `fleet_ready_at`, `fleet_failed_at`, and `fleet_error_code` on the Instance without holding an Operation lock ([A clone starts its fleet](/reference/development-containers#a-clone-starts-its-fleet)).

### instance.complete

When `instance.create` recorded `services: seed` and no database, Valkey ACL user, deferred Route, custom Process, or nested fleet start remains on the Node, `instance.complete` settles immediately on the engine with zero steps ([A completion with nothing left](/reference/development-containers#a-completion-with-nothing-left)).

### instance.finalize

For a [reserved clone](/reference/conn#reserve-a-clone), `instance.finalize` runs `fetch` (`instance.fetch_branch`) when switching branches, then `names` (`instance.names`) to update the copy's branch and machine names. `names` sends `SIGHUP` first to live-reload Conn Server and preserve active connections, restarting T3 once only if reload fails, then polls until the new label appears.

### The workspace read

The workspace unsaved-work read (`ContainerWorkspaceRead`, which Conn's Remove and the unacknowledged destroy call) is no kind: it is a synchronous [signed read](/reference/executor#signed-reads) of `instance.work_read`, decided on each read by the Node's report. A Node that reports the action answers it; any other Node, or a Gateway without a bus, is read over SSH exactly as before. Both paths give the same answer (`instance/container/work-read-engine.mmd`).

### Capability reporting

An executor reports `{actions, node_targets, features, version, clock_synchronized}` to key `n<node>` in the `NODES` bucket when it connects and every minute after (`node_targets` lets actions like `instance.club_key` run beside `checkout` and `t3` under `<node>:<part>` targets, and `features` advertises optional action inputs such as `instance.t3_pairing`). Entries expire after five minutes, so a stopped executor stops receiving engine Operations. `EngineBus::client()` opens publish connections with `reconnect: false`, pings connections idle for 10 seconds, and replaces failed sockets immediately.

If the Gateway cannot read the bucket (for example during a `nats-server` restart), Operations with Node steps routed through `StartSwampRun` fall back to Swamp and `instance.fleet` checks admit no wait. A zero-step `instance.complete` never reads `KV_NODES`, so with `instance.create` in `ORBIT_ENGINE_KINDS` it still selects the engine and fails through `StartSwampRun` if publishing its start event fails, with no Swamp fallback. An already-admitted `instance.finalize` also bypasses the bucket read and keeps nudging through its sweep until the bus recovers or `operation.timed_out`.

`clock_synchronized` comes from `adjtimex`. Doctor warns `node.clock_unsynchronized` when it is false because clock drift causes the executor to reject signed orders.

## Steps and transitions

`Engine::start` inserts one row per step in `operation_engine_steps`, sets `state.swamp_run_id` to `engine-<id>`, and publishes a start event. Every state transition runs inside `advance` under a single database transaction locking the Operation and its steps:

- A pending step whose dependencies are done is dispatched.
- A sent step past its resend deadline is dispatched again with a fresh order.
- A waiting Gateway step whose time has come runs again.
- A sent step past its budget fails with `engine.step_budget_exceeded`.
- The Operation fails as soon as any step fails, and succeeds once all steps finish.
- A cancel request ends the Operation at the next transition, except after all steps finish.

Orders publish after the transaction commits. For each dispatched step, the engine schedules its deadline first and then publishes the order. An unbuildable order or one exceeding 4 KB fails with `engine.order_invalid`. A publish error resets the step to pending and negatively acknowledges the event for retry after one second.

The engine mirrors step state into `state.native_progress` so `operation:show` matches Swamp output. At completion, it calls the kind's domain projection; if that throws, the event redelivers and retries.

### Orders and receipts

Each step is one signed order with `operation` set to `<id>:<step>` and `target` set to the resource being mutated. For `ufw.ensure_rule`, the target is the Node, serializing firewall writes per Node.

Engine orders expire 8 seconds after signing, and the executor refuses expiries more than 10 seconds ahead. A `refused` receipt with `expired` or a `failed` receipt with `executor.order_expired` resets the step to pending for resend. Signed removal instead keeps its publication fence and the scheduled retry. A `replayed_nonce` receipt applies the original result when present.

The engine reads the Node ID from the receipt subject (`orbit.receipts.<node>`), never from the payload, and terminates unreadable receipts. A receipt's `output` field carries public text needed by later steps, such as a CSR. Its `applied` count reports changes made: for firewall kinds, zero changes records a skip with `firewall.rule_absent` for `firewall.remove` and `firewall.rule_exists` for allow or deny.

### Content

Orders are capped at 4 KB. Larger or sensitive payloads, such as Caddyfiles, certificates, or credentials, travel as blobs: `EngineContent` writes the bytes to `$KV.CONTENT.n<node>.<sha256>` before signing, and the order references only `{"blob": {"sha256": "..."}}`. The executor fetches each blob into a root-only cache and verifies its hash before running the primitive.

### Deadlines

Deadlines use NATS message schedules rather than polling. After sending a step, the engine publishes to `orbit.engine.schedule.<id>.<step>.<sends>` with `Nats-Schedule: @at <time>` and `Nats-Schedule-Target: orbit.engine.deadline`. Stale deadlines for steps that already moved on are ignored.

Each flow sets its own resend interval and step budget:

| Flow | Resend interval | Step budget |
| --- | --- | --- |
| Firewall (`ufw.ensure_rule`) | 10 seconds | 120 seconds |
| Route (`csr`, `site`, `dns`) | 15–30 seconds | 60–180 seconds |
| Instance create (`clone`, `fetch`, `prepare`, `start`, `checkout`, `t3`) | 30–120 seconds | 120–600 seconds |

Every minute, the reconciler sends an immediate deadline nudge to any unfinished engine Operation with a pending step, or a sent or waiting step more than 30 seconds past its resend time. This recovers starts or orders lost if the Gateway stopped between commit and publish. The reconciler also ends engine Operations whose overall budget expires.

### Waits

A kind that implements `EngineWait` names the time before which a Gateway step must not run, from the steps as recorded. When such a step is ready before that time, the engine marks it sent, keeps the time as its `resend_at_ms`, and schedules its deadline for it. No process sleeps through the wait. The deadline, or the reconciler's nudge if the deadline was lost, runs the step once the time has passed; an earlier event runs nothing. A cancel during the wait sends nothing more, because the step published no order.

`route.destroy` and `route.update` wait their DNS grace this way. A forward retry of `route.update` times it from the receipts of the attempt that committed the cutover.

### Cancel

`operation:cancel` sets `cancel_requested_at` and sends a nudge. The next transition ends the Operation as `cancelled` (`operation.cancelled`) and dispatches no further steps. Orders already on a Node still finish, and if every step has already ended, their real result wins over the cancel.

## The machine bus

`nats-server` runs on the Gateway and listens only on the Gateway's WireGuard address on `ORBIT_NATS_PORT` (default `4222`). Both `orbit:engine-setup` and the consumer create these JetStream streams idempotently:

| Stream | Subjects | Retention | Consumers |
| --- | --- | --- | --- |
| `ORDERS` | `orbit.node.<node>.orders` | Work queue, 1 h | `node-<node>`, pulled by that Node's executor |
| `RECEIPTS` | `orbit.receipts.<node>` | Work queue, 1 h | `gateway-receipts`, pushed to the engine |
| `ENGINE` | `orbit.engine.>` | Limits, 24 h, message schedules | `gateway-engine` for start and deadline events |
| `KV_CONTENT` | `$KV.CONTENT.n<node>.<sha256>` | One per key, 1 h | Direct get by the Node |
| `KV_NODES` | `$KV.NODES.n<node>` | One per key, 5 min, no rollup | Direct get by the Gateway |

The Gateway creates a Node's `node-<node>` consumer before publishing its first order. Publishes wait for JetStream acknowledgement, and orders use their nonce as `Nats-Msg-Id` so retries land once.

### The consumer process

`orbit-gateway-engine.service` runs `php artisan orbit:engine-consume` to process receipts, engine events, and auth callout requests. Each message handler returns one verdict: acknowledge on completion, negatively acknowledge with a 1-second delay on exception, or terminate an unreadable message.

Because the PHP client only answers server pings while reading, `EngineBus::client()` pings any connection idle for 10 seconds and replaces it if the ping fails.

## Authentication

Nodes hold no NATS credentials. The bus admits each Node by its WireGuard address, matching the Gateway API trust boundary.

The Gateway environment stores one nkey account seed in `ORBIT_NATS_SEED`, generated with `php artisan orbit:engine-keys`. Without `ORBIT_NATS_SEED`, the Gateway runs no `nats-server` and exposes no bus address. `nats.conf` contains only public keys: the Gateway's derived nkey user (full subject access) and the auth callout issuer.

When any other client connects, `nats-server` sends an authorization request to `$SYS.REQ.USER.AUTH` and the consumer process answers it:

1. Read the client's IP address from the request.
2. Look up the active Node with that WireGuard address; refuse unknown or inactive addresses with `peer.identity_unknown`.
3. Sign a user JWT named `node-<id>` valid for one hour and scoped to that Node's subjects.

When the JWT expires, the server disconnects the client and it re-authenticates, so a removed Node loses bus access within an hour. Rotating `ORBIT_NATS_SEED` requires no changes on Nodes.

Because all processes on a Node share its WireGuard address, each Node also loads an nftables table (`orbit_bus_guard`) that rejects connections to the Gateway's NATS port from any user except `root` (plus the Gateway runtime user on the Gateway Node). A Node's user JWT allows only:

- Reading, pulling, and acknowledging its own `node-<id>` consumer.
- Publishing receipts to `orbit.receipts.<id>`.
- Direct gets of its own content keys `n<id>.*`.
- Writing its own capability key `$KV.NODES.n<id>`.
- Subscribing to its own inbox prefix `_INBOX.node-<id>.>`.
- Subscribing to its own [signed reads](/reference/executor#signed-reads) on `orbit.node.<id>.reads`, which only the Gateway publishes, and one reply to each request within five seconds (`resp`).

Order signatures remain the execution boundary: neither the bus nor another Node can forge an order.

### Delivery to a Node

Node convergence writes `/etc/orbit/executor/nats.json` (containing only the bus URL) and `/etc/orbit/executor/bus-guard.nft` (loaded by `orbit-executor.service` before start). Without `ORBIT_NATS_SEED`, convergence removes both files and drops the nftables table so the executor serves only its local socket.

## Install

Converging the `gateway` role installs the bus whenever `ORBIT_NATS_SEED` is set: `gateway-nats.yml` installs the SHA-256 pinned `nats-server` binary under the `nats` user, `GatewayRuntimeFiles` renders `/etc/nats/nats.conf`, `nats-server.service`, and `orbit-gateway-engine.service`, and `NodeFirewallRuleCatalog` opens `orbit:gateway-nats` on the `orbit` WireGuard interface.

To enable the engine on a fleet:

1. Generate `ORBIT_NATS_SEED` with `php artisan orbit:engine-keys` and add it to the Gateway environment.
2. Converge the `gateway` role, then converge the target Nodes.
3. Add the desired kinds to `ORBIT_ENGINE_KINDS`.
4. Reload the Gateway configuration and services (`gateway:release:configure`, web workers, `orbit-gateway-engine.service`, and Horizon).

Running workers keep the `ORBIT_ENGINE_KINDS` list read at startup, so skipping step 4 leaves them routing to Swamp. To disable the engine, clear `ORBIT_ENGINE_KINDS` and repeat step 4. Removing `ORBIT_NATS_SEED` stops rendering bus files, but you must stop and disable `orbit-gateway-engine.service` and `nats-server.service` by hand.
