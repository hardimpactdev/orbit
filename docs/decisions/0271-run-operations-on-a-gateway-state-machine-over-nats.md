---
title: "ADR 0271: Run operations on a Gateway state machine over NATS"
sidebarTitle: "0271 Gateway state machine"
description: "In progress. The Gateway runs an Operation as an event-driven state machine of typed primitives. orbit-executor runs each primitive on its Node and reaches the Gateway over NATS JetStream, which carries orders, receipts and content. Operation kinds move off Swamp one at a time. This changes ADR 0267's transport, its action granularity and its split with Swamp."
---

# ADR 0271: Run operations on a Gateway state machine over NATS

The Gateway runs an Operation as a state machine. An Operation is a set of steps. Each step is one typed primitive on one Node, or a short step on the Gateway itself, with the steps it waits for. Nothing stays open while a Node works: the Gateway sends what can run, and every receipt records its step and sends what can run next. `orbit-executor` runs each primitive on its Node. Orders, receipts and content travel over NATS JetStream, which stores them until they are delivered and acknowledged. Operation kinds move off Swamp one at a time: a kind runs entirely on Swamp or entirely on the state machine.

## Status

In progress.

The owner decided N1–N6 below on 2026-11-18, after the spike on the copy `executor-spike` (branch `spike/swamp-engine-reference`).

