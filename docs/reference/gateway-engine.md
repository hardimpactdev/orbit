---
title: "Gateway engine"
description: "How the Gateway runs an Operation kind as an event-driven state machine over NATS JetStream, including routing, steps, deadlines, and authentication."
covers:
  - apps/gateway/app/{Domain/Engine/**,Infrastructure/Engine/**,Domain/Firewall/FirewallEngineFlow.php,Domain/Routes/RouteEngineFlow.php,Domain/Instances/{InstanceCreateEngineFlow,InstanceDestroyEngineFlow,InstanceFleetEngineFlow,InstanceFleetOperation}.php}
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
| `route.update` | `csr-<i>`, `sign-<i>`, `site-<node>`, `probe-<i>`, `dns-<i>`, `cutover`, `grace`, `late-<node>`, `withdraw-<node>`, `sibling-<node>` | `tls.ensure_csr`, `route.publish_site`, `dns.publish_records` (with `dns.guarded_records`), `route.withdraw_site` |
| `instance.create` | `clone`, `fetch`, `copy_volume`, `prepare`, `start`, `club_key`, `checkout`, `spare`, `t3`, `stop` | `instance.clone`, `instance.fetch_branch`, `instance.copy_volume`, `instance.prepare`, `instance.start`, `instance.club_key`, `instance.checkout`, `instance.spare_volume`, `instance.t3`, `instance.stop` |
| `instance.destroy` | `dns-<node>`, `withdraw-<node>`, `sibling-<node>`, `release` (a clone with Routes), `remove` | `dns.publish_records`, `route.withdraw_site`, `instance.destroy` |
| `instance.fleet` (engine-only; follows `instance.create`) | `fleet_ready` | `instance.fleet_ready` |
| `instance.complete` (follows `instance.create`) | zero steps (when nothing remains on the Node) | none |
| `instance.finalize` (engine-only; follows `instance.create`) | `fetch` (on branch switch), `names` | `instance.fetch_branch`, `instance.names` |

### route.create

The engine runs the admitted `route_create` plan while preserving Route holds and waiting-create Caddyfile refreshes. Its steps run in this order:

1. `csr-<i>`: The leaf Node creates its private key once and returns a certificate signing request. The key never leaves the Node.
2. `sign-<i>`: The Gateway signs the request using `operation:direct:certificate-sign`. It refuses any pending cancellation.
3. `site-<node>`: `route.publish_site` configures Caddy access, `APP_URL`, symlinks, PHP-FPM pools, leaf certificates, Orbit root trust, and the Caddyfile.
4. `dns-<i>`: `dns.publish_records` updates changed private DNS records on each VPN Node alongside site publication.
5. `probe-<i>`: The Gateway completes a TLS handshake with each serving Node over its WireGuard address, trusting only the Orbit root.

Probes wait only for site publication because they connect by WireGuard address rather than DNS. A failed step records the chart step name, such as `Install cert, publish site-3`, so the Route receives the same `failed_step` and error code as on Swamp.

Routes that need an Ingress edge, Cluster Router LAN rules, a production `.env`, awake markers, or non-standard access commands or PHP-FPM pools stay on Swamp. Routes targeting Nodes without the executor also stay on Swamp, as do `route.update` shapes the next section excludes.

### route.destroy

The engine runs the admitted withdrawal plan (`route_lifecycle.plan`, the plan of `operation-route-removal`) with the Route Nodes held from admission to the terminal state. Its steps run in this order:

1. `dns-<i>`: `dns.publish_records` writes each VPN Node's records and catalog without the Route. Each file carries the digest of the bytes the Gateway rendered from at admission. Admission requires `dns.guarded_records` in the VPN Node's `features`.
2. `grace`: The Gateway waits until 31 seconds (TTL 30 s + 1 s) after the last DNS receipt. A redelivered receipt does not move that time. A repeat waits only what is left.
3. `withdraw-<node>`: `route.withdraw_site` writes the Node's Caddyfile and pools without the Route and removes its leaf folders. It writes only over the Caddyfile the Gateway rendered from. A repeat reports `applied: 0`.
4. `sibling-<node>`: The Gateway completes a TLS handshake with another private Route on that Node, as `route.create`'s probe does. A Node that serves no other Route needs no probe.

A failed step records the Swamp chart's step name, such as `Withdraw site, remove cert-3`, so the Route keeps the same `failed_step` and error code and the existing removal projection deletes the Route only after every step succeeds. `--offline`, a replacement Route, LAN rules, the Ingress edge, Caddy access, link or `APP_URL` writes for a surviving Route, and pool changes on a Node the Route does not leave stay on Swamp.

### route.update

The engine runs a private Route's `--domain` change from the admitted `route_update.plan`. Its steps run in this order:

1. `csr-<i>`, `sign-<i>`, `site-<node>`: The new leaves and the prepared Caddyfile, which serves the old and the new name side by side, as `route.create` publishes them. The site also writes the new `APP_URL` lines.
2. `probe-<i>`: The Gateway probes the new name on each serving Node before any switch.
3. `dns-<i>`: The guarded `dns.publish_records` moves the answers to the new name.
4. `cutover`: The Gateway records the replacement authoritative and the old Route retiring.
5. `grace`, `late-<node>`: After the 31-second grace, a new version of a scope the old site still names installs.
6. `withdraw-<node>`, `sibling-<node>`: `route.withdraw_site` writes the lasting Caddyfile over the prepared one and removes the old leaves. Another Route on the Node must still answer.

A failure before the cutover keeps the old Route and marks the replacement failed. A failure or cancel after it keeps the replacement authoritative and the old Route retiring, so a retry recovers forward. `--publication`, public Routes, LAN rules, the Ingress edge, analytics changes and whole `.env` writes stay on Swamp. A Laravel development target rewrites its whole `.env`, so its update stays on Swamp. An update with no Node work still settles at admission with no run.

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

A failed withdrawal fails as `route.withdrawal_failed` at `Withdraw the Route` before `remove` runs. A retry of a removal that already withdrew its Routes runs `remove` alone.

The Gateway binds the `remove` step and takes the Instance's Operation lock in the same transaction, before publishing the start. The executor takes the same target lock it uses for sleep and wake, force-stops and removes the saved clone, and discards both engines' freeze artifacts. The existing removal projection deletes the records only after the Node confirms the removal.

The removal can still be cancelled during order preparation, deadline scheduling, and setup of the Node's order consumer. After setup and just before publishing, the Gateway checks cancellation and records the first order's signed expiry under the same Operation lock. From then on, a lost order acknowledgement, cancellation, expiry, or order refusal cannot release the Instance lock: none proves that the Node did not act.

The Gateway resends the same step with a fresh nonce only within the Node journal's guaranteed replay window. A repeat must expire by the first order's expiry minus the Node's 10-second acceptance window plus 24 hours of journal retention. After that, the Operation stops issuing orders and stays fenced pending a matching terminal receipt or authoritative recovery. An absent record after compaction does not settle the removal.

A terminal receipt, including the original result in a nonce replay, must match the Node, Operation step ID, action and target. A terminal failure keeps the removal intent for the existing retry rules and exposes a bounded, redacted diagnostic in `error.detail`. If no complete line fits in the tail, the error records that the diagnostic was omitted rather than exposing part of a line.

### instance.fleet

`instance.fleet` waits for the inner fleet of a nested-fleet clone (`instance.fleet_ready`) in the background alongside `instance.complete`, updating `fleet_ready_at`, `fleet_failed_at`, and `fleet_error_code` on the Instance without holding an Operation lock ([A clone starts its fleet](/reference/development-containers#a-clone-starts-its-fleet)).

### instance.complete

When `instance.create` recorded `services: seed` and no database, Valkey ACL user, deferred Route, custom Process, or nested fleet start remains on the Node, `instance.complete` settles immediately on the engine with zero steps ([A completion with nothing left](/reference/development-containers#a-completion-with-nothing-left)).

### instance.finalize

For a [reserved clone](/reference/conn#reserve-a-clone), `instance.finalize` runs `fetch` (`instance.fetch_branch`) when switching branches, then `names` (`instance.names`) to update the copy's branch and machine names. `names` sends `SIGHUP` first to live-reload Conn Server and preserve active connections, restarting T3 once only if reload fails, then polls until the new label appears.

### Capability reporting

An executor reports `{actions, node_targets, features, version, clock_synchronized}` to key `n<node>` in the `NODES` bucket when it connects and every minute after (`node_targets` lets actions like `instance.club_key` run beside `checkout` and `t3` under `<node>:<part>` targets, and `features` advertises optional action inputs such as `instance.t3_pairing`). Entries expire after five minutes, so a stopped executor stops receiving engine Operations. `EngineBus::client()` opens publish connections with `reconnect: false`, pings connections idle for 10 seconds, and replaces failed sockets immediately.

If the Gateway cannot read the bucket (for example during a `nats-server` restart), Operations with Node steps routed through `StartSwampRun` fall back to Swamp and `instance.fleet` checks admit no wait. A zero-step `instance.complete` never reads `KV_NODES`, so with `instance.create` in `ORBIT_ENGINE_KINDS` it still selects the engine and fails through `StartSwampRun` if publishing its start event fails, with no Swamp fallback. An already-admitted `instance.finalize` also bypasses the bucket read and keeps nudging through its sweep until the bus recovers or `operation.timed_out`.

`clock_synchronized` comes from `adjtimex`. Doctor warns `node.clock_unsynchronized` when it is false because clock drift causes the executor to reject signed orders.

## Steps and transitions

`Engine::start` inserts one row per step in `operation_engine_steps`, sets `state.swamp_run_id` to `engine-<id>`, and publishes a start event. Every state transition runs inside `advance` under a single database transaction locking the Operation and its steps:

- A pending step whose dependencies are done is dispatched.
- A sent step past its resend deadline is dispatched again with a fresh order.
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

Every minute, the reconciler sends an immediate deadline nudge to any unfinished engine Operation with a pending step or a sent step more than 30 seconds past its resend time. This recovers starts or orders lost if the Gateway stopped between commit and publish. The reconciler also ends engine Operations whose overall budget expires.

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
