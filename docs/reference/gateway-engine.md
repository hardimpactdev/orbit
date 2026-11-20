---
title: "Gateway engine"
description: "How the Gateway runs an Operation kind as an event-driven state machine over NATS JetStream: routing a kind off Swamp, steps and receipts, deadlines, the machine bus, how Nodes join it and its install."
covers:
  - apps/gateway/app/{Domain/Engine/**,Infrastructure/Engine/**,Domain/Firewall/FirewallEngineFlow.php,Domain/Routes/RouteEngineFlow.php,Domain/Instances/InstanceCreateEngineFlow.php}
  - apps/gateway/app/Console/Commands/{EngineConsumeCommand.php,EngineSetupCommand.php,EngineKeysCommand.php}
  - apps/gateway/database/migrations/2027_03_10_000000_create_operation_engine_steps_table.php
  - apps/agent/contract/fixtures/ufw-ensure-rule-input.json
  - swamp/ansible/tasks/gateway-nats.yml
---

# Gateway engine

The Gateway engine runs an Operation as a state machine in the Gateway. Each step is a typed primitive that the [executor](/reference/executor) runs on its Node. NATS JetStream carries the orders, the receipts and the engine's own events. [ADR 0271](/decisions/0271-run-operations-on-a-gateway-state-machine-over-nats) records why.

Operation kinds move off Swamp one at a time, per Operation ([ADR 0271](/decisions/0271-run-operations-on-a-gateway-state-machine-over-nats) N4). Each Operation runs entirely on the engine or entirely on Swamp, never half on each. Once the switch for a kind is proven, a separate pull request removes the kind's Swamp workflows.

## Which kinds run on the engine

A kind runs on the engine when all of these hold:

- the engine has a definition for the kind (`EngineKinds`);
- the kind is listed in `ORBIT_ENGINE_KINDS`, a comma-separated list in the Gateway environment;
- every Node that one of its steps targets has reported the step's primitive;
- the kind's definition can run this Operation's intent: a definition that meets a part it does not run yet throws `EngineUnsupported`.

Otherwise the kind starts its Swamp workflow as before. `StartSwampRun` makes this choice after it admits the Operation, so admission, locks and the API stay the same for both paths.

Kinds with a definition today:

| Kind | Steps | Primitive |
| --- | --- | --- |
| `firewall.allow`, `firewall.deny`, `firewall.remove` | `ensure-0` on the rule's Node | `ufw.ensure_rule` |
| `route.create` | `csr-<i>` on each leaf's Node, `sign-<i>` on the Gateway, `site-<node>` on each Node with Route work, `probe-<i>` on the Gateway | `tls.ensure_csr`, `route.publish_site` |

### route.create

The engine runs the plan the Gateway admitted (`route_create`). Route holds work as before, and so does the refresh of a waiting create's Caddyfiles at its start. The steps:

1. `csr-<i>`: the leaf's Node makes its key once and answers a certificate request. The key never leaves the Node.
2. `sign-<i>`: the Gateway signs the request through the action that `operation:direct:certificate-sign` uses. That action checks the admitted leaf, the root and the run. It refuses once a cancel is pending.
3. `site-<node>`: `route.publish_site` places the Node's part: Caddy access, `APP_URL`, links, PHP-FPM pools, the leaf and its account copy, the Orbit root's trust, and the Caddyfile.
4. `dns-<i>`: `dns.publish_records` places changed private DNS records on each VPN Node, beside the sites.
5. `probe-<i>`: the Gateway makes a TLS handshake with each serving Node, by its WireGuard address and the Route's name, trusting the Orbit root alone.

The probes wait for the sites alone: they reach each Node by its WireGuard address, not by DNS.

A failed step records the chart's step name, such as `Install cert, publish site-3`, so the Route gets the same `failed_step` and error code as on Swamp.

A Route that needs the Ingress edge, a LAN rule for a Cluster Router, a production `.env` or awake markers starts on Swamp. So does one whose access commands or PHP-FPM pools differ from the shapes the executor rebuilds. Today only `app-dev` and `vpn` Nodes run the executor, so a Route on another Node also stays on Swamp. The other kinds that publish Routes, such as `route.update`, keep their Swamp flows.

### instance.create

The engine runs a container clone's create from its admitted intent (`direct_provisioning.container`). Its steps are the executor's `instance` family ([Executor: The instance family](/reference/executor#the-instance-family)):

1. `clone`, on the Node: the copy of the default's seed, with its ACL, NIC, T3 proxy and route rule.
2. `fetch`, on the Node, beside `clone`: the branch into the Node's mirror. The Gateway mints the read credential and sends it as a blob.
3. `prepare`, on the copy: its files before the first start. The list goes as one blob, because an order holds at most 4 KB.
4. `start`, on the copy: the network guard, the start and the copy's names.
5. `checkout`, on the copy: the branch, and the `.env` merged from the default's and the Gateway's render.
6. `t3`, on the copy: T3 running, its project, and its two sessions sealed to the Gateway's key.

The Gateway opens the sealed sessions and gives its own to the create's projection. No vault holds it. The create leaves its address to its completion. Its result says `route_publication: deferred`, with the reason `engine_completion`. The completion runs on Swamp, as before.

Some clones start on Swamp:

- a nested-fleet default's copy, a task copy or a branch selection;
- a clone with attached Node services, Processes or a CI hold;
- an already active create;
- a clone with a firewall row other than a TCP route allow.

A default's own create stays on Swamp.

An executor reports its primitives when it connects to the bus, and again every minute, also while it waits for its consumer. The Gateway makes that consumer with the Node's first order, and it sends that order only after a fresh report. The report writes `{actions, version, clock_synchronized}` to key `n<node>` of the `NODES` bucket. A report expires after five minutes, so a stopped executor stops receiving kinds. A Node with an older executor reports nothing, so its Operations stay on Swamp. When the Gateway cannot read the bucket, for example while `nats-server` restarts, the Operation also runs on Swamp.

`clock_synchronized` comes from a read-only `adjtimex`. Doctor warns `node.clock_unsynchronized` when it is false, because a clock that follows no time source drifts until the executor refuses the Gateway's signed orders.

Today `app-dev` Nodes enable the `firewall`, `route` and `instance` families, `vpn` Nodes the `dns` family and the Gateway's Node the `proof` family, because the converge installs the executor only where a role enables a family. The converge enables `firewall` only once the pinned agent delivery is 0.16.0 or newer, `route` from 0.17.0, `dns` from 0.18.0 and `instance` from 0.19.0: an older executor refuses to start on a family it does not know. Firewall changes on other Nodes stay on Swamp until the executor runs on every Node, as `orbit-agent execute serve` ([ADR 0271](/decisions/0271-run-operations-on-a-gateway-state-machine-over-nats) N7).

`instance.create` needs agent 0.21.0 on the Node: an executor at 0.20.0 reports the same `instance` actions but refuses the clone's route rules and its `files_blob`, so the create fails instead of running wrong. Keep `instance.create` out of `ORBIT_ENGINE_KINDS` until the pinned delivery is 0.21.0 or newer.

The `files_blob` holds the model environment inline. The Gateway also puts the placeholder model key into the JSON settings there, in place of the intent's `key_value` ([Antigravity](/reference/development-containers#antigravity)). The agent's prepare step still replaces each top-level settings key whole. That drops a provider instance of the agent's own, and it rewrites a key that the Conn Server already moved into its store. So `instance.create` also stays out of `ORBIT_ENGINE_KINDS` until prepare merges as the other settings writers do.

## Steps and transitions

`Engine::start` records one row per step in `operation_engine_steps` and sets `state.swamp_run_id` to `engine-<id>`. It then publishes a start event. Every transition happens in `advance`, inside one transaction that locks the Operation and its steps:

- a pending step whose `after` steps are done is sent;
- a sent step past its deadline is sent again with a new order;
- a sent step past its budget fails with `engine.step_budget_exceeded`;
- the Operation fails as soon as one step failed;
- the Operation succeeds once all steps are done;
- the Operation ends as cancelled at the first transition after a cancel request.

The steps' real result beats a cancel: when every step already ended, their result counts.

Orders are published after the transaction commits. For each step the engine first schedules its deadline, then publishes its order:

- an order it cannot make, such as an input the kind cannot build or an order over 4 KB, fails its step with `engine.order_invalid`;
- a publish that fails puts its step back to pending and throws, so the event is negatively acknowledged and comes back after a second.

If the projection at the end throws, the event comes back too, and the engine runs the projection again.

The engine mirrors its steps into `state.native_progress`, so `operation:show` shows them like a Swamp run's steps. At the end it calls the kind's own projection with the status, error code, failed step and times, as a Swamp run's end did.

### Orders and receipts

Each step is one signed order. Its `operation` is `<id>:<step>`, so each step has its own journal record on the Node. Its `target` is the real resource the primitive changes. For `ufw.ensure_rule` that is the Node, so firewall changes on one Node run one at a time.

An engine order expires 8 seconds after signing. The executor refuses an expiry more than 10 seconds ahead. A receipt with `refused` and `expired` puts the step back to pending, and the next transition sends a new order. So does a `failed` receipt with `executor.order_expired`: the order expired while it waited for the executor's lock, so it never acted. A `replayed_nonce` receipt counts with the first order's result it carries; without one it changes nothing.

The engine takes a receipt's Node from its subject, `orbit.receipts.<node>`, never from its body, and applies it only to a step on that Node. A receipt it cannot read is terminated, so it never comes back.

A receipt with `output` carries public text a later step reads, such as a certificate request. The engine keeps it as the step's output.

A receipt with `applied` reports how many changes the primitive made. For a firewall kind, zero changes records the result as skipped with `firewall.rule_exists`.

### Content

An order is at most 4 KB. Larger or secret content, such as a Caddyfile or a certificate, travels as a blob: a step's input names `{"blob": {"content": …}}`, and `EngineContent` puts the content into the Node's own content key `$KV.CONTENT.n<node>.<sha256>` before the order is signed. The order then names only `{"blob": {"sha256": …}}`. The executor reads each blob its order names into a root-only cache and checks its hash before the primitive runs. Only that Node may read its content keys.

### Deadlines

A deadline is a NATS message schedule, not a poller. After each send, the engine publishes to `orbit.engine.schedule.<id>.<step>.<sends>` with the headers `Nats-Schedule: @at <time>` and `Nats-Schedule-Target: orbit.engine.deadline`. JetStream delivers the deadline to the engine at that time. A deadline for a step that already moved on changes nothing.

The firewall step re-sends after 10 seconds and fails after 120 seconds. The reconciler ends an engine Operation whose whole budget ran out.

A start or an order can be lost when the Gateway stops between a commit and its publish. The reconciler covers that: each minute it sends a nudge, an immediate deadline event, to an unfinished engine Operation with a pending step or a sent step more than 30 seconds past its re-send time.

### Cancel

`operation:cancel` sets `cancel_requested_at` and sends a nudge with its own message id. The next transition ends the Operation as cancelled, with `operation.cancelled`, and sends nothing more. An order already on its Node still runs to its end, and when every step has ended, their result counts instead. If the bus is down, the cancel waits for the next receipt, deadline or nudge.

## The machine bus

`nats-server` runs on the Gateway and listens on port 4222 of the Gateway's WireGuard address only, so the bus is never reachable from the Internet, even on a Gateway host without a firewall. The Gateway's own client, and every executor, connect to that address. JetStream keeps these streams; `orbit:engine-setup` and the consumer make them, and both are idempotent:

| Stream | Subjects | Retention | Consumers |
| --- | --- | --- | --- |
| `ORDERS` | `orbit.node.<node>.orders` | work queue, 1 h | `node-<node>`, pulled by that Node's executor |
| `RECEIPTS` | `orbit.receipts.<node>` | work queue, 1 h | `gateway-receipts`, pushed to the engine |
| `ENGINE` | `orbit.engine.>` | limits, 24 h, message schedules | `gateway-engine`: start and deadline events |
| `KV_CONTENT` | `$KV.CONTENT.n<node>.<sha256>` | one per key, 1 h | direct get by the Node |
| `KV_NODES` | `$KV.NODES.n<node>` | one per key, 5 min, no rollup | direct get by the Gateway |

The Gateway makes a Node's `node-<node>` consumer before its first order. Until then the executor waits for it.

Publishes wait for JetStream's acknowledgement. An order uses its nonce as `Nats-Msg-Id`, so a repeated publish lands once.

### The consumer process

`orbit-gateway-engine.service` runs `orbit:engine-consume`. It applies receipts and engine events from two push consumers. Each handler returns its verdict, and the client sends it as the one reply:

- an acknowledgement when the handler ended;
- a negative acknowledgement with a one-second delay when the handler threw;
- a termination for a message it cannot read.

The PHP client answers the server's pings only while it reads. A request connection that sits unused in a long-running process can therefore be closed by the server. `EngineBus::client()` pings a connection that was unused for 10 seconds and replaces it when the ping fails.

## Authentication

A Node holds no bus credential. The bus admits a Node by its WireGuard address, the same rule the Gateway API applies to a Node's requests.

The Gateway environment holds one nkey account seed, `ORBIT_NATS_SEED`. `orbit:engine-keys` prints a new one as an environment line. A Gateway without it runs no `nats-server`, opens no port 4222 and gives no Node a bus address.

`nats.conf` holds only public keys:

- the Gateway's own nkey user, derived from the seed, which may use every subject;
- the auth callout's issuer, the seed's public key.

Every other connection goes to the auth callout. `nats-server` sends an authorization request to `$SYS.REQ.USER.AUTH`, and the consumer process answers it:

1. It reads the connection's address from the request.
2. It looks for the active Node with that WireGuard address.
3. It answers with a user JWT that the issuer signs. The user is named `node-<id>`, lasts one hour and may use only that Node's subjects.

An address with no active Node gets a refusal with `peer.identity_unknown`.

WireGuard binds each address to one peer key, so a Node cannot connect with another Node's address. When the user expires, the server closes the connection, and the client reconnects and asks again. A removed Node therefore loses the bus within an hour. When its WireGuard peer goes, its connection also ends once the server's pings go unanswered. Replacing the seed changes nothing on the Nodes.

Every process on a Node shares the Node's WireGuard address. So each Node also runs a guard: an nftables table, `orbit_bus_guard`, that rejects a connection to the Gateway's NATS port from any user but root. The bus boundary is therefore root on the Node. An app or an agent shell on the Node cannot join as its executor. On the Gateway's own host the guard also admits the Gateway's runtime user, whose processes join with the Gateway's own key. Any other user there stays out.

The callout runs in the consumer process, between receipts and events. While that process is down, new Node connections fail and retry; connected Nodes keep their users until they expire.

A Node's user may publish only:

- the JetStream API calls to read and pull its own `node-<id>` consumer, and acknowledgements for it;
- receipts on `orbit.receipts.<id>`;
- direct gets of its own content keys `n<id>.*`;
- its own capability key `$KV.NODES.n<id>`.

It may subscribe only to its own inbox prefix, `_INBOX.node-<id>.>`, so it never sees another Node's replies or content. The order signature stays the boundary: a NATS server or another Node cannot forge an order.

### Delivery to a Node

The Node converge writes two files from the executor intent:

- `/etc/orbit/executor/nats.json` with the bus URL only;
- `/etc/orbit/executor/bus-guard.nft`, the guard, which `orbit-executor.service` loads before each start.

Without the engine's key the converge removes both files and drops the guard table, and the executor serves its socket alone.

## Install

The Gateway role converge installs the bus when the Gateway holds the key:

- `gateway-nats.yml` makes the `nats` user and installs the pinned `nats-server` release, checked by SHA-256;
- `GatewayRuntimeFiles` writes `/etc/nats/nats.conf`, `nats-server.service` and `orbit-gateway-engine.service`;
- `NodeFirewallRuleCatalog` adds the rule `orbit:gateway-nats` for port 4222 on the `orbit` interface, for a host whose firewall is on.

To turn the engine on for a fleet:

1. Pin an agent delivery of 0.16.0 or newer.
2. Add the seed from `orbit:engine-keys` to the Gateway environment.
3. Converge the Gateway role, then the Nodes.
4. List the kinds in `ORBIT_ENGINE_KINDS`.

To turn it off, empty `ORBIT_ENGINE_KINDS` first. Removing the seed stops new bus files, but the converge does not stop the running units: stop and disable `orbit-gateway-engine.service` and `nats-server.service` by hand.

## Proof

The foundation was proven in a task copy's nested fleet:

- `firewall:allow`, a repeat and `firewall:remove` on app-dev ran on the engine, and each ufw table matched;
- an Operation took 0.55–0.67 seconds from request to result, against 2.8–3.7 seconds on Swamp; the step itself took 0.2–0.3 seconds, and a repeat 62 ms;
- an executor stopped for 12 seconds: its first order expired and the engine re-sent it;
- a `nats-server` restart: the executor and the Gateway reconnected;
- the first Operation after that restart ran on Swamp, since its capability read failed, and succeeded;
- a Node whose executor did not report `ufw.ensure_rule` ran its firewall Operations on Swamp, in 2.8–7.8 seconds each;
- the consumer process stopped for 5 seconds: the start event waited in its durable consumer and ran on restart.

The authentication was proven there too:

- the executor joined with no credential;
- a connection from a non-Node address was refused;
- the Node was refused while marked `removing`;
- the Node could publish its own receipts but not another Node's;
- with the guard loaded, root on the Node reached the bus and the `orbit` user was refused.

The shared fixture `apps/agent/contract/fixtures/ufw-ensure-rule-input.json` keeps both sides in step. A Pest test checks that the Gateway signs that input for its intent, and a Rust test checks that the primitive reads and accepts it.
