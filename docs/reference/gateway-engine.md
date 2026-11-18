---
title: "Gateway engine"
description: "How the Gateway runs an Operation kind as an event-driven state machine over NATS JetStream: routing a kind off Swamp, steps and receipts, deadlines, the machine bus, its credentials and its install."
covers:
  - apps/gateway/app/{Domain/Engine/**,Infrastructure/Engine/**,Domain/Firewall/FirewallEngineFlow.php}
  - apps/gateway/app/Console/Commands/{EngineConsumeCommand.php,EngineSetupCommand.php,EngineKeysCommand.php}
  - apps/gateway/database/migrations/2027_03_10_000000_create_operation_engine_steps_table.php
  - apps/agent/contract/fixtures/ufw-ensure-rule-input.json
  - swamp/ansible/tasks/gateway-nats.yml
---

# Gateway engine

The Gateway engine runs an Operation as a state machine in the Gateway. Each step is a typed primitive that the [executor](/reference/executor) runs on its Node. NATS JetStream carries the orders, the receipts and the engine's own events. [ADR 0271](/decisions/0271-run-operations-on-a-gateway-state-machine-over-nats) records why.

Operation kinds move off Swamp one at a time. A kind runs entirely on the engine or entirely on Swamp, never half on each.

## Which kinds run on the engine

A kind runs on the engine when all of these hold:

- the engine has a definition for the kind (`EngineKinds`);
- the kind is listed in `ORBIT_ENGINE_KINDS`, a comma-separated list in the Gateway environment;
- every Node that one of its steps targets has reported the step's primitive.

Otherwise the kind starts its Swamp workflow as before. `StartSwampRun` makes this choice after it admits the Operation, so admission, locks and the API stay the same for both paths.

Kinds with a definition today:

| Kind | Steps | Primitive |
| --- | --- | --- |
| `firewall.allow`, `firewall.deny`, `firewall.remove` | `ensure-0` on the rule's Node | `ufw.ensure_rule` |

An executor reports its primitives when it joins the bus: it writes `{actions, version}` to key `n<node>` of the `NODES` bucket. A Node with an older executor reports nothing, so its Operations stay on Swamp.

Today only `app-dev` Nodes enable the `firewall` family, because the converge installs the executor only where a role enables a family. Firewall changes on other Nodes stay on Swamp until the executor runs on every Node, as `orbit-agent execute serve` ([ADR 0271](/decisions/0271-run-operations-on-a-gateway-state-machine-over-nats) N7).

## Steps and transitions

`Engine::start` records one row per step in `operation_engine_steps` and sets `state.swamp_run_id` to `engine-<id>`. It then publishes a start event. Every transition happens in `advance`, inside one transaction that locks the Operation and its steps:

- a pending step whose `after` steps are done is sent;
- a sent step past its deadline is sent again with a new order;
- a sent step past its budget fails with `engine.step_budget_exceeded`;
- the Operation fails as soon as one step failed;
- the Operation succeeds once all steps are done;
- the Operation ends as cancelled at the first transition after a cancel request.

Orders are published after the transaction commits. A publish that fails puts its step back to pending and throws. The event is then negatively acknowledged, and JetStream delivers it again after a second.

The engine mirrors its steps into `state.native_progress`, so `operation:show` shows them like a Swamp run's steps. At the end it calls the kind's own projection with the status, error code, failed step and times, as a Swamp run's end did.

### Orders and receipts

Each step is one signed order. Its `operation` is `<id>:<step>`, so each step has its own journal record on the Node. Its `target` is the real resource the primitive changes. For `ufw.ensure_rule` that is the Node, so firewall changes on one Node run one at a time.

An engine order expires 8 seconds after signing. The executor refuses an expiry more than 10 seconds ahead. A receipt with `refused` and `expired` puts the step back to pending, and the next transition sends a new order. A `replayed_nonce` receipt changes nothing: the first order's own receipt carries its result.

A receipt with `applied` reports how many changes the primitive made. For a firewall kind, zero changes records the result as skipped with `firewall.rule_exists`.

### Deadlines

A deadline is a NATS message schedule, not a poller. After each send, the engine publishes to `orbit.engine.schedule.<id>.<step>.<sends>` with the headers `Nats-Schedule: @at <time>` and `Nats-Schedule-Target: orbit.engine.deadline`. JetStream delivers the deadline to the engine at that time. A deadline for a step that already moved on changes nothing.

The firewall step re-sends after 10 seconds and fails after 120 seconds. The reconciler still ends an engine Operation whose whole budget ran out.

### Cancel

`operation:cancel` sets `cancel_requested_at` and publishes a start event. The next transition ends the Operation as cancelled and sends nothing more. An order already on its Node still runs to its end.

## The machine bus

`nats-server` runs on the Gateway and listens on port 4222. The Gateway firewall allows that port on the WireGuard interface only. JetStream keeps these streams; `orbit:engine-setup` and the consumer make them, and both are idempotent:

| Stream | Subjects | Retention | Consumers |
| --- | --- | --- | --- |
| `ORDERS` | `orbit.node.<node>.orders` | work queue, 1 h | `node-<node>`, pulled by that Node's executor |
| `RECEIPTS` | `orbit.receipts.<node>` | work queue, 1 h | `gateway-receipts`, pushed to the engine |
| `ENGINE` | `orbit.engine.>` | limits, 24 h, message schedules | `gateway-engine`: start and deadline events |
| `KV_CONTENT` | `$KV.CONTENT.n<node>.<sha256>` | one per key, 1 h | direct get by the Node |
| `KV_NODES` | `$KV.NODES.n<node>` | one per key | direct get by the Gateway |

The Gateway makes a Node's `node-<node>` consumer before its first order. Until then the executor waits for it.

Publishes wait for JetStream's acknowledgement. An order uses its nonce as `Nats-Msg-Id`, so a repeated publish lands once.

### The consumer process

`orbit-gateway-engine.service` runs `orbit:engine-consume`. It applies receipts and engine events from two push consumers. Each handler returns its verdict, and the client sends it as the one reply: an acknowledgement, or a negative acknowledgement with a one-second delay when the handler threw.

The PHP client answers the server's pings only while it reads. A request connection that sits unused in a long-running process can therefore be closed by the server. `EngineBus::client()` pings a connection that was unused for 10 seconds and replaces it when the ping fails.

## Credentials

The bus uses NATS decentralized authentication. The Gateway environment holds three nkey seeds:

| Variable | Use |
| --- | --- |
| `ORBIT_NATS_OPERATOR_SEED` | signs the account JWTs |
| `ORBIT_NATS_ACCOUNT_SEED` | the `ORBIT` account; signs every user JWT |
| `ORBIT_NATS_SYSTEM_SEED` | the server's system account |

`orbit:engine-keys` prints three new seeds as environment lines. A Gateway without all three runs no `nats-server`, opens no port 4222 and gives no Node a bus configuration.

`nats.conf` holds only public JWTs, so the server's configuration does not change when Nodes come and go. A user's key derives from the account seed and the user's name (`gateway` or `node-<id>`). The JWTs have a fixed issue time, so the same inputs give the same files and a converge changes nothing. Replacing the account seed revokes every user at once.

A Node's user may publish only:

- the JetStream API calls to read and pull its own `node-<id>` consumer, and acknowledgements for it;
- receipts on `orbit.receipts.<id>`;
- direct gets of its own content keys `n<id>.*`;
- its own capability key `$KV.NODES.n<id>`.

It may subscribe only to `_INBOX.>`. The order signature stays the boundary: a NATS server or another Node cannot forge an order.

### Delivery to a Node

The Node converge writes `/etc/orbit/executor/nats.json` (root, mode 0600) with the bus URL and the Node's `.creds`. The credentials travel as the converge's private copy `executor_bus` in the Swamp vault, never in the intent or the Operation input. Without the engine's keys the converge removes the file, and the executor serves its socket alone.

## Install

The Gateway role converge installs the bus when the Gateway holds the keys:

- `gateway-nats.yml` makes the `nats` user and installs the pinned `nats-server` release, checked by SHA-256;
- `GatewayRuntimeFiles` writes `/etc/nats/nats.conf`, `nats-server.service` and `orbit-gateway-engine.service`;
- `NodeFirewallRuleCatalog` adds the rule `orbit:gateway-nats` for port 4222 on the `orbit` interface.

To turn the engine on for a fleet, add the keys to the Gateway environment, converge the Gateway role and the Nodes, then list the kinds in `ORBIT_ENGINE_KINDS`.

## Proof

The foundation was proven in a task copy's nested fleet:

- `firewall:allow`, a repeat and `firewall:remove` on app-dev ran on the engine, and each ufw table matched;
- an Operation took 0.55–0.67 seconds from request to result, against 2.8–3.7 seconds on Swamp; the step itself took 0.2–0.3 seconds, and a repeat 62 ms;
- an executor stopped for 12 seconds: its first order expired and the engine re-sent it;
- a `nats-server` restart: the executor and the Gateway reconnected and the next Operation succeeded;
- the consumer process stopped for 5 seconds: the start event waited in its durable consumer and ran on restart.

The shared fixture `apps/agent/contract/fixtures/ufw-ensure-rule-input.json` keeps both sides in step. A Pest test checks that the Gateway signs that input for its intent, and a Rust test checks that the primitive reads and accepts it.
