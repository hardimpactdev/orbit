---
title: "Gateway engine"
description: "How the Gateway runs an Operation kind as an event-driven state machine over NATS JetStream, including routing, steps, deadlines, and authentication."
covers:
  - apps/gateway/app/{Domain/Engine/**,Infrastructure/Engine/**,Domain/Firewall/FirewallEngineFlow.php,Domain/Routes/RouteEngineFlow.php,Domain/Instances/InstanceCreateEngineFlow.php}
  - apps/gateway/app/Console/Commands/{EngineConsumeCommand.php,EngineSetupCommand.php,EngineKeysCommand.php}
  - apps/gateway/database/migrations/2027_03_10_000000_create_operation_engine_steps_table.php
  - apps/agent/contract/fixtures/ufw-ensure-rule-input.json
  - swamp/ansible/tasks/gateway-nats.yml
---

# Gateway engine

The Gateway engine runs an Operation as a state machine inside the Gateway. Each step is a typed primitive that the [executor](/reference/executor) runs on its target Node, with NATS JetStream carrying orders, receipts, and engine events ([ADR 0271](/decisions/0271-run-operations-on-a-gateway-state-machine-over-nats)).

Operation kinds move off Swamp one at a time, per Operation. Each Operation runs entirely on the engine or entirely on Swamp, never split across both.

## Which kinds run on the engine

`StartSwampRun` chooses between the engine and Swamp after admitting an Operation, keeping admission, locks, and API responses identical. A kind runs on the engine when all of these hold:

- The engine defines a flow for the kind (`EngineKinds`).
- The kind appears in `ORBIT_ENGINE_KINDS`, a Gateway environment variable holding a comma-separated list.
- Every Node targeted by its steps has reported the required primitive.
- The flow accepts the Operation's intent without throwing `EngineUnsupported`.

Otherwise the Operation runs on its Swamp workflow. The table below lists the kinds with engine definitions:

| Kind | Steps | Primitives |
| --- | --- | --- |
| `firewall.allow`, `firewall.deny`, `firewall.remove` | `ensure-0` on the rule's Node | `ufw.ensure_rule` |
| `route.create` | `csr-<i>`, `sign-<i>`, `site-<node>`, `dns-<i>`, `probe-<i>` | `tls.ensure_csr`, `route.publish_site`, `dns.publish_records` |
| `instance.create` | `clone`, `fetch`, `prepare`, `start`, `checkout`, `t3` | `instance.clone`, `instance.fetch_branch`, `instance.prepare`, `instance.start`, `instance.checkout`, `instance.t3` |

### route.create

The engine runs the admitted `route_create` plan while preserving Route holds and waiting-create Caddyfile refreshes. Its steps run in this order:

1. `csr-<i>`: The leaf Node creates its private key once and returns a certificate signing request. The key never leaves the Node.
2. `sign-<i>`: The Gateway signs the request using `operation:direct:certificate-sign`. It refuses any pending cancellation.
3. `site-<node>`: `route.publish_site` configures Caddy access, `APP_URL`, symlinks, PHP-FPM pools, leaf certificates, Orbit root trust, and the Caddyfile.
4. `dns-<i>`: `dns.publish_records` updates changed private DNS records on each VPN Node alongside site publication.
5. `probe-<i>`: The Gateway completes a TLS handshake with each serving Node over its WireGuard address, trusting only the Orbit root.

Probes wait only for site publication because they connect by WireGuard address rather than DNS. A failed step records the chart step name, such as `Install cert, publish site-3`, so the Route receives the same `failed_step` and error code as on Swamp.

Routes that need an Ingress edge, Cluster Router LAN rules, a production `.env`, awake markers, or non-standard access commands or PHP-FPM pools stay on Swamp. Routes targeting Nodes without the executor also stay on Swamp, as do other Route kinds such as `route.update`.

### instance.create

The engine runs container clone creation from its admitted `direct_provisioning.container` intent using the executor's [instance family](/reference/executor#the-instance-family). Its steps run in this order:

1. `clone`: Copies the default's seed snapshot with its ACL, NIC, T3 proxy, and route rules.
2. `fetch`: Runs beside `clone` to fetch the branch into the Node's mirror using a short-lived read credential blob.
3. `prepare`: Places the stopped copy's files before first boot, delivered as a single blob.
4. `start`: Checks the network guard, starts the copy, and configures its hostnames.
5. `checkout`: Checks out the branch and merges `.env` from the seed and the Gateway's render.
6. `t3`: Starts T3, registers the project, and returns two sessions sealed to the Gateway's key.

The Gateway unseals the sessions and passes its own to the create projection without using a vault. The create records `route_publication: deferred` with reason `engine_completion`, leaving Route publication to `instance.complete` on Swamp.

Default Instance creates stay on Swamp, along with clones that use nested-fleet defaults, task copies, branch selections, attached Node services, Processes, CI holds, already-active creates, or non-TCP firewall rules.

Keep `instance.create` out of `ORBIT_ENGINE_KINDS` until `instance.prepare` merges JSON settings keys instead of replacing top-level keys whole. Replacing top-level keys drops agent-owned provider instances and rewrites keys already moved into the Conn Server store.

### Capability reporting

An executor reports `{actions, version, clock_synchronized}` to key `n<node>` in the `NODES` bucket when it connects and every minute after. Entries expire after five minutes, so a stopped executor stops receiving engine Operations. If the Gateway cannot read the bucket (for example during a `nats-server` restart), Operations fall back to Swamp.

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

Engine orders expire 8 seconds after signing, and the executor refuses expiries more than 10 seconds ahead. A `refused` receipt with `expired` or a `failed` receipt with `executor.order_expired` resets the step to pending for resend. A `replayed_nonce` receipt applies the original result when present.

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