Principle: [One way, one name](/mission#principles), [Deterministic first](/mission#principles) and [Security fits the real threat model](/mission#principles). One state machine runs every migrated kind, one primitive holds each resource rule, and the order signature stays the security boundary. Exceptions:

- [No exceptions and no legacy](/mission#principles): while kinds move, Swamp and the state machine run side by side. Each kind is on exactly one of them, and the move of the last kind removes Swamp's operation workflows. Swamp is not a fallback for a migrated kind.
- The frozen agent of [ADR 0253](/decisions/0253-patch-nodes-and-reboot-them-drained): the observer gets no new command. Its relay of orders and receipts goes away, because the executor joins NATS itself.

## Context

Today an Operation is a Swamp run. The Gateway admits it, Swamp runs a YAML workflow of steps, and the Gateway reads the run's terminal event. A placed step costs about 1 s of dispatch before it does any work, and a run starts about 2 s after admission ([ADR 0267](/decisions/0267-run-fast-node-actions-in-a-signed-order-executor#context)). Swamp's workers cost about 130 MB idle and about 75 MB per running step, so they cannot run on small Nodes.

[ADR 0267](/decisions/0267-run-fast-node-actions-in-a-signed-order-executor) moved fast Node actions into a Rust executor on Gateway-signed orders, sent over the observer's WebSocket. It kept long and multi-Node work in Swamp, and it planned the clone as one large action, `instance.clone`.

The spike on the copy `executor-spike` (2026-11-18) tested a different split:

| Measured | Swamp | State machine |
| --- | --- | --- |
| `firewall:allow`, a new rule | 4–7 s | 0.41 s (one desired-state primitive) |
| `route:create` | 6–7 s | 0.37–0.75 s |
| Five `route:create` at once | 44 s | 6.3 s |
| Container `instance:create` on the nested fleet (`dir` pool, 2 CPUs) | 32–39 s | 22.5–26 s, the whole create through the existing projection |

The state machine adds no measurable time of its own: on the nested fleet, the serial Node work of a clone's container phase added up to its total. NATS JetStream carried a `route:create` at the same speed as the WebSocket (median 747 ms against 763 ms). With the resend tick off, JetStream and the executor's journal recovered every fault: an executor restart, a Node's NATS connection refused for 8 s, the Gateway's receipt consumer stopped for 8 s, a `nats-server` restart, and a 20 s step with a 5 s acknowledgement wait. Without NATS, an observer that was offline lost a receipt. `nats-server` used 14 MB idle and the executor 3 MB.

The Node work of a container clone fitted 19 primitives, each on one resource. No Python or shell ran on the Node.

## Decision

### The owner's decisions

- **N1. The Gateway orchestrates.** An Operation of a migrated kind is a state machine in the Gateway. No job or worker stays open while a Node works. Receipts drive every transition.
- **N2. NATS JetStream is the machine bus.** Orders, receipts and content travel over NATS. Reverb stays the channel for people and apps: Conn and the web UI send commands over HTTP and receive progress over Reverb.
- **N3. Typed primitives on one resource kind.** A primitive acts on one kind of resource that exists on any Linux host, such as a firewall rule, a file, a unit, an Incus instance or a dotenv file. It never acts on an Orbit concept such as an Instance or a Route. Where the resource allows it, the primitive is desired state: it reads the resource, changes only what differs, and answers how many changes it made. Orbit's knowledge (what, in which order, with which content) lives in the Gateway's PHP. No script is sent to a Node or written per flow.
- **N4. One kind at a time.** An Operation kind moves as a whole, in one pull request, after its proof passes. Swamp keeps every other kind until it moves.
- **N5. Two processes, both root, for now.** The observer and the executor stay separate processes with separate NATS users. Only the executor publishes receipts. Both run as root, as Swamp's SSH does today. Hardening (an unprivileged observer, sealed receipts) comes later, and these seams keep it a configuration change.
- **N6. Content travels over NATS.** A file's bytes reach a Node through the NATS KV bucket `CONTENT`, keyed by Node and SHA-256. The signed order names only the hash. There is no HTTP side channel.

### The state machine

The Gateway records each migrated Operation's steps and their state in its own table. A step has a Node (0 for a Gateway step), a primitive, the steps it waits for, a budget, and a resend interval that follows its expected duration.

- **Start.** Admission records the Operation and publishes one start event to the Gateway's JetStream stream. The Gateway's consumer handles it like a receipt. A start that cannot be published fails the request; nothing is half-started.
- **Advance.** In one database transaction, the Gateway records a receipt, marks every step whose dependencies are done as sent, and decides the Operation's state. After the commit it publishes the orders. A failed publish puts its step back to pending and the consumer negatively acknowledges the event, so JetStream redelivers it and the next advance sends the step again.
- **Gateway steps.** A step with Node 0 runs on the Gateway, such as signing a certificate or checking a T3 session, and records its result like a receipt.
- **Input from results.** A step's input is computed when it is sent, from the Operation's admitted intent and the results of earlier steps. An Incus copy answers its UUID; the Gateway renders the machine id from it.
- **Budgets and cancel.** A step that runs past its budget fails the Operation. A cancel marks the Operation and sends no further step. Budget deadlines are scheduled NATS messages, so no Gateway process polls.
- **Failure.** The first failed step ends the Operation. A definition may start a compensation, such as withdrawing a Route, before it records the original failure.
- **Projection.** The end of the Operation calls the kind's existing projection, as a Swamp run's terminal event does today. Callers of the API, CLI, MCP and Conn see no difference.

### The executor on NATS

- **Orders.** The executor holds a durable pull consumer on the stream `ORDERS`, subject `orbit.node.<id>.orders`. It checks each order exactly as [ADR 0266](/decisions/0266-sleep-and-wake-containers-through-a-signed-order-executor) and ADR 0267 define: signature, action, enabled on this Node, Node, target, expiry, nonce, Operation, input.
- **Receipts.** It publishes the receipt to `orbit.receipts.<id>` with a deduplication id, waits until JetStream has stored it, and only then acknowledges the order. An order whose receipt was not stored comes back, and the journal answers it as a repeat.
- **Long steps.** While a primitive runs, the executor sends progress acknowledgements, so a short acknowledgement wait never redelivers a step that is still running.
- **Content.** Before it runs an order, the executor reads every hash the order names from `CONTENT` into a root-only cache and checks it.
- **Fan-out.** The executor runs at most two orders per CPU at once, between 2 and 8, counting the CPUs its container may use.
- **Capabilities.** The executor reports the primitives it supports. The Gateway runs a migrated kind on the state machine only when every Node it touches supports the kind's primitives.

### NATS

One `nats-server` runs on the Gateway host, reachable only over WireGuard. Each Node has two NATS users: the executor's, which may read its own orders, publish its own receipts and read its own `CONTENT` keys, and the observer's, which may only publish its facts. The Gateway's user may do everything. The Node converge writes each Node's credentials. Streams use work-queue retention and a one-hour age limit, so nothing piles up.

### Primitives

A primitive is one variant of the executor's action enum, with a typed input, a result bound and a target kind, as in ADR 0267's "Add an action". The Gateway's signer allowlist and the executor's enum come from one list, and a contract test fails when they differ. A new primitive needs a reason in its pull request that no existing primitive fits.

### What this changes

- **ADR 0267 P1** (orders over the WebSocket): orders, receipts and content go over NATS. The observer's relay goes away.
- **ADR 0267 P3** (the executor test): the test is one Node, idempotent by Operation, a fixed name and one resource kind. A primitive may run longer than seconds within its budget, with progress acknowledgements.
- **ADR 0267 P4 and P8** (Swamp keeps multi-Node work; `instance.clone` is one action): the state machine runs migrated kinds, multi-Node ones included. The clone's Node part is a composition of primitives, not one action.
- **The boundary "the Gateway is the center, Swamp does the work"** (ADR 0267, What this changes): for a migrated kind, the Gateway is the center and runs the work; the executor runs primitives; Swamp is not involved.

### Order of the moves

1. The foundation, with `firewall:allow` and `firewall:deny` as the first kind.
2. `route:create` and `route:destroy`.
3. The DNS writes and the short Node writes, such as Process control.
4. `instance:create` and the other container flows.
5. The converges (`node:role-add`, Node setup) last.

## Rejected alternatives

- Swamp workers on every Node over a WebSocket: about 130 MB idle and 75 MB per step on each Node.
- Durable Workflow in the Gateway: about 30 tables of run state, and in the spike 2.5–4.7 s per `firewall:allow` against 0.7–1.8 s for a plain job.
- A Horizon job that holds a run open: a job waits on each Node step and holds a worker; a Gateway restart loses its place.
- Content fetched over HTTPS by hash: a second channel next to the bus, with its own authorization.
- The WebSocket alone, without NATS: an observer or Gateway consumer that is offline loses a receipt, and a resend tick has to cover every hop.
- One large action per flow (`instance.clone`): every flow becomes its own Rust command, the pattern that grew the frozen agent to 321 commands.

## Consequences

- `route:create` and `firewall:allow` drop from seconds to under a second, and bursts no longer queue behind Swamp's workers.
- An Operation of a migrated kind keeps its place across a Gateway, executor or `nats-server` restart.
- One system holds a migrated kind, in PHP, with no YAML or expression layer.
- The Gateway owns run inspection for migrated kinds: per-step timing, receipts and errors in the Operation's steps.
- The Gateway host runs one more service, `nats-server`, and each Node gets NATS credentials.
- A new kind of Node action is Rust code and an executor release. Mixed executor versions are handled by the capability check.
- Swamp and the state machine run side by side until the last kind moves.
- Secret output (such as a T3 session token) is in the receipt today. Sealing it to the Gateway's key is follow-up work before `instance:create` moves.

## Affects

- Components: apps/gateway, apps/agent, apps/docs, swamp (Ansible role for `nats-server`)
- ADRs: [ADR 0267](/decisions/0267-run-fast-node-actions-in-a-signed-order-executor), [ADR 0266](/decisions/0266-sleep-and-wake-containers-through-a-signed-order-executor)
- Detail: [Executor](/reference/executor), [Operations](/reference/operations), and a new reference page for the state machine and NATS
- Verify: the executor's and Gateway's tests for the state machine, the bus and each primitive; the contract test; the fault proofs on the nested fleet; the `firewall:allow` proof cases
